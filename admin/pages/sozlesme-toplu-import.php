<?php
/**
 * Admin Panel - Sözleşme Toplu import
 * Excel/Tab-separated veri ile toplu sözleşme ve hareket ekleme
 */

require_once __DIR__ . '/../auth.php';
require_once __DIR__ . '/../includes/PageAuth.php';
requireAuth();

$user = Auth::user();
$db = Database::getInstance();

// Sayfa Yetki kontrolü - sozlesme-yonetimi yetkilerini kullan
$currentPagefile = 'sozlesme-yonetimi.php';
$pagePermissions = PageAuth::checkPagePermissions(
    $user['kullanici_id'],
    $user['departman_id'],
    $currentPagefile
);

if (!$pagePermissions['has_access'] || !$pagePermissions['can_add']) {
    PageAuth::accessDenied('Bu sayfaya erişim veya ekleme Yetkiniz bulunmamaktadır.');
}

// site title
$siteAyarlari = $db->fetchOne("SELECT TOP 1 site_ayarlari_site_title FROM dbo.tanim_site_ayarlari ORDER BY site_ayarlari_id DESC");
$siteTitle = $siteAyarlari['site_ayarlari_site_title'] ?? 'Örnek Yazılım Portal';

// Dropdown verileri
$sezonlar = $db->fetchAll("SELECT sezon_id, sezon_ad FROM Sozlesme_Sezonlar WHERE sezon_durum = 1 ORDER BY sezon_ad DESC");
$uyeTipleri = $db->fetchAll("SELECT uye_tipi_id, uye_tipi_ad FROM Sozlesme_UyeTipleri WHERE uye_tipi_durum = 1 ORDER BY uye_tipi_id");
$ticariGruplar = $db->fetchAll("SELECT ticari_grup_id, ticari_grup_kod, ticari_grup_ad FROM Sozlesme_TicariGruplar WHERE ticari_grup_durum = 1 ORDER BY ticari_grup_id");
$urunler = $db->fetchAll("SELECT urun_hizmet_id, urun_hizmet_adi FROM Urun_Hizmet WHERE urun_hizmet_durum = 1 ORDER BY urun_hizmet_adi");

// Tüm cariler (eşleştirme için)
$tumCariler = $db->fetchAll("SELECT cari_id, cari_adi FROM Cari ORDER BY cari_adi");

