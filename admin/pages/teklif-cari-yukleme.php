<?php
/**
 * Teklif - Cari Yükleme
 * Excel/CSV dosyasından toplu Cari yükleme (varsayılan cari_tipi_id = 2 / Potansiyel Müşteri)
 * İl ve İlçe alanları isim olarak eşleştirilir, ID'ye çevrilerek kaydedilir.
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

/**
 * Türkçe karakter duyarlı küçük harfe çevirme (İ/I sorunu için)
 */
function turkishLower($str) {
    $str = str_replace(['İ', 'I'], ['i', 'ı'], (string)$str);
    return mb_strtolower($str, 'UTF-8');
}

/**
 * Cari tablosu alan tanımları - mapping UI, validasyon ve şablon tek kaynaktan üretilir.
 * db   : Cari tablosundaki kolon adı (sehir_adi / ilce_adi isim eşleştirmesi olduğu için kolon değil)
 * max  : nvarchar uzunluk sınırı
 * match: otomatik eşleştirme anahtar kelimeleri
 */
$cariAlanlari = [
    ['key' => 'cari_adi',              'db' => 'cari_adi',              'label' => 'Cari Adı',           'required' => true,  'max' => 255,  'match' => ['cari adi', 'cari', 'firma adi', 'firma', 'musteri adi', 'musteri', 'ad']],
    ['key' => 'cari_unvan',            'db' => 'cari_unvan',            'label' => 'Ünvan',              'required' => false, 'max' => 500,  'match' => ['unvan', 'ticari unvan', 'resmi unvan']],
    ['key' => 'cari_vergi_dairesi',    'db' => 'cari_vergi_dairesi',    'label' => 'Vergi Dairesi',      'required' => false, 'max' => 100,  'match' => ['vergi dairesi', 'vd']],
    ['key' => 'cari_vergi_no',         'db' => 'cari_vergi_no',         'label' => 'Vergi No / TCKN',    'required' => false, 'max' => 50,   'match' => ['vergi no', 'vergino', 'vkn', 'tckn', 'tc kimlik']],
    ['key' => 'cari_mersis_no',        'db' => 'cari_mersis_no',        'label' => 'Mersis No',          'required' => false, 'max' => 50,   'match' => ['mersis']],
    ['key' => 'cari_ticaret_sicil_no', 'db' => 'cari_ticaret_sicil_no', 'label' => 'Ticaret Sicil No',   'required' => false, 'max' => 50,   'match' => ['ticaret sicil', 'sicil no']],
    ['key' => 'cari_telefon',          'db' => 'cari_telefon',          'label' => 'Telefon',            'required' => false, 'max' => 50,   'match' => ['telefon', 'tel', 'gsm', 'cep']],
    ['key' => 'cari_email',            'db' => 'cari_email',            'label' => 'E-Posta',            'required' => false, 'max' => 255,  'match' => ['e-posta', 'eposta', 'email', 'mail']],
    ['key' => 'cari_web_sitesi',       'db' => 'cari_web_sitesi',       'label' => 'Web Sitesi',         'required' => false, 'max' => 255,  'match' => ['web', 'web sitesi', 'website', 'site', 'url']],
    ['key' => 'sehir_adi',             'db' => null,                    'label' => 'İl (İsim)',          'required' => false, 'max' => 100,  'match' => ['il', 'sehir', 'şehir', 'il adi']],
    ['key' => 'ilce_adi',              'db' => null,                    'label' => 'İlçe (İsim)',        'required' => false, 'max' => 100,  'match' => ['ilce', 'ilçe', 'ilce adi', 'semt']],
    ['key' => 'cari_adres',            'db' => 'cari_adres',            'label' => 'Adres',              'required' => false, 'max' => 1000, 'match' => ['adres', 'acik adres']],
    ['key' => 'cari_posta_kodu',       'db' => 'cari_posta_kodu',       'label' => 'Posta Kodu',         'required' => false, 'max' => 20,   'match' => ['posta kodu', 'posta', 'zip']],
    ['key' => 'cari_yetkili_adi',      'db' => 'cari_yetkili_adi',      'label' => 'Yetkili Adı',        'required' => false, 'max' => 255,  'match' => ['yetkili adi', 'yetkili', 'ilgili kisi', 'ilgili']],
    ['key' => 'cari_yetkili_telefon',  'db' => 'cari_yetkili_telefon',  'label' => 'Yetkili Telefon',    'required' => false, 'max' => 50,   'match' => ['yetkili telefon', 'yetkili tel', 'yetkili gsm']],
    ['key' => 'cari_yetkili_email',    'db' => 'cari_yetkili_email',    'label' => 'Yetkili E-Posta',    'required' => false, 'max' => 255,  'match' => ['yetkili e-posta', 'yetkili eposta', 'yetkili email', 'yetkili mail']],
];

// ==================== AJAX İŞLEMLERİ ====================
$action = $_POST['action'] ?? '';

