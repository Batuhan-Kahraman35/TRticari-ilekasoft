<?php
/**
 * Konum Kayıtları Bakım Cron'u
 *
 * 1) Saklama süresini aşan "sayfa" kayıtlarını siler (giris/cikis/kayit/manuel korunur).
 * 2) IP şehir bilgisi boş olan kayıtları ip-api.com üzerinden doldurur.
 *
 * Plesk Scheduled Task:
 *   URL: https://ticari.ornekproje.com/admin/cron/konum-bakim.php
 *   Çalışma: günde 1 kez (gece)
 * Test: .../konum-bakim.php?test
 */

error_reporting(E_ALL);
ini_set('display_errors', 0);
ini_set('log_errors', 1);
date_default_timezone_set('Europe/Istanbul');
if (function_exists('mb_internal_encoding')) mb_internal_encoding('UTF-8');

require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../includes/KonumHelper.php';

header('Content-Type: application/json; charset=utf-8');

// Tek seferde çözümlenecek en fazla IP (ip-api.com ücretsiz limiti: 45 istek/dk)
const IP_LIMIT = 40;

$db     = Database::getInstance();
$test   = isset($_GET['test']);
$ozet   = ['silinen' => 0, 'ip_cozulen' => 0, 'hatalar' => []];

try {
    $ayar = KonumHelper::ayarlar($db);

    // ─── 1) Eski "sayfa" kayıtlarını temizle ───
    if ($ayar['saklama_gun'] > 0) {
        $sayac = $db->fetchOne("
            SELECT COUNT(*) AS sayi
            FROM dbo.Cihaz_Konum_Kayitlari
            WHERE Cihaz_Konum_Kayitlari_OlayTipi = 'sayfa'
              AND OlusturmaTarihi < DATEADD(DAY, -?, GETDATE())
        ", [$ayar['saklama_gun']]);

        $ozet['silinen'] = (int)($sayac['sayi'] ?? 0);

        if (!$test && $ozet['silinen'] > 0) {
            // Büyük tabloda kilit süresini kısa tutmak için parça parça sil
            do {
                $db->execute("
                    DELETE TOP (5000) FROM dbo.Cihaz_Konum_Kayitlari
                    WHERE Cihaz_Konum_Kayitlari_OlayTipi = 'sayfa'
                      AND OlusturmaTarihi < DATEADD(DAY, -?, GETDATE())
                ", [$ayar['saklama_gun']]);

                $kalan = $db->fetchOne("
                    SELECT COUNT(*) AS sayi
                    FROM dbo.Cihaz_Konum_Kayitlari
                    WHERE Cihaz_Konum_Kayitlari_OlayTipi = 'sayfa'
                      AND OlusturmaTarihi < DATEADD(DAY, -?, GETDATE())
                ", [$ayar['saklama_gun']]);
            } while ((int)($kalan['sayi'] ?? 0) > 0);
        }
    }

    // ─── 2) IP şehir bilgisini doldur ───
    $ipler = $db->fetchAll("
        SELECT DISTINCT TOP (" . IP_LIMIT . ") Cihaz_Konum_Kayitlari_Ip AS ip
        FROM dbo.Cihaz_Konum_Kayitlari
        WHERE Cihaz_Konum_Kayitlari_Ip IS NOT NULL
          AND Cihaz_Konum_Kayitlari_IpSehir IS NULL
    ");

    foreach ($ipler as $satir) {
        $ip = $satir['ip'];

        // Yerel ağ adreslerini sorgulama
        if (!filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
            if (!$test) {
                $db->execute("
                    UPDATE dbo.Cihaz_Konum_Kayitlari
                    SET Cihaz_Konum_Kayitlari_IpSehir = 'Yerel ağ'
                    WHERE Cihaz_Konum_Kayitlari_Ip = ? AND Cihaz_Konum_Kayitlari_IpSehir IS NULL
                ", [$ip]);
            }
            continue;
        }

        $sehir = ipSehirBul($ip);
        if ($sehir === null) {
            $ozet['hatalar'][] = $ip . ' çözümlenemedi';
            continue;
        }

        if (!$test) {
            $db->execute("
                UPDATE dbo.Cihaz_Konum_Kayitlari
                SET Cihaz_Konum_Kayitlari_IpSehir = ?
                WHERE Cihaz_Konum_Kayitlari_Ip = ? AND Cihaz_Konum_Kayitlari_IpSehir IS NULL
            ", [$sehir, $ip]);
        }
        $ozet['ip_cozulen']++;

        usleep(300000); // ip-api.com hız sınırı için 0.3 sn bekle
    }

    echo json_encode([
        'success' => true,
        'test'    => $test,
        'ozet'    => $ozet,
        'tarih'   => date('Y-m-d H:i:s'),
    ], JSON_UNESCAPED_UNICODE);

} catch (Exception $e) {
    error_log('konum-bakim hatası: ' . $e->getMessage());
    echo json_encode(['success' => false, 'message' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
}

/**
 * IP'den şehir/ülke bilgisi çözer (ip-api.com, ücretsiz uç nokta).
 * @return string|null "İstanbul, TR" biçiminde; başarısızsa null
 */
function ipSehirBul(string $ip): ?string
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
