<?php
/**
 * Admin Panel - Banka ve İBAN Yönetimi
 */

require_once __DIR__ . '/../auth.php';
require_once __DIR__ . '/../includes/PageAuth.php';
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
    PageAuth::accessDenied($pagePermissions['error'] ?? 'Bu sayfaya erişim Yetkiniz bulunmamaktadır.');
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

$pageTitle = $pageinfo['sayfalar_sayfa_adi'] ?? 'Banka ve İBAN Yönetimi';
$pageDescription = $pageinfo['sayfalar_aciklama'] ?? '';
$menuAdi = $pageinfo['menu_adi'] ?? null;

// site title'i çek
$siteAyarlari = $db->fetchOne("SELECT TOP 1 site_ayarlari_site_title FROM dbo.tanim_site_ayarlari ORDER BY site_ayarlari_id DESC");
$siteTitle = $siteAyarlari['site_ayarlari_site_title'] ?? 'Ornek Yazilim Portal';

// AJAX işlemleri
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json');
    
    try {
        $action = $_POST['action'] ?? '';
        
        if ($action === 'stats') {
            $stats = [
                'toplam' => $db->fetchOne("SELECT count(*) as sayi FROM banka_Hesap")['sayi'] ?? 0,
                'firma_hesaplari' => $db->fetchOne("SELECT count(*) as sayi FROM banka_Hesap WHERE bankaHesap_firma_id IS NOT NULL")['sayi'] ?? 0,
                'aktif' => $db->fetchOne("SELECT count(*) as sayi FROM banka_Hesap WHERE bankaHesap_durum = 1")['sayi'] ?? 0,
                'otomatik' => $db->fetchOne("SELECT count(*) as sayi FROM banka_Hesap WHERE bankaHesap_otomatik = 1")['sayi'] ?? 0
            ];
            
            echo json_encode(['success' => true, 'data' => $stats]);
            exit;
        }
        
        if ($action === 'get_firmalar') {
            $firmalar = $db->fetchAll("SELECT firma_id, firma_adi FROM Firmalar WHERE firma_durum = 1 ORDER BY firma_adi");
            echo json_encode(['success' => true, 'data' => $firmalar]);
            exit;
        }
        
        if ($action === 'get_bankalar') {
            $bankalar = $db->fetchAll("SELECT banka_id, banka_adi, banka_kodu FROM bankalar WHERE banka_durum = 1 ORDER BY banka_adi");
            echo json_encode(['success' => true, 'data' => $bankalar]);
            exit;
        }
        
        // Banka Tanımları işlemleri
        if ($action === 'list_tanimlar') {
            $tanimlar = $db->fetchAll("
                SELECT 
                    banka_id,
                    banka_adi,
                    banka_kodu,
                    banka_sira_no,
                    banka_personel,
                    banka_durum
                FROM bankalar
                ORDER BY banka_sira_no, banka_adi
            ");
            echo json_encode(['success' => true, 'data' => $tanimlar]);
            exit;
        }
        
        if ($action === 'save_tanim') {
            $id = intval($_POST['id'] ?? 0);
            
            if ($id > 0 && !$pagePermissions['can_edit']) {
                throw new Exception('Düzenleme Yetkiniz bulunmamaktadır.');
            }
            if ($id == 0 && !$pagePermissions['can_add']) {
                throw new Exception('Ekleme Yetkiniz bulunmamaktadır.');
            }
            
            $adi = trim($_POST['adi'] ?? '');
            $kodu = trim($_POST['kodu'] ?? '');
            $sira_no = intval($_POST['sira_no'] ?? 0);
            $personel = intval($_POST['personel'] ?? 0);
            $durum = intval($_POST['durum'] ?? 1);
            
            if (empty($adi)) {
                throw new Exception('Banka adı zorunludur');
            }
            
            if ($id > 0) {
                $db->update('bankalar', [
                    'banka_adi' => $adi,
                    'banka_kodu' => $kodu ?: null,
                    'banka_sira_no' => $sira_no,
                    'banka_personel' => $personel,
                    'banka_durum' => $durum,
                    'banka_guncelleme_tarihi' => date('Y-m-d H:i:s'),
                    'banka_guncelleyen_kullanici_id' => $user['kullanici_id']
                ], ['banka_id' => $id]);
                
                echo json_encode(['success' => true, 'message' => 'Banka güncellendi']);
            } else {
                $newid = $db->insert('bankalar', [
                    'banka_adi' => $adi,
                    'banka_kodu' => $kodu ?: null,
                    'banka_sira_no' => $sira_no,
                    'banka_personel' => $personel,
                    'banka_durum' => $durum,
                    'banka_olusturan_kullanici_id' => $user['kullanici_id']
                ]);
                
                echo json_encode(['success' => true, 'message' => 'Banka eklendi', 'id' => $newid]);
            }
            exit;
        }
        
        if ($action === 'delete_tanim') {
            if (!$pagePermissions['can_delete']) {
                throw new Exception('Silme Yetkiniz bulunmamaktadır.');
            }
            
            $id = intval($_POST['id'] ?? 0);
            if ($id <= 0) {
                throw new Exception('Geçersiz id');
            }
            
            // Kullanımda olup olmadığını kontrol et
            $kullanim = $db->fetchOne("SELECT count(*) as sayi FROM banka_Hesap WHERE bankaHesap_banka_id = ?", [$id]);
            if ($kullanim && $kullanim['sayi'] > 0) {
                throw new Exception('Bu banka ' . $kullanim['sayi'] . ' hesapta kullanılıyor, silinemez!');
            }
            
            $db->delete('bankalar', ['banka_id' => $id]);
            echo json_encode(['success' => true, 'message' => 'Banka silindi']);
            exit;
        }
        
        if ($action === 'list') {
            // Filtreleri al
            $firma_id = $_POST['firma_id'] ?? '';
            $durum = $_POST['durum'] ?? '';
            $search = $_POST['search'] ?? '';
            
            $whereConditions = ["1=1"];
            $params = [];
            
            if ($firma_id) {
                $whereConditions[] = "h.bankaHesap_firma_id = ?";
                $params[] = $firma_id;
            }
            
            if ($durum !== '') {
                $whereConditions[] = "h.bankaHesap_durum = ?";
                $params[] = $durum;
            }
            
            if ($search) {
                $whereConditions[] = "(b.banka_adi LIKE ? OR b.banka_kodu LIKE ? OR h.bankaHesap_iban LIKE ?)";
                $params[] = "%$search%";
                $params[] = "%$search%";
                $params[] = "%$search%";
            }
            
            $whereClause = implode(" AND ", $whereConditions);
            
            $hesaplar = $db->fetchAll("
                SELECT 
                    h.bankaHesap_id,
                    h.bankaHesap_banka_id,
                    h.bankaHesap_firma_id,
                    h.bankaHesap_swift,
                    h.bankaHesap_iban,
                    h.bankaHesap_no,
                    h.bankaHesap_sube_adi,
                    h.bankaHesap_sube_kodu,
                    h.bankaHesap_aciklama,
                    h.bankaHesap_otomatik,
                    h.bankaHesap_durum,
                    b.banka_adi,
                    b.banka_kodu,
                    b.banka_logo_url,
                    f.firma_adi,
                    CONVERT(VARCHAR(19), h.bankaHesap_olusturma_tarihi, 120) as bankaHesap_olusturma_tarihi,
                    CONVERT(VARCHAR(19), h.bankaHesap_guncelleme_tarihi, 120) as bankaHesap_guncelleme_tarihi
                FROM banka_Hesap h
                INNER JOIN bankalar b ON h.bankaHesap_banka_id = b.banka_id
                LEFT JOIN Firmalar f ON h.bankaHesap_firma_id = f.firma_id
                WHERE $whereClause
                ORDER BY b.banka_adi, h.bankaHesap_iban
            ", $params);
            
            echo json_encode(['success' => true, 'data' => $hesaplar]);
            exit;
        }
        
        if ($action === 'save') {
            // Yetki kontrolü
            $id = intval($_POST['id'] ?? 0);
            $banka_id = intval($_POST['banka_id'] ?? 0);
            if ($id > 0 && !$pagePermissions['can_edit']) {
                throw new Exception('Düzenleme Yetkiniz bulunmamaktadır.');
            }
            if ($id == 0 && !$pagePermissions['can_add']) {
                throw new Exception('Ekleme Yetkiniz bulunmamaktadır.');
            }
            
            $firma_id = !empty($_POST['firma_id']) ? intval($_POST['firma_id']) : null;
            $SWIFT = trim($_POST['SWIFT'] ?? '');
            $iban = trim($_POST['iban'] ?? '');
            $hesap_no = trim($_POST['hesap_no'] ?? '');
            $sube_adi = trim($_POST['sube_adi'] ?? '');
            $sube_kodu = trim($_POST['sube_kodu'] ?? '');
            $aciklama = trim($_POST['aciklama'] ?? '');
            $durum = intval($_POST['durum'] ?? 1);
            
            if ($banka_id <= 0) {
                throw new Exception('Banka seçimi zorunludur');
            }
            
            if (empty($iban)) {
                throw new Exception('İBAN zorunludur');
            }
            
            // Otomatik hesap kontrolü (API'den gelen hesaplar güncellenemez)
            if ($id > 0) {
                $mevcut = $db->fetchOne("SELECT bankaHesap_otomatik FROM banka_Hesap WHERE bankaHesap_id = ?", [$id]);
                if ($mevcut && $mevcut['bankaHesap_otomatik'] == 1) {
                    throw new Exception('Bu hesap otomatik Sistemden geldi, manuel değiştirilemez!');
                }
            }
            
            if ($id > 0) {
                // güncelleme
                $db->update('banka_Hesap', [
                    'bankaHesap_banka_id' => $banka_id,
                    'bankaHesap_firma_id' => $firma_id,
                    'bankaHesap_swift' => $SWIFT ?: null,
                    'bankaHesap_iban' => $iban,
                    'bankaHesap_no' => $hesap_no ?: null,
                    'bankaHesap_sube_adi' => $sube_adi ?: null,
                    'bankaHesap_sube_kodu' => $sube_kodu ?: null,
                    'bankaHesap_aciklama' => $aciklama ?: null,
                    'bankaHesap_durum' => $durum,
                    'bankaHesap_guncelleme_tarihi' => date('Y-m-d H:i:s'),
                    'bankaHesap_guncelleyen_kullanici_id' => $user['kullanici_id']
                ], ['bankaHesap_id' => $id]);
                
                echo json_encode(['success' => true, 'message' => 'Banka hesabı güncellendi']);
            } else {
                // Yeni ekleme (Manuel hesap)
                $newid = $db->insert('banka_Hesap', [
                    'bankaHesap_banka_id' => $banka_id,
                    'bankaHesap_firma_id' => $firma_id,
                    'bankaHesap_swift' => $SWIFT ?: null,
                    'bankaHesap_iban' => $iban,
                    'bankaHesap_no' => $hesap_no ?: null,
                    'bankaHesap_sube_adi' => $sube_adi ?: null,
                    'bankaHesap_sube_kodu' => $sube_kodu ?: null,
                    'bankaHesap_aciklama' => $aciklama ?: null,
                    'bankaHesap_otomatik' => 0, // Manuel eklenen hesap
                    'bankaHesap_durum' => $durum,
                    'bankaHesap_olusturan_kullanici_id' => $user['kullanici_id']
                ]);
                
                echo json_encode(['success' => true, 'message' => 'Banka hesabı eklendi', 'id' => $newid]);
            }
            exit;
        }
        
        if ($action === 'delete') {
            // Yetki kontrolü
            if (!$pagePermissions['can_delete']) {
                throw new Exception('Silme Yetkiniz bulunmamaktadır.');
            }
            
            $id = intval($_POST['id'] ?? 0);
            if ($id <= 0) {
                throw new Exception('Geçersiz id');
            }
            
            // Otomatik hesap kontrolü
            $hesap = $db->fetchOne("SELECT bankaHesap_otomatik FROM banka_Hesap WHERE bankaHesap_id = ?", [$id]);
            if ($hesap && $hesap['bankaHesap_otomatik'] == 1) {
                throw new Exception('Otomatik hesaplar silinemez!');
            }
            
            // Kullanımda olup olmadığını kontrol et
            $kullanim = $db->fetchOne("SELECT count(*) as sayi FROM kullanicilar WHERE kullanici_banka_hesap_id = ?", [$id]);
            if ($kullanim && $kullanim['sayi'] > 0) {
                throw new Exception('Bu hesap ' . $kullanim['sayi'] . ' personelde kullanılıyor, silinemez!');
            }
            
            $db->delete('banka_Hesap', ['bankaHesap_id' => $id]);
            echo json_encode(['success' => true, 'message' => 'Banka hesabı silindi']);
            exit;
        }
        
        throw new Exception('Geçersiz işlem');
        
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'message' => $e->getMessage()]);
        exit;
    }
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
    <link rel="stylesheet" href="https://cdn.datatables.net/1.13.7/css/dataTables.bootstrap5.min.css">
    <link href="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css" rel="stylesheet" />
    <link href="https://cdn.jsdelivr.net/npm/select2-bootstrap-5-theme@1.3.0/dist/select2-bootstrap-5-theme.min.css" rel="stylesheet" />
    <link href="https://cdn.jsdelivr.net/npm/sweetalert2@11/dist/sweetalert2.min.css" rel="stylesheet">
    <link rel="stylesheet" href="/admin/assets/css/custom.css">
