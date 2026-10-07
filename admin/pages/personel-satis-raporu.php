<?php
/**
 * Personel Satış Raporu
 * Personel bazında satış, tahsilat ve kalan tutarları
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

$pageTitle = $pageInfo['sayfalar_sayfa_adi'] ?? 'Personel Satış Raporu';
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

/**
 * Filtre koşulunu üretir. Sözleşme tablosu "s", cari tablosu "c" takma adıyla JOIN edilmiş olmalı.
 */
function personelRaporFiltresi(array $post): array {
    $where  = "";
    $params = [];

    if (!empty($post['ay'])) {
        $where .= " AND MONTH(s.sozlesme_tarih) = ?";
        $params[] = $post['ay'];
    }
    if (!empty($post['sezon_id'])) {
        $where .= " AND s.sozlesme_sezon_id = ?";
        $params[] = $post['sezon_id'];
    }
    if (!empty($post['cari_tipi_id'])) {
        $where .= " AND c.cari_tipi_id = ?";
        $params[] = $post['cari_tipi_id'];
    }

    return [$where, $params];
}

// AJAX İşlemleri
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json');
    $action = $_POST['action'] ?? '';
    
    try {
        switch ($action) {
            case 'stats':
                list($filtre, $filtreParams) = personelRaporFiltresi($_POST);

                $toplamSatis = $db->fetchOne("
                    SELECT ISNULL(SUM(h.hareket_fiyat), 0) as toplam
                    FROM Sozlesme_StokHareketleri h
                    INNER JOIN Sozlesmeler s ON h.hareket_sozlesme_id = s.sozlesme_id
                    LEFT JOIN Cari c ON s.sozlesme_cari_id = c.cari_id
                    WHERE 1=1 $filtre
                ", $filtreParams);

                $toplamTahsilat = $db->fetchOne("
                    SELECT ISNULL(SUM(o.odeme_tutar), 0) as toplam
                    FROM Sozlesme_Odemeler o
                    INNER JOIN Sozlesmeler s ON o.odeme_sozlesme_id = s.sozlesme_id
                    LEFT JOIN Cari c ON s.sozlesme_cari_id = c.cari_id
                    WHERE o.odeme_yapildi = 1 $filtre
                ", $filtreParams);

                $personelSayisi = $db->fetchOne("
                    SELECT COUNT(DISTINCT s.sozlesme_personel_id) as sayi
                    FROM Sozlesmeler s
                    LEFT JOIN Cari c ON s.sozlesme_cari_id = c.cari_id
                    WHERE s.sozlesme_personel_id IS NOT NULL $filtre
                ", $filtreParams);

                $senetTipi = $db->fetchOne("SELECT odeme_tipi_id FROM Sozlesme_OdemeTipleri WHERE odeme_tipi_ad = 'Senet' AND odeme_tipi_durum = 1");
                $senetTipiId = $senetTipi['odeme_tipi_id'] ?? null;

                $toplamOdenecekSenet = 0;
                if ($senetTipiId) {
                    $res = $db->fetchOne("
                        SELECT ISNULL(SUM(o.odeme_tutar), 0) as toplam
                        FROM Sozlesme_Odemeler o
                        INNER JOIN Sozlesmeler s ON o.odeme_sozlesme_id = s.sozlesme_id
                        LEFT JOIN Cari c ON s.sozlesme_cari_id = c.cari_id
                        WHERE o.odeme_tipi_id = ? AND o.odeme_yapildi = 0 AND ISNULL(o.odeme_durum_id, 0) NOT IN (7, 8) $filtre
                    ", array_merge([$senetTipiId], $filtreParams));
                    $toplamOdenecekSenet = floatval($res['toplam']);
                }

                $toplamIcra = $db->fetchOne("
                    SELECT ISNULL(SUM(o.odeme_tutar), 0) as toplam
                    FROM Sozlesme_Odemeler o
                    INNER JOIN Sozlesmeler s ON o.odeme_sozlesme_id = s.sozlesme_id
                    LEFT JOIN Cari c ON s.sozlesme_cari_id = c.cari_id
                    WHERE o.odeme_durum_id = 7 AND o.odeme_yapildi = 0 $filtre
                ", $filtreParams);
                $toplamIcra = floatval($toplamIcra['toplam']);

                $toplamZaafiyet = $db->fetchOne("
                    SELECT ISNULL(SUM(o.odeme_tutar), 0) as toplam
                    FROM Sozlesme_Odemeler o
                    INNER JOIN Sozlesmeler s ON o.odeme_sozlesme_id = s.sozlesme_id
                    LEFT JOIN Cari c ON s.sozlesme_cari_id = c.cari_id
                    WHERE o.odeme_durum_id = 8 AND o.odeme_yapildi = 0 $filtre
                ", $filtreParams);
                $toplamZaafiyet = floatval($toplamZaafiyet['toplam']);
                
                $satis = floatval($toplamSatis['toplam'] ?? 0);
                $tahsilat = floatval($toplamTahsilat['toplam'] ?? 0);
                
                $stats = [
                    'toplam_satis' => $satis,
                    'toplam_tahsilat' => $tahsilat,
                    'toplam_kalan' => max(0, $satis - $tahsilat - $toplamOdenecekSenet - $toplamIcra - $toplamZaafiyet),
                    'personel_sayisi' => intval($personelSayisi['sayi'] ?? 0)
                ];
                echo json_encode(['success' => true, 'data' => $stats]);
                break;
                
            case 'report':
                list($filtre, $filtreParams) = personelRaporFiltresi($_POST);

                // Personel bazında satış
                $satisData = $db->fetchAll("
                    SELECT
                        ISNULL(s.sozlesme_personel_id, 0) as personel_id,
                        ISNULL(k.kullanici_ad + ' ' + k.kullanici_soyad, 'Atanmamış') as personel_adi,
                        ISNULL(SUM(h.hareket_fiyat), 0) as satis_tutari
                    FROM Sozlesme_StokHareketleri h
                    INNER JOIN Sozlesmeler s ON h.hareket_sozlesme_id = s.sozlesme_id
                    LEFT JOIN Cari c ON s.sozlesme_cari_id = c.cari_id
                    LEFT JOIN kullanicilar k ON s.sozlesme_personel_id = k.kullanici_id
                    WHERE 1=1 $filtre
                    GROUP BY s.sozlesme_personel_id, k.kullanici_ad, k.kullanici_soyad
                    ORDER BY ISNULL(SUM(h.hareket_fiyat), 0) DESC
                ", $filtreParams);

                // Personel ve ödeme tipi bazında tahsilat (odeme_yapildi=1)
                $rawTahsilatData = $db->fetchAll("
                    SELECT
                        ISNULL(s.sozlesme_personel_id, 0) as personel_id,
                        o.odeme_tipi_id,
                        ot.odeme_tipi_ad,
                        ISNULL(SUM(o.odeme_tutar), 0) as tutar
                    FROM Sozlesme_Odemeler o
                    INNER JOIN Sozlesmeler s ON o.odeme_sozlesme_id = s.sozlesme_id
                    INNER JOIN Sozlesme_OdemeTipleri ot ON ot.odeme_tipi_id = o.odeme_tipi_id
                    LEFT JOIN Cari c ON s.sozlesme_cari_id = c.cari_id
                    WHERE o.odeme_yapildi = 1 $filtre
                    GROUP BY s.sozlesme_personel_id, o.odeme_tipi_id, ot.odeme_tipi_ad
                    ORDER BY ot.odeme_tipi_ad
                ", $filtreParams);
                
                // Ödeme tiplerini topla
                $columns = [];
                $columnIds = [];
                $tahsilatMap = []; // [personel_id][odeme_tipi_id] = tutar
                
                foreach ($rawTahsilatData as $row) {
                    $tipId = $row['odeme_tipi_id'];
                    $tipAd = $row['odeme_tipi_ad'];
                    $pid = intval($row['personel_id']);
                    
                    if (!isset($columnIds[$tipId])) {
                        $columnIds[$tipId] = $tipAd;
                        $columns[] = ['id' => $tipId, 'ad' => $tipAd];
                    }
                    
                    if (!isset($tahsilatMap[$pid])) {
                        $tahsilatMap[$pid] = [];
                    }
                    $tahsilatMap[$pid][$tipId] = floatval($row['tutar']);
                }
                
                // Senet tipinin ID'sini bul
                $senetTipi = $db->fetchOne("SELECT odeme_tipi_id FROM Sozlesme_OdemeTipleri WHERE odeme_tipi_ad = 'Senet' AND odeme_tipi_durum = 1");
                $senetTipiId = $senetTipi['odeme_tipi_id'] ?? null;
                
                // Personel bazında Ödenecek Senet (odeme_yapildi=0, senet tipi)
                $odenecekSenetMap = [];
                if ($senetTipiId) {
                    $odenecekSenetData = $db->fetchAll("
                        SELECT
                            ISNULL(s.sozlesme_personel_id, 0) as personel_id,
                            ISNULL(SUM(o.odeme_tutar), 0) as tutar
                        FROM Sozlesme_Odemeler o
                        INNER JOIN Sozlesmeler s ON o.odeme_sozlesme_id = s.sozlesme_id
                        LEFT JOIN Cari c ON s.sozlesme_cari_id = c.cari_id
                        WHERE o.odeme_tipi_id = ? AND o.odeme_yapildi = 0 AND ISNULL(o.odeme_durum_id, 0) NOT IN (7, 8) $filtre
                        GROUP BY s.sozlesme_personel_id
                    ", array_merge([$senetTipiId], $filtreParams));
                    
                    foreach ($odenecekSenetData as $row) {
                        $odenecekSenetMap[intval($row['personel_id'])] = floatval($row['tutar']);
                    }
                }
                
                // Personel bazında Zaafiyet (odeme_durum_id = 8)
                $zaafiyetData = $db->fetchAll("
                    SELECT
                        ISNULL(s.sozlesme_personel_id, 0) as personel_id,
                        ISNULL(SUM(o.odeme_tutar), 0) as zaafiyet_tutari
                    FROM Sozlesme_Odemeler o
                    INNER JOIN Sozlesmeler s ON o.odeme_sozlesme_id = s.sozlesme_id
                    LEFT JOIN Cari c ON s.sozlesme_cari_id = c.cari_id
                    WHERE o.odeme_durum_id = 8 AND o.odeme_yapildi = 0 $filtre
                    GROUP BY s.sozlesme_personel_id
                ", $filtreParams);
                
                $zaafiyetMap = [];
                foreach ($zaafiyetData as $row) {
                    $zaafiyetMap[intval($row['personel_id'])] = floatval($row['zaafiyet_tutari']);
                }
                
                // Personel bazında İcra (odeme_durum_id = 7)
                $icraData = $db->fetchAll("
                    SELECT
                        ISNULL(s.sozlesme_personel_id, 0) as personel_id,
                        ISNULL(SUM(o.odeme_tutar), 0) as icra_tutari
                    FROM Sozlesme_Odemeler o
                    INNER JOIN Sozlesmeler s ON o.odeme_sozlesme_id = s.sozlesme_id
                    LEFT JOIN Cari c ON s.sozlesme_cari_id = c.cari_id
                    WHERE o.odeme_durum_id = 7 AND o.odeme_yapildi = 0 $filtre
                    GROUP BY s.sozlesme_personel_id
                ", $filtreParams);
                
                $icraMap = [];
                foreach ($icraData as $row) {
                    $icraMap[intval($row['personel_id'])] = floatval($row['icra_tutari']);
                }
                
                // Rapor verisi oluştur
                $report = [];
                $genelSatis = 0;
                $genelZaafiyet = 0;
                $genelIcra = 0;
                $genelOdenecekSenet = 0;
                $genelKalan = 0;
                $colTotals = [];
                foreach ($columns as $col) {
                    $colTotals[$col['id']] = 0.0;
                }
                
                foreach ($satisData as $row) {
                    $pid = intval($row['personel_id']);
                    $satis = floatval($row['satis_tutari']);
                    $zaafiyet = $zaafiyetMap[$pid] ?? 0;
                    $icra = $icraMap[$pid] ?? 0;
                    $odenecekSenet = $odenecekSenetMap[$pid] ?? 0;
                    
                    // Ödeme tipleri
                    $odemeTipleri = [];
                    $tahsilatToplam = 0;
                    foreach ($columns as $col) {
                        $tipId = $col['id'];
                        $val = $tahsilatMap[$pid][$tipId] ?? 0;
                        $odemeTipleri[$tipId] = $val;
                        $tahsilatToplam += $val;
                        $colTotals[$tipId] += $val;
                    }
                    
                    $kalan = max(0, $satis - $tahsilatToplam - $odenecekSenet - $icra - $zaafiyet);
                    
                    $genelSatis += $satis;
                    $genelZaafiyet += $zaafiyet;
                    $genelIcra += $icra;
                    $genelOdenecekSenet += $odenecekSenet;
                    $genelKalan += $kalan;
                    
                    $report[] = [
                        'personel_id' => $pid,
                        'personel_adi' => $row['personel_adi'],
                        'satis_tutari' => $satis,
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
                                    <span class="info-box-text">Kalan Tutar</span>
                                    <span class="info-box-number" id="stat-toplam-kalan">0 ₺</span>
                                </div>
                            </div>
                        </div>
                        <div class="col-12 col-sm-6 col-md-3">
                            <div class="info-box">
                                <span class="info-box-icon text-bg-info shadow-sm">
                                    <i class="bi bi-people"></i>
                                </span>
                                <div class="info-box-content">
                                    <span class="info-box-text">Personel Sayısı</span>
                                    <span class="info-box-number" id="stat-personel-sayisi">0</span>
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
                            <h3 class="card-title"><i class="bi bi-person-lines-fill"></i> Personel Satış Raporu</h3>
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
                                            <th>Personel</th>
                                            <th class="text-end">Satış Tutarı</th>
                                            <th class="text-end">Kalan</th>
                                        </tr>
                                    </thead>
                                    <tbody id="reportBody">
                                        <tr>
                                            <td colspan="3" class="text-center">Yükleniyor...</td>
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
        let currentFilters = { cari_tipi_id: '1', sezon_id: '<?= $defaultSezonId ?>' };

        function loadStats() {
            $.post('', { action: 'stats', ...currentFilters }, function(response) {
                if (response.success) {
                    const d = response.data;
                    $('#stat-toplam-satis').text(formatCurrency(d.toplam_satis));
                    $('#stat-toplam-tahsilat').text(formatCurrency(d.toplam_tahsilat));
                    $('#stat-toplam-kalan').text(formatCurrency(d.toplam_kalan));
                    $('#stat-personel-sayisi').text(d.personel_sayisi);
                }
            });
        }
        
        function loadReport() {
            $.post('', { action: 'report', ...currentFilters }, function(response) {
                if (response.success) {
                    const cols = response.columns || [];
                    const data = response.data;
                    const toplam = response.toplam;
                    const colTotals = response.col_totals || {};
                    
                    // Dinamik thead oluştur
                    let thHtml = '<tr><th>Personel</th><th class="text-end">Satış Tutarı</th>';
                    cols.forEach(col => {
                        thHtml += `<th class="text-end">${col.ad}</th>`;
                        // Senet sütununun sağına "Ödenecek Senet" ekle
                        if (col.ad === 'Senet') {
                            thHtml += `<th class="text-end">Ödenecek Senet</th>`;
                        }
                    });
                    thHtml += '<th class="text-end text-warning">İcra</th>';
                    thHtml += '<th class="text-end text-danger">Zaafiyet</th>';
                    thHtml += '<th class="text-end">Kalan</th></tr>';
                    $('#reportHead').html(thHtml);
                    
                    // Tablo içeriği
                    let html = '';
                    const colCount = cols.length + 4; // Personel + Satış + Ödeme Tipleri + Zaafiyet + Kalan + Ödenecek Senet
                    
                    data.forEach(row => {
                        const kalanClass = row.kalan > 0 ? 'text-negative' : (row.kalan < 0 ? 'text-positive' : '');
                        const zaafiyetClass = row.zaafiyet > 0 ? 'text-danger fw-bold' : '';
                        const icraClass = row.icra > 0 ? 'text-warning fw-bold' : '';
                        
                        html += `<tr>`;
                        html += `<td><i class="bi bi-person me-1"></i><strong>${row.personel_adi}</strong></td>`;
                        html += `<td class="text-end clickable-cell" data-tip="satis" data-personel-id="${row.personel_id}" title="Detay için tıklayın" onclick="handleCellClick(this)">${formatCurrency(row.satis_tutari)}</td>`;
                        
                        // Ödeme tipleri
                        cols.forEach(col => {
                            const val = (row.odeme_tipleri && row.odeme_tipleri[col.id]) ? row.odeme_tipleri[col.id] : 0;
                            html += `<td class="text-end clickable-cell" data-tip="odeme_tipi" data-personel-id="${row.personel_id}" data-odeme-tipi-id="${col.id}" data-odeme-tipi="${col.ad}" title="Detay için tıklayın" onclick="handleCellClick(this)">${val > 0 ? formatCurrency(val) : '<span class="text-muted">-</span>'}</td>`;
                            
                            // Senet sütununun sağına "Ödenecek Senet" ekle
                            if (col.ad === 'Senet') {
                                const odenecekSenet = row.odenecek_senet || 0;
                                html += `<td class="text-end clickable-cell" data-tip="odenecek_senet" data-personel-id="${row.personel_id}" title="Detay için tıklayın" onclick="handleCellClick(this)">${odenecekSenet > 0 ? formatCurrency(odenecekSenet) : '<span class="text-muted">-</span>'}</td>`;
                            }
                        });
                        
                        // İcra sütunu (odeme_durum_id = 7)
                        html += `<td class="text-end clickable-cell ${icraClass}" data-tip="icra" data-personel-id="${row.personel_id}" title="Detay için tıklayın" onclick="handleCellClick(this)">${row.icra > 0 ? formatCurrency(row.icra) : '<span class="text-muted">-</span>'}</td>`;
                        html += `<td class="text-end clickable-cell ${zaafiyetClass}" data-tip="zaafiyet" data-personel-id="${row.personel_id}" title="Detay için tıklayın" onclick="handleCellClick(this)">${row.zaafiyet > 0 ? formatCurrency(row.zaafiyet) : '<span class="text-muted">-</span>'}</td>`;
                        html += `<td class="text-end clickable-cell ${kalanClass}" data-tip="kalan" data-personel-id="${row.personel_id}" title="Detay için tıklayın" onclick="handleCellClick(this)">${formatCurrency(row.kalan)}</td>`;
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
                    
                    let footHtml = '<tr><td><strong>TOPLAM</strong></td>';
                    footHtml += `<td class="text-end">${formatCurrency(toplam.satis)}</td>`;
                    
                    cols.forEach(col => {
                        footHtml += `<td class="text-end">${formatCurrency(colTotals[col.id] ?? 0)}</td>`;
                        // Senet sütununun sağına "Ödenecek Senet" toplamı ekle
                        if (col.ad === 'Senet') {
                            footHtml += `<td class="text-end">${formatCurrency(toplam.odenecek_senet || 0)}</td>`;
                        }
                    });
                    
                    footHtml += `<td class="text-end ${toplamIcraClass}">${toplam.icra > 0 ? formatCurrency(toplam.icra) : '-'}</td>`;
                    footHtml += `<td class="text-end ${toplamZaafiyetClass}">${toplam.zaafiyet > 0 ? formatCurrency(toplam.zaafiyet) : '-'}</td>`;
                    footHtml += `<td class="text-end ${toplamKalanClass}">${formatCurrency(toplam.kalan)}</td>`;
                    footHtml += '</tr>';
                    
                    $('#reportFoot').html(footHtml);
                }
            });
        }
        
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
            a.download = 'personel-satis-raporu.csv';
            a.click();
            URL.revokeObjectURL(url);
            showToast('Excel dosyası indirildi', 'success');
        });
        
        // Hücre tıklama - rapor-detay.php'ye yönlendir
        function handleCellClick(cell) {
            const $cell = $(cell);
            const tip = $cell.data('tip');
            const personelId = $cell.data('personel-id');
            const odemeTipiId = $cell.data('odeme-tipi-id') || '';
            const odemeTipi = $cell.data('odeme-tipi') || '';
            
            if (tip && personelId) {
                let url = `/admin/pages/rapor-detay.php?kaynak=personel-satis&tip=${tip}&personel_id=${personelId}`;
                if (tip === 'odeme_tipi' && odemeTipiId) {
                    url += `&odeme_tipi_id=${odemeTipiId}&odeme_tipi=${encodeURIComponent(odemeTipi)}`;
                }
                window.location.href = url;
            }
        }
        
        // Filtre
        $('#filterForm').on('submit', function(e) {
            e.preventDefault();
            currentFilters = {
                cari_tipi_id: $('#filter_cari_tipi').val(),
                ay: $('#filter_ay').val(),
                sezon_id: $('#filter_sezon').val()
            };
            Object.keys(currentFilters).forEach(key => { if (!currentFilters[key]) delete currentFilters[key]; });
            loadStats();
            loadReport();
            showToast('Filtre uygulandı', 'info');
        });

        $('#clearFilters').on('click', function() {
            $('#filterForm')[0].reset();
            $('#filter_cari_tipi').val('1').trigger('change.select2');
            $('#filter_ay').val('').trigger('change.select2');
            $('#filter_sezon').val('<?= $defaultSezonId ?>').trigger('change.select2');
            currentFilters = { cari_tipi_id: '1', sezon_id: '<?= $defaultSezonId ?>' };
            loadStats();
            loadReport();
            showToast('Filtreler temizlendi', 'info');
        });

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

            loadStats();
            loadReport();
        });
    </script>
</body>
</html>
