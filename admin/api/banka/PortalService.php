<?php
/**
 * Örnek Portal Banka API İstemcisi
 *
 * portal.ornekproje.com/api/v1 üzerinden banka hareketlerini çeker ve
 * BankaHesapHareketleri tablosuna artımlı olarak yazar.
 *
 * Bağlantı bilgileri Entegrasyonlar / EntegrasyonKanallari tablolarında tutulur;
 * kod içinde token bulunmaz.
 */

require_once __DIR__ . '/../../db.php';
require_once __DIR__ . '/CariEslestirici.php';

class PortalService
{
    private Database $db;
    private array $entegrasyon;
    private array $kanal;
    private array $ayarlar;

    /** Referans öneki: mevcut kayıtlarla aynı format korunur */
    private const REFERANS_ONEK = 'BANKA-HAREKET-';

    /** Tek turda çekilecek en fazla sayfa (kaçak döngü koruması) */
    private const MAX_SAYFA = 200;

    public function __construct(array $entegrasyon, array $kanal)
    {
        $this->db          = Database::getInstance();
        $this->entegrasyon = $entegrasyon;
        $this->kanal       = $kanal;
        $this->ayarlar     = json_decode((string) ($kanal['kanal_ayarlar'] ?? '{}'), true) ?: [];
    }

    /**
     * Kanal koduna göre servisi oluşturur.
     */
    public static function kanaldan(string $kanalKod = 'banka_hareket'): self
    {
        $db = Database::getInstance();

        $kanal = $db->fetchOne(
            "SELECT * FROM EntegrasyonKanallari WHERE kanal_kod = ? AND kanal_durum = 1",
            [$kanalKod]
        );

        if (!$kanal) {
            throw new RuntimeException("Aktif kanal bulunamadı: {$kanalKod}");
        }

        $entegrasyon = $db->fetchOne(
            "SELECT * FROM Entegrasyonlar WHERE entegrasyon_id = ? AND entegrasyon_durum = 1",
            [$kanal['kanal_entegrasyon_id']]
        );

        if (!$entegrasyon) {
            throw new RuntimeException('Kanala bağlı aktif entegrasyon bulunamadı.');
        }

        if (empty($entegrasyon['entegrasyon_api_key'])) {
            throw new RuntimeException('Entegrasyon token değeri boş.');
        }

        return new self($entegrasyon, $kanal);
    }

    // ─── API erişimi ─────────────────────────────────────────────────────────

