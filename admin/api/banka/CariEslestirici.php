<?php
/**
 * Banka Hareketi - Cari Eşleştirici
 *
 * Hareket satırlarını Cari kayıtlarıyla eşleştirip sonucu
 * BankaHesapHareketleri.hareket_oneri_cari_id kolonuna yazar.
 *
 * Böylece liste ve InfoBox sorguları eşleştirmeyi her açılışta
 * yeniden hesaplamak yerine hazır kolonu okur.
 *
 * Kolon anlamı:
 *   NULL -> henüz bakılmadı
 *   0    -> bakıldı, eşleşme yok
 *   >0   -> önerilen cari_id
 */

require_once __DIR__ . '/../../db.php';

class CariEslestirici
{
    private Database $db;

    /** Açıklama içinde aranacak en kısa cari adı uzunluğu */
    private const MIN_AD_UZUNLUK = 8;

    /** cari listesi önbelleği */
    private ?array $cariler = null;
    private array $vknEndeks = [];
    private array $adEndeks  = [];

    public function __construct()
    {
        $this->db = Database::getInstance();
    }

    /**
     * Bakılmamış hareketleri eşleştirir.
     *
     * @param bool $tumu true ise daha önce bakılmış satırlar da yeniden değerlendirilir
     */
    public function calistir(bool $tumu = false, ?int $sinir = null): array
    {
        $this->cariYukle();

        $kosul = $tumu ? '1 = 1' : 'h.hareket_oneri_cari_id IS NULL';
        $top   = $sinir ? 'TOP ' . (int) $sinir . ' ' : '';

        $satirlar = $this->db->fetchAll(
            "SELECT {$top}h.hareket_id, h.hareket_vkn_tc, h.hareket_gonderen_ad, h.hareket_aciklama
             FROM BankaHesapHareketleri h
             WHERE h.hareket_durum = 1 AND h.hareket_islendi = 0 AND {$kosul}
             ORDER BY h.hareket_id DESC"
        );

        $eslesen = 0;
        $bos     = 0;

        foreach ($satirlar as $satir) {
            $cariId = $this->eslestir($satir) ?? 0;

            $this->db->update(
                'BankaHesapHareketleri',
                ['hareket_oneri_cari_id' => $cariId],
                ['hareket_id' => $satir['hareket_id']]
            );

            $cariId > 0 ? $eslesen++ : $bos++;
        }

        return [
            'incelenen' => count($satirlar),
            'eslesen'   => $eslesen,
            'bos'       => $bos,
            'message'   => sprintf('%d satır incelendi, %d eşleşme bulundu.', count($satirlar), $eslesen),
        ];
    }

    /**
     * Tek hareket için önerilen cari_id — bulunamazsa null.
     *
     * Sıra: VKN/TCKN -> gönderen adı -> unvan -> açıklama içinde cari adı
     */
    public function eslestir(array $hareket): ?int
    {
        $vkn = preg_replace('/\D/', '', (string) ($hareket['hareket_vkn_tc'] ?? ''));

        if ($vkn !== '' && isset($this->vknEndeks[$vkn])) {
            return $this->vknEndeks[$vkn];
        }

        $gonderen = $this->normalize($hareket['hareket_gonderen_ad'] ?? '');

        if ($gonderen !== '' && isset($this->adEndeks[$gonderen])) {
            return $this->adEndeks[$gonderen];
        }

        $aciklama = $this->normalize($hareket['hareket_aciklama'] ?? '');

        if ($aciklama !== '') {
            foreach ($this->cariler as $cari) {
                if ($cari['ad_uzunluk'] >= self::MIN_AD_UZUNLUK
                    && strpos($aciklama, $cari['ad_normal']) !== false) {
                    return $cari['id'];
                }
            }
        }

        return null;
    }

    /**
     * Cari listesini belleğe alır ve arama endekslerini kurar.
     */
    private function cariYukle(): void
    {
        if ($this->cariler !== null) {
            return;
        }

        $this->cariler = [];

        $satirlar = $this->db->fetchAll(
            "SELECT cari_id, cari_adi, cari_unvan, cari_vergi_no FROM Cari"
        );

        foreach ($satirlar as $c) {
            $id = (int) $c['cari_id'];

            $vkn = preg_replace('/\D/', '', (string) ($c['cari_vergi_no'] ?? ''));
            if ($vkn !== '' && !isset($this->vknEndeks[$vkn])) {
                $this->vknEndeks[$vkn] = $id;
            }

            foreach (['cari_adi', 'cari_unvan'] as $alan) {
                $deger = $this->normalize($c[$alan] ?? '');
                if ($deger !== '' && !isset($this->adEndeks[$deger])) {
                    $this->adEndeks[$deger] = $id;
                }
            }

            $ad = $this->normalize($c['cari_adi'] ?? '');
            if ($ad !== '') {
                $this->cariler[] = [
                    'id'         => $id,
                    'ad_normal'  => $ad,
                    'ad_uzunluk' => mb_strlen($ad),
                ];
            }
        }

        // Uzun adlar önce denenir; kısa ad uzun adın parçasıysa yanlış eşleşme olmasın.
        usort($this->cariler, fn($a, $b) => $b['ad_uzunluk'] <=> $a['ad_uzunluk']);
    }

    /**
     * Karşılaştırma için metni sadeleştirir:
     * Türkçe harfler ASCII'ye indirgenir, büyük harfe çevrilir, boşluklar tekleştirilir.
     */
    private function normalize(?string $metin): string
    {
        if ($metin === null) {
            return '';
        }

        $metin = strtr($metin, [
            'ı' => 'I', 'İ' => 'I', 'i' => 'I',
            'ş' => 'S', 'Ş' => 'S',
            'ğ' => 'G', 'Ğ' => 'G',
            'ü' => 'U', 'Ü' => 'U',
            'ö' => 'O', 'Ö' => 'O',
            'ç' => 'C', 'Ç' => 'C',
        ]);

        $metin = mb_strtoupper($metin, 'UTF-8');
        $metin = preg_replace('/\s+/u', ' ', $metin);

        return trim($metin);
    }
}
