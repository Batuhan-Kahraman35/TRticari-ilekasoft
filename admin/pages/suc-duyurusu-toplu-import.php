<?php
/**
 * Suç Duyurusu - Toplu Import
 * Excel/CSV dosyasından toplu suç duyurusu dosyası yükleme
 * cari_tipi_id = 4
 */

require_once __DIR__ . '/../auth.php';
require_once __DIR__ . '/../includes/PageAuth.php';
requireAuth();

$user = Auth::user();
$db = Database::getInstance();

$siteAyarlari = $db->fetchOne("SELECT TOP 1 site_ayarlari_site_title FROM dbo.tanim_site_ayarlari ORDER BY site_ayarlari_id DESC");
$siteTitle = $siteAyarlari['site_ayarlari_site_title'] ?? 'Portal';

$pageTitle = 'Suç Duyurusu - Toplu Import';
$pageDescription = 'Excel/CSV dosyasından toplu suç duyurusu dosyası yükleme';

$cariTipiId = 4;

// Mevcut veriler (eşleştirme için)
$taraflar = $db->fetchAll("SELECT taraf_id, taraf_ad FROM HukukTaraflar WHERE Durum = 1");
$sehirler = $db->fetchAll("SELECT SehirId, SehirAdi FROM Adres_Sehirler WHERE UlkeId = 1");
$ilceler = $db->fetchAll("SELECT ilceId, IlceAdi, SehirId FROM Adres_Ilceler");
$musteriler = $db->fetchAll("SELECT cari_id, cari_adi, cari_unvan FROM Cari WHERE cari_tipi_id = ? AND cari_aktif = 1", [$cariTipiId]);

// Sezonlar (suc-duyurusu-takip sezon filtresiyle ayni kaynak)
$sezonlar = $db->fetchAll("SELECT sezon_id, sezon_ad, sezon_varsayilan FROM Sozlesme_Sezonlar WHERE sezon_durum = 1 ORDER BY sezon_ad DESC");
$varsayilanSezonId = '';
foreach ($sezonlar as $sz) {
    if (!empty($sz['sezon_varsayilan'])) { $varsayilanSezonId = (string)$sz['sezon_id']; break; }
}
if ($varsayilanSezonId === '' && !empty($sezonlar)) {
    $varsayilanSezonId = (string)$sezonlar[0]['sezon_id'];
}

// Sabit listeler
$tespitTurleri = ['İHBAR KONUM', 'İMZALI TUTANAK', 'İMZASIZ TUTANAK', 'GÖRSEL YOK'];
$tespitDurumlari = ['GİDİLMEDİ', 'GİDİLDİ'];

// Türkçe karakter normalize fonksiyonu (İ/I dönüşümü)
function turkceNormalize($str) {
    $str = trim($str);
    $str = str_replace(
        ['İ', 'I', 'Ğ', 'Ü', 'Ş', 'Ö', 'Ç'],
        ['i', 'ı', 'ğ', 'ü', 'ş', 'ö', 'ç'],
        $str
    );
    return mb_strtolower($str, 'UTF-8');
}

