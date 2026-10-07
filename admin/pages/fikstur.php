<?php
/**
 * Admin Panel - Fikstür
 *
 * SporFikstur tablosundaki kazınmış maç kayıtlarını listeler.
 * Veri kaynağı: FiksturHelper (haberciniz.biz lig gösterim servisi).
 * Otomatik güncelleme cron görevi: fikstur_kazi
 *
 * Liste tek tablodan (SporFikstur) beslenir; kolonlar: Lig, Tarih, Saat,
 * Ev Sahibi, Deplasman.
 */

require_once __DIR__ . '/../auth.php';
require_once __DIR__ . '/../includes/PageAuth.php';
require_once __DIR__ . '/../includes/FiksturHelper.php';
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

$pageInfo = $db->fetchOne("
    SELECT
        s.sayfalar_sayfa_adi,
        s.sayfalar_aciklama,
        m.menuler_menu_adi as menu_adi
    FROM Menu_Sayfalar s
    LEFT JOIN Menuler m ON s.sayfalar_menu_id = m.menuler_id
    WHERE s.sayfalar_sayfa_url LIKE ? AND s.sayfalar_durum = 1
", ['%' . $currentPageFile]);

$pageTitle = $pageInfo['sayfalar_sayfa_adi'] ?? 'Fikstür';
$menuAdi   = $pageInfo['menu_adi'] ?? null;

$siteAyarlari = $db->fetchOne("SELECT TOP 1 site_ayarlari_site_title FROM dbo.tanim_site_ayarlari ORDER BY site_ayarlari_id DESC");
$siteTitle    = $siteAyarlari['site_ayarlari_site_title'] ?? 'Örnek Yazılım Portal';

// Sayfa açılış filtresi: Süper Lig + bugünden itibaren.
// Lig kodu sabit yazılmaz, tablodan çözülür (kod değişirse filtre bozulmasın).
$varsayilanLig = $db->fetchOne("
    SELECT TOP 1 SporFikstur_LigKodu AS kod, SporFikstur_LigAdi AS ad
    FROM dbo.SporFikstur
    WHERE Durum = 1 AND SporFikstur_LigAdi LIKE N'%Süper Lig%'
    ORDER BY SporFikstur_LigKodu
");
$varsayilanLigKod   = $varsayilanLig['kod'] ?? '';
$varsayilanLigAd    = $varsayilanLig['ad']  ?? '';
$varsayilanTarihBas = date('Y-m-d');

// AJAX işlemleri
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json');
    $action = $_POST['action'] ?? '';

    try {
        switch ($action) {

            case 'list': {
                // ── DataTables server-side parametreleri ──
                $draw   = (int)($_POST['draw'] ?? 1);
                $start  = max(0, (int)($_POST['start'] ?? 0));
                $length = (int)($_POST['length'] ?? 25);
                if ($length <= 0 || $length > 200) $length = 25;

                $search   = trim($_POST['search']['value'] ?? '');
                $lig      = trim($_POST['f_lig']       ?? '');
                $hafta    = trim($_POST['f_hafta']     ?? '');
                $tarihBas = trim($_POST['f_tarih_bas'] ?? '');
                $tarihBit = trim($_POST['f_tarih_bit'] ?? '');

                $where  = ["f.Durum = 1"];
                $params = [];

                if ($search !== '') {
                    $where[]  = "(f.SporFikstur_EvSahibi LIKE ? OR f.SporFikstur_Deplasman LIKE ?)";
                    $params[] = "%$search%";
                    $params[] = "%$search%";
                }
                if ($lig !== '') {
                    $where[]  = "f.SporFikstur_LigKodu = ?";
                    $params[] = $lig;
                }
                if ($hafta !== '') {
                    $where[]  = "f.SporFikstur_Hafta = ?";
                    $params[] = (int)$hafta;
                }
                if ($tarihBas !== '') {
                    $where[]  = "f.SporFikstur_MacTarihi >= ?";
                    $params[] = $tarihBas . ' 00:00:00';
                }
                if ($tarihBit !== '') {
                    $where[]  = "f.SporFikstur_MacTarihi <= ?";
                    $params[] = $tarihBit . ' 23:59:59';
                }

                $whereClause = implode(" AND ", $where);

                // Sayımlar — tek tablo, JOIN yok
                $recordsTotal    = (int)($db->fetchOne("SELECT COUNT(*) AS c FROM dbo.SporFikstur f WHERE f.Durum = 1")['c'] ?? 0);
                $recordsFiltered = (int)($db->fetchOne("SELECT COUNT(*) AS c FROM dbo.SporFikstur f WHERE $whereClause", $params)['c'] ?? 0);

                // ── Sıralama (kolon index → güvenli whitelist) ──
                $orderMap = [
                    0 => 'f.SporFikstur_LigAdi',
                    1 => 'f.SporFikstur_MacTarihi',
                    2 => 'f.SporFikstur_MacTarihi',
                    3 => 'f.SporFikstur_EvSahibi',
                    4 => 'f.SporFikstur_Deplasman',
                ];
                $orderCol = (int)($_POST['order'][0]['column'] ?? 1);
                $orderBy  = $orderMap[$orderCol] ?? 'f.SporFikstur_MacTarihi';
                $orderDir = strtolower($_POST['order'][0]['dir'] ?? 'asc') === 'desc' ? 'DESC' : 'ASC';

                $data = [];
                if ($recordsFiltered > 0) {
                    $data = $db->fetchAll("
                        SELECT
                            f.SporFikstur_Id,
                            f.SporFikstur_LigAdi,
                            CONVERT(VARCHAR(16), f.SporFikstur_MacTarihi, 120) AS MacTarihi,
                            f.SporFikstur_TarihMetin,
                            f.SporFikstur_EvSahibi,
                            f.SporFikstur_Deplasman
                        FROM dbo.SporFikstur f
                        WHERE $whereClause
                        ORDER BY $orderBy $orderDir, f.SporFikstur_Id DESC
                        OFFSET $start ROWS FETCH NEXT $length ROWS ONLY
                    ", $params);
                }

                echo json_encode([
                    'draw'            => $draw,
                    'recordsTotal'    => $recordsTotal,
                    'recordsFiltered' => $recordsFiltered,
                    'data'            => $data,
                ]);
                break;
            }

            case 'stats': {
                $ozet = $db->fetchOne("
                    SELECT
                        COUNT(*) AS toplam,
                        COUNT(DISTINCT SporFikstur_LigKodu) AS lig,
                        SUM(CASE WHEN SporFikstur_MacTarihi >= CAST(GETDATE() AS DATE) THEN 1 ELSE 0 END) AS gelecek,
                        SUM(CASE WHEN CAST(SporFikstur_MacTarihi AS DATE) = CAST(GETDATE() AS DATE) THEN 1 ELSE 0 END) AS bugun,
                        CONVERT(VARCHAR(19), MAX(SporFikstur_SonKazimaTarihi), 120) AS son_kazima
                    FROM dbo.SporFikstur
                    WHERE Durum = 1
                ");

                echo json_encode(['success' => true, 'data' => [
                    'toplam'     => (int)($ozet['toplam']  ?? 0),
                    'lig'        => (int)($ozet['lig']     ?? 0),
                    'gelecek'    => (int)($ozet['gelecek'] ?? 0),
                    'bugun'      => (int)($ozet['bugun']   ?? 0),
                    'son_kazima' => $ozet['son_kazima'] ?? null,
                ]]);
                break;
            }

            case 'filtre_secenekleri': {
                // Lig ve hafta listeleri tablodan çekilir; sabit liste tutulmaz
                $ligler = $db->fetchAll("
                    SELECT DISTINCT SporFikstur_LigKodu AS kod, SporFikstur_LigAdi AS ad
                    FROM dbo.SporFikstur
                    WHERE Durum = 1
                    ORDER BY SporFikstur_LigAdi
                ");
                $haftalar = $db->fetchAll("
                    SELECT DISTINCT SporFikstur_Hafta AS hafta
                    FROM dbo.SporFikstur
                    WHERE Durum = 1 AND SporFikstur_Hafta > 0
                    ORDER BY SporFikstur_Hafta
                ");

                echo json_encode(['success' => true, 'data' => [
                    'ligler'   => $ligler,
                    'haftalar' => array_column($haftalar, 'hafta'),
                ]]);
                break;
            }

            case 'kazi': {
                if (!$pagePermissions['can_edit']) {
                    echo json_encode(['success' => false, 'message' => 'Güncelleme yetkiniz yok!']);
                    break;
                }

                set_time_limit(300);
                $r = FiksturHelper::senkron($db, (int)$user['kullanici_id']);

                echo json_encode([
                    'success' => $r['success'],
                    'message' => $r['message'],
                    'data'    => [
                        'eklenen'     => $r['eklenen'],
                        'guncellenen' => $r['guncellenen'],
                        'toplam'      => $r['toplam'],
                        'ligler'      => $r['ligler'],
                    ],
                ]);
                break;
            }

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
    <link href="https://cdn.datatables.net/1.13.7/css/dataTables.bootstrap5.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/select2-bootstrap-5-theme@1.3.0/dist/select2-bootstrap-5-theme.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/sweetalert2@11/dist/sweetalert2.min.css" rel="stylesheet">
    <link rel="stylesheet" href="/admin/assets/css/custom.css">

    <style>
        .lig-rozet {
            display: inline-block;
            padding: .2rem .55rem;
            border-radius: .35rem;
            background-color: #e9ecef;
            font-size: .8rem;
            font-weight: 600;
            white-space: nowrap;
        }
        .saat-hucre {
            font-weight: 600;
            white-space: nowrap;
        }
        .takim-ev  { text-align: right; }
        .takim-dep { text-align: left; }

        .info-box { transition: transform 0.2s; }
        .info-box:hover {
            transform: translateY(-5px);
            box-shadow: 0 4px 6px rgba(0,0,0,0.1);
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

                <!-- Info Boxes -->
                <div class="row mb-3">
                    <div class="col-12 col-sm-6 col-md-3">
                        <div class="info-box">
                            <span class="info-box-icon text-bg-primary shadow-sm"><i class="bi bi-calendar3"></i></span>
                            <div class="info-box-content">
                                <span class="info-box-text">Toplam Maç</span>
                                <span class="info-box-number" id="stat-toplam">0</span>
                            </div>
                        </div>
                    </div>
                    <div class="col-12 col-sm-6 col-md-3">
                        <div class="info-box">
                            <span class="info-box-icon text-bg-secondary shadow-sm"><i class="bi bi-trophy"></i></span>
                            <div class="info-box-content">
                                <span class="info-box-text">Lig</span>
                                <span class="info-box-number" id="stat-lig">0</span>
                            </div>
                        </div>
                    </div>
                    <div class="col-12 col-sm-6 col-md-3">
                        <div class="info-box">
                            <span class="info-box-icon text-bg-success shadow-sm"><i class="bi bi-calendar-day"></i></span>
                            <div class="info-box-content">
                                <span class="info-box-text">Bugünkü Maç</span>
                                <span class="info-box-number" id="stat-bugun">0</span>
                            </div>
                        </div>
                    </div>
                    <div class="col-12 col-sm-6 col-md-3">
                        <div class="info-box">
                            <span class="info-box-icon text-bg-info shadow-sm"><i class="bi bi-clock-history"></i></span>
                            <div class="info-box-content">
                                <span class="info-box-text">Gelecek Maç</span>
                                <span class="info-box-number" id="stat-gelecek">0</span>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Filtre -->
                <div class="card card-primary card-outline mb-3">
                    <div class="card-header">
                        <h3 class="card-title"><i class="bi bi-funnel"></i> Filtrele</h3>
                        <div class="card-tools">
                            <button type="button" class="btn btn-tool" data-bs-toggle="collapse" data-bs-target="#filterCard" aria-expanded="false">
                                <i class="bi bi-chevron-down"></i>
                            </button>
                        </div>
                    </div>
                    <div class="card-body collapse" id="filterCard">
                        <form id="filterForm">
                            <div class="row g-3">
                                <div class="col-md-4">
                                    <label class="form-label">Takım Ara</label>
                                    <input type="text" class="form-control" name="search" id="filter_search" placeholder="Ev sahibi veya deplasman...">
                                </div>
                                <div class="col-md-2">
                                    <label class="form-label">Lig</label>
                                    <select class="form-select" name="lig" id="filter_lig">
                                        <option value="">Tümü</option>
<?php if ($varsayilanLigKod !== ''): ?>
                                        <option value="<?= htmlspecialchars($varsayilanLigKod) ?>" selected><?= htmlspecialchars($varsayilanLigAd) ?></option>
<?php endif; ?>
                                    </select>
                                </div>
                                <div class="col-md-2">
                                    <label class="form-label">Hafta</label>
                                    <select class="form-select" name="hafta" id="filter_hafta">
                                        <option value="">Tümü</option>
                                    </select>
                                </div>
                                <div class="col-md-2">
                                    <label class="form-label">Tarih (Başlangıç)</label>
                                    <input type="date" class="form-control" id="filter_tarih_bas" value="<?= $varsayilanTarihBas ?>">
                                </div>
                                <div class="col-md-2">
                                    <label class="form-label">Tarih (Bitiş)</label>
                                    <input type="date" class="form-control" id="filter_tarih_bit">
                                </div>
                                <div class="col-md-12">
                                    <button type="submit" class="btn btn-primary"><i class="bi bi-search"></i> Filtrele</button>
                                    <button type="button" class="btn btn-secondary" id="clearFilters"><i class="bi bi-x-circle"></i> Temizle</button>
                                </div>
                            </div>
                        </form>
                    </div>
                </div>

                <!-- Liste -->
                <div class="card">
                    <div class="card-header">
                        <h3 class="card-title">
                            Maç Listesi
                            <small class="text-muted ms-2" id="son-kazima-metin"></small>
                        </h3>
                        <div class="card-tools">
                            <?php if ($pagePermissions['can_edit']): ?>
                            <button type="button" class="btn btn-primary btn-sm" id="btnKazi">
                                <i class="bi bi-arrow-repeat"></i> Şimdi Güncelle
                            </button>
                            <?php endif; ?>
                        </div>
                    </div>
                    <div class="card-body">
                        <table id="kayitTable" class="table table-bordered table-striped table-hover w-100">
                            <thead>
                                <tr>
                                    <th>Lig</th>
                                    <th>Tarih</th>
                                    <th class="text-center">Saat</th>
                                    <th class="text-end">Ev Sahibi</th>
                                    <th>Deplasman</th>
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

<script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/@popperjs/core@2.11.8/dist/umd/popper.min.js" crossorigin="anonymous"></script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.7/dist/js/bootstrap.min.js" crossorigin="anonymous"></script>
<script src="/admin/assets/js/adminlte.min.js"></script>
<script src="https://cdn.datatables.net/1.13.7/js/jquery.dataTables.min.js"></script>
<script src="https://cdn.datatables.net/1.13.7/js/dataTables.bootstrap5.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11/dist/sweetalert2.all.min.js"></script>
<script src="/admin/assets/js/custom.js"></script>

<script>
    const permissions = {
        canEdit: <?= $pagePermissions['can_edit'] ? 'true' : 'false' ?>
    };

    let dataTable;

    $(document).ready(function () {

        dataTable = $('#kayitTable').DataTable({
            language: { url: 'https://cdn.datatables.net/plug-ins/1.13.7/i18n/tr.json' },
            processing: true,
            serverSide: true,
            autoWidth: false,
            scrollX: true,
            dom: 'lrtip', // global arama kutusu gizli — filtre panelindeki "Takım Ara" kullanılır
            order: [[1, 'asc']], // en yakın maç üstte (varsayılan filtre bugünden itibaren)
            pageLength: 25,
            lengthMenu: [[10, 25, 50, 100], [10, 25, 50, 100]],
            ajax: {
                url: '', type: 'POST',
                data: function (d) {
                    d.action       = 'list';
                    d.search.value = $('#filter_search').val() || '';
                    d.f_lig        = $('#filter_lig').val() || '';
                    d.f_hafta      = $('#filter_hafta').val() || '';
                    d.f_tarih_bas  = $('#filter_tarih_bas').val() || '';
                    d.f_tarih_bit  = $('#filter_tarih_bit').val() || '';
                    return d;
                },
                error: function () { showToast('Liste yüklenirken hata oluştu', 'error'); }
            },
            columns: [
                { data: 'SporFikstur_LigAdi', render: d => `<span class="lig-rozet">${escapeHtml(d || '-')}</span>` },
                { data: null, render: (d, t, r) => tarihHucre(r) },
                { data: null, className: 'text-center', render: (d, t, r) => saatHucre(r) },
                { data: 'SporFikstur_EvSahibi',  className: 'takim-ev',  render: d => escapeHtml(d || '-') },
                { data: 'SporFikstur_Deplasman', className: 'takim-dep', render: d => escapeHtml(d || '-') }
            ]
        });

        loadStats();
        loadFiltreSecenekleri();

        // Filtre select'lerini custom.js (.form-select) otomatik Select2 yapıyor — burada tekrar init etmiyoruz.

        $('#filterForm').on('submit', function (e) {
            e.preventDefault();
            dataTable.ajax.reload();
            showToast('Filtre uygulandı', 'info');
        });

        $('#clearFilters').on('click', function () {
            $('#filterForm')[0].reset();
            $('#filter_lig, #filter_hafta').val('').trigger('change');
            $('#filter_tarih_bas, #filter_tarih_bit').val('');
            dataTable.ajax.reload();
            showToast('Filtreler temizlendi', 'info');
        });

        $('#btnKazi').on('click', kaziBaslat);
    });

    function tarihHucre(row) {
        const tarih = row.MacTarihi;
        if (!tarih) return escapeHtml(row.SporFikstur_TarihMetin || '-');
        const [g] = tarih.split(' ');
        const [yil, ay, gun] = g.split('-');
        return `${gun}.${ay}.${yil}`;
    }

    function saatHucre(row) {
        const tarih = row.MacTarihi;
        if (!tarih) return '<span class="text-muted">-</span>';
        const s = tarih.split(' ')[1];
        if (!s || s === '00:00') return '<span class="text-muted">-</span>';
        return `<span class="saat-hucre">${escapeHtml(s)}</span>`;
    }

    function loadStats() {
        $.post('', { action: 'stats' }, function (r) {
            if (!r.success) return;
            $('#stat-toplam').text(r.data.toplam);
            $('#stat-lig').text(r.data.lig);
            $('#stat-bugun').text(r.data.bugun);
            $('#stat-gelecek').text(r.data.gelecek);
            $('#son-kazima-metin').text(r.data.son_kazima ? 'Son güncelleme: ' + r.data.son_kazima : '');
        }, 'json');
    }

    function loadFiltreSecenekleri() {
        $.post('', { action: 'filtre_secenekleri' }, function (r) {
            if (!r.success) return;

            const $lig = $('#filter_lig');
            const ligSecili = $lig.val();
            if ($lig.hasClass('select2-hidden-accessible')) $lig.select2('close');
            $lig.html('<option value="">Tümü</option>');
            r.data.ligler.forEach(l => {
                $lig.append(`<option value="${escapeHtml(l.kod)}">${escapeHtml(l.ad || l.kod)}</option>`);
            });
            $lig.val(ligSecili || '').trigger('change');

            const $hafta = $('#filter_hafta');
            const haftaSecili = $hafta.val();
            if ($hafta.hasClass('select2-hidden-accessible')) $hafta.select2('close');
            $hafta.html('<option value="">Tümü</option>');
            r.data.haftalar.forEach(h => {
                $hafta.append(`<option value="${h}">${h}. Hafta</option>`);
            });
            $hafta.val(haftaSecili || '').trigger('change');
        }, 'json');
    }

    function kaziBaslat() {
        if (!permissions.canEdit) return;

        const $btn = $('#btnKazi');
        $btn.prop('disabled', true).html('<i class="bi bi-hourglass-split"></i> Güncelleniyor...');

        $.ajax({
            url: '', method: 'POST',
            data: { action: 'kazi' },
            dataType: 'json',
            success: function (r) {
                if (r.success) {
                    showSuccess('Güncellendi!', r.message);
                    dataTable.ajax.reload(null, false);
                    loadStats();
                    loadFiltreSecenekleri();
                } else {
                    showError('Hata!', r.message);
                }
            },
            error: function () { showError('Bağlantı Hatası!', 'Kaynak servise ulaşılamadı.'); },
            complete: function () {
                $btn.prop('disabled', false).html('<i class="bi bi-arrow-repeat"></i> Şimdi Güncelle');
            }
        });
    }

    function escapeHtml(str) {
        if (str === null || str === undefined) return '';
        return String(str).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
    }
</script>
</body>
</html>
