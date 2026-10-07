<?php
/**
 * Otomatik Bildirim Cron (genel dağıtıcı)
 *
 * Kayıtlı bildirim görevlerini sırayla çalıştırır. Her görev, bir olay/koşul için
 * dbo.Bildirimler'e kayıt oluşturur (çan + push).
 *
 * YENİ OTOMATİK BİLDİRİM EKLEMEK:
 *   1) Aşağıya `gorevXYZ(Database $db): array` fonksiyonu yaz (özet dizisi döndür).
 *   2) $GOREVLER dizisine ekle: 'xyz' => 'gorevXYZ'.
 *
 * Plesk Scheduled Task:
 *   URL: https://ticari.ornekproje.com/admin/cron/bildirim-cron.php
 *   Çalışma: her 5 dakikada bir
 * Test    : .../bildirim-cron.php?test
 * Tek görev: .../bildirim-cron.php?gorev=destek
 *
 * Mükerrer önleme: EK TABLO YOK; dedup mevcut dbo.Bildirimler üzerinden yapılır.
 */

// İlk kez görülen destek ticket'ı için: bu süreden eski yanıtlar bildirilmez (saniye)
const DESTEK_ILK_PENCERE = 7200; // 2 saat

error_reporting(E_ALL);
ini_set('display_errors', 0);
ini_set('log_errors', 1);
date_default_timezone_set('Europe/Istanbul');
if (function_exists('mb_internal_encoding')) mb_internal_encoding('UTF-8');

require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../includes/Bildirim.php';

header('Content-Type: application/json; charset=utf-8');

$db = Database::getInstance();

// ─── Görev kayıtları ───
$GOREVLER = [
    'destek' => 'gorevDestekYanitlari',
    // 'tahsilat' => 'gorevTahsilatHatirlatma',   // ileride
    // 'sozlesme' => 'gorevSozlesmeAtama',         // ileride
];

$sadece = trim($_GET['gorev'] ?? '');
$sonuc  = [];
foreach ($GOREVLER as $ad => $fn) {
    if ($sadece !== '' && $sadece !== $ad) continue;
    try {
        $sonuc[$ad] = $fn($db);
    } catch (Exception $e) {
        $sonuc[$ad] = ['success' => false, 'hata' => $e->getMessage()];
    }
}

echo json_encode(['success' => true, 'gorevler' => $sonuc], JSON_UNESCAPED_UNICODE) . "\n";


/* ══════════════════════════════════════════════════════════════════
 *  GÖREV: Destek Yanıtları
 *  Destek API'de bir ticket'a DESTEK yanıtı geldiğinde kullanıcıya bildir.
 * ════════════════════════════════════════════════════════════════ */
function gorevDestekYanitlari(Database $db): array
{
    $ayar   = $db->fetchOne("SELECT TOP 1 site_ayarlari_destek_api_key, site_ayarlari_destek_api_url FROM dbo.tanim_site_ayarlari ORDER BY site_ayarlari_id DESC");
    $apiKey = $ayar['site_ayarlari_destek_api_key'] ?? '';
    $apiUrl = $ayar['site_ayarlari_destek_api_url'] ?? '';
    if (!$apiKey || !$apiUrl) {
        return ['success' => false, 'hata' => 'Destek API yapılandırması eksik.'];
    }

    $kullanicilar = $db->fetchAll("
        SELECT kullanici_id, kullanici_email
        FROM kullanicilar
        WHERE kullanici_durum = 1 AND kullanici_email IS NOT NULL AND LTRIM(RTRIM(kullanici_email)) <> ''
    ");

    $olusan = 0; $bakilan = 0; $hataliKullanici = 0;

    foreach ($kullanicilar as $k) {
        $eposta = $k['kullanici_email'];
        $kid    = (int)$k['kullanici_id'];

        $liste = destekApiCagir($apiUrl, $apiKey, ['action' => 'list_tickets', 'eposta' => $eposta]);
        if (!$liste || !($liste['success'] ?? false)) { $hataliKullanici++; continue; }

        foreach (($liste['data'] ?? []) as $t) {
            $ticketId = (int)($t['Tickets_id'] ?? 0);
            $sonYanit = $t['son_yanit_tarihi'] ?? null;
            if (!$ticketId || !$sonYanit) continue;
            $bakilan++;

            // Dedup: bu ticket için daha önce oluşturulmuş son "Destek Yanıtı" bildirimi
            $onceki = $db->fetchOne("
                SELECT TOP 1 CONVERT(VARCHAR(19), OlusturmaTarihi, 120) AS son
                FROM dbo.Bildirimler
                WHERE bildirim_kullanici_id = ? AND bildirim_baslik = 'Destek Yanıtı'
                  AND bildirim_url LIKE ?
                ORDER BY bildirim_id DESC
            ", [$kid, '%destek-detay.php?id=' . $ticketId]);

            if ($onceki && !empty($onceki['son'])) {
                if (strtotime($sonYanit) <= strtotime($onceki['son'])) continue; // ilerlememiş
            } else {
                if (strtotime($sonYanit) < time() - DESTEK_ILK_PENCERE) continue; // ilk çalıştırma flood önleme
            }

            // Son mesaj destekten mi? ('api' = kullanıcının kendi mesajı)
            $detay = destekApiCagir($apiUrl, $apiKey, ['action' => 'ticket_detail', 'ticket_id' => $ticketId, 'eposta' => $eposta]);
            $son = enYeniMesaj($detay['data']['mesajlar'] ?? []);
            if (!$son || ($son['mesaj_kaynak'] ?? '') === 'api') continue;

            Bildirim::olustur($db, [
                'kullanici_id' => $kid,
                'baslik'       => 'Destek Yanıtı',
                'govde'        => 'Talebinize yanıt geldi: #' . ($t['Tickets_no'] ?? '') . ' ' . ($t['Tickets_konu'] ?? ''),
                'url'          => '/admin/pages/destek-detay.php?id=' . $ticketId,
                'tip'          => 'info',
                'push'         => true,
                'olusturan'    => 0,
            ]);
            $olusan++;
        }
    }

    return ['success' => true, 'kullanici' => count($kullanicilar), 'bakilan_ticket' => $bakilan,
            'olusan_bildirim' => $olusan, 'api_hatali_kullanici' => $hataliKullanici];
}

/* ── Yardımcılar ── */
function destekApiCagir(string $url, string $apiKey, array $payload): ?array
{
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_USERAGENT      => 'TRticari-Bildirim-Cron/1.0',
        CURLOPT_POST           => true,
        CURLOPT_HTTPHEADER     => ['Content-Type: application/json', 'X-API-KEY: ' . $apiKey],
        CURLOPT_POSTFIELDS     => json_encode($payload, JSON_UNESCAPED_UNICODE),
        CURLOPT_TIMEOUT        => 15,
        CURLOPT_SSL_VERIFYPEER => false,
    ]);
    $res = curl_exec($ch);
    curl_close($ch);
    return $res === false ? null : json_decode($res, true);
}

function enYeniMesaj(array $mesajlar): ?array
{
    $en = null; $enT = -1;
    foreach ($mesajlar as $m) {
        $t = strtotime($m['tarih'] ?? '');
        if ($t !== false && $t > $enT) { $enT = $t; $en = $m; }
    }
    return $en;
}
