<?php
/**
 * Cagri Bildirimleri
 *
 * Bot hattina gelen cagrilarin ve WhatsApp gruplarina iletimlerinin
 * listesi. Cagri bildirimi ve WhatsApp lead botu buradan acilip
 * kapatilir (WhatsappBotAyarlari).
 *
 * CagriBildirimleri bir hareket tablosudur (sinirsiz buyur), bu yuzden
 * liste server-side calisir. Ozet sayimlar ayri istekle (loadStats) cekilir.
 *
 * Durum, kayittaki alanlardan turetilir:
 *   Gonderildi = 1               -> Iletildi
 *   Gonderildi = 0, Mesaj var    -> Hata (mesaj hazirlandi, gonderilemedi)
 *   Gonderildi = 0, Mesaj yok    -> Atlandi (kayitsiz numara, grupsuz sehir, tekrar arama...)
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

/** Sayfadan acilip kapatilabilen ayarlar (whitelist) */
const ANAHTAR_AYARLAR = ['cagri_bildirim_aktif', 'bot_aktif'];

/** 5xxxxxxxxx -> 0 5xx xxx xx xx */
function telefonGoster(?string $t): string
{
    $t = (string)$t;
    if (strlen($t) !== 10) return $t;
    return '0' . substr($t, 0, 3) . ' ' . substr($t, 3, 3) . ' ' . substr($t, 6, 2) . ' ' . substr($t, 8, 2);
}

/**
 * Grup JID -> grup adi. Ad, ayarin aciklamasindan alinir
 * ('Antalya Satis Haberlesme grup JID' -> 'Antalya Satis Haberlesme').
 */
