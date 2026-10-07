<?php
/**
 * SmsHelper - EkoMesaj SMS Gönderim Yardımcısı
 *
 * Ayarlar Entegrasyonlar / EntegrasyonKanallari tablolarından okunur.
 * Hiçbir hassas bilgi (kullanıcı/şifre) kod içinde tutulmaz.
 *
 * API sözleşmesi (sms-api-postman-json-collection.json):
 *   sendingType 0 = tekil        -> number  (STRING olmalı)
 *   sendingType 1 = 1'e çok      -> numbers ["90...", ...]
 *   sendingType 2 = çoka çok     -> numbers [{nr, msg}]
 *   encoding    0 = GSM7, 1 = Türkçe karakter (UCS2)
 *
 * Kullanım:
 *   require_once __DIR__ . '/SmsHelper.php';
 *   $sonuc = SmsHelper::gonder('5000000005', 'Mesaj metni', $kullaniciId);
 *   // $sonuc = ['success' => bool, 'message' => string]
 */
class SmsHelper
{
    /** Tek pakette gönderilebilecek azami numara adedi */
    const TOPLU_LIMIT = 500;

    /** Gönderim isteği zaman aşımı (saniye) */
    const TIMEOUT_TEKIL = 15;
    const TIMEOUT_TOPLU = 120;

    /**
     * Telefon numarasını 90XXXXXXXXXX formatına normalize eder.
     * Geçersizse null döner.
     */
    public static function normalizeTelefon(?string $telefon): ?string
    {
        $t = preg_replace('/\D/', '', (string) $telefon);
        if ($t === '') return null;

        // Baştaki 0'ları temizle (0532... -> 532...)
        $t = ltrim($t, '0');

        // 90 ile başlamıyorsa ve 10 haneyse (5XXXXXXXXX) ülke kodu ekle
        if (strlen($t) === 10) {
            $t = '90' . $t;
        } elseif (strlen($t) === 12 && strpos($t, '90') === 0) {
            // zaten 90XXXXXXXXXX
        } else {
            return null; // beklenmeyen uzunluk
        }

        // 90 + 10 hane = 12 karakter ve cep 5 ile başlamalı
        if (strlen($t) !== 12 || $t[2] !== '5') return null;

        return $t;
    }

    /**
     * Numara listesini normalize eder, tekrarları ve geçersizleri ayıklar.
     *
     * @return array ['gecerli' => ['90...' => ham, ...], 'gecersiz' => [ham, ...]]
     */
    public static function normalizeListe(array $telefonlar): array
    {
        $gecerli  = [];
        $gecersiz = [];

        foreach ($telefonlar as $ham) {
            $n = self::normalizeTelefon((string) $ham);
            if ($n === null) {
                $gecersiz[] = (string) $ham;
                continue;
            }
            if (!isset($gecerli[$n])) {
                $gecerli[$n] = (string) $ham;
            }
        }

        return ['gecerli' => $gecerli, 'gecersiz' => $gecersiz];
    }

    // ─────────────────────────── Kanal ───────────────────────────

