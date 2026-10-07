<?php
/**
 * Zarf Yazdırma
 * Excel'den yüklenen kayıtlardan 25x17 cm zarf çıktısı alır.
 * Her seçili kayıt = 1 zarf sayfası. Sol alta İŞYERİ ADI ve ADRES basılır.
 */

require_once __DIR__ . '/../auth.php';
requireAuth();

$user = Auth::user();
$db = Database::getInstance();

$siteAyarlari = $db->fetchOne("SELECT TOP 1 site_ayarlari_site_title FROM dbo.tanim_site_ayarlari ORDER BY site_ayarlari_id DESC");
$siteTitle = $siteAyarlari['site_ayarlari_site_title'] ?? 'Portal';

$pageTitle = 'Zarf Yazdırma';
$pageDescription = 'Excel dosyasından 25x17 cm zarf çıktısı';
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
    <link rel="stylesheet" href="https://cdn.datatables.net/1.13.7/css/dataTables.bootstrap5.min.css">
    <link href="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/select2-bootstrap-5-theme@1.3.0/dist/select2-bootstrap-5-theme.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/sweetalert2@11/dist/sweetalert2.min.css" rel="stylesheet">
    <link rel="stylesheet" href="/admin/assets/css/custom.css">
    <!-- Excel okuma -->
    <script src="https://cdn.sheetjs.com/xlsx-0.20.1/package/dist/xlsx.full.min.js"></script>
    <style>
        #zarfPreview {
            background: #e9ecef;
            padding: 15px;
            max-height: 520px;
            overflow: auto;
        }
        .zarf-mini {
            width: 250mm;
            height: 170mm;
            transform: scale(.55);
            transform-origin: top left;
            margin-bottom: -70mm;
        }
    </style>
</head>
<body class="layout-fixed sidebar-expand-lg sidebar-open bg-body-tertiary">
    <div class="app-wrapper">
