<?php
/**
 * Aylık Satış Raporu
 * Sözleşme ve cari verilerinden aylık bazda satış, tahsilat ve kalan tutarları
 */

require_once __DIR__ . '/../auth.php';
require_once __DIR__ . '/../includes/PageAuth.php';
requireAuth();

$user = Auth::user();
$db = Database::getInstance();

// Sayfa yetki kontrolü
$currentPageFile = basename($_SERVER['PHP_SELF']);
$pagePermissions = PageAuth::checkPagePermissions(
    $user['kullanici_id'],
    $user['departman_id'],
    $currentPageFile
);

if (!$pagePermissions['has_access']) {
    PageAuth::accessDenied($pagePermissions['error'] ?? 'Bu sayfaya erişim yetkiniz bulunmamaktadır.');
}

// Sayfa bilgilerini al
$pageInfo = $db->fetchOne("
    SELECT 
        s.sayfalar_sayfa_adi, 
        s.sayfalar_aciklama,
        m.menuler_menu_adi as menu_adi
    FROM Menu_Sayfalar s
    LEFT JOIN Menuler m ON s.sayfalar_menu_id = m.menuler_id
    WHERE s.sayfalar_sayfa_url LIKE ? AND s.sayfalar_durum = 1
", ['%' . $currentPageFile]);

$pageTitle = $pageInfo['sayfalar_sayfa_adi'] ?? 'Aylık Satış Raporu';
$pageDescription = $pageInfo['sayfalar_aciklama'] ?? '';
$menuAdi = $pageInfo['menu_adi'] ?? null;

$siteAyarlari = $db->fetchOne("SELECT TOP 1 site_ayarlari_site_title FROM dbo.tanim_site_ayarlari ORDER BY site_ayarlari_id DESC");
$siteTitle = $siteAyarlari['site_ayarlari_site_title'] ?? 'Örnek Yazılım Portal';

// Sezon listesi
$sezonlar = $db->fetchAll("SELECT sezon_id, sezon_ad FROM Sozlesme_Sezonlar WHERE sezon_durum = 1 ORDER BY sezon_ad DESC");

// Varsayılan sezon (URL parametresi - menü sezon bağlamı için, örn. Raporlar 2026 -> sezon_id=2)
$defaultSezonId = (isset($_GET['sezon_id']) && $_GET['sezon_id'] !== '') ? (int)$_GET['sezon_id'] : 2;

// Cari tip listesi
$cariTipleri = $db->fetchAll("SELECT cari_tipi_id, cari_tipi_ad FROM Cari_CariTipleri WHERE cari_tipi_durum = 1 ORDER BY cari_tipi_sira");

// AJAX İşlemleri
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json');
    $action = $_POST['action'] ?? '';
    
    try {
        switch ($action) {
            case 'stats':
                $ayFilter = $_POST['ay'] ?? '';
                $sezonId = $_POST['sezon_id']  ?? '';
                $cariTipiFilter = $_POST['cari_tipi_id'] ?? '';
                
                // Tüm sözleşmeler (yıl filtresi opsiyonel)
                $satisWhere     = "WHERE 1=1";
                $satisParams    = [];
                $tahsilatWhere  = "WHERE o.odeme_yapildi = 1";
                $tahsilatParams = [];
                
                if ($ayFilter) {
                    $satisWhere    .= " AND MONTH(s.sozlesme_tarih) = ?";
                    $satisParams[]  = $ayFilter;
                    $tahsilatWhere .= " AND MONTH(s.sozlesme_tarih) = ?";
                    $tahsilatParams[] = $ayFilter;
                }
                
                if ($sezonId) {
                    $satisWhere    .= " AND s.sozlesme_sezon_id = ?";
                    $satisParams[] = $sezonId;
                    $tahsilatWhere    .= " AND s.sozlesme_sezon_id = ?";
                    $tahsilatParams[] = $sezonId;
                }
                
                if ($cariTipiFilter) {
                    $satisWhere    .= " AND c.cari_tipi_id = ?";
                    $satisParams[] = $cariTipiFilter;
                    $tahsilatWhere    .= " AND c.cari_tipi_id = ?";
                    $tahsilatParams[] = $cariTipiFilter;
                }
                
                $toplamSatis = $db->fetchOne("
                    SELECT ISNULL(SUM(h.hareket_fiyat), 0) as toplam
                    FROM Sozlesmeler s
                    LEFT JOIN Sozlesme_StokHareketleri h ON h.hareket_sozlesme_id = s.sozlesme_id
                    LEFT JOIN Cari c ON s.sozlesme_cari_id = c.cari_id
                    $satisWhere
                ", $satisParams);
                
                $toplamTahsilat = $db->fetchOne("
                    SELECT ISNULL(SUM(o.odeme_tutar), 0) as toplam
                    FROM Sozlesmeler s
                    LEFT JOIN Sozlesme_Odemeler o ON o.odeme_sozlesme_id = s.sozlesme_id
                    LEFT JOIN Cari c ON s.sozlesme_cari_id = c.cari_id
                    $tahsilatWhere
                ", $tahsilatParams);
                
                $satis = floatval($toplamSatis['toplam'] ?? 0);
                $tahsilat = floatval($toplamTahsilat['toplam'] ?? 0);
                
                $stats = [
                    'toplam_satis' => $satis,
                    'toplam_tahsilat' => $tahsilat,
                    'toplam_kalan' => $satis - $tahsilat,
                    'tahsilat_oran' => $satis > 0 ? round(($tahsilat / $satis) * 100, 1) : 0
                ];
                echo json_encode(['success' => true, 'data' => $stats]);
                break;
                
            case 'report':
                $ayFilter = $_POST['ay'] ?? '';
                $sezonId = $_POST['sezon_id'] ?? '';
                $cariTipiFilter = $_POST['cari_tipi_id'] ?? '';
                
                $aylar = [
                    1 => 'Ocak', 2 => 'Şubat', 3 => 'Mart', 4 => 'Nisan',
                    5 => 'Mayıs', 6 => 'Haziran', 7 => 'Temmuz', 8 => 'Ağustos',
                    9 => 'Eylül', 10 => 'Ekim', 11 => 'Kasım', 12 => 'Aralık'
                ];
                
                // Tüm sözleşmeler (yıl filtresi opsiyonel)
                $whereClause = "WHERE 1=1";
                $params = [];
                
                if ($ayFilter) {
                    $whereClause .= " AND MONTH(s.sozlesme_tarih) = ?";
                    $params[] = $ayFilter;
                    $ayAraligi = $aylar[intval($ayFilter)] ?? $ayFilter;
                } else {
                    $ayAraligi = 'Tüm Zamanlar';
                }
                
                if ($sezonId) {
                    $whereClause .= " AND s.sozlesme_sezon_id = ?";
                    $params[] = $sezonId;
                }
                
                if ($cariTipiFilter) {
                    $whereClause .= " AND c.cari_tipi_id = ?";
                    $params[] = $cariTipiFilter;
                }
                
                // Sorgu 1: Aylık toplam hareket_fiyat → "Sözleşme Satış Tutarı" sabit sütunu
                $satisTutarlari = $db->fetchAll("
                    SELECT
                        YEAR(s.sozlesme_tarih)  as yil,
                        MONTH(s.sozlesme_tarih) as ay,
                        ISNULL(SUM(h.hareket_fiyat), 0) as satis_tutari
                    FROM Sozlesmeler s
                    LEFT JOIN Sozlesme_StokHareketleri h ON h.hareket_sozlesme_id = s.sozlesme_id
                    LEFT JOIN Cari c ON s.sozlesme_cari_id = c.cari_id
                    $whereClause
                    GROUP BY YEAR(s.sozlesme_tarih), MONTH(s.sozlesme_tarih)
                    ORDER BY YEAR(s.sozlesme_tarih), MONTH(s.sozlesme_tarih)
                ", $params);
                
                $satisMap = [];
                foreach ($satisTutarlari as $row) {
                    $key = $row['yil'] . '-' . str_pad($row['ay'], 2, '0', STR_PAD_LEFT);
                    $satisMap[$key] = floatval($row['satis_tutari']);
                }
                
                // Sorgu 2: Ödeme tipine göre TAHSİLAT (odeme_yapildi=1, odeme_tutar)
                // Doğrudan Sozlesme_Odemeler tablosundan odeme_tipi_id bazlı
                $rawData = $db->fetchAll("
                    SELECT
                        YEAR(s.sozlesme_tarih)  as yil,
                        MONTH(s.sozlesme_tarih) as ay,
                        o.odeme_tipi_id,
                        ot.odeme_tipi_ad,
                        ISNULL(SUM(o.odeme_tutar), 0) as tutar
                    FROM Sozlesmeler s
                    INNER JOIN Sozlesme_Odemeler o ON o.odeme_sozlesme_id = s.sozlesme_id
                    INNER JOIN Sozlesme_OdemeTipleri ot ON ot.odeme_tipi_id = o.odeme_tipi_id
                    LEFT JOIN Cari c ON s.sozlesme_cari_id = c.cari_id
                    $whereClause AND o.odeme_yapildi = 1
                    GROUP BY YEAR(s.sozlesme_tarih), MONTH(s.sozlesme_tarih), o.odeme_tipi_id, ot.odeme_tipi_ad
                    ORDER BY YEAR(s.sozlesme_tarih), MONTH(s.sozlesme_tarih), ot.odeme_tipi_ad
                ", $params);
                
                // Pivot matris oluştur
                $columns     = [];   // benzersiz ödeme tipleri (sıralı) [{id, ad}]
                $columnIds   = [];   // id -> ad mapping
                $pivotMap    = [];   // anahtar: "YIL-AY"
                $ayMetaMap   = [];   // anahtar → ay_adi
                
                foreach ($rawData as $row) {
                    $tipId = $row['odeme_tipi_id'];
                    $tipAd = $row['odeme_tipi_ad'];
                    if (!isset($columnIds[$tipId])) {
                        $columnIds[$tipId] = $tipAd;
                        $columns[] = ['id' => $tipId, 'ad' => $tipAd];
                    }
                    
                    $key = $row['yil'] . '-' . str_pad($row['ay'], 2, '0', STR_PAD_LEFT);
                    if (!isset($pivotMap[$key])) {
                        $pivotMap[$key] = [];
                        // Her zaman "Ay Yıl" formatında göster
                        $ayAdi = $aylar[intval($row['ay'])] . ' ' . $row['yil'];
                        $ayMetaMap[$key] = $ayAdi;
                    }
                    $pivotMap[$key][$tipId] = floatval($row['tutar']);
                }
                
                // Sorgu 3: Aylık tahsilat (odeme_yapildi=1, sözleşme tarihine göre gruplu)
                $tahsilatWhere = $whereClause . " AND o.odeme_yapildi = 1";
                $tahsilatData = $db->fetchAll("
                    SELECT
                        YEAR(s.sozlesme_tarih)  as yil,
                        MONTH(s.sozlesme_tarih) as ay,
                        ISNULL(SUM(o.odeme_tutar), 0) as tahsilat_tutari
                    FROM Sozlesmeler s
                    INNER JOIN Sozlesme_Odemeler o ON o.odeme_sozlesme_id = s.sozlesme_id
                    LEFT JOIN Cari c ON s.sozlesme_cari_id = c.cari_id
                    $tahsilatWhere
                    GROUP BY YEAR(s.sozlesme_tarih), MONTH(s.sozlesme_tarih)
                    ORDER BY YEAR(s.sozlesme_tarih), MONTH(s.sozlesme_tarih)
                ", $params);

                $tahsilatMap = [];
                foreach ($tahsilatData as $row) {
                    $key = $row['yil'] . '-' . str_pad($row['ay'], 2, '0', STR_PAD_LEFT);
                    $tahsilatMap[$key] = floatval($row['tahsilat_tutari']);
                }

                // Senet ödeme tipinin ID'sini bul
                $senetTipi = $db->fetchOne("SELECT odeme_tipi_id FROM Sozlesme_OdemeTipleri WHERE odeme_tipi_ad = 'Senet' AND odeme_tipi_durum = 1");
                $senetTipiId = $senetTipi['odeme_tipi_id'] ?? null;

                // Sorgu 4: Ödenecek Senet (Senet tipi ve odeme_yapildi=0) - Aylık bazda
                $odenecekSenetMap = [];
                if ($senetTipiId) {
                    $odenecekSenetParams = array_merge($params, [$senetTipiId]);
                    $odenecekSenetData = $db->fetchAll("
                        SELECT
                            YEAR(s.sozlesme_tarih) as yil,
                            MONTH(s.sozlesme_tarih) as ay,
                            ISNULL(SUM(o.odeme_tutar), 0) as tutar
                        FROM Sozlesmeler s
                        INNER JOIN Sozlesme_Odemeler o ON o.odeme_sozlesme_id = s.sozlesme_id
                        LEFT JOIN Cari c ON s.sozlesme_cari_id = c.cari_id
                        $whereClause AND o.odeme_tipi_id = ? AND o.odeme_yapildi = 0
                        GROUP BY YEAR(s.sozlesme_tarih), MONTH(s.sozlesme_tarih)
                        ORDER BY YEAR(s.sozlesme_tarih), MONTH(s.sozlesme_tarih)
                    ", $odenecekSenetParams);
                    
                    foreach ($odenecekSenetData as $row) {
                        $key = $row['yil'] . '-' . str_pad($row['ay'], 2, '0', STR_PAD_LEFT);
                        $odenecekSenetMap[$key] = floatval($row['tutar']);
                    }
                }
                
                // Sorgu 5: Zaafiyet (odeme_durum_id = 8) - Aylık bazda
                $zaafiyetMap = [];
                $zaafiyetData = $db->fetchAll("
                    SELECT
                        YEAR(s.sozlesme_tarih) as yil,
                        MONTH(s.sozlesme_tarih) as ay,
                        ISNULL(SUM(o.odeme_tutar), 0) as tutar
                    FROM Sozlesmeler s
                    INNER JOIN Sozlesme_Odemeler o ON o.odeme_sozlesme_id = s.sozlesme_id
                    LEFT JOIN Cari c ON s.sozlesme_cari_id = c.cari_id
                    $whereClause AND o.odeme_durum_id = 8
                    GROUP BY YEAR(s.sozlesme_tarih), MONTH(s.sozlesme_tarih)
                    ORDER BY YEAR(s.sozlesme_tarih), MONTH(s.sozlesme_tarih)
                ", $params);
                
                foreach ($zaafiyetData as $row) {
                    $key = $row['yil'] . '-' . str_pad($row['ay'], 2, '0', STR_PAD_LEFT);
                    $zaafiyetMap[$key] = floatval($row['tutar']);
                }
                
                // Sorgu 6: İcra (odeme_durum_id = 7) - Aylık bazda
                $icraMap = [];
                $icraData = $db->fetchAll("
                    SELECT
                        YEAR(s.sozlesme_tarih) as yil,
                        MONTH(s.sozlesme_tarih) as ay,
                        ISNULL(SUM(o.odeme_tutar), 0) as tutar
                    FROM Sozlesmeler s
                    INNER JOIN Sozlesme_Odemeler o ON o.odeme_sozlesme_id = s.sozlesme_id
                    LEFT JOIN Cari c ON s.sozlesme_cari_id = c.cari_id
                    $whereClause AND o.odeme_durum_id = 7
                    GROUP BY YEAR(s.sozlesme_tarih), MONTH(s.sozlesme_tarih)
                    ORDER BY YEAR(s.sozlesme_tarih), MONTH(s.sozlesme_tarih)
                ", $params);
                
                foreach ($icraData as $row) {
                    $key = $row['yil'] . '-' . str_pad($row['ay'], 2, '0', STR_PAD_LEFT);
                    $icraMap[$key] = floatval($row['tutar']);
                }
                
                // satisMap'teki ayları garantile (ödeme tipi verisi olmayan aylar için)
                foreach ($satisMap as $key => $val) {
                    if (!isset($pivotMap[$key])) {
                        preg_match('/^(\d+)-(\d+)$/', $key, $m);
                        $pivotMap[$key] = [];
                        // Her zaman "Ay Yıl" formatında göster
                        $ayAdi = $aylar[intval($m[2])] . ' ' . $m[1];
                        $ayMetaMap[$key] = $ayAdi;
                    }
                }
                ksort($pivotMap);
                
                // Satır ve sütun toplamları (ID bazlı)
                $pivotRows       = [];
                $colTotals       = [];
                foreach ($columns as $col) {
                    $colTotals[$col['id']] = 0.0;
                }
                $satisToplam     = 0.0;
                $grandTotal      = 0.0;
                
                $tahsilatToplam  = 0.0;
                $odenecekSenetToplam = 0.0;
                $zaafiyetToplam = 0.0;
                $icraToplam = 0.0;

                foreach ($pivotMap as $key => $tipValues) {
                    $satisTutari  = $satisMap[$key]    ?? 0.0;
                    $tahsilat     = $tahsilatMap[$key] ?? 0.0;
                    $odenecekSenet = $odenecekSenetMap[$key] ?? 0.0;
                    $zaafiyet = $zaafiyetMap[$key] ?? 0.0;
                    $icra = $icraMap[$key] ?? 0.0;
                    $rowTotal     = 0.0;
                    
                    // Yıl ve ayı key'den çıkar (format: "2026-03")
                    $keyParts = explode('-', $key);
                    $rowYil = $keyParts[0];
                    $rowAy = intval($keyParts[1]);
                    
                    $rowData      = [
                        'ay_adi'         => $ayMetaMap[$key],
                        'yil'            => $rowYil,
                        'ay'             => $rowAy,
                        'satis_tutari'   => $satisTutari,
                        'tahsilat'       => $tahsilat,
                        'odenecek_senet' => $odenecekSenet,
                        'zaafiyet'       => $zaafiyet,
                        'icra'           => $icra,
                        'odeme_tipleri'  => [] // ID bazlı tutar map
                    ];
                    foreach ($columns as $col) {
                        $tipId = $col['id'];
                        $val = $tipValues[$tipId] ?? 0.0;
                        $rowData['odeme_tipleri'][$tipId] = $val;
                        $rowTotal      += $val;
                        $colTotals[$tipId] += $val;
                    }
                    // Kalan = Sözleşme Satış Tutarı - Ödeme Tipleri Toplamı
                    $rowData['kalan'] = $satisTutari - $rowTotal;
                    $satisToplam    += $satisTutari;
                    $tahsilatToplam += $tahsilat;
                    $odenecekSenetToplam += $odenecekSenet;
                    $zaafiyetToplam += $zaafiyet;
                    $icraToplam += $icra;
                    $grandTotal     += $rowTotal;
                    $pivotRows[]    = $rowData;
                }
                
                // Kalan sütununa göre büyükten küçüğe sırala
                usort($pivotRows, function($a, $b) {
                    return $b['kalan'] <=> $a['kalan'];
                });
                
                // Sözleşme adet istatistikleri
                $sozlesmeToplam = $db->fetchOne("
                    SELECT COUNT(DISTINCT s.sozlesme_id) as adet
                    FROM Sozlesmeler s
                    LEFT JOIN Cari c ON s.sozlesme_cari_id = c.cari_id
                    $whereClause
                ", $params);
                
                $sozlesmeOdenen = $db->fetchOne("
                    SELECT COUNT(DISTINCT s.sozlesme_id) as adet
                    FROM Sozlesmeler s
                    INNER JOIN Sozlesme_Odemeler o ON o.odeme_sozlesme_id = s.sozlesme_id AND o.odeme_yapildi = 1
                    LEFT JOIN Cari c ON s.sozlesme_cari_id = c.cari_id
                    $whereClause
                ", $params);
                
                // Doğrudan toplam tahsilat hesapla (aylık gruplamadan bağımsız)
                $dogruTahsilat = $db->fetchOne("
                    SELECT ISNULL(SUM(o.odeme_tutar), 0) as toplam
                    FROM Sozlesme_Odemeler o
                    INNER JOIN Sozlesmeler s ON o.odeme_sozlesme_id = s.sozlesme_id
                    LEFT JOIN Cari c ON s.sozlesme_cari_id = c.cari_id
                    $whereClause AND o.odeme_yapildi = 1
                ", $params);
                $tahsilatToplamGercek = floatval($dogruTahsilat['toplam'] ?? 0);
                
                $toplamAdet = intval($sozlesmeToplam['adet'] ?? 0);
                $odenenAdet = intval($sozlesmeOdenen['adet'] ?? 0);
                $kalanAdet = $toplamAdet - $odenenAdet;
                
                echo json_encode([
                    'success'         => true,
                    'columns'         => $columns, // [{id, ad}, ...]
                    'data'            => $pivotRows,
                    'col_totals'      => $colTotals, // {id: toplam, ...}
                    'satis_toplam'    => $satisToplam,
                    'tahsilat_toplam' => $tahsilatToplamGercek,
                    'kalan_toplam'    => $satisToplam - $grandTotal,
                    'grand_total'     => $grandTotal,
                    'odenecek_senet_toplam' => $odenecekSenetToplam,
                    'zaafiyet_toplam' => $zaafiyetToplam,
                    'icra_toplam' => $icraToplam,
                    'ay_araligi'      => $ayAraligi,
                    'sozlesme_toplam' => $toplamAdet,
                    'sozlesme_odenen' => $odenenAdet,
                    'sozlesme_kalan'  => $kalanAdet
                ]);
                break;
                
            case 'detail':
                $yil         = intval($_POST['yil'] ?? 0);
                $ay          = intval($_POST['ay'] ?? 0);
                $odemeTipiId = $_POST['odeme_tipi_id'] ?? ''; // ID bazlı
                $odemeTipi   = $_POST['odeme_tipi'] ?? ''; // Başlık için
                $tip         = $_POST['tip'] ?? ''; // satis, odeme_tipi, kalan
                $sezonId     = $_POST['sezon_id'] ?? '';
                
                $aylar = [
                    1 => 'Ocak', 2 => 'Şubat', 3 => 'Mart', 4 => 'Nisan',
                    5 => 'Mayıs', 6 => 'Haziran', 7 => 'Temmuz', 8 => 'Ağustos',
                    9 => 'Eylül', 10 => 'Ekim', 11 => 'Kasım', 12 => 'Aralık'
                ];
                
                $whereClause = "WHERE YEAR(s.sozlesme_tarih) = ? AND MONTH(s.sozlesme_tarih) = ?";
                $params = [$yil, $ay];
                
                if ($sezonId) {
                    $whereClause .= " AND s.sozlesme_sezon_id = ?";
                    $params[] = $sezonId;
                }
                
                if ($tip === 'satis') {
                    // Sözleşme Satış Tutarı detayı
                    $sql = "
                        SELECT 
                            s.sozlesme_id,
                            s.sozlesme_no,
                            c.cari_adi,
                            CONVERT(VARCHAR(10), s.sozlesme_tarih, 104) as sozlesme_tarih,
                            ISNULL(SUM(h.hareket_fiyat), 0) as tutar
                        FROM Sozlesmeler s
                        LEFT JOIN Cari c ON s.sozlesme_cari_id = c.cari_id
                        LEFT JOIN Sozlesme_StokHareketleri h ON h.hareket_sozlesme_id = s.sozlesme_id
                        $whereClause
                        GROUP BY s.sozlesme_id, s.sozlesme_no, c.cari_adi, s.sozlesme_tarih
                        HAVING ISNULL(SUM(h.hareket_fiyat), 0) > 0
                        ORDER BY s.sozlesme_tarih DESC
                    ";
                    $data = $db->fetchAll($sql, $params);
                    $baslik = ($aylar[intval($ay)] ?? $ay) . ' ' . $yil . ' - Sözleşme Satış Tutarı Detayı';
                    
                } elseif ($tip === 'odeme_tipi' && $odemeTipiId !== '') {
                    // Ödeme tipine göre TAHSİLAT detayı (odeme_yapildi=1, odeme_tutar)
                    $data = $db->fetchAll("
                        SELECT 
                            s.sozlesme_id,
                            s.sozlesme_no,
                            c.cari_adi,
                            CONVERT(VARCHAR(10), s.sozlesme_tarih, 104) as sozlesme_tarih,
                            ot.odeme_tipi_ad,
                            ISNULL(SUM(o.odeme_tutar), 0) as tutar
                        FROM Sozlesmeler s
                        LEFT JOIN Cari c ON s.sozlesme_cari_id = c.cari_id
                        INNER JOIN Sozlesme_Odemeler o ON o.odeme_sozlesme_id = s.sozlesme_id
                        INNER JOIN Sozlesme_OdemeTipleri ot ON ot.odeme_tipi_id = o.odeme_tipi_id
                        $whereClause AND o.odeme_tipi_id = ? AND o.odeme_yapildi = 1
                        GROUP BY s.sozlesme_id, s.sozlesme_no, c.cari_adi, s.sozlesme_tarih, ot.odeme_tipi_ad
                        HAVING ISNULL(SUM(o.odeme_tutar), 0) > 0
                        ORDER BY s.sozlesme_tarih DESC
                    ", array_merge($params, [$odemeTipiId]));
                    $baslik = ($aylar[intval($ay)] ?? $ay) . ' ' . $yil . ' - ' . ($odemeTipi ?: 'Ödeme') . ' Tahsilat Detayı';
                    
                } elseif ($tip === 'kalan') {
                    // Kalan tutarı detayı (satış - gerçek tahsilat)
                    $data = $db->fetchAll("
                        SELECT 
                            s.sozlesme_id,
                            s.sozlesme_no,
                            c.cari_adi,
                            CONVERT(VARCHAR(10), s.sozlesme_tarih, 104) as sozlesme_tarih,
                            ISNULL(SUM(h.hareket_fiyat), 0) as satis_tutari,
                            ISNULL((
                                SELECT SUM(o2.odeme_tutar)
                                FROM Sozlesme_Odemeler o2
                                WHERE o2.odeme_sozlesme_id = s.sozlesme_id AND o2.odeme_yapildi = 1
                            ), 0) as tahsilat_tutari
                        FROM Sozlesmeler s
                        LEFT JOIN Cari c ON s.sozlesme_cari_id = c.cari_id
                        LEFT JOIN Sozlesme_StokHareketleri h ON h.hareket_sozlesme_id = s.sozlesme_id
                        $whereClause
                        GROUP BY s.sozlesme_id, s.sozlesme_no, c.cari_adi, s.sozlesme_tarih
                        HAVING ISNULL(SUM(h.hareket_fiyat), 0) > 0
                        ORDER BY s.sozlesme_tarih DESC
                    ", $params);
                    
                    // Kalan hesapla (Satış - Tahsilat)
                    foreach ($data as &$row) {
                        $row['tutar'] = floatval($row['satis_tutari']) - floatval($row['tahsilat_tutari']);
                    }
                    $baslik = ($aylar[intval($ay)] ?? $ay) . ' ' . $yil . ' - Kalan Tutar Detayı';
                    
                } else {
                    echo json_encode(['success' => false, 'message' => 'Geçersiz detay tipi']);
                    break;
                }
                
                echo json_encode(['success' => true, 'data' => $data, 'baslik' => $baslik]);
                break;
                
            default:
                echo json_encode(['success' => false, 'message' => 'Geçersiz işlem']);
        }
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'message' => 'Hata: ' . $e->getMessage()]);
    }
    exit;
}
?>
<!DOCTYPE html>
<html lang="tr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars($pageTitle) ?> - <?= htmlspecialchars($siteTitle) ?></title>
    
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/@fontsource/source-sans-3@5.0.12/index.css" crossorigin="anonymous">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.13.1/font/bootstrap-icons.min.css" crossorigin="anonymous">
    <link rel="stylesheet" href="/admin/assets/css/adminlte.min.css">
    <link href="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css" rel="stylesheet" />
    <link href="https://cdn.jsdelivr.net/npm/select2-bootstrap-5-theme@1.3.0/dist/select2-bootstrap-5-theme.min.css" rel="stylesheet" />
    <link href="https://cdn.jsdelivr.net/npm/sweetalert2@11/dist/sweetalert2.min.css" rel="stylesheet">
    <link rel="stylesheet" href="/admin/assets/css/custom.css">
    <style>
        .info-box { transition: transform 0.2s; }
        .info-box:hover { transform: translateY(-5px); box-shadow: 0 4px 6px rgba(0,0,0,0.1); }
        .table-report th { background-color: #f8f9fa; }
        .table-report tfoot { font-weight: bold; background-color: #e9ecef; }
        .text-positive { color: #198754; }
        .text-negative { color: #dc3545; }
        .clickable-cell { cursor: pointer; transition: background-color 0.2s; pointer-events: auto !important; position: relative; z-index: 1; }
        .clickable-cell:hover { background-color: #e3f2fd !important; }
        .clickable-cell * { pointer-events: none; }
    </style>
</head>
<body class="layout-fixed sidebar-expand-lg sidebar-open bg-body-tertiary">
    <div class="app-wrapper">
        <?php include __DIR__ . '/../includes/header.php'; ?>
        <?php include __DIR__ . '/../includes/sidebar.php'; ?>
        
        <main class="app-main">
            <div class="app-content-header">
                <div class="container-fluid">
                    <div class="row">
                        <div class="col-sm-6">
                            <h3 class="mb-0"><?= htmlspecialchars($pageTitle) ?></h3>
                        </div>
                        <div class="col-sm-6">
                            <ol class="breadcrumb float-sm-end">
                                <?php if ($menuAdi): ?>
                                <li class="breadcrumb-item"><?= htmlspecialchars($menuAdi) ?></li>
                                <?php endif; ?>
                                <li class="breadcrumb-item active"><?= htmlspecialchars($pageTitle) ?></li>
                            </ol>
                        </div>
                    </div>
                </div>
            </div>
            
            <div class="app-content">
                <div class="container-fluid">
                    
                    <!-- Info Boxes -->
                    <div class="row mb-3">
                        <div class="col-12 col-sm-6 col-md-4">
                            <div class="info-box">
                                <span class="info-box-icon text-bg-primary shadow-sm">
                                    <i class="bi bi-file-earmark-text"></i>
                                </span>
                                <div class="info-box-content">
                                    <span class="info-box-text">Toplam Sözleşme</span>
                                    <span class="info-box-number" id="stat-sozlesme-toplam">0 Adet</span>
                                </div>
                            </div>
                        </div>
                        <div class="col-12 col-sm-6 col-md-4">
                            <div class="info-box">
                                <span class="info-box-icon text-bg-success shadow-sm">
                                    <i class="bi bi-check-circle"></i>
                                </span>
                                <div class="info-box-content">
                                    <span class="info-box-text">Ödenen Sözleşme</span>
                                    <span class="info-box-number" id="stat-sozlesme-odenen">0 Adet</span>
                                </div>
                            </div>
                        </div>
                        <div class="col-12 col-sm-6 col-md-4">
                            <div class="info-box">
                                <span class="info-box-icon text-bg-warning shadow-sm">
                                    <i class="bi bi-hourglass-split"></i>
                                </span>
                                <div class="info-box-content">
                                    <span class="info-box-text">Kalan Sözleşme</span>
                                    <span class="info-box-number" id="stat-sozlesme-kalan">0 Adet</span>
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <!-- Filtre Kartı -->
                    <div class="card card-primary card-outline mb-3">
                        <div class="card-header">
                            <h3 class="card-title">
                                <i class="bi bi-funnel"></i> Filtrele
                            </h3>
                            <div class="card-tools">
                                <button type="button" class="btn btn-tool" data-bs-toggle="collapse" data-bs-target="#filterCollapse" aria-expanded="false">
                                    <i class="bi bi-chevron-down"></i>
                                </button>
                            </div>
                        </div>
                        <div class="card-body collapse show" id="filterCollapse">
                            <form id="filterForm">
                                <div class="row g-3">
                                    <div class="col-md-2">
                                        <label class="form-label">Cari Tip</label>
                                        <select class="form-select" id="filter_cari_tipi" name="cari_tipi_id">
                                            <option value="">Tümü</option>
                                            <?php foreach ($cariTipleri as $tip): ?>
                                                <option value="<?= $tip['cari_tipi_id'] ?>" <?= $tip['cari_tipi_id'] == 1 ? 'selected' : '' ?>><?= htmlspecialchars($tip['cari_tipi_ad']) ?></option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                    <div class="col-md-2">
                                        <label class="form-label">Ay <small class="text-muted">(Sözleşme Tarihi)</small></label>
                                        <select class="form-select" id="filter_ay" name="ay">
                                            <option value="">Tümü</option>
                                            <option value="1">Ocak</option>
                                            <option value="2">Şubat</option>
                                            <option value="3">Mart</option>
                                            <option value="4">Nisan</option>
                                            <option value="5">Mayıs</option>
                                            <option value="6">Haziran</option>
                                            <option value="7">Temmuz</option>
                                            <option value="8">Ağustos</option>
                                            <option value="9">Eylül</option>
                                            <option value="10">Ekim</option>
                                            <option value="11">Kasım</option>
                                            <option value="12">Aralık</option>
                                        </select>
                                    </div>
                                    <div class="col-md-2">
                                        <label class="form-label">Sezon</label>
                                        <select class="form-select" id="filter_sezon" name="sezon_id">
                                            <option value="">Tümü</option>
                                            <?php foreach ($sezonlar as $sezon): ?>
                                                <option value="<?= $sezon['sezon_id'] ?>" <?= $sezon['sezon_id'] == $defaultSezonId ? 'selected' : '' ?>><?= htmlspecialchars($sezon['sezon_ad']) ?></option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                    <div class="col-md-4 d-flex align-items-end">
                                        <button type="submit" class="btn btn-primary me-2">
                                            <i class="bi bi-search"></i> Filtrele
                                        </button>
                                        <button type="button" class="btn btn-secondary" id="clearFilters">
                                            <i class="bi bi-x-circle"></i> Temizle
                                        </button>
                                    </div>
                                </div>
                            </form>
                        </div>
                    </div>
                    
                    <!-- Rapor Tablosu -->
                    <div class="card card-primary card-outline">
                        <div class="card-header">
                            <h3 class="card-title"><i class="bi bi-bar-chart-line"></i> Aylık Satış Raporu <small class="text-muted" id="reportPeriod"></small></h3>
                            <div class="card-tools">
                                <button type="button" class="btn btn-sm btn-secondary" data-bs-toggle="collapse" data-bs-target="#filterCollapse">
                                    <i class="bi bi-funnel"></i> Filtrele
                                </button>
                                <button type="button" class="btn btn-sm btn-success" id="btnExport">
                                    <i class="bi bi-file-earmark-excel"></i> Excel
                                </button>
                            </div>
                        </div>
                        <div class="card-body">
                            <div class="table-responsive">
                                <table class="table table-bordered table-striped table-hover table-report" id="reportTable">
                                    <thead id="reportHead">
                                        <tr>
                                            <th>Ay</th>
                                            <th class="text-end">Toplam</th>
                                        </tr>
                                    </thead>
                                    <tbody id="reportBody">
                                        <tr>
                                            <td colspan="2" class="text-center">Yükleniyor...</td>
                                        </tr>
                                    </tbody>
                                    <tfoot id="reportFoot"></tfoot>
                                </table>
                            </div>
                        </div>
                    </div>
                    
                </div>
            </div>
        </main>
        
        <?php include __DIR__ . '/../includes/footer.php'; ?>
    </div>
    
    <script src="https://cdn.jsdelivr.net/npm/jquery@3.7.1/dist/jquery.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/@popperjs/core@2.11.8/dist/umd/popper.min.js" crossorigin="anonymous"></script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.min.js" crossorigin="anonymous"></script>
    <script src="/admin/assets/js/adminlte.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11/dist/sweetalert2.all.min.js"></script>
    <script src="/admin/assets/js/custom.js"></script>
    
    <script>
        let currentFilters = { cari_tipi_id: '1', sezon_id: '<?= $defaultSezonId ?>' };

        function loadReport() {
            $.post('', { action: 'report', ...currentFilters }, function(response) {
                if (!response.success) return;
                
                const cols           = response.columns; // [{id, ad}, ...]
                const data           = response.data;
                const colTotals      = response.col_totals; // {id: toplam, ...}
                const satisToplam    = response.satis_toplam;
                const kalanToplam    = response.kalan_toplam;
                
                // Info Box'ları güncelle (sözleşme adetleri)
                $('#stat-sozlesme-toplam').text(response.sozlesme_toplam + ' Adet');
                $('#stat-sozlesme-odenen').text(response.sozlesme_odenen + ' Adet');
                $('#stat-sozlesme-kalan').text(response.sozlesme_kalan + ' Adet');
                
                // Senet tipinin ID'sini bul
                let senetTipiId = null;
                cols.forEach(col => {
                    if (col.ad === 'Senet') {
                        senetTipiId = col.id;
                    }
                });
                window.senetTipiId = senetTipiId; // Global olarak sakla
                
                // Dinamik thead (ödeme tipi başlıkları - tahsilat sütunları)
                let thHtml = '<tr><th>Ay</th><th class="text-end">Sözleşme Satış Tutarı</th>';
                cols.forEach(col => {
                    thHtml += `<th class="text-end">${col.ad}</th>`;
                    // Senet sütununun hemen sağına "Ödenecek Senet" ekle
                    if (col.ad === 'Senet') {
                        thHtml += `<th class="text-end">Ödenecek Senet</th>`;
                    }
                });
                thHtml += '<th class="text-end text-warning">İcra</th>';
                thHtml += '<th class="text-end text-danger">Zaafiyet</th>';
                thHtml += '<th class="text-end">Kalan</th></tr>';
                $('#reportHead').html(thHtml);
                
                // Ay bilgilerini sakla (yil-ay formatında)
                window.reportData = data;
                window.reportCols = cols;
                
                const colCount = cols.length + 3;
                let html = '';
                data.forEach((row, idx) => {
                    const kalan      = row.kalan ?? 0;
                    const kalanClass = kalan > 0 ? 'text-danger' : (kalan < 0 ? 'text-success' : '');
                    
                    // Yıl ve ay doğrudan PHP'den geliyor
                    const yil = row.yil;
                    const ay = row.ay;
                    const dataAttr = `data-yil="${yil}" data-ay="${ay}"`;
                    
                    html += `<tr><td><strong>${row.ay_adi}</strong></td>`;
                    html += `<td class="text-end clickable-cell" ${dataAttr} data-tip="satis" title="Detay için tıklayın" onclick="handleCellClick(this)">${row.satis_tutari > 0 ? formatCurrency(row.satis_tutari) : '<span class="text-muted">-</span>'}</td>`;
                    cols.forEach(col => {
                        // odeme_tipleri objesinden ID ile değer al
                        const val = (row.odeme_tipleri && row.odeme_tipleri[col.id]) ? row.odeme_tipleri[col.id] : 0;
                        html += `<td class="text-end clickable-cell" ${dataAttr} data-tip="odeme_tipi" data-odeme-tipi-id="${col.id}" data-odeme-tipi="${col.ad}" title="Detay için tıklayın" onclick="handleCellClick(this)">${val > 0 ? formatCurrency(val) : '<span class="text-muted">-</span>'}</td>`;
                        
                        // Senet sütununun sağına "Ödenecek Senet" ekle
                        if (col.ad === 'Senet') {
                            const odenecekSenet = row.odenecek_senet || 0;
                            html += `<td class="text-end clickable-cell" ${dataAttr} data-tip="odenecek_senet" data-senet-tipi-id="${col.id}" title="Detay için tıklayın" onclick="handleCellClick(this)">${odenecekSenet > 0 ? formatCurrency(odenecekSenet) : '<span class="text-muted">-</span>'}</td>`;
                        }
                    });
                    // İcra sütunu (odeme_durum_id = 7)
                    const icra = row.icra || 0;
                    html += `<td class="text-end clickable-cell ${icra > 0 ? 'text-warning fw-bold' : ''}" ${dataAttr} data-tip="icra" title="Detay için tıklayın" onclick="handleCellClick(this)">${icra > 0 ? formatCurrency(icra) : '<span class="text-muted">-</span>'}</td>`;
                    // Zaafiyet sütunu (odeme_durum_id = 8)
                    const zaafiyet = row.zaafiyet || 0;
                    html += `<td class="text-end clickable-cell ${zaafiyet > 0 ? 'text-danger fw-bold' : ''}" ${dataAttr} data-tip="zaafiyet" title="Detay için tıklayın" onclick="handleCellClick(this)">${zaafiyet > 0 ? formatCurrency(zaafiyet) : '<span class="text-muted">-</span>'}</td>`;
                    html += `<td class="text-end fw-bold clickable-cell ${kalanClass}" ${dataAttr} data-tip="kalan" title="Detay için tıklayın" onclick="handleCellClick(this)">${formatCurrency(kalan)}</td></tr>`;
                });
                
                if (!html) {
                    html = `<tr><td colspan="${colCount}" class="text-center text-muted">Veri bulunamadı</td></tr>`;
                }
                $('#reportBody').html(html);
                
                // Tfoot (ID bazlı toplam)
                const kalanToplamClass = kalanToplam > 0 ? 'text-danger' : (kalanToplam < 0 ? 'text-success' : '');
                const odenecekSenetToplam = response.odenecek_senet_toplam || 0;
                const zaafiyetToplam = response.zaafiyet_toplam || 0;
                const icraToplam = response.icra_toplam || 0;
                let footHtml = `<tr><td><strong>TOPLAM</strong></td><td class="text-end">${formatCurrency(satisToplam)}</td>`;
                cols.forEach(col => {
                    footHtml += `<td class="text-end">${formatCurrency(colTotals[col.id] ?? 0)}</td>`;
                    // Senet sütununun sağına "Ödenecek Senet" toplamı ekle
                    if (col.ad === 'Senet') {
                        footHtml += `<td class="text-end">${formatCurrency(odenecekSenetToplam)}</td>`;
                    }
                });
                footHtml += `<td class="text-end ${icraToplam > 0 ? 'text-warning fw-bold' : ''}">${formatCurrency(icraToplam)}</td>`;
                footHtml += `<td class="text-end ${zaafiyetToplam > 0 ? 'text-danger fw-bold' : ''}">${formatCurrency(zaafiyetToplam)}</td>`;
                footHtml += `<td class="text-end ${kalanToplamClass}">${formatCurrency(kalanToplam)}</td></tr>`;
                $('#reportFoot').html(footHtml);
                
                if (response.ay_araligi) {
                    $('#reportPeriod').text('(' + response.ay_araligi + ')');
                }
            });
        }
        
        // Excel export
        $('#btnExport').on('click', function() {
            const table = document.getElementById('reportTable');
            let csv = [];
            const rows = table.querySelectorAll('tr');
            
            rows.forEach(row => {
                const cols = row.querySelectorAll('td, th');
                let rowData = [];
                cols.forEach(col => rowData.push('"' + col.innerText.replace(/"/g, '""') + '"'));
                csv.push(rowData.join(';'));
            });
            
            const csvContent = '\uFEFF' + csv.join('\n');
            const blob = new Blob([csvContent], { type: 'text/csv;charset=utf-8;' });
            const url = URL.createObjectURL(blob);
            const a = document.createElement('a');
            a.href = url;
            a.download = 'aylık-satis-raporu' + (currentFilters.ay ? '-ay' + currentFilters.ay : '') + '.csv';
            a.click();
            URL.revokeObjectURL(url);
            showToast('Excel dosyası indirildi', 'success');
        });
        
        // Filtre
        $('#filterForm').on('submit', function(e) {
            e.preventDefault();
            currentFilters = {
                cari_tipi_id: $('#filter_cari_tipi').val(),
                ay: $('#filter_ay').val(),
                sezon_id: $('#filter_sezon').val()
            };
            Object.keys(currentFilters).forEach(key => { if (!currentFilters[key]) delete currentFilters[key]; });
            loadReport();
            showToast('Filtre uygulandı', 'info');
        });
        
        $('#clearFilters').on('click', function() {
            $('#filterForm')[0].reset();
            $('#filter_cari_tipi').val('1').trigger('change.select2');
            $('#filter_ay').val('').trigger('change.select2');
            $('#filter_sezon').val('<?= $defaultSezonId ?>').trigger('change.select2');
            currentFilters = { cari_tipi_id: '1', sezon_id: '<?= $defaultSezonId ?>' };
            loadReport();
            showToast('Filtreler temizlendi', 'info');
        });

        // Detay sayfasına yönlendir
        function openDetailModal(yil, ay, tip, odemeTipiId, odemeTipiAd) {
            const sezonId = currentFilters.sezon_id || '';
            let url = `/admin/pages/rapor-detay.php?kaynak=aylık-satis&tip=${tip}&yil=${yil}&ay=${ay}`;
            
            if (sezonId) url += `&sezon_id=${sezonId}`;
            if (odemeTipiId) url += `&odeme_tipi_id=${odemeTipiId}`;
            
            window.location.href = url;
        }
        
        // Hücre tıklama olayı - rapor-detay.php'ye yönlendir
        function handleCellClick(cell) {
            const $cell = $(cell);
            const yil = $cell.data('yil');
            const ay = $cell.data('ay');
            const tip = $cell.data('tip');
            const odemeTipiId = $cell.data('odeme-tipi-id') || '';
            const odemeTipi = $cell.data('odeme-tipi') || '';
            const sezonId = typeof currentFilters !== 'undefined' ? (currentFilters.sezon_id || '') : '';
            
            if (yil && ay && tip) {
                // Ödenecek Senet tıklandığında ayrı sayfaya yönlendir
                if (tip === 'odenecek_senet') {
                    const senetId = $cell.data('senet-tipi-id') || window.senetTipiId || '';
                    window.location.href = `/admin/pages/odenecek-senet-detay.php?yil=${yil}&ay=${ay}&sezon_id=${sezonId}&senet_tipi_id=${senetId}`;
                    return;
                }
                
                // rapor-detay.php'ye yönlendir
                let url = `/admin/pages/rapor-detay.php?kaynak=aylık-satis&tip=${tip}&yil=${yil}&ay=${ay}`;
                if (sezonId) {
                    url += `&sezon_id=${sezonId}`;
                }
                if (tip === 'odeme_tipi' && odemeTipiId) {
                    url += `&odeme_tipi_id=${odemeTipiId}&odeme_tipi=${encodeURIComponent(odemeTipi)}`;
                }
                window.location.href = url;
            }
        }
        
        $(document).ready(function() {
            $('#filter_cari_tipi, #filter_ay, #filter_sezon').select2({
                theme: 'bootstrap-5',
                placeholder: 'Seçiniz...',
                allowClear: true,
                language: {
                    noResults: function() { return "Sonuç bulunamadı"; },
                    searching: function() { return "Aranıyor..."; }
                }
            });

            loadReport();
        });
    </script>
</body>
</html>
