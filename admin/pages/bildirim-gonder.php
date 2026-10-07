<?php
/**
 * Admin Panel - Manuel Bildirim Gönder
 * Hedef: Herkes / Departman / Kullanıcı(lar). Çan + push.
 */

require_once __DIR__ . '/../auth.php';
require_once __DIR__ . '/../includes/PageAuth.php';
require_once __DIR__ . '/../includes/Bildirim.php';
requireAuth();

$user = Auth::user();
$db = Database::getInstance();

$currentPageFile = basename($_SERVER['PHP_SELF']);
$pageInfo = $db->fetchOne("
    SELECT s.sayfalar_sayfa_adi, s.sayfalar_aciklama, m.menuler_menu_adi AS menu_adi
    FROM Menu_Sayfalar s
    LEFT JOIN Menuler m ON s.sayfalar_menu_id = m.menuler_id
    WHERE s.sayfalar_sayfa_url LIKE ? AND s.sayfalar_durum = 1
", ['%' . $currentPageFile]);

$pageTitle       = $pageInfo['sayfalar_sayfa_adi'] ?? 'Bildirim Gönder';
$pageDescription = $pageInfo['sayfalar_aciklama'] ?? '';
$menuAdi         = $pageInfo['menu_adi'] ?? null;

$permissions = PageAuth::checkPagePermissions($user['kullanici_id'], $user['departman_id'], $currentPageFile);
if (!$permissions['has_access']) {
    PageAuth::accessDenied($permissions['error'] ?? 'Bu sayfaya erişim yetkiniz yok!');
}

$siteAyarlari = $db->fetchOne("SELECT TOP 1 site_ayarlari_site_title FROM dbo.tanim_site_ayarlari ORDER BY site_ayarlari_id DESC");
$siteTitle = $siteAyarlari['site_ayarlari_site_title'] ?? 'Örnek Yazılım Portal';

// ─── AJAX ───
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json; charset=utf-8');
    $action = $_POST['action'] ?? '';
    try {
        switch ($action) {

            case 'gonder':
                if (!$permissions['can_add']) throw new Exception('Bildirim gönderme yetkiniz yok.');

                $veri = [
                    'baslik'    => trim($_POST['baslik'] ?? ''),
                    'govde'     => trim($_POST['govde'] ?? ''),
                    'url'       => trim($_POST['url'] ?? '') ?: '/admin/',
                    'tip'       => $_POST['tip'] ?? 'info',
                    'push'      => !empty($_POST['push']),
                    'olusturan' => $user['kullanici_id'],
                ];
                if ($veri['baslik'] === '') throw new Exception('Başlık zorunludur.');

                $hedefTip = $_POST['hedef_tip'] ?? 'kullanici';

                if ($hedefTip === 'herkes') {
                    $r = Bildirim::genelDuyuru($db, $veri);
                    $mesaj = 'Genel duyuru gönderildi. Push: ' . $r['push'] . ' cihaz.';

                } elseif ($hedefTip === 'departman') {
                    $depId = (int)($_POST['departman_id'] ?? 0);
                    if ($depId <= 0) throw new Exception('Departman seçiniz.');
                    $kids = array_column($db->fetchAll("
                        SELECT kullanici_id FROM kullanicilar
                        WHERE kullanici_departman_id = ? AND kullanici_durum = 1
                    ", [$depId]), 'kullanici_id');
                    if (!$kids) throw new Exception('Bu departmanda aktif kullanıcı yok.');
                    $r = Bildirim::olusturCoklu($db, $kids, $veri);
                    $mesaj = $r['bildirim'] . ' kullanıcıya gönderildi. Push: ' . $r['push'] . ' cihaz.';

                } else { // kullanici
                    $kids = $_POST['kullanici_idler'] ?? [];
                    if (!is_array($kids)) $kids = [$kids];
                    $kids = array_filter(array_map('intval', $kids));
                    if (!$kids) throw new Exception('En az bir kullanıcı seçiniz.');
                    $r = Bildirim::olusturCoklu($db, $kids, $veri);
                    $mesaj = $r['bildirim'] . ' kullanıcıya gönderildi. Push: ' . $r['push'] . ' cihaz.';
                }

                echo json_encode(['success' => true, 'message' => $mesaj]);
                break;

            case 'list':
                $search = trim($_POST['search'] ?? '');
                $tip    = $_POST['tip'] ?? '';
                $where  = ['b.Durum = 1'];
                $params = [];
                if ($search !== '') {
                    $where[] = '(b.bildirim_baslik LIKE ? OR b.bildirim_govde LIKE ?)';
                    $params[] = "%$search%"; $params[] = "%$search%";
                }
                if ($tip !== '') { $where[] = 'b.bildirim_tip = ?'; $params[] = $tip; }

                $rows = $db->fetchAll("
                    SELECT TOP 500 b.bildirim_id, b.bildirim_baslik, b.bildirim_govde, b.bildirim_tip,
                           b.bildirim_okundu, b.bildirim_push_gonderildi, b.bildirim_kullanici_id,
                           LTRIM(RTRIM(ISNULL(k.kullanici_ad,'') + ' ' + ISNULL(k.kullanici_soyad,''))) AS hedef_ad,
                           CONVERT(VARCHAR(19), b.OlusturmaTarihi, 120) AS olusturma
                    FROM dbo.Bildirimler b
                    LEFT JOIN kullanicilar k ON k.kullanici_id = b.bildirim_kullanici_id
                    WHERE " . implode(' AND ', $where) . "
                    ORDER BY b.bildirim_id DESC
                ", $params);
                echo json_encode(['success' => true, 'data' => $rows]);
                break;

            case 'stats':
                $toplam    = (int)($db->fetchOne("SELECT COUNT(*) c FROM dbo.Bildirimler WHERE Durum=1")['c'] ?? 0);
                $bugun     = (int)($db->fetchOne("SELECT COUNT(*) c FROM dbo.Bildirimler WHERE Durum=1 AND CAST(OlusturmaTarihi AS DATE)=CAST(GETDATE() AS DATE)")['c'] ?? 0);
                $pushli    = (int)($db->fetchOne("SELECT COUNT(*) c FROM dbo.Bildirimler WHERE Durum=1 AND bildirim_push_gonderildi=1")['c'] ?? 0);
                $okunmamis = (int)($db->fetchOne("SELECT COUNT(*) c FROM dbo.Bildirimler WHERE Durum=1 AND bildirim_okundu=0")['c'] ?? 0);
                echo json_encode(['success' => true, 'toplam' => $toplam, 'bugun' => $bugun, 'pushli' => $pushli, 'okunmamis' => $okunmamis]);
                break;

            default:
                echo json_encode(['success' => false, 'message' => 'Geçersiz işlem']);
        }
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'message' => 'Hata: ' . $e->getMessage()]);
    }
    exit;
}

// Hedef listeleri (DB'den)
$departmanlar = $db->fetchAll("SELECT departman_id, departman_adi FROM kullanici_Departmanlar WHERE departman_durum = 1 ORDER BY departman_adi");
$kullanicilar = $db->fetchAll("SELECT kullanici_id, kullanici_ad, kullanici_soyad, kullanici_email FROM kullanicilar WHERE kullanici_durum = 1 ORDER BY kullanici_ad, kullanici_soyad");
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
    <link rel="stylesheet" href="https://cdn.datatables.net/1.13.6/css/dataTables.bootstrap5.min.css">
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
                                <span class="info-box-icon"><i class="bi bi-bell"></i></span>
                                <div class="info-box-content">
                                    <span class="info-box-text">Toplam Bildirim</span>
                                    <span class="info-box-number" id="stat-toplam">0</span>
                                </div>
                            </div>
                        </div>
                        <div class="col-md-3">
                            <div class="info-box text-bg-info">
                                <span class="info-box-icon"><i class="bi bi-calendar-day"></i></span>
                                <div class="info-box-content">
                                    <span class="info-box-text">Bugün</span>
                                    <span class="info-box-number" id="stat-bugun">0</span>
                                </div>
                            </div>
                        </div>
                        <div class="col-md-3">
                            <div class="info-box text-bg-success">
                                <span class="info-box-icon"><i class="bi bi-send-check"></i></span>
                                <div class="info-box-content">
                                    <span class="info-box-text">Push Gönderilen</span>
                                    <span class="info-box-number" id="stat-pushli">0</span>
                                </div>
                            </div>
                        </div>
                        <div class="col-md-3">
                            <div class="info-box text-bg-warning">
                                <span class="info-box-icon"><i class="bi bi-envelope-exclamation"></i></span>
                                <div class="info-box-content">
                                    <span class="info-box-text">Okunmamış</span>
                                    <span class="info-box-number" id="stat-okunmamis">0</span>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="row">
                        <!-- Gönderim Formu -->
                        <div class="col-lg-5">
                            <div class="card card-primary card-outline mb-3">
                                <div class="card-header"><h3 class="card-title"><i class="bi bi-send"></i> Yeni Bildirim</h3></div>
                                <div class="card-body">
                                    <?php if (!$permissions['can_add']): ?>
                                        <div class="alert alert-warning mb-0">Bildirim gönderme yetkiniz yok.</div>
                                    <?php else: ?>
                                    <form id="gonderForm">
                                        <div class="mb-3">
                                            <label class="form-label">Hedef <span class="text-danger">*</span></label>
                                            <select class="form-select" id="hedef_tip" name="hedef_tip">
                                                <option value="kullanici">Belirli Kullanıcı(lar)</option>
                                                <option value="departman">Departman</option>
                                                <option value="herkes">Herkes (Genel Duyuru)</option>
                                            </select>
                                        </div>

                                        <div class="mb-3" id="grup_kullanici">
                                            <label class="form-label">Kullanıcı(lar) <span class="text-danger">*</span></label>
                                            <select class="form-select" id="kullanici_idler" name="kullanici_idler[]" multiple>
                                                <?php foreach ($kullanicilar as $k):
                                                    $ad = trim($k['kullanici_ad'] . ' ' . $k['kullanici_soyad']);
                                                    if ($ad === '') $ad = $k['kullanici_email']; ?>
                                                    <option value="<?= (int)$k['kullanici_id'] ?>"><?= htmlspecialchars($ad . ' (' . $k['kullanici_email'] . ')') ?></option>
                                                <?php endforeach; ?>
                                            </select>
                                        </div>

                                        <div class="mb-3 d-none" id="grup_departman">
                                            <label class="form-label">Departman <span class="text-danger">*</span></label>
                                            <select class="form-select" id="departman_id" name="departman_id">
                                                <option value="">Seçiniz...</option>
                                                <?php foreach ($departmanlar as $d): ?>
                                                    <option value="<?= (int)$d['departman_id'] ?>"><?= htmlspecialchars($d['departman_adi']) ?></option>
                                                <?php endforeach; ?>
                                            </select>
                                        </div>

                                        <div class="mb-3">
                                            <label class="form-label">Başlık <span class="text-danger">*</span></label>
                                            <input type="text" class="form-control" id="baslik" name="baslik" maxlength="200" required>
                                        </div>
                                        <div class="mb-3">
                                            <label class="form-label">Mesaj</label>
                                            <textarea class="form-control" id="govde" name="govde" rows="3" maxlength="1000"></textarea>
                                        </div>
                                        <div class="row g-2">
                                            <div class="col-md-7 mb-3">
                                                <label class="form-label">Bağlantı (tıklayınca gidilecek)</label>
                                                <input type="text" class="form-control" id="url" name="url" placeholder="/admin/">
                                            </div>
                                            <div class="col-md-5 mb-3">
                                                <label class="form-label">Tip</label>
                                                <select class="form-select" id="tip" name="tip">
                                                    <option value="info">Bilgi</option>
                                                    <option value="basari">Başarı</option>
                                                    <option value="uyari">Uyarı</option>
                                                    <option value="hata">Hata</option>
                                                </select>
                                            </div>
                                        </div>
                                        <div class="form-check form-switch mb-3">
                                            <input class="form-check-input" type="checkbox" id="push" name="push" checked>
                                            <label class="form-check-label" for="push">Push bildirimi de gönder</label>
                                        </div>
                                        <button type="submit" class="btn btn-primary w-100"><i class="bi bi-send"></i> Gönder</button>
                                    </form>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>

                        <!-- Gönderilen Bildirimler -->
                        <div class="col-lg-7">
                            <div class="card card-primary card-outline mb-3">
                                <div class="card-header">
                                    <h3 class="card-title"><i class="bi bi-funnel"></i> Filtrele</h3>
                                    <div class="card-tools">
                                        <button type="button" class="btn btn-tool" data-bs-toggle="collapse" data-bs-target="#filterCard"><i class="bi bi-chevron-down"></i></button>
                                    </div>
                                </div>
                                <div class="card-body collapse" id="filterCard">
                                    <form id="filterForm">
                                        <div class="row g-3">
                                            <div class="col-md-7">
                                                <label class="form-label">Ara</label>
                                                <input type="text" class="form-control" name="search" id="filter_search" placeholder="Başlık veya mesaj...">
                                            </div>
                                            <div class="col-md-5">
                                                <label class="form-label">Tip</label>
                                                <select class="form-select" name="tip" id="filter_tip">
                                                    <option value="">Tümü</option>
                                                    <option value="info">Bilgi</option>
                                                    <option value="basari">Başarı</option>
                                                    <option value="uyari">Uyarı</option>
                                                    <option value="hata">Hata</option>
                                                </select>
                                            </div>
                                            <div class="col-12">
                                                <button type="submit" class="btn btn-primary"><i class="bi bi-search"></i> Filtrele</button>
                                                <button type="button" class="btn btn-secondary" id="clearFilters"><i class="bi bi-x-circle"></i> Temizle</button>
                                            </div>
                                        </div>
                                    </form>
                                </div>
                            </div>

                            <div class="card">
                                <div class="card-header"><h3 class="card-title">Gönderilen Bildirimler</h3></div>
                                <div class="card-body">
                                    <table id="bildirimTable" class="table table-bordered table-striped table-hover">
                                        <thead>
                                            <tr>
                                                <th>Başlık</th>
                                                <th>Hedef</th>
                                                <th>Tip</th>
                                                <th>Push</th>
                                                <th>Okundu</th>
                                                <th>Tarih</th>
                                            </tr>
                                        </thead>
                                        <tbody></tbody>
                                    </table>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </main>

        <?php include __DIR__ . '/../includes/footer.php'; ?>
    </div>

    <script src="https://code.jquery.com/jquery-3.7.0.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/@popperjs/core@2.11.8/dist/umd/popper.min.js" crossorigin="anonymous"></script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.7/dist/js/bootstrap.min.js" crossorigin="anonymous"></script>
    <script src="/admin/assets/js/adminlte.min.js"></script>
    <script src="https://cdn.datatables.net/1.13.6/js/jquery.dataTables.min.js"></script>
    <script src="https://cdn.datatables.net/1.13.6/js/dataTables.bootstrap5.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11/dist/sweetalert2.all.min.js"></script>
    <script src="/admin/assets/js/custom.js"></script>

    <script>
        let dataTable;
        const tipRozet = {
            info:   '<span class="badge text-bg-primary">Bilgi</span>',
            basari: '<span class="badge text-bg-success">Başarı</span>',
            uyari:  '<span class="badge text-bg-warning">Uyarı</span>',
            hata:   '<span class="badge text-bg-danger">Hata</span>'
        };

        function statYukle() {
            $.post('', { action: 'stats' }, function (r) {
                if (r.success) {
                    $('#stat-toplam').text(r.toplam);
                    $('#stat-bugun').text(r.bugun);
                    $('#stat-pushli').text(r.pushli);
                    $('#stat-okunmamis').text(r.okunmamis);
                }
            }, 'json');
        }

        function listeYukle() {
            const filtre = {
                action: 'list',
                search: $('#filter_search').val() || '',
                tip: $('#filter_tip').val() || ''
            };
            $.post('', filtre, function (r) {
                dataTable.clear();
                if (r.success && r.data) {
                    r.data.forEach(function (b) {
                        const hedef = b.bildirim_kullanici_id
                            ? (b.hedef_ad || 'Kullanıcı #' + b.bildirim_kullanici_id)
                            : '<span class="badge text-bg-secondary">Genel</span>';
                        const push = b.bildirim_push_gonderildi == 1
                            ? '<i class="bi bi-check-circle text-success"></i>' : '<i class="bi bi-dash text-muted"></i>';
                        const okundu = b.bildirim_okundu == 1
                            ? '<i class="bi bi-check2-all text-success"></i>' : '<i class="bi bi-envelope text-warning"></i>';
                        dataTable.row.add([
                            '<strong>' + $('<div>').text(b.bildirim_baslik).html() + '</strong><br><small class="text-muted">' + $('<div>').text(b.bildirim_govde || '').html() + '</small>',
                            hedef,
                            tipRozet[b.bildirim_tip] || b.bildirim_tip,
                            push, okundu, b.olusturma
                        ]);
                    });
                }
                dataTable.draw();
            }, 'json');
        }

        $(document).ready(function () {
            $('#kullanici_idler').select2({ theme: 'bootstrap-5', placeholder: 'Kullanıcı ara ve seç...', width: '100%' });
            $('#departman_id').select2({ theme: 'bootstrap-5', width: '100%' });

            dataTable = $('#bildirimTable').DataTable({
                language: { url: '//cdn.datatables.net/plug-ins/1.13.6/i18n/tr.json' },
                order: [], columnDefs: [{ orderable: false, targets: [3, 4] }],
                pageLength: 10, lengthMenu: [[10, 25, 50, -1], [10, 25, 50, 'Tümü']]
            });

            // Hedef tipine göre alanları göster/gizle
            $('#hedef_tip').on('change', function () {
                const v = $(this).val();
                $('#grup_kullanici').toggleClass('d-none', v !== 'kullanici');
                $('#grup_departman').toggleClass('d-none', v !== 'departman');
            });

            // Gönder
            $('#gonderForm').on('submit', function (e) {
                e.preventDefault();
                const $btn = $(this).find('button[type=submit]');
                $btn.prop('disabled', true);
                $.post('', $(this).serialize() + '&action=gonder', function (r) {
                    if (r.success) {
                        showToast(r.message, 'success');
                        $('#gonderForm')[0].reset();
                        $('#kullanici_idler').val(null).trigger('change');
                        $('#departman_id').val('').trigger('change');
                        $('#hedef_tip').trigger('change');
                        statYukle(); listeYukle();
                    } else {
                        showToast(r.message || 'Gönderilemedi', 'error');
                    }
                }, 'json').fail(function () {
                    showToast('Sunucu hatası', 'error');
                }).always(function () { $btn.prop('disabled', false); });
            });

            $('#filterForm').on('submit', function (e) { e.preventDefault(); listeYukle(); });
            $('#clearFilters').on('click', function () {
                $('#filter_search').val(''); $('#filter_tip').val('');
                listeYukle();
            });

            statYukle();
            listeYukle();
        });
    </script>
</body>
</html>
