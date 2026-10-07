<?php
/**
 * Admin Panel - Personel Ekleme/Düzenleme Formu
 * 
 * Tablo: kullanicilar
 * Alanlar: kullanici_id, kullanici_email, kullanici_sifre_hash, kullanici_ad, kullanici_soyad,
 *          kullanici_telefon, kullanici_durum, kullanici_olusturma_tarihi, kullanici_son_giris_tarihi,
 *          kullanici_departman_id, kullanici_tc_kimlik_no, kullanici_dogum_tarihi,
 *          kullanici_olusturan_id, kullanici_guncelleme_tarihi, kullanici_guncelleyen_id
 */

require_once __DIR__ . '/../auth.php';
require_once __DIR__ . '/../includes/PageAuth.php';
requireAuth();

$user = Auth::user();
$db = Database::getInstance();

/**
 * Telefonu ne girilirse girilsin 90XXXXXXXXXX formatına çevirir.
 * Örnek: "0500 000 00 01", "+90 500 000 0001", "5000000001" -> "905000000001"
 */
function personelTelefonNormalize(?string $telefon): ?string
{
    $t = preg_replace('/\D/', '', (string) $telefon);
    if ($t === '') return null;

    // Baştaki 0'ları temizle (00 uluslararası önek / 0532... dahil)
    $t = ltrim($t, '0');

    // 90 ülke kodu varsa ayır
    if (strlen($t) > 10 && strpos($t, '90') === 0) {
        $t = substr($t, 2);
    }

    // Hâlâ fazlaysa son 10 haneyi al
    if (strlen($t) > 10) {
        $t = substr($t, -10);
    }

    return '90' . $t;
}

/**
 * Ad ve soyaddan kurumsal e-posta üretir: isim.soyisim@ornekproje.com.tr
 */
function personelEpostaUret(string $ad, string $soyad, string $alan = 'ornekproje.com.tr'): string
{
    $tr = ['ç'=>'c','Ç'=>'c','ğ'=>'g','Ğ'=>'g','ı'=>'i','I'=>'i','İ'=>'i','ö'=>'o','Ö'=>'o','ş'=>'s','Ş'=>'s','ü'=>'u','Ü'=>'u'];
    $sadelestir = static function (string $metin) use ($tr): string {
        $metin = strtr($metin, $tr);
        $metin = mb_strtolower($metin, 'UTF-8');
        return preg_replace('/[^a-z0-9]+/', '', $metin);
    };

    $adPart    = $sadelestir($ad);
    $soyadPart = $sadelestir($soyad);

    if ($adPart === '' && $soyadPart === '') return '';
    if ($soyadPart === '') return $adPart . '@' . $alan;
    if ($adPart === '')    return $soyadPart . '@' . $alan;

    return $adPart . '.' . $soyadPart . '@' . $alan;
}

// Sayfa yetki kontrolü - Ana sayfanın yetkilerini kullan
$parentPagefile = 'personel-yonetimi.php';
$pagePermissions = PageAuth::checkPagePermissions(
    $user['kullanici_id'],
    $user['departman_id'],
    $parentPagefile
);

// Sayfa erişim kontrolü
if (!$pagePermissions['has_access']) {
    PageAuth::accessDenied($pagePermissions['error'] ?? 'Bu sayfaya erişim yetkiniz bulunmamaktadır.');
}

// ID varsa düzenleme modu
$editMode = isset($_GET['id']) && intval($_GET['id']) > 0;
$kullaniciid = $editMode ? intval($_GET['id']) : 0;
$kullanici = null;

// Düzenleme modunda yetki kontrolü
if ($editMode && !$pagePermissions['can_edit']) {
    PageAuth::accessDenied('Düzenleme yetkiniz bulunmamaktadır.');
}

// Ekleme modunda yetki kontrolü
if (!$editMode && !$pagePermissions['can_add']) {
    PageAuth::accessDenied('Ekleme yetkiniz bulunmamaktadır.');
}