<?php
include __DIR__ . '/../includes/header.php';
include __DIR__ . '/../includes/sidebar.php';
?>

        <main class="app-main">
            <div class="app-content-header">
                <div class="container-fluid">
                    <div class="row">
                        <div class="col-sm-6">
                            <h3 class="mb-0"><?= $pageTitle ?></h3>
                            <small class="text-muted"><?= $pageDescription ?></small>
                        </div>
                        <div class="col-sm-6">
                            <ol class="breadcrumb float-sm-end">
                                <li class="breadcrumb-item"><a href="anasayfa.php">Ana Sayfa</a></li>
                                <li class="breadcrumb-item active">Zarf Yazdırma</li>
                            </ol>
                        </div>
                    </div>
                </div>
            </div>

            <div class="app-content">
                <div class="container-fluid">

                    <!-- InfoBox -->
                    <div class="row">
                        <div class="col-6 col-md-3">
                            <div class="info-box">
                                <span class="info-box-icon text-bg-primary shadow-sm"><i class="bi bi-list-ul"></i></span>
                                <div class="info-box-content">
                                    <span class="info-box-text">Toplam Kayıt</span>
                                    <span class="info-box-number" id="ibToplam">0</span>
                                </div>
                            </div>
                        </div>
                        <div class="col-6 col-md-3">
                            <div class="info-box">
                                <span class="info-box-icon text-bg-success shadow-sm"><i class="bi bi-check2-square"></i></span>
                                <div class="info-box-content">
                                    <span class="info-box-text">Seçili</span>
                                    <span class="info-box-number" id="ibSecili">0</span>
                                </div>
                            </div>
                        </div>
                        <div class="col-6 col-md-3">
                            <div class="info-box">
                                <span class="info-box-icon text-bg-warning shadow-sm"><i class="bi bi-geo-alt"></i></span>
                                <div class="info-box-content">
                                    <span class="info-box-text">Adresi Boş</span>
                                    <span class="info-box-number" id="ibAdresBos">0</span>
                                </div>
                            </div>
                        </div>
                        <div class="col-6 col-md-3">
                            <div class="info-box">
                                <span class="info-box-icon text-bg-danger shadow-sm"><i class="bi bi-shop"></i></span>
                                <div class="info-box-content">
                                    <span class="info-box-text">İşyeri Adı Boş</span>
                                    <span class="info-box-number" id="ibIsyeriBos">0</span>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Dosya Yükleme -->
                    <div class="card card-primary card-outline mb-3">
                        <div class="card-header">
                            <h3 class="card-title"><i class="bi bi-upload"></i> Excel Yükle</h3>
                            <div class="card-tools">
                                <button type="button" class="btn btn-sm btn-outline-secondary" id="sablonIndirBtn">
                                    <i class="bi bi-download"></i> Şablon İndir
                                </button>
                            </div>
                        </div>
                        <div class="card-body">
                            <div class="row g-3 align-items-end">
                                <div class="col-md-6">
                                    <label class="form-label">Excel / CSV Dosyası</label>
                                    <input type="file" class="form-control" id="excelDosya" accept=".xlsx,.xls,.csv">
                                    <small class="text-muted">İlk satır başlık olmalı. Sütunlar başlık adına göre eşleştirilir.</small>
                                </div>
                                <div class="col-md-6">
                                    <button type="button" class="btn btn-primary" id="yukleBtn" disabled>
                                        <i class="bi bi-file-earmark-arrow-up"></i> Dosyayı Oku
                                    </button>
                                    <button type="button" class="btn btn-outline-danger" id="temizleBtn" disabled>
                                        <i class="bi bi-trash"></i> Listeyi Temizle
                                    </button>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Zarf Ayarları -->
                    <div class="card card-secondary card-outline mb-3 d-none" id="ayarKart">
                        <div class="card-header">
                            <h3 class="card-title"><i class="bi bi-sliders"></i> Zarf Ayarları (25 x 17 cm)</h3>
                            <div class="card-tools">
                                <button type="button" class="btn btn-tool" data-lte-toggle="card-collapse"><i class="bi bi-chevron-up"></i></button>
                            </div>
                        </div>
                        <div class="card-body">
                            <div class="row g-3">
                                <div class="col-md-2">
                                    <label class="form-label">Sol Boşluk (mm)</label>
                                    <input type="number" class="form-control" id="ayarSol" value="25" min="0" max="150">
                                </div>
                                <div class="col-md-2">
                                    <label class="form-label">Alt Boşluk (mm)</label>
                                    <input type="number" class="form-control" id="ayarAlt" value="20" min="0" max="100">
                                </div>
                                <div class="col-md-2">
                                    <label class="form-label">Blok Genişliği (mm)</label>
                                    <input type="number" class="form-control" id="ayarGenislik" value="110" min="40" max="220">
                                </div>
                                <div class="col-md-2">
                                    <label class="form-label">Yazı Boyutu (pt)</label>
                                    <input type="number" class="form-control" id="ayarFont" value="12" min="6" max="24">
                                </div>
                                <div class="col-md-4">
                                    <label class="form-label d-block">Ek Alanlar</label>
                                    <div class="form-check form-switch">
                                        <input class="form-check-input" type="checkbox" role="switch" id="ayarIlceIl" value="1" checked>
                                        <label class="form-check-label" for="ayarIlceIl">Adres altına İLÇE / İL yaz</label>
                                    </div>
                                    <div class="form-check form-switch">
                                        <input class="form-check-input" type="checkbox" role="switch" id="ayarTel" value="1">
                                        <label class="form-check-label" for="ayarTel">Telefon numarasını yaz</label>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Filtre -->
                    <div class="card card-info card-outline mb-3 d-none" id="filtreKart">
                        <div class="card-header">
                            <h3 class="card-title"><i class="bi bi-funnel"></i> Filtre</h3>
                            <div class="card-tools">
                                <button type="button" class="btn btn-tool" data-lte-toggle="card-collapse"><i class="bi bi-chevron-up"></i></button>
                            </div>
                        </div>
                        <div class="card-body">
                            <div class="row g-3">
                                <div class="col-md-3">
                                    <label class="form-label">Arama</label>
                                    <input type="text" class="form-control" id="fArama" placeholder="İşyeri, adres, bayii...">
                                </div>
                                <div class="col-md-2">
                                    <label class="form-label">İl</label>
                                    <select class="form-select select2" id="fIl"><option value="">Tümü</option></select>
                                </div>
                                <div class="col-md-2">
                                    <label class="form-label">İlçe</label>
                                    <select class="form-select select2" id="fIlce"><option value="">Tümü</option></select>
                                </div>
                                <div class="col-md-2">
                                    <label class="form-label">Tür</label>
                                    <select class="form-select select2" id="fTur"><option value="">Tümü</option></select>
                                </div>
                                <div class="col-md-3">
                                    <label class="form-label">Bayii</label>
                                    <select class="form-select select2" id="fBayii"><option value="">Tümü</option></select>
                                </div>
                            </div>
                            <div class="mt-3">
                                <button type="button" class="btn btn-secondary btn-sm" id="filtreTemizleBtn">
                                    <i class="bi bi-x-circle"></i> Filtreyi Temizle
                                </button>
                            </div>
                        </div>
                    </div>

                    <!-- Liste -->
                    <div class="card card-primary card-outline d-none" id="listeKart">
                        <div class="card-header">
                            <h3 class="card-title"><i class="bi bi-table"></i> Kayıtlar</h3>
                            <div class="card-tools">
                                <button type="button" class="btn btn-sm btn-outline-primary" id="tumunuSecBtn">
                                    <i class="bi bi-check-all"></i> Görünenleri Seç
                                </button>
                                <button type="button" class="btn btn-sm btn-outline-secondary" id="secimiKaldirBtn">
                                    <i class="bi bi-x-square"></i> Seçimi Kaldır
                                </button>
                                <button type="button" class="btn btn-sm btn-outline-dark" id="onizleBtn">
                                    <i class="bi bi-eye"></i> Önizle
                                </button>
                                <button type="button" class="btn btn-sm btn-success" id="yazdirBtn">
                                    <i class="bi bi-printer"></i> Zarfları Yazdır
                                </button>
                            </div>
                        </div>
                        <div class="card-body">
                            <table id="zarfTablo" class="table table-bordered table-striped table-hover align-middle" style="width:100%">
                                <thead>
                                    <tr>
                                        <th style="width:50px">Seç</th>
                                        <th>İşyeri Adı</th>
                                        <th>Adres</th>
                                        <th>İl</th>
                                        <th>İlçe</th>
                                        <th>Tür</th>
                                        <th>Bayii</th>
                                        <th>Tel</th>
                                        <th>İşletmeci</th>
                                        <th>Tespit Tarihi</th>
                                    </tr>
                                </thead>
                                <tbody></tbody>
                            </table>
                        </div>
                    </div>

                    <!-- Önizleme -->
                    <div class="card card-outline card-dark mt-3 d-none" id="onizlemeKart">
                        <div class="card-header">
                            <h3 class="card-title"><i class="bi bi-eye"></i> Zarf Önizleme</h3>
                            <div class="card-tools">
                                <button type="button" class="btn btn-tool" id="onizlemeKapatBtn"><i class="bi bi-x-lg"></i></button>
                            </div>
                        </div>
                        <div class="card-body p-0">
                            <div id="zarfPreview"></div>
                        </div>
                    </div>

                </div>
            </div>
        </main>

        <?php include __DIR__ . '/../includes/footer.php'; ?>
    </div>

    <script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/@popperjs/core@2.11.8/dist/umd/popper.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.7/dist/js/bootstrap.min.js"></script>
    <script src="/admin/assets/js/adminlte.min.js"></script>
    <script src="https://cdn.datatables.net/1.13.7/js/jquery.dataTables.min.js"></script>
    <script src="https://cdn.datatables.net/1.13.7/js/dataTables.bootstrap5.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11/dist/sweetalert2.all.min.js"></script>
    <script src="/admin/assets/js/custom.js"></script>

    <script>
    $(function () {

        // Excel başlıkları -> iç alan adları
        const BASLIK_HARITA = {
            'durum': 'durum',
            'tespit adet': 'tespit_adet',
            'lig': 'lig',
            'tespit tarihi': 'tespit_tarihi',
            'tur': 'tur',
            'isletmeci': 'isletmeci',
            'isyeri adi': 'isyeri_adi',
            'il': 'il',
            'ilce': 'ilce',
            'bayii': 'bayii',
            'tel num': 'tel',
            'telefon': 'tel',
            'adres': 'adres',
            'tespit yapilan mac': 'tespit_mac',
            'tespit eden': 'tespit_eden',
            'av. tutanakli': 'av_tutanakli',
            'av tutanakli': 'av_tutanakli'
        };

        const SABLON_BASLIK = ['DURUM','TESPİT ADET','LİG','TESPİT TARİHİ','TÜR','İŞLETMECİ','İŞYERİ ADI','İL','İLÇE','BAYİİ','TEL NUM','ADRES','TESPİT YAPILAN MAÇ','TESPİT EDEN','AV. TUTANAKLI'];

        let kayitlar = [];   // {id, ...alanlar, secili}
        let tablo = null;

        // Türkçe karakterleri sadeleştirerek başlık normalize et
        function normalize(s) {
            return String(s || '')
                .replace(/İ/g, 'i').replace(/I/g, 'i').replace(/ı/g, 'i')
                .replace(/Ş/g, 's').replace(/ş/g, 's')
                .replace(/Ğ/g, 'g').replace(/ğ/g, 'g')
                .replace(/Ü/g, 'u').replace(/ü/g, 'u')
                .replace(/Ö/g, 'o').replace(/ö/g, 'o')
                .replace(/Ç/g, 'c').replace(/ç/g, 'c')
                .toLowerCase().replace(/\s+/g, ' ').trim();
        }

        function esc(s) {
            return String(s == null ? '' : s)
                .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')
                .replace(/"/g, '&quot;');
        }

        function tarihCevir(val) {
            if (val === null || val === undefined || val === '') return '';
            if (typeof val === 'number') {
                const d = XLSX.SSF.parse_date_code(val);
                if (d) {
                    return String(d.d).padStart(2, '0') + '.' + String(d.m).padStart(2, '0') + '.' + d.y;
                }
            }
            return String(val).trim();
        }

        // Şablon indir
        $('#sablonIndirBtn').on('click', function () {
            const ornek = [
                ['Açık','1','Süper Lig','01.08.2026','Kafe','Ahmet Yılmaz','Örnek Cafe','İstanbul','Kadıköy','Bayi A','05321234567','Caferağa Mah. Moda Cad. No:12/A','Fenerbahçe - Galatasaray','Mehmet Demir','Evet']
            ];
            const ws = XLSX.utils.aoa_to_sheet([SABLON_BASLIK, ...ornek]);
            ws['!cols'] = SABLON_BASLIK.map(function (h) { return { wch: Math.max(12, h.length + 4) }; });
            const wb = XLSX.utils.book_new();
            XLSX.utils.book_append_sheet(wb, ws, 'Zarf');
            XLSX.writeFile(wb, 'zarf-yazdirma-sablonu.xlsx');
            showToast('Şablon indirildi', 'success');
        });

        $('#excelDosya').on('change', function () {
            $('#yukleBtn').prop('disabled', !this.files.length);
        });

        // Dosyayı oku
        $('#yukleBtn').on('click', function () {
            const dosya = $('#excelDosya')[0].files[0];
            if (!dosya) return;

            const btn = $(this);
            btn.prop('disabled', true).html('<i class="bi bi-hourglass-split"></i> Okunuyor...');

            const reader = new FileReader();
            reader.onload = function (e) {
                try {
                    const wb = XLSX.read(new Uint8Array(e.target.result), { type: 'array' });
                    const sheet = wb.Sheets[wb.SheetNames[0]];
                    const satirlar = XLSX.utils.sheet_to_json(sheet, { header: 1, blankrows: false });

                    if (!satirlar.length) {
                        showToast('Dosyada veri bulunamadı', 'error');
                        return;
                    }

                    // Başlık satırından kolon index haritası
                    const basliklar = satirlar[0].map(normalize);
                    const idx = {};
                    basliklar.forEach(function (b, i) {
                        if (BASLIK_HARITA[b] !== undefined) idx[BASLIK_HARITA[b]] = i;
                    });

                    if (idx.isyeri_adi === undefined || idx.adres === undefined) {
                        Swal.fire('Başlık bulunamadı',
                            'Dosyada "İŞYERİ ADI" ve "ADRES" sütunları zorunludur. Şablonu indirip kontrol edin.', 'error');
                        return;
                    }

                    const al = function (row, alan) {
                        return idx[alan] === undefined ? '' : String(row[idx[alan]] == null ? '' : row[idx[alan]]).trim();
                    };

                    kayitlar = [];
                    satirlar.slice(1).forEach(function (row) {
                        if (!row || !row.length) return;
                        const isyeri = al(row, 'isyeri_adi');
                        const adres = al(row, 'adres');
                        if (!isyeri && !adres) return;
                        kayitlar.push({
                            id: kayitlar.length,
                            durum: al(row, 'durum'),
                            tespit_adet: al(row, 'tespit_adet'),
                            lig: al(row, 'lig'),
                            tespit_tarihi: tarihCevir(idx.tespit_tarihi === undefined ? '' : row[idx.tespit_tarihi]),
                            tur: al(row, 'tur'),
                            isletmeci: al(row, 'isletmeci'),
                            isyeri_adi: isyeri,
                            il: al(row, 'il'),
                            ilce: al(row, 'ilce'),
                            bayii: al(row, 'bayii'),
                            tel: al(row, 'tel'),
                            adres: adres,
                            tespit_mac: al(row, 'tespit_mac'),
                            tespit_eden: al(row, 'tespit_eden'),
                            av_tutanakli: al(row, 'av_tutanakli'),
                            secili: true
                        });
                    });

                    if (!kayitlar.length) {
                        showToast('Geçerli kayıt bulunamadı', 'error');
                        return;
                    }

                    tabloDoldur();
                    filtreSecenekleriDoldur();
                    $('#ayarKart, #filtreKart, #listeKart').removeClass('d-none');
                    $('#temizleBtn').prop('disabled', false);
                    showToast(kayitlar.length + ' kayıt yüklendi', 'success');
                } catch (err) {
                    Swal.fire('Hata', 'Dosya okunamadı: ' + err.message, 'error');
                } finally {
                    btn.prop('disabled', false).html('<i class="bi bi-file-earmark-arrow-up"></i> Dosyayı Oku');
                }
            };
            reader.readAsArrayBuffer(dosya);
        });

        // Listeyi temizle
        $('#temizleBtn').on('click', function () {
            kayitlar = [];
            if (tablo) { tablo.destroy(); tablo = null; }
            $('#zarfTablo tbody').empty();
            $('#ayarKart, #filtreKart, #listeKart, #onizlemeKart').addClass('d-none');
            $('#excelDosya').val('');
            $('#yukleBtn, #temizleBtn').prop('disabled', true);
            sayaclariGuncelle();
        });

        function tabloDoldur() {
            if (tablo) { tablo.destroy(); tablo = null; }

            const satirHtml = kayitlar.map(function (k) {
                return '<tr data-id="' + k.id + '">' +
                    '<td><div class="d-flex justify-content-center"><div class="form-check form-switch">' +
                        '<input class="form-check-input satir-sec" type="checkbox" role="switch" value="1"' + (k.secili ? ' checked' : '') + '>' +
                        '<label class="form-check-label"></label>' +
                    '</div></div></td>' +
                    '<td>' + esc(k.isyeri_adi) + '</td>' +
                    '<td>' + esc(k.adres) + '</td>' +
                    '<td>' + esc(k.il) + '</td>' +
                    '<td>' + esc(k.ilce) + '</td>' +
                    '<td>' + esc(k.tur) + '</td>' +
                    '<td>' + esc(k.bayii) + '</td>' +
                    '<td>' + esc(k.tel) + '</td>' +
                    '<td>' + esc(k.isletmeci) + '</td>' +
                    '<td>' + esc(k.tespit_tarihi) + '</td>' +
                '</tr>';
            }).join('');

            $('#zarfTablo tbody').html(satirHtml);

            tablo = $('#zarfTablo').DataTable({
                dom: 'lrtip',
                pageLength: 25,
                order: [[1, 'asc']],
                scrollX: true,
                columnDefs: [{ targets: 0, orderable: false, searchable: false }],
                language: { url: '//cdn.datatables.net/plug-ins/1.13.7/i18n/tr.json' }
            });

            sayaclariGuncelle();
        }

        function filtreSecenekleriDoldur() {
            const doldur = function (sel, alan) {
                const degerler = [...new Set(kayitlar.map(function (k) { return k[alan]; }).filter(Boolean))].sort();
                const $s = $(sel);
                $s.html('<option value="">Tümü</option>' + degerler.map(function (d) {
                    return '<option value="' + esc(d) + '">' + esc(d) + '</option>';
                }).join(''));
            };
            doldur('#fIl', 'il');
            doldur('#fIlce', 'ilce');
            doldur('#fTur', 'tur');
            doldur('#fBayii', 'bayii');

            $('.select2').select2({ theme: 'bootstrap-5', width: '100%', placeholder: 'Tümü', allowClear: true });
        }

        // Filtre uygula (DataTables kolon araması)
        function filtreUygula() {
            if (!tablo) return;
            const eslesTam = function (deger) {
                return deger ? '^' + $.fn.dataTable.util.escapeRegex(deger) + '$' : '';
            };
            tablo.column(3).search(eslesTam($('#fIl').val()), true, false);
            tablo.column(4).search(eslesTam($('#fIlce').val()), true, false);
            tablo.column(5).search(eslesTam($('#fTur').val()), true, false);
            tablo.column(6).search(eslesTam($('#fBayii').val()), true, false);
            tablo.search($('#fArama').val()).draw();
        }

        $('#fIl, #fIlce, #fTur, #fBayii').on('change', filtreUygula);
        $('#fArama').on('keyup', filtreUygula);

        $('#filtreTemizleBtn').on('click', function () {
            $('#fArama').val('');
            $('#fIl, #fIlce, #fTur, #fBayii').val('').trigger('change.select2');
            filtreUygula();
        });

        // Seçim
        $('#zarfTablo tbody').on('change', '.satir-sec', function () {
            const id = $(this).closest('tr').data('id');
            const kayit = kayitlar.find(function (k) { return k.id === id; });
            if (kayit) kayit.secili = this.checked;
            sayaclariGuncelle();
        });

        $('#tumunuSecBtn').on('click', function () { gorunenleriIsaretle(true); });
        $('#secimiKaldirBtn').on('click', function () { gorunenleriIsaretle(false); });

        function gorunenleriIsaretle(durum) {
            if (!tablo) return;
            tablo.rows({ search: 'applied' }).nodes().to$().each(function () {
                const id = $(this).data('id');
                const kayit = kayitlar.find(function (k) { return k.id === id; });
                if (kayit) kayit.secili = durum;
                $(this).find('.satir-sec').prop('checked', durum);
            });
            sayaclariGuncelle();
        }

        function sayaclariGuncelle() {
            $('#ibToplam').text(kayitlar.length);
            $('#ibSecili').text(kayitlar.filter(function (k) { return k.secili; }).length);
            $('#ibAdresBos').text(kayitlar.filter(function (k) { return !k.adres; }).length);
            $('#ibIsyeriBos').text(kayitlar.filter(function (k) { return !k.isyeri_adi; }).length);
        }

        function seciliKayitlar() {
            return kayitlar.filter(function (k) { return k.secili; });
        }

        function ayarlar() {
            return {
                sol: parseInt($('#ayarSol').val(), 10) || 0,
                alt: parseInt($('#ayarAlt').val(), 10) || 0,
                genislik: parseInt($('#ayarGenislik').val(), 10) || 110,
                font: parseInt($('#ayarFont').val(), 10) || 12,
                ilceIl: $('#ayarIlceIl').is(':checked'),
                tel: $('#ayarTel').is(':checked')
            };
        }

        // Tek zarf gövdesi
        function zarfHtml(k, a) {
            let satirlar = '<div class="zarf-isyeri">' + esc(k.isyeri_adi) + '</div>';
            if (k.adres) satirlar += '<div class="zarf-adres">' + esc(k.adres) + '</div>';
            if (a.ilceIl) {
                const yer = [k.ilce, k.il].filter(Boolean).join(' / ');
                if (yer) satirlar += '<div class="zarf-yer">' + esc(yer.toUpperCase()) + '</div>';
            }
            if (a.tel && k.tel) satirlar += '<div class="zarf-tel">Tel: ' + esc(k.tel) + '</div>';

            return '<div class="zarf"><div class="zarf-blok">' + satirlar + '</div></div>';
        }

        function zarfCss(a) {
            return '@page { size: 250mm 170mm; margin: 0; }' +
                'html, body { margin: 0; padding: 0; }' +
                'body { font-family: "Source Sans 3", Arial, sans-serif; color: #000; background: #fff; }' +
                '.zarf { position: relative; width: 250mm; height: 170mm; box-sizing: border-box;' +
                    ' page-break-after: always; break-after: page; overflow: hidden; background: #fff; }' +
                '.zarf:last-child { page-break-after: auto; break-after: auto; }' +
                '.zarf-blok { position: absolute; left: ' + a.sol + 'mm; bottom: ' + a.alt + 'mm;' +
                    ' width: ' + a.genislik + 'mm; font-size: ' + a.font + 'pt; line-height: 1.35; }' +
                '.zarf-isyeri { font-weight: 700; font-size: ' + (a.font + 2) + 'pt; text-transform: uppercase; margin-bottom: 2mm; }' +
                '.zarf-adres { white-space: pre-line; }' +
                '.zarf-yer { margin-top: 1.5mm; font-weight: 600; }' +
                '.zarf-tel { margin-top: 1.5mm; }' +
                '@media screen { .zarf { border: 1px solid #adb5bd; margin-bottom: 10mm; } }';
        }

        // Önizleme
        $('#onizleBtn').on('click', function () {
            const secili = seciliKayitlar();
            if (!secili.length) { showToast('Önce kayıt seçin', 'warning'); return; }

            const a = ayarlar();
            const govde = secili.slice(0, 5).map(function (k) { return zarfHtml(k, a); }).join('');
            $('#zarfPreview').html(
                '<style>' + zarfCss(a) + '</style>' +
                '<div class="zarf-mini-wrap">' + govde.replace(/class="zarf"/g, 'class="zarf zarf-mini"') + '</div>' +
                (secili.length > 5 ? '<div class="text-muted small mt-2">İlk 5 zarf gösteriliyor. Toplam ' + secili.length + ' zarf yazdırılacak.</div>' : '')
            );
            $('#onizlemeKart').removeClass('d-none');
            document.getElementById('onizlemeKart').scrollIntoView({ behavior: 'smooth' });
        });

        $('#onizlemeKapatBtn').on('click', function () { $('#onizlemeKart').addClass('d-none'); });

        // Yazdır
        $('#yazdirBtn').on('click', function () {
            const secili = seciliKayitlar();
            if (!secili.length) { showToast('Önce yazdırılacak kayıtları seçin', 'warning'); return; }

            const a = ayarlar();
            const html = '<!DOCTYPE html><html lang="tr"><head><meta charset="UTF-8">' +
                '<title>Zarf Çıktısı</title>' +
                '<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/@fontsource/source-sans-3@5.0.12/index.css">' +
                '<style>' + zarfCss(a) + '@media screen { .zarf { margin: 0 auto 10mm; } }</style>' +
                '</head><body>' +
                secili.map(function (k) { return zarfHtml(k, a); }).join('') +
                '<scr' + 'ipt>window.onload = function(){ setTimeout(function(){ window.print(); }, 400); };</scr' + 'ipt>' +
                '</body></html>';

            const pencere = window.open('', '_blank');
            if (!pencere) { Swal.fire('Uyarı', 'Pop-up engellendi. Tarayıcı ayarlarından izin verin.', 'warning'); return; }
            pencere.document.open();
            pencere.document.write(html);
            pencere.document.close();
        });

    });
    </script>
</body>
</html>
