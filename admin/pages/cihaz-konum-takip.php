<?php
/**
 * Cihaz ve Konum Takibi
 *
 * dbo.Cihaz_Konum_Kayitlari tablosundaki olay kayıtlarını listeler, haritada gösterir
 * ve kullanıcılardan push bildirimiyle anlık konum ister.
 */

require_once __DIR__ . '/../auth.php';
require_once __DIR__ . '/../includes/PageAuth.php';
require_once __DIR__ . '/../includes/KonumHelper.php';
require_once __DIR__ . '/../includes/Bildirim.php';
requireAuth();

$user = Auth::user();
$db = Database::getInstance();

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

$pageTitle = $pageInfo['sayfalar_sayfa_adi'] ?? 'Cihaz ve Konum Takibi';
$menuAdi   = $pageInfo['menu_adi'] ?? null;

$siteAyarlari = $db->fetchOne("SELECT TOP 1 site_ayarlari_site_title FROM dbo.tanim_site_ayarlari ORDER BY site_ayarlari_id DESC");
$siteTitle = $siteAyarlari['site_ayarlari_site_title'] ?? 'Örnek Yazılım Portal';

// Filtre kaynakları (tamamı DB'den)
$olayTipleri = KonumHelper::olayTipleri($db);
$konumAyar   = KonumHelper::ayarlar($db);