    /**
     * API'ye GET isteği atar, çözümlenmiş gövdeyi döndürür.
     */
    private function istek(string $yol, array $sorgu = []): array
    {
        $url = rtrim((string) $this->entegrasyon['entegrasyon_url'], '/') . '/' . ltrim($yol, '/');
        if ($sorgu) {
            $url .= '?' . http_build_query($sorgu);
        }

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER     => [
                'Authorization: Bearer ' . $this->entegrasyon['entegrasyon_api_key'],
                'Accept: application/json',
            ],
            CURLOPT_TIMEOUT        => 60,
            CURLOPT_CONNECTTIMEOUT => 15,
            CURLOPT_SSL_VERIFYPEER => false,
        ]);

        $cevap = curl_exec($ch);
        $hata  = curl_error($ch);
        $kod   = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($cevap === false) {
            throw new RuntimeException('Bağlantı hatası: ' . $hata);
        }

        $veri = json_decode((string) $cevap, true);

        if ($kod < 200 || $kod >= 300) {
            $mesaj = $veri['error']['message'] ?? $veri['message'] ?? mb_substr((string) $cevap, 0, 200);
            throw new RuntimeException("API hatası (HTTP {$kod}): {$mesaj}");
        }

        if (!is_array($veri) || empty($veri['success'])) {
            throw new RuntimeException('API beklenmeyen yanıt döndürdü.');
        }

        return $veri;
    }

    /**
     * Bağlantıyı ve token kapsamını doğrular.
     */
    public function baglantiTesti(): array
    {
        try {
            $firmalar = $this->istek('/firmalar')['data'] ?? [];
            $hesaplar = $this->istek('/hesaplar')['data'] ?? [];

            return [
                'success' => true,
                'message' => sprintf(
                    'Bağlantı başarılı. %d firma, %d hesap erişilebilir.',
                    count($firmalar),
                    count($hesaplar)
                ),
                'firmalar' => array_column($firmalar, 'ad'),
                'hesap_sayisi' => count($hesaplar),
            ];
        } catch (Throwable $e) {
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }

    /**
     * Hesap listesi ve güncel bakiyeler.
     */
    public function hesaplar(array $filtre = []): array
    {
        return $this->istek('/hesaplar', $filtre)['data'] ?? [];
    }

    // ─── Senkronizasyon ──────────────────────────────────────────────────────

    /**
     * son_id imlecinden itibaren yeni hareketleri çeker ve tabloya yazar.
     */
    public function senkronEt(?int $kullaniciId = null): array
    {
        $sonId    = (int) ($this->ayarlar['son_id'] ?? 0);
        $limit    = min(500, max(1, (int) ($this->ayarlar['limit'] ?? 500)));
        $baslicId = $sonId;

        $eklenen  = 0;
        $atlanan  = 0;
        $sayfa    = 0;

        try {
            do {
                $sorgu = ['limit' => $limit, 'sonraki_id' => $sonId];

                if (!empty($this->ayarlar['firma_id'])) {
                    $sorgu['firma_id'] = (int) $this->ayarlar['firma_id'];
                }

                $yanit    = $this->istek('/hareketler', $sorgu);
                $satirlar = $yanit['data'] ?? [];
                $devamVar = (bool) ($yanit['meta']['devam_var'] ?? false);

                foreach ($satirlar as $hareket) {
                    if ($this->hareketYaz($hareket, $kullaniciId)) {
                        $eklenen++;
                    } else {
                        $atlanan++;
                    }
                    $sonId = max($sonId, (int) $hareket['id']);
                }

                // İmleç her sayfadan sonra kaydedilir; kesinti hâlinde kalınan yerden devam eder.
                if ($sonId > $baslicId) {
                    $this->imlecKaydet($sonId);
                }

                $sayfa++;
            } while ($devamVar && $satirlar && $sayfa < self::MAX_SAYFA);

            // Yeni satırların cari önerisi burada hesaplanır; liste sayfası hazır kolonu okur.
            $eslestirme = ['eslesen' => 0, 'incelenen' => 0];

            if ($eklenen > 0) {
                $eslestirme = (new CariEslestirici())->calistir();
            }

            $mesaj = sprintf(
                '%d yeni hareket eklendi, %d kayıt atlandı (imleç: %d). %d satırda cari önerisi bulundu.',
                $eklenen,
                $atlanan,
                $sonId,
                $eslestirme['eslesen']
            );

            $this->logla('senkron', 'basarili', $mesaj, null, $kullaniciId);

            return [
                'success'    => true,
                'message'    => $mesaj,
                'eklenen'    => $eklenen,
                'atlanan'    => $atlanan,
                'son_id'     => $sonId,
                'sayfa'      => $sayfa,
                'oneri'      => $eslestirme['eslesen'],
                'devam_var'  => ($sayfa >= self::MAX_SAYFA),
            ];

        } catch (Throwable $e) {
            $this->logla('senkron', 'hata', "Kısmi sonuç: {$eklenen} eklendi.", $e->getMessage(), $kullaniciId);

            return [
                'success' => false,
                'message' => $e->getMessage(),
                'eklenen' => $eklenen,
                'atlanan' => $atlanan,
                'son_id'  => $sonId,
            ];
        }
    }

    /**
     * Tek hareketi tabloya yazar. Kayıt zaten varsa false döner.
     */
    private function hareketYaz(array $h, ?int $kullaniciId): bool
    {
        $kaynakId = (int) $h['id'];

        $mevcut = $this->db->fetchOne(
            "SELECT hareket_id FROM BankaHesapHareketleri WHERE hareket_kaynak_id = ?",
            [$kaynakId]
        );

        if ($mevcut) {
            return false;
        }

        $referans = self::REFERANS_ONEK . $kaynakId;

        // Referans daha önce kaynak_id'siz girilmiş olabilir (eski push kayıtları)
        $referansVar = $this->db->fetchOne(
            "SELECT hareket_id FROM BankaHesapHareketleri WHERE hareket_referans = ?",
            [$referans]
        );

        if ($referansVar) {
            $this->db->update(
                'BankaHesapHareketleri',
                ['hareket_kaynak_id' => $kaynakId, 'GuncellemeTarihi' => date('Y-m-d H:i:s')],
                ['hareket_id' => $referansVar['hareket_id']]
            );
            return false;
        }

        $veri = [
            'hareket_kanal_id'    => (int) $this->kanal['kanal_id'],
            'hareket_kaynak_id'   => $kaynakId,
            'hareket_referans'    => $this->kirp($referans, 100),
            'hareket_banka'       => $this->kirp($h['banka']['ad'] ?? null, 150),
            'hareket_gonderen_ad' => $this->kirp($h['karsi_taraf'] ?? null, 250),
            'hareket_tutar'       => (float) ($h['tutar'] ?? 0),
            'hareket_para_birimi' => $this->kirp($h['para_birimi'] ?? 'TRY', 10),
            'hareket_iban'        => $this->kirp($h['iban'] ?? null, 34),
            'hareket_vkn_tc'      => $this->kirp($h['vkn_tckn'] ?? null, 11),
            'hareket_aciklama'    => $this->kirp($h['aciklama'] ?? null, 500),
            'hareket_tarih'       => $this->tarih($h['islem_tarihi'] ?? null),
            'hareket_dekont_url'  => $this->dekontYolu($h),
            'hareket_islendi'     => 0,
            'hareket_durum'       => 1,
            'OlusturmaTarihi'     => date('Y-m-d H:i:s'),
        ];

        if ($kullaniciId !== null) {
            $veri['GuncelleyenKullanici'] = $kullaniciId;
        }

        try {
            $this->db->insert('BankaHesapHareketleri', $veri);
            return true;
        } catch (Throwable $e) {
            // Eşzamanlı çalışmada unique index çakışması olabilir; kayıt zaten var demektir.
            if (stripos($e->getMessage(), 'duplicate') !== false
                || stripos($e->getMessage(), 'UX_BankaHesapHareketleri') !== false) {
                return false;
            }
            throw $e;
        }
    }

    /**
     * Dekont yolu — dosya diske indirilmez, panel üzerinden vekil ile sunulur.
     */
    private function dekontYolu(array $h): ?string
    {
        if (empty($h['dekont_var'])) {
            return null;
        }

        return self::dekontVekilYolu((int) $h['id']);
    }

    public static function dekontVekilYolu(int $kaynakId): string
    {
        return '/admin/api/banka/dekont.php?kaynak=' . $kaynakId;
    }

    /**
     * Dekont dosyasını portaldan çeker.
     *
     * @return array{tip:string, icerik:string}
     */
    public function dekont(int $kaynakId): array
    {
        $url = rtrim((string) $this->entegrasyon['entegrasyon_url'], '/')
             . '/hareketler/' . $kaynakId . '/dekont';

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER     => ['Authorization: Bearer ' . $this->entegrasyon['entegrasyon_api_key']],
            CURLOPT_TIMEOUT        => 60,
            CURLOPT_CONNECTTIMEOUT => 15,
            CURLOPT_SSL_VERIFYPEER => false,
        ]);

        $icerik = curl_exec($ch);
        $hata   = curl_error($ch);
        $kod    = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $tip    = (string) curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
        curl_close($ch);

        if ($icerik === false) {
            throw new RuntimeException('Dekont alınamadı: ' . $hata);
        }

        if ($kod !== 200) {
            $veri  = json_decode((string) $icerik, true);
            $mesaj = $veri['error']['message'] ?? $veri['message'] ?? "HTTP {$kod}";
            throw new RuntimeException('Dekont alınamadı: ' . $mesaj);
        }

        return [
            'tip'    => explode(';', $tip)[0] ?: 'application/pdf',
            'icerik' => $icerik,
        ];
    }

    /**
     * Mevcut kayıtların dekont bilgisini portaldan tarayıp günceller.
     * Senkron öncesinde dekont alanı boş kalmış satırlar için kullanılır.
     */
    public function dekontlariGuncelle(int $baslangicId = 0): array
    {
        $sonId = $baslangicId;
        $sayfa = 0;

        $oncesi = (int) ($this->db->fetchOne(
            "SELECT COUNT(*) AS k FROM BankaHesapHareketleri WHERE ISNULL(hareket_dekont_url, '') <> ''"
        )['k'] ?? 0);

        do {
            $sorgu = ['limit' => 500, 'sonraki_id' => $sonId];

            if (!empty($this->ayarlar['firma_id'])) {
                $sorgu['firma_id'] = (int) $this->ayarlar['firma_id'];
            }

            $yanit    = $this->istek('/hareketler', $sorgu);
            $satirlar = $yanit['data'] ?? [];
            $devamVar = (bool) ($yanit['meta']['devam_var'] ?? false);

            foreach ($satirlar as $h) {
                $kaynakId = (int) $h['id'];
                $sonId    = max($sonId, $kaynakId);

                if (empty($h['dekont_var'])) {
                    continue;
                }

                $this->db->execute(
                    "UPDATE BankaHesapHareketleri
                     SET hareket_dekont_url = ?
                     WHERE hareket_kaynak_id = ? AND ISNULL(hareket_dekont_url, '') = ''",
                    [self::dekontVekilYolu($kaynakId), $kaynakId]
                );
            }

            $sayfa++;
        } while ($devamVar && $satirlar && $sayfa < self::MAX_SAYFA);

        $sonrasi = (int) ($this->db->fetchOne(
            "SELECT COUNT(*) AS k FROM BankaHesapHareketleri WHERE ISNULL(hareket_dekont_url, '') <> ''"
        )['k'] ?? 0);

        return [
            'success'     => true,
            'guncellenen' => $sonrasi - $oncesi,
            'toplam'      => $sonrasi,
            'sayfa'       => $sayfa,
            'message'     => ($sonrasi - $oncesi) . " kayda dekont bağlantısı eklendi (toplam {$sonrasi}).",
        ];
    }

    private function kirp(?string $deger, int $uzunluk): ?string
    {
        if ($deger === null) {
            return null;
        }

        $deger = trim($deger);

        return $deger === '' ? null : mb_substr($deger, 0, $uzunluk);
    }

    private function tarih(?string $iso): string
    {
        if (!$iso) {
            return date('Y-m-d H:i:s');
        }

        $d = date_create($iso);

        return $d ? $d->format('Y-m-d H:i:s') : date('Y-m-d H:i:s');
    }

    // ─── Kanal durumu ve log ─────────────────────────────────────────────────

    /**
     * İmleci kanal ayarlarına yazar.
     */
    private function imlecKaydet(int $sonId): void
    {
        $this->ayarlar['son_id']       = $sonId;
        $this->ayarlar['son_senkron']  = date('Y-m-d H:i:s');

        $this->db->update(
            'EntegrasyonKanallari',
            [
                'kanal_ayarlar'    => json_encode($this->ayarlar, JSON_UNESCAPED_UNICODE),
                'GuncellemeTarihi' => date('Y-m-d H:i:s'),
            ],
            ['kanal_id' => $this->kanal['kanal_id']]
        );
    }

    /**
     * İmleci elle geri alır (geçmiş yükleme için).
     */
    public function imlecAyarla(int $sonId): void
    {
        $this->imlecKaydet($sonId);
    }

    public function imlec(): int
    {
        return (int) ($this->ayarlar['son_id'] ?? 0);
    }

    private function logla(string $tip, string $sonuc, string $mesaj, ?string $hata, ?int $kullaniciId): void
    {
        try {
            $this->db->insert('EntegrasyonLoglari', [
                'log_kanal_id'       => (int) $this->kanal['kanal_id'],
                'log_tip'            => $tip,
                'log_konu'           => 'Portal banka hareket senkronu',
                'log_mesaj'          => $mesaj,
                'log_sonuc'          => $sonuc,
                'log_hata'           => $hata,
                'OlusturanKullanici' => $kullaniciId,
                'OlusturmaTarihi'    => date('Y-m-d H:i:s'),
                'Durum'              => 1,
            ]);
        } catch (Throwable $e) {
            error_log('Portal senkron log yazılamadı: ' . $e->getMessage());
        }
    }
}
