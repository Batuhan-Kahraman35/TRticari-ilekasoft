<?php
/**
 * Admin Panel - Giriş Kontrol ve Yönlendirme
 * Portal Örnek Yazılım
 */

require_once __DIR__ . '/auth.php';

// Oturum kontrolü
if (Auth::check()) {
    // Kullanıcı giriş yapmışsa ana sayfaya yönlendir
    // Query string korunur (?giris=1, ?konum=1 gibi parametreler hedef sayfada okunur)
    $query = !empty($_SERVER['QUERY_STRING']) ? '?' . $_SERVER['QUERY_STRING'] : '';
    header('Location: pages/anasayfa.php' . $query);
    exit;
} else {
    // Giriş yapmamışsa login sayfasına yönlendir
    header('Location: login.php');
    exit;
}