$kullanicilar = $db->fetchAll("
    SELECT kullanici_id,
           kullanici_ad + ' ' + kullanici_soyad
             + CASE WHEN ISNULL(kullanici_durum, 0) = 1 THEN '' ELSE ' (Pasif)' END AS ad_soyad
    FROM kullanicilar
    ORDER BY ISNULL(kullanici_durum, 0) DESC, kullanici_ad, kullanici_soyad
");

$departmanlar = $db->fetchAll("
    SELECT departman_id, departman_adi
    FROM Kullanici_Departmanlar
    WHERE departman_durum = 1
    ORDER BY departman_adi
");

$cihazTipleri   = $db->fetchAll("
    SELECT DISTINCT Cihaz_Konum_Kayitlari_CihazTipi AS deger
    FROM dbo.Cihaz_Konum_Kayitlari
    WHERE Cihaz_Konum_Kayitlari_CihazTipi IS NOT NULL
    ORDER BY deger
");

$konumDurumlari = $db->fetchAll("
    SELECT DISTINCT Cihaz_Konum_Kayitlari_KonumDurum AS deger
    FROM dbo.Cihaz_Konum_Kayitlari
    WHERE Cihaz_Konum_Kayitlari_KonumDurum IS NOT NULL
    ORDER BY deger
");

// ─── AJAX İşlemleri ───
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json; charset=utf-8');
    $action = $_POST['action'] ?? '';

    /**
     * Filtre koşullarını üretir. $pagePermissions['can_view_own_records'] ise
     * kullanıcı yalnızca kendi kayıtlarını görür.
     */
    $filtreKur = function (array $post) use ($pagePermissions, $user) {
        $sql = " WHERE k.Durum = 1";
        $params = [];

        if ($pagePermissions['can_view_own_records']) {
            $sql .= " AND k.Cihaz_Konum_Kayitlari_KullaniciId = ?";
            $params[] = $user['kullanici_id'];
        } elseif (!empty($post['kullanici_id'])) {
            $sql .= " AND k.Cihaz_Konum_Kayitlari_KullaniciId = ?";
            $params[] = (int)$post['kullanici_id'];
        }

        if (!empty($post['departman_id'])) {
            $sql .= " AND u.kullanici_departman_id = ?";
            $params[] = (int)$post['departman_id'];
        }
        if (!empty($post['olay_tipi'])) {
            $sql .= " AND k.Cihaz_Konum_Kayitlari_OlayTipi = ?";
            $params[] = $post['olay_tipi'];
        }
        if (!empty($post['cihaz_tipi'])) {
            $sql .= " AND k.Cihaz_Konum_Kayitlari_CihazTipi = ?";
            $params[] = $post['cihaz_tipi'];
        }
        if (!empty($post['konum_durum'])) {
            $sql .= " AND k.Cihaz_Konum_Kayitlari_KonumDurum = ?";
            $params[] = $post['konum_durum'];
        }
        if (isset($post['pwa_mi']) && $post['pwa_mi'] !== '') {
            $sql .= " AND k.Cihaz_Konum_Kayitlari_PwaMi = ?";
            $params[] = (int)$post['pwa_mi'];
        }
        if (!empty($post['start_date'])) {
            $sql .= " AND CONVERT(date, k.OlusturmaTarihi) >= ?";
            $params[] = $post['start_date'];
        }
        if (!empty($post['end_date'])) {
            $sql .= " AND CONVERT(date, k.OlusturmaTarihi) <= ?";
            $params[] = $post['end_date'];
        }
        if (!empty($post['search'])) {
            $sql .= " AND (u.kullanici_ad LIKE ? OR u.kullanici_soyad LIKE ?
                          OR k.Cihaz_Konum_Kayitlari_Ip LIKE ?
                          OR k.Cihaz_Konum_Kayitlari_OlayKaynak LIKE ?
                          OR k.Cihaz_Konum_Kayitlari_Platform LIKE ?)";
            $arama = '%' . $post['search'] . '%';
            array_push($params, $arama, $arama, $arama, $arama, $arama);
        }

        return [$sql, $params];
    };

    try {
        switch ($action) {

            case 'stats':
                [$where, $params] = $filtreKur($_POST);

                $temelSql = "
                    FROM dbo.Cihaz_Konum_Kayitlari k
                    LEFT JOIN kullanicilar u ON u.kullanici_id = k.Cihaz_Konum_Kayitlari_KullaniciId
                    {$where}
                ";

                $stats = [
                    'toplam' => (int)($db->fetchOne("SELECT COUNT(*) AS sayi {$temelSql}", $params)['sayi'] ?? 0),
                    'kullanici' => (int)($db->fetchOne("
                        SELECT COUNT(DISTINCT k.Cihaz_Konum_Kayitlari_KullaniciId) AS sayi {$temelSql}
                    ", $params)['sayi'] ?? 0),
                    'konumlu' => (int)($db->fetchOne("
                        SELECT COUNT(*) AS sayi {$temelSql} AND k.Cihaz_Konum_Kayitlari_KonumDurum = 'alindi'
                    ", $params)['sayi'] ?? 0),
                    'cihaz' => (int)($db->fetchOne("
                        SELECT COUNT(DISTINCT k.Cihaz_Konum_Kayitlari_CihazId) AS sayi {$temelSql}
                          AND k.Cihaz_Konum_Kayitlari_CihazId IS NOT NULL
                    ", $params)['sayi'] ?? 0),
                ];

                echo json_encode(['success' => true, 'data' => $stats]);
                break;

            case 'list':
                [$where, $params] = $filtreKur($_POST);
                $limit = min(5000, max(50, (int)($_POST['limit'] ?? 1000)));

                $data = $db->fetchAll("
                    SELECT TOP ({$limit})
                        k.Cihaz_Konum_Kayitlari_id            AS id,
                        k.Cihaz_Konum_Kayitlari_KullaniciId   AS kullanici_id,
                        ISNULL(u.kullanici_ad + ' ' + u.kullanici_soyad, '-') AS ad_soyad,
                        d.departman_adi                       AS departman,
                        k.Cihaz_Konum_Kayitlari_OlayTipi      AS olay_tipi,
                        o.tanim_konum_olay_tipleri_Ad         AS olay_adi,
                        o.tanim_konum_olay_tipleri_Renk       AS olay_renk,
                        k.Cihaz_Konum_Kayitlari_OlayKaynak    AS kaynak,
                        k.Cihaz_Konum_Kayitlari_OlayAciklama  AS aciklama,
                        k.Cihaz_Konum_Kayitlari_Enlem         AS enlem,
                        k.Cihaz_Konum_Kayitlari_Boylam        AS boylam,
                        k.Cihaz_Konum_Kayitlari_Dogruluk      AS dogruluk,
                        k.Cihaz_Konum_Kayitlari_KonumDurum    AS konum_durum,
                        k.Cihaz_Konum_Kayitlari_CihazTipi     AS cihaz_tipi,
                        k.Cihaz_Konum_Kayitlari_Platform      AS platform,
                        k.Cihaz_Konum_Kayitlari_Tarayici      AS tarayıcı,
                        k.Cihaz_Konum_Kayitlari_Model         AS model,
                        k.Cihaz_Konum_Kayitlari_Ekran         AS ekran,
                        k.Cihaz_Konum_Kayitlari_PwaMi         AS pwa_mi,
                        k.Cihaz_Konum_Kayitlari_Ip            AS ip,
                        k.Cihaz_Konum_Kayitlari_BaglantiTipi  AS baglanti,
                        k.Cihaz_Konum_Kayitlari_PilSeviye     AS pil,
                        k.Cihaz_Konum_Kayitlari_PilSarjda     AS pil_sarjda,
                        k.Cihaz_Konum_Kayitlari_Dil           AS dil,
                        k.Cihaz_Konum_Kayitlari_SaatDilimi    AS saat_dilimi,
                        k.Cihaz_Konum_Kayitlari_UserAgent     AS user_agent,
                        CONVERT(VARCHAR(19), k.OlusturmaTarihi, 120) AS tarih
                    FROM dbo.Cihaz_Konum_Kayitlari k
                    LEFT JOIN kullanicilar u ON u.kullanici_id = k.Cihaz_Konum_Kayitlari_KullaniciId
                    LEFT JOIN Kullanici_Departmanlar d ON d.departman_id = u.kullanici_departman_id
                    LEFT JOIN dbo.tanim_konum_olay_tipleri o ON o.tanim_konum_olay_tipleri_Kod = k.Cihaz_Konum_Kayitlari_OlayTipi
                    {$where}
                    ORDER BY k.Cihaz_Konum_Kayitlari_id DESC
                ", $params);

                echo json_encode(['success' => true, 'data' => $data, 'count' => count($data)]);
                break;

            case 'harita':
                // Filtreye uyan, konumu alınmış kayıtlar
                [$where, $params] = $filtreKur($_POST);
                $limit = min(2000, max(50, (int)($_POST['limit'] ?? 500)));

                $noktalar = $db->fetchAll("
                    SELECT TOP ({$limit})
                        k.Cihaz_Konum_Kayitlari_id          AS id,
                        ISNULL(u.kullanici_ad + ' ' + u.kullanici_soyad, '-') AS ad_soyad,
                        k.Cihaz_Konum_Kayitlari_Enlem       AS enlem,
                        k.Cihaz_Konum_Kayitlari_Boylam      AS boylam,
                        k.Cihaz_Konum_Kayitlari_Dogruluk    AS dogruluk,
                        k.Cihaz_Konum_Kayitlari_OlayTipi    AS olay_tipi,
                        o.tanim_konum_olay_tipleri_Ad       AS olay_adi,
                        k.Cihaz_Konum_Kayitlari_CihazTipi   AS cihaz_tipi,
                        k.Cihaz_Konum_Kayitlari_Platform    AS platform,
                        CONVERT(VARCHAR(19), k.OlusturmaTarihi, 120) AS tarih
                    FROM dbo.Cihaz_Konum_Kayitlari k
                    LEFT JOIN kullanicilar u ON u.kullanici_id = k.Cihaz_Konum_Kayitlari_KullaniciId
                    LEFT JOIN dbo.tanim_konum_olay_tipleri o ON o.tanim_konum_olay_tipleri_Kod = k.Cihaz_Konum_Kayitlari_OlayTipi
                    {$where}
                      AND k.Cihaz_Konum_Kayitlari_Enlem IS NOT NULL
                      AND k.Cihaz_Konum_Kayitlari_Boylam IS NOT NULL
                    ORDER BY k.Cihaz_Konum_Kayitlari_id DESC
                ", $params);

                echo json_encode(['success' => true, 'data' => $noktalar]);
                break;

            case 'son_konumlar':
                // Her kullanıcının en son alınmış konumu
                $sql = "
                    SELECT s.kullanici_id, s.ad_soyad, s.enlem, s.boylam, s.dogruluk,
                           s.olay_tipi, s.tarih, s.dakika_once, s.platform, s.cihaz_tipi
                    FROM (
                        SELECT
                            k.Cihaz_Konum_Kayitlari_KullaniciId AS kullanici_id,
                            ISNULL(u.kullanici_ad + ' ' + u.kullanici_soyad, '-') AS ad_soyad,
                            k.Cihaz_Konum_Kayitlari_Enlem       AS enlem,
                            k.Cihaz_Konum_Kayitlari_Boylam      AS boylam,
                            k.Cihaz_Konum_Kayitlari_Dogruluk    AS dogruluk,
                            k.Cihaz_Konum_Kayitlari_OlayTipi    AS olay_tipi,
                            k.Cihaz_Konum_Kayitlari_Platform    AS platform,
                            k.Cihaz_Konum_Kayitlari_CihazTipi   AS cihaz_tipi,
                            CONVERT(VARCHAR(19), k.OlusturmaTarihi, 120) AS tarih,
                            DATEDIFF(MINUTE, k.OlusturmaTarihi, GETDATE()) AS dakika_once,
                            ROW_NUMBER() OVER (
                                PARTITION BY k.Cihaz_Konum_Kayitlari_KullaniciId
                                ORDER BY k.Cihaz_Konum_Kayitlari_id DESC
                            ) AS sira
                        FROM dbo.Cihaz_Konum_Kayitlari k
                        LEFT JOIN kullanicilar u ON u.kullanici_id = k.Cihaz_Konum_Kayitlari_KullaniciId
                        WHERE k.Durum = 1
                          AND k.Cihaz_Konum_Kayitlari_Enlem IS NOT NULL
                          AND k.Cihaz_Konum_Kayitlari_Boylam IS NOT NULL
                    ) s
                    WHERE s.sira = 1
                ";
                $params = [];

                if ($pagePermissions['can_view_own_records']) {
                    $sql .= " AND s.kullanici_id = ?";
                    $params[] = $user['kullanici_id'];
                }
                $sql .= " ORDER BY s.dakika_once";

                echo json_encode(['success' => true, 'data' => $db->fetchAll($sql, $params)]);
                break;

            case 'konum_iste':
                // Seçili kullanıcılara push bildirimi gönder; tıklayınca konum kaydedilir
                if (!$pagePermissions['can_edit']) {
                    echo json_encode(['success' => false, 'message' => 'Bu işlem için yetkiniz yok!']);
                    break;
                }

                $hedefler = $_POST['kullanici_idler'] ?? [];
                if (!is_array($hedefler)) {
                    $hedefler = array_filter(explode(',', (string)$hedefler));
                }
                $hedefler = array_values(array_filter(array_map('intval', $hedefler)));

                if (empty($hedefler)) {
                    echo json_encode(['success' => false, 'message' => 'En az bir kullanıcı seçin!']);
                    break;
                }

                $sonuc = Bildirim::olusturCoklu($db, $hedefler, [
                    'baslik'    => 'Konum Bilgisi İsteniyor',
                    'govde'     => 'Konumunuzu paylaşmak için bu bildirime dokunun.',
                    'url'       => '/admin/index.php?konum=1',
                    'tip'       => 'uyari',
                    'push'      => true,
                    'olusturan' => $user['kullanici_id'],
                ]);

                echo json_encode([
                    'success' => true,
                    'message' => $sonuc['bildirim'] . ' kullanıcıya istek gönderildi (' . $sonuc['push'] . ' cihaza push).',
                ]);
                break;

            case 'ayar_kaydet':
                if (!$pagePermissions['can_edit']) {
                    echo json_encode(['success' => false, 'message' => 'Bu işlem için yetkiniz yok!']);
                    break;
                }

                $ayarSatir = $db->fetchOne("SELECT TOP 1 site_ayarlari_id FROM dbo.tanim_site_ayarlari ORDER BY site_ayarlari_id DESC");
                if (!$ayarSatir) {
                    echo json_encode(['success' => false, 'message' => 'Site ayarları kaydı bulunamadı!']);
                    break;
                }

                $db->update('dbo.tanim_site_ayarlari', [
                    'site_ayarlari_konum_takip_aktif' => !empty($_POST['takip_aktif']) ? 1 : 0,
                    'site_ayarlari_konum_throttle_dk' => max(0, (int)($_POST['throttle_dk'] ?? 15)),
                    'site_ayarlari_konum_saklama_gun' => max(0, (int)($_POST['saklama_gun'] ?? 180)),
                ], ['site_ayarlari_id' => $ayarSatir['site_ayarlari_id']]);

                echo json_encode(['success' => true, 'message' => 'Ayarlar kaydedildi.']);
                break;

            case 'delete':
                if (!$pagePermissions['can_delete']) {
                    echo json_encode(['success' => false, 'message' => 'Silme yetkiniz yok!']);
                    break;
                }

                $id = (int)($_POST['id'] ?? 0);
                $db->update('dbo.Cihaz_Konum_Kayitlari', [
                    'Durum'                => 0,
                    'GuncelleyenKullanici' => $user['kullanici_id'],
                    'GuncellemeTarihi'     => date('Y-m-d H:i:s'),
                ], ['Cihaz_Konum_Kayitlari_id' => $id]);

                echo json_encode(['success' => true, 'message' => 'Kayıt silindi.']);
                break;

            default:
                echo json_encode(['success' => false, 'message' => 'Geçersiz işlem!']);
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
    <link href="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/select2-bootstrap-5-theme@1.3.0/dist/select2-bootstrap-5-theme.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/sweetalert2@11/dist/sweetalert2.min.css" rel="stylesheet">
    <link href="https://cdn.datatables.net/1.13.7/css/dataTables.bootstrap5.min.css" rel="stylesheet">
    <link href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css" rel="stylesheet">
    <link href="https://unpkg.com/leaflet.markercluster@1.5.3/dist/MarkerCluster.css" rel="stylesheet">
    <link href="https://unpkg.com/leaflet.markercluster@1.5.3/dist/MarkerCluster.Default.css" rel="stylesheet">
    <link rel="stylesheet" href="/admin/assets/css/custom.css">
    <style>
        .info-box { transition: transform 0.2s; }
        .info-box:hover { transform: translateY(-5px); box-shadow: 0 4px 6px rgba(0,0,0,0.1); }
        #harita { height: 620px; border-radius: 0.5rem; }
        .badge { font-size: 0.75rem; padding: 0.35em 0.65em; }
        .detay-tablo th { width: 40%; font-weight: 500; color: #6c757d; }
        table.dataTable td { vertical-align: middle; }
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
                    <div class="col-sm-6"><h3 class="mb-0"><?= htmlspecialchars($pageTitle) ?></h3></div>
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
                    <div class="col-12 col-sm-6 col-md-3">
                        <div class="info-box">
                            <span class="info-box-icon text-bg-primary shadow-sm"><i class="bi bi-list-check"></i></span>
                            <div class="info-box-content">
                                <span class="info-box-text">Toplam Kayıt</span>
                                <span class="info-box-number" id="stat-toplam">0</span>
                            </div>
                        </div>
                    </div>
                    <div class="col-12 col-sm-6 col-md-3">
                        <div class="info-box">
                            <span class="info-box-icon text-bg-success shadow-sm"><i class="bi bi-people"></i></span>
                            <div class="info-box-content">
                                <span class="info-box-text">Kullanıcı</span>
                                <span class="info-box-number" id="stat-kullanici">0</span>
                            </div>
                        </div>
                    </div>
                    <div class="col-12 col-sm-6 col-md-3">
                        <div class="info-box">
                            <span class="info-box-icon text-bg-warning shadow-sm"><i class="bi bi-geo-alt"></i></span>
                            <div class="info-box-content">
                                <span class="info-box-text">Konumlu Kayıt</span>
                                <span class="info-box-number" id="stat-konumlu">0</span>
                            </div>
                        </div>
                    </div>
                    <div class="col-12 col-sm-6 col-md-3">
                        <div class="info-box">
                            <span class="info-box-icon text-bg-info shadow-sm"><i class="bi bi-phone"></i></span>
                            <div class="info-box-content">
                                <span class="info-box-text">Farklı Cihaz</span>
                                <span class="info-box-number" id="stat-cihaz">0</span>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Filtre Kartı -->
                <div class="card card-primary card-outline mb-3 collapse" id="filterCard">
                    <div class="card-header">
                        <h3 class="card-title"><i class="bi bi-funnel"></i> Filtreler</h3>
                    </div>
                    <div class="card-body">
                        <form id="filterForm">
                            <div class="row g-3">
                                <div class="col-md-2">
                                    <label class="form-label">Başlangıç Tarihi</label>
                                    <input type="date" class="form-control" id="filter_start_date">
                                </div>
                                <div class="col-md-2">
                                    <label class="form-label">Bitiş Tarihi</label>
                                    <input type="date" class="form-control" id="filter_end_date">
                                </div>
                                <?php if (!$pagePermissions['can_view_own_records']): ?>
                                <div class="col-md-3">
                                    <label class="form-label">Kullanıcı</label>
                                    <select class="form-select" id="filter_kullanici">
                                        <option value="">Tümü</option>
                                        <?php foreach ($kullanicilar as $k): ?>
                                            <option value="<?= (int)$k['kullanici_id'] ?>"><?= htmlspecialchars($k['ad_soyad']) ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div class="col-md-2">
                                    <label class="form-label">Departman</label>
                                    <select class="form-select" id="filter_departman">
                                        <option value="">Tümü</option>
                                        <?php foreach ($departmanlar as $d): ?>
                                            <option value="<?= (int)$d['departman_id'] ?>"><?= htmlspecialchars($d['departman_adi']) ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <?php endif; ?>
                                <div class="col-md-3">
                                    <label class="form-label">Olay Tipi</label>
                                    <select class="form-select" id="filter_olay">
                                        <option value="">Tümü</option>
                                        <?php foreach ($olayTipleri as $o): ?>
                                            <option value="<?= htmlspecialchars($o['kod']) ?>"><?= htmlspecialchars($o['ad']) ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div class="col-md-2">
                                    <label class="form-label">Cihaz Tipi</label>
                                    <select class="form-select" id="filter_cihaz">
                                        <option value="">Tümü</option>
                                        <?php foreach ($cihazTipleri as $c): ?>
                                            <option value="<?= htmlspecialchars($c['deger']) ?>"><?= htmlspecialchars(ucfirst($c['deger'])) ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div class="col-md-2">
                                    <label class="form-label">Konum Durumu</label>
                                    <select class="form-select" id="filter_konum_durum">
                                        <option value="">Tümü</option>
                                        <?php foreach ($konumDurumlari as $kd): ?>
                                            <option value="<?= htmlspecialchars($kd['deger']) ?>"><?= htmlspecialchars(ucfirst(str_replace('_', ' ', $kd['deger']))) ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div class="col-md-2">
                                    <label class="form-label">Uygulama</label>
                                    <select class="form-select" id="filter_pwa">
                                        <option value="">Tümü</option>
                                        <option value="1">PWA</option>
                                        <option value="0">Tarayıcı</option>
                                    </select>
                                </div>
                                <div class="col-md-3">
                                    <label class="form-label">Arama</label>
                                    <input type="text" class="form-control" id="filter_search" placeholder="Ad, IP, kaynak, platform...">
                                </div>
                                <div class="col-12">
                                    <button type="submit" class="btn btn-primary"><i class="bi bi-search"></i> Filtrele</button>
                                    <button type="button" class="btn btn-secondary" id="clearFilters"><i class="bi bi-x-circle"></i> Temizle</button>
                                </div>
                            </div>
                        </form>
                    </div>
                </div>

                <!-- Sekmeler -->
                <div class="card card-primary card-outline">
                    <div class="card-header d-flex flex-wrap justify-content-between align-items-center gap-2">
                        <ul class="nav nav-tabs card-header-tabs" role="tablist">
                            <li class="nav-item">
                                <button class="nav-link active" data-bs-toggle="tab" data-bs-target="#tab-liste" type="button">
                                    <i class="bi bi-list-ul"></i> Kayıt Listesi
                                </button>
                            </li>
                            <li class="nav-item">
                                <button class="nav-link" data-bs-toggle="tab" data-bs-target="#tab-harita" type="button" id="btnHaritaTab">
                                    <i class="bi bi-map"></i> Harita
                                </button>
                            </li>
                        </ul>
                        <div class="d-flex gap-2">
                            <button type="button" class="btn btn-sm btn-secondary" data-bs-toggle="collapse" data-bs-target="#filterCard">
                                <i class="bi bi-funnel"></i> Filtrele
                            </button>
                            <?php if ($pagePermissions['can_edit']): ?>
                            <button type="button" class="btn btn-sm btn-warning" id="btnKonumIste">
                                <i class="bi bi-geo-alt"></i> Konum İste
                            </button>
                            <button type="button" class="btn btn-sm btn-outline-primary" id="btnAyarlar">
                                <i class="bi bi-gear"></i> Ayarlar
                            </button>
                            <?php endif; ?>
                        </div>
                    </div>
                    <div class="card-body">
                        <div class="tab-content">
                            <!-- Liste -->
                            <div class="tab-pane fade show active" id="tab-liste">
                                <div class="table-responsive">
                                    <table class="table table-bordered table-striped table-hover w-100" id="kayitTable">
                                        <thead>
                                        <tr>
                                            <th>Tarih</th>
                                            <th>Kullanıcı</th>
                                            <th>Olay</th>
                                            <th>Kaynak</th>
                                            <th>Konum</th>
                                            <th>Cihaz</th>
                                            <th>Platform</th>
                                            <th>IP</th>
                                            <th width="110">İşlemler</th>
                                        </tr>
                                        </thead>
                                        <tbody></tbody>
                                    </table>
                                </div>
                            </div>

                            <!-- Harita -->
                            <div class="tab-pane fade" id="tab-harita">
                                <div class="d-flex flex-wrap gap-2 mb-2">
                                    <div class="btn-group btn-group-sm" role="group">
                                        <input type="radio" class="btn-check" name="haritaMod" id="modSon" value="son" checked>
                                        <label class="btn btn-outline-primary" for="modSon">
                                            <i class="bi bi-pin-map"></i> Son Konumlar
                                        </label>
                                        <input type="radio" class="btn-check" name="haritaMod" id="modTum" value="tum">
                                        <label class="btn btn-outline-primary" for="modTum">
                                            <i class="bi bi-clock-history"></i> Filtreli Geçmiş
                                        </label>
                                    </div>
                                    <button type="button" class="btn btn-sm btn-outline-secondary" id="btnHaritaYenile">
                                        <i class="bi bi-arrow-clockwise"></i> Yenile
                                    </button>
                                    <span class="align-self-center text-muted small" id="haritaBilgi"></span>
                                </div>
                                <div id="harita"></div>
                            </div>
                        </div>
                    </div>
                </div>

            </div>
        </div>
    </main>

    <?php include __DIR__ . '/../includes/footer.php'; ?>
</div>

<!-- Modal: Kayıt Detayı -->
<div class="modal fade" id="modalDetay" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-scrollable">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title"><i class="bi bi-info-circle"></i> Kayıt Detayı</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <table class="table table-sm detay-tablo" id="detayTablo"><tbody></tbody></table>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Kapat</button>
            </div>
        </div>
    </div>
</div>

<?php if ($pagePermissions['can_edit']): ?>
<!-- Modal: Konum İste -->
<div class="modal fade" id="modalKonumIste" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title"><i class="bi bi-geo-alt"></i> Konum İste</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <form id="konumIsteForm">
                <div class="modal-body">
                    <p class="text-muted small">
                        Seçilen kullanıcılara push bildirimi gönderilir. Kullanıcı bildirime dokunup
                        konum iznini onayladığında kaydı oluşur.
                    </p>
                    <label class="form-label">Kullanıcılar <span class="text-danger">*</span></label>
                    <select class="form-select" id="istekKullanicilar" multiple required>
                        <?php foreach ($kullanicilar as $k): ?>
                            <option value="<?= (int)$k['kullanici_id'] ?>"><?= htmlspecialchars($k['ad_soyad']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">İptal</button>
                    <button type="submit" class="btn btn-warning"><i class="bi bi-send"></i> İstek Gönder</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Modal: Ayarlar -->
<div class="modal fade" id="modalAyarlar" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title"><i class="bi bi-gear"></i> Takip Ayarları</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <form id="ayarForm">
                <div class="modal-body">
                    <div class="form-check form-switch mb-3">
                        <input class="form-check-input" type="checkbox" id="ayar_aktif" <?= $konumAyar['aktif'] ? 'checked' : '' ?>>
                        <label class="form-check-label" for="ayar_aktif">Cihaz ve konum takibi aktif</label>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Sayfa olayı aralığı (dakika)</label>
                        <input type="number" min="0" class="form-control" id="ayar_throttle" value="<?= (int)$konumAyar['throttle_dk'] ?>">
                        <small class="text-muted">Aynı kullanıcı için bu süre dolmadan yeni "sayfa" kaydı oluşturulmaz.</small>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Kayıt saklama süresi (gün)</label>
                        <input type="number" min="0" class="form-control" id="ayar_saklama" value="<?= (int)$konumAyar['saklama_gun'] ?>">
                        <small class="text-muted">Bu süreden eski "sayfa" kayıtları cron ile silinir. 0 = silme.</small>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">İptal</button>
                    <button type="submit" class="btn btn-primary"><i class="bi bi-save"></i> Kaydet</button>
                </div>
            </form>
        </div>
    </div>
</div>
<?php endif; ?>

<script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/@popperjs/core@2.11.8/dist/umd/popper.min.js" crossorigin="anonymous"></script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.7/dist/js/bootstrap.min.js" crossorigin="anonymous"></script>
<script src="/admin/assets/js/adminlte.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11/dist/sweetalert2.all.min.js"></script>
<script src="https://cdn.datatables.net/1.13.7/js/jquery.dataTables.min.js"></script>
<script src="https://cdn.datatables.net/1.13.7/js/dataTables.bootstrap5.min.js"></script>
<script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>
<script src="https://unpkg.com/leaflet.markercluster@1.5.3/dist/leaflet.markercluster.js"></script>
<script src="/admin/assets/js/custom.js"></script>

<script>
const permissions = <?= json_encode($pagePermissions) ?>;
const olayRenkleri = <?= json_encode(array_column($olayTipleri, 'renk', 'kod'), JSON_UNESCAPED_UNICODE) ?>;
const kendiKayitlari = <?= $pagePermissions['can_view_own_records'] ? 'true' : 'false' ?>;

let currentFilters = {};
let table = null;
let harita = null;
let markerGrup = null;

// ─── Yardımcılar ───
function esc(s) {
    if (s === null || s === undefined || s === '') return '-';
    return $('<div>').text(s).html();
}

function tarihFormat(t) {
    if (!t) return '-';
    const d = new Date(t.replace(' ', 'T'));
    if (isNaN(d.getTime())) return esc(t);
    return d.toLocaleString('tr-TR', {
        day: '2-digit', month: '2-digit', year: 'numeric',
        hour: '2-digit', minute: '2-digit'
    });
}

function konumRozeti(satır) {
    if (satır.konum_durum === 'alindi' && satır.enlem && satır.boylam) {
        const dogruluk = satır.dogruluk ? ` ±${satır.dogruluk}m` : '';
        return `<a href="https://www.google.com/maps?q=${satır.enlem},${satır.boylam}" target="_blank"
                   class="badge bg-success text-decoration-none" title="Google Maps'te aç">
                   <i class="bi bi-geo-alt-fill"></i> Konum${dogruluk}</a>`;
    }
    const etiketler = {
        red: ['danger', 'İzin Reddedildi'],
        hata: ['warning', 'Hata'],
        zaman_asimi: ['warning', 'Zaman Aşımı'],
        yok: ['secondary', 'Yok']
    };
    const e = etiketler[satır.konum_durum] || ['secondary', satır.konum_durum || '-'];
    return `<span class="badge bg-${e[0]}">${esc(e[1])}</span>`;
}

function cihazRozeti(satır) {
    const ikonlar = { mobil: 'bi-phone', tablet: 'bi-tablet', masaustu: 'bi-pc-display' };
    const ikon = ikonlar[satır.cihaz_tipi] || 'bi-question-circle';
    const pwa = parseInt(satır.pwa_mi, 10) === 1
        ? ' <span class="badge bg-primary" title="PWA olarak açıldı">PWA</span>' : '';
    return `<i class="bi ${ikon}"></i> ${esc(satır.cihaz_tipi)}${pwa}`;
}

// ─── İstatistik ───
function loadStats() {
    $.post('', { action: 'stats', ...currentFilters }, r => {
        if (!r.success) return;
        $('#stat-toplam').text(r.data.toplam);
        $('#stat-kullanici').text(r.data.kullanici);
        $('#stat-konumlu').text(r.data.konumlu);
        $('#stat-cihaz').text(r.data.cihaz);
    }, 'json');
}

// ─── DataTable ───
function initTable() {
    table = $('#kayitTable').DataTable({
        processing: true,
        order: [],
        pageLength: 25,
        language: { url: '//cdn.datatables.net/plug-ins/1.13.7/i18n/tr.json' },
        ajax: {
            url: '',
            type: 'POST',
            data: d => ({ action: 'list', ...currentFilters }),
            dataSrc: json => json.success ? json.data : []
        },
        columns: [
            { data: 'tarih', render: (d, t) => t === 'display' ? tarihFormat(d) : d },
            { data: 'ad_soyad', render: (d, t, row) => {
                const dep = row.departman ? `<br><small class="text-muted">${esc(row.departman)}</small>` : '';
                return esc(d) + dep;
            }},
            { data: 'olay_tipi', render: (d, t, row) => {
                if (t !== 'display') return d;
                const renk = olayRenkleri[d] || 'secondary';
                return `<span class="badge bg-${renk}">${esc(row.olay_adi || d)}</span>`;
            }},
            { data: 'kaynak', defaultContent: '-', render: (d, t) => t === 'display' ? esc(d) : d },
            { data: null, orderable: false, render: (d, t, row) => t === 'display' ? konumRozeti(row) : row.konum_durum },
            { data: null, render: (d, t, row) => t === 'display' ? cihazRozeti(row) : row.cihaz_tipi },
            { data: 'platform', defaultContent: '-', render: (d, t, row) => {
                if (t !== 'display') return d;
                const tarayıcı = row.tarayıcı ? `<br><small class="text-muted">${esc(row.tarayıcı)}</small>` : '';
                return esc(d) + tarayıcı;
            }},
            { data: 'ip', defaultContent: '-', render: (d, t) => t === 'display' ? esc(d) : d },
            {
                data: 'id',
                orderable: false,
                render: id => {
                    let html = `<button class="btn btn-sm btn-info" onclick="detayGoster(${id})" title="Detay"><i class="bi bi-eye"></i></button>`;
                    if (permissions.can_delete) {
                        html += ` <button class="btn btn-sm btn-danger" onclick="kayitSil(${id})" title="Sil"><i class="bi bi-trash"></i></button>`;
                    }
                    return html;
                }
            }
        ]
    });
}

// ─── Detay ───
function detayGoster(id) {
    const satır = table.rows().data().toArray().find(r => parseInt(r.id, 10) === id);
    if (!satır) return;

    const alanlar = [
        ['Tarih', tarihFormat(satır.tarih)],
        ['Kullanıcı', esc(satır.ad_soyad)],
        ['Departman', esc(satır.departman)],
        ['Olay', esc(satır.olay_adi || satır.olay_tipi)],
        ['Kaynak', esc(satır.kaynak)],
        ['Açıklama', esc(satır.aciklama)],
        ['Konum Durumu', konumRozeti(satır)],
        ['Koordinat', satır.enlem && satır.boylam ? `${satır.enlem}, ${satır.boylam}` : '-'],
        ['Doğruluk', satır.dogruluk ? satır.dogruluk + ' m' : '-'],
        ['Cihaz Tipi', esc(satır.cihaz_tipi)],
        ['Model', esc(satır.model)],
        ['Platform', esc(satır.platform)],
        ['Tarayıcı', esc(satır.tarayıcı)],
        ['Ekran', esc(satır.ekran)],
        ['PWA', parseInt(satır.pwa_mi, 10) === 1 ? 'Evet' : 'Hayır'],
        ['IP', esc(satır.ip)],
        ['Bağlantı', esc(satır.baglanti)],
        ['Pil', satır.pil !== null && satır.pil !== undefined ? satır.pil + '%' + (parseInt(satır.pil_sarjda, 10) === 1 ? ' (şarjda)' : '') : '-'],
        ['Dil', esc(satır.dil)],
        ['Saat Dilimi', esc(satır.saat_dilimi)],
        ['User Agent', `<small class="text-muted">${esc(satır.user_agent)}</small>`]
    ];

    $('#detayTablo tbody').html(alanlar.map(a => `<tr><th>${a[0]}</th><td>${a[1]}</td></tr>`).join(''));
    new bootstrap.Modal('#modalDetay').show();
}

function kayitSil(id) {
    confirmAction('Bu kaydı silmek istediğinize emin misiniz?', 'Kayıt listeden kaldırılacak.', function () {
        $.post('', { action: 'delete', id: id }, r => {
            showToast(r.message, r.success ? 'success' : 'error');
            if (r.success) { table.ajax.reload(null, false); loadStats(); }
        }, 'json');
    });
}

// ─── Harita ───
function initHarita() {
    if (harita) return;
    harita = L.map('harita').setView([39.0, 35.0], 6); // Türkiye geneli
    L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
        maxZoom: 19,
        attribution: '&copy; OpenStreetMap'
    }).addTo(harita);
    markerGrup = L.markerClusterGroup();
    harita.addLayer(markerGrup);
}

function haritaYukle() {
    initHarita();
    const mod = $('input[name="haritaMod"]:checked').val();
    const action = mod === 'son' ? 'son_konumlar' : 'harita';
    const veri = mod === 'son' ? { action: action } : { action: action, ...currentFilters };

    $('#haritaBilgi').text('Yükleniyor...');

    $.post('', veri, r => {
        markerGrup.clearLayers();

        if (!r.success || !r.data.length) {
            $('#haritaBilgi').text('Gösterilecek konum yok.');
            return;
        }

        const sinirlar = [];
        r.data.forEach(n => {
            const lat = parseFloat(n.enlem), lng = parseFloat(n.boylam);
            if (isNaN(lat) || isNaN(lng)) return;

            const gecen = (n.dakika_once !== undefined && n.dakika_once !== null)
                ? `<br><small class="text-muted">${n.dakika_once} dakika önce</small>` : '';

            const popup = `
                <strong>${esc(n.ad_soyad)}</strong><br>
                ${esc(n.olay_adi || n.olay_tipi)}<br>
                ${tarihFormat(n.tarih)}${gecen}<br>
                ${n.platform ? esc(n.platform) + '<br>' : ''}
                ${n.dogruluk ? 'Doğruluk: ±' + n.dogruluk + ' m<br>' : ''}
                <a href="https://www.google.com/maps?q=${lat},${lng}" target="_blank">Google Maps</a>
            `;

            markerGrup.addLayer(L.marker([lat, lng]).bindPopup(popup));
            sinirlar.push([lat, lng]);
        });

        if (sinirlar.length) {
            harita.fitBounds(L.latLngBounds(sinirlar), { padding: [40, 40], maxZoom: 16 });
        }
        $('#haritaBilgi').text(sinirlar.length + ' konum gösteriliyor.');
    }, 'json').fail(() => $('#haritaBilgi').text('Konumlar yüklenemedi.'));
}

// ─── Filtre ───
function filtreleriTopla() {
    const f = {
        start_date: $('#filter_start_date').val(),
        end_date: $('#filter_end_date').val(),
        olay_tipi: $('#filter_olay').val(),
        cihaz_tipi: $('#filter_cihaz').val(),
        konum_durum: $('#filter_konum_durum').val(),
        pwa_mi: $('#filter_pwa').val(),
        search: $('#filter_search').val()
    };

    if (!kendiKayitlari) {
        f.kullanici_id = $('#filter_kullanici').val();
        f.departman_id = $('#filter_departman').val();
    }

    Object.keys(f).forEach(k => { if (f[k] === '' || f[k] === null) delete f[k]; });
    return f;
}

$(document).ready(function () {
    // Searchable dropdown'lar
    $('#filter_kullanici, #filter_departman, #filter_olay, #filter_cihaz, #filter_konum_durum, #filter_pwa').select2({
        theme: 'bootstrap-5',
        placeholder: 'Tümü',
        allowClear: true,
        language: {
            noResults: () => 'Sonuç bulunamadı',
            searching: () => 'Aranıyor...'
        }
    });

    $('#istekKullanicilar').select2({
        theme: 'bootstrap-5',
        dropdownParent: $('#modalKonumIste'),
        placeholder: 'Kullanıcı seçin...',
        language: {
            noResults: () => 'Sonuç bulunamadı',
            searching: () => 'Aranıyor...'
        }
    });

    initTable();
    loadStats();

    $('#filterForm').on('submit', function (e) {
        e.preventDefault();
        currentFilters = filtreleriTopla();
        table.ajax.reload();
        loadStats();
        if (harita) haritaYukle();
        showToast('Filtre uygulandı', 'info');
    });

    $('#clearFilters').on('click', function () {
        $('#filterForm')[0].reset();
        $('#filterForm select').val('').trigger('change.select2');
        currentFilters = {};
        table.ajax.reload();
        loadStats();
        if (harita) haritaYukle();
        showToast('Filtreler temizlendi', 'info');
    });

    // Harita sekmesi ilk açılışta yüklensin, boyut düzeltmesi yapılsın
    $('#btnHaritaTab').on('shown.bs.tab', function () {
        haritaYukle();
        setTimeout(() => harita.invalidateSize(), 100);
    });

    $('input[name="haritaMod"]').on('change', haritaYukle);
    $('#btnHaritaYenile').on('click', haritaYukle);

    // Konum iste
    $('#btnKonumIste').on('click', () => new bootstrap.Modal('#modalKonumIste').show());

    $('#konumIsteForm').on('submit', function (e) {
        e.preventDefault();
        const secili = $('#istekKullanicilar').val();
        if (!secili || !secili.length) {
            showToast('En az bir kullanıcı seçin', 'warning');
            return;
        }
        $.post('', { action: 'konum_iste', kullanici_idler: secili }, r => {
            showToast(r.message, r.success ? 'success' : 'error');
            if (r.success) bootstrap.Modal.getInstance(document.getElementById('modalKonumIste')).hide();
        }, 'json');
    });

    // Ayarlar
    $('#btnAyarlar').on('click', () => new bootstrap.Modal('#modalAyarlar').show());

    $('#ayarForm').on('submit', function (e) {
        e.preventDefault();
        $.post('', {
            action: 'ayar_kaydet',
            takip_aktif: $('#ayar_aktif').is(':checked') ? 1 : 0,
            throttle_dk: $('#ayar_throttle').val(),
            saklama_gun: $('#ayar_saklama').val()
        }, r => {
            showToast(r.message, r.success ? 'success' : 'error');
            if (r.success) bootstrap.Modal.getInstance(document.getElementById('modalAyarlar')).hide();
        }, 'json');
    });
});
</script>
</body>
</html>
