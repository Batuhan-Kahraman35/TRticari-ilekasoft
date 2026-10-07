<?php
/**
 * Ülke Satış Raporu
 * Ülke bazında satış, tahsilat ve kalan tutarları
 */

require_once __DIR__ . '/../auth.php';
require_once __DIR__ . '/../includes/PageAuth.php';
requireAuth();

$user = Auth::user();
$db = Database::getInstance();

$currentPageFile = basename($_SERVER['PHP_SELF']);
$pagePermissions = PageAuth::checkPagePermissions(
    $user['kullanici_id'],
    $user['departman_id'],
    $currentPageFile
);

if (!$pagePermissions['has_access']) {
    PageAuth::accessDenied($pagePermissions['error'] ?? 'Bu sayfaya erişim yetkiniz bulunmamaktadır.');
}

$pageInfo = $db->fetchOne("
    SELECT 
        s.sayfalar_sayfa_adi, 
        s.sayfalar_aciklama,
        m.menuler_menu_adi as menu_adi
    FROM Menu_Sayfalar s
    LEFT JOIN Menuler m ON s.sayfalar_menu_id = m.menuler_id
    WHERE s.sayfalar_sayfa_url LIKE ? AND s.sayfalar_durum = 1
", ['%' . $currentPageFile]);

$pageTitle = $pageInfo['sayfalar_sayfa_adi'] ?? 'Ülke Satış Raporu';
$pageDescription = $pageInfo['sayfalar_aciklama'] ?? '';
$menuAdi = $pageInfo['menu_adi'] ?? null;

$siteAyarlari = $db->fetchOne("SELECT TOP 1 site_ayarlari_site_title FROM dbo.tanim_site_ayarlari ORDER BY site_ayarlari_id DESC");
$siteTitle = $siteAyarlari['site_ayarlari_site_title'] ?? 'Örnek Yazılım Portal';

$ulkeler = $db->fetchAll("
    SELECT DISTINCT u.UlkeId, u.UlkeAdi
    FROM Adres_Ulkeler u
    INNER JOIN Cari c ON c.cari_ulke = u.UlkeId
    INNER JOIN Sozlesmeler s ON s.sozlesme_cari_id = c.cari_id
    ORDER BY u.UlkeAdi
");

$odemeTipleri = $db->fetchAll("
    SELECT odeme_tipi_id, odeme_tipi_ad 
    FROM Sozlesme_OdemeTipleri 
    WHERE odeme_tipi_durum = 1 
    ORDER BY odeme_tipi_ad
");

$cariTipleri = $db->fetchAll("SELECT cari_tipi_id, cari_tipi_ad FROM Cari_CariTipleri WHERE cari_tipi_durum = 1 ORDER BY cari_tipi_sira");

$sezonlar = $db->fetchAll("SELECT sezon_id, sezon_ad FROM Sozlesme_Sezonlar WHERE sezon_durum = 1 ORDER BY sezon_ad DESC");

// Varsayılan sezon (URL parametresi - menü sezon bağlamı için, örn. Raporlar 2026 -> sezon_id=2)
$defaultSezonId = (isset($_GET['sezon_id']) && $_GET['sezon_id'] !== '') ? (int)$_GET['sezon_id'] : 2;

// AJAX İşlemleri
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json');
    $action = $_POST['action'] ?? '';
    
    try {
        switch ($action) {
            case 'stats':
                // Tüm zamana ait genel toplamlar (tarih filtresi yok)
                $toplamSatis = $db->fetchOne("
                    SELECT ISNULL(SUM(h.hareket_fiyat), 0) as toplam
                    FROM Sozlesme_StokHareketleri h
                ");
                
                $toplamTahsilat = $db->fetchOne("
                    SELECT ISNULL(SUM(o.odeme_tutar), 0) as toplam
                    FROM Sozlesme_Odemeler o
                    WHERE o.odeme_yapildi = 1
                ");
                
                $ulkeSayisi = $db->fetchOne("
                    SELECT COUNT(DISTINCT c.cari_ulke) as sayi
                    FROM Sozlesmeler s
                    INNER JOIN Cari c ON s.sozlesme_cari_id = c.cari_id
                    WHERE NULLIF(c.cari_ulke, 0) IS NOT NULL
                ");

                $sehirSayisi = $db->fetchOne("
                    SELECT COUNT(DISTINCT c.cari_sehirler) as sayi
                    FROM Sozlesmeler s
                    INNER JOIN Cari c ON s.sozlesme_cari_id = c.cari_id
                    WHERE NULLIF(c.cari_sehirler, 0) IS NOT NULL
                ");
                
                $satis = floatval($toplamSatis['toplam'] ?? 0);
                $tahsilat = floatval($toplamTahsilat['toplam'] ?? 0);
                
                $stats = [
                    'toplam_satis' => $satis,
                    'toplam_tahsilat' => $tahsilat,
                    'toplam_kalan' => $satis - $tahsilat,
                    'ulke_sayisi' => intval($ulkeSayisi['sayi'] ?? 0),
                    'sehir_sayisi' => intval($sehirSayisi['sayi'] ?? 0)
                ];
                echo json_encode(['success' => true, 'data' => $stats]);
                break;

            case 'get_sehirler':
                $ulkeId = $_POST['ulke_id'] ?? '';
                $where = $ulkeId ? "WHERE sh.UlkeId = ?" : '';
                $prm = $ulkeId ? [$ulkeId] : [];
                $sehirler = $db->fetchAll("
                    SELECT DISTINCT sh.SehirId, sh.SehirAdi
                    FROM Adres_Sehirler sh
                    INNER JOIN Cari c ON c.cari_sehirler = sh.SehirId
                    INNER JOIN Sozlesmeler s ON s.sozlesme_cari_id = c.cari_id
                    $where
                    ORDER BY sh.SehirAdi
                ", $prm);
                echo json_encode(['success' => true, 'data' => $sehirler]);
                break;
                
            case 'report':
                $ulkeFilter = $_POST['ulke_id'] ?? '';
                $sehirFilter = $_POST['sehir_id'] ?? '';
                $odemeTipiFilter = $_POST['odeme_tipi_id'] ?? '';
                $odemeDurumFilter = $_POST['odeme_durum'] ?? '';
                $cariTipiFilter = $_POST['cari_tipi_id'] ?? '';
                $sezonFilter = $_POST['sezon_id'] ?? '';
                
                // Satış filtresi
                $satisWhere  = "WHERE 1=1";
                $satisParams = [];
                if ($ulkeFilter) {
                    $satisWhere    .= " AND c.cari_ulke = ?";
                    $satisParams[] = $ulkeFilter;
                }
                if ($sehirFilter) {
                    $satisWhere    .= " AND c.cari_sehirler = ?";
                    $satisParams[] = $sehirFilter;
                }
                if ($cariTipiFilter) {
                    $satisWhere    .= " AND c.cari_tipi_id = ?";
                    $satisParams[] = $cariTipiFilter;
                }
                if ($sezonFilter) {
                    $satisWhere    .= " AND s.sozlesme_sezon_id = ?";
                    $satisParams[] = $sezonFilter;
                }
                
                // Tahsilat filtresi
                $tahsilatWhere  = "WHERE o.odeme_yapildi = 1";
                $tahsilatParams = [];
                if ($ulkeFilter) {
                    $tahsilatWhere  .= " AND c.cari_ulke = ?";
                    $tahsilatParams[] = $ulkeFilter;
                }
                if ($sehirFilter) {
                    $tahsilatWhere  .= " AND c.cari_sehirler = ?";
                    $tahsilatParams[] = $sehirFilter;
                }
                if ($odemeTipiFilter) {
                    $tahsilatWhere  .= " AND o.odeme_tipi_id = ?";
                    $tahsilatParams[] = $odemeTipiFilter;
                }
                if ($cariTipiFilter) {
                    $tahsilatWhere  .= " AND c.cari_tipi_id = ?";
                    $tahsilatParams[] = $cariTipiFilter;
                }
                if ($sezonFilter) {
                    $tahsilatWhere  .= " AND s.sozlesme_sezon_id = ?";
                    $tahsilatParams[] = $sezonFilter;
                }
                
                // Ülke + Şehir bazında satış (tüm zamanlar)
                $satisData = $db->fetchAll("
                    SELECT 
                        ISNULL(NULLIF(c.cari_ulke, 0), 0) as ulke_id,
                        ISNULL(u.UlkeAdi, 'Belirtilmemiş') as ulke_adi,
                        ISNULL(u.IkiliKod, '') as ulke_kod,
                        ISNULL(NULLIF(c.cari_sehirler, 0), 0) as sehir_id,
                        ISNULL(sh.SehirAdi, 'Belirtilmemiş') as sehir_adi,
                        COUNT(DISTINCT s.sozlesme_id) as sozlesme_adet,
                        ISNULL(SUM(h.hareket_fiyat), 0) as satis_tutari
                    FROM Sozlesme_StokHareketleri h
                    INNER JOIN Sozlesmeler s ON h.hareket_sozlesme_id = s.sozlesme_id
                    LEFT JOIN Cari c ON s.sozlesme_cari_id = c.cari_id
                    LEFT JOIN Adres_Ulkeler u ON NULLIF(c.cari_ulke, 0) = u.UlkeId
                    LEFT JOIN Adres_Sehirler sh ON NULLIF(c.cari_sehirler, 0) = sh.SehirId
                    $satisWhere
                    GROUP BY NULLIF(c.cari_ulke, 0), u.UlkeAdi, u.IkiliKod, NULLIF(c.cari_sehirler, 0), sh.SehirAdi
                    ORDER BY ISNULL(SUM(h.hareket_fiyat), 0) DESC
                ", $satisParams);
                
                // Ülke + Şehir + Ödeme tipi bazında tahsilat (odeme_yapildi=1)
                $rawTahsilatData = $db->fetchAll("
                    SELECT 
                        ISNULL(NULLIF(c.cari_ulke, 0), 0) as ulke_id,
                        ISNULL(NULLIF(c.cari_sehirler, 0), 0) as sehir_id,
                        o.odeme_tipi_id,
                        ot.odeme_tipi_ad,
                        ISNULL(SUM(o.odeme_tutar), 0) as tutar
                    FROM Sozlesme_Odemeler o
                    INNER JOIN Sozlesmeler s ON o.odeme_sozlesme_id = s.sozlesme_id
                    INNER JOIN Sozlesme_OdemeTipleri ot ON ot.odeme_tipi_id = o.odeme_tipi_id
                    LEFT JOIN Cari c ON s.sozlesme_cari_id = c.cari_id
                    $tahsilatWhere
                    GROUP BY NULLIF(c.cari_ulke, 0), NULLIF(c.cari_sehirler, 0), o.odeme_tipi_id, ot.odeme_tipi_ad
                    ORDER BY ot.odeme_tipi_ad
                ", $tahsilatParams);
                
                // Kolonlar: aktif tüm ödeme tipleri (tahsilatı olmasa bile görünsün)
                $columns = [];
                $columnIds = [];
                $tahsilatMap = []; // [key][odeme_tipi_id] = tutar

                $kolonOdemeTipleri = $odemeTipleri;
                if ($odemeTipiFilter) {
                    $kolonOdemeTipleri = array_values(array_filter($kolonOdemeTipleri, function ($t) use ($odemeTipiFilter) {
                        return (string)$t['odeme_tipi_id'] === (string)$odemeTipiFilter;
                    }));
                }

                foreach ($kolonOdemeTipleri as $tip) {
                    $columnIds[$tip['odeme_tipi_id']] = $tip['odeme_tipi_ad'];
                    $columns[] = ['id' => $tip['odeme_tipi_id'], 'ad' => $tip['odeme_tipi_ad']];
                }

                foreach ($rawTahsilatData as $row) {
                    $tipId = $row['odeme_tipi_id'];
                    $tipAd = $row['odeme_tipi_ad'];
                    $key = intval($row['ulke_id']) . '_' . intval($row['sehir_id']);
                    
                    // Pasife alınmış ama tahsilatı olan tip de kolonlarda yer alsın
                    if (!isset($columnIds[$tipId])) {
                        $columnIds[$tipId] = $tipAd;
                        $columns[] = ['id' => $tipId, 'ad' => $tipAd];
                    }

                    if (!isset($tahsilatMap[$key])) {
                        $tahsilatMap[$key] = [];
                    }
                    $tahsilatMap[$key][$tipId] = floatval($row['tutar']);
                }
                
                // Senet tipinin ID'sini bul
                $senetTipi = $db->fetchOne("SELECT odeme_tipi_id FROM Sozlesme_OdemeTipleri WHERE odeme_tipi_ad = 'Senet' AND odeme_tipi_durum = 1");
                $senetTipiId = $senetTipi['odeme_tipi_id'] ?? null;
                
                // Ülke+Şehir bazında Ödenecek Senet (odeme_yapildi=0, senet tipi)
                $odenecekSenetMap = [];
                if ($senetTipiId) {
                    $odenecekSenetWhere = "WHERE o.odeme_tipi_id = ? AND o.odeme_yapildi = 0 AND ISNULL(o.odeme_durum_id, 0) NOT IN (7, 8)";
                    $odenecekSenetParams = [$senetTipiId];
                    if ($ulkeFilter) {
                        $odenecekSenetWhere .= " AND c.cari_ulke = ?";
                        $odenecekSenetParams[] = $ulkeFilter;
                    }
                    if ($sehirFilter) {
                        $odenecekSenetWhere .= " AND c.cari_sehirler = ?";
                        $odenecekSenetParams[] = $sehirFilter;
                    }
                    if ($cariTipiFilter) {
                        $odenecekSenetWhere .= " AND c.cari_tipi_id = ?";
                        $odenecekSenetParams[] = $cariTipiFilter;
                    }
                    if ($sezonFilter) {
                        $odenecekSenetWhere .= " AND s.sozlesme_sezon_id = ?";
                        $odenecekSenetParams[] = $sezonFilter;
                    }
                    $odenecekSenetData = $db->fetchAll("
                        SELECT 
                            ISNULL(NULLIF(c.cari_ulke, 0), 0) as ulke_id,
                            ISNULL(NULLIF(c.cari_sehirler, 0), 0) as sehir_id,
                            ISNULL(SUM(o.odeme_tutar), 0) as tutar
                        FROM Sozlesme_Odemeler o
                        INNER JOIN Sozlesmeler s ON o.odeme_sozlesme_id = s.sozlesme_id
                        LEFT JOIN Cari c ON s.sozlesme_cari_id = c.cari_id
                        $odenecekSenetWhere
                        GROUP BY NULLIF(c.cari_ulke, 0), NULLIF(c.cari_sehirler, 0)
                    ", $odenecekSenetParams);
                    
                    foreach ($odenecekSenetData as $row) {
                        $key = intval($row['ulke_id']) . '_' . intval($row['sehir_id']);
                        $odenecekSenetMap[$key] = floatval($row['tutar']);
                    }
                }
                
                // Ülke+Şehir bazında İcra (odeme_durum_id = 7, sadece yapılmamış)
                $icraWhere = "WHERE o.odeme_durum_id = 7 AND o.odeme_yapildi = 0";
                $icraParams = [];
                if ($ulkeFilter) {
                    $icraWhere .= " AND c.cari_ulke = ?";
                    $icraParams[] = $ulkeFilter;
                }
                if ($sehirFilter) {
                    $icraWhere .= " AND c.cari_sehirler = ?";
                    $icraParams[] = $sehirFilter;
                }
                if ($cariTipiFilter) {
                    $icraWhere .= " AND c.cari_tipi_id = ?";
                    $icraParams[] = $cariTipiFilter;
                }
                if ($sezonFilter) {
                    $icraWhere .= " AND s.sozlesme_sezon_id = ?";
                    $icraParams[] = $sezonFilter;
                }
                $icraData = $db->fetchAll("
                    SELECT 
                        ISNULL(NULLIF(c.cari_ulke, 0), 0) as ulke_id,
                        ISNULL(NULLIF(c.cari_sehirler, 0), 0) as sehir_id,
                        ISNULL(SUM(o.odeme_tutar), 0) as icra_tutari
                    FROM Sozlesme_Odemeler o
                    INNER JOIN Sozlesmeler s ON o.odeme_sozlesme_id = s.sozlesme_id
                    LEFT JOIN Cari c ON s.sozlesme_cari_id = c.cari_id
                    $icraWhere
                    GROUP BY NULLIF(c.cari_ulke, 0), NULLIF(c.cari_sehirler, 0)
                ", $icraParams);
                
                $icraMap = [];
                foreach ($icraData as $row) {
                    $key = intval($row['ulke_id']) . '_' . intval($row['sehir_id']);
                    $icraMap[$key] = floatval($row['icra_tutari']);
                }
                
                // Ülke+Şehir bazında Zaafiyet (odeme_durum_id = 8, sadece yapılmamış)
                $zaafiyetWhere = "WHERE o.odeme_durum_id = 8 AND o.odeme_yapildi = 0";
                $zaafiyetParams = [];
                if ($ulkeFilter) {
                    $zaafiyetWhere .= " AND c.cari_ulke = ?";
                    $zaafiyetParams[] = $ulkeFilter;
                }
                if ($sehirFilter) {
                    $zaafiyetWhere .= " AND c.cari_sehirler = ?";
                    $zaafiyetParams[] = $sehirFilter;
                }
                if ($cariTipiFilter) {
                    $zaafiyetWhere .= " AND c.cari_tipi_id = ?";
                    $zaafiyetParams[] = $cariTipiFilter;
                }
                if ($sezonFilter) {
                    $zaafiyetWhere .= " AND s.sozlesme_sezon_id = ?";
                    $zaafiyetParams[] = $sezonFilter;
                }
                $zaafiyetData = $db->fetchAll("
                    SELECT 
                        ISNULL(NULLIF(c.cari_ulke, 0), 0) as ulke_id,
                        ISNULL(NULLIF(c.cari_sehirler, 0), 0) as sehir_id,
                        ISNULL(SUM(o.odeme_tutar), 0) as zaafiyet_tutari
                    FROM Sozlesme_Odemeler o
                    INNER JOIN Sozlesmeler s ON o.odeme_sozlesme_id = s.sozlesme_id
                    LEFT JOIN Cari c ON s.sozlesme_cari_id = c.cari_id
                    $zaafiyetWhere
                    GROUP BY NULLIF(c.cari_ulke, 0), NULLIF(c.cari_sehirler, 0)
                ", $zaafiyetParams);
                
                $zaafiyetMap = [];
                foreach ($zaafiyetData as $row) {
                    $key = intval($row['ulke_id']) . '_' . intval($row['sehir_id']);
                    $zaafiyetMap[$key] = floatval($row['zaafiyet_tutari']);
                }
                
                // Rapor verisi oluştur
                $report = [];
                $genelSatis = 0;
                $genelAdet = 0;
                $genelZaafiyet = 0;
                $genelIcra = 0;
                $genelOdenecekSenet = 0;
                $genelKalan = 0;
                $colTotals = [];
                foreach ($columns as $col) {
                    $colTotals[$col['id']] = 0.0;
                }

                // Sezonun bölge tutarları (Adres_Bolgeler) - şehir bazında tek tutar;
                // ilçe bazlı şehirlerde (İzmir-2, İzmir-7) her ilçede aynı tutar durur, toplanmaz
                $bolgeTutarMap = [];
                if ($sezonFilter) {
                    $bolgeRows = $db->fetchAll("
                        SELECT BolgeSehirId, MAX(BolgeTutar) AS bolge_tutar
                        FROM Adres_Bolgeler
                        WHERE BolgeSezonId = ? AND Durum = 1 AND BolgeTutar IS NOT NULL
                        GROUP BY BolgeSehirId
                    ", [$sezonFilter]);
                    foreach ($bolgeRows as $b) {
                        $bolgeTutarMap[intval($b['BolgeSehirId'])] = floatval($b['bolge_tutar']);
                    }
                }

                foreach ($satisData as $row) {
                    $uid = intval($row['ulke_id']);
                    $sid = intval($row['sehir_id']);
                    $key = $uid . '_' . $sid;
                    $satis = floatval($row['satis_tutari']);
                    $zaafiyet = $zaafiyetMap[$key] ?? 0;
                    $icra = $icraMap[$key] ?? 0;
                    $odenecekSenet = $odenecekSenetMap[$key] ?? 0;
                    
                    // Ödeme durumu filtresi: sadece ilgili verisi olanları göster
                    if ($odemeDurumFilter === 'odenecek_senet' && $odenecekSenet <= 0) continue;
                    if ($odemeDurumFilter === 'icra' && $icra <= 0) continue;
                    if ($odemeDurumFilter === 'zaafiyet' && $zaafiyet <= 0) continue;
                    
                    // Ödeme tipleri
                    $odemeTipleri = [];
                    $tahsilatToplam = 0;
                    foreach ($columns as $col) {
                        $tipId = $col['id'];
                        $val = $tahsilatMap[$key][$tipId] ?? 0;
                        $odemeTipleri[$tipId] = $val;
                        $tahsilatToplam += $val;
                        $colTotals[$tipId] += $val;
                    }
                    
                    $kalan = max(0, $satis - $tahsilatToplam - $odenecekSenet - $icra - $zaafiyet);
                    
                    $genelAdet += intval($row['sozlesme_adet']);
                    $genelSatis += $satis;
                    $genelZaafiyet += $zaafiyet;
                    $genelIcra += $icra;
                    $genelOdenecekSenet += $odenecekSenet;
                    $genelKalan += $kalan;

                    // Bölge tutarından satış düşülür (eksi = bölge tutarı aşıldı)
                    $bolgeTutar = $bolgeTutarMap[$sid] ?? null;
                    $bolgeKalan = $bolgeTutar !== null ? $bolgeTutar - $satis : null;

                    $report[] = [
                        'ulke_id' => $uid,
                        'ulke_adi' => $row['ulke_adi'],
                        'ulke_kod' => $row['ulke_kod'],
                        'sehir_id' => $sid,
                        'sehir_adi' => $row['sehir_adi'],
                        'sozlesme_adet' => intval($row['sozlesme_adet']),
                        'satis_tutari' => $satis,
                        'bolge_tutar' => $bolgeTutar,
                        'bolge_kalan' => $bolgeKalan,
                        'odeme_tipleri' => $odemeTipleri,
                        'odenecek_senet' => $odenecekSenet,
                        'icra' => $icra,
                        'zaafiyet' => $zaafiyet,
                        'kalan' => $kalan
                    ];
                }
                
                // Kalan sütununa göre büyükten küçüğe sırala
                usort($report, function($a, $b) {
                    return $b['kalan'] <=> $a['kalan'];
                });
                
                // Genel tahsilat toplamı
                $genelTahsilat = 0;
                foreach ($colTotals as $val) {
                    $genelTahsilat += $val;
                }
                
                echo json_encode([
                    'success' => true, 
                    'columns' => $columns,
                    'data' => $report,
                    'col_totals' => $colTotals,
                    'toplam' => [
                        'adet' => $genelAdet,
                        'satis' => $genelSatis,
                        'tahsilat' => $genelTahsilat,
                        'icra' => $genelIcra,
                        'zaafiyet' => $genelZaafiyet,
                        'odenecek_senet' => $genelOdenecekSenet,
                        'kalan' => $genelKalan
                    ]
                ]);
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
        .flag-icon { width: 24px; height: 16px; margin-right: 8px; vertical-align: middle; border-radius: 2px; }
        .clickable-cell { cursor: pointer; transition: background-color 0.2s; }
        .clickable-cell:hover { background-color: #e3f2fd !important; }
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
                        <div class="col-12 col-sm-6 col-md-3">
                            <div class="info-box">
                                <span class="info-box-icon text-bg-primary shadow-sm">
                                    <i class="bi bi-cart-check"></i>
                                </span>
                                <div class="info-box-content">
                                    <span class="info-box-text">Toplam Satış</span>
                                    <span class="info-box-number" id="stat-toplam-satis">0 ₺</span>
                                </div>
                            </div>
                        </div>
                        <div class="col-12 col-sm-6 col-md-3">
                            <div class="info-box">
                                <span class="info-box-icon text-bg-success shadow-sm">
                                    <i class="bi bi-cash-stack"></i>
                                </span>
                                <div class="info-box-content">
                                    <span class="info-box-text">Toplam Tahsilat</span>
                                    <span class="info-box-number" id="stat-toplam-tahsilat">0 ₺</span>
                                </div>
                            </div>
                        </div>
                        <div class="col-12 col-sm-6 col-md-3">
                            <div class="info-box">
                                <span class="info-box-icon text-bg-warning shadow-sm">
                                    <i class="bi bi-hourglass-split"></i>
                                </span>
                                <div class="info-box-content">
                                    <span class="info-box-text">Açık Hesap</span>
                                    <span class="info-box-number" id="stat-toplam-kalan">0 ₺</span>
                                </div>
                            </div>
                        </div>
                        <div class="col-12 col-sm-6 col-md-3">
                            <div class="info-box">
                                <span class="info-box-icon text-bg-info shadow-sm">
                                    <i class="bi bi-globe"></i>
                                </span>
                                <div class="info-box-content">
                                    <span class="info-box-text">Ülke Sayısı</span>
                                    <span class="info-box-number" id="stat-ulke-sayisi">0</span>
                                </div>
                            </div>
                        </div>
                        <div class="col-12 col-sm-6 col-md-3">
                            <div class="info-box">
                                <span class="info-box-icon text-bg-secondary shadow-sm">
                                    <i class="bi bi-building"></i>
                                </span>
                                <div class="info-box-content">
                                    <span class="info-box-text">Şehir Sayısı</span>
                                    <span class="info-box-number" id="stat-sehir-sayisi">0</span>
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
                                        <label class="form-label">Sezon</label>
                                        <select class="form-select" id="filter_sezon" name="sezon_id">
                                            <option value="">Tümü</option>
                                            <?php foreach ($sezonlar as $szn): ?>
                                                <option value="<?= $szn['sezon_id'] ?>" <?= $szn['sezon_id'] == $defaultSezonId ? 'selected' : '' ?>><?= htmlspecialchars($szn['sezon_ad']) ?></option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                    <div class="col-md-2">
                                        <label class="form-label">Cari Tip</label>
                                        <select class="form-select" id="filter_cari_tipi" name="cari_tipi_id">
                                            <option value="">Tümü</option>
                                            <?php foreach ($cariTipleri as $ct): ?>
                                                <option value="<?= $ct['cari_tipi_id'] ?>" <?= $ct['cari_tipi_id'] == 1 ? 'selected' : '' ?>><?= htmlspecialchars($ct['cari_tipi_ad']) ?></option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                    <div class="col-md-2">
                                        <label class="form-label">Ülke</label>
                                        <select class="form-select" id="filter_ulke" name="ulke_id">
                                            <option value="">Tümü</option>
                                            <?php foreach ($ulkeler as $ulke): ?>
                                                <option value="<?= $ulke['UlkeId'] ?>"><?= htmlspecialchars($ulke['UlkeAdi']) ?></option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                    <div class="col-md-2">
                                        <label class="form-label">Şehir</label>
                                        <select class="form-select" id="filter_sehir" name="sehir_id">
                                            <option value="">Tümü</option>
                                        </select>
                                    </div>
                                    <div class="col-md-2">
                                        <label class="form-label">Ödeme Tipi</label>
                                        <select class="form-select" id="filter_odeme_tipi" name="odeme_tipi_id">
                                            <option value="">Tümü</option>
                                            <?php foreach ($odemeTipleri as $tip): ?>
                                                <option value="<?= $tip['odeme_tipi_id'] ?>"><?= htmlspecialchars($tip['odeme_tipi_ad']) ?></option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                    <div class="col-md-2">
                                        <label class="form-label">Ödeme Durumu</label>
                                        <select class="form-select" id="filter_odeme_durum" name="odeme_durum">
                                            <option value="">Tümü</option>
                                            <option value="odenecek_senet">Ödenecek Senet</option>
                                            <option value="icra">İcra</option>
                                            <option value="zaafiyet">Zaafiyet</option>
                                        </select>
                                    </div>
                                    <div class="col-md-12">
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
                            <h3 class="card-title"><i class="bi bi-globe-americas"></i> Ülke Satış Raporu</h3>
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
                                            <th>Ülke</th>
                                            <th>Şehir</th>
                                            <th class="text-center">Adet</th>
                                            <th class="text-end">Satış Tutarı</th>
                                            <th class="text-end">Açık Hesap</th>
                                        </tr>
                                    </thead>
                                    <tbody id="reportBody">
                                        <tr>
                                            <td colspan="4" class="text-center">Yükleniyor...</td>
                                        </tr>
                                    </tbody>
                                    <tfoot id="reportFoot">
                                    </tfoot>
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
        let currentFilters = {};
        
        function loadStats() {
            $.post('', { action: 'stats', ...currentFilters }, function(response) {
                if (response.success) {
                    const d = response.data;
                    $('#stat-toplam-satis').text(formatTutar(d.toplam_satis));
                    $('#stat-toplam-tahsilat').text(formatTutar(d.toplam_tahsilat));
                    $('#stat-toplam-kalan').text(formatTutar(d.toplam_kalan));
                    $('#stat-ulke-sayisi').text(d.ulke_sayisi);
                    $('#stat-sehir-sayisi').text(d.sehir_sayisi);
                }
            });
        }
        
        function loadSehirler(ulkeId) {
            $('#filter_sehir').empty().append('<option value="">Tümü</option>');
            $.post('', { action: 'get_sehirler', ulke_id: ulkeId }, function(response) {
                if (response.success) {
                    response.data.forEach(function(s) {
                        $('#filter_sehir').append(`<option value="${s.SehirId}">${s.SehirAdi}</option>`);
                    });
                    $('#filter_sehir').trigger('change.select2');
                }
            });
        }
        
        // Rapor tutarları kuruşsuz gösterilir
        function formatTutar(amount, currency = '₺') {
            if (amount === null || amount === undefined) return '-';
            return parseFloat(amount).toLocaleString('tr-TR', { maximumFractionDigits: 0 }) + ' ' + currency;
        }

        // Satış hücresinin altına: bölge tutarı - satış (Adres_Bolgeler.BolgeTutar)
        function bolgeKalanHtml(kalan, bolgeTutar) {
            if (kalan === null || kalan === undefined) return '';
            return `<div class="bolge-kalan small fw-bold text-danger" title="Bölge tutarı: ${formatTutar(bolgeTutar)} - Satış = Kalan">${formatTutar(kalan)}</div>`;
        }

        function loadReport() {
            $.post('', { action: 'report', ...currentFilters }, function(response) {
                if (response.success) {
                    const cols = response.columns || [];
                    const data = response.data;
                    const toplam = response.toplam;
                    const colTotals = response.col_totals || {};
                    
                    // Dinamik thead oluştur
                    let thHtml = '<tr><th>Ülke</th><th>Şehir</th><th class="text-center">Adet</th><th class="text-end">Satış Tutarı</th>';
                    cols.forEach(col => {
                        thHtml += `<th class="text-end">${col.ad}</th>`;
                        if (col.ad === 'Senet') {
                            thHtml += `<th class="text-end">Ödenecek Senet</th>`;
                        }
                    });
                    thHtml += '<th class="text-end text-warning">İcra</th>';
                    thHtml += '<th class="text-end text-danger">Zaafiyet</th>';
                    thHtml += '<th class="text-end">Açık Hesap</th></tr>';
                    $('#reportHead').html(thHtml);
                    
                    // Tablo içeriği
                    let html = '';
                    const colCount = cols.length + 7; // Ülke + Şehir + Adet + Satış + Ödeme Tipleri + Ödenecek Senet + İcra + Zaafiyet + Kalan
                    
                    data.forEach(row => {
                        const kalanClass = row.kalan > 0 ? 'text-negative' : (row.kalan < 0 ? 'text-positive' : '');
                        const zaafiyetClass = row.zaafiyet > 0 ? 'text-danger fw-bold' : '';
                        const icraClass = row.icra > 0 ? 'text-warning fw-bold' : '';
                        const flagUrl = row.ulke_kod ? `https://flagcdn.com/24x18/${row.ulke_kod.toLowerCase()}.png` : '';
                        const flagImg = flagUrl ? `<img src="${flagUrl}" class="flag-icon" alt="${row.ulke_adi}" onerror="this.style.display='none'">` : '<i class="bi bi-geo-alt me-1"></i>';
                        const dataAttr = `data-ulke-id="${row.ulke_id}" data-sehir-id="${row.sehir_id}"`;
                        
                        html += `<tr>`;
                        html += `<td>${flagImg}<strong>${row.ulke_adi}</strong></td>`;
                        html += `<td>${row.sehir_adi}</td>`;
                        html += `<td class="text-center">${row.sozlesme_adet || 0}</td>`;
                        html += `<td class="text-end clickable-cell" ${dataAttr} data-tip="satis" title="Detay için tıklayın" onclick="handleCellClick(this)">${row.satis_tutari > 0 ? formatTutar(row.satis_tutari) : '<span class="text-muted">-</span>'}${bolgeKalanHtml(row.bolge_kalan, row.bolge_tutar)}</td>`;
                        
                        // Ödeme tipleri
                        cols.forEach(col => {
                            const val = (row.odeme_tipleri && row.odeme_tipleri[col.id]) ? row.odeme_tipleri[col.id] : 0;
                            html += `<td class="text-end clickable-cell" ${dataAttr} data-tip="odeme_tipi" data-odeme-tipi-id="${col.id}" data-odeme-tipi="${col.ad}" title="Detay için tıklayın" onclick="handleCellClick(this)">${val > 0 ? formatTutar(val) : '<span class="text-muted">-</span>'}</td>`;
                            
                            if (col.ad === 'Senet') {
                                const odenecekSenet = row.odenecek_senet || 0;
                                html += `<td class="text-end clickable-cell" ${dataAttr} data-tip="odenecek_senet" title="Detay için tıklayın" onclick="handleCellClick(this)">${odenecekSenet > 0 ? formatTutar(odenecekSenet) : '<span class="text-muted">-</span>'}</td>`;
                            }
                        });
                        
                        // İcra sütunu (odeme_durum_id = 7)
                        html += `<td class="text-end clickable-cell ${icraClass}" ${dataAttr} data-tip="icra" title="Detay için tıklayın" onclick="handleCellClick(this)">${row.icra > 0 ? formatTutar(row.icra) : '<span class="text-muted">-</span>'}</td>`;
                        html += `<td class="text-end clickable-cell ${zaafiyetClass}" ${dataAttr} data-tip="zaafiyet" title="Detay için tıklayın" onclick="handleCellClick(this)">${row.zaafiyet > 0 ? formatTutar(row.zaafiyet) : '<span class="text-muted">-</span>'}</td>`;
                        html += `<td class="text-end clickable-cell ${kalanClass}" ${dataAttr} data-tip="kalan" title="Detay için tıklayın" onclick="handleCellClick(this)">${formatTutar(row.kalan)}</td>`;
                        html += `</tr>`;
                    });
                    
                    if (!html) {
                        html = `<tr><td colspan="${colCount}" class="text-center text-muted">Veri bulunamadı</td></tr>`;
                    }
                    
                    $('#reportBody').html(html);
                    
                    // Tfoot oluştur
                    const toplamKalanClass = toplam.kalan > 0 ? 'text-negative' : (toplam.kalan < 0 ? 'text-positive' : '');
                    const toplamZaafiyetClass = toplam.zaafiyet > 0 ? 'text-danger fw-bold' : '';
                    const toplamIcraClass = toplam.icra > 0 ? 'text-warning fw-bold' : '';
                    
                    let footHtml = '<tr><td colspan="2"><strong>TOPLAM</strong></td>';
                    footHtml += `<td class="text-center"><strong>${toplam.adet || 0}</strong></td>`;
                    footHtml += `<td class="text-end clickable-cell" data-tip="satis" title="Detay için tıklayın" onclick="handleCellClick(this)">${formatTutar(toplam.satis)}</td>`;
                    
                    cols.forEach(col => {
                        footHtml += `<td class="text-end clickable-cell" data-tip="odeme_tipi" data-odeme-tipi-id="${col.id}" data-odeme-tipi="${col.ad}" title="Detay için tıklayın" onclick="handleCellClick(this)">${formatTutar(colTotals[col.id] ?? 0)}</td>`;
                        if (col.ad === 'Senet') {
                            footHtml += `<td class="text-end clickable-cell" data-tip="odenecek_senet" title="Detay için tıklayın" onclick="handleCellClick(this)">${formatTutar(toplam.odenecek_senet || 0)}</td>`;
                        }
                    });
                    
                    footHtml += `<td class="text-end clickable-cell ${toplamIcraClass}" data-tip="icra" title="Detay için tıklayın" onclick="handleCellClick(this)">${toplam.icra > 0 ? formatTutar(toplam.icra) : '-'}</td>`;
                    footHtml += `<td class="text-end clickable-cell ${toplamZaafiyetClass}" data-tip="zaafiyet" title="Detay için tıklayın" onclick="handleCellClick(this)">${toplam.zaafiyet > 0 ? formatTutar(toplam.zaafiyet) : '-'}</td>`;
                    footHtml += `<td class="text-end clickable-cell ${toplamKalanClass}" data-tip="kalan" title="Detay için tıklayın" onclick="handleCellClick(this)">${formatTutar(toplam.kalan)}</td>`;
                    footHtml += '</tr>';
                    
                    $('#reportFoot').html(footHtml);
                }
            });
        }
        
        function handleCellClick(cell) {
            const $cell = $(cell);
            const ulkeId = $cell.data('ulke-id') || '';
            const sehirId = $cell.data('sehir-id') || '';
            const tip = $cell.data('tip') || 'satis';
            const odemeTipiId = $cell.data('odeme-tipi-id') || '';
            const odemeTipi = $cell.data('odeme-tipi') || '';
            
            let url = `/admin/pages/rapor-detay.php?kaynak=ulke-satis&tip=${tip}`;
            if (ulkeId) url += `&ulke_id=${ulkeId}`;
            if (sehirId) url += `&sehir_id=${sehirId}`;
            if (tip === 'odeme_tipi' && odemeTipiId) {
                url += `&odeme_tipi_id=${odemeTipiId}&odeme_tipi=${encodeURIComponent(odemeTipi)}`;
            }
            // Aktif filtreleri de ekle
            if (currentFilters.sezon_id) url += `&sezon_id=${currentFilters.sezon_id}`;
            if (currentFilters.cari_tipi_id) url += `&cari_tipi_id=${currentFilters.cari_tipi_id}`;
            if (currentFilters.odeme_tipi_id) url += `&odeme_tipi_id=${currentFilters.odeme_tipi_id}`;
            if (currentFilters.odeme_durum) url += `&odeme_durum=${currentFilters.odeme_durum}`;
            
            window.location.href = url;
        }
        
        $('#btnExport').on('click', function() {
            const table = document.getElementById('reportTable');
            let csv = [];
            const rows = table.querySelectorAll('tr');
            rows.forEach(row => {
                const cols = row.querySelectorAll('td, th');
                let rowData = [];
                cols.forEach(col => {
                    // Bölge kalan satırı ekranda gösterim içindir, CSV hücresine karışmasın
                    const kopya = col.cloneNode(true);
                    kopya.querySelectorAll('.bolge-kalan').forEach(el => el.remove());
                    rowData.push('"' + kopya.textContent.trim().replace(/"/g, '""') + '"');
                });
                csv.push(rowData.join(';'));
            });
            const csvContent = '\uFEFF' + csv.join('\n');
            const blob = new Blob([csvContent], { type: 'text/csv;charset=utf-8;' });
            const url = URL.createObjectURL(blob);
            const a = document.createElement('a');
            a.href = url;
            a.download = 'ulke-satis-raporu.csv';
            a.click();
            URL.revokeObjectURL(url);
            showToast('Excel dosyası indirildi', 'success');
        });
        
        $('#filterForm').on('submit', function(e) {
            e.preventDefault();
            currentFilters = {
                sezon_id: $('#filter_sezon').val(),
                cari_tipi_id: $('#filter_cari_tipi').val(),
                ulke_id: $('#filter_ulke').val(),
                sehir_id: $('#filter_sehir').val(),
                odeme_tipi_id: $('#filter_odeme_tipi').val(),
                odeme_durum: $('#filter_odeme_durum').val()
            };
            Object.keys(currentFilters).forEach(key => {
                if (!currentFilters[key]) delete currentFilters[key];
            });
            loadStats();
            loadReport();
            showToast('Filtre uygulandı', 'info');
        });
        
        $('#clearFilters').on('click', function() {
            $('#filterForm')[0].reset();
            $('#filter_sezon').val('<?= $defaultSezonId ?>').trigger('change.select2');
            $('#filter_cari_tipi').val('1').trigger('change.select2');
            $('#filter_ulke').val('').trigger('change.select2');
            $('#filter_sehir').empty().append('<option value="">Tümü</option>').trigger('change.select2');
            $('#filter_odeme_tipi').val('').trigger('change.select2');
            $('#filter_odeme_durum').val('').trigger('change.select2');
            currentFilters = { sezon_id: '<?= $defaultSezonId ?>', cari_tipi_id: '1' };
            loadStats();
            loadReport();
            showToast('Filtreler temizlendi', 'info');
        });
        
        $(document).ready(function() {
            $('#filter_sezon, #filter_cari_tipi, #filter_ulke, #filter_sehir, #filter_odeme_tipi, #filter_odeme_durum').select2({
                theme: 'bootstrap-5',
                placeholder: 'Seçiniz...',
                allowClear: true,
                language: {
                    noResults: function() { return "Sonuç bulunamadı"; },
                    searching: function() { return "Aranıyor..."; }
                }
            });
            
            $('#filter_ulke').on('change', function() {
                loadSehirler($(this).val());
            });
            
            currentFilters = { sezon_id: '<?= $defaultSezonId ?>', cari_tipi_id: '1' };
            loadStats();
            loadReport();
        });
    </script>
</body>
</html>
