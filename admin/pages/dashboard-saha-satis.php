<?php
/**
 * Admin Panel - Saha Satış Dashboard
 * @baslik Saha Satış
 *
 * Giriş yapan kullanıcının KENDİ tahsilat durumunu ve yaklaşan tahsilatlarını gösterir.
 * Mobil öncelikli: en üstte İhbar kaydı butonu bulunur.
 *
 * NOT: Bu dosya anasayfa.php tarafından include edilir, doğrudan çağrılmaz!
 */

// $user ve $db değişkenleri anasayfa.php'den geliyor

$personelId = (int) $user['kullanici_id'];

$siteAyarlari = $db->fetchOne("SELECT TOP 1 site_ayarlari_site_title FROM dbo.tanim_site_ayarlari ORDER BY site_ayarlari_id DESC");
$siteTitle = $siteAyarlari['site_ayarlari_site_title'] ?? 'Portal';

// Sayfa başlıkları veritabanından
$ihbarSayfa = $db->fetchOne("SELECT sayfalar_sayfa_adi FROM Menu_Sayfalar WHERE sayfalar_sayfa_url LIKE '%ihbar.php' AND sayfalar_durum = 1");
$ihbarBaslik = $ihbarSayfa['sayfalar_sayfa_adi'] ?? 'İhbar Kaydı';

$tahsilatSayfa = $db->fetchOne("SELECT sayfalar_sayfa_adi FROM Menu_Sayfalar WHERE sayfalar_sayfa_url LIKE '%tahsilat-ajandasi.php' AND sayfalar_durum = 1");
$tahsilatBaslik = $tahsilatSayfa['sayfalar_sayfa_adi'] ?? 'Tahsilat Ajandası';

/**
 * Bekleyen tahsilat koşulu: henüz tahsil edilmemiş ve durumu final (Tamamlandı/Başarısız/İade)
 * olmayan ödemeler. Durum ID'leri koda gömülmez, odeme_durum_final bayrağı kullanılır.
 */
const BEKLEYEN_KOSUL = "o.odeme_yapildi = 0 AND ISNULL(d.odeme_durum_final, 0) = 0";

// Hafta başı/sonu: @@DATEFIRST ayarından bağımsız Pazartesi-Pazar aralığı
const HAFTA_BAS = "DATEADD(DAY, -((DATEPART(WEEKDAY, GETDATE()) + @@DATEFIRST - 2) % 7), CAST(GETDATE() AS DATE))";
const HAFTA_SON = "DATEADD(DAY, 6 - ((DATEPART(WEEKDAY, GETDATE()) + @@DATEFIRST - 2) % 7), CAST(GETDATE() AS DATE))";

