<?php
/**
 * Destek Bildirim Proxy Endpoint
 * destek-widget.php tarafından AJAX ile çağrılır.
 * API key server-side tutulur, client'a açılmaz.
 *
 * GET: /admin/api/destek-bildirim.php?son_kontrol=2026-05-07T12:00:00
 *
 * Döndürür:
 *   { success, yeni_sayisi, toplam, ticketler[] }
 */

require_once __DIR__ . '/../auth.php';
require_once __DIR__ . '/../db.php';

header('Content-Type: application/json; charset=utf-8');

// Oturum kontrolü
if (!Auth::check()) {
    echo json_encode(['success' => false, 'message' => 'Oturum bulunamadı.']);
    exit;
}

$db     = Database::getInstance();
$ayar   = $db->fetchOne("SELECT TOP 1 site_ayarlari_destek_api_key, site_ayarlari_destek_api_url FROM dbo.tanim_site_ayarlari ORDER BY site_ayarlari_id DESC");
$apiKey = $ayar['site_ayarlari_destek_api_key'] ?? '';
$apiUrl = $ayar['site_ayarlari_destek_api_url'] ?? '';

$eposta = $_SESSION['user_email'] ?? null;

if (!$eposta || !$apiKey || !$apiUrl) {
    echo json_encode(['success' => false, 'message' => 'Yapılandırma eksik.']);
    exit;
}

// ─── API Çağrısı ───
function apiCall($url, $apiKey, $payload) {
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_USERAGENT      => 'TRticari-Destek-App/1.0',
        CURLOPT_POST           => true,
        CURLOPT_HTTPHEADER     => ['Content-Type: application/json', 'X-API-KEY: ' . $apiKey],
        CURLOPT_POSTFIELDS     => json_encode($payload, JSON_UNESCAPED_UNICODE),
        CURLOPT_TIMEOUT        => 15,
        CURLOPT_SSL_VERIFYPEER => false,
    ]);
    $res  = curl_exec($ch);
    $err  = curl_error($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($res === false) {
        return ['success' => false, 'message' => 'API bağlantı hatası: ' . ($err ?: 'Bilinmeyen hata')];
    }
    $data = json_decode($res, true);
    if ($code < 200 || $code >= 300) {
        if ($data !== null && isset($data['success']) && $data['success'] === false) {
            return $data;
        }
        return ['success' => false, 'message' => 'API HTTP hatası: ' . $code];
    }
    if ($data === null) {
        return ['success' => false, 'message' => 'JSON parse hatası'];
    }
    return $data;
}

$result  = apiCall($apiUrl, $apiKey, ['action' => 'list_tickets', 'eposta' => $eposta]);

if (!($result['success'] ?? false)) {
    if (strpos($result['message'] ?? '', 'Kullanıcı bulunamadı') !== false) {
        $result['data'] = [];
    } else {
        echo json_encode(['success' => false, 'message' => $result['message'] ?? 'API hatası.']);
        exit;
    }
}

$tickets = $result['data'] ?? [];
$toplam  = count($tickets);

// Yeni yanıt filtresi: son_kontrol'den sonra gelen yanıtlar
$sonKontrol     = trim($_GET['son_kontrol'] ?? '');
$sonKontrolTime = $sonKontrol !== '' ? strtotime($sonKontrol) : 0;

$yeniSayisi    = 0;
$yeniTicketler = [];

foreach ($tickets as $t) {
    $sonYanitStr = $t['son_yanit_tarihi'] ?? null;
    if (!$sonYanitStr) continue;

    $sonYanitTime = strtotime($sonYanitStr);
    if ($sonKontrolTime > 0 && $sonYanitTime > $sonKontrolTime) {
        $yeniSayisi++;
        $yeniTicketler[] = [
            'id'        => (int)($t['Tickets_id'] ?? 0),
            'no'        => $t['Tickets_no'] ?? '',
            'konu'      => $t['Tickets_konu'] ?? '',
            'son_yanit' => $sonYanitStr,
        ];
    }
}

echo json_encode([
    'success'     => true,
    'yeni_sayisi' => $yeniSayisi,
    'toplam'      => $toplam,
    'ticketler'   => $yeniTicketler,
], JSON_UNESCAPED_UNICODE);
