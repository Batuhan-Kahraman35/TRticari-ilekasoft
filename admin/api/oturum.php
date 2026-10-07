<?php
/**
 * Oturum Durum / Uzatma API
 *
 * action=durum  → kalan süreyi döner (oturuma dokunmaz)
 * action=uzat   → login_time'ı tazeler, süre ayardaki değer kadar yeniden başlar
 *
 * NOT: Bilerek requireAuth() kullanılmaz. requireAuth() süre dolduğunda login.php'ye
 * 302 döndürür; bu uç noktanın her zaman JSON dönmesi gerekir.
 */

require_once __DIR__ . '/../auth.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

$action = $_POST['action'] ?? $_GET['action'] ?? 'durum';
$omur   = oturumTimeoutSaniye();

// Oturum hiç yoksa (çıkış yapılmış / session temizlenmiş)
if (empty($_SESSION['logged_in']) || empty($_SESSION['login_time'])) {
    echo json_encode(['oturum' => false, 'kalan' => 0, 'omur' => $omur]);
    exit;
}

$kalan = $omur - (time() - $_SESSION['login_time']);

if ($action === 'uzat') {
    if ($kalan <= 0) {
        // Süre dolmuş bir oturum uzatılamaz
        Auth::logout();
        echo json_encode(['oturum' => false, 'kalan' => 0, 'omur' => $omur]);
        exit;
    }

    // Uzatma miktarı da ayardan gelir; hiçbir yerde sabit süre yoktur
    $_SESSION['login_time'] = time();
    $kalan = $omur;
}

echo json_encode([
    'oturum' => $kalan > 0,
    'kalan'  => max(0, $kalan),
    'omur'   => $omur
]);
