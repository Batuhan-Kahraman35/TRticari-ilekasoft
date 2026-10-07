<?php
/**
 * Admin Panel - Sektör Yönetimi
 *
 * Cari sektör tanımları + Excel ile toplu sektör eşleştirme
 */

require_once __DIR__ . '/../auth.php';
require_once __DIR__ . '/../includes/PageAuth.php';
requireAuth();

$user = Auth::user();
$db   = Database::getInstance();

// Sayfa yetki kontrolü
$currentPageFile = basename($_SERVER['PHP_SELF']);
$pagePermissions = PageAuth::checkPagePermissions(
    $user['kullanici_id'],
    $user['departman_id'],
    $currentPageFile
);

if (!$pagePermissions['has_access']) {
    PageAuth::accessDenied($pagePermissions['error'] ?? 'Bu sayfaya erişim yetkiniz bulunmamaktadır.');
}

// Sayfa bilgileri
$pageInfo = $db->fetchOne("
    SELECT s.sayfalar_sayfa_adi, s.sayfalar_aciklama, m.menuler_menu_adi AS menu_adi
    FROM Menu_Sayfalar s
    LEFT JOIN Menuler m ON s.sayfalar_menu_id = m.menuler_id
    WHERE s.sayfalar_sayfa_url LIKE ? AND s.sayfalar_durum = 1
", ['%' . $currentPageFile]);

$pageTitle       = $pageInfo['sayfalar_sayfa_adi'] ?? 'Sektör Yönetimi';
$pageDescription = $pageInfo['sayfalar_aciklama'] ?? '';
$menuAdi         = $pageInfo['menu_adi'] ?? null;

$siteAyarlari = $db->fetchOne("SELECT TOP 1 site_ayarlari_site_title FROM dbo.tanim_site_ayarlari ORDER BY site_ayarlari_id DESC");
$siteTitle    = $siteAyarlari['site_ayarlari_site_title'] ?? 'Örnek Yazılım Portal';

/**
 * Sektör adı karşılaştırma anahtarı (Türkçe karakter duyarlı büyük harf + boşluk sadeleştirme)
 */
function sektorAnahtar($ad) {
    $ad = trim((string)$ad);
    if ($ad === '') return '';
    $ad = preg_replace('/\s+/u', ' ', $ad);
    $ad = str_replace(['i', 'ı'], ['İ', 'I'], $ad);
    return mb_strtoupper($ad, 'UTF-8');
}

/**
 * Mevcut sektörleri anahtar => kayıt şeklinde döner
 */
function sektorHaritasi($db) {
    $harita = [];
    foreach ($db->fetchAll("SELECT sektor_id, sektor_ad, sektor_kod FROM dbo.Cari_Sektorler") as $s) {
        $harita[sektorAnahtar($s['sektor_ad'])] = $s;
        if (!empty($s['sektor_kod'])) {
            $kodAnahtar = sektorAnahtar($s['sektor_kod']);
            if (!isset($harita[$kodAnahtar])) $harita[$kodAnahtar] = $s;
        }
    }
    return $harita;
}

// ==========================================================
// AJAX
// ==========================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json; charset=utf-8');
    $action = $_POST['action'] ?? '';

    try {
        switch ($action) {

            // ------------------------------------------------ InfoBox
            case 'stats':
                $stats = [
                    'toplam'    => (int)($db->fetchOne("SELECT COUNT(*) AS sayi FROM dbo.Cari_Sektorler")['sayi'] ?? 0),
                    'aktif'     => (int)($db->fetchOne("SELECT COUNT(*) AS sayi FROM dbo.Cari_Sektorler WHERE Durum = 1")['sayi'] ?? 0),
                    'pasif'     => (int)($db->fetchOne("SELECT COUNT(*) AS sayi FROM dbo.Cari_Sektorler WHERE Durum = 0")['sayi'] ?? 0),
                    'sektorsuz' => (int)($db->fetchOne("SELECT COUNT(*) AS sayi FROM dbo.Cari WHERE cari_sektor_id IS NULL")['sayi'] ?? 0)
                ];
                echo json_encode(['success' => true, 'data' => $stats], JSON_UNESCAPED_UNICODE);
                break;

            // ------------------------------------------------ Liste
            case 'list':
                $rows = $db->fetchAll("
                    SELECT
                        s.sektor_id,
                        s.sektor_ad,
                        s.sektor_kod,
                        s.sektor_aciklama,
                        s.sektor_ikon,
                        s.sektor_renk,
                        s.sektor_sira_no,
                        s.Durum,
                        CONVERT(VARCHAR(19), s.OlusturmaTarihi, 120)  AS olusturma_tarihi,
                        CONVERT(VARCHAR(19), s.GuncellemeTarihi, 120) AS guncelleme_tarihi,
                        olusturan.kullanici_ad + ' ' + olusturan.kullanici_soyad     AS olusturan_adi,
                        guncelleyen.kullanici_ad + ' ' + guncelleyen.kullanici_soyad AS guncelleyen_adi,
                        (SELECT COUNT(*) FROM dbo.Cari c WHERE c.cari_sektor_id = s.sektor_id) AS cari_sayisi
                    FROM dbo.Cari_Sektorler s
                    LEFT JOIN kullanicilar olusturan   ON olusturan.kullanici_id   = s.OlusturanKullanici
                    LEFT JOIN kullanicilar guncelleyen ON guncelleyen.kullanici_id = s.GuncelleyenKullanici
                    ORDER BY s.sektor_sira_no, s.sektor_ad
                ");
                echo json_encode(['success' => true, 'data' => $rows], JSON_UNESCAPED_UNICODE);
                break;

            // ------------------------------------------------ Tek kayıt
            case 'get':
                $id  = intval($_POST['id'] ?? 0);
                $row = $db->fetchOne("SELECT * FROM dbo.Cari_Sektorler WHERE sektor_id = ?", [$id]);
                if (!$row) throw new Exception('Sektör bulunamadı!');
                echo json_encode(['success' => true, 'data' => $row], JSON_UNESCAPED_UNICODE);
                break;

            // ------------------------------------------------ Kaydet
            case 'save':
                $id = intval($_POST['id'] ?? 0);
                if ($id > 0 && !$pagePermissions['can_edit']) throw new Exception('Düzenleme yetkiniz yok!');
                if ($id == 0 && !$pagePermissions['can_add'])  throw new Exception('Ekleme yetkiniz yok!');

                $ad       = trim($_POST['sektor_ad'] ?? '');
                $kod      = trim($_POST['sektor_kod'] ?? '');
                $aciklama = trim($_POST['sektor_aciklama'] ?? '');
                $ikon     = trim($_POST['sektor_ikon'] ?? '');
                $renk     = trim($_POST['sektor_renk'] ?? '');
                $sira     = intval($_POST['sektor_sira_no'] ?? 0);
                $durum    = isset($_POST['Durum']) ? 1 : 0;

                if ($ad === '') throw new Exception('Sektör adı zorunludur!');

                // Mükerrer ad kontrolü
                $mukerrerSql    = "SELECT sektor_id FROM dbo.Cari_Sektorler WHERE sektor_ad = ?";
                $mukerrerParams = [$ad];
                if ($id > 0) { $mukerrerSql .= " AND sektor_id <> ?"; $mukerrerParams[] = $id; }
                if ($db->fetchOne($mukerrerSql, $mukerrerParams)) {
                    throw new Exception('Bu sektör adı zaten kayıtlı!');
                }

                if ($id > 0) {
                    $db->execute("
                        UPDATE dbo.Cari_Sektorler SET
                            sektor_ad = ?, sektor_kod = ?, sektor_aciklama = ?,
                            sektor_ikon = ?, sektor_renk = ?, sektor_sira_no = ?, Durum = ?,
                            GuncelleyenKullanici = ?, GuncellemeTarihi = GETDATE()
                        WHERE sektor_id = ?
                    ", [$ad, $kod ?: null, $aciklama ?: null, $ikon ?: null, $renk ?: null, $sira, $durum, $user['kullanici_id'], $id]);
                    echo json_encode(['success' => true, 'message' => 'Sektör güncellendi.'], JSON_UNESCAPED_UNICODE);
                } else {
                    $db->execute("
                        INSERT INTO dbo.Cari_Sektorler
                            (sektor_ad, sektor_kod, sektor_aciklama, sektor_ikon, sektor_renk, sektor_sira_no, Durum, OlusturanKullanici, OlusturmaTarihi)
                        VALUES (?, ?, ?, ?, ?, ?, ?, ?, GETDATE())
                    ", [$ad, $kod ?: null, $aciklama ?: null, $ikon ?: null, $renk ?: null, $sira, $durum, $user['kullanici_id']]);
                    echo json_encode(['success' => true, 'message' => 'Sektör eklendi.'], JSON_UNESCAPED_UNICODE);
                }
                break;

            // ------------------------------------------------ Sil
            case 'delete':
                if (!$pagePermissions['can_delete']) throw new Exception('Silme yetkiniz yok!');
                $id = intval($_POST['id'] ?? 0);

                $bagliCari = (int)($db->fetchOne("SELECT COUNT(*) AS sayi FROM dbo.Cari WHERE cari_sektor_id = ?", [$id])['sayi'] ?? 0);
                if ($bagliCari > 0) {
                    throw new Exception('Bu sektöre bağlı ' . $bagliCari . ' cari var, silinemez. Kullanımdan kaldırmak için pasife alın.');
                }

                $db->execute("DELETE FROM dbo.Cari_Sektorler WHERE sektor_id = ?", [$id]);
                echo json_encode(['success' => true, 'message' => 'Sektör silindi.'], JSON_UNESCAPED_UNICODE);
                break;

            // ------------------------------------------------ Excel eşleştirme (önizleme + uygula)
            case 'eslestir_onizleme':
            case 'eslestir_uygula':
                $uygula = ($action === 'eslestir_uygula');
                if ($uygula && !$pagePermissions['can_edit']) throw new Exception('Düzenleme yetkiniz yok!');

                $satirlar    = json_decode($_POST['satirlar'] ?? '[]', true);
                $anahtarTipi = ($_POST['anahtar_tipi'] ?? 'vergi_no') === 'cari_adi' ? 'cari_adi' : 'vergi_no';
                $yeniOlustur = ($_POST['yeni_sektor_olustur'] ?? '0') === '1';

                if (!is_array($satirlar) || count($satirlar) === 0) throw new Exception('İşlenecek satır bulunamadı!');
                if (count($satirlar) > 20000) throw new Exception('Tek seferde en fazla 20.000 satır işlenebilir.');

                $anahtarKolon = $anahtarTipi === 'cari_adi' ? 'cari_adi' : 'cari_vergi_no';
                $sektorler    = sektorHaritasi($db);

                $sonuc             = [];
                $gorulen           = [];   // Excel içi mükerrer takibi
                $ozet              = ['hazir' => 0, 'yeni_sektor' => 0, 'degisiklik_yok' => 0, 'cari_yok' => 0, 'sektor_yok' => 0, 'eksik' => 0, 'mukerrer' => 0];
                $guncellenenCari   = 0;
                $olusturulanSektor = 0;

                foreach ($satirlar as $i => $s) {
                    $satirNo  = intval($s['satir'] ?? ($i + 2));
                    $anahtar  = trim((string)($s['anahtar'] ?? ''));
                    $sektorAd = trim((string)($s['sektor'] ?? ''));

                    $kayit = [
                        'satir'         => $satirNo,
                        'anahtar'       => $anahtar,
                        'sektor_excel'  => $sektorAd,
                        'cari_adi'      => '',
                        'cari_sayisi'   => 0,
                        'mevcut_sektor' => '',
                        'durum'         => '',
                        'mesaj'         => ''
                    ];

                    if ($anahtar === '' || $sektorAd === '') {
                        $kayit['durum'] = 'eksik';
                        $kayit['mesaj'] = 'Anahtar veya sektör alanı boş';
                        $ozet['eksik']++;
                        $sonuc[] = $kayit;
                        continue;
                    }

                    $tekil = sektorAnahtar($anahtar);
                    if (isset($gorulen[$tekil])) {
                        $kayit['durum'] = 'mukerrer';
                        $kayit['mesaj'] = 'Excel içinde mükerrer (Satır ' . $gorulen[$tekil] . ')';
                        $ozet['mukerrer']++;
                        $sonuc[] = $kayit;
                        continue;
                    }
                    $gorulen[$tekil] = $satirNo;

                    // Sektör çözümleme
                    $sektorKey = sektorAnahtar($sektorAd);
                    $sektorId  = null;
                    $yeniMi    = false;

                    if (isset($sektorler[$sektorKey])) {
                        $sektorId = (int)$sektorler[$sektorKey]['sektor_id'];
                    } elseif ($yeniOlustur) {
                        $yeniMi = true;
                        if ($uygula) {
                            $db->execute("
                                INSERT INTO dbo.Cari_Sektorler (sektor_ad, sektor_sira_no, Durum, OlusturanKullanici, OlusturmaTarihi)
                                VALUES (?, 0, 1, ?, GETDATE())
                            ", [$sektorAd, $user['kullanici_id']]);
                            $yeni = $db->fetchOne("SELECT sektor_id, sektor_ad, sektor_kod FROM dbo.Cari_Sektorler WHERE sektor_ad = ?", [$sektorAd]);
                            $sektorId = (int)$yeni['sektor_id'];
                            $sektorler[$sektorKey] = $yeni;
                            $olusturulanSektor++;
                        }
                    } else {
                        $kayit['durum'] = 'sektor_yok';
                        $kayit['mesaj'] = 'Sektör tanımlı değil';
                        $ozet['sektor_yok']++;
                        $sonuc[] = $kayit;
                        continue;
                    }

                    // Cari çözümleme
                    $cariler = $db->fetchAll("
                        SELECT c.cari_id, c.cari_adi, c.cari_sektor_id, s.sektor_ad AS mevcut_sektor_ad
                        FROM dbo.Cari c
                        LEFT JOIN dbo.Cari_Sektorler s ON s.sektor_id = c.cari_sektor_id
                        WHERE c.$anahtarKolon = ?
                    ", [$anahtar]);

                    if (count($cariler) === 0) {
                        $kayit['durum'] = 'cari_yok';
                        $kayit['mesaj'] = 'Eşleşen cari bulunamadı';
                        $ozet['cari_yok']++;
                        $sonuc[] = $kayit;
                        continue;
                    }

                    $kayit['cari_adi']      = $cariler[0]['cari_adi'];
                    $kayit['cari_sayisi']   = count($cariler);
                    $kayit['mevcut_sektor'] = $cariler[0]['mevcut_sektor_ad'] ?? '';

                    // Değişiklik gerekmeyenleri ayır
                    $degisecek = [];
                    foreach ($cariler as $c) {
                        if ($sektorId === null || (int)$c['cari_sektor_id'] !== $sektorId) $degisecek[] = (int)$c['cari_id'];
                    }

                    if (!$yeniMi && count($degisecek) === 0) {
                        $kayit['durum'] = 'degisiklik_yok';
                        $kayit['mesaj'] = 'Sektör zaten atanmış';
                        $ozet['degisiklik_yok']++;
                        $sonuc[] = $kayit;
                        continue;
                    }

                    if ($uygula && $sektorId !== null && count($degisecek) > 0) {
                        $yerTutucu = implode(',', array_fill(0, count($degisecek), '?'));
                        $params    = array_merge([$sektorId, $user['kullanici_id']], $degisecek);
                        $db->execute("
                            UPDATE dbo.Cari
                            SET cari_sektor_id = ?, cari_guncelleyen_kullanici = ?, cari_guncelleme_tarihi = GETDATE()
                            WHERE cari_id IN ($yerTutucu)
                        ", $params);
                        $guncellenenCari += count($degisecek);
                    }

                    $kayit['durum'] = $yeniMi ? 'yeni_sektor' : 'hazir';
                    $kayit['mesaj'] = $yeniMi
                        ? ($uygula ? 'Sektör oluşturuldu ve atandı' : 'Sektör yeni oluşturulacak')
                        : ($uygula ? 'Atandı' : 'Atanacak');
                    if (count($cariler) > 1) $kayit['mesaj'] .= ' (' . count($cariler) . ' cari)';
                    $ozet[$yeniMi ? 'yeni_sektor' : 'hazir']++;
                    $sonuc[] = $kayit;
                }

                echo json_encode([
                    'success'   => true,
                    'data'      => $sonuc,
                    'ozet'      => $ozet,
                    'uygulandi' => $uygula,
                    'message'   => $uygula
                        ? ($guncellenenCari . ' cari güncellendi, ' . $olusturulanSektor . ' yeni sektör oluşturuldu.')
                        : 'Önizleme hazırlandı.'
                ], JSON_UNESCAPED_UNICODE);
                break;

            default:
                throw new Exception('Geçersiz işlem!');
        }
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'message' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
    }
    exit;
}
?><!DOCTYPE html>
<html lang="tr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars($pageTitle) ?> - <?= htmlspecialchars($siteTitle) ?></title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/@fontsource/source-sans-3@5.0.12/index.css" crossorigin="anonymous">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.13.1/font/bootstrap-icons.min.css" crossorigin="anonymous">
    <link rel="stylesheet" href="/admin/assets/css/adminlte.min.css">
    <link rel="stylesheet" href="https://cdn.datatables.net/1.13.7/css/dataTables.bootstrap5.min.css">
    <link href="https://cdn.jsdelivr.net/npm/sweetalert2@11/dist/sweetalert2.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/select2-bootstrap-5-theme@1.3.0/dist/select2-bootstrap-5-theme.min.css" rel="stylesheet">
    <link rel="stylesheet" href="/admin/assets/css/custom.css">
    <script src="https://cdn.sheetjs.com/xlsx-0.20.1/package/dist/xlsx.full.min.js"></script>
    <style>
        .step-card.disabled-step { opacity: .5; pointer-events: none; }
        .step-number { display: inline-flex; align-items: center; justify-content: center; width: 28px; height: 28px; border-radius: 50%; background: #0d6efd; color: #fff; font-weight: bold; font-size: 14px; margin-right: 8px; }
        .step-number.completed { background: #198754; }
        .sektor-renk-kutu { display: inline-block; width: 16px; height: 16px; border-radius: 4px; vertical-align: -3px; margin-right: 6px; border: 1px solid rgba(0,0,0,.15); }
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
                            <?php if ($pageDescription): ?><small class="text-muted"><?= htmlspecialchars($pageDescription) ?></small><?php endif; ?>
                        </div>
                        <div class="col-sm-6">
                            <ol class="breadcrumb float-sm-end">
                                <?php if ($menuAdi): ?><li class="breadcrumb-item"><?= htmlspecialchars($menuAdi) ?></li><?php endif; ?>
                                <li class="breadcrumb-item active"><?= htmlspecialchars($pageTitle) ?></li>
                            </ol>
                        </div>
                    </div>
                </div>
            </div>

            <div class="app-content">
                <div class="container-fluid">

                    <!-- InfoBox -->
                    <div class="row mb-3">
                        <div class="col-12 col-sm-6 col-md-3">
                            <div class="info-box">
                                <span class="info-box-icon text-bg-primary shadow-sm"><i class="bi bi-diagram-3"></i></span>
                                <div class="info-box-content">
                                    <span class="info-box-text">Toplam Sektör</span>
                                    <span class="info-box-number" id="stat-toplam">0</span>
                                </div>
                            </div>
                        </div>
                        <div class="col-12 col-sm-6 col-md-3">
                            <div class="info-box">
                                <span class="info-box-icon text-bg-success shadow-sm"><i class="bi bi-check-circle"></i></span>
                                <div class="info-box-content">
                                    <span class="info-box-text">Aktif</span>
                                    <span class="info-box-number" id="stat-aktif">0</span>
                                </div>
                            </div>
                        </div>
                        <div class="col-12 col-sm-6 col-md-3">
                            <div class="info-box">
                                <span class="info-box-icon text-bg-danger shadow-sm"><i class="bi bi-x-circle"></i></span>
                                <div class="info-box-content">
                                    <span class="info-box-text">Pasif</span>
                                    <span class="info-box-number" id="stat-pasif">0</span>
                                </div>
                            </div>
                        </div>
                        <div class="col-12 col-sm-6 col-md-3">
                            <div class="info-box">
                                <span class="info-box-icon text-bg-warning shadow-sm"><i class="bi bi-question-circle"></i></span>
                                <div class="info-box-content">
                                    <span class="info-box-text">Sektörsüz Cari</span>
                                    <span class="info-box-number" id="stat-sektorsuz">0</span>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Sekmeler -->
                    <ul class="nav nav-tabs mb-3" id="sektorTab" role="tablist">
                        <li class="nav-item" role="presentation">
                            <button class="nav-link active" id="tab-liste-btn" data-bs-toggle="tab" data-bs-target="#tab-liste" type="button" role="tab">
                                <i class="bi bi-list-ul"></i> Sektör Tanımları
                            </button>
                        </li>
                        <li class="nav-item" role="presentation">
                            <button class="nav-link" id="tab-excel-btn" data-bs-toggle="tab" data-bs-target="#tab-excel" type="button" role="tab">
                                <i class="bi bi-file-earmark-excel"></i> Excel ile Toplu Eşleştirme
                            </button>
                        </li>
                    </ul>

                    <div class="tab-content">

                        <!-- ============ SEKME 1: TANIMLAR ============ -->
                        <div class="tab-pane fade show active" id="tab-liste" role="tabpanel">

                            <!-- Filtre -->
                            <div class="card mb-3">
                                <div class="card-header" role="button" data-bs-toggle="collapse" data-bs-target="#filtrePanel">
                                    <h3 class="card-title"><i class="bi bi-funnel"></i> Filtre</h3>
                                    <div class="card-tools"><i class="bi bi-chevron-down"></i></div>
                                </div>
                                <div class="collapse show" id="filtrePanel">
                                    <div class="card-body">
                                        <div class="row g-3">
                                            <div class="col-md-4">
                                                <label class="form-label">Ara</label>
                                                <input type="text" class="form-control" id="filtreArama" placeholder="Sektör adı, kod, açıklama...">
                                            </div>
                                            <div class="col-md-3">
                                                <label class="form-label">Durum</label>
                                                <select class="form-select" id="filtreDurum">
                                                    <option value="">Tümü</option>
                                                    <option value="1">Aktif</option>
                                                    <option value="0">Pasif</option>
                                                </select>
                                            </div>
                                            <div class="col-md-3">
                                                <label class="form-label">Cari Durumu</label>
                                                <select class="form-select" id="filtreCari">
                                                    <option value="">Tümü</option>
                                                    <option value="dolu">Cari atanmış</option>
                                                    <option value="bos">Hiç cari yok</option>
                                                </select>
                                            </div>
                                            <div class="col-md-2 d-flex align-items-end">
                                                <button class="btn btn-secondary w-100" onclick="filtreTemizle()"><i class="bi bi-arrow-counterclockwise"></i> Temizle</button>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>

                            <div class="card">
                                <div class="card-header">
                                    <h3 class="card-title">Sektörler</h3>
                                    <div class="card-tools">
                                        <?php if ($pagePermissions['can_add']): ?>
                                        <button type="button" class="btn btn-primary btn-sm" onclick="sektorModalAc()">
                                            <i class="bi bi-plus-circle"></i> Yeni Sektör
                                        </button>
                                        <?php endif; ?>
                                    </div>
                                </div>
                                <div class="card-body">
                                    <table id="sektorTable" class="table table-bordered table-striped w-100">
                                        <thead>
                                            <tr>
                                                <th>ID</th>
                                                <th>Sektör Adı</th>
                                                <th>Kod</th>
                                                <th>Açıklama</th>
                                                <th>Cari Sayısı</th>
                                                <th>Sıra</th>
                                                <th>Durum</th>
                                                <th>İşlemler</th>
                                            </tr>
                                        </thead>
                                        <tbody></tbody>
                                    </table>
                                </div>
                            </div>
                        </div>

                        <!-- ============ SEKME 2: EXCEL EŞLEŞTİRME ============ -->
                        <div class="tab-pane fade" id="tab-excel" role="tabpanel">

                            <!-- Adım 1: Dosya -->
                            <div class="card step-card mb-3" id="adim1Card">
                                <div class="card-header">
                                    <h3 class="card-title"><span class="step-number" id="adim1No">1</span> Excel / CSV Dosyası</h3>
                                </div>
                                <div class="card-body">
                                    <div class="row g-3">
                                        <div class="col-md-6">
                                            <label class="form-label fw-bold">Dosya Seç</label>
                                            <input type="file" class="form-control" id="excelFile" accept=".csv,.xlsx,.xls">
                                            <div class="form-text">Desteklenen: .xlsx, .xls, .csv — ilk satır başlık olmalıdır.</div>
                                        </div>
                                        <div class="col-md-6 d-flex align-items-end">
                                            <button class="btn btn-primary" onclick="excelOku()"><i class="bi bi-upload"></i> Dosyayı Oku</button>
                                            <button class="btn btn-outline-secondary ms-2" onclick="ornekSablonIndir()"><i class="bi bi-download"></i> Örnek Şablon</button>
                                        </div>
                                    </div>
                                    <div class="alert alert-info mt-3 mb-0">
                                        <i class="bi bi-info-circle"></i>
                                        Dosyada en az iki sütun olmalı: cariyi bulmak için <strong>Vergi No</strong> (veya Cari Adı) ve atanacak <strong>Sektör</strong>.
                                    </div>
                                </div>
                            </div>

                            <!-- Adım 2: Eşleştirme -->
                            <div class="card step-card mb-3 d-none disabled-step" id="adim2Card">
                                <div class="card-header">
                                    <h3 class="card-title"><span class="step-number" id="adim2No">2</span> Sütun Eşleştirme</h3>
                                    <div class="card-tools">
                                        <span class="badge bg-info" id="excelColCount">0 sütun</span>
                                        <span class="badge bg-primary" id="excelRowCount">0 satır</span>
                                    </div>
                                </div>
                                <div class="card-body">
                                    <div class="row g-3">
                                        <div class="col-md-4">
                                            <label class="form-label fw-bold">Eşleşme Anahtarı <span class="text-danger">*</span></label>
                                            <select class="form-select" id="anahtarTipi">
                                                <option value="vergi_no">Vergi No</option>
                                                <option value="cari_adi">Cari Adı</option>
                                            </select>
                                            <div class="form-text">Carinin hangi alanına göre eşleşeceği</div>
                                        </div>
                                        <div class="col-md-4">
                                            <label class="form-label fw-bold">Anahtar Sütunu <span class="text-danger">*</span></label>
                                            <select class="form-select" id="mapAnahtar"></select>
                                            <div class="form-text" id="prevAnahtar">-</div>
                                        </div>
                                        <div class="col-md-4">
                                            <label class="form-label fw-bold">Sektör Sütunu <span class="text-danger">*</span></label>
                                            <select class="form-select" id="mapSektor"></select>
                                            <div class="form-text" id="prevSektor">-</div>
                                        </div>
                                    </div>

                                    <div class="form-check form-switch mt-3">
                                        <input class="form-check-input" type="checkbox" role="switch" id="yeniSektorOlustur" value="1">
                                        <label class="form-check-label" for="yeniSektorOlustur">
                                            Tanımlı olmayan sektörleri otomatik oluştur
                                        </label>
                                    </div>

                                    <hr>
                                    <h6 class="fw-bold">Dosya Önizleme (ilk 5 satır)</h6>
                                    <div class="table-responsive">
                                        <table class="table table-sm table-bordered mb-0" id="excelOnizlemeTablo">
                                            <thead></thead>
                                            <tbody></tbody>
                                        </table>
                                    </div>

                                    <div class="mt-3">
                                        <button class="btn btn-primary" onclick="onizlemeYap()"><i class="bi bi-search"></i> Kontrol Et</button>
                                        <button class="btn btn-secondary" onclick="excelSifirla()"><i class="bi bi-arrow-counterclockwise"></i> Baştan Başla</button>
                                    </div>
                                </div>
                            </div>

                            <!-- Adım 3: Önizleme -->
                            <div class="card step-card mb-3 d-none disabled-step" id="adim3Card">
                                <div class="card-header">
                                    <h3 class="card-title"><span class="step-number" id="adim3No">3</span> Kontrol Sonucu</h3>
                                </div>
                                <div class="card-body">
                                    <div class="row g-2 mb-3" id="ozetKutulari"></div>

                                    <div class="row g-3 mb-3">
                                        <div class="col-md-4">
                                            <label class="form-label">Sonuç Filtresi</label>
                                            <select class="form-select" id="sonucFiltre">
                                                <option value="">Tümü</option>
                                                <option value="hazir">Atanacak</option>
                                                <option value="yeni_sektor">Yeni sektör</option>
                                                <option value="degisiklik_yok">Değişiklik yok</option>
                                                <option value="cari_yok">Cari bulunamadı</option>
                                                <option value="sektor_yok">Sektör tanımsız</option>
                                                <option value="mukerrer">Mükerrer</option>
                                                <option value="eksik">Eksik veri</option>
                                            </select>
                                        </div>
                                    </div>

                                    <table id="sonucTable" class="table table-bordered table-striped table-sm w-100">
                                        <thead>
                                            <tr>
                                                <th>Satır</th>
                                                <th>Anahtar</th>
                                                <th>Cari</th>
                                                <th>Mevcut Sektör</th>
                                                <th>Excel Sektör</th>
                                                <th>Durum</th>
                                                <th>Açıklama</th>
                                            </tr>
                                        </thead>
                                        <tbody></tbody>
                                    </table>

                                    <div class="mt-3">
                                        <?php if ($pagePermissions['can_edit']): ?>
                                        <button class="btn btn-success" id="uygulaBtn" onclick="eslestirUygula()">
                                            <i class="bi bi-check2-circle"></i> Eşleştirmeyi Uygula
                                        </button>
                                        <?php else: ?>
                                        <div class="alert alert-warning mb-0"><i class="bi bi-lock"></i> Uygulamak için düzenleme yetkisi gerekir.</div>
                                        <?php endif; ?>
                                        <button class="btn btn-secondary" onclick="excelSifirla()"><i class="bi bi-arrow-counterclockwise"></i> Baştan Başla</button>
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

    <!-- Sektör Modal -->
    <div class="modal fade" id="sektorModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="sektorModalBaslik">Yeni Sektör</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <form id="sektorForm">
                    <div class="modal-body">
                        <input type="hidden" name="id" id="sektor_id">

                        <div class="mb-3">
                            <label for="sektor_ad" class="form-label">Sektör Adı <span class="text-danger">*</span></label>
                            <input type="text" class="form-control" id="sektor_ad" name="sektor_ad" required placeholder="örn: BERBER">
                        </div>

                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label for="sektor_kod" class="form-label">Kod</label>
                                <input type="text" class="form-control" id="sektor_kod" name="sektor_kod" placeholder="örn: BRB">
                                <small class="text-muted">Excel eşleştirmede alternatif anahtar olarak kullanılır</small>
                            </div>
                            <div class="col-md-6 mb-3">
                                <label for="sektor_sira_no" class="form-label">Sıra No</label>
                                <input type="number" class="form-control" id="sektor_sira_no" name="sektor_sira_no" value="0">
                            </div>
                        </div>

                        <div class="mb-3">
                            <label for="sektor_aciklama" class="form-label">Açıklama</label>
                            <textarea class="form-control" id="sektor_aciklama" name="sektor_aciklama" rows="2"></textarea>
                        </div>

                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label for="sektor_ikon" class="form-label">İkon</label>
                                <input type="text" class="form-control" id="sektor_ikon" name="sektor_ikon" placeholder="bi bi-shop">
                                <small class="text-muted">Bootstrap Icons sınıfı</small>
                            </div>
                            <div class="col-md-6 mb-3">
                                <label for="sektor_renk" class="form-label">Renk</label>
                                <input type="color" class="form-control form-control-color" id="sektor_renk" name="sektor_renk" value="#0d6efd">
                            </div>
                        </div>

                        <div class="form-check form-switch">
                            <input class="form-check-input" type="checkbox" role="switch" id="Durum" name="Durum" value="1" checked>
                            <label class="form-check-label" for="Durum">Aktif</label>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal"><i class="bi bi-x-circle"></i> İptal</button>
                        <button type="submit" class="btn btn-primary"><i class="bi bi-save"></i> Kaydet</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/@popperjs/core@2.11.8/dist/umd/popper.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.7/dist/js/bootstrap.min.js"></script>
    <script src="/admin/assets/js/adminlte.min.js"></script>
    <script src="https://cdn.datatables.net/1.13.7/js/jquery.dataTables.min.js"></script>
    <script src="https://cdn.datatables.net/1.13.7/js/dataTables.bootstrap5.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11/dist/sweetalert2.all.min.js"></script>
    <script src="/admin/assets/js/custom.js"></script>

    <script>
    const pagePermissions = {
        can_add:    <?= $pagePermissions['can_add'] ? 'true' : 'false' ?>,
        can_edit:   <?= $pagePermissions['can_edit'] ? 'true' : 'false' ?>,
        can_delete: <?= $pagePermissions['can_delete'] ? 'true' : 'false' ?>
    };

    let sektorTable = null;
    let sonucTable  = null;
    let sektorModal = null;

    let excelBasliklar = [];
    let excelSatirlar  = [];
    let sonSonuc       = [];

    function escapeHtml(s) {
        return String(s === null || s === undefined ? '' : s)
            .replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
    }

    // ==========================================================
    // SEKME 1 - TANIMLAR
    // ==========================================================
    function istatistikYukle() {
        $.post('', { action: 'stats' }, function(r) {
            if (r.success) {
                $('#stat-toplam').text(r.data.toplam);
                $('#stat-aktif').text(r.data.aktif);
                $('#stat-pasif').text(r.data.pasif);
                $('#stat-sektorsuz').text(r.data.sektorsuz);
            }
        }, 'json');
    }

    function tabloBaslat() {
        sektorTable = $('#sektorTable').DataTable({
            processing: true,
            scrollX: true,
            dom: 'lrtip',
            language: { url: '//cdn.datatables.net/plug-ins/1.13.7/i18n/tr.json' },
            ajax: {
                url: '',
                type: 'POST',
                data: { action: 'list' },
                dataSrc: function(json) {
                    if (json.success) return json.data;
                    showToast(json.message || 'Veri yüklenemedi!', 'error');
                    return [];
                }
            },
            columns: [
                { data: 'sektor_id' },
                {
                    data: 'sektor_ad',
                    render: function(data, type, row) {
                        if (type !== 'display') return data;
                        let html = '';
                        if (row.sektor_renk) html += '<span class="sektor-renk-kutu" style="background:' + escapeHtml(row.sektor_renk) + '"></span>';
                        if (row.sektor_ikon) html += '<i class="' + escapeHtml(row.sektor_ikon) + ' me-1"></i>';
                        return html + escapeHtml(data);
                    }
                },
                { data: 'sektor_kod', render: d => d ? escapeHtml(d) : '-' },
                { data: 'sektor_aciklama', render: d => d ? escapeHtml(d) : '-' },
                {
                    data: 'cari_sayisi',
                    render: function(d, type) {
                        if (type !== 'display') return d;
                        return d > 0
                            ? '<span class="badge bg-primary">' + d + '</span>'
                            : '<span class="badge bg-secondary">0</span>';
                    }
                },
                { data: 'sektor_sira_no' },
                {
                    data: 'Durum',
                    render: function(d, type) {
                        if (type !== 'display') return d;
                        return d == 1
                            ? '<span class="badge bg-success">Aktif</span>'
                            : '<span class="badge bg-danger">Pasif</span>';
                    }
                },
                {
                    data: null,
                    orderable: false,
                    searchable: false,
                    render: function(row) {
                        let b = '';
                        if (pagePermissions.can_edit) {
                            b += '<button class="btn btn-sm btn-warning" onclick="sektorDuzenle(' + row.sektor_id + ')" title="Düzenle"><i class="bi bi-pencil"></i></button> ';
                        }
                        if (pagePermissions.can_delete) {
                            b += '<button class="btn btn-sm btn-danger" onclick="sektorSil(' + row.sektor_id + ', ' + row.cari_sayisi + ')" title="Sil"><i class="bi bi-trash"></i></button>';
                        }
                        return b || '<span class="text-muted">-</span>';
                    }
                }
            ],
            order: [[5, 'asc'], [1, 'asc']]
        });

        // Özel filtre: durum + cari sayısı
        $.fn.dataTable.ext.search.push(function(settings, data, dataIndex) {
            if (settings.nTable.id !== 'sektorTable') return true;
            const row   = sektorTable.row(dataIndex).data();
            const durum = $('#filtreDurum').val();
            const cari  = $('#filtreCari').val();
            if (durum !== '' && durum !== null && String(row.Durum) !== durum) return false;
            if (cari === 'dolu' && Number(row.cari_sayisi) === 0) return false;
            if (cari === 'bos'  && Number(row.cari_sayisi) > 0)  return false;
            return true;
        });

        $('#filtreArama').on('keyup', function() { sektorTable.search(this.value).draw(); });
        $('#filtreDurum, #filtreCari').on('change', function() { sektorTable.draw(); });
    }

    function filtreTemizle() {
        $('#filtreArama').val('');
        $('#filtreDurum').val('').trigger('change');
        $('#filtreCari').val('').trigger('change');
        sektorTable.search('').draw();
    }

    function sektorModalAc() {
        document.getElementById('sektorForm').reset();
        document.getElementById('sektor_id').value = '';
        document.getElementById('sektorModalBaslik').textContent = 'Yeni Sektör';
        document.getElementById('Durum').checked = true;
        document.getElementById('sektor_renk').value = '#0d6efd';
        sektorModal.show();
    }

    function sektorDuzenle(id) {
        $.post('', { action: 'get', id: id }, function(r) {
            if (!r.success) { showToast(r.message, 'error'); return; }
            const d = r.data;
            document.getElementById('sektorModalBaslik').textContent = 'Sektör Düzenle';
            document.getElementById('sektor_id').value       = d.sektor_id;
            document.getElementById('sektor_ad').value       = d.sektor_ad || '';
            document.getElementById('sektor_kod').value      = d.sektor_kod || '';
            document.getElementById('sektor_aciklama').value = d.sektor_aciklama || '';
            document.getElementById('sektor_ikon').value     = d.sektor_ikon || '';
            document.getElementById('sektor_renk').value     = d.sektor_renk || '#0d6efd';
            document.getElementById('sektor_sira_no').value  = d.sektor_sira_no || 0;
            document.getElementById('Durum').checked         = d.Durum == 1;
            sektorModal.show();
        }, 'json');
    }

    function sektorSil(id, cariSayisi) {
        if (cariSayisi > 0) {
            Swal.fire('Silinemez', 'Bu sektöre bağlı ' + cariSayisi + ' cari var. Kullanımdan kaldırmak için pasife alın.', 'warning');
            return;
        }
        Swal.fire({
            title: 'Sektör silinsin mi?',
            text: 'Bu işlem geri alınamaz.',
            icon: 'warning',
            showCancelButton: true,
            confirmButtonText: 'Evet, sil',
            cancelButtonText: 'Vazgeç',
            confirmButtonColor: '#dc3545'
        }).then(res => {
            if (!res.isConfirmed) return;
            $.post('', { action: 'delete', id: id }, function(r) {
                showToast(r.message, r.success ? 'success' : 'error');
                if (r.success) { sektorTable.ajax.reload(null, false); istatistikYukle(); }
            }, 'json');
        });
    }

    $('#sektorForm').on('submit', function(e) {
        e.preventDefault();
        const fd = new FormData(this);
        fd.append('action', 'save');
        $.ajax({
            url: '', method: 'POST', data: fd, processData: false, contentType: false, dataType: 'json',
            success: function(r) {
                showToast(r.message, r.success ? 'success' : 'error');
                if (r.success) {
                    sektorModal.hide();
                    sektorTable.ajax.reload(null, false);
                    istatistikYukle();
                }
            },
            error: function() { showToast('Bir hata oluştu!', 'error'); }
        });
    });

    // ==========================================================
    // SEKME 2 - EXCEL EŞLEŞTİRME
    // ==========================================================
    function excelOku() {
        const input = document.getElementById('excelFile');
        if (!input.files.length) { showToast('Lütfen bir dosya seçin!', 'warning'); return; }

        const file = input.files[0];
        const ext  = file.name.split('.').pop().toLowerCase();
        if (['xlsx', 'xls', 'csv'].indexOf(ext) === -1) { showToast('Desteklenen formatlar: .xlsx, .xls, .csv', 'error'); return; }

        const reader = new FileReader();
        reader.onload = function(e) {
            try {
                const data     = new Uint8Array(e.target.result);
                const workbook = XLSX.read(data, { type: 'array', cellDates: false, cellText: true, raw: true });
                const sheet    = workbook.Sheets[workbook.SheetNames[0]];
                const json     = XLSX.utils.sheet_to_json(sheet, { header: 1, raw: true, defval: '' });

                if (json.length < 2) { showToast('Dosyada en az 1 başlık + 1 veri satırı olmalı!', 'error'); return; }

                excelBasliklar = json[0].map(h => (h || '').toString().trim());
                excelSatirlar  = json.slice(1).filter(r => r.some(c => c !== '' && c !== null && c !== undefined));

                if (excelSatirlar.length === 0) { showToast('Veri satırı bulunamadı!', 'error'); return; }

                eslestirmeKur();
                onizlemeTablosuKur();
                otomatikEslestir();

                $('#excelColCount').text(excelBasliklar.length + ' sütun');
                $('#excelRowCount').text(excelSatirlar.length + ' satır');
                $('#adim1Card').addClass('d-none');
                $('#adim1No').addClass('completed');
                $('#adim2Card').removeClass('d-none disabled-step');

                showToast(excelBasliklar.length + ' sütun ve ' + excelSatirlar.length + ' satır okundu.', 'success');
            } catch (err) {
                console.error(err);
                showToast('Dosya okunamadı: ' + err.message, 'error');
            }
        };
        reader.readAsArrayBuffer(file);
    }

    function eslestirmeKur() {
        let opts = '<option value="">-- Seçim Yapılmadı --</option>';
        excelBasliklar.forEach(function(h, i) {
            opts += '<option value="' + i + '">' + escapeHtml(h || ('Sütun ' + (i + 1))) + '</option>';
        });
        $('#mapAnahtar, #mapSektor').html(opts);
        $('#mapAnahtar, #mapSektor').off('change.prev').on('change.prev', sutunOnizlemeGuncelle);
    }

    function sutunOnizlemeGuncelle() {
        [['#mapAnahtar', '#prevAnahtar'], ['#mapSektor', '#prevSektor']].forEach(function(p) {
            const idx = $(p[0]).val();
            if (idx === '' || idx === null) { $(p[1]).html('-'); return; }
            const ornek = excelSatirlar.slice(0, 3)
                .map(r => (r[idx] === null || r[idx] === undefined ? '' : r[idx]).toString().trim())
                .filter(v => v !== '');
            $(p[1]).html(ornek.length
                ? '<span class="text-success">' + escapeHtml(ornek.join(' · ')) + '</span>'
                : '<span class="text-muted">boş</span>');
        });
    }

    function otomatikEslestir() {
        const anahtarTipi = $('#anahtarTipi').val();
        const kaliplar = {
            vergi_no: ['vergi no', 'vergino', 'vkn', 'vergi numarası', 'tckn', 'vergi/tc'],
            cari_adi: ['cari adı', 'cari adi', 'cari', 'unvan', 'ünvan', 'firma', 'müşteri', 'musteri'],
            sektor:   ['sektör', 'sektor', 'faaliyet', 'meslek', 'iş kolu', 'is kolu', 'branş', 'brans']
        };
        const bul = function(liste) {
            for (let i = 0; i < excelBasliklar.length; i++) {
                const h = excelBasliklar[i].toLocaleLowerCase('tr');
                if (liste.some(k => h.indexOf(k) !== -1)) return i;
            }
            return null;
        };
        const anahtarIdx = bul(kaliplar[anahtarTipi]);
        const sektorIdx  = bul(kaliplar.sektor);
        if (anahtarIdx !== null) $('#mapAnahtar').val(anahtarIdx);
        if (sektorIdx  !== null) $('#mapSektor').val(sektorIdx);
        $('#mapAnahtar, #mapSektor').trigger('change');
    }

    function onizlemeTablosuKur() {
        const thead = '<tr>' + excelBasliklar.map(h => '<th>' + escapeHtml(h) + '</th>').join('') + '</tr>';
        const tbody = excelSatirlar.slice(0, 5).map(function(r) {
            return '<tr>' + excelBasliklar.map(function(_, i) {
                return '<td>' + escapeHtml((r[i] === null || r[i] === undefined ? '' : r[i]).toString()) + '</td>';
            }).join('') + '</tr>';
        }).join('');
        $('#excelOnizlemeTablo thead').html(thead);
        $('#excelOnizlemeTablo tbody').html(tbody);
    }

    function satirlariTopla() {
        const aIdx = $('#mapAnahtar').val();
        const sIdx = $('#mapSektor').val();
        if (aIdx === '' || aIdx === null) { showToast('Anahtar sütununu seçin!', 'warning'); return null; }
        if (sIdx === '' || sIdx === null) { showToast('Sektör sütununu seçin!', 'warning'); return null; }

        return excelSatirlar.map(function(r, i) {
            return {
                satir:   i + 2,
                anahtar: (r[aIdx] === null || r[aIdx] === undefined ? '' : r[aIdx]).toString().trim(),
                sektor:  (r[sIdx] === null || r[sIdx] === undefined ? '' : r[sIdx]).toString().trim()
            };
        });
    }

    function onizlemeYap() {
        const satirlar = satirlariTopla();
        if (!satirlar) return;

        Swal.fire({ title: 'Kontrol ediliyor...', allowOutsideClick: false, didOpen: () => Swal.showLoading() });

        $.post('', {
            action: 'eslestir_onizleme',
            satirlar: JSON.stringify(satirlar),
            anahtar_tipi: $('#anahtarTipi').val(),
            yeni_sektor_olustur: $('#yeniSektorOlustur').is(':checked') ? '1' : '0'
        }, function(r) {
            Swal.close();
            if (!r.success) { showToast(r.message, 'error'); return; }
            sonucGoster(r, false);
        }, 'json').fail(function() { Swal.close(); showToast('Sunucu hatası!', 'error'); });
    }

    function eslestirUygula() {
        const satirlar = satirlariTopla();
        if (!satirlar) return;

        Swal.fire({
            title: 'Eşleştirme uygulansın mı?',
            html: 'Seçilen sektörler carilere <strong>kalıcı olarak</strong> atanacak.',
            icon: 'question',
            showCancelButton: true,
            confirmButtonText: 'Evet, uygula',
            cancelButtonText: 'Vazgeç'
        }).then(function(res) {
            if (!res.isConfirmed) return;

            Swal.fire({ title: 'Uygulanıyor...', allowOutsideClick: false, didOpen: () => Swal.showLoading() });

            $.post('', {
                action: 'eslestir_uygula',
                satirlar: JSON.stringify(satirlar),
                anahtar_tipi: $('#anahtarTipi').val(),
                yeni_sektor_olustur: $('#yeniSektorOlustur').is(':checked') ? '1' : '0'
            }, function(r) {
                Swal.close();
                if (!r.success) { showToast(r.message, 'error'); return; }
                sonucGoster(r, true);
                Swal.fire('Tamamlandı', r.message, 'success');
                sektorTable.ajax.reload(null, false);
                istatistikYukle();
            }, 'json').fail(function() { Swal.close(); showToast('Sunucu hatası!', 'error'); });
        });
    }

    const durumEtiket = {
        hazir:          { metin: 'Atanacak',       renk: 'bg-primary' },
        yeni_sektor:    { metin: 'Yeni sektör',    renk: 'bg-info' },
        degisiklik_yok: { metin: 'Değişiklik yok', renk: 'bg-secondary' },
        cari_yok:       { metin: 'Cari bulunamadı',renk: 'bg-danger' },
        sektor_yok:     { metin: 'Sektör tanımsız',renk: 'bg-warning text-dark' },
        mukerrer:       { metin: 'Mükerrer',       renk: 'bg-warning text-dark' },
        eksik:          { metin: 'Eksik veri',     renk: 'bg-danger' }
    };

    function sonucGoster(r, uygulandi) {
        sonSonuc = r.data;

        const o = r.ozet;
        const kutular = [
            ['Atanacak',         o.hazir,               'text-bg-primary'],
            ['Yeni Sektör',      o.yeni_sektor,         'text-bg-info'],
            ['Değişiklik Yok',   o.degisiklik_yok,      'text-bg-secondary'],
            ['Cari Yok',         o.cari_yok,            'text-bg-danger'],
            ['Sektör Tanımsız',  o.sektor_yok,          'text-bg-warning'],
            ['Mükerrer / Eksik', o.mukerrer + o.eksik,  'text-bg-dark']
        ];
        $('#ozetKutulari').html(kutular.map(function(k) {
            return '<div class="col-6 col-md-2"><div class="small-box ' + k[2] + '">' +
                   '<div class="inner p-2"><h4 class="mb-0">' + k[1] + '</h4>' +
                   '<p class="mb-0 small">' + k[0] + '</p></div></div></div>';
        }).join(''));

        if (sonucTable) { sonucTable.destroy(); $('#sonucTable tbody').empty(); }

        sonucTable = $('#sonucTable').DataTable({
            data: sonSonuc,
            scrollX: true,
            dom: 'lrtip',
            pageLength: 25,
            language: { url: '//cdn.datatables.net/plug-ins/1.13.7/i18n/tr.json' },
            columns: [
                { data: 'satir' },
                { data: 'anahtar', render: d => escapeHtml(d) },
                {
                    data: 'cari_adi',
                    render: function(d, type, row) {
                        if (type !== 'display') return d;
                        if (!d) return '-';
                        let html = escapeHtml(d);
                        if (row.cari_sayisi > 1) html += ' <span class="badge bg-warning text-dark">+' + (row.cari_sayisi - 1) + '</span>';
                        return html;
                    }
                },
                { data: 'mevcut_sektor', render: d => d ? escapeHtml(d) : '<span class="text-muted">-</span>' },
                { data: 'sektor_excel', render: d => escapeHtml(d) },
                {
                    data: 'durum',
                    render: function(d, type) {
                        if (type !== 'display') return d;
                        const e = durumEtiket[d] || { metin: d, renk: 'bg-secondary' };
                        return '<span class="badge ' + e.renk + '">' + e.metin + '</span>';
                    }
                },
                { data: 'mesaj', render: d => escapeHtml(d) }
            ],
            order: [[0, 'asc']]
        });

        $('#sonucFiltre').off('change.sonuc').on('change.sonuc', function() {
            sonucTable.column(5).search(this.value ? '^' + this.value + '$' : '', true, false).draw();
        }).val('').trigger('change.sonuc');

        $('#adim3Card').removeClass('d-none disabled-step');
        $('#adim2No').addClass('completed');
        if (uygulandi) {
            $('#adim3No').addClass('completed');
            $('#uygulaBtn').prop('disabled', true).html('<i class="bi bi-check2-all"></i> Uygulandı');
        } else {
            $('#uygulaBtn').prop('disabled', false).html('<i class="bi bi-check2-circle"></i> Eşleştirmeyi Uygula');
        }
        document.getElementById('adim3Card').scrollIntoView({ behavior: 'smooth' });
    }

    function excelSifirla() {
        excelBasliklar = [];
        excelSatirlar  = [];
        sonSonuc       = [];
        document.getElementById('excelFile').value = '';
        $('#adim1Card').removeClass('d-none');
        $('#adim2Card, #adim3Card').addClass('d-none disabled-step');
        $('#adim1No, #adim2No, #adim3No').removeClass('completed');
        $('#uygulaBtn').prop('disabled', false).html('<i class="bi bi-check2-circle"></i> Eşleştirmeyi Uygula');
        if (sonucTable) { sonucTable.destroy(); sonucTable = null; $('#sonucTable tbody').empty(); }
    }

    function ornekSablonIndir() {
        const bom = '﻿';
        const satirlar = [
            'Vergi No;Cari Adı;Sektör',
            '1234567890;ÖRNEK BERBER SALONU;BERBER',
            '9876543210;ÖRNEK MARKET;TEKEL/MARKET'
        ];
        const blob = new Blob([bom + satirlar.join('\r\n')], { type: 'text/csv;charset=utf-8;' });
        const a    = document.createElement('a');
        a.href     = URL.createObjectURL(blob);
        a.download = 'sektor-eslestirme-sablon.csv';
        a.click();
        URL.revokeObjectURL(a.href);
    }

    // ==========================================================
    $(document).ready(function() {
        sektorModal = new bootstrap.Modal(document.getElementById('sektorModal'));

        $('#filtreDurum, #filtreCari, #anahtarTipi, #mapAnahtar, #mapSektor, #sonucFiltre').select2({
            theme: 'bootstrap-5',
            width: '100%',
            language: { noResults: function() { return 'Sonuç bulunamadı'; } }
        });

        $('#anahtarTipi').on('change', function() {
            if (excelBasliklar.length) otomatikEslestir();
        });

        istatistikYukle();
        tabloBaslat();
    });
    </script>
</body>
</html>
