<?php
/**
 * Admin Panel - Cari Yönetimi
 * 
 * Cari (Müşteri/Tedarikçi) kayıtlarının yönetimi
 */

require_once __DIR__ . '/../auth.php';
require_once __DIR__ . '/../includes/PageAuth.php';
require_once __DIR__ . '/../includes/LogHelper.php';
requireAuth();

$user = Auth::user();
$db = Database::getInstance();

// Sayfa Yetki kontrolü
$currentPagefile = basename($_SERVER['PHP_SELF']);
$pagePermissions = PageAuth::checkPagePermissions(
    $user['kullanici_id'],
    $user['departman_id'],
    $currentPagefile
);

// Sayfa erişim kontrolü
if (!$pagePermissions['has_access']) {
    PageAuth::accessDenied($pagePermissions['error'] ?? 'Bu sayfaya erişim yetkiniz bulunmamaktadır.');
}

// Mevcut sayfanın bilgilerini al
$pageinfo = $db->fetchOne("
    SELECT 
        s.sayfalar_sayfa_adi, 
        s.sayfalar_aciklama,
        m.menuler_menu_adi as menu_adi
    FROM Menu_Sayfalar s
    LEFT JOIN Menuler m ON s.sayfalar_menu_id = m.menuler_id
    WHERE s.sayfalar_sayfa_url LIKE ? AND s.sayfalar_durum = 1
", ['%' . $currentPagefile]);

// Sayfa bilgileri
$pageTitle = $pageinfo['sayfalar_sayfa_adi'] ?? 'Cari Yönetimi';
$pageDescription = $pageinfo['sayfalar_aciklama'] ?? '';
$menuAdi = $pageinfo['menu_adi'] ?? null;

// site title'i çek
$siteAyarlari = $db->fetchOne("SELECT TOP 1 site_ayarlari_site_title FROM dbo.tanim_site_ayarlari ORDER BY site_ayarlari_id DESC");
$siteTitle = $siteAyarlari['site_ayarlari_site_title'] ?? 'Ornek Yazilim Portal';

// Ülke Listesini Getir (Filtre için)
$ulkeler = $db->fetchAll("SELECT UlkeId, UlkeAdi FROM Adres_Ulkeler ORDER BY UlkeAdi");
$cariTipleri = $db->fetchAll("SELECT cari_tipi_id, cari_tipi_ad, cari_tipi_renk FROM Cari_CariTipleri WHERE cari_tipi_durum = 1 ORDER BY cari_tipi_sira");

/**
 * Cari tablosuna FK ile bağlı tüm kayıtları bulur (sys.foreign_keys üzerinden dinamik)
 */
function cariBagliKayitlar($db, $cariid, $ornekLimit = 5) {
    $fkListesi = $db->fetchAll("
        SELECT
            OBJECT_SCHEMA_NAME(fk.parent_object_id) AS sema,
            OBJECT_NAME(fk.parent_object_id) AS tablo,
            COL_NAME(fkc.parent_object_id, fkc.parent_column_id) AS kolon,
            fk.name AS fk_adi
        FROM sys.foreign_keys fk
        INNER JOIN sys.foreign_key_columns fkc ON fk.object_id = fkc.constraint_object_id
        WHERE fk.referenced_object_id = OBJECT_ID('dbo.Cari')
        ORDER BY tablo, kolon
    ");

    $q = fn($ad) => '[' . str_replace(']', ']]', $ad) . ']';
    $cariTipiId = (int)($db->fetchOne("SELECT cari_tipi_id FROM Cari WHERE cari_id = ?", [$cariid])['cari_tipi_id'] ?? 0);

    // form.php ile açılabilen tablolar: tablo => [PK kolonu, cari_tipi_id]
    $formTablolari = [
        'HukukTakip'  => ['takip_id', in_array($cariTipiId, [3, 4]) ? $cariTipiId : 3],
        'Sozlesmeler' => ['sozlesme_id', 1],
    ];

    $sonuc = [];
    foreach ($fkListesi as $fk) {
        $kaynak = $q($fk['sema']) . '.' . $q($fk['tablo']);
        $kolon = $q($fk['kolon']);
        $adet = (int)($db->fetchOne("SELECT COUNT(*) AS adet FROM $kaynak WHERE $kolon = ?", [$cariid])['adet'] ?? 0);
        if ($adet === 0) {
            continue;
        }
        $linkler = [];
        if (isset($formTablolari[$fk['tablo']])) {
            [$pk, $tipId] = $formTablolari[$fk['tablo']];
            $idler = $db->fetchAll("SELECT TOP 100 " . $q($pk) . " AS id FROM $kaynak WHERE $kolon = ? ORDER BY " . $q($pk) . " DESC", [$cariid]);
            foreach ($idler as $r) {
                $linkler[] = ['id' => (int)$r['id'], 'url' => '/admin/form?id=' . (int)$r['id'] . '&cari_tipi_id=' . $tipId];
            }
        }
        $sonuc[] = [
            'tablo' => $fk['tablo'],
            'kolon' => $fk['kolon'],
            'fk_adi' => $fk['fk_adi'],
            'adet' => $adet,
            'linkler' => $linkler,
            'ornekler' => $db->fetchAll("SELECT TOP " . (int)$ornekLimit . " * FROM $kaynak WHERE $kolon = ?", [$cariid])
        ];
    }
    return $sonuc;
}

// AJAX işlemleri
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json');
    
    $action = $_POST['action'] ?? '';
    
    try {
        switch ($action) {
            case 'getSehirler':
                // Şehir listesini getir
                echo json_encode(['success' => true, 'data' => $sehirler]);
                break;
                
            case 'getIlceler':
                // Belirli bir şehre ait ilçeleri getir
                $sehirId = $_POST['sehir_id'] ?? 0;
                $filteredIlceler = array_filter($ilceler, function($ilce) use ($sehirId) {
                    return $ilce['SehirId'] == $sehirId;
                });
                echo json_encode(['success' => true, 'data' => array_values($filteredIlceler)]);
                break;
                
            case 'stats':
                // İstatistikleri getir
                $stats = [
                    'toplam_cari' => $db->fetchOne("SELECT count(*) as total FROM Cari")['total'] ?? 0,
                    'aktif_cari' => $db->fetchOne("SELECT count(*) as total FROM Cari WHERE cari_aktif = 1")['total'] ?? 0,
                    'musteri_sayisi' => $db->fetchOne("SELECT count(*) as total FROM Cari c JOIN Cari_CariTipleri ct ON c.cari_tipi_id = ct.cari_tipi_id WHERE ct.cari_tipi_ad = N'Müşteri'")['total'] ?? 0,
                    'tedarikci_sayisi' => $db->fetchOne("SELECT count(*) as total FROM Cari c JOIN Cari_CariTipleri ct ON c.cari_tipi_id = ct.cari_tipi_id WHERE ct.cari_tipi_ad = N'Tedarikçi'")['total'] ?? 0
                ];
                echo json_encode(['success' => true, 'data' => $stats]);
                break;
                
            case 'list':
                // Filtre parametreleri (DataTables'dan search_custom olarak geliyor)
                $search = $_POST['search_custom'] ?? $_POST['search'] ?? '';
                $tip = $_POST['tip'] ?? '';
                $durum = $_POST['durum'] ?? '';
                $ulke = $_POST['ulke'] ?? '';
                
                // SQL sorgusu
                $sql = "
                    SELECT 
                        c.cari_id,
                        c.cari_adi,
                        c.cari_unvan,
                        c.cari_vergi_no,
                        c.cari_telefon,
                        c.cari_email,
                        c.cari_tipi_id,
                        ct.cari_tipi_ad,
                        ct.cari_tipi_renk,
                        c.cari_aktif,
                        c.cari_ulke,
                        CONVERT(VARCHAR(19), c.cari_olusturma_tarihi, 120) as cari_olusturma_tarihi
                    FROM Cari c
                    LEFT JOIN Cari_CariTipleri ct ON c.cari_tipi_id = ct.cari_tipi_id
                    WHERE 1=1
                ";
                $params = [];
                
                // Arama filtresi
                if ($search) {
                    $sql .= " AND (cari_adi LIKE ? OR cari_unvan LIKE ? OR cari_telefon LIKE ? OR cari_email LIKE ?)";
                    $params[] = "%$search%";
                    $params[] = "%$search%";
                    $params[] = "%$search%";
                    $params[] = "%$search%";
                }
                
                // Tip filtresi
                if ($tip) {
                    $sql .= " AND c.cari_tipi_id = ?";
                    $params[] = intval($tip);
                }
                
                // Durum filtresi
                if ($durum !== '') {
                    $sql .= " AND c.cari_aktif = ?";
                    $params[] = $durum;
                }
                
                // Ulke filtresi
                if ($ulke) {
                    $sql .= " AND c.cari_ulke = ?";
                    $params[] = $ulke;
                }
                
                $sql .= " ORDER BY cari_id DESC";
                
                $cariList = $db->fetchAll($sql, $params);
                echo json_encode(['success' => true, 'data' => $cariList]);
                break;
                
            case 'get':
                // Tek bir cari kaydini getir
                $cariid = $_POST['cari_id'] ?? 0;
                $cari = $db->fetchOne("SELECT * FROM Cari WHERE cari_id = ?", [$cariid]);
                echo json_encode(['success' => true, 'data' => $cari]);
                break;
                
            case 'save':
                // Yetki kontrolü
                $cariid = $_POST['cari_id'] ?? 0;
                if ($cariid > 0 && !$pagePermissions['can_edit']) {
                    echo json_encode(['success' => false, 'message' => 'Düzenleme yetkiniz yok!']);
                    break;
                }
                if ($cariid == 0 && !$pagePermissions['can_add']) {
                    echo json_encode(['success' => false, 'message' => 'Ekleme yetkiniz yok!']);
                    break;
                }
                
                // Eski kaydı al (log için)
                $eskiCari = $cariid > 0 ? $db->fetchOne("SELECT * FROM Cari WHERE cari_id = ?", [$cariid]) : null;
                
                // Yeni cari kaydet veya güncelle
                $data = [
                    'cari_adi' => $_POST['cari_adi'] ?? '',
                    'cari_unvan' => $_POST['cari_unvan'] ?? '',
                    'cari_vergi_dairesi' => $_POST['cari_vergi_dairesi'] ?? '',
                    'cari_vergi_no' => $_POST['cari_vergi_no'] ?? '',
                    'cari_mersis_no' => $_POST['cari_mersis_no'] ?? '',
                    'cari_ticaret_sicil_no' => $_POST['cari_ticaret_sicil_no'] ?? '',
                    'cari_telefon' => $_POST['cari_telefon'] ?? '',
                    'cari_email' => $_POST['cari_email'] ?? '',
                    'cari_adres' => $_POST['cari_adres'] ?? '',
                    'cari_posta_kodu' => $_POST['cari_posta_kodu'] ?? '',
                    'cari_Yetkili_adi' => $_POST['cari_Yetkili_adi'] ?? '',
                    'cari_Yetkili_telefon' => $_POST['cari_Yetkili_telefon'] ?? '',
                    'cari_Yetkili_email' => $_POST['cari_Yetkili_email'] ?? '',
                    'cari_ulke' => $_POST['cari_ulke'] ?? null,
                    'cari_sehirler' => $_POST['cari_sehirler'] ?? '',
                    'cari_ilceler' => $_POST['cari_ilceler'] ?? '',
                    'cari_tipi_id' => !empty($_POST['cari_tipi_id']) ? intval($_POST['cari_tipi_id']) : null,
                    'cari_aktif' => isset($_POST['cari_aktif']) ? 1 : 0
                ];
                
                if ($cariid > 0) {
                    // Güncelleme
                    $data['cari_guncelleme_tarihi'] = date('Y-m-d H:i:s');
                    $data['cari_guncelleyen_kullanici'] = $user['kullanici_id'];
                    
                    $db->update('Cari', $data, ['cari_id' => $cariid]);
                    
                    // Log kaydı
                    $yeniCari = $db->fetchOne("SELECT * FROM Cari WHERE cari_id = ?", [$cariid]);
                    logKayitDegisiklikleri($db, 'cari-yonetimi', 'Cari', $cariid, $eskiCari, $yeniCari, $user['kullanici_id'], 'Cari güncellendi');
                    
                    echo json_encode(['success' => true, 'message' => 'Cari başarıyla güncellendi']);
                } else {
                    // Yeni kayıt
                    $data['cari_olusturma_tarihi'] = date('Y-m-d H:i:s');
                    $data['cari_olusturan_kullanici'] = $user['kullanici_id'];
                    
                    $db->insert('Cari', $data);
                    
                    // Yeni eklenen kaydın ID'sini al ve log
                    $yeniCari = $db->fetchOne("SELECT TOP 1 * FROM Cari ORDER BY cari_id DESC");
                    if ($yeniCari) {
                        logKayitDegisiklikleri($db, 'cari-yonetimi', 'Cari', $yeniCari['cari_id'], null, $yeniCari, $user['kullanici_id'], 'Yeni cari eklendi');
                    }
                    
                    echo json_encode(['success' => true, 'message' => 'Cari başarıyla eklendi']);
                }
                break;
                
            case 'delete':
                // Yetki kontrolü
                if (!$pagePermissions['can_delete']) {
                    echo json_encode(['success' => false, 'message' => 'Silme yetkiniz yok!']);
                    break;
                }
                
                // Cari sil
                $cariid = (int)($_POST['cari_id'] ?? 0);

                // Bağlı kayıt varsa silme, listeyi döndür
                $bagli = cariBagliKayitlar($db, $cariid);
                if (!empty($bagli)) {
                    echo json_encode(['success' => false, 'message' => 'Bu cariye bağlı kayıtlar olduğu için silinemez.', 'bagli' => $bagli]);
                    break;
                }

                // Log için eski kaydı al
                $eskiCari = $db->fetchOne("SELECT * FROM Cari WHERE cari_id = ?", [$cariid]);

                $db->delete('Cari', ['cari_id' => $cariid]);

                // Log kaydı (silme başarılı olduktan sonra)
                if ($eskiCari) {
                    logKayitDegisiklikleri($db, 'cari-yonetimi', 'Cari', $cariid, $eskiCari, null, $user['kullanici_id'], 'Cari silindi');
                }

                echo json_encode(['success' => true, 'message' => 'Cari başarıyla silindi']);
                break;

            case 'bagliKayitlar':
                $cariid = (int)($_POST['cari_id'] ?? 0);
                echo json_encode(['success' => true, 'data' => cariBagliKayitlar($db, $cariid)]);
                break;
            
            default:
                echo json_encode(['success' => false, 'message' => 'Geçersiz işlem']);
        }
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'message' => $e->getMessage()]);
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
    <link rel="stylesheet" href="/Admin/assets/css/Adminlte.min.css">
    <link rel="stylesheet" href="https://cdn.datatables.net/1.13.7/css/dataTables.bootstrap5.min.css">
    <link href="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css" rel="stylesheet" />
    <link href="https://cdn.jsdelivr.net/npm/select2-bootstrap-5-theme@1.3.0/dist/select2-bootstrap-5-theme.min.css" rel="stylesheet" />
    <link href="https://cdn.jsdelivr.net/npm/sweetalert2@11/dist/sweetalert2.min.css" rel="stylesheet">
    <link rel="stylesheet" href="/Admin/assets/css/custom.css">
    
    <style>
        .table-responsive {
            max-height: 600px;
            overflow-y: auto;
        }
        .badge-aktif {
            background-color: #28a745;
        }
        .badge-pasif {
            background-color: #dc3545;
        }
    </style>
</head>
<body class="layout-fixed sidebar-expand-lg sidebar-open bg-body-tertiary">
    <div class="app-wrapper">
        <?php include __DIR__ . '/../includes/header.php'; ?>
        <?php include __DIR__ . '/../includes/sidebar.php'; ?>
        
        <main class="app-main">
            <!-- Sayfa Başliği -->
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
            
            <!-- Sayfa içeriği -->
            <div class="app-content">
                <div class="container-fluid">
                    
                    <!-- info Boxes -->
                    <div class="row mb-3">
                        <div class="col-md-3">
                            <div class="info-box text-bg-primary">
                                <span class="info-box-icon">
                                    <i class="bi bi-people-fill"></i>
                                </span>
                                <div class="info-box-content">
                                    <span class="info-box-text">Toplam Cari</span>
                                    <span class="info-box-number" id="stat-toplam-cari">0</span>
                                </div>
                            </div>
                        </div>
                        
                        <div class="col-md-3">
                            <div class="info-box text-bg-success">
                                <span class="info-box-icon">
                                    <i class="bi bi-check-circle-fill"></i>
                                </span>
                                <div class="info-box-content">
                                    <span class="info-box-text">Aktif Cari</span>
                                    <span class="info-box-number" id="stat-aktif-cari">0</span>
                                </div>
                            </div>
                        </div>
                        
                        <div class="col-md-3">
                            <div class="info-box text-bg-info">
                                <span class="info-box-icon">
                                    <i class="bi bi-cart-check-fill"></i>
                                </span>
                                <div class="info-box-content">
                                    <span class="info-box-text">Müşteri Sayısı</span>
                                    <span class="info-box-number" id="stat-musteri">0</span>
                                </div>
                            </div>
                        </div>
                        
                        <div class="col-md-3">
                            <div class="info-box text-bg-warning">
                                <span class="info-box-icon">
                                    <i class="bi bi-truck"></i>
                                </span>
                                <div class="info-box-content">
                                    <span class="info-box-text">Tedarikçi Sayısı</span>
                                    <span class="info-box-number" id="stat-tedarikci">0</span>
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <!-- Filtre Karti -->
                    <div class="card card-primary card-outline mb-3">
                        <div class="card-header">
                            <h3 class="card-title">
                                <i class="bi bi-funnel"></i> Filtrele
                            </h3>
                            <div class="card-tools">
                                <button type="button" class="btn btn-tool" data-bs-toggle="collapse" data-bs-target="#filterCard" aria-expanded="false">
                                    <i class="bi bi-chevron-down"></i>
                                </button>
                            </div>
                        </div>
                        <div class="card-body collapse" id="filterCard">
                            <form id="filterForm">
                                <div class="row g-3">
                                    <div class="col-md-3">
                                        <label class="form-label">Ara</label>
                                        <input type="text" class="form-control" name="search" id="filter_search" placeholder="Ad, ünvan, telefon, e-posta...">
                                    </div>
                                    <div class="col-md-2">
                                        <label class="form-label">Tip</label>
                                        <select class="form-select" name="tip" id="filter_tip">
                                            <option value="">Tümü</option>
                                            <?php foreach ($cariTipleri as $tip): ?>
                                                <option value="<?= $tip['cari_tipi_id'] ?>"><?= htmlspecialchars($tip['cari_tipi_ad']) ?></option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                    <div class="col-md-2">
                                        <label class="form-label">Durum</label>
                                        <select class="form-select" name="durum" id="filter_durum">
                                            <option value="">Tümü</option>
                                            <option value="1">Aktif</option>
                                            <option value="0">Pasif</option>
                                        </select>
                                    </div>
                                    <div class="col-md-2">
                                        <label class="form-label">Ülke</label>
                                        <select class="form-select" name="ulke" id="filter_ulke">
                                            <option value="">Tümü</option>
                                            <?php foreach ($ulkeler as $ulke): ?>
                                                <option value="<?= $ulke['UlkeId'] ?>"><?= htmlspecialchars($ulke['UlkeAdi']) ?></option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                    <div class="col-md-3 d-flex align-items-end gap-2">
                                        <button type="submit" class="btn btn-primary">
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
                    
                    <!-- Cari Listesi -->
                    <div class="card card-primary card-outline">
                        <div class="card-header">
                            <h3 class="card-title">Cari Listesi</h3>
                            <div class="card-tools">
                                <?php if ($pagePermissions['can_add']): ?>
                                <a href="/Admin/cari-form" class="btn btn-primary btn-sm">
                                    <i class="bi bi-plus-circle"></i> Yeni Cari Ekle
                                </a>
                                <?php endif; ?>
                            </div>
                        </div>
                        <div class="card-body">
                            <div class="table-responsive">
                                <table class="table table-bordered table-striped table-hover" id="cariTable">
                                    <thead>
                                        <tr>
                                            <th>ID</th>
                                            <th>Cari Adı</th>
                                            <th>Ünvan</th>
                                            <th>Vergi No</th>
                                            <th>Telefon</th>
                                            <th>E-posta</th>
                                            <th>Tip</th>
                                            <th>Durum</th>
                                            <th>İşlemler</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <!-- Dinamik içerik -->
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </div>
                    
                </div>
            </div>
        </main>
        
        <?php include __DIR__ . '/../includes/footer.php'; ?>
    </div>
    
    <!-- jQuery -->
    <script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
    
    <!-- Popper.js -->
    <script src="https://cdn.jsdelivr.net/npm/@popperjs/core@2.11.8/dist/umd/popper.min.js" crossorigin="anonymous"></script>
    
    <!-- Bootstrap 5 JS -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.7/dist/js/bootstrap.min.js" crossorigin="anonymous"></script>
    
    <!-- DataTables JS -->
    <script src="https://cdn.datatables.net/1.13.7/js/jquery.dataTables.min.js"></script>
    <script src="https://cdn.datatables.net/1.13.7/js/dataTables.bootstrap5.min.js"></script>
    
    <!-- AdminLTE JS -->
    <script src="/Admin/assets/js/Adminlte.min.js"></script>
    
    <!-- Select2 JS -->
    <script src="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js"></script>
    
    <!-- SweetAlert2 JS -->
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11/dist/sweetalert2.all.min.js"></script>
    
    <!-- Custom JS -->
    <script src="/Admin/assets/js/custom.js"></script>
    
    <script>
        // Sayfa yetkileri
        const pagePermissions = {
            can_add: <?= $pagePermissions['can_add'] ? 'true' : 'false' ?>,
            can_edit: <?= $pagePermissions['can_edit'] ? 'true' : 'false' ?>,
            can_delete: <?= $pagePermissions['can_delete'] ? 'true' : 'false' ?>
        };
        
        let table;
        let currentFilters = {};
        
        // Sayfa yüklendiğinde
        $(document).ready(function() {
            // DataTables init
            initDataTable();
            loadStats();
            
            // Select2 init (sadece filtre)
            $('#filter_tip, #filter_durum, #filter_ulke').select2({
                theme: 'bootstrap-5',
                width: '100%',
                allowClear: true,
                language: {
                    noResults: function() { return "Sonuç bulunamadı"; },
                    searching: function() { return "Aranıyor..."; }
                }
            });
            
            // Filtre form submit
            $('#filterForm').on('submit', function(e) {
                e.preventDefault();
                currentFilters = {
                    search: $('#filter_search').val(),
                    tip: $('#filter_tip').val(),
                    durum: $('#filter_durum').val(),
                    ulke: $('#filter_ulke').val()
                };
                table.ajax.reload();
                showToast('Filtre uygulandı', 'info');
            });
            
            // Filtre temizle
            $('#clearFilters').on('click', function() {
                $('#filterForm')[0].reset();
                $('#filter_tip').val('').trigger('change.select2');
                $('#filter_durum').val('').trigger('change.select2');
                $('#filter_ulke').val('').trigger('change.select2');
                currentFilters = {};
                table.ajax.reload();
                showToast('Filtreler temizlendi', 'info');
            });
        });
        
        // DataTables init
        function initDataTable() {
            table = $('#cariTable').DataTable({
                processing: true,
                stateSave: true,
                serverSide: false,
                ajax: {
                    url: '',
                    type: 'POST',
                    data: function(d) {
                        d.action = 'list';
                        d.search_custom = currentFilters.search || '';
                        d.tip = currentFilters.tip || '';
                        d.durum = currentFilters.durum !== undefined ? currentFilters.durum : '';
                        d.ulke = currentFilters.ulke || '';
                    },
                    dataSrc: function(json) {
                        return json.success ? json.data : [];
                    }
                },
                columns: [
                    { data: 'cari_id', title: 'ID', width: '50px' },
                    { data: 'cari_adi', title: 'Cari Adı' },
                    { data: 'cari_unvan', title: 'Ünvan' },
                    { data: 'cari_vergi_no', title: 'Vergi No' },
                    { data: 'cari_telefon', title: 'Telefon' },
                    { data: 'cari_email', title: 'E-posta' },
                    { 
                        data: null, 
                        title: 'Tip',
                        render: function(data, type, row) {
                            if (row.cari_tipi_ad) {
                                const renk = row.cari_tipi_renk ? row.cari_tipi_renk.replace('text-', 'bg-') : 'bg-primary';
                                return '<span class="badge ' + renk + '">' + row.cari_tipi_ad + '</span>';
                            }
                            return '<span class="badge bg-secondary">Belirtilmemiş</span>';
                        }
                    },
                    { 
                        data: 'cari_aktif', 
                        title: 'Durum',
                        render: function(data, type, row) {
                            return data == 1 
                                ? '<span class="badge bg-success">Aktif</span>' 
                                : '<span class="badge bg-danger">Pasif</span>';
                        }
                    },
                    { 
                        data: null, 
                        title: 'İşlemler',
                        orderable: false,
                        width: '130px',
                        render: function(data, type, row) {
                            let buttons = '<div class="btn-group btn-group-sm">';
                            buttons += `<button class="btn btn-secondary btn-sm" onclick="bagliKayitlariGoster(${row.cari_id})" title="Bağlı Kayıtlar"><i class="bi bi-diagram-3"></i></button>`;
                            if (pagePermissions.can_edit) {
                                buttons += `<a href="/Admin/cari-form?id=${row.cari_id}" class="btn btn-info btn-sm" title="Düzenlel"><i class="bi bi-pencil"></i></a>`;
                            }
                            if (pagePermissions.can_delete) {
                                buttons += `<button class="btn btn-danger btn-sm" onclick="deleteCari(${row.cari_id})" title="Sil"><i class="bi bi-trash"></i></button>`;
                            }
                            buttons += '</div>';
                            return buttons;
                        }
                    }
                ],
                order: [[0, 'desc']],
                pageLength: 25,
                lengthMenu: [[10, 25, 50, 100, -1], [10, 25, 50, 100, "Tümü"]],
                language: {
                    url: "https://cdn.datatables.net/plug-ins/1.13.7/i18n/tr.json"
                },
                responsive: true,
                dom: '<"row"<"col-sm-12 col-md-6"l><"col-sm-12 col-md-6"f>>rtip'
            });
        }
        
        // İstatistikleri yükle
        function loadStats() {
            $.post('', { action: 'stats' }, function(response) {
                if (response.success) {
                    $('#stat-toplam-cari').text(response.data.toplam_cari);
                    $('#stat-aktif-cari').text(response.data.aktif_cari);
                    $('#stat-musteri').text(response.data.musteri_sayisi);
                    $('#stat-tedarikci').text(response.data.tedarikci_sayisi);
                }
            });
        }
        
        // Cari sil
        function deleteCari(cariid) {
            confirmAction(
                'Bu cariyi silmek istediğinize emin misiniz?',
                'Bu işlem geri alınamaz!',
                function() {
                    $.post('', { action: 'delete', cari_id: cariid }, function(response) {
                        if (response.success) {
                            showSuccess('Silindi!', response.message);
                            loadStats();
                            table.ajax.reload(null, false);
                        } else if (response.bagli) {
                            bagliKayitPenceresi(response.bagli, response.message);
                        } else {
                            showError('Hata!', response.message);
                        }
                    }).fail(function() {
                        showError('Bağlantı Hatası!', 'Sunucuya ulaşılamıyor.');
                    });
                }
            );
        }

        // Bağlı kayıtları getir
        function bagliKayitlariGoster(cariid) {
            $.post('', { action: 'bagliKayitlar', cari_id: cariid }, function(response) {
                if (response.success) {
                    bagliKayitPenceresi(response.data, null);
                } else {
                    showError('Hata!', response.message);
                }
            }).fail(function() {
                showError('Bağlantı Hatası!', 'Sunucuya ulaşılamıyor.');
            });
        }

        function htmlKacis(deger) {
            return $('<div>').text(deger === null || deger === undefined ? '' : String(deger)).html();
        }

        // Bağlı kayıt penceresi
        function bagliKayitPenceresi(liste, mesaj) {
            if (!liste || liste.length === 0) {
                Swal.fire({ icon: 'info', title: 'Bağlı Kayıt Yok', text: 'Bu cariye bağlı herhangi bir kayıt bulunamadı.' });
                return;
            }

            let html = mesaj ? `<div class="alert alert-warning text-start">${htmlKacis(mesaj)}</div>` : '';
            html += '<div class="accordion text-start" id="bagliAccordion">';
            liste.forEach(function(k, i) {
                const kolonlar = k.ornekler.length ? Object.keys(k.ornekler[0]) : [];
                let tablo = '';
                if (k.linkler && k.linkler.length) {
                    tablo += '<div class="d-flex flex-wrap gap-1 mb-2">';
                    k.linkler.forEach(l => tablo += `<a href="${htmlKacis(l.url)}" target="_blank" class="btn btn-sm btn-outline-primary"><i class="bi bi-box-arrow-up-right"></i> #${l.id}</a>`);
                    tablo += '</div>';
                }
                tablo += '<div class="table-responsive"><table class="table table-sm table-bordered small mb-0"><thead><tr>';
                kolonlar.forEach(c => tablo += `<th class="text-nowrap">${htmlKacis(c)}</th>`);
                tablo += '</tr></thead><tbody>';
                k.ornekler.forEach(function(satir) {
                    tablo += '<tr>' + kolonlar.map(c => `<td class="text-nowrap">${htmlKacis(satir[c])}</td>`).join('') + '</tr>';
                });
                tablo += '</tbody></table></div>';
                if (k.adet > k.ornekler.length) {
                    tablo += `<div class="text-muted small mt-1">İlk ${k.ornekler.length} kayıt gösteriliyor.</div>`;
                }

                html += `
                    <div class="accordion-item">
                        <h2 class="accordion-header">
                            <button class="accordion-button collapsed" type="button" data-bs-toggle="collapse" data-bs-target="#bagli_${i}">
                                <i class="bi bi-table me-2"></i><strong>${htmlKacis(k.tablo)}</strong>
                                <span class="text-muted ms-2 small">(${htmlKacis(k.kolon)})</span>
                                <span class="badge bg-danger ms-auto me-2">${k.adet}</span>
                            </button>
                        </h2>
                        <div id="bagli_${i}" class="accordion-collapse collapse${k.linkler && k.linkler.length ? ' show' : ''}" data-bs-parent="#bagliAccordion">
                            <div class="accordion-body p-2">${tablo}</div>
                        </div>
                    </div>`;
            });
            html += '</div>';

            Swal.fire({
                icon: mesaj ? 'warning' : 'info',
                title: 'Bağlı Kayıtlar',
                html: html,
                width: '90%',
                confirmButtonText: 'Kapat'
            });
        }
    </script>
    
</body>
</html>