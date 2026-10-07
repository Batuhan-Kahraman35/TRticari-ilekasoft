<?php
/**
 * Gorev: Konum Kayitlari Bakimi
 *
 * 1) Saklama suresini asan "sayfa" kayitlarini siler (giris/cikis/kayit/manuel korunur).
 * 2) IpSehir alani bos olan kayitlari ip-api.com uzerinden doldurur.
 *
 * Kaynak: admin/cron/konum-bakim.php (v1) - JSON ciktisi ve $_GET['test'] yerine
 * gorev sozlesmesine tasindi. Silme dongusu COUNT tabanli iken @@ROWCOUNT tabanli
 * yapildi: eski surumde her turda ekstra bir COUNT sorgusu atiliyordu.
 *
 * Onerilen zamanlama: 0 3 * * *   ·   TelafiEt = 1
 */

if (!defined('CRON_SISTEMI')) exit('Dogrudan calistirilamaz.');

require_once __DIR__ . '/../../includes/KonumHelper.php';

function gorev_konum_bakim(array $params, $db): array
{
    ob_start();

    $test    = !empty($params['test']);
    $ipLimit = (int)($params['ip_limit'] ?? 40);   // ip-api.com ucretsiz limiti: 45 istek/dk
    if ($ipLimit < 1)   $ipLimit = 1;
    if ($ipLimit > 200) $ipLimit = 200;

    if ($test) echo "*** TEST MODU - veritabanina yazilmayacak ***\n";

    $ayar       = KonumHelper::ayarlar($db);
    $saklamaGun = (int)($params['saklama_gun'] ?? $ayar['saklama_gun']);
    $silinen    = 0;
    $cozulen    = 0;
    $yerel      = 0;
    $hata       = 0;

    // ─── 1) Eski "sayfa" kayitlarini temizle ───
    if ($saklamaGun > 0) {
        echo "Saklama suresi: {$saklamaGun} gun\n";

        if ($test) {
            $sayac = $db->fetchOne("
                SELECT COUNT(*) AS Adet
                FROM dbo.Cihaz_Konum_Kayitlari
                WHERE Cihaz_Konum_Kayitlari_OlayTipi = 'sayfa'
                  AND OlusturmaTarihi < DATEADD(DAY, -?, GETDATE())
            ", [$saklamaGun]);

            $silinen = (int)($sayac['Adet'] ?? 0);
            echo "  [TEST] Silinecek kayit: {$silinen}\n";

        } else {
            $tur = 0;
            do {
                $satir = $db->fetchOne("
                    SET NOCOUNT ON;
                    DELETE TOP (5000) FROM dbo.Cihaz_Konum_Kayitlari
                    WHERE Cihaz_Konum_Kayitlari_OlayTipi = 'sayfa'
                      AND OlusturmaTarihi < DATEADD(DAY, -?, GETDATE());
                    SELECT @@ROWCOUNT AS Adet;
                ", [$saklamaGun]);

                $bu = (int)($satir['Adet'] ?? 0);
                $silinen += $bu;
                $tur++;

                if ($bu > 0) echo "  Tur {$tur}: {$bu} kayit silindi.\n";

            } while ($bu === 5000 && $tur < 200);

            echo "  Toplam silinen: {$silinen}\n";
        }
    } else {
        echo "Saklama suresi 0 - silme adimi atlandi.\n";
    }

    // ─── 2) IP sehir bilgisini doldur ───
    $ipler = $db->fetchAll("
        SELECT DISTINCT TOP (" . $ipLimit . ") Cihaz_Konum_Kayitlari_Ip AS ip
        FROM dbo.Cihaz_Konum_Kayitlari
        WHERE Cihaz_Konum_Kayitlari_Ip IS NOT NULL
          AND Cihaz_Konum_Kayitlari_IpSehir IS NULL
    ");

    echo "Cozumlenecek IP: " . count($ipler) . " (limit {$ipLimit})\n";

    foreach ($ipler as $satir) {
        $ip = $satir['ip'];

        // Yerel ag adreslerini disariya sorma
        if (!filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
            if (!$test) {
                $db->execute("
                    UPDATE dbo.Cihaz_Konum_Kayitlari
                    SET Cihaz_Konum_Kayitlari_IpSehir = N'Yerel ag'
                    WHERE Cihaz_Konum_Kayitlari_Ip = ? AND Cihaz_Konum_Kayitlari_IpSehir IS NULL
                ", [$ip]);
            }
            $yerel++;
            continue;
        }

        $sehir = konumIpSehirBul($ip);

        if ($sehir === null) {
            echo "  UYARI: {$ip} cozumlenemedi.\n";
            $hata++;
            continue;
        }

        if (!$test) {
            $db->execute("
                UPDATE dbo.Cihaz_Konum_Kayitlari
                SET Cihaz_Konum_Kayitlari_IpSehir = ?
                WHERE Cihaz_Konum_Kayitlari_Ip = ? AND Cihaz_Konum_Kayitlari_IpSehir IS NULL
            ", [$sehir, $ip]);
        }

        echo "  {$ip} -> {$sehir}\n";
        $cozulen++;

        usleep(300000);   // ip-api.com hiz siniri
    }

    $ozet = ($test ? '[TEST] ' : '')
          . "{$silinen} kayit silindi, {$cozulen} IP cozuldu, {$yerel} yerel, {$hata} basarisiz";

    echo "\n[OK] {$ozet}\n";

    return [
        'durum' => $hata > 0 && $cozulen === 0 && count($ipler) > 0 ? 2 : 1,
        'sonuc' => $ozet,
        'cikti' => ob_get_clean(),
    ];
}

/**
 * IP'den sehir/ulke cozer (ip-api.com ucretsiz uc nokta).
 * @return string|null "Istanbul, TR" biciminde; basarisizsa null
 */
function konumIpSehirBul(string $ip): ?string
{
    $url = 'http://ip-api.com/json/' . urlencode($ip) . '?fields=status,country,countryCode,city&lang=tr';

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 5,
        CURLOPT_CONNECTTIMEOUT => 3,
    ]);
    $cevap = curl_exec($ch);
    $kod   = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($cevap === false || $kod !== 200) return null;

    $veri = json_decode($cevap, true);
    if (!is_array($veri) || ($veri['status'] ?? '') !== 'success') return null;

    $parcalar = array_filter([$veri['city'] ?? '', $veri['countryCode'] ?? '']);

    return $parcalar ? mb_substr(implode(', ', $parcalar), 0, 100) : null;
}
