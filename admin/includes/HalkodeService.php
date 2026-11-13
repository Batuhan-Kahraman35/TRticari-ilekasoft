<?php
/**
 * HalkÖde (Platformode Payment Gateway V2) Servisi
 *
 * Link ile tahsilat: sözleşme için ödeme linki üretir, müşteriye SMS/e-posta
 * ile iletir, ödeme sonucunu Sale WebHook, return sayfası veya durum
 * sorgulama ile karşılayıp sözleşmeye ödeme satırı (Sozlesme_Odemeler) açar.
 *
 * Kimlik bilgileri EntegrasyonKanallari (entegrasyon_kod = HALKODE):
 *   kanal_endpoint : https://app.halkode.com.tr/ccpayment
 *   kanal_ayarlar  : {
 *       "merchant_id", "merchant_key" (Üyeişyeri Anahtarı, $2y$10$...),
 *       "app_id" (Uygulama Anahtarı), "app_secret" (Uygulama Parolası),
 *       "sale_web_hook_key" (panelde tanımlı Satış Webhook key adı),
 *       "banka_hesap_id" (ödeme satırına yazılacak banka_Hesap),
 *       "odeme_tipi_id" (Sozlesme_OdemeTipleri, Kredi Kartı),
 *       "site_url" (return / callback adresinin kökü),
 *       "_token", "_token_gecerlilik" (otomatik yazılır)
 *   }
 *
 * Servisler:
 *   POST /purchase/link   → ödeme linki (form-urlencoded, invoice JSON string)
 *   POST /api/token       → Bearer token (2 saat geçerli, kanal ayarında saklanır)
 *   POST /api/checkstatus → sipariş durumu (Bearer + hash_key)
 *
 * hash_key: AES-256-CBC ile "iv:salt:sifreli" üçlüsü; '/' karakteri '__' ile
 * değiştirilir. Çözülmüş gövde "status|total|invoice_id|order_id|currency".
 *
 * Mükerrer tahsilat koruması:
 *   - SiparisNo, ProcessId ve Odeme_id tabloda UNIQUE (filtreli)
 *   - ödeme satırı OUTPUT'lu koşullu UPDATE ile bağlanır; webhook, return
 *     sayfası ve cron aynı işlemi bildirse de ikinci satır oluşmaz
 *
 * @see admin/api/halkode-callback.php
 * @see admin/pages/halkode-odeme-linkleri.php
 * @see admin/cron/gorevler/halkode_durum_sorgula.php
 */

require_once __DIR__ . '/SmsHelper.php';
require_once __DIR__ . '/MailHelper.php';
require_once __DIR__ . '/LogHelper.php';

class HalkodeService
{
    /** Entegrasyonlar tablosundaki kod */
    const ENTEGRASYON_KOD = 'HALKODE';

    /** Link durumları (Halkode_Odeme_Linkleri_DurumKod) */
    const DURUM_OLUSTURULDU = 'OLUSTURULDU';
    const DURUM_GONDERILDI  = 'GONDERILDI';
    const DURUM_ODENDI      = 'ODENDI';
    const DURUM_BASARISIZ   = 'BASARISIZ';
    const DURUM_IPTAL       = 'IPTAL';

    /** Platformode başarı durum kodu */
    const BASARI_KODU = '100';

    /** Servis yolları */
    const YOL_LINK  = '/purchase/link';
    const YOL_TOKEN = '/api/token';
    const YOL_DURUM = '/api/checkstatus';

    private $db;
    private $kanalId;
    private $kanalCache = null;

    /**
     * @param int $kanalId 0 ise ilk aktif HALKODE kanalı kullanılır
     */
    public function __construct($db, int $kanalId = 0)
    {
        $this->db      = $db;
        $this->kanalId = $kanalId;
    }

    // ─────────────────────────────────────────────
    // KANAL / AYAR
    // ─────────────────────────────────────────────