// Mevcut kullanıcı bilgilerini çek
if ($editMode) {
    $kullanici = $db->fetchOne("
        SELECT 
            k.*,
            CONVERT(VARCHAR(10), k.kullanici_dogum_tarihi, 120) as kullanici_dogum_tarihi,
            CONVERT(VARCHAR(19), k.kullanici_olusturma_tarihi, 120) as kullanici_olusturma_tarihi,
            CONVERT(VARCHAR(19), k.kullanici_guncelleme_tarihi, 120) as kullanici_guncelleme_tarihi,
            CONVERT(VARCHAR(19), k.kullanici_son_giris_tarihi, 120) as kullanici_son_giris_tarihi,
            olusturan.kullanici_ad + ' ' + olusturan.kullanici_soyad as olusturan_adi,
            guncelleyen.kullanici_ad + ' ' + guncelleyen.kullanici_soyad as guncelleyen_adi
        FROM kullanicilar k
        LEFT JOIN kullanicilar olusturan ON k.kullanici_olusturan_id = olusturan.kullanici_id
        LEFT JOIN kullanicilar guncelleyen ON k.kullanici_guncelleyen_id = guncelleyen.kullanici_id
        WHERE k.kullanici_id = ?
    ", [$kullaniciid]);
    
    if (!$kullanici) {
        header('Location: /Admin/personel-yonetimi?error=notfound');
        exit;
    }
}

// Sayfa başlığı
$pageTitle = $editMode ? 'Personel Düzenle' : 'Yeni Personel Ekle';

// Mevcut sayfanın bilgilerini al (Ana sayfa bilgileri)
$pageinfo = $db->fetchOne("
    SELECT 
        m.menuler_menu_adi as menu_adi
    FROM Menu_Sayfalar s
    LEFT JOIN Menuler m ON s.sayfalar_menu_id = m.menuler_id
    WHERE s.sayfalar_sayfa_url LIKE ? AND s.sayfalar_durum = 1
", ['%personel-yonetimi.php']);

$menuAdi = $pageinfo['menu_adi'] ?? null;

// Site title'ı çek
$siteAyarlari = $db->fetchOne("SELECT TOP 1 site_ayarlari_site_title FROM dbo.tanim_site_ayarlari ORDER BY site_ayarlari_id DESC");
$siteTitle = $siteAyarlari['site_ayarlari_site_title'] ?? 'Ornek Yazilim Portal';

// Dropdown verileri
$departmanlar = $db->fetchAll("SELECT departman_id, departman_adi FROM kullanici_Departmanlar WHERE departman_durum = 1 ORDER BY departman_adi");

// AJAX işlemleri
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json');
    
    try {
        $action = $_POST['action'] ?? '';
        
        if ($action === 'kaydet') {
            $id = intval($_POST['id'] ?? 0);
            $email = trim($_POST['email'] ?? '');
            $sifre = trim($_POST['sifre'] ?? '');
            $ad = trim($_POST['ad'] ?? '');
            $soyad = trim($_POST['soyad'] ?? '');
            $telefon = personelTelefonNormalize($_POST['telefon'] ?? '');
            $departman_id = !empty($_POST['departman_id']) ? intval($_POST['departman_id']) : null;
            $tc_kimlik = trim($_POST['tc_kimlik_no'] ?? '');
            $dogum_tarihi = !empty($_POST['dogum_tarihi']) ? $_POST['dogum_tarihi'] : null;
            $durum = intval($_POST['durum'] ?? 1);
            $sifre_degistirmeli = intval($_POST['sifre_degistirmeli'] ?? 1);
            
            // Validasyon - Ad Soyad zorunlu
            if (empty($ad) || empty($soyad)) {
                throw new Exception('Ad ve Soyad zorunludur');
            }
            
            // Validasyon - Departman zorunlu
            if (empty($departman_id)) {
                throw new Exception('Departman seçimi zorunludur');
            }

            // E-posta boş bırakıldıysa ad.soyad@ornekproje.com.tr üretilir
            if ($email === '') {
                $email = personelEpostaUret($ad, $soyad);
            }
            
            // Email validasyonu (opsiyonel ama doluysa geçerli olmalı)
            if (!empty($email) && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
                throw new Exception('Geçersiz email adresi');
            }
            
            // TC Kimlik No validasyonu (opsiyonel)
            if (!empty($tc_kimlik)) {
                if (strlen($tc_kimlik) !== 11) {
                    throw new Exception('TC Kimlik No 11 haneli olmalıdır');
                }
                if (!ctype_digit($tc_kimlik)) {
                    throw new Exception('TC Kimlik No sadece rakamlardan oluşmalıdır');
                }
                if ($tc_kimlik[0] === '0') {
                    throw new Exception('TC Kimlik No sıfır ile başlayamaz');
                }
            }
            
            // Yeni kayıt için şifre zorunlu
            if ($id === 0 && empty($sifre)) {
                throw new Exception('Şifre boş olamaz');
            }
            
            if ($id === 0) {
                // EKLEME
                if (!$pagePermissions['can_add']) {
                    throw new Exception('Ekleme Yetkiniz bulunmamaktadır.');
                }
                
                // Email kontrolü (email doluysa)
                if (!empty($email)) {
                    $emailKontrol = $db->fetchOne("SELECT kullanici_id FROM kullanicilar WHERE kullanici_email = ?", [$email]);
                    if ($emailKontrol) {
                        throw new Exception('Bu email adresi zaten kayıtlı');
                    }
                }
                
                $newid = $db->insert('kullanicilar', [
                    'kullanici_email' => $email ?: null,
                    'kullanici_sifre_hash' => password_hash($sifre, PASSWORD_DEFAULT),
                    'kullanici_ad' => $ad,
                    'kullanici_soyad' => $soyad,
                    'kullanici_telefon' => $telefon ?: null,
                    'kullanici_departman_id' => $departman_id,
                    'kullanici_tc_kimlik_no' => $tc_kimlik ?: null,
                    'kullanici_dogum_tarihi' => $dogum_tarihi,
                    'kullanici_durum' => $durum,
                    'kullanici_sifre_degistirmeli' => $sifre_degistirmeli,
                    'kullanici_olusturma_tarihi' => date('Y-m-d H:i:s'),
                    'kullanici_olusturan_id' => $user['kullanici_id']
                ]);
                
                echo json_encode(['success' => true, 'message' => 'Personel başarıyla eklendi', 'id' => $newid]);
                
            } else {
                // GÜNCELLEME
                if (!$pagePermissions['can_edit']) {
                    throw new Exception('Düzenleme yetkiniz bulunmamaktadır.');
                }
                
                // Email kontrolü (email doluysa ve kendisi hariç)
                if (!empty($email)) {
                    $emailKontrol = $db->fetchOne("SELECT kullanici_id FROM kullanicilar WHERE kullanici_email = ? AND kullanici_id != ?", [$email, $id]);
                    if ($emailKontrol) {
                        throw new Exception('Bu email adresi başka bir kullanıcıda kayıtlı');
                    }
                }
                
                $updateData = [
                    'kullanici_email' => $email ?: null,
                    'kullanici_ad' => $ad,
                    'kullanici_soyad' => $soyad,
                    'kullanici_telefon' => $telefon ?: null,
                    'kullanici_departman_id' => $departman_id,
                    'kullanici_tc_kimlik_no' => $tc_kimlik ?: null,
                    'kullanici_dogum_tarihi' => $dogum_tarihi,
                    'kullanici_durum' => $durum,
                    'kullanici_sifre_degistirmeli' => $sifre_degistirmeli,
                    'kullanici_guncelleme_tarihi' => date('Y-m-d H:i:s'),
                    'kullanici_guncelleyen_id' => $user['kullanici_id']
                ];
                
                // Şifre değiştirilecekse
                if (!empty($sifre)) {
                    $updateData['kullanici_sifre_hash'] = password_hash($sifre, PASSWORD_DEFAULT);
                }
                
                $db->update('kullanicilar', $updateData, ['kullanici_id' => $id]);
                
                echo json_encode(['success' => true, 'message' => 'Personel başarıyla güncellendi']);
            }
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
                                <li class="breadcrumb-item"><a href="/Admin/personel-yonetimi">Personel Yönetimi</a></li>
                                <li class="breadcrumb-item active"><?= htmlspecialchars($pageTitle) ?></li>
                            </ol>
                        </div>
                    </div>
                </div>
            </div>
            
            <!-- Sayfa içeriği -->
            <div class="app-content">
                <div class="container-fluid">
                    
                    <form id="personelForm" class="needs-validation" novalidate autocomplete="off">
                        <!-- Tarayicinin kayitli kullanici/sifre bilgilerini gercek alanlara doldurmasini engelleyen tuzak alanlar -->
                        <input type="text" name="_tuzak_kullanici" autocomplete="username" tabindex="-1" aria-hidden="true" style="position:absolute;opacity:0;height:0;width:0;pointer-events:none;">
                        <input type="password" name="_tuzak_sifre" autocomplete="current-password" tabindex="-1" aria-hidden="true" style="position:absolute;opacity:0;height:0;width:0;pointer-events:none;">
                        <input type="hidden" id="kullanici_id" name="id" value="<?= $kullaniciid ?>">
                        
                        <div class="row">
                            <!-- Sol Kolon - Ana bilgiler -->
                            <div class="col-lg-8">
                                
                                <!-- Kişisel Bilgiler -->
                                <div class="card card-primary card-outline mb-3">
                                    <div class="card-header">
                                        <h3 class="card-title"><i class="bi bi-person"></i> Kişisel Bilgiler</h3>
                                    </div>
                                    <div class="card-body">
                                        <div class="row g-3">
                                            <div class="col-md-6">
                                                <label for="ad" class="form-label">Ad <span class="text-danger">*</span></label>
                                                <input type="text" class="form-control" id="ad" name="ad" 
                                                       value="<?= htmlspecialchars($kullanici['kullanici_ad'] ?? '') ?>" required>
                                                <div class="invalid-feedback">Ad zorunludur.</div>
                                            </div>
                                            
                                            <div class="col-md-6">
                                                <label for="soyad" class="form-label">Soyad <span class="text-danger">*</span></label>
                                                <input type="text" class="form-control" id="soyad" name="soyad" 
                                                       value="<?= htmlspecialchars($kullanici['kullanici_soyad'] ?? '') ?>" required>
                                                <div class="invalid-feedback">Soyad zorunludur.</div>
                                            </div>
                                            
                                            <div class="col-md-6">
                                                <label for="tc_kimlik_no" class="form-label">T.C. Kimlik No</label>
                                                <input type="text" class="form-control" id="tc_kimlik_no" name="tc_kimlik_no" 
                                                       value="<?= htmlspecialchars($kullanici['kullanici_tc_kimlik_no'] ?? '') ?>" 
                                                       maxlength="11" pattern="[0-9]{11}">
                                                <div class="form-text">11 haneli TC Kimlik numarası</div>
                                            </div>
                                            
                                            <div class="col-md-6">
                                                <label for="dogum_tarihi" class="form-label">Doğum Tarihi</label>
                                                <input type="date" class="form-control" id="dogum_tarihi" name="dogum_tarihi" 
                                                       value="<?= htmlspecialchars($kullanici['kullanici_dogum_tarihi'] ?? '') ?>">
                                            </div>
                                        </div>
                                    </div>
                                </div>
                                
                                <!-- İletişim Bilgileri -->
                                <div class="card card-primary card-outline mb-3">
                                    <div class="card-header">
                                        <h3 class="card-title"><i class="bi bi-telephone"></i> İletişim Bilgileri</h3>
                                    </div>
                                    <div class="card-body">
                                        <div class="row g-3">
                                            <div class="col-md-6">
                                                <label for="email" class="form-label">E-posta</label>
                                                <input type="email" class="form-control" id="email" name="email" autocomplete="off" data-lpignore="true" 
                                                       value="<?= htmlspecialchars($kullanici['kullanici_email'] ?? '') ?>">
                                                <div class="form-text">Ad ve soyaddan otomatik oluşur (<code>isim.soyisim@ornekproje.com.tr</code>), istenirse değiştirilebilir.</div>
                                            </div>
                                            
                                            <div class="col-md-6">
                                                <label for="telefon" class="form-label">Telefon</label>
                                                <input type="tel" class="form-control" id="telefon" name="telefon" 
                                                       value="<?= htmlspecialchars($kullanici['kullanici_telefon'] ?? '') ?>"
                                                       placeholder="0500 000 00 01">
                                                <div class="form-text">Nasıl girilirse girilsin <code>905000000001</code> biçiminde kaydedilir.</div>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                                
                                <!-- Güvenlik Bilgileri -->
                                <div class="card card-primary card-outline mb-3">
                                    <div class="card-header">
                                        <h3 class="card-title"><i class="bi bi-shield-lock"></i> Güvenlik Bilgileri</h3>
                                    </div>
                                    <div class="card-body">
                                        <div class="row g-3">
                                            <div class="col-md-6">
                                                <label for="sifre" class="form-label">
                                                    Şifre <?= !$editMode ? '<span class="text-danger">*</span>' : '' ?>
                                                </label>
                                                <div class="input-group">
                                                    <input type="password" class="form-control" id="sifre" name="sifre" autocomplete="new-password" data-lpignore="true" 
                                                           <?= !$editMode ? 'required' : '' ?>>
                                                    <button class="btn btn-outline-secondary" type="button" id="togglePassword">
                                                        <i class="bi bi-eye"></i>
                                                    </button>
                                                </div>
                                                <?php if ($editMode): ?>
                                                <div class="form-text">Boş bırakırsanız mevcut şifre korunur</div>
                                                <?php else: ?>
                                                <div class="invalid-feedback">Şifre zorunludur.</div>
                                                <?php endif; ?>
                                            </div>
                                            
                                            <div class="col-md-6">
                                                <label for="departman_id" class="form-label">Departman <span class="text-danger">*</span></label>
                                                <select class="form-select" id="departman_id" name="departman_id" required>
                                                    <option value="">Departman Seçiniz...</option>
                                                    <?php foreach ($departmanlar as $departman): ?>
                                                        <option value="<?= $departman['departman_id'] ?>" 
                                                                <?= ($kullanici['kullanici_departman_id'] ?? '') == $departman['departman_id'] ? 'selected' : '' ?>>
                                                            <?= htmlspecialchars($departman['departman_adi']) ?>
                                                        </option>
                                                    <?php endforeach; ?>
                                                </select>
                                                <div class="invalid-feedback">Departman seçimi zorunludur.</div>
                                            </div>
                                            
                                            <div class="col-md-6 mt-3">
                                                <div class="form-check form-switch">
                                                    <input class="form-check-input" type="checkbox" role="switch" id="sifre_degistirmeli" name="sifre_degistirmeli" value="1"
                                                           <?= ($kullanici['kullanici_sifre_degistirmeli'] ?? 1) == 1 ? 'checked' : '' ?>>
                                                    <label class="form-check-label" for="sifre_degistirmeli">
                                                        <i class="bi bi-key"></i> İlk Girişte Şifre Değiştirme Zorunlu
                                                    </label>
                                                </div>
                                                <div class="form-text">Aktifse kullanıcı ilk girişinde şifresini değiştirmek zorundadır.</div>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                                
                            </div>
                            
                            <!-- Sağ Kolon - Durum ve Kayıt bilgileri -->
                            <div class="col-lg-4">
                                
                                <!-- Durum -->
                                <div class="card card-primary card-outline mb-3">
                                    <div class="card-header">
                                        <h3 class="card-title"><i class="bi bi-toggle-on"></i> Durum</h3>
                                    </div>
                                    <div class="card-body">
                                        <div class="form-check form-switch">
                                            <input class="form-check-input" type="checkbox" role="switch" id="durum" name="durum" value="1"
                                                   <?= ($kullanici['kullanici_durum'] ?? 1) == 1 ? 'checked' : '' ?>>
                                            <label class="form-check-label" for="durum">
                                                Kullanıcı Aktif
                                            </label>
                                        </div>
                                        <div class="form-text mt-2">
                                            Pasif kullanıcılar sisteme giriş yapamaz.
                                        </div>
                                    </div>
                                </div>
                                
                                <?php if ($editMode && $kullanici): ?>
                                <!-- Kayıt Bilgileri -->
                                <div class="card card-secondary card-outline mb-3">
                                    <div class="card-header">
                                        <h3 class="card-title"><i class="bi bi-clock-history"></i> Kayıt Bilgileri</h3>
                                    </div>
                                    <div class="card-body">
                                        <small class="text-muted">
                                            <?php if ($kullanici['kullanici_olusturma_tarihi']): ?>
                                            <div class="mb-2">
                                                <strong>Oluşturulma:</strong><br>
                                                <?= $kullanici['kullanici_olusturma_tarihi'] ?>
                                                <?php if ($kullanici['olusturan_adi']): ?>
                                                    <br><span class="text-primary"><?= htmlspecialchars($kullanici['olusturan_adi']) ?></span>
                                                <?php endif; ?>
                                            </div>
                                            <?php endif; ?>
                                            
                                            <?php if ($kullanici['kullanici_guncelleme_tarihi']): ?>
                                            <div class="mb-2">
                                                <strong>Son güncelleme:</strong><br>
                                                <?= $kullanici['kullanici_guncelleme_tarihi'] ?>
                                                <?php if ($kullanici['guncelleyen_adi']): ?>
                                                    <br><span class="text-primary"><?= htmlspecialchars($kullanici['guncelleyen_adi']) ?></span>
                                                <?php endif; ?>
                                            </div>
                                            <?php endif; ?>
                                            
                                            <?php if ($kullanici['kullanici_son_giris_tarihi']): ?>
                                            <div>
                                                <strong>Son giriş:</strong><br>
                                                <?= $kullanici['kullanici_son_giris_tarihi'] ?>
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
                                            <a href="/Admin/personel-yonetimi" class="btn btn-secondary">
                                                <i class="bi bi-arrow-left"></i> Geri Dön
                                            </a>
                                            <?php if ($editMode && $pagePermissions['can_delete'] && $kullaniciid != $user['kullanici_id']): ?>
                                            <button type="button" class="btn btn-danger" onclick="formKayitSil('/Admin/personel-yonetimi', { action: 'kullanici_sil', id: <?= $kullaniciid ?> }, '/Admin/personel-yonetimi', 'Bu personeli silmek istediğinize emin misiniz?', 'Kullanıcı kalıcı olarak silinecek!')">
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
        $(document).ready(function() {
            // Select2 init
            $('#departman_id').select2({
                theme: 'bootstrap-5',
                width: '100%',
                allowClear: true,
                placeholder: 'Departman Seçiniz...',
                language: {
                    noResults: function() { return "Sonuç bulunamadı"; },
                    searching: function() { return "Aranıyor..."; }
                }
            });
            
            // Şifre göster/gizle
            $('#togglePassword').on('click', function() {
                const passwordinput = $('#sifre');
                const icon = $(this).find('i');
                
                if (passwordinput.attr('type') === 'password') {
                    passwordinput.attr('type', 'text');
                    icon.removeClass('bi-eye').addClass('bi-eye-slash');
                } else {
                    passwordinput.attr('type', 'password');
                    icon.removeClass('bi-eye-slash').addClass('bi-eye');
                }
            });
            
            // --- E-posta otomatik üretimi: isim.soyisim@ornekproje.com.tr ---
            let epostaManuel = $('#email').val().trim() !== '';

            $('#email').on('input', function() {
                // Kullanıcı elle dokunduysa otomatik üretim durur
                epostaManuel = $(this).val().trim() !== '' && $(this).val().trim() !== epostaUret();
            });

            $('#ad, #soyad').on('input', function() {
                if (!epostaManuel) {
                    $('#email').val(epostaUret());
                }
            });

            // --- Telefon: girilen değer 90XXXXXXXXXX olarak kaydedilir ---
            $('#telefon').on('blur', function() {
                const n = telefonNormalize($(this).val());
                if (n) $(this).val(n);
            });

            // Form submit
            $('#personelForm').on('submit', function(e) {
                e.preventDefault();
                
                if (!this.checkValidity()) {
                    e.stopPropagation();
                    $(this).addClass('was-validated');
                    return;
                }
                
                savePersonel();
            });
        });
        
        // Türkçe karakterleri sadeleştirip e-posta parçası üretir
        function epostaSadelestir(metin) {
            const tr = { 'ç':'c','Ç':'c','ğ':'g','Ğ':'g','ı':'i','I':'i','İ':'i','ö':'o','Ö':'o','ş':'s','Ş':'s','ü':'u','Ü':'u' };
            return (metin || '')
                .replace(/[çÇğĞıIİöÖşŞüÜ]/g, c => tr[c])
                .toLowerCase()
                .replace(/[^a-z0-9]+/g, '');
        }

        // Ad + soyaddan kurumsal e-posta üretir
        function epostaUret() {
            const ad = epostaSadelestir($('#ad').val());
            const soyad = epostaSadelestir($('#soyad').val());
            if (!ad && !soyad) return '';
            if (!soyad) return ad + '@ornekproje.com.tr';
            if (!ad) return soyad + '@ornekproje.com.tr';
            return ad + '.' + soyad + '@ornekproje.com.tr';
        }

        // Telefonu 90XXXXXXXXXX biçimine çevirir
        function telefonNormalize(deger) {
            let t = (deger || '').replace(/\D/g, '');
            if (!t) return '';
            t = t.replace(/^0+/, '');
            if (t.length > 10 && t.indexOf('90') === 0) t = t.substring(2);
            if (t.length > 10) t = t.slice(-10);
            return '90' + t;
        }

        // Kaydet
        function savePersonel() {
            const formData = new FormData($('#personelForm')[0]);
            formData.append('action', 'kaydet');

            // Tarayici autofill tuzak alanlari gonderilmez
            formData.delete('_tuzak_kullanici');
            formData.delete('_tuzak_sifre');

            // Telefonu 90XXXXXXXXXX biçiminde gönder
            formData.set('telefon', telefonNormalize($('#telefon').val()));

            // E-posta boşsa ad.soyad@ornekproje.com.tr ile doldur
            if (!$('#email').val().trim()) {
                formData.set('email', epostaUret());
            }
            
            // Durum checkbox'i kontrol et
            if (!$('#durum').is(':checked')) {
                formData.set('durum', '0');
            }
            
            // Şifre değiştirmeli checkbox'ı kontrol et
            if (!$('#sifre_degistirmeli').is(':checked')) {
                formData.set('sifre_degistirmeli', '0');
            }
            
            $.ajax({
                url: '',
                method: 'POST',
                data: formData,
                processData: false,
                contentType: false,
                success: function(response) {
                    if (response.success) {
                        showSuccess('Başarılı!', response.message);
                        setTimeout(function() {
                            window.location.href = '/Admin/personel-yonetimi';
                        }, 1500);
                    } else {
                        showError('Hata!', response.message);
                    }
                },
                error: function() {
                    showError('Hata!', 'Sunucuya bağlanılamadı.');
                }
            });
        }
    </script>
</body>
</html>
