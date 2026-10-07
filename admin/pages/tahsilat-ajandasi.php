<?php
/**
 * Tahsilat Ajandası
 * Aylık takvim görünümünde günlük tahsilatlar.
 *  - Beklenen tahsilat : odeme_vade_tarih o güne denk gelen ödemeler
 *  - Gerçekleşen tahsilat: odeme_yapildi = 1 ve odeme_tarih o güne denk gelen ödemeler
 */

require_once __DIR__ . '/../auth.php';
require_once __DIR__ . '/../includes/PageAuth.php';
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

// Sayfa bilgilerini al
$pageInfo = $db->fetchOne("
    SELECT
        s.sayfalar_sayfa_adi,
        s.sayfalar_aciklama,
        m.menuler_menu_adi as menu_adi
    FROM Menu_Sayfalar s
    LEFT JOIN Menuler m ON s.sayfalar_menu_id = m.menuler_id
    WHERE s.sayfalar_sayfa_url LIKE ? AND s.sayfalar_durum = 1
", ['%' . $currentPageFile]);

$pageTitle = $pageInfo['sayfalar_sayfa_adi'] ?? 'Tahsilat Ajandası';
$pageDescription = $pageInfo['sayfalar_aciklama'] ?? '';
$menuAdi = $pageInfo['menu_adi'] ?? 'Raporlar';

$siteAyarlari = $db->fetchOne("SELECT TOP 1 site_ayarlari_site_title FROM dbo.tanim_site_ayarlari ORDER BY site_ayarlari_id DESC");
$siteTitle = $siteAyarlari['site_ayarlari_site_title'] ?? 'Örnek Yazılım Portal';

// Filtre listeleri
$sezonlar = $db->fetchAll("SELECT sezon_id, sezon_ad FROM Sozlesme_Sezonlar WHERE sezon_durum = 1 ORDER BY sezon_ad DESC");
$cariTipleri = $db->fetchAll("SELECT cari_tipi_id, cari_tipi_ad FROM Cari_CariTipleri WHERE cari_tipi_durum = 1 ORDER BY cari_tipi_sira");

// Varsayılan sezon (URL parametresi - menü sezon bağlamı için, örn. Raporlar 2026 -> sezon_id=2). Parametre yoksa Tümü.
$defaultSezonId = (isset($_GET['sezon_id']) && $_GET['sezon_id'] !== '') ? (string)(int)$_GET['sezon_id'] : '';

$aylar = [
    1 => 'Ocak', 2 => 'Şubat', 3 => 'Mart', 4 => 'Nisan',
    5 => 'Mayıs', 6 => 'Haziran', 7 => 'Temmuz', 8 => 'Ağustos',
    9 => 'Eylül', 10 => 'Ekim', 11 => 'Kasım', 12 => 'Aralık'
];

// ─────────────────────────────────────────────
// AJAX İşlemleri
// ─────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json');
    $action = $_POST['action'] ?? '';

    try {
        switch ($action) {

            // ── Aylık takvim verisi ──
            case 'ay_verisi':
                $yil = intval($_POST['yil'] ?? date('Y'));
                $ay  = intval($_POST['ay']  ?? date('n'));
                $cariTipiId = $_POST['cari_tipi_id'] ?? '';
                $sezonId    = $_POST['sezon_id'] ?? '';

                if ($ay < 1 || $ay > 12) $ay = intval(date('n'));

                // Ortak filtre parçaları
                $filtreSql = '';
                $filtreParams = [];
                if ($cariTipiId !== '') {
                    $filtreSql .= " AND c.cari_tipi_id = ?";
                    $filtreParams[] = $cariTipiId;
                }
                if ($sezonId !== '') {
                    $filtreSql .= " AND s.sozlesme_sezon_id = ?";
                    $filtreParams[] = $sezonId;
                }

                // Beklenen (vade tarihi bazlı) — günlük
                $beklenenRows = $db->fetchAll("
                    SELECT DAY(o.odeme_vade_tarih) AS gun,
                           ISNULL(SUM(o.odeme_tutar), 0) AS tutar,
                           COUNT(*) AS adet
                    FROM Sozlesme_Odemeler o
                    LEFT JOIN Sozlesmeler s ON o.odeme_sozlesme_id = s.sozlesme_id
                    LEFT JOIN Cari c ON s.sozlesme_cari_id = c.cari_id
                    WHERE o.odeme_vade_tarih IS NOT NULL
                      AND YEAR(o.odeme_vade_tarih) = ?
                      AND MONTH(o.odeme_vade_tarih) = ?
                      $filtreSql
                    GROUP BY DAY(o.odeme_vade_tarih)
                ", array_merge([$yil, $ay], $filtreParams));

                // Gerçekleşen (ödeme tarihi bazlı, ödeme yapıldı) — günlük
                $gerceklesenRows = $db->fetchAll("
                    SELECT DAY(o.odeme_tarih) AS gun,
                           ISNULL(SUM(o.odeme_tutar), 0) AS tutar,
                           COUNT(*) AS adet
                    FROM Sozlesme_Odemeler o
                    LEFT JOIN Sozlesmeler s ON o.odeme_sozlesme_id = s.sozlesme_id
                    LEFT JOIN Cari c ON s.sozlesme_cari_id = c.cari_id
                    WHERE o.odeme_yapildi = 1
                      AND o.odeme_tarih IS NOT NULL
                      AND YEAR(o.odeme_tarih) = ?
                      AND MONTH(o.odeme_tarih) = ?
                      $filtreSql
                    GROUP BY DAY(o.odeme_tarih)
                ", array_merge([$yil, $ay], $filtreParams));

                // Beklenip henüz tahsil edilmemiş (vade bazlı, yapıldı=0) — gün başlığı için
                $bekleyenAcikRows = $db->fetchAll("
                    SELECT DAY(o.odeme_vade_tarih) AS gun,
                           ISNULL(SUM(o.odeme_tutar), 0) AS tutar
                    FROM Sozlesme_Odemeler o
                    LEFT JOIN Sozlesmeler s ON o.odeme_sozlesme_id = s.sozlesme_id
                    LEFT JOIN Cari c ON s.sozlesme_cari_id = c.cari_id
                    WHERE o.odeme_yapildi = 0
                      AND o.odeme_vade_tarih IS NOT NULL
                      AND YEAR(o.odeme_vade_tarih) = ?
                      AND MONTH(o.odeme_vade_tarih) = ?
                      $filtreSql
                    GROUP BY DAY(o.odeme_vade_tarih)
                ", array_merge([$yil, $ay], $filtreParams));

                // Günlere göre birleştir
                $gunler = [];
                $ensureGun = function ($g) use (&$gunler) {
                    if (!isset($gunler[$g])) {
                        $gunler[$g] = ['beklenen' => 0, 'beklenen_adet' => 0, 'gerceklesen' => 0, 'gerceklesen_adet' => 0, 'acik' => 0];
                    }
                };
                foreach ($beklenenRows as $r) {
                    $g = intval($r['gun']); $ensureGun($g);
                    $gunler[$g]['beklenen'] = floatval($r['tutar']);
                    $gunler[$g]['beklenen_adet'] = intval($r['adet']);
                }
                foreach ($gerceklesenRows as $r) {
                    $g = intval($r['gun']); $ensureGun($g);
                    $gunler[$g]['gerceklesen'] = floatval($r['tutar']);
                    $gunler[$g]['gerceklesen_adet'] = intval($r['adet']);
                }
                foreach ($bekleyenAcikRows as $r) {
                    $g = intval($r['gun']); $ensureGun($g);
                    $gunler[$g]['acik'] = floatval($r['tutar']);
                }

                // Ay özeti
                $toplamBeklenen   = array_sum(array_column($gunler, 'beklenen'));
                $toplamGerceklesen = array_sum(array_column($gunler, 'gerceklesen'));
                $toplamAcik       = array_sum(array_column($gunler, 'acik'));
                $oran = $toplamBeklenen > 0 ? round(($toplamGerceklesen / $toplamBeklenen) * 100, 1) : 0;

                // Üst özet: gerçek "bugün"e göre bekleyen tahsilatlar (ödenmemiş, vade bazlı)
                // Filtre (Cari Tip / Sezon) bu değerlere de uygulanır.
                $bugunStr = date('Y-m-d');
                $haftaBas = date('Y-m-d', strtotime('monday this week'));
                $haftaSon = date('Y-m-d', strtotime('sunday this week'));
                $ayBas    = date('Y-m-01');
                $aySon    = date('Y-m-t');

                $ustOzetRow = $db->fetchOne("
                    SELECT
                        ISNULL(SUM(CASE WHEN CONVERT(date, o.odeme_vade_tarih) = ? THEN o.odeme_tutar END), 0) AS bugun,
                        ISNULL(SUM(CASE WHEN CONVERT(date, o.odeme_vade_tarih) BETWEEN ? AND ? THEN o.odeme_tutar END), 0) AS hafta,
                        ISNULL(SUM(CASE WHEN CONVERT(date, o.odeme_vade_tarih) BETWEEN ? AND ? THEN o.odeme_tutar END), 0) AS ay
                    FROM Sozlesme_Odemeler o
                    LEFT JOIN Sozlesmeler s ON o.odeme_sozlesme_id = s.sozlesme_id
                    LEFT JOIN Cari c ON s.sozlesme_cari_id = c.cari_id
                    WHERE o.odeme_yapildi = 0
                      AND o.odeme_vade_tarih IS NOT NULL
                      $filtreSql
                ", array_merge([$bugunStr, $haftaBas, $haftaSon, $ayBas, $aySon], $filtreParams));

                echo json_encode([
                    'success' => true,
                    'gunler'  => $gunler,
                    'ozet'    => [
                        'beklenen'    => $toplamBeklenen,
                        'gerceklesen' => $toplamGerceklesen,
                        'acik'        => $toplamAcik,
                        'oran'        => $oran
                    ],
                    'ustOzet' => [
                        'bugun' => floatval($ustOzetRow['bugun'] ?? 0),
                        'hafta' => floatval($ustOzetRow['hafta'] ?? 0),
                        'ay'    => floatval($ustOzetRow['ay'] ?? 0)
                    ]
                ]);
                break;

            // ── Gün detayı ──
            case 'gun_detay':
                $tarih = $_POST['tarih'] ?? '';   // Y-m-d
                $cariTipiId = $_POST['cari_tipi_id'] ?? '';
                $sezonId    = $_POST['sezon_id'] ?? '';

                if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $tarih)) {
                    echo json_encode(['success' => false, 'message' => 'Geçersiz tarih']);
                    break;
                }

                $filtreSql = '';
                $filtreParams = [];
                if ($cariTipiId !== '') { $filtreSql .= " AND c.cari_tipi_id = ?"; $filtreParams[] = $cariTipiId; }
                if ($sezonId !== '')    { $filtreSql .= " AND s.sozlesme_sezon_id = ?"; $filtreParams[] = $sezonId; }

                // Vadesi bu gün olan VEYA bu gün tahsil edilen ödemeler
                $rows = $db->fetchAll("
                    SELECT
                        o.odeme_id,
                        s.sozlesme_id,
                        s.sozlesme_no,
                        c.cari_tipi_id,
                        ISNULL(NULLIF(LTRIM(RTRIM(c.cari_unvan)), ''), c.cari_adi) AS cari_adi,
                        (SELECT TOP 1 ht.takip_id FROM HukukTakip ht
                           WHERE ht.takip_cari_id = c.cari_id ORDER BY ht.takip_id DESC) AS takip_id,
                        ISNULL(ot.odeme_tipi_ad, '-') AS odeme_tipi_ad,
                        o.odeme_tutar,
                        o.odeme_yapildi,
                        CONVERT(VARCHAR(10), o.odeme_vade_tarih, 120) AS vade_tarih,
                        CONVERT(VARCHAR(10), o.odeme_tarih, 120) AS odeme_tarih,
                        ISNULL(d.odeme_durum_ad, 'Bekliyor') AS durum_ad,
                        ISNULL(d.odeme_durum_renk, 'secondary') AS durum_renk
                    FROM Sozlesme_Odemeler o
                    LEFT JOIN Sozlesmeler s ON o.odeme_sozlesme_id = s.sozlesme_id
                    LEFT JOIN Cari c ON s.sozlesme_cari_id = c.cari_id
                    LEFT JOIN Sozlesme_OdemeTipleri ot ON o.odeme_tipi_id = ot.odeme_tipi_id
                    LEFT JOIN Sozlesme_OdemeDurumlari d ON o.odeme_durum_id = d.odeme_durum_id
                    WHERE (
                            CONVERT(VARCHAR(10), o.odeme_vade_tarih, 120) = ?
                            OR (o.odeme_yapildi = 1 AND CONVERT(VARCHAR(10), o.odeme_tarih, 120) = ?)
                          )
                      $filtreSql
                    ORDER BY o.odeme_yapildi, cari_adi
                ", array_merge([$tarih, $tarih], $filtreParams));

                // Her satır için "tür" belirle (beklenen / tahsil edildi)
                foreach ($rows as &$row) {
                    $row['odeme_tutar'] = floatval($row['odeme_tutar']);
                    $row['odeme_yapildi'] = intval($row['odeme_yapildi']);
                    if ($row['odeme_yapildi'] == 1 && $row['odeme_tarih'] === $tarih) {
                        $row['tur'] = 'tahsil';   // bu gün kasaya girdi
                    } else {
                        $row['tur'] = 'beklenen'; // bu gün vadesi var
                    }
                }
                unset($row);

                echo json_encode(['success' => true, 'data' => $rows]);
                break;

            // ── Aylık detay listesi (Excel için) ──
            case 'ay_liste':
                $yil = intval($_POST['yil'] ?? date('Y'));
                $ay  = intval($_POST['ay']  ?? date('n'));
                $cariTipiId = $_POST['cari_tipi_id'] ?? '';
                $sezonId    = $_POST['sezon_id'] ?? '';

                if ($ay < 1 || $ay > 12) $ay = intval(date('n'));

                $filtreSql = '';
                $filtreParams = [];
                if ($cariTipiId !== '') { $filtreSql .= " AND c.cari_tipi_id = ?"; $filtreParams[] = $cariTipiId; }
                if ($sezonId !== '')    { $filtreSql .= " AND s.sozlesme_sezon_id = ?"; $filtreParams[] = $sezonId; }

                // Vadesi bu ayda olan VEYA bu ayda tahsil edilen ödemeler
                $rows = $db->fetchAll("
                    SELECT
                        o.odeme_id,
                        s.sozlesme_no,
                        ISNULL(NULLIF(LTRIM(RTRIM(c.cari_unvan)), ''), c.cari_adi) AS cari_adi,
                        ISNULL(ct.cari_tipi_ad, '-') AS cari_tipi_ad,
                        ISNULL(NULLIF(LTRIM(RTRIM(ISNULL(k.kullanici_ad, '') + ' ' + ISNULL(k.kullanici_soyad, ''))), ''), '-') AS personel_adi,
                        ISNULL(ot.odeme_tipi_ad, '-') AS odeme_tipi_ad,
                        o.odeme_tutar,
                        o.odeme_yapildi,
                        CONVERT(VARCHAR(10), o.odeme_vade_tarih, 104) AS vade_tarih,
                        CONVERT(VARCHAR(10), o.odeme_tarih, 104) AS odeme_tarih,
                        ISNULL(d.odeme_durum_ad, 'Bekliyor') AS durum_ad,
                        CASE WHEN o.odeme_yapildi = 1
                                  AND YEAR(o.odeme_tarih) = ?
                                  AND MONTH(o.odeme_tarih) = ?
                             THEN 'Tahsil Edildi' ELSE 'Beklenen' END AS tur_ad,
                        COALESCE(o.odeme_vade_tarih, o.odeme_tarih) AS sirala_tarih
                    FROM Sozlesme_Odemeler o
                    LEFT JOIN Sozlesmeler s ON o.odeme_sozlesme_id = s.sozlesme_id
                    LEFT JOIN Cari c ON s.sozlesme_cari_id = c.cari_id
                    LEFT JOIN Cari_CariTipleri ct ON c.cari_tipi_id = ct.cari_tipi_id
                    LEFT JOIN kullanicilar k ON s.sozlesme_personel_id = k.kullanici_id
                    LEFT JOIN Sozlesme_OdemeTipleri ot ON o.odeme_tipi_id = ot.odeme_tipi_id
                    LEFT JOIN Sozlesme_OdemeDurumlari d ON o.odeme_durum_id = d.odeme_durum_id
                    WHERE (
                            (o.odeme_vade_tarih IS NOT NULL
                             AND YEAR(o.odeme_vade_tarih) = ? AND MONTH(o.odeme_vade_tarih) = ?)
                            OR
                            (o.odeme_yapildi = 1 AND o.odeme_tarih IS NOT NULL
                             AND YEAR(o.odeme_tarih) = ? AND MONTH(o.odeme_tarih) = ?)
                          )
                      $filtreSql
                    ORDER BY sirala_tarih, cari_adi
                ", array_merge([$yil, $ay, $yil, $ay, $yil, $ay], $filtreParams));

                foreach ($rows as &$row) {
                    $row['odeme_tutar'] = floatval($row['odeme_tutar']);
                }
                unset($row);

                echo json_encode(['success' => true, 'data' => $rows]);
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
    <link href="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css" rel="stylesheet" />
    <link href="https://cdn.jsdelivr.net/npm/select2-bootstrap-5-theme@1.3.0/dist/select2-bootstrap-5-theme.min.css" rel="stylesheet" />
    <link href="https://cdn.jsdelivr.net/npm/sweetalert2@11/dist/sweetalert2.min.css" rel="stylesheet">
    <link rel="stylesheet" href="/admin/assets/css/custom.css">
    <style>
        .info-box { transition: transform 0.2s; }
        .info-box:hover { transform: translateY(-5px); box-shadow: 0 4px 6px rgba(0,0,0,0.1); }

        .ajanda-tablo { table-layout: fixed; width: 100%; }
        .ajanda-tablo th { text-align: center; background-color: #f8f9fa; padding: 8px 4px; font-size: 13px; }
        .ajanda-tablo th.hafta-sonu { color: #dc3545; }
        .ajanda-hucre {
            height: 108px; vertical-align: top; padding: 4px 6px; cursor: pointer;
            transition: background-color 0.15s; position: relative; overflow: hidden;
        }
        .ajanda-hucre:hover { background-color: #eef4ff; }
        .ajanda-hucre.bos { background-color: #fafafa; cursor: default; }
        .ajanda-hucre.bugun { outline: 2px solid #0d6efd; outline-offset: -2px; }
        .ajanda-hucre.hafta-sonu { background-color: #fff8f8; }
        .ajanda-gun-no { font-weight: 600; font-size: 14px; color: #495057; }
        .ajanda-gun-no.bugun-no { color: #0d6efd; }
        .ajanda-satır { display: flex; align-items: center; gap: 4px; font-size: 11.5px; margin-top: 3px; line-height: 1.3; }
        .ajanda-badge { display:inline-block; padding: 1px 5px; border-radius: 4px; font-weight: 600; font-size: 10.5px; }
        .badge-tahsil   { background:#d1e7dd; color:#0f5132; }
        .badge-beklenen { background:#fff3cd; color:#664d03; }
        .badge-acik     { background:#f8d7da; color:#842029; }
        .ajanda-tutar { font-weight: 600; margin-left: auto; text-align: right; white-space: nowrap; }
        .t-yesil { color:#0f5132; } .t-sari { color:#997404; } .t-kirmizi { color:#842029; }
        @media (max-width: 768px) {
            .ajanda-hucre { height: auto; min-height: 70px; }
            .ajanda-satır { font-size: 10px; }
        }
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

                    <!-- Bekleyen Tahsilat Özeti (bugün / hafta / ay) -->
                    <div class="row mb-3">
                        <div class="col-12 col-md-4">
                            <div class="info-box">
                                <span class="info-box-icon text-bg-primary shadow-sm"><i class="bi bi-calendar-day"></i></span>
                                <div class="info-box-content">
                                    <span class="info-box-text">Bugün Bekleyen Tahsilat</span>
                                    <span class="info-box-number" id="stat-bekleyen-bugun">0 ₺</span>
                                </div>
                            </div>
                        </div>
                        <div class="col-12 col-md-4">
                            <div class="info-box">
                                <span class="info-box-icon text-bg-secondary shadow-sm"><i class="bi bi-calendar-week"></i></span>
                                <div class="info-box-content">
                                    <span class="info-box-text">Bu Hafta Bekleyen Tahsilat</span>
                                    <span class="info-box-number" id="stat-bekleyen-hafta">0 ₺</span>
                                </div>
                            </div>
                        </div>
                        <div class="col-12 col-md-4">
                            <div class="info-box">
                                <span class="info-box-icon text-bg-dark shadow-sm"><i class="bi bi-calendar-month"></i></span>
                                <div class="info-box-content">
                                    <span class="info-box-text">Bu Ay Bekleyen Tahsilat</span>
                                    <span class="info-box-number" id="stat-bekleyen-ay">0 ₺</span>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Info Boxes -->
                    <div class="row mb-3">
                        <div class="col-12 col-sm-6 col-md-3">
                            <div class="info-box">
                                <span class="info-box-icon text-bg-warning shadow-sm"><i class="bi bi-calendar-check"></i></span>
                                <div class="info-box-content">
                                    <span class="info-box-text">Ay Beklenen (Vade)</span>
                                    <span class="info-box-number" id="stat-beklenen">0 ₺</span>
                                </div>
                            </div>
                        </div>
                        <div class="col-12 col-sm-6 col-md-3">
                            <div class="info-box">
                                <span class="info-box-icon text-bg-success shadow-sm"><i class="bi bi-cash-stack"></i></span>
                                <div class="info-box-content">
                                    <span class="info-box-text">Ay Tahsil Edilen</span>
                                    <span class="info-box-number" id="stat-gerceklesen">0 ₺</span>
                                </div>
                            </div>
                        </div>
                        <div class="col-12 col-sm-6 col-md-3">
                            <div class="info-box">
                                <span class="info-box-icon text-bg-danger shadow-sm"><i class="bi bi-hourglass-split"></i></span>
                                <div class="info-box-content">
                                    <span class="info-box-text">Bekleyen Açık (Vade)</span>
                                    <span class="info-box-number" id="stat-acik">0 ₺</span>
                                </div>
                            </div>
                        </div>
                        <div class="col-12 col-sm-6 col-md-3">
                            <div class="info-box">
                                <span class="info-box-icon text-bg-info shadow-sm"><i class="bi bi-percent"></i></span>
                                <div class="info-box-content">
                                    <span class="info-box-text">Tahsilat Oranı</span>
                                    <span class="info-box-number" id="stat-oran">%0</span>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Filtre Kartı -->
                    <div class="card card-primary card-outline mb-3">
                        <div class="card-header">
                            <h3 class="card-title"><i class="bi bi-funnel"></i> Filtrele</h3>
                            <div class="card-tools">
                                <button type="button" class="btn btn-tool" data-bs-toggle="collapse" data-bs-target="#filterCollapse" aria-expanded="false">
                                    <i class="bi bi-chevron-down"></i>
                                </button>
                            </div>
                        </div>
                        <div class="card-body collapse" id="filterCollapse">
                            <div class="row g-3">
                                <div class="col-md-3">
                                    <label class="form-label">Cari Tip</label>
                                    <select class="form-select select2" id="filter_cari_tipi">
                                        <option value="">Tümü</option>
                                        <?php foreach ($cariTipleri as $tip): ?>
                                            <option value="<?= $tip['cari_tipi_id'] ?>"><?= htmlspecialchars($tip['cari_tipi_ad']) ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div class="col-md-3">
                                    <label class="form-label">Sezon</label>
                                    <select class="form-select select2" id="filter_sezon">
                                        <option value="">Tümü</option>
                                        <?php foreach ($sezonlar as $sezon): ?>
                                            <option value="<?= $sezon['sezon_id'] ?>" <?= (string)$sezon['sezon_id'] === $defaultSezonId ? 'selected' : '' ?>><?= htmlspecialchars($sezon['sezon_ad']) ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div class="col-md-3 d-flex align-items-end">
                                    <button type="button" class="btn btn-primary w-100" id="btnFiltrele"><i class="bi bi-search"></i> Uygula</button>
                                </div>
                                <div class="col-md-3 d-flex align-items-end">
                                    <button type="button" class="btn btn-outline-secondary w-100" id="btnTemizle"><i class="bi bi-x-circle"></i> Temizle</button>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Ajanda Kartı -->
                    <div class="card card-outline card-primary">
                        <div class="card-header d-flex align-items-center justify-content-between flex-wrap gap-2">
                            <div class="btn-group">
                                <button type="button" class="btn btn-outline-primary" id="btnOncekiAy"><i class="bi bi-chevron-left"></i></button>
                                <button type="button" class="btn btn-outline-primary" id="btnBuAy">Bugün</button>
                                <button type="button" class="btn btn-outline-primary" id="btnSonrakiAy"><i class="bi bi-chevron-right"></i></button>
                            </div>
                            <h4 class="mb-0 fw-bold" id="ayBaslik">—</h4>
                            <div class="d-flex align-items-center gap-3 flex-wrap">
                                <div class="d-flex gap-2 small">
                                    <span><span class="ajanda-badge badge-tahsil">Tahsil</span></span>
                                    <span><span class="ajanda-badge badge-beklenen">Beklenen</span></span>
                                    <span><span class="ajanda-badge badge-acik">Açık</span></span>
                                </div>
                                <div class="btn-group">
                                    <button type="button" class="btn btn-sm btn-success" id="btnExcelDetay">
                                        <i class="bi bi-file-earmark-excel"></i> Excel (Detay)
                                    </button>
                                    <button type="button" class="btn btn-sm btn-outline-success" id="btnExcelOzet">
                                        <i class="bi bi-file-earmark-spreadsheet"></i> Excel (Günlük Özet)
                                    </button>
                                </div>
                            </div>
                        </div>
                        <div class="card-body p-2 position-relative">
                            <div id="ajandaYukleniyor" class="text-center py-5 d-none">
                                <div class="spinner-border text-primary" role="status"></div>
                            </div>
                            <div class="table-responsive">
                                <table class="table table-bordered ajanda-tablo mb-0">
                                    <thead>
                                        <tr>
                                            <th>Pzt</th><th>Sal</th><th>Çar</th><th>Per</th><th>Cum</th>
                                            <th class="hafta-sonu">Cmt</th><th class="hafta-sonu">Paz</th>
                                        </tr>
                                    </thead>
                                    <tbody id="ajandaBody"></tbody>
                                </table>
                            </div>
                        </div>
                    </div>

                </div>
            </div>
        </main>

        <?php include __DIR__ . '/../includes/footer.php'; ?>
    </div>

    <!-- Gün Detay Modal -->
    <div class="modal fade" id="gunModal" tabindex="-1">
        <div class="modal-dialog modal-lg modal-dialog-scrollable">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title"><i class="bi bi-calendar-day"></i> <span id="gunModalBaslik"></span></h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body" id="gunModalBody"></div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/jquery@3.7.1/dist/jquery.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/@popperjs/core@2.11.8/dist/umd/popper.min.js" crossorigin="anonymous"></script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.min.js" crossorigin="anonymous"></script>
    <script src="/admin/assets/js/adminlte.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11/dist/sweetalert2.all.min.js"></script>
    <script src="/admin/assets/js/custom.js"></script>

    <script>
        const AYLAR = ['Ocak','Şubat','Mart','Nisan','Mayıs','Haziran','Temmuz','Ağustos','Eylül','Ekim','Kasım','Aralık'];
        let bugun = new Date('<?= date('Y-m-d') ?>T00:00:00');
        let aktifYil = bugun.getFullYear();
        let aktifAy  = bugun.getMonth() + 1; // 1-12

        function fmt(n) {
            return new Intl.NumberFormat('tr-TR', { minimumFractionDigits: 2, maximumFractionDigits: 2 }).format(n || 0) + ' ₺';
        }
        function filtreler() {
            return {
                cari_tipi_id: $('#filter_cari_tipi').val() || '',
                sezon_id: $('#filter_sezon').val() || ''
            };
        }
        function ikiHane(n){ return (n < 10 ? '0' : '') + n; }

        // Son yüklenen ay verisi (özet Excel için)
        let sonGunler = {};

        // ── CSV yardımcıları ──
        function csvHucre(v) {
            return '"' + String(v ?? '').replace(/"/g, '""') + '"';
        }
        function csvSayi(n) {
            // Excel TR: ondalık ayırıcı virgül
            return '"' + (parseFloat(n) || 0).toFixed(2).replace('.', ',') + '"';
        }
        function csvIndir(satirlar, dosyaAdi) {
            const icerik = '﻿' + satirlar.map(r => r.join(';')).join('\r\n');
            const blob = new Blob([icerik], { type: 'text/csv;charset=utf-8;' });
            const url = URL.createObjectURL(blob);
            const a = document.createElement('a');
            a.href = url;
            a.download = dosyaAdi;
            a.click();
            URL.revokeObjectURL(url);
        }
        function dosyaAdiUret(onek) {
            return onek + '-' + aktifYil + '-' + ikiHane(aktifAy) + '.csv';
        }

        // ── Excel: Günlük özet ──
        function excelOzetIndir() {
            const gunSayisi = new Date(aktifYil, aktifAy, 0).getDate();
            const bugunStr = '<?= date('Y-m-d') ?>';

            let satirlar = [];
            satirlar.push([csvHucre('Tarih'), csvHucre('Gün'), csvHucre('Tahsil Edilen'), csvHucre('Tahsil Adet'),
                           csvHucre('Beklenen (Vade)'), csvHucre('Beklenen Adet'), csvHucre('Açık Tutar'), csvHucre('Durum')]);

            let tTahsil = 0, tTahsilAdet = 0, tBeklenen = 0, tBeklenenAdet = 0, tAcik = 0;

            for (let g = 1; g <= gunSayisi; g++) {
                const tarihStr = aktifYil + '-' + ikiHane(aktifAy) + '-' + ikiHane(g);
                const d = sonGunler[g] || null;
                if (!d) continue;

                const gunAdi = ['Pazar','Pazartesi','Salı','Çarşamba','Perşembe','Cuma','Cumartesi'][new Date(tarihStr + 'T00:00:00').getDay()];
                const durum = d.acik > 0 ? (tarihStr < bugunStr ? 'Gecikmiş' : 'Bekliyor') : '';

                tTahsil += d.gerceklesen; tTahsilAdet += d.gerceklesen_adet;
                tBeklenen += d.beklenen;  tBeklenenAdet += d.beklenen_adet;
                tAcik += d.acik;

                satirlar.push([
                    csvHucre(ikiHane(g) + '.' + ikiHane(aktifAy) + '.' + aktifYil),
                    csvHucre(gunAdi),
                    csvSayi(d.gerceklesen), csvHucre(d.gerceklesen_adet),
                    csvSayi(d.beklenen),    csvHucre(d.beklenen_adet),
                    csvSayi(d.acik),        csvHucre(durum)
                ]);
            }

            if (satirlar.length === 1) {
                Swal.fire('Bilgi', 'Bu ayda dışa aktarılacak kayıt bulunamadı.', 'info');
                return;
            }

            satirlar.push([csvHucre('TOPLAM'), csvHucre(''), csvSayi(tTahsil), csvHucre(tTahsilAdet),
                           csvSayi(tBeklenen), csvHucre(tBeklenenAdet), csvSayi(tAcik), csvHucre('')]);

            csvIndir(satirlar, dosyaAdiUret('tahsilat-ajandasi-ozet'));
            if (typeof showToast === 'function') showToast('Excel dosyası indirildi', 'success');
        }

        // ── Excel: Ödeme detay satırları ──
        function excelDetayIndir() {
            const $btn = $('#btnExcelDetay');
            const eskiHtml = $btn.html();
            $btn.prop('disabled', true).html('<span class="spinner-border spinner-border-sm"></span> Hazırlanıyor...');

            $.post('', { action: 'ay_liste', yil: aktifYil, ay: aktifAy, ...filtreler() }, function (res) {
                $btn.prop('disabled', false).html(eskiHtml);

                if (!res.success) {
                    Swal.fire('Hata', res.message || 'Veri alınamadı', 'error');
                    return;
                }
                if (!res.data.length) {
                    Swal.fire('Bilgi', 'Bu ayda dışa aktarılacak kayıt bulunamadı.', 'info');
                    return;
                }

                let satirlar = [];
                satirlar.push([csvHucre('Vade Tarihi'), csvHucre('Ödeme Tarihi'), csvHucre('Cari'), csvHucre('Satış Personeli'),
                               csvHucre('Cari Tipi'), csvHucre('Sözleşme No'), csvHucre('Ödeme Tipi'), csvHucre('Tutar'),
                               csvHucre('Durum'), csvHucre('Tür')]);

                let toplam = 0;
                res.data.forEach(function (r) {
                    toplam += parseFloat(r.odeme_tutar) || 0;
                    satirlar.push([
                        csvHucre(r.vade_tarih || '-'),
                        csvHucre(r.odeme_tarih || '-'),
                        csvHucre(r.cari_adi || '-'),
                        csvHucre(r.personel_adi || '-'),
                        csvHucre(r.cari_tipi_ad || '-'),
                        csvHucre(r.sozlesme_no || '-'),
                        csvHucre(r.odeme_tipi_ad || '-'),
                        csvSayi(r.odeme_tutar),
                        csvHucre(r.durum_ad || '-'),
                        csvHucre(r.tur_ad || '-')
                    ]);
                });

                satirlar.push([csvHucre('TOPLAM'), csvHucre(''), csvHucre(''), csvHucre(''), csvHucre(''),
                               csvHucre(''), csvHucre(''), csvSayi(toplam), csvHucre(''), csvHucre('')]);

                csvIndir(satirlar, dosyaAdiUret('tahsilat-ajandasi-detay'));
                if (typeof showToast === 'function') showToast('Excel dosyası indirildi', 'success');
            }, 'json').fail(function () {
                $btn.prop('disabled', false).html(eskiHtml);
                Swal.fire('Hata', 'Sunucu ile bağlantı kurulamadı.', 'error');
            });
        }

        function ayYukle() {
            $('#ajandaYukleniyor').removeClass('d-none');
            $('#ayBaslik').text(AYLAR[aktifAy - 1] + ' ' + aktifYil);

            $.post('', { action: 'ay_verisi', yil: aktifYil, ay: aktifAy, ...filtreler() }, function (res) {
                $('#ajandaYukleniyor').addClass('d-none');
                if (!res.success) {
                    Swal.fire('Hata', res.message || 'Veri alınamadı', 'error');
                    return;
                }
                // Özet
                $('#stat-beklenen').text(fmt(res.ozet.beklenen));
                $('#stat-gerceklesen').text(fmt(res.ozet.gerceklesen));
                $('#stat-acik').text(fmt(res.ozet.acik));
                $('#stat-oran').text('%' + res.ozet.oran);

                // Üst özet (bugün / hafta / ay bekleyen tahsilat)
                if (res.ustOzet) {
                    $('#stat-bekleyen-bugun').text(fmt(res.ustOzet.bugun));
                    $('#stat-bekleyen-hafta').text(fmt(res.ustOzet.hafta));
                    $('#stat-bekleyen-ay').text(fmt(res.ustOzet.ay));
                }

                sonGunler = res.gunler || {};
                takvimCiz(res.gunler);
            }, 'json').fail(function () {
                $('#ajandaYukleniyor').addClass('d-none');
                Swal.fire('Hata', 'Sunucu ile bağlantı kurulamadı.', 'error');
            });
        }

        function takvimCiz(gunler) {
            const ilkGun = new Date(aktifYil, aktifAy - 1, 1);
            const gunSayisi = new Date(aktifYil, aktifAy, 0).getDate();
            // Pazartesi = 0 olacak şekilde kaydırma (JS: Pzr=0)
            let baslangicKaydirma = (ilkGun.getDay() + 6) % 7;

            const bugunStr = '<?= date('Y-m-d') ?>';
            let html = '';
            let gun = 1;
            const toplamHucre = baslangicKaydirma + gunSayisi;
            const satirSayisi = Math.ceil(toplamHucre / 7);

            for (let s = 0; s < satirSayisi; s++) {
                html += '<tr>';
                for (let g = 0; g < 7; g++) {
                    const hucreIndex = s * 7 + g;
                    const haftaSonu = (g >= 5);
                    if (hucreIndex < baslangicKaydirma || gun > gunSayisi) {
                        html += '<td class="ajanda-hucre bos"></td>';
                        continue;
                    }
                    const tarihStr = aktifYil + '-' + ikiHane(aktifAy) + '-' + ikiHane(gun);
                    const d = gunler[gun] || null;
                    const buGunMu = (tarihStr === bugunStr);

                    let ic = '<div class="ajanda-gun-no ' + (buGunMu ? 'bugun-no' : '') + '">' + gun + '</div>';
                    if (d) {
                        if (d.gerceklesen > 0) {
                            ic += '<div class="ajanda-satır"><span class="ajanda-badge badge-tahsil">' + d.gerceklesen_adet + '</span>'
                                + '<span class="ajanda-tutar t-yesil">' + fmt(d.gerceklesen) + '</span></div>';
                        }
                        if (d.acik > 0) {
                            const gecmis = tarihStr < bugunStr;
                            ic += '<div class="ajanda-satır"><span class="ajanda-badge ' + (gecmis ? 'badge-acik' : 'badge-beklenen') + '">'
                                + (gecmis ? 'Gecikmiş' : 'Bekliyor') + '</span>'
                                + '<span class="ajanda-tutar ' + (gecmis ? 't-kirmizi' : 't-sari') + '">' + fmt(d.acik) + '</span></div>';
                        }
                    }

                    let cls = 'ajanda-hucre';
                    if (haftaSonu) cls += ' hafta-sonu';
                    if (buGunMu) cls += ' bugun';
                    html += '<td class="' + cls + '" data-tarih="' + tarihStr + '">' + ic + '</td>';
                    gun++;
                }
                html += '</tr>';
            }
            $('#ajandaBody').html(html);
        }

        function gunDetayAc(tarih) {
            const dt = new Date(tarih + 'T00:00:00');
            const baslik = dt.getDate() + ' ' + AYLAR[dt.getMonth()] + ' ' + dt.getFullYear();
            $('#gunModalBaslik').text(baslik);
            $('#gunModalBody').html('<div class="text-center py-4"><div class="spinner-border text-primary"></div></div>');
            const modal = new bootstrap.Modal('#gunModal');
            modal.show();

            $.post('', { action: 'gun_detay', tarih: tarih, ...filtreler() }, function (res) {
                if (!res.success) {
                    $('#gunModalBody').html('<div class="alert alert-danger">' + (res.message || 'Hata') + '</div>');
                    return;
                }
                if (!res.data.length) {
                    $('#gunModalBody').html('<div class="alert alert-info mb-0"><i class="bi bi-info-circle"></i> Bu güne ait tahsilat kaydı yok.</div>');
                    return;
                }
                let t = '<div class="table-responsive"><table class="table table-sm table-hover align-middle">';
                t += '<thead class="table-light"><tr><th>Cari</th><th>Sözleşme</th><th>Tip</th><th class="text-end">Tutar</th><th>Durum</th><th class="text-center">Tür</th><th></th></tr></thead><tbody>';
                res.data.forEach(function (r) {
                    const turBadge = r.tur === 'tahsil'
                        ? '<span class="badge bg-success">Tahsil Edildi</span>'
                        : '<span class="badge bg-warning text-dark">Beklenen</span>';

                    // Cari tipine göre doğru forma yönlendir
                    // cari_tipi_id=1 → sözleşme formu (id=sozlesme_id)
                    // cari_tipi_id=3/4 → hukuk formu (id=takip_id)
                    let linkUrl = '';
                    if (r.cari_tipi_id == 1 && r.sozlesme_id) {
                        linkUrl = '/admin/form?id=' + r.sozlesme_id + '&cari_tipi_id=1';
                    } else if (r.takip_id) {
                        linkUrl = '/admin/form?id=' + r.takip_id + '&cari_tipi_id=' + r.cari_tipi_id;
                    }
                    const linkBtn = linkUrl
                        ? '<a href="' + linkUrl + '" target="_blank" class="btn btn-sm btn-outline-primary"><i class="bi bi-box-arrow-up-right"></i></a>'
                        : '';

                    t += '<tr>'
                       + '<td>' + (r.cari_adi || '-') + '</td>'
                       + '<td>' + (r.sozlesme_no || '-') + '</td>'
                       + '<td>' + (r.odeme_tipi_ad || '-') + '</td>'
                       + '<td class="text-end fw-bold">' + fmt(r.odeme_tutar) + '</td>'
                       + '<td><span class="badge bg-' + (r.durum_renk || 'secondary') + '">' + (r.durum_ad || '-') + '</span></td>'
                       + '<td class="text-center">' + turBadge + '</td>'
                       + '<td class="text-end">' + linkBtn + '</td>'
                       + '</tr>';
                });
                t += '</tbody></table></div>';
                $('#gunModalBody').html(t);
            }, 'json').fail(function () {
                $('#gunModalBody').html('<div class="alert alert-danger">Sunucu ile bağlantı kurulamadı.</div>');
            });
        }

        $(function () {
            $('.select2').select2({ theme: 'bootstrap-5', width: '100%' });

            $('#btnOncekiAy').on('click', function () {
                aktifAy--; if (aktifAy < 1) { aktifAy = 12; aktifYil--; } ayYukle();
            });
            $('#btnSonrakiAy').on('click', function () {
                aktifAy++; if (aktifAy > 12) { aktifAy = 1; aktifYil++; } ayYukle();
            });
            $('#btnBuAy').on('click', function () {
                aktifYil = bugun.getFullYear(); aktifAy = bugun.getMonth() + 1; ayYukle();
            });
            $('#btnFiltrele').on('click', ayYukle);
            $('#btnTemizle').on('click', function () {
                $('#filter_cari_tipi').val('').trigger('change');
                $('#filter_sezon').val('<?= $defaultSezonId ?>').trigger('change');
                ayYukle();
            });

            $('#btnExcelDetay').on('click', excelDetayIndir);
            $('#btnExcelOzet').on('click', excelOzetIndir);

            $('#ajandaBody').on('click', '.ajanda-hucre:not(.bos)', function () {
                gunDetayAc($(this).data('tarih'));
            });

            ayYukle();
        });
    </script>
</body>
</html>
