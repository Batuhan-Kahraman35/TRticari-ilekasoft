<?php
/**
 * PwaHelper - PWA push için ortak yardımcılar
 * VAPID anahtarları dbo.tanim_site_ayarlari tablosundan okunur (hardcode yok).
 */
class PwaHelper
{
    /** VAPID yapılandırmasını entegrasyon kanalından (webpush/vapid) döner; eksikse null */
    public static function vapid(Database $db): ?array
    {
        $kanal = $db->fetchOne("
            SELECT k.kanal_ayarlar
            FROM EntegrasyonKanallari k
            INNER JOIN Entegrasyonlar e ON k.kanal_entegrasyon_id = e.entegrasyon_id
            WHERE e.entegrasyon_kod = 'webpush' AND k.kanal_kod = 'vapid'
              AND e.entegrasyon_durum = 1 AND k.kanal_durum = 1
        ");
        if (!$kanal || empty($kanal['kanal_ayarlar'])) {
            return null;
        }
        $ayar = json_decode($kanal['kanal_ayarlar'], true) ?: [];
        if (empty($ayar['public']) || empty($ayar['private'])) {
            return null;
        }
        return [
            'public'  => $ayar['public'],
            'private' => $ayar['private'],
            'subject' => $ayar['subject'] ?? 'mailto:destek@ornekproje.com',
        ];
    }

    /**
     * Bir kullanıcının aktif cihazlarına push gönderir.
     * Ölü abonelikleri (404/410) otomatik pasif yapar.
     * @return array ['gonderilen'=>int, 'basarisiz'=>int]
     */
    public static function kullaniciyaGonder(Database $db, int $kullaniciId, array $bildirim): array
    {
        require_once __DIR__ . '/WebPush.php';
        $vapid = self::vapid($db);
        if (!$vapid) return ['gonderilen' => 0, 'basarisiz' => 0, 'hata' => 'VAPID ayarlı değil'];

        $aboneler = $db->fetchAll("
            SELECT abonelik_id, abonelik_endpoint, abonelik_p256dh, abonelik_auth
            FROM dbo.Bildirim_Abonelikleri
            WHERE abonelik_kullanici_id = ? AND Durum = 1
        ", [$kullaniciId]);

        return self::aboneleregonder($db, $aboneler, $bildirim, $vapid);
    }

    /** Sistemdeki tüm aktif cihazlara push gönderir (genel duyuru) */
    public static function tumCihazlaraGonder(Database $db, array $bildirim): array
    {
        require_once __DIR__ . '/WebPush.php';
        $vapid = self::vapid($db);
        if (!$vapid) return ['gonderilen' => 0, 'basarisiz' => 0, 'hata' => 'VAPID ayarlı değil'];

        $aboneler = $db->fetchAll("
            SELECT abonelik_id, abonelik_endpoint, abonelik_p256dh, abonelik_auth
            FROM dbo.Bildirim_Abonelikleri WHERE Durum = 1
        ");
        return self::aboneleregonder($db, $aboneler, $bildirim, $vapid);
    }

    /** Verilen abonelik listesine push gönderir; ölüleri pasifler */
    private static function aboneleregonder(Database $db, array $aboneler, array $bildirim, array $vapid): array
    {
        $gonderilen = 0; $basarisiz = 0;
        $payload = json_encode($bildirim, JSON_UNESCAPED_UNICODE);
        foreach ($aboneler as $a) {
            $sonuc = WebPush::gonder(
                $a['abonelik_endpoint'], $a['abonelik_p256dh'], $a['abonelik_auth'], $payload, $vapid
            );
            if ($sonuc['kod'] >= 200 && $sonuc['kod'] < 300) {
                $gonderilen++;
            } else {
                $basarisiz++;
                if (in_array($sonuc['kod'], [404, 410], true)) {
                    $db->execute("UPDATE dbo.Bildirim_Abonelikleri SET Durum = 0, GuncellemeTarihi = GETDATE() WHERE abonelik_id = ?", [$a['abonelik_id']]);
                }
            }
        }
        return ['gonderilen' => $gonderilen, 'basarisiz' => $basarisiz];
    }
}
