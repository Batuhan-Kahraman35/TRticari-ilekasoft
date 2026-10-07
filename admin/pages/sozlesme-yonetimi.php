<?php
/**
 * Admin Panel - Sozlesme Yonetimi
 * Liste sayfası - DataTables ile
 */

require_once __DIR__ . '/../auth.php';
require_once __DIR__ . '/../includes/PageAuth.php';
requireAuth();

$user = Auth::user();
$db = Database::getInstance();

// Sayfa yetki kontrolu
$currentPageFile = basename($_SERVER['PHP_SELF']);
$pagePermissions = PageAuth::checkPagePermissions(
    $user['kullanici_id'],
    $user['departman_id'],
    $currentPageFile
);

if (!$pagePermissions['has_access']) {
    PageAuth::accessDenied($pagePermissions['error'] ?? 'Bu sayfaya erisim yetkiniz bulunmamaktadır.');
}

// Sayfa bilgileri
$pageInfo = $db->fetchOne("
    SELECT s.sayfalar_sayfa_adi, s.sayfalar_aciklama, m.menuler_menu_adi as menu_adi
    FROM Menu_Sayfalar s
    LEFT JOIN Menuler m ON s.sayfalar_menu_id = m.menuler_id
    WHERE s.sayfalar_sayfa_url LIKE ? AND s.sayfalar_durum = 1
", ['%' . $currentPageFile]);

$pageTitle = $pageInfo['sayfalar_sayfa_adi'] ?? 'Sözleşme Yönetimi';
$pageDescription = $pageInfo['sayfalar_aciklama'] ?? '';
$menuAdi = $pageInfo['menu_adi'] ?? null;

// Site title
$siteAyarlari = $db->fetchOne("SELECT TOP 1 site_ayarlari_site_title FROM dbo.tanim_site_ayarlari ORDER BY site_ayarlari_id DESC");
$siteTitle = $siteAyarlari['site_ayarlari_site_title'] ?? 'Örnek Yazılım Portal';

// Dropdown verileri
$sezonlar = $db->fetchAll("SELECT sezon_id, sezon_ad FROM Sozlesme_Sezonlar WHERE sezon_durum = 1 ORDER BY sezon_ad DESC");
$cariler = $db->fetchAll("SELECT cari_id, cari_adi FROM Cari WHERE cari_aktif = 1 AND cari_tipi_id = 1 ORDER BY cari_adi");
// Pasif personel de filtrede secilebilir; pasiflik yalnizca panele girisi engeller (bkz. admin/auth.php)
$personeller = $db->fetchAll("
    SELECT kullanici_id,
           kullanici_ad + ' ' + kullanici_soyad
             + CASE WHEN ISNULL(kullanici_durum, 0) = 1 THEN '' ELSE ' (Pasif)' END AS personel_adi
    FROM kullanicilar
    ORDER BY ISNULL(kullanici_durum, 0) DESC, kullanici_ad
");
$ulkeler = $db->fetchAll("SELECT DISTINCT u.UlkeId, u.UlkeAdi FROM Adres_Ulkeler u INNER JOIN Cari c ON c.cari_ulke = u.UlkeId WHERE c.cari_aktif = 1 ORDER BY u.UlkeAdi");
$sehirler = $db->fetchAll("SELECT DISTINCT s.SehirId, s.SehirAdi, s.UlkeId FROM Adres_Sehirler s INNER JOIN Cari c ON c.cari_sehirler = s.SehirId WHERE c.cari_aktif = 1 ORDER BY s.SehirAdi");

// AJAX Islemleri
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json');
    $action = $_POST['action'] ?? '';
    
    try {
        switch ($action) {
            case 'stats':
                // Filtre parametreleri (list ile aynı)
                $sezonId    = $_POST['sezon_id']    ?? '';
                $cariId     = $_POST['cari_id']     ?? '';
                $startDate  = $_POST['start_date']  ?? '';
                $endDate    = $_POST['end_date']    ?? '';
                $durum      = $_POST['durum']       ?? '';
                $odemeDurum = $_POST['odeme_durum'] ?? '';
                $personelId = $_POST['personel_id'] ?? '';
                $ulkeId     = $_POST['ulke_id']     ?? '';
                $sehirId    = $_POST['sehir_id']    ?? '';

                $where  = " WHERE c.cari_tipi_id = 1 ";
                $params = [];
                if ($sezonId)    { $where .= " AND s.sozlesme_sezon_id = ?";    $params[] = $sezonId; }
                if ($cariId)     { $where .= " AND s.sozlesme_cari_id = ?";     $params[] = $cariId; }
                if ($personelId) { $where .= " AND s.sozlesme_personel_id = ?"; $params[] = $personelId; }
                if ($ulkeId)     { $where .= " AND c.cari_ulke = ?";            $params[] = $ulkeId; }
                if ($sehirId)    { $where .= " AND c.cari_sehirler = ?";        $params[] = $sehirId; }
                if ($startDate)  { $where .= " AND EXISTS (SELECT 1 FROM Sozlesme_StokHareketleri WHERE hareket_sozlesme_id = s.sozlesme_id AND CONVERT(date, hareket_aktivasyon_tarihi) >= ?)"; $params[] = $startDate; }
                if ($endDate)    { $where .= " AND EXISTS (SELECT 1 FROM Sozlesme_StokHareketleri WHERE hareket_sozlesme_id = s.sozlesme_id AND CONVERT(date, hareket_aktivasyon_tarihi) <= ?)"; $params[] = $endDate; }
                if ($durum !== '') { $where .= " AND s.sozlesme_durum = ?"; $params[] = $durum; }
                if ($odemeDurum === 'odendi') {
                    $where .= " AND (SELECT ISNULL(SUM(hareket_fiyat),0) FROM Sozlesme_StokHareketleri WHERE hareket_sozlesme_id=s.sozlesme_id) <= (SELECT ISNULL(SUM(odeme_tutar),0) FROM Sozlesme_Odemeler WHERE odeme_sozlesme_id=s.sozlesme_id AND odeme_yapildi=1)";
                } elseif ($odemeDurum === 'borclu') {
                    $where .= " AND (SELECT ISNULL(SUM(hareket_fiyat),0) FROM Sozlesme_StokHareketleri WHERE hareket_sozlesme_id=s.sozlesme_id) > (SELECT ISNULL(SUM(odeme_tutar),0) FROM Sozlesme_Odemeler WHERE odeme_sozlesme_id=s.sozlesme_id AND odeme_yapildi=1)";
                }

                // MSSQL'de SUM(scalar subquery) çalışmaz → LEFT JOIN ile gruplama
                $statsRow = $db->fetchOne("
                    SELECT
                        COUNT(s.sozlesme_id) as sozlesme_sayisi,
                        ISNULL(SUM(h.satis_tutar),    0) as satis_tutar,
                        ISNULL(SUM(o.tahsilat_tutar), 0) as toplam_tahsilat
                    FROM Sozlesmeler s
                    LEFT JOIN Cari c            ON s.sozlesme_cari_id  = c.cari_id
                    LEFT JOIN Adres_Ulkeler u   ON c.cari_ulke         = u.UlkeId
                    LEFT JOIN Adres_Sehirler sehir ON c.cari_sehirler  = sehir.SehirId
                    LEFT JOIN (
                        SELECT hareket_sozlesme_id, SUM(hareket_fiyat) as satis_tutar
                        FROM Sozlesme_StokHareketleri
                        GROUP BY hareket_sozlesme_id
                    ) h ON h.hareket_sozlesme_id = s.sozlesme_id
                    LEFT JOIN (
                        SELECT odeme_sozlesme_id, SUM(odeme_tutar) as tahsilat_tutar
                        FROM Sozlesme_Odemeler
                        WHERE odeme_yapildi = 1
                        GROUP BY odeme_sozlesme_id
                    ) o ON o.odeme_sozlesme_id = s.sozlesme_id
                    $where
                ", $params);

                $satisTutar      = floatval($statsRow['satis_tutar']      ?? 0);
                $toplamTahsilat  = floatval($statsRow['toplam_tahsilat']  ?? 0);
                $kalan           = $satisTutar - $toplamTahsilat;

                $stats = [
                    'sozlesme_sayisi'  => intval($statsRow['sozlesme_sayisi'] ?? 0),
                    'satis_tutar'      => number_format($satisTutar,     2, ',', '.'),
                    'toplam_tahsilat'  => number_format($toplamTahsilat, 2, ',', '.'),
                    'kalan'            => number_format($kalan,          2, ',', '.'),
                ];
                echo json_encode(['success' => true, 'data' => $stats]);
                break;
                
            case 'list':
                // Filtre parametreleri
                $sezonId = $_POST['sezon_id'] ?? '';
                $cariId = $_POST['cari_id'] ?? '';
                $startDate = $_POST['start_date'] ?? '';
                $endDate = $_POST['end_date'] ?? '';
                $durum = $_POST['durum'] ?? '';
                $odemeDurum = $_POST['odeme_durum'] ?? '';
                $personelId = $_POST['personel_id'] ?? '';
                $ulkeId = $_POST['ulke_id'] ?? '';
                $sehirId = $_POST['sehir_id'] ?? '';
                $search = $_POST['search'] ?? '';
                
                $sql = "
                    SELECT 
                        s.sozlesme_id,
                        s.sozlesme_aciklama,
                        s.sozlesme_durum,
                        s.sozlesme_dosyalar,
                        c.cari_id,
                        c.cari_adi,
                        c.cari_unvan,
                        u.UlkeAdi as cari_ulke_adi,
                        sehir.SehirAdi as cari_sehir_adi,
                        ISNULL(u.ParaBirimiSimge, '₺') as para_birimi_simge,
                        sz.sezon_ad,
                        p.kullanici_ad + ' ' + p.kullanici_soyad as personel_adi,
                        (SELECT TOP 1 ut.uye_tipi_ad FROM Sozlesme_StokHareketleri h2 LEFT JOIN Sozlesme_UyeTipleri ut ON h2.hareket_uye_tipi_id = ut.uye_tipi_id WHERE h2.hareket_sozlesme_id = s.sozlesme_id) as uye_tipi_adi,
                        (SELECT TOP 1 ISNULL(CAST(hareket_uye_no_1 AS VARCHAR(50)), '') + ' / ' + ISNULL(CAST(hareket_uye_no_2 AS VARCHAR(50)), '') FROM Sozlesme_StokHareketleri WHERE hareket_sozlesme_id = s.sozlesme_id) as uye_no,
                        (SELECT TOP 1 CONVERT(VARCHAR(10), hareket_aktivasyon_tarihi, 23) FROM Sozlesme_StokHareketleri WHERE hareket_sozlesme_id = s.sozlesme_id) as aktivasyon_tarihi,
                        (SELECT TOP 1 CONVERT(VARCHAR(10), hareket_taahut_bitis, 104) FROM Sozlesme_StokHareketleri WHERE hareket_sozlesme_id = s.sozlesme_id) as taahut_bitis,
                        (SELECT STRING_AGG(uh.urun_hizmet_kodu, ', ') FROM Sozlesme_StokHareketleri sh LEFT JOIN Urun_Hizmet uh ON sh.hareket_urun_hizmet_id = uh.urun_hizmet_id WHERE sh.hareket_sozlesme_id = s.sozlesme_id) as urun_hizmet_kodlari,
                        (SELECT ISNULL(SUM(hareket_fiyat), 0) FROM Sozlesme_StokHareketleri WHERE hareket_sozlesme_id = s.sozlesme_id) as toplam_tutar,
                        (SELECT ISNULL(SUM(odeme_tutar), 0) FROM Sozlesme_Odemeler WHERE odeme_sozlesme_id = s.sozlesme_id AND odeme_yapildi = 1) as toplam_tahsilat,
                        (SELECT COUNT(*) FROM Cari_Dosyalar WHERE cari_id = c.cari_id) as cari_dosya_sayisi
                    FROM Sozlesmeler s
                    LEFT JOIN Cari c ON s.sozlesme_cari_id = c.cari_id
                    LEFT JOIN Adres_Ulkeler u ON c.cari_ulke = u.UlkeId
                    LEFT JOIN Adres_Sehirler sehir ON c.cari_sehirler = sehir.SehirId
                    LEFT JOIN Sozlesme_Sezonlar sz ON s.sozlesme_sezon_id = sz.sezon_id
                    LEFT JOIN kullanicilar p ON s.sozlesme_personel_id = p.kullanici_id
                    WHERE c.cari_tipi_id = 1
                ";
                $params = [];
                
                if ($sezonId) {
                    $sql .= " AND s.sozlesme_sezon_id = ?";
                    $params[] = $sezonId;
                }
                if ($cariId) {
                    $sql .= " AND s.sozlesme_cari_id = ?";
                    $params[] = $cariId;
                }
                if ($personelId) {
                    $sql .= " AND s.sozlesme_personel_id = ?";
                    $params[] = $personelId;
                }
                if ($ulkeId) {
                    $sql .= " AND c.cari_ulke = ?";
                    $params[] = $ulkeId;
                }
                if ($sehirId) {
                    $sql .= " AND c.cari_sehirler = ?";
                    $params[] = $sehirId;
                }
                if ($startDate) {
                    $sql .= " AND EXISTS (SELECT 1 FROM Sozlesme_StokHareketleri WHERE hareket_sozlesme_id = s.sozlesme_id AND CONVERT(date, hareket_aktivasyon_tarihi) >= ?)";
                    $params[] = $startDate;
                }
                if ($endDate) {
                    $sql .= " AND EXISTS (SELECT 1 FROM Sozlesme_StokHareketleri WHERE hareket_sozlesme_id = s.sozlesme_id AND CONVERT(date, hareket_aktivasyon_tarihi) <= ?)";
                    $params[] = $endDate;
                }
                if ($durum !== '') {
                    $sql .= " AND s.sozlesme_durum = ?";
                    $params[] = $durum;
                }
                if ($odemeDurum === 'odendi') {
                    $sql .= " AND (SELECT ISNULL(SUM(hareket_fiyat), 0) FROM Sozlesme_StokHareketleri WHERE hareket_sozlesme_id = s.sozlesme_id) <= (SELECT ISNULL(SUM(odeme_tutar), 0) FROM Sozlesme_Odemeler WHERE odeme_sozlesme_id = s.sozlesme_id AND odeme_yapildi = 1)";
                } elseif ($odemeDurum === 'borclu') {
                    $sql .= " AND (SELECT ISNULL(SUM(hareket_fiyat), 0) FROM Sozlesme_StokHareketleri WHERE hareket_sozlesme_id = s.sozlesme_id) > (SELECT ISNULL(SUM(odeme_tutar), 0) FROM Sozlesme_Odemeler WHERE odeme_sozlesme_id = s.sozlesme_id AND odeme_yapildi = 1)";
                }
                if ($search) {
                    $sql .= " AND (s.sozlesme_fatura_no LIKE ? OR c.cari_adi LIKE ? OR s.sozlesme_aciklama LIKE ?)";
                    $params[] = "%$search%";
                    $params[] = "%$search%";
                    $params[] = "%$search%";
                }
                
                $sql .= " ORDER BY s.sozlesme_id DESC";
                
                $data = $db->fetchAll($sql, $params);
                echo json_encode(['success' => true, 'data' => $data]);
                break;

            case 'cari_dosyalar':
                $cariId = intval($_POST['cari_id'] ?? 0);
                if (!$cariId) {
                    echo json_encode(['success' => false, 'message' => 'Geçersiz işletme ID']);
                    break;
                }
                $cariBilgi = $db->fetchOne("SELECT cari_adi FROM Cari WHERE cari_id = ?", [$cariId]);
                $dosyalar = $db->fetchAll("
                    SELECT
                        dosya_id,
                        dosya_orijinal,
                        dosya_yol,
                        dosya_boyut,
                        dosya_tip,
                        CONVERT(VARCHAR(19), dosya_tarih, 104) as dosya_tarih
                    FROM Cari_Dosyalar
                    WHERE cari_id = ?
                    ORDER BY dosya_tarih DESC
                ", [$cariId]);
                echo json_encode(['success' => true, 'cari_adi' => $cariBilgi['cari_adi'] ?? '', 'data' => $dosyalar]);
                break;

            case 'delete':
                if (!$pagePermissions['can_delete']) {
                    echo json_encode(['success' => false, 'message' => 'Silme yetkiniz yok!']);
                    break;
                }
                
                $id = $_POST['id'] ?? 0;
                
                // Stok hareketlerini sil (CASCADE ile otomatik silinmeli ama guvenlik icin)
                $db->execute("DELETE FROM Sozlesme_StokHareketleri WHERE hareket_sozlesme_id = ?", [$id]);
                
                // Sozlesmeyi sil
                $db->execute("DELETE FROM Sozlesmeler WHERE sozlesme_id = ?", [$id]);
                
                echo json_encode(['success' => true, 'message' => 'Sözleşme silindi!']);
                break;
                
            case 'toggle_durum':
                $id = $_POST['id'] ?? 0;
                $durum = $_POST['durum'] ?? 1;
                
                $db->execute("UPDATE Sozlesmeler SET sozlesme_durum = ?, sozlesme_guncelleme_tarihi = GETDATE() WHERE sozlesme_id = ?", [$durum, $id]);
                
                echo json_encode(['success' => true, 'message' => 'Durum guncellendi!']);
                break;
                
            default:
                echo json_encode(['success' => false, 'message' => 'Gecersiz islem!']);
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
    <link rel="stylesheet" href="https://cdn.datatables.net/1.13.7/css/dataTables.bootstrap5.min.css">
    <link rel="stylesheet" href="/admin/assets/css/custom.css">
</head>
<body class="layout-fixed sidebar-expand-lg sidebar-open bg-body-tertiary">
    <div class="app-wrapper">
        <?php include __DIR__ . '/../includes/header.php'; ?>
        <?php include __DIR__ . '/../includes/sidebar.php'; ?>
        
        <main class="app-main">
            <!-- Sayfa Basligi -->
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
                        <div class="col-md-3">
                            <div class="info-box text-bg-primary">
                                <span class="info-box-icon"><i class="bi bi-file-earmark-text"></i></span>
                                <div class="info-box-content">
                                    <span class="info-box-text">Sözleşme Sayısı</span>
                                    <span class="info-box-number" id="stat_sozlesme_sayisi">0</span>
                                </div>
                            </div>
                        </div>
                        <div class="col-md-3">
                            <div class="info-box text-bg-success">
                                <span class="info-box-icon"><i class="bi bi-cash-coin"></i></span>
                                <div class="info-box-content">
                                    <span class="info-box-text">Satış Tutar</span>
                                    <span class="info-box-number" id="stat_satis_tutar">0,00</span>
                                </div>
                            </div>
                        </div>
                        <div class="col-md-3">
                            <div class="info-box text-bg-info">
                                <span class="info-box-icon"><i class="bi bi-check2-circle"></i></span>
                                <div class="info-box-content">
                                    <span class="info-box-text">Toplam Tahsilat</span>
                                    <span class="info-box-number" id="stat_toplam_tahsilat">0,00</span>
                                </div>
                            </div>
                        </div>
                        <div class="col-md-3">
                            <div class="info-box text-bg-warning">
                                <span class="info-box-icon"><i class="bi bi-exclamation-circle"></i></span>
                                <div class="info-box-content">
                                    <span class="info-box-text">Kalan</span>
                                    <span class="info-box-number" id="stat_kalan">0,00</span>
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
                                <button type="button" class="btn btn-tool" data-bs-toggle="collapse" data-bs-target="#filterCollapse" aria-expanded="false">
                                    <i class="bi bi-chevron-down"></i>
                                </button>
                            </div>
                        </div>
                        <div class="card-body collapse" id="filterCollapse">
                            <form id="filterForm">
                                <div class="row g-3">
                                    <div class="col-md-2">
                                        <label class="form-label">Sezon</label>
                                        <select class="form-select" id="filter_sezon_id" name="sezon_id">
                                            <option value="">Tumu</option>
                                            <?php foreach ($sezonlar as $sezon): ?>
                                                <option value="<?= $sezon['sezon_id'] ?>"><?= htmlspecialchars($sezon['sezon_ad']) ?></option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                    <div class="col-md-2">
                                        <label class="form-label">Müşteri</label>
                                        <select class="form-select" id="filter_cari_id" name="cari_id">
                                            <option value="">Tümü</option>
                                            <?php foreach ($cariler as $cari): ?>
                                                <option value="<?= $cari['cari_id'] ?>"><?= htmlspecialchars($cari['cari_adi']) ?></option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                    <div class="col-md-2">
                                        <label class="form-label">Aktivasyon Başlangıç</label>
                                        <input type="date" class="form-control" id="filter_start_date" name="start_date">
                                    </div>
                                    <div class="col-md-2">
                                        <label class="form-label">Aktivasyon Bitiş</label>
                                        <input type="date" class="form-control" id="filter_end_date" name="end_date">
                                    </div>
                                    <div class="col-md-2">
                                        <label class="form-label">Durum</label>
                                        <select class="form-select" id="filter_durum" name="durum">
                                            <option value="">Tümü</option>
                                            <option value="1">Aktif</option>
                                            <option value="0">Pasif</option>
                                        </select>
                                    </div>
                                    <div class="col-md-2">
                                        <label class="form-label">Ödeme Durumu</label>
                                        <select class="form-select" id="filter_odeme_durum" name="odeme_durum">
                                            <option value="">Tümü</option>
                                            <option value="odendi">Ödendi</option>
                                            <option value="borclu">Borçlu</option>
                                        </select>
                                    </div>
                                    <div class="col-md-2">
                                        <label class="form-label">Personel</label>
                                        <select class="form-select" id="filter_personel_id" name="personel_id">
                                            <option value="">Tümü</option>
                                            <?php foreach ($personeller as $p): ?>
                                                <option value="<?= $p['kullanici_id'] ?>"><?= htmlspecialchars($p['personel_adi']) ?></option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                    <div class="col-md-2">
                                        <label class="form-label">Ülke</label>
                                        <select class="form-select" id="filter_ulke_id" name="ulke_id">
                                            <option value="">Tümü</option>
                                            <?php foreach ($ulkeler as $ulke): ?>
                                                <option value="<?= $ulke['UlkeId'] ?>"><?= htmlspecialchars($ulke['UlkeAdi']) ?></option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                    <div class="col-md-2">
                                        <label class="form-label">Şehir</label>
                                        <select class="form-select" id="filter_sehir_id" name="sehir_id">
                                            <option value="">Tümü</option>
                                            <?php foreach ($sehirler as $sehir): ?>
                                                <option value="<?= $sehir['SehirId'] ?>"><?= htmlspecialchars($sehir['SehirAdi']) ?></option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
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
                    
                    <!-- Ana Tablo Karti -->
                    <div class="card card-primary card-outline">
                        <div class="card-header">
                            <h3 class="card-title">
                                <i class="bi bi-list-ul"></i> Sözleşme Listesi
                            </h3>
                            <div class="card-tools">
                                <button type="button" class="btn btn-sm btn-success" id="btnExcelExport">
                                    <i class="bi bi-file-earmark-excel"></i> Excel
                                </button>
                                <?php if ($pagePermissions['can_add']): ?>
                                <a href="form?cari_tipi_id=1" class="btn btn-sm btn-primary ms-1">
                                    <i class="bi bi-plus-lg"></i> Yeni Sözleşme
                                </a>
                                <?php endif; ?>
                            </div>
                        </div>
                        <div class="card-body">
                            <div class="table-responsive">
                                <table id="sozlesmelerTable" class="table table-bordered table-striped table-hover">
                                    <thead>
                                        <tr>
                                            <th>Sezon</th>
                                            <th>Müşteri</th>
                                            <th>Ülke / Şehir</th>
                                            <th>Uye No</th>
                                            <th>Aktivasyon</th>
                                            <th width="100">Satış Tutar</th>
                                            <th width="110">Toplam Tahsilat</th>
                                            <th width="100">Kalan Tutar</th>
                                            <th width="70">Durum</th>
                                            <th width="120">Islemler</th>
                                        </tr>
                                    </thead>
                                    <tbody></tbody>
                                </table>
                            </div>
                        </div>
                    </div>
                    
                </div>
            </div>
        </main>

        <?php include __DIR__ . '/../includes/footer.php'; ?>
    </div>

    <!-- İşletme Evrakları Modal -->
    <div class="modal fade" id="cariDosyalarModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-lg modal-dialog-scrollable">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title"><i class="bi bi-paperclip"></i> <span id="cariDosyalarBaslik">İşletme Evrakları</span></h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Kapat"></button>
                </div>
                <div class="modal-body" id="cariDosyalarBody">
                    <div class="text-center py-4"><span class="spinner-border text-primary"></span></div>
                </div>
            </div>
        </div>
    </div>
    
    <script src="https://cdn.jsdelivr.net/npm/jquery@3.7.1/dist/jquery.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/overlayscrollbars@2.10.1/browser/overlayscrollbars.browser.es6.min.js"></script>
    <script src="/admin/assets/js/adminlte.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11/dist/sweetalert2.all.min.js"></script>
    <script src="https://cdn.datatables.net/1.13.7/js/jquery.dataTables.min.js"></script>
    <script src="https://cdn.datatables.net/1.13.7/js/dataTables.bootstrap5.min.js"></script>
    <script src="/admin/assets/js/custom.js"></script>
    
    <script>
    let table;
    let currentFilters = {};
    
    $(document).ready(function() {
        // Select2 init
        $('#filter_sezon_id, #filter_cari_id, #filter_durum, #filter_odeme_durum, #filter_personel_id, #filter_ulke_id, #filter_sehir_id').select2({
            theme: 'bootstrap-5',
            width: '100%',
            allowClear: true,
            placeholder: 'Seciniz...'
        });
        
        // DataTable init
        table = $('#sozlesmelerTable').DataTable({
            processing: true,
            stateSave: true,
            serverSide: false,
            ajax: {
                url: '',
                type: 'POST',
                data: function(d) {
                    return {
                        action: 'list',
                        ...currentFilters
                    };
                },
                dataSrc: function(json) {
                    return json.success ? json.data : [];
                }
            },
            columns: [
                { 
                    data: 'sezon_ad',
                    render: function(data) {
                        return data || '-';
                    }
                },
                { 
                    data: null,
                    render: function(data) {
                        if (!data.cari_adi || !data.cari_id) return '-';
                        let cari = '<a href="cari-form?id=' + data.cari_id + '" target="_blank" class="text-primary text-decoration-none">' + data.cari_adi + '</a>';
                        if (data.cari_unvan) {
                            cari += ' <small class="text-muted">(' + data.cari_unvan + ')</small>';
                        }
                        if (parseInt(data.cari_dosya_sayisi) > 0) {
                            cari += ' <a href="javascript:void(0)" onclick="showCariDosyalar(' + data.cari_id + ')" title="' + data.cari_dosya_sayisi + ' evrak - görüntülemek için tıklayın" class="text-info"><i class="bi bi-paperclip"></i><sup>' + data.cari_dosya_sayisi + '</sup></a>';
                        }
                        return cari;
                    }
                },
                { 
                    data: null,
                    render: function(data) {
                        let parts = [];
                        if (data.cari_ulke_adi) parts.push(data.cari_ulke_adi);
                        if (data.cari_sehir_adi) parts.push(data.cari_sehir_adi);
                        return parts.length ? parts.join(' / ') : '-';
                    }
                },
                { 
                    data: 'uye_no',
                    render: function(data) {
                        return data || '-';
                    }
                },
                { 
                    data: 'aktivasyon_tarihi',
                    render: function(data, type) {
                        if (!data) return '-';
                        if (type === 'sort' || type === 'type') return data; // YYYY-MM-DD ile dogru sıralama
                        // display: DD.MM.YYYY
                        const p = data.split('-');
                        return p.length === 3 ? p[2] + '.' + p[1] + '.' + p[0] : data;
                    }
                },
                { 
                    data: 'toplam_tutar',
                    className: 'text-end',
                    render: function(data, type, row) {
                        return formatCurrency(data, row.para_birimi_simge);
                    }
                },
                { 
                    data: 'toplam_tahsilat',
                    className: 'text-end',
                    render: function(data, type, row) {
                        return formatCurrency(data, row.para_birimi_simge);
                    }
                },
                { 
                    data: null,
                    className: 'text-end',
                    render: function(data, type, row) {
                        const kalan = parseFloat(row.toplam_tutar || 0) - parseFloat(row.toplam_tahsilat || 0);
                        if (type === 'sort' || type === 'type') return kalan;
                        const cls = kalan > 0 ? 'text-danger fw-bold' : 'text-success';
                        return '<span class="' + cls + '">' + formatCurrency(kalan, row.para_birimi_simge) + '</span>';
                    }
                },
                { 
                    data: 'sozlesme_durum',
                    className: 'text-center',
                    render: function(data, type, row) {
                        if (data == 1) {
                            return '<span class="badge bg-success cursor-pointer" onclick="toggleDurum(' + row.sozlesme_id + ', 0)" title="Pasif yap">Aktif</span>';
                        } else {
                            return '<span class="badge bg-warning cursor-pointer" onclick="toggleDurum(' + row.sozlesme_id + ', 1)" title="Aktif yap">Pasif</span>';
                        }
                    }
                },
                { 
                    data: null,
                    orderable: false,
                    render: function(data) {
                        let btns = '';
                        
                        <?php if ($pagePermissions['can_edit']): ?>
                        btns += '<a href="form?id=' + data.sozlesme_id + '&cari_tipi_id=1" class="btn btn-sm btn-info" title="Duzenle"><i class="bi bi-pencil"></i></a> ';
                        <?php endif; ?>
                        
                        <?php if ($pagePermissions['can_delete']): ?>
                        btns += '<button class="btn btn-sm btn-danger" onclick="deleteSozlesme(' + data.sozlesme_id + ')" title="Sil"><i class="bi bi-trash"></i></button>';
                        <?php endif; ?>
                        
                        return btns;
                    }
                }
            ],
            order: [[7, 'desc']],
            createdRow: function(row, data) {
                const kalan = parseFloat(data.toplam_tutar || 0) - parseFloat(data.toplam_tahsilat || 0);
                if (kalan <= 0) {
                    $(row).addClass('table-success');
                } else {
                    $(row).addClass('table-danger');
                }
            },
            language: {
                url: 'https://cdn.datatables.net/plug-ins/1.13.7/i18n/tr.json'
            },
            pageLength: 25
        });
        
        // Stats yukle
        loadStats();
        
        // Excel export
        $('#btnExcelExport').on('click', function() {
            const data = table.rows({ search: 'applied' }).data().toArray();
            if (data.length === 0) {
                showToast('Disa aktarılacak veri yok', 'warning');
                return;
            }
            
            let csv = [];
            // Baslik satiri
            csv.push(['Sezon', 'Cari', 'Unvan', 'Ulke/Sehir', 'Uye No', 'Aktivasyon', 'Satis Tutar', 'Toplam Tahsilat', 'Kalan Tutar', 'Durum'].map(h => '"' + h + '"').join(';'));
            
            // Veri satirlari
            data.forEach(row => {
                csv.push([
                    row.sezon_ad || '-',
                    row.cari_adi || '-',
                    row.cari_unvan || '-',
                    [row.cari_ulke_adi, row.cari_sehir_adi].filter(Boolean).join(' / ') || '-',
                    row.uye_no || '-',
                    row.aktivasyon_tarihi ? row.aktivasyon_tarihi.split('-').reverse().join('.') : '-',
                    row.toplam_tutar ? parseFloat(row.toplam_tutar).toLocaleString('tr-TR', { minimumFractionDigits: 2 }) + ' ' + (row.para_birimi_simge || '₺') : '0,00 ₺',
                    row.toplam_tahsilat ? parseFloat(row.toplam_tahsilat).toLocaleString('tr-TR', { minimumFractionDigits: 2 }) + ' ' + (row.para_birimi_simge || '₺') : '0,00 ₺',
                    (parseFloat(row.toplam_tutar || 0) - parseFloat(row.toplam_tahsilat || 0)).toLocaleString('tr-TR', { minimumFractionDigits: 2 }) + ' ' + (row.para_birimi_simge || '₺'),
                    row.sozlesme_durum == 1 ? 'Aktif' : 'Pasif'
                ].map(c => '"' + String(c).replace(/"/g, '""') + '"').join(';'));
            });
            
            const csvContent = '\uFEFF' + csv.join('\n');
            const blob = new Blob([csvContent], { type: 'text/csv;charset=utf-8;' });
            const url = URL.createObjectURL(blob);
            const a = document.createElement('a');
            a.href = url;
            a.download = 'sozlesme-listesi-' + new Date().toISOString().slice(0,10) + '.csv';
            a.click();
            URL.revokeObjectURL(url);
            showToast('Excel dosyasi indirildi', 'success');
        });
        
        // Filtre form submit
        $('#filterForm').on('submit', function(e) {
            e.preventDefault();
            currentFilters = {
                sezon_id: $('#filter_sezon_id').val(),
                cari_id: $('#filter_cari_id').val(),
                start_date: $('#filter_start_date').val(),
                end_date: $('#filter_end_date').val(),
                durum: $('#filter_durum').val(),
                odeme_durum: $('#filter_odeme_durum').val(),
                personel_id: $('#filter_personel_id').val(),
                ulke_id: $('#filter_ulke_id').val(),
                sehir_id: $('#filter_sehir_id').val()
            };
            table.ajax.reload();
            loadStats();
            showToast('Filtre uygulandı', 'info');
        });
        
        // Filtreleri temizle
        $('#clearFilters').on('click', function() {
            $('#filterForm')[0].reset();
            $('#filter_sezon_id').val('').trigger('change.select2');
            $('#filter_cari_id').val('').trigger('change.select2');
            $('#filter_durum').val('').trigger('change.select2');
            $('#filter_odeme_durum').val('').trigger('change.select2');
            $('#filter_personel_id').val('').trigger('change.select2');
            $('#filter_ulke_id').val('').trigger('change.select2');
            $('#filter_sehir_id').val('').trigger('change.select2');
            currentFilters = {};
            table.ajax.reload();
            loadStats();
            showToast('Filtreler temizlendi', 'info');
        });
    });
    
    // Istatistikleri yukle (filtreye bağlı)
    function loadStats() {
        $.post('', Object.assign({ action: 'stats' }, currentFilters), function(response) {
            if (response.success) {
                $('#stat_sozlesme_sayisi').text(response.data.sozlesme_sayisi);
                $('#stat_satis_tutar').text(response.data.satis_tutar);
                $('#stat_toplam_tahsilat').text(response.data.toplam_tahsilat);
                $('#stat_kalan').text(response.data.kalan);
            }
        });
    }
    
    // Durum toggle
    function toggleDurum(id, durum) {
        $.post('', { action: 'toggle_durum', id: id, durum: durum }, function(response) {
            if (response.success) {
                table.ajax.reload(null, false);
                loadStats();
                showToast(response.message, 'success');
            } else {
                showToast(response.message, 'error');
            }
        });
    }
    
    // Sozlesme sil
    function deleteSozlesme(id) {
        confirmAction(
            'Bu sözleşmeyi silmek istediğinize emin misiniz?',
            'Sozlesmeye ait tum stok hareketleri de silinecek!',
            function() {
                $.post('', { action: 'delete', id: id }, function(response) {
                    if (response.success) {
                        showSuccess('Silindi!', response.message);
                        table.ajax.reload(null, false);
                        loadStats();
                    } else {
                        showError('Hata!', response.message);
                    }
                });
            }
        );
    }
    
    // İşletme evraklarını modalda göster
    function showCariDosyalar(cariId) {
        const modal = new bootstrap.Modal(document.getElementById('cariDosyalarModal'));
        $('#cariDosyalarBaslik').text('İşletme Evrakları');
        $('#cariDosyalarBody').html('<div class="text-center py-4"><span class="spinner-border text-primary"></span></div>');
        modal.show();

        $.post('', { action: 'cari_dosyalar', cari_id: cariId }, function(res) {
            if (!res.success) {
                $('#cariDosyalarBody').html('<div class="alert alert-danger mb-0">' + (res.message || 'Evraklar yüklenemedi') + '</div>');
                return;
            }
            if (res.cari_adi) $('#cariDosyalarBaslik').text(res.cari_adi + ' - Evraklar');

            if (!res.data || res.data.length === 0) {
                $('#cariDosyalarBody').html('<div class="alert alert-info mb-0">Bu işletmeye ait evrak bulunmuyor.</div>');
                return;
            }

            const gorseller = res.data.filter(d => (d.dosya_tip || '').indexOf('image/') === 0);
            const belgeler  = res.data.filter(d => (d.dosya_tip || '').indexOf('image/') !== 0);
            let html = '';

            // Görseller - thumbnail galeri
            if (gorseller.length) {
                html += '<h6 class="mb-2"><i class="bi bi-images"></i> Görüntüler</h6><div class="row g-2 mb-3">';
                gorseller.forEach(d => {
                    html += '<div class="col-6 col-md-3 col-lg-2">'
                          + '<a href="' + d.dosya_yol + '" target="_blank" title="' + (d.dosya_orijinal || '') + '">'
                          + '<img src="' + d.dosya_yol + '" class="img-fluid rounded border" style="height:110px;width:100%;object-fit:cover;" '
                          + 'onerror="this.parentNode.innerHTML=\'<div class=&quot;border rounded d-flex align-items-center justify-content-center text-muted&quot; style=&quot;height:110px&quot;><i class=&quot;bi bi-image&quot;></i></div>\'">'
                          + '</a></div>';
                });
                html += '</div>';
            }

            // Diğer belgeler - liste
            if (belgeler.length) {
                html += '<h6 class="mb-2"><i class="bi bi-file-earmark-text"></i> Belgeler</h6><div class="list-group">';
                belgeler.forEach(d => {
                    const ikon = (d.dosya_tip || '').indexOf('pdf') !== -1 ? 'bi-file-earmark-pdf text-danger'
                               : (d.dosya_tip || '').indexOf('word') !== -1 ? 'bi-file-earmark-word text-primary'
                               : (d.dosya_tip || '').indexOf('sheet') !== -1 || (d.dosya_tip || '').indexOf('excel') !== -1 ? 'bi-file-earmark-excel text-success'
                               : 'bi-file-earmark';
                    const boyutKb = d.dosya_boyut ? (parseInt(d.dosya_boyut) / 1024).toFixed(0) + ' KB' : '';
                    html += '<a href="' + d.dosya_yol + '" target="_blank" class="list-group-item list-group-item-action d-flex justify-content-between align-items-center">'
                          + '<span><i class="bi ' + ikon + ' me-2"></i>' + (d.dosya_orijinal || 'Belge') + '</span>'
                          + '<small class="text-muted">' + [d.dosya_tarih, boyutKb].filter(Boolean).join(' · ') + '</small>'
                          + '</a>';
                });
                html += '</div>';
            }

            $('#cariDosyalarBody').html(html);
        }, 'json').fail(function() {
            $('#cariDosyalarBody').html('<div class="alert alert-danger mb-0">Sunucu hatası, evraklar yüklenemedi.</div>');
        });
    }

    // Para formatla (para birimi simgesiyle)
    function formatCurrency(value, simge) {
        simge = simge || '₺';
        if (!value) return '0,00 ' + simge;
        return parseFloat(value).toLocaleString('tr-TR', { minimumFractionDigits: 2, maximumFractionDigits: 2 }) + ' ' + simge;
    }
    </script>
</body>
</html>
