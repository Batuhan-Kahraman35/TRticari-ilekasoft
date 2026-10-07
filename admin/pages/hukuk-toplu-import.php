<?php
/**
 * Hukuk Takip - Toplu Import
 * Excel/CSV dosyasından toplu dosya yükleme
 */

require_once __DIR__ . '/../auth.php';
require_once __DIR__ . '/../includes/PageAuth.php';
requireAuth();

$user = Auth::user();
$db = Database::getInstance();

$siteAyarlari = $db->fetchOne("SELECT TOP 1 site_ayarlari_site_title FROM dbo.tanim_site_ayarlari ORDER BY site_ayarlari_id DESC");
$siteTitle = $siteAyarlari['site_ayarlari_site_title'] ?? 'Portal';

$pageTitle = 'Hukuk Takip - Toplu Import';
$pageDescription = 'Excel/CSV dosyasından toplu hukuk dosyası yükleme';

// Mevcut veriler (eşleştirme için)
$taraflar = $db->fetchAll("SELECT taraf_id, taraf_ad FROM HukukTaraflar WHERE Durum = 1");
$icraDaireleri = $db->fetchAll("SELECT icra_dairesi_id, icra_dairesi_ad FROM HukukIcraDairesi WHERE Durum = 1");
$sehirler = $db->fetchAll("SELECT SehirId, SehirAdi FROM Adres_Sehirler WHERE UlkeId = 1");
$ilceler = $db->fetchAll("SELECT ilceId, IlceAdi, SehirId FROM Adres_Ilceler");
$musteriler = $db->fetchAll("SELECT cari_id, cari_adi FROM Cari WHERE cari_tipi_id = 3 AND cari_aktif = 1");

