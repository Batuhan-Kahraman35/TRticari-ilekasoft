<?php
/**
 * Bildirim - Merkezi bildirim yönetimi
 * Tüm bildirimler dbo.Bildirimler tablosundan yönetilir.
 * Oluşturma + (opsiyonel) push gönderimi + listeleme + okundu işaretleme.
 */
require_once __DIR__ . '/PwaHelper.php';

class Bildirim
{
    /**
     * Yeni bildirim oluşturur; push_gonder=1 ise kullanıcının cihazlarına push atar.
     *
     * @param array $veri {
     *   kullanici_id (int, hedef; 0/null = genel),
     *   baslik (string), govde (string), url (string),
     *   tip (string: info|basari|uyari|hata), push (bool, varsayılan true),
     *   olusturan (int)
     * }
     * @return int Oluşan bildirim_id
     */
    public static function olustur(Database $db, array $veri): int
    {
        $pushGonder = array_key_exists('push', $veri) ? (int)(bool)$veri['push'] : 1;
        $olusturan  = (int)($veri['olusturan'] ?? ($veri['kullanici_id'] ?? 0));

        $bildirimId = $db->insert('dbo.Bildirimler', [
            'bildirim_kullanici_id' => $veri['kullanici_id'] ?? null,
            'bildirim_baslik'       => mb_substr($veri['baslik'] ?? '', 0, 200),
            'bildirim_govde'        => mb_substr($veri['govde'] ?? '', 0, 1000),
            'bildirim_url'          => mb_substr($veri['url'] ?? '/admin/', 0, 500),
            'bildirim_tip'          => $veri['tip'] ?? 'info',
            'bildirim_okundu'       => 0,
            'bildirim_push_gonder'  => $pushGonder,
            'bildirim_push_gonderildi' => 0,
            'OlusturanKullanici'    => $olusturan,
            'OlusturmaTarihi'       => date('Y-m-d H:i:s'),
            'Durum'                 => 1,
        ]);

        if ($pushGonder && !empty($veri['kullanici_id'])) {
            self::push($db, (int)$bildirimId);
        }
        return (int)$bildirimId;
    }

    /** Bildirimi ilgili kullanıcının cihazlarına push olarak gönderir */
    public static function push(Database $db, int $bildirimId): array
    {
        $b = $db->fetchOne("SELECT * FROM dbo.Bildirimler WHERE bildirim_id = ?", [$bildirimId]);
        if (!$b || empty($b['bildirim_kullanici_id'])) {
            return ['gonderilen' => 0, 'basarisiz' => 0];
        }
        $sonuc = PwaHelper::kullaniciyaGonder($db, (int)$b['bildirim_kullanici_id'], [
            'baslik' => $b['bildirim_baslik'],
            'govde'  => $b['bildirim_govde'],
            'url'    => $b['bildirim_url'],
            'tag'    => 'bildirim-' . $bildirimId,
        ]);
        if (($sonuc['gonderilen'] ?? 0) > 0) {
            $db->execute("UPDATE dbo.Bildirimler SET bildirim_push_gonderildi = 1 WHERE bildirim_id = ?", [$bildirimId]);
        }
        return $sonuc;
    }

    /**
     * Birden çok kullanıcıya aynı bildirimi oluşturur + push atar.
     * @return array ['bildirim'=>int oluşan kayıt, 'push'=>int push gönderilen cihaz]
     */
    public static function olusturCoklu(Database $db, array $kullaniciIdler, array $veri): array
    {
        $bildirimSayisi = 0; $pushToplam = 0;
        $pushIstenen = array_key_exists('push', $veri) ? (bool)$veri['push'] : true;
        foreach (array_unique(array_map('intval', $kullaniciIdler)) as $kid) {
            if ($kid <= 0) continue;
            $veri['kullanici_id'] = $kid;
            $veri['push'] = false; // push'u aşağıda tek sefer tetikle
            $bid = self::olustur($db, $veri);
            $bildirimSayisi++;
            if ($pushIstenen) {
                $r = self::push($db, $bid);
                $pushToplam += ($r['gonderilen'] ?? 0);
            }
        }
        return ['bildirim' => $bildirimSayisi, 'push' => $pushToplam];
    }

    /**
     * Genel duyuru: tek broadcast kaydı (kullanici_id NULL, herkesin çanında görünür)
     * + istenirse tüm cihazlara push.
     */
    public static function genelDuyuru(Database $db, array $veri): array
    {
        $pushIstenen = array_key_exists('push', $veri) ? (bool)$veri['push'] : true;
        $veri['kullanici_id'] = null;
        $veri['push'] = false;
        $bid = self::olustur($db, $veri);

        $push = ['gonderilen' => 0];
        if ($pushIstenen) {
            $push = PwaHelper::tumCihazlaraGonder($db, [
                'baslik' => $veri['baslik'] ?? '',
                'govde'  => $veri['govde'] ?? '',
                'url'    => $veri['url'] ?? '/admin/',
                'tag'    => 'bildirim-' . $bid,
            ]);
            if (($push['gonderilen'] ?? 0) > 0) {
                $db->execute("UPDATE dbo.Bildirimler SET bildirim_push_gonderildi = 1 WHERE bildirim_id = ?", [$bid]);
            }
        }
        return ['bildirim_id' => $bid, 'push' => $push['gonderilen'] ?? 0];
    }

    /** Kullanıcının bildirimlerini listeler (genel + kendine ait) */
    public static function listele(Database $db, int $kullaniciId, int $limit = 20): array
    {
        return $db->fetchAll("
            SELECT TOP (?) bildirim_id, bildirim_baslik, bildirim_govde, bildirim_url,
                   bildirim_tip, bildirim_okundu,
                   CONVERT(VARCHAR(19), OlusturmaTarihi, 120) AS olusturma_tarihi,
                   DATEDIFF(MINUTE, OlusturmaTarihi, GETDATE()) AS dakika_once
            FROM dbo.Bildirimler
            WHERE Durum = 1 AND (bildirim_kullanici_id = ? OR bildirim_kullanici_id IS NULL)
            ORDER BY bildirim_id DESC
        ", [$limit, $kullaniciId]);
    }

    /** Okunmamış bildirim sayısı */
    public static function okunmamisSayisi(Database $db, int $kullaniciId): int
    {
        $r = $db->fetchOne("
            SELECT COUNT(*) AS sayi FROM dbo.Bildirimler
            WHERE Durum = 1 AND bildirim_okundu = 0
              AND (bildirim_kullanici_id = ? OR bildirim_kullanici_id IS NULL)
        ", [$kullaniciId]);
        return (int)($r['sayi'] ?? 0);
    }

    /** Bir bildirimi (veya tümünü) okundu işaretle */
    public static function okunduYap(Database $db, int $kullaniciId, ?int $bildirimId = null): void
    {
        if ($bildirimId) {
            $db->execute("
                UPDATE dbo.Bildirimler SET bildirim_okundu = 1, bildirim_okunma_tarihi = GETDATE()
                WHERE bildirim_id = ? AND (bildirim_kullanici_id = ? OR bildirim_kullanici_id IS NULL)
            ", [$bildirimId, $kullaniciId]);
        } else {
            $db->execute("
                UPDATE dbo.Bildirimler SET bildirim_okundu = 1, bildirim_okunma_tarihi = GETDATE()
                WHERE bildirim_okundu = 0 AND (bildirim_kullanici_id = ? OR bildirim_kullanici_id IS NULL)
            ", [$kullaniciId]);
        }
    }
}