function grupAdlari(Database $db): array
{
    $adlar = [];
    foreach ($db->fetchAll("
        SELECT WhatsappBotAyarlari_Deger AS jid, WhatsappBotAyarlari_Aciklama AS aciklama
        FROM WhatsappBotAyarlari
        WHERE Durum = 1 AND WhatsappBotAyarlari_Anahtar LIKE 'cagri[_]%[_]jid'
    ") as $r) {
        if ((string)$r['jid'] === '') continue;
        $adlar[$r['jid']] = trim(preg_replace('/\s*(grup\s+)?JID.*$/i', '', (string)$r['aciklama']));
    }
    return $adlar;
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
                        SUM(CASE WHEN CagriBildirimleri_CagriTarihi >= CAST(GETDATE() AS DATE) THEN 1 ELSE 0 END) AS bugun,
                        SUM(CASE WHEN CagriBildirimleri_Gonderildi = 1 THEN 1 ELSE 0 END) AS iletildi,
                        SUM(CASE WHEN CagriBildirimleri_Gonderildi = 1
                                  AND CagriBildirimleri_CagriTarihi >= CAST(GETDATE() AS DATE) THEN 1 ELSE 0 END) AS iletildi_bugun,
                        SUM(CASE WHEN CagriBildirimleri_Gonderildi = 0 AND CagriBildirimleri_Mesaj IS NULL THEN 1 ELSE 0 END) AS atlandi,
                        SUM(CASE WHEN CagriBildirimleri_CariId IS NULL THEN 1 ELSE 0 END) AS kayitsiz,
                        SUM(CASE WHEN CagriBildirimleri_Gonderildi = 0 AND CagriBildirimleri_Mesaj IS NOT NULL THEN 1 ELSE 0 END) AS hata
                    FROM CagriBildirimleri
                    WHERE Durum = 1
                ");

                echo json_encode(['success' => true, 'data' => array_map('intval', $s ?: [])]);
                break;
            }

            // ── Cagri listesi (server-side) ─────────────────────────
            case 'list': {
                $draw   = (int)($_POST['draw'] ?? 1);
                $start  = max(0, (int)($_POST['start'] ?? 0));
                $length = (int)($_POST['length'] ?? 25);
                if ($length < 1 || $length > 200) $length = 25;

                $recordsTotal = (int)($db->fetchOne(
                    "SELECT COUNT(*) AS s FROM CagriBildirimleri b WHERE b.Durum = 1"
                )['s'] ?? 0);

                $whereFilt  = ['b.Durum = 1'];
                $paramsFilt = [];

                switch ($_POST['durum'] ?? '') {
                    case 'iletildi': $whereFilt[] = 'b.CagriBildirimleri_Gonderildi = 1'; break;
                    case 'hata':     $whereFilt[] = 'b.CagriBildirimleri_Gonderildi = 0 AND b.CagriBildirimleri_Mesaj IS NOT NULL'; break;
                    case 'atlandi':  $whereFilt[] = 'b.CagriBildirimleri_Gonderildi = 0 AND b.CagriBildirimleri_Mesaj IS NULL'; break;
                    case 'kayitsiz': $whereFilt[] = 'b.CagriBildirimleri_CariId IS NULL'; break;
                }
                if (!empty($_POST['sehir_id'])) {
                    $whereFilt[]  = 'b.CagriBildirimleri_SehirId = ?';
                    $paramsFilt[] = (int)$_POST['sehir_id'];
                }
                if (!empty($_POST['start_date'])) {
                    $whereFilt[]  = 'b.CagriBildirimleri_CagriTarihi >= ?';
                    $paramsFilt[] = $_POST['start_date'] . ' 00:00:00';
                }
                if (!empty($_POST['end_date'])) {
                    $whereFilt[]  = 'b.CagriBildirimleri_CagriTarihi <= ?';
                    $paramsFilt[] = $_POST['end_date'] . ' 23:59:59';
                }
                if (!empty($_POST['search_text'])) {
                    $rakam = preg_replace('/\D/', '', $_POST['search_text']);
                    $whereFilt[]  = '(' . ($rakam !== '' ? 'b.CagriBildirimleri_Telefon LIKE ? OR ' : '') . '
                        EXISTS (SELECT 1 FROM Cari c WHERE c.cari_id = b.CagriBildirimleri_CariId
                                AND (c.cari_unvan LIKE ? OR c.cari_adi LIKE ?)))';
                    if ($rakam !== '') $paramsFilt[] = '%' . $rakam . '%';
                    $paramsFilt[] = '%' . $_POST['search_text'] . '%';
                    $paramsFilt[] = '%' . $_POST['search_text'] . '%';
                }

                $wFilt = implode(' AND ', $whereFilt);
                $recordsFiltered = (int)($db->fetchOne(
                    "SELECT COUNT(*) AS s FROM CagriBildirimleri b WHERE $wFilt",
                    $paramsFilt
                )['s'] ?? 0);

                // Siralama kolonu istemciden gelen degerle dogrudan sorguya girmez
                $orderMap = [
                    0 => 'b.CagriBildirimleri_CagriTarihi',
                    1 => 'b.CagriBildirimleri_Telefon',
                    5 => 'b.CagriBildirimleri_Gonderildi',
                ];
                $orderIdx = (int)($_POST['order'][0]['column'] ?? 0);
                $orderBy  = $orderMap[$orderIdx] ?? 'b.CagriBildirimleri_CagriTarihi';
                $orderDir = strtolower($_POST['order'][0]['dir'] ?? 'desc') === 'asc' ? 'ASC' : 'DESC';

                $data = [];
                if ($recordsFiltered > 0) {
                    $rows = $db->fetchAll("
                        WITH Sayfa AS (
                            SELECT b.CagriBildirimleri_id
                            FROM CagriBildirimleri b
                            WHERE $wFilt
                            ORDER BY $orderBy $orderDir, b.CagriBildirimleri_id DESC
                            OFFSET $start ROWS FETCH NEXT $length ROWS ONLY
                        )
                        SELECT
                            b.CagriBildirimleri_id         AS id,
                            b.CagriBildirimleri_HamNumara  AS ham_numara,
                            b.CagriBildirimleri_Telefon    AS telefon,
                            b.CagriBildirimleri_CariId     AS cari_id,
                            b.CagriBildirimleri_GrupJid    AS grup_jid,
                            b.CagriBildirimleri_Mesaj      AS mesaj,
                            b.CagriBildirimleri_Gonderildi AS gonderildi,
                            b.CagriBildirimleri_Hata       AS hata,
                            b.CagriBildirimleri_Cihaz      AS cihaz,
                            CONVERT(VARCHAR(19), b.CagriBildirimleri_CagriTarihi, 120) AS tarih,
                            ISNULL(NULLIF(c.cari_unvan, ''), c.cari_adi) AS isletme,
                            s.SehirAdi AS sehir,
                            i.IlceAdi  AS ilce
                        FROM Sayfa p
                        INNER JOIN CagriBildirimleri b ON b.CagriBildirimleri_id = p.CagriBildirimleri_id
                        LEFT JOIN Cari c           ON c.cari_id  = b.CagriBildirimleri_CariId
                        LEFT JOIN Adres_Sehirler s ON s.SehirId  = b.CagriBildirimleri_SehirId
                        LEFT JOIN Adres_Ilceler  i ON i.ilceId   = b.CagriBildirimleri_IlceId
                        ORDER BY $orderBy $orderDir, b.CagriBildirimleri_id DESC
                    ", $paramsFilt);

                    $gruplar = grupAdlari($db);
                    foreach ($rows as $r) {
                        // Okunamayan numara (gizli arama veya bozuk istek) bos doner, istemcide etiketlenir.
                        // '(bos)' ilk surumde yazilan eski kayitlar icindir.
                        $ham = in_array($r['ham_numara'], ['', '(bos)'], true) ? '' : (string)$r['ham_numara'];
                        $r['telefon_goster'] = $r['telefon'] ? telefonGoster($r['telefon']) : $ham;
                        $r['grup_adi']       = $r['grup_jid'] ? ($gruplar[$r['grup_jid']] ?? 'Grup') : null;
                        $r['durum']          = (int)$r['gonderildi'] === 1 ? 'iletildi' : ($r['mesaj'] !== null ? 'hata' : 'atlandi');
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

            // ── Acma / kapama anahtarlari ───────────────────────────
            case 'ayar_kaydet': {
                if (!$pagePermissions['can_edit']) throw new Exception('Yetkiniz yok.');

                $anahtar = (string)($_POST['anahtar'] ?? '');
                if (!in_array($anahtar, ANAHTAR_AYARLAR, true)) throw new Exception('Geçersiz ayar.');
                $deger = !empty($_POST['deger']) ? '1' : '0';

                $db->execute("
                    UPDATE WhatsappBotAyarlari
                    SET WhatsappBotAyarlari_Deger = ?, GuncelleyenKullanici = ?, GuncellemeTarihi = GETDATE()
                    WHERE WhatsappBotAyarlari_Anahtar = ?
                ", [$deger, (int)$user['kullanici_id'], $anahtar]);

                echo json_encode(['success' => true, 'message' => $deger === '1' ? 'Açıldı.' : 'Kapatıldı.'], JSON_UNESCAPED_UNICODE);
                break;
            }

            // ── Gonderilemeyen bildirimi yeniden ilet ───────────────
            case 'yeniden_ilet': {
                if (!$pagePermissions['can_edit']) throw new Exception('Yetkiniz yok.');

                $id = (int)($_POST['id'] ?? 0);
                $k  = $db->fetchOne("SELECT * FROM CagriBildirimleri WHERE CagriBildirimleri_id = ?", [$id]);
                if (!$k) throw new Exception('Kayıt bulunamadı.');
                if ((int)$k['CagriBildirimleri_Gonderildi'] === 1) throw new Exception('Bu bildirim zaten iletilmiş.');
                if ((string)$k['CagriBildirimleri_Mesaj'] === '' || (string)$k['CagriBildirimleri_GrupJid'] === '') {
                    throw new Exception('İletilecek mesaj veya grup yok.');
                }

                $bot   = new WhatsappBot($db);
                $sonuc = $bot->gonder((string)$k['CagriBildirimleri_GrupJid'], (string)$k['CagriBildirimleri_Mesaj'], 'GRUP', $bot->ayar('cagri_instance_kod'));

                $db->update('CagriBildirimleri', [
                    'CagriBildirimleri_Gonderildi' => $sonuc['success'] ? 1 : 0,
                    'CagriBildirimleri_Hata'       => $sonuc['success'] ? null : mb_substr($sonuc['message'], 0, 500),
                    'GuncelleyenKullanici'         => (int)$user['kullanici_id'],
                    'GuncellemeTarihi'             => date('Y-m-d H:i:s'),
                ], ['CagriBildirimleri_id' => $id]);

                echo json_encode([
                    'success' => $sonuc['success'],
                    'message' => $sonuc['success'] ? 'Bildirim gruba iletildi.' : $sonuc['message'],
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

$pageTitle       = $pageInfo['sayfalar_sayfa_adi'] ?? 'Çağrı Bildirimleri';
$pageDescription = $pageInfo['sayfalar_aciklama'] ?? 'Bot hattına gelen çağrılar ve grup iletimleri';
$menuAdi         = $pageInfo['menu_adi'] ?? null;

$siteAyarlari = $db->fetchOne("SELECT TOP 1 site_ayarlari_site_title FROM dbo.tanim_site_ayarlari ORDER BY site_ayarlari_id DESC");
$siteTitle    = $siteAyarlari['site_ayarlari_site_title'] ?? 'Örnek Yazılım Portal';

$bot      = new WhatsappBot($db);
$anahtar  = [];
foreach (ANAHTAR_AYARLAR as $k) $anahtar[$k] = $bot->ayar($k, '0') === '1';
$tekrarDk = (int)$bot->ayar('cagri_tekrar_sure_dk', '5');
$grupAdlari = grupAdlari($db);

// Filtre icin sehir listesi: cagrilarda gecen sehirler
$sehirler = $db->fetchAll("
    SELECT s.SehirId, s.SehirAdi
    FROM Adres_Sehirler s
    WHERE s.SehirId IN (SELECT DISTINCT CagriBildirimleri_SehirId FROM CagriBildirimleri WHERE Durum = 1)
    ORDER BY s.SehirAdi
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
        .mesaj-onizleme { white-space: pre-wrap; word-break: break-word; background: #d9fdd3; color: #111; border-radius: .75rem; padding: .75rem 1rem; }
        [data-bs-theme="dark"] .mesaj-onizleme { background: #005c4b; color: #e9edef; }
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
                            <span class="info-box-icon text-bg-primary shadow-sm"><i class="bi bi-telephone-inbound"></i></span>
                            <div class="info-box-content">
                                <span class="info-box-text">Bugünkü Çağrı</span>
                                <span class="info-box-number" id="stat-bugun">0</span>
                                <span class="info-box-text small text-muted">Toplam: <span id="stat-toplam">0</span></span>
                            </div>
                        </div>
                    </div>
                    <div class="col-12 col-sm-6 col-md-3">
                        <div class="info-box">
                            <span class="info-box-icon text-bg-success shadow-sm"><i class="bi bi-whatsapp"></i></span>
                            <div class="info-box-content">
                                <span class="info-box-text">Gruba İletilen</span>
                                <span class="info-box-number" id="stat-iletildi">0</span>
                                <span class="info-box-text small text-muted">Bugün: <span id="stat-iletildi-bugun">0</span></span>
                            </div>
                        </div>
                    </div>
                    <div class="col-12 col-sm-6 col-md-3">
                        <div class="info-box">
                            <span class="info-box-icon text-bg-secondary shadow-sm"><i class="bi bi-skip-forward"></i></span>
                            <div class="info-box-content">
                                <span class="info-box-text">Atlanan</span>
                                <span class="info-box-number" id="stat-atlandi">0</span>
                                <span class="info-box-text small text-muted">Kayıtsız numara: <span id="stat-kayitsiz">0</span></span>
                            </div>
                        </div>
                    </div>
                    <div class="col-12 col-sm-6 col-md-3">
                        <div class="info-box">
                            <span class="info-box-icon text-bg-danger shadow-sm"><i class="bi bi-exclamation-triangle"></i></span>
                            <div class="info-box-content">
                                <span class="info-box-text">Gönderim Hatası</span>
                                <span class="info-box-number" id="stat-hata">0</span>
                                <span class="info-box-text small text-muted">Yeniden iletilebilir</span>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Anahtarlar -->
                <div class="card mb-3">
                    <div class="card-body">
                        <div class="row g-3 align-items-center">
                            <div class="col-md-4">
                                <div class="form-check form-switch">
                                    <input class="form-check-input sw-ayar" type="checkbox" role="switch" id="sw_cagri_bildirim_aktif"
                                           name="cagri_bildirim_aktif" value="1" data-anahtar="cagri_bildirim_aktif"
                                           <?= $anahtar['cagri_bildirim_aktif'] ? 'checked' : '' ?>
                                           <?= $pagePermissions['can_edit'] ? '' : 'disabled' ?>>
                                    <label class="form-check-label fw-semibold" for="sw_cagri_bildirim_aktif">Çağrı bildirimi</label>
                                </div>
                                <small class="text-muted">Kapalıyken çağrılar loglanır, gruba mesaj gitmez.</small>
                            </div>
                            <div class="col-md-4">
                                <div class="form-check form-switch">
                                    <input class="form-check-input sw-ayar" type="checkbox" role="switch" id="sw_bot_aktif"
                                           name="bot_aktif" value="1" data-anahtar="bot_aktif"
                                           <?= $anahtar['bot_aktif'] ? 'checked' : '' ?>
                                           <?= $pagePermissions['can_edit'] ? '' : 'disabled' ?>>
                                    <label class="form-check-label fw-semibold" for="sw_bot_aktif">WhatsApp lead botu</label>
                                </div>
                                <small class="text-muted">Kapalıyken bot gelen WhatsApp mesajlarına yanıt vermez.</small>
                            </div>
                            <div class="col-md-4 small text-muted">
                                <div><i class="bi bi-people"></i> Gruplar: <?= htmlspecialchars(implode(', ', $grupAdlari) ?: '-') ?></div>
                                <div><i class="bi bi-clock-history"></i> Aynı numara <?= $tekrarDk ?> dk içinde tekrar ararsa yeniden bildirilmez.</div>
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
                                <label class="form-label">Durum</label>
                                <select class="form-select select2" id="filter_durum">
                                    <option value="">Tümü</option>
                                    <option value="iletildi">İletildi</option>
                                    <option value="atlandi">Atlandı</option>
                                    <option value="kayitsiz">Kayıtsız numara</option>
                                    <option value="hata">Hata</option>
                                </select>
                            </div>
                            <div class="col-md-2">
                                <label class="form-label">Şehir</label>
                                <select class="form-select select2" id="filter_sehir">
                                    <option value="">Tümü</option>
                                    <?php foreach ($sehirler as $s): ?>
                                        <option value="<?= (int)$s['SehirId'] ?>"><?= htmlspecialchars($s['SehirAdi']) ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-md-4">
                                <label class="form-label">Arama</label>
                                <input type="text" class="form-control" id="filter_search" placeholder="Telefon veya işletme adı...">
                            </div>
                            <div class="col-12 d-flex gap-2">
                                <button class="btn btn-primary" id="btnFiltrele"><i class="bi bi-search"></i> Filtrele</button>
                                <button class="btn btn-outline-secondary" id="btnTemizle"><i class="bi bi-x-circle"></i> Temizle</button>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Liste -->
                <div class="card">
                    <div class="card-header d-flex justify-content-between align-items-center flex-wrap gap-2">
                        <h3 class="card-title mb-0"><i class="bi bi-telephone"></i> Gelen Çağrılar</h3>
                        <div class="d-flex gap-2 ms-auto">
                            <button class="btn btn-sm btn-outline-primary" type="button"
                                    data-bs-toggle="collapse" data-bs-target="#filterCard">
                                <i class="bi bi-funnel"></i> Filtreler
                            </button>
                            <button class="btn btn-sm btn-outline-secondary" id="btnYenile">
                                <i class="bi bi-arrow-clockwise"></i> Yenile
                            </button>
                        </div>
                    </div>
                    <div class="card-body">
                        <table class="table table-hover table-striped align-middle w-100" id="tblCagrilar">
                            <thead>
                                <tr>
                                    <th>Tarih</th>
                                    <th>Telefon</th>
                                    <th>İşletme</th>
                                    <th>İl / İlçe</th>
                                    <th>Grup</th>
                                    <th>Durum</th>
                                    <th class="text-center">İşlem</th>
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

<!-- Mesaj onizleme -->
<div class="modal fade" id="mesajModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title"><i class="bi bi-chat-text"></i> Gruba Giden Mesaj</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <div class="mesaj-onizleme" id="mesajIcerik"></div>
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

const DURUM_ETIKET = {
    iletildi: '<span class="badge bg-success"><i class="bi bi-check2"></i> İletildi</span>',
    atlandi : '<span class="badge bg-secondary"><i class="bi bi-skip-forward"></i> Atlandı</span>',
    hata    : '<span class="badge bg-danger"><i class="bi bi-x"></i> Hata</span>'
};

let currentFilters = {};
const mesajlar = {};

function filtreleriTopla() {
    return {
        start_date : $('#filter_start_date').val(),
        end_date   : $('#filter_end_date').val(),
        durum      : $('#filter_durum').val(),
        sehir_id   : $('#filter_sehir').val(),
        search_text: $('#filter_search').val()
    };
}

function loadStats() {
    $.post('', { action: 'stats' }, r => {
        if (!r.success) return;
        $('#stat-bugun').text(r.data.bugun);
        $('#stat-toplam').text(r.data.toplam);
        $('#stat-iletildi').text(r.data.iletildi);
        $('#stat-iletildi-bugun').text(r.data.iletildi_bugun);
        $('#stat-atlandi').text(r.data.atlandi);
        $('#stat-kayitsiz').text(r.data.kayitsiz);
        $('#stat-hata').text(r.data.hata);
    }, 'json');
}

$(function () {
    $('.select2').select2({ theme: 'bootstrap-5', width: '100%' });

    const tablo = $('#tblCagrilar').DataTable({
        serverSide: true,
        processing: true,
        scrollX: true,
        dom: 'lrtip',
        pageLength: 25,
        order: [[0, 'desc']],
        // CDN'deki tr.json'da infoPostFix, info ile ayni metni tasidigi icin
        // alt bilgi iki kez yaziliyor; custom.js'teki Turkce tanim kullanilir
        language: dataTableTurkish,
        ajax: {
            url: '',
            type: 'POST',
            data: d => Object.assign(d, { action: 'list' }, currentFilters)
        },
        columns: [
            { data: 'tarih', render: (d, t) => t === 'display' ? tarihSaatFormat(d) : d },
            {
                data: 'telefon_goster',
                render: (d, t, row) => {
                    if (t !== 'display') return d;
                    const cihaz = row.cihaz ? `<br><small class="text-muted">${esc(row.cihaz)}</small>` : '';
                    const numara = d
                        ? `<span class="fw-semibold">${esc(d)}</span>`
                        : '<span class="badge bg-secondary" title="Gizli numara veya okunamayan istek">Okunamadı</span>';
                    return numara + cihaz;
                }
            },
            {
                data: 'isletme', orderable: false,
                render: (d, t, row) => {
                    if (t !== 'display') return d;
                    if (!row.telefon) return '<span class="text-muted">-</span>';
                    if (!row.cari_id) return '<span class="badge bg-warning text-dark">Kayıtlı değil</span>';
                    return esc(d || '-');
                }
            },
            {
                data: 'sehir', orderable: false,
                render: (d, t, row) => {
                    if (t !== 'display') return d;
                    if (!d) return '<span class="text-muted">-</span>';
                    return esc(d) + (row.ilce ? ' / ' + esc(row.ilce) : '');
                }
            },
            {
                data: 'grup_adi', orderable: false,
                render: (d, t) => t === 'display' ? (d ? esc(d) : '<span class="text-muted">-</span>') : d
            },
            {
                data: 'durum',
                render: (d, t, row) => {
                    if (t !== 'display') return d;
                    const neden = row.hata ? `<br><small class="text-muted">${esc(row.hata)}</small>` : '';
                    return (DURUM_ETIKET[d] || esc(d)) + neden;
                }
            },
            {
                data: null,
                orderable: false,
                className: 'text-center text-nowrap',
                render: (d, t, row) => {
                    let html = '';
                    if (row.mesaj) {
                        mesajlar[row.id] = row.mesaj;
                        html += `<button class="btn btn-sm btn-outline-primary btn-mesaj" data-id="${row.id}" title="Mesajı göster">
                                    <i class="bi bi-chat-text"></i>
                                 </button> `;
                    }
                    if (row.telefon) {
                        html += `<a class="btn btn-sm btn-outline-success" href="https://wa.me/90${row.telefon}" target="_blank" title="WhatsApp'ta aç">
                                    <i class="bi bi-whatsapp"></i>
                                 </a> `;
                    }
                    if (row.durum === 'hata') {
                        html += `<button class="btn btn-sm btn-outline-danger btn-yeniden" data-id="${row.id}" title="Yeniden ilet">
                                    <i class="bi bi-arrow-repeat"></i>
                                 </button>`;
                    }
                    return html || '<span class="text-muted">-</span>';
                }
            }
        ],
        drawCallback: () => loadStats()
    });

    $('#btnFiltrele').on('click', () => { currentFilters = filtreleriTopla(); tablo.ajax.reload(); });
    $('#btnYenile').on('click', () => tablo.ajax.reload(null, false));
    $('#filter_search').on('keypress', e => { if (e.which === 13) $('#btnFiltrele').click(); });

    $('#btnTemizle').on('click', () => {
        $('#filter_start_date, #filter_end_date, #filter_search').val('');
        $('#filter_durum, #filter_sehir').val('').trigger('change');
        currentFilters = {};
        tablo.ajax.reload();
    });

    // Ac / kapa anahtarlari
    $('.sw-ayar').on('change', function () {
        const $sw   = $(this);
        const acik  = $sw.is(':checked');
        const etiket = $sw.next('label').text();

        $sw.prop('disabled', true);
        $.post('', { action: 'ayar_kaydet', anahtar: $sw.data('anahtar'), deger: acik ? 1 : 0 }, r => {
            $sw.prop('disabled', false);
            if (!r.success) $sw.prop('checked', !acik);
            Swal.fire({
                toast: true, position: 'top-end', timer: 2500, showConfirmButton: false,
                icon: r.success ? 'success' : 'error',
                title: r.success ? `${etiket}: ${r.message}` : r.message
            });
        }, 'json').fail(() => {
            $sw.prop('disabled', false).prop('checked', !acik);
            Swal.fire({ icon: 'error', title: 'Hata', text: 'Ayar kaydedilemedi.' });
        });
    });

    // Mesaj onizleme
    $('#tblCagrilar').on('click', '.btn-mesaj', function () {
        $('#mesajIcerik').text(mesajlar[$(this).data('id')] || '');
        new bootstrap.Modal('#mesajModal').show();
    });

    // Yeniden ilet
    $('#tblCagrilar').on('click', '.btn-yeniden', function () {
        const id = $(this).data('id');
        Swal.fire({
            title: 'Yeniden iletilsin mi?',
            text: 'Bildirim ilgili WhatsApp grubuna tekrar gönderilecek.',
            icon: 'question',
            showCancelButton: true,
            confirmButtonText: 'Gönder',
            cancelButtonText: 'Vazgeç'
        }).then(s => {
            if (!s.isConfirmed) return;
            $.post('', { action: 'yeniden_ilet', id }, r => {
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
});
</script>
</body>
</html>
