<?php
/**
 * Admin Panel - Adres Bölge Yönetimi
 *
 * Sezon bazlı yetki bölgeleri ve tutarları (Adres_Bolgeler). İlçe boş = ilin tamamı.
 * WhatsApp botu bölgeleri buradan okur. Tutarlar tabloda satır içi toplu girilebilir.
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

$pageTitle       = $pageInfo['sayfalar_sayfa_adi'] ?? 'Adres Bölge Yönetimi';
$pageDescription = $pageInfo['sayfalar_aciklama'] ?? '';
$menuAdi         = $pageInfo['menu_adi'] ?? null;

$siteAyarlari = $db->fetchOne("SELECT TOP 1 site_ayarlari_site_title FROM dbo.tanim_site_ayarlari ORDER BY site_ayarlari_id DESC");
$siteTitle    = $siteAyarlari['site_ayarlari_site_title'] ?? 'Örnek Yazılım Portal';

/**
 * Virgülle ayrılmış takma adları sadeleştirir (boş ve tekrar edenler atılır)
 */
function takmaAdlariDuzenle($metin) {
    $liste = [];
    foreach (explode(',', (string)$metin) as $ad) {
        $ad = trim(preg_replace('/\s+/u', ' ', $ad));
        if ($ad === '') continue;
        $liste[mb_strtolower($ad, 'UTF-8')] = $ad;
    }
    return $liste ? mb_substr(implode(',', $liste), 0, 300, 'UTF-8') : null;
}

/**
 * Tutar alanını doğrular: boş = NULL, aksi halde 0 veya pozitif sayı
 */
function tutarCoz($deger) {
    $deger = trim((string)$deger);
    if ($deger === '') return null;
    if (!is_numeric($deger) || (float)$deger < 0) throw new Exception('Geçersiz tutar: ' . $deger);
    return round((float)$deger, 2);
}