</head>
<body class="layout-fixed sidebar-expand-lg sidebar-open bg-body-tertiary">
    <div class="app-wrapper">
        <?php include __DIR__ . '/../includes/header.php'; ?>
        <?php include __DIR__ . '/../includes/sidebar.php'; ?>
        
        <main class="app-main">
            <!-- Sayfa Başlığı -->
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
            
            <!-- Sayfa İçeriği -->
            <div class="app-content">
                <div class="container-fluid">
                    
                    <!-- Info Boxes -->
                    <div class="row mb-3">
                        <div class="col-12 col-sm-6 col-md-3">
                            <div class="info-box">
                                <span class="info-box-icon text-bg-primary shadow-sm">
                                    <i class="bi bi-bank"></i>
                                </span>
                                <div class="info-box-content">
                                    <span class="info-box-text">Toplam Hesap</span>
                                    <span class="info-box-number" id="stat-toplam">0</span>
                                </div>
                            </div>
                        </div>
                        
                        <div class="col-12 col-sm-6 col-md-3">
                            <div class="info-box">
                                <span class="info-box-icon text-bg-info shadow-sm">
                                    <i class="bi bi-building"></i>
                                </span>
                                <div class="info-box-content">
                                    <span class="info-box-text">Firma Hesapları</span>
                                    <span class="info-box-number" id="stat-firma">0</span>
                                </div>
                            </div>
                        </div>
                        
                        <div class="col-12 col-sm-6 col-md-3">
                            <div class="info-box">
                                <span class="info-box-icon text-bg-success shadow-sm">
                                    <i class="bi bi-check-circle"></i>
                                </span>
                                <div class="info-box-content">
                                    <span class="info-box-text">Aktif</span>
                                    <span class="info-box-number" id="stat-aktif">0</span>
                                </div>
                            </div>
                        </div>
                        
                        <div class="col-12 col-sm-6 col-md-3">
                            <div class="info-box">
                                <span class="info-box-icon text-bg-warning shadow-sm">
                                    <i class="bi bi-robot"></i>
                                </span>
                                <div class="info-box-content">
                                    <span class="info-box-text">Otomatik (API)</span>
                                    <span class="info-box-number" id="stat-otomatik">0</span>
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
                                <button type="button" class="btn btn-tool" data-bs-toggle="collapse" data-bs-target="#filterCard" aria-expanded="false">
                                    <i class="bi bi-chevron-down"></i>
                                </button>
                            </div>
                        </div>
                        <div class="card-body collapse" id="filterCard">
                            <form id="filterForm">
                                <div class="row g-3">
                                    <!-- Firma -->
                                    <div class="col-md-3">
                                        <label class="form-label">Firma</label>
                                        <select class="form-select" name="firma_id" id="filter_firma_id">
                                            <option value="">Tümü</option>
                                        </select>
                                    </div>
                                    
                                    <!-- Durum -->
                                    <div class="col-md-2">
                                        <label class="form-label">Durum</label>
                                        <select class="form-select" name="durum" id="filter_durum">
                                            <option value="">Tümü</option>
                                            <option value="1">Aktif</option>
                                            <option value="0">Pasif</option>
                                        </select>
                                    </div>
                                    
                                    <!-- Arama -->
                                    <div class="col-md-5">
                                        <label class="form-label">Ara</label>
                                        <input type="text" class="form-control" name="search" id="filter_search" placeholder="Banka adı, kod, İBAN...">
                                    </div>
                                    
                                    <!-- Butonlar -->
                                    <div class="col-md-12">
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
                    
                    <!-- Sekmeler -->
                    <ul class="nav nav-tabs mb-3" role="tablist">
                        <li class="nav-item" role="presentation">
                            <button class="nav-link active" id="hesaplar-tab" data-bs-toggle="tab" data-bs-target="#hesaplar" type="button" role="tab">
                                <i class="bi bi-wallet2"></i> Banka Hesapları
                            </button>
                        </li>
                        <li class="nav-item" role="presentation">
                            <button class="nav-link" id="tanimlar-tab" data-bs-toggle="tab" data-bs-target="#tanimlar" type="button" role="tab">
                                <i class="bi bi-bank"></i> Banka Tanımları
                            </button>
                        </li>
                    </ul>
                    
                    <!-- Sekme İçerikleri -->
                    <div class="tab-content">
                        <!-- Banka Hesapları Sekmesi -->
                        <div class="tab-pane fade show active" id="hesaplar" role="tabpanel">
                            <div class="card card-primary card-outline">
                                <div class="card-header">
                                    <h3 class="card-title">
                                        <i class="bi bi-wallet2"></i> Banka Hesapları
                                    </h3>
                                    <div class="card-tools">
                                        <?php if ($pagePermissions['can_add']): ?>
                                        <button type="button" class="btn btn-primary btn-sm" onclick="bankaEkleModalAc()">
                                            <i class="bi bi-plus-circle"></i> Yeni Hesap
                                        </button>
                                        <?php endif; ?>
                                    </div>
                                </div>
                                <div class="card-body">
                                    <table id="bankaTable" class="table table-bordered table-striped">
                                        <thead>
                                            <tr>
                                                <th>ID</th>
                                                <th>Firma</th>
                                                <th>Banka Adı</th>
                                                <th>İBAN</th>
                                                <th>Hesap No</th>
                                                <th>Şube</th>
                                                <th>Kaynak</th>
                                                <th>Durum</th>
                                                <th>İşlemler</th>
                                            </tr>
                                        </thead>
                                        <tbody></tbody>
                                    </table>
                                </div>
                            </div>
                        </div>
                        
                        <!-- Banka Tanımları Sekmesi -->
                        <div class="tab-pane fade" id="tanimlar" role="tabpanel">
                            <div class="card card-primary card-outline">
                                <div class="card-header">
                                    <h3 class="card-title">
                                        <i class="bi bi-bank"></i> Banka Tanımları
                                    </h3>
                                    <div class="card-tools">
                                        <?php if ($pagePermissions['can_add']): ?>
                                        <button type="button" class="btn btn-primary btn-sm" onclick="bankatanimEkleModalAc()">
                                            <i class="bi bi-plus-circle"></i> Yeni Banka
                                        </button>
                                        <?php endif; ?>
                                    </div>
                                </div>
                                <div class="card-body">
                                    <table id="bankatanimTable" class="table table-bordered table-striped">
                                        <thead>
                                            <tr>
                                                <th>ID</th>
                                                <th>Banka Adı</th>
                                                <th>Kod</th>
                                                <th>Sıra</th>
                                                <th>Personel</th>
                                                <th>Durum</th>
                                                <th>İşlemler</th>
                                            </tr>
                                        </thead>
                                        <tbody></tbody>
                                    </table>
                                </div>
                            </div>
                        </div>
                    </div>
                    
                </div>
            </div>
        </main>
        
        <?php include __DIR__ . '/../includes/footer.php'; ?>
    </div>
    
    <!-- Banka Ekle/Düzenle Modal -->
    <div class="modal fade" id="bankaModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="bankaModalBaslik">Yeni Banka Ekle</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <form id="bankaForm">
                    <input type="hidden" id="banka_id" name="id">
                    <div class="modal-body">
                        <!-- Uyarı Mesajı (Otomatik Hesaplar için) -->
                        <div id="otomatik_uyari" class="alert alert-warning" style="display:none;">
                            <i class="bi bi-exclamation-triangle-fill"></i> <strong>Dikkat!</strong> Bu hesap otomatik Sistemden geldi, değiştirilemez!
                        </div>
                        
                        <!-- Firma ve Banka Seçimi -->
                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label for="banka_firma_id" class="form-label">Firma</label>
                                <select class="form-select" id="banka_firma_id" name="firma_id">
                                    <option value="">Seçiniz...</option>
                                </select>
                            </div>
                            <div class="col-md-6 mb-3">
                                <label for="banka_banka_id" class="form-label">Banka *</label>
                                <select class="form-select" id="banka_banka_id" name="banka_id" required>
                                    <option value="">Seçiniz...</option>
                                </select>
                            </div>
                        </div>
                        
                        <!-- İBAN ve Hesap No -->
                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label for="banka_iban" class="form-label">İBAN *</label>
                                <input type="text" class="form-control" id="banka_iban" name="iban" maxlength="34" placeholder="TR00 0000 0000 0000 0000 0000 00" required>
                            </div>
                            <div class="col-md-6 mb-3">
                                <label for="banka_hesap_no" class="form-label">Hesap No</label>
                                <input type="text" class="form-control" id="banka_hesap_no" name="hesap_no" maxlength="50">
                            </div>
                        </div>
                        
                        <!-- SWIFT ve Şube bilgileri -->
                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label for="banka_SWIFT" class="form-label">SWIFT Kodu</label>
                                <input type="text" class="form-control" id="banka_SWIFT" name="SWIFT" placeholder="TCZBTR2AXXX" maxlength="20">
                            </div>
                            <div class="col-md-6 mb-3">
                                <label for="banka_sube_kodu" class="form-label">Şube Kodu</label>
                                <input type="text" class="form-control" id="banka_sube_kodu" name="sube_kodu" maxlength="20">
                            </div>
                        </div>
                        
                        <!-- Şube Adı ve identifier -->
                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label for="banka_sube_adi" class="form-label">Şube Adı</label>
                                <input type="text" class="form-control" id="banka_sube_adi" name="sube_adi" maxlength="100">
                            </div>
                            <div class="col-md-6 mb-3">
                                <label for="banka_identifier" class="form-label">Tanımlayıcı</label>
                                <input type="text" class="form-control" id="banka_identifier" name="identifier" maxlength="50" placeholder="API Hesap Tanımlayıcı">
                            </div>
                        </div>
                        
                        <!-- Durum -->
                        <div class="row">
                            <div class="col-md-4 mb-3">
                                <label for="banka_durum" class="form-label">Durum</label>
                                <select class="form-select" id="banka_durum" name="durum">
                                    <option value="1">Aktif</option>
                                    <option value="0">Pasif</option>
                                </select>
                            </div>
                        </div>
                        
                        <!-- Açıklama -->
                        <div class="row">
                            <div class="col-md-12 mb-3">
                                <label for="banka_aciklama" class="form-label">Açıklama</label>
                                <textarea class="form-control" id="banka_aciklama" name="aciklama" rows="3"></textarea>
                            </div>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">İptal</button>
                        <button type="submit" class="btn btn-primary">Kaydet</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
    
    <!-- Banka Tanım Modal -->
    <div class="modal fade" id="bankatanimModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="bankatanimModalBaslik">Yeni Banka Tanımı</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <form id="bankatanimForm">
                    <input type="hidden" id="tanim_id" name="id">
                    <div class="modal-body">
                        <div class="row">
                            <div class="col-md-8 mb-3">
                                <label for="tanim_adi" class="form-label">Banka Adı *</label>
                                <input type="text" class="form-control" id="tanim_adi" name="adi" required>
                            </div>
                            <div class="col-md-4 mb-3">
                                <label for="tanim_kodu" class="form-label">Banka Kodu</label>
                                <input type="text" class="form-control" id="tanim_kodu" name="kodu" maxlength="10" placeholder="0001">
                            </div>
                        </div>
                        <div class="row">
                            <div class="col-md-4 mb-3">
                                <label for="tanim_sira_no" class="form-label">Sıra No</label>
                                <input type="number" class="form-control" id="tanim_sira_no" name="sira_no" value="0">
                            </div>
                            <div class="col-md-4 mb-3">
                                <label for="tanim_durum" class="form-label">Durum</label>
                                <select class="form-select" id="tanim_durum" name="durum">
                                    <option value="1">Aktif</option>
                                    <option value="0">Pasif</option>
                                </select>
                            </div>
                            <div class="col-md-4 mb-3">
                                <div class="form-check form-switch mt-4">
                                    <input class="form-check-input" type="checkbox" id="tanim_personel" name="personel" value="1">
                                    <label class="form-check-label" for="tanim_personel">
                                        <i class="bi bi-people"></i> Personel için göster
                                    </label>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">İptal</button>
                        <button type="submit" class="btn btn-primary">Kaydet</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
    
    <script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/@popperjs/core@2.11.8/dist/umd/popper.min.js" crossorigin="anonymous"></script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.7/dist/js/bootstrap.min.js" crossorigin="anonymous"></script>
    <script src="/admin/assets/js/adminlte.min.js"></script>
    <script src="https://cdn.datatables.net/1.13.7/js/jquery.dataTables.min.js"></script>
    <script src="https://cdn.datatables.net/1.13.7/js/dataTables.bootstrap5.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11/dist/sweetalert2.all.min.js"></script>
    <script src="/admin/assets/js/custom.js"></script>
    
    <script>
        let table;
        let currentFilters = {};
        let bankaModalInstance;
        
        // Sayfa yetkileri
        const permissions = <?= json_encode($pagePermissions) ?>;
        
        function loadStats() {
            $.post('', { action: 'stats' }, response => {
                if (response.success) {
                    $('#stat-toplam').text(response.data.toplam);
                    $('#stat-firma').text(response.data.firma_hesaplari);
                    $('#stat-aktif').text(response.data.aktif);
                    $('#stat-otomatik').text(response.data.otomatik);
                }
            });
        }
        
        function loadFirmalar() {
            $.post('', { action: 'get_firmalar' }, response => {
                if (response.success) {
                    const select = $('#banka_firma_id, #filter_firma_id');
                    select.find('option:not(:first)').remove();
                    response.data.forEach(firma => {
                        select.append(`<option value="${firma.firma_id}">${firma.firma_adi}</option>`);
                    });
                }
            });
        }
        
        function loadBankalar() {
            $.post('', { action: 'get_bankalar' }, response => {
                if (response.success) {
                    const select = $('#banka_banka_id');
                    select.find('option:not(:first)').remove();
                    response.data.forEach(banka => {
                        select.append(`<option value="${banka.banka_id}">${banka.banka_adi}</option>`);
                    });
                    initSelect2();
                }
            });
        }
        
        function initSelect2() {
            $('.form-select').select2({
                theme: 'bootstrap-5',
                width: '100%',
                placeholder: 'Seçiniz...',
                allowClear: true,
                language: {
                    noResults: function() { return "Sonuç bulunamadı"; },
                    searching: function() { return "Aranıyor..."; }
                }
            });
            
            // Modal içindeki select'ler için
            $('#banka_firma_id, #banka_banka_id').select2({
                theme: 'bootstrap-5',
                dropdownParent: $('#bankaModal'),
                width: '100%',
                placeholder: 'Seçiniz...',
                allowClear: true
            });
        }
        
        function initDataTable() {
            table = $('#bankaTable').DataTable({
                processing: true,
                language: { url: '//cdn.datatables.net/plug-ins/1.13.7/i18n/tr.json' },
                ajax: {
                    url: '',
                    type: 'POST',
                    data: function(d) {
                        return { action: 'list', ...currentFilters };
                    },
                    dataSrc: json => json.success ? json.data : []
                },
                columns: [
                    { data: 'bankaHesap_id' },
                    { 
                        data: 'firma_adi',
                        defaultContent: '-',
                        render: (data) => data || '-'
                    },
                    { 
                        data: 'banka_adi',
                        render: data => `<strong>${data}</strong>`
                    },
                    { 
                        data: 'bankaHesap_iban', 
                        defaultContent: '-',
                        render: data => data || '-'
                    },
                    { 
                        data: 'bankaHesap_no', 
                        defaultContent: '-' 
                    },
                    { 
                        data: null,
                        defaultContent: '-',
                        render: (data, type, row) => {
                            if (!row.bankaHesap_sube_adi && !row.bankaHesap_sube_kodu) return '-';
                            let sube = row.bankaHesap_sube_adi || '';
                            if (row.bankaHesap_sube_kodu) {
                                sube += sube ? ` (${row.bankaHesap_sube_kodu})` : row.bankaHesap_sube_kodu;
                            }
                            return sube;
                        }
                    },
                    { 
                        data: 'bankaHesap_otomatik',
                        render: data => data 
                            ? '<span class="badge bg-info"><i class="bi bi-robot"></i> Otomatik</span>' 
                            : '<span class="badge bg-secondary"><i class="bi bi-pencil"></i> Manuel</span>'
                    },
                    { 
                        data: 'bankaHesap_durum',
                        render: data => data 
                            ? '<span class="badge bg-success">Aktif</span>' 
                            : '<span class="badge bg-danger">Pasif</span>'
                    },
                    { 
                        data: null,
                        orderable: false,
                        render: data => {
                            let buttons = '';
                            
                            if (permissions.can_edit) {
                                buttons += `<button class="btn btn-sm btn-warning" onclick='bankaDuzenle(${JSON.stringify(data)})' title="Düzenle">
                                    <i class="bi bi-pencil"></i>
                                </button> `;
                            }
                            
                            if (permissions.can_delete && !data.bankaHesap_otomatik) {
                                buttons += `<button class="btn btn-sm btn-danger" onclick="bankaSil(${data.bankaHesap_id}, '${data.banka_adi}')" title="Sil">
                                    <i class="bi bi-trash"></i>
                                </button>`;
                            }
                            
                            return buttons || '<span class="text-muted">-</span>';
                        }
                    }
                ],
                order: [[0, 'desc']] // id'ye göre azalan (en yeni önce)
            });
        }
        
        $(document).ready(() => {
            bankaModalInstance = new bootstrap.Modal(document.getElementById('bankaModal'));
            
            loadStats();
            loadFirmalar();
            loadBankalar();
            initDataTable();
            
            // Filtre form submit
            $('#filterForm').on('submit', function(e) {
                e.preventDefault();
                
                currentFilters = {
                    firma_id: $('#filter_firma_id').val(),
                    durum: $('#filter_durum').val(),
                    search: $('#filter_search').val()
                };
                
                Object.keys(currentFilters).forEach(key => {
                    if (!currentFilters[key]) delete currentFilters[key];
                });
                
                table.ajax.reload();
                showToast('Filtre uygulandı', 'info');
            });
            
            // Filtreleri temizle
            $('#clearFilters').on('click', function() {
                $('#filterForm')[0].reset();
                $('#filter_firma_id').val('').trigger('change.select2');
                $('#filter_durum').val('').trigger('change.select2');
                currentFilters = {};
                table.ajax.reload();
                showToast('Filtreler temizlendi', 'info');
            });
            
            // Form submit
            $('#bankaForm').on('submit', bankaKaydet);
        });
        
        function bankaEkleModalAc() {
            document.getElementById('bankaModalBaslik').textContent = 'Yeni Banka Hesabı Ekle';
            document.getElementById('bankaForm').reset();
            document.getElementById('banka_id').value = '';
            document.getElementById('otomatik_uyari').style.display = 'none';
            
            // Tüm alanları etkinleştir
            document.querySelectorAll('#bankaForm input, #bankaForm select, #bankaForm textarea').forEach(el => {
                el.disabled = false;
            });
            document.querySelector('#bankaForm button[type="submit"]').style.display = 'inline-block';
            
            bankaModalInstance.show();
        }
        
        function bankaDuzenle(banka) {
            // Otomatik hesap kontrolü
            const isOtomatik = banka.bankaHesap_otomatik == 1;
            
            if (isOtomatik) {
                document.getElementById('otomatik_uyari').style.display = 'block';
                // Tüm form alanlarını disabled yap
                document.querySelectorAll('#bankaForm input, #bankaForm select, #bankaForm textarea').forEach(el => {
                    el.disabled = true;
                });
                // Submit butonunu gizle
                document.querySelector('#bankaForm button[type="submit"]').style.display = 'none';
            } else {
                document.getElementById('otomatik_uyari').style.display = 'none';
                document.querySelectorAll('#bankaForm input, #bankaForm select, #bankaForm textarea').forEach(el => {
                    el.disabled = false;
                });
                document.querySelector('#bankaForm button[type="submit"]').style.display = 'inline-block';
            }
            
            document.getElementById('bankaModalBaslik').textContent = 'Banka Hesabı Düzenle';
            document.getElementById('banka_id').value = banka.bankaHesap_id;
            document.getElementById('banka_firma_id').value = banka.bankaHesap_firma_id || '';
            document.getElementById('banka_banka_id').value = banka.bankaHesap_banka_id;
            document.getElementById('banka_iban').value = banka.bankaHesap_iban || '';
            document.getElementById('banka_hesap_no').value = banka.bankaHesap_no || '';
            document.getElementById('banka_SWIFT').value = banka.bankaHesap_swift || '';
            document.getElementById('banka_sube_adi').value = banka.bankaHesap_sube_adi || '';
            document.getElementById('banka_sube_kodu').value = banka.bankaHesap_sube_kodu || '';
            // bankaHesap_identifier kolonu DB'de yok, alan boş kalacak
            document.getElementById('banka_identifier').value = '';
            document.getElementById('banka_aciklama').value = banka.bankaHesap_aciklama || '';
            document.getElementById('banka_durum').value = banka.bankaHesap_durum ? '1' : '0';
            
            initSelect2();
            bankaModalInstance.show();
        }
        
        function bankaKaydet(e) {
            e.preventDefault();
            
            const formData = new FormData(e.target);
            formData.append('action', 'save');
            
            fetch(window.location.href, {
                method: 'POST',
                body: new URLSearchParams(formData)
            })
            .then(r => r.json())
            .then(data => {
                if (data.success) {
                    bankaModalInstance.hide();
                    table.ajax.reload();
                    loadStats();
                    showToast(data.message, 'success');
                } else {
                    showToast(data.message, 'error');
                }
            });
        }
        
        function bankaSil(id, bankaAdi) {
            confirmAction(
                `"${bankaAdi}" bankasını silmek istediğinize emin misiniz?`,
                'Personelde kullanılıyorsa silinemez!',
                function() {
                    fetch(window.location.href, {
                        method: 'POST',
                        headers: {'Content-Type': 'application/x-www-form-urlencoded'},
                        body: `action=delete&id=${id}`
                    })
                    .then(r => r.json())
                    .then(data => {
                        if (data.success) {
                            showSuccess('Silindi!', data.message);
                            table.ajax.reload();
                            loadStats();
                        } else {
                            showError('Hata!', data.message);
                        }
                    })
                    .catch(error => {
                        showError('Bağlantı Hatası!', 'Sunucuya ulaşılamıyor.');
                    });
                }
            );
        }
        
        // ===== BANKA tanimLARi Fonksiyonlari =====
        let tableTanim;
        let bankaTanimModalInstance;
        
        function initBankatanimTable() {
            tableTanim = $('#bankatanimTable').DataTable({
                processing: true,
                language: { url: '//cdn.datatables.net/plug-ins/1.13.7/i18n/tr.json' },
                ajax: {
                    url: '',
                    type: 'POST',
                    data: { action: 'list_tanimlar' },
                    dataSrc: json => json.success ? json.data : []
                },
                columns: [
                    { data: 'banka_id' },
                    { 
                        data: 'banka_adi',
                        render: data => `<strong>${data}</strong>`
                    },
                    { 
                        data: 'banka_kodu',
                        defaultContent: '-'
                    },
                    { 
                        data: 'banka_sira_no',
                        defaultContent: '0'
                    },
                    { 
                        data: 'banka_personel',
                        render: data => data 
                            ? '<span class="badge bg-success"><i class="bi bi-people"></i> Evet</span>' 
                            : '<span class="badge bg-secondary">Hayır</span>'
                    },
                    { 
                        data: 'banka_durum',
                        render: data => data 
                            ? '<span class="badge bg-success">Aktif</span>' 
                            : '<span class="badge bg-danger">Pasif</span>'
                    },
                    { 
                        data: null,
                        orderable: false,
                        render: data => {
                            let buttons = '';
                            
                            if (permissions.can_edit) {
                                buttons += `<button class="btn btn-sm btn-warning" onclick='bankatanimDuzenle(${JSON.stringify(data)})' title="Düzenle">
                                    <i class="bi bi-pencil"></i>
                                </button> `;
                            }
                            
                            if (permissions.can_delete) {
                                buttons += `<button class="btn btn-sm btn-danger" onclick="bankatanimSil(${data.banka_id}, '${data.banka_adi}')" title="Sil">
                                    <i class="bi bi-trash"></i>
                                </button>`;
                            }
                            
                            return buttons || '-';
                        }
                    }
                ]
            });
        }
        
        function bankatanimEkleModalAc() {
            document.getElementById('bankatanimModalBaslik').textContent = 'Yeni Banka Tanımı';
            document.getElementById('bankatanimForm').reset();
            document.getElementById('tanim_id').value = '';
            bankaTanimModalInstance.show();
        }
        
        function bankatanimDuzenle(banka) {
            document.getElementById('bankatanimModalBaslik').textContent = 'Banka Düzenle';
            document.getElementById('tanim_id').value = banka.banka_id;
            document.getElementById('tanim_adi').value = banka.banka_adi;
            document.getElementById('tanim_kodu').value = banka.banka_kodu || '';
            document.getElementById('tanim_sira_no').value = banka.banka_sira_no || 0;
            document.getElementById('tanim_personel').checked = banka.banka_personel == 1;
            document.getElementById('tanim_durum').value = banka.banka_durum ? '1' : '0';
            bankaTanimModalInstance.show();
        }
        
        function bankatanimKaydet(e) {
            e.preventDefault();
            
            const formData = new FormData(e.target);
            formData.append('action', 'save_tanim');
            
            fetch(window.location.href, {
                method: 'POST',
                body: new URLSearchParams(formData)
            })
            .then(r => r.json())
            .then(data => {
                if (data.success) {
                    bankaTanimModalInstance.hide();
                    tableTanim.ajax.reload();
                    loadBankalar(); // Dropdown'lari da güncelle
                    showToast(data.message, 'success');
                } else {
                    showToast(data.message, 'error');
                }
            });
        }
        
        function bankatanimSil(id, bankaAdi) {
            confirmAction(
                `"${bankaAdi}" bankasını silmek istediğinize emin misiniz?`,
                'Bu banka hesaplarda kullanılıyorsa silinemez!',
                function() {
                    fetch(window.location.href, {
                        method: 'POST',
                        headers: {'Content-Type': 'application/x-www-form-urlencoded'},
                        body: `action=delete_tanim&id=${id}`
                    })
                    .then(r => r.json())
                    .then(data => {
                        if (data.success) {
                            showSuccess('Silindi!', data.message);
                            tableTanim.ajax.reload();
                            loadBankalar();
                        } else {
                            showError('Hata!', data.message);
                        }
                    })
                    .catch(error => {
                        showError('Bağlantı Hatası!', 'Sunucuya ulaşılamıyor.');
                    });
                }
            );
        }
        
        // Sekme değiştığınde tablolari başlat
        document.getElementById('tanimlar-tab').addEventListener('shown.bs.tab', function() {
            if (!tableTanim) {
                initBankatanimTable();
                bankaTanimModalInstance = new bootstrap.Modal(document.getElementById('bankatanimModal'));
                document.getElementById('bankatanimForm').addEventListener('submit', bankatanimKaydet);
            }
        });
    </script>
</body>
</html>