// AJAX işlemleri
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json');
    $action = $_POST['action'] ?? '';
    
    try {
        switch ($action) {
            case 'parse':
                // Ham veriyi parse et
                $rawData = $_POST['raw_data'] ?? '';
                $sezonid = intval($_POST['sezon_id'] ?? 3);
                $defaultUrunid = intval($_POST['default_urun_id'] ?? 0);
                
                if (empty(trim($rawData))) {
                    echo json_encode(['success' => false, 'message' => 'Veri girilmedi!']);
                    exit;
                }
                
                $lines = array_filter(array_map('trim', explode("\n", $rawData)));
                $results = [];
                $matchcount = 0;
                $noMatchcount = 0;
                
                foreach ($lines as $index => $line) {
                    // Tab veya çoklu boşluk ile ayir
                    // önce tab dene, yoksa 2+ boşluk ile ayir
                    if (strpos($line, "\t") !== false) {
                        $cols = preg_split('/\t+/', $line);
                    } else {
                        // Tab yoksa, 2+ boşluk ile ayir
                        $cols = preg_split('/\s{2,}/', $line);
                    }
                    
                    // En az 9 kolon olmalı
                    if (count($cols) < 9) {
                        // Debug: kolon sayisi yetersiz
                        continue;
                    }
                    
                    $uyeTipiid = trim($cols[0] ?? '');
                    $uyeNo1 = trim($cols[1] ?? '');
                    $uyeNo2 = trim($cols[2] ?? '');
                    $hareketDurum = trim($cols[3] ?? '1');
                    $ticariGrupid = trim($cols[4] ?? '');
                    $cariAdi = trim($cols[5] ?? '');
                    $pakettanim = trim($cols[6] ?? '');
                    $aktivasyontarihi = trim($cols[7] ?? '');
                    $taahutBitis = trim($cols[8] ?? '');
                    
                    // Cari eşleştirme - tam eşleşme veya benzer
                    $cariMatch = null;
                    $matchType = 'none';
                    
                    // 1. Tam eşleşme dene
                    foreach ($tumCariler as $cari) {
                        if (mb_strtolower($cari['cari_adi']) === mb_strtolower($cariAdi)) {
                            $cariMatch = $cari;
                            $matchType = 'exact';
                            break;
                        }
                    }
                    
                    // 2. Benzer eşleşme dene (LiKE)
                    if (!$cariMatch) {
                        foreach ($tumCariler as $cari) {
                            // içerik kontrolü (cari_adi verinin içinde geçiyor mu)
                            if (stripos($cari['cari_adi'], $cariAdi) !== false || stripos($cariAdi, $cari['cari_adi']) !== false) {
                                $cariMatch = $cari;
                                $matchType = 'partial';
                                break;
                            }
                        }
                    }
                    
                    // 3. E-posta ise e-posta ile eşleştir
                    if (!$cariMatch && filter_var($cariAdi, filter_VALidATE_email)) {
                        $emailCari = $db->fetchOne("SELECT cari_id, cari_adi FROM Cari WHERE cari_email = ?", [$cariAdi]);
                        if ($emailCari) {
                            $cariMatch = $emailCari;
                            $matchType = 'email';
                        }
                    }
                    
                    if ($cariMatch) {
                        $matchcount++;
                    } else {
                        $noMatchcount++;
                    }
                    
                    $results[] = [
                        'index' => $index,
                        'uye_tipi_id' => $uyeTipiid,
                        'uye_no_1' => $uyeNo1,
                        'uye_no_2' => $uyeNo2,
                        'hareket_durum' => $hareketDurum,
                        'ticari_grup_id' => $ticariGrupid,
                        'cari_adi_input' => $cariAdi,
                        'paket_tanim' => $pakettanim,
                        'aktivasyon_tarihi' => $aktivasyontarihi,
                        'taahut_bitis' => $taahutBitis,
                        'cari_id' => $cariMatch ? $cariMatch['cari_id'] : null,
                        'cari_adi_match' => $cariMatch ? $cariMatch['cari_adi'] : null,
                        'match_type' => $matchType
                    ];
                }
                
                echo json_encode([
                    'success' => true, 
                    'data' => $results,
                    'stats' => [
                        'total' => count($results),
                        'matched' => $matchcount,
                        'not_matched' => $noMatchcount
                    ]
                ]);
                break;
                
            case 'import':
                // Onaylanan verileri import et
                $importData = json_decode($_POST['import_data'] ?? '[]', true);
                $sezonid = intval($_POST['sezon_id'] ?? 3);
                $defaultUrunid = intval($_POST['default_urun_id'] ?? 0);
                $kullaniciid = intval($_POST['kullanici_id'] ?? 1);
                
                if (empty($importData)) {
                    echo json_encode(['success' => false, 'message' => 'import edilecek veri yok!']);
                    exit;
                }
                
                $successcount = 0;
                $errorcount = 0;
                $errors = [];
                
                foreach ($importData as $row) {
                    try {
                        // Cari id kontrolü
                        $cariid = $row['cari_id'] ?? null;
                        if (!$cariid) {
                            $errorcount++;
                            $errors[] = "Satır " . ($row['index'] + 1) . ": Cari eşleşmedi - " . $row['cari_adi_input'];
                            continue;
                        }
                        
                        // tarihleri dönüştür (DD.MM.YY -> YYYY-MM-DD)
                        $aktivasyontarihi = null;
                        $taahutBitis = null;
                        
                        if (!empty($row['aktivasyon_tarihi']) && $row['aktivasyon_tarihi'] !== 'SÜRESİZ') {
                            $aktivasyontarihi = convertDateFormat($row['aktivasyon_tarihi']);
                        }
                        if (!empty($row['taahut_bitis']) && $row['taahut_bitis'] !== 'SÜRESİZ') {
                            $taahutBitis = convertDateFormat($row['taahut_bitis']);
                        }
                        
                        // Sözleşme tarihi olarak aktivasyon tarihini kullan (yoksa bugün)
                        $sozlesmetarihi = $taahutBitis ?: date('Y-m-d');
                        
                        // Sözleşme oluştur ve id'yi al (OUTPUT kullanarak)
                        $insertResult = $db->fetchOne("
                            iNSERT intO Sozlesmeler (
                                sozlesme_sezon_id, 
                                sozlesme_tarih, 
                                sozlesme_cari_id,
                                sozlesme_no, 
                                sozlesme_aciklama, 
                                sozlesme_personel_id,
                                sozlesme_fatura_no, 
                                sozlesme_dosyalar, 
                                sozlesme_kullanici_id,
                                sozlesme_olusturma_tarihi, 
                                sozlesme_durum
                            ) 
                            OUTPUT iNSERTED.sozlesme_id
                            VALUES (?, ?, ?, ?, ?, NULL, '', '[]', ?, GETDATE(), 1)
                        ", [
                            $sezonid,
                            $sozlesmetarihi,
                            $cariid,
                            '', // sozlesme_no
                            $row['paket_tanim'] ?? '', // aciklama
                            $kullaniciid
                        ]);
                        
                        // Yeni id'yi al
                        $sozlesmeid = $insertResult['sozlesme_id'] ?? null;
                        
                        if (!$sozlesmeid) {
                            $errorcount++;
                            $errors[] = "Satır " . ($row['index'] + 1) . ": Sözleşme id alınamadı";
                            continue;
                        }
                        
                        // Stok hareketi ekle
                        $db->execute("
                            iNSERT intO Sozlesme_StokHareketleri (
                                hareket_sozlesme_id, 
                                hareket_uye_tipi_id, 
                                hareket_ticari_grup_id,
                                hareket_uye_no_1, 
                                hareket_uye_no_2, 
                                hareket_urun_hizmet_id,
                                hareket_fiyat, 
                                hareket_aktivasyon_tarihi, 
                                hareket_taahut_bitis,
                                hareket_durum, 
                                hareket_olusturma_tarihi
                            ) VALUES (?, ?, ?, ?, ?, ?, 0, ?, ?, ?, GETDATE())
                        ", [
                            $sozlesmeid,
                            $row['uye_tipi_id'] ?: null,
                            $row['ticari_grup_id'] ?: null,
                            $row['uye_no_1'] ?: null,
                            $row['uye_no_2'] ?: null,
                            $defaultUrunid ?: null,
                            $aktivasyontarihi,
                            $taahutBitis,
                            $row['hareket_durum'] ?? 1
                        ]);
                        
                        $successcount++;
                        
                    } catch (Exception $e) {
                        $errorcount++;
                        $errors[] = "Satır " . ($row['index'] + 1) . ": " . $e->getMessage();
                    }
                }
                
                echo json_encode([
                    'success' => true,
                    'message' => "$successcount kayit başarıyla eklendi, $errorcount hata oluştu.",
                    'stats' => [
                        'success' => $successcount,
                        'error' => $errorcount
                    ],
                    'errors' => $errors
                ]);
                break;
                
            case 'search_cari':
                // Manuel cari arama
                $search = $_POST['search'] ?? '';
                $cariler = $db->fetchAll("
                    SELECT TOP 20 cari_id, cari_adi 
                    FROM Cari 
                    WHERE cari_adi LiKE ? OR cari_email LiKE ?
                    ORDER BY cari_adi
                ", ["%$search%", "%$search%"]);
                echo json_encode(['success' => true, 'data' => $cariler]);
                break;
                
            default:
                echo json_encode(['success' => false, 'message' => 'Geçersiz işlem!']);
        }
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'message' => 'Hata: ' . $e->getMessage()]);
    }
    exit;
}

