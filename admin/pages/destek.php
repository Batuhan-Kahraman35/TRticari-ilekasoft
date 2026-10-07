<?php
/**
 * Destek Talepleri Listesi
 * destek.ornekproje.com API entegrasyonu
 */

require_once __DIR__ . '/../auth.php';
require_once __DIR__ . '/../includes/PageAuth.php';
requireAuth();

$user = Auth::user();
$db   = Database::getInstance();

// Sayfa yetki kontrolü kaldırıldı - Herkes erişebilir
$pagePermissions = [
    'has_access' => true,
    'can_add' => true,
    'can_edit' => true,
    'can_delete' => false,
    'can_view_own_records' => true,
    'can_view_firma' => true,
    'can_view_sube' => true
];

// Sayfa bilgileri
$pageInfo = $db->fetchOne("
    SELECT s.sayfalar_sayfa_adi, s.sayfalar_aciklama, m.menuler_menu_adi as menu_adi
    FROM Menu_Sayfalar s
    LEFT JOIN Menuler m ON s.sayfalar_menu_id = m.menuler_id
    WHERE s.sayfalar_sayfa_url LIKE ? AND s.sayfalar_durum = 1
", ['%' . $currentPageFile]);

$pageTitle = $pageInfo['sayfalar_sayfa_adi'] ?? 'Destek Talepleri';
$menuAdi   = $pageInfo['menu_adi'] ?? null;

$siteAyarlari = $db->fetchOne("SELECT TOP 1 site_ayarlari_site_title, site_ayarlari_destek_api_key, site_ayarlari_destek_api_url FROM dbo.tanim_site_ayarlari ORDER BY site_ayarlari_id DESC");
$siteTitle    = $siteAyarlari['site_ayarlari_site_title'] ?? 'Örnek Yazılım Portal';
$apiKey       = $siteAyarlari['site_ayarlari_destek_api_key'] ?? '';
$apiUrl       = $siteAyarlari['site_ayarlari_destek_api_url'] ?? '';

// Session'dan kullanıcı bilgileri
$eposta = $_SESSION['user_email'] ?? null;
$nameParts = explode(' ', $_SESSION['user_name'] ?? '', 2);
$kulAd    = $nameParts[0] ?? '';
$kulSoyad = $nameParts[1] ?? '';

// ─── API Fonksiyonu ───
function apiCall($url, $apiKey, $payload) {
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_USERAGENT      => 'TRticari-Destek-App/1.0',
        CURLOPT_POST           => true,
        CURLOPT_HTTPHEADER     => ['Content-Type: application/json', 'X-API-KEY: ' . $apiKey],
        CURLOPT_POSTFIELDS     => json_encode($payload, JSON_UNESCAPED_UNICODE),
        CURLOPT_TIMEOUT        => 30,
        CURLOPT_SSL_VERIFYPEER => false,
    ]);
    $res  = curl_exec($ch);
    $err  = curl_error($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($res === false) {
        return ['success' => false, 'message' => 'API bağlantı hatası: ' . ($err ?: 'Bilinmeyen hata')];
    }
    $data = json_decode($res, true);
    if ($code < 200 || $code >= 300) {
        if ($data !== null && isset($data['success']) && $data['success'] === false) {
            return $data;
        }
        return ['success' => false, 'message' => 'API HTTP hatası: ' . $code . ' — Yanıt: ' . mb_substr($res, 0, 200)];
    }
    if ($data === null) {
        return ['success' => false, 'message' => 'Geçersiz API yanıtı. Ham: ' . mb_substr($res, 0, 200)];
    }
    return $data;
}

// Ticket listesi çek
$apiHata  = '';
$tickets  = [];
if ($eposta && $apiKey && $apiUrl) {
    $result  = apiCall($apiUrl, $apiKey, ['action' => 'list_tickets', 'eposta' => $eposta]);
    $tickets = ($result['success'] ?? false) ? ($result['data'] ?? []) : [];
    if (!($result['success'] ?? false)) {
        // Kullanıcı daha önce ticket açmamışsa API 404 dönüyor olabilir, bunu hata olarak gösterme
        if (strpos($result['message'] ?? '', 'Kullanıcı bulunamadı') !== false) {
            $apiHata = '';
            $tickets = [];
        } else {
            $apiHata = $result['message'] ?? 'API hatası.';
        }
    }
} else {
    $apiHata = 'API yapılandırması eksik veya oturum bulunamadı.';
}

