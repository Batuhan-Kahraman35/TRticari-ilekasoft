<?php
/**
 * Admin Panel - Default Dashboard
 * Departmana özel dashboard tanımlanmamışsa bu sayfa gösterilir
 * NOT: Bu dosya anasayfa.php tarafından include edilir, doğrudan çağrılmaz!
 */

// $user ve $db değişkenleri anasayfa.php'den geliyor

// Site ayarlarını çek
$siteAyarlari = $db->fetchOne("SELECT TOP 1 site_ayarlari_site_title FROM dbo.tanim_site_ayarlari ORDER BY site_ayarlari_id DESC");
$siteTitle = $siteAyarlari['site_ayarlari_site_title'] ?? 'Portal';

// Sayfa başlıklarını veritabanından çek
$sozlesmeBaslik = $db->fetchOne("SELECT sayfalar_sayfa_adi FROM Menu_Sayfalar WHERE sayfalar_sayfa_url LIKE '%sozlesme-yonetimi.php' AND sayfalar_durum = 1");
$hukukBaslik = $db->fetchOne("SELECT sayfalar_sayfa_adi FROM Menu_Sayfalar WHERE sayfalar_sayfa_url LIKE '%hukuk-yonetimi.php' AND sayfalar_durum = 1");
$sucBaslik = $db->fetchOne("SELECT sayfalar_sayfa_adi FROM Menu_Sayfalar WHERE sayfalar_sayfa_url LIKE '%suc-duyurusu-takip.php' AND sayfalar_durum = 1");

$sozlesmeBaslik = $sozlesmeBaslik['sayfalar_sayfa_adi'] ?? 'Sözleşme Yönetimi';
$hukukBaslik = $hukukBaslik['sayfalar_sayfa_adi'] ?? 'Hukuk Takip Yönetimi';
$sucBaslik = $sucBaslik['sayfalar_sayfa_adi'] ?? 'Suç Duyurusu Takip';

