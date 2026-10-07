<?php
/**
 * Bildirim API - Merkezi (dbo.Bildirimler) + türetilmiş başvuru uyarıları
 * Aksiyonlar: get_notifications, get_count, mark_read
 */

require_once __DIR__ . '/../auth.php';
require_once __DIR__ . '/../includes/PageAuth.php';
require_once __DIR__ . '/../includes/Bildirim.php';
requireAuth();

header('Content-Type: application/json; charset=utf-8');

$db   = Database::getInstance();
$user = Auth::user();
$kullaniciId = (int)($user['kullanici_id'] ?? 0);

// Başvuru uyarılarını görme yetkisi (personel-yonetimi.php)
$basvuruYetkisi = PageAuth::checkPagePermissions(
    $kullaniciId, $user['departman_id'] ?? null, 'personel-yonetimi.php'
)['has_access'] ?? false;

/** dakika -> "x dakika önce" */
function zamanMetni(int $dakika): string {
    if ($dakika < 1)     return 'az önce';
    if ($dakika < 60)    return $dakika . ' dakika önce';
    if ($dakika < 1440)  return floor($dakika / 60) . ' saat önce';
    return floor($dakika / 1440) . ' gün önce';
}

/** bildirim_tip -> ikon/renk */
function tipStil(string $tip): array {
    switch ($tip) {
        case 'basari': return ['bi-check-circle', 'success'];
        case 'uyari':  return ['bi-exclamation-triangle', 'warning'];
        case 'hata':   return ['bi-x-circle', 'danger'];
        case 'basvuru':return ['bi-person-plus', 'warning'];
        default:       return ['bi-info-circle', 'primary'];
    }
}

try {
    $action = $_GET['action'] ?? $_POST['action'] ?? '';

    if ($action === 'mark_read') {
        $bid = isset($_POST['bildirim_id']) ? (int)$_POST['bildirim_id'] : null;
        Bildirim::okunduYap($db, $kullaniciId, $bid ?: null);
        echo json_encode(['success' => true]);
        exit;
    }

    if ($action === 'get_notifications') {
        $liste = [];

        // 1) Merkezi bildirimler (kendine ait + genel)
        foreach (Bildirim::listele($db, $kullaniciId, 20) as $b) {
            [$ikon, $renk] = tipStil($b['bildirim_tip'] ?? 'info');
            $liste[] = [
                'id'     => (int)$b['bildirim_id'],
                'tip'    => $b['bildirim_tip'],
                'baslik' => $b['bildirim_baslik'],
                'mesaj'  => $b['bildirim_govde'],
                'zaman'  => zamanMetni((int)$b['dakika_once']),
                'link'   => $b['bildirim_url'] ?: '/admin/',
                'icon'   => $ikon,
                'renk'   => $renk,
                'okundu' => (bool)$b['bildirim_okundu'],
                'kaynak' => 'bildirim',
            ];
        }

        // 2) Türetilmiş yeni başvuru uyarıları (yalnızca yetkililer)
        $basvuruSayisi = 0;
        if ($basvuruYetkisi) {
            $basvurular = $db->fetchAll("
                SELECT kullanici_id, kullanici_ad, kullanici_soyad, kullanici_email,
                       DATEDIFF(MINUTE, kullanici_olusturma_tarihi, GETDATE()) AS dakika_once
                FROM kullanicilar
                WHERE kullanici_durum IS NULL
                  AND kullanici_olusturma_tarihi >= DATEADD(DAY, -30, GETDATE())
                ORDER BY kullanici_olusturma_tarihi DESC
            ");
            $basvuruSayisi = count($basvurular);
            foreach ($basvurular as $bv) {
                $adSoyad = trim(($bv['kullanici_ad'] ?? '') . ' ' . ($bv['kullanici_soyad'] ?? ''));
                if ($adSoyad === '') $adSoyad = $bv['kullanici_email'];
                $liste[] = [
                    'id'     => 'basvuru-' . $bv['kullanici_id'],
                    'tip'    => 'basvuru',
                    'baslik' => 'Yeni Başvuru',
                    'mesaj'  => $adSoyad . ' başvuruda bulundu',
                    'zaman'  => zamanMetni((int)$bv['dakika_once']),
                    'link'   => '/admin/pages/personel-yonetimi.php',
                    'icon'   => 'bi-person-plus',
                    'renk'   => 'warning',
                    'okundu' => false,
                    'kaynak' => 'basvuru',
                ];
            }
        }

        // Okunmamış sayısı = merkezi okunmamış + başvurular
        $okunmamis = Bildirim::okunmamisSayisi($db, $kullaniciId) + $basvuruSayisi;

        echo json_encode([
            'success' => true,
            'data'    => $liste,
            'count'   => $okunmamis,
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    if ($action === 'get_count') {
        $basvuruSayisi = 0;
        if ($basvuruYetkisi) {
            $basvuruSayisi = (int)($db->fetchOne("
                SELECT COUNT(*) AS sayi FROM kullanicilar
                WHERE kullanici_durum IS NULL AND kullanici_olusturma_tarihi >= DATEADD(DAY, -30, GETDATE())
            ")['sayi'] ?? 0);
        }
        echo json_encode([
            'success' => true,
            'count'   => Bildirim::okunmamisSayisi($db, $kullaniciId) + $basvuruSayisi,
        ]);
        exit;
    }

    throw new Exception('Geçersiz işlem');
} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