// Filtre dropdown verileri (kategori/öncelik/durum) API'nin meta ucundan gelir
$metaResult  = apiCall($apiUrl, $apiKey, ['action' => 'ticket_meta']);
$meta        = ($metaResult['success'] ?? false) ? ($metaResult['data'] ?? []) : [];
$kategoriler = $meta['kategoriler'] ?? [];
$oncelikler  = $meta['oncelikler'] ?? [];
$durumlar    = $meta['durumlar'] ?? [];

/**
 * Infobox sayımları. Kapalı ayrımı API'nin dondugu kapatma bayragindan gelir;
 * acik ve islemde ayrımı ise durum adi uzerinden yapılır, boylece durum id'leri
 * koda gomulmez. Meta'da bu adlar degisirse yalnızca asagidaki iki sabit guncellenir.
 */
const DURUM_ACIK    = 'Açık';
const DURUM_ISLEMDE = 'İşlemde';

$toplamTicket   = count($tickets);
$kapaliTicket   = count(array_filter($tickets, fn($t) => !empty($t['Ticket_Durumlar_kapatma_durumu'])));
$acikTicket     = count(array_filter($tickets, fn($t) => ($t['durum_ad'] ?? '') === DURUM_ACIK));
$islemdeTicket  = count(array_filter($tickets, fn($t) => ($t['durum_ad'] ?? '') === DURUM_ISLEMDE));
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
    <link href="https://cdn.jsdelivr.net/npm/sweetalert2@11/dist/sweetalert2.min.css" rel="stylesheet">
    <link href="https://cdn.datatables.net/1.13.7/css/dataTables.bootstrap5.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/select2-bootstrap-5-theme@1.3.0/dist/select2-bootstrap-5-theme.min.css" rel="stylesheet">
    <link rel="stylesheet" href="/admin/assets/css/custom.css">
    <style>
        .info-box { transition: transform .2s; }
        .info-box:hover { transform: translateY(-3px); box-shadow: 0 4px 10px rgba(0,0,0,.1); }
        #tblTalep tbody tr { cursor: pointer; }
    </style>
