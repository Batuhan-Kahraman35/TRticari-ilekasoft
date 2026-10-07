<?php
/**
 * KonumHelper - Cihaz ve konum olay kayıtları
 *
 * Tüm kayıtlar dbo.Cihaz_Konum_Kayitlari tablosuna olay bazlı yazılır.
 * Olay tipleri dbo.tanim_konum_olay_tipleri tablosundan okunur.
 * Ayarlar dbo.tanim_site_ayarlari üzerinden yönetilir.
 *
 * Kullanım (PHP tarafı):
 *   KonumHelper::kaydet($db, $kullaniciId, 'giris', ['kaynak' => 'login.php']);
 *
 * Kullanım (JS tarafı): /admin/api/konum-kaydet.php
 */

class KonumHelper
{
    private static ?array $ayarCache = null;
    private static ?array $olayCache = null;

    /** Site ayarlarını (takip aktif / throttle / saklama) tek seferde okur */
    public static function ayarlar(Database $db): array
    {
        if (self::$ayarCache !== null) {
            return self::$ayarCache;
        }

        $varsayilan = ['aktif' => 1, 'throttle_dk' => 15, 'saklama_gun' => 180];

        try {
            $r = $db->fetchOne("
                SELECT TOP 1
                    site_ayarlari_konum_takip_aktif  AS aktif,
                    site_ayarlari_konum_throttle_dk  AS throttle_dk,
                    site_ayarlari_konum_saklama_gun  AS saklama_gun
                FROM dbo.tanim_site_ayarlari
                ORDER BY site_ayarlari_id DESC
            ");
            if ($r) {
                $varsayilan = [
                    'aktif'       => (int)$r['aktif'],
                    'throttle_dk' => max(0, (int)$r['throttle_dk']),
                    'saklama_gun' => max(0, (int)$r['saklama_gun']),
                ];
            }
        } catch (Exception $e) {
            error_log('KonumHelper ayar hatası: ' . $e->getMessage());
        }

        self::$ayarCache = $varsayilan;
        return self::$ayarCache;
    }

    public static function aktifMi(Database $db): bool
    {
        return (bool)self::ayarlar($db)['aktif'];
    }

    /** Tanımlı olay tipleri (dropdown / rozet renkleri buradan beslenir) */
    public static function olayTipleri(Database $db): array
    {
        if (self::$olayCache !== null) {
            return self::$olayCache;
        }

        try {
            self::$olayCache = $db->fetchAll("
                SELECT tanim_konum_olay_tipleri_Kod  AS kod,
                       tanim_konum_olay_tipleri_Ad   AS ad,
                       tanim_konum_olay_tipleri_Renk AS renk,
                       tanim_konum_olay_tipleri_Ikon AS ikon
                FROM dbo.tanim_konum_olay_tipleri
                WHERE Durum = 1
                ORDER BY tanim_konum_olay_tipleri_Sira, tanim_konum_olay_tipleri_id
            ");
        } catch (Exception $e) {
            error_log('KonumHelper olay tipi hatası: ' . $e->getMessage());
            self::$olayCache = [];
        }

        return self::$olayCache;
    }

    /** Olay tipi kodu geçerli mi */
    public static function olayGecerliMi(Database $db, string $kod): bool
    {
        foreach (self::olayTipleri($db) as $o) {
            if ($o['kod'] === $kod) return true;
        }
        return false;
    }

    /** Gerçek istemci IP'si (proxy başlıkları dahil) */
    public static function ip(): string
    {
        $basliklar = ['HTTP_CF_CONNECTING_IP', 'HTTP_X_FORWARDED_FOR', 'HTTP_X_REAL_IP', 'REMOTE_ADDR'];
        foreach ($basliklar as $b) {
            if (empty($_SERVER[$b])) continue;
            $ip = trim(explode(',', $_SERVER[$b])[0]);
            if (filter_var($ip, FILTER_VALIDATE_IP)) return $ip;
        }
        return '';
    }

    /**
     * User-Agent'tan cihaz tipi / platform / tarayıcı çıkarır.
     * JS tarafı userAgentData ile daha isabetli veri gönderirse o öncelikli kullanılır.
     */
    public static function cihazCoz(string $ua): array
    {
        $sonuc = ['tip' => null, 'platform' => null, 'tarayıcı' => null];
        if ($ua === '') return $sonuc;

        // Cihaz tipi
        if (preg_match('/iPad|Tablet|PlayBook|Silk|(Android(?!.*Mobile))/i', $ua)) {
            $sonuc['tip'] = 'tablet';
        } elseif (preg_match('/Mobile|iPhone|iPod|Android|BlackBerry|Opera Mini|IEMobile/i', $ua)) {
            $sonuc['tip'] = 'mobil';
        } else {
            $sonuc['tip'] = 'masaustu';
        }

        // Platform
        if (preg_match('/Android[ \/]?([0-9._]+)?/i', $ua, $m)) {
            $sonuc['platform'] = trim('Android ' . ($m[1] ?? ''));
        } elseif (preg_match('/(iPhone|iPad|iPod).*?OS ([0-9_]+)/i', $ua, $m)) {
            $sonuc['platform'] = 'iOS ' . str_replace('_', '.', $m[2]);
        } elseif (preg_match('/Windows NT ([0-9.]+)/i', $ua, $m)) {
            $surumler = ['10.0' => '10/11', '6.3' => '8.1', '6.2' => '8', '6.1' => '7'];
            $sonuc['platform'] = 'Windows ' . ($surumler[$m[1]] ?? $m[1]);
        } elseif (preg_match('/Mac OS X ([0-9_.]+)/i', $ua, $m)) {
            $sonuc['platform'] = 'macOS ' . str_replace('_', '.', $m[1]);
        } elseif (stripos($ua, 'Linux') !== false) {
            $sonuc['platform'] = 'Linux';
        }

        // Tarayıcı (sıralama önemli: Edge/Opera, Chrome'dan önce)
        if (preg_match('/Edg[eA]?\/([0-9.]+)/i', $ua, $m)) {
            $sonuc['tarayıcı'] = 'Edge ' . explode('.', $m[1])[0];
        } elseif (preg_match('/OPR\/([0-9.]+)/i', $ua, $m)) {
            $sonuc['tarayıcı'] = 'Opera ' . explode('.', $m[1])[0];
        } elseif (preg_match('/SamsungBrowser\/([0-9.]+)/i', $ua, $m)) {
            $sonuc['tarayıcı'] = 'Samsung Internet ' . explode('.', $m[1])[0];
        } elseif (preg_match('/Firefox\/([0-9.]+)/i', $ua, $m)) {
            $sonuc['tarayıcı'] = 'Firefox ' . explode('.', $m[1])[0];
        } elseif (preg_match('/Chrome\/([0-9.]+)/i', $ua, $m)) {
            $sonuc['tarayıcı'] = 'Chrome ' . explode('.', $m[1])[0];
        } elseif (preg_match('/Version\/([0-9.]+).*Safari/i', $ua, $m)) {
            $sonuc['tarayıcı'] = 'Safari ' . explode('.', $m[1])[0];
        }

        return $sonuc;
    }

    /**
     * Son kayıttan bu yana geçen dakika (throttle kontrolü için).
     * Kayıt yoksa null döner.
     */
    public static function sonKayitDakika(Database $db, int $kullaniciId, ?string $olayTipi = null): ?int
    {
        $sql = "SELECT TOP 1 DATEDIFF(MINUTE, OlusturmaTarihi, GETDATE()) AS dk
                FROM dbo.Cihaz_Konum_Kayitlari
                WHERE Cihaz_Konum_Kayitlari_KullaniciId = ?";
        $params = [$kullaniciId];

        if ($olayTipi !== null) {
            $sql .= " AND Cihaz_Konum_Kayitlari_OlayTipi = ?";
            $params[] = $olayTipi;
        }
        $sql .= " ORDER BY Cihaz_Konum_Kayitlari_id DESC";

        $r = $db->fetchOne($sql, $params);
        return $r ? (int)$r['dk'] : null;
    }

    /**
     * Olay kaydı oluşturur.
     *
     * @param array $veri Opsiyonel alanlar:
     *   kaynak, aciklama,
     *   enlem, boylam, dogruluk, rakim, hiz, konum_durum, adres,
     *   cihaz_id, cihaz_tipi, platform, tarayıcı, model, ekran, pwa_mi,
     *   baglanti_tipi, pil_seviye, pil_sarjda, dil, saat_dilimi
     * @return int|null Oluşan kayıt id'si; takip kapalıysa veya hata varsa null
     */
    public static function kaydet(Database $db, int $kullaniciId, string $olayTipi, array $veri = []): ?int
    {
        if ($kullaniciId <= 0) return null;
        if (!self::aktifMi($db)) return null;

        $ua  = mb_substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 500);
        $coz = self::cihazCoz($ua);

        $kayit = [
            'Cihaz_Konum_Kayitlari_KullaniciId'  => $kullaniciId,
            'Cihaz_Konum_Kayitlari_OlayTipi'     => mb_substr($olayTipi, 0, 30),
            'Cihaz_Konum_Kayitlari_OlayKaynak'   => self::kes($veri['kaynak'] ?? basename($_SERVER['PHP_SELF'] ?? ''), 150),
            'Cihaz_Konum_Kayitlari_OlayAciklama' => self::kes($veri['aciklama'] ?? null, 255),

            'Cihaz_Konum_Kayitlari_Enlem'        => self::sayi($veri['enlem'] ?? null),
            'Cihaz_Konum_Kayitlari_Boylam'       => self::sayi($veri['boylam'] ?? null),
            'Cihaz_Konum_Kayitlari_Dogruluk'     => self::tamsayi($veri['dogruluk'] ?? null),
            'Cihaz_Konum_Kayitlari_Rakim'        => self::sayi($veri['rakim'] ?? null),
            'Cihaz_Konum_Kayitlari_Hiz'          => self::sayi($veri['hiz'] ?? null),
            'Cihaz_Konum_Kayitlari_KonumDurum'   => self::kes($veri['konum_durum'] ?? 'yok', 20),
            'Cihaz_Konum_Kayitlari_Adres'        => self::kes($veri['adres'] ?? null, 400),

            'Cihaz_Konum_Kayitlari_CihazId'      => self::kes($veri['cihaz_id'] ?? null, 64),
            'Cihaz_Konum_Kayitlari_CihazTipi'    => self::kes($veri['cihaz_tipi'] ?? $coz['tip'], 20),
            'Cihaz_Konum_Kayitlari_Platform'     => self::kes($veri['platform'] ?? $coz['platform'], 50),
            'Cihaz_Konum_Kayitlari_Tarayici'     => self::kes($veri['tarayici'] ?? $coz['tarayıcı'], 50),
            'Cihaz_Konum_Kayitlari_Model'        => self::kes($veri['model'] ?? null, 100),
            'Cihaz_Konum_Kayitlari_Ekran'        => self::kes($veri['ekran'] ?? null, 30),
            'Cihaz_Konum_Kayitlari_UserAgent'    => $ua !== '' ? $ua : null,
            'Cihaz_Konum_Kayitlari_PwaMi'        => !empty($veri['pwa_mi']) ? 1 : 0,

            'Cihaz_Konum_Kayitlari_Ip'           => self::kes(self::ip(), 45) ?: null,
            'Cihaz_Konum_Kayitlari_IpSehir'      => self::kes($veri['ip_sehir'] ?? null, 100),
            'Cihaz_Konum_Kayitlari_BaglantiTipi' => self::kes($veri['baglanti_tipi'] ?? null, 20),
            'Cihaz_Konum_Kayitlari_PilSeviye'    => self::tamsayi($veri['pil_seviye'] ?? null),
            'Cihaz_Konum_Kayitlari_PilSarjda'    => isset($veri['pil_sarjda']) ? (int)(bool)$veri['pil_sarjda'] : null,
            'Cihaz_Konum_Kayitlari_Dil'          => self::kes($veri['dil'] ?? null, 20),
            'Cihaz_Konum_Kayitlari_SaatDilimi'   => self::kes($veri['saat_dilimi'] ?? null, 60),

            'OlusturanKullanici'                 => $kullaniciId,
            'OlusturmaTarihi'                    => date('Y-m-d H:i:s'),
            'Durum'                              => 1,
        ];

        try {
            return (int)$db->insert('dbo.Cihaz_Konum_Kayitlari', $kayit);
        } catch (Exception $e) {
            // Kayıt hatası ana işlemi bozmamalı
            error_log('KonumHelper kaydet hatası: ' . $e->getMessage());
            return null;
        }
    }

    private static function kes($deger, int $uzunluk): ?string
    {
        if ($deger === null || $deger === '') return null;
        return mb_substr((string)$deger, 0, $uzunluk);
    }

    private static function sayi($deger): ?float
    {
        if ($deger === null || $deger === '' || !is_numeric($deger)) return null;
        return (float)$deger;
    }

    private static function tamsayi($deger): ?int
    {
        if ($deger === null || $deger === '' || !is_numeric($deger)) return null;
        return (int)$deger;
    }
}