// tarih formati dönüştürme Fonksiyonu
function convertDateFormat($dateStr) {
    // DD.MM.YY veya DD.MM.YYYY formatini YYYY-MM-DD'ye çevir
    $dateStr = trim($dateStr);
    if (empty($dateStr) || $dateStr === 'SÜRESİZ') {
        return null;
    }
    
    // Farklı formatlari dene
    $formats = ['d.m.y', 'd.m.Y', 'd/m/y', 'd/m/Y'];
    foreach ($formats as $format) {
        $date = Datetime::createFromFormat($format, $dateStr);
        if ($date) {
            return $date->format('Y-m-d');
        }
    }
    
    return null;
}

$pageTitle = 'Sözleşme Toplu import';
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
    <link href="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css" rel="stylesheet" />
    <link href="https://cdn.jsdelivr.net/npm/select2-bootstrap-5-theme@1.3.0/dist/select2-bootstrap-5-theme.min.css" rel="stylesheet" />
    <link href="https://cdn.jsdelivr.net/npm/sweetalert2@11/dist/sweetalert2.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.datatables.net/1.13.7/css/dataTables.bootstrap5.min.css">
    <link rel="stylesheet" href="/Admin/assets/css/custom.css">
    <style>
        .match-exact { background-color: #d4edda !important; }
        .match-partial { background-color: #fff3cd !important; }
        .match-email { background-color: #cce5ff !important; }
        .match-none { background-color: #f8d7da !important; }
        .preview-table { font-size: 0.8rem; }
        .preview-table td, .preview-table th { padding: 0.3rem !important; vertical-align: middle; }
        .badge-match { font-size: 0.7rem; }
        #rawDatainput { font-family: monospace; font-size: 0.85rem; }
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
                                <li class="breadcrumb-item"><a href="/Admin/sozlesme-yonetimi">Sözleşme Yönetimi</a></li>
                                <li class="breadcrumb-item active"><?= htmlspecialchars($pageTitle) ?></li>
                            </ol>
                        </div>
                    </div>
                </div>
            </div>
            
            <!-- Sayfa içeriği -->
            <div class="app-content">
                <div class="container-fluid">
                    
                    <!-- Ayarlar Karti -->
                    <div class="card card-primary card-outline mb-3">
                        <div class="card-header">
                            <h3 class="card-title"><i class="bi bi-gear"></i> import Ayarlari</h3>
                        </div>
                        <div class="card-body">
                            <div class="row g-3">
                                <div class="col-md-3">
                                    <label class="form-label">Sezon <span class="text-danger">*</span></label>
                                    <select class="form-select" id="sezonid" required>
                                        <?php foreach ($sezonlar as $sezon): ?>
                                            <option value="<?= $sezon['sezon_id'] ?>" <?= $sezon['sezon_id'] == 3 ? 'selected' : '' ?>>
                                                <?= htmlspecialchars($sezon['sezon_ad']) ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div class="col-md-3">
                                    <label class="form-label">Varsayılan ürün/Hizmet</label>
                                    <select class="form-select" id="defaultUrunid">
                                        <option value="">Seçilmedi</option>
                                        <?php foreach ($urunler as $urun): ?>
                                            <option value="<?= $urun['urun_hizmet_id'] ?>">
                                                <?= htmlspecialchars($urun['urun_hizmet_adi']) ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div class="col-md-3">
                                    <label class="form-label">kullanici id</label>
                                    <input type="number" class="form-control" id="kullaniciid" value="1">
                                </div>
                                <div class="col-md-3">
                                    <label class="form-label">&nbsp;</label>
                                    <div class="d-grid">
                                        <button type="button" class="btn btn-secondary" onclick="showHelp()">
                                            <i class="bi bi-question-circle"></i> Yardim
                                        </button>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <!-- Veri giris Karti -->
                    <div class="card card-primary card-outline mb-3">
                        <div class="card-header">
                            <h3 class="card-title"><i class="bi bi-clipboard-data"></i> Veri girisi</h3>
                        </div>
                        <div class="card-body">
                            <div class="mb-3">
                                <label class="form-label">Tab-separated veriyi buraya yapıştırın:</label>
                                <textarea class="form-control" id="rawDatainput" rows="10" placeholder="Excel'den kopyaladiğiniz veriyi buraya yapıştırın...
                                
Format (Tab ile ayrılmış):
hareket_uye_tipi_id    hareket_uye_no_1    hareket_uye_no_2    hareket_durum    hareket_ticari_grup_id    cari_adi    Paket tanim    aktivasyon_tarihi    taahut_bitis"></textarea>
                            </div>
                            <div class="d-flex gap-2">
                                <button type="button" class="btn btn-primary" onclick="parseData()">
                                    <i class="bi bi-search"></i> önizle ve Eşleştir
                                </button>
                                <button type="button" class="btn btn-secondary" onclick="clearData()">
                                    <i class="bi bi-x-circle"></i> Temizle
                                </button>
                            </div>
                        </div>
                    </div>
                    
                    <!-- istatistik Kartlari -->
                    <div class="row mb-3" id="statsRow" style="display: none;">
                        <div class="col-md-3">
                            <div class="info-box text-bg-primary">
                                <span class="info-box-icon"><i class="bi bi-list-ol"></i></span>
                                <div class="info-box-content">
                                    <span class="info-box-text">Toplam Satır</span>
                                    <span class="info-box-number" id="statTotal">0</span>
                                </div>
                            </div>
                        </div>
                        <div class="col-md-3">
                            <div class="info-box text-bg-success">
                                <span class="info-box-icon"><i class="bi bi-check-circle"></i></span>
                                <div class="info-box-content">
                                    <span class="info-box-text">Eşleşen</span>
                                    <span class="info-box-number" id="statMatched">0</span>
                                </div>
                            </div>
                        </div>
                        <div class="col-md-3">
                            <div class="info-box text-bg-danger">
                                <span class="info-box-icon"><i class="bi bi-x-circle"></i></span>
                                <div class="info-box-content">
                                    <span class="info-box-text">Eşleşmeyen</span>
                                    <span class="info-box-number" id="statNotMatched">0</span>
                                </div>
                            </div>
                        </div>
                        <div class="col-md-3">
                            <div class="info-box text-bg-info">
                                <span class="info-box-icon"><i class="bi bi-check2-all"></i></span>
                                <div class="info-box-content">
                                    <span class="info-box-text">Seçili</span>
                                    <span class="info-box-number" id="statSelected">0</span>
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <!-- önizleme Tablosu -->
                    <div class="card card-primary card-outline mb-3" id="previewCard" style="display: none;">
                        <div class="card-header">
                            <h3 class="card-title"><i class="bi bi-table"></i> önizleme ve Eşleştirme</h3>
                            <div class="card-tools">
                                <button type="button" class="btn btn-sm btn-success" onclick="selectAllMatched()">
                                    <i class="bi bi-check-all"></i> Eşleşenleri Seç
                                </button>
                                <button type="button" class="btn btn-sm btn-secondary" onclick="deselectAll()">
                                    <i class="bi bi-x"></i> Seçimi Kaldır
                                </button>
                            </div>
                        </div>
                        <div class="card-body p-0">
                            <div class="table-responsive">
                                <table class="table table-sm table-bordered table-hover preview-table mb-0" id="previewTable">
                                    <thead class="table-light">
                                        <tr>
                                            <th width="30"><input type="checkbox" id="selectAll" onchange="toggleSelectAll()"></th>
                                            <th width="30">#</th>
                                            <th>üye Tipi</th>
                                            <th>üye No 1</th>
                                            <th>üye No 2</th>
                                            <th>Ticari Grup</th>
                                            <th>Girilen Cari Adi</th>
                                            <th>Eşleşen Cari</th>
                                            <th>Eşleşme</th>
                                            <th>Paket</th>
                                            <th>aktivasyon</th>
                                            <th>Taahhüt Bitiş</th>
                                            <th>işlem</th>
                                        </tr>
                                    </thead>
                                    <tbody id="previewBody">
                                    </tbody>
                                </table>
                            </div>
                        </div>
                        <div class="card-footer">
                            <button type="button" class="btn btn-success btn-lg" onclick="startimport()">
                                <i class="bi bi-cloud-upload"></i> Seçilenleri import Et
                            </button>
                            <a href="/Admin/sozlesme-yonetimi" class="btn btn-secondary btn-lg">
                                <i class="bi bi-arrow-left"></i> Geri Dön
                            </a>
                        </div>
                    </div>
                    
                </div>
            </div>
        </main>
        
        <?php include __DIR__ . '/../includes/footer.php'; ?>
    </div>
    
    <!-- Manuel Cari Seçim Modal -->
    <div class="modal fade" id="cariSelectModal" tabindex="-1">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title"><i class="bi bi-search"></i> Cari Seç</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <input type="hidden" id="modalRowindex">
                    <div class="mb-3">
                        <label class="form-label">Cari Ara:</label>
                        <input type="text" class="form-control" id="cariSearchinput" placeholder="Cari adi veya e-posta...">
                    </div>
                    <div id="cariSearchResults"></div>
                </div>
            </div>
        </div>
    </div>
    
    <!-- jQuery -->
    <script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/@popperjs/core@2.11.8/dist/umd/popper.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.7/dist/js/bootstrap.min.js"></script>
    <script src="/Admin/assets/js/Adminlte.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11/dist/sweetalert2.all.min.js"></script>
    <script src="/Admin/assets/js/custom.js"></script>
    
    <script>
        let parsedData = [];
        const uyeTipleri = <?= json_encode($uyeTipleri) ?>;
        const ticariGruplar = <?= json_encode($ticariGruplar) ?>;
        const cariSelectModal = new bootstrap.Modal(document.getElementByid('cariSelectModal'));
        
        function getUyeTipiAdi(id) {
            const tip = uyeTipleri.find(t => t.uye_tipi_id == id);
            return tip ? tip.uye_tipi_ad : id;
        }
        
        function getTicariGrupAdi(id) {
            const grup = ticariGruplar.find(g => g.ticari_grup_id == id);
            return grup ? grup.ticari_grup_ad : id;
        }
        
        function parseData() {
            const rawData = $('#rawDatainput').val().trim();
            if (!rawData) {
                showWarning('Uyarı', 'Lütfen veri girin!');
                return;
            }
            
            $.post('', {
                action: 'parse',
                raw_data: rawData,
                sezon_id: $('#sezonid').val(),
                default_urun_id: $('#defaultUrunid').val()
            }, function(response) {
                if (response.success) {
                    parsedData = response.data;
                    renderPreview();
                    updateStats(response.stats);
                    $('#statsRow, #previewCard').show();
                } else {
                    showError('Hata', response.message);
                }
            });
        }
        
        function renderPreview() {
            let html = '';
            parsedData.forEach((row, idx) => {
                const matchClass = 'match-' + row.match_type;
                const matchBadge = getMatchBadge(row.match_type);
                
                html += `
                    <tr class="${matchClass}" data-index="${idx}">
                        <td><input type="checkbox" class="row-select" data-index="${idx}" ${row.cari_id ? 'checked' : ''}></td>
                        <td>${idx + 1}</td>
                        <td>${getUyeTipiAdi(row.uye_tipi_id)}</td>
                        <td>${row.uye_no_1 || '-'}</td>
                        <td>${row.uye_no_2 || '-'}</td>
                        <td>${getTicariGrupAdi(row.ticari_grup_id)}</td>
                        <td><small>${escapeHtml(row.cari_adi_input)}</small></td>
                        <td>
                            <span class="cari-match-name">${row.cari_adi_match ? escapeHtml(row.cari_adi_match) : '<span class="text-danger">Eşleşme yok</span>'}</span>
                            <input type="hidden" class="cari-id-input" value="${row.cari_id || ''}">
                        </td>
                        <td>${matchBadge}</td>
                        <td><small>${row.paket_tanim}</small></td>
                        <td>${row.aktivasyon_tarihi}</td>
                        <td>${row.taahut_bitis}</td>
                        <td>
                            <button type="button" class="btn btn-xs btn-outline-primary" onclick="openCariSelect(${idx})">
                                <i class="bi bi-search"></i>
                            </button>
                        </td>
                    </tr>
                `;
            });
            $('#previewBody').html(html);
            updateSelectedcount();
            
            // Checkbox değişikliklerini izle
            $('.row-select').on('change', updateSelectedcount);
        }
        
        function getMatchBadge(type) {
            switch(type) {
                case 'exact': return '<span class="badge bg-success badge-match">Tam</span>';
                case 'partial': return '<span class="badge bg-warning badge-match">Kismi</span>';
                case 'email': return '<span class="badge bg-info badge-match">E-posta</span>';
                default: return '<span class="badge bg-danger badge-match">Yok</span>';
            }
        }
        
        function updateStats(stats) {
            $('#statTotal').text(stats.total);
            $('#statMatched').text(stats.matched);
            $('#statNotMatched').text(stats.not_matched);
        }
        
        function updateSelectedcount() {
            const count = $('.row-select:checked').length;
            $('#statSelected').text(count);
        }
        
        function selectAllMatched() {
            $('.row-select').each(function() {
                const idx = $(this).data('index');
                if (parsedData[idx].cari_id) {
                    $(this).prop('checked', true);
                }
            });
            updateSelectedcount();
        }
        
        function deselectAll() {
            $('.row-select').prop('checked', false);
            updateSelectedcount();
        }
        
        function toggleSelectAll() {
            const checked = $('#selectAll').prop('checked');
            $('.row-select').prop('checked', checked);
            updateSelectedcount();
        }
        
        function openCariSelect(rowindex) {
            $('#modalRowindex').val(rowindex);
            $('#cariSearchinput').val(parsedData[rowindex].cari_adi_input);
            $('#cariSearchResults').html('');
            cariSelectModal.show();
            
            // Auto search
            searchCari();
        }
        
        function searchCari() {
            const search = $('#cariSearchinput').val();
            if (!search) return;
            
            $.post('', {
                action: 'search_cari',
                search: search
            }, function(response) {
                if (response.success) {
                    let html = '<div class="list-group">';
                    response.data.forEach(cari => {
                        html += `
                            <button type="button" class="list-group-item list-group-item-action" 
                                    onclick="selectCari(${cari.cari_id}, '${escapeHtml(cari.cari_adi)}')">
                                ${escapeHtml(cari.cari_adi)}
                            </button>
                        `;
                    });
                    html += '</div>';
                    $('#cariSearchResults').html(html);
                }
            });
        }
        
        function selectCari(cariid, cariAdi) {
            const rowindex = $('#modalRowindex').val();
            
            // Update data
            parsedData[rowindex].cari_id = cariid;
            parsedData[rowindex].cari_adi_match = cariAdi;
            parsedData[rowindex].match_type = 'manual';
            
            // Update Ui
            const row = $(`tr[data-index="${rowindex}"]`);
            row.find('.cari-match-name').html(escapeHtml(cariAdi));
            row.find('.cari-id-input').val(cariid);
            row.removeClass('match-none match-exact match-partial match-email').addClass('match-exact');
            row.find('.row-select').prop('checked', true);
            
            cariSelectModal.hide();
            updateSelectedcount();
            showToast('Cari seçildi: ' + cariAdi, 'success');
        }
        
        function startimport() {
            // Seçili satirlari topla
            const selectedRows = [];
            $('.row-select:checked').each(function() {
                const idx = $(this).data('index');
                const row = parsedData[idx];
                
                // Güncel cari_id'yi al (manuel değiştirilmişse)
                row.cari_id = $(`tr[data-index="${idx}"]`).find('.cari-id-input').val();
                
                if (row.cari_id) {
                    selectedRows.push(row);
                }
            });
            
            if (selectedRows.length === 0) {
                showWarning('Uyarı', 'import edilecek kayit seçilmedi!');
                return;
            }
            
            confirmaction(
                `${selectedRows.length} kayit import edilecek`,
                'Bu işlem geri alınamaz. Devam etmek istiyor musunuz?',
                function() {
                    $.post('', {
                        action: 'import',
                        import_data: JSON.stringify(selectedRows),
                        sezon_id: $('#sezonid').val(),
                        default_urun_id: $('#defaultUrunid').val(),
                        kullanici_id: $('#kullaniciid').val()
                    }, function(response) {
                        if (response.success) {
                            let message = response.message;
                            if (response.errors && response.errors.length > 0) {
                                message += '\n\nHatalar:\n' + response.errors.slice(0, 5).join('\n');
                                if (response.errors.length > 5) {
                                    message += '\n... ve ' + (response.errors.length - 5) + ' hata daha';
                                }
                            }
                            
                            showSuccess('import Tamamlandı', message);
                            
                            // başarılı olanlari listeden kaldır
                            if (response.stats.success > 0) {
                                settimeout(function() {
                                    window.Location.href = '/Admin/sozlesme-yonetimi';
                                }, 2000);
                            }
                        } else {
                            showError('Hata', response.message);
                        }
                    });
                }
            );
        }
        
        function clearData() {
            $('#rawDatainput').val('');
            parsedData = [];
            $('#previewBody').html('');
            $('#statsRow, #previewCard').hide();
        }
        
        function showHelp() {
            Swal.fire({
                title: 'Veri Formati',
                html: `
                    <div class="text-start">
                        <p>Excel'den kopyaladiğiniz veri aşağıdaki sütunları içermelidir (Tab ile ayrılmış):</p>
                        <ol>
                            <li><strong>hareket_uye_tipi_id</strong> - üye tipi id (1, 2, vb.)</li>
                            <li><strong>hareket_uye_no_1</strong> - üye numarasi 1</li>
                            <li><strong>hareket_uye_no_2</strong> - üye numarasi 2</li>
                            <li><strong>hareket_durum</strong> - Durum (1=Aktif)</li>
                            <li><strong>hareket_ticari_grup_id</strong> - Ticari grup id</li>
                            <li><strong>cari_adi</strong> - Eşleştirilecek cari adi</li>
                            <li><strong>paket_tanim</strong> - Paket tanimi (1 YiLLiK, vb.)</li>
                            <li><strong>aktivasyon_tarihi</strong> - Format: GG.AA.YY</li>
                            <li><strong>taahut_bitis</strong> - Format: GG.AA.YY</li>
                        </ol>
                        <hr>
                        <p><strong>Eşleşme Renkleri:</strong></p>
                        <ul>
                            <li><span class="badge bg-success">Yeşil</span> - Tam eşleşme</li>
                            <li><span class="badge bg-warning">Sarı</span> - Kismi eşleşme</li>
                            <li><span class="badge bg-info">Mavi</span> - E-posta ile eşleşme</li>
                            <li><span class="badge bg-danger">Kırmızı</span> - Eşleşme yok</li>
                        </ul>
                    </div>
                `,
                icon: 'info',
                width: 600
            });
        }
        
        function escapeHtml(text) {
            if (!text) return '';
            return text.replace(/&/g, "&amp;")
                       .replace(/</g, "&lt;")
                       .replace(/>/g, "&gt;")
                       .replace(/"/g, "&quot;")
                       .replace(/'/g, "&#039;");
        }
        
        // Cari arama input
        $(document).ready(function() {
            let searchtimeout;
            $('#cariSearchinput').on('keyup', function() {
                cleartimeout(searchtimeout);
                searchtimeout = settimeout(searchCari, 300);
            });
        });
    </script>
</body>
</html>