function sezonGecerliMi($db, $sezonId) {
    return $sezonId > 0 && (bool)$db->fetchOne("SELECT sezon_id FROM dbo.Sozlesme_Sezonlar WHERE sezon_id = ?", [$sezonId]);
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
                $sezonId = intval($_POST['sezon_id'] ?? 0);
                // Tutar şehir bazında tektir: ilçe kayıtlarındaki aynı tutar bir kez sayılır
                $row = $db->fetchOne("
                    WITH sehirler AS (
                        SELECT BolgeSehirId, COUNT(*) AS kayit, MAX(BolgeTutar) AS tutar
                        FROM dbo.Adres_Bolgeler
                        WHERE BolgeSezonId = ? AND Durum = 1
                        GROUP BY BolgeSehirId
                    )
                    SELECT
                        ISNULL(SUM(kayit), 0) AS toplam,
                        COUNT(*) AS il_sayisi,
                        SUM(CASE WHEN tutar IS NULL THEN 1 ELSE 0 END) AS tutar_eksik,
                        ISNULL(SUM(tutar), 0) AS toplam_tutar
                    FROM sehirler
                ", [$sezonId]);
                echo json_encode(['success' => true, 'data' => [
                    'toplam'       => (int)($row['toplam'] ?? 0),
                    'il_sayisi'    => (int)($row['il_sayisi'] ?? 0),
                    'tutar_eksik'  => (int)($row['tutar_eksik'] ?? 0),
                    'toplam_tutar' => (float)($row['toplam_tutar'] ?? 0)
                ]], JSON_UNESCAPED_UNICODE);
                break;

            // ------------------------------------------------ Liste
            case 'list':
                $sezonId = intval($_POST['sezon_id'] ?? 0);
                $rows = $db->fetchAll("
                    SELECT
                        b.BolgeId,
                        b.BolgeSehirId,
                        s.SehirAdi,
                        s.PlakaNo,
                        b.BolgeIlceId,
                        i.IlceAdi,
                        b.BolgeTutar,
                        b.BolgeTakmaAdlar,
                        b.BolgeAciklama,
                        b.Durum,
                        CONVERT(VARCHAR(16), ISNULL(b.GuncellemeTarihi, b.OlusturmaTarihi), 120) AS islem_tarihi,
                        islemci.kullanici_ad + ' ' + islemci.kullanici_soyad AS islem_yapan
                    FROM dbo.Adres_Bolgeler b
                    INNER JOIN dbo.Adres_Sehirler s ON s.SehirId = b.BolgeSehirId
                    LEFT JOIN dbo.Adres_Ilceler i   ON i.ilceId  = b.BolgeIlceId
                    LEFT JOIN kullanicilar islemci  ON islemci.kullanici_id = ISNULL(b.GuncelleyenKullanici, b.OlusturanKullanici)
                    WHERE b.BolgeSezonId = ?
                    ORDER BY s.SehirAdi, i.IlceAdi
                ", [$sezonId]);
                echo json_encode(['success' => true, 'data' => $rows], JSON_UNESCAPED_UNICODE);
                break;

            // ------------------------------------------------ Şehrin ilçeleri
            case 'ilceler':
                $sehirId = intval($_POST['sehir_id'] ?? 0);
                $rows = $db->fetchAll("SELECT ilceId, IlceAdi FROM dbo.Adres_Ilceler WHERE SehirId = ? ORDER BY IlceAdi", [$sehirId]);
                echo json_encode(['success' => true, 'data' => $rows], JSON_UNESCAPED_UNICODE);
                break;

            // ------------------------------------------------ Tek kayıt
            case 'get':
                $id  = intval($_POST['id'] ?? 0);
                $row = $db->fetchOne("SELECT * FROM dbo.Adres_Bolgeler WHERE BolgeId = ?", [$id]);
                if (!$row) throw new Exception('Bölge kaydı bulunamadı!');
                echo json_encode(['success' => true, 'data' => $row], JSON_UNESCAPED_UNICODE);
                break;

            // ------------------------------------------------ Kaydet
            case 'save':
                $id = intval($_POST['id'] ?? 0);
                if ($id > 0 && !$pagePermissions['can_edit']) throw new Exception('Düzenleme yetkiniz yok!');
                if ($id == 0 && !$pagePermissions['can_add'])  throw new Exception('Ekleme yetkiniz yok!');

                $sezonId  = intval($_POST['BolgeSezonId'] ?? 0);
                $sehirId  = intval($_POST['BolgeSehirId'] ?? 0);
                $ilceId   = intval($_POST['BolgeIlceId'] ?? 0) ?: null;
                $tutar    = tutarCoz($_POST['BolgeTutar'] ?? '');
                $takma    =takmaAdlariDuzenle($_POST['BolgeTakmaAdlar'] ?? '');
                $aciklama = mb_substr(trim($_POST['BolgeAciklama'] ?? ''), 0, 200, 'UTF-8');
                $durum    = isset($_POST['Durum']) ? 1 : 0;

                if (!sezonGecerliMi($db, $sezonId)) throw new Exception('Sezon seçin!');
                if (!$db->fetchOne("SELECT SehirId FROM dbo.Adres_Sehirler WHERE SehirId = ?", [$sehirId])) throw new Exception('Şehir seçin!');
                if ($ilceId && !$db->fetchOne("SELECT ilceId FROM dbo.Adres_Ilceler WHERE ilceId = ? AND SehirId = ?", [$ilceId, $sehirId])) {
                    throw new Exception('Seçilen ilçe bu şehre ait değil!');
                }

                // Aynı sezon + şehir için diğer kayıtlar
                $digerler = $db->fetchAll("
                    SELECT BolgeId, BolgeIlceId FROM dbo.Adres_Bolgeler
                    WHERE BolgeSezonId = ? AND BolgeSehirId = ? AND BolgeId <> ?
                ", [$sezonId, $sehirId, $id]);

                foreach ($digerler as $d) {
                    if ($d['BolgeIlceId'] === null && $ilceId === null) throw new Exception('Bu şehir bu sezon için zaten "Tüm il" olarak kayıtlı!');
                    if ($d['BolgeIlceId'] === null)                     throw new Exception('Bu şehir bu sezon için "Tüm il" olarak kayıtlı, ayrıca ilçe eklemeye gerek yok.');
                    if ($ilceId === null)                               throw new Exception('Bu şehrin bu sezonda ilçe kayıtları var. "Tüm il" yapmak için önce ilçe kayıtlarını silin.');
                    if ((int)$d['BolgeIlceId'] === $ilceId)             throw new Exception('Bu ilçe bu sezon için zaten kayıtlı!');
                }

                // Yeni ilçe kaydı tutarsız girilirse şehrin mevcut tutarını devralır
                if ($id == 0 && $tutar === null && $digerler) {
                    $mevcutTutar = $db->fetchOne("
                        SELECT MAX(BolgeTutar) AS tutar FROM dbo.Adres_Bolgeler
                        WHERE BolgeSezonId = ? AND BolgeSehirId = ?
                    ", [$sezonId, $sehirId])['tutar'] ?? null;
                    $tutar = $mevcutTutar !== null ? (float)$mevcutTutar : null;
                }

                if ($id > 0) {
                    $ok = $db->execute("
                        UPDATE dbo.Adres_Bolgeler SET
                            BolgeSezonId = ?, BolgeSehirId = ?, BolgeIlceId = ?, BolgeTutar = ?,
                            BolgeTakmaAdlar = ?, BolgeAciklama = ?, Durum = ?,
                            GuncelleyenKullanici = ?, GuncellemeTarihi = GETDATE()
                        WHERE BolgeId = ?
                    ", [$sezonId, $sehirId, $ilceId, $tutar, $takma, $aciklama ?: null, $durum, $user['kullanici_id'], $id]);
                    if (!$ok) throw new Exception('Bölge güncellenemedi!');
                    echo json_encode(['success' => true, 'message' => 'Bölge güncellendi.'], JSON_UNESCAPED_UNICODE);
                } else {
                    $ok = $db->execute("
                        INSERT INTO dbo.Adres_Bolgeler
                            (BolgeSezonId, BolgeSehirId, BolgeIlceId, BolgeTutar, BolgeTakmaAdlar, BolgeAciklama, Durum, OlusturanKullanici, OlusturmaTarihi)
                        VALUES (?, ?, ?, ?, ?, ?, ?, ?, GETDATE())
                    ", [$sezonId, $sehirId, $ilceId, $tutar, $takma, $aciklama ?: null, $durum, $user['kullanici_id']]);
                    if (!$ok) throw new Exception('Bölge eklenemedi!');
                    echo json_encode(['success' => true, 'message' => 'Bölge eklendi.'], JSON_UNESCAPED_UNICODE);
                }

                // Şehir tutarı tektir: aynı sezon + şehrin diğer ilçe kayıtlarına da yaz
                $db->execute("
                    UPDATE dbo.Adres_Bolgeler SET
                        BolgeTutar = ?, GuncelleyenKullanici = ?, GuncellemeTarihi = GETDATE()
                    WHERE BolgeSezonId = ? AND BolgeSehirId = ?
                      AND ISNULL(BolgeTutar, -1) <> ISNULL(?, -1)
                ", [$tutar, $user['kullanici_id'], $sezonId, $sehirId, $tutar]);
                break;

            // ------------------------------------------------ Satır içi toplu tutar kaydı
            case 'tutar_kaydet':
                if (!$pagePermissions['can_edit']) throw new Exception('Düzenleme yetkiniz yok!');
                $sezonId  = intval($_POST['sezon_id'] ?? 0);
                $satirlar = json_decode($_POST['satirlar'] ?? '[]', true);
                if (!is_array($satirlar) || count($satirlar) === 0) throw new Exception('Kaydedilecek değişiklik yok!');

                $guncellenen = 0;
                $hatalar     = [];
                foreach ($satirlar as $s) {
                    $bolgeId = intval($s['bolge_id'] ?? 0);
                    try {
                        $tutar = tutarCoz($s['tutar'] ?? '');
                    } catch (Exception $e) {
                        $hatalar[] = $e->getMessage();
                        continue;
                    }
                    // Şehir tutarı tektir: satırın şehrine ait tüm kayıtlar güncellenir.
                    // Sezon şartı: başka sezonun kaydı bu istekle değişmesin
                    $ok = $db->execute("
                        UPDATE b SET
                            b.BolgeTutar = ?, b.GuncelleyenKullanici = ?, b.GuncellemeTarihi = GETDATE()
                        FROM dbo.Adres_Bolgeler b
                        INNER JOIN dbo.Adres_Bolgeler kaynak
                                ON kaynak.BolgeId = ? AND kaynak.BolgeSezonId = b.BolgeSezonId AND kaynak.BolgeSehirId = b.BolgeSehirId
                        WHERE b.BolgeSezonId = ?
                    ", [$tutar, $user['kullanici_id'], $bolgeId, $sezonId]);
                    if ($ok) $guncellenen++; else $hatalar[] = 'Bölge #' . $bolgeId . ' güncellenemedi.';
                }

                $mesaj = $guncellenen . ' şehir tutarı kaydedildi.';
                echo json_encode([
                    'success' => count($hatalar) === 0,
                    'message' => count($hatalar) ? $mesaj . ' Hatalar: ' . implode(' ', $hatalar) : $mesaj
                ], JSON_UNESCAPED_UNICODE);
                break;

            // ------------------------------------------------ Sil
            case 'delete':
                if (!$pagePermissions['can_delete']) throw new Exception('Silme yetkiniz yok!');
                $id = intval($_POST['id'] ?? 0);
                if (!$db->fetchOne("SELECT BolgeId FROM dbo.Adres_Bolgeler WHERE BolgeId = ?", [$id])) throw new Exception('Bölge kaydı bulunamadı!');

                if (!$db->execute("DELETE FROM dbo.Adres_Bolgeler WHERE BolgeId = ?", [$id])) throw new Exception('Bölge silinemedi!');
                echo json_encode(['success' => true, 'message' => 'Bölge silindi.'], JSON_UNESCAPED_UNICODE);
                break;

            // ------------------------------------------------ Sezon kopyala
            case 'kopyala':
                if (!$pagePermissions['can_add']) throw new Exception('Ekleme yetkiniz yok!');
                $kaynak = intval($_POST['kaynak_sezon_id'] ?? 0);
                $hedef  = intval($_POST['hedef_sezon_id'] ?? 0);
                if (!sezonGecerliMi($db, $kaynak) || !sezonGecerliMi($db, $hedef)) throw new Exception('Kaynak ve hedef sezon seçin!');
                if ($kaynak === $hedef) throw new Exception('Kaynak ve hedef sezon aynı olamaz!');

                $once = (int)($db->fetchOne("SELECT COUNT(*) AS sayi FROM dbo.Adres_Bolgeler WHERE BolgeSezonId = ?", [$hedef])['sayi'] ?? 0);

                // Hedefte aynı şehir için kayıt varsa o şehir hiç kopyalanmaz (tüm il / ilçe çakışmasını önler)
                $ok = $db->execute("
                    INSERT INTO dbo.Adres_Bolgeler
                        (BolgeSezonId, BolgeSehirId, BolgeIlceId, BolgeTutar, BolgeTakmaAdlar, BolgeAciklama, Durum, OlusturanKullanici, OlusturmaTarihi)
                    SELECT ?, k.BolgeSehirId, k.BolgeIlceId, k.BolgeTutar, k.BolgeTakmaAdlar, k.BolgeAciklama, 1, ?, GETDATE()
                    FROM dbo.Adres_Bolgeler k
                    WHERE k.BolgeSezonId = ? AND k.Durum = 1
                      AND NOT EXISTS (
                          SELECT 1 FROM dbo.Adres_Bolgeler h
                          WHERE h.BolgeSezonId = ? AND h.BolgeSehirId = k.BolgeSehirId
                      )
                ", [$hedef, $user['kullanici_id'], $kaynak, $hedef]);
                if (!$ok) throw new Exception('Kopyalama başarısız!');

                $sonra = (int)($db->fetchOne("SELECT COUNT(*) AS sayi FROM dbo.Adres_Bolgeler WHERE BolgeSezonId = ?", [$hedef])['sayi'] ?? 0);
                echo json_encode(['success' => true, 'message' => ($sonra - $once) . ' bölge kaydı kopyalandı. Hedef sezonda zaten kaydı olan şehirler atlandı.'], JSON_UNESCAPED_UNICODE);
                break;

            default:
                throw new Exception('Geçersiz işlem!');
        }
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'message' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
    }
    exit;
}

// Sezon listesi ve seçili sezon (?sezon_id > varsayılan > ilk aktif)
$sezonlar = $db->fetchAll("SELECT sezon_id, sezon_ad, sezon_durum, sezon_varsayilan FROM dbo.Sozlesme_Sezonlar ORDER BY sezon_ad DESC");
$seciliSezonId = isset($_GET['sezon_id']) ? (int)$_GET['sezon_id'] : 0;
if (!in_array($seciliSezonId, array_map('intval', array_column($sezonlar, 'sezon_id')), true)) {
    $seciliSezonId = 0;
    foreach ($sezonlar as $s) { if ($s['sezon_varsayilan']) { $seciliSezonId = (int)$s['sezon_id']; break; } }
    if (!$seciliSezonId) {
        foreach ($sezonlar as $s) { if ($s['sezon_durum']) { $seciliSezonId = (int)$s['sezon_id']; break; } }
    }
}

$sehirler = $db->fetchAll("SELECT SehirId, SehirAdi, PlakaNo FROM dbo.Adres_Sehirler ORDER BY PlakaNo, SehirAdi");

function sezonSecenekleri($sezonlar, $secili) {
    $html = '';
    foreach ($sezonlar as $s) {
        $html .= '<option value="' . (int)$s['sezon_id'] . '"' . ((int)$s['sezon_id'] === $secili ? ' selected' : '') . '>'
               . htmlspecialchars($s['sezon_ad']) . ($s['sezon_durum'] ? '' : ' (Pasif)') . '</option>';
    }
    return $html;
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
    <style>
        .tutar-input { min-width: 130px; max-width: 170px; text-align: right; }
        tr.satir-degisti td { background-color: rgba(255, 193, 7, .15) !important; }
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
                                <span class="info-box-icon text-bg-primary shadow-sm"><i class="bi bi-map"></i></span>
                                <div class="info-box-content">
                                    <span class="info-box-text">Aktif Bölge Kaydı</span>
                                    <span class="info-box-number" id="stat-toplam">0</span>
                                </div>
                            </div>
                        </div>
                        <div class="col-12 col-sm-6 col-md-3">
                            <div class="info-box">
                                <span class="info-box-icon text-bg-success shadow-sm"><i class="bi bi-geo-alt"></i></span>
                                <div class="info-box-content">
                                    <span class="info-box-text">Şehir</span>
                                    <span class="info-box-number" id="stat-il">0</span>
                                </div>
                            </div>
                        </div>
                        <div class="col-12 col-sm-6 col-md-3">
                            <div class="info-box">
                                <span class="info-box-icon text-bg-warning shadow-sm"><i class="bi bi-exclamation-circle"></i></span>
                                <div class="info-box-content">
                                    <span class="info-box-text">Tutarı Eksik</span>
                                    <span class="info-box-number" id="stat-eksik">0</span>
                                </div>
                            </div>
                        </div>
                        <div class="col-12 col-sm-6 col-md-3">
                            <div class="info-box">
                                <span class="info-box-icon text-bg-info shadow-sm"><i class="bi bi-cash-stack"></i></span>
                                <div class="info-box-content">
                                    <span class="info-box-text">Toplam Tutar</span>
                                    <span class="info-box-number" id="stat-tutar">0,00</span>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Filtre -->
                    <div class="card mb-3">
                        <div class="card-header" role="button" data-bs-toggle="collapse" data-bs-target="#filtrePanel">
                            <h3 class="card-title"><i class="bi bi-funnel"></i> Filtre</h3>
                            <div class="card-tools"><i class="bi bi-chevron-down"></i></div>
                        </div>
                        <div class="collapse show" id="filtrePanel">
                            <div class="card-body">
                                <div class="row g-3">
                                    <div class="col-md-2">
                                        <label class="form-label">Sezon</label>
                                        <select class="form-select" id="filtreSezon"><?= sezonSecenekleri($sezonlar, $seciliSezonId) ?></select>
                                    </div>
                                    <div class="col-md-2">
                                        <label class="form-label">Ara</label>
                                        <input type="text" class="form-control" id="filtreArama" placeholder="Şehir, ilçe, takma ad...">
                                    </div>
                                    <div class="col-md-2">
                                        <label class="form-label">Şehir</label>
                                        <select class="form-select" id="filtreSehir"><option value="">Tümü</option></select>
                                    </div>
                                    <div class="col-md-1">
                                        <label class="form-label">Tutar</label>
                                        <select class="form-select" id="filtreTutar">
                                            <option value="">Tümü</option>
                                            <option value="dolu">Girilmiş</option>
                                            <option value="bos">Eksik</option>
                                        </select>
                                    </div>
                                    <div class="col-md-1">
                                        <label class="form-label">Kapsam</label>
                                        <select class="form-select" id="filtreKapsam">
                                            <option value="">Tümü</option>
                                            <option value="il">Tüm il</option>
                                            <option value="ilce">İlçe</option>
                                        </select>
                                    </div>
                                    <div class="col-md-1">
                                        <label class="form-label">Durum</label>
                                        <select class="form-select" id="filtreDurum">
                                            <option value="">Tümü</option>
                                            <option value="1">Aktif</option>
                                            <option value="0">Pasif</option>
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
                            <h3 class="card-title">Bölgeler</h3>
                            <div class="card-tools">
                                <?php if ($pagePermissions['can_edit']): ?>
                                <span class="badge bg-warning text-dark d-none me-1" id="degisiklikRozet">0 değişiklik</span>
                                <button type="button" class="btn btn-secondary btn-sm" id="geriAlBtn" onclick="yenile()" disabled>
                                    <i class="bi bi-arrow-counterclockwise"></i> Geri Al
                                </button>
                                <button type="button" class="btn btn-success btn-sm" id="tutarKaydetBtn" onclick="tutarlariKaydet()" disabled>
                                    <i class="bi bi-save"></i> Tutarları Kaydet
                                </button>
                                <?php endif; ?>
                                <?php if ($pagePermissions['can_add']): ?>
                                <button type="button" class="btn btn-outline-primary btn-sm" onclick="kopyalaModalAc()">
                                    <i class="bi bi-copy"></i> Sezondan Kopyala
                                </button>
                                <button type="button" class="btn btn-primary btn-sm" onclick="bolgeModalAc()">
                                    <i class="bi bi-plus-circle"></i> Yeni Bölge
                                </button>
                                <?php endif; ?>
                            </div>
                        </div>
                        <div class="card-body">
                            <table id="bolgeTable" class="table table-bordered table-striped w-100">
                                <thead>
                                    <tr>
                                        <th>Şehir</th>
                                        <th>Plaka</th>
                                        <th>İlçe</th>
                                        <th>Tutar (₺)</th>
                                        <th>Takma Adlar</th>
                                        <th>Açıklama</th>
                                        <th>Durum</th>
                                        <th>Son İşlem</th>
                                        <th>İşlemler</th>
                                    </tr>
                                </thead>
                                <tbody></tbody>
                            </table>
                        </div>
                    </div>

                </div>
            </div>
        </main>

        <?php include __DIR__ . '/../includes/footer.php'; ?>
    </div>

    <!-- Bölge Modal -->
    <div class="modal fade" id="bolgeModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="bolgeModalBaslik">Yeni Bölge</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <form id="bolgeForm">
                    <div class="modal-body">
                        <input type="hidden" name="id" id="BolgeId">

                        <div class="mb-3">
                            <label for="BolgeSezonId" class="form-label">Sezon <span class="text-danger">*</span></label>
                            <select class="form-select" id="BolgeSezonId" name="BolgeSezonId" required><?= sezonSecenekleri($sezonlar, $seciliSezonId) ?></select>
                        </div>

                        <div class="mb-3">
                            <label for="BolgeSehirId" class="form-label">Şehir <span class="text-danger">*</span></label>
                            <select class="form-select" id="BolgeSehirId" name="BolgeSehirId" required>
                                <option value="">Seçin</option>
                                <?php foreach ($sehirler as $s): ?>
                                <option value="<?= (int)$s['SehirId'] ?>"><?= htmlspecialchars($s['SehirAdi']) ?> (<?= (int)$s['PlakaNo'] ?>)</option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="mb-3">
                            <label for="BolgeIlceId" class="form-label">İlçe</label>
                            <select class="form-select" id="BolgeIlceId" name="BolgeIlceId">
                                <option value="">Tüm il</option>
                            </select>
                            <small class="text-muted">Boş bırakılırsa ilin tamamı bölgeye dahil olur.</small>
                        </div>

                        <div class="mb-3">
                            <label for="BolgeTutar" class="form-label">Tutar (₺)</label>
                            <input type="number" step="any" min="0" class="form-control" id="BolgeTutar" name="BolgeTutar">
                            <small class="text-muted">Şehir tutarıdır; aynı sezonda şehrin tüm ilçe kayıtlarına birlikte yazılır, raporda bir kez sayılır.</small>
                        </div>

                        <div class="mb-3">
                            <label for="BolgeTakmaAdlar" class="form-label">Takma Adlar</label>
                            <input type="text" class="form-control" id="BolgeTakmaAdlar" name="BolgeTakmaAdlar" maxlength="300" placeholder="örn: Seyrek, Ulukent">
                            <small class="text-muted">Virgülle ayırın. WhatsApp botu müşterinin yazdığı mahalle/belde adını bu listeden ilçeye eşler. Yalnızca ilçe kayıtlarında kullanılır.</small>
                        </div>

                        <div class="mb-3">
                            <label for="BolgeAciklama" class="form-label">Açıklama</label>
                            <input type="text" class="form-control" id="BolgeAciklama" name="BolgeAciklama" maxlength="200">
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

    <!-- Kopyala Modal -->
    <div class="modal fade" id="kopyalaModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">Sezondan Kopyala</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="mb-3">
                        <label for="kaynakSezon" class="form-label">Kaynak Sezon</label>
                        <select class="form-select" id="kaynakSezon"><?= sezonSecenekleri($sezonlar, $seciliSezonId) ?></select>
                    </div>
                    <div class="mb-3">
                        <label for="hedefSezon" class="form-label">Hedef Sezon</label>
                        <select class="form-select" id="hedefSezon"><option value=""></option><?= sezonSecenekleri($sezonlar, 0) ?></select>
                    </div>
                    <div class="alert alert-info mb-0">
                        <i class="bi bi-info-circle"></i>
                        Kaynak sezonun aktif bölge kayıtları tutarlarıyla birlikte hedef sezona eklenir. Hedef sezonda zaten kaydı olan şehirler atlanır.
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal"><i class="bi bi-x-circle"></i> İptal</button>
                    <button type="button" class="btn btn-primary" onclick="sezonKopyala()"><i class="bi bi-copy"></i> Kopyala</button>
                </div>
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

    let bolgeTable   = null;
    let bolgeModal   = null;
    let kopyalaModal = null;
    let sezonId      = <?= (int)$seciliSezonId ?>;

    const select2Ayar = {
        theme: 'bootstrap-5',
        width: '100%',
        language: { noResults: function() { return 'Sonuç bulunamadı'; } }
    };

    const paraFormat = new Intl.NumberFormat('tr-TR', { maximumFractionDigits: 0 });

    function escapeHtml(s) {
        return String(s === null || s === undefined ? '' : s)
            .replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
    }

    // Kuruş sıfırsa gösterilmez: "60000000.00" -> "60000000"
    function tutarNormalize(v) {
        if (v === null || v === undefined || v === '') return '';
        return String(Number(Number(v).toFixed(2)));
    }

    function istatistikYukle() {
        $.post('', { action: 'stats', sezon_id: sezonId }, function(r) {
            if (!r.success) return;
            $('#stat-toplam').text(r.data.toplam);
            $('#stat-il').text(r.data.il_sayisi);
            $('#stat-eksik').text(r.data.tutar_eksik);
            $('#stat-tutar').text(paraFormat.format(r.data.toplam_tutar));
        }, 'json');
    }

    // Tüm sayfalardaki satırlardan tutarı değişenleri toplar (şehir başına tek kayıt;
    // sunucu şehrin tüm ilçe kayıtlarını birlikte günceller)
    function degisenTutarlar() {
        const liste   = [];
        const sehirler = {};
        if (!bolgeTable) return liste;
        $(bolgeTable.rows().nodes()).find('.js-tutar').each(function() {
            const $t    = $(this);
            const tutar = $t.val() === '' ? '' : tutarNormalize($t.val());
            const degisti = tutar !== $t.attr('data-ilk');
            $t.closest('tr').toggleClass('satir-degisti', degisti);
            if (degisti && !sehirler[$t.attr('data-sehir')]) {
                sehirler[$t.attr('data-sehir')] = true;
                liste.push({ bolge_id: $t.attr('data-bolge'), tutar: tutar });
            }
        });
        return liste;
    }

    // Bir ilçenin tutarı yazılınca aynı şehrin diğer ilçelerine de aynısı yazılır
    function sehirTutariniEsle() {
        const $t = $(this);
        $(bolgeTable.rows().nodes()).find('.js-tutar[data-sehir="' + $t.attr('data-sehir') + '"]').not($t).val($t.val());
        degisiklikSay();
    }

    function degisiklikSay() {
        const adet = degisenTutarlar().length;
        $('#degisiklikRozet').text(adet + ' değişiklik').toggleClass('d-none', adet === 0);
        $('#tutarKaydetBtn, #geriAlBtn').prop('disabled', adet === 0);
    }

    function tutarlariKaydet() {
        const satirlar = degisenTutarlar();
        if (!satirlar.length) { showToast('Kaydedilecek değişiklik yok.', 'info'); return; }
        if (satirlar.some(s => s.tutar !== '' && (isNaN(Number(s.tutar)) || Number(s.tutar) < 0))) {
            showToast('Tutar negatif veya geçersiz olamaz!', 'warning');
            return;
        }
        $('#tutarKaydetBtn').prop('disabled', true);
        $.post('', { action: 'tutar_kaydet', sezon_id: sezonId, satirlar: JSON.stringify(satirlar) }, function(r) {
            showToast(r.message, r.success ? 'success' : 'error');
            yenile();
        }, 'json').fail(function() {
            showToast('Sunucu hatası!', 'error');
            degisiklikSay();
        });
    }

    // Kaydedilmemiş tutar varsa işlemden önce onay ister
    function degisiklikVarsaSor(devam, vazgec) {
        if (degisenTutarlar().length === 0) { devam(); return; }
        Swal.fire({
            title: 'Kaydedilmemiş tutarlar var',
            text: 'Devam ederseniz değişiklikler kaybolacak.',
            icon: 'warning',
            showCancelButton: true,
            confirmButtonText: 'Devam et',
            cancelButtonText: 'Vazgeç'
        }).then(function(res) {
            if (res.isConfirmed) devam(); else if (vazgec) vazgec();
        });
    }

    // Şehir filtresi tablodaki şehirlerden oluşur
    function sehirFiltresiGuncelle() {
        const secili = $('#filtreSehir').val();
        const sehirler = {};
        bolgeTable.rows().data().each(function(r) { sehirler[r.BolgeSehirId] = r.SehirAdi; });
        let html = '<option value="">Tümü</option>';
        Object.keys(sehirler).sort((a, b) => sehirler[a].localeCompare(sehirler[b], 'tr')).forEach(function(id) {
            html += '<option value="' + id + '">' + escapeHtml(sehirler[id]) + '</option>';
        });
        $('#filtreSehir').html(html).val(sehirler[secili] ? secili : '').trigger('change.select2');
    }

    function tabloBaslat() {
        bolgeTable = $('#bolgeTable').DataTable({
            processing: true,
            scrollX: true,
            dom: 'lrtip',
            pageLength: 50,
            language: { url: '//cdn.datatables.net/plug-ins/1.13.7/i18n/tr.json' },
            ajax: {
                url: '',
                type: 'POST',
                data: function(d) { d.action = 'list'; d.sezon_id = sezonId; },
                dataSrc: function(json) {
                    if (json.success) return json.data;
                    showToast(json.message || 'Veri yüklenemedi!', 'error');
                    return [];
                }
            },
            columns: [
                { data: 'SehirAdi', render: d => escapeHtml(d) },
                { data: 'PlakaNo' },
                {
                    data: 'IlceAdi',
                    render: function(d, type) {
                        if (type !== 'display') return d || 'Tüm il';
                        return d ? escapeHtml(d) : '<span class="badge bg-primary">Tüm il</span>';
                    }
                },
                {
                    data: 'BolgeTutar',
                    render: function(d, type, row) {
                        if (type !== 'display') return d === null ? -1 : Number(d);
                        const deger = tutarNormalize(d);
                        if (!pagePermissions.can_edit) return deger === '' ? '<span class="text-muted">-</span>' : paraFormat.format(d);
                        return '<input type="number" step="any" min="0" class="form-control form-control-sm tutar-input js-tutar"' +
                               ' data-bolge="' + row.BolgeId + '" data-sehir="' + row.BolgeSehirId + '" data-ilk="' + deger + '" value="' + deger + '">';
                    }
                },
                {
                    data: 'BolgeTakmaAdlar',
                    render: function(d, type) {
                        if (type !== 'display') return d || '';
                        if (!d) return '<span class="text-muted">-</span>';
                        return d.split(',').map(t => '<span class="badge bg-secondary me-1">' + escapeHtml(t.trim()) + '</span>').join('');
                    }
                },
                { data: 'BolgeAciklama', render: d => d ? escapeHtml(d) : '-' },
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
                    data: 'islem_tarihi',
                    render: function(d, type, row) {
                        if (type !== 'display') return d || '';
                        if (!d) return '<span class="text-muted">-</span>';
                        return escapeHtml(d) + '<br><small class="text-muted">' + escapeHtml(row.islem_yapan || '') + '</small>';
                    }
                },
                {
                    data: null,
                    orderable: false,
                    searchable: false,
                    render: function(row) {
                        let b = '';
                        if (pagePermissions.can_edit) {
                            b += '<button class="btn btn-sm btn-warning" onclick="bolgeDuzenle(' + row.BolgeId + ')" title="Düzenle"><i class="bi bi-pencil"></i></button> ';
                        }
                        if (pagePermissions.can_delete) {
                            b += '<button class="btn btn-sm btn-danger" onclick="bolgeSil(' + row.BolgeId + ')" title="Sil"><i class="bi bi-trash"></i></button>';
                        }
                        return b || '<span class="text-muted">-</span>';
                    }
                }
            ],
            order: [[0, 'asc'], [2, 'asc']],
            drawCallback: degisiklikSay
        });

        $('#bolgeTable').on('input change', '.js-tutar', sehirTutariniEsle);

        bolgeTable.on('xhr.dt', function() { setTimeout(sehirFiltresiGuncelle, 0); });

        // Özel filtre: şehir + kapsam + durum
        $.fn.dataTable.ext.search.push(function(settings, data, dataIndex) {
            if (settings.nTable.id !== 'bolgeTable') return true;
            const row    = bolgeTable.row(dataIndex).data();
            const sehir  = $('#filtreSehir').val();
            const kapsam = $('#filtreKapsam').val();
            const durum  = $('#filtreDurum').val();
            const tutar  = $('#filtreTutar').val();
            if (sehir && String(row.BolgeSehirId) !== sehir) return false;
            if (tutar === 'dolu' && row.BolgeTutar === null) return false;
            if (tutar === 'bos'  && row.BolgeTutar !== null) return false;
            if (kapsam === 'il'   && row.BolgeIlceId !== null) return false;
            if (kapsam === 'ilce' && row.BolgeIlceId === null) return false;
            if (durum !== '' && durum !== null && String(row.Durum) !== durum) return false;
            return true;
        });

        $('#filtreArama').on('keyup', function() { bolgeTable.search(this.value).draw(); });
        $('#filtreSehir, #filtreKapsam, #filtreDurum, #filtreTutar').on('change', function() { bolgeTable.draw(); });
    }

    function yenile() {
        bolgeTable.ajax.reload(null, false);
        istatistikYukle();
    }

    function sezonDegistir() {
        const yeni = parseInt($('#filtreSezon').val(), 10);
        if (!yeni || yeni === sezonId) return;
        degisiklikVarsaSor(function() {
            sezonId = yeni;
            const url = new URL(window.location.href);
            url.searchParams.set('sezon_id', sezonId);
            history.replaceState(null, '', url);
            bolgeTable.ajax.reload();
            istatistikYukle();
        }, function() {
            $('#filtreSezon').val(String(sezonId)).trigger('change.select2');
        });
    }

    function filtreTemizle() {
        $('#filtreArama').val('');
        $('#filtreSehir, #filtreKapsam, #filtreDurum, #filtreTutar').val('').trigger('change');
        bolgeTable.search('').draw();
    }

    // İlçe listesini yükler, seciliIlce verilirse seçer
    function ilceleriYukle(sehirId, seciliIlce) {
        const $ilce = $('#BolgeIlceId');
        $ilce.html('<option value="">Tüm il</option>').trigger('change');
        if (!sehirId) return;
        $.post('', { action: 'ilceler', sehir_id: sehirId }, function(r) {
            if (!r.success) { showToast(r.message, 'error'); return; }
            let html = '<option value="">Tüm il</option>';
            r.data.forEach(i => html += '<option value="' + i.ilceId + '">' + escapeHtml(i.IlceAdi) + '</option>');
            $ilce.html(html).val(seciliIlce ? String(seciliIlce) : '').trigger('change');
        }, 'json');
    }

    function bolgeModalAc() {
        document.getElementById('bolgeForm').reset();
        $('#BolgeId').val('');
        $('#bolgeModalBaslik').text('Yeni Bölge');
        $('#BolgeSezonId').val(String(sezonId)).trigger('change');
        $('#BolgeSehirId').val('').trigger('change.select2');
        ilceleriYukle(null);
        $('#Durum').prop('checked', true);
        bolgeModal.show();
    }

    function bolgeDuzenle(id) {
        $.post('', { action: 'get', id: id }, function(r) {
            if (!r.success) { showToast(r.message, 'error'); return; }
            const d = r.data;
            $('#bolgeModalBaslik').text('Bölge Düzenle');
            $('#BolgeId').val(d.BolgeId);
            $('#BolgeSezonId').val(String(d.BolgeSezonId)).trigger('change');
            $('#BolgeSehirId').val(String(d.BolgeSehirId)).trigger('change.select2');
            ilceleriYukle(d.BolgeSehirId, d.BolgeIlceId);
            $('#BolgeTutar').val(tutarNormalize(d.BolgeTutar));
            $('#BolgeTakmaAdlar').val(d.BolgeTakmaAdlar || '');
            $('#BolgeAciklama').val(d.BolgeAciklama || '');
            $('#Durum').prop('checked', d.Durum == 1);
            bolgeModal.show();
        }, 'json');
    }

    function bolgeSil(id) {
        Swal.fire({
            title: 'Bölge kaydı silinsin mi?',
            text: 'WhatsApp botu ve tutar sayfası bu kaydı artık görmeyecek. Geçici kapatmak için pasife alabilirsiniz.',
            icon: 'warning',
            showCancelButton: true,
            confirmButtonText: 'Evet, sil',
            cancelButtonText: 'Vazgeç',
            confirmButtonColor: '#dc3545'
        }).then(res => {
            if (!res.isConfirmed) return;
            $.post('', { action: 'delete', id: id }, function(r) {
                showToast(r.message, r.success ? 'success' : 'error');
                if (r.success) yenile();
            }, 'json');
        });
    }

    $('#bolgeForm').on('submit', function(e) {
        e.preventDefault();
        const fd = new FormData(this);
        fd.append('action', 'save');
        $.ajax({
            url: '', method: 'POST', data: fd, processData: false, contentType: false, dataType: 'json',
            success: function(r) {
                showToast(r.message, r.success ? 'success' : 'error');
                if (r.success) { bolgeModal.hide(); yenile(); }
            },
            error: function() { showToast('Bir hata oluştu!', 'error'); }
        });
    });

    function kopyalaModalAc() {
        $('#kaynakSezon').val(String(sezonId)).trigger('change');
        $('#hedefSezon').val('').trigger('change');
        kopyalaModal.show();
    }

    function sezonKopyala() {
        const kaynak = $('#kaynakSezon').val();
        const hedef  = $('#hedefSezon').val();
        if (!kaynak || !hedef) { showToast('Kaynak ve hedef sezon seçin!', 'warning'); return; }
        if (kaynak === hedef)  { showToast('Kaynak ve hedef sezon aynı olamaz!', 'warning'); return; }

        $.post('', { action: 'kopyala', kaynak_sezon_id: kaynak, hedef_sezon_id: hedef }, function(r) {
            showToast(r.message, r.success ? 'success' : 'error');
            if (!r.success) return;
            kopyalaModal.hide();
            $('#filtreSezon').val(hedef).trigger('change');
        }, 'json');
    }

    $(window).on('beforeunload', function() {
        if (degisenTutarlar().length) return 'Kaydedilmemiş tutarlar var.';
    });

    // ==========================================================
    $(document).ready(function() {
        bolgeModal   = new bootstrap.Modal(document.getElementById('bolgeModal'));
        kopyalaModal = new bootstrap.Modal(document.getElementById('kopyalaModal'));

        $('#filtreSezon, #filtreSehir, #filtreKapsam, #filtreDurum, #filtreTutar').select2(select2Ayar);
        $('#BolgeSezonId, #BolgeSehirId, #BolgeIlceId').select2($.extend({}, select2Ayar, { dropdownParent: $('#bolgeModal') }));
        $('#kaynakSezon, #hedefSezon').select2($.extend({}, select2Ayar, { dropdownParent: $('#kopyalaModal'), placeholder: 'Seçin', allowClear: true }));
        $('#hedefSezon').val('').trigger('change');

        $('#filtreSezon').on('change', sezonDegistir);
        $('#BolgeSehirId').on('select2:select', function() { ilceleriYukle($(this).val()); });

        if (!sezonId) {
            showToast('Tanımlı sezon bulunamadı!', 'warning');
            return;
        }

        istatistikYukle();
        tabloBaslat();
    });
    </script>
</body>
</html>
