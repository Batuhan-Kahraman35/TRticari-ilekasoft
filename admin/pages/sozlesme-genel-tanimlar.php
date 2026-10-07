<?php
header('Content-Type: text/html; charset=utf-8');
require_once __DIR__ . '/../auth.php';
require_once __DIR__ . '/../includes/PageAuth.php';
requireAuth();

$user = Auth::user();
$db = Database::getInstance();

$currentPageFile = basename($_SERVER['PHP_SELF']);
$pagePermissions = PageAuth::checkPagePermissions($user['kullanici_id'], $user['departman_id'], $currentPageFile);
if (!$pagePermissions['has_access']) {
    PageAuth::accessDenied($pagePermissions['error'] ?? 'Bu sayfaya erisim yetkiniz bulunmamaktadır.');
}

$pageInfo = $db->fetchOne("
    SELECT s.sayfalar_sayfa_adi, s.sayfalar_aciklama, m.menuler_menu_adi as menu_adi
    FROM Menu_Sayfalar s LEFT JOIN Menuler m ON s.sayfalar_menu_id = m.menuler_id
    WHERE s.sayfalar_sayfa_url LIKE ? AND s.sayfalar_durum = 1
", ['%' . $currentPageFile]);

$pageTitle = $pageInfo['sayfalar_sayfa_adi'] ?? 'Sozlesme Genel Tanimlar';
$siteAyarlari = $db->fetchOne("SELECT TOP 1 site_ayarlari_site_title FROM dbo.tanim_site_ayarlari ORDER BY site_ayarlari_id DESC");
$siteTitle = $siteAyarlari['site_ayarlari_site_title'] ?? 'Ornek Yazilim Portal';

// AJAX islemleri
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json');
    $action = $_POST['action'] ?? '';

    try {
        switch ($action) {

            case 'stats':
                $sezon = $db->fetchOne("SELECT COUNT(*) as toplam, SUM(CASE WHEN sezon_durum=1 THEN 1 ELSE 0 END) as aktif FROM Sozlesme_Sezonlar");
                $grup  = $db->fetchOne("SELECT COUNT(*) as toplam, SUM(CASE WHEN ticari_grup_durum=1 THEN 1 ELSE 0 END) as aktif FROM Sozlesme_TicariGruplar");
                $uye   = $db->fetchOne("SELECT COUNT(*) as toplam, SUM(CASE WHEN uye_tipi_durum=1 THEN 1 ELSE 0 END) as aktif FROM Sozlesme_UyeTipleri");
                echo json_encode(['success' => true, 'sezon' => $sezon, 'grup' => $grup, 'uye' => $uye]);
                break;

            case 'sezon_list':
                $data = $db->fetchAll("SELECT * FROM Sozlesme_Sezonlar ORDER BY sezon_id ASC");
                echo json_encode(['success' => true, 'data' => $data]);
                break;

            case 'sezon_save':
                $id         = intval($_POST['sezon_id'] ?? 0);
                $ad         = trim($_POST['sezon_ad'] ?? '');
                $durum      = isset($_POST['sezon_durum']) ? 1 : 0;
                $varsayilan = isset($_POST['sezon_varsayilan']) ? 1 : 0;

                if (!$ad) { echo json_encode(['success' => false, 'message' => 'Ad zorunludur.']); break; }

                // Varsayılan isaretlendiyse once tum sezonlarin varsayilanini sıfırla (tek varsayılan garantisi)
                if ($varsayilan) {
                    $db->execute("UPDATE Sozlesme_Sezonlar SET sezon_varsayilan=0");
                }

                if ($id > 0) {
                    $db->execute("UPDATE Sozlesme_Sezonlar SET sezon_ad=?, sezon_durum=?, sezon_varsayilan=? WHERE sezon_id=?", [$ad, $durum, $varsayilan, $id]);
                    echo json_encode(['success' => true, 'message' => 'Sezon guncellendi.']);
                } else {
                    $db->execute("INSERT INTO Sozlesme_Sezonlar (sezon_ad, sezon_durum, sezon_varsayilan) VALUES (?,?,?)", [$ad, $durum, $varsayilan]);
                    echo json_encode(['success' => true, 'message' => 'Sezon eklendi.']);
                }
                break;

            case 'sezon_delete':
                $id = intval($_POST['id'] ?? 0);
                $fk = $db->fetchOne("SELECT COUNT(*) as cnt FROM Sozlesmeler WHERE sozlesme_sezon_id=?", [$id]);
                if ($fk['cnt'] > 0) { echo json_encode(['success' => false, 'message' => 'Bu sezona bagli sozlesme kayitlari var, silinemez.']); break; }
                $fk2 = $db->fetchOne("SELECT COUNT(*) as cnt FROM Sozlesme_StokHareketleri WHERE hareket_sezon_id=?", [$id]);
                if ($fk2['cnt'] > 0) { echo json_encode(['success' => false, 'message' => 'Bu sezona bagli stok hareketleri var, silinemez.']); break; }
                $db->execute("DELETE FROM Sozlesme_Sezonlar WHERE sezon_id=?", [$id]);
                echo json_encode(['success' => true, 'message' => 'Sezon silindi.']);
                break;

            case 'grup_list':
                $data = $db->fetchAll("SELECT * FROM Sozlesme_TicariGruplar ORDER BY ticari_grup_kod ASC");
                echo json_encode(['success' => true, 'data' => $data]);
                break;

            case 'grup_save':
                $id    = intval($_POST['grup_id'] ?? 0);
                $kod   = trim($_POST['ticari_grup_kod'] ?? '');
                $ad    = trim($_POST['ticari_grup_ad'] ?? '');
                $durum = isset($_POST['ticari_grup_durum']) ? 1 : 0;

                if (!$kod) { echo json_encode(['success' => false, 'message' => 'Kod zorunludur.']); break; }

                if ($id > 0) {
                    $db->execute("UPDATE Sozlesme_TicariGruplar SET ticari_grup_kod=?, ticari_grup_ad=?, ticari_grup_durum=? WHERE ticari_grup_id=?", [$kod, $ad, $durum, $id]);
                    echo json_encode(['success' => true, 'message' => 'Ticari grup guncellendi.']);
                } else {
                    $db->execute("INSERT INTO Sozlesme_TicariGruplar (ticari_grup_kod, ticari_grup_ad, ticari_grup_durum) VALUES (?,?,?)", [$kod, $ad, $durum]);
                    echo json_encode(['success' => true, 'message' => 'Ticari grup eklendi.']);
                }
                break;

            case 'grup_delete':
                $id = intval($_POST['id'] ?? 0);
                $fk = $db->fetchOne("SELECT COUNT(*) as cnt FROM Sozlesme_StokHareketleri WHERE hareket_ticari_grup_id=?", [$id]);
                if ($fk['cnt'] > 0) { echo json_encode(['success' => false, 'message' => 'Bu gruba bagli stok hareketleri var, silinemez.']); break; }
                $db->execute("DELETE FROM Sozlesme_TicariGruplar WHERE ticari_grup_id=?", [$id]);
                echo json_encode(['success' => true, 'message' => 'Ticari grup silindi.']);
                break;

            case 'uye_list':
                $data = $db->fetchAll("SELECT * FROM Sozlesme_UyeTipleri ORDER BY uye_tipi_id ASC");
                echo json_encode(['success' => true, 'data' => $data]);
                break;

            case 'uye_save':
                $id    = intval($_POST['uye_id'] ?? 0);
                $ad    = trim($_POST['uye_tipi_ad'] ?? '');
                $durum = isset($_POST['uye_tipi_durum']) ? 1 : 0;

                if (!$ad) { echo json_encode(['success' => false, 'message' => 'Ad zorunludur.']); break; }

                if ($id > 0) {
                    $db->execute("UPDATE Sozlesme_UyeTipleri SET uye_tipi_ad=?, uye_tipi_durum=? WHERE uye_tipi_id=?", [$ad, $durum, $id]);
                    echo json_encode(['success' => true, 'message' => 'Uye tipi guncellendi.']);
                } else {
                    $db->execute("INSERT INTO Sozlesme_UyeTipleri (uye_tipi_ad, uye_tipi_durum) VALUES (?,?)", [$ad, $durum]);
                    echo json_encode(['success' => true, 'message' => 'Uye tipi eklendi.']);
                }
                break;

            case 'uye_delete':
                $id = intval($_POST['id'] ?? 0);
                $fk = $db->fetchOne("SELECT COUNT(*) as cnt FROM Sozlesme_StokHareketleri WHERE hareket_uye_tipi_id=?", [$id]);
                if ($fk['cnt'] > 0) { echo json_encode(['success' => false, 'message' => 'Bu uye tipine bagli stok hareketleri var, silinemez.']); break; }
                $db->execute("DELETE FROM Sozlesme_UyeTipleri WHERE uye_tipi_id=?", [$id]);
                echo json_encode(['success' => true, 'message' => 'Uye tipi silindi.']);
                break;

            default:
                echo json_encode(['success' => false, 'message' => 'Gecersiz islem.']);
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
    <link rel="stylesheet" href="/Admin/assets/css/Adminlte.min.css">
    <link rel="stylesheet" href="/Admin/assets/css/custom.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.13.1/font/bootstrap-icons.min.css">
    <link href="https://cdn.datatables.net/1.13.7/css/dataTables.bootstrap5.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/sweetalert2@11/dist/sweetalert2.min.css" rel="stylesheet">
</head>
<body class="layout-fixed sidebar-expand-lg sidebar-open bg-body-tertiary">
<div class="app-wrapper">
<?php include __DIR__ . '/../includes/header.php'; ?>
<?php include __DIR__ . '/../includes/sidebar.php'; ?>

<main class="app-main">
<div class="app-content-header">
    <div class="container-fluid">
        <div class="row">
            <div class="col-sm-6"><h3 class="mb-0">S&#246;zle&#351;me Tan&#305;mlar&#305;</h3></div>
            <div class="col-sm-6">
                <ol class="breadcrumb float-sm-end">
                    <li class="breadcrumb-item"><a href="#">Ana Sayfa</a></li>
                    <li class="breadcrumb-item active">S&#246;zle&#351;me Tan&#305;mlar&#305;</li>
                </ol>
            </div>
        </div>
    </div>
</div>
<div class="app-content">
<div class="container-fluid">

    <!-- INFO BOXES -->
    <div class="row mb-3">
        <div class="col-md-3">
            <div class="info-box text-bg-primary">
                <span class="info-box-icon"><i class="bi bi-calendar3"></i></span>
                <div class="info-box-content">
                    <span class="info-box-text">Toplam Sezon</span>
                    <span class="info-box-number" id="stat_toplam_sezon">0</span>
                </div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="info-box text-bg-success">
                <span class="info-box-icon"><i class="bi bi-calendar-check"></i></span>
                <div class="info-box-content">
                    <span class="info-box-text">Aktif Sezon</span>
                    <span class="info-box-number" id="stat_aktif_sezon">0</span>
                </div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="info-box text-bg-info">
                <span class="info-box-icon"><i class="bi bi-diagram-3"></i></span>
                <div class="info-box-content">
                    <span class="info-box-text">Ticari Grup</span>
                    <span class="info-box-number" id="stat_toplam_grup">0</span>
                </div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="info-box text-bg-warning">
                <span class="info-box-icon"><i class="bi bi-person-badge"></i></span>
                <div class="info-box-content">
                    <span class="info-box-text">&#220;ye Tipi</span>
                    <span class="info-box-number" id="stat_toplam_uye">0</span>
                </div>
            </div>
        </div>
    </div>

    <!-- TABS -->
    <ul class="nav nav-tabs mb-3" id="mainTabs">
        <li class="nav-item">
            <button class="nav-link active" id="tab-sezon-btn" data-bs-toggle="tab" data-bs-target="#tab-sezon">
                <i class="bi bi-calendar3"></i> Sezonlar
            </button>
        </li>
        <li class="nav-item">
            <button class="nav-link" id="tab-grup-btn" data-bs-toggle="tab" data-bs-target="#tab-grup">
                <i class="bi bi-diagram-3"></i> Ticari Gruplar
            </button>
        </li>
        <li class="nav-item">
            <button class="nav-link" id="tab-uye-btn" data-bs-toggle="tab" data-bs-target="#tab-uye">
                <i class="bi bi-person-badge"></i> &#220;ye Tipleri
            </button>
        </li>
    </ul>

    <div class="tab-content">

        <!-- TAB: SEZONLAR -->
        <div class="tab-pane fade show active" id="tab-sezon">
            <div class="card card-primary card-outline">
                <div class="card-header">
                    <h3 class="card-title"><i class="bi bi-calendar3"></i> Sezonlar</h3>
                    <div class="card-tools">
                        <?php if (($pagePermissions['can_add'] || $pagePermissions['can_edit'])): ?>
                        <button class="btn btn-primary btn-sm" onclick="openSezonModal()">
                            <i class="bi bi-plus-lg"></i> Yeni Sezon
                        </button>
                        <?php endif; ?>
                    </div>
                </div>
                <div class="card-body">
                    <div class="table-responsive">
                        <table id="tableSezon" class="table table-bordered table-striped table-hover table-sm">
                            <thead>
                                <tr>
                                    <th>#</th>
                                    <th>Ad</th>
                                    <th>Durum</th>
                                    <th>Varsay&#305;lan</th>
                                    <th class="text-center">&#304;&#351;lem</th>
                                </tr>
                            </thead>
                            <tbody></tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>

        <!-- TAB: TICARI GRUPLAR -->
        <div class="tab-pane fade" id="tab-grup">
            <div class="card card-primary card-outline">
                <div class="card-header">
                    <h3 class="card-title"><i class="bi bi-diagram-3"></i> Ticari Gruplar</h3>
                    <div class="card-tools">
                        <?php if (($pagePermissions['can_add'] || $pagePermissions['can_edit'])): ?>
                        <button class="btn btn-primary btn-sm" onclick="openGrupModal()">
                            <i class="bi bi-plus-lg"></i> Yeni Grup
                        </button>
                        <?php endif; ?>
                    </div>
                </div>
                <div class="card-body">
                    <div class="table-responsive">
                        <table id="tableGrup" class="table table-bordered table-striped table-hover table-sm">
                            <thead>
                                <tr>
                                    <th>#</th>
                                    <th>Kod</th>
                                    <th>Ad</th>
                                    <th>Durum</th>
                                    <th class="text-center">&#304;&#351;lem</th>
                                </tr>
                            </thead>
                            <tbody></tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>

        <!-- TAB: UYE TIPLERI -->
        <div class="tab-pane fade" id="tab-uye">
            <div class="card card-primary card-outline">
                <div class="card-header">
                    <h3 class="card-title"><i class="bi bi-person-badge"></i> &#220;ye Tipleri</h3>
                    <div class="card-tools">
                        <?php if (($pagePermissions['can_add'] || $pagePermissions['can_edit'])): ?>
                        <button class="btn btn-primary btn-sm" onclick="openUyeModal()">
                            <i class="bi bi-plus-lg"></i> Yeni &#220;ye Tipi
                        </button>
                        <?php endif; ?>
                    </div>
                </div>
                <div class="card-body">
                    <div class="table-responsive">
                        <table id="tableUye" class="table table-bordered table-striped table-hover table-sm">
                            <thead>
                                <tr>
                                    <th>#</th>
                                    <th>Ad</th>
                                    <th>Durum</th>
                                    <th class="text-center">&#304;&#351;lem</th>
                                </tr>
                            </thead>
                            <tbody></tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>

    </div><!-- tab-content -->

</div><!-- container-fluid -->
</div><!-- app-content -->
</main><!-- app-main -->
</div><!-- app-wrapper -->

<!-- MODAL: SEZON -->
<div class="modal fade" id="sezonModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title"><span id="sezonModalTitle">Yeni Sezon</span></h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <form id="sezonForm">
                <div class="modal-body">
                    <input type="hidden" name="sezon_id" id="sezon_id" value="0">
                    <div class="mb-3">
                        <label class="form-label">Sezon Ad&#305; <span class="text-danger">*</span></label>
                        <input type="text" class="form-control" name="sezon_ad" id="sezon_ad" required>
                    </div>
                    <div class="form-check">
                        <input class="form-check-input" type="checkbox" name="sezon_durum" id="sezon_durum" value="1" checked>
                        <label class="form-check-label" for="sezon_durum">Aktif</label>
                    </div>
                    <div class="form-check">
                        <input class="form-check-input" type="checkbox" name="sezon_varsayilan" id="sezon_varsayilan" value="1">
                        <label class="form-check-label" for="sezon_varsayilan">Varsay&#305;lan Sezon</label>
                        <div class="form-text">Yeni s&#246;zle&#351;me formlar&#305;nda otomatik se&#231;ili gelir. Tek sezon varsay&#305;lan olabilir.</div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">&#304;ptal</button>
                    <button type="submit" class="btn btn-primary"><i class="bi bi-save"></i> Kaydet</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- MODAL: TICARI GRUP -->
<div class="modal fade" id="grupModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title"><span id="grupModalTitle">Yeni Ticari Grup</span></h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <form id="grupForm">
                <div class="modal-body">
                    <input type="hidden" name="grup_id" id="grup_id" value="0">
                    <div class="mb-3">
                        <label class="form-label">Kod <span class="text-danger">*</span></label>
                        <input type="text" class="form-control" name="ticari_grup_kod" id="ticari_grup_kod" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Ad</label>
                        <input type="text" class="form-control" name="ticari_grup_ad" id="ticari_grup_ad">
                    </div>
                    <div class="form-check">
                        <input class="form-check-input" type="checkbox" name="ticari_grup_durum" id="ticari_grup_durum" value="1" checked>
                        <label class="form-check-label" for="ticari_grup_durum">Aktif</label>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">&#304;ptal</button>
                    <button type="submit" class="btn btn-primary"><i class="bi bi-save"></i> Kaydet</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- MODAL: UYE TIPI -->
<div class="modal fade" id="uyeModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title"><span id="uyeModalTitle">Yeni &#220;ye Tipi</span></h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <form id="uyeForm">
                <div class="modal-body">
                    <input type="hidden" name="uye_id" id="uye_id" value="0">
                    <div class="mb-3">
                        <label class="form-label">Ad <span class="text-danger">*</span></label>
                        <input type="text" class="form-control" name="uye_tipi_ad" id="uye_tipi_ad" required>
                    </div>
                    <div class="form-check">
                        <input class="form-check-input" type="checkbox" name="uye_tipi_durum" id="uye_tipi_durum" value="1" checked>
                        <label class="form-check-label" for="uye_tipi_durum">Aktif</label>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">&#304;ptal</button>
                    <button type="submit" class="btn btn-primary"><i class="bi bi-save"></i> Kaydet</button>
                </div>
            </form>
        </div>
    </div>
</div>

<?php include __DIR__ . '/../includes/footer.php'; ?>
<script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js" crossorigin="anonymous"></script>
<script src="https://cdn.datatables.net/1.13.7/js/jquery.dataTables.min.js"></script>
<script src="https://cdn.datatables.net/1.13.7/js/dataTables.bootstrap5.min.js"></script>
<script src="/Admin/assets/js/Adminlte.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11/dist/sweetalert2.all.min.js"></script>
<script src="/Admin/assets/js/custom.js"></script>
<script>
const canWrite = <?= ($pagePermissions['can_add'] || $pagePermissions['can_edit']) ? 'true' : 'false' ?>;
let sezonModal, grupModal, uyeModal;
let tableSezon, tableGrup, tableUye;

$(document).ready(function () {
    sezonModal = new bootstrap.Modal(document.getElementById('sezonModal'));
    grupModal  = new bootstrap.Modal(document.getElementById('grupModal'));
    uyeModal   = new bootstrap.Modal(document.getElementById('uyeModal'));

    tableSezon = $('#tableSezon').DataTable({ language: { url: '//cdn.datatables.net/plug-ins/1.13.7/i18n/tr.json' }, pageLength: 25 });
    tableGrup  = $('#tableGrup').DataTable({ language: { url: '//cdn.datatables.net/plug-ins/1.13.7/i18n/tr.json' }, pageLength: 25 });
    tableUye   = $('#tableUye').DataTable({ language: { url: '//cdn.datatables.net/plug-ins/1.13.7/i18n/tr.json' }, pageLength: 25 });

    loadStats();
    loadSezonlar();

    document.getElementById('tab-grup-btn').addEventListener('shown.bs.tab', loadGruplar);
    document.getElementById('tab-uye-btn').addEventListener('shown.bs.tab', loadUyeTipleri);

    $('#sezonForm').on('submit', function (e) { e.preventDefault(); saveSezon(); });
    $('#grupForm').on('submit', function (e) { e.preventDefault(); saveGrup(); });
    $('#uyeForm').on('submit', function (e) { e.preventDefault(); saveUye(); });
});

function loadStats() {
    $.post('', { action: 'stats' }, function (r) {
        if (r.success) {
            $('#stat_toplam_sezon').text(r.sezon.toplam || 0);
            $('#stat_aktif_sezon').text(r.sezon.aktif || 0);
            $('#stat_toplam_grup').text(r.grup.toplam || 0);
            $('#stat_toplam_uye').text(r.uye.toplam || 0);
        }
    });
}

// ====== SEZONLAR ======
function loadSezonlar() {
    $.post('', { action: 'sezon_list' }, function (r) {
        tableSezon.clear();
        if (r.success && r.data.length > 0) {
            r.data.forEach(function (d) {
                const badge  = d.sezon_durum == 1 ? '<span class="badge text-bg-success">Aktif</span>' : '<span class="badge text-bg-secondary">Pasif</span>';
                const vsyBadge = d.sezon_varsayilan == 1 ? '<span class="badge text-bg-primary"><i class="bi bi-star-fill"></i> Varsayılan</span>' : '<span class="text-muted">-</span>';
                const islem  = canWrite ? '<button class="btn btn-warning btn-xs py-0 px-1" onclick="editSezon(' + d.sezon_id + ')"><i class="bi bi-pencil"></i></button> <button class="btn btn-danger btn-xs py-0 px-1" onclick="deleteSezon(' + d.sezon_id + ', \'' + d.sezon_ad.replace(/'/g, "\\'") + '\')"><i class="bi bi-trash"></i></button>' : '-';
                tableSezon.row.add([d.sezon_id, d.sezon_ad, badge, vsyBadge, '<div class="d-flex gap-1 justify-content-center">' + islem + '</div>']);
            });
        }
        tableSezon.draw();
    });
}

function openSezonModal() {
    $('#sezonForm')[0].reset();
    $('#sezon_id').val(0);
    $('#sezonModalTitle').text('Yeni Sezon');
    $('#sezon_durum').prop('checked', true);
    $('#sezon_varsayilan').prop('checked', false);
    sezonModal.show();
}

function editSezon(id) {
    $.post('', { action: 'sezon_list' }, function (r) {
        if (r.success) {
            const d = r.data.find(x => x.sezon_id == id);
            if (!d) return;
            $('#sezon_id').val(d.sezon_id);
            $('#sezon_ad').val(d.sezon_ad);
            $('#sezon_durum').prop('checked', d.sezon_durum == 1);
            $('#sezon_varsayilan').prop('checked', d.sezon_varsayilan == 1);
            $('#sezonModalTitle').text('Sezon D\u00FCzenle');
            sezonModal.show();
        }
    });
}

function saveSezon() {
    const data = { action: 'sezon_save' };
    $('#sezonForm').serializeArray().forEach(f => data[f.name] = f.value);
    if ($('#sezon_durum').is(':checked')) data['sezon_durum'] = 1;
    $.post('', data, function (r) {
        if (r.success) { showToast(r.message, 'success'); sezonModal.hide(); loadSezonlar(); loadStats(); }
        else { showToast(r.message, 'error'); }
    });
}

function deleteSezon(id, ad) {
    confirmAction('"' + ad + '" sezonunu silmek istedi\u011Finize emin misiniz?', 'Bu i\u015Flem geri al\u0131namaz!', function () {
        $.post('', { action: 'sezon_delete', id: id }, function (r) {
            if (r.success) { showSuccess('Silindi!', r.message); loadSezonlar(); loadStats(); }
            else { showError('Hata!', r.message); }
        });
    });
}

// ====== TICARI GRUPLAR ======
function loadGruplar() {
    $.post('', { action: 'grup_list' }, function (r) {
        tableGrup.clear();
        if (r.success && r.data.length > 0) {
            r.data.forEach(function (d) {
                const badge = d.ticari_grup_durum == 1 ? '<span class="badge text-bg-success">Aktif</span>' : '<span class="badge text-bg-secondary">Pasif</span>';
                const islem = canWrite ? '<button class="btn btn-warning btn-xs py-0 px-1" onclick="editGrup(' + d.ticari_grup_id + ')"><i class="bi bi-pencil"></i></button> <button class="btn btn-danger btn-xs py-0 px-1" onclick="deleteGrup(' + d.ticari_grup_id + ', \'' + d.ticari_grup_kod.replace(/'/g, "\\'") + '\')"><i class="bi bi-trash"></i></button>' : '-';
                tableGrup.row.add([d.ticari_grup_id, '<code>' + d.ticari_grup_kod + '</code>', d.ticari_grup_ad || '-', badge, '<div class="d-flex gap-1 justify-content-center">' + islem + '</div>']);
            });
        }
        tableGrup.draw();
    });
}

function openGrupModal() {
    $('#grupForm')[0].reset();
    $('#grup_id').val(0);
    $('#grupModalTitle').text('Yeni Ticari Grup');
    $('#ticari_grup_durum').prop('checked', true);
    grupModal.show();
}

function editGrup(id) {
    $.post('', { action: 'grup_list' }, function (r) {
        if (r.success) {
            const d = r.data.find(x => x.ticari_grup_id == id);
            if (!d) return;
            $('#grup_id').val(d.ticari_grup_id);
            $('#ticari_grup_kod').val(d.ticari_grup_kod);
            $('#ticari_grup_ad').val(d.ticari_grup_ad);
            $('#ticari_grup_durum').prop('checked', d.ticari_grup_durum == 1);
            $('#grupModalTitle').text('Ticari Grup D\u00FCzenle');
            grupModal.show();
        }
    });
}

function saveGrup() {
    const data = { action: 'grup_save' };
    $('#grupForm').serializeArray().forEach(f => data[f.name] = f.value);
    if ($('#ticari_grup_durum').is(':checked')) data['ticari_grup_durum'] = 1;
    $.post('', data, function (r) {
        if (r.success) { showToast(r.message, 'success'); grupModal.hide(); loadGruplar(); loadStats(); }
        else { showToast(r.message, 'error'); }
    });
}

function deleteGrup(id, kod) {
    confirmAction('"' + kod + '" grubunu silmek istedi\u011Finize emin misiniz?', 'Bu i\u015Flem geri al\u0131namaz!', function () {
        $.post('', { action: 'grup_delete', id: id }, function (r) {
            if (r.success) { showSuccess('Silindi!', r.message); loadGruplar(); loadStats(); }
            else { showError('Hata!', r.message); }
        });
    });
}

// ====== UYE TIPLERI ======
function loadUyeTipleri() {
    $.post('', { action: 'uye_list' }, function (r) {
        tableUye.clear();
        if (r.success && r.data.length > 0) {
            r.data.forEach(function (d) {
                const badge = d.uye_tipi_durum == 1 ? '<span class="badge text-bg-success">Aktif</span>' : '<span class="badge text-bg-secondary">Pasif</span>';
                const islem = canWrite ? '<button class="btn btn-warning btn-xs py-0 px-1" onclick="editUye(' + d.uye_tipi_id + ')"><i class="bi bi-pencil"></i></button> <button class="btn btn-danger btn-xs py-0 px-1" onclick="deleteUye(' + d.uye_tipi_id + ', \'' + d.uye_tipi_ad.replace(/'/g, "\\'") + '\')"><i class="bi bi-trash"></i></button>' : '-';
                tableUye.row.add([d.uye_tipi_id, d.uye_tipi_ad, badge, '<div class="d-flex gap-1 justify-content-center">' + islem + '</div>']);
            });
        }
        tableUye.draw();
    });
}

function openUyeModal() {
    $('#uyeForm')[0].reset();
    $('#uye_id').val(0);
    $('#uyeModalTitle').text('Yeni \u00DCye Tipi');
    $('#uye_tipi_durum').prop('checked', true);
    uyeModal.show();
}

function editUye(id) {
    $.post('', { action: 'uye_list' }, function (r) {
        if (r.success) {
            const d = r.data.find(x => x.uye_tipi_id == id);
            if (!d) return;
            $('#uye_id').val(d.uye_tipi_id);
            $('#uye_tipi_ad').val(d.uye_tipi_ad);
            $('#uye_tipi_durum').prop('checked', d.uye_tipi_durum == 1);
            $('#uyeModalTitle').text('\u00DCye Tipi D\u00FCzenle');
            uyeModal.show();
        }
    });
}

function saveUye() {
    const data = { action: 'uye_save' };
    $('#uyeForm').serializeArray().forEach(f => data[f.name] = f.value);
    if ($('#uye_tipi_durum').is(':checked')) data['uye_tipi_durum'] = 1;
    $.post('', data, function (r) {
        if (r.success) { showToast(r.message, 'success'); uyeModal.hide(); loadUyeTipleri(); loadStats(); }
        else { showToast(r.message, 'error'); }
    });
}

function deleteUye(id, ad) {
    confirmAction('"' + ad + '" \u00FCye tipini silmek istedi\u011Finize emin misiniz?', 'Bu i\u015Flem geri al\u0131namaz!', function () {
        $.post('', { action: 'uye_delete', id: id }, function (r) {
            if (r.success) { showSuccess('Silindi!', r.message); loadUyeTipleri(); loadStats(); }
            else { showError('Hata!', r.message); }
        });
    });
}
</script>
</body>
</html>
