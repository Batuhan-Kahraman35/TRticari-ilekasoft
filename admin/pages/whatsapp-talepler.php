<?php
/**
 * WhatsApp Talepleri
 *
 * Bot uzerinden gelen fiyat taleplerinin listesi. Talepler gruba
 * dustukten sonra takip edilmez; bu sayfa kayit ve teshis amaclidir.
 *
 * Talep tablosu bir hareket tablosudur (sinirsiz buyur), bu yuzden
 * liste server-side calisir. Ozet sayimlar tablo sorgusunun icinde
 * degil, ayri bir istekle (loadStats) cekilir.
 */

require_once __DIR__ . '/../auth.php';
require_once __DIR__ . '/../includes/PageAuth.php';
require_once __DIR__ . '/../includes/WhatsappBot.php';
requireAuth();

$user = Auth::user();
$db   = Database::getInstance();

$currentPageFile = basename($_SERVER['PHP_SELF']);
$pagePermissions = PageAuth::checkPagePermissions(
    $user['kullanici_id'],
    $user['departman_id'],
    $currentPageFile
);

if (!$pagePermissions['has_access']) {
    PageAuth::accessDenied($pagePermissions['error'] ?? 'Bu sayfaya erişim yetkiniz bulunmamaktadır.');
}

/** 5xxxxxxxxx -> 0 5xx xxx xx xx */
function telefonGoster(?string $t): string
{
    $t = (string)$t;
    if (strlen($t) !== 10) return $t;
    return '0' . substr($t, 0, 3) . ' ' . substr($t, 3, 3) . ' ' . substr($t, 6, 2) . ' ' . substr($t, 8, 2);
}

