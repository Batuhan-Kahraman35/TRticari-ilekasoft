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

$pageTitle = $pageInfo['sayfalar_sayfa_adi'] ?? 'Odeme Tanimlari';
$siteAyarlari = $db->fetchOne("SELECT TOP 1 site_ayarlari_site_title FROM dbo.tanim_site_ayarlari ORDER BY site_ayarlari_id DESC");
$siteTitle = $siteAyarlari['site_ayarlari_site_title'] ?? 'Ornek Yazilim Portal';

// AJAX islemleri
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json');
    $action = $_POST['action'] ?? '';

    try {
        switch ($action) {

            case 'stats':
                $durumStats = $db->fetchOne("SELECT COUNT(*) as toplam, SUM(CASE WHEN odeme_durum_final=1 THEN 1 ELSE 0 END) as final_adet FROM Sozlesme_OdemeDurumlari");
                $tipStats   = $db->fetchOne("SELECT COUNT(*) as toplam, SUM(CASE WHEN odeme_tipi_durum=1 THEN 1 ELSE 0 END) as aktif_adet FROM Sozlesme_OdemeTipleri");
                echo json_encode(['success' => true, 'durum' => $durumStats, 'tip' => $tipStats]);
                break;

            case 'durum_list':
                $data = $db->fetchAll("SELECT * FROM Sozlesme_OdemeDurumlari ORDER BY odeme_durum_sira ASC, odeme_durum_id ASC");
                echo json_encode(['success' => true, 'data' => $data]);
                break;

            case 'durum_save':
                $id      = intval($_POST['durum_id'] ?? 0);
                $kod     = trim($_POST['odeme_durum_kod'] ?? '');
                $ad      = trim($_POST['odeme_durum_ad'] ?? '');
                $hedef   = trim($_POST['odeme_durum_hedef_tipi'] ?? '');
                $zorunlu = isset($_POST['odeme_durum_hedef_zorunlu']) ? 1 : 0;
                $renk    = trim($_POST['odeme_durum_renk'] ?? '');
                $icon    = trim($_POST['odeme_durum_icon'] ?? '');
                $final   = isset($_POST['odeme_durum_final']) ? 1 : 0;
                $sira    = intval($_POST['odeme_durum_sira'] ?? 0);

                if (!$kod || !$ad) { echo json_encode(['success' => false, 'message' => 'Kod ve Ad zorunludur.']); break; }

                if ($id > 0) {
                    $db->execute("UPDATE Sozlesme_OdemeDurumlari SET odeme_durum_kod=?, odeme_durum_ad=?, odeme_durum_hedef_tipi=?, odeme_durum_hedef_zorunlu=?, odeme_durum_renk=?, odeme_durum_icon=?, odeme_durum_final=?, odeme_durum_sira=? WHERE odeme_durum_id=?",
                        [$kod, $ad, $hedef, $zorunlu, $renk, $icon, $final, $sira, $id]);
                    echo json_encode(['success' => true, 'message' => 'Odeme durumu guncellendi.']);
                } else {
                    $db->execute("INSERT INTO Sozlesme_OdemeDurumlari (odeme_durum_kod, odeme_durum_ad, odeme_durum_hedef_tipi, odeme_durum_hedef_zorunlu, odeme_durum_renk, odeme_durum_icon, odeme_durum_final, odeme_durum_sira) VALUES (?,?,?,?,?,?,?,?)",
                        [$kod, $ad, $hedef, $zorunlu, $renk, $icon, $final, $sira]);
                    echo json_encode(['success' => true, 'message' => 'Odeme durumu eklendi.']);
                }
                break;

            case 'durum_delete':
                $id = intval($_POST['id'] ?? 0);
                $fk = $db->fetchOne("SELECT COUNT(*) as cnt FROM Sozlesme_Odemeler WHERE odeme_durum_id=?", [$id]);
                if ($fk['cnt'] > 0) { echo json_encode(['success' => false, 'message' => 'Bu duruma bagli odeme kayitlari var, silinemez.']); break; }
                $db->execute("DELETE FROM Sozlesme_OdemeDurumlari WHERE odeme_durum_id=?", [$id]);
                echo json_encode(['success' => true, 'message' => 'Odeme durumu silindi.']);
                break;

            case 'tip_list':
                $data = $db->fetchAll("
                    SELECT t.*, d.odeme_durum_ad as varsayilan_durum_adi
                    FROM Sozlesme_OdemeTipleri t
                    LEFT JOIN Sozlesme_OdemeDurumlari d ON t.odeme_tipi_varsayilan_durum_id = d.odeme_durum_id
                    ORDER BY t.odeme_tipi_sira ASC, t.odeme_tipi_id ASC
                ");
                echo json_encode(['success' => true, 'data' => $data]);
                break;

            case 'tip_save':
                $id              = intval($_POST['tip_id'] ?? 0);
                $ad              = trim($_POST['odeme_tipi_ad'] ?? '');
                $hedef           = trim($_POST['odeme_tipi_hedef'] ?? '');
                $varsayilanDurum = $_POST['odeme_tipi_varsayilan_durum_id'] !== '' ? intval($_POST['odeme_tipi_varsayilan_durum_id']) : null;
                $onek            = trim($_POST['odeme_tipi_dosya_onek'] ?? '');
                $sira            = intval($_POST['odeme_tipi_sira'] ?? 0);
                $durum           = isset($_POST['odeme_tipi_durum']) ? 1 : 0;

                if (!$ad) { echo json_encode(['success' => false, 'message' => 'Ad zorunludur.']); break; }

                if ($id > 0) {
                    $db->execute("UPDATE Sozlesme_OdemeTipleri SET odeme_tipi_ad=?, odeme_tipi_hedef=?, odeme_tipi_varsayilan_durum_id=?, odeme_tipi_dosya_onek=?, odeme_tipi_sira=?, odeme_tipi_durum=? WHERE odeme_tipi_id=?",
                        [$ad, $hedef, $varsayilanDurum, $onek, $sira, $durum, $id]);
                    echo json_encode(['success' => true, 'message' => 'Odeme tipi guncellendi.']);
                } else {
                    $db->execute("INSERT INTO Sozlesme_OdemeTipleri (odeme_tipi_ad, odeme_tipi_hedef, odeme_tipi_varsayilan_durum_id, odeme_tipi_dosya_onek, odeme_tipi_sira, odeme_tipi_durum) VALUES (?,?,?,?,?,?)",
                        [$ad, $hedef, $varsayilanDurum, $onek, $sira, $durum]);
                    echo json_encode(['success' => true, 'message' => 'Odeme tipi eklendi.']);
                }
                break;

            case 'tip_delete':
                $id = intval($_POST['id'] ?? 0);
                $fk = $db->fetchOne("SELECT COUNT(*) as cnt FROM Sozlesme_Odemeler WHERE odeme_tipi_id=?", [$id]);
                if ($fk['cnt'] > 0) { echo json_encode(['success' => false, 'message' => 'Bu tipe bagli odeme kayitlari var, silinemez.']); break; }
                $db->execute("DELETE FROM Sozlesme_OdemeTipleri WHERE odeme_tipi_id=?", [$id]);
                echo json_encode(['success' => true, 'message' => 'Odeme tipi silindi.']);
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
    <link href="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/select2-bootstrap-5-theme@1.3.0/dist/select2-bootstrap-5-theme.min.css" rel="stylesheet">
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
            <div class="col-sm-6"><h3 class="mb-0">&#214;deme Tan&#305;mlar&#305;</h3></div>
            <div class="col-sm-6">
                <ol class="breadcrumb float-sm-end">
                    <li class="breadcrumb-item"><a href="#">Ana Sayfa</a></li>
                    <li class="breadcrumb-item active">&#214;deme Tan&#305;mlar&#305;</li>
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
                <span class="info-box-icon"><i class="bi bi-arrow-left-right"></i></span>
                <div class="info-box-content">
                    <span class="info-box-text">Toplam Durum</span>
                    <span class="info-box-number" id="stat_toplam_durum">0</span>
                </div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="info-box text-bg-success">
                <span class="info-box-icon"><i class="bi bi-check-circle"></i></span>
                <div class="info-box-content">
                    <span class="info-box-text">Final Durum</span>
                    <span class="info-box-number" id="stat_final_durum">0</span>
                </div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="info-box text-bg-info">
                <span class="info-box-icon"><i class="bi bi-wallet2"></i></span>
                <div class="info-box-content">
                    <span class="info-box-text">Toplam Tip</span>
                    <span class="info-box-number" id="stat_toplam_tip">0</span>
                </div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="info-box text-bg-warning">
                <span class="info-box-icon"><i class="bi bi-toggle-on"></i></span>
                <div class="info-box-content">
                    <span class="info-box-text">Aktif Tip</span>
                    <span class="info-box-number" id="stat_aktif_tip">0</span>
                </div>
            </div>
        </div>
    </div>

    <!-- TABS -->
    <ul class="nav nav-tabs mb-3" id="mainTabs">
        <li class="nav-item">
            <button class="nav-link active" id="tab-durum-btn" data-bs-toggle="tab" data-bs-target="#tab-durum">
                <i class="bi bi-arrow-left-right"></i> &#214;deme Durumlar&#305;
            </button>
        </li>
        <li class="nav-item">
            <button class="nav-link" id="tab-tip-btn" data-bs-toggle="tab" data-bs-target="#tab-tip">
                <i class="bi bi-wallet2"></i> &#214;deme Tipleri
            </button>
        </li>
    </ul>

    <div class="tab-content">
        <!-- TAB: ODEME DURUMLARI -->
        <div class="tab-pane fade show active" id="tab-durum">
            <div class="card card-primary card-outline">
                <div class="card-header">
                    <h3 class="card-title"><i class="bi bi-arrow-left-right"></i> &#214;deme Durumlar&#305;</h3>
                    <div class="card-tools">
                        <?php if ($pagePermissions['can_add'] || $pagePermissions['can_edit']): ?>
                        <button class="btn btn-primary btn-sm" onclick="openDurumModal()">
                            <i class="bi bi-plus-lg"></i> Yeni Durum
                        </button>
                        <?php endif; ?>
                    </div>
                </div>
                <div class="card-body">
                    <div class="table-responsive">
                        <table id="tableDurum" class="table table-bordered table-striped table-hover table-sm">
                            <thead>
                                <tr>
                                    <th>#</th>
                                    <th>Kod</th>
                                    <th>Ad</th>
                                    <th>Hedef Tipi</th>
                                    <th>Zorunlu</th>
                                    <th>Renk</th>
                                    <th>&#304;kon</th>
                                    <th>Final</th>
                                    <th>S&#305;ra</th>
                                    <th class="text-center">&#304;&#351;lem</th>
                                </tr>
                            </thead>
                            <tbody></tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>

        <!-- TAB: ODEME TIPLERI -->
        <div class="tab-pane fade" id="tab-tip">
            <div class="card card-primary card-outline">
                <div class="card-header">
                    <h3 class="card-title"><i class="bi bi-wallet2"></i> &#214;deme Tipleri</h3>
                    <div class="card-tools">
                        <?php if ($pagePermissions['can_add'] || $pagePermissions['can_edit']): ?>
                        <button class="btn btn-primary btn-sm" onclick="openTipModal()">
                            <i class="bi bi-plus-lg"></i> Yeni Tip
                        </button>
                        <?php endif; ?>
                    </div>
                </div>
                <div class="card-body">
                    <div class="table-responsive">
                        <table id="tableTip" class="table table-bordered table-striped table-hover table-sm">
                            <thead>
                                <tr>
                                    <th>#</th>
                                    <th>Ad</th>
                                    <th>Hedef</th>
                                    <th>Varsa&#305;lan Durum</th>
                                    <th>Dosya &#214;nek</th>
                                    <th>S&#305;ra</th>
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

        <?php include __DIR__ . '/../includes/footer.php'; ?>
    </div><!-- app-wrapper -->

<!-- MODAL: ODEME DURUMU -->
<div class="modal fade" id="durumModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title"><span id="durumModalTitle">Yeni &#214;deme Durumu</span></h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <form id="durumForm">
                <div class="modal-body">
                    <input type="hidden" name="durum_id" id="durum_id" value="0">
                    <div class="row g-3">
                        <div class="col-md-4">
                            <label class="form-label">Kod <span class="text-danger">*</span></label>
                            <input type="text" class="form-control" name="odeme_durum_kod" id="odeme_durum_kod" required>
                        </div>
                        <div class="col-md-8">
                            <label class="form-label">Ad <span class="text-danger">*</span></label>
                            <input type="text" class="form-control" name="odeme_durum_ad" id="odeme_durum_ad" required>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Hedef Tipi</label>
                            <select class="form-select" name="odeme_durum_hedef_tipi" id="odeme_durum_hedef_tipi">
                                <option value="">Se&#231;iniz...</option>
                                <option value="SOZLESME">S&#246;zle&#351;me</option>
                                <option value="CARI">Cari</option>
                                <option value="HER_IKISI">Her &#304;kisi</option>
                            </select>
                        </div>
                        <div class="col-md-6 d-flex align-items-end">
                            <div class="form-check me-3">
                                <input class="form-check-input" type="checkbox" name="odeme_durum_hedef_zorunlu" id="odeme_durum_hedef_zorunlu" value="1">
                                <label class="form-check-label" for="odeme_durum_hedef_zorunlu">Hedef Zorunlu</label>
                            </div>
                            <div class="form-check">
                                <input class="form-check-input" type="checkbox" name="odeme_durum_final" id="odeme_durum_final" value="1">
                                <label class="form-check-label" for="odeme_durum_final">Final Durum</label>
                            </div>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">Renk (CSS s&#305;n&#305;f&#305;)</label>
                            <select class="form-select" name="odeme_durum_renk" id="odeme_durum_renk">
                                <option value="">Se&#231;iniz...</option>
                                <option value="primary">primary (Mavi)</option>
                                <option value="success">success (Ye&#351;il)</option>
                                <option value="danger">danger (K&#305;rm&#305;z&#305;)</option>
                                <option value="warning">warning (Sar&#305;)</option>
                                <option value="info">info (Turkuaz)</option>
                                <option value="secondary">secondary (Gri)</option>
                            </select>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">&#304;kon (Bootstrap Icons)</label>
                            <input type="text" class="form-control" name="odeme_durum_icon" id="odeme_durum_icon" placeholder="bi-check-circle">
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">S&#305;ra</label>
                            <input type="number" class="form-control" name="odeme_durum_sira" id="odeme_durum_sira" value="0">
                        </div>
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

<!-- MODAL: ODEME TIPI -->
<div class="modal fade" id="tipModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title"><span id="tipModalTitle">Yeni &#214;deme Tipi</span></h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <form id="tipForm">
                <div class="modal-body">
                    <input type="hidden" name="tip_id" id="tip_id" value="0">
                    <div class="row g-3">
                        <div class="col-md-8">
                            <label class="form-label">Ad <span class="text-danger">*</span></label>
                            <input type="text" class="form-control" name="odeme_tipi_ad" id="odeme_tipi_ad" required>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">Hedef</label>
                            <select class="form-select" name="odeme_tipi_hedef" id="odeme_tipi_hedef">
                                <option value="">Se&#231;iniz...</option>
                                <option value="SOZLESME">S&#246;zle&#351;me</option>
                                <option value="CARI">Cari</option>
                                <option value="HER_IKISI">Her &#304;kisi</option>
                            </select>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Varsa&#305;lan Durum</label>
                            <select class="form-select" name="odeme_tipi_varsayilan_durum_id" id="odeme_tipi_varsayilan_durum_id">
                                <option value="">Se&#231;iniz...</option>
                            </select>
                        </div>
                        <div class="col-md-3">
                            <label class="form-label">Dosya &#214;nek</label>
                            <input type="text" class="form-control" name="odeme_tipi_dosya_onek" id="odeme_tipi_dosya_onek" placeholder="FAT">
                        </div>
                        <div class="col-md-3">
                            <label class="form-label">S&#305;ra</label>
                            <input type="number" class="form-control" name="odeme_tipi_sira" id="odeme_tipi_sira" value="0">
                        </div>
                        <div class="col-md-12">
                            <div class="form-check">
                                <input class="form-check-input" type="checkbox" name="odeme_tipi_durum" id="odeme_tipi_durum" value="1" checked>
                                <label class="form-check-label" for="odeme_tipi_durum">Aktif</label>
                            </div>
                        </div>
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

<script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js" crossorigin="anonymous"></script>
<script src="https://cdn.datatables.net/1.13.7/js/jquery.dataTables.min.js"></script>
<script src="https://cdn.datatables.net/1.13.7/js/dataTables.bootstrap5.min.js"></script>
<script src="/Admin/assets/js/Adminlte.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11/dist/sweetalert2.all.min.js"></script>
<script src="/Admin/assets/js/custom.js"></script>
<script>
const canWrite = <?= ($pagePermissions['can_add'] || $pagePermissions['can_edit']) ? 'true' : 'false' ?>;
let durumModal, tipModal;
let tableDurum, tableTip;

$(document).ready(function () {
    durumModal = new bootstrap.Modal(document.getElementById('durumModal'));
    tipModal   = new bootstrap.Modal(document.getElementById('tipModal'));

    $('#odeme_durum_hedef_tipi, #odeme_durum_renk').select2({ theme: 'bootstrap-5', dropdownParent: $('#durumModal'), placeholder: 'Se\u00E7iniz...', allowClear: true });
    $('#odeme_tipi_hedef').select2({ theme: 'bootstrap-5', dropdownParent: $('#tipModal'), placeholder: 'Se\u00E7iniz...', allowClear: true });
    $('#odeme_tipi_varsayilan_durum_id').select2({ theme: 'bootstrap-5', dropdownParent: $('#tipModal'), placeholder: 'Se\u00E7iniz...', allowClear: true });

    tableDurum = $('#tableDurum').DataTable({
        language: { url: '//cdn.datatables.net/plug-ins/1.13.7/i18n/tr.json' },
        pageLength: 25, order: [[8, 'asc']]
    });
    tableTip = $('#tableTip').DataTable({
        language: { url: '//cdn.datatables.net/plug-ins/1.13.7/i18n/tr.json' },
        pageLength: 25, order: [[5, 'asc']]
    });

    loadStats();
    loadDurumlar();

    document.getElementById('tab-tip-btn').addEventListener('shown.bs.tab', function () {
        loadTipler();
        loadDurumSelectOptions();
    });

    $('#durumForm').on('submit', function (e) { e.preventDefault(); saveDurum(); });
    $('#tipForm').on('submit', function (e) { e.preventDefault(); saveTip(); });
});

function loadStats() {
    $.post('', { action: 'stats' }, function (r) {
        if (r.success) {
            $('#stat_toplam_durum').text(r.durum.toplam || 0);
            $('#stat_final_durum').text(r.durum.final_adet || 0);
            $('#stat_toplam_tip').text(r.tip.toplam || 0);
            $('#stat_aktif_tip').text(r.tip.aktif_adet || 0);
        }
    });
}

function loadDurumlar() {
    $.post('', { action: 'durum_list' }, function (r) {
        tableDurum.clear();
        if (r.success && r.data.length > 0) {
            r.data.forEach(function (d) {
                const renkBadge   = d.odeme_durum_renk ? '<span class="badge text-bg-' + d.odeme_durum_renk + '"><i class="bi ' + (d.odeme_durum_icon || '') + '"></i> ' + d.odeme_durum_renk + '</span>' : '-';
                const finalBadge  = d.odeme_durum_final == 1 ? '<span class="badge text-bg-success">Evet</span>' : '<span class="badge text-bg-secondary">Hay\u0131r</span>';
                const zorunluBadge = d.odeme_durum_hedef_zorunlu == 1 ? '<i class="bi bi-check-lg text-success"></i>' : '<i class="bi bi-x-lg text-danger"></i>';
                const islemler = canWrite ? '<button class="btn btn-warning btn-sm" onclick="editDurum(' + d.odeme_durum_id + ')" title="D\u00FCzenle"><i class="bi bi-pencil"></i> D\u00FCzenle</button> <button class="btn btn-danger btn-sm" onclick="deleteDurum(' + d.odeme_durum_id + ', \'' + d.odeme_durum_ad.replace(/'/g, "\\'") + '\')" title="Sil"><i class="bi bi-trash"></i> Sil</button>' : '-';
                tableDurum.row.add([
                    d.odeme_durum_id,
                    '<code>' + d.odeme_durum_kod + '</code>',
                    d.odeme_durum_ad,
                    d.odeme_durum_hedef_tipi || '-',
                    zorunluBadge,
                    renkBadge,
                    d.odeme_durum_icon ? '<code>' + d.odeme_durum_icon + '</code>' : '-',
                    finalBadge,
                    d.odeme_durum_sira,
                    '<div class="d-flex gap-1 justify-content-center">' + islemler + '</div>'
                ]);
            });
        }
        tableDurum.draw();
    });
}

function openDurumModal() {
    $('#durumForm')[0].reset();
    $('#durum_id').val(0);
    $('#durumModalTitle').text('Yeni \u00D6deme Durumu');
    $('#odeme_durum_hedef_tipi').val('').trigger('change.select2');
    $('#odeme_durum_renk').val('').trigger('change.select2');
    $('#odeme_durum_hedef_zorunlu, #odeme_durum_final').prop('checked', false);
    durumModal.show();
}

function editDurum(id) {
    $.post('', { action: 'durum_list' }, function (r) {
        if (r.success) {
            const d = r.data.find(x => x.odeme_durum_id == id);
            if (!d) return;
            $('#durum_id').val(d.odeme_durum_id);
            $('#odeme_durum_kod').val(d.odeme_durum_kod);
            $('#odeme_durum_ad').val(d.odeme_durum_ad);
            $('#odeme_durum_hedef_tipi').val(d.odeme_durum_hedef_tipi).trigger('change.select2');
            $('#odeme_durum_renk').val(d.odeme_durum_renk).trigger('change.select2');
            $('#odeme_durum_hedef_zorunlu').prop('checked', d.odeme_durum_hedef_zorunlu == 1);
            $('#odeme_durum_icon').val(d.odeme_durum_icon);
            $('#odeme_durum_final').prop('checked', d.odeme_durum_final == 1);
            $('#odeme_durum_sira').val(d.odeme_durum_sira);
            $('#durumModalTitle').text('\u00D6deme Durumu D\u00FCzenle');
            durumModal.show();
        }
    });
}

function saveDurum() {
    const data = { action: 'durum_save' };
    $('#durumForm').serializeArray().forEach(f => data[f.name] = f.value);
    if ($('#odeme_durum_hedef_zorunlu').is(':checked')) data['odeme_durum_hedef_zorunlu'] = 1;
    if ($('#odeme_durum_final').is(':checked')) data['odeme_durum_final'] = 1;
    $.post('', data, function (r) {
        if (r.success) { showToast(r.message, 'success'); durumModal.hide(); loadDurumlar(); loadStats(); }
        else { showToast(r.message, 'error'); }
    });
}

function deleteDurum(id, ad) {
    confirmAction('"' + ad + '" durumunu silmek istedi\u011Finize emin misiniz?', 'Bu i\u015Flem geri al\u0131namaz!', function () {
        $.post('', { action: 'durum_delete', id: id }, function (r) {
            if (r.success) { showSuccess('Silindi!', r.message); loadDurumlar(); loadStats(); }
            else { showError('Hata!', r.message); }
        });
    });
}

function loadDurumSelectOptions() {
    $.post('', { action: 'durum_list' }, function (r) {
        if (r.success) {
            let opts = '<option value="">Se\u00E7iniz</option>';
            r.data.forEach(d => opts += '<option value="' + d.odeme_durum_id + '">' + d.odeme_durum_ad + '</option>');
            $('#odeme_tipi_varsayilan_durum_id').html(opts).trigger('change.select2');
        }
    });
}

function loadTipler() {
    $.post('', { action: 'tip_list' }, function (r) {
        tableTip.clear();
        if (r.success && r.data.length > 0) {
            r.data.forEach(function (d) {
                const durumBadge = d.odeme_tipi_durum == 1 ? '<span class="badge text-bg-success">Aktif</span>' : '<span class="badge text-bg-secondary">Pasif</span>';
                const islemler  = canWrite ? '<button class="btn btn-warning btn-sm" onclick="editTip(' + d.odeme_tipi_id + ')" title="D\u00FCzenle"><i class="bi bi-pencil"></i> D\u00FCzenle</button> <button class="btn btn-danger btn-sm" onclick="deleteTip(' + d.odeme_tipi_id + ', \'' + d.odeme_tipi_ad.replace(/'/g, "\\'") + '\')" title="Sil"><i class="bi bi-trash"></i> Sil</button>' : '-';
                tableTip.row.add([
                    d.odeme_tipi_id,
                    d.odeme_tipi_ad,
                    d.odeme_tipi_hedef || '-',
                    d.varsayilan_durum_adi || '-',
                    d.odeme_tipi_dosya_onek || '-',
                    d.odeme_tipi_sira,
                    durumBadge,
                    '<div class="d-flex gap-1 justify-content-center">' + islemler + '</div>'
                ]);
            });
        }
        tableTip.draw();
    });
}

function openTipModal() {
    $('#tipForm')[0].reset();
    $('#tip_id').val(0);
    $('#tipModalTitle').text('Yeni \u00D6deme Tipi');
    $('#odeme_tipi_hedef').val('').trigger('change.select2');
    $('#odeme_tipi_varsayilan_durum_id').val('').trigger('change.select2');
    $('#odeme_tipi_durum').prop('checked', true);
    loadDurumSelectOptions();
    tipModal.show();
}

function editTip(id) {
    $.post('', { action: 'tip_list' }, function (r) {
        if (r.success) {
            const d = r.data.find(x => x.odeme_tipi_id == id);
            if (!d) return;
            loadDurumSelectOptions();
            setTimeout(function () {
                $('#tip_id').val(d.odeme_tipi_id);
                $('#odeme_tipi_ad').val(d.odeme_tipi_ad);
                $('#odeme_tipi_hedef').val(d.odeme_tipi_hedef).trigger('change.select2');
                $('#odeme_tipi_varsayilan_durum_id').val(d.odeme_tipi_varsayilan_durum_id || '').trigger('change.select2');
                $('#odeme_tipi_dosya_onek').val(d.odeme_tipi_dosya_onek);
                $('#odeme_tipi_sira').val(d.odeme_tipi_sira);
                $('#odeme_tipi_durum').prop('checked', d.odeme_tipi_durum == 1);
                $('#tipModalTitle').text('\u00D6deme Tipi D\u00FCzenle');
                tipModal.show();
            }, 300);
        }
    });
}

function saveTip() {
    const data = { action: 'tip_save' };
    $('#tipForm').serializeArray().forEach(f => data[f.name] = f.value);
    if ($('#odeme_tipi_durum').is(':checked')) data['odeme_tipi_durum'] = 1;
    $.post('', data, function (r) {
        if (r.success) { showToast(r.message, 'success'); tipModal.hide(); loadTipler(); loadStats(); }
        else { showToast(r.message, 'error'); }
    });
}

function deleteTip(id, ad) {
    confirmAction('"' + ad + '" tipini silmek istedi\u011Finize emin misiniz?', 'Bu i\u015Flem geri al\u0131namaz!', function () {
        $.post('', { action: 'tip_delete', id: id }, function (r) {
            if (r.success) { showSuccess('Silindi!', r.message); loadTipler(); loadStats(); }
            else { showError('Hata!', r.message); }
        });
    });
}
</script>
</body>
</html>
