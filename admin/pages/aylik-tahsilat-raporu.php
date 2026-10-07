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

                $satisWhere     = "WHERE 1=1";
                $satisParams    = [];
                $tahsilatWhere  = "WHERE o.odeme_yapildi = 1";
                $tahsilatParams = [];

                if ($ayFilter) {
                    $satisWhere    .= " AND MONTH(h.hareket_aktivasyon_tarihi) = ?";
                    $satisParams[]  = $ayFilter;
                    $tahsilatWhere .= " AND MONTH(o.odeme_tarih) = ?";
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
                    FROM Sozlesme_StokHareketleri h
                    LEFT JOIN Sozlesmeler s ON h.hareket_sozlesme_id = s.sozlesme_id
                    LEFT JOIN Cari c ON s.sozlesme_cari_id = c.cari_id
                    $satisWhere
                ", $satisParams);
                
                $toplamTahsilat = $db->fetchOne("
                    SELECT ISNULL(SUM(o.odeme_tutar), 0) as toplam
                    FROM Sozlesme_Odemeler o
                    LEFT JOIN Sozlesmeler s ON o.odeme_sozlesme_id = s.sozlesme_id
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

                $whereClause = "WHERE o.odeme_yapildi = 1";
                $params = [];

                if ($ayFilter) {
                    $whereClause .= " AND MONTH(o.odeme_tarih) = ?";
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
                
                // Ham veriyi çek
                $rawData = $db->fetchAll("
                    SELECT
                        YEAR(o.odeme_tarih)  as yil,
                        MONTH(o.odeme_tarih) as ay,
                        ISNULL(ot.odeme_tipi_ad, 'Belirtilmemiş') as odeme_tipi_ad,
                        ISNULL(SUM(o.odeme_tutar), 0) as tutar
                    FROM Sozlesme_Odemeler o
                    LEFT JOIN Sozlesmeler s              ON o.odeme_sozlesme_id = s.sozlesme_id
                    LEFT JOIN Sozlesme_OdemeTipleri ot   ON o.odeme_tipi_id     = ot.odeme_tipi_id
                    LEFT JOIN Cari c ON s.sozlesme_cari_id = c.cari_id
                    $whereClause
                    GROUP BY YEAR(o.odeme_tarih), MONTH(o.odeme_tarih), ot.odeme_tipi_id, ot.odeme_tipi_ad
                    ORDER BY YEAR(o.odeme_tarih), MONTH(o.odeme_tarih), ot.odeme_tipi_ad
                ", $params);
                
                // Pivot matris oluştur
                $columns   = [];   // benzersiz ödeme tipleri (sıralı)
                $pivotMap  = [];   // anahtar: "YIL-AY"
                $ayMetaMap = [];   // anahtar → ay_adi
                
                foreach ($rawData as $row) {
                    $tip = $row['odeme_tipi_ad'];
                    if (!in_array($tip, $columns)) {
                        $columns[] = $tip;
                    }
                    
                    $key = $row['yil'] . '-' . str_pad($row['ay'], 2, '0', STR_PAD_LEFT);
                    if (!isset($pivotMap[$key])) {
                        $pivotMap[$key] = [];
                        $ayMetaMap[$key] = $aylar[intval($row['ay'])] . ' ' . $row['yil'];
                    }
                    $pivotMap[$key][$tip] = floatval($row['tutar']);
                }
                
                ksort($pivotMap);

                // Satır ve sütun toplamları
                $pivotRows = [];
                $colTotals = array_fill_keys($columns, 0.0);
                $grandTotal = 0.0;
                
                foreach ($pivotMap as $key => $tipValues) {
                    $rowTotal = 0.0;
                    $rowData  = ['ay_adi' => $ayMetaMap[$key]];
                    foreach ($columns as $col) {
                        $val = $tipValues[$col] ?? 0.0;
                        $rowData[$col] = $val;
                        $rowTotal      += $val;
                        $colTotals[$col] += $val;
                    }
                    $rowData['toplam'] = $rowTotal;
                    $grandTotal        += $rowTotal;
                    $pivotRows[]       = $rowData;
                }
                
                echo json_encode([
                    'success'    => true,
                    'columns'    => $columns,
                    'data'       => $pivotRows,
                    'col_totals' => $colTotals,
                    'grand_total'=> $grandTotal,
                    'ay_araligi' => $ayAraligi
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
                                    <i class="bi bi-percent"></i>
                                </span>
                                <div class="info-box-content">
                                    <span class="info-box-text">Tahsilat Oranı</span>
                                    <span class="info-box-number" id="stat-tahsilat-oran">%0</span>
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
                                        <label class="form-label">Ay <small class="text-muted">(Ödeme Tarihi)</small></label>
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
        
        function loadStats() {
            $.post('', { action: 'stats', ...currentFilters }, function(response) {
                if (response.success) {
                    const d = response.data;
                    $('#stat-toplam-satis').text(formatCurrency(d.toplam_satis));
                    $('#stat-toplam-tahsilat').text(formatCurrency(d.toplam_tahsilat));
                    $('#stat-toplam-kalan').text(formatCurrency(d.toplam_kalan));
                    $('#stat-tahsilat-oran').text('%' + d.tahsilat_oran);
                }
            });
        }
        
        function loadReport() {
            $.post('', { action: 'report', ...currentFilters }, function(response) {
                if (!response.success) return;
                
                const cols       = response.columns;    // ödeme tipi adları
                const data       = response.data;
                const colTotals  = response.col_totals;
                const grandTotal = response.grand_total;
                const colCount   = cols.length + 2;     // Ay + her tip + Toplam
                
                // Dinamik thead
                let thHtml = '<tr><th>Ay</th>';
                cols.forEach(col => { thHtml += `<th class="text-end">${col}</th>`; });
                thHtml += '<th class="text-end">Toplam</th></tr>';
                $('#reportHead').html(thHtml);
                
                // Tbody satırları
                let html = '';
                data.forEach(row => {
                    html += `<tr><td><strong>${row.ay_adi}</strong></td>`;
                    cols.forEach(col => {
                        const val = row[col] ?? 0;
                        html += `<td class="text-end">${val > 0 ? formatCurrency(val) : '<span class="text-muted">-</span>'}</td>`;
                    });
                    html += `<td class="text-end fw-bold">${formatCurrency(row.toplam)}</td></tr>`;
                });
                
                if (!html) {
                    html = `<tr><td colspan="${colCount}" class="text-center text-muted">Veri bulunamadı</td></tr>`;
                }
                $('#reportBody').html(html);
                
                // Tfoot toplamlar
                let footHtml = '<tr><td><strong>TOPLAM</strong></td>';
                cols.forEach(col => {
                    footHtml += `<td class="text-end">${formatCurrency(colTotals[col] ?? 0)}</td>`;
                });
                footHtml += `<td class="text-end">${formatCurrency(grandTotal)}</td></tr>`;
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
            a.download = 'aylık-tahsilat-raporu' + (currentFilters.ay ? '-ay' + currentFilters.ay : '') + '.csv';
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
            Object.keys(currentFilters).forEach(key => {
                if (!currentFilters[key]) delete currentFilters[key];
            });
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
