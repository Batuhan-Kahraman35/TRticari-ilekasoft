<?php
/**
 * Admin Panel - SMS Gönder
 * Manuel (numara listesi) ve cari bazlı toplu SMS gönderimi.
 * Gönderim EkoMesaj API'si üzerinden SmsHelper ile yapılır.
 */

require_once __DIR__ . '/../auth.php';
require_once __DIR__ . '/../includes/PageAuth.php';
require_once __DIR__ . '/../includes/SmsHelper.php';
requireAuth();

$user = Auth::user();
$db   = Database::getInstance();

$currentPageFile = basename($_SERVER['PHP_SELF']);
$pageInfo = $db->fetchOne("
    SELECT s.sayfalar_sayfa_adi, s.sayfalar_aciklama, m.menuler_menu_adi AS menu_adi
    FROM Menu_Sayfalar s
    LEFT JOIN Menuler m ON s.sayfalar_menu_id = m.menuler_id
    WHERE s.sayfalar_sayfa_url LIKE ? AND s.sayfalar_durum = 1
", ['%' . $currentPageFile]);

$pageTitle       = $pageInfo['sayfalar_sayfa_adi'] ?? 'SMS Gönder';
$pageDescription = $pageInfo['sayfalar_aciklama'] ?? '';
$menuAdi         = $pageInfo['menu_adi'] ?? null;

$permissions = PageAuth::checkPagePermissions($user['kullanici_id'], $user['departman_id'], $currentPageFile);
if (!$permissions['has_access']) {
    PageAuth::accessDenied($permissions['error'] ?? 'Bu sayfaya erişim yetkiniz yok!');
}

$siteAyarlari = $db->fetchOne("SELECT TOP 1 site_ayarlari_site_title FROM dbo.tanim_site_ayarlari ORDER BY site_ayarlari_id DESC");
$siteTitle = $siteAyarlari['site_ayarlari_site_title'] ?? 'Örnek Yazılım Portal';

/**
 * Mesaj metnindeki yer tutucuları API custom field anahtarlarına çevirir.
 * Yer tutucu yoksa null döner (o zaman düz toplu gönderim yapılır).
 *
 * @return array|null ['sablon' => 'Sayın ADI ...', 'alanlar' => ['ADI' => 'cari_adi', ...]]
 */
function smsSablonCoz(string $mesaj, array $yerTutucular): ?array
{
    $sablon  = $mesaj;
    $alanlar = [];

    foreach ($yerTutucular as $etiket => $tanim) {
        if (mb_strpos($mesaj, '{' . $etiket . '}') === false) continue;
        $sablon = str_replace('{' . $etiket . '}', $tanim['anahtar'], $sablon);
        $alanlar[$tanim['anahtar']] = $tanim['kolon'];
    }

    return $alanlar ? ['sablon' => $sablon, 'alanlar' => $alanlar] : null;
}

// Cari toplu gönderimde kullanılabilen yer tutucular (mesaj kutusunda gösterilir)
$yerTutucular = [
    'ad'    => ['anahtar' => 'ADI',   'kolon' => 'cari_adi',   'aciklama' => 'Cari adı'],
    'unvan' => ['anahtar' => 'UNVAN', 'kolon' => 'cari_unvan', 'aciklama' => 'Cari unvanı'],
];

// ─── AJAX ───
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json; charset=utf-8');
    $action = $_POST['action'] ?? '';

    try {
        switch ($action) {

            case 'kanallar':
                $data = array_map(function ($k) {
                    return [
                        'id'     => (int) $k['kanal_id'],
                        'ad'     => $k['kanal_ad'],
                        'sender' => $k['kanal_kod'],
                    ];
                }, SmsHelper::kanallar($db));
                echo json_encode(['success' => true, 'data' => $data], JSON_UNESCAPED_UNICODE);
                break;

            case 'kredi':
                $r = SmsHelper::kredi((int) ($_POST['kanal_id'] ?? 0));
                echo json_encode([
                    'success' => $r['success'],
                    'kredi'   => $r['kredi'],
                    'ham'     => mb_substr((string) $r['ham'], 0, 200),
                    'message' => $r['message'],
                ], JSON_UNESCAPED_UNICODE);
                break;

            case 'stats':
                $bugun = $db->fetchOne("
                    SELECT
                        COUNT(*) AS toplam,
                        SUM(CASE WHEN log_sonuc = 'başarılı' THEN 1 ELSE 0 END) AS basarili,
                        SUM(CASE WHEN log_sonuc <> 'başarılı' THEN 1 ELSE 0 END) AS basarisiz
                    FROM EntegrasyonLoglari
                    WHERE log_tip = 'sms'
                      AND CAST(OlusturmaTarihi AS DATE) = CAST(GETDATE() AS DATE)
                ");
                $ay = $db->fetchOne("
                    SELECT COUNT(*) AS toplam
                    FROM EntegrasyonLoglari
                    WHERE log_tip = 'sms'
                      AND OlusturmaTarihi >= DATEADD(DAY, 1 - DAY(GETDATE()), CAST(GETDATE() AS DATE))
                ");
                echo json_encode(['success' => true, 'data' => [
                    'bugun'     => (int) ($bugun['toplam'] ?? 0),
                    'basarili'  => (int) ($bugun['basarili'] ?? 0),
                    'basarisiz' => (int) ($bugun['basarisiz'] ?? 0),
                    'ay'        => (int) ($ay['toplam'] ?? 0),
                ]]);
                break;

            case 'cari_yukle':
                $tipId  = (int) ($_POST['cari_tipi_id'] ?? 0);
                $where  = ['c.cari_aktif = 1', "ISNULL(c.cari_telefon, '') <> ''"];
                $params = [];
                if ($tipId > 0) {
                    $where[]  = 'c.cari_tipi_id = ?';
                    $params[] = $tipId;
                }

                $rows = $db->fetchAll("
                    SELECT c.cari_id, c.cari_adi, c.cari_unvan, c.cari_telefon,
                           t.cari_tipi_ad
                    FROM Cari c
                    LEFT JOIN Cari_CariTipleri t ON t.cari_tipi_id = c.cari_tipi_id
                    WHERE " . implode(' AND ', $where) . "
                    ORDER BY c.cari_adi
                ", $params) ?: [];

                // Telefonu geçersiz olanlar listede gösterilmesin
                $data = [];
                foreach ($rows as $r) {
                    if (SmsHelper::normalizeTelefon($r['cari_telefon']) === null) continue;
                    $data[] = [
                        'id'      => (int) $r['cari_id'],
                        'adi'     => $r['cari_adi'],
                        'unvan'   => $r['cari_unvan'],
                        'telefon' => $r['cari_telefon'],
                        'tip'     => $r['cari_tipi_ad'],
                    ];
                }
                echo json_encode(['success' => true, 'data' => $data], JSON_UNESCAPED_UNICODE);
                break;

            case 'gonder':
                if (!$permissions['can_add']) throw new Exception('SMS gönderme yetkiniz yok.');

                $mesaj      = trim($_POST['mesaj'] ?? '');
                $kanalId    = (int) ($_POST['kanal_id'] ?? 0);
                $commercial = !empty($_POST['commercial']);

                // Numara listesi JSON string gelir (max_input_vars sınırına takılmamak için)
                $numaralar = $_POST['numaralar'] ?? [];
                if (is_string($numaralar)) {
                    $cozulen   = json_decode($numaralar, true);
                    $numaralar = is_array($cozulen) ? $cozulen : [$numaralar];
                }
                $numaralar = array_values(array_filter(array_map('trim', (array) $numaralar)));

                if (!$numaralar) throw new Exception('En az bir telefon numarası girin.');
                if ($mesaj === '') throw new Exception('Mesaj alanı boş olamaz.');

                if (count($numaralar) > 50) {
                    set_time_limit(0);
                    ignore_user_abort(true);
                }

                $r = SmsHelper::gonderToplu(
                    $numaralar,
                    $mesaj,
                    (int) $user['kullanici_id'],
                    'Manuel SMS',
                    $commercial,
                    $kanalId
                );

                echo json_encode([
                    'success' => $r['toplam'] > 0 && $r['basarili'] > 0,
                    'message' => $r['message'],
                    'detay'   => $r['detay'],
                ], JSON_UNESCAPED_UNICODE);
                break;

            case 'gonder_cari':
                if (!$permissions['can_add']) throw new Exception('SMS gönderme yetkiniz yok.');

                $mesaj      = trim($_POST['mesaj'] ?? '');
                $kanalId    = (int) ($_POST['kanal_id'] ?? 0);
                $commercial = !empty($_POST['commercial']);

                $cariIdler = $_POST['cari_idler'] ?? [];
                if (is_string($cariIdler)) {
                    $cozulen   = json_decode($cariIdler, true);
                    $cariIdler = is_array($cozulen) ? $cozulen : [$cariIdler];
                }
                $cariIdler = array_values(array_unique(array_filter(array_map('intval', (array) $cariIdler))));

                if (!$cariIdler) throw new Exception('En az bir cari seçin.');
                if ($mesaj === '') throw new Exception('Mesaj alanı boş olamaz.');

                $yerTutucu = str_repeat('?,', count($cariIdler) - 1) . '?';
                $cariler   = $db->fetchAll("
                    SELECT cari_id, cari_adi, cari_unvan, cari_telefon
                    FROM Cari
                    WHERE cari_id IN ($yerTutucu) AND cari_aktif = 1
                ", $cariIdler) ?: [];

                if (!$cariler) throw new Exception('Seçilen cariler bulunamadı.');

                set_time_limit(0);
                ignore_user_abort(true);

                $cozum = smsSablonCoz($mesaj, $yerTutucular);

                if ($cozum) {
                    // Kişiselleştirilmiş gönderim (customFields / cfs)
                    $alicilar = [];
                    foreach ($cariler as $c) {
                        $alanlar = [];
                        foreach ($cozum['alanlar'] as $anahtar => $kolon) {
                            $alanlar[$anahtar] = (string) ($c[$kolon] ?? '');
                        }
                        $alicilar[] = ['telefon' => $c['cari_telefon'], 'alanlar' => $alanlar];
                    }
                    $r = SmsHelper::gonderKisisel(
                        $alicilar,
                        $cozum['sablon'],
                        (int) $user['kullanici_id'],
                        'Cari Toplu SMS',
                        $commercial,
                        $kanalId
                    );
                } else {
                    $r = SmsHelper::gonderToplu(
                        array_column($cariler, 'cari_telefon'),
                        $mesaj,
                        (int) $user['kullanici_id'],
                        'Cari Toplu SMS',
                        $commercial,
                        $kanalId
                    );
                }

                echo json_encode([
                    'success' => $r['toplam'] > 0 && $r['basarili'] > 0,
                    'message' => $r['message'],
                    'detay'   => $r['detay'],
                ], JSON_UNESCAPED_UNICODE);
                break;

            default:
                throw new Exception('Geçersiz işlem.');
        }
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'message' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
    }
    exit;
}

$cariTipleri = $db->fetchAll("
    SELECT cari_tipi_id, cari_tipi_ad
    FROM Cari_CariTipleri
    WHERE cari_tipi_durum = 1
    ORDER BY cari_tipi_sira
") ?: [];
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
                                <?php if ($menuAdi): ?><li class="breadcrumb-item"><?= htmlspecialchars($menuAdi) ?></li><?php endif; ?>
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
                        <div class="col-md-3">
                            <div class="info-box text-bg-primary">
                                <span class="info-box-icon"><i class="bi bi-chat-dots"></i></span>
                                <div class="info-box-content">
                                    <span class="info-box-text">Bugün Gönderilen</span>
                                    <span class="info-box-number" id="stat-bugun">0</span>
                                </div>
                            </div>
                        </div>
                        <div class="col-md-3">
                            <div class="info-box text-bg-success">
                                <span class="info-box-icon"><i class="bi bi-check2-circle"></i></span>
                                <div class="info-box-content">
                                    <span class="info-box-text">Bugün Başarılı</span>
                                    <span class="info-box-number" id="stat-basarili">0</span>
                                </div>
                            </div>
                        </div>
                        <div class="col-md-3">
                            <div class="info-box text-bg-danger">
                                <span class="info-box-icon"><i class="bi bi-exclamation-triangle"></i></span>
                                <div class="info-box-content">
                                    <span class="info-box-text">Bugün Başarısız</span>
                                    <span class="info-box-number" id="stat-basarisiz">0</span>
                                </div>
                            </div>
                        </div>
                        <div class="col-md-3">
                            <div class="info-box text-bg-info">
                                <span class="info-box-icon"><i class="bi bi-calendar-month"></i></span>
                                <div class="info-box-content">
                                    <span class="info-box-text">Bu Ay</span>
                                    <span class="info-box-number" id="stat-ay">0</span>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="row">
                        <!-- Manuel SMS -->
                        <div class="col-lg-6">
                            <div class="card card-primary card-outline mb-3">
                                <div class="card-header">
                                    <h3 class="card-title"><i class="bi bi-phone"></i> SMS Gönder</h3>
                                </div>
                                <div class="card-body">
                                    <form id="manuelForm">
                                        <div class="mb-3">
                                            <div class="d-flex justify-content-between align-items-center mb-1">
                                                <label for="kanal_id" class="form-label mb-0">Gönderen Başlık</label>
                                                <span class="badge bg-secondary" id="krediRozet" title="Sağlayıcıdaki kalan SMS bakiyesi">
                                                    <i class="bi bi-wallet2"></i> Bakiye: <span id="krediDeger">-</span>
                                                    <a href="#" id="krediYenile" class="text-white ms-1 text-decoration-none" title="Bakiyeyi yenile">
                                                        <i class="bi bi-arrow-clockwise"></i>
                                                    </a>
                                                </span>
                                            </div>
                                            <select class="form-select" id="kanal_id" name="kanal_id">
                                                <option value="">Varsayılan kanal</option>
                                            </select>
                                        </div>

                                        <div class="mb-3">
                                            <label for="numaralar" class="form-label">Telefon Numarası(ları) <span class="text-danger">*</span></label>
                                            <textarea class="form-control" id="numaralar" rows="7" placeholder="Her satıra bir numara yazın veya yapıştırın...&#10;05001234567&#10;05009876543"></textarea>
                                            <div class="d-flex justify-content-between align-items-center mt-2">
                                                <small class="text-muted">Her satıra bir numara (tekrarlar otomatik ayıklanır)</small>
                                                <span class="badge bg-primary" id="numaraSayaci">0 numara</span>
                                            </div>
                                        </div>

                                        <div class="mb-3">
                                            <label for="mesaj" class="form-label">Mesaj <span class="text-danger">*</span></label>
                                            <textarea class="form-control sms-metin" id="mesaj" rows="5" maxlength="500" required placeholder="SMS metnini buraya yazın..."></textarea>
                                            <small class="text-muted">
                                                Kalan: <span class="sms-kalan">500</span> karakter |
                                                <span class="sms-adet">1 SMS</span>
                                            </small>
                                        </div>

                                        <div class="mb-3">
                                            <div class="form-check form-switch">
                                                <input class="form-check-input" type="checkbox" role="switch" id="commercial" name="commercial" value="1">
                                                <label class="form-check-label" for="commercial">Ticari ileti</label>
                                            </div>
                                            <small class="text-muted">
                                                Kampanya, tanıtım ve indirim mesajları için açın; alıcıların
                                                <strong>İYS onayı</strong> bulunmalıdır. Bilgilendirme mesajlarında kapalı kalmalıdır.
                                            </small>
                                        </div>

                                        <button type="submit" class="btn btn-primary w-100">
                                            <i class="bi bi-send"></i> SMS Gönder
                                        </button>
                                    </form>
                                </div>
                            </div>
                        </div>

                        <!-- Cari Bazlı Toplu SMS -->
                        <div class="col-lg-6">
                            <div class="card card-success card-outline mb-3">
                                <div class="card-header">
                                    <h3 class="card-title"><i class="bi bi-people"></i> Carilere Toplu SMS</h3>
                                </div>
                                <div class="card-body">
                                    <form id="cariForm">
                                        <div class="mb-3">
                                            <label for="cari_kanal_id" class="form-label">Gönderen Başlık</label>
                                            <select class="form-select" id="cari_kanal_id" name="kanal_id">
                                                <option value="">Varsayılan kanal</option>
                                            </select>
                                        </div>

                                        <div class="mb-3">
                                            <label for="cari_tipi_id" class="form-label">Cari Tipi</label>
                                            <select class="form-select" id="cari_tipi_id">
                                                <option value="">Tüm Tipler</option>
                                                <?php foreach ($cariTipleri as $tip): ?>
                                                    <option value="<?= (int) $tip['cari_tipi_id'] ?>"><?= htmlspecialchars($tip['cari_tipi_ad']) ?></option>
                                                <?php endforeach; ?>
                                            </select>
                                        </div>

                                        <div class="mb-3">
                                            <label for="cari_listesi" class="form-label">Cari Seç <span class="text-danger">*</span></label>
                                            <select class="form-select" id="cari_listesi" multiple size="8">
                                                <option value="">Önce cari listesini yükleyin...</option>
                                            </select>
                                            <div class="mt-2 d-flex flex-wrap gap-1">
                                                <button type="button" class="btn btn-sm btn-secondary" id="cariYukle">
                                                    <i class="bi bi-arrow-clockwise"></i> Listeyi Yükle
                                                </button>
                                                <button type="button" class="btn btn-sm btn-info" id="tumunuSec">
                                                    <i class="bi bi-check-all"></i> Tümünü Seç
                                                </button>
                                                <button type="button" class="btn btn-sm btn-warning" id="secimTemizle">
                                                    <i class="bi bi-x"></i> Seçimi Temizle
                                                </button>
                                            </div>
                                        </div>

                                        <div class="mb-3">
                                            <label for="cari_mesaj" class="form-label">Mesaj <span class="text-danger">*</span></label>
                                            <textarea class="form-control sms-metin" id="cari_mesaj" rows="5" maxlength="500" required placeholder="Toplu SMS metnini buraya yazın..."></textarea>
                                            <small class="text-muted d-block">
                                                Kalan: <span class="sms-kalan">500</span> karakter |
                                                <span class="sms-adet">1 SMS</span>
                                            </small>
                                            <small class="text-muted d-block mt-1">
                                                Kişiye özel alan:
                                                <?php foreach ($yerTutucular as $etiket => $t): ?>
                                                    <code class="yer-tutucu" role="button" title="Mesaja ekle"><?= '{' . $etiket . '}' ?></code>
                                                    <span class="me-2"><?= htmlspecialchars($t['aciklama']) ?></span>
                                                <?php endforeach; ?>
                                            </small>
                                        </div>

                                        <div class="mb-3">
                                            <div class="form-check form-switch">
                                                <input class="form-check-input" type="checkbox" role="switch" id="cari_commercial" name="commercial" value="1">
                                                <label class="form-check-label" for="cari_commercial">Ticari ileti</label>
                                            </div>
                                            <small class="text-muted">
                                                Kampanya/tanıtım gönderiminde açın; alıcıların <strong>İYS onayı</strong> bulunmalıdır.
                                            </small>
                                        </div>

                                        <button type="submit" class="btn btn-success w-100">
                                            <i class="bi bi-send-fill"></i> Toplu SMS Gönder
                                        </button>
                                    </form>
                                </div>
                            </div>
                        </div>
                    </div>

                </div>
            </div>
        </main>

        <?php include __DIR__ . '/../includes/footer.php'; ?>
    </div>

    <script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/@popperjs/core@2.11.8/dist/umd/popper.min.js" crossorigin="anonymous"></script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.7/dist/js/bootstrap.min.js" crossorigin="anonymous"></script>
    <script src="/admin/assets/js/adminlte.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11/dist/sweetalert2.all.min.js"></script>
    <script src="/admin/assets/js/custom.js"></script>

    <script>
    $(function () {
        // Gönderim encoding = 1 (UCS2) olduğu için parça uzunluğu Türkçe metne göre hesaplanır
        const TEK_SMS = 70, COK_SMS = 67, LIMIT = 500;

        $('#kanal_id, #cari_kanal_id, #cari_tipi_id').select2({ theme: 'bootstrap-5', width: '100%' });
        $('#cari_listesi').select2({
            theme: 'bootstrap-5',
            width: '100%',
            placeholder: 'Cari arayın veya seçin...',
            closeOnSelect: false
        });

        // ── Kanal ve bakiye ──
        function kanallariYukle() {
            $.post('', { action: 'kanallar' }, function (r) {
                if (!r.success) return;
                const hedefler = $('#kanal_id, #cari_kanal_id');
                hedefler.find('option:not(:first)').remove();
                r.data.forEach(function (k) {
                    hedefler.append($('<option>', { value: k.id, text: k.ad + ' (' + k.sender + ')' }));
                });
                hedefler.trigger('change.select2');
            }, 'json');
        }

        function bakiyeYukle() {
            $('#krediDeger').text('...');
            $('#krediRozet').removeClass('bg-danger bg-success bg-warning').addClass('bg-secondary');

            $.post('', { action: 'kredi', kanal_id: $('#kanal_id').val() || 0 }, function (r) {
                if (!r.success) {
                    $('#krediDeger').text('alınamadı');
                    $('#krediRozet').removeClass('bg-secondary').addClass('bg-danger')
                        .attr('title', r.message || 'Bakiye sorgulanamadı');
                    return;
                }
                if (r.kredi === null) {
                    $('#krediDeger').text('?');
                    $('#krediRozet').removeClass('bg-secondary').addClass('bg-warning')
                        .attr('title', 'Yanıt çözümlenemedi: ' + (r.ham || '-'));
                    return;
                }
                const k = Number(r.kredi);
                $('#krediDeger').text(k.toLocaleString('tr-TR') + ' SMS');
                $('#krediRozet').removeClass('bg-secondary')
                    .addClass(k < 1000 ? 'bg-danger' : 'bg-success')
                    .attr('title', 'Kalan bakiye');
            }, 'json').fail(function () {
                $('#krediDeger').text('alınamadı');
                $('#krediRozet').removeClass('bg-secondary').addClass('bg-danger');
            });
        }

        function statYukle() {
            $.post('', { action: 'stats' }, function (r) {
                if (!r.success) return;
                $('#stat-bugun').text(r.data.bugun);
                $('#stat-basarili').text(r.data.basarili);
                $('#stat-basarisiz').text(r.data.basarisiz);
                $('#stat-ay').text(r.data.ay);
            }, 'json');
        }

        $('#krediYenile').on('click', function (e) { e.preventDefault(); bakiyeYukle(); });
        $('#kanal_id').on('change', bakiyeYukle);

        kanallariYukle();
        bakiyeYukle();
        statYukle();

        // ── Karakter / SMS sayacı ──
        $('.sms-metin').on('input', function () {
            const len = $(this).val().length;
            const kap = $(this).closest('.mb-3');
            kap.find('.sms-kalan').text(LIMIT - len);
            kap.find('.sms-adet').text((len <= TEK_SMS ? 1 : Math.ceil(len / COK_SMS)) + ' SMS');
        });

        // ── Numara ayrıştırma ──
        function numaralariAl() {
            const metin = $('#numaralar').val();
            if (!metin.trim()) return [];
            const cikti = [];
            metin.split(/[\n\r,;]+/).forEach(function (satir) {
                const temiz = satir.replace(/\D/g, '');
                if (temiz.length >= 10) cikti.push(temiz);
            });
            return cikti;
        }

        $('#numaralar').on('input', function () {
            $('#numaraSayaci').text(numaralariAl().length + ' numara');
        });

        // ── Sonuç detayı ──
        function sonucGoster(r) {
            const basarisiz = (r.detay || []).filter(function (d) { return !d.success; });
            if (!basarisiz.length) {
                showSuccess('Gönderildi!', r.message);
                return;
            }
            const satirlar = basarisiz.slice(0, 20).map(function (d) {
                return '<tr><td>' + d.phone + '</td><td class="text-danger">' + d.message + '</td></tr>';
            }).join('');
            const fazla = basarisiz.length > 20
                ? '<p class="text-muted mb-0">... ve ' + (basarisiz.length - 20) + ' kayıt daha</p>' : '';

            Swal.fire({
                icon: r.success ? 'warning' : 'error',
                title: r.success ? 'Kısmen gönderildi' : 'Gönderilemedi',
                html: '<p>' + r.message + '</p>'
                    + '<div style="max-height:260px;overflow:auto"><table class="table table-sm">'
                    + '<thead><tr><th>Numara</th><th>Hata</th></tr></thead><tbody>' + satirlar + '</tbody></table></div>'
                    + fazla,
                width: 600,
                confirmButtonText: 'Tamam'
            });
        }

        // ── Manuel gönderim ──
        $('#manuelForm').on('submit', function (e) {
            e.preventDefault();

            const numaralar = numaralariAl();
            const mesaj     = $('#mesaj').val().trim();
            const ticari    = $('#commercial').is(':checked');

            if (!numaralar.length) { showWarning('Uyarı!', 'En az bir geçerli telefon numarası girin.'); return; }
            if (!mesaj)            { showWarning('Uyarı!', 'Mesaj alanı boş olamaz.'); return; }

            let metin = numaralar.length === 1
                ? numaralar[0] + ' numarasına mesaj gönderilecek.'
                : numaralar.length + ' numaraya mesaj gönderilecek.';
            if (ticari) metin += '\n\nTİCARİ İLETİ olarak gönderilecek. Alıcıların İYS onayı bulunmalıdır.';

            confirmAction('SMS göndermek istediğinize emin misiniz?', metin, function () {
                showLoading('SMS gönderiliyor...');
                $.post('', {
                    action: 'gonder',
                    numaralar: JSON.stringify(numaralar),
                    mesaj: mesaj,
                    kanal_id: $('#kanal_id').val() || 0,
                    commercial: ticari ? 1 : 0
                }, function (r) {
                    hideLoading();
                    sonucGoster(r);
                    if (r.success) {
                        $('#numaralar, #mesaj').val('');
                        $('#numaraSayaci').text('0 numara');
                        $('#manuelForm .sms-kalan').text(LIMIT);
                        $('#manuelForm .sms-adet').text('1 SMS');
                        $('#commercial').prop('checked', false); // her gönderimde bilinçli seçilsin
                    }
                    bakiyeYukle();
                    statYukle();
                }, 'json').fail(function () {
                    hideLoading();
                    showError('Hata!', 'SMS gönderimi başarısız.');
                });
            });
        });

        // ── Cari listesi ──
        $('#cariYukle').on('click', function () {
            const $btn = $(this).prop('disabled', true);
            $.post('', { action: 'cari_yukle', cari_tipi_id: $('#cari_tipi_id').val() || 0 }, function (r) {
                if (!r.success) { showToast(r.message, 'error'); return; }

                const secim = $('#cari_listesi').empty();
                if (!r.data.length) {
                    secim.append('<option value="">Telefonu olan cari bulunamadı</option>');
                } else {
                    r.data.forEach(function (c) {
                        const etiket = c.adi + ' (' + c.telefon + ')' + (c.tip ? ' - ' + c.tip : '');
                        secim.append($('<option>', { value: c.id, text: etiket }));
                    });
                    showToast(r.data.length + ' cari yüklendi', 'success');
                }
                secim.trigger('change.select2');
            }, 'json').fail(function () {
                showToast('Cari listesi yüklenemedi', 'error');
            }).always(function () { $btn.prop('disabled', false); });
        });

        $('#tumunuSec').on('click', function () {
            $('#cari_listesi option[value!=""]').prop('selected', true);
            $('#cari_listesi').trigger('change.select2');
        });

        $('#secimTemizle').on('click', function () {
            $('#cari_listesi option').prop('selected', false);
            $('#cari_listesi').trigger('change.select2');
        });

        // Yer tutucuyu tıklayınca mesaja ekle
        $('.yer-tutucu').on('click', function () {
            const alan = $('#cari_mesaj');
            alan.val((alan.val() + ' ' + $(this).text()).trim()).trigger('input').focus();
        });

        // ── Cari toplu gönderim ──
        $('#cariForm').on('submit', function (e) {
            e.preventDefault();

            const secilen = ($('#cari_listesi').val() || []).filter(function (v) { return v !== ''; });
            const mesaj   = $('#cari_mesaj').val().trim();
            const ticari  = $('#cari_commercial').is(':checked');

            if (!secilen.length) { showWarning('Uyarı!', 'Lütfen en az bir cari seçin.'); return; }
            if (!mesaj)          { showWarning('Uyarı!', 'Mesaj alanı boş olamaz.'); return; }

            let metin = secilen.length + ' cariye SMS gönderilecek.';
            if (ticari) metin += '\n\nTİCARİ İLETİ olarak gönderilecek. Alıcıların İYS onayı bulunmalıdır.';

            confirmAction('Toplu SMS göndermek istediğinize emin misiniz?', metin, function () {
                showLoading('SMS gönderiliyor...');
                $.post('', {
                    action: 'gonder_cari',
                    cari_idler: JSON.stringify(secilen),
                    mesaj: mesaj,
                    kanal_id: $('#cari_kanal_id').val() || 0,
                    commercial: ticari ? 1 : 0
                }, function (r) {
                    hideLoading();
                    sonucGoster(r);
                    if (r.success) {
                        $('#cari_mesaj').val('');
                        $('#cariForm .sms-kalan').text(LIMIT);
                        $('#cariForm .sms-adet').text('1 SMS');
                        $('#cari_commercial').prop('checked', false);
                        $('#cari_listesi option').prop('selected', false);
                        $('#cari_listesi').trigger('change.select2');
                    }
                    bakiyeYukle();
                    statYukle();
                }, 'json').fail(function () {
                    hideLoading();
                    showError('Hata!', 'SMS gönderimi başarısız.');
                });
            });
        });
    });
    </script>
</body>
</html>