if ($action !== '') {
    header('Content-Type: application/json; charset=utf-8');

    try {
        // Referans veriler (isim -> ID eşleştirmesi için)
        $sehirler = $db->fetchAll("SELECT SehirId, SehirAdi, UlkeId FROM Adres_Sehirler ORDER BY SehirAdi");
        $ilceler  = $db->fetchAll("SELECT ilceId, IlceAdi, SehirId FROM Adres_Ilceler ORDER BY IlceAdi");

        // ---------- ANALİZ ----------
        if ($action === 'analyze') {
            if (!$pagePermissions['can_add']) {
                throw new Exception('Kayıt ekleme yetkiniz bulunmamaktadır.');
            }

            $rows    = json_decode($_POST['rows'] ?? '[]', true);
            $mapping = json_decode($_POST['mapping'] ?? '{}', true);

            if (!is_array($rows) || count($rows) === 0) {
                throw new Exception('Analiz edilecek veri bulunamadı.');
            }
            if (empty($mapping['cari_adi']) && $mapping['cari_adi'] !== '0') {
                throw new Exception('Cari Adı alanı zorunludur, lütfen bir Excel sütunu ile eşleştirin.');
            }

            $settings = [
                'cari_tipi_id' => intval($_POST['cari_tipi_id'] ?? 2),
                'cari_ulke'    => intval($_POST['cari_ulke'] ?? 0),
                'cari_aktif'   => intval($_POST['cari_aktif'] ?? 1),
            ];
            if ($settings['cari_tipi_id'] <= 0) {
                throw new Exception('Geçerli bir Cari Tipi seçilmelidir.');
            }
            if ($settings['cari_ulke'] <= 0) {
                throw new Exception('Geçerli bir Ülke seçilmelidir.');
            }

            // Alan tanımlarını key ile eriş
            $alanMap = [];
            foreach ($cariAlanlari as $a) { $alanMap[$a['key']] = $a; }

            // Mükerrer kontrolü için mevcut cariler (vergi no + cari adı)
            $mevcutCariler = $db->fetchAll("SELECT cari_id, cari_adi, cari_vergi_no FROM Cari");
            $vergiIndex = [];
            $adIndex    = [];
            foreach ($mevcutCariler as $mc) {
                $vn = preg_replace('/\D/', '', (string)$mc['cari_vergi_no']);
                if ($vn !== '') { $vergiIndex[$vn] = $mc; }
                $adIndex[turkishLower(trim((string)$mc['cari_adi']))] = $mc;
            }

            // Aynı dosya içindeki tekrarları da yakalamak için
            $dosyaVergi = [];
            $dosyaAd    = [];

            $analiz  = [];
            $summary = ['total' => 0, 'new' => 0, 'duplicate' => 0, 'error' => 0];

            foreach ($rows as $i => $row) {
                $summary['total']++;

                $d        = [];
                $uyarilar = [];

                // Eşleştirilmiş alanları oku, kırp ve uzunluk sınırına uydur
                foreach ($mapping as $fieldKey => $excelCol) {
                    if ($excelCol === '' || $excelCol === null) continue;
                    if (!isset($alanMap[$fieldKey])) continue;

                    $val = isset($row[$fieldKey]) ? trim((string)$row[$fieldKey]) : '';
                    $max = $alanMap[$fieldKey]['max'];
                    if ($val !== '' && mb_strlen($val, 'UTF-8') > $max) {
                        $val = mb_substr($val, 0, $max, 'UTF-8');
                        $uyarilar[] = $alanMap[$fieldKey]['label'] . ' ' . $max . ' karaktere kırpıldı';
                    }
                    $d[$fieldKey] = $val;
                }

                $satir = [
                    'excel_satir'  => $i + 2, // başlık satırı + 1 indeks
                    'data'         => $d,
                    'sehir_id'     => null,
                    'ilce_id'      => null,
                    'status'       => 'new',
                    'mesaj'        => '',
                    'uyarilar'     => [],
                    'mevcut_cari_id' => null,
                ];

                // --- Zorunlu: Cari Adı ---
                if (empty($d['cari_adi'])) {
                    $satir['status'] = 'error';
                    $satir['mesaj']  = 'Cari Adı boş';
                    $summary['error']++;
                    $analiz[] = $satir;
                    continue;
                }

                // --- Vergi No normalize + format uyarısı ---
                $vergiNo = '';
                if (!empty($d['cari_vergi_no'])) {
                    $vergiNo = preg_replace('/\D/', '', $d['cari_vergi_no']);
                    if ($vergiNo === '') {
                        $uyarilar[] = 'Vergi No rakam içermiyor, boş bırakıldı';
                    } elseif (!preg_match('/^\d{10,11}$/', $vergiNo)) {
                        $uyarilar[] = 'Vergi No 10-11 hane değil (' . mb_strlen($vergiNo) . ' hane)';
                    }
                    $satir['data']['cari_vergi_no'] = $vergiNo;
                    $d['cari_vergi_no'] = $vergiNo;
                }

                // --- E-posta format kontrolü (geçersizse boşaltılır) ---
                foreach (['cari_email' => 'E-Posta', 'cari_yetkili_email' => 'Yetkili E-Posta'] as $ek => $elabel) {
                    if (!empty($d[$ek]) && !filter_var($d[$ek], FILTER_VALIDATE_EMAIL)) {
                        $uyarilar[] = $elabel . ' geçersiz, boş bırakıldı';
                        $satir['data'][$ek] = '';
                        $d[$ek] = '';
                    }
                }

                // --- İl eşleştirme (isim -> ID) ---
                if (!empty($d['sehir_adi'])) {
                    $aranan = turkishLower(trim($d['sehir_adi']));
                    foreach ($sehirler as $s) {
                        if ($s['UlkeId'] != $settings['cari_ulke']) continue;
                        if (turkishLower(trim($s['SehirAdi'])) === $aranan) {
                            $satir['sehir_id'] = $s['SehirId'];
                            break;
                        }
                    }
                    if (!$satir['sehir_id']) {
                        $uyarilar[] = 'İl bulunamadı: ' . $d['sehir_adi'];
                    }
                }

                // --- İlçe eşleştirme (sadece il bulunduysa, ile bağlı olmalı) ---
                if (!empty($d['ilce_adi'])) {
                    if (!$satir['sehir_id']) {
                        $uyarilar[] = 'İl bulunamadığı için İlçe eşleştirilemedi';
                    } else {
                        $aranan = turkishLower(trim($d['ilce_adi']));
                        foreach ($ilceler as $ilc) {
                            if ($ilc['SehirId'] != $satir['sehir_id']) continue;
                            if (turkishLower(trim($ilc['IlceAdi'])) === $aranan) {
                                $satir['ilce_id'] = $ilc['ilceId'];
                                break;
                            }
                        }
                        if (!$satir['ilce_id']) {
                            $uyarilar[] = 'İlçe bulunamadı: ' . $d['ilce_adi'];
                        }
                    }
                }

                // --- Mükerrer kontrolü: vergi no varsa onunla, yoksa cari adı ile ---
                $adKey = turkishLower(trim($d['cari_adi']));
                if ($vergiNo !== '' && isset($vergiIndex[$vergiNo])) {
                    $satir['status'] = 'duplicate';
                    $satir['mesaj']  = 'Vergi No kayıtlı: ' . $vergiIndex[$vergiNo]['cari_adi'];
                    $satir['mevcut_cari_id'] = $vergiIndex[$vergiNo]['cari_id'];
                } elseif ($vergiNo !== '' && isset($dosyaVergi[$vergiNo])) {
                    $satir['status'] = 'duplicate';
                    $satir['mesaj']  = 'Aynı Vergi No dosyada tekrar ediyor (satır ' . $dosyaVergi[$vergiNo] . ')';
                } elseif ($vergiNo === '' && isset($adIndex[$adKey])) {
                    $satir['status'] = 'duplicate';
                    $satir['mesaj']  = 'Cari Adı kayıtlı (Vergi No boş)';
                    $satir['mevcut_cari_id'] = $adIndex[$adKey]['cari_id'];
                } elseif ($vergiNo === '' && isset($dosyaAd[$adKey])) {
                    $satir['status'] = 'duplicate';
                    $satir['mesaj']  = 'Aynı Cari Adı dosyada tekrar ediyor (satır ' . $dosyaAd[$adKey] . ')';
                }

                if ($satir['status'] === 'new') {
                    if ($vergiNo !== '') { $dosyaVergi[$vergiNo] = $satir['excel_satir']; }
                    $dosyaAd[$adKey] = $satir['excel_satir'];
                    $summary['new']++;
                } else {
                    $summary['duplicate']++;
                }

                $satir['uyarilar'] = $uyarilar;
                $analiz[] = $satir;
            }

            $_SESSION['teklif_import_data']     = $analiz;
            $_SESSION['teklif_import_settings'] = $settings;
            $_SESSION['teklif_import_mapping']  = array_keys(array_filter($mapping, fn($v) => $v !== '' && $v !== null));

            echo json_encode([
                'success' => true,
                'summary' => $summary,
                'rows'    => $analiz,
                'mapped'  => $_SESSION['teklif_import_mapping'],
            ], JSON_UNESCAPED_UNICODE);
            exit;
        }

        // ---------- IMPORT ----------
        if ($action === 'import') {
            if (!$pagePermissions['can_add']) {
                throw new Exception('Kayıt ekleme yetkiniz bulunmamaktadır.');
            }

            $analiz   = $_SESSION['teklif_import_data'] ?? [];
            $settings = $_SESSION['teklif_import_settings'] ?? [];

            if (empty($analiz)) {
                throw new Exception('Import edilecek veri bulunamadı. Lütfen dosyayı tekrar analiz edin.');
            }
            if (empty($settings['cari_tipi_id']) || empty($settings['cari_ulke'])) {
                throw new Exception('Import ayarları eksik. Lütfen dosyayı tekrar analiz edin.');
            }

            // db karşılığı olan opsiyonel alanlar
            $opsiyonelKolonlar = [];
            foreach ($cariAlanlari as $a) {
                if ($a['db'] !== null && $a['db'] !== 'cari_adi') {
                    $opsiyonelKolonlar[$a['key']] = $a['db'];
                }
            }

            $eklenen  = 0;
            $atlanan  = 0;
            $hatali   = 0;
            $hataMesajlari = [];

            foreach ($analiz as $satir) {
                if ($satir['status'] !== 'new') {
                    $atlanan++;
                    continue;
                }

                $d = $satir['data'];

                $fields = ['cari_adi', 'cari_tipi_id', 'cari_aktif', 'cari_musteri', 'cari_tedarikci', 'cari_ulke', 'cari_sehirler', 'cari_ilceler', 'cari_olusturan_kullanici', 'cari_olusturma_tarihi'];
                $plc    = ['?', '?', '?', '1', '0', '?', '?', '?', '?', 'GETDATE()'];
                $params = [
                    $d['cari_adi'],
                    $settings['cari_tipi_id'],
                    $settings['cari_aktif'],
                    $settings['cari_ulke'],
                    $satir['sehir_id'],
                    $satir['ilce_id'],
                    $user['kullanici_id'],
                ];

                foreach ($opsiyonelKolonlar as $key => $kolon) {
                    if (!empty($d[$key])) {
                        $fields[] = $kolon;
                        $plc[]    = '?';
                        $params[] = $d[$key];
                    }
                }

                $sql = "INSERT INTO Cari (" . implode(', ', $fields) . ")
                        OUTPUT INSERTED.cari_id
                        VALUES (" . implode(', ', $plc) . ")";

                $res = $db->fetchOne($sql, $params);

                if ($res && !empty($res['cari_id'])) {
                    $eklenen++;
                } else {
                    $hatali++;
                    if (count($hataMesajlari) < 10) {
                        $hataMesajlari[] = 'Satır ' . $satir['excel_satir'] . ': ' . $d['cari_adi'];
                    }
                }
            }

            unset($_SESSION['teklif_import_data'], $_SESSION['teklif_import_settings'], $_SESSION['teklif_import_mapping']);

            echo json_encode([
                'success'  => true,
                'eklenen'  => $eklenen,
                'atlanan'  => $atlanan,
                'hatalı'   => $hatali,
                'hatalar'  => $hataMesajlari,
                'message'  => $eklenen . ' cari eklendi, ' . $atlanan . ' satır atlandı.' . ($hatali > 0 ? ' ' . $hatali . ' satır kaydedilemedi.' : ''),
            ], JSON_UNESCAPED_UNICODE);
            exit;
        }

        // ---------- TEMİZLE ----------
        if ($action === 'clear') {
            unset($_SESSION['teklif_import_data'], $_SESSION['teklif_import_settings'], $_SESSION['teklif_import_mapping']);
            echo json_encode(['success' => true]);
            exit;
        }

        throw new Exception('Geçersiz işlem.');

    } catch (Exception $e) {
        echo json_encode(['success' => false, 'message' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
        exit;
    }
}

// ==================== SAYFA VERİLERİ ====================
$pageInfo = $db->fetchOne("
    SELECT s.sayfalar_sayfa_adi, s.sayfalar_aciklama, m.menuler_menu_adi as menu_adi
    FROM Menu_Sayfalar s
    LEFT JOIN Menuler m ON s.sayfalar_menu_id = m.menuler_id
    WHERE s.sayfalar_sayfa_url LIKE ? AND s.sayfalar_durum = 1
", ['%' . $currentPageFile]);

$pageTitle       = $pageInfo['sayfalar_sayfa_adi'] ?? 'Teklif Cari Yükleme';
$pageDescription = $pageInfo['sayfalar_aciklama'] ?? 'Excel dosyasından toplu cari yükleme';
$menuAdi         = $pageInfo['menu_adi'] ?? 'Teklif';

$siteAyarlari = $db->fetchOne("SELECT TOP 1 site_ayarlari_site_title FROM dbo.tanim_site_ayarlari ORDER BY site_ayarlari_id DESC");
$siteTitle    = $siteAyarlari['site_ayarlari_site_title'] ?? 'Portal';

$ulkeler     = $db->fetchAll("SELECT UlkeId, UlkeAdi FROM Adres_Ulkeler ORDER BY UlkeAdi");
$cariTipleri = $db->fetchAll("SELECT cari_tipi_id, cari_tipi_ad FROM Cari_CariTipleri WHERE cari_tipi_durum = 1 ORDER BY cari_tipi_sira");

// Varsayılan ülke: Türkiye
$varsayilanUlkeId = null;
foreach ($ulkeler as $u) {
    if (turkishLower(trim($u['UlkeAdi'])) === 'Türkiye' || turkishLower(trim($u['UlkeAdi'])) === 'turkiye') {
        $varsayilanUlkeId = $u['UlkeId'];
        break;
    }
}

// Bu sayfanın hedeflediği cari tipi
$varsayilanCariTipiId = 2;
?><!DOCTYPE html>
<html lang="tr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars($pageTitle) ?> - <?= htmlspecialchars($siteTitle) ?></title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/@fontsource/source-sans-3@5.0.12/index.css" crossorigin="anonymous">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.13.1/font/bootstrap-icons.min.css" crossorigin="anonymous">
    <link rel="stylesheet" href="/admin/assets/css/adminlte.min.css">
    <link href="https://cdn.jsdelivr.net/npm/sweetalert2@11/dist/sweetalert2.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/select2-bootstrap-5-theme@1.3.0/dist/select2-bootstrap-5-theme.min.css" rel="stylesheet">
    <link rel="stylesheet" href="/admin/assets/css/custom.css">
    <script src="https://cdn.sheetjs.com/xlsx-0.20.1/package/dist/xlsx.full.min.js"></script>
    <style>
        .step-card { transition: all 0.3s ease; }
        .step-card.disabled-step { opacity: 0.5; pointer-events: none; }
        .step-number { display: inline-flex; align-items: center; justify-content: center; width: 28px; height: 28px; border-radius: 50%; background: #0d6efd; color: #fff; font-weight: bold; font-size: 14px; margin-right: 8px; }
        .step-number.completed { background: #198754; }
        .preview-cell { font-size: 0.85em; }
        .uyari-badge { font-size: 0.75em; }
        #mappingTable .select2-container { width: 100% !important; }
    </style>
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
                            <h3 class="mb-0"><?= htmlspecialchars($pageTitle) ?></h3>
                            <small class="text-muted"><?= htmlspecialchars($pageDescription) ?></small>
                        </div>
                        <div class="col-sm-6">
                            <ol class="breadcrumb float-sm-end">
                                <li class="breadcrumb-item"><a href="/admin/anasayfa">Ana Sayfa</a></li>
                                <li class="breadcrumb-item"><?= htmlspecialchars($menuAdi) ?></li>
                                <li class="breadcrumb-item active">Cari Yükleme</li>
                            </ol>
                        </div>
                    </div>
                </div>
            </div>

            <div class="app-content">
                <div class="container-fluid">

                    <!-- INFO BOX -->
                    <div class="row mb-3">
                        <div class="col-md-3">
                            <div class="info-box text-bg-primary">
                                <span class="info-box-icon"><i class="bi bi-list-ol"></i></span>
                                <div class="info-box-content">
                                    <span class="info-box-text">Toplam Satır</span>
                                    <span class="info-box-number" id="box-toplam">0</span>
                                </div>
                            </div>
                        </div>
                        <div class="col-md-3">
                            <div class="info-box text-bg-success">
                                <span class="info-box-icon"><i class="bi bi-plus-circle"></i></span>
                                <div class="info-box-content">
                                    <span class="info-box-text">Yeni Kayıt</span>
                                    <span class="info-box-number" id="box-yeni">0</span>
                                </div>
                            </div>
                        </div>
                        <div class="col-md-3">
                            <div class="info-box text-bg-warning">
                                <span class="info-box-icon"><i class="bi bi-exclamation-triangle"></i></span>
                                <div class="info-box-content">
                                    <span class="info-box-text">Mükerrer (Atlanacak)</span>
                                    <span class="info-box-number" id="box-mukerrer">0</span>
                                </div>
                            </div>
                        </div>
                        <div class="col-md-3">
                            <div class="info-box text-bg-danger">
                                <span class="info-box-icon"><i class="bi bi-x-circle"></i></span>
                                <div class="info-box-content">
                                    <span class="info-box-text">Hatalı</span>
                                    <span class="info-box-number" id="box-hatalı">0</span>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- ADIM 1: Dosya & Ayarlar -->
                    <div class="card card-primary card-outline mb-3 step-card" id="step1Card">
                        <div class="card-header">
                            <h3 class="card-title">
                                <span class="step-number" id="step1Badge">1</span>
                                <i class="bi bi-gear"></i> Dosya ve Import Ayarları
                            </h3>
                            <div class="card-tools">
                                <button type="button" class="btn btn-success btn-sm" id="downloadTemplateBtn">
                                    <i class="bi bi-download"></i> Şablon İndir
                                </button>
                            </div>
                        </div>
                        <div class="card-body">
                            <div class="row g-3">
                                <div class="col-md-6">
                                    <label class="form-label fw-bold">Excel / CSV Dosyası</label>
                                    <input type="file" class="form-control" id="excelFile" accept=".csv,.xlsx,.xls">
                                    <div class="form-text">Desteklenen: .xlsx, .xls, .csv — ilk satır başlık olmalıdır.</div>
                                </div>
                                <div class="col-md-6">
                                    <div class="alert alert-info mb-0 small">
                                        <i class="bi bi-info-circle"></i>
                                        <strong>İl</strong> ve <strong>İlçe</strong> sütunları <strong>isim</strong> olarak eşleştirilir, sistem otomatik olarak ID'ye çevirir.
                                        Eşleşmeyen il/ilçe boş bırakılır, kayıt yine de eklenir.
                                    </div>
                                </div>
                            </div>

                            <hr>

                            <h6 class="fw-bold"><i class="bi bi-sliders"></i> Tüm Satırlara Uygulanacak Sabit Alanlar</h6>
                            <div class="row g-3">
                                <div class="col-md-4">
                                    <label class="form-label fw-bold">Cari Tipi</label>
                                    <select class="form-select" id="cariTipiSelect">
                                        <?php foreach ($cariTipleri as $ct): ?>
                                            <option value="<?= $ct['cari_tipi_id'] ?>"<?= $ct['cari_tipi_id'] == $varsayilanCariTipiId ? ' selected' : '' ?>>
                                                <?= htmlspecialchars($ct['cari_tipi_ad']) ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div class="col-md-4">
                                    <label class="form-label fw-bold">Ülke</label>
                                    <select class="form-select" id="ulkeSelect">
                                        <?php foreach ($ulkeler as $u): ?>
                                            <option value="<?= $u['UlkeId'] ?>"<?= $u['UlkeId'] == $varsayilanUlkeId ? ' selected' : '' ?>>
                                                <?= htmlspecialchars($u['UlkeAdi']) ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                    <div class="form-text">İl eşleştirmesi seçilen ülkeye göre yapılır.</div>
                                </div>
                                <div class="col-md-4">
                                    <label class="form-label fw-bold">Durum</label>
                                    <select class="form-select" id="aktifSelect">
                                        <option value="1" selected>Aktif</option>
                                        <option value="0">Pasif</option>
                                    </select>
                                </div>
                            </div>

                            <div class="mt-3">
                                <button type="button" class="btn btn-primary" id="readFileBtn" disabled>
                                    <i class="bi bi-file-earmark-spreadsheet"></i> Dosyayı Oku
                                </button>
                                <button type="button" class="btn btn-secondary" id="resetAllBtn" style="display:none;">
                                    <i class="bi bi-arrow-counterclockwise"></i> Sıfırla
                                </button>
                            </div>
                        </div>
                    </div>

                    <!-- ADIM 2: Sütun Eşleştirme -->
                    <div class="card card-warning card-outline mb-3 step-card disabled-step d-none" id="step2Card">
                        <div class="card-header">
                            <h3 class="card-title">
                                <span class="step-number" id="step2Badge">2</span>
                                <i class="bi bi-arrows-angle-expand"></i> Sütun Eşleştirme
                            </h3>
                            <div class="card-tools">
                                <span class="badge bg-info" id="excelColCount">0 sütun</span>
                                <span class="badge bg-primary" id="excelRowCount">0 satır</span>
                            </div>
                        </div>
                        <div class="card-body">
                            <p class="text-muted small mb-3">
                                <i class="bi bi-lightbulb"></i>
                                Excel sütunlarını veritabanı alanlarına eşleştirin. <strong class="text-danger">Zorunlu alan</strong> işaretlidir.
                            </p>
                            <div class="row">
                                <div class="col-md-6">
                                    <div class="table-responsive" style="max-height: 500px; overflow-y: auto;">
                                        <table class="table table-sm table-bordered mb-0" id="mappingTable">
                                            <thead class="table-light sticky-top">
                                                <tr>
                                                    <th width="5%">#</th>
                                                    <th width="30%">Veritabanı Alanı</th>
                                                    <th width="40%">Excel Sütunu</th>
                                                    <th width="25%">Ön İzleme</th>
                                                </tr>
                                            </thead>
                                            <tbody id="mappingBody"></tbody>
                                        </table>
                                    </div>
                                </div>
                                <div class="col-md-6">
                                    <div class="card bg-light">
                                        <div class="card-header py-2">
                                            <h6 class="card-title mb-0"><i class="bi bi-eye"></i> Excel Ön İzleme (İlk 5 Satır)</h6>
                                        </div>
                                        <div class="card-body p-0" style="max-height: 400px; overflow: auto;">
                                            <table class="table table-sm table-bordered table-striped mb-0">
                                                <thead class="table-dark sticky-top" id="excelPreviewHead"></thead>
                                                <tbody id="excelPreviewBody"></tbody>
                                            </table>
                                        </div>
                                    </div>
                                </div>
                            </div>

                            <div class="mt-3">
                                <button type="button" class="btn btn-outline-secondary me-2" id="backToStep1Btn">
                                    <i class="bi bi-arrow-left"></i> Geri
                                </button>
                                <button type="button" class="btn btn-warning" id="analyzeBtn">
                                    <i class="bi bi-search"></i> Analiz Et
                                </button>
                                <button type="button" class="btn btn-outline-secondary btn-sm" id="autoMatchBtn">
                                    <i class="bi bi-magic"></i> Otomatik Eşleştir
                                </button>
                            </div>
                        </div>
                    </div>

                    <!-- ADIM 3: Analiz Sonucu -->
                    <div class="card card-info card-outline mb-3 d-none" id="step3Card">
                        <div class="card-header">
                            <h3 class="card-title">
                                <span class="step-number" id="step3Badge">3</span>
                                <i class="bi bi-bar-chart"></i> Analiz Sonucu
                            </h3>
                        </div>
                        <div class="card-body">
                            <div class="alert alert-warning small mb-3">
                                <i class="bi bi-info-circle"></i>
                                <strong>Mükerrer</strong> kayıtlar atlanır, mevcut cariler değiştirilmez.
                                Mükerrer kontrolü Vergi No üzerinden yapılır; Vergi No boşsa Cari Adı ile kontrol edilir.
                            </div>
                            <div class="d-flex justify-content-end gap-2">
                                <button type="button" class="btn btn-outline-secondary" id="backToStep2Btn">
                                    <i class="bi bi-arrow-left"></i> Geri
                                </button>
                                <button type="button" class="btn btn-success btn-lg" id="importBtn">
                                    <i class="bi bi-database-add"></i> Import Et
                                </button>
                            </div>
                        </div>
                    </div>

                    <!-- Önizleme Tablosu -->
                    <div class="card card-primary card-outline d-none" id="previewCard">
                        <div class="card-header">
                            <h3 class="card-title"><i class="bi bi-table"></i> Veri Önizleme</h3>
                            <div class="card-tools">
                                <span class="badge bg-success me-1"><i class="bi bi-plus"></i> Yeni</span>
                                <span class="badge bg-warning me-1"><i class="bi bi-exclamation"></i> Mükerrer</span>
                                <span class="badge bg-danger"><i class="bi bi-x"></i> Hata</span>
                            </div>
                        </div>
                        <div class="card-body p-0">
                            <div class="table-responsive" style="max-height: 500px; overflow-y: auto;">
                                <table class="table table-bordered table-sm table-hover mb-0">
                                    <thead class="table-dark sticky-top" id="previewHead"></thead>
                                    <tbody id="previewBody"></tbody>
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
    <script src="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11/dist/sweetalert2.all.min.js"></script>
    <script src="/admin/assets/js/custom.js"></script>

    <script>
    const cariAlanlari = <?= json_encode($cariAlanlari, JSON_UNESCAPED_UNICODE) ?>;
    const canAdd = <?= $pagePermissions['can_add'] ? 'true' : 'false' ?>;

    let excelHeaders = [];
    let excelData    = [];
    let analizRows   = [];
    let mappedFields = [];

    const select2Ayar = {
        theme: 'bootstrap-5',
        width: '100%',
        language: {
            noResults: function() { return 'Sonuç bulunamadı'; },
            searching: function() { return 'Aranıyor...'; }
        }
    };

    function escapeHtml(str) {
        if (str === null || str === undefined) return '';
        return String(str)
            .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;').replace(/'/g, '&#039;');
    }

    function truncate(str, len) {
        str = String(str);
        return str.length > len ? str.substring(0, len) + '...' : str;
    }

    function normalizeStr(str) {
        return String(str || '')
            .replace(/İ/g, 'i').replace(/I/g, 'i').replace(/ı/g, 'i')
            .replace(/Ş/g, 's').replace(/ş/g, 's')
            .replace(/Ğ/g, 'g').replace(/ğ/g, 'g')
            .replace(/Ü/g, 'u').replace(/ü/g, 'u')
            .replace(/Ö/g, 'o').replace(/ö/g, 'o')
            .replace(/Ç/g, 'c').replace(/ç/g, 'c')
            .toLowerCase().trim();
    }

    // ---------- ADIM 1: Dosya Okuma ----------
    $('#excelFile').on('change', function() {
        $('#readFileBtn').prop('disabled', !this.files.length);
    });

    $('#readFileBtn').on('click', readExcelFile);

    function readExcelFile() {
        const fileInput = document.getElementById('excelFile');
        if (!fileInput.files.length) {
            showToast('Lütfen bir Excel dosyası seçin!', 'warning');
            return;
        }

        const file = fileInput.files[0];
        const ext  = file.name.split('.').pop().toLowerCase();
        if (!['xlsx', 'xls', 'csv'].includes(ext)) {
            showToast('Desteklenen formatlar: .xlsx, .xls, .csv', 'error');
            return;
        }

        showLoading();
        const reader = new FileReader();
        reader.onload = function(e) {
            try {
                const data      = new Uint8Array(e.target.result);
                const workbook  = XLSX.read(data, { type: 'array', cellDates: false, cellText: true, raw: true });
                const firstSheet = workbook.Sheets[workbook.SheetNames[0]];
                const jsonData  = XLSX.utils.sheet_to_json(firstSheet, { header: 1, raw: true, defval: '' });

                if (jsonData.length < 2) {
                    hideLoading();
                    showToast('Dosyada başlık satırı dışında veri bulunamadı!', 'error');
                    return;
                }

                excelHeaders = jsonData[0].map(h => (h || '').toString().trim());
                excelData    = jsonData.slice(1).filter(row => row.some(cell => cell !== '' && cell !== null && cell !== undefined));

                $('#excelColCount').text(excelHeaders.length + ' sütun');
                $('#excelRowCount').text(excelData.length + ' satır');
                $('#box-toplam').text(excelData.length);

                buildMappingTable();
                buildExcelPreview();
                autoMatch();

                $('#step1Card').addClass('d-none');
                $('#step2Card').removeClass('d-none disabled-step');
                $('#step1Badge').addClass('completed');
                $('#resetAllBtn').show();
            } catch (err) {
                showToast('Dosya okunamadı: ' + err.message, 'error');
            }
            hideLoading();
        };
        reader.readAsArrayBuffer(file);
    }

    // ---------- ADIM 2: Eşleştirme ----------
    function buildMappingTable() {
        let html = '';
        cariAlanlari.forEach((field, idx) => {
            let options = '<option value="">-- Seçim Yapılmadı --</option>';
            excelHeaders.forEach((h, i) => {
                options += '<option value="' + i + '">' + escapeHtml(h) + '</option>';
            });
            const reqBadge = field.required ? ' <span class="badge bg-danger">Zorunlu</span>' : '';
            html += '<tr>' +
                '<td>' + (idx + 1) + '</td>' +
                '<td><strong>' + escapeHtml(field.label) + '</strong>' + reqBadge + '</td>' +
                '<td><select class="form-select form-select-sm mapping-select" data-field="' + field.key + '" id="map_' + field.key + '">' + options + '</select></td>' +
                '<td class="preview-cell" id="prev_' + field.key + '"><span class="text-muted">-</span></td>' +
                '</tr>';
        });
        $('#mappingBody').html(html);

        $('.mapping-select').select2($.extend({}, select2Ayar, {
            placeholder: '-- Seçim Yapılmadı --',
            allowClear: true,
            dropdownParent: $('#step2Card')
        }));

        $('.mapping-select').off('change').on('change', function() {
            const field    = $(this).data('field');
            const colIndex = $(this).val();
            if (colIndex !== '' && colIndex !== null) {
                const sample = excelData[0] ? (excelData[0][parseInt(colIndex)] || '-') : '-';
                $('#prev_' + field).html('<span class="text-success">' + escapeHtml(truncate(sample, 40)) + '</span>');
            } else {
                $('#prev_' + field).html('<span class="text-muted">-</span>');
            }
        });
    }

    function buildExcelPreview() {
        let head = '<tr><th>#</th>';
        excelHeaders.forEach(h => { head += '<th>' + escapeHtml(h) + '</th>'; });
        head += '</tr>';
        $('#excelPreviewHead').html(head);

        let body = '';
        excelData.slice(0, 5).forEach((row, i) => {
            body += '<tr><td>' + (i + 2) + '</td>';
            excelHeaders.forEach((h, ci) => {
                body += '<td>' + escapeHtml(truncate(row[ci] !== undefined && row[ci] !== null ? row[ci] : '', 25)) + '</td>';
            });
            body += '</tr>';
        });
        $('#excelPreviewBody').html(body);
    }

    $('#autoMatchBtn').on('click', autoMatch);

    function autoMatch() {
        let matchCount = 0;
        const kullanilan = [];

        cariAlanlari.forEach(field => {
            let bestMatch = -1;

            // Önce tam eşleşme ara
            for (let i = 0; i < excelHeaders.length; i++) {
                if (kullanilan.includes(i)) continue;
                const hNorm = normalizeStr(excelHeaders[i]);
                if (field.match.some(kw => normalizeStr(kw) === hNorm)) { bestMatch = i; break; }
            }
            // Bulunamazsa kısmi eşleşme ara
            if (bestMatch < 0) {
                for (let i = 0; i < excelHeaders.length; i++) {
                    if (kullanilan.includes(i)) continue;
                    const hNorm = normalizeStr(excelHeaders[i]);
                    if (hNorm === '') continue;
                    if (field.match.some(kw => hNorm.includes(normalizeStr(kw)))) { bestMatch = i; break; }
                }
            }

            if (bestMatch >= 0) {
                kullanilan.push(bestMatch);
                $('#map_' + field.key).val(bestMatch).trigger('change');
                matchCount++;
            }
        });

        showToast(matchCount + ' alan otomatik eşleştirildi', matchCount > 0 ? 'success' : 'warning');
    }

    function getMapping() {
        const mapping = {};
        $('.mapping-select').each(function() {
            const field = $(this).data('field');
            const val   = $(this).val();
            mapping[field] = (val === null || val === '') ? '' : val;
        });
        return mapping;
    }

    // ---------- ADIM 3: Analiz ----------
    $('#analyzeBtn').on('click', function() {
        const mapping = getMapping();

        if (mapping['cari_adi'] === '') {
            showToast('Cari Adı alanı zorunludur, lütfen bir sütun ile eşleştirin!', 'error');
            return;
        }

        // Sadece eşleştirilen alanları {alanKey: deger} formatında gönder
        const rows = excelData.map(row => {
            const obj = {};
            for (const [field, colIndex] of Object.entries(mapping)) {
                if (colIndex === '') continue;
                const v = row[parseInt(colIndex)];
                obj[field] = (v === null || v === undefined) ? '' : String(v).trim();
            }
            return obj;
        });

        showLoading();
        $.post('', {
            action: 'analyze',
            rows: JSON.stringify(rows),
            mapping: JSON.stringify(mapping),
            cari_tipi_id: $('#cariTipiSelect').val(),
            cari_ulke: $('#ulkeSelect').val(),
            cari_aktif: $('#aktifSelect').val()
        }, function(res) {
            hideLoading();
            if (!res.success) {
                showError('Analiz Hatası!', res.message);
                return;
            }

            analizRows   = res.rows;
            mappedFields = res.mapped;

            $('#box-toplam').text(res.summary.total);
            $('#box-yeni').text(res.summary.new);
            $('#box-mukerrer').text(res.summary.duplicate);
            $('#box-hatalı').text(res.summary.error);

            buildPreviewTable();

            $('#step2Card').addClass('d-none');
            $('#step2Badge').addClass('completed');
            $('#step3Card').removeClass('d-none');
            $('#previewCard').removeClass('d-none');
            $('#importBtn').prop('disabled', res.summary.new === 0 || !canAdd);

            if (res.summary.new === 0) {
                showToast('Eklenecek yeni kayıt bulunamadı!', 'warning');
            } else {
                showToast('Analiz tamamlandı: ' + res.summary.new + ' yeni kayıt', 'success');
            }
        }, 'json').fail(function() {
            hideLoading();
            showError('Bağlantı Hatası!', 'Sunucuya ulaşılamıyor.');
        });
    });

    function buildPreviewTable() {
        const alanMap = {};
        cariAlanlari.forEach(a => { alanMap[a.key] = a; });

        let head = '<tr><th style="width:60px;">Satır</th><th style="width:90px;">Durum</th>';
        mappedFields.forEach(f => {
            if (alanMap[f]) head += '<th>' + escapeHtml(alanMap[f].label) + '</th>';
        });
        head += '<th>Not / Uyarı</th></tr>';
        $('#previewHead').html(head);

        let body = '';
        analizRows.forEach(row => {
            let badge = '<span class="badge bg-success">Yeni</span>';
            if (row.status === 'duplicate') badge = '<span class="badge bg-warning text-dark">Mükerrer</span>';
            if (row.status === 'error')     badge = '<span class="badge bg-danger">Hata</span>';

            body += '<tr><td>' + row.excel_satir + '</td><td>' + badge + '</td>';

            mappedFields.forEach(f => {
                if (!alanMap[f]) return;
                let val = row.data[f] !== undefined && row.data[f] !== null ? row.data[f] : '';

                // İl / İlçe: eşleşme durumunu renklendir
                if (f === 'sehir_adi' && val !== '') {
                    val = row.sehir_id
                        ? '<span class="text-success">' + escapeHtml(val) + '</span>'
                        : '<span class="text-danger"><i class="bi bi-x-circle"></i> ' + escapeHtml(val) + '</span>';
                } else if (f === 'ilce_adi' && val !== '') {
                    val = row.ilce_id
                        ? '<span class="text-success">' + escapeHtml(val) + '</span>'
                        : '<span class="text-danger"><i class="bi bi-x-circle"></i> ' + escapeHtml(val) + '</span>';
                } else {
                    val = escapeHtml(truncate(val, 30));
                }
                body += '<td>' + val + '</td>';
            });

            let not = '';
            if (row.mesaj) {
                not += '<span class="badge bg-warning text-dark uyari-badge">' + escapeHtml(row.mesaj) + '</span> ';
            }
            (row.uyarilar || []).forEach(u => {
                not += '<span class="badge bg-secondary uyari-badge">' + escapeHtml(u) + '</span> ';
            });
            body += '<td>' + (not || '<span class="text-muted">-</span>') + '</td></tr>';
        });
        $('#previewBody').html(body);
    }

    // ---------- IMPORT ----------
    $('#importBtn').on('click', function() {
        const yeni = parseInt($('#box-yeni').text());
        confirmAction(
            yeni + ' adet yeni cari eklenecek. Devam edilsin mi?',
            'Mükerrer kayıtlar atlanacak, mevcut cariler değiştirilmeyecek.',
            function() {
                showLoading();
                $.post('', { action: 'import' }, function(res) {
                    hideLoading();
                    if (!res.success) {
                        showError('Import Hatası!', res.message);
                        return;
                    }
                    let mesaj = res.message;
                    if (res.hatalar && res.hatalar.length) {
                        mesaj += '\n\nKaydedilemeyenler:\n' + res.hatalar.join('\n');
                    }
                    Swal.fire({
                        icon: 'success',
                        title: 'Import Tamamlandı',
                        text: mesaj,
                        confirmButtonText: 'Tamam'
                    }).then(function() { resetAll(); });
                }, 'json').fail(function() {
                    hideLoading();
                    showError('Bağlantı Hatası!', 'Sunucuya ulaşılamıyor.');
                });
            }
        );
    });

    // ---------- NAVİGASYON ----------
    $('#backToStep1Btn').on('click', function() {
        $('#step2Card').addClass('d-none');
        $('#step1Card').removeClass('d-none');
        $('#step1Badge').removeClass('completed');
    });

    $('#backToStep2Btn').on('click', function() {
        $('#step3Card').addClass('d-none');
        $('#previewCard').addClass('d-none');
        $('#step2Card').removeClass('d-none');
        $('#step2Badge').removeClass('completed');
    });

    $('#resetAllBtn').on('click', function() {
        confirmAction('Tüm veriler sıfırlanacak.', 'Yüklenen dosya ve eşleştirmeler silinecek.', resetAll);
    });

    function resetAll() {
        $.post('', { action: 'clear' });
        excelHeaders = [];
        excelData    = [];
        analizRows   = [];
        mappedFields = [];

        $('#excelFile').val('');
        $('#readFileBtn').prop('disabled', true);
        $('#mappingBody').html('');
        $('#excelPreviewHead, #excelPreviewBody, #previewHead, #previewBody').html('');
        $('#box-toplam, #box-yeni, #box-mukerrer, #box-hatalı').text('0');
        $('#excelColCount').text('0 sütun');
        $('#excelRowCount').text('0 satır');

        $('#step2Card, #step3Card, #previewCard').addClass('d-none');
        $('#step2Card').addClass('disabled-step');
        $('#step1Card').removeClass('d-none');
        $('#step1Badge, #step2Badge').removeClass('completed');
        $('#resetAllBtn').hide();
    }

    // ---------- ŞABLON İNDİR ----------
    $('#downloadTemplateBtn').on('click', function() {
        const basliklar = cariAlanlari.map(f => f.label);
        const ornek = cariAlanlari.map(f => {
            switch (f.key) {
                case 'cari_adi':              return 'Örnek Firma';
                case 'cari_unvan':            return 'Örnek Firma Sanayi ve Ticaret A.Ş.';
                case 'cari_vergi_dairesi':    return 'Kadıköy';
                case 'cari_vergi_no':         return '1234567890';
                case 'cari_mersis_no':        return '0123456789012345';
                case 'cari_ticaret_sicil_no': return '123456';
                case 'cari_telefon':          return '02161234567';
                case 'cari_email':            return 'info@ornekfirma.com';
                case 'cari_web_sitesi':       return 'www.ornekfirma.com';
                case 'sehir_adi':             return 'İstanbul';
                case 'ilce_adi':              return 'Kadıköy';
                case 'cari_adres':            return 'Örnek Mah. Örnek Cad. No:1';
                case 'cari_posta_kodu':       return '34700';
                case 'cari_yetkili_adi':      return 'Ahmet Yılmaz';
                case 'cari_yetkili_telefon':  return '05321234567';
                case 'cari_yetkili_email':    return 'ahmet@ornekfirma.com';
                default: return '';
            }
        });

        const ws = XLSX.utils.aoa_to_sheet([basliklar, ornek]);
        ws['!cols'] = basliklar.map(() => ({ wch: 22 }));
        const wb = XLSX.utils.book_new();
        XLSX.utils.book_append_sheet(wb, ws, 'Cari');
        XLSX.writeFile(wb, 'teklif-cari-yukleme-sablonu.xlsx');
        showToast('Şablon indirildi', 'success');
    });

    // ---------- INIT ----------
    $(document).ready(function() {
        $('#cariTipiSelect, #ulkeSelect, #aktifSelect').select2(select2Ayar);
        if (!canAdd) {
            $('#readFileBtn, #analyzeBtn, #importBtn').prop('disabled', true);
            showToast('Bu sayfada kayıt ekleme yetkiniz bulunmamaktadır.', 'warning');
        }
    });
    </script>
</body>
</html>