// ==========================================================
// AJAX
// ==========================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !empty($_POST['action'])) {
    header('Content-Type: application/json; charset=utf-8');

    try {
        switch ($_POST['action']) {

            // ── Ozet sayimlar ───────────────────────────────────────
            case 'stats': {
                $s = $db->fetchOne("
                    SELECT
                        COUNT(*) AS toplam,
                        SUM(CASE WHEN WhatsappTalepler_TalepTipi = 'ISLETME'  THEN 1 ELSE 0 END) AS isletme,
                        SUM(CASE WHEN WhatsappTalepler_TalepTipi = 'BIREYSEL' THEN 1 ELSE 0 END) AS bireysel,
                        SUM(CASE WHEN WhatsappTalepler_BolgeIci = 1 THEN 1 ELSE 0 END)           AS bolge_ici,
                        SUM(CASE WHEN WhatsappTalepler_GrupGonderildi = 0
                                  AND WhatsappTalepler_BolgeIci = 1     THEN 1 ELSE 0 END)       AS iletilemedi,
                        SUM(CASE WHEN CAST(OlusturmaTarihi AS DATE) = CAST(GETDATE() AS DATE)
                                 THEN 1 ELSE 0 END)                                             AS bugun
                    FROM WhatsappTalepler
                    WHERE Durum = 1
                ");

                $bekleyen = $db->fetchOne("
                    SELECT COUNT(*) AS s FROM WhatsappKonusmalar
                    WHERE Durum = 1 AND WhatsappKonusmalar_Adim <> 'TAMAM'
                ");

                echo json_encode(['success' => true, 'data' => [
                    'toplam'      => (int)($s['toplam'] ?? 0),
                    'isletme'     => (int)($s['isletme'] ?? 0),
                    'bireysel'    => (int)($s['bireysel'] ?? 0),
                    'bolge_ici'   => (int)($s['bolge_ici'] ?? 0),
                    'iletilemedi' => (int)($s['iletilemedi'] ?? 0),
                    'bugun'       => (int)($s['bugun'] ?? 0),
                    'yarim'       => (int)($bekleyen['s'] ?? 0),
                ]]);
                break;
            }

            // ── Talep listesi (server-side) ─────────────────────────
            case 'list': {
                $draw   = (int)($_POST['draw'] ?? 1);
                $start  = max(0, (int)($_POST['start'] ?? 0));
                $length = (int)($_POST['length'] ?? 25);
                if ($length < 1 || $length > 200) $length = 25;

                $whereBase  = ['t.Durum = 1'];
                $paramsBase = [];

                $wBase = implode(' AND ', $whereBase);
                $recordsTotal = (int)($db->fetchOne(
                    "SELECT COUNT(*) AS s FROM WhatsappTalepler t WHERE $wBase",
                    $paramsBase
                )['s'] ?? 0);

                $whereFilt  = $whereBase;
                $paramsFilt = $paramsBase;

                if (!empty($_POST['talep_tipi'])) {
                    $whereFilt[]  = 't.WhatsappTalepler_TalepTipi = ?';
                    $paramsFilt[] = $_POST['talep_tipi'];
                }
                if (isset($_POST['bolge_ici']) && $_POST['bolge_ici'] !== '') {
                    $whereFilt[]  = 't.WhatsappTalepler_BolgeIci = ?';
                    $paramsFilt[] = (int)$_POST['bolge_ici'];
                }
                if (isset($_POST['iletildi']) && $_POST['iletildi'] !== '') {
                    $whereFilt[]  = 't.WhatsappTalepler_GrupGonderildi = ?';
                    $paramsFilt[] = (int)$_POST['iletildi'];
                }
                if (!empty($_POST['sehir'])) {
                    $whereFilt[]  = 't.WhatsappTalepler_SehirAdi = ?';
                    $paramsFilt[] = $_POST['sehir'];
                }
                if (!empty($_POST['start_date'])) {
                    $whereFilt[]  = 't.OlusturmaTarihi >= ?';
                    $paramsFilt[] = $_POST['start_date'] . ' 00:00:00';
                }
                if (!empty($_POST['end_date'])) {
                    $whereFilt[]  = 't.OlusturmaTarihi <= ?';
                    $paramsFilt[] = $_POST['end_date'] . ' 23:59:59';
                }
                if (!empty($_POST['search_text'])) {
                    $ara = '%' . preg_replace('/\D/', '', $_POST['search_text']) . '%';
                    $whereFilt[]  = '(t.WhatsappTalepler_Telefon LIKE ? OR t.WhatsappTalepler_GorunenAd LIKE ?)';
                    $paramsFilt[] = $ara;
                    $paramsFilt[] = '%' . $_POST['search_text'] . '%';
                }

                $wFilt = implode(' AND ', $whereFilt);
                $recordsFiltered = (int)($db->fetchOne(
                    "SELECT COUNT(*) AS s FROM WhatsappTalepler t WHERE $wFilt",
                    $paramsFilt
                )['s'] ?? 0);

                // Siralama kolonu istemciden gelen degerle dogrudan sorguya girmez
                $orderMap = [
                    0 => 't.OlusturmaTarihi',
                    1 => 't.WhatsappTalepler_TalepTipi',
                    2 => 't.WhatsappTalepler_SehirAdi',
                    3 => 't.WhatsappTalepler_BolgeIci',
                    4 => 't.WhatsappTalepler_Telefon',
                    5 => 't.WhatsappTalepler_GrupGonderildi',
                ];
                $orderIdx = (int)($_POST['order'][0]['column'] ?? 0);
                $orderBy  = $orderMap[$orderIdx] ?? 't.OlusturmaTarihi';
                $orderDir = strtolower($_POST['order'][0]['dir'] ?? 'desc') === 'asc' ? 'ASC' : 'DESC';

                $data = [];
                if ($recordsFiltered > 0) {
                    $rows = $db->fetchAll("
                        WITH Sayfa AS (
                            SELECT t.WhatsappTalepler_id
                            FROM WhatsappTalepler t
                            WHERE $wFilt
                            ORDER BY $orderBy $orderDir, t.WhatsappTalepler_id DESC
                            OFFSET $start ROWS FETCH NEXT $length ROWS ONLY
                        )
                        SELECT
                            t.WhatsappTalepler_id             AS id,
                            t.WhatsappTalepler_KonusmaId      AS konusma_id,
                            t.WhatsappTalepler_TalepTipi      AS talep_tipi,
                            t.WhatsappTalepler_GorunenAd      AS gorunen_ad,
                            t.WhatsappTalepler_Telefon        AS telefon,
                            t.WhatsappTalepler_SehirAdi       AS sehir,
                            t.WhatsappTalepler_IlceAdi        AS ilce,
                            t.WhatsappTalepler_BolgeIci       AS bolge_ici,
                            t.WhatsappTalepler_GrupGonderildi AS iletildi,
                            t.WhatsappTalepler_YonlendirmeHedefi AS hedef,
                            t.WhatsappTalepler_GrupHata       AS hata,
                            CONVERT(VARCHAR(19), t.OlusturmaTarihi, 120) AS tarih
                        FROM Sayfa s
                        INNER JOIN WhatsappTalepler t
                                ON t.WhatsappTalepler_id = s.WhatsappTalepler_id
                        ORDER BY $orderBy $orderDir, t.WhatsappTalepler_id DESC
                    ", $paramsFilt);

                    foreach ($rows as $r) {
                        $r['telefon_goster'] = telefonGoster($r['telefon']);
                        $data[] = $r;
                    }
                }

                echo json_encode([
                    'draw'            => $draw,
                    'recordsTotal'    => $recordsTotal,
                    'recordsFiltered' => $recordsFiltered,
                    'data'            => $data,
                ], JSON_UNESCAPED_UNICODE);
                break;
            }

            // ── Konusma listesi (server-side) ───────────────────────
            case 'konusma_list': {
                $draw   = (int)($_POST['draw'] ?? 1);
                $start  = max(0, (int)($_POST['start'] ?? 0));
                $length = (int)($_POST['length'] ?? 25);
                if ($length < 1 || $length > 200) $length = 25;

                $whereBase  = ['k.Durum = 1'];
                $paramsBase = [];
                $wBase = implode(' AND ', $whereBase);

                $recordsTotal = (int)($db->fetchOne(
                    "SELECT COUNT(*) AS s FROM WhatsappKonusmalar k WHERE $wBase",
                    $paramsBase
                )['s'] ?? 0);

                $whereFilt  = $whereBase;
                $paramsFilt = $paramsBase;

                // Yalniz yarim kalanlar (talebe donusmemis konusmalar)
                if (!empty($_POST['sadece_yarim'])) {
                    $whereFilt[] = "k.WhatsappKonusmalar_Adim <> 'TAMAM'";
                }
                if (!empty($_POST['adim'])) {
                    $whereFilt[]  = 'k.WhatsappKonusmalar_Adim = ?';
                    $paramsFilt[] = $_POST['adim'];
                }
                if (!empty($_POST['start_date'])) {
                    $whereFilt[]  = 'k.WhatsappKonusmalar_SonMesajTarihi >= ?';
                    $paramsFilt[] = $_POST['start_date'] . ' 00:00:00';
                }
                if (!empty($_POST['end_date'])) {
                    $whereFilt[]  = 'k.WhatsappKonusmalar_SonMesajTarihi <= ?';
                    $paramsFilt[] = $_POST['end_date'] . ' 23:59:59';
                }
                if (!empty($_POST['search_text'])) {
                    $whereFilt[]  = '(k.WhatsappKonusmalar_Telefon LIKE ? OR k.WhatsappKonusmalar_GorunenAd LIKE ?)';
                    $paramsFilt[] = '%' . preg_replace('/\D/', '', $_POST['search_text']) . '%';
                    $paramsFilt[] = '%' . $_POST['search_text'] . '%';
                }

                $wFilt = implode(' AND ', $whereFilt);
                $recordsFiltered = (int)($db->fetchOne(
                    "SELECT COUNT(*) AS s FROM WhatsappKonusmalar k WHERE $wFilt",
                    $paramsFilt
                )['s'] ?? 0);

                $orderMap = [
                    0 => 'k.WhatsappKonusmalar_SonMesajTarihi',
                    1 => 'k.WhatsappKonusmalar_Telefon',
                    2 => 'k.WhatsappKonusmalar_Adim',
                    3 => 'k.WhatsappKonusmalar_TalepTipi',
                    4 => 'k.WhatsappKonusmalar_SehirMetin',
                ];
                $orderIdx = (int)($_POST['order'][0]['column'] ?? 0);
                $orderBy  = $orderMap[$orderIdx] ?? 'k.WhatsappKonusmalar_SonMesajTarihi';
                $orderDir = strtolower($_POST['order'][0]['dir'] ?? 'desc') === 'asc' ? 'ASC' : 'DESC';

                $data = [];
                if ($recordsFiltered > 0) {
                    // Mesaj sayimi agir oldugu icin yalniz sayfanin satirlarina uygulanir
                    $rows = $db->fetchAll("
                        WITH Sayfa AS (
                            SELECT k.WhatsappKonusmalar_id
                            FROM WhatsappKonusmalar k
                            WHERE $wFilt
                            ORDER BY $orderBy $orderDir, k.WhatsappKonusmalar_id DESC
                            OFFSET $start ROWS FETCH NEXT $length ROWS ONLY
                        )
                        SELECT
                            k.WhatsappKonusmalar_id        AS id,
                            k.WhatsappKonusmalar_Telefon   AS telefon,
                            k.WhatsappKonusmalar_GorunenAd AS gorunen_ad,
                            k.WhatsappKonusmalar_Adim      AS adim,
                            k.WhatsappKonusmalar_TalepTipi AS talep_tipi,
                            k.WhatsappKonusmalar_SehirMetin AS sehir,
                            k.WhatsappKonusmalar_IlceMetin AS ilce,
                            k.WhatsappKonusmalar_BotAktif  AS bot_aktif,
                            k.WhatsappKonusmalar_HataSayaci AS hata_sayaci,
                            CONVERT(VARCHAR(19), k.WhatsappKonusmalar_SonMesajTarihi, 120) AS son_mesaj,
                            m.mesaj_sayisi,
                            t.WhatsappTalepler_id          AS talep_id
                        FROM Sayfa s
                        INNER JOIN WhatsappKonusmalar k
                                ON k.WhatsappKonusmalar_id = s.WhatsappKonusmalar_id
                        OUTER APPLY (
                            SELECT COUNT(*) AS mesaj_sayisi
                            FROM WhatsappMesajlar wm
                            WHERE wm.WhatsappMesajlar_KonusmaId = k.WhatsappKonusmalar_id
                        ) m
                        OUTER APPLY (
                            SELECT TOP 1 wt.WhatsappTalepler_id
                            FROM WhatsappTalepler wt
                            WHERE wt.WhatsappTalepler_KonusmaId = k.WhatsappKonusmalar_id
                            ORDER BY wt.WhatsappTalepler_id DESC
                        ) t
                        ORDER BY $orderBy $orderDir, k.WhatsappKonusmalar_id DESC
                    ", $paramsFilt);

                    foreach ($rows as $r) {
                        $r['telefon_goster'] = telefonGoster($r['telefon']);
                        $data[] = $r;
                    }
                }

                echo json_encode([
                    'draw'            => $draw,
                    'recordsTotal'    => $recordsTotal,
                    'recordsFiltered' => $recordsFiltered,
                    'data'            => $data,
                ], JSON_UNESCAPED_UNICODE);
                break;
            }

            // ── Konusmayi bastan baslat ─────────────────────────────
            case 'konusma_baslat': {
                if (!$pagePermissions['can_edit']) throw new Exception('Yetkiniz yok.');

                $konusmaId = (int)($_POST['konusma_id'] ?? 0);
                $k = $db->fetchOne("
                    SELECT WhatsappKonusmalar_Jid, WhatsappKonusmalar_GorunenAd
                    FROM WhatsappKonusmalar WHERE WhatsappKonusmalar_id = ?
                ", [$konusmaId]);
                if (!$k) throw new Exception('Konuşma bulunamadı.');

                // Numara yerine adres gonderilir; kullanici adi (LID) ile
                // yazan musterilerin numarasi olmayabilir.
                $bot   = new WhatsappBot($db);
                $sonuc = $bot->konusmaBaslat(
                    (string)$k['WhatsappKonusmalar_Jid'],
                    (string)$k['WhatsappKonusmalar_GorunenAd']
                );

                echo json_encode([
                    'success' => strpos($sonuc, 'gonderildi') !== false,
                    'message' => $sonuc,
                ], JSON_UNESCAPED_UNICODE);
                break;
            }

            // ── Konusma gecmisi ─────────────────────────────────────
            case 'konusma': {
                $konusmaId = (int)($_POST['konusma_id'] ?? 0);
                if ($konusmaId < 1) throw new Exception('Konuşma bulunamadı.');

                $konusma = $db->fetchOne("
                    SELECT WhatsappKonusmalar_id, WhatsappKonusmalar_Telefon,
                           WhatsappKonusmalar_GorunenAd, WhatsappKonusmalar_Adim,
                           WhatsappKonusmalar_TalepTipi, WhatsappKonusmalar_BotAktif,
                           WhatsappKonusmalar_SehirMetin, WhatsappKonusmalar_IlceMetin,
                           CONVERT(VARCHAR(19), OlusturmaTarihi, 120)              AS baslangic,
                           CONVERT(VARCHAR(19), WhatsappKonusmalar_SonMesajTarihi, 120) AS son_mesaj
                    FROM WhatsappKonusmalar WHERE WhatsappKonusmalar_id = ?
                ", [$konusmaId]);

                if (!$konusma) throw new Exception('Konuşma bulunamadı.');

                $mesajlar = $db->fetchAll("
                    SELECT WhatsappMesajlar_Yon AS yon, WhatsappMesajlar_Icerik AS icerik,
                           WhatsappMesajlar_Basarili AS basarili, WhatsappMesajlar_Hata AS hata,
                           WhatsappMesajlar_Jid AS jid,
                           CONVERT(VARCHAR(19), OlusturmaTarihi, 120) AS tarih
                    FROM WhatsappMesajlar
                    WHERE WhatsappMesajlar_KonusmaId = ?
                    ORDER BY WhatsappMesajlar_id
                ", [$konusmaId]);

                $konusma['telefon_goster'] = telefonGoster($konusma['WhatsappKonusmalar_Telefon']);

                echo json_encode([
                    'success'  => true,
                    'konusma'  => $konusma,
                    'mesajlar' => $mesajlar,
                ], JSON_UNESCAPED_UNICODE);
                break;
            }

            // ── Iletilemeyen talebi yeniden gonder ──────────────────
            case 'yeniden_ilet': {
                if (!$pagePermissions['can_edit']) throw new Exception('Yetkiniz yok.');

                $talepId = (int)($_POST['talep_id'] ?? 0);
                $talep = $db->fetchOne("
                    SELECT * FROM WhatsappTalepler WHERE WhatsappTalepler_id = ?
                ", [$talepId]);
                if (!$talep) throw new Exception('Talep bulunamadı.');
                if ($talep['WhatsappTalepler_GrupGonderildi']) {
                    throw new Exception('Bu talep zaten iletilmiş.');
                }

                $bot   = new WhatsappBot($db);
                $mesaj = (string)$talep['WhatsappTalepler_GrupMesaji'];
                if ($mesaj === '') throw new Exception('İletilecek mesaj metni yok.');

                $bireysel = $talep['WhatsappTalepler_TalepTipi'] === 'BIREYSEL';
                $hedef    = $bireysel
                    ? $bot->ayar('bireysel_yonlendirme_jid')
                    : $bot->ayar('grup_jid');

                if ($hedef === '') throw new Exception('Hedef tanımlı değil.');

                $sonuc = $bot->gonder($hedef, $mesaj, $bireysel ? 'YETKILI' : 'GRUP');

                $db->update('WhatsappTalepler', [
                    'WhatsappTalepler_GrupGonderildi' => $sonuc['success'] ? 1 : 0,
                    'WhatsappTalepler_GrupTarihi'     => date('Y-m-d H:i:s'),
                    'WhatsappTalepler_GrupHata'       => $sonuc['success'] ? null : mb_substr($sonuc['message'], 0, 500),
                    'GuncelleyenKullanici'            => (int)$user['kullanici_id'],
                    'GuncellemeTarihi'                => date('Y-m-d H:i:s'),
                ], ['WhatsappTalepler_id' => $talepId]);

                echo json_encode([
                    'success' => $sonuc['success'],
                    'message' => $sonuc['success'] ? 'Talep yeniden iletildi.' : $sonuc['message'],
                ], JSON_UNESCAPED_UNICODE);
                break;
            }

            default:
                throw new Exception('Geçersiz işlem.');
        }
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'message' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
    }
    exit;
}

// ==========================================================
// Sayfa verileri
// ==========================================================
$pageInfo = $db->fetchOne("
    SELECT s.sayfalar_sayfa_adi, s.sayfalar_aciklama, m.menuler_menu_adi AS menu_adi
    FROM Menu_Sayfalar s
    LEFT JOIN Menuler m ON s.sayfalar_menu_id = m.menuler_id
    WHERE s.sayfalar_sayfa_url LIKE ? AND s.sayfalar_durum = 1
", ['%' . $currentPageFile]);

$pageTitle       = $pageInfo['sayfalar_sayfa_adi'] ?? 'WhatsApp Talepleri';
$pageDescription = $pageInfo['sayfalar_aciklama'] ?? 'Bot üzerinden gelen fiyat talepleri';
$menuAdi         = $pageInfo['menu_adi'] ?? null;

$siteAyarlari = $db->fetchOne("SELECT TOP 1 site_ayarlari_site_title FROM dbo.tanim_site_ayarlari ORDER BY site_ayarlari_id DESC");
$siteTitle    = $siteAyarlari['site_ayarlari_site_title'] ?? 'Örnek Yazılım Portal';

// Filtre icin sehir listesi: taleplerde gecen sehirler
$sehirler = $db->fetchAll("
    SELECT DISTINCT WhatsappTalepler_SehirAdi AS sehir
    FROM WhatsappTalepler
    WHERE Durum = 1 AND WhatsappTalepler_SehirAdi IS NOT NULL
    ORDER BY WhatsappTalepler_SehirAdi
");
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
    <link rel="stylesheet" href="/admin/assets/css/custom.css">
    <style>
        .info-box { transition: transform .2s; }
        .info-box:hover { transform: translateY(-5px); box-shadow: 0 4px 6px rgba(0,0,0,.1); }
        .badge { font-size: .75rem; padding: .35em .65em; }

        /* Konusma balonlari */
        .sohbet { max-height: 60vh; overflow-y: auto; padding: .5rem; background: var(--bs-tertiary-bg); border-radius: .5rem; }
        .balon { max-width: 78%; padding: .5rem .75rem; border-radius: .75rem; margin-bottom: .5rem; white-space: pre-wrap; word-break: break-word; }
        .balon-gelen { background: var(--bs-body-bg); border: 1px solid var(--bs-border-color); margin-right: auto; }
        .balon-giden { background: #d9fdd3; color: #111; margin-left: auto; }
        [data-bs-theme="dark"] .balon-giden { background: #005c4b; color: #e9edef; }
        .balon-zaman { font-size: .7rem; opacity: .65; display: block; margin-top: .25rem; }
        .balon-hata { border-color: var(--bs-danger); }
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
                        <?php if ($pageDescription): ?>
                            <small class="text-muted"><?= htmlspecialchars($pageDescription) ?></small>
                        <?php endif; ?>
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

                <!-- InfoBox -->
                <div class="row mb-3">
                    <div class="col-12 col-sm-6 col-md-3">
                        <div class="info-box">
                            <span class="info-box-icon text-bg-secondary shadow-sm"><i class="bi bi-collection"></i></span>
                            <div class="info-box-content">
                                <span class="info-box-text">Toplam Talep</span>
                                <span class="info-box-number" id="stat-toplam">0</span>
                                <span class="info-box-text small text-muted">Bugün: <span id="stat-bugun">0</span></span>
                            </div>
                        </div>
                    </div>
                    <div class="col-12 col-sm-6 col-md-3">
                        <div class="info-box">
                            <span class="info-box-icon text-bg-success shadow-sm"><i class="bi bi-geo-alt"></i></span>
                            <div class="info-box-content">
                                <span class="info-box-text">Bölge İçi</span>
                                <span class="info-box-number" id="stat-bolge-ici">0</span>
                                <span class="info-box-text small text-muted">İşletme: <span id="stat-isletme">0</span></span>
                            </div>
                        </div>
                    </div>
                    <div class="col-12 col-sm-6 col-md-3">
                        <div class="info-box">
                            <span class="info-box-icon text-bg-info shadow-sm"><i class="bi bi-house"></i></span>
                            <div class="info-box-content">
                                <span class="info-box-text">Bireysel</span>
                                <span class="info-box-number" id="stat-bireysel">0</span>
                                <span class="info-box-text small text-muted">Yarım konuşma: <span id="stat-yarim">0</span></span>
                            </div>
                        </div>
                    </div>
                    <div class="col-12 col-sm-6 col-md-3">
                        <div class="info-box">
                            <span class="info-box-icon text-bg-danger shadow-sm"><i class="bi bi-exclamation-triangle"></i></span>
                            <div class="info-box-content">
                                <span class="info-box-text">İletilemeyen</span>
                                <span class="info-box-number" id="stat-iletilemedi">0</span>
                                <span class="info-box-text small text-muted">Gruba düşmemiş</span>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Filtre -->
                <div class="card card-primary card-outline mb-3 collapse" id="filterCard">
                    <div class="card-header">
                        <h3 class="card-title"><i class="bi bi-funnel"></i> Filtreler</h3>
                    </div>
                    <div class="card-body">
                        <div class="row g-3">
                            <div class="col-md-2">
                                <label class="form-label">Başlangıç Tarihi</label>
                                <input type="date" class="form-control" id="filter_start_date">
                            </div>
                            <div class="col-md-2">
                                <label class="form-label">Bitiş Tarihi</label>
                                <input type="date" class="form-control" id="filter_end_date">
                            </div>
                            <div class="col-md-2">
                                <label class="form-label">Talep Tipi</label>
                                <select class="form-select select2" id="filter_talep_tipi">
                                    <option value="">Tümü</option>
                                    <option value="ISLETME">İşletme</option>
                                    <option value="BIREYSEL">Bireysel</option>
                                </select>
                            </div>
                            <div class="col-md-2">
                                <label class="form-label">Bölge</label>
                                <select class="form-select select2" id="filter_bolge_ici">
                                    <option value="">Tümü</option>
                                    <option value="1">Bölge içi</option>
                                    <option value="0">Bölge dışı</option>
                                </select>
                            </div>
                            <div class="col-md-2">
                                <label class="form-label">Şehir</label>
                                <select class="form-select select2" id="filter_sehir">
                                    <option value="">Tümü</option>
                                    <?php foreach ($sehirler as $s): ?>
                                        <option value="<?= htmlspecialchars($s['sehir']) ?>"><?= htmlspecialchars($s['sehir']) ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-md-2">
                                <label class="form-label">İletim</label>
                                <select class="form-select select2" id="filter_iletildi">
                                    <option value="">Tümü</option>
                                    <option value="1">İletildi</option>
                                    <option value="0">İletilemedi</option>
                                </select>
                            </div>
                            <div class="col-md-4">
                                <label class="form-label">Arama</label>
                                <input type="text" class="form-control" id="filter_search" placeholder="Telefon veya isim...">
                            </div>
                            <div class="col-md-8 d-flex align-items-end gap-2">
                                <button class="btn btn-primary" id="btnFiltrele"><i class="bi bi-search"></i> Filtrele</button>
                                <button class="btn btn-outline-secondary" id="btnTemizle"><i class="bi bi-x-circle"></i> Temizle</button>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Sekmeler -->
                <div class="card">
                    <div class="card-header d-flex justify-content-between align-items-center flex-wrap gap-2">
                        <ul class="nav nav-tabs card-header-tabs" id="sekmeler">
                            <li class="nav-item">
                                <a class="nav-link active" data-bs-toggle="tab" href="#tabTalepler">
                                    <i class="bi bi-clipboard-check"></i> Talepler
                                </a>
                            </li>
                            <li class="nav-item">
                                <a class="nav-link" data-bs-toggle="tab" href="#tabKonusmalar">
                                    <i class="bi bi-chat-left-text"></i> Konuşmalar
                                    <span class="badge bg-warning text-dark ms-1" id="badge-yarim">0</span>
                                </a>
                            </li>
                        </ul>
                        <div class="d-flex gap-2">
                            <button class="btn btn-sm btn-outline-primary" type="button"
                                    data-bs-toggle="collapse" data-bs-target="#filterCard">
                                <i class="bi bi-funnel"></i> Filtreler
                            </button>
                            <button class="btn btn-sm btn-outline-secondary" id="btnYenile">
                                <i class="bi bi-arrow-clockwise"></i> Yenile
                            </button>
                        </div>
                    </div>
                    <div class="card-body tab-content">

                        <!-- Talepler -->
                        <div class="tab-pane fade show active" id="tabTalepler">
                            <table class="table table-hover table-striped align-middle w-100" id="tblTalepler">
                                <thead>
                                    <tr>
                                        <th>Tarih</th>
                                        <th>Tip</th>
                                        <th>Bölge</th>
                                        <th>Durum</th>
                                        <th>Telefon</th>
                                        <th>İletim</th>
                                        <th class="text-center">İşlem</th>
                                    </tr>
                                </thead>
                                <tbody></tbody>
                            </table>
                        </div>

                        <!-- Konusmalar -->
                        <div class="tab-pane fade" id="tabKonusmalar">
                            <div class="alert alert-info d-flex align-items-center gap-2 py-2">
                                <i class="bi bi-info-circle"></i>
                                <div class="flex-grow-1 small">
                                    Tamamlanmamış konuşmalar da müşteridir: numaraları elimizdedir,
                                    temsilci doğrudan arayabilir.
                                </div>
                                <div class="form-check form-switch mb-0">
                                    <input class="form-check-input" type="checkbox" role="switch"
                                           id="swSadeceYarim" checked>
                                    <label class="form-check-label small" for="swSadeceYarim">Sadece yarım kalanlar</label>
                                </div>
                            </div>
                            <table class="table table-hover table-striped align-middle w-100" id="tblKonusmalar">
                                <thead>
                                    <tr>
                                        <th>Son Mesaj</th>
                                        <th>Telefon</th>
                                        <th>Kaldığı Adım</th>
                                        <th>Tip</th>
                                        <th>Bölge</th>
                                        <th class="text-center">İşlem</th>
                                    </tr>
                                </thead>
                                <tbody></tbody>
                            </table>
                        </div>

                    </div>
                </div>

            </div>
        </div>
    </main>

    <?php include __DIR__ . '/../includes/footer.php'; ?>
</div>

<!-- Konusma detayi -->
<div class="modal fade" id="konusmaModal" tabindex="-1">
    <div class="modal-dialog modal-lg modal-dialog-scrollable">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title"><i class="bi bi-chat-dots"></i> Konuşma Geçmişi</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <div id="konusmaOzet" class="mb-3"></div>
                <div class="sohbet" id="konusmaSohbet"></div>
            </div>
        </div>
    </div>
</div>

<script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/@popperjs/core@2.11.8/dist/umd/popper.min.js" crossorigin="anonymous"></script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.7/dist/js/bootstrap.min.js" crossorigin="anonymous"></script>
<script src="/admin/assets/js/adminlte.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11/dist/sweetalert2.all.min.js"></script>
<script src="https://cdn.datatables.net/1.13.7/js/jquery.dataTables.min.js"></script>
<script src="https://cdn.datatables.net/1.13.7/js/dataTables.bootstrap5.min.js"></script>
<script src="/admin/assets/js/custom.js"></script>
<script>
const esc = s => $('<div>').text(s ?? '').html();

const tarihSaatFormat = d => {
    if (!d) return '-';
    const [t, s] = String(d).split(' ');
    const [y, a, g] = t.split('-');
    return `${g}.${a}.${y}` + (s ? ' ' + s.substring(0, 5) : '');
};

let currentFilters = {};

function filtreleriTopla() {
    return {
        start_date : $('#filter_start_date').val(),
        end_date   : $('#filter_end_date').val(),
        talep_tipi : $('#filter_talep_tipi').val(),
        bolge_ici  : $('#filter_bolge_ici').val(),
        sehir      : $('#filter_sehir').val(),
        iletildi   : $('#filter_iletildi').val(),
        search_text: $('#filter_search').val()
    };
}

function loadStats() {
    $.post('', { action: 'stats' }, r => {
        if (!r.success) return;
        $('#stat-toplam').text(r.data.toplam);
        $('#stat-bugun').text(r.data.bugun);
        $('#stat-bolge-ici').text(r.data.bolge_ici);
        $('#stat-isletme').text(r.data.isletme);
        $('#stat-bireysel').text(r.data.bireysel);
        $('#stat-yarim').text(r.data.yarim);
        $('#stat-iletilemedi').text(r.data.iletilemedi);
        $('#badge-yarim').text(r.data.yarim);
    }, 'json');
}

/** Bot adimlarinin okunabilir karsiliklari */
const ADIM_ETIKET = {
    YENI            : { ad: 'Karşılama bekliyor', renk: 'secondary' },
    TALEP_TIPI      : { ad: 'Talep tipi soruldu', renk: 'warning' },
    SEHIR           : { ad: 'Şehir soruldu',      renk: 'warning' },
    ILCE            : { ad: 'İlçe soruldu',       renk: 'warning' },
    TELEFON         : { ad: 'Telefon soruldu',    renk: 'info'    },
    BIREYSEL_TELEFON: { ad: 'Telefon soruldu (bireysel)', renk: 'info' },
    TAMAM           : { ad: 'Tamamlandı',         renk: 'success' }
};

$(function () {
    $('.select2').select2({ theme: 'bootstrap-5', width: '100%' });

    const tablo = $('#tblTalepler').DataTable({
        serverSide: true,
        processing: true,
        scrollX: true,
        dom: 'lrtip',
        pageLength: 25,
        order: [[0, 'desc']],
        language: { url: '//cdn.datatables.net/plug-ins/1.13.7/i18n/tr.json' },
        ajax: {
            url: '',
            type: 'POST',
            data: d => Object.assign(d, { action: 'list' }, currentFilters)
        },
        columns: [
            { data: 'tarih', render: (d, t) => t === 'display' ? tarihSaatFormat(d) : d },
            {
                data: 'talep_tipi',
                render: (d, t) => {
                    if (t !== 'display') return d;
                    return d === 'BIREYSEL'
                        ? '<span class="badge bg-info"><i class="bi bi-house"></i> Bireysel</span>'
                        : '<span class="badge bg-primary"><i class="bi bi-shop"></i> İşletme</span>';
                }
            },
            {
                data: 'sehir',
                render: (d, t, row) => {
                    if (t !== 'display') return d;
                    if (!d) return '<span class="text-muted">-</span>';
                    return esc(d) + (row.ilce ? ' / ' + esc(row.ilce) : '');
                }
            },
            {
                data: 'bolge_ici',
                render: (d, t, row) => {
                    if (t !== 'display') return d;
                    if (row.talep_tipi === 'BIREYSEL') return '<span class="text-muted">-</span>';
                    return Number(d) === 1
                        ? '<span class="badge bg-success">Bölge içi</span>'
                        : '<span class="badge bg-warning text-dark">Bölge dışı</span>';
                }
            },
            {
                data: 'telefon_goster',
                render: (d, t, row) => {
                    if (t !== 'display') return d;
                    const ad = row.gorunen_ad ? `<br><small class="text-muted">${esc(row.gorunen_ad)}</small>` : '';
                    return `<span class="fw-semibold">${esc(d)}</span>${ad}`;
                }
            },
            {
                data: 'iletildi',
                render: (d, t, row) => {
                    if (t !== 'display') return d;
                    // Bolge disi talepler hicbir yere iletilmez, bu normaldir
                    if (row.talep_tipi === 'ISLETME' && Number(row.bolge_ici) === 0) {
                        return '<span class="text-muted">İletilmez</span>';
                    }
                    if (Number(d) === 1) {
                        const hedef = row.hedef === 'BIREYSEL_YETKILI' ? 'Yetkiliye' : 'Gruba';
                        return `<span class="badge bg-success"><i class="bi bi-check2"></i> ${hedef}</span>`;
                    }
                    const hata = row.hata ? ` title="${esc(row.hata)}"` : '';
                    return `<span class="badge bg-danger"${hata}><i class="bi bi-x"></i> İletilemedi</span>`;
                }
            },
            {
                data: null,
                orderable: false,
                className: 'text-center',
                render: (d, t, row) => {
                    let html = `<button class="btn btn-sm btn-outline-primary btn-konusma" data-id="${row.konusma_id}" title="Konuşma geçmişi">
                                    <i class="bi bi-chat-dots"></i>
                                </button>`;
                    const iletilmeli = !(row.talep_tipi === 'ISLETME' && Number(row.bolge_ici) === 0);
                    if (iletilmeli && Number(row.iletildi) === 0) {
                        html += ` <button class="btn btn-sm btn-outline-danger btn-yeniden" data-id="${row.id}" title="Yeniden ilet">
                                     <i class="bi bi-arrow-repeat"></i>
                                  </button>`;
                    }
                    return html;
                }
            }
        ],
        drawCallback: () => loadStats()
    });

    // ── Konusmalar tablosu ──────────────────────────────────────
    const konusmaTablo = $('#tblKonusmalar').DataTable({
        serverSide: true,
        processing: true,
        scrollX: true,
        dom: 'lrtip',
        pageLength: 25,
        order: [[0, 'desc']],
        language: { url: '//cdn.datatables.net/plug-ins/1.13.7/i18n/tr.json' },
        ajax: {
            url: '',
            type: 'POST',
            data: d => Object.assign(d, {
                action: 'konusma_list',
                sadece_yarim: $('#swSadeceYarim').is(':checked') ? 1 : ''
            }, currentFilters)
        },
        columns: [
            { data: 'son_mesaj', render: (d, t) => t === 'display' ? tarihSaatFormat(d) : d },
            {
                data: 'telefon_goster',
                render: (d, t, row) => {
                    if (t !== 'display') return d;
                    const ad = row.gorunen_ad ? `<br><small class="text-muted">${esc(row.gorunen_ad)}</small>` : '';
                    // Kullanici adi (LID) ile yazanlarin numarasi olmayabilir
                    const kimlik = d
                        ? `<span class="fw-semibold">${esc(d)}</span>`
                        : '<span class="badge bg-secondary" title="Kullanıcı adıyla yazmış, numarası görünmüyor">numara yok</span>';
                    return kimlik + ad;
                }
            },
            {
                data: 'adim',
                render: (d, t, row) => {
                    if (t !== 'display') return d;
                    const e = ADIM_ETIKET[d] || { ad: d, renk: 'secondary' };
                    let html = `<span class="badge bg-${e.renk}">${esc(e.ad)}</span>`;
                    if (Number(row.bot_aktif) === 0) {
                        html += ' <span class="badge bg-dark" title="Bot bu konuşmada durdu">bot pasif</span>';
                    }
                    if (Number(row.hata_sayaci) > 0) {
                        html += ` <span class="badge bg-danger" title="Anlaşılmayan yanıt sayısı">${row.hata_sayaci} hata</span>`;
                    }
                    return html;
                }
            },
            {
                data: 'talep_tipi',
                render: (d, t) => {
                    if (t !== 'display') return d;
                    if (!d) return '<span class="text-muted">-</span>';
                    return d === 'BIREYSEL'
                        ? '<span class="badge bg-info"><i class="bi bi-house"></i> Bireysel</span>'
                        : '<span class="badge bg-primary"><i class="bi bi-shop"></i> İşletme</span>';
                }
            },
            {
                data: 'sehir',
                render: (d, t, row) => {
                    if (t !== 'display') return d;
                    if (!d) return '<span class="text-muted">-</span>';
                    return esc(d) + (row.ilce ? ' / ' + esc(row.ilce) : '');
                }
            },
            {
                data: null,
                orderable: false,
                className: 'text-center',
                render: (d, t, row) => {
                    let html = `<button class="btn btn-sm btn-outline-primary btn-konusma" data-id="${row.id}" title="Konuşma geçmişi">
                                    <i class="bi bi-chat-dots"></i>
                                </button>`;
                    // Numarasi olmayan (kullanici adiyla yazan) musteri icin
                    // wa.me baglantisi kurulamaz
                    if (row.telefon) {
                        html += ` <a class="btn btn-sm btn-outline-success" href="https://wa.me/90${row.telefon}" target="_blank" title="WhatsApp'ta aç">
                                     <i class="bi bi-whatsapp"></i>
                                  </a>`;
                    }
                    if (row.adim !== 'TAMAM') {
                        html += ` <button class="btn btn-sm btn-outline-warning btn-yeniden-baslat" data-id="${row.id}" title="Botu baştan başlat">
                                     <i class="bi bi-arrow-counterclockwise"></i>
                                  </button>`;
                    }
                    return html;
                }
            }
        ]
    });

    $('#swSadeceYarim').on('change', () => konusmaTablo.ajax.reload());

    // Sekme acildiginda tablo genisligini duzelt
    $('a[data-bs-toggle="tab"]').on('shown.bs.tab', function () {
        if ($(this).attr('href') === '#tabKonusmalar') konusmaTablo.columns.adjust();
        else tablo.columns.adjust();
    });

    function aktifTablo() {
        return $('#tabKonusmalar').hasClass('active') ? konusmaTablo : tablo;
    }

    $('#btnFiltrele').on('click', () => { currentFilters = filtreleriTopla(); aktifTablo().ajax.reload(); });
    $('#btnYenile').on('click', () => { aktifTablo().ajax.reload(null, false); loadStats(); });
    $('#filter_search').on('keypress', e => { if (e.which === 13) $('#btnFiltrele').click(); });

    $('#btnTemizle').on('click', () => {
        $('#filter_start_date, #filter_end_date, #filter_search').val('');
        $('#filter_talep_tipi, #filter_bolge_ici, #filter_sehir, #filter_iletildi').val('').trigger('change');
        currentFilters = {};
        aktifTablo().ajax.reload();
    });

    // Botu bastan baslat
    $('#tblKonusmalar').on('click', '.btn-yeniden-baslat', function () {
        const id = $(this).data('id');
        Swal.fire({
            title: 'Bot baştan başlatılsın mı?',
            text: 'Müşteriye karşılama mesajı ve talep tipi sorusu yeniden gönderilecek.',
            icon: 'question',
            showCancelButton: true,
            confirmButtonText: 'Gönder',
            cancelButtonText: 'Vazgeç'
        }).then(s => {
            if (!s.isConfirmed) return;
            $.post('', { action: 'konusma_baslat', konusma_id: id }, r => {
                Swal.fire({
                    icon: r.success ? 'success' : 'error',
                    title: r.success ? 'Gönderildi' : 'Hata',
                    text: r.message,
                    timer: r.success ? 2000 : undefined,
                    showConfirmButton: !r.success
                });
                if (r.success) konusmaTablo.ajax.reload(null, false);
            }, 'json');
        });
    });

    // Konusma gecmisi
    $('#tblTalepler, #tblKonusmalar').on('click', '.btn-konusma', function () {
        const id = $(this).data('id');
        $('#konusmaOzet').html('<div class="text-center py-3"><div class="spinner-border"></div></div>');
        $('#konusmaSohbet').empty();
        new bootstrap.Modal('#konusmaModal').show();

        $.post('', { action: 'konusma', konusma_id: id }, r => {
            if (!r.success) {
                $('#konusmaOzet').html(`<div class="alert alert-danger mb-0">${esc(r.message)}</div>`);
                return;
            }

            const k = r.konusma;
            const bot = Number(k.WhatsappKonusmalar_BotAktif) === 1
                ? '<span class="badge bg-success">Bot aktif</span>'
                : '<span class="badge bg-secondary">Bot pasif</span>';

            $('#konusmaOzet').html(`
                <div class="row g-2">
                    <div class="col-md-4"><small class="text-muted d-block">Telefon</small>
                        <span class="fw-semibold">${esc(k.telefon_goster)}</span></div>
                    <div class="col-md-4"><small class="text-muted d-block">İsim</small>
                        ${esc(k.WhatsappKonusmalar_GorunenAd || '-')}</div>
                    <div class="col-md-4"><small class="text-muted d-block">Durum</small>
                        <span class="badge bg-dark">${esc(k.WhatsappKonusmalar_Adim)}</span> ${bot}</div>
                    <div class="col-md-4"><small class="text-muted d-block">Bölge</small>
                        ${esc(k.WhatsappKonusmalar_SehirMetin || '-')}${k.WhatsappKonusmalar_IlceMetin ? ' / ' + esc(k.WhatsappKonusmalar_IlceMetin) : ''}</div>
                    <div class="col-md-4"><small class="text-muted d-block">Başlangıç</small>
                        ${tarihSaatFormat(k.baslangic)}</div>
                    <div class="col-md-4"><small class="text-muted d-block">Son Mesaj</small>
                        ${tarihSaatFormat(k.son_mesaj)}</div>
                </div><hr>
            `);

            if (!r.mesajlar.length) {
                $('#konusmaSohbet').html('<div class="text-muted text-center py-3">Mesaj kaydı yok.</div>');
                return;
            }

            const html = r.mesajlar.map(m => {
                const gelen = m.yon === 'GELEN';
                const sinif = gelen ? 'balon balon-gelen' : 'balon balon-giden';
                const hataSinif = Number(m.basarili) === 0 ? ' balon-hata' : '';
                // Bireysel yetkiliye giden mesajlarda numara gizlidir
                const hedef = (!gelen && m.jid === 'BIREYSEL_YETKILI')
                    ? '<span class="badge bg-info ms-1">yetkiliye</span>' : '';
                const hata = m.hata ? `<div class="text-danger small mt-1">${esc(m.hata)}</div>` : '';
                return `<div class="d-flex"><div class="${sinif}${hataSinif}">${esc(m.icerik || '(metin yok)')}
                            ${hata}
                            <span class="balon-zaman">${tarihSaatFormat(m.tarih)}${hedef}</span>
                        </div></div>`;
            }).join('');

            $('#konusmaSohbet').html(html);
            $('#konusmaSohbet').scrollTop($('#konusmaSohbet')[0].scrollHeight);
        }, 'json');
    });

    // Yeniden ilet
    $('#tblTalepler').on('click', '.btn-yeniden', function () {
        const id = $(this).data('id');
        Swal.fire({
            title: 'Yeniden iletilsin mi?',
            text: 'Talep satış grubuna (bireyselse yetkiliye) tekrar gönderilecek.',
            icon: 'question',
            showCancelButton: true,
            confirmButtonText: 'Gönder',
            cancelButtonText: 'Vazgeç'
        }).then(s => {
            if (!s.isConfirmed) return;
            $.post('', { action: 'yeniden_ilet', talep_id: id }, r => {
                Swal.fire({
                    icon: r.success ? 'success' : 'error',
                    title: r.success ? 'İletildi' : 'Hata',
                    text: r.message,
                    timer: r.success ? 2000 : undefined,
                    showConfirmButton: !r.success
                });
                if (r.success) tablo.ajax.reload(null, false);
            }, 'json');
        });
    });

    loadStats();
});
</script>
</body>
</html>
