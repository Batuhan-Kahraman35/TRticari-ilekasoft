<?php
/**
 * Satılan / Satılmayan İllegaller Raporu - Görünüm
 * illegal-rapor.php tarafından include edilir, tek başına çağrılmaz.
 */
if (!isset($pagePermissions)) {
    exit;
}
$raporIkon = $illegalRaporSatildi ? 'bi-cash-coin' : 'bi-exclamation-triangle';
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
    <link rel="stylesheet" href="https://cdn.datatables.net/1.13.7/css/dataTables.bootstrap5.min.css">
    <link rel="stylesheet" href="/admin/assets/css/custom.css">
    <style>
        .info-box { transition: transform 0.2s; }
        .info-box:hover { transform: translateY(-3px); box-shadow: 0 4px 6px rgba(0,0,0,0.1); }
    </style>
</head>
<body class="layout-fixed sidebar-expand-lg sidebar-open bg-body-tertiary">
    <div class="app-wrapper">
        <?php include __DIR__ . '/header.php'; ?>
        <?php include __DIR__ . '/sidebar.php'; ?>

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

                    <!-- Rapora dahil statüler (filtre dropdown'u ile aynı kaynak) -->
                    <div class="alert alert-light border d-flex flex-wrap align-items-center gap-2 py-2 mb-3">
                        <span class="fw-semibold me-1"><i class="bi bi-info-circle text-primary"></i> Bu rapordaki statüler:</span>
                        <?php foreach ($statuler as $st): ?>
                        <span class="badge text-bg-secondary fw-normal"><?= htmlspecialchars($st['statu_ad']) ?></span>
                        <?php endforeach; ?>
                        <?php if (!$illegalRaporSatildi && $illegalRaporStatuId === 0): ?>
                        <span class="badge text-bg-light border fw-normal fst-italic">Statüsü atanmamış</span>
                        <?php endif; ?>
                    </div>

                    <!-- Info Boxes -->
                    <div class="row mb-3">
                        <div class="col-12 col-sm-6 col-md-3">
                            <div class="info-box text-bg-primary">
                                <span class="info-box-icon"><i class="bi <?= $raporIkon ?>"></i></span>
                                <div class="info-box-content">
                                    <span class="info-box-text">Kayıt / Satır</span>
                                    <span class="info-box-number" id="stat-kayit">0 / 0</span>
                                </div>
                            </div>
                        </div>
                        <div class="col-12 col-sm-6 col-md-3">
                            <div class="info-box text-bg-info">
                                <span class="info-box-icon"><i class="bi bi-people"></i></span>
                                <div class="info-box-content">
                                    <span class="info-box-text">Farklı Müşteri</span>
                                    <span class="info-box-number" id="stat-musteri">0</span>
                                </div>
                            </div>
                        </div>
                        <div class="col-12 col-sm-6 col-md-3">
                            <div class="info-box text-bg-secondary">
                                <span class="info-box-icon"><i class="bi bi-geo-alt"></i></span>
                                <div class="info-box-content">
                                    <span class="info-box-text">Şehir Sayısı</span>
                                    <span class="info-box-number" id="stat-sehir">0</span>
                                </div>
                            </div>
                        </div>
                        <div class="col-12 col-sm-6 col-md-3">
                            <div class="info-box text-bg-success">
                                <span class="info-box-icon"><i class="bi bi-currency-exchange"></i></span>
                                <div class="info-box-content">
                                    <span class="info-box-text">Toplam Tutar</span>
                                    <span class="info-box-number" id="stat-tutar">0,00 ₺</span>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Filtre Kartı -->
                    <div class="card card-primary card-outline mb-3">
                        <div class="card-header">
                            <h3 class="card-title"><i class="bi bi-funnel"></i> Filtrele</h3>
                            <div class="card-tools">
                                <button type="button" class="btn btn-tool" data-bs-toggle="collapse" data-bs-target="#filterCard">
                                    <i class="bi bi-chevron-down"></i>
                                </button>
                            </div>
                        </div>
                        <div class="card-body collapse" id="filterCard">
                            <form id="filterForm">
                                <div class="row g-3">
                                    <div class="col-md-2">
                                        <label class="form-label">Sezon</label>
                                        <select class="form-select" id="filter_sezon_id" name="sezon_id">
                                            <option value="">Tümü</option>
                                            <?php foreach ($sezonlar as $sz): ?>
                                                <option value="<?= $sz['sezon_id'] ?>" <?= ((string)$sz['sezon_id'] === $varsayilanSezonId) ? 'selected' : '' ?>><?= htmlspecialchars($sz['sezon_ad']) ?></option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                    <div class="col-md-3">
                                        <label class="form-label">Müşteri</label>
                                        <input type="text" class="form-control" id="filter_musteri" name="musteri" placeholder="Müşteri adı / unvan ara...">
                                    </div>
                                    <div class="col-md-2">
                                        <label class="form-label">Şehir</label>
                                        <select class="form-select" id="filter_sehir_id" name="sehir_id">
                                            <option value="">Tümü</option>
                                            <?php foreach ($sehirler as $s): ?>
                                                <option value="<?= $s['SehirId'] ?>"><?= htmlspecialchars($s['SehirAdi']) ?></option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                    <div class="col-md-2">
                                        <label class="form-label">İlçe</label>
                                        <select class="form-select" id="filter_ilce_id" name="ilce_id" disabled>
                                            <option value="">Önce Şehir Seç</option>
                                        </select>
                                    </div>
                                    <div class="col-md-3">
                                        <label class="form-label">Ürün/Hizmet</label>
                                        <select class="form-select" id="filter_urun_id" name="urun_id">
                                            <option value="">Tümü</option>
                                            <?php foreach ($urunler as $u): ?>
                                                <option value="<?= $u['urun_hizmet_id'] ?>"><?= htmlspecialchars($u['urun_hizmet_adi']) ?></option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                </div>
                                <div class="row g-3 mt-2">
                                    <div class="col-md-3">
                                        <label class="form-label">Statü</label>
                                        <select class="form-select" id="filter_statu_id" name="statu_id">
                                            <option value="">Tümü</option>
                                            <?php foreach ($statuler as $st): ?>
                                                <option value="<?= $st['statu_id'] ?>"><?= htmlspecialchars($st['statu_ad']) ?></option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                    <div class="col-md-2">
                                        <label class="form-label">Tespit Başlangıç</label>
                                        <input type="date" class="form-control" id="filter_tespit_bas" name="tespit_bas">
                                    </div>
                                    <div class="col-md-2">
                                        <label class="form-label">Tespit Bitiş</label>
                                        <input type="date" class="form-control" id="filter_tespit_bit" name="tespit_bit">
                                    </div>
                                    <?php if ($sorumlulukAktif): ?>
                                    <div class="col-md-2">
                                        <label class="form-label">Sözleşme Başlangıç</label>
                                        <input type="date" class="form-control" id="filter_sozlesme_bas" name="sozlesme_bas">
                                    </div>
                                    <div class="col-md-2">
                                        <label class="form-label">Sözleşme Bitiş</label>
                                        <input type="date" class="form-control" id="filter_sozlesme_bit" name="sozlesme_bit">
                                    </div>
                                    <div class="col-md-3">
                                        <label class="form-label">Sorumlu</label>
                                        <select class="form-select" id="filter_sorumlu_id" name="sorumlu_id">
                                            <option value="">Tümü</option>
                                            <option value="yok">Atanmamış</option>
                                            <?php foreach ($sorumlular as $sr): ?>
                                                <option value="<?= (int)$sr['kullanici_id'] ?>"><?= htmlspecialchars($sr['sorumlu_adi']) ?></option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                    <?php endif; ?>
                                    <div class="col-md-5 d-flex align-items-end">
                                        <button type="submit" class="btn btn-primary me-2">
                                            <i class="bi bi-search"></i> Filtrele
                                        </button>
                                        <button type="button" class="btn btn-secondary" id="clearFilters">
                                            <i class="bi bi-x-circle"></i> Temizle
                                        </button>
                                    </div>
                                </div>
                            </form>
                        </div>
                    </div>

                    <!-- Ana Kart -->
                    <div class="card card-primary card-outline">
                        <div class="card-header">
                            <h3 class="card-title"><i class="bi <?= $raporIkon ?>"></i> <?= htmlspecialchars($pageTitle) ?></h3>
                            <div class="card-tools">
                                <?php if ($canAssign): ?>
                                    <span class="badge bg-primary me-1" id="secim-sayaci">0 seçili</span>
                                    <button type="button" class="btn btn-sm btn-primary" id="btnTopluAta" disabled>
                                        <i class="bi bi-person-plus"></i> Sorumlu Ata
                                    </button>
                                    <button type="button" class="btn btn-sm btn-outline-success" id="btnWhatsapp" disabled>
                                        <i class="bi bi-whatsapp"></i> WhatsApp ile Bildir
                                    </button>
                                    <button type="button" class="btn btn-sm btn-outline-primary" id="btnSms" disabled>
                                        <i class="bi bi-chat-dots"></i> SMS ile Bildir
                                    </button>
                                <?php endif; ?>
                                <button type="button" class="btn btn-sm btn-success" onclick="exportExcel()">
                                    <i class="bi bi-file-earmark-excel"></i> Excel
                                </button>
                                <button type="button" class="btn btn-sm btn-secondary" data-bs-toggle="collapse" data-bs-target="#filterCard">
                                    <i class="bi bi-funnel"></i> Filtre
                                </button>
                            </div>
                        </div>
                        <div class="card-body p-0">
                            <table class="table table-bordered table-striped table-hover" id="mainTable">
                                <thead>
                                    <tr>
                                        <?php if ($sorumlulukAktif): ?>
                                        <th class="text-center" style="width:36px;">
                                            <?php if ($canAssign): ?>
                                            <div class="form-check form-switch d-flex justify-content-center">
                                                <input class="form-check-input" type="checkbox" role="switch" id="chkTumu">
                                                <label class="form-check-label" for="chkTumu"></label>
                                            </div>
                                            <?php endif; ?>
                                        </th>
                                        <?php endif; ?>
                                        <th>Müşteri Adı</th>
                                        <th>Şehir</th>
                                        <th>İlçe</th>
                                        <th>Ürün/Hizmet</th>
                                        <th class="text-end">Fiyat</th>
                                        <th>Statü</th>
                                        <th>Sezon</th>
                                        <th>Tespit Tarihi</th>
                                        <?php if ($sorumlulukAktif): ?>
                                        <th>Sözleşme Tarihi</th>
                                        <th>Sorumlu</th>
                                        <?php endif; ?>
                                    </tr>
                                </thead>
                                <tbody></tbody>
                            </table>
                        </div>
                    </div>

                </div>
            </div>
        </main>

        <?php include __DIR__ . '/footer.php'; ?>
    </div>

    <?php if ($canAssign): ?>
    <!-- Sorumlu atama -->
    <div class="modal fade" id="ataModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title"><i class="bi bi-person-plus"></i> Sorumlu Ata</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <p class="mb-3">
                        Seçili satırlara ait <span class="fw-semibold" id="ata-sozlesme-sayisi">0</span> sözleşme güncellenecek.
                    </p>
                    <label class="form-label">Sorumlu Kullanıcı</label>
                    <select class="form-select" id="ata_sorumlu_id">
                        <option value="">— Atamayı kaldır —</option>
                        <?php foreach ($sorumlular as $sr): ?>
                            <option value="<?= (int)$sr['kullanici_id'] ?>"><?= htmlspecialchars($sr['sorumlu_adi']) ?></option>
                        <?php endforeach; ?>
                    </select>
                    <div class="alert alert-info mt-3 mb-0 small">
                        <i class="bi bi-whatsapp"></i>
                        Kaydedildikten sonra seçilen sorumluya, kayıtların tespit tarihi / ünvan /
                        şehir-ilçe / telefon bilgilerini içeren WhatsApp bildirimi gönderilir.
                        "Atamayı kaldır" seçilirse bildirim gönderilmez.
                    </div>
                    <?php if (!$sorumlular): ?>
                        <div class="alert alert-warning mt-3 mb-0 small">
                            Atanabilir kullanıcı bulunamadı. Saha Satış departmanında aktif kullanıcı olmalı.
                        </div>
                    <?php endif; ?>
                </div>
                <div class="modal-footer">
                    <button class="btn btn-secondary" data-bs-dismiss="modal">Vazgeç</button>
                    <button class="btn btn-primary" id="btnAtaKaydet"><i class="bi bi-whatsapp"></i> Kaydet ve Bildir</button>
                </div>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <script src="https://cdn.jsdelivr.net/npm/jquery@3.7.1/dist/jquery.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/@popperjs/core@2.11.8/dist/umd/popper.min.js" crossorigin="anonymous"></script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.min.js" crossorigin="anonymous"></script>
    <script src="/admin/assets/js/adminlte.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11/dist/sweetalert2.all.min.js"></script>
    <script src="https://cdn.datatables.net/1.13.7/js/jquery.dataTables.min.js"></script>
    <script src="https://cdn.datatables.net/1.13.7/js/dataTables.bootstrap5.min.js"></script>
    <script src="/admin/assets/js/custom.js"></script>

    <script>
        const varsayilanSezonId = <?= json_encode($varsayilanSezonId) ?>;
        const SORUMLULUK_AKTIF  = <?= $sorumlulukAktif ? 'true' : 'false' ?>;
        const CAN_ASSIGN        = <?= $canAssign ? 'true' : 'false' ?>;
        let currentFilters = varsayilanSezonId ? { sezon_id: varsayilanSezonId } : {};
        let table = null;

        // Satir bazinda secim: anahtar hareket_id, islem hedefi sozlesme_id.
        // Sozlesmesi olmayan satir secilemez.
        const secili = new Map(); // rowKey -> sozlesme_id

        function rowKey(row) {
            return row.hareket_id ? 'h' + row.hareket_id : 't' + row.takip_id;
        }

        function seciliSozlesmeler() {
            return [...new Set(secili.values())];
        }

        function secimGuncelle() {
            $('#secim-sayaci').text(secili.size + ' seçili');
            $('#btnTopluAta, #btnWhatsapp, #btnSms').prop('disabled', secili.size === 0);
        }

        function fmtPara(deger) {
            return (parseFloat(deger) || 0).toLocaleString('tr-TR', { minimumFractionDigits: 2, maximumFractionDigits: 2 }) + ' ₺';
        }

        function fmtTarih(deger) {
            if (!deger) return '-';
            const p = String(deger).substring(0, 10).split('-');
            return p.length === 3 ? `${p[2]}.${p[1]}.${p[0]}` : deger;
        }

        function esc(deger) {
            return String(deger ?? '').replace(/[&<>"]/g, k => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' })[k]);
        }

        // Özet kutuları tablo sorgusundan bağımsız yüklenir
        function loadStats() {
            $.post('', { action: 'stats', ...currentFilters }, function(res) {
                if (!res.success) return;
                $('#stat-kayit').text(res.data.kayit_adet + ' / ' + res.data.satir_adet);
                $('#stat-musteri').text(res.data.musteri_adet);
                $('#stat-sehir').text(res.data.sehir_adet);
                $('#stat-tutar').text(fmtPara(res.data.toplam_tutar));
            }, 'json');
        }

        function initDataTable() {
            table = $('#mainTable').DataTable({
                processing: true,
                serverSide: true,
                stateSave: true,
                scrollX: true,
                autoWidth: false,
                dom: 'lrtip',
                pageLength: 25,
                lengthMenu: [10, 25, 50, 100, 250],
                language: { url: '//cdn.datatables.net/plug-ins/1.13.7/i18n/tr.json' },
                ajax: {
                    url: '',
                    type: 'POST',
                    data: function(d) {
                        return {
                            action: 'list',
                            draw: d.draw,
                            start: d.start,
                            length: d.length,
                            order: d.order,
                            ...currentFilters
                        };
                    },
                    error: function() {
                        showToast('Liste yüklenirken hata oluştu', 'error');
                    }
                },
                columns: [
                    ...(SORUMLULUK_AKTIF ? [{
                        data: null,
                        orderable: false,
                        className: 'text-center',
                        render: function(d, t, row) {
                            if (!CAN_ASSIGN) return '';
                            if (!row.sozlesme_id) {
                                return '<span class="text-muted" title="Sözleşmesi olmayan satıra atama yapılamaz">-</span>';
                            }
                            const k = rowKey(row);
                            const isaretli = secili.has(k) ? ' checked' : '';
                            return `<div class="form-check form-switch d-flex justify-content-center">
                                        <input class="form-check-input chk-satir" type="checkbox" role="switch"
                                               data-key="${k}" data-sozlesme="${row.sozlesme_id}"${isaretli}>
                                        <label class="form-check-label"></label>
                                    </div>`;
                        }
                    }] : []),
                    { data: 'musteri_adi', render: data => esc(data) || '-' },
                    { data: 'sehir_adi', render: data => esc(data) || '-' },
                    { data: 'ilce_adi', render: data => esc(data) || '-' },
                    { data: 'urun_adi', render: data => esc(data) || '-' },
                    {
                        data: 'fiyat',
                        className: 'text-end',
                        render: function(data) {
                            const v = parseFloat(data) || 0;
                            return v > 0 ? fmtPara(v) : '-';
                        }
                    },
                    { data: 'statu_ad', render: data => data ? `<span class="badge bg-info">${esc(data)}</span>` : '-' },
                    { data: 'sezon_ad', render: data => data ? `<span class="badge bg-secondary">${esc(data)}</span>` : '-' },
                    { data: 'tespit_tarihi', render: data => fmtTarih(data) },
                    ...(SORUMLULUK_AKTIF ? [
                        { data: 'sozlesme_tarihi', render: data => fmtTarih(data) },
                        {
                            data: 'sorumlu_adi',
                            render: function(data, t, row) {
                                if (!row.sozlesme_id) return '<span class="text-muted">-</span>';
                                return data
                                    ? esc(data)
                                    : '<span class="badge bg-danger">Atanmamış</span>';
                            }
                        }
                    ] : [])
                ],
                // Satilmayanda en yeni sozlesmeden en eskiye, satilanda musteri adina gore
                order: SORUMLULUK_AKTIF ? [[9, 'desc']] : [[0, 'asc']],
                drawCallback: function() {
                    if (!CAN_ASSIGN) return;
                    const satirlar = table.rows({ page: 'current' }).data().toArray()
                        .filter(r => r.sozlesme_id);
                    const hepsi = satirlar.length > 0 && satirlar.every(r => secili.has(rowKey(r)));
                    $('#chkTumu').prop('checked', hepsi);
                }
            });
        }

        function exportExcel() {
            const form = document.createElement('form');
            form.method = 'POST';
            form.action = '';
            form.style.display = 'none';

            const actionInput = document.createElement('input');
            actionInput.name = 'action';
            actionInput.value = 'export_excel';
            form.appendChild(actionInput);

            Object.keys(currentFilters).forEach(key => {
                if (currentFilters[key]) {
                    const input = document.createElement('input');
                    input.name = key;
                    input.value = currentFilters[key];
                    form.appendChild(input);
                }
            });

            document.body.appendChild(form);
            form.submit();
            form.remove();
            showToast('Excel dosyası indiriliyor...', 'info');
        }

        $('#filterForm').on('submit', function(e) {
            e.preventDefault();
            currentFilters = {
                sezon_id: $('#filter_sezon_id').val(),
                statu_id: $('#filter_statu_id').val(),
                musteri: $('#filter_musteri').val(),
                sehir_id: $('#filter_sehir_id').val(),
                ilce_id: $('#filter_ilce_id').val(),
                urun_id: $('#filter_urun_id').val(),
                tespit_bas: $('#filter_tespit_bas').val(),
                tespit_bit: $('#filter_tespit_bit').val(),
                sozlesme_bas: $('#filter_sozlesme_bas').val(),
                sozlesme_bit: $('#filter_sozlesme_bit').val(),
                sorumlu_id: $('#filter_sorumlu_id').val()
            };
            Object.keys(currentFilters).forEach(k => {
                if (!currentFilters[k]) delete currentFilters[k];
            });
            secili.clear();
            secimGuncelle();
            table.ajax.reload();
            loadStats();
            showToast('Filtre uygulandı', 'info');
        });

        $('#clearFilters').on('click', function() {
            $('#filterForm')[0].reset();
            $('#filter_sezon_id').val(varsayilanSezonId).trigger('change.select2');
            $('#filter_statu_id').val('').trigger('change.select2');
            $('#filter_sehir_id').val('').trigger('change.select2');
            $('#filter_urun_id').val('').trigger('change.select2');
            $('#filter_ilce_id').html('<option value="">Önce Şehir Seç</option>').prop('disabled', true);
            $('#filter_sorumlu_id').val('').trigger('change.select2');
            currentFilters = varsayilanSezonId ? { sezon_id: varsayilanSezonId } : {};
            secili.clear();
            secimGuncelle();
            table.ajax.reload();
            loadStats();
            showToast('Filtreler temizlendi', 'info');
        });

        $(document).ready(function() {
            const select2Ayar = {
                theme: 'bootstrap-5',
                placeholder: 'Seçiniz...',
                allowClear: true,
                language: {
                    noResults: function() { return "Sonuç bulunamadı"; },
                    searching: function() { return "Aranıyor..."; }
                }
            };

            $('#filter_sezon_id, #filter_statu_id, #filter_sehir_id, #filter_urun_id, #filter_sorumlu_id').select2(select2Ayar);

            // ── Sorumluluk: secim, atama, bildirim ──────────────────
            if (CAN_ASSIGN) {
                $('#mainTable').on('change', '.chk-satir', function() {
                    const k = $(this).data('key');
                    const s = Number($(this).data('sozlesme'));
                    this.checked ? secili.set(k, s) : secili.delete(k);
                    secimGuncelle();
                });

                $('#chkTumu').on('change', function() {
                    const ac = this.checked;
                    table.rows({ page: 'current' }).data().toArray()
                        .filter(r => r.sozlesme_id)
                        .forEach(r => {
                            ac ? secili.set(rowKey(r), Number(r.sozlesme_id)) : secili.delete(rowKey(r));
                        });
                    $('.chk-satir').prop('checked', ac);
                    secimGuncelle();
                });

                $('#btnTopluAta').on('click', function() {
                    $('#ata-sozlesme-sayisi').text(seciliSozlesmeler().length);
                    $('#ata_sorumlu_id').val('');
                    new bootstrap.Modal('#ataModal').show();
                });

                $('#ataModal').on('shown.bs.modal', function() {
                    $('#ata_sorumlu_id').select2({
                        theme: 'bootstrap-5',
                        width: '100%',
                        dropdownParent: $('#ataModal')
                    });
                });

                $('#btnAtaKaydet').on('click', function() {
                    const btn = $(this).prop('disabled', true);
                    $.post('', {
                        action: 'ata',
                        sorumlu_id: $('#ata_sorumlu_id').val(),
                        sozlesme_ids: seciliSozlesmeler()
                    }, function(r) {
                        btn.prop('disabled', false);
                        if (!r.success) { showToast(r.message, 'error'); return; }
                        bootstrap.Modal.getInstance(document.getElementById('ataModal')).hide();

                        // Atama basarili ama bildirim gitmediyse (numara yok, kanal kapali vb.)
                        // mesaj toast'a sigmayacak kadar uzun olabilir, uyari penceresi gosterilir
                        if (r.uyari) {
                            Swal.fire({ icon: 'warning', title: 'Bildirim gönderilemedi', text: r.message });
                        } else {
                            showToast(r.message, 'success');
                        }
                        secili.clear();
                        secimGuncelle();
                        table.ajax.reload(null, false);
                    }, 'json').fail(function() {
                        btn.prop('disabled', false);
                        showToast('Sunucuya ulaşılamadı.', 'error');
                    });
                });

                function bildirimGonder(kanal) {
                    const kanalAdi = kanal === 'sms' ? 'SMS' : 'WhatsApp';
                    Swal.fire({
                        title: kanalAdi + ' ile bildir',
                        html: `<div class="text-start">
                                 <p><strong>${seciliSozlesmeler().length}</strong> sözleşme seçildi.</p>
                                 <p class="small text-muted">
                                   Kayıtlar sorumlularına göre gruplanır, her sorumluya
                                   <strong>tek mesajda</strong> kendi listesi gönderilir.
                                   Sorumlusu atanmamış kayıtlar atlanır.
                                 </p>
                                 <hr class="my-2">
                                 <div class="form-check form-switch">
                                   <input class="form-check-input" type="checkbox" role="switch" id="swMusteri">
                                   <label class="form-check-label" for="swMusteri">Müşteriye de bildirim gönder</label>
                                 </div>
                                 <div id="musteriTelKutu" class="mt-2 ms-4" hidden>
                                   <div class="form-check">
                                     <input class="form-check-input" type="radio" name="musteriTel" id="mtCari" value="cari" checked>
                                     <label class="form-check-label" for="mtCari">Cari Telefon</label>
                                   </div>
                                   <div class="form-check">
                                     <input class="form-check-input" type="radio" name="musteriTel" id="mtYetkili" value="yetkili">
                                     <label class="form-check-label" for="mtYetkili">Yetkili Telefon</label>
                                   </div>
                                   <div class="form-check">
                                     <input class="form-check-input" type="radio" name="musteriTel" id="mtIkisi" value="ikisi">
                                     <label class="form-check-label" for="mtIkisi">İkisi de</label>
                                   </div>
                                   <p class="mb-0 small text-muted mt-1">
                                     Müşteriye cari bazında tek mesaj gider; aynı numara iki alanda
                                     da yazılıysa tek gönderim yapılır.
                                   </p>
                                 </div>
                               </div>`,
                        icon: 'question',
                        showCancelButton: true,
                        confirmButtonText: 'Gönder',
                        cancelButtonText: 'Vazgeç',
                        didOpen: function() {
                            $('#swMusteri').on('change', function() {
                                document.getElementById('musteriTelKutu').hidden = !this.checked;
                            });
                        },
                        preConfirm: function() {
                            return {
                                musteriye: $('#swMusteri').is(':checked') ? 1 : 0,
                                musteri_tel_tipi: $('input[name="musteriTel"]:checked').val() || 'cari'
                            };
                        }
                    }).then(function(res) {
                        if (!res.isConfirmed) return;
                        showLoading(kanalAdi + ' gönderiliyor...');
                        $.post('', {
                            action: 'bildir',
                            kanal: kanal,
                            musteriye: res.value.musteriye,
                            musteri_tel_tipi: res.value.musteri_tel_tipi,
                            sozlesme_ids: seciliSozlesmeler()
                        }, function(r) {
                            hideLoading();
                            if (!r.detay || !r.detay.length) {
                                showToast(r.message, r.success ? 'success' : 'error');
                                return;
                            }
                            const satirlar = r.detay.map(d => `
                                <tr>
                                    <td class="text-center"><span class="badge ${d.tur === 'Müşteri' ? 'bg-info' : 'bg-secondary'}">${esc(d.tur)}</span></td>
                                    <td class="text-start">${esc(d.ad)}</td>
                                    <td class="text-start">${esc(d.hedef || '')}</td>
                                    <td class="text-center">${d.kayit}</td>
                                    <td class="text-start">${d.success
                                        ? '<span class="badge bg-success">Gönderildi</span>'
                                        : '<span class="badge bg-danger">' + esc(d.message) + '</span>'}</td>
                                </tr>`).join('');
                            Swal.fire({
                                title: r.success ? 'Gönderildi' : 'Kısmen gönderildi',
                                icon: r.success ? 'success' : 'warning',
                                width: 800,
                                html: `<p>${esc(r.message)}</p>
                                       <div style="max-height:50vh;overflow:auto;">
                                       <table class="table table-sm">
                                         <thead><tr><th>Tür</th><th class="text-start">Alıcı</th><th class="text-start">Numara</th><th>Kayıt</th><th class="text-start">Sonuç</th></tr></thead>
                                         <tbody>${satirlar}</tbody>
                                       </table>
                                       </div>`
                            });
                        }, 'json').fail(function() {
                            hideLoading();
                            showToast('Sunucuya ulaşılamadı.', 'error');
                        });
                    });
                }

                $('#btnWhatsapp').on('click', function() { bildirimGonder('whatsapp'); });
                $('#btnSms').on('click', function() { bildirimGonder('sms'); });
            }

            $('#filter_sehir_id').on('change', function() {
                const sehirId = $(this).val();
                const ilceSelect = $('#filter_ilce_id');

                if (!sehirId) {
                    ilceSelect.html('<option value="">Önce Şehir Seç</option>').prop('disabled', true);
                    return;
                }

                ilceSelect.html('<option value="">Yükleniyor...</option>').prop('disabled', true);

                $.post('', { action: 'get_ilceler', sehir_id: sehirId }, function(res) {
                    if (!res.success) return;
                    let options = '<option value="">Tümü</option>';
                    res.data.forEach(i => {
                        options += `<option value="${i.ilceId}">${i.IlceAdi}</option>`;
                    });
                    ilceSelect.html(options).prop('disabled', false);
                    ilceSelect.select2(select2Ayar);
                }, 'json');
            });

            initDataTable();
            loadStats();

            $('[data-lte-toggle="sidebar"]').on('click', function() {
                setTimeout(function() {
                    if (table) table.columns.adjust();
                }, 350);
            });

            $(window).on('resize', function() {
                if (table) table.columns.adjust();
            });
        });
    </script>
</body>
</html>