    /** Aktif HALKODE kanalları (link oluşturmada üye işyeri seçimi için) */
    public static function kanallar($db): array
    {
        return $db->fetchAll("
            SELECT k.kanal_id, k.kanal_ad
            FROM dbo.EntegrasyonKanallari k
            INNER JOIN dbo.Entegrasyonlar e ON e.entegrasyon_id = k.kanal_entegrasyon_id
            WHERE e.entegrasyon_kod = ?
              AND e.entegrasyon_durum = 1
              AND k.kanal_durum = 1
            ORDER BY k.kanal_id
        ", [self::ENTEGRASYON_KOD]);
    }

    /**
     * HALKODE kanalını (endpoint + anahtarlar) getirir.
     * Anahtarlar Entegrasyonlar ekranından girilir, kodda tutulmaz.
     */
    private function kanal(): array
    {
        if ($this->kanalCache !== null) {
            return $this->kanalCache;
        }

        $sql = "
            SELECT TOP 1 k.*
            FROM dbo.EntegrasyonKanallari k
            INNER JOIN dbo.Entegrasyonlar e ON e.entegrasyon_id = k.kanal_entegrasyon_id
            WHERE e.entegrasyon_kod = ?
              AND e.entegrasyon_durum = 1
              AND k.kanal_durum = 1
        ";
        $params = [self::ENTEGRASYON_KOD];

        if ($this->kanalId > 0) {
            $sql     .= " AND k.kanal_id = ? ";
            $params[] = $this->kanalId;
        }

        $kanal = $this->db->fetchOne($sql . " ORDER BY k.kanal_id", $params);

        if (!$kanal) {
            throw new Exception('HalkÖde entegrasyon kanalı bulunamadı veya pasif.');
        }

        $kanal['ayar'] = !empty($kanal['kanal_ayarlar'])
            ? (json_decode($kanal['kanal_ayarlar'], true) ?? [])
            : [];

        foreach (['app_id' => 'Uygulama Anahtarı', 'app_secret' => 'Uygulama Parolası', 'merchant_key' => 'Üyeişyeri Anahtarı'] as $alan => $ad) {
            if (empty($kanal['ayar'][$alan])) {
                throw new Exception("HalkÖde kanal ayarlarında {$ad} ({$alan}) tanımlı değil.");
            }
        }

        if (empty($kanal['kanal_endpoint'])) {
            throw new Exception('HalkÖde kanalında servis adresi (endpoint) tanımlı değil.');
        }

        $this->kanalId    = (int)$kanal['kanal_id'];
        $this->kanalCache = $kanal;

        return $kanal;
    }

    private function ayar(string $anahtar, $varsayilan = null)
    {
        return $this->kanal()['ayar'][$anahtar] ?? $varsayilan;
    }

    public function kanalId(): int
    {
        return (int)$this->kanal()['kanal_id'];
    }

    /** Callback ve return adreslerinin türetildiği site kökü */
    private function siteKoku(): string
    {
        $url = (string)$this->ayar('site_url', '');

        if ($url === '') {
            $satir = $this->db->fetchOne("
                SELECT TOP 1 site_ayarlari_site_url AS url
                FROM dbo.tanim_site_ayarlari
                ORDER BY site_ayarlari_id DESC
            ");
            $url = (string)($satir['url'] ?? '');
        }

        if ($url === '') {
            throw new Exception('Site adresi bulunamadı; kanal ayarlarına site_url ekleyin.');
        }

        // Adres panel köküne (…/admin) işaret edebilir; callback yolu /admin ile başlar.
        return preg_replace('#/admin/?$#i', '', rtrim($url, '/'));
    }

    // ─────────────────────────────────────────────
    // HASH KEY
    // ─────────────────────────────────────────────

    /**
     * İstek imzası üretir: "invoice_id|merchant_key" verisi APP_SECRET ile
     * AES-256-CBC şifrelenir, "iv:salt:şifreli" biçiminde döner ('/' → '__').
     */
    public function hashKeyUret(string $invoiceId): string
    {
        $veri         = $invoiceId . '|' . $this->ayar('merchant_key');
        $iv           = substr(sha1((string)mt_rand()), 0, 16);
        $salt         = substr(sha1((string)mt_rand()), 0, 4);
        $saltliParola = hash('sha256', sha1((string)$this->ayar('app_secret')) . $salt);

        $sifreli = openssl_encrypt($veri, 'aes-256-cbc', $saltliParola, 0, $iv);

        return str_replace('/', '__', $iv . ':' . $salt . ':' . $sifreli);
    }

    /**
     * Gelen hash_key değerini çözer.
     *
     * @return array|null ['status','total','invoice_id','order_id','currency'] veya null
     */
    public function hashKeyCoz(string $hashKey): ?array
    {
        if ($hashKey === '') {
            return null;
        }

        $parcalar = explode(':', str_replace('__', '/', $hashKey));

        if (count($parcalar) < 3) {
            return null;
        }

        [$iv, $salt, $sifreli] = $parcalar;
        $saltliParola = hash('sha256', sha1((string)$this->ayar('app_secret')) . $salt);

        $cozulmus = openssl_decrypt($sifreli, 'aes-256-cbc', $saltliParola, 0, $iv);

        if ($cozulmus === false || strpos($cozulmus, '|') === false) {
            return null;
        }

        $alan = explode('|', $cozulmus);

        return [
            'status'     => $alan[0] ?? '',
            'total'      => $alan[1] ?? 0,
            'invoice_id' => $alan[2] ?? '',
            'order_id'   => $alan[3] ?? '',
            'currency'   => $alan[4] ?? '',
        ];
    }

    // ─────────────────────────────────────────────
    // İSTEK
    // ─────────────────────────────────────────────

    /**
     * Platformode servisine istek gönderir.
     *
     * @param  string $yol   Servis yolu (self::YOL_*)
     * @param  array  $govde İstek alanları
     * @param  string $bicim 'json' veya 'form'
     * @param  string $token Bearer token (gerekiyorsa)
     */
    private function istek(string $yol, array $govde, string $bicim = 'json', string $token = ''): array
    {
        $url       = rtrim((string)$this->kanal()['kanal_endpoint'], '/') . $yol;
        $basliklar = ['Accept: application/json'];

        if ($token !== '') {
            $basliklar[] = 'Authorization: Bearer ' . $token;
        }

        if ($bicim === 'form') {
            $veri        = http_build_query($govde);
            $basliklar[] = 'Content-Type: application/x-www-form-urlencoded';
        } else {
            $veri        = json_encode($govde, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            $basliklar[] = 'Content-Type: application/json';
        }

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $veri,
            CURLOPT_TIMEOUT        => 60,
            CURLOPT_HTTPHEADER     => $basliklar,
        ]);

        $ham      = curl_exec($ch);
        $httpKod  = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlHata = curl_error($ch);
        curl_close($ch);

        // Gizli alanlar loglanmaz
        $logIstek = $govde;
        unset($logIstek['app_secret'], $logIstek['merchant_key'], $logIstek['hash_key']);

        if ($curlHata) {
            $this->log($yol, $logIstek, null, false, 'cURL: ' . $curlHata);
            throw new Exception('HalkÖde bağlantı hatası: ' . $curlHata);
        }

        $yanit = json_decode((string)$ham, true);

        if (!is_array($yanit)) {
            $this->log($yol, $logIstek, ['http' => $httpKod, 'govde' => mb_substr((string)$ham, 0, 1000)], false,
                'Yanıt çözümlenemedi (HTTP ' . $httpKod . ')');
            throw new Exception('HalkÖde servisi beklenmeyen yanıt döndü (HTTP ' . $httpKod . ').');
        }

        $basarili = (string)($yanit['status_code'] ?? '') === self::BASARI_KODU
                 || strtolower((string)($yanit['status'] ?? '')) === 'true'
                 || !empty($yanit['link']);

        // Token yanıtı loga yazılmaz
        $logYanit = $yanit;
        unset($logYanit['data']['token']);

        $this->log($yol, $logIstek, $logYanit, $basarili,
            $basarili ? null : ($yanit['status_description'] ?? $yanit['message'] ?? 'Bilinmeyen hata'));

        return $yanit;
    }

    /** EntegrasyonLoglari kaydı; anahtarlar loglanmaz, log hatası akışı bozmaz */
    private function log(string $islem, $istek, $yanit, bool $basarili, ?string $hata, string $alici = ''): void
    {
        try {
            $this->db->insert('EntegrasyonLoglari', [
                'log_kanal_id'       => $this->kanalId ?: null,
                'log_tip'            => 'halkode',
                'log_alici'          => mb_substr($alici, 0, 255),
                'log_konu'           => 'HalkÖde ' . $islem,
                'log_mesaj'          => json_encode(['istek' => $istek, 'yanit' => $yanit], JSON_UNESCAPED_UNICODE),
                'log_sonuc'          => $basarili ? 'başarılı' : 'basarisiz',
                'log_hata'           => $hata !== null ? mb_substr($hata, 0, 500) : null,
                'OlusturanKullanici' => $_SESSION['kullanici_id'] ?? null,
                'OlusturmaTarihi'    => date('Y-m-d H:i:s'),
                'Durum'              => 1,
            ]);
        } catch (Throwable $e) {
            error_log('HalkÖde log kaydı hatası: ' . $e->getMessage());
        }
    }

    // ─────────────────────────────────────────────
    // TOKEN
    // ─────────────────────────────────────────────

    /**
     * Bearer token döner. Token 2 saat geçerlidir ve kanal ayarlarında
     * saklanır; süresi dolmadan yeniden istenmez.
     */
    private function token(): string
    {
        $kanal = $this->kanal();
        $ayar  = $kanal['ayar'];

        if (!empty($ayar['_token']) && !empty($ayar['_token_gecerlilik'])
            && strtotime($ayar['_token_gecerlilik']) > time() + 60) {
            return (string)$ayar['_token'];
        }

        $yanit = $this->istek(self::YOL_TOKEN, [
            'app_id'     => $ayar['app_id'],
            'app_secret' => $ayar['app_secret'],
        ]);

        $token = $yanit['data']['token'] ?? '';

        if ((string)($yanit['status_code'] ?? '') !== self::BASARI_KODU || $token === '') {
            throw new Exception('HalkÖde token alınamadı: ' . ($yanit['status_description'] ?? 'bilinmeyen hata'));
        }

        $ayar['_token']            = $token;
        $ayar['_token_gecerlilik'] = $yanit['data']['expires_at'] ?? date('Y-m-d H:i:s', time() + 7000);

        $this->db->update('EntegrasyonKanallari', [
            'kanal_ayarlar'    => json_encode($ayar, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'GuncellemeTarihi' => date('Y-m-d H:i:s'),
        ], [
            'kanal_id' => (int)$kanal['kanal_id'],
        ]);

        $this->kanalCache['ayar'] = $ayar;

        return $token;
    }

    // ─────────────────────────────────────────────
    // LİNK ÜRETİMİ
    // ─────────────────────────────────────────────

    /**
     * Sözleşme için ödeme linki üretir ve takip kaydını açar.
     *
     * Taksit 0 gönderilirse müşteri ödeme sayfasında kartının desteklediği
     * taksitlerden seçim yapar.
     *
     * @param  array $veri sozlesme_id, tutar, taksit, aciklama, telefon, email, kullanici_id
     * @return array       ['link_id' => int, 'link' => string, 'siparis_no' => string]
     */
    public function linkOlustur(array $veri): array
    {
        $sozlesmeId = (int)($veri['sozlesme_id'] ?? 0);
        $tutar      = round((float)($veri['tutar'] ?? 0), 2);
        $taksit     = max(0, (int)($veri['taksit'] ?? 0));
        $aciklama   = trim((string)($veri['aciklama'] ?? ''));
        $telefon    = preg_replace('/\D/', '', (string)($veri['telefon'] ?? ''));
        $email      = trim((string)($veri['email'] ?? ''));
        $kullanici  = (int)($veri['kullanici_id'] ?? 0);

        if (!$sozlesmeId) throw new Exception('Sözleşme seçilmedi.');
        if ($tutar <= 0)  throw new Exception('Tutar sıfırdan büyük olmalıdır.');

        $sozlesme = $this->db->fetchOne("
            SELECT s.sozlesme_id, s.sozlesme_no, s.sozlesme_cari_id,
                   ISNULL(NULLIF(c.cari_unvan, ''), c.cari_adi) AS unvan,
                   c.cari_telefon, c.cari_email
            FROM dbo.Sozlesmeler s
            INNER JOIN dbo.Cari c ON c.cari_id = s.sozlesme_cari_id
            WHERE s.sozlesme_id = ?
        ", [$sozlesmeId]);

        if (!$sozlesme) {
            throw new Exception('Sözleşme bulunamadı.');
        }

        // Kanal doğrulaması istek öncesinde: eksik ayarda takip kaydı açılmaz
        $kanalId = $this->kanalId();

        // invoice_id yalnız harf/rakam içermeli
        $siparisNo = 'TRT' . date('YmdHis') . random_int(100, 999);
        $simdi     = date('Y-m-d H:i:s');
        $unvan     = trim((string)$sozlesme['unvan']);

        // Takip kaydı istek öncesinde açılır: servis yanıt vermese bile hangi
        // sipariş numarasının gönderildiği kayıt altında kalır.
        $linkId = (int)$this->db->insert('Halkode_Odeme_Linkleri', [
            'Halkode_Odeme_Linkleri_Kanal_id'    => $kanalId,
            'Halkode_Odeme_Linkleri_Sozlesme_id' => $sozlesmeId,
            'Halkode_Odeme_Linkleri_Cari_id'     => (int)$sozlesme['sozlesme_cari_id'],
            'Halkode_Odeme_Linkleri_SiparisNo'   => $siparisNo,
            'Halkode_Odeme_Linkleri_Tutar'       => $tutar,
            'Halkode_Odeme_Linkleri_ParaBirimi'  => 'TRY',
            'Halkode_Odeme_Linkleri_Taksit'      => $taksit,
            'Halkode_Odeme_Linkleri_DurumKod'    => self::DURUM_OLUSTURULDU,
            'Halkode_Odeme_Linkleri_Telefon'     => $telefon ?: null,
            'Halkode_Odeme_Linkleri_Email'       => $email ?: null,
            'Halkode_Odeme_Linkleri_Aciklama'    => $aciklama ?: null,
            'OlusturanKullanici'                 => $kullanici ?: null,
            'OlusturmaTarihi'                    => $simdi,
            'GuncelleyenKullanici'               => $kullanici ?: null,
            'GuncellemeTarihi'                   => $simdi,
            'Durum'                              => 1,
        ]);

        $sonucUrl = $this->siteKoku() . '/admin/api/halkode-callback.php?tur=return';

        // Servis name ve surname alanlarını 30 karakterden kısa istiyor; unvan
        // ikiye bölünüp kırpılır, tam unvan fatura açıklamasında yer alır.
        $parca = preg_split('/\s+/', $unvan, 2);
        $ad    = mb_substr($parca[0] ?? $unvan, 0, 29) ?: 'Musteri';
        $soyad = mb_substr(trim((string)($parca[1] ?? '')), 0, 29) ?: '-';

        $varsayilanAciklama = 'Sözleşme ' . ($sozlesme['sozlesme_no'] ?: ('#' . $sozlesmeId)) . ' - ' . $unvan;

        $fatura = [
            'invoice_id'          => $siparisNo,
            'invoice_description' => mb_substr($aciklama !== '' ? $aciklama : $varsayilanAciklama, 0, 200),
            'total'               => number_format($tutar, 2, '.', ''),
            'items'               => [[
                'name'        => 'Tahsilat',
                'price'       => number_format($tutar, 2, '.', ''),
                'quantity'    => 1,
                'description' => mb_substr($aciklama !== '' ? $aciklama : $varsayilanAciklama, 0, 100),
            ]],
            'return_url'          => $sonucUrl,
            'cancel_url'          => $sonucUrl,
        ];

        $faturaEmail   = $email ?: trim((string)$sozlesme['cari_email']);
        $faturaTelefon = $telefon ?: preg_replace('/\D/', '', (string)$sozlesme['cari_telefon']);

        if ($faturaEmail !== '')   $fatura['bill_email'] = $faturaEmail;
        if ($faturaTelefon !== '') $fatura['bill_phone'] = $faturaTelefon;

        if ($this->ayar('sale_web_hook_key')) {
            $fatura['sale_web_hook_key'] = $this->ayar('sale_web_hook_key');
        }

        $govde = [
            'merchant_key'  => $this->ayar('merchant_key'),
            'invoice'       => json_encode($fatura, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'currency_code' => 'TRY',
            'name'          => $ad,
            'surname'       => $soyad,
        ];

        // Taksit gönderilirse ödeme sayfasında taksit seçimi gösterilmez
        if ($taksit > 1) {
            $govde['installments_number'] = $taksit;
        }

        try {
            $yanit = $this->istek(self::YOL_LINK, $govde, 'form');
        } catch (Throwable $e) {
            $this->durumYaz($linkId, self::DURUM_BASARISIZ, ['sonuc_mesaj' => $e->getMessage()], $kullanici);
            throw $e;
        }

        $link = $yanit['link'] ?? ($yanit['data']['link'] ?? '');

        if ($link === '') {
            $mesaj = $yanit['success_message']
                  ?? $yanit['status_description']
                  ?? $yanit['message']
                  ?? 'Ödeme linki üretilemedi.';

            $this->durumYaz($linkId, self::DURUM_BASARISIZ, [
                'sonuc_kod'   => $yanit['status_code'] ?? null,
                'sonuc_mesaj' => $mesaj,
            ], $kullanici);

            throw new Exception('HalkÖde: ' . $mesaj);
        }

        $this->db->update('Halkode_Odeme_Linkleri', [
            'Halkode_Odeme_Linkleri_Link' => $link,
            'GuncelleyenKullanici'        => $kullanici ?: null,
            'GuncellemeTarihi'            => date('Y-m-d H:i:s'),
        ], [
            'Halkode_Odeme_Linkleri_id' => $linkId,
        ]);

        return ['link_id' => $linkId, 'link' => $link, 'siparis_no' => $siparisNo];
    }

    /** Link kaydına durum ve sonuç alanlarını yazar */
    private function durumYaz(int $linkId, string $durumKod, array $alan, int $kullaniciId = 0): void
    {
        $veri = [
            'Halkode_Odeme_Linkleri_DurumKod' => $durumKod,
            'GuncelleyenKullanici'            => $kullaniciId ?: null,
            'GuncellemeTarihi'                => date('Y-m-d H:i:s'),
        ];

        if (array_key_exists('sonuc_kod', $alan)) {
            $veri['Halkode_Odeme_Linkleri_SonucKod'] = mb_substr((string)$alan['sonuc_kod'], 0, 20) ?: null;
        }
        if (array_key_exists('sonuc_mesaj', $alan)) {
            $veri['Halkode_Odeme_Linkleri_SonucMesaj'] = mb_substr((string)$alan['sonuc_mesaj'], 0, 500);
        }

        $this->db->update('Halkode_Odeme_Linkleri', $veri, ['Halkode_Odeme_Linkleri_id' => $linkId]);
    }

    // ─────────────────────────────────────────────
    // GÖNDERİM
    // ─────────────────────────────────────────────

    /**
     * Üretilen linki müşteriye iletir. API ile üretilen linkte gönderim üye
     * işyerine aittir; SMS ve e-posta kendi kanallarımızdan çıkar.
     *
     * @param  array $kanallar ['sms', 'mail'] alt kümesi
     * @param  array $hedef    ['telefon' => ..., 'email' => ...] verilirse kayda işlenir
     * @return array           ['sms' => bool|null, 'mail' => bool|null, 'hatalar' => string[]]
     */
    public function linkGonder(int $linkId, array $kanallar, int $kullaniciId = 0, array $hedef = []): array
    {
        $link = $this->linkGetir($linkId);

        if (!$link) {
            throw new Exception('Ödeme linki kaydı bulunamadı.');
        }
        if (empty($link['Halkode_Odeme_Linkleri_Link'])) {
            throw new Exception('Bu kayıt için henüz ödeme linki üretilmemiş.');
        }

        $yeniTelefon = preg_replace('/\D/', '', (string)($hedef['telefon'] ?? ''));
        $yeniEmail   = trim((string)($hedef['email'] ?? ''));

        if ($yeniTelefon !== '' || $yeniEmail !== '') {
            $guncel = ['GuncelleyenKullanici' => $kullaniciId ?: null, 'GuncellemeTarihi' => date('Y-m-d H:i:s')];

            if ($yeniTelefon !== '') {
                $guncel['Halkode_Odeme_Linkleri_Telefon'] = $yeniTelefon;
                $link['Halkode_Odeme_Linkleri_Telefon']   = $yeniTelefon;
            }
            if ($yeniEmail !== '') {
                $guncel['Halkode_Odeme_Linkleri_Email'] = $yeniEmail;
                $link['Halkode_Odeme_Linkleri_Email']   = $yeniEmail;
            }

            $this->db->update('Halkode_Odeme_Linkleri', $guncel, ['Halkode_Odeme_Linkleri_id' => $linkId]);
        }

        $tutar = number_format((float)$link['Halkode_Odeme_Linkleri_Tutar'], 2, ',', '.');
        $unvan = (string)($link['cari_unvan'] ?? '');
        $adres = (string)$link['Halkode_Odeme_Linkleri_Link'];
        $sonuc = ['sms' => null, 'mail' => null, 'hatalar' => []];

        if (in_array('sms', $kanallar, true)) {
            $telefon = $link['Halkode_Odeme_Linkleri_Telefon']
                ?: preg_replace('/\D/', '', (string)($link['cari_telefon'] ?? ''));

            if ($telefon === '') {
                $sonuc['hatalar'][] = 'Telefon numarası yok, SMS gönderilemedi.';
            } else {
                $mesaj = $tutar . ' TL tutarindaki odemeniz icin guvenli odeme linki: ' . $adres;
                $yanit = SmsHelper::gonder($telefon, $mesaj, $kullaniciId, 'HalkÖde ödeme linki');

                $sonuc['sms'] = !empty($yanit['success']);
                if (!$sonuc['sms']) {
                    $sonuc['hatalar'][] = 'SMS: ' . ($yanit['message'] ?? 'gönderilemedi');
                }
            }
        }

        if (in_array('mail', $kanallar, true)) {
            $email = $link['Halkode_Odeme_Linkleri_Email'] ?: trim((string)($link['cari_email'] ?? ''));

            if ($email === '') {
                $sonuc['hatalar'][] = 'E-posta adresi yok, mail gönderilemedi.';
            } else {
                $icerik = '<p>Sayın ' . htmlspecialchars($unvan) . ',</p>'
                        . '<p><b>' . $tutar . ' TL</b> tutarındaki ödemenizi aşağıdaki güvenli bağlantı üzerinden '
                        . 'kredi kartınızla gerçekleştirebilirsiniz.</p>'
                        . '<p><a href="' . htmlspecialchars($adres) . '">Ödeme yapmak için tıklayın</a></p>'
                        . '<p style="color:#888;font-size:12px">Bağlantı belirli bir süre sonra geçersiz olur.</p>';

                $yanit = MailHelper::gonder($this->db, [$email], 'Ödeme Bağlantınız', $icerik);

                $sonuc['mail'] = !empty($yanit['success']);
                if (!$sonuc['mail']) {
                    $sonuc['hatalar'][] = 'E-posta: ' . ($yanit['message'] ?? 'gönderilemedi');
                }
            }
        }

        if ($sonuc['sms'] || $sonuc['mail']) {
            $kanalAd = [];
            if ($sonuc['sms'])  $kanalAd[] = 'SMS';
            if ($sonuc['mail']) $kanalAd[] = 'E-posta';

            $veri = [
                'Halkode_Odeme_Linkleri_GonderimKanali' => implode(' + ', $kanalAd),
                'Halkode_Odeme_Linkleri_GonderimTarihi' => date('Y-m-d H:i:s'),
                'GuncelleyenKullanici'                  => $kullaniciId ?: null,
                'GuncellemeTarihi'                      => date('Y-m-d H:i:s'),
            ];

            // Ödenmiş/başarısız denemedeki durum geri alınmaz
            if ($link['Halkode_Odeme_Linkleri_DurumKod'] === self::DURUM_OLUSTURULDU) {
                $veri['Halkode_Odeme_Linkleri_DurumKod'] = self::DURUM_GONDERILDI;
            }

            $this->db->update('Halkode_Odeme_Linkleri', $veri, ['Halkode_Odeme_Linkleri_id' => $linkId]);
        }

        return $sonuc;
    }

    /**
     * Gönderim için seçilebilecek kişiler: cari kartı ve kartındaki yetkili.
     * Bilgisi eksik kişi de listelenir; eksik alan arayüzde gösterilir.
     */
    public function iletisimKisileri(int $cariId): array
    {
        $cari = $this->db->fetchOne("
            SELECT ISNULL(NULLIF(cari_unvan, ''), cari_adi) AS unvan, cari_telefon, cari_email,
                   cari_yetkili_adi, cari_yetkili_telefon, cari_yetkili_email
            FROM dbo.Cari WHERE cari_id = ?
        ", [$cariId]);

        if (!$cari) {
            return [];
        }

        $liste = [[
            'id'      => 'cari',
            'ad'      => trim((string)$cari['unvan']),
            'gorev'   => 'Cari kartı',
            'telefon' => preg_replace('/\D/', '', (string)($cari['cari_telefon'] ?? '')),
            'email'   => trim((string)($cari['cari_email'] ?? '')),
        ]];

        if (trim((string)$cari['cari_yetkili_adi']) !== '' || trim((string)$cari['cari_yetkili_telefon']) !== '') {
            $liste[] = [
                'id'      => 'yetkili',
                'ad'      => trim((string)$cari['cari_yetkili_adi']) ?: 'Yetkili',
                'gorev'   => 'Yetkili',
                'telefon' => preg_replace('/\D/', '', (string)($cari['cari_yetkili_telefon'] ?? '')),
                'email'   => trim((string)($cari['cari_yetkili_email'] ?? '')),
            ];
        }

        return $liste;
    }

    /** Link kaydını sözleşme ve cari bilgileriyle getirir */
    public function linkGetir(int $linkId): ?array
    {
        return self::linkSorgu($this->db, 'h.Halkode_Odeme_Linkleri_id = ?', [$linkId]);
    }

    /** Sipariş numarasından (invoice_id) link kaydını getirir; kanal bilinmeden çağrılabilir */
    public static function linkGetirSiparisNo($db, string $siparisNo): ?array
    {
        return self::linkSorgu($db, 'h.Halkode_Odeme_Linkleri_SiparisNo = ?', [$siparisNo]);
    }

    private static function linkSorgu($db, string $kosul, array $params): ?array
    {
        $satir = $db->fetchOne("
            SELECT h.*,
                   ISNULL(NULLIF(c.cari_unvan, ''), c.cari_adi) AS cari_unvan, c.cari_telefon, c.cari_email,
                   s.sozlesme_no, s.sozlesme_personel_id
            FROM dbo.Halkode_Odeme_Linkleri h
            LEFT JOIN dbo.Cari c        ON c.cari_id = h.Halkode_Odeme_Linkleri_Cari_id
            LEFT JOIN dbo.Sozlesmeler s ON s.sozlesme_id = h.Halkode_Odeme_Linkleri_Sozlesme_id
            WHERE {$kosul}
        ", $params);

        return $satir ?: null;
    }

    // ─────────────────────────────────────────────
    // SONUÇ İŞLEME
    // ─────────────────────────────────────────────

    /**
     * checkstatus servisiyle güncel durumu sorgular; tamamlanmışsa işler.
     * Webhook ulaşmadığında mutabakat yolu budur.
     *
     * Tamamlanmamış işlem link durumunu değiştirmez: müşteri henüz ödememiş
     * olabilir, link açık kalır ve sonraki sorgularda tekrar kontrol edilir.
     *
     * @return array ['islendi' => bool, 'durum' => string, 'odeme_id' => int|null, 'mesaj' => string]
     */
    public function durumSorgulaVeIsle(int $linkId, int $kullaniciId = 0): array
    {
        $link = $this->linkGetir($linkId);

        if (!$link) {
            throw new Exception('Ödeme linki kaydı bulunamadı.');
        }

        $invoiceId = (string)$link['Halkode_Odeme_Linkleri_SiparisNo'];

        $yanit = $this->istek(self::YOL_DURUM, [
            'invoice_id'   => $invoiceId,
            'merchant_key' => $this->ayar('merchant_key'),
            'hash_key'     => $this->hashKeyUret($invoiceId),
        ], 'json', $this->token());

        $tamamlandi = (string)($yanit['status_code'] ?? '') === self::BASARI_KODU
                   && strtolower((string)($yanit['transaction_status'] ?? '')) === 'completed';

        $mesaj = (string)($yanit['status_description'] ?? ($yanit['reason'] ?? ''));

        if (!$tamamlandi) {
            return [
                'islendi'  => false,
                'durum'    => $link['Halkode_Odeme_Linkleri_DurumKod'],
                'odeme_id' => null,
                'mesaj'    => $mesaj !== '' ? $mesaj : 'Ödeme henüz tamamlanmamış.',
            ];
        }

        $sonuc = $this->sonucIsle($link, [
            'basarili'   => true,
            'order_id'   => $yanit['order_id'] ?? null,
            'islem_no'   => $yanit['transaction_id'] ?? null,
            'durum_kodu' => $yanit['status_code'] ?? null,
            'mesaj'      => $mesaj,
            'ref_no'     => $yanit['ref_number'] ?? null,
        ], $kullaniciId);

        return $sonuc + ['mesaj' => $mesaj];
    }

    /**
     * Ödeme sonucunu link kaydına yazar; başarılı tahsilatta sözleşmeye ödeme
     * satırı açar. Ödenmiş linke gelen başarısız bildirim durumu bozmaz.
     *
     * @param  array $sonuc basarili, order_id, islem_no, durum_kodu, mesaj, ref_no
     * @return array ['islendi' => bool, 'durum' => string, 'odeme_id' => int|null]
     */
    public function sonucIsle(array $link, array $sonuc, int $kullaniciId = 0): array
    {
        $linkId   = (int)$link['Halkode_Odeme_Linkleri_id'];
        $basarili = !empty($sonuc['basarili']);
        $odenmis  = !empty($link['Halkode_Odeme_Linkleri_Odeme_id']);
        $simdi    = date('Y-m-d H:i:s');

        if (!$basarili && $odenmis) {
            return ['islendi' => false, 'durum' => self::DURUM_ODENDI, 'odeme_id' => (int)$link['Halkode_Odeme_Linkleri_Odeme_id']];
        }

        $veri = [
            'Halkode_Odeme_Linkleri_SonucKod'   => mb_substr((string)($sonuc['durum_kodu'] ?? ''), 0, 20) ?: null,
            'Halkode_Odeme_Linkleri_SonucMesaj' => mb_substr((string)($sonuc['mesaj'] ?? ''), 0, 500) ?: null,
            'Halkode_Odeme_Linkleri_DurumKod'   => $basarili ? self::DURUM_ODENDI : self::DURUM_BASARISIZ,
            'GuncelleyenKullanici'              => $kullaniciId ?: null,
            'GuncellemeTarihi'                  => $simdi,
        ];

        if (!empty($sonuc['order_id']))  $veri['Halkode_Odeme_Linkleri_ProcessId'] = mb_substr((string)$sonuc['order_id'], 0, 50);
        if (!empty($sonuc['islem_no']))  $veri['Halkode_Odeme_Linkleri_IslemNo']   = mb_substr((string)$sonuc['islem_no'], 0, 50);
        if (!empty($sonuc['ref_no']))    $veri['Halkode_Odeme_Linkleri_RefNo']     = mb_substr((string)$sonuc['ref_no'], 0, 50);
        if ($basarili && empty($link['Halkode_Odeme_Linkleri_OdemeTarihi'])) {
            $veri['Halkode_Odeme_Linkleri_OdemeTarihi'] = $simdi;
        }

        $this->db->update('Halkode_Odeme_Linkleri', $veri, ['Halkode_Odeme_Linkleri_id' => $linkId]);

        if (!$basarili) {
            return ['islendi' => false, 'durum' => self::DURUM_BASARISIZ, 'odeme_id' => null];
        }

        if ($odenmis) {
            return ['islendi' => false, 'durum' => self::DURUM_ODENDI, 'odeme_id' => (int)$link['Halkode_Odeme_Linkleri_Odeme_id']];
        }

        return $this->odemeOlustur($link, $sonuc);
    }

    /**
     * Sözleşmeye kredi kartı ödeme satırı açar ve link kaydına bağlar.
     * İkisi tek transaction içindedir; bağlama koşullu olduğu için yarış
     * durumunda ikinci çağrı kendi satırını geri alır.
     */
    private function odemeOlustur(array $link, array $sonuc): array
    {
        $linkId     = (int)$link['Halkode_Odeme_Linkleri_id'];
        $sozlesmeId = (int)$link['Halkode_Odeme_Linkleri_Sozlesme_id'];
        $olusturan  = (int)($link['OlusturanKullanici'] ?? 0) ?: null;

        // Tutar link tutarıdır; servisin döndüğü işlem tutarı komisyon
        // içerebildiği için sözleşme bakiyesini bozmaması adına kullanılmaz.
        $tutar = round((float)$link['Halkode_Odeme_Linkleri_Tutar'], 2);
        $simdi = date('Y-m-d H:i:s');
        $bugun = date('Y-m-d');

        $tipId = (int)$this->ayar('odeme_tipi_id', 0);
        $tip   = $this->db->fetchOne("
            SELECT odeme_tipi_id, odeme_tipi_varsayilan_durum_id
            FROM dbo.Sozlesme_OdemeTipleri
            WHERE odeme_tipi_id = ? AND odeme_tipi_durum = 1
        ", [$tipId]);

        if (!$tip) {
            throw new Exception('HalkÖde kanalındaki odeme_tipi_id geçersiz veya pasif.');
        }

        $bankaHesapId = (int)$this->ayar('banka_hesap_id', 0) ?: null;

        $aciklama = ['HalkÖde link ile tahsilat (' . $link['Halkode_Odeme_Linkleri_SiparisNo'] . ')'];
        if (!empty($sonuc['islem_no']))                    $aciklama[] = 'İşlem: ' . $sonuc['islem_no'];
        if (!empty($link['Halkode_Odeme_Linkleri_Aciklama'])) $aciklama[] = $link['Halkode_Odeme_Linkleri_Aciklama'];

        $belgeNo = (string)($sonuc['order_id'] ?? ($link['Halkode_Odeme_Linkleri_ProcessId'] ?? ($sonuc['ref_no'] ?? '')));

        $conn = $this->db->getConnection();
        sqlsrv_begin_transaction($conn);

        try {
            $odemeId = (int)$this->db->insert('Sozlesme_Odemeler', [
                'odeme_sozlesme_id'           => $sozlesmeId,
                'odeme_tipi_id'               => (int)$tip['odeme_tipi_id'],
                'odeme_durum_id'              => (int)$tip['odeme_tipi_varsayilan_durum_id'],
                'odeme_belge_no'              => mb_substr($belgeNo, 0, 50) ?: null,
                'odeme_tarih'                 => $bugun,
                'odeme_vade_tarih'            => $bugun,
                'odeme_tutar'                 => $tutar,
                'odeme_banka_hesap_id'        => $bankaHesapId,
                'odeme_guncel_banka_hesap_id' => $bankaHesapId,
                'odeme_aciklama'              => mb_substr(implode(' · ', $aciklama), 0, 500),
                'odeme_yapildi'               => 1,
                'odeme_olusturan_id'          => $olusturan,
                'odeme_olusturma_tarihi'      => $simdi,
                // Otomatik satır elle değiştirilmesin diye linki açan kişi adına kilitlenir
                'odeme_kilit_kullanici_id'    => $olusturan,
                'odeme_kilit_tarihi'          => $olusturan ? $simdi : null,
            ]);

            $bagli = $this->db->fetchOne("
                UPDATE dbo.Halkode_Odeme_Linkleri
                SET Halkode_Odeme_Linkleri_Odeme_id = ?,
                    GuncellemeTarihi                = ?
                OUTPUT INSERTED.Halkode_Odeme_Linkleri_id AS id
                WHERE Halkode_Odeme_Linkleri_id = ?
                  AND Halkode_Odeme_Linkleri_Odeme_id IS NULL
            ", [$odemeId, $simdi, $linkId]);

            if (!$bagli) {
                sqlsrv_rollback($conn);
                $guncel = $this->linkGetir($linkId);

                return [
                    'islendi'  => false,
                    'durum'    => self::DURUM_ODENDI,
                    'odeme_id' => (int)($guncel['Halkode_Odeme_Linkleri_Odeme_id'] ?? 0) ?: null,
                ];
            }

            sqlsrv_commit($conn);
        } catch (Throwable $e) {
            sqlsrv_rollback($conn);
            throw $e;
        }

        $yeni = $this->db->fetchOne("SELECT * FROM dbo.Sozlesme_Odemeler WHERE odeme_id = ?", [$odemeId]);
        logKayitDegisiklikleri($this->db, 'halkode-callback', 'Sozlesme_Odemeler', $odemeId, null, $yeni,
            (int)$olusturan, 'HalkÖde ödemesi otomatik eklendi');

        return ['islendi' => true, 'durum' => self::DURUM_ODENDI, 'odeme_id' => $odemeId];
    }

    // ─────────────────────────────────────────────
    // WEBHOOK / RETURN
    // ─────────────────────────────────────────────

    /**
     * Sale WebHook veya return isteğini çözer ve doğrular.
     *
     * İstekler açık uçtan geldiği için hash_key ile doğrulanır: çözülmüş
     * gövdedeki invoice_id istekteki invoice_id ile eşleşmelidir.
     *
     * @param  array $veri $_POST + $_GET birleşimi
     */
    public function bildirimCoz(array $veri): array
    {
        $invoiceId = trim((string)($veri['invoice_id'] ?? ''));
        $hashKey   = trim((string)($veri['hash_key'] ?? ''));

        if ($invoiceId === '') throw new Exception('Bildirimde invoice_id yok.');
        if ($hashKey === '')   throw new Exception('Bildirimde hash_key yok.');

        $cozulmus = $this->hashKeyCoz($hashKey);

        if (!$cozulmus) {
            throw new Exception('hash_key çözülemedi; istek doğrulanamadı.');
        }
        if ((string)$cozulmus['invoice_id'] !== $invoiceId) {
            throw new Exception('hash_key içindeki sipariş numarası istekle uyuşmuyor.');
        }

        // Hash içindeki durum imzalıdır; payment_status okunabilir biçimdedir
        $hashDurum = strtolower((string)$cozulmus['status']);
        $basarili  = ($hashDurum === 'completed' || $hashDurum === '1')
                  && (string)($veri['payment_status'] ?? '1') === '1';

        $this->log('bildirim', array_diff_key($veri, ['hash_key' => 1]), null, $basarili,
            $basarili ? null : (string)($veri['status_description'] ?? ($veri['error'] ?? 'Ödeme tamamlanmadı')), $invoiceId);

        return [
            'invoice_id' => $invoiceId,
            'basarili'   => $basarili,
            'order_id'   => $veri['order_id'] ?? ($veri['order_no'] ?? $cozulmus['order_id']),
            'islem_no'   => $veri['transaction_id'] ?? null,
            'durum_kodu' => $veri['status_code'] ?? ($veri['error_code'] ?? null),
            'mesaj'      => $veri['status_description'] ?? ($veri['error'] ?? null),
            'tutar'      => $cozulmus['total'] ?? null,
            'ref_no'     => $veri['ref_number'] ?? null,
        ];
    }
}
