<?php
/**
 * Hukuk Tanımları Yönetimi
 * HukukTaraflar, HukukIcraDairesi, HukukSavcilik ve HukukStatu tablolarını yönetir
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

$pageTitle = $pageInfo['sayfalar_sayfa_adi'] ?? 'Hukuk Tanımları';
$pageDescription = $pageInfo['sayfalar_aciklama'] ?? '';
$menuAdi = $pageInfo['menu_adi'] ?? null;

$siteAyarlari = $db->fetchOne("SELECT TOP 1 site_ayarlari_site_title FROM dbo.tanim_site_ayarlari ORDER BY site_ayarlari_id DESC");
$siteTitle = $siteAyarlari['site_ayarlari_site_title'] ?? 'Portal';

// AJAX İşlemleri
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json');
    $action = $_POST['action'] ?? '';
    
    try {
        switch ($action) {
            // ========== ORTAK: ŞEHİR LİSTESİ ==========
            case 'sehir_list':
                $data = $db->fetchAll("SELECT SehirId, SehirAdi FROM Adres_Sehirler ORDER BY SehirAdi");
                echo json_encode(['success' => true, 'data' => $data]);
                break;

            // ========== TARAFLAR ==========
            case 'taraf_stats':
                $toplam = $db->fetchOne("SELECT COUNT(*) as sayi FROM HukukTaraflar WHERE Durum IS NOT NULL");
                $aktif = $db->fetchOne("SELECT COUNT(*) as sayi FROM HukukTaraflar WHERE Durum = 1");
                $pasif = $db->fetchOne("SELECT COUNT(*) as sayi FROM HukukTaraflar WHERE Durum = 0");
                
                echo json_encode(['success' => true, 'data' => [
                    'toplam' => intval($toplam['sayi'] ?? 0),
                    'aktif' => intval($aktif['sayi'] ?? 0),
                    'pasif' => intval($pasif['sayi'] ?? 0)
                ]]);
                break;
                
            case 'taraf_list':
                $data = $db->fetchAll("
                    SELECT 
                        t.taraf_id,
                        t.taraf_ad,
                        t.taraf_aciklama,
                        t.taraf_renk,
                        t.taraf_yazi_renk,
                        t.Durum,
                        CONVERT(VARCHAR(19), t.OlusturmaTarihi, 120) as OlusturmaTarihi,
                        k.kullanici_ad + ' ' + k.kullanici_soyad as olusturan
                    FROM HukukTaraflar t
                    LEFT JOIN kullanicilar k ON t.OlusturanKullanici = k.kullanici_id
                    ORDER BY t.taraf_ad
                ");
                echo json_encode(['success' => true, 'data' => $data]);
                break;
                
            case 'taraf_get':
                $id = intval($_POST['id'] ?? 0);
                $data = $db->fetchOne("SELECT * FROM HukukTaraflar WHERE taraf_id = ?", [$id]);
                if ($data) {
                    echo json_encode(['success' => true, 'data' => $data]);
                } else {
                    echo json_encode(['success' => false, 'message' => 'Kayıt bulunamadı']);
                }
                break;
                
            case 'taraf_save':
                $id = intval($_POST['taraf_id'] ?? 0);
                $ad = trim($_POST['taraf_ad'] ?? '');
                $aciklama = trim($_POST['taraf_aciklama'] ?? '');
                $durum = intval($_POST['durum'] ?? 1);

                // Renkler ihbar sayfasında inline CSS'e basıldığı için yalnız #RRGGBB kabul edilir.
                $hexKontrol = static function ($deger) {
                    $deger = trim((string)$deger);
                    return preg_match('/^#[0-9A-Fa-f]{6}$/', $deger) ? strtoupper($deger) : null;
                };
                $renk = $hexKontrol($_POST['taraf_renk'] ?? '');
                $yaziRenk = $hexKontrol($_POST['taraf_yazi_renk'] ?? '');

                if (empty($ad)) {
                    echo json_encode(['success' => false, 'message' => 'Taraf adı zorunludur']);
                    break;
                }

                if ($id > 0) {
                    // Güncelle
                    $db->execute("
                        UPDATE HukukTaraflar SET
                            taraf_ad = ?,
                            taraf_aciklama = ?,
                            taraf_renk = ?,
                            taraf_yazi_renk = ?,
                            Durum = ?,
                            GuncelleyenKullanici = ?,
                            GuncellemeTarihi = GETDATE()
                        WHERE taraf_id = ?
                    ", [$ad, $aciklama, $renk, $yaziRenk, $durum, $user['kullanici_id'], $id]);
                    echo json_encode(['success' => true, 'message' => 'Taraf güncellendi']);
                } else {
                    // Ekle
                    $db->execute("
                        INSERT INTO HukukTaraflar (taraf_ad, taraf_aciklama, taraf_renk, taraf_yazi_renk, Durum, OlusturanKullanici)
                        VALUES (?, ?, ?, ?, ?, ?)
                    ", [$ad, $aciklama, $renk, $yaziRenk, $durum, $user['kullanici_id']]);
                    echo json_encode(['success' => true, 'message' => 'Taraf eklendi']);
                }
                break;
                
            case 'taraf_delete':
                $id = intval($_POST['id'] ?? 0);
                $db->execute("DELETE FROM HukukTaraflar WHERE taraf_id = ?", [$id]);
                echo json_encode(['success' => true, 'message' => 'Taraf silindi']);
                break;
                
            // ========== İCRA DAİRESİ ==========
            case 'icra_stats':
                $toplam = $db->fetchOne("SELECT COUNT(*) as sayi FROM HukukIcraDairesi WHERE Durum IS NOT NULL");
                $aktif = $db->fetchOne("SELECT COUNT(*) as sayi FROM HukukIcraDairesi WHERE Durum = 1");
                $pasif = $db->fetchOne("SELECT COUNT(*) as sayi FROM HukukIcraDairesi WHERE Durum = 0");
                
                echo json_encode(['success' => true, 'data' => [
                    'toplam' => intval($toplam['sayi'] ?? 0),
                    'aktif' => intval($aktif['sayi'] ?? 0),
                    'pasif' => intval($pasif['sayi'] ?? 0)
                ]]);
                break;
                
            case 'icra_list':
                $data = $db->fetchAll("
                    SELECT 
                        i.icra_dairesi_id,
                        i.icra_dairesi_ad,
                        i.icra_dairesi_il,
                        i.icra_dairesi_aciklama,
                        i.Durum,
                        CONVERT(VARCHAR(19), i.OlusturmaTarihi, 120) as OlusturmaTarihi,
                        k.kullanici_ad + ' ' + k.kullanici_soyad as olusturan
                    FROM HukukIcraDairesi i
                    LEFT JOIN kullanicilar k ON i.OlusturanKullanici = k.kullanici_id
                    ORDER BY i.icra_dairesi_ad
                ");
                echo json_encode(['success' => true, 'data' => $data]);
                break;
                
            case 'icra_get':
                $id = intval($_POST['id'] ?? 0);
                $data = $db->fetchOne("SELECT * FROM HukukIcraDairesi WHERE icra_dairesi_id = ?", [$id]);
                if ($data) {
                    echo json_encode(['success' => true, 'data' => $data]);
                } else {
                    echo json_encode(['success' => false, 'message' => 'Kayıt bulunamadı']);
                }
                break;
                
            case 'icra_save':
                $id = intval($_POST['icra_dairesi_id'] ?? 0);
                $ad = trim($_POST['icra_dairesi_ad'] ?? '');
                $il = trim($_POST['icra_dairesi_il'] ?? '');
                $aciklama = trim($_POST['icra_dairesi_aciklama'] ?? '');
                $durum = intval($_POST['durum'] ?? 1);
                
                if (empty($ad)) {
                    echo json_encode(['success' => false, 'message' => 'İcra dairesi adı zorunludur']);
                    break;
                }

                // Mükerrer kontrolü
                if ($id > 0) {
                    $mukerrer = $db->fetchOne("SELECT icra_dairesi_id FROM HukukIcraDairesi WHERE icra_dairesi_ad = ? AND icra_dairesi_id != ?", [$ad, $id]);
                } else {
                    $mukerrer = $db->fetchOne("SELECT icra_dairesi_id FROM HukukIcraDairesi WHERE icra_dairesi_ad = ?", [$ad]);
                }
                if ($mukerrer) {
                    echo json_encode(['success' => false, 'message' => 'Bu icra dairesi adı zaten kayıtlı']);
                    break;
                }
                
                if ($id > 0) {
                    // Güncelle
                    $db->execute("
                        UPDATE HukukIcraDairesi SET 
                            icra_dairesi_ad = ?, 
                            icra_dairesi_il = ?,
                            icra_dairesi_aciklama = ?,
                            Durum = ?,
                            GuncelleyenKullanici = ?,
                            GuncellemeTarihi = GETDATE()
                        WHERE icra_dairesi_id = ?
                    ", [$ad, $il, $aciklama, $durum, $user['kullanici_id'], $id]);
                    echo json_encode(['success' => true, 'message' => 'İcra dairesi güncellendi']);
                } else {
                    // Ekle
                    $db->execute("
                        INSERT INTO HukukIcraDairesi (icra_dairesi_ad, icra_dairesi_il, icra_dairesi_aciklama, Durum, OlusturanKullanici)
                        VALUES (?, ?, ?, ?, ?)
                    ", [$ad, $il, $aciklama, $durum, $user['kullanici_id']]);
                    echo json_encode(['success' => true, 'message' => 'İcra dairesi eklendi']);
                }
                break;
                
            case 'icra_delete':
                $id = intval($_POST['id'] ?? 0);
                $db->execute("DELETE FROM HukukIcraDairesi WHERE icra_dairesi_id = ?", [$id]);
                echo json_encode(['success' => true, 'message' => 'İcra dairesi silindi']);
                break;
                
            // ========== SAVCILIKLAR ==========
            case 'savcilik_stats':
                $toplam = $db->fetchOne("SELECT COUNT(*) as sayi FROM HukukSavcilik WHERE Durum IS NOT NULL");
                $aktif = $db->fetchOne("SELECT COUNT(*) as sayi FROM HukukSavcilik WHERE Durum = 1");
                $pasif = $db->fetchOne("SELECT COUNT(*) as sayi FROM HukukSavcilik WHERE Durum = 0");
                
                echo json_encode(['success' => true, 'data' => [
                    'toplam' => intval($toplam['sayi'] ?? 0),
                    'aktif' => intval($aktif['sayi'] ?? 0),
                    'pasif' => intval($pasif['sayi'] ?? 0)
                ]]);
                break;
                
            case 'savcilik_list':
                $data = $db->fetchAll("
                    SELECT 
                        s.savcilik_id,
                        s.savcilik_ad,
                        s.savcilik_il,
                        s.savcilik_aciklama,
                        s.Durum,
                        CONVERT(VARCHAR(19), s.OlusturmaTarihi, 120) as OlusturmaTarihi,
                        k.kullanici_ad + ' ' + k.kullanici_soyad as olusturan
                    FROM HukukSavcilik s
                    LEFT JOIN kullanicilar k ON s.OlusturanKullanici = k.kullanici_id
                    ORDER BY s.savcilik_ad
                ");
                echo json_encode(['success' => true, 'data' => $data]);
                break;
                
            case 'savcilik_get':
                $id = intval($_POST['id'] ?? 0);
                $data = $db->fetchOne("SELECT * FROM HukukSavcilik WHERE savcilik_id = ?", [$id]);
                if ($data) {
                    echo json_encode(['success' => true, 'data' => $data]);
                } else {
                    echo json_encode(['success' => false, 'message' => 'Kayıt bulunamadı']);
                }
                break;
                
            case 'savcilik_save':
                $id = intval($_POST['savcilik_id'] ?? 0);
                $ad = trim($_POST['savcilik_ad'] ?? '');
                $il = trim($_POST['savcilik_il'] ?? '');
                $aciklama = trim($_POST['savcilik_aciklama'] ?? '');
                $durum = intval($_POST['durum'] ?? 1);
                
                if (empty($ad)) {
                    echo json_encode(['success' => false, 'message' => 'Savcılık adı zorunludur']);
                    break;
                }
                
                // Mükerrer kontrol
                if ($id > 0) {
                    $mukerrer = $db->fetchOne("SELECT savcilik_id FROM HukukSavcilik WHERE savcilik_ad = ? AND savcilik_id != ?", [$ad, $id]);
                } else {
                    $mukerrer = $db->fetchOne("SELECT savcilik_id FROM HukukSavcilik WHERE savcilik_ad = ?", [$ad]);
                }
                if ($mukerrer) {
                    echo json_encode(['success' => false, 'message' => 'Bu savcılık adı zaten kayıtlı']);
                    break;
                }
                
                if ($id > 0) {
                    $db->execute("
                        UPDATE HukukSavcilik SET 
                            savcilik_ad = ?, 
                            savcilik_il = ?,
                            savcilik_aciklama = ?,
                            Durum = ?,
                            GuncelleyenKullanici = ?,
                            GuncellemeTarihi = GETDATE()
                        WHERE savcilik_id = ?
                    ", [$ad, $il, $aciklama, $durum, $user['kullanici_id'], $id]);
                    echo json_encode(['success' => true, 'message' => 'Savcılık güncellendi']);
                } else {
                    $db->execute("
                        INSERT INTO HukukSavcilik (savcilik_ad, savcilik_il, savcilik_aciklama, Durum, OlusturanKullanici)
                        VALUES (?, ?, ?, ?, ?)
                    ", [$ad, $il, $aciklama, $durum, $user['kullanici_id']]);
                    echo json_encode(['success' => true, 'message' => 'Savcılık eklendi']);
                }
                break;
                
            case 'savcilik_delete':
                $id = intval($_POST['id'] ?? 0);
                $db->execute("DELETE FROM HukukSavcilik WHERE savcilik_id = ?", [$id]);
                echo json_encode(['success' => true, 'message' => 'Savcılık silindi']);
                break;
                
            // ========== STATÜLER ==========
            case 'statu_stats':
                $toplam = $db->fetchOne("SELECT COUNT(*) as sayi FROM HukukStatu WHERE Durum IS NOT NULL");
                $aktif = $db->fetchOne("SELECT COUNT(*) as sayi FROM HukukStatu WHERE Durum = 1");
                $pasif = $db->fetchOne("SELECT COUNT(*) as sayi FROM HukukStatu WHERE Durum = 0");
                
                echo json_encode(['success' => true, 'data' => [
                    'toplam' => intval($toplam['sayi'] ?? 0),
                    'aktif' => intval($aktif['sayi'] ?? 0),
                    'pasif' => intval($pasif['sayi'] ?? 0)
                ]]);
                break;
                
            case 'statu_list':
                $data = $db->fetchAll("
                    SELECT 
                        s.statu_id,
                        s.statu_ad,
                        s.statu_sira,
                        s.statu_cari_tipi_id,
                        s.Durum,
                        CONVERT(VARCHAR(19), s.OlusturmaTarihi, 120) as OlusturmaTarihi,
                        ct.cari_tipi_ad,
                        k.kullanici_ad + ' ' + k.kullanici_soyad as olusturan
                    FROM HukukStatu s
                    LEFT JOIN Cari_CariTipleri ct ON s.statu_cari_tipi_id = ct.cari_tipi_id
                    LEFT JOIN kullanicilar k ON s.OlusturanKullanici = k.kullanici_id
                    ORDER BY s.statu_cari_tipi_id, s.statu_sira, s.statu_ad
                ");
                echo json_encode(['success' => true, 'data' => $data]);
                break;
                
            case 'statu_get':
                $id = intval($_POST['id'] ?? 0);
                $data = $db->fetchOne("SELECT * FROM HukukStatu WHERE statu_id = ?", [$id]);
                if ($data) {
                    echo json_encode(['success' => true, 'data' => $data]);
                } else {
                    echo json_encode(['success' => false, 'message' => 'Kayıt bulunamadı']);
                }
                break;
                
            case 'statu_save':
                $id = intval($_POST['statu_id'] ?? 0);
                $ad = trim($_POST['statu_ad'] ?? '');
                $sira = intval($_POST['statu_sira'] ?? 0);
                $cariTipiId = intval($_POST['statu_cari_tipi_id'] ?? 0);
                $durum = intval($_POST['durum'] ?? 1);
                
                if (empty($ad)) {
                    echo json_encode(['success' => false, 'message' => 'Statü adı zorunludur']);
                    break;
                }
                
                if ($cariTipiId <= 0) {
                    echo json_encode(['success' => false, 'message' => 'Cari tipi seçimi zorunludur']);
                    break;
                }
                
                if ($id > 0) {
                    $db->execute("
                        UPDATE HukukStatu SET 
                            statu_ad = ?, 
                            statu_sira = ?,
                            statu_cari_tipi_id = ?,
                            Durum = ?,
                            GuncelleyenKullanici = ?,
                            GuncellemeTarihi = GETDATE()
                        WHERE statu_id = ?
                    ", [$ad, $sira, $cariTipiId, $durum, $user['kullanici_id'], $id]);
                    echo json_encode(['success' => true, 'message' => 'Statü güncellendi']);
                } else {
                    $db->execute("
                        INSERT INTO HukukStatu (statu_ad, statu_sira, statu_cari_tipi_id, Durum, OlusturanKullanici)
                        VALUES (?, ?, ?, ?, ?)
                    ", [$ad, $sira, $cariTipiId, $durum, $user['kullanici_id']]);
                    echo json_encode(['success' => true, 'message' => 'Statü eklendi']);
                }
                break;
                
            case 'statu_delete':
                $id = intval($_POST['id'] ?? 0);
                $kullaniliyor = $db->fetchOne("SELECT COUNT(*) as sayi FROM HukukTakip WHERE takip_statu_id = ?", [$id]);
                if (intval($kullaniliyor['sayi'] ?? 0) > 0) {
                    echo json_encode(['success' => false, 'message' => 'Bu statü kullanımda olduğu için silinemez (' . $kullaniliyor['sayi'] . ' kayıtta kullanılıyor)']);
                    break;
                }
                $db->execute("DELETE FROM HukukStatu WHERE statu_id = ?", [$id]);
                echo json_encode(['success' => true, 'message' => 'Statü silindi']);
                break;
                
            case 'cari_tipleri_list':
                $data = $db->fetchAll("SELECT cari_tipi_id, cari_tipi_ad FROM Cari_CariTipleri WHERE cari_tipi_durum = 1 ORDER BY cari_tipi_sira, cari_tipi_ad");
                echo json_encode(['success' => true, 'data' => $data]);
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
    <link href="https://cdn.jsdelivr.net/npm/sweetalert2@11/dist/sweetalert2.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css" rel="stylesheet" />
    <link href="https://cdn.jsdelivr.net/npm/select2-bootstrap-5-theme@1.3.0/dist/select2-bootstrap-5-theme.min.css" rel="stylesheet" />
    <link rel="stylesheet" href="/admin/assets/css/custom.css">
    <style>
        .info-box { transition: transform 0.2s; }
        .info-box:hover { transform: translateY(-3px); box-shadow: 0 4px 6px rgba(0,0,0,0.1); }
        .nav-tabs .nav-link.active { font-weight: bold; }
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
                    
                    <!-- Tab Navigation -->
                    <ul class="nav nav-tabs mb-3" id="hukukTabs" role="tablist">
                        <li class="nav-item" role="presentation">
                            <button class="nav-link active" id="taraflar-tab" data-bs-toggle="tab" data-bs-target="#taraflar" type="button" role="tab">
                                <i class="bi bi-people me-1"></i> Taraflar
                            </button>
                        </li>
                        <li class="nav-item" role="presentation">
                            <button class="nav-link" id="icra-tab" data-bs-toggle="tab" data-bs-target="#icra" type="button" role="tab">
                                <i class="bi bi-building me-1"></i> İcra Daireleri
                            </button>
                        </li>
                        <li class="nav-item" role="presentation">
                            <button class="nav-link" id="savcılık-tab" data-bs-toggle="tab" data-bs-target="#savcılık" type="button" role="tab">
                                <i class="bi bi-bank me-1"></i> Savcılıklar
                            </button>
                        </li>
                        <li class="nav-item" role="presentation">
                            <button class="nav-link" id="statu-tab" data-bs-toggle="tab" data-bs-target="#statu" type="button" role="tab">
                                <i class="bi bi-flag me-1"></i> Statüler
                            </button>
                        </li>
                    </ul>
                    
                    <!-- Tab Content -->
                    <div class="tab-content" id="hukukTabContent">
                        
                        <!-- ========== TARAFLAR TAB ========== -->
                        <div class="tab-pane fade show active" id="taraflar" role="tabpanel">
                            <!-- Info Boxes -->
                            <div class="row mb-3">
                                <div class="col-md-4">
                                    <div class="info-box text-bg-primary">
                                        <span class="info-box-icon"><i class="bi bi-list-check"></i></span>
                                        <div class="info-box-content">
                                            <span class="info-box-text">Toplam Taraf</span>
                                            <span class="info-box-number" id="taraf-toplam">0</span>
                                        </div>
                                    </div>
                                </div>
                                <div class="col-md-4">
                                    <div class="info-box text-bg-success">
                                        <span class="info-box-icon"><i class="bi bi-check-circle"></i></span>
                                        <div class="info-box-content">
                                            <span class="info-box-text">Aktif</span>
                                            <span class="info-box-number" id="taraf-aktif">0</span>
                                        </div>
                                    </div>
                                </div>
                                <div class="col-md-4">
                                    <div class="info-box text-bg-secondary">
                                        <span class="info-box-icon"><i class="bi bi-x-circle"></i></span>
                                        <div class="info-box-content">
                                            <span class="info-box-text">Pasif</span>
                                            <span class="info-box-number" id="taraf-pasif">0</span>
                                        </div>
                                    </div>
                                </div>
                            </div>
                            
                            <!-- Taraflar Kartı -->
                            <div class="card card-primary card-outline">
                                <div class="card-header">
                                    <h3 class="card-title"><i class="bi bi-people"></i> Taraflar</h3>
                                    <div class="card-tools">
                                        <button type="button" class="btn btn-sm btn-primary" onclick="openTarafModal()">
                                            <i class="bi bi-plus-lg"></i> Yeni Taraf
                                        </button>
                                    </div>
                                </div>
                                <div class="card-body">
                                    <div class="table-responsive">
                                        <table class="table table-bordered table-striped table-hover" id="tarafTable">
                                            <thead>
                                                <tr>
                                                    <th width="50">#</th>
                                                    <th>Taraf Adı</th>
                                                    <th>Açıklama</th>
                                                    <th width="100">Durum</th>
                                                    <th width="120">İşlemler</th>
                                                </tr>
                                            </thead>
                                            <tbody id="tarafBody">
                                                <tr><td colspan="5" class="text-center">Yükleniyor...</td></tr>
                                            </tbody>
                                        </table>
                                    </div>
                                </div>
                            </div>
                        </div>
                        
                        <!-- ========== İCRA DAİRESİ TAB ========== -->
                        <div class="tab-pane fade" id="icra" role="tabpanel">
                            <!-- Info Boxes -->
                            <div class="row mb-3">
                                <div class="col-md-4">
                                    <div class="info-box text-bg-info">
                                        <span class="info-box-icon"><i class="bi bi-building"></i></span>
                                        <div class="info-box-content">
                                            <span class="info-box-text">Toplam İcra Dairesi</span>
                                            <span class="info-box-number" id="icra-toplam">0</span>
                                        </div>
                                    </div>
                                </div>
                                <div class="col-md-4">
                                    <div class="info-box text-bg-success">
                                        <span class="info-box-icon"><i class="bi bi-check-circle"></i></span>
                                        <div class="info-box-content">
                                            <span class="info-box-text">Aktif</span>
                                            <span class="info-box-number" id="icra-aktif">0</span>
                                        </div>
                                    </div>
                                </div>
                                <div class="col-md-4">
                                    <div class="info-box text-bg-secondary">
                                        <span class="info-box-icon"><i class="bi bi-x-circle"></i></span>
                                        <div class="info-box-content">
                                            <span class="info-box-text">Pasif</span>
                                            <span class="info-box-number" id="icra-pasif">0</span>
                                        </div>
                                    </div>
                                </div>
                            </div>
                            
                            <!-- İcra Dairesi Kartı -->
                            <div class="card card-info card-outline">
                                <div class="card-header">
                                    <h3 class="card-title"><i class="bi bi-building"></i> İcra Daireleri</h3>
                                    <div class="card-tools">
                                        <button type="button" class="btn btn-sm btn-info" onclick="openIcraModal()">
                                            <i class="bi bi-plus-lg"></i> Yeni İcra Dairesi
                                        </button>
                                    </div>
                                </div>
                                <div class="card-body">
                                    <div class="table-responsive">
                                        <table class="table table-bordered table-striped table-hover" id="icraTable">
                                            <thead>
                                                <tr>
                                                    <th width="50">#</th>
                                                    <th>İcra Dairesi Adı</th>
                                                    <th>İl</th>
                                                    <th>Açıklama</th>
                                                    <th width="100">Durum</th>
                                                    <th width="120">İşlemler</th>
                                                </tr>
                                            </thead>
                                            <tbody id="icraBody">
                                                <tr><td colspan="6" class="text-center">Yükleniyor...</td></tr>
                                            </tbody>
                                        </table>
                                    </div>
                                </div>
                            </div>
                        </div>
                        
                        <!-- ========== SAVCILIKLAR TAB ========== -->
                        <div class="tab-pane fade" id="savcılık" role="tabpanel">
                            <!-- Info Boxes -->
                            <div class="row mb-3">
                                <div class="col-md-4">
                                    <div class="info-box text-bg-danger">
                                        <span class="info-box-icon"><i class="bi bi-bank"></i></span>
                                        <div class="info-box-content">
                                            <span class="info-box-text">Toplam Savcılık</span>
                                            <span class="info-box-number" id="savcılık-toplam">0</span>
                                        </div>
                                    </div>
                                </div>
                                <div class="col-md-4">
                                    <div class="info-box text-bg-success">
                                        <span class="info-box-icon"><i class="bi bi-check-circle"></i></span>
                                        <div class="info-box-content">
                                            <span class="info-box-text">Aktif</span>
                                            <span class="info-box-number" id="savcılık-aktif">0</span>
                                        </div>
                                    </div>
                                </div>
                                <div class="col-md-4">
                                    <div class="info-box text-bg-secondary">
                                        <span class="info-box-icon"><i class="bi bi-x-circle"></i></span>
                                        <div class="info-box-content">
                                            <span class="info-box-text">Pasif</span>
                                            <span class="info-box-number" id="savcılık-pasif">0</span>
                                        </div>
                                    </div>
                                </div>
                            </div>
                            
                            <!-- Savcılık Kartı -->
                            <div class="card card-danger card-outline">
                                <div class="card-header">
                                    <h3 class="card-title"><i class="bi bi-bank"></i> Savcılıklar</h3>
                                    <div class="card-tools">
                                        <button type="button" class="btn btn-sm btn-danger" onclick="openSavcilikModal()">
                                            <i class="bi bi-plus-lg"></i> Yeni Savcılık
                                        </button>
                                    </div>
                                </div>
                                <div class="card-body">
                                    <div class="table-responsive">
                                        <table class="table table-bordered table-striped table-hover" id="savcilikTable">
                                            <thead>
                                                <tr>
                                                    <th width="50">#</th>
                                                    <th>Savcılık Adı</th>
                                                    <th>İl</th>
                                                    <th>Açıklama</th>
                                                    <th width="100">Durum</th>
                                                    <th width="120">İşlemler</th>
                                                </tr>
                                            </thead>
                                            <tbody id="savcilikBody">
                                                <tr><td colspan="6" class="text-center">Yükleniyor...</td></tr>
                                            </tbody>
                                        </table>
                                    </div>
                                </div>
                            </div>
                        </div>
                        
                        <!-- ========== STATÜLER TAB ========== -->
                        <div class="tab-pane fade" id="statu" role="tabpanel">
                            <!-- Info Boxes -->
                            <div class="row mb-3">
                                <div class="col-md-4">
                                    <div class="info-box text-bg-warning">
                                        <span class="info-box-icon"><i class="bi bi-flag"></i></span>
                                        <div class="info-box-content">
                                            <span class="info-box-text">Toplam Statü</span>
                                            <span class="info-box-number" id="statu-toplam">0</span>
                                        </div>
                                    </div>
                                </div>
                                <div class="col-md-4">
                                    <div class="info-box text-bg-success">
                                        <span class="info-box-icon"><i class="bi bi-check-circle"></i></span>
                                        <div class="info-box-content">
                                            <span class="info-box-text">Aktif</span>
                                            <span class="info-box-number" id="statu-aktif">0</span>
                                        </div>
                                    </div>
                                </div>
                                <div class="col-md-4">
                                    <div class="info-box text-bg-secondary">
                                        <span class="info-box-icon"><i class="bi bi-x-circle"></i></span>
                                        <div class="info-box-content">
                                            <span class="info-box-text">Pasif</span>
                                            <span class="info-box-number" id="statu-pasif">0</span>
                                        </div>
                                    </div>
                                </div>
                            </div>
                            
                            <!-- Statüler Kartı -->
                            <div class="card card-warning card-outline">
                                <div class="card-header">
                                    <h3 class="card-title"><i class="bi bi-flag"></i> Statüler</h3>
                                    <div class="card-tools">
                                        <button type="button" class="btn btn-sm btn-warning" onclick="openStatuModal()">
                                            <i class="bi bi-plus-lg"></i> Yeni Statü
                                        </button>
                                    </div>
                                </div>
                                <div class="card-body">
                                    <div class="table-responsive">
                                        <table class="table table-bordered table-striped table-hover" id="statuTable">
                                            <thead>
                                                <tr>
                                                    <th width="50">#</th>
                                                    <th>Statü Adı</th>
                                                    <th>Cari Tipi</th>
                                                    <th width="80">Sıra</th>
                                                    <th width="100">Durum</th>
                                                    <th width="120">İşlemler</th>
                                                </tr>
                                            </thead>
                                            <tbody id="statuBody">
                                                <tr><td colspan="6" class="text-center">Yükleniyor...</td></tr>
                                            </tbody>
                                        </table>
                                    </div>
                                </div>
                            </div>
                        </div>
                        
                    </div>
                    
                </div>
            </div>
        </main>
        
        <?php include __DIR__ . '/../includes/footer.php'; ?>
    </div>
    
    <!-- Taraf Modal -->
    <div class="modal fade" id="tarafModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="tarafModalTitle">Yeni Taraf</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <form id="tarafForm">
                    <div class="modal-body">
                        <input type="hidden" name="taraf_id" id="taraf_id">
                        <div class="mb-3">
                            <label class="form-label">Taraf Adı <span class="text-danger">*</span></label>
                            <input type="text" class="form-control" name="taraf_ad" id="taraf_ad" required>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Açıklama</label>
                            <textarea class="form-control" name="taraf_aciklama" id="taraf_aciklama" rows="3"></textarea>
                        </div>
                        <div class="row g-3 mb-3">
                            <div class="col-6">
                                <label class="form-label" for="taraf_renk">Arka Plan Rengi</label>
                                <input type="color" class="form-control form-control-color w-100" name="taraf_renk" id="taraf_renk" value="#6C757D">
                            </div>
                            <div class="col-6">
                                <label class="form-label" for="taraf_yazi_renk">Yazı Rengi</label>
                                <input type="color" class="form-control form-control-color w-100" name="taraf_yazi_renk" id="taraf_yazi_renk" value="#FFFFFF">
                            </div>
                            <div class="col-12">
                                <div class="form-text">İhbar sayfasındaki yayın hakkı butonunda kullanılır.</div>
                                <span id="tarafRenkOnizleme" class="badge mt-1 px-3 py-2">Önizleme</span>
                            </div>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Durum</label>
                            <select class="form-select" name="durum" id="taraf_durum">
                                <option value="1">Aktif</option>
                                <option value="0">Pasif</option>
                            </select>
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
    
    <!-- Statü Modal -->
    <div class="modal fade" id="statuModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="statuModalTitle">Yeni Statü</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <form id="statuForm">
                    <div class="modal-body">
                        <input type="hidden" name="statu_id" id="statu_id">
                        <div class="mb-3">
                            <label class="form-label">Statü Adı <span class="text-danger">*</span></label>
                            <input type="text" class="form-control" name="statu_ad" id="statu_ad" required>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Cari Tipi <span class="text-danger">*</span></label>
                            <select class="form-select" name="statu_cari_tipi_id" id="statu_cari_tipi_id" required>
                                <option value="">Seçiniz...</option>
                            </select>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Sıra</label>
                            <input type="number" class="form-control" name="statu_sira" id="statu_sira" value="0" min="0">
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Durum</label>
                            <select class="form-select" name="durum" id="statu_durum">
                                <option value="1">Aktif</option>
                                <option value="0">Pasif</option>
                            </select>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">İptal</button>
                        <button type="submit" class="btn btn-warning">Kaydet</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
    
    <!-- Savcılık Modal -->
    <div class="modal fade" id="savcilikModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="savcilikModalTitle">Yeni Savcılık</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <form id="savcilikForm">
                    <div class="modal-body">
                        <input type="hidden" name="savcilik_id" id="savcilik_id">
                        <div class="mb-3">
                            <label class="form-label">Savcılık Adı <span class="text-danger">*</span></label>
                            <input type="text" class="form-control" name="savcilik_ad" id="savcilik_ad" required>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">İl</label>
                            <select class="form-select" name="savcilik_il" id="savcilik_il">
                                <option value="">Seçiniz...</option>
                            </select>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Açıklama</label>
                            <textarea class="form-control" name="savcilik_aciklama" id="savcilik_aciklama" rows="3"></textarea>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Durum</label>
                            <select class="form-select" name="durum" id="savcilik_durum">
                                <option value="1">Aktif</option>
                                <option value="0">Pasif</option>
                            </select>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">İptal</button>
                        <button type="submit" class="btn btn-danger">Kaydet</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
    
    <!-- İcra Modal -->
    <div class="modal fade" id="icraModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="icraModalTitle">Yeni İcra Dairesi</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <form id="icraForm">
                    <div class="modal-body">
                        <input type="hidden" name="icra_dairesi_id" id="icra_dairesi_id">
                        <div class="mb-3">
                            <label class="form-label">İcra Dairesi Adı <span class="text-danger">*</span></label>
                            <input type="text" class="form-control" name="icra_dairesi_ad" id="icra_dairesi_ad" required>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">İl</label>
                            <select class="form-select" name="icra_dairesi_il" id="icra_dairesi_il">
                                <option value="">Seçiniz...</option>
                            </select>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Açıklama</label>
                            <textarea class="form-control" name="icra_dairesi_aciklama" id="icra_dairesi_aciklama" rows="3"></textarea>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Durum</label>
                            <select class="form-select" name="durum" id="icra_durum">
                                <option value="1">Aktif</option>
                                <option value="0">Pasif</option>
                            </select>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">İptal</button>
                        <button type="submit" class="btn btn-info">Kaydet</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
    
    <script src="https://cdn.jsdelivr.net/npm/jquery@3.7.1/dist/jquery.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/@popperjs/core@2.11.8/dist/umd/popper.min.js" crossorigin="anonymous"></script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.min.js" crossorigin="anonymous"></script>
    <script src="/admin/assets/js/adminlte.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11/dist/sweetalert2.all.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js"></script>
    <script src="/admin/assets/js/custom.js"></script>
    
    <script>
        const tarafModal = new bootstrap.Modal(document.getElementById('tarafModal'));
        const icraModal = new bootstrap.Modal(document.getElementById('icraModal'));
        const savcilikModal = new bootstrap.Modal(document.getElementById('savcilikModal'));
        const statuModal = new bootstrap.Modal(document.getElementById('statuModal'));
        
        // ========== TARAFLAR ==========
        function loadTarafStats() {
            $.post('', { action: 'taraf_stats' }, function(res) {
                if (res.success) {
                    $('#taraf-toplam').text(res.data.toplam);
                    $('#taraf-aktif').text(res.data.aktif);
                    $('#taraf-pasif').text(res.data.pasif);
                }
            });
        }
        
        function loadTarafList() {
            $.post('', { action: 'taraf_list' }, function(res) {
                if (res.success) {
                    let html = '';
                    res.data.forEach((row, i) => {
                        const durumBadge = row.Durum == 1 
                            ? '<span class="badge bg-success">Aktif</span>' 
                            : '<span class="badge bg-secondary">Pasif</span>';
                        const renk = row.taraf_renk || '#6C757D';
                        const yazi = row.taraf_yazi_renk || '#FFFFFF';
                        html += `
                            <tr>
                                <td>${i + 1}</td>
                                <td>
                                    <span class="badge me-2" style="background:${renk};color:${yazi};">&nbsp;&nbsp;</span>
                                    <strong>${row.taraf_ad}</strong>
                                </td>
                                <td>${row.taraf_aciklama || '-'}</td>
                                <td>${durumBadge}</td>
                                <td>
                                    <button class="btn btn-sm btn-warning" onclick="editTaraf(${row.taraf_id})" title="Düzenle">
                                        <i class="bi bi-pencil"></i>
                                    </button>
                                    <button class="btn btn-sm btn-danger" onclick="deleteTaraf(${row.taraf_id})" title="Sil">
                                        <i class="bi bi-trash"></i>
                                    </button>
                                </td>
                            </tr>
                        `;
                    });
                    if (!html) html = '<tr><td colspan="5" class="text-center text-muted">Kayıt bulunamadı</td></tr>';
                    $('#tarafBody').html(html);
                }
            });
        }
        
        // Renk seçicilerin altındaki örnek rozeti günceller.
        function tarafRenkOnizleme() {
            $('#tarafRenkOnizleme').css({
                background: $('#taraf_renk').val(),
                color: $('#taraf_yazi_renk').val()
            });
        }
        $(document).on('input', '#taraf_renk, #taraf_yazi_renk', tarafRenkOnizleme);

        function openTarafModal(id = 0) {
            $('#tarafForm')[0].reset();
            $('#taraf_id').val(0);
            $('#taraf_renk').val('#6C757D');
            $('#taraf_yazi_renk').val('#FFFFFF');
            tarafRenkOnizleme();
            $('#tarafModalTitle').text('Yeni Taraf');
            tarafModal.show();
        }

        function editTaraf(id) {
            $.post('', { action: 'taraf_get', id: id }, function(res) {
                if (res.success) {
                    $('#taraf_id').val(res.data.taraf_id);
                    $('#taraf_ad').val(res.data.taraf_ad);
                    $('#taraf_aciklama').val(res.data.taraf_aciklama);
                    $('#taraf_renk').val(res.data.taraf_renk || '#6C757D');
                    $('#taraf_yazi_renk').val(res.data.taraf_yazi_renk || '#FFFFFF');
                    tarafRenkOnizleme();
                    $('#taraf_durum').val(res.data.Durum);
                    $('#tarafModalTitle').text('Taraf Düzenle');
                    tarafModal.show();
                } else {
                    showToast(res.message, 'error');
                }
            });
        }
        
        function deleteTaraf(id) {
            confirmAction('Bu tarafı silmek istediğinize emin misiniz?', null, function() {
                $.post('', { action: 'taraf_delete', id: id }, function(res) {
                    if (res.success) {
                        showSuccess('Silindi!', res.message);
                        loadTarafStats();
                        loadTarafList();
                    } else {
                        showError('Hata!', res.message);
                    }
                });
            });
        }
        
        $('#tarafForm').on('submit', function(e) {
            e.preventDefault();
            $.post('', { action: 'taraf_save', ...Object.fromEntries(new FormData(this)) }, function(res) {
                if (res.success) {
                    showToast(res.message, 'success');
                    tarafModal.hide();
                    loadTarafStats();
                    loadTarafList();
                } else {
                    showToast(res.message, 'error');
                }
            });
        });
        
        // ========== İCRA DAİRESİ ==========
        function loadIcraStats() {
            $.post('', { action: 'icra_stats' }, function(res) {
                if (res.success) {
                    $('#icra-toplam').text(res.data.toplam);
                    $('#icra-aktif').text(res.data.aktif);
                    $('#icra-pasif').text(res.data.pasif);
                }
            });
        }
        
        function loadIcraList() {
            $.post('', { action: 'icra_list' }, function(res) {
                if (res.success) {
                    let html = '';
                    res.data.forEach((row, i) => {
                        const durumBadge = row.Durum == 1 
                            ? '<span class="badge bg-success">Aktif</span>' 
                            : '<span class="badge bg-secondary">Pasif</span>';
                        html += `
                            <tr>
                                <td>${i + 1}</td>
                                <td><strong>${row.icra_dairesi_ad}</strong></td>
                                <td>${row.icra_dairesi_il || '-'}</td>
                                <td>${row.icra_dairesi_aciklama || '-'}</td>
                                <td>${durumBadge}</td>
                                <td>
                                    <button class="btn btn-sm btn-warning" onclick="editIcra(${row.icra_dairesi_id})" title="Düzenle">
                                        <i class="bi bi-pencil"></i>
                                    </button>
                                    <button class="btn btn-sm btn-danger" onclick="deleteIcra(${row.icra_dairesi_id})" title="Sil">
                                        <i class="bi bi-trash"></i>
                                    </button>
                                </td>
                            </tr>
                        `;
                    });
                    if (!html) html = '<tr><td colspan="6" class="text-center text-muted">Kayıt bulunamadı</td></tr>';
                    $('#icraBody').html(html);
                }
            });
        }
        
        function openIcraModal(id = 0) {
            $('#icraForm')[0].reset();
            $('#icra_dairesi_id').val(0);
            $('#icra_dairesi_il').val('').trigger('change.select2');
            $('#icraModalTitle').text('Yeni İcra Dairesi');
            icraModal.show();
        }
        
        function editIcra(id) {
            $.post('', { action: 'icra_get', id: id }, function(res) {
                if (res.success) {
                    $('#icra_dairesi_id').val(res.data.icra_dairesi_id);
                    $('#icra_dairesi_ad').val(res.data.icra_dairesi_ad);
                    $('#icra_dairesi_il').val(res.data.icra_dairesi_il).trigger('change.select2');
                    $('#icra_dairesi_aciklama').val(res.data.icra_dairesi_aciklama);
                    $('#icra_durum').val(res.data.Durum);
                    $('#icraModalTitle').text('İcra Dairesi Düzenle');
                    icraModal.show();
                } else {
                    showToast(res.message, 'error');
                }
            });
        }
        
        function deleteIcra(id) {
            confirmAction('Bu icra dairesini silmek istediğinize emin misiniz?', null, function() {
                $.post('', { action: 'icra_delete', id: id }, function(res) {
                    if (res.success) {
                        showSuccess('Silindi!', res.message);
                        loadIcraStats();
                        loadIcraList();
                    } else {
                        showError('Hata!', res.message);
                    }
                });
            });
        }
        
        $('#icraForm').on('submit', function(e) {
            e.preventDefault();
            $.post('', { action: 'icra_save', ...Object.fromEntries(new FormData(this)) }, function(res) {
                if (res.success) {
                    showToast(res.message, 'success');
                    icraModal.hide();
                    loadIcraStats();
                    loadIcraList();
                } else {
                    showToast(res.message, 'error');
                }
            });
        });
        
        // ========== SAVCILIKLAR ==========
        function loadSavcilikStats() {
            $.post('', { action: 'savcilik_stats' }, function(res) {
                if (res.success) {
                    $('#savcılık-toplam').text(res.data.toplam);
                    $('#savcılık-aktif').text(res.data.aktif);
                    $('#savcılık-pasif').text(res.data.pasif);
                }
            });
        }
        
        function loadSavcilikList() {
            $.post('', { action: 'savcilik_list' }, function(res) {
                if (res.success) {
                    let html = '';
                    res.data.forEach((row, i) => {
                        const durumBadge = row.Durum == 1 
                            ? '<span class="badge bg-success">Aktif</span>' 
                            : '<span class="badge bg-secondary">Pasif</span>';
                        html += `
                            <tr>
                                <td>${i + 1}</td>
                                <td><strong>${row.savcilik_ad}</strong></td>
                                <td>${row.savcilik_il || '-'}</td>
                                <td>${row.savcilik_aciklama || '-'}</td>
                                <td>${durumBadge}</td>
                                <td>
                                    <button class="btn btn-sm btn-warning" onclick="editSavcilik(${row.savcilik_id})" title="Düzenle">
                                        <i class="bi bi-pencil"></i>
                                    </button>
                                    <button class="btn btn-sm btn-danger" onclick="deleteSavcilik(${row.savcilik_id})" title="Sil">
                                        <i class="bi bi-trash"></i>
                                    </button>
                                </td>
                            </tr>
                        `;
                    });
                    if (!html) html = '<tr><td colspan="6" class="text-center text-muted">Kayıt bulunamadı</td></tr>';
                    $('#savcilikBody').html(html);
                }
            });
        }
        
        function openSavcilikModal(id = 0) {
            $('#savcilikForm')[0].reset();
            $('#savcilik_id').val(0);
            $('#savcilik_il').val('').trigger('change.select2');
            $('#savcilikModalTitle').text('Yeni Savcılık');
            savcilikModal.show();
        }
        
        function editSavcilik(id) {
            $.post('', { action: 'savcilik_get', id: id }, function(res) {
                if (res.success) {
                    $('#savcilik_id').val(res.data.savcilik_id);
                    $('#savcilik_ad').val(res.data.savcilik_ad);
                    $('#savcilik_il').val(res.data.savcilik_il).trigger('change.select2');
                    $('#savcilik_aciklama').val(res.data.savcilik_aciklama);
                    $('#savcilik_durum').val(res.data.Durum);
                    $('#savcilikModalTitle').text('Savcılık Düzenle');
                    savcilikModal.show();
                } else {
                    showToast(res.message, 'error');
                }
            });
        }
        
        function deleteSavcilik(id) {
            confirmAction('Bu savcılığı silmek istediğinize emin misiniz?', null, function() {
                $.post('', { action: 'savcilik_delete', id: id }, function(res) {
                    if (res.success) {
                        showSuccess('Silindi!', res.message);
                        loadSavcilikStats();
                        loadSavcilikList();
                    } else {
                        showError('Hata!', res.message);
                    }
                });
            });
        }
        
        $('#savcilikForm').on('submit', function(e) {
            e.preventDefault();
            $.post('', { action: 'savcilik_save', ...Object.fromEntries(new FormData(this)) }, function(res) {
                if (res.success) {
                    showToast(res.message, 'success');
                    savcilikModal.hide();
                    loadSavcilikStats();
                    loadSavcilikList();
                } else {
                    showToast(res.message, 'error');
                }
            });
        });
        
        // ========== STATÜLER ==========
        function loadStatuStats() {
            $.post('', { action: 'statu_stats' }, function(res) {
                if (res.success) {
                    $('#statu-toplam').text(res.data.toplam);
                    $('#statu-aktif').text(res.data.aktif);
                    $('#statu-pasif').text(res.data.pasif);
                }
            });
        }
        
        function loadStatuList() {
            $.post('', { action: 'statu_list' }, function(res) {
                if (res.success) {
                    let html = '';
                    res.data.forEach((row, i) => {
                        const durumBadge = row.Durum == 1 
                            ? '<span class="badge bg-success">Aktif</span>' 
                            : '<span class="badge bg-secondary">Pasif</span>';
                        html += `
                            <tr>
                                <td>${i + 1}</td>
                                <td><strong>${row.statu_ad}</strong></td>
                                <td>${row.cari_tipi_ad || '-'}</td>
                                <td class="text-center">${row.statu_sira ?? 0}</td>
                                <td>${durumBadge}</td>
                                <td>
                                    <button class="btn btn-sm btn-warning" onclick="editStatu(${row.statu_id})" title="Düzenle">
                                        <i class="bi bi-pencil"></i>
                                    </button>
                                    <button class="btn btn-sm btn-danger" onclick="deleteStatu(${row.statu_id})" title="Sil">
                                        <i class="bi bi-trash"></i>
                                    </button>
                                </td>
                            </tr>
                        `;
                    });
                    if (!html) html = '<tr><td colspan="6" class="text-center text-muted">Kayıt bulunamadı</td></tr>';
                    $('#statuBody').html(html);
                }
            });
        }
        
        function loadCariTipleri() {
            $.post('', { action: 'cari_tipleri_list' }, function(res) {
                if (res.success) {
                    let options = '<option value="">Seçiniz...</option>';
                    res.data.forEach(ct => {
                        options += `<option value="${ct.cari_tipi_id}">${ct.cari_tipi_ad}</option>`;
                    });
                    $('#statu_cari_tipi_id').html(options);
                }
            });
        }
        
        function openStatuModal() {
            $('#statuForm')[0].reset();
            $('#statu_id').val(0);
            $('#statu_sira').val(0);
            $('#statuModalTitle').text('Yeni Statü');
            statuModal.show();
        }
        
        function editStatu(id) {
            $.post('', { action: 'statu_get', id: id }, function(res) {
                if (res.success) {
                    $('#statu_id').val(res.data.statu_id);
                    $('#statu_ad').val(res.data.statu_ad);
                    $('#statu_sira').val(res.data.statu_sira ?? 0);
                    $('#statu_cari_tipi_id').val(res.data.statu_cari_tipi_id);
                    $('#statu_durum').val(res.data.Durum);
                    $('#statuModalTitle').text('Statü Düzenle');
                    statuModal.show();
                } else {
                    showToast(res.message, 'error');
                }
            });
        }
        
        function deleteStatu(id) {
            confirmAction('Bu statüyü silmek istediğinize emin misiniz?', null, function() {
                $.post('', { action: 'statu_delete', id: id }, function(res) {
                    if (res.success) {
                        showSuccess('Silindi!', res.message);
                        loadStatuStats();
                        loadStatuList();
                    } else {
                        showError('Hata!', res.message);
                    }
                });
            });
        }
        
        $('#statuForm').on('submit', function(e) {
            e.preventDefault();
            $.post('', { action: 'statu_save', ...Object.fromEntries(new FormData(this)) }, function(res) {
                if (res.success) {
                    showToast(res.message, 'success');
                    statuModal.hide();
                    loadStatuStats();
                    loadStatuList();
                } else {
                    showToast(res.message, 'error');
                }
            });
        });
        
        // Tab değiştiğinde ilgili verileri yükle
        $('button[data-bs-toggle="tab"]').on('shown.bs.tab', function(e) {
            const target = $(e.target).data('bs-target');
            if (target === '#taraflar') {
                loadTarafStats();
                loadTarafList();
            } else if (target === '#icra') {
                loadIcraStats();
                loadIcraList();
            } else if (target === '#savcılık') {
                loadSavcilikStats();
                loadSavcilikList();
            } else if (target === '#statu') {
                loadStatuStats();
                loadStatuList();
            }
        });
        
        // Sayfa yüklendiğinde
        function loadSehirler() {
            $.post('', { action: 'sehir_list' }, function(res) {
                if (res.success) {
                    let options = '<option value="">Seçiniz...</option>';
                    res.data.forEach(function(sehir) {
                        options += '<option value="' + sehir.SehirAdi + '">' + sehir.SehirAdi + '</option>';
                    });
                    $('#savcilik_il').html(options);
                    $('#savcilik_il').select2({
                        theme: 'bootstrap-5',
                        width: '100%',
                        placeholder: 'Seçiniz...',
                        allowClear: true,
                        dropdownParent: $('#savcilikModal'),
                        language: {
                            noResults: function() { return "Sonuç bulunamadı"; },
                            searching: function() { return "Aranıyor..."; }
                        }
                    });
                    $('#icra_dairesi_il').html(options);
                    $('#icra_dairesi_il').select2({
                        theme: 'bootstrap-5',
                        width: '100%',
                        placeholder: 'Seçiniz...',
                        allowClear: true,
                        dropdownParent: $('#icraModal'),
                        language: {
                            noResults: function() { return "Sonuç bulunamadı"; },
                            searching: function() { return "Aranıyor..."; }
                        }
                    });
                }
            });
        }

        $(document).ready(function() {
            loadTarafStats();
            loadTarafList();
            loadIcraStats();
            loadIcraList();
            loadSavcilikStats();
            loadSavcilikList();
            loadStatuStats();
            loadStatuList();
            loadCariTipleri();
            loadSehirler();
        });
    </script>
</body>
</html>