    /**
     * Aktif EkoMesaj SMS kanallarını listeler.
     */
    public static function kanallar(?Database $db = null): array
    {
        $db = $db ?: Database::getInstance();

        return $db->fetchAll("
            SELECT k.kanal_id, k.kanal_ad, k.kanal_kod, k.kanal_endpoint,
                   k.kanal_ayarlar, e.entegrasyon_url
            FROM EntegrasyonKanallari k
            JOIN Entegrasyonlar e ON k.kanal_entegrasyon_id = e.entegrasyon_id
            WHERE e.entegrasyon_kod = 'ekomesaj_sms'
              AND e.entegrasyon_durum = 1
              AND k.kanal_durum = 1
            ORDER BY k.kanal_id
        ") ?: [];
    }

    /**
     * Belirtilen kanalı, verilmemişse ilk aktif kanalı döner.
     */
    public static function kanal(?Database $db = null, int $kanalId = 0): ?array
    {
        $db = $db ?: Database::getInstance();

        if ($kanalId > 0) {
            return $db->fetchOne("
                SELECT TOP 1 k.kanal_id, k.kanal_ad, k.kanal_kod, k.kanal_endpoint,
                       k.kanal_ayarlar, e.entegrasyon_url
                FROM EntegrasyonKanallari k
                JOIN Entegrasyonlar e ON k.kanal_entegrasyon_id = e.entegrasyon_id
                WHERE k.kanal_id = ?
                  AND e.entegrasyon_kod = 'ekomesaj_sms'
                  AND e.entegrasyon_durum = 1
                  AND k.kanal_durum = 1
            ", [$kanalId]) ?: null;
        }

        $liste = self::kanallar($db);
        return $liste ? $liste[0] : null;
    }

    /**
     * Kanal satırından API kimlik bilgilerini çıkarır.
     *
     * @return array ['ok' => bool, 'hata' => string, 'url' => string,
     *                'username' => string, 'password' => string, 'sender' => string]
     */
    private static function kimlik(array $kanal): array
    {
        $ayarlar  = json_decode($kanal['kanal_ayarlar'] ?? '{}', true) ?: [];
        $username = (string) $kanal['kanal_ad'];
        $password = (string) ($ayarlar['sifre'] ?? '');
        $sender   = (string) $kanal['kanal_kod'];
        $url      = (string) ($kanal['entegrasyon_url'] ?? '');

        if ($url === '') {
            return ['ok' => false, 'hata' => 'SMS entegrasyon adresi tanımlı değil.'];
        }
        if ($password === '' || $sender === '') {
            return ['ok' => false, 'hata' => 'SMS kanal ayarları eksik (şifre/başlık).'];
        }

        return [
            'ok'       => true,
            'hata'     => '',
            'url'      => $url,
            'username' => $username,
            'password' => $password,
            'sender'   => $sender,
        ];
    }

    // ─────────────────────────── Gönderim ───────────────────────────

    /**
     * Tek numaraya SMS gönderir (sendingType = 0).
     *
     * @param string $telefon     Ham telefon (5XXXXXXXXX / 0532... / 90532...)
     * @param string $mesaj       Gönderilecek metin
     * @param int    $kullaniciId Log için işlemi yapan kullanıcı
     * @param string $konu        Log konusu / paket başlığı
     * @param bool   $commercial  Ticari ileti (İYS onayı gerektirir)
     * @param int    $kanalId     0 ise ilk aktif kanal
     */
    public static function gonder(
        string $telefon,
        string $mesaj,
        int $kullaniciId = 0,
        string $konu = 'Otomatik SMS',
        bool $commercial = false,
        int $kanalId = 0
    ): array {
        $db = Database::getInstance();

        $hedef = self::normalizeTelefon($telefon);
        if ($hedef === null) {
            return ['success' => false, 'message' => 'Geçersiz telefon numarası: ' . $telefon];
        }

        $kanal = self::kanal($db, $kanalId);
        if (!$kanal) {
            return ['success' => false, 'message' => 'Aktif EkoMesaj SMS kanalı bulunamadı.'];
        }

        $k = self::kimlik($kanal);
        if (!$k['ok']) {
            return ['success' => false, 'message' => $k['hata']];
        }

        $payload = [
            'type'        => 1,
            'sendingType' => 0,
            'title'       => self::paketBasligi($konu),
            'content'     => $mesaj,
            'number'      => $hedef, // STRING olmalı; int'e çevrilirse baştaki hane kaybolabilir
            'encoding'    => 1,      // 1 = Türkçe karakter (UCS2)
            'sender'      => $k['sender'],
            'validity'    => 60,
            'commercial'  => $commercial,
        ];

        $result = self::istek($k, '/sms/create', $payload, 'POST', self::TIMEOUT_TEKIL);

        self::log($db, $kanal['kanal_id'], $hedef, $konu, $mesaj, $result, $kullaniciId);

        return ['success' => $result['success'], 'message' => $result['message']];
    }

    /**
     * Aynı içeriği birden çok numaraya tek istekte gönderir (sendingType = 1).
     * Numara sayısı TOPLU_LIMIT'i aşarsa paketlere bölünür.
     *
     * @return array [
     *   'success'      => bool,   // hiç başarısız paket yoksa true
     *   'message'      => string, // özet metin
     *   'toplam'       => int,    // gönderilmeye çalışılan geçerli numara adedi
     *   'basarili'     => int,
     *   'basarisiz'    => int,
     *   'gecersiz'     => array,  // formatı tutmayan ham numaralar
     *   'detay'        => array   // ['phone'=>, 'success'=>, 'message'=>]
     * ]
     */
    public static function gonderToplu(
        array $telefonlar,
        string $mesaj,
        int $kullaniciId = 0,
        string $konu = 'Toplu SMS',
        bool $commercial = false,
        int $kanalId = 0
    ): array {
        $db = Database::getInstance();

        $ayirma   = self::normalizeListe($telefonlar);
        $gecerli  = array_keys($ayirma['gecerli']);
        $gecersiz = $ayirma['gecersiz'];

        $sonuc = [
            'success'   => false,
            'message'   => '',
            'toplam'    => count($gecerli),
            'basarili'  => 0,
            'basarisiz' => 0,
            'gecersiz'  => $gecersiz,
            'detay'     => [],
        ];

        if (!$gecerli) {
            $sonuc['message'] = 'Gönderilecek geçerli numara yok.';
            return self::topluDetayTamamla($sonuc, $gecersiz);
        }

        $kanal = self::kanal($db, $kanalId);
        if (!$kanal) {
            $sonuc['message'] = 'Aktif EkoMesaj SMS kanalı bulunamadı.';
            return self::topluDetayTamamla($sonuc, $gecersiz);
        }

        $k = self::kimlik($kanal);
        if (!$k['ok']) {
            $sonuc['message'] = $k['hata'];
            return self::topluDetayTamamla($sonuc, $gecersiz);
        }

        $parcalar    = array_chunk($gecerli, self::TOPLU_LIMIT);
        $parcaSayisi = count($parcalar);
        $logSatirlar = [];

        foreach ($parcalar as $i => $parca) {
            // Sağlayıcı aynı başlık + içerik tekrarını reddediyor (ERR_SMS_PKG_DUPLICATION)
            $baslik = self::paketBasligi($konu);
            if ($parcaSayisi > 1) {
                $baslik .= ' (' . ($i + 1) . '/' . $parcaSayisi . ')';
            }

            $payload = [
                'type'        => 1,
                'sendingType' => 1,
                'title'       => $baslik,
                'content'     => $mesaj,
                'numbers'     => array_values($parca),
                'encoding'    => 1,
                'sender'      => $k['sender'],
                'validity'    => 1440,
                'commercial'  => $commercial,
            ];

            $r = self::istek($k, '/sms/create', $payload, 'POST', self::TIMEOUT_TOPLU);

            foreach ($parca as $phone) {
                $r['success'] ? $sonuc['basarili']++ : $sonuc['basarisiz']++;
                $sonuc['detay'][] = [
                    'phone'   => $phone,
                    'success' => $r['success'],
                    'message' => $r['success'] ? 'Gönderildi' : $r['message'],
                ];
                $logSatirlar[] = self::logSatir($kanal['kanal_id'], $phone, $konu, $mesaj, $r, $kullaniciId);
            }
        }

        self::logToplu($db, $logSatirlar);

        $sonuc['success'] = ($sonuc['basarisiz'] === 0);
        $sonuc['message'] = self::ozetMetin($sonuc);

        return self::topluDetayTamamla($sonuc, $gecersiz);
    }

    /**
     * Her numaraya kişiye özel alanlarla, tek şablondan gönderim yapar.
     * Şablondaki anahtarlar (ör. NAME) numbers[].cfs değerleriyle değiştirilir.
     *
     * @param array  $alicilar ['telefon' => '05...', 'alanlar' => ['NAME' => 'Ahmet']] satırları
     * @param string $sablon   Anahtarları içeren metin: "Sayın NAME, bakiyeniz TUTAR TL."
     */
    public static function gonderKisisel(
        array $alicilar,
        string $sablon,
        int $kullaniciId = 0,
        string $konu = 'Kişiselleştirilmiş SMS',
        bool $commercial = false,
        int $kanalId = 0
    ): array {
        $db = Database::getInstance();

        $sonuc = [
            'success'   => false,
            'message'   => '',
            'toplam'    => 0,
            'basarili'  => 0,
            'basarisiz' => 0,
            'gecersiz'  => [],
            'detay'     => [],
        ];

        // Numaraları normalize et, tekrarları ve alan anahtarlarını topla
        $satirlar = [];
        $anahtar  = [];
        foreach ($alicilar as $a) {
            $ham = (string) ($a['telefon'] ?? '');
            $n   = self::normalizeTelefon($ham);
            if ($n === null) {
                $sonuc['gecersiz'][] = $ham;
                continue;
            }
            if (isset($satirlar[$n])) continue;

            $alanlar = [];
            foreach ((array) ($a['alanlar'] ?? []) as $ad => $deger) {
                $ad = strtoupper(preg_replace('/[^A-Za-z0-9_]/', '', (string) $ad));
                if ($ad === '') continue;
                $alanlar[$ad] = (string) $deger;
                $anahtar[$ad] = true;
            }
            $satirlar[$n] = $alanlar;
        }

        $sonuc['toplam'] = count($satirlar);

        if (!$satirlar) {
            $sonuc['message'] = 'Gönderilecek geçerli numara yok.';
            return self::topluDetayTamamla($sonuc, $sonuc['gecersiz']);
        }

        $kanal = self::kanal($db, $kanalId);
        if (!$kanal) {
            $sonuc['message'] = 'Aktif EkoMesaj SMS kanalı bulunamadı.';
            return self::topluDetayTamamla($sonuc, $sonuc['gecersiz']);
        }

        $k = self::kimlik($kanal);
        if (!$k['ok']) {
            $sonuc['message'] = $k['hata'];
            return self::topluDetayTamamla($sonuc, $sonuc['gecersiz']);
        }

        $customFields = array_keys($anahtar);
        $parcalar     = array_chunk($satirlar, self::TOPLU_LIMIT, true);
        $parcaSayisi  = count($parcalar);
        $logSatirlar  = [];

        foreach ($parcalar as $i => $parca) {
            $baslik = self::paketBasligi($konu);
            if ($parcaSayisi > 1) {
                $baslik .= ' (' . ($i + 1) . '/' . $parcaSayisi . ')';
            }

            $numbers = [];
            foreach ($parca as $phone => $alanlar) {
                $satir = ['nr' => (string) $phone];
                if ($customFields) {
                    // Eksik anahtarlar boş geçilsin, şablonda ham anahtar kalmasın
                    $cfs = [];
                    foreach ($customFields as $ad) {
                        $cfs[$ad] = $alanlar[$ad] ?? '';
                    }
                    $satir['cfs'] = $cfs;
                }
                $numbers[] = $satir;
            }

            $payload = [
                'type'        => 1,
                'sendingType' => 1,
                'title'       => $baslik,
                'content'     => $sablon,
                'numbers'     => $numbers,
                'encoding'    => 1,
                'sender'      => $k['sender'],
                'validity'    => 1440,
                'commercial'  => $commercial,
            ];
            if ($customFields) {
                $payload['customFields'] = $customFields;
            }

            $r = self::istek($k, '/sms/create', $payload, 'POST', self::TIMEOUT_TOPLU);

            foreach ($parca as $phone => $alanlar) {
                $r['success'] ? $sonuc['basarili']++ : $sonuc['basarisiz']++;
                // Logda okunabilir olsun diye anahtarlar değerleriyle değiştirilir
                $metin = $customFields
                    ? str_replace($customFields, array_map(function ($ad) use ($alanlar) {
                        return $alanlar[$ad] ?? '';
                    }, $customFields), $sablon)
                    : $sablon;

                $sonuc['detay'][] = [
                    'phone'   => $phone,
                    'success' => $r['success'],
                    'message' => $r['success'] ? 'Gönderildi' : $r['message'],
                ];
                $logSatirlar[] = self::logSatir($kanal['kanal_id'], (string) $phone, $konu, $metin, $r, $kullaniciId);
            }
        }

        self::logToplu($db, $logSatirlar);

        $sonuc['success'] = ($sonuc['basarisiz'] === 0);
        $sonuc['message'] = self::ozetMetin($sonuc);

        return self::topluDetayTamamla($sonuc, $sonuc['gecersiz']);
    }

    // ─────────────────────────── Sorgular ───────────────────────────

    /**
     * Hesabın kalan SMS kredisini sorgular (GET /user/credit).
     *
     * @return array ['success' => bool, 'kredi' => float|null, 'ham' => string, 'message' => string]
     */
    public static function kredi(int $kanalId = 0): array
    {
        $db    = Database::getInstance();
        $kanal = self::kanal($db, $kanalId);

        if (!$kanal) {
            return ['success' => false, 'kredi' => null, 'ham' => '', 'message' => 'Aktif EkoMesaj SMS kanalı bulunamadı.'];
        }

        $k = self::kimlik($kanal);
        if (!$k['ok']) {
            return ['success' => false, 'kredi' => null, 'ham' => '', 'message' => $k['hata']];
        }

        $r = self::istek($k, '/user/credit', null, 'GET', self::TIMEOUT_TEKIL);

        if (!$r['success']) {
            return ['success' => false, 'kredi' => null, 'ham' => $r['ham'], 'message' => $r['message']];
        }

        return [
            'success' => true,
            'kredi'   => self::krediAyristir($r['ham']),
            'ham'     => $r['ham'],
            'message' => '',
        ];
    }

    /**
     * Hesapta tanımlı gönderici (sender / başlık) listesini döner.
     *
     * @return array ['success' => bool, 'data' => array, 'message' => string]
     */
    public static function gondericiler(int $kanalId = 0): array
    {
        $db    = Database::getInstance();
        $kanal = self::kanal($db, $kanalId);

        if (!$kanal) {
            return ['success' => false, 'data' => [], 'message' => 'Aktif EkoMesaj SMS kanalı bulunamadı.'];
        }

        $k = self::kimlik($kanal);
        if (!$k['ok']) {
            return ['success' => false, 'data' => [], 'message' => $k['hata']];
        }

        $r = self::istek($k, '/sms/list-sender', [
            'keyword'   => null,
            'status'    => null,
            'pageIndex' => 0,
            'pageSize'  => 100,
        ], 'POST', self::TIMEOUT_TEKIL);

        if (!$r['success']) {
            return ['success' => false, 'data' => [], 'message' => $r['message']];
        }

        $json = json_decode($r['ham'], true);
        $data = [];
        if (is_array($json)) {
            $liste = $json['data']['items'] ?? $json['data'] ?? $json['items'] ?? $json;
            if (is_array($liste)) {
                foreach ($liste as $s) {
                    if (is_array($s)) {
                        $ad = $s['name'] ?? $s['sender'] ?? $s['title'] ?? null;
                        if ($ad !== null) $data[] = (string) $ad;
                    } elseif (is_string($s)) {
                        $data[] = $s;
                    }
                }
            }
        }

        return ['success' => true, 'data' => array_values(array_unique($data)), 'message' => ''];
    }

    // ─────────────────────────── Yardımcılar ───────────────────────────

    /**
     * Paket başlığı: sağlayıcı aynı başlık + içerik tekrarını reddettiği için
     * her pakete zaman damgası eklenir.
     */
    private static function paketBasligi(string $konu): string
    {
        $konu = trim($konu) !== '' ? trim($konu) : 'SMS';
        return mb_substr($konu, 0, 40) . ' - ' . date('d.m.Y H:i:s');
    }

    /**
     * EkoMesaj API isteği (HTTP Basic Auth).
     *
     * @param array      $k       kimlik() çıktısı
     * @param string     $path    /sms/create gibi
     * @param array|null $payload POST gövdesi (GET'te null)
     * @return array ['success' => bool, 'message' => string, 'ham' => string]
     */
    private static function istek(array $k, string $path, ?array $payload, string $method = 'POST', int $timeout = 15): array
    {
        $url = rtrim($k['url'], '/') . $path;

        $secenekler = [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER     => ['Content-Type: application/json', 'Accept: application/json'],
            CURLOPT_USERPWD        => $k['username'] . ':' . $k['password'],
            CURLOPT_HTTPAUTH       => CURLAUTH_BASIC,
            CURLOPT_TIMEOUT        => $timeout,
            CURLOPT_SSL_VERIFYPEER => false,
        ];

        if (strtoupper($method) === 'POST') {
            $secenekler[CURLOPT_POST]       = true;
            $secenekler[CURLOPT_POSTFIELDS] = json_encode($payload ?: [], JSON_UNESCAPED_UNICODE);
        }

        $ch = curl_init($url);
        curl_setopt_array($ch, $secenekler);
        $res  = curl_exec($ch);
        $err  = curl_error($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($res === false) {
            return ['success' => false, 'message' => 'cURL hatası: ' . $err, 'ham' => ''];
        }
        if ($code < 200 || $code >= 300) {
            return ['success' => false, 'message' => "HTTP {$code}: " . mb_substr($res, 0, 300), 'ham' => $res];
        }

        $json = json_decode($res, true);
        if (is_array($json) && ((isset($json['error']) && $json['error']) || (isset($json['success']) && !$json['success']))) {
            $hata = $json['message'] ?? $json['error'] ?? 'Bilinmeyen SMS hatası';
            return [
                'success' => false,
                'message' => is_string($hata) ? $hata : json_encode($hata, JSON_UNESCAPED_UNICODE),
                'ham'     => $res,
            ];
        }

        return ['success' => true, 'message' => 'SMS başarıyla gönderildi.', 'ham' => $res];
    }

    /**
     * Kredi yanıtından sayısal bakiyeyi çıkarır; çözümlenemezse null.
     */
    private static function krediAyristir(string $ham): ?float
    {
        $json = json_decode($ham, true);

        if (is_array($json)) {
            $aday = $json['data'] ?? $json;
            if (is_numeric($aday)) return (float) $aday;

            if (is_array($aday)) {
                foreach (['credit', 'credits', 'balance', 'amount', 'kredi', 'bakiye'] as $ad) {
                    if (isset($aday[$ad]) && is_numeric($aday[$ad])) return (float) $aday[$ad];
                }
                foreach ($aday as $deger) {
                    if (is_numeric($deger)) return (float) $deger;
                }
            }
            return null;
        }

        return is_numeric(trim($ham)) ? (float) trim($ham) : null;
    }

    /**
     * Geçersiz numaraları sonuç detayına ekler.
     */
    private static function topluDetayTamamla(array $sonuc, array $gecersiz): array
    {
        foreach ($gecersiz as $ham) {
            $sonuc['detay'][] = [
                'phone'   => $ham,
                'success' => false,
                'message' => 'Geçersiz numara formatı',
            ];
        }
        if ($sonuc['message'] === '') {
            $sonuc['message'] = self::ozetMetin($sonuc);
        }
        return $sonuc;
    }

    private static function ozetMetin(array $sonuc): string
    {
        $parcalar = [
            'Toplam: ' . $sonuc['toplam'],
            'Başarılı: ' . $sonuc['basarili'],
            'Başarısız: ' . $sonuc['basarisiz'],
        ];
        if (!empty($sonuc['gecersiz'])) {
            $parcalar[] = 'Geçersiz: ' . count($sonuc['gecersiz']);
        }
        return implode(' | ', $parcalar);
    }

    // ─────────────────────────── Loglama ───────────────────────────

    /**
     * Tek log satırı için kolon dizisi üretir.
     */
    private static function logSatir(int $kanalId, string $hedef, string $konu, string $mesaj, array $result, int $kullaniciId): array
    {
        return [
            'log_kanal_id'       => $kanalId,
            'log_tip'            => 'sms',
            'log_alici'          => $hedef,
            'log_konu'           => $konu,
            'log_mesaj'          => $mesaj,
            'log_sonuc'          => $result['success'] ? 'başarılı' : 'basarisiz',
            'log_hata'           => $result['success'] ? null : mb_substr((string) $result['message'], 0, 500),
            'OlusturanKullanici' => $kullaniciId,
            'OlusturmaTarihi'    => date('Y-m-d H:i:s'),
            'Durum'              => 1,
        ];
    }

    /**
     * Tek kayıt loglar. Log yazılamazsa çağıranı bozmaz.
     */
    private static function log(Database $db, int $kanalId, string $hedef, string $konu, string $mesaj, array $result, int $kullaniciId): void
    {
        try {
            $db->insert('EntegrasyonLoglari', self::logSatir($kanalId, $hedef, $konu, $mesaj, $result, $kullaniciId));
        } catch (\Throwable $e) {
            // log yazılamazsa sessiz geç
        }
    }

    /**
     * Numara başına bir satır olacak şekilde toplu insert (MSSQL 1000 satır sınırı için parçalanır).
     */
    private static function logToplu(Database $db, array $satirlar): void
    {
        if (!$satirlar) return;

        $kolonlar = array_keys($satirlar[0]);
        $kolonSay = count($kolonlar);
        // MSSQL: tek INSERT'te en fazla 1000 satır ve 2100 parametre
        $adim = max(1, min(1000, (int) floor(2000 / $kolonSay)));

        foreach (array_chunk($satirlar, $adim) as $parca) {
            $params = [];
            $values = [];
            foreach ($parca as $satir) {
                $values[] = '(' . implode(', ', array_fill(0, $kolonSay, '?')) . ')';
                foreach ($kolonlar as $kolon) {
                    $params[] = $satir[$kolon];
                }
            }

            $sql = 'INSERT INTO EntegrasyonLoglari (' . implode(', ', $kolonlar) . ') VALUES '
                 . implode(', ', $values);

            try {
                $db->execute($sql, $params);
            } catch (\Throwable $e) {
                // log yazılamazsa sessiz geç
            }
        }
    }
}
