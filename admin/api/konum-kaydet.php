<?php
/**
 * Cihaz / konum olay kaydı endpoint'i
 *
 * POST (JSON): {
 *   olay_tipi: giris|sayfa|kayit|manuel|cikis,
 *   kaynak, aciklama,
 *   enlem, boylam, dogruluk, rakim, hiz, konum_durum,
 *   cihaz_id, cihaz_tipi, platform, tarayici, model, ekran, pwa_mi,
 *   baglanti_tipi, pil_seviye, pil_sarjda, dil, saat_dilimi
 * }
 *
 * Yanıt: {success, kayit_id, atlandi, message}
 */
require_once __DIR__ . '/../auth.php';
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../includes/KonumHelper.php';

header('Content-Type: application/json; charset=utf-8');

if (!Auth::check()) {
    echo json_encode(['success' => false, 'message' => 'Oturum bulunamadı.']);
    exit;
}

$user        = Auth::user();
$kullaniciId = (int)($user['kullanici_id'] ?? 0);
$db          = Database::getInstance();

$input = json_decode(file_get_contents('php://input'), true) ?: $_POST;

try {
    if (!KonumHelper::aktifMi($db)) {
        echo json_encode(['success' => true, 'atlandi' => true, 'message' => 'Konum takibi kapalı.']);
        exit;
    }

    $olayTipi = trim($input['olay_tipi'] ?? 'sayfa');
    if (!KonumHelper::olayGecerliMi($db, $olayTipi)) {
        throw new Exception('Geçersiz olay tipi: ' . $olayTipi);
    }

    // Throttle yalnızca "sayfa" olayına uygulanır; diğer olaylar her zaman kaydedilir
    if ($olayTipi === 'sayfa') {
        $throttle = KonumHelper::ayarlar($db)['throttle_dk'];
        $gecen    = KonumHelper::sonKayitDakika($db, $kullaniciId, 'sayfa');

        if ($throttle > 0 && $gecen !== null && $gecen < $throttle) {
            echo json_encode([
                'success'  => true,
                'atlandi'  => true,
                'kalan_dk' => $throttle - $gecen,
                'message'  => 'Throttle süresi dolmadı.',
            ]);
            exit;
        }
    }

    $kayitId = KonumHelper::kaydet($db, $kullaniciId, $olayTipi, [
        'kaynak'        => $input['kaynak']        ?? null,
        'aciklama'      => $input['aciklama']      ?? null,
        'enlem'         => $input['enlem']         ?? null,
        'boylam'        => $input['boylam']        ?? null,
        'dogruluk'      => $input['dogruluk']      ?? null,
        'rakim'         => $input['rakim']         ?? null,
        'hiz'           => $input['hiz']           ?? null,
        'konum_durum'   => $input['konum_durum']   ?? 'yok',
        'cihaz_id'      => $input['cihaz_id']      ?? null,
        'cihaz_tipi'    => $input['cihaz_tipi']    ?? null,
        'platform'      => $input['platform']      ?? null,
        'tarayici'      => $input['tarayici']      ?? null,
        'model'         => $input['model']         ?? null,
        'ekran'         => $input['ekran']         ?? null,
        'pwa_mi'        => $input['pwa_mi']        ?? 0,
        'baglanti_tipi' => $input['baglanti_tipi'] ?? null,
        'pil_seviye'    => $input['pil_seviye']    ?? null,
        'pil_sarjda'    => $input['pil_sarjda']    ?? null,
        'dil'           => $input['dil']           ?? null,
        'saat_dilimi'   => $input['saat_dilimi']   ?? null,
    ]);

    if ($kayitId === null) {
        throw new Exception('Kayıt oluşturulamadı.');
    }

    echo json_encode(['success' => true, 'atlandi' => false, 'kayit_id' => $kayitId]);
} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
