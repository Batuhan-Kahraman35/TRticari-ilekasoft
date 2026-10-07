<?php
/**
 * Admin Panel - Cari Ekleme/Düzenleme Formu
 * 
 * Cari (Müşteri/Tedarikçi) ekleme ve düzenleme işlemleri
 */

require_once __DIR__ . '/../auth.php';
require_once __DIR__ . '/../includes/LogHelper.php';
require_once __DIR__ . '/../includes/SmsHelper.php';
require_once __DIR__ . '/../includes/PageAuth.php';
requireAuth();

$user = Auth::user();
$db = Database::getInstance();

// Silme butonunun görünürlüğü için ana sayfa (cari-yonetimi) yetkileri
$pagePermissions = PageAuth::checkPagePermissions($user['kullanici_id'], $user['departman_id'], 'cari-yonetimi.php');

// id varsa düzenleme modu
$editMode = isset($_GET['id']) && intval($_GET['id']) > 0;
$cariid = $editMode ? intval($_GET['id']) : 0;
$cari = null;

// Mevcut cari bilgilerini çek
if ($editMode) {
    $cari = $db->fetchOne("
        SELECT 
            c.*,
            CONVERT(VARCHAR(19), c.cari_olusturma_tarihi, 120) as cari_olusturma_tarihi,
            CONVERT(VARCHAR(19), c.cari_guncelleme_tarihi, 120) as cari_guncelleme_tarihi,
            olusturan.kullanici_ad + ' ' + olusturan.kullanici_soyad as olusturan_adi,
            guncelleyen.kullanici_ad + ' ' + guncelleyen.kullanici_soyad as guncelleyen_adi
        FROM Cari c
        LEFT JOIN kullanicilar olusturan ON c.cari_olusturan_kullanici = olusturan.kullanici_id
        LEFT JOIN kullanicilar guncelleyen ON c.cari_guncelleyen_kullanici = guncelleyen.kullanici_id
        WHERE c.cari_id = ?
    ", [$cariid]);
    
    if (!$cari) {
        header('Location: /Admin/cari-yonetimi?error=notfound');
        exit;
    }
}

// Sayfa başliği
$pageTitle = $editMode ? 'Cari Düzenle' : 'Yeni Cari Ekle';

// Mevcut sayfanın bilgilerini al
$pageinfo = $db->fetchOne("
    SELECT 
        m.menuler_menu_adi as menu_adi
    FROM Menu_Sayfalar s
    LEFT JOIN Menuler m ON s.sayfalar_menu_id = m.menuler_id
    WHERE s.sayfalar_sayfa_url LIKE ? AND s.sayfalar_durum = 1
", ['%cari-yonetimi.php']);

$menuAdi = $pageinfo['menu_adi'] ?? null;

// site title'i çek
$siteAyarlari = $db->fetchOne("SELECT TOP 1 site_ayarlari_site_title FROM dbo.tanim_site_ayarlari ORDER BY site_ayarlari_id DESC");
$siteTitle = $siteAyarlari['site_ayarlari_site_title'] ?? 'Ornek Yazilim Portal';

// Dropdown verileri
$ulkeler = $db->fetchAll("SELECT UlkeId, UlkeAdi FROM Adres_Ulkeler ORDER BY UlkeAdi");
$sehirler = $db->fetchAll("SELECT SehirId, SehirAdi, UlkeId FROM Adres_Sehirler ORDER BY SehirAdi");
$ilceler = $db->fetchAll("SELECT ilceId, SehirId, IlceAdi FROM Adres_Ilceler ORDER BY IlceAdi");
$cariTipleri = $db->fetchAll("SELECT cari_tipi_id, cari_tipi_ad, cari_tipi_ikon, cari_tipi_renk FROM Cari_CariTipleri WHERE cari_tipi_durum = 1 ORDER BY cari_tipi_sira");
$sektorler   = $db->fetchAll("SELECT sektor_id, sektor_ad, sektor_ikon, sektor_renk FROM dbo.Cari_Sektorler WHERE Durum = 1 ORDER BY sektor_sira_no, sektor_ad");

// AJAX işlemleri
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json');
    
    try {
        $action = $_POST['action'] ?? '';
        
        if ($action === 'kaydet') {
            $id = intval($_POST['cari_id'] ?? 0);
            
            $data = [
                'cari_adi' => trim($_POST['cari_adi'] ?? ''),
                'cari_unvan' => trim($_POST['cari_unvan'] ?? ''),
                'cari_vergi_dairesi' => trim($_POST['cari_vergi_dairesi'] ?? ''),
                'cari_vergi_no' => trim($_POST['cari_vergi_no'] ?? ''),
                'cari_mersis_no' => trim($_POST['cari_mersis_no'] ?? ''),
                'cari_ticaret_sicil_no' => trim($_POST['cari_ticaret_sicil_no'] ?? ''),
                'cari_telefon' => trim($_POST['cari_telefon'] ?? ''),
                'cari_email' => trim($_POST['cari_email'] ?? ''),
                'cari_adres' => trim($_POST['cari_adres'] ?? ''),
                'cari_posta_kodu' => trim($_POST['cari_posta_kodu'] ?? ''),
                'cari_Yetkili_adi' => trim($_POST['cari_Yetkili_adi'] ?? ''),
                'cari_Yetkili_telefon' => trim($_POST['cari_Yetkili_telefon'] ?? ''),
                'cari_Yetkili_email' => trim($_POST['cari_Yetkili_email'] ?? ''),
                'cari_ulke' => !empty($_POST['cari_ulke']) ? intval($_POST['cari_ulke']) : null,
                'cari_sehirler' => !empty($_POST['cari_sehirler']) ? intval($_POST['cari_sehirler']) : null,
                'cari_ilceler' => !empty($_POST['cari_ilceler']) ? intval($_POST['cari_ilceler']) : null,
                'cari_tipi_id' => !empty($_POST['cari_tipi_id']) ? intval($_POST['cari_tipi_id']) : null,
                'cari_sektor_id' => !empty($_POST['cari_sektor_id']) ? intval($_POST['cari_sektor_id']) : null,
                'cari_aktif' => isset($_POST['cari_aktif']) ? 1 : 0
            ];
            
            // Validasyon
            if (empty($data['cari_adi'])) {
                throw new Exception('Cari adi zorunludur');
            }
            if (empty($data['cari_unvan'])) {
                throw new Exception('Ünvan zorunludur');
            }
            if (empty($data['cari_vergi_no'])) {
                throw new Exception('Vergi No zorunludur');
            }
            if (!preg_match('/^\d{10,11}$/', $data['cari_vergi_no'])) {
                throw new Exception('Vergi No 10 veya 11 haneli rakamdan oluşmalıdır');
            }
            if (empty($data['cari_telefon'])) {
                throw new Exception('Telefon numarası zorunludur');
            }
            if (SmsHelper::normalizeTelefon($data['cari_telefon']) === null) {
                throw new Exception('Geçerli bir cep telefonu numarası giriniz (örn. 5XX XXX XX XX)');
            }
            if (empty($data['cari_ulke'])) {
                throw new Exception('Ülke seçimi zorunludur');
            }
            if (empty($data['cari_sehirler'])) {
                throw new Exception('Şehir seçimi zorunludur');
            }
            
            // Mükerrer vergi no kontrolü
            $mukerrerParams = [$data['cari_vergi_no']];
            $mukerrerSql = "SELECT cari_id, cari_adi FROM Cari WHERE cari_vergi_no = ?";
            if ($id > 0) {
                $mukerrerSql .= " AND cari_id <> ?";
                $mukerrerParams[] = $id;
            }
            $mukerrer = $db->fetchOne($mukerrerSql, $mukerrerParams);
            if ($mukerrer) {
                $msg = 'Bu Vergi No ile kayıtlı başka bir cari mevcut: ' . $mukerrer['cari_adi'];
                echo json_encode(['success' => false, 'cari_id' => $mukerrer['cari_id'], 'message' => $msg], JSON_UNESCAPED_UNICODE);
                exit;
            }
            
            if ($id > 0) {
                // GÜNCELLEME
                // Eski kaydı al (log için)
                $eskiCari = $db->fetchOne("SELECT * FROM Cari WHERE cari_id = ?", [$id]);
                
                $data['cari_guncelleme_tarihi'] = date('Y-m-d H:i:s');
                $data['cari_guncelleyen_kullanici'] = $user['kullanici_id'];
                
                $db->update('Cari', $data, ['cari_id' => $id]);
                
                // Yeni kaydı al ve log oluştur
                $yeniCari = $db->fetchOne("SELECT * FROM Cari WHERE cari_id = ?", [$id]);
                logKayitDegisiklikleri($db, 'cari-form', 'Cari', $id, $eskiCari, $yeniCari, $user['kullanici_id'], 'Cari güncellendi');
                
                echo json_encode(['success' => true, 'message' => 'Cari başarıyla güncellendi', 'id' => $id]);
            } else {
                // YENİ KAYIT
                $data['cari_olusturma_tarihi'] = date('Y-m-d H:i:s');
                $data['cari_olusturan_kullanici'] = $user['kullanici_id'];
                
                $newid = $db->insert('Cari', $data);

                // Yeni kaydı al ve log oluştur
                $yeniCari = $db->fetchOne("SELECT * FROM Cari WHERE cari_id = ?", [$newid]);
                logKayitDegisiklikleri($db, 'cari-form', 'Cari', $newid, null, $yeniCari, $user['kullanici_id'], 'Yeni cari oluşturuldu');

                // Yeni cariye otomatik hoş geldin + aydınlatma metni SMS'i
                $smsUyari = null;
                try {
                    $smsAyar = $db->fetchOne("SELECT TOP 1 site_ayarlari_hosgeldin_sms_metin, site_ayarlari_aydinlatma_link FROM dbo.tanim_site_ayarlari ORDER BY site_ayarlari_id DESC");
                    $sablon = trim($smsAyar['site_ayarlari_hosgeldin_sms_metin'] ?? '');
                    $link   = trim($smsAyar['site_ayarlari_aydinlatma_link'] ?? '');
                    if ($sablon !== '') {
                        $mesaj = str_replace(['{link}', '{cari_adi}'], [$link, $data['cari_adi']], $sablon);
                        $smsSonuc = SmsHelper::gonder($data['cari_telefon'], $mesaj, $user['kullanici_id'], 'Cari hoş geldin SMS');
                        if (!$smsSonuc['success']) {
                            $smsUyari = 'Cari kaydedildi ancak SMS gönderilemedi: ' . $smsSonuc['message'];
                        }
                    }
                } catch (\Throwable $e) {
                    $smsUyari = 'Cari kaydedildi ancak SMS gönderiminde hata oluştu.';
                }

                echo json_encode(['success' => true, 'message' => 'Cari başarıyla eklendi', 'id' => $newid, 'sms_uyari' => $smsUyari], JSON_UNESCAPED_UNICODE);
            }
            exit;
        }
        
        if ($action === 'vergi_no_kontrol') {
            $vergiNo = trim($_POST['vergi_no'] ?? '');
            $cariId  = intval($_POST['cari_id'] ?? 0);
            
            if (empty($vergiNo)) { echo json_encode(['success' => true]); exit; }
            
            $params = [$vergiNo];
            $sql = "SELECT cari_id, cari_adi FROM Cari WHERE cari_vergi_no = ?";
            if ($cariId > 0) { $sql .= " AND cari_id <> ?"; $params[] = $cariId; }
            
            $mevcut = $db->fetchOne($sql, $params);
            if ($mevcut) {
                $msg = 'Bu Vergi No ile kayıtlı cari mevcut: ' . $mevcut['cari_adi'];
                echo json_encode(['success' => false, 'cari_id' => $mevcut['cari_id'], 'message' => $msg], JSON_UNESCAPED_UNICODE);
            } else {
                echo json_encode(['success' => true]);
            }
            exit;
        }
        
        if ($action === 'dosya_listele') {
            $cari_id = intval($_POST['cari_id'] ?? 0);
            if (!$cari_id) throw new Exception('Geçersiz cari ID');
            
            $dosyalar = $db->fetchAll("
                SELECT
                    dosya_id,
                    dosya_orijinal,
                    dosya_adi,
                    dosya_yol,
                    dosya_boyut,
                    dosya_tip,
                    dosya_aciklama,
                    CONVERT(VARCHAR(19), dosya_tarih, 120) as dosya_tarih,
                    k.kullanici_ad + ' ' + k.kullanici_soyad as yukleyen_adi
                FROM Cari_Dosyalar cd
                LEFT JOIN kullanicilar k ON cd.dosya_yukleyen = k.kullanici_id
                WHERE cd.cari_id = ?
                ORDER BY cd.dosya_tarih DESC
            ", [$cari_id]);
            
            echo json_encode(['success' => true, 'data' => $dosyalar]);
            exit;
        }
        
        if ($action === 'dosya_yukle') {
            $cari_id = intval($_POST['cari_id'] ?? 0);
            if (!$cari_id) throw new Exception('Önce cariyi kaydedin');
            
            if (empty($_FILES['dosyalar'])) throw new Exception('Dosya seçilmedi');
            
            $uploadDir = __DIR__ . '/../assets/uploads/cariler/' . $cari_id . '/';
            if (!is_dir($uploadDir)) mkdir($uploadDir, 0755, true);
            
            $izinliTipler = [
                'image/jpeg', 'image/png', 'image/gif', 'image/webp',
                'application/pdf',
                'application/msword',
                'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
                'application/vnd.ms-excel',
                'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
                'text/plain', 'text/csv',
                'application/zip', 'application/x-rar-compressed'
            ];
            $maxBoyut = 20 * 1024 * 1024; // 20 MB
            
            $yuklenenler = [];
            $files = $_FILES['dosyalar'];
            $count = is_array($files['name']) ? count($files['name']) : 1;
            
            for ($i = 0; $i < $count; $i++) {
                $name     = is_array($files['name'])  ? $files['name'][$i]     : $files['name'];
                $type     = is_array($files['type'])  ? $files['type'][$i]     : $files['type'];
                $tmp      = is_array($files['tmp_name']) ? $files['tmp_name'][$i] : $files['tmp_name'];
                $error    = is_array($files['error']) ? $files['error'][$i]    : $files['error'];
                $size     = is_array($files['size'])  ? $files['size'][$i]     : $files['size'];
                
                if ($error !== UPLOAD_ERR_OK) continue;
                if (!in_array($type, $izinliTipler)) throw new Exception(htmlspecialchars($name) . ' - Desteklenmeyen dosya türü');
                if ($size > $maxBoyut) throw new Exception(htmlspecialchars($name) . ' - Dosya boyutu 20 MB sınırını aşıyor');
                
                $ext      = strtolower(pathinfo($name, PATHINFO_EXTENSION));
                $yeniAd   = uniqid('cari_', true) . '.' . $ext;
                $hedef    = $uploadDir . $yeniAd;
                
                if (!move_uploaded_file($tmp, $hedef)) throw new Exception('Dosya yüklenemedi: ' . htmlspecialchars($name));
                
                $db->insert('Cari_Dosyalar', [
                    'cari_id'       => $cari_id,
                    'dosya_adi'     => $yeniAd,
                    'dosya_orijinal'=> $name,
                    'dosya_yol'     => '/Admin/assets/uploads/cariler/' . $cari_id . '/' . $yeniAd,
                    'dosya_boyut'   => $size,
                    'dosya_tip'     => $type,
                    'dosya_yukleyen'=> $user['kullanici_id'],
                    'dosya_tarih'   => date('Y-m-d H:i:s')
                ]);
                
                $yuklenenler[] = $name;
            }
            
            echo json_encode(['success' => true, 'message' => count($yuklenenler) . ' dosya yüklendi', 'dosyalar' => $yuklenenler]);
            exit;
        }
        
        if ($action === 'dosya_sil') {
            $dosya_id = intval($_POST['dosya_id'] ?? 0);
            if (!$dosya_id) throw new Exception('Geçersiz dosya ID');
            
            $dosya = $db->fetchOne("SELECT * FROM Cari_Dosyalar WHERE dosya_id = ?", [$dosya_id]);
            if (!$dosya) throw new Exception('Dosya bulunamadı');
            
            $fizikselYol = __DIR__ . '/../assets/uploads/cariler/' . $dosya['cari_id'] . '/' . $dosya['dosya_adi'];
            if (file_exists($fizikselYol)) @unlink($fizikselYol);
            
            $db->execute("DELETE FROM Cari_Dosyalar WHERE dosya_id = ?", [$dosya_id]);
            
            echo json_encode(['success' => true, 'message' => 'Dosya silindi']);
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
    <link rel="stylesheet" href="/Admin/assets/css/Adminlte.min.css">
    <link href="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css" rel="stylesheet" />
    <link href="https://cdn.jsdelivr.net/npm/select2-bootstrap-5-theme@1.3.0/dist/select2-bootstrap-5-theme.min.css" rel="stylesheet" />
    <link href="https://cdn.jsdelivr.net/npm/sweetalert2@11/dist/sweetalert2.min.css" rel="stylesheet">
    <link rel="stylesheet" href="/Admin/assets/css/custom.css">
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
                                <li class="breadcrumb-item"><a href="/Admin/cari-yonetimi">Cari Yönetimi</a></li>
                                <li class="breadcrumb-item active"><?= htmlspecialchars($pageTitle) ?></li>
                            </ol>
                        </div>
                    </div>
                </div>
            </div>
            
            <!-- Sayfa içeriği -->
            <div class="app-content">
                <div class="container-fluid">
                    
                    <form id="cariForm" class="needs-validation" novalidate>
                        <input type="hidden" id="cari_id" name="cari_id" value="<?= $cariid ?>">
                        
                        <div class="row">
                            <!-- Sol Kolon - Ana bilgiler -->
                            <div class="col-lg-8">
                                
                                <!-- Genel bilgiler -->
                                <div class="card card-primary card-outline mb-3">
                                    <div class="card-header">
                                        <h3 class="card-title"><i class="bi bi-info-circle"></i> Genel bilgiler</h3>
                                    </div>
                                    <div class="card-body">
                                        <div class="row g-3">
                                            <div class="col-md-6">
                                                <label for="cari_adi" class="form-label">Cari Adi <span class="text-danger">*</span></label>
                                                <input type="text" class="form-control" id="cari_adi" name="cari_adi" 
                                                       value="<?= htmlspecialchars($cari['cari_adi'] ?? '') ?>" required>
                                                <div class="invalid-feedback">Cari adi zorunludur.</div>
                                            </div>
                                            
                                            <div class="col-md-6">
                                                <label for="cari_unvan" class="form-label">Ünvan <span class="text-danger">*</span></label>
                                                <input type="text" class="form-control" id="cari_unvan" name="cari_unvan" 
                                                       value="<?= htmlspecialchars($cari['cari_unvan'] ?? '') ?>" required>
                                                <div class="invalid-feedback">Ünvan zorunludur.</div>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                                
                                <!-- Vergi bilgileri -->
                                <div class="card card-primary card-outline mb-3">
                                    <div class="card-header">
                                        <h3 class="card-title"><i class="bi bi-receipt"></i> Vergi bilgileri</h3>
                                    </div>
                                    <div class="card-body">
                                        <div class="row g-3">
                                            <div class="col-md-6">
                                                <label for="cari_vergi_dairesi" class="form-label">Vergi Dairesi</label>
                                                <input type="text" class="form-control" id="cari_vergi_dairesi" name="cari_vergi_dairesi" 
                                                       value="<?= htmlspecialchars($cari['cari_vergi_dairesi'] ?? '') ?>">
                                            </div>
                                            
                                            <div class="col-md-6">
                                                <label for="cari_vergi_no" class="form-label">Vergi No <span class="text-danger">*</span></label>
                                                <input type="text" class="form-control" id="cari_vergi_no" name="cari_vergi_no"
                                                       value="<?= htmlspecialchars($cari['cari_vergi_no'] ?? '') ?>"
                                                       inputmode="numeric" maxlength="11" required
                                                       placeholder="10 veya 11 haneli vergi numarası">
                                                <div class="invalid-feedback" id="vergiNoFeedback">Vergi No zorunludur ve sadece rakam girilmelidir.</div>
                                                <div id="vergiNoUyari" class="mt-1" style="display:none"></div>
                                            </div>
                                            
                                            <div class="col-md-6">
                                                <label for="cari_mersis_no" class="form-label">Mersis No</label>
                                                <input type="text" class="form-control" id="cari_mersis_no" name="cari_mersis_no" 
                                                       value="<?= htmlspecialchars($cari['cari_mersis_no'] ?? '') ?>">
                                            </div>
                                            
                                            <div class="col-md-6">
                                                <label for="cari_ticaret_sicil_no" class="form-label">Ticaret Sicil No</label>
                                                <input type="text" class="form-control" id="cari_ticaret_sicil_no" name="cari_ticaret_sicil_no" 
                                                       value="<?= htmlspecialchars($cari['cari_ticaret_sicil_no'] ?? '') ?>">
                                            </div>
                                        </div>
                                    </div>
                                </div>
                                
                                <!-- iletişim bilgileri -->
                                <div class="card card-primary card-outline mb-3">
                                    <div class="card-header">
                                        <h3 class="card-title"><i class="bi bi-telephone"></i> iletişim bilgileri</h3>
                                    </div>
                                    <div class="card-body">
                                        <div class="row g-3">
                                            <div class="col-md-4">
                                                <label for="cari_telefon" class="form-label">Telefon <span class="text-danger">*</span></label>
                                                <input type="tel" class="form-control" id="cari_telefon" name="cari_telefon"
                                                       value="<?= htmlspecialchars($cari['cari_telefon'] ?? '') ?>"
                                                       required placeholder="5XX XXX XX XX">
                                                <div class="invalid-feedback">Telefon numarası zorunludur.</div>
                                            </div>
                                            
                                            <div class="col-md-4">
                                                <label for="cari_email" class="form-label">email</label>
                                                <input type="email" class="form-control" id="cari_email" name="cari_email" 
                                                       value="<?= htmlspecialchars($cari['cari_email'] ?? '') ?>">
                                            </div>
                                            
                                            <div class="col-md-4">
                                                <label for="cari_posta_kodu" class="form-label">Posta Kodu</label>
                                                <input type="text" class="form-control" id="cari_posta_kodu" name="cari_posta_kodu" 
                                                       value="<?= htmlspecialchars($cari['cari_posta_kodu'] ?? '') ?>">
                                            </div>
                                            
                                            <div class="col-md-4">
                                                <label for="cari_ulke" class="form-label">Ülke <span class="text-danger">*</span></label>
                                                <select class="form-select" id="cari_ulke" name="cari_ulke" required>
                                                    <option value="">Ülke Seçiniz...</option>
                                                    <?php foreach ($ulkeler as $ulke): ?>
                                                        <option value="<?= $ulke['UlkeId'] ?>" <?= ($cari['cari_ulke'] ?? '') == $ulke['UlkeId'] ? 'selected' : '' ?>>
                                                            <?= htmlspecialchars($ulke['UlkeAdi']) ?>
                                                        </option>
                                                    <?php endforeach; ?>
                                                </select>
                                                <div class="invalid-feedback">Ülke seçimi zorunludur.</div>
                                            </div>
                                            
                                            <div class="col-md-4">
                                                <label for="cari_sehirler" class="form-label">Şehir <span class="text-danger">*</span></label>
                                                <select class="form-select" id="cari_sehirler" name="cari_sehirler" required>
                                                    <option value="">Önce Ülke Seçiniz...</option>
                                                </select>
                                                <div class="invalid-feedback">Şehir seçimi zorunludur.</div>
                                            </div>
                                            
                                            <div class="col-md-4">
                                                <label for="cari_ilceler" class="form-label">İlçe</label>
                                                <select class="form-select" id="cari_ilceler" name="cari_ilceler">
                                                    <option value="">İlçe Seçiniz...</option>
                                                </select>
                                            </div>
                                            
                                            <div class="col-12">
                                                <label for="cari_adres" class="form-label">Adres</label>
                                                <textarea class="form-control" id="cari_adres" name="cari_adres" rows="3"><?= htmlspecialchars($cari['cari_adres'] ?? '') ?></textarea>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                                
                                <!-- Yetkili bilgileri -->
                                <div class="card card-primary card-outline mb-3">
                                    <div class="card-header">
                                        <h3 class="card-title"><i class="bi bi-person-badge"></i> Yetkili bilgileri</h3>
                                    </div>
                                    <div class="card-body">
                                        <div class="row g-3">
                                            <div class="col-md-4">
                                                <label for="cari_Yetkili_adi" class="form-label">Yetkili Adi</label>
                                                <input type="text" class="form-control" id="cari_Yetkili_adi" name="cari_Yetkili_adi" 
                                                       value="<?= htmlspecialchars($cari['cari_Yetkili_adi'] ?? '') ?>">
                                            </div>
                                            
                                            <div class="col-md-4">
                                                <label for="cari_Yetkili_telefon" class="form-label">Yetkili Telefon</label>
                                                <input type="tel" class="form-control" id="cari_Yetkili_telefon" name="cari_Yetkili_telefon" 
                                                       value="<?= htmlspecialchars($cari['cari_Yetkili_telefon'] ?? '') ?>">
                                            </div>
                                            
                                            <div class="col-md-4">
                                                <label for="cari_Yetkili_email" class="form-label">Yetkili email</label>
                                                <input type="email" class="form-control" id="cari_Yetkili_email" name="cari_Yetkili_email" 
                                                       value="<?= htmlspecialchars($cari['cari_Yetkili_email'] ?? '') ?>">
                                            </div>
                                        </div>
                                    </div>
                                </div>

                                <!-- Dosya Yükleme -->
                                <div class="card card-primary card-outline mb-3" id="dosyaKarti" <?= !$editMode ? 'style="display:none"' : '' ?>>
                                    <div class="card-header">
                                        <h3 class="card-title"><i class="bi bi-paperclip"></i> Dosyalar</h3>
                                        <div class="card-tools">
                                            <span class="badge bg-primary" id="dosyaSayisi">0</span>
                                        </div>
                                    </div>
                                    <div class="card-body">
                                        <!-- Drop Zone -->
                                        <div id="dropZone" class="border border-2 border-dashed rounded-3 p-4 text-center mb-3"
                                             style="border-color:#0d6efd!important; background:#f8f9ff; cursor:pointer; transition:background .2s;">
                                            <i class="bi bi-cloud-upload fs-2 text-primary"></i>
                                            <p class="mb-1 fw-semibold">Dosyaları buraya sürükleyin</p>
                                            <p class="text-muted small mb-2">veya tıklayarak seçin</p>
                                            <p class="text-muted" style="font-size:11px">JPG, PNG, PDF, Word, Excel, ZIP — Maks. 20 MB/dosya</p>
                                            <input type="file" id="dosyaInput" multiple accept=".jpg,.jpeg,.png,.gif,.webp,.pdf,.doc,.docx,.xls,.xlsx,.txt,.csv,.zip,.rar" style="display:none">
                                        </div>

                                        <!-- Yükleme Progress -->
                                        <div id="uploadProgress" class="d-none mb-3">
                                            <div class="progress">
                                                <div class="progress-bar progress-bar-striped progress-bar-animated bg-primary" id="progressBar" style="width:0%"></div>
                                            </div>
                                            <small class="text-muted" id="progressText">Yükleniyor...</small>
                                        </div>

                                        <!-- Dosya Listesi -->
                                        <div id="dosyaListesi"></div>
                                    </div>
                                </div>

                                <?php if (!$editMode): ?>
                                <div class="alert alert-info mb-3">
                                    <i class="bi bi-info-circle"></i> Dosya yüklemek için önce <strong>cariyi kaydedin</strong>, ardından sayfaya yönlendirileceksiniz.
                                </div>
                                <?php endif; ?>
                                
                            </div>
                            
                            <!-- Sağ Kolon - Tip ve Durum -->
                            <div class="col-lg-4">
                                
                                <!-- Cari Tipi -->
                                <div class="card card-primary card-outline mb-3">
                                    <div class="card-header">
                                        <h3 class="card-title"><i class="bi bi-tags"></i> Cari Tipi</h3>
                                    </div>
                                    <div class="card-body">
                                        <select class="form-select" name="cari_tipi_id" id="cari_tipi_id">
                                            <option value="">Seçiniz...</option>
                                            <?php foreach ($cariTipleri as $tip): ?>
                                                <option value="<?= $tip['cari_tipi_id'] ?>"
                                                    <?= ($cari['cari_tipi_id'] ?? 1) == $tip['cari_tipi_id'] ? 'selected' : '' ?>>
                                                    <i class="<?= htmlspecialchars($tip['cari_tipi_ikon']) ?>"></i>
                                                    <?= htmlspecialchars($tip['cari_tipi_ad']) ?>
                                                </option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                </div>

                                <!-- Sektör -->
                                <div class="card card-primary card-outline mb-3">
                                    <div class="card-header">
                                        <h3 class="card-title"><i class="bi bi-diagram-3"></i> Sektör</h3>
                                    </div>
                                    <div class="card-body">
                                        <select class="form-select" name="cari_sektor_id" id="cari_sektor_id">
                                            <option value="">Seçiniz...</option>
                                            <?php foreach ($sektorler as $sektor): ?>
                                                <option value="<?= $sektor['sektor_id'] ?>"
                                                    <?= ($cari['cari_sektor_id'] ?? '') == $sektor['sektor_id'] ? 'selected' : '' ?>>
                                                    <?= htmlspecialchars($sektor['sektor_ad']) ?>
                                                </option>
                                            <?php endforeach; ?>
                                        </select>
                                        <small class="text-muted">Sektör tanımları <strong>Tanımlar &gt; Sektör Yönetimi</strong> sayfasından yönetilir.</small>
                                    </div>
                                </div>

                                <!-- Durum -->
                                <div class="card card-primary card-outline mb-3">
                                    <div class="card-header">
                                        <h3 class="card-title"><i class="bi bi-toggle-on"></i> Durum</h3>
                                    </div>
                                    <div class="card-body">
                                        <div class="form-check form-switch">
                                            <input class="form-check-input" type="checkbox" role="switch" id="cari_aktif" name="cari_aktif" 
                                                   <?= ($cari['cari_aktif'] ?? 1) == 1 ? 'checked' : '' ?>>
                                            <label class="form-check-label" for="cari_aktif">
                                                Cari Aktif
                                            </label>
                                        </div>
                                    </div>
                                </div>
                                
                                <?php if ($editMode && $cari): ?>
                                <!-- Kayıt bilgileri -->
                                <div class="card card-secondary card-outline mb-3">
                                    <div class="card-header">
                                        <h3 class="card-title"><i class="bi bi-clock-history"></i> Kayıt bilgileri</h3>
                                    </div>
                                    <div class="card-body">
                                        <small class="text-muted">
                                            <?php if ($cari['cari_olusturma_tarihi']): ?>
                                            <div class="mb-2">
                                                <strong>Oluşturulma:</strong><br>
                                                <?= $cari['cari_olusturma_tarihi'] ?>
                                                <?php if ($cari['olusturan_adi']): ?>
                                                    <br><span class="text-primary"><?= htmlspecialchars($cari['olusturan_adi']) ?></span>
                                                <?php endif; ?>
                                            </div>
                                            <?php endif; ?>
                                            
                                            <?php if ($cari['cari_guncelleme_tarihi']): ?>
                                            <div>
                                                <strong>Son Güncelleme:</strong><br>
                                                <?= $cari['cari_guncelleme_tarihi'] ?>
                                                <?php if ($cari['guncelleyen_adi']): ?>
                                                    <br><span class="text-primary"><?= htmlspecialchars($cari['guncelleyen_adi']) ?></span>
                                                <?php endif; ?>
                                            </div>
                                            <?php endif; ?>
                                        </small>
                                    </div>
                                </div>
                                <?php endif; ?>
                                
                                <!-- Butonlar -->
                                <div class="card card-primary card-outline">
                                    <div class="card-body">
                                        <div class="d-grid gap-2">
                                            <button type="submit" class="btn btn-primary btn-lg">
                                                <i class="bi bi-check-lg"></i> <?= $editMode ? 'Güncelle' : 'Kaydet' ?>
                                            </button>
                                            <button type="button" class="btn btn-success btn-lg" onclick="saveCariAndContinue()">
                                                <i class="bi bi-file-earmark-text"></i> Kaydet ve Sözleşmeye Devam Et
                                            </button>
                                            <a href="/Admin/cari-yonetimi" class="btn btn-secondary">
                                                <i class="bi bi-arrow-left"></i> Geri Dön
                                            </a>
                                            <?php if ($editMode): ?>
                                            <a href="/admin/pages/degisiklik-log.php?tablo=Cari&kayit_id=<?= $cariid ?>" class="btn btn-info" target="_blank">
                                                <i class="bi bi-clock-history"></i> Log Geçmişi
                                            </a>
                                            <?php endif; ?>
                                            <?php if ($editMode && $pagePermissions['can_delete']): ?>
                                            <button type="button" class="btn btn-danger" onclick="formKayitSil('/Admin/cari-yonetimi', { action: 'delete', cari_id: <?= $cariid ?> }, '/Admin/cari-yonetimi', 'Bu cariyi silmek istediğinize emin misiniz?')">
                                                <i class="bi bi-trash"></i> Sil
                                            </button>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                </div>
                                
                            </div>
                        </div>
                        
                    </form>
                    
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
    
    <!-- AdminLTE JS -->
    <script src="/Admin/assets/js/Adminlte.min.js"></script>
    
    <!-- Select2 JS -->
    <script src="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js"></script>
    
    <!-- SweetAlert2 JS -->
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11/dist/sweetalert2.all.min.js"></script>
    
    <!-- Custom JS -->
    <script src="/Admin/assets/js/custom.js"></script>
    
    <script>
        // Şehir ve İlçe listeleri
        const allSehirler = <?= json_encode($sehirler) ?>;
        const allIlceler = <?= json_encode($ilceler) ?>;
        const selectedSehir = <?= json_encode($cari['cari_sehirler'] ?? null) ?>;
        const selectedIlce = <?= json_encode($cari['cari_ilceler'] ?? null) ?>;
        
        $(document).ready(function() {
            // Select2 init
            $('#cari_ulke, #cari_sehirler, #cari_ilceler, #cari_tipi_id, #cari_sektor_id').select2({
                theme: 'bootstrap-5',
                width: '100%',
                allowClear: true,
                language: {
                    noResults: function() { return "Sonuç bulunamadı"; },
                    searching: function() { return "Aranıyor..."; }
                }
            });
            
            // Ülke değiştiğinde şehirleri yükle
            $('#cari_ulke').on('change', function() {
                loadSehirler();
                loadIlceler(); // İlçeleri de sıfırla
            });
            
            // Şehir değiştiğinde ilçeleri yükle
            $('#cari_sehirler').on('change', function() {
                loadIlceler();
            });
            
            // Sayfa yüklendiğinde (düzenleme modunda) şehir ve ilçeleri yükle
            if ($('#cari_ulke').val()) {
                loadSehirler(selectedSehir);
                if (selectedSehir) {
                    loadIlceler(selectedIlce);
                }
            }
            
            // Form submit
            $('#cariForm').on('submit', function(e) {
                e.preventDefault();
                
                if (!this.checkValidity()) {
                    e.stopPropagation();
                    $(this).addClass('was-validated');
                    return;
                }
                
                saveCari();
            });
        });
        
        // Şehirleri yükle (Ülke'ye göre)
        function loadSehirler(selectedValue = null) {
            const ulkeId = $('#cari_ulke').val();
            const sehirSelect = $('#cari_sehirler');
            
            sehirSelect.html('<option value="">Şehir Seçiniz...</option>');
            
            if (ulkeId) {
                const filteredSehirler = allSehirler.filter(s => s.UlkeId == ulkeId);
                filteredSehirler.forEach(sehir => {
                    const selected = selectedValue == sehir.SehirId ? ' selected' : '';
                    sehirSelect.append(`<option value="${sehir.SehirId}"${selected}>${sehir.SehirAdi}</option>`);
                });
                sehirSelect.prop('disabled', false);
            } else {
                sehirSelect.html('<option value="">Önce Ülke Seçiniz...</option>');
                sehirSelect.prop('disabled', true);
            }
            
            sehirSelect.trigger('change.select2');
        }
        
        // İlçeleri yükle (Şehir'e göre)
        function loadIlceler(selectedValue = null) {
            const sehirId = $('#cari_sehirler').val();
            const ilceSelect = $('#cari_ilceler');
            
            ilceSelect.html('<option value="">İlçe Seçiniz...</option>');
            
            if (sehirId) {
                const filteredIlceler = allIlceler.filter(ilce => ilce.SehirId == sehirId);
                filteredIlceler.forEach(ilce => {
                    const selected = selectedValue == ilce.ilceId ? ' selected' : '';
                    ilceSelect.append(`<option value="${ilce.ilceId}"${selected}>${ilce.IlceAdi}</option>`);
                });
                ilceSelect.prop('disabled', false);
            } else {
                ilceSelect.html('<option value="">Önce Şehir Seçiniz...</option>');
                ilceSelect.prop('disabled', true);
            }
            
            ilceSelect.trigger('change.select2');
        }
        
        // Kaydet
        function saveCari(redirectToSozlesme = false) {
            const formData = new FormData($('#cariForm')[0]);
            formData.append('action', 'kaydet');
            
            $.ajax({
                url: '',
                method: 'POST',
                data: formData,
                processData: false,
                contentType: false,
                success: function(response) {
                    if (response.success) {
                        if (response.sms_uyari) {
                            Swal.fire({ icon: 'warning', title: 'Cari kaydedildi', text: response.sms_uyari, confirmButtonText: 'Tamam' });
                        } else {
                            showSuccess('Başarılı!', response.message);
                        }
                        const bekleme = response.sms_uyari ? 3000 : 1500;
                        setTimeout(function() {
                            if (redirectToSozlesme && response.id) {
                                window.location.href = '/Admin/sozlesme-form?cari_id=' + response.id;
                            } else {
                                window.location.href = '/Admin/cari-yonetimi';
                            }
                        }, bekleme);
                    } else {
                        if (response.cari_id) {
                            Swal.fire({
                                icon: 'error',
                                title: 'M\u00FCkerrer Vergi No!',
                                text: response.message,
                                showCancelButton: true,
                                confirmButtonText: '<i class="bi bi-box-arrow-up-right"></i> Cari\'ye Git',
                                cancelButtonText: 'Tamam',
                                confirmButtonColor: '#ffc107',
                                cancelButtonColor: '#6c757d'
                            }).then(function(result) {
                                if (result.isConfirmed) {
                                    window.location.href = '/Admin/cari-form?id=' + response.cari_id;
                                }
                            });
                        } else {
                            showError('Hata!', response.message);
                        }
                    }
                },
                error: function() {
                    showError('Hata!', 'Sunucuya ba\u011Flan\u0131lamad\u0131.');
                }
            });
        }
        
        // Kaydet ve Sözleşmeye Devam Et
        function saveCariAndContinue() {
            const form = $('#cariForm')[0];
            if (!form.checkValidity()) {
                $(form).addClass('was-validated');
                return;
            }
            saveCari(true);
        }

        // ===== VERGİ NO =====
        // Sadece rakam girişi
        document.getElementById('cari_vergi_no').addEventListener('input', function() {
            this.value = this.value.replace(/[^0-9]/g, '');
        });

        // Blur'da mükerrer kontrol
        document.getElementById('cari_vergi_no').addEventListener('blur', function() {
            const vergiNo = this.value.trim();
            const uyariDiv = document.getElementById('vergiNoUyari');
            if (vergiNo.length < 10) { uyariDiv.style.display = 'none'; return; }

            const cariId = document.getElementById('cari_id').value;
            $.post('', { action: 'vergi_no_kontrol', vergi_no: vergiNo, cari_id: cariId }, function(res) {
                if (!res.success) {
                    let btnHtml = res.cari_id
                        ? ' <a href="/Admin/cari-form?id=' + res.cari_id + '" class="btn btn-warning btn-sm py-0 px-2 ms-2"><i class="bi bi-box-arrow-up-right"></i> Cari\'ye Git</a>'
                        : '';
                    uyariDiv.innerHTML = '<span class="text-danger"><i class="bi bi-exclamation-triangle-fill"></i> ' + res.message + '</span>' + btnHtml;
                    uyariDiv.style.display = 'block';
                } else {
                    uyariDiv.style.display = 'none';
                }
            });
        });

        // ===== DOSYA YÜKLEME =====
        const cariId = <?= $cariid ?>;
        const editMode = <?= $editMode ? 'true' : 'false' ?>;

        // İkon belirle
        function dosyaIkon(tip) {
            if (!tip) return 'bi-file-earmark';
            if (tip.includes('image'))          return 'bi-file-image text-info';
            if (tip.includes('pdf'))            return 'bi-file-pdf text-danger';
            if (tip.includes('word') || tip.includes('document')) return 'bi-file-word text-primary';
            if (tip.includes('excel') || tip.includes('sheet'))   return 'bi-file-excel text-success';
            if (tip.includes('zip') || tip.includes('rar'))       return 'bi-file-zip text-warning';
            return 'bi-file-earmark text-secondary';
        }

        // Boyut formatla
        function dosyaBoyut(bytes) {
            if (!bytes) return '';
            if (bytes < 1024) return bytes + ' B';
            if (bytes < 1048576) return (bytes / 1024).toFixed(1) + ' KB';
            return (bytes / 1048576).toFixed(1) + ' MB';
        }

        // Dosya listesini yükle
        function dosyaListeYukle() {
            if (!editMode || !cariId) return;
            $.post('', { action: 'dosya_listele', cari_id: cariId }, function(res) {
                if (!res.success) return;
                const liste = $('#dosyaListesi');
                $('#dosyaSayisi').text(res.data.length);
                if (res.data.length === 0) {
                    liste.html('<p class="text-muted text-center small mb-0"><i class="bi bi-inbox"></i> Henüz dosya yüklenmedi</p>');
                    return;
                }
                let html = '<div class="list-group list-group-flush">';
                res.data.forEach(function(d) {
                    const ikon = dosyaIkon(d.dosya_tip);
                    const boyut = dosyaBoyut(d.dosya_boyut);
                    const isResim = d.dosya_tip && d.dosya_tip.includes('image');
                    html += `
                        <div class="list-group-item px-0 py-2 d-flex align-items-center gap-2" id="dosya_${d.dosya_id}">
                            <i class="bi ${ikon} fs-4 flex-shrink-0"></i>
                            <div class="flex-grow-1 min-width-0">
                                <div class="fw-semibold text-truncate" title="${d.dosya_orijinal}" style="max-width:280px">${d.dosya_orijinal}</div>
                                <small class="text-muted">${boyut} &bull; ${d.dosya_tarih ?? ''} &bull; ${d.yukleyen_adi ?? ''}</small>
                            </div>
                            <div class="d-flex gap-1 flex-shrink-0">
                                ${isResim ? `<a href="${d.dosya_yol}" target="_blank" class="btn btn-sm btn-outline-info"><i class="bi bi-eye"></i></a>` : ''}
                                <a href="${d.dosya_yol}" download="${d.dosya_orijinal}" class="btn btn-sm btn-outline-secondary"><i class="bi bi-download"></i></a>
                                <button type="button" class="btn btn-sm btn-outline-danger" onclick="dosyaSil(${d.dosya_id})"><i class="bi bi-trash"></i></button>
                            </div>
                        </div>`;
                });
                html += '</div>';
                liste.html(html);
            });
        }

        // Dosya yükle
        function dosyaYukle(files) {
            if (!cariId) {
                showToast('Önce cariyi kaydedin!', 'warning');
                return;
            }
            if (!files || files.length === 0) return;

            const formData = new FormData();
            formData.append('action', 'dosya_yukle');
            formData.append('cari_id', cariId);
            for (let i = 0; i < files.length; i++) {
                formData.append('dosyalar[]', files[i]);
            }

            $('#uploadProgress').removeClass('d-none');
            $('#progressBar').css('width', '0%');
            $('#progressText').text('Yükleniyor...');

            $.ajax({
                url: '',
                method: 'POST',
                data: formData,
                processData: false,
                contentType: false,
                xhr: function() {
                    const xhr = new window.XMLHttpRequest();
                    xhr.upload.addEventListener('progress', function(e) {
                        if (e.lengthComputable) {
                            const pct = Math.round((e.loaded / e.total) * 100);
                            $('#progressBar').css('width', pct + '%');
                            $('#progressText').text(pct + '% yüklendi...');
                        }
                    });
                    return xhr;
                },
                success: function(res) {
                    $('#uploadProgress').addClass('d-none');
                    if (res.success) {
                        showToast(res.message, 'success');
                        dosyaListeYukle();
                        $('#dosyaInput').val('');
                    } else {
                        showToast(res.message, 'error');
                    }
                },
                error: function() {
                    $('#uploadProgress').addClass('d-none');
                    showToast('Yükleme sırasında hata oluştu!', 'error');
                }
            });
        }

        // Dosya sil
        function dosyaSil(dosyaId) {
            confirmAction('Bu dosyayı silmek istediğinize emin misiniz?', null, function() {
                $.post('', { action: 'dosya_sil', dosya_id: dosyaId }, function(res) {
                    if (res.success) {
                        showToast('Dosya silindi', 'success');
                        dosyaListeYukle();
                    } else {
                        showToast(res.message, 'error');
                    }
                });
            });
        }

        // Drop Zone event'leri
        $(document).ready(function() {
            if (editMode) dosyaListeYukle();

            const dropZone = document.getElementById('dropZone');
            const dosyaInput = document.getElementById('dosyaInput');

            dropZone.addEventListener('click', () => dosyaInput.click());
            dosyaInput.addEventListener('change', () => dosyaYukle(dosyaInput.files));

            dropZone.addEventListener('dragover', function(e) {
                e.preventDefault();
                dropZone.style.background = '#e8f0fe';
            });
            dropZone.addEventListener('dragleave', function() {
                dropZone.style.background = '#f8f9ff';
            });
            dropZone.addEventListener('drop', function(e) {
                e.preventDefault();
                dropZone.style.background = '#f8f9ff';
                dosyaYukle(e.dataTransfer.files);
            });
        });
    </script>
</body>
</html>