// AJAX İşlemleri
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json');
    $action = $_POST['action'] ?? '';
    
    try {
        switch ($action) {
            case 'parse_data':
                // Client-side'dan gelen JSON data
                $rawData = $_POST['data'] ?? '';
                if (empty($rawData)) {
                    echo json_encode(['success' => false, 'message' => 'Veri alınamadı']);
                    break;
                }
                
                $data = json_decode($rawData, true);
                if (empty($data) || !is_array($data)) {
                    echo json_encode(['success' => false, 'message' => 'Dosyada geçerli veri bulunamadı']);
                    break;
                }
                
                // Verileri doğrula ve eşleştir
                $validatedData = [];
                $errors = [];
                
                foreach ($data as $i => $row) {
                    $rowNum = $i + 2; // Excel satır numarası (başlık + 0-index)
                    $validated = [
                        'row_num' => $rowNum,
                        'taraf' => $row['taraf'],
                        'taraf_id' => null,
                        'taraf_status' => 'new', // new, found
                        'icra_dairesi' => $row['icra_dairesi'],
                        'icra_dairesi_id' => null,
                        'icra_dairesi_status' => 'new',
                        'dosya_no' => $row['dosya_no'],
                        'musteri_adi' => $row['musteri_adi'],
                        'musteri_id' => null,
                        'musteri_status' => 'new',
                        'aciklama' => $row['aciklama'],
                        'ana_tutar' => $row['ana_tutar'],
                        'dosya_acilis_tarihi' => $row['dosya_acilis_tarihi'] ?? '',
                        'sehir' => $row['sehir'],
                        'sehir_id' => null,
                        'sehir_status' => 'not_found',
                        'ilce' => $row['ilce'],
                        'ilce_id' => null,
                        'ilce_status' => 'not_found',
                        'has_error' => false,
                        'error_message' => ''
                    ];
                    
                    // Taraf eşleştir
                    foreach ($taraflar as $t) {
                        if (mb_strtolower(trim($t['taraf_ad'])) === mb_strtolower($row['taraf'])) {
                            $validated['taraf_id'] = $t['taraf_id'];
                            $validated['taraf_status'] = 'found';
                            break;
                        }
                    }
                    
                    // İcra Dairesi eşleştir
                    foreach ($icraDaireleri as $ic) {
                        if (mb_strtolower(trim($ic['icra_dairesi_ad'])) === mb_strtolower($row['icra_dairesi'])) {
                            $validated['icra_dairesi_id'] = $ic['icra_dairesi_id'];
                            $validated['icra_dairesi_status'] = 'found';
                            break;
                        }
                    }
                    
                    // Müşteri eşleştir
                    foreach ($musteriler as $m) {
                        if (mb_strtolower(trim($m['cari_adi'])) === mb_strtolower($row['musteri_adi'])) {
                            $validated['musteri_id'] = $m['cari_id'];
                            $validated['musteri_status'] = 'found';
                            break;
                        }
                    }
                    
                    // Şehir eşleştir
                    foreach ($sehirler as $s) {
                        if (mb_strtolower(trim($s['SehirAdi'])) === mb_strtolower(trim($row['sehir']))) {
                            $validated['sehir_id'] = $s['SehirId'];
                            $validated['sehir_status'] = 'found';
                            break;
                        }
                    }
                    
                    // İlçe eşleştir (şehir bulunduysa)
                    if ($validated['sehir_id']) {
                        foreach ($ilceler as $il) {
                            if ($il['SehirId'] == $validated['sehir_id'] && 
                                mb_strtolower(trim($il['IlceAdi'])) === mb_strtolower(trim($row['ilce']))) {
                                $validated['ilce_id'] = $il['ilceId'];
                                $validated['ilce_status'] = 'found';
                                break;
                            }
                        }
                    }
                    
                    // Hata kontrolü
                    if (empty($row['dosya_no'])) {
                        $validated['has_error'] = true;
                        $validated['error_message'] = 'Dosya No boş olamaz';
                    }
                    if (empty($row['musteri_adi'])) {
                        $validated['has_error'] = true;
                        $validated['error_message'] = 'Müşteri adı boş olamaz';
                    }
                    
                    $validatedData[] = $validated;
                }
                
                // Özet istatistikler
                $summary = [
                    'total' => count($validatedData),
                    'new_taraf' => count(array_filter($validatedData, fn($r) => $r['taraf_status'] === 'new' && !empty($r['taraf']))),
                    'new_icra' => count(array_filter($validatedData, fn($r) => $r['icra_dairesi_status'] === 'new' && !empty($r['icra_dairesi']))),
                    'new_musteri' => count(array_filter($validatedData, fn($r) => $r['musteri_status'] === 'new' && !empty($r['musteri_adi']))),
                    'errors' => count(array_filter($validatedData, fn($r) => $r['has_error']))
                ];
                
                // Session'a kaydet (import için)
                $_SESSION['import_data'] = $validatedData;
                
                echo json_encode([
                    'success' => true, 
                    'data' => $validatedData,
                    'summary' => $summary
                ]);
                break;
                
            case 'import':
                if (!isset($_SESSION['import_data']) || empty($_SESSION['import_data'])) {
                    echo json_encode(['success' => false, 'message' => 'Import verisi bulunamadı. Lütfen önce dosya yükleyin.']);
                    break;
                }
                
                $importData = $_SESSION['import_data'];
                $imported = 0;
                $skipped = 0;
                $newTaraflar = [];
                $newIcraDaireleri = [];
                $newMusteriler = [];
                
                foreach ($importData as $row) {
                    if ($row['has_error']) {
                        $skipped++;
                        continue;
                    }
                    
                    // Taraf oluştur (yoksa)
                    $tarafId = $row['taraf_id'];
                    if (!$tarafId && !empty($row['taraf'])) {
                        $tarafKey = mb_strtolower($row['taraf']);
                        if (isset($newTaraflar[$tarafKey])) {
                            $tarafId = $newTaraflar[$tarafKey];
                        } else {
                            $db->execute("INSERT INTO HukukTaraflar (taraf_ad, Durum, OlusturanKullanici, OlusturmaTarihi) VALUES (?, 1, ?, GETDATE())", 
                                [$row['taraf'], $user['kullanici_id']]);
                            $result = $db->fetchOne("SELECT MAX(taraf_id) as id FROM HukukTaraflar");
                            $tarafId = $result['id'];
                            $newTaraflar[$tarafKey] = $tarafId;
                        }
                    }
                    
                    // İcra Dairesi oluştur (yoksa)
                    $icraDairesiId = $row['icra_dairesi_id'];
                    if (!$icraDairesiId && !empty($row['icra_dairesi'])) {
                        $icraKey = mb_strtolower($row['icra_dairesi']);
                        if (isset($newIcraDaireleri[$icraKey])) {
                            $icraDairesiId = $newIcraDaireleri[$icraKey];
                        } else {
                            $db->execute("INSERT INTO HukukIcraDairesi (icra_dairesi_ad, Durum, OlusturanKullanici, OlusturmaTarihi) VALUES (?, 1, ?, GETDATE())", 
                                [$row['icra_dairesi'], $user['kullanici_id']]);
                            $result = $db->fetchOne("SELECT MAX(icra_dairesi_id) as id FROM HukukIcraDairesi");
                            $icraDairesiId = $result['id'];
                            $newIcraDaireleri[$icraKey] = $icraDairesiId;
                        }
                    }
                    
                    // Müşteri oluştur (yoksa)
                    $musteriId = $row['musteri_id'];
                    if (!$musteriId && !empty($row['musteri_adi'])) {
                        $musteriKey = mb_strtolower($row['musteri_adi']);
                        if (isset($newMusteriler[$musteriKey])) {
                            $musteriId = $newMusteriler[$musteriKey];
                        } else {
                            $db->execute("
                                INSERT INTO Cari (cari_adi, cari_tipi_id, cari_aktif, cari_musteri, cari_tedarikci, cari_ulke, cari_sehirler, cari_ilceler, cari_olusturan_kullanici, cari_olusturma_tarihi) 
                                VALUES (?, 3, 1, 1, 0, 1, ?, ?, ?, GETDATE())", 
                                [$row['musteri_adi'], $row['sehir_id'], $row['ilce_id'], $user['kullanici_id']]);
                            $result = $db->fetchOne("SELECT MAX(cari_id) as id FROM Cari");
                            $musteriId = $result['id'];
                            $newMusteriler[$musteriKey] = $musteriId;
                        }
                    }
                    
                    // Dosya no kontrolü (varsa güncelle, yoksa ekle)
                    $existing = $db->fetchOne("SELECT takip_id FROM HukukTakip WHERE takip_dosya_no = ?", [$row['dosya_no']]);
                    if ($existing) {
                        // Mevcut kaydı güncelle
                        $updateFields = [];
                        $updateParams = [];
                        
                        if ($tarafId) {
                            $updateFields[] = "takip_taraf_id = ?";
                            $updateParams[] = $tarafId;
                        }
                        if ($icraDairesiId) {
                            $updateFields[] = "takip_icra_dairesi_id = ?";
                            $updateParams[] = $icraDairesiId;
                        }
                        if ($musteriId) {
                            $updateFields[] = "takip_cari_id = ?";
                            $updateParams[] = $musteriId;
                        }
                        if (!empty($row['aciklama'])) {
                            $updateFields[] = "takip_aciklama = ?";
                            $updateParams[] = $row['aciklama'];
                        }
                        if (!empty($row['ana_tutar'])) {
                            $updateFields[] = "takip_ana_tutar = ?";
                            $updateParams[] = $row['ana_tutar'];
                        }
                        if (!empty($row['dosya_acilis_tarihi'])) {
                            $updateFields[] = "takip_dosya_acilis_tarihi = ?";
                            $updateParams[] = $row['dosya_acilis_tarihi'];
                        }
                        
                        if (!empty($updateFields)) {
                            $updateFields[] = "GuncelleyenKullanici = ?";
                            $updateParams[] = $user['kullanici_id'];
                            $updateFields[] = "GuncellemeTarihi = GETDATE()";
                            $updateParams[] = $existing['takip_id'];
                            
                            $db->execute("UPDATE HukukTakip SET " . implode(", ", $updateFields) . " WHERE takip_id = ?", $updateParams);
                        }
                        
                        $skipped++;
                        continue;
                    }
                    
                    // HukukTakip kaydı ekle
                    $dosyaAcilisTarihi = !empty($row['dosya_acilis_tarihi']) ? $row['dosya_acilis_tarihi'] : null;
                    $db->execute("
                        INSERT INTO HukukTakip (takip_cari_id, takip_taraf_id, takip_icra_dairesi_id, takip_dosya_no, takip_aciklama, takip_ana_tutar, takip_dosya_acilis_tarihi, Durum, OlusturanKullanici, OlusturmaTarihi) 
                        VALUES (?, ?, ?, ?, ?, ?, ?, 1, ?, GETDATE())", 
                        [$musteriId, $tarafId, $icraDairesiId, $row['dosya_no'], $row['aciklama'], $row['ana_tutar'], $dosyaAcilisTarihi, $user['kullanici_id']]);
                    
                    $imported++;
                }
                
                // Session temizle
                unset($_SESSION['import_data']);
                
                echo json_encode([
                    'success' => true, 
                    'message' => "Import tamamlandı. $imported yeni kayıt eklendi, $skipped mevcut kayıt güncellendi.",
                    'imported' => $imported,
                    'skipped' => $skipped,
                    'new_taraf' => count($newTaraflar),
                    'new_icra' => count($newIcraDaireleri),
                    'new_musteri' => count($newMusteriler)
                ]);
                break;
                
            case 'clear':
                unset($_SESSION['import_data']);
                echo json_encode(['success' => true, 'message' => 'Veriler temizlendi']);
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
    <link rel="stylesheet" href="/admin/assets/css/custom.css">
    <!-- SheetJS for Excel parsing -->
    <script src="https://cdn.sheetjs.com/xlsx-0.20.1/package/dist/xlsx.full.min.js"></script>
</head>
<body class="layout-fixed sidebar-expand-lg sidebar-open bg-body-tertiary">
    <div class="app-wrapper">
<?php
include __DIR__ . '/../includes/header.php';
include __DIR__ . '/../includes/sidebar.php';
?>

        <main class="app-main">
            <div class="app-content-header">
                <div class="container-fluid">
                    <div class="row">
                        <div class="col-sm-6">
                            <h3 class="mb-0"><?= $pageTitle ?></h3>
                        </div>
                        <div class="col-sm-6">
                            <ol class="breadcrumb float-sm-end">
                                <li class="breadcrumb-item"><a href="anasayfa.php">Ana Sayfa</a></li>
                                <li class="breadcrumb-item"><a href="hukuk-yonetimi.php">Hukuk Takip</a></li>
                                <li class="breadcrumb-item active">Toplu Import</li>
                            </ol>
                        </div>
                    </div>
                </div>
            </div>

            <div class="app-content">
                <div class="container-fluid">
                    
                    <!-- Yükleme Kartı -->
                    <div class="card card-primary card-outline mb-3">
                        <div class="card-header">
                            <h3 class="card-title"><i class="bi bi-upload"></i> Dosya Yükle</h3>
                        </div>
                        <div class="card-body">
                            <div class="row">
                                <div class="col-md-6">
                                    <div class="mb-3">
                                        <label class="form-label">CSV Dosyası Seçin</label>
                                        <input type="file" class="form-control" id="importFile" accept=".csv,.xlsx,.xls">
                                        <div class="form-text">
                                            Dosya formatı: Taraflar, İcra Dairesi, Dosya No, Müşteri Adı, Açıklama, Ana Tutar, Dosya Açılış Tarihi, İl, İlçe
                                        </div>
                                    </div>
                                    <button type="button" class="btn btn-primary" id="parseBtn" disabled>
                                        <i class="bi bi-file-earmark-arrow-up"></i> Dosyayı Analiz Et
                                    </button>
                                    <button type="button" class="btn btn-secondary" id="clearBtn" style="display:none;">
                                        <i class="bi bi-x-circle"></i> Temizle
                                    </button>
                                    <button type="button" class="btn btn-success" id="downloadTemplateBtn">
                                        <i class="bi bi-download"></i> Şablon İndir
                                    </button>
                                </div>
                                <div class="col-md-6">
                                    <div class="alert alert-info mb-0">
                                        <h6><i class="bi bi-info-circle"></i> Kullanım Talimatları</h6>
                                        <ol class="mb-0 small">
                                            <li>Excel dosyasını <strong>CSV (noktalı virgül ayraçlı)</strong> olarak kaydedin</li>
                                            <li>İlk satır başlık satırı olmalı</li>
                                            <li>Dosyayı yükleyin ve "Analiz Et" butonuna tıklayın</li>
                                            <li>Yeşil: Mevcut kayıt, Sarı: Yeni eklenecek, Kırmızı: Hatalı</li>
                                            <li>Kontrol ettikten sonra "Import Et" butonuna tıklayın</li>
                                        </ol>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <!-- Özet Kartı -->
                    <div class="card card-info card-outline mb-3" id="summaryCard" style="display:none;">
                        <div class="card-header">
                            <h3 class="card-title"><i class="bi bi-bar-chart"></i> Analiz Özeti</h3>
                        </div>
                        <div class="card-body">
                            <div class="row text-center">
                                <div class="col-md-2">
                                    <div class="info-box text-bg-primary mb-0">
                                        <span class="info-box-icon"><i class="bi bi-files"></i></span>
                                        <div class="info-box-content">
                                            <span class="info-box-text">Toplam Satır</span>
                                            <span class="info-box-number" id="sum-total">0</span>
                                        </div>
                                    </div>
                                </div>
                                <div class="col-md-2">
                                    <div class="info-box text-bg-warning mb-0">
                                        <span class="info-box-icon"><i class="bi bi-person-plus"></i></span>
                                        <div class="info-box-content">
                                            <span class="info-box-text">Yeni Müşteri</span>
                                            <span class="info-box-number" id="sum-musteri">0</span>
                                        </div>
                                    </div>
                                </div>
                                <div class="col-md-2">
                                    <div class="info-box text-bg-warning mb-0">
                                        <span class="info-box-icon"><i class="bi bi-people"></i></span>
                                        <div class="info-box-content">
                                            <span class="info-box-text">Yeni Taraf</span>
                                            <span class="info-box-number" id="sum-taraf">0</span>
                                        </div>
                                    </div>
                                </div>
                                <div class="col-md-2">
                                    <div class="info-box text-bg-warning mb-0">
                                        <span class="info-box-icon"><i class="bi bi-building"></i></span>
                                        <div class="info-box-content">
                                            <span class="info-box-text">Yeni İcra Dairesi</span>
                                            <span class="info-box-number" id="sum-icra">0</span>
                                        </div>
                                    </div>
                                </div>
                                <div class="col-md-2">
                                    <div class="info-box text-bg-danger mb-0">
                                        <span class="info-box-icon"><i class="bi bi-exclamation-triangle"></i></span>
                                        <div class="info-box-content">
                                            <span class="info-box-text">Hatalı</span>
                                            <span class="info-box-number" id="sum-errors">0</span>
                                        </div>
                                    </div>
                                </div>
                                <div class="col-md-2">
                                    <button type="button" class="btn btn-success btn-lg w-100 h-100" id="importBtn">
                                        <i class="bi bi-database-add"></i><br>Import Et
                                    </button>
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <!-- Önizleme Tablosu -->
                    <div class="card card-primary card-outline" id="previewCard" style="display:none;">
                        <div class="card-header">
                            <h3 class="card-title"><i class="bi bi-table"></i> Veri Önizleme</h3>
                            <div class="card-tools">
                                <span class="badge bg-success me-2"><i class="bi bi-check"></i> Mevcut</span>
                                <span class="badge bg-warning me-2"><i class="bi bi-plus"></i> Yeni Eklenecek</span>
                                <span class="badge bg-danger"><i class="bi bi-x"></i> Hatalı</span>
                            </div>
                        </div>
                        <div class="card-body">
                            <div class="table-responsive">
                                <table class="table table-bordered table-sm table-hover" id="previewTable">
                                    <thead class="table-dark">
                                        <tr>
                                            <th width="40">#</th>
                                            <th>Taraf</th>
                                            <th>İcra Dairesi</th>
                                            <th>Dosya No</th>
                                            <th>Müşteri Adı</th>
                                            <th>Açıklama</th>
                                            <th class="text-end">Ana Tutar</th>
                                            <th>Dosya Açılış</th>
                                            <th>İl</th>
                                            <th>İlçe</th>
                                            <th width="80">Durum</th>
                                        </tr>
                                    </thead>
                                    <tbody id="previewBody">
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

    <!-- JS Kütüphaneleri -->
    <script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/@popperjs/core@2.11.8/dist/umd/popper.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.7/dist/js/bootstrap.min.js"></script>
    <script src="/admin/assets/js/adminlte.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11/dist/sweetalert2.all.min.js"></script>
    <script src="/admin/assets/js/custom.js"></script>

    <script>
        // Şablon indirme
        function downloadTemplate() {
            const headers = ['Taraf', 'İcra Dairesi', 'Dosya No', 'Müşteri Adı', 'Açıklama', 'Ana Tutar', 'Dosya Açılış Tarihi', 'İl', 'İlçe'];
            const sampleData = [
                ['Alacaklı', 'İzmir 2. İcra Dairesi', '2025/6650', 'Mehmet Yılmaz', 'Örnek açıklama', '15000.00', '05.08.2025 11:03:00', 'İzmir', 'Konak'],
                ['Borçlu', 'Ankara 8. Genel İcra Dairesi', '2026/17306', 'Ayşe Demir', '', '25000.50', '09.03.2026 14:55:00', 'Ankara', 'Çankaya'],
            ];
            
            const wsData = [headers, ...sampleData];
            const ws = XLSX.utils.aoa_to_sheet(wsData);
            
            ws['!cols'] = [
                { wch: 12 }, { wch: 30 }, { wch: 15 }, { wch: 20 },
                { wch: 25 }, { wch: 18 }, { wch: 22 }, { wch: 12 }, { wch: 15 }
            ];
            
            const wb = XLSX.utils.book_new();
            XLSX.utils.book_append_sheet(wb, ws, 'Toplu Import');
            XLSX.writeFile(wb, 'hukuk-toplu-import-sablonu.xlsx');
            showToast('Şablon indirildi', 'success');
        }
        
        $(document).ready(function() {
            // Şablon indir
            $('#downloadTemplateBtn').on('click', downloadTemplate);
            // Dosya seçildiğinde
            $('#importFile').on('change', function() {
                $('#parseBtn').prop('disabled', !this.files.length);
            });
            
            // Analiz Et - SheetJS ile client-side parse
            $('#parseBtn').on('click', function() {
                const file = $('#importFile')[0].files[0];
                if (!file) return;
                
                $(this).prop('disabled', true).html('<i class="bi bi-hourglass-split"></i> Analiz ediliyor...');
                
                const reader = new FileReader();
                reader.onload = function(e) {
                    try {
                        const data = new Uint8Array(e.target.result);
                        const workbook = XLSX.read(data, { type: 'array' });
                        const sheetName = workbook.SheetNames[0];
                        const sheet = workbook.Sheets[sheetName];
                        const jsonData = XLSX.utils.sheet_to_json(sheet, { header: 1 });
                        
                        // İlk satır başlık, atla
                        const rows = jsonData.slice(1);
                        const parsedData = [];
                        
                        // Türkçe sayı formatı parse fonksiyonu
                        function parseTurkishNumber(val) {
                            if (val === null || val === undefined || val === '') return 0;
                            if (typeof val === 'number') return val;
                            const str = String(val).trim();
                            return parseFloat(str.replace(/\./g, '').replace(',', '.')) || 0;
                        }
                        
                        // Tarih parse fonksiyonu (Excel serial number veya string)
                        function parseExcelDate(val) {
                            if (!val) return '';
                            if (typeof val === 'number') {
                                const date = XLSX.SSF.parse_date_code(val);
                                if (date) {
                                    const y = date.y;
                                    const m = String(date.m).padStart(2, '0');
                                    const d = String(date.d).padStart(2, '0');
                                    const H = String(date.H).padStart(2, '0');
                                    const M = String(date.M).padStart(2, '0');
                                    const S = String(Math.floor(date.S)).padStart(2, '0');
                                    return `${y}-${m}-${d} ${H}:${M}:${S}`;
                                }
                            }
                            const str = String(val).trim();
                            const match = str.match(/^(\d{2})\.(\d{2})\.(\d{4})\s+(\d{2}):(\d{2}):(\d{2})$/);
                            if (match) return `${match[3]}-${match[2]}-${match[1]} ${match[4]}:${match[5]}:${match[6]}`;
                            const match2 = str.match(/^(\d{2})\.(\d{2})\.(\d{4})$/);
                            if (match2) return `${match2[3]}-${match2[2]}-${match2[1]} 00:00:00`;
                            return str;
                        }
                        
                        rows.forEach(row => {
                            if (row.length >= 3 && row[2]) { // Dosya No zorunlu (3. kolon)
                                parsedData.push({
                                    taraf: String(row[0] || '').trim(),
                                    icra_dairesi: String(row[1] || '').trim(),
                                    dosya_no: String(row[2] || '').trim(),
                                    musteri_adi: String(row[3] || '').trim(),
                                    aciklama: String(row[4] || '').trim(),
                                    ana_tutar: parseTurkishNumber(row[5]),
                                    dosya_acilis_tarihi: parseExcelDate(row[6]),
                                    sehir: String(row[7] || '').trim(),
                                    ilce: String(row[8] || '').trim()
                                });
                            }
                        });
                        
                        if (parsedData.length === 0) {
                            showToast('Dosyada geçerli veri bulunamadı', 'error');
                            $('#parseBtn').prop('disabled', false).html('<i class="bi bi-file-earmark-arrow-up"></i> Dosyayı Analiz Et');
                            return;
                        }
                        
                        // Sunucuya gönder
                        $.post('', {
                            action: 'parse_data',
                            data: JSON.stringify(parsedData)
                        }, function(res) {
                            if (res.success) {
                                renderPreview(res.data);
                                updateSummary(res.summary);
                                $('#summaryCard, #previewCard, #clearBtn').show();
                                showToast(`${res.data.length} satır analiz edildi`, 'success');
                            } else {
                                showToast(res.message, 'error');
                            }
                        }).fail(function() {
                            showToast('Sunucu hatası', 'error');
                        }).always(function() {
                            $('#parseBtn').prop('disabled', false).html('<i class="bi bi-file-earmark-arrow-up"></i> Dosyayı Analiz Et');
                        });
                        
                    } catch (err) {
                        console.error('Parse error:', err);
                        showToast('Dosya okunamadı: ' + err.message, 'error');
                        $('#parseBtn').prop('disabled', false).html('<i class="bi bi-file-earmark-arrow-up"></i> Dosyayı Analiz Et');
                    }
                };
                
                reader.onerror = function() {
                    showToast('Dosya okunamadı', 'error');
                    $('#parseBtn').prop('disabled', false).html('<i class="bi bi-file-earmark-arrow-up"></i> Dosyayı Analiz Et');
                };
                
                reader.readAsArrayBuffer(file);
            });
            
            // Temizle
            $('#clearBtn').on('click', function() {
                $.post('', { action: 'clear' }, function() {
                    $('#importFile').val('');
                    $('#parseBtn').prop('disabled', true);
                    $('#summaryCard, #previewCard, #clearBtn').hide();
                    $('#previewBody').empty();
                    showToast('Veriler temizlendi', 'info');
                });
            });
            
            // Import Et
            $('#importBtn').on('click', function() {
                confirmAction('Verileri import etmek istediğinize emin misiniz?', 'Yeni müşteriler, taraflar ve icra daireleri otomatik oluşturulacak.', function() {
                    $('#importBtn').prop('disabled', true).html('<i class="bi bi-hourglass-split"></i> İçe aktarılıyor...');
                    
                    $.post('', { action: 'import' }, function(res) {
                        if (res.success) {
                            showSuccess('Import Tamamlandı!', res.message);
                            $('#summaryCard, #previewCard, #clearBtn').hide();
                            $('#previewBody').empty();
                            $('#importFile').val('');
                            $('#parseBtn').prop('disabled', true);
                        } else {
                            showError('Hata!', res.message);
                        }
                    }).always(function() {
                        $('#importBtn').prop('disabled', false).html('<i class="bi bi-database-add"></i><br>Import Et');
                    });
                });
            });
        });
        
        function renderPreview(data) {
            let html = '';
            data.forEach((row, i) => {
                const rowClass = row.has_error ? 'table-danger' : '';
                
                html += `<tr class="${rowClass}">
                    <td>${row.row_num}</td>
                    <td>${getStatusBadge(row.taraf, row.taraf_status)}</td>
                    <td>${getStatusBadge(row.icra_dairesi, row.icra_dairesi_status)}</td>
                    <td><strong>${row.dosya_no}</strong></td>
                    <td>${getStatusBadge(row.musteri_adi, row.musteri_status)}</td>
                    <td><small>${truncate(row.aciklama, 50)}</small></td>
                    <td class="text-end">${formatCurrency(row.ana_tutar)}</td>
                    <td>${row.dosya_acilis_tarihi ? formatDisplayDate(row.dosya_acilis_tarihi) : '-'}</td>
                    <td>${getStatusBadge(row.sehir, row.sehir_status, true)}</td>
                    <td>${getStatusBadge(row.ilce, row.ilce_status, true)}</td>
                    <td>${row.has_error ? '<span class="badge bg-danger">Hatalı</span>' : '<span class="badge bg-success">OK</span>'}</td>
                </tr>`;
            });
            $('#previewBody').html(html);
        }
        
        function formatDisplayDate(dateStr) {
            if (!dateStr || dateStr === '-') return '-';
            const match = dateStr.match(/^(\d{4})-(\d{2})-(\d{2})\s+(\d{2}):(\d{2})/);
            if (match) return `${match[3]}.${match[2]}.${match[1]} ${match[4]}:${match[5]}`;
            return dateStr;
        }
        
        function getStatusBadge(text, status, isLocation = false) {
            if (!text) return '<span class="text-muted">-</span>';
            
            let badgeClass = 'bg-warning text-dark'; // new
            let icon = 'bi-plus-circle';
            
            if (status === 'found') {
                badgeClass = 'bg-success';
                icon = 'bi-check-circle';
            } else if (status === 'not_found' && isLocation) {
                badgeClass = 'bg-secondary';
                icon = 'bi-question-circle';
            }
            
            return `<span class="badge ${badgeClass}"><i class="bi ${icon}"></i> ${text}</span>`;
        }
        
        function updateSummary(summary) {
            $('#sum-total').text(summary.total);
            $('#sum-musteri').text(summary.new_musteri);
            $('#sum-taraf').text(summary.new_taraf);
            $('#sum-icra').text(summary.new_icra);
            $('#sum-errors').text(summary.errors);
        }
        
        function truncate(str, len) {
            if (!str) return '';
            return str.length > len ? str.substring(0, len) + '...' : str;
        }
    </script>
</body>
</html>