// AJAX İşlemleri
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json');
    $action = $_POST['action'] ?? '';
    
    try {
        switch ($action) {
            case 'parse_data':
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
                
                $validatedData = [];
                
                foreach ($data as $i => $row) {
                    $rowNum = $i + 2;
                    $validated = [
                        'row_num' => $rowNum,
                        'tespit_durum' => $row['tespit_durum'],
                        'tespit_adet' => $row['tespit_adet'],
                        'tespit_tarihi' => $row['tespit_tarihi'],
                        'tespit_turu' => $row['tespit_turu'],
                        'isletmeci' => $row['isletmeci'],
                        'isyeri_adi' => $row['isyeri_adi'],
                        'musteri_id' => null,
                        'musteri_status' => 'new',
                        'sehir' => $row['sehir'],
                        'sehir_id' => null,
                        'sehir_status' => 'not_found',
                        'ilce' => $row['ilce'],
                        'ilce_id' => null,
                        'ilce_status' => 'not_found',
                        'taraf' => $row['taraf'],
                        'taraf_id' => null,
                        'taraf_status' => 'new',
                        'adres' => $row['adres'],
                        'aciklama' => $row['aciklama'],
                        'has_error' => false,
                        'error_message' => ''
                    ];
                    
                    // Müşteri eşleştir (cari_adi = isletmeci veya isyeri_adi)
                    $musteriArama = !empty($row['isletmeci']) ? $row['isletmeci'] : $row['isyeri_adi'];
                    if (!empty($musteriArama)) {
                        foreach ($musteriler as $m) {
                            if (turkceNormalize($m['cari_adi']) === turkceNormalize($musteriArama)) {
                                $validated['musteri_id'] = $m['cari_id'];
                                $validated['musteri_status'] = 'found';
                                break;
                            }
                        }
                    }
                    
                    // Taraf eşleştir
                    foreach ($taraflar as $t) {
                        if (turkceNormalize($t['taraf_ad']) === turkceNormalize($row['taraf'])) {
                            $validated['taraf_id'] = $t['taraf_id'];
                            $validated['taraf_status'] = 'found';
                            break;
                        }
                    }
                    
                    // Şehir eşleştir (Türkçe karakter uyumlu)
                    $sehirNorm = turkceNormalize($row['sehir']);
                    foreach ($sehirler as $s) {
                        if (turkceNormalize($s['SehirAdi']) === $sehirNorm) {
                            $validated['sehir_id'] = $s['SehirId'];
                            $validated['sehir_status'] = 'found';
                            break;
                        }
                    }
                    
                    // İlçe eşleştir (şehir bulunduysa, Türkçe karakter uyumlu)
                    if ($validated['sehir_id']) {
                        $ilceNorm = turkceNormalize($row['ilce']);
                        foreach ($ilceler as $il) {
                            if ($il['SehirId'] == $validated['sehir_id'] && 
                                turkceNormalize($il['IlceAdi']) === $ilceNorm) {
                                $validated['ilce_id'] = $il['ilceId'];
                                $validated['ilce_status'] = 'found';
                                break;
                            }
                        }
                    }
                    
                    // Hata kontrolü: İşletmeci veya İşyeri Adı'ndan en az biri dolu olmalı
                    if (empty($row['isletmeci']) && empty($row['isyeri_adi'])) {
                        $validated['has_error'] = true;
                        $validated['error_message'] = 'İşletmeci veya İşyeri Adı boş olamaz';
                    }
                    
                    $validatedData[] = $validated;
                }
                
                // Özet istatistikler
                $summary = [
                    'total' => count($validatedData),
                    'new_musteri' => count(array_filter($validatedData, fn($r) => $r['musteri_status'] === 'new' && (!empty($r['isletmeci']) || !empty($r['isyeri_adi'])))),
                    'new_taraf' => count(array_filter($validatedData, fn($r) => $r['taraf_status'] === 'new' && !empty($r['taraf']))),
                    'errors' => count(array_filter($validatedData, fn($r) => $r['has_error']))
                ];
                
                $_SESSION['import_data_suc'] = $validatedData;
                
                echo json_encode([
                    'success' => true, 
                    'data' => $validatedData,
                    'summary' => $summary
                ]);
                break;
                
            case 'import':
                if (!isset($_SESSION['import_data_suc']) || empty($_SESSION['import_data_suc'])) {
                    echo json_encode(['success' => false, 'message' => 'Import verisi bulunamadı. Lütfen önce dosya yükleyin.']);
                    break;
                }
                
                // Sezon zorunlu: HukukTakip'te sezon kolonu yok, sezon carinin sozlesmesinden turetilir.
                // Sezon secilmeden eklenen kayitlar suc-duyurusu-takip sezon filtresine takilmaz.
                $sezonId = $_POST['sezon_id'] ?? '';
                if ($sezonId === '') {
                    echo json_encode(['success' => false, 'message' => 'Sezon seçilmedi. Lütfen import edilecek sezonu seçin.']);
                    break;
                }
                $sezonKayit = $db->fetchOne("SELECT sezon_id FROM Sozlesme_Sezonlar WHERE sezon_id = ? AND sezon_durum = 1", [$sezonId]);
                if (!$sezonKayit) {
                    echo json_encode(['success' => false, 'message' => 'Geçersiz sezon seçimi.']);
                    break;
                }
                $sezonId = (int)$sezonKayit['sezon_id'];

                $importData = $_SESSION['import_data_suc'];
                $imported = 0;
                $updated = 0;
                $skipped = 0;
                $newTaraflar = [];
                $newMusteriler = [];
                $newSozlesmeler = 0;
                $sozlesmeKontrolEdilen = [];
                $failed = 0;
                $hataMesajlari = [];

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
                    
                    // Müşteri oluştur veya güncelle (cari_adi = isletmeci veya isyeri_adi)
                    $musteriId = $row['musteri_id'];
                    $cariAdi = !empty($row['isletmeci']) ? $row['isletmeci'] : $row['isyeri_adi'];
                    if (!$musteriId && !empty($cariAdi)) {
                        $musteriKey = mb_strtolower($cariAdi, 'UTF-8');
                        if (isset($newMusteriler[$musteriKey])) {
                            $musteriId = $newMusteriler[$musteriKey];
                        } else {
                            $cariEklendi = $db->execute("
                                INSERT INTO Cari (cari_adi, cari_unvan, cari_adres, cari_tipi_id, cari_aktif, cari_musteri, cari_tedarikci, cari_ulke, cari_sehirler, cari_ilceler, cari_olusturan_kullanici, cari_olusturma_tarihi)
                                VALUES (?, ?, ?, ?, 1, 1, 0, 1, ?, ?, ?, GETDATE())",
                                [$cariAdi, $row['isyeri_adi'], $row['adres'], $cariTipiId, $row['sehir_id'], $row['ilce_id'], $user['kullanici_id']]);
                            if (!$cariEklendi) {
                                $sqlErrors = sqlsrv_errors();
                                $hataMesajlari[] = 'Satır ' . $row['row_num'] . ' (cari): ' . ($sqlErrors[0]['message'] ?? 'Bilinmeyen SQL hatası');
                                $failed++;
                                continue;
                            }
                            $result = $db->fetchOne("SELECT MAX(cari_id) as id FROM Cari");
                            $musteriId = $result['id'];
                            $newMusteriler[$musteriKey] = $musteriId;
                        }
                    } else if ($musteriId) {
                        // Mevcut müşterinin adres/unvan bilgilerini güncelle
                        $updateFields = [];
                        $updateParams = [];
                        if (!empty($row['isyeri_adi'])) {
                            $updateFields[] = "cari_unvan = ?";
                            $updateParams[] = $row['isyeri_adi'];
                        }
                        if (!empty($row['adres'])) {
                            $updateFields[] = "cari_adres = ?";
                            $updateParams[] = $row['adres'];
                        }
                        if ($row['sehir_id']) {
                            $updateFields[] = "cari_sehirler = ?";
                            $updateParams[] = $row['sehir_id'];
                        }
                        if ($row['ilce_id']) {
                            $updateFields[] = "cari_ilceler = ?";
                            $updateParams[] = $row['ilce_id'];
                        }
                        if (!empty($updateFields)) {
                            $updateParams[] = $musteriId;
                            $db->execute("UPDATE Cari SET " . implode(", ", $updateFields) . " WHERE cari_id = ?", $updateParams);
                        }
                    }
                    
                    if (!$musteriId) {
                        $skipped++;
                        continue;
                    }
                    
                    // Tespit tarihi parse
                    $tespitTarihi = !empty($row['tespit_tarihi']) ? $row['tespit_tarihi'] : null;
                    $tespitAdet = !empty($row['tespit_adet']) ? intval($row['tespit_adet']) : null;
                    
                    // Dosya no varsa kontrol et (isletmeci + tespit_tarihi ile unique)
                    // Bu sayfada dosya_no olmayabilir, isletmeci + tarih ile kontrol
                    $dosyaNo = '';
                    
                    // HukukTakip kaydı ekle
                    // NOT: HukukTakip'te takip_tespit_durum kolonu yoktur; Excel'deki Durum sütunu
                    // (GİDİLDİ / GİDİLMEDİ) yalnızca önizlemede gösterilir, veritabanına yazılmaz.
                    $takipEklendi = $db->execute("
                        INSERT INTO HukukTakip (
                            takip_cari_id, takip_taraf_id, takip_dosya_no, takip_aciklama,
                            takip_tespit_tarihi, takip_tespit_turu, takip_tespit_adet,
                            Durum, OlusturanKullanici, OlusturmaTarihi
                        ) VALUES (?, ?, ?, ?, ?, ?, ?, 1, ?, GETDATE())",
                        [
                            $musteriId, $tarafId, $dosyaNo, $row['aciklama'],
                            $tespitTarihi, $row['tespit_turu'], $tespitAdet,
                            $user['kullanici_id']
                        ]);

                    // execute() hata durumunda exception atmaz, false doner. Kontrol edilmezse
                    // basarisiz satirlar da "eklendi" sayilir ve hata sessizce kaybolur.
                    if (!$takipEklendi) {
                        $sqlErrors = sqlsrv_errors();
                        $hataMesajlari[] = 'Satır ' . $row['row_num'] . ': ' . ($sqlErrors[0]['message'] ?? 'Bilinmeyen SQL hatası');
                        $failed++;
                        continue;
                    }

                    $imported++;

                    // Sezon kaydi: suc duyurusu listesinde sezon, cariye bagli son sozlesmeden turetilir.
                    // Ayni cari + sezon icin sozlesme yoksa olustur (ihbar.php ile ayni desen).
                    if (!isset($sozlesmeKontrolEdilen[$musteriId])) {
                        $sozlesmeKontrolEdilen[$musteriId] = true;
                        $mevcutSozlesme = $db->fetchOne("
                            SELECT TOP 1 sozlesme_id
                            FROM Sozlesmeler
                            WHERE sozlesme_cari_id = ? AND sozlesme_sezon_id = ?
                            ORDER BY sozlesme_id DESC
                        ", [$musteriId, $sezonId]);

                        if (!$mevcutSozlesme) {
                            $sozlesmeEklendi = $db->execute("
                                INSERT INTO Sozlesmeler (
                                    sozlesme_sezon_id, sozlesme_tarih, sozlesme_cari_id,
                                    sozlesme_no, sozlesme_aciklama, sozlesme_personel_id,
                                    sozlesme_fatura_no, sozlesme_fatura_dosya, sozlesme_dosyalar,
                                    sozlesme_kullanici_id, sozlesme_olusturma_tarihi, sozlesme_durum
                                ) VALUES (?, CAST(GETDATE() AS DATE), ?, '', ?, ?, '', '', '[]', ?, GETDATE(), 1)
                            ", [
                                $sezonId, $musteriId, 'Toplu import sezon kaydı',
                                $user['kullanici_id'],
                                $user['kullanici_id']
                            ]);
                            if ($sozlesmeEklendi) {
                                $newSozlesmeler++;
                            } else {
                                $sqlErrors = sqlsrv_errors();
                                $hataMesajlari[] = 'Satır ' . $row['row_num'] . ' (sezon kaydı): ' . ($sqlErrors[0]['message'] ?? 'Bilinmeyen SQL hatası');
                            }
                        }
                    }
                }

                // Hicbir satir yazilamadiysa oturum verisi korunur, kullanici duzeltip tekrar deneyebilir
                if ($failed === 0) {
                    unset($_SESSION['import_data_suc']);
                }

                $mesaj = "Import tamamlandı. $imported yeni kayıt eklendi";
                if ($skipped > 0) { $mesaj .= ", $skipped satır atlandı"; }
                if ($failed > 0)  { $mesaj .= ", $failed satır veritabanı hatası nedeniyle yazılamadı"; }
                $mesaj .= '.';
                if ($newSozlesmeler > 0) { $mesaj .= " $newSozlesmeler cari için sezon kaydı oluşturuldu."; }
                if ($failed > 0) {
                    $mesaj .= ' İlk hata: ' . $hataMesajlari[0];
                }

                echo json_encode([
                    'success' => ($failed === 0),
                    'message' => $mesaj,
                    'imported' => $imported,
                    'skipped' => $skipped,
                    'failed' => $failed,
                    'errors' => array_slice($hataMesajlari, 0, 10),
                    'new_taraf' => count($newTaraflar),
                    'new_musteri' => count($newMusteriler),
                    'new_sozlesme' => $newSozlesmeler
                ]);
                break;
                
            case 'clear':
                unset($_SESSION['import_data_suc']);
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
    <link href="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css" rel="stylesheet" />
    <link href="https://cdn.jsdelivr.net/npm/select2-bootstrap-5-theme@1.3.0/dist/select2-bootstrap-5-theme.min.css" rel="stylesheet" />
    <link rel="stylesheet" href="/admin/assets/css/custom.css">
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
                                <li class="breadcrumb-item"><a href="/admin/suc-duyurusu-takip">Suç Duyurusu Takip</a></li>
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
                                        <label class="form-label" for="importSezonId">Sezon <span class="text-danger">*</span></label>
                                        <select class="form-select" id="importSezonId" name="sezon_id">
                                            <option value="">Seçiniz</option>
                                            <?php foreach ($sezonlar as $sz): ?>
                                                <option value="<?= $sz['sezon_id'] ?>" <?= ((string)$sz['sezon_id'] === $varsayilanSezonId) ? 'selected' : '' ?>><?= htmlspecialchars($sz['sezon_ad']) ?></option>
                                            <?php endforeach; ?>
                                        </select>
                                        <div class="form-text">
                                            İçe aktarılan kayıtlar bu sezona bağlanır. Sezonu olmayan kayıtlar Suç Duyurusu Takip listesinde görünmez.
                                        </div>
                                    </div>
                                    <div class="mb-3">
                                        <label class="form-label">Excel/CSV Dosyası Seçin</label>
                                        <input type="file" class="form-control" id="importFile" accept=".csv,.xlsx,.xls">
                                        <div class="form-text">
                                            Kolonlar: Durum, Tespit Adet, Tespit Tarihi, Tür, İşletmeci, İşyeri Adı, İl, İlçe, Taraf, Adres, Açıklama
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
                                            <li>Excel dosyasını yükleyin (XLSX veya CSV formatında)</li>
                                            <li>İlk satır başlık satırı olmalı</li>
                                            <li>Kolon sırası: Durum, Tespit Adet, Tespit Tarihi, Tür, İşletmeci, İşyeri Adı, İl, İlçe, Taraf, Adres, Açıklama</li>
                                            <li>Dosyayı yükleyin ve "Analiz Et" butonuna tıklayın</li>
                                            <li><span class="badge bg-success">Yeşil</span>: Mevcut kayıt, <span class="badge bg-warning text-dark">Sarı</span>: Yeni eklenecek</li>
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
                                <div class="col-md-3">
                                    <div class="info-box text-bg-primary mb-0">
                                        <span class="info-box-icon"><i class="bi bi-files"></i></span>
                                        <div class="info-box-content">
                                            <span class="info-box-text">Toplam Satır</span>
                                            <span class="info-box-number" id="sum-total">0</span>
                                        </div>
                                    </div>
                                </div>
                                <div class="col-md-3">
                                    <div class="info-box text-bg-warning mb-0">
                                        <span class="info-box-icon"><i class="bi bi-person-plus"></i></span>
                                        <div class="info-box-content">
                                            <span class="info-box-text">Yeni İşletmeci</span>
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
                                            <th>Durum</th>
                                            <th>Adet</th>
                                            <th>Tespit Tarihi</th>
                                            <th>Tür</th>
                                            <th>İşletmeci</th>
                                            <th>İşyeri Adı</th>
                                            <th>İl</th>
                                            <th>İlçe</th>
                                            <th>Taraf</th>
                                            <th>Adres</th>
                                            <th>Açıklama</th>
                                            <th width="60">Sonuç</th>
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

    <script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/@popperjs/core@2.11.8/dist/umd/popper.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.7/dist/js/bootstrap.min.js"></script>
    <script src="/admin/assets/js/adminlte.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11/dist/sweetalert2.all.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js"></script>
    <script src="/admin/assets/js/custom.js"></script>

    <script>
        // Şablon indirme
        function downloadTemplate() {
            const headers = ['Durum', 'Tespit Adet', 'Tespit Tarihi', 'Tür', 'İşletmeci', 'İşyeri Adı', 'İl', 'İlçe', 'Taraf', 'Adres', 'Açıklama'];
            const sampleData = [
                ['GİDİLMEDİ', '', '1.11.2025', 'İHBAR KONUM', 'HALİL ALKAN HAİR BOSS', '', 'BURDUR', 'MERKEZ', 'ÖRNEK', 'KONAK, KALEKAPI CD. KONAK APT NO54/A, 15200 BURDUR', 'GALATASARAY-TRABZONSPOR'],
                ['GİDİLMEDİ', '2.TESPİT 1.11', '17.01.2026', 'İMZALI TUTANAK', 'TUANA ERKEK KUAFÖRÜ', '', 'İZMİR', 'Buca', 'ÖRNEK', 'Çamıpınar Mah, 2254. Sk. No:275, 35590 Buca/İzmir', 'GALATASARAY-GAZİANTEP FK'],
            ];
            
            const wsData = [headers, ...sampleData];
            const ws = XLSX.utils.aoa_to_sheet(wsData);
            
            ws['!cols'] = [
                { wch: 12 }, { wch: 15 }, { wch: 14 }, { wch: 18 }, { wch: 25 },
                { wch: 25 }, { wch: 15 }, { wch: 15 }, { wch: 10 }, { wch: 50 }, { wch: 30 }
            ];
            
            const wb = XLSX.utils.book_new();
            XLSX.utils.book_append_sheet(wb, ws, 'Toplu Import');
            XLSX.writeFile(wb, 'suc-duyurusu-toplu-import-sablonu.xlsx');
            showToast('Şablon indirildi', 'success');
        }
        
        // Tarih parse fonksiyonu
        function parseExcelDate(val) {
            if (!val) return '';
            if (typeof val === 'number') {
                const date = XLSX.SSF.parse_date_code(val);
                if (date) {
                    const y = date.y;
                    const m = String(date.m).padStart(2, '0');
                    const d = String(date.d).padStart(2, '0');
                    return `${y}-${m}-${d}`;
                }
            }
            const str = String(val).trim();
            // DD.MM.YYYY HH:MM:SS
            const match = str.match(/^(\d{1,2})\.(\d{1,2})\.(\d{4})\s*(\d{2}:\d{2}:\d{2})?$/);
            if (match) return `${match[3]}-${match[2].padStart(2,'0')}-${match[1].padStart(2,'0')}`;
            // Already YYYY-MM-DD
            const match2 = str.match(/^(\d{4})-(\d{2})-(\d{2})/);
            if (match2) return `${match2[1]}-${match2[2]}-${match2[3]}`;
            return str;
        }
        
        function formatDisplayDate(dateStr) {
            if (!dateStr || dateStr === '-') return '-';
            const match = dateStr.match(/^(\d{4})-(\d{2})-(\d{2})/);
            if (match) return `${match[3]}.${match[2]}.${match[1]}`;
            return dateStr;
        }
        
        $(document).ready(function() {
            $('#downloadTemplateBtn').on('click', downloadTemplate);
            
            $('#importFile').on('change', function() {
                $('#parseBtn').prop('disabled', !this.files.length);
            });
            
            // Analiz Et
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
                        
                        const rows = jsonData.slice(1);
                        const parsedData = [];
                        
                        rows.forEach(row => {
                            // Satırda en az bir hücrede veri olmalı
                            if (row.length >= 1 && row.some(cell => cell !== null && cell !== undefined && String(cell).trim() !== '')) {
                                parsedData.push({
                                    tespit_durum: String(row[0] || '').trim(),
                                    tespit_adet: String(row[1] || '').trim(),
                                    tespit_tarihi: parseExcelDate(row[2]),
                                    tespit_turu: String(row[3] || '').trim(),
                                    isletmeci: String(row[4] || '').trim(),
                                    isyeri_adi: String(row[5] || '').trim(),
                                    sehir: String(row[6] || '').trim(),
                                    ilce: String(row[7] || '').trim(),
                                    taraf: String(row[8] || '').trim(),
                                    adres: String(row[9] || '').trim(),
                                    aciklama: String(row[10] || '').trim()
                                });
                            }
                        });
                        
                        if (parsedData.length === 0) {
                            showToast('Dosyada geçerli veri bulunamadı', 'error');
                            $('#parseBtn').prop('disabled', false).html('<i class="bi bi-file-earmark-arrow-up"></i> Dosyayı Analiz Et');
                            return;
                        }
                        
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
            
            // Sezon dropdown (arama destekli)
            $('#importSezonId').select2({ theme: 'bootstrap-5', width: '100%', placeholder: 'Sezon seçiniz' });

            // Import Et
            $('#importBtn').on('click', function() {
                const sezonId = $('#importSezonId').val();
                if (!sezonId) {
                    showError('Sezon Seçilmedi', 'İçe aktarım öncesi sezon seçmelisiniz. Sezonu olmayan kayıtlar Suç Duyurusu Takip listesinde görünmez.');
                    $('#importSezonId').select2('open');
                    return;
                }
                const sezonAd = $('#importSezonId').find('option:selected').text();
                confirmAction('Verileri import etmek istediğinize emin misiniz?', 'Kayıtlar "' + sezonAd + '" sezonuna bağlanacak. Yeni işletmeciler ve taraflar otomatik oluşturulacak.', function() {
                    $('#importBtn').prop('disabled', true).html('<i class="bi bi-hourglass-split"></i> İçe aktarılıyor...');

                    $.post('', { action: 'import', sezon_id: sezonId }, function(res) {
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
                    <td>${row.tespit_durum || '-'}</td>
                    <td>${row.tespit_adet || '-'}</td>
                    <td>${row.tespit_tarihi ? formatDisplayDate(row.tespit_tarihi) : '-'}</td>
                    <td>${row.tespit_turu || '-'}</td>
                    <td>${getStatusBadge(row.isletmeci, row.musteri_status)}</td>
                    <td><small>${truncate(row.isyeri_adi, 30)}</small></td>
                    <td>${getStatusBadge(row.sehir, row.sehir_status, true)}</td>
                    <td>${getStatusBadge(row.ilce, row.ilce_status, true)}</td>
                    <td>${getStatusBadge(row.taraf, row.taraf_status)}</td>
                    <td><small>${truncate(row.adres, 40)}</small></td>
                    <td><small>${truncate(row.aciklama, 30)}</small></td>
                    <td>${row.has_error ? '<span class="badge bg-danger">Hata</span>' : '<span class="badge bg-success">OK</span>'}</td>
                </tr>`;
            });
            $('#previewBody').html(html);
        }
        
        function getStatusBadge(text, status, isLocation = false) {
            if (!text) return '<span class="text-muted">-</span>';
            
            let badgeClass = 'bg-warning text-dark';
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
            $('#sum-errors').text(summary.errors);
        }
        
        function truncate(str, len) {
            if (!str) return '';
            return str.length > len ? str.substring(0, len) + '...' : str;
        }
    </script>
</body>
</html>