// AJAX İşlemleri
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json');
    $action = $_POST['action'] ?? '';
    
    try {
        switch ($action) {
            case 'dashboard_stats':
                // Sözleşme İstatistikleri
                $sozlesmeToplam = $db->fetchOne("SELECT COUNT(*) as sayi FROM Sozlesmeler s INNER JOIN Cari c ON s.sozlesme_cari_id = c.cari_id WHERE c.cari_tipi_id = 1")['sayi'] ?? 0;
                $sozlesmeAktif = $db->fetchOne("SELECT COUNT(*) as sayi FROM Sozlesmeler s INNER JOIN Cari c ON s.sozlesme_cari_id = c.cari_id WHERE c.cari_tipi_id = 1 AND s.sozlesme_durum = 1")['sayi'] ?? 0;
                $sozlesmePasif = $db->fetchOne("SELECT COUNT(*) as sayi FROM Sozlesmeler s INNER JOIN Cari c ON s.sozlesme_cari_id = c.cari_id WHERE c.cari_tipi_id = 1 AND s.sozlesme_durum = 0")['sayi'] ?? 0;
                $sozlesmeBuAy = $db->fetchOne("SELECT COUNT(*) as sayi FROM Sozlesmeler s INNER JOIN Cari c ON s.sozlesme_cari_id = c.cari_id WHERE c.cari_tipi_id = 1 AND MONTH(s.sozlesme_tarih) = MONTH(GETDATE()) AND YEAR(s.sozlesme_tarih) = YEAR(GETDATE())")['sayi'] ?? 0;
                $sozlesmeTutar = $db->fetchOne("SELECT ISNULL(SUM(h.hareket_fiyat), 0) as toplam FROM Sozlesme_StokHareketleri h INNER JOIN Sozlesmeler s ON h.hareket_sozlesme_id = s.sozlesme_id INNER JOIN Cari c ON s.sozlesme_cari_id = c.cari_id WHERE c.cari_tipi_id = 1 AND s.sozlesme_durum = 1")['toplam'] ?? 0;
                $sozlesmeTahsilat = $db->fetchOne("SELECT ISNULL(SUM(o.odeme_tutar), 0) as toplam FROM Sozlesme_Odemeler o INNER JOIN Sozlesmeler s ON o.odeme_sozlesme_id = s.sozlesme_id INNER JOIN Cari c ON s.sozlesme_cari_id = c.cari_id WHERE c.cari_tipi_id = 1 AND o.odeme_yapildi = 1")['toplam'] ?? 0;

                // Beklenen Tahsilatlar: vadesi gelen ve henüz ödenmemiş (odeme_yapildi = 0)
                // Bugün gelecek tahsilatlar
                $sozlesmeBugunTahsilat = $db->fetchOne("SELECT ISNULL(SUM(o.odeme_tutar), 0) as toplam FROM Sozlesme_Odemeler o INNER JOIN Sozlesmeler s ON o.odeme_sozlesme_id = s.sozlesme_id INNER JOIN Cari c ON s.sozlesme_cari_id = c.cari_id WHERE c.cari_tipi_id = 1 AND o.odeme_yapildi = 0 AND CAST(o.odeme_vade_tarih AS DATE) = CAST(GETDATE() AS DATE)")['toplam'] ?? 0;
                // Bu hafta (Pazartesi-Pazar) gelecek tahsilatlar - @@DATEFIRST bağımsız
                $sozlesmeBuHaftaTahsilat = $db->fetchOne("SELECT ISNULL(SUM(o.odeme_tutar), 0) as toplam FROM Sozlesme_Odemeler o INNER JOIN Sozlesmeler s ON o.odeme_sozlesme_id = s.sozlesme_id INNER JOIN Cari c ON s.sozlesme_cari_id = c.cari_id WHERE c.cari_tipi_id = 1 AND o.odeme_yapildi = 0 AND CAST(o.odeme_vade_tarih AS DATE) BETWEEN DATEADD(DAY, -((DATEPART(WEEKDAY, GETDATE()) + @@DATEFIRST - 2) % 7), CAST(GETDATE() AS DATE)) AND DATEADD(DAY, 6 - ((DATEPART(WEEKDAY, GETDATE()) + @@DATEFIRST - 2) % 7), CAST(GETDATE() AS DATE))")['toplam'] ?? 0;
                // Bu ay gelecek tahsilatlar
                $sozlesmeBuAyTahsilat = $db->fetchOne("SELECT ISNULL(SUM(o.odeme_tutar), 0) as toplam FROM Sozlesme_Odemeler o INNER JOIN Sozlesmeler s ON o.odeme_sozlesme_id = s.sozlesme_id INNER JOIN Cari c ON s.sozlesme_cari_id = c.cari_id WHERE c.cari_tipi_id = 1 AND o.odeme_yapildi = 0 AND YEAR(o.odeme_vade_tarih) = YEAR(GETDATE()) AND MONTH(o.odeme_vade_tarih) = MONTH(GETDATE())")['toplam'] ?? 0;

                // Hukuk İstatistikleri
                $hukukToplam = $db->fetchOne("SELECT COUNT(*) as sayi FROM HukukTakip t INNER JOIN Cari c ON t.takip_cari_id = c.cari_id WHERE c.cari_tipi_id = 3")['sayi'] ?? 0;
                $hukukAktif = $db->fetchOne("SELECT COUNT(*) as sayi FROM HukukTakip t INNER JOIN Cari c ON t.takip_cari_id = c.cari_id WHERE c.cari_tipi_id = 3 AND t.Durum = 1")['sayi'] ?? 0;
                $hukukIcrayaGidildi = $db->fetchOne("SELECT COUNT(*) as sayi FROM HukukTakip t INNER JOIN Cari c ON t.takip_cari_id = c.cari_id WHERE c.cari_tipi_id = 3 AND t.Durum = 1 AND t.takip_icraya_gidildi = 1")['sayi'] ?? 0;
                $hukukIcrayaGidilmedi = $db->fetchOne("SELECT COUNT(*) as sayi FROM HukukTakip t INNER JOIN Cari c ON t.takip_cari_id = c.cari_id WHERE c.cari_tipi_id = 3 AND t.Durum = 1 AND t.takip_icraya_gidildi = 0 AND t.takip_odeme_taahhutu_alindi = 0")['sayi'] ?? 0;
                $hukukIcrayaGidildiAlacak = $db->fetchOne("SELECT ISNULL(SUM(ISNULL(t.takip_ana_tutar,0) + ISNULL(t.takip_Isleyen_Faiz,0) + ISNULL(t.takip_Islemis_Faiz,0) + ISNULL(t.takip_Vekalet_Ucreti,0) + ISNULL(t.takip_Masraf,0) + ISNULL(t.takip_Tahsil_Harci,0)), 0) as toplam FROM HukukTakip t INNER JOIN Cari c ON t.takip_cari_id = c.cari_id WHERE c.cari_tipi_id = 3 AND t.Durum = 1 AND t.takip_icraya_gidildi = 1")['toplam'] ?? 0;
                $hukukIcrayaGidilmediAlacak = $db->fetchOne("SELECT ISNULL(SUM(ISNULL(t.takip_ana_tutar,0) + ISNULL(t.takip_Isleyen_Faiz,0) + ISNULL(t.takip_Islemis_Faiz,0) + ISNULL(t.takip_Vekalet_Ucreti,0) + ISNULL(t.takip_Masraf,0) + ISNULL(t.takip_Tahsil_Harci,0)), 0) as toplam FROM HukukTakip t INNER JOIN Cari c ON t.takip_cari_id = c.cari_id WHERE c.cari_tipi_id = 3 AND t.Durum = 1 AND t.takip_icraya_gidildi = 0 AND t.takip_odeme_taahhutu_alindi = 0")['toplam'] ?? 0;
                $hukukAnaTutar = $db->fetchOne("SELECT ISNULL(SUM(t.takip_ana_tutar), 0) as toplam FROM HukukTakip t INNER JOIN Cari c ON t.takip_cari_id = c.cari_id WHERE c.cari_tipi_id = 3 AND t.Durum = 1")['toplam'] ?? 0;
                $hukukToplamAlacak = $db->fetchOne("SELECT ISNULL(SUM(ISNULL(t.takip_ana_tutar,0) + ISNULL(t.takip_Isleyen_Faiz,0) + ISNULL(t.takip_Islemis_Faiz,0) + ISNULL(t.takip_Vekalet_Ucreti,0) + ISNULL(t.takip_Masraf,0) + ISNULL(t.takip_Tahsil_Harci,0)), 0) as toplam FROM HukukTakip t INNER JOIN Cari c ON t.takip_cari_id = c.cari_id WHERE c.cari_tipi_id = 3 AND t.Durum = 1")['toplam'] ?? 0;
                $hukukIsleyenFaiz = $db->fetchOne("SELECT ISNULL(SUM(t.takip_Isleyen_Faiz), 0) as toplam FROM HukukTakip t INNER JOIN Cari c ON t.takip_cari_id = c.cari_id WHERE c.cari_tipi_id = 3 AND t.Durum = 1")['toplam'] ?? 0;
                $hukukVekaletUcreti = $db->fetchOne("SELECT ISNULL(SUM(t.takip_Vekalet_Ucreti), 0) as toplam FROM HukukTakip t INNER JOIN Cari c ON t.takip_cari_id = c.cari_id WHERE c.cari_tipi_id = 3 AND t.Durum = 1")['toplam'] ?? 0;
                $hukukMasraf = $db->fetchOne("SELECT ISNULL(SUM(t.takip_Masraf), 0) as toplam FROM HukukTakip t INNER JOIN Cari c ON t.takip_cari_id = c.cari_id WHERE c.cari_tipi_id = 3 AND t.Durum = 1")['toplam'] ?? 0;

                // Hukuk - Tahsilat Yapıldı (statu_id = 4) Toplam Alacak
                $hukukTahsilatYapilanAlacak = $db->fetchOne("SELECT ISNULL(SUM(ISNULL(t.takip_ana_tutar,0) + ISNULL(t.takip_Isleyen_Faiz,0) + ISNULL(t.takip_Islemis_Faiz,0) + ISNULL(t.takip_Vekalet_Ucreti,0) + ISNULL(t.takip_Masraf,0) + ISNULL(t.takip_Tahsil_Harci,0)), 0) as toplam FROM HukukTakip t INNER JOIN Cari c ON t.takip_cari_id = c.cari_id WHERE c.cari_tipi_id = 3 AND t.Durum = 1 AND t.takip_statu_id = 4")['toplam'] ?? 0;
                $hukukOdemeTaahhutAlindi = $db->fetchOne("SELECT COUNT(*) as sayi FROM HukukTakip t INNER JOIN Cari c ON t.takip_cari_id = c.cari_id WHERE c.cari_tipi_id = 3 AND t.Durum = 1 AND t.takip_odeme_taahhutu_alindi = 1")['sayi'] ?? 0;
                $hukukOdemeTaahhutAlindiAlacak = $db->fetchOne("SELECT ISNULL(SUM(ISNULL(t.takip_ana_tutar,0) + ISNULL(t.takip_Isleyen_Faiz,0) + ISNULL(t.takip_Islemis_Faiz,0) + ISNULL(t.takip_Vekalet_Ucreti,0) + ISNULL(t.takip_Masraf,0) + ISNULL(t.takip_Tahsil_Harci,0)), 0) as toplam FROM HukukTakip t INNER JOIN Cari c ON t.takip_cari_id = c.cari_id WHERE c.cari_tipi_id = 3 AND t.Durum = 1 AND t.takip_odeme_taahhutu_alindi = 1")['toplam'] ?? 0;

                // Suç Duyurusu İstatistikleri
                $sucToplam = $db->fetchOne("SELECT COUNT(*) as sayi FROM HukukTakip t INNER JOIN Cari c ON t.takip_cari_id = c.cari_id WHERE c.cari_tipi_id = 4")['sayi'] ?? 0;
                $sucToplamAdet = $db->fetchOne("SELECT ISNULL(SUM(ISNULL(t.takip_tespit_adet, 0)), 0) as toplam FROM HukukTakip t INNER JOIN Cari c ON t.takip_cari_id = c.cari_id WHERE c.cari_tipi_id = 4")['toplam'] ?? 0;

                // Kullanıcı İstatistikleri
                $aktifKullanici = $db->fetchOne("SELECT COUNT(*) as total FROM kullanicilar WHERE kullanici_durum = 1")['total'] ?? 0;

                echo json_encode(['success' => true, 'data' => [
                    'sozlesme' => [
                        'toplam' => intval($sozlesmeToplam),
                        'aktif' => intval($sozlesmeAktif),
                        'pasif' => intval($sozlesmePasif),
                        'bu_ay' => intval($sozlesmeBuAy),
                        'tutar' => floatval($sozlesmeTutar),
                        'tahsilat' => floatval($sozlesmeTahsilat),
                        'kalan' => floatval($sozlesmeTutar) - floatval($sozlesmeTahsilat),
                        'bugun_tahsilat' => floatval($sozlesmeBugunTahsilat),
                        'bu_hafta_tahsilat' => floatval($sozlesmeBuHaftaTahsilat),
                        'bu_ay_tahsilat' => floatval($sozlesmeBuAyTahsilat)
                    ],
                    'hukuk' => [
                        'toplam' => intval($hukukToplam),
                        'aktif' => intval($hukukAktif),
                        'icraya_gidildi' => intval($hukukIcrayaGidildi),
                        'icraya_gidilmedi' => intval($hukukIcrayaGidilmedi),
                        'icraya_gidildi_alacak' => floatval($hukukIcrayaGidildiAlacak),
                        'icraya_gidilmedi_alacak' => floatval($hukukIcrayaGidilmediAlacak),
                        'odeme_taahhutu_alindi' => intval($hukukOdemeTaahhutAlindi),
                        'odeme_taahhutu_alindi_alacak' => floatval($hukukOdemeTaahhutAlindiAlacak),
                        'ana_tutar' => floatval($hukukAnaTutar),
                        'toplam_alacak' => floatval($hukukToplamAlacak),
                        'tahsilat_yapilan_alacak' => floatval($hukukTahsilatYapilanAlacak),
                        'isleyen_faiz' => floatval($hukukIsleyenFaiz),
                        'vekalet_ucreti' => floatval($hukukVekaletUcreti),
                        'masraf' => floatval($hukukMasraf)
                    ],
                    'suc' => [
                        'toplam' => intval($sucToplam),
                        'toplam_adet' => intval($sucToplamAdet)
                    ],
                    'kullanici' => [
                        'aktif' => intval($aktifKullanici)
                    ]
                ]]);
                break;

            case 'banka_ozet':
                // Eşleşmeyi bekleyen hareketlerin özeti. Öneri sayısı satırda hazır
                // tutulan hareket_oneri_cari_id kolonundan okunur, Cari taraması yapılmaz.
                $ozet = $db->fetchOne("
                    SELECT
                        SUM(CASE WHEN hareket_islendi = 0 THEN 1 ELSE 0 END) AS bekleyen,
                        SUM(CASE WHEN hareket_islendi = 0 AND hareket_oneri_cari_id > 0 THEN 1 ELSE 0 END) AS onerili,
                        SUM(CASE WHEN hareket_islendi = 1 THEN 1 ELSE 0 END) AS eslesen
                    FROM BankaHesapHareketleri
                    WHERE hareket_durum = 1
                ");

                // Son senkron bilgisi entegrasyon kanalının ayarlarında tutulur
                $kanal = $db->fetchOne("
                    SELECT kanal_ayarlar FROM EntegrasyonKanallari
                    WHERE kanal_kod = 'banka_hareket' AND kanal_durum = 1
                ");
                $ayar = json_decode((string) ($kanal['kanal_ayarlar'] ?? '{}'), true) ?: [];

                $sonHareketler = $db->fetchAll("
                    SELECT TOP 5
                        h.hareket_id,
                        h.hareket_gonderen_ad,
                        h.hareket_tutar,
                        h.hareket_para_birimi,
                        h.hareket_banka,
                        h.hareket_islendi,
                        CONVERT(VARCHAR(16), h.hareket_tarih, 120) AS hareket_tarih,
                        ISNULL(oc.cari_adi, oc.cari_unvan) AS oneri_cari_ad
                    FROM BankaHesapHareketleri h
                    LEFT JOIN Cari oc ON oc.cari_id = h.hareket_oneri_cari_id
                    WHERE h.hareket_durum = 1
                    ORDER BY h.hareket_id DESC
                ");

                echo json_encode([
                    'success' => true,
                    'ozet'    => $ozet,
                    'son_senkron' => $ayar['son_senkron'] ?? null,
                    'hareketler'  => $sonHareketler,
                ]);
                break;

            case 'son_sozlesmeler':
                $data = $db->fetchAll("
                    SELECT TOP 5
                        s.sozlesme_id,
                        c.cari_adi,
                        sz.sezon_ad,
                        p.kullanici_ad + ' ' + p.kullanici_soyad as personel_adi,
                        (SELECT ISNULL(SUM(hareket_fiyat), 0) FROM Sozlesme_StokHareketleri WHERE hareket_sozlesme_id = s.sozlesme_id) as toplam_tutar,
                        CONVERT(VARCHAR(10), s.sozlesme_tarih, 104) as sozlesme_tarih,
                        s.sozlesme_durum
                    FROM Sozlesmeler s
                    LEFT JOIN Cari c ON s.sozlesme_cari_id = c.cari_id
                    LEFT JOIN Sozlesme_Sezonlar sz ON s.sozlesme_sezon_id = sz.sezon_id
                    LEFT JOIN kullanicilar p ON s.sozlesme_personel_id = p.kullanici_id
                    WHERE c.cari_tipi_id = 1
                    ORDER BY s.sozlesme_id DESC
                ");
                echo json_encode(['success' => true, 'data' => $data]);
                break;

            case 'son_hukuk':
                $data = $db->fetchAll("
                    SELECT TOP 5
                        t.takip_id,
                        t.takip_dosya_no,
                        c.cari_adi as musteri_adi,
                        t.takip_ana_tutar,
                        (ISNULL(t.takip_ana_tutar,0) + ISNULL(t.takip_Isleyen_Faiz,0) + ISNULL(t.takip_Islemis_Faiz,0) + ISNULL(t.takip_Vekalet_Ucreti,0) + ISNULL(t.takip_Masraf,0) + ISNULL(t.takip_Tahsil_Harci,0)) as toplam_alacak,
                        st.statu_ad,
                        CONVERT(VARCHAR(10), t.takip_dosya_acilis_tarihi, 104) as dosya_acilis_tarihi,
                        t.Durum
                    FROM HukukTakip t
                    INNER JOIN Cari c ON t.takip_cari_id = c.cari_id
                    LEFT JOIN HukukStatu st ON t.takip_statu_id = st.statu_id
                    WHERE c.cari_tipi_id = 3
                    ORDER BY t.OlusturmaTarihi DESC
                ");
                echo json_encode(['success' => true, 'data' => $data]);
                break;

            case 'son_suc_duyurusu':
                $data = $db->fetchAll("
                    SELECT TOP 5
                        t.takip_id,
                        c.cari_adi as isletmeci,
                        c.cari_unvan as isyeri_adi,
                        t.takip_tespit_adet,
                        t.takip_tespit_turu,
                        s.SehirAdi as sehir_adi,
                        CONVERT(VARCHAR(10), t.takip_tespit_tarihi, 104) as tespit_tarihi
                    FROM HukukTakip t
                    INNER JOIN Cari c ON t.takip_cari_id = c.cari_id
                    LEFT JOIN Adres_Sehirler s ON c.cari_sehirler = s.SehirId
                    WHERE c.cari_tipi_id = 4
                    ORDER BY t.OlusturmaTarihi DESC
                ");
                echo json_encode(['success' => true, 'data' => $data]);
                break;

            case 'hukuk_statu_dagilimi':
                $data = $db->fetchAll("
                    SELECT st.statu_ad, COUNT(*) as sayi
                    FROM HukukTakip t
                    INNER JOIN Cari c ON t.takip_cari_id = c.cari_id
                    LEFT JOIN HukukStatu st ON t.takip_statu_id = st.statu_id
                    WHERE c.cari_tipi_id = 3 AND t.Durum = 1
                    GROUP BY st.statu_ad
                    ORDER BY sayi DESC
                ");
                echo json_encode(['success' => true, 'data' => $data]);
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
    <link rel="stylesheet" href="/admin/assets/css/custom.css">
    <style>
        .dashboard-card { transition: transform 0.2s, box-shadow 0.2s; border: none; }
        .dashboard-card:hover { transform: translateY(-3px); box-shadow: 0 8px 25px rgba(0,0,0,0.1); }
        .small-box .inner h3 { font-size: 2rem; font-weight: 700; }
        .small-box .inner p { font-size: 0.9rem; }
        .module-header { font-size: 1rem; font-weight: 600; letter-spacing: 0.5px; text-transform: uppercase; }
        .module-header i { font-size: 1.1rem; }
        .stat-highlight { font-size: 1.5rem; font-weight: 700; }
        .stat-label { font-size: 0.8rem; color: #6c757d; }
        .table-dashboard th { font-size: 0.8rem; text-transform: uppercase; letter-spacing: 0.5px; color: #6c757d; border-top: none; }
        .table-dashboard td { font-size: 0.85rem; vertical-align: middle; }
        .progress-thin { height: 6px; border-radius: 3px; }
        .welcome-gradient { background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); }
        .section-divider { border: none; border-top: 2px solid #e9ecef; margin: 1.5rem 0; }
    </style>
</head>
<body class="layout-fixed sidebar-expand-lg sidebar-open bg-body-tertiary">
    <div class="app-wrapper">
        <?php include __DIR__ . '/../includes/header.php'; ?>
        <?php include __DIR__ . '/../includes/sidebar.php'; ?>
        
        <main class="app-main">
            <div class="app-content">
                <div class="container-fluid">

                    <!-- ═══════════════════════════════════════════ -->
                    <!-- SÖZLEŞME YÖNETİMİ -->
                    <!-- ═══════════════════════════════════════════ -->
                    <div class="d-flex align-items-center mb-3">
                        <span class="module-header text-primary"><i class="bi bi-file-earmark-text me-2"></i><?= htmlspecialchars($sozlesmeBaslik) ?></span>
                        <hr class="flex-grow-1 ms-3 my-0" style="border-color: #0d6efd;">
                        <a href="/admin/sozlesme-yonetimi" class="btn btn-sm btn-outline-primary ms-3">
                            <i class="bi bi-arrow-right"></i> Detaylar
                        </a>
                    </div>

                    <div class="row mb-3">
                        <div class="col-6 col-lg-3">
                            <div class="small-box text-bg-primary dashboard-card">
                                <div class="inner">
                                    <h3 id="sz_toplam">-</h3>
                                    <p>Toplam Sözleşme</p>
                                </div>
                                <div class="small-box-icon"><i class="bi bi-file-earmark-text"></i></div>
                            </div>
                        </div>
                        <div class="col-6 col-lg-3">
                            <div class="small-box text-bg-success dashboard-card">
                                <div class="inner">
                                    <h3 id="sz_aktif">-</h3>
                                    <p>Aktif Sözleşme</p>
                                </div>
                                <div class="small-box-icon"><i class="bi bi-check-circle"></i></div>
                            </div>
                        </div>
                        <div class="col-6 col-lg-3">
                            <div class="small-box text-bg-warning dashboard-card">
                                <div class="inner">
                                    <h3 id="sz_pasif">-</h3>
                                    <p>Pasif Sözleşme</p>
                                </div>
                                <div class="small-box-icon"><i class="bi bi-pause-circle"></i></div>
                            </div>
                        </div>
                        <div class="col-6 col-lg-3">
                            <div class="small-box text-bg-info dashboard-card">
                                <div class="inner">
                                    <h3 id="sz_bu_ay">-</h3>
                                    <p>Bu Ay Eklenen</p>
                                </div>
                                <div class="small-box-icon"><i class="bi bi-calendar-month"></i></div>
                            </div>
                        </div>
                    </div>

                    <div class="row mb-3">
                        <div class="col-12 col-lg-4">
                            <div class="small-box text-bg-secondary dashboard-card">
                                <div class="inner">
                                    <h3 id="sz_bugun_tahsilat">-</h3>
                                    <p>Bugün Gelecek Tahsilatlar</p>
                                </div>
                                <div class="small-box-icon"><i class="bi bi-calendar-check"></i></div>
                            </div>
                        </div>
                        <div class="col-12 col-lg-4">
                            <div class="small-box text-bg-dark dashboard-card">
                                <div class="inner">
                                    <h3 id="sz_bu_hafta_tahsilat">-</h3>
                                    <p>Bu Hafta Gelecek Tahsilatlar</p>
                                </div>
                                <div class="small-box-icon"><i class="bi bi-calendar-week"></i></div>
                            </div>
                        </div>
                        <div class="col-12 col-lg-4">
                            <div class="small-box text-bg-primary dashboard-card">
                                <div class="inner">
                                    <h3 id="sz_bu_ay_tahsilat">-</h3>
                                    <p>Bu Ay Gelecek Tahsilatlar</p>
                                </div>
                                <div class="small-box-icon"><i class="bi bi-calendar-range"></i></div>
                            </div>
                        </div>
                    </div>

                    <!-- Banka Hareketleri: eşleşmeyi bekleyen tahsilatlar -->
                    <div class="row mb-4">
                        <div class="col-12">
                            <div class="card dashboard-card">
                                <div class="card-header d-flex justify-content-between align-items-center">
                                    <h3 class="card-title mb-0">
                                        <i class="bi bi-bank me-2"></i>Banka Hareketleri
                                    </h3>
                                    <div class="d-flex align-items-center gap-2">
                                        <small class="text-muted" id="bnk_senkron"></small>
                                        <a href="/admin/banka-hesap-hareketleri" class="btn btn-sm btn-outline-primary">
                                            Tümü <i class="bi bi-arrow-right"></i>
                                        </a>
                                    </div>
                                </div>
                                <div class="card-body">
                                    <div class="row g-3 mb-3">
                                        <div class="col-12 col-lg-4">
                                            <div class="border rounded p-2 text-center h-100">
                                                <div class="fs-4 fw-semibold text-warning" id="bnk_bekleyen">-</div>
                                                <small class="text-muted">Eşleşme Bekleyen</small>
                                            </div>
                                        </div>
                                        <div class="col-6 col-lg-4">
                                            <div class="border rounded p-2 text-center h-100">
                                                <div class="fs-4 fw-semibold text-info" id="bnk_onerili">-</div>
                                                <small class="text-muted">Cari Önerisi Olan</small>
                                            </div>
                                        </div>
                                        <div class="col-6 col-lg-4">
                                            <div class="border rounded p-2 text-center h-100">
                                                <div class="fs-4 fw-semibold text-success" id="bnk_eslesen">-</div>
                                                <small class="text-muted">Eşleştirilmiş</small>
                                            </div>
                                        </div>
                                    </div>

                                    <div class="table-responsive">
                                        <table class="table table-sm table-hover align-middle mb-0">
                                            <thead>
                                                <tr>
                                                    <th>Tarih</th>
                                                    <th>Gönderen</th>
                                                    <th>Banka</th>
                                                    <th class="text-end">Tutar</th>
                                                    <th>Durum</th>
                                                </tr>
                                            </thead>
                                            <tbody id="tbl_banka_hareketleri">
                                                <tr><td colspan="5" class="text-center text-muted py-3">Yükleniyor...</td></tr>
                                            </tbody>
                                        </table>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="row mb-4">
                        <!-- Sözleşme Finansal Özet -->
                        <div class="col-lg-5">
                            <div class="card dashboard-card h-100">
                                <div class="card-header">
                                    <h3 class="card-title"><i class="bi bi-cash-coin me-2"></i>Finansal Özet</h3>
                                </div>
                                <div class="card-body">
                                    <div class="row text-center">
                                        <div class="col-4">
                                            <div class="stat-highlight text-primary" id="sz_tutar">-</div>
                                            <div class="stat-label">Toplam Tutar</div>
                                        </div>
                                        <div class="col-4">
                                            <div class="stat-highlight text-success" id="sz_tahsilat">-</div>
                                            <div class="stat-label">Tahsilat</div>
                                        </div>
                                        <div class="col-4">
                                            <div class="stat-highlight text-danger" id="sz_kalan">-</div>
                                            <div class="stat-label">Kalan Borç</div>
                                        </div>
                                    </div>
                                    <div class="mt-3">
                                        <div class="d-flex justify-content-between mb-1">
                                            <small class="text-muted">Tahsilat Oranı</small>
                                            <small class="fw-bold" id="sz_oran_text">%0</small>
                                        </div>
                                        <div class="progress progress-thin">
                                            <div class="progress-bar bg-success" id="sz_oran_bar" role="progressbar" style="width: 0%"></div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                        <!-- Son Sözleşmeler -->
                        <div class="col-lg-7">
                            <div class="card dashboard-card h-100">
                                <div class="card-header">
                                    <h3 class="card-title"><i class="bi bi-clock-history me-2"></i>Son Eklenen Sözleşmeler</h3>
                                </div>
                                <div class="card-body p-0">
                                    <div class="table-responsive">
                                        <table class="table table-hover table-dashboard mb-0">
                                            <thead>
                                                <tr>
                                                    <th>Müşteri</th>
                                                    <th>Sezon</th>
                                                    <th>Personel</th>
                                                    <th class="text-end">Tutar</th>
                                                    <th>Durum</th>
                                                </tr>
                                            </thead>
                                            <tbody id="tbl_son_sozlesmeler">
                                                <tr><td colspan="5" class="text-center text-muted py-3">Yükleniyor...</td></tr>
                                            </tbody>
                                        </table>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- ═══════════════════════════════════════════ -->
                    <!-- HUKUK TAKİP YÖNETİMİ -->
                    <!-- ═══════════════════════════════════════════ -->
                    <div class="d-flex align-items-center mb-3">
                        <span class="module-header text-danger"><i class="bi bi-briefcase me-2"></i><?= htmlspecialchars($hukukBaslik) ?></span>
                        <hr class="flex-grow-1 ms-3 my-0" style="border-color: #dc3545;">
                        <a href="/admin/hukuk-yonetimi" class="btn btn-sm btn-outline-danger ms-3">
                            <i class="bi bi-arrow-right"></i> Detaylar
                        </a>
                    </div>

                    <div class="row mb-2">
                        <div class="col-6 col-lg-3">
                            <div class="small-box text-bg-danger dashboard-card">
                                <div class="inner">
                                    <h3 id="hk_toplam">-</h3>
                                    <p>Toplam Dosya</p>
                                </div>
                                <div class="small-box-icon"><i class="bi bi-folder2-open"></i></div>
                            </div>
                        </div>
                        <div class="col-6 col-lg-3">
                            <a href="/admin/hukuk-yonetimi?icraya_gidildi=1" class="text-decoration-none">
                                <div class="small-box text-bg-primary dashboard-card">
                                    <div class="inner">
                                        <h3 id="hk_icraya_gidildi">-</h3>
                                        <p class="mb-0">Hacze Gidildi</p>
                                        <small id="hk_icraya_gidildi_alacak" style="font-size:0.7rem;opacity:0.85">-</small>
                                    </div>
                                    <div class="small-box-icon"><i class="bi bi-check2-all"></i></div>
                                </div>
                            </a>
                        </div>
                        <div class="col-6 col-lg-3">
                            <a href="/admin/hukuk-yonetimi?icraya_gidildi=0" class="text-decoration-none">
                                <div class="small-box text-bg-dark dashboard-card">
                                    <div class="inner">
                                        <h3 id="hk_icraya_gidilmedi">-</h3>
                                        <p class="mb-0">Hacze Gidilmedi</p>
                                        <small id="hk_icraya_gidilmedi_alacak" style="font-size:0.7rem;opacity:0.85">-</small>
                                    </div>
                                    <div class="small-box-icon"><i class="bi bi-hourglass-split"></i></div>
                                </div>
                            </a>
                        </div>
                        <div class="col-6 col-lg-3">
                            <a href="/admin/hukuk-yonetimi?odeme_taahhutu=1" class="text-decoration-none">
                                <div class="small-box text-bg-warning dashboard-card">
                                    <div class="inner">
                                        <h3 id="hk_odeme_taahhutu_alindi">-</h3>
                                        <p class="mb-0">Ödeme Taahhütü Alındı</p>
                                        <small id="hk_odeme_taahhutu_alindi_alacak" style="font-size:0.7rem;opacity:0.85">-</small>
                                    </div>
                                    <div class="small-box-icon"><i class="bi bi-file-earmark-check"></i></div>
                                </div>
                            </a>
                        </div>
                    </div>
                    <div class="row mb-3">
                        <div class="col-6 col-lg-4">
                            <div class="small-box text-bg-secondary dashboard-card">
                                <div class="inner">
                                    <h3 id="hk_ana_tutar">-</h3>
                                    <p>Toplam Ana Tutar</p>
                                </div>
                                <div class="small-box-icon"><i class="bi bi-cash-coin"></i></div>
                            </div>
                        </div>
                        <div class="col-6 col-lg-4">
                            <div class="small-box text-bg-danger dashboard-card">
                                <div class="inner">
                                    <h3 id="hk_toplam_alacak">-</h3>
                                    <p>Toplam Alacak</p>
                                </div>
                                <div class="small-box-icon"><i class="bi bi-cash-stack"></i></div>
                            </div>
                        </div>
                        <div class="col-6 col-lg-4">
                            <div class="small-box text-bg-success dashboard-card">
                                <div class="inner">
                                    <h3 id="hk_tahsilat_yapilan">-</h3>
                                    <p>Tahsilat Yapıldı</p>
                                </div>
                                <div class="small-box-icon"><i class="bi bi-check2-circle"></i></div>
                            </div>
                        </div>
                    </div>

                    <div class="row mb-4">
                        <!-- Hukuk Alacak Dağılımı -->
                        <div class="col-lg-5">
                            <div class="card dashboard-card h-100">
                                <div class="card-header">
                                    <h3 class="card-title"><i class="bi bi-pie-chart me-2"></i>Alacak Dağılımı</h3>
                                </div>
                                <div class="card-body">
                                    <div id="hukukAlacakChart"></div>
                                </div>
                            </div>
                        </div>
                        <!-- Son Hukuk Dosyaları -->
                        <div class="col-lg-7">
                            <div class="card dashboard-card h-100">
                                <div class="card-header">
                                    <h3 class="card-title"><i class="bi bi-clock-history me-2"></i>Son Hukuk Dosyaları</h3>
                                </div>
                                <div class="card-body p-0">
                                    <div class="table-responsive">
                                        <table class="table table-hover table-dashboard mb-0">
                                            <thead>
                                                <tr>
                                                    <th>Dosya No</th>
                                                    <th>Müşteri</th>
                                                    <th>Statü</th>
                                                    <th class="text-end">Ana Tutar</th>
                                                    <th class="text-end">Toplam Alacak</th>
                                                </tr>
                                            </thead>
                                            <tbody id="tbl_son_hukuk">
                                                <tr><td colspan="5" class="text-center text-muted py-3">Yükleniyor...</td></tr>
                                            </tbody>
                                        </table>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- ═══════════════════════════════════════════ -->
                    <!-- SUÇ DUYURUSU TAKİP -->
                    <!-- ═══════════════════════════════════════════ -->
                    <div class="d-flex align-items-center mb-3">
                        <span class="module-header text-warning"><i class="bi bi-exclamation-triangle me-2"></i><?= htmlspecialchars($sucBaslik) ?></span>
                        <hr class="flex-grow-1 ms-3 my-0" style="border-color: #ffc107;">
                        <a href="/admin/suc-duyurusu-takip" class="btn btn-sm btn-outline-warning ms-3">
                            <i class="bi bi-arrow-right"></i> Detaylar
                        </a>
                    </div>

                    <div class="row mb-3">
                        <div class="col-6 col-lg-4">
                            <div class="small-box text-bg-warning dashboard-card">
                                <div class="inner">
                                    <h3 id="sd_toplam">-</h3>
                                    <p>Toplam Kayıt</p>
                                </div>
                                <div class="small-box-icon"><i class="bi bi-exclamation-triangle"></i></div>
                            </div>
                        </div>
                        <div class="col-6 col-lg-4">
                            <div class="small-box text-bg-info dashboard-card">
                                <div class="inner">
                                    <h3 id="sd_adet">-</h3>
                                    <p>Toplam Tespit Adet</p>
                                </div>
                                <div class="small-box-icon"><i class="bi bi-hash"></i></div>
                            </div>
                        </div>
                    </div>

                    <div class="row mb-4">
                        <!-- Suç Duyurusu Durum Grafiği -->
                        <div class="col-lg-5">
                            <div class="card dashboard-card h-100">
                                <div class="card-header">
                                    <h3 class="card-title"><i class="bi bi-bar-chart me-2"></i>Tespit Durum Dağılımı</h3>
                                </div>
                                <div class="card-body">
                                    <div id="sucDurumChart"></div>
                                </div>
                            </div>
                        </div>
                        <!-- Son Suç Duyuruları -->
                        <div class="col-lg-7">
                            <div class="card dashboard-card h-100">
                                <div class="card-header">
                                    <h3 class="card-title"><i class="bi bi-clock-history me-2"></i>Son Suç Duyuruları</h3>
                                </div>
                                <div class="card-body p-0">
                                    <div class="table-responsive">
                                        <table class="table table-hover table-dashboard mb-0">
                                            <thead>
                                                <tr>
                                                    <th>İşletmeci</th>
                                                    <th>Şehir</th>
                                                    <th>Tür</th>
                                                    <th class="text-center">Adet</th>
                                                </tr>
                                            </thead>
                                            <tbody id="tbl_son_suc">
                                                <tr><td colspan="5" class="text-center text-muted py-3">Yükleniyor...</td></tr>
                                            </tbody>
                                        </table>
                                    </div>
                                </div>
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
    <script src="https://cdn.jsdelivr.net/npm/apexcharts"></script>
    <script src="/admin/assets/js/custom.js"></script>
    <script>
    // Para formatlama (kısa)
    function fmtMoney(val) {
        if (!val && val !== 0) return '-';
        return parseFloat(val).toLocaleString('tr-TR', { minimumFractionDigits: 0, maximumFractionDigits: 0 }) + ' ₺';
    }
    function fmtMoneyShort(val) {
        if (!val && val !== 0) return '-';
        val = parseFloat(val);
        if (val >= 1000000) return (val / 1000000).toFixed(1).replace('.', ',') + 'M ₺';
        if (val >= 1000) return (val / 1000).toFixed(0) + 'K ₺';
        return val.toLocaleString('tr-TR', { minimumFractionDigits: 0, maximumFractionDigits: 0 }) + ' ₺';
    }

    $(document).ready(function() {
        loadDashboardStats();
        loadBankaOzet();
        loadSonSozlesmeler();
        loadSonHukuk();
        loadSonSucDuyurusu();
        loadHukukStatuDagilimi();
    });

    // ======================================
    // Ana İstatistikler
    // ======================================
    function loadDashboardStats() {
        $.post('', { action: 'dashboard_stats' }, function(res) {
            if (!res.success) return;
            const d = res.data;

            // Sözleşme
            $('#sz_toplam').text(d.sozlesme.toplam.toLocaleString('tr-TR'));
            $('#sz_aktif').text(d.sozlesme.aktif.toLocaleString('tr-TR'));
            $('#sz_pasif').text(d.sozlesme.pasif.toLocaleString('tr-TR'));
            $('#sz_bu_ay').text(d.sozlesme.bu_ay.toLocaleString('tr-TR'));
            $('#sz_tutar').text(fmtMoneyShort(d.sozlesme.tutar));
            $('#sz_tahsilat').text(fmtMoneyShort(d.sozlesme.tahsilat));
            $('#sz_kalan').text(fmtMoneyShort(d.sozlesme.kalan));
            $('#sz_bugun_tahsilat').text(fmtMoney(d.sozlesme.bugun_tahsilat));
            $('#sz_bu_hafta_tahsilat').text(fmtMoney(d.sozlesme.bu_hafta_tahsilat));
            $('#sz_bu_ay_tahsilat').text(fmtMoney(d.sozlesme.bu_ay_tahsilat));

            const tahsilatOran = d.sozlesme.tutar > 0 ? Math.round((d.sozlesme.tahsilat / d.sozlesme.tutar) * 100) : 0;
            $('#sz_oran_text').text('%' + tahsilatOran);
            $('#sz_oran_bar').css('width', tahsilatOran + '%');

            // Hukuk
            $('#hk_toplam').text(d.hukuk.toplam.toLocaleString('tr-TR'));
            $('#hk_icraya_gidildi').text(d.hukuk.icraya_gidildi.toLocaleString('tr-TR'));
            $('#hk_icraya_gidilmedi').text(d.hukuk.icraya_gidilmedi.toLocaleString('tr-TR'));
            $('#hk_icraya_gidildi_alacak').text(fmtMoney(d.hukuk.icraya_gidildi_alacak));
            $('#hk_icraya_gidilmedi_alacak').text(fmtMoney(d.hukuk.icraya_gidilmedi_alacak));
            $('#hk_odeme_taahhutu_alindi').text(d.hukuk.odeme_taahhutu_alindi.toLocaleString('tr-TR'));
            $('#hk_odeme_taahhutu_alindi_alacak').text(fmtMoney(d.hukuk.odeme_taahhutu_alindi_alacak));
            $('#hk_ana_tutar').text(fmtMoneyShort(d.hukuk.ana_tutar));
            $('#hk_toplam_alacak').text(fmtMoneyShort(d.hukuk.toplam_alacak));
            $('#hk_tahsilat_yapilan').text(fmtMoneyShort(d.hukuk.tahsilat_yapilan_alacak));

            // Hukuk Alacak Dağılımı Grafiği
            renderHukukAlacakChart(d.hukuk);

            // Suç Duyurusu
            $('#sd_toplam').text(d.suc.toplam.toLocaleString('tr-TR'));
            $('#sd_adet').text(d.suc.toplam_adet.toLocaleString('tr-TR'));

            // Suç Duyurusu Durum Grafiği
            renderSucDurumChart(d.suc);
        }, 'json');
    }

    // ======================================
    // Grafikler
    // ======================================
    function renderHukukAlacakChart(hukuk) {
        const options = {
            series: [hukuk.ana_tutar, hukuk.isleyen_faiz, hukuk.vekalet_ucreti, hukuk.masraf],
            chart: { type: 'donut', height: 260 },
            labels: ['Ana Tutar', 'İşleyen Faiz', 'Vekalet Ücreti', 'Masraf'],
            colors: ['#0d6efd', '#ffc107', '#6c757d', '#dc3545'],
            legend: { position: 'bottom', fontSize: '12px' },
            tooltip: {
                y: { formatter: function(val) { return fmtMoney(val); } }
            },
            plotOptions: {
                pie: {
                    donut: {
                        size: '55%',
                        labels: {
                            show: true,
                            total: {
                                show: true,
                                label: 'Toplam Alacak',
                                formatter: function(w) { return fmtMoneyShort(hukuk.toplam_alacak); }
                            }
                        }
                    }
                }
            },
            dataLabels: { enabled: false }
        };
        new ApexCharts(document.querySelector("#hukukAlacakChart"), options).render();
    }

    function renderSucDurumChart(suc) {
        const options = {
            series: [{
                name: 'Kayıt Sayısı',
                data: [suc.toplam, suc.toplam_adet]
            }],
            chart: { type: 'bar', height: 260 },
            plotOptions: {
                bar: { borderRadius: 6, horizontal: true, barHeight: '50%' }
            },
            colors: ['#ffc107', '#0dcaf0'],
            xaxis: {
                categories: ['Toplam Kayıt', 'Toplam Tespit Adet']
            },
            tooltip: {
                y: { formatter: function(val) { return val + ' kayıt'; } }
            },
            dataLabels: {
                enabled: true,
                style: { fontSize: '13px', fontWeight: 700 }
            }
        };
        new ApexCharts(document.querySelector("#sucDurumChart"), options).render();
    }

    // ======================================
    // Son Kayıtlar Tabloları
    // ======================================
    function loadBankaOzet() {
        $.post('', { action: 'banka_ozet' }, function(res) {
            if (!res.success) {
                $('#tbl_banka_hareketleri').html('<tr><td colspan="5" class="text-center text-muted py-3">Veri alınamadı</td></tr>');
                return;
            }

            const o = res.ozet || {};
            $('#bnk_bekleyen').text(parseInt(o.bekleyen || 0, 10).toLocaleString('tr-TR'));
            $('#bnk_onerili').text(parseInt(o.onerili || 0, 10).toLocaleString('tr-TR'));
            $('#bnk_eslesen').text(parseInt(o.eslesen || 0, 10).toLocaleString('tr-TR'));

            if (res.son_senkron) {
                $('#bnk_senkron').text('Senkron: ' + res.son_senkron.substring(11, 16));
            }

            if (!res.hareketler || !res.hareketler.length) {
                $('#tbl_banka_hareketleri').html('<tr><td colspan="5" class="text-center text-muted py-3">Kayıt bulunamadı</td></tr>');
                return;
            }

            let html = '';
            res.hareketler.forEach(function(h) {
                let durum;
                if (h.hareket_islendi == 1) {
                    durum = '<span class="badge bg-success">Eşleşti</span>';
                } else if (h.oneri_cari_ad) {
                    const kısa = h.oneri_cari_ad.length > 20
                        ? h.oneri_cari_ad.substring(0, 20) + '…' : h.oneri_cari_ad;
                    durum = '<span class="badge bg-info text-dark" title="'
                          + h.oneri_cari_ad.replace(/"/g, '&quot;') + '">Öneri: ' + kısa + '</span>';
                } else {
                    durum = '<span class="badge bg-warning text-dark">Bekliyor</span>';
                }

                const gonderen = (h.hareket_gonderen_ad || '-');
                const kisaAd = gonderen.length > 28 ? gonderen.substring(0, 28) + '…' : gonderen;

                html += '<tr>' +
                    '<td class="text-nowrap"><small>' + (h.hareket_tarih || '-').replace(' ', '<br>') + '</small></td>' +
                    '<td title="' + gonderen.replace(/"/g, '&quot;') + '">' + kisaAd + '</td>' +
                    '<td><span class="badge bg-secondary">' + (h.hareket_banka || '-') + '</span></td>' +
                    '<td class="text-end fw-semibold text-success">' + fmtMoney(h.hareket_tutar) + '</td>' +
                    '<td>' + durum + '</td>' +
                    '</tr>';
            });

            $('#tbl_banka_hareketleri').html(html);
        }, 'json');
    }

    function loadSonSozlesmeler() {
        $.post('', { action: 'son_sozlesmeler' }, function(res) {
            if (!res.success || !res.data.length) {
                $('#tbl_son_sozlesmeler').html('<tr><td colspan="5" class="text-center text-muted py-3">Kayıt bulunamadı</td></tr>');
                return;
            }
            let html = '';
            res.data.forEach(function(r) {
                const durumBadge = r.sozlesme_durum == 1
                    ? '<span class="badge bg-success">Aktif</span>'
                    : '<span class="badge bg-secondary">Pasif</span>';
                html += '<tr>' +
                    '<td><strong>' + (r.cari_adi || '-') + '</strong></td>' +
                    '<td>' + (r.sezon_ad || '-') + '</td>' +
                    '<td>' + (r.personel_adi || '-') + '</td>' +
                    '<td class="text-end">' + fmtMoney(r.toplam_tutar) + '</td>' +
                    '<td>' + durumBadge + '</td>' +
                    '</tr>';
            });
            $('#tbl_son_sozlesmeler').html(html);
        }, 'json');
    }

    function loadSonHukuk() {
        $.post('', { action: 'son_hukuk' }, function(res) {
            if (!res.success || !res.data.length) {
                $('#tbl_son_hukuk').html('<tr><td colspan="5" class="text-center text-muted py-3">Kayıt bulunamadı</td></tr>');
                return;
            }
            let html = '';
            res.data.forEach(function(r) {
                html += '<tr>' +
                    '<td><strong>' + (r.takip_dosya_no || '-') + '</strong></td>' +
                    '<td>' + (r.musteri_adi || '-') + '</td>' +
                    '<td><span class="badge bg-info">' + (r.statu_ad || '-') + '</span></td>' +
                    '<td class="text-end">' + fmtMoney(r.takip_ana_tutar) + '</td>' +
                    '<td class="text-end fw-bold">' + fmtMoney(r.toplam_alacak) + '</td>' +
                    '</tr>';
            });
            $('#tbl_son_hukuk').html(html);
        }, 'json');
    }

    function loadSonSucDuyurusu() {
        $.post('', { action: 'son_suc_duyurusu' }, function(res) {
            if (!res.success || !res.data.length) {
                $('#tbl_son_suc').html('<tr><td colspan="4" class="text-center text-muted py-3">Kayıt bulunamadı</td></tr>');
                return;
            }
            let html = '';
            res.data.forEach(function(r) {
                html += '<tr>' +
                    '<td><strong>' + (r.isletmeci || '-') + '</strong></td>' +
                    '<td>' + (r.sehir_adi || '-') + '</td>' +
                    '<td>' + (r.takip_tespit_turu || '-') + '</td>' +
                    '<td class="text-center">' + (r.takip_tespit_adet || '0') + '</td>' +
                    '</tr>';
            });
            $('#tbl_son_suc').html(html);
        }, 'json');
    }

    function loadHukukStatuDagilimi() {
        // İsteğe bağlı: Statü dağılımı ayrı bir grafik olarak eklenebilir
    }
    </script>
</body>
</html>
