<?php
/**
 * Admin Panel - Çıkış İşlemi
 * Portal Örnek Yazılım
 */

require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/includes/KonumHelper.php';

// Çıkış olayını kaydet (oturum kapanmadan önce; konum alınamaz, cihaz/IP kaydedilir)
if (Auth::check()) {
    $_cikisUser = Auth::user();
    KonumHelper::kaydet(Database::getInstance(), (int)($_cikisUser['kullanici_id'] ?? 0), 'cikis', [
        'kaynak'      => 'logout.php',
        'konum_durum' => 'yok',
    ]);
}

// Çıkış işlemi
Auth::logout();

// Giriş sayfasına yönlendir
redirect('/admin/login.php');