</head>
<body class="layout-fixed sidebar-expand-lg sidebar-open bg-body-tertiary">
<div class="app-wrapper">
    <?php include __DIR__ . '/../includes/header.php'; ?>
    <?php include __DIR__ . '/../includes/sidebar.php'; ?>

    <main class="app-main">
        <!-- Sayfa Başlığı -->
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

        <!-- Sayfa İçeriği -->
        <div class="app-content">
            <div class="container-fluid">

                <!-- Info Boxes -->
                <div class="row mb-3">
                    <div class="col-12 col-sm-6 col-md-3">
                        <div class="info-box">
                            <span class="info-box-icon text-bg-secondary shadow-sm">
                                <i class="bi bi-ticket-detailed"></i>
                            </span>
                            <div class="info-box-content">
                                <span class="info-box-text">Toplam Talep</span>
                                <span class="info-box-number"><?= $toplamTicket ?></span>
                            </div>

                        </div>
                    </div>
                    <div class="col-12 col-sm-6 col-md-3">
                        <div class="info-box">
                            <span class="info-box-icon text-bg-primary shadow-sm">
                                <i class="bi bi-envelope-open"></i>
                            </span>
                            <div class="info-box-content">
                                <span class="info-box-text">Açık Talep</span>
                                <span class="info-box-number"><?= $acikTicket ?></span>
                            </div>
                        </div>
                    </div>
                    <div class="col-12 col-sm-6 col-md-3">
                        <div class="info-box">
                            <span class="info-box-icon text-bg-warning shadow-sm">
                                <i class="bi bi-arrow-repeat"></i>
                            </span>
                            <div class="info-box-content">
                                <span class="info-box-text">İşlemde</span>
                                <span class="info-box-number"><?= $islemdeTicket ?></span>
                            </div>
                        </div>
                    </div>
                    <div class="col-12 col-sm-6 col-md-3">
                        <div class="info-box">
                            <span class="info-box-icon text-bg-success shadow-sm">
                                <i class="bi bi-check-circle"></i>
                            </span>
                            <div class="info-box-content">
                                <span class="info-box-text">Kapalı</span>
                                <span class="info-box-number"><?= $kapaliTicket ?></span>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- API Hata Mesajı -->
                <?php if ($apiHata): ?>
                <div class="alert alert-danger">
                    <i class="bi bi-exclamation-triangle me-1"></i>
                    <?= htmlspecialchars($apiHata) ?>
                </div>
                <?php endif; ?>

                <!-- Filtre Paneli -->
                <div class="card card-outline card-secondary mb-3">
                    <div class="card-header" role="button" data-bs-toggle="collapse" data-bs-target="#filtrePanel" aria-expanded="false">
                        <h3 class="card-title"><i class="bi bi-funnel me-1"></i> Filtrele</h3>
                        <div class="card-tools">
                            <button type="button" class="btn btn-tool"><i class="bi bi-chevron-down"></i></button>
                        </div>
                    </div>
                    <div id="filtrePanel" class="collapse">
                        <div class="card-body">
                            <div class="row g-3">
                                <div class="col-md-3">
                                    <label class="form-label">Kategori</label>
                                    <select id="fltKategori" class="form-select select2">
                                        <option value="">Tümü</option>
                                        <?php foreach ($kategoriler as $k): ?>
                                        <option value="<?= htmlspecialchars($k['ad']) ?>"><?= htmlspecialchars($k['ad']) ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div class="col-md-3">
                                    <label class="form-label">Öncelik</label>
                                    <select id="fltOncelik" class="form-select select2">
                                        <option value="">Tümü</option>
                                        <?php foreach ($oncelikler as $o): ?>
                                        <option value="<?= htmlspecialchars($o['ad']) ?>"><?= htmlspecialchars($o['ad']) ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div class="col-md-3">
                                    <label class="form-label">Durum</label>
                                    <select id="fltDurum" class="form-select select2">
                                        <option value="">Tümü</option>
                                        <?php foreach ($durumlar as $d): ?>
                                        <option value="<?= htmlspecialchars($d['ad']) ?>"><?= htmlspecialchars($d['ad']) ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div class="col-md-3">
                                    <label class="form-label">Başlangıç Tarihi</label>
                                    <input type="date" id="fltBaslangic" class="form-control">
                                </div>
                                <div class="col-md-3">
                                    <label class="form-label">Bitiş Tarihi</label>
                                    <input type="date" id="fltBitis" class="form-control">
                                </div>
                                <div class="col-md-3 d-flex align-items-end">
                                    <button type="button" id="btnFiltreTemizle" class="btn btn-outline-secondary">
                                        <i class="bi bi-x-circle me-1"></i> Temizle
                                    </button>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Talep Listesi Kartı -->
                <div class="card card-primary card-outline">
                    <div class="card-header">
                        <h3 class="card-title"><i class="bi bi-headset me-1"></i> Destek Talepleri</h3>
                        <div class="card-tools">
                            <a href="destek-detay?yeni=1" class="btn btn-sm btn-primary">
                                <i class="bi bi-plus-circle me-1"></i> Yeni Talep Oluştur
                            </a>
                        </div>
                    </div>
                    <div class="card-body">
                        <table id="tblTalep" class="table table-hover table-striped align-middle w-100">
                            <thead>
                                <tr>
                                    <th>#</th>
                                    <th>Talep No</th>
                                    <th>Konu</th>
                                    <th>Kategori</th>
                                    <th>Öncelik</th>
                                    <th>Durum</th>
                                    <th>Kullanıcı</th>
                                    <th>Tarih</th>
                                    <th>İşlem</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($tickets as $t): ?>
                                <?php
                                    $talepId  = (int)$t['Tickets_id'];
                                    $olusturan = trim(($t['olusturan_ad'] ?? '') . ' ' . ($t['olusturan_soyad'] ?? ''));
                                ?>
                                <tr>
                                    <td><?= $talepId ?></td>
                                    <td><code><?= htmlspecialchars($t['Tickets_no'] ?? '') ?></code></td>
                                    <td>
                                        <a href="destek-detay?id=<?= $talepId ?>" class="text-decoration-none fw-semibold">
                                            <?= htmlspecialchars($t['Tickets_konu'] ?? '') ?>
                                        </a>
                                        <?php if (!empty($t['cc_mi'])): ?>
                                        <i class="bi bi-people-fill text-secondary ms-1" title="Bu talebe bilgilendirme (CC) amacıyla eklendiniz"></i>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <?php if (!empty($t['kategori_ad'])): ?>
                                        <span class="badge" style="background:<?= htmlspecialchars($t['kategori_renk'] ?? '#6c757d') ?>"><?= htmlspecialchars($t['kategori_ad']) ?></span>
                                        <?php else: ?>-<?php endif; ?>
                                    </td>
                                    <td>
                                        <span class="badge" style="background:<?= htmlspecialchars($t['oncelik_renk'] ?? '#6c757d') ?>"><?= htmlspecialchars($t['oncelik_ad'] ?? '-') ?></span>
                                    </td>
                                    <td>
                                        <span class="badge" style="background:<?= htmlspecialchars($t['durum_renk'] ?? '#6c757d') ?>"><?= htmlspecialchars($t['durum_ad'] ?? '-') ?></span>
                                    </td>
                                    <td><?= $olusturan !== '' ? htmlspecialchars($olusturan) : '-' ?></td>
                                    <td data-order="<?= htmlspecialchars($t['acilis_tarihi'] ?? '') ?>"><?= htmlspecialchars($t['acilis_tarihi'] ?? '-') ?></td>
                                    <td>
                                        <a href="destek-detay?id=<?= $talepId ?>" class="btn btn-sm btn-info" title="Detay">
                                            <i class="bi bi-eye"></i>
                                        </a>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
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
<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11/dist/sweetalert2.all.min.js"></script>
<script src="https://cdn.datatables.net/1.13.7/js/jquery.dataTables.min.js"></script>
<script src="https://cdn.datatables.net/1.13.7/js/dataTables.bootstrap5.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js"></script>
<script src="/admin/assets/js/custom.js"></script>
<script>
$(function () {
    $('.select2').select2({ theme: 'bootstrap-5', width: '100%' });

    // Tarih araligi filtresi: acilis tarihi kolonuna (index 7) bakar
    $.fn.dataTable.ext.search.push(function (settings, data) {
        if (settings.nTable.id !== 'tblTalep') return true;

        var bas = $('#fltBaslangic').val();
        var bit = $('#fltBitis').val();
        if (!bas && !bit) return true;

        var tarih = (data[7] || '').substring(0, 10);
        if (!tarih) return false;
        if (bas && tarih < bas) return false;
        if (bit && tarih > bit) return false;
        return true;
    });

    var dt = $('#tblTalep').DataTable({
        language: { url: 'https://cdn.datatables.net/plug-ins/1.13.7/i18n/tr.json' },
        scrollX: true,
        order: [[0, 'desc']],
        columnDefs: [
            { targets: 8, orderable: false, searchable: false },
            { targets: '_all', defaultContent: '-' }
        ]
    });

    function kolonFiltrele(kolonIndex, deger) {
        dt.column(kolonIndex).search(deger ? '^' + $.fn.dataTable.util.escapeRegex(deger) + '$' : '', true, false);
    }

    $('#fltKategori').on('change', function () { kolonFiltrele(3, this.value); dt.draw(); });
    $('#fltOncelik').on('change',  function () { kolonFiltrele(4, this.value); dt.draw(); });
    $('#fltDurum').on('change',    function () { kolonFiltrele(5, this.value); dt.draw(); });
    $('#fltBaslangic, #fltBitis').on('change', function () { dt.draw(); });

    $('#btnFiltreTemizle').on('click', function () {
        $('#fltKategori, #fltOncelik, #fltDurum').val('').trigger('change.select2');
        $('#fltBaslangic, #fltBitis').val('');
        dt.columns().search('');
        dt.search('').draw();
    });
});
</script>
<!-- Widget badge sıfırlama: Bu sayfayı açtığında sayaç temizlenir -->
<script>localStorage.setItem('destek_son_kontrol', new Date().toISOString());</script>
</body>
</html>