// AJAX İşlemleri
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json');
    $action = $_POST['action'] ?? '';

    try {
        switch ($action) {
            case 'saha_stats':
                // Ortak FROM/JOIN bloğu: kullanıcının sattığı sözleşmelerin ödemeleri
                $kaynak = "
                    FROM Sozlesme_Odemeler o
                    INNER JOIN Sozlesmeler s ON o.odeme_sozlesme_id = s.sozlesme_id
                    LEFT JOIN Sozlesme_OdemeDurumlari d ON o.odeme_durum_id = d.odeme_durum_id
                    WHERE s.sozlesme_personel_id = ?
                ";

                $tutar = function (string $ekKosul) use ($db, $kaynak, $personelId): float {
                    $row = $db->fetchOne("SELECT ISNULL(SUM(o.odeme_tutar), 0) as toplam $kaynak AND $ekKosul", [$personelId]);
                    return (float) ($row['toplam'] ?? 0);
                };
                $adet = function (string $ekKosul) use ($db, $kaynak, $personelId): int {
                    $row = $db->fetchOne("SELECT COUNT(*) as sayi $kaynak AND $ekKosul", [$personelId]);
                    return (int) ($row['sayi'] ?? 0);
                };

                $bekleyen = BEKLEYEN_KOSUL;
                $haftaBas = HAFTA_BAS;
                $haftaSon = HAFTA_SON;

                $stats = [
                    // Tahsil edilenler
                    'tahsil_bu_ay'      => $tutar("o.odeme_yapildi = 1 AND YEAR(o.odeme_tarih) = YEAR(GETDATE()) AND MONTH(o.odeme_tarih) = MONTH(GETDATE())"),
                    'tahsil_bu_yil'     => $tutar("o.odeme_yapildi = 1 AND YEAR(o.odeme_tarih) = YEAR(GETDATE())"),
                    'tahsil_toplam'     => $tutar("o.odeme_yapildi = 1"),

                    // Bekleyenler
                    'bekleyen_toplam'   => $tutar($bekleyen),
                    'bekleyen_adet'     => $adet($bekleyen),

                    // Vadesi geçmiş
                    'geciken_tutar'     => $tutar("$bekleyen AND o.odeme_vade_tarih < CAST(GETDATE() AS DATE)"),
                    'geciken_adet'      => $adet("$bekleyen AND o.odeme_vade_tarih < CAST(GETDATE() AS DATE)"),

                    // Yaklaşanlar
                    'bugun_tutar'       => $tutar("$bekleyen AND o.odeme_vade_tarih = CAST(GETDATE() AS DATE)"),
                    'bugun_adet'        => $adet("$bekleyen AND o.odeme_vade_tarih = CAST(GETDATE() AS DATE)"),
                    'hafta_tutar'       => $tutar("$bekleyen AND o.odeme_vade_tarih BETWEEN $haftaBas AND $haftaSon"),
                    'hafta_adet'        => $adet("$bekleyen AND o.odeme_vade_tarih BETWEEN $haftaBas AND $haftaSon"),

                    // Üzerimdeki tahsilat belgeleri (şu an bu kullanıcıda olanlar)
                    'uzerimde_tutar'    => (float) ($db->fetchOne("
                        SELECT ISNULL(SUM(o.odeme_tutar), 0) as toplam
                        FROM Sozlesme_Odemeler o
                        LEFT JOIN Sozlesme_OdemeDurumlari d ON o.odeme_durum_id = d.odeme_durum_id
                        WHERE o.odeme_guncel_personel_id = ? AND $bekleyen
                    ", [$personelId])['toplam'] ?? 0),
                    'uzerimde_adet'     => (int) ($db->fetchOne("
                        SELECT COUNT(*) as sayi
                        FROM Sozlesme_Odemeler o
                        LEFT JOIN Sozlesme_OdemeDurumlari d ON o.odeme_durum_id = d.odeme_durum_id
                        WHERE o.odeme_guncel_personel_id = ? AND $bekleyen
                    ", [$personelId])['sayi'] ?? 0),
                ];

                // Bekleyenlerin durum kırılımı (rozet renk/ikon bilgisi tablodan gelir)
                $stats['durum_dagilimi'] = $db->fetchAll("
                    SELECT
                        ISNULL(d.odeme_durum_ad, 'Durumsuz')   as durum_ad,
                        ISNULL(d.odeme_durum_renk, 'secondary') as durum_renk,
                        ISNULL(d.odeme_durum_icon, '')          as durum_icon,
                        COUNT(*)                                as adet,
                        ISNULL(SUM(o.odeme_tutar), 0)           as tutar
                    $kaynak AND $bekleyen
                    GROUP BY d.odeme_durum_ad, d.odeme_durum_renk, d.odeme_durum_icon, d.odeme_durum_sira
                    ORDER BY ISNULL(d.odeme_durum_sira, 999)
                ", [$personelId]);

                echo json_encode(['success' => true, 'data' => $stats]);
                break;

            case 'yaklasan_tahsilatlar':
                // Vadesi geçmişler + önümüzdeki 30 gün; en acil olan üstte
                $gun = (int) ($_POST['gun'] ?? 30);
                if ($gun < 1 || $gun > 365) { $gun = 30; }

                $bekleyen = BEKLEYEN_KOSUL;
                $liste = $db->fetchAll("
                    SELECT TOP 100
                        o.odeme_id,
                        o.odeme_tutar,
                        CONVERT(VARCHAR(10), o.odeme_vade_tarih, 120) as odeme_vade_tarih,
                        DATEDIFF(DAY, CAST(GETDATE() AS DATE), o.odeme_vade_tarih) as kalan_gun,
                        o.odeme_belge_no,
                        s.sozlesme_id,
                        ISNULL(c.cari_unvan, c.cari_adi) as cari_ad,
                        c.cari_telefon,
                        ISNULL(t.odeme_tipi_ad, '-')            as odeme_tipi_ad,
                        ISNULL(d.odeme_durum_ad, 'Durumsuz')    as durum_ad,
                        ISNULL(d.odeme_durum_renk, 'secondary') as durum_renk
                    FROM Sozlesme_Odemeler o
                    INNER JOIN Sozlesmeler s ON o.odeme_sozlesme_id = s.sozlesme_id
                    LEFT JOIN Cari c ON s.sozlesme_cari_id = c.cari_id
                    LEFT JOIN Sozlesme_OdemeTipleri t ON o.odeme_tipi_id = t.odeme_tipi_id
                    LEFT JOIN Sozlesme_OdemeDurumlari d ON o.odeme_durum_id = d.odeme_durum_id
                    WHERE s.sozlesme_personel_id = ?
                      AND $bekleyen
                      AND o.odeme_vade_tarih <= DATEADD(DAY, ?, CAST(GETDATE() AS DATE))
                    ORDER BY o.odeme_vade_tarih ASC
                ", [$personelId, $gun]);

                echo json_encode(['success' => true, 'data' => $liste]);
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
    <title>Ana Sayfa - <?= htmlspecialchars($siteTitle) ?></title>

    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/@fontsource/source-sans-3@5.0.12/index.css" crossorigin="anonymous">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.13.1/font/bootstrap-icons.min.css" crossorigin="anonymous">
    <link rel="stylesheet" href="/admin/assets/css/adminlte.min.css">
    <link href="https://cdn.jsdelivr.net/npm/sweetalert2@11/dist/sweetalert2.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css" rel="stylesheet" />
    <link href="https://cdn.jsdelivr.net/npm/select2-bootstrap-5-theme@1.3.0/dist/select2-bootstrap-5-theme.min.css" rel="stylesheet" />
    <link rel="stylesheet" href="/admin/assets/css/custom.css">
    <style>
        .dashboard-card { transition: transform 0.2s, box-shadow 0.2s; border: none; }
        .dashboard-card:hover { transform: translateY(-3px); box-shadow: 0 8px 25px rgba(0,0,0,0.1); }
        .small-box .inner h3 { font-size: 1.6rem; font-weight: 700; }
        .small-box .inner p { font-size: 0.85rem; }
        .module-header { font-size: 1rem; font-weight: 600; letter-spacing: 0.5px; text-transform: uppercase; }

        /* Mobil öncelikli ihbar butonu */
        .ihbar-btn {
            display: flex; align-items: center; justify-content: center; gap: .75rem;
            width: 100%; padding: 1.1rem 1rem; border-radius: .75rem;
            font-size: 1.15rem; font-weight: 700; letter-spacing: .3px;
            box-shadow: 0 6px 18px rgba(220, 53, 69, .35);
        }
        .ihbar-btn i { font-size: 1.6rem; }
        @media (min-width: 992px) {
            .ihbar-btn { width: auto; min-width: 320px; margin-inline: auto; }
        }

        .table-dashboard th { font-size: .78rem; text-transform: uppercase; letter-spacing: .5px; color: #6c757d; border-top: none; }
        .table-dashboard td { font-size: .85rem; vertical-align: middle; }
        .vade-gecmis { background-color: rgba(220, 53, 69, .07); }
        .vade-bugun  { background-color: rgba(255, 193, 7, .12); }
    </style>
</head>
<body class="layout-fixed sidebar-expand-lg sidebar-open bg-body-tertiary">
    <div class="app-wrapper">
        <?php include __DIR__ . '/../includes/header.php'; ?>
        <?php include __DIR__ . '/../includes/sidebar.php'; ?>

        <main class="app-main">
            <div class="app-content">
                <div class="container-fluid">

                    <!-- ═══ HIZLI İHBAR (mobil öncelikli, en üstte) ═══ -->
                    <div class="row mb-4">
                        <div class="col-12 text-center">
                            <a href="/admin/ihbar" class="btn btn-danger ihbar-btn">
                                <i class="bi bi-exclamation-octagon-fill"></i>
                                <span><?= htmlspecialchars($ihbarBaslik) ?> Oluştur</span>
                            </a>
                            <div class="form-text mt-2">Sahada tespit ettiğiniz durumu konumuyla birlikte anında kaydedin.</div>
                        </div>
                    </div>

                    <!-- ═══ TAHSİLAT DURUMU ═══ -->
                    <div class="d-flex align-items-center mb-3">
                        <span class="module-header text-primary"><i class="bi bi-cash-coin me-2"></i>Tahsilat Durumum</span>
                        <hr class="flex-grow-1 ms-3 my-0" style="border-color: #0d6efd;">
                        <a href="/admin/tahsilat-ajandasi" class="btn btn-sm btn-outline-primary ms-3">
                            <i class="bi bi-arrow-right"></i> <span class="d-none d-sm-inline"><?= htmlspecialchars($tahsilatBaslik) ?></span>
                        </a>
                    </div>

                    <div class="row mb-3">
                        <div class="col-6 col-lg-3">
                            <div class="small-box text-bg-success dashboard-card">
                                <div class="inner">
                                    <h3 id="st_tahsil_bu_ay">-</h3>
                                    <p>Bu Ay Tahsil Edilen</p>
                                </div>
                                <div class="small-box-icon"><i class="bi bi-check2-circle"></i></div>
                            </div>
                        </div>
                        <div class="col-6 col-lg-3">
                            <div class="small-box text-bg-warning dashboard-card">
                                <div class="inner">
                                    <h3 id="st_bekleyen">-</h3>
                                    <p>Bekleyen Tahsilat <span id="st_bekleyen_adet" class="badge bg-dark"></span></p>
                                </div>
                                <div class="small-box-icon"><i class="bi bi-hourglass-split"></i></div>
                            </div>
                        </div>
                        <div class="col-6 col-lg-3">
                            <div class="small-box text-bg-danger dashboard-card">
                                <div class="inner">
                                    <h3 id="st_geciken">-</h3>
                                    <p>Vadesi Geçmiş <span id="st_geciken_adet" class="badge bg-dark"></span></p>
                                </div>
                                <div class="small-box-icon"><i class="bi bi-exclamation-triangle"></i></div>
                            </div>
                        </div>
                        <div class="col-6 col-lg-3">
                            <div class="small-box text-bg-info dashboard-card">
                                <div class="inner">
                                    <h3 id="st_uzerimde">-</h3>
                                    <p>Üzerimdeki Belge <span id="st_uzerimde_adet" class="badge bg-dark"></span></p>
                                </div>
                                <div class="small-box-icon"><i class="bi bi-wallet2"></i></div>
                            </div>
                        </div>
                    </div>

                    <div class="row mb-4">
                        <div class="col-md-6 mb-3 mb-md-0">
                            <div class="card h-100">
                                <div class="card-header"><h3 class="card-title"><i class="bi bi-calendar-event me-1"></i> Yaklaşan Vadeler</h3></div>
                                <div class="card-body">
                                    <div class="d-flex justify-content-between align-items-center mb-3">
                                        <span class="text-muted">Bugün</span>
                                        <span><strong id="st_bugun">-</strong> <span id="st_bugun_adet" class="badge bg-secondary"></span></span>
                                    </div>
                                    <div class="d-flex justify-content-between align-items-center mb-3">
                                        <span class="text-muted">Bu Hafta</span>
                                        <span><strong id="st_hafta">-</strong> <span id="st_hafta_adet" class="badge bg-secondary"></span></span>
                                    </div>
                                    <hr>
                                    <div class="d-flex justify-content-between align-items-center mb-2">
                                        <span class="text-muted">Bu Yıl Tahsil Edilen</span>
                                        <strong id="st_tahsil_bu_yil">-</strong>
                                    </div>
                                    <div class="d-flex justify-content-between align-items-center">
                                        <span class="text-muted">Toplam Tahsil Edilen</span>
                                        <strong id="st_tahsil_toplam">-</strong>
                                    </div>
                                </div>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="card h-100">
                                <div class="card-header"><h3 class="card-title"><i class="bi bi-pie-chart me-1"></i> Bekleyenlerin Durumu</h3></div>
                                <div class="card-body" id="durumDagilimi">
                                    <div class="text-center text-muted py-3">Yükleniyor...</div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- ═══ YAKLAŞAN TAHSİLATLAR LİSTESİ ═══ -->
                    <div class="card mb-4">
                        <div class="card-header d-flex flex-wrap gap-2 align-items-center">
                            <h3 class="card-title mb-0"><i class="bi bi-list-check me-1"></i> Takip Edilecek Tahsilatlar</h3>
                            <div class="ms-auto">
                                <select class="form-select form-select-sm" id="gunFiltre" style="min-width: 170px;">
                                    <option value="7">Vadesi geçmiş + 7 gün</option>
                                    <option value="30" selected>Vadesi geçmiş + 30 gün</option>
                                    <option value="90">Vadesi geçmiş + 90 gün</option>
                                </select>
                            </div>
                        </div>
                        <div class="card-body p-0">
                            <div class="table-responsive">
                                <table class="table table-hover table-dashboard mb-0">
                                    <thead>
                                        <tr>
                                            <th>Vade</th>
                                            <th>Müşteri</th>
                                            <th class="d-none d-md-table-cell">Tip</th>
                                            <th class="d-none d-md-table-cell">Durum</th>
                                            <th class="text-end">Tutar</th>
                                            <th class="text-center">Ara</th>
                                        </tr>
                                    </thead>
                                    <tbody id="tahsilatListesi">
                                        <tr><td colspan="6" class="text-center text-muted py-4">Yükleniyor...</td></tr>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </div>

                </div>
            </div>
        </main>

        <?php include __DIR__ . '/../includes/footer.php'; ?>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/jquery@3.7.1/dist/jquery.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/@popperjs/core@2.11.8/dist/umd/popper.min.js" crossorigin="anonymous"></script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.7/dist/js/bootstrap.min.js" crossorigin="anonymous"></script>
    <script src="/admin/assets/js/adminlte.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11/dist/sweetalert2.all.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js"></script>
    <script src="/admin/assets/js/custom.js"></script>
    <script>
        const paraFormat = new Intl.NumberFormat('tr-TR', { style: 'currency', currency: 'TRY', maximumFractionDigits: 0 });
        const para = (v) => paraFormat.format(Number(v) || 0);

        function setText(id, deger) {
            const el = document.getElementById(id);
            if (el) el.textContent = deger;
        }

        function loadStats() {
            $.ajax({
                url: '', method: 'POST', dataType: 'json',
                data: { action: 'saha_stats' },
                success: function (res) {
                    if (!res.success) { showToast(res.message || 'İstatistikler yüklenemedi', 'error'); return; }
                    const d = res.data;

                    setText('st_tahsil_bu_ay',   para(d.tahsil_bu_ay));
                    setText('st_tahsil_bu_yil',  para(d.tahsil_bu_yil));
                    setText('st_tahsil_toplam',  para(d.tahsil_toplam));
                    setText('st_bekleyen',       para(d.bekleyen_toplam));
                    setText('st_bekleyen_adet',  d.bekleyen_adet + ' adet');
                    setText('st_geciken',        para(d.geciken_tutar));
                    setText('st_geciken_adet',   d.geciken_adet + ' adet');
                    setText('st_uzerimde',       para(d.uzerimde_tutar));
                    setText('st_uzerimde_adet',  d.uzerimde_adet + ' adet');
                    setText('st_bugun',          para(d.bugun_tutar));
                    setText('st_bugun_adet',     d.bugun_adet + ' adet');
                    setText('st_hafta',          para(d.hafta_tutar));
                    setText('st_hafta_adet',     d.hafta_adet + ' adet');

                    renderDurumDagilimi(d.durum_dagilimi || []);
                },
                error: function () { showToast('Sunucu hatası oluştu', 'error'); }
            });
        }

        function renderDurumDagilimi(liste) {
            const kap = document.getElementById('durumDagilimi');
            if (!liste.length) {
                kap.innerHTML = '<div class="text-center text-muted py-3">Bekleyen tahsilatınız yok.</div>';
                return;
            }
            kap.innerHTML = liste.map(d => `
                <div class="d-flex justify-content-between align-items-center mb-2">
                    <span>
                        <span class="badge bg-${d.durum_renk}">
                            ${d.durum_icon ? `<i class="${d.durum_icon} me-1"></i>` : ''}${d.durum_ad}
                        </span>
                        <span class="text-muted ms-1">${d.adet} adet</span>
                    </span>
                    <strong>${para(d.tutar)}</strong>
                </div>
            `).join('');
        }

        function loadTahsilatListesi() {
            const gun = document.getElementById('gunFiltre').value;
            $.ajax({
                url: '', method: 'POST', dataType: 'json',
                data: { action: 'yaklasan_tahsilatlar', gun: gun },
                success: function (res) {
                    const tbody = document.getElementById('tahsilatListesi');
                    if (!res.success) {
                        tbody.innerHTML = '<tr><td colspan="6" class="text-center text-danger py-4">Liste yüklenemedi</td></tr>';
                        return;
                    }
                    if (!res.data.length) {
                        tbody.innerHTML = '<tr><td colspan="6" class="text-center text-muted py-4">Bu aralıkta takip edilecek tahsilat yok.</td></tr>';
                        return;
                    }

                    tbody.innerHTML = res.data.map(o => {
                        const kalan = Number(o.kalan_gun);
                        const satirSinif = kalan < 0 ? 'vade-gecmis' : (kalan === 0 ? 'vade-bugun' : '');
                        const vadeNot = kalan < 0
                            ? `<span class="badge bg-danger">${Math.abs(kalan)} gün geçti</span>`
                            : (kalan === 0 ? '<span class="badge bg-warning text-dark">Bugün</span>'
                                           : `<span class="badge bg-light text-dark">${kalan} gün</span>`);

                        const tel = (o.cari_telefon || '').replace(/[^0-9+]/g, '');
                        const araBtn = tel
                            ? `<a href="tel:${tel}" class="btn btn-sm btn-success"><i class="bi bi-telephone-fill"></i></a>`
                            : '<span class="text-muted">-</span>';

                        return `
                            <tr class="${satirSinif}">
                                <td class="text-nowrap">${formatDate(o.odeme_vade_tarih)}<br>${vadeNot}</td>
                                <td>
                                    <a href="/admin/sozlesme-form?id=${o.sozlesme_id}" class="fw-semibold text-decoration-none">${o.cari_ad || '-'}</a>
                                    ${o.odeme_belge_no ? `<br><small class="text-muted">Belge: ${o.odeme_belge_no}</small>` : ''}
                                </td>
                                <td class="d-none d-md-table-cell">${o.odeme_tipi_ad}</td>
                                <td class="d-none d-md-table-cell"><span class="badge bg-${o.durum_renk}">${o.durum_ad}</span></td>
                                <td class="text-end fw-bold text-nowrap">${para(o.odeme_tutar)}</td>
                                <td class="text-center">${araBtn}</td>
                            </tr>
                        `;
                    }).join('');
                },
                error: function () {
                    document.getElementById('tahsilatListesi').innerHTML =
                        '<tr><td colspan="6" class="text-center text-danger py-4">Sunucu hatası</td></tr>';
                }
            });
        }

        $(document).ready(function () {
            $('#gunFiltre').select2({
                theme: 'bootstrap-5',
                width: '100%',
                language: { noResults: () => 'Sonuç bulunamadı' }
            });

            loadStats();
            loadTahsilatListesi();
            $('#gunFiltre').on('change', loadTahsilatListesi);
        });
    </script>
</body>
</html>
