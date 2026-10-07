<?php
/**
 * Admin Panel - Hukuk Form
 * Takip Ekleme/Düzenleme sayfası
 * Müşteri Bilgileri + Sözleşme Bilgileri (Ürün/Hizmet + Ödeme) tek sayfada
 */

require_once __DIR__ . '/../auth.php';
require_once __DIR__ . '/../includes/PageAuth.php';
require_once __DIR__ . '/../includes/HukukFaiz.php';
require_once __DIR__ . '/../includes/LogHelper.php';
requireAuth();

$user = Auth::user();
$db = Database::getInstance();

// Cari tipi kontrolü (1=Sözleşme, 3=Hukuk, 4=Suç Duyurusu)
$cariTipiId = isset($_GET['cari_tipi_id']) ? intval($_GET['cari_tipi_id']) : 3;
if (!in_array($cariTipiId, [1, 3, 4])) $cariTipiId = 3;

// Sayfa Yetki kontrolu - cari tipine göre yetki sayfası belirle
$currentPagefile = match($cariTipiId) {
    1 => 'sozlesme-yonetimi.php',
    4 => 'suc-duyurusu-takip.php',
    default => 'hukuk-yonetimi.php'
};
$pagePermissions = PageAuth::checkPagePermissions(
    $user['kullanici_id'],
    $user['departman_id'],
    $currentPagefile
);

if (!$pagePermissions['has_access']) {
    PageAuth::accessDenied($pagePermissions['error'] ?? 'Bu sayfaya erişim yetkiniz bulunmamaktadır.');
}

$backPage = match($cariTipiId) {
    1 => 'sozlesme-yonetimi',
    4 => 'suc-duyurusu-takip',
    default => 'hukuk-yonetimi'
};

// Düzenleme modu kontrolu
$editId = isset($_GET['id']) ? intval($_GET['id']) : 0;
$isEdit = $editId > 0;
$takip = null;
$hareketler = [];
$hacizDosyalari = [];

if ($isEdit) {
    if (!$pagePermissions['can_edit']) {
        PageAuth::accessDenied('Düzenleme yetkiniz bulunmamaktadır.');
    }
    
    if ($cariTipiId === 1) {
        // Sözleşme modu: id = sozlesme_id
        $sozlesmeCheck = $db->fetchOne("
            SELECT s.sozlesme_id, s.sozlesme_cari_id,
                   c.cari_adi, c.cari_unvan, c.cari_vergi_dairesi, c.cari_vergi_no,
                   c.cari_telefon, c.cari_yetkili_adi, c.cari_yetkili_telefon,
                   c.cari_adres, c.cari_sehirler, c.cari_ilceler
            FROM Sozlesmeler s
            LEFT JOIN Cari c ON s.sozlesme_cari_id = c.cari_id
            WHERE s.sozlesme_id = ?
        ", [$editId]);
        
        if (!$sozlesmeCheck) {
            header('Location: /admin/' . $backPage);
            exit;
        }
        
        // $takip dizisini cari verileriyle doldur (form alanları için)
        $takip = [
            'takip_id' => 0,
            'takip_cari_id' => $sozlesmeCheck['sozlesme_cari_id'],
            'takip_taraf_id' => null,
            'takip_icra_dairesi_id' => null,
            'takip_savcilik_id' => null,
            'takip_statu_id' => null,
            'takip_dosya_no' => '',
            'takip_aciklama' => '',
            'takip_ana_tutar' => 0,
            'takip_Isleyen_Faiz' => 0,
            'takip_Islemis_Faiz' => 0,
            'takip_Vekalet_Ucreti' => 0,
            'takip_Masraf' => 0,
            'takip_Tahsil_Harci' => 0,
            'Durum' => 1,
            'takip_dosya_acilis_tarihi' => null,
            'OlusturmaTarihi' => null,
            'cari_adi' => $sozlesmeCheck['cari_adi'],
            'cari_unvan' => $sozlesmeCheck['cari_unvan'],
            'cari_vergi_dairesi' => $sozlesmeCheck['cari_vergi_dairesi'],
            'cari_vergi_no' => $sozlesmeCheck['cari_vergi_no'],
            'cari_telefon' => $sozlesmeCheck['cari_telefon'],
            'cari_yetkili_adi' => $sozlesmeCheck['cari_yetkili_adi'],
            'cari_yetkili_telefon' => $sozlesmeCheck['cari_yetkili_telefon'],
            'cari_adres' => $sozlesmeCheck['cari_adres'],
            'cari_sehirler' => $sozlesmeCheck['cari_sehirler'],
            'cari_ilceler' => $sozlesmeCheck['cari_ilceler'],
        ];
    } else {
        // Hukuk/Suç Duyurusu modu: id = takip_id
        $takip = $db->fetchOne("
            SELECT 
                t.takip_id,
                t.takip_cari_id,
                t.takip_taraf_id,
                t.takip_icra_dairesi_id,
                t.takip_savcilik_id,
                t.takip_statu_id,
                t.takip_dosya_no,
                CONVERT(VARCHAR(19), t.takip_haciz_randevu_tarihi, 120) as takip_haciz_randevu_tarihi,
                t.takip_talimat_dosya_no,
                t.takip_aciklama,
                t.takip_ana_tutar,
                t.takip_Isleyen_Faiz,
                t.takip_Islemis_Faiz,
                t.takip_Vekalet_Ucreti,
                t.takip_Masraf,
                t.takip_Tahsil_Harci,
                t.takip_haciz_harci,
                t.takip_arac_ucreti,
                t.takip_tevkil_ucreti,
                t.takip_haciz_tahsilati,
                t.takip_tespit_turu,
                CONVERT(VARCHAR(10), t.takip_tespit_tarihi, 120) as takip_tespit_tarihi,
                t.Durum,
                t.takip_icraya_gidildi,
                t.takip_odeme_taahhutu_alindi,
                CONVERT(VARCHAR(19), t.takip_dosya_acilis_tarihi, 120) as takip_dosya_acilis_tarihi,
                CONVERT(VARCHAR(19), t.OlusturmaTarihi, 120) as OlusturmaTarihi,
                c.cari_adi,
                c.cari_unvan,
                c.cari_vergi_dairesi,
                c.cari_vergi_no,
                c.cari_telefon,
                c.cari_yetkili_adi,
                c.cari_yetkili_telefon,
                c.cari_adres,
                c.cari_sehirler,
                c.cari_ilceler
            FROM HukukTakip t
            LEFT JOIN Cari c ON t.takip_cari_id = c.cari_id
            WHERE t.takip_id = ?
        ", [$editId]);
        
        if (!$takip) {
            header('Location: /admin/' . $backPage);
            exit;
        }

        // Haciz masrafı evrakları
        if ($cariTipiId == 3) {
            $hacizDosyalari = $db->fetchAll("
                SELECT HukukHacizDosyalari_id, HukukHacizDosyalari_OrijinalAd, HukukHacizDosyalari_DosyaAdi,
                       CONVERT(VARCHAR(16), OlusturmaTarihi, 120) AS OlusturmaTarihi
                FROM HukukHacizDosyalari
                WHERE HukukHacizDosyalari_takip_id = ? AND Durum = 1
                ORDER BY HukukHacizDosyalari_id
            ", [$editId]) ?: [];
        }

        // Sözleşme hareketleri (varsa) - cari'ye ait son sözleşme üzerinden
        $hareketler = $db->fetchAll("
            SELECT 
                h.hareket_id,
                h.hareket_sozlesme_id,
                h.hareket_uye_tipi_id,
                h.hareket_ticari_grup_id,
                h.hareket_uye_no_1,
                h.hareket_uye_no_2,
                h.hareket_urun_hizmet_id,
                h.hareket_fiyat,
                h.hareket_durum,
                CONVERT(VARCHAR(10), h.hareket_aktivasyon_tarihi, 120) as hareket_aktivasyon_tarihi,
                CONVERT(VARCHAR(10), h.hareket_taahut_bitis, 120) as hareket_taahut_bitis,
                u.urun_hizmet_adi
            FROM Sozlesme_StokHareketleri h
            LEFT JOIN Urun_Hizmet u ON h.hareket_urun_hizmet_id = u.urun_hizmet_id
            WHERE h.hareket_sozlesme_id = (
                SELECT TOP 1 sozlesme_id FROM Sozlesmeler WHERE sozlesme_cari_id = ? ORDER BY sozlesme_id DESC
            )
            ORDER BY h.hareket_id
        ", [$takip['takip_cari_id']]);
    }
}

// Sayfa bilgilerini veritabanından çek
$pageInfo = $db->fetchOne("
    SELECT 
        s.sayfalar_sayfa_adi, 
        s.sayfalar_aciklama,
        m.menuler_menu_adi as menu_adi
    FROM Menu_Sayfalar s
    LEFT JOIN Menuler m ON s.sayfalar_menu_id = m.menuler_id
    WHERE s.sayfalar_sayfa_url LIKE ? AND s.sayfalar_durum = 1
", ['%' . $currentPagefile]);

$pageTitle = $isEdit ? 'Takip Düzenle' : 'Yeni Takip';
$pageDescription = $pageInfo['sayfalar_aciklama'] ?? '';
$menuAdi = $pageInfo['menu_adi'] ?? null;

// site title
$siteAyarlari = $db->fetchOne("SELECT TOP 1 site_ayarlari_site_title FROM dbo.tanim_site_ayarlari ORDER BY site_ayarlari_id DESC");
$siteTitle = $siteAyarlari['site_ayarlari_site_title'] ?? 'Portal';

// Dropdown verileri
$sezonlar = $db->fetchAll("SELECT sezon_id, sezon_ad, sezon_varsayilan FROM Sozlesme_Sezonlar WHERE sezon_durum = 1 ORDER BY sezon_ad DESC");
// Pasif personel de secilebilir; pasiflik yalnizca panele girisi engeller (bkz. admin/auth.php)
$personeller = $db->fetchAll("
    SELECT kullanici_id,
           kullanici_ad + ' ' + kullanici_soyad
             + CASE WHEN ISNULL(kullanici_durum, 0) = 1 THEN '' ELSE ' (Pasif)' END AS personel_adi
    FROM kullanicilar
    ORDER BY ISNULL(kullanici_durum, 0) DESC, kullanici_ad
");
$uyeTipleri = $db->fetchAll("SELECT uye_tipi_id, uye_tipi_ad FROM Sozlesme_UyeTipleri WHERE uye_tipi_durum = 1 ORDER BY uye_tipi_ad");
$ticariGruplar = $db->fetchAll("SELECT ticari_grup_id, ticari_grup_kod, ticari_grup_ad FROM Sozlesme_TicariGruplar WHERE ticari_grup_durum = 1 ORDER BY ticari_grup_kod");
$urunler = $db->fetchAll("SELECT urun_hizmet_id, urun_hizmet_adi, ISNULL(hizmetSuresi, 0) as hizmetSuresi FROM Urun_Hizmet WHERE urun_hizmet_durum = 1 ORDER BY urun_hizmet_kodu");
$taraflar = $db->fetchAll("SELECT taraf_id, taraf_ad FROM HukukTaraflar WHERE Durum = 1 ORDER BY taraf_ad");
$icraDaireleri = $db->fetchAll("SELECT icra_dairesi_id, icra_dairesi_ad FROM HukukIcraDairesi WHERE Durum = 1 ORDER BY icra_dairesi_ad");
$savciliklar = $db->fetchAll("SELECT savcilik_id, savcilik_ad FROM HukukSavcilik WHERE Durum = 1 ORDER BY savcilik_ad");
$statuler = $db->fetchAll("SELECT statu_id, statu_ad, ISNULL(statu_urun_zorunlu, 0) AS statu_urun_zorunlu FROM HukukStatu WHERE Durum = 1 AND statu_cari_tipi_id = ? ORDER BY statu_sira", [$cariTipiId]);
$sehirler = $db->fetchAll("SELECT SehirId, SehirAdi FROM Adres_Sehirler WHERE UlkeId = 1 ORDER BY SehirAdi");

// İlçeler (düzenleme modunda şehir seçiliyse)
$ilceler = [];
if ($isEdit && !empty($takip['cari_sehirler'])) {
    $ilceler = $db->fetchAll("SELECT ilceId, IlceAdi FROM Adres_Ilceler WHERE SehirId = ? ORDER BY IlceAdi", [$takip['cari_sehirler']]);
}

// Ödeme Sistemi dropdown verileri
$odemeTipleri = $db->fetchAll("SELECT * FROM Sozlesme_OdemeTipleri WHERE odeme_tipi_durum = 1 ORDER BY odeme_tipi_sira");
$odemeDurumlari = $db->fetchAll("SELECT * FROM Sozlesme_OdemeDurumlari ORDER BY odeme_durum_sira");
$bankalar = $db->fetchAll("SELECT banka_id, banka_adi FROM bankalar WHERE banka_durum = 1 ORDER BY banka_adi");
$bankaHesaplari = $db->fetchAll("
    SELECT h.bankaHesap_id, h.bankaHesap_iban, h.bankaHesap_no, h.bankaHesap_sube_adi,
           b.banka_adi
    FROM banka_Hesap h
    INNER JOIN bankalar b ON h.bankaHesap_banka_id = b.banka_id
    WHERE h.bankaHesap_durum = 1
    ORDER BY b.banka_adi, h.bankaHesap_iban
");

// Varsayılan para birimi
$parabirimiSimge = '₺';

// Mevcut sözleşme ve ödemeler (düzenleme modunda)
$sozlesmeId = 0;
$sozlesme = null;
$odemeler = [];
if ($isEdit && !empty($takip['takip_cari_id'])) {
    if ($cariTipiId === 1) {
        // Sözleşme modu: doğrudan sozlesme_id ile çek
        $sozlesme = $db->fetchOne("
            SELECT sozlesme_id, sozlesme_sezon_id, sozlesme_no, sozlesme_aciklama, 
                   sozlesme_personel_id, sozlesme_fatura_no, sozlesme_fatura_dosya, sozlesme_dosyalar, sozlesme_durum,
                   CONVERT(VARCHAR(10), sozlesme_tarih, 120) as sozlesme_tarih
            FROM Sozlesmeler 
            WHERE sozlesme_id = ?
        ", [$editId]);
    } else {
        // Hukuk/Suç Duyurusu modu: cari'ye ait son sözleşmeyi bul
        $sozlesme = $db->fetchOne("
            SELECT TOP 1 sozlesme_id, sozlesme_sezon_id, sozlesme_no, sozlesme_aciklama, 
                   sozlesme_personel_id, sozlesme_fatura_no, sozlesme_fatura_dosya, sozlesme_dosyalar, sozlesme_durum,
                   CONVERT(VARCHAR(10), sozlesme_tarih, 120) as sozlesme_tarih
            FROM Sozlesmeler 
            WHERE sozlesme_cari_id = ? 
            ORDER BY sozlesme_id DESC
        ", [$takip['takip_cari_id']]);
    }
    
    if ($sozlesme) {
        $sozlesmeId = $sozlesme['sozlesme_id'];
        
        $hareketler = $db->fetchAll("
            SELECT 
                h.hareket_id,
                h.hareket_sozlesme_id,
                h.hareket_uye_tipi_id,
                h.hareket_ticari_grup_id,
                h.hareket_uye_no_1,
                h.hareket_uye_no_2,
                h.hareket_urun_hizmet_id,
                h.hareket_fiyat,
                h.hareket_durum,
                CONVERT(VARCHAR(10), h.hareket_aktivasyon_tarihi, 120) as hareket_aktivasyon_tarihi,
                CONVERT(VARCHAR(10), h.hareket_taahut_bitis, 120) as hareket_taahut_bitis,
                u.urun_hizmet_adi
            FROM Sozlesme_StokHareketleri h
            LEFT JOIN Urun_Hizmet u ON h.hareket_urun_hizmet_id = u.urun_hizmet_id
            WHERE h.hareket_sozlesme_id = ?
            ORDER BY h.hareket_id
        ", [$sozlesmeId]);
        
        $odemeler = $db->fetchAll("
            SELECT 
                o.odeme_id,
                o.odeme_tipi_id,
                o.odeme_durum_id,
                o.odeme_belge_no,
                CONVERT(VARCHAR(10), o.odeme_tarih, 120) as odeme_tarih,
                CONVERT(VARCHAR(10), o.odeme_vade_tarih, 120) as odeme_vade_tarihi,
                o.odeme_tutar,
                o.odeme_yapildi,
                o.odeme_personel_id,
                o.odeme_banka_hesap_id,
                o.odeme_guncel_personel_id,
                o.odeme_guncel_banka_hesap_id,
                o.odeme_dosyalar,
                o.odeme_aciklama,
                o.odeme_kilit_kullanici_id,
                o.odeme_kilit_tarihi,
                t.odeme_tipi_ad,
                t.odeme_tipi_hedef,
                d.odeme_durum_ad,
                d.odeme_durum_renk,
                d.odeme_durum_icon,
                k.kullanici_ad + ' ' + k.kullanici_soyad as kilit_kullanici_adi
            FROM Sozlesme_Odemeler o
            LEFT JOIN Sozlesme_OdemeTipleri t ON o.odeme_tipi_id = t.odeme_tipi_id
            LEFT JOIN Sozlesme_OdemeDurumlari d ON o.odeme_durum_id = d.odeme_durum_id
            LEFT JOIN kullanicilar k ON o.odeme_kilit_kullanici_id = k.kullanici_id
            WHERE o.odeme_sozlesme_id = ?
            ORDER BY o.odeme_id
        ", [$sozlesmeId]);
    }
}

// Dosya yükleme dizini
$uploadDir = '../assets/uploads/sozlesmeler/';
if (!is_dir($uploadDir)) {
    mkdir($uploadDir, 0755, true);
}

// AJAX işlemleri
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json');
    $action = $_POST['action'] ?? '';
    
    try {
        switch ($action) {
            case 'save':
                // === 1. MÜŞTERİ BİLGİLERİ ===
                $cariId = intval($_POST['cari_id'] ?? 0);
                $cariAdi = trim($_POST['musteri_adi'] ?? '');
                $cariUnvan = trim($_POST['musteri_unvan'] ?? '');
                $cariVergiDairesi = trim($_POST['musteri_vergi_dairesi'] ?? '');
                $cariVergiNo = trim($_POST['musteri_vergi_no'] ?? '');
                $cariTelefon = preg_replace('/[^0-9]/', '', $_POST['musteri_telefon'] ?? '');
                $cariYetkiliAdi = trim($_POST['musteri_yetkili_adi'] ?? '');
                $cariYetkiliTelefon = preg_replace('/[^0-9]/', '', $_POST['musteri_yetkili_telefon'] ?? '');
                $cariSehir = intval($_POST['musteri_sehir'] ?? 0) ?: null;
                $cariIlce = intval($_POST['musteri_ilce'] ?? 0) ?: null;
                $cariAdres = trim($_POST['musteri_adres'] ?? '');
                
                // Hukuk bilgileri
                $tarafId = intval($_POST['taraf_id'] ?? 0) ?: null;
                $icraDairesiId = intval($_POST['icra_dairesi_id'] ?? 0) ?: null;
                $savcilikId = intval($_POST['savcilik_id'] ?? 0) ?: null;
                $statuId = intval($_POST['statu_id'] ?? 0) ?: null;
                $dosyaNo = trim($_POST['dosya_no'] ?? '');
                $tespitTuru = trim($_POST['tespit_turu'] ?? '');
                $tespitTarihi = trim($_POST['tespit_tarihi'] ?? '');
                $tespitTarihi = !empty($tespitTarihi) ? $tespitTarihi : null;
                $takipAciklama = trim($_POST['takip_aciklama'] ?? '');
                $anaTutar = floatval(str_replace(['.', ','], ['', '.'], $_POST['ana_tutar'] ?? 0));
                $islemisFaiz = floatval(str_replace(['.', ','], ['', '.'], $_POST['islemis_faiz'] ?? 0));
                $vekaletUcreti = floatval(str_replace(['.', ','], ['', '.'], $_POST['vekalet_ucreti'] ?? 0));
                $masraf = floatval(str_replace(['.', ','], ['', '.'], $_POST['masraf'] ?? 0));
                $tahsilHarci = floatval(str_replace(['.', ','], ['', '.'], $_POST['tahsil_harci'] ?? 0));
                $hacizRandevuTarihi = trim($_POST['haciz_randevu_tarihi'] ?? '');
                if (!empty($hacizRandevuTarihi) && $hacizRandevuTarihi !== '0000-00-00') {
                    $hacizRandevuTarihi = str_replace('T', ' ', $hacizRandevuTarihi);
                } else {
                    $hacizRandevuTarihi = null;
                }
                $talimatDosyaNo = trim($_POST['talimat_dosya_no'] ?? '');
                $takipDurum = intval($_POST['takip_durum'] ?? 1);
                $icrayayGidildi = isset($_POST['icraya_gidildi']) ? 1 : 0;
                $odemeTaahhutuAlindi = isset($_POST['odeme_taahhutu_alindi']) ? 1 : 0;
                $hacizHarci = floatval(str_replace(['.', ','], ['', '.'], $_POST['haciz_harci'] ?? 0));
                $aracUcreti = floatval(str_replace(['.', ','], ['', '.'], $_POST['arac_ucreti'] ?? 0));
                $tevkilUcreti = floatval(str_replace(['.', ','], ['', '.'], $_POST['tevkil_ucreti'] ?? 0));
                $hacizTahsilati = floatval(str_replace(['.', ','], ['', '.'], $_POST['haciz_tahsilati'] ?? 0));
                $dosyaAcilisTarihi = trim($_POST['dosya_acilis_tarihi'] ?? '');
                if (!empty($dosyaAcilisTarihi) && $dosyaAcilisTarihi !== '0000-00-00') {
                    $dosyaAcilisTarihi = str_replace('T', ' ', $dosyaAcilisTarihi);
                } else {
                    $dosyaAcilisTarihi = null;
                }
                
                // Sözleşme bilgileri
                $sezonId = $_POST['sezon_id'] ?? null;
                $sozlesmeTarih = trim($_POST['sozlesme_tarih'] ?? '');
                $sozlesmeTarih = !empty($sozlesmeTarih) ? $sozlesmeTarih : date('Y-m-d');
                $sozlesmeNo = trim($_POST['sozlesme_no'] ?? '');
                $sozlesmeAciklama = trim($_POST['sozlesme_aciklama'] ?? '');
                $personelId = $_POST['personel_id'] ?: null;
                $faturaNo = trim($_POST['fatura_no'] ?? '');
                $sozlesmeDurum = $_POST['sozlesme_durum'] ?? 1;
                $mevcutDosyalar = $_POST['mevcut_dosyalar'] ?? '[]';
                $mevcutFaturaDosya = $_POST['mevcut_fatura_dosya'] ?? '';
                $mevcutSozlesmeId = intval($_POST['sozlesme_id'] ?? 0);
                $mevcutTakipId = intval($_POST['takip_id'] ?? 0);
                
                // Validasyon
                if (empty($cariAdi)) {
                    echo json_encode(['success' => false, 'message' => 'Müşteri adı zorunludur!']);
                    exit;
                }
                if (empty($cariSehir)) {
                    echo json_encode(['success' => false, 'message' => 'Şehir seçimi zorunludur!']);
                    exit;
                }

                // Statüye bağlı Ürün/Hizmet + Fiyat zorunluluğu
                if ($statuId) {
                    $statuKontrol = $db->fetchOne(
                        "SELECT statu_ad, ISNULL(statu_urun_zorunlu, 0) AS statu_urun_zorunlu FROM HukukStatu WHERE statu_id = ?",
                        [$statuId]
                    );
                    if ($statuKontrol && intval($statuKontrol['statu_urun_zorunlu']) === 1) {
                        $kontrolHareketler = json_decode($_POST['hareketler'] ?? '[]', true) ?: [];
                        $gecerliSatir = 0;
                        $eksikSatir = false;
                        foreach ($kontrolHareketler as $h) {
                            $urunId = intval($h['urun_hizmet_id'] ?? 0);
                            $fiyat = floatval($h['fiyat'] ?? 0);
                            if (!$urunId && $fiyat <= 0) continue;
                            if (!$urunId || $fiyat <= 0) {
                                $eksikSatir = true;
                                continue;
                            }
                            $gecerliSatir++;
                        }
                        if ($gecerliSatir === 0 || $eksikSatir) {
                            echo json_encode([
                                'success' => false,
                                'message' => '"' . $statuKontrol['statu_ad'] . '" statüsünde Ürün/Hizmet Listesine en az bir Ürün/Hizmet ve Fiyat girilmesi zorunludur!'
                            ]);
                            exit;
                        }
                    }
                }

                // === MÜŞTERİ KAYDET/GÜNCELLE ===
                if ($cariId > 0) {
                    // Güncelle - eski kaydı al (log için)
                    $eskiCari = $db->fetchOne("SELECT * FROM Cari WHERE cari_id = ?", [$cariId]);
                    $cariResult = $db->execute("
                        UPDATE Cari SET
                            cari_adi = ?,
                            cari_unvan = ?,
                            cari_vergi_dairesi = ?,
                            cari_vergi_no = ?,
                            cari_telefon = ?,
                            cari_yetkili_adi = ?,
                            cari_yetkili_telefon = ?,
                            cari_sehirler = ?,
                            cari_ilceler = ?,
                            cari_adres = ?,
                            cari_guncelleme_tarihi = GETDATE()
                        WHERE cari_id = ?
                    ", [
                        $cariAdi, $cariUnvan, $cariVergiDairesi, $cariVergiNo,
                        $cariTelefon, $cariYetkiliAdi, $cariYetkiliTelefon,
                        $cariSehir, $cariIlce, $cariAdres, $cariId
                    ]);
                    if (!$cariResult) {
                        $errors = sqlsrv_errors();
                        $errMsg = $errors ? $errors[0]['message'] : 'Bilinmeyen hata';
                        echo json_encode(['success' => false, 'message' => 'Cari güncelleme hatası: ' . $errMsg]);
                        exit;
                    }
                    $yeniCari = $db->fetchOne("SELECT * FROM Cari WHERE cari_id = ?", [$cariId]);
                    logKayitDegisiklikleri($db, 'form', 'Cari', $cariId, $eskiCari, $yeniCari, $user['kullanici_id'], 'Cari güncellendi');
                } else {
                    // Yeni müşteri ekle
                    $db->execute("
                        INSERT INTO Cari (
                            cari_adi, cari_unvan, cari_vergi_dairesi, cari_vergi_no,
                            cari_telefon, cari_yetkili_adi, cari_yetkili_telefon,
                            cari_adres, cari_ulke, cari_sehirler, cari_ilceler,
                            cari_tipi_id, cari_aktif, cari_musteri, cari_tedarikci, 
                            cari_olusturan_kullanici, cari_olusturma_tarihi
                        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, 1, ?, ?, ?, 1, 1, 0, ?, GETDATE())
                    ", [
                        $cariAdi, $cariUnvan, $cariVergiDairesi, $cariVergiNo,
                        $cariTelefon, $cariYetkiliAdi, $cariYetkiliTelefon,
                        $cariAdres, $cariSehir, $cariIlce, $cariTipiId, $user['kullanici_id']
                    ]);
                    $newCari = $db->fetchOne("SELECT TOP 1 cari_id FROM Cari ORDER BY cari_id DESC");
                    $cariId = $newCari['cari_id'];
                    $yeniCari = $db->fetchOne("SELECT * FROM Cari WHERE cari_id = ?", [$cariId]);
                    logKayitDegisiklikleri($db, 'form', 'Cari', $cariId, null, $yeniCari, $user['kullanici_id'], 'Yeni cari oluşturuldu');
                }
                
                // === HUKUK TAKİP KAYDET/GÜNCELLE ===
                if ($mevcutTakipId > 0) {
                    $eskiTakip = $db->fetchOne("SELECT * FROM HukukTakip WHERE takip_id = ?", [$mevcutTakipId]);
                    $takipResult = $db->execute("
                        UPDATE HukukTakip SET 
                            takip_cari_id = ?,
                            takip_taraf_id = ?,
                            takip_icra_dairesi_id = ?,
                            takip_savcilik_id = ?,
                            takip_statu_id = ?,
                            takip_dosya_no = ?,
                            takip_haciz_randevu_tarihi = ?,
                            takip_talimat_dosya_no = ?,
                            takip_aciklama = ?,
                            takip_dosya_acilis_tarihi = ?,
                            takip_ana_tutar = ?,
                            takip_Islemis_Faiz = ?,
                            takip_Vekalet_Ucreti = ?,
                            takip_Masraf = ?,
                            takip_Tahsil_Harci = ?,
                            takip_haciz_harci = ?,
                            takip_arac_ucreti = ?,
                            takip_tevkil_ucreti = ?,
                            takip_haciz_tahsilati = ?,
                            takip_tespit_turu = ?,
                            takip_tespit_tarihi = ?,
                            Durum = ?,
                            takip_icraya_gidildi = ?,
                            takip_odeme_taahhutu_alindi = ?,
                            GuncelleyenKullanici = ?,
                            GuncellemeTarihi = GETDATE()
                        WHERE takip_id = ?
                    ", [
                        $cariId, $tarafId, $icraDairesiId, $savcilikId, $statuId, $dosyaNo,
                        $hacizRandevuTarihi, $talimatDosyaNo,
                        $takipAciklama, $dosyaAcilisTarihi, $anaTutar,
                        $islemisFaiz, $vekaletUcreti, $masraf, $tahsilHarci,
                        $hacizHarci, $aracUcreti, $tevkilUcreti, $hacizTahsilati,
                        $tespitTuru, $tespitTarihi,
                        $takipDurum, $icrayayGidildi, $odemeTaahhutuAlindi,
                        $user['kullanici_id'], $mevcutTakipId
                    ]);
                    if (!$takipResult) {
                        $errors = sqlsrv_errors();
                        $errMsg = $errors ? $errors[0]['message'] : 'Bilinmeyen hata';
                        echo json_encode(['success' => false, 'message' => 'Takip güncelleme hatası: ' . $errMsg]);
                        exit;
                    }
                    $takipId = $mevcutTakipId;
                    $yeniTakip = $db->fetchOne("SELECT * FROM HukukTakip WHERE takip_id = ?", [$takipId]);
                    logKayitDegisiklikleri($db, 'form', 'HukukTakip', $takipId, $eskiTakip, $yeniTakip, $user['kullanici_id'], 'Takip güncellendi');
                } else {
                    $db->execute("
                        INSERT INTO HukukTakip (
                            takip_cari_id, takip_taraf_id, takip_icra_dairesi_id, takip_savcilik_id, takip_statu_id,
                            takip_dosya_no, takip_haciz_randevu_tarihi, takip_talimat_dosya_no, takip_aciklama, takip_dosya_acilis_tarihi, takip_ana_tutar, takip_Isleyen_Faiz,
                            takip_Islemis_Faiz, takip_Vekalet_Ucreti, takip_Masraf, takip_Tahsil_Harci,
                            takip_haciz_harci, takip_arac_ucreti, takip_tevkil_ucreti, takip_haciz_tahsilati,
                            takip_tespit_turu, takip_tespit_tarihi, Durum, takip_icraya_gidildi, takip_odeme_taahhutu_alindi, OlusturanKullanici
                        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                    ", [
                        $cariId, $tarafId, $icraDairesiId, $savcilikId, $statuId, $dosyaNo,
                        $hacizRandevuTarihi, $talimatDosyaNo,
                        $takipAciklama, $dosyaAcilisTarihi, $anaTutar, 0,
                        $islemisFaiz, $vekaletUcreti, $masraf, $tahsilHarci,
                        $hacizHarci, $aracUcreti, $tevkilUcreti, $hacizTahsilati,
                        $tespitTuru, $tespitTarihi,
                        $takipDurum, $icrayayGidildi, $odemeTaahhutuAlindi,
                        $user['kullanici_id']
                    ]);
                    $newTakip = $db->fetchOne("SELECT TOP 1 takip_id FROM HukukTakip ORDER BY takip_id DESC");
                    $takipId = $newTakip['takip_id'];
                    $yeniTakip = $db->fetchOne("SELECT * FROM HukukTakip WHERE takip_id = ?", [$takipId]);
                    logKayitDegisiklikleri($db, 'form', 'HukukTakip', $takipId, null, $yeniTakip, $user['kullanici_id'], 'Yeni takip oluşturuldu');
                }
                
                // Isleyen faiz kayit/guncelleme aninda hesaplanir; gece 01:00 cronundan
                // sonra girilen tutarlarin ertesi gune kadar 0 gorunmesini engeller.
                hukukIsleyenFaizGuncelle($db, (int)$takipId);

                // === HACİZ MASRAFI EVRAKLARI ===
                if ($cariTipiId == 3) {
                    // Kaldırılan evraklar (soft delete, yalnız bu takibe ait olanlar)
                    $silinenHacizDosyalar = array_filter(array_map('intval', json_decode($_POST['haciz_silinen_dosyalar'] ?? '[]', true) ?: []));
                    foreach ($silinenHacizDosyalar as $silinenId) {
                        $db->execute("
                            UPDATE HukukHacizDosyalari SET Durum = 0, GuncelleyenKullanici = ?, GuncellemeTarihi = GETDATE()
                            WHERE HukukHacizDosyalari_id = ? AND HukukHacizDosyalari_takip_id = ?
                        ", [$user['kullanici_id'], $silinenId, $takipId]);
                    }

                    // Yeni evraklar
                    if (!empty($_FILES['haciz_dosyalar']['name'][0])) {
                        $hacizUploadDir = __DIR__ . '/../assets/uploads/haciz_dosyalar/';
                        if (!is_dir($hacizUploadDir)) {
                            mkdir($hacizUploadDir, 0755, true);
                        }
                        $izinliUzantilar = ['pdf', 'jpg', 'jpeg', 'png', 'doc', 'docx', 'xls', 'xlsx'];
                        foreach ($_FILES['haciz_dosyalar']['tmp_name'] as $key => $tmpName) {
                            if ($_FILES['haciz_dosyalar']['error'][$key] !== UPLOAD_ERR_OK) continue;
                            $orijinalAd = basename($_FILES['haciz_dosyalar']['name'][$key]);
                            $uzanti = strtolower(pathinfo($orijinalAd, PATHINFO_EXTENSION));
                            if (!in_array($uzanti, $izinliUzantilar)) continue;
                            $yeniAd = uniqid('haciz_' . $takipId . '_') . '.' . $uzanti;
                            if (move_uploaded_file($tmpName, $hacizUploadDir . $yeniAd)) {
                                $db->execute("
                                    INSERT INTO HukukHacizDosyalari (
                                        HukukHacizDosyalari_takip_id, HukukHacizDosyalari_OrijinalAd, HukukHacizDosyalari_DosyaAdi,
                                        HukukHacizDosyalari_Boyut, OlusturanKullanici, OlusturmaTarihi, Durum
                                    ) VALUES (?, ?, ?, ?, ?, GETDATE(), 1)
                                ", [$takipId, $orijinalAd, $yeniAd, intval($_FILES['haciz_dosyalar']['size'][$key]), $user['kullanici_id']]);
                            }
                        }
                    }
                }

                // === SÖZLEŞME KAYDET/GÜNCELLE ===
                // Dosya yükleme
                $dosyalar = json_decode($mevcutDosyalar, true) ?: [];
                if (!empty($_FILES['dosyalar']['name'][0])) {
                    foreach ($_FILES['dosyalar']['tmp_name'] as $key => $tmpName) {
                        if ($_FILES['dosyalar']['error'][$key] === UPLOAD_ERR_OK) {
                            $originalName = $_FILES['dosyalar']['name'][$key];
                            $extension = pathinfo($originalName, PATHINFO_EXTENSION);
                            $newName = uniqid('sozlesme_') . '.' . $extension;
                            $targetPath = $uploadDir . $newName;
                            if (move_uploaded_file($tmpName, $targetPath)) {
                                $dosyalar[] = 'Admin/assets/uploads/sozlesmeler/' . $newName;
                            }
                        }
                    }
                }
                $dosyalarJson = json_encode($dosyalar, JSON_UNESCAPED_UNICODE);
                
                // Fatura dosyası
                $faturaDosya = $mevcutFaturaDosya;
                if (!empty($_FILES['fatura_dosya']['name']) && $_FILES['fatura_dosya']['error'] === UPLOAD_ERR_OK) {
                    $faturaExt = strtolower(pathinfo($_FILES['fatura_dosya']['name'], PATHINFO_EXTENSION));
                    $faturaNewName = uniqid('fatura_') . '.' . $faturaExt;
                    $faturaDosyaDir = __DIR__ . '/../assets/uploads/sozlesmeler/';
                    if (move_uploaded_file($_FILES['fatura_dosya']['tmp_name'], $faturaDosyaDir . $faturaNewName)) {
                        if ($mevcutFaturaDosya && file_exists($faturaDosyaDir . basename($mevcutFaturaDosya))) {
                            unlink($faturaDosyaDir . basename($mevcutFaturaDosya));
                        }
                        $faturaDosya = 'Admin/assets/uploads/sozlesmeler/' . $faturaNewName;
                    }
                }
                
                if ($mevcutSozlesmeId > 0) {
                    $eskiSozlesme = $db->fetchOne("SELECT * FROM Sozlesmeler WHERE sozlesme_id = ?", [$mevcutSozlesmeId]);
                    $db->execute("
                        UPDATE Sozlesmeler SET
                            sozlesme_sezon_id = ?,
                            sozlesme_tarih = ?,
                            sozlesme_cari_id = ?,
                            sozlesme_no = ?,
                            sozlesme_aciklama = ?,
                            sozlesme_personel_id = ?,
                            sozlesme_fatura_no = ?,
                            sozlesme_fatura_dosya = ?,
                            sozlesme_dosyalar = ?,
                            sozlesme_guncelleme_tarihi = GETDATE(),
                            sozlesme_durum = ?
                        WHERE sozlesme_id = ?
                    ", [
                        $sezonId, $sozlesmeTarih, $cariId, $sozlesmeNo, $sozlesmeAciklama,
                        $personelId, $faturaNo, $faturaDosya, $dosyalarJson, $sozlesmeDurum, $mevcutSozlesmeId
                    ]);
                    $currentSozlesmeId = $mevcutSozlesmeId;
                    $yeniSozlesme = $db->fetchOne("SELECT * FROM Sozlesmeler WHERE sozlesme_id = ?", [$currentSozlesmeId]);
                    logKayitDegisiklikleri($db, 'form', 'Sozlesmeler', $currentSozlesmeId, $eskiSozlesme, $yeniSozlesme, $user['kullanici_id'], 'Sözleşme güncellendi');
                } else {
                    $db->execute("
                        INSERT INTO Sozlesmeler (
                            sozlesme_sezon_id, sozlesme_tarih, sozlesme_cari_id,
                            sozlesme_no, sozlesme_aciklama, sozlesme_personel_id,
                            sozlesme_fatura_no, sozlesme_fatura_dosya, sozlesme_dosyalar,
                            sozlesme_kullanici_id, sozlesme_olusturma_tarihi, sozlesme_durum
                        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, GETDATE(), ?)
                    ", [
                        $sezonId, $sozlesmeTarih, $cariId, $sozlesmeNo, $sozlesmeAciklama,
                        $personelId, $faturaNo, $faturaDosya, $dosyalarJson,
                        $user['kullanici_id'], $sozlesmeDurum
                    ]);
                    $newSozlesme = $db->fetchOne("SELECT TOP 1 sozlesme_id FROM Sozlesmeler ORDER BY sozlesme_id DESC");
                    $currentSozlesmeId = $newSozlesme['sozlesme_id'];
                    $yeniSozlesme = $db->fetchOne("SELECT * FROM Sozlesmeler WHERE sozlesme_id = ?", [$currentSozlesmeId]);
                    logKayitDegisiklikleri($db, 'form', 'Sozlesmeler', $currentSozlesmeId, null, $yeniSozlesme, $user['kullanici_id'], 'Yeni sözleşme oluşturuldu');
                }
                
                // === STOK HAREKETLERİ ===
                $db->execute("DELETE FROM Sozlesme_StokHareketleri WHERE hareket_sozlesme_id = ?", [$currentSozlesmeId]);
                
                $hareketlerData = json_decode($_POST['hareketler'] ?? '[]', true);
                foreach ($hareketlerData as $hareket) {
                    if (empty($hareket['urun_hizmet_id'])) continue;
                    $db->execute("
                        INSERT INTO Sozlesme_StokHareketleri (
                            hareket_sozlesme_id, hareket_uye_tipi_id, hareket_ticari_grup_id,
                            hareket_uye_no_1, hareket_uye_no_2, hareket_urun_hizmet_id,
                            hareket_fiyat, hareket_aktivasyon_tarihi, hareket_taahut_bitis,
                            hareket_durum, hareket_olusturma_tarihi
                        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, GETDATE())
                    ", [
                        $currentSozlesmeId,
                        $hareket['uye_tipi_id'] ?: null,
                        $hareket['ticari_grup_id'] ?: null,
                        $hareket['uye_no_1'] ?: null,
                        $hareket['uye_no_2'] ?: null,
                        $hareket['urun_hizmet_id'],
                        $hareket['fiyat'] ?: 0,
                        $hareket['aktivasyon_tarihi'] ?: null,
                        $hareket['taahut_bitis'] ?: null,
                        $hareket['durum'] ?? 1
                    ]);
                }
                
                // === ÖDEMELER ===
                $odemelerData = json_decode($_POST['odemeler'] ?? '[]', true);
                $mevcutOdemeIds = [];
                foreach ($odemelerData as $odeme) {
                    if (!empty($odeme['odeme_id'])) {
                        $mevcutOdemeIds[] = $odeme['odeme_id'];
                    }
                }
                
                if (!empty($mevcutOdemeIds)) {
                    $placeholders = implode(',', array_fill(0, count($mevcutOdemeIds), '?'));
                    $db->execute("DELETE FROM Sozlesme_Odemeler WHERE odeme_sozlesme_id = ? AND odeme_id NOT IN ($placeholders)", array_merge([$currentSozlesmeId], $mevcutOdemeIds));
                } else {
                    $db->execute("DELETE FROM Sozlesme_Odemeler WHERE odeme_sozlesme_id = ?", [$currentSozlesmeId]);
                }
                
                $odemeIndex = 0;
                $odemeUploadDir = __DIR__ . '/../assets/uploads/sozlesme_odemeler/';
                $izinliUzantilar = ['pdf', 'jpg', 'jpeg', 'png'];
                
                foreach ($odemelerData as $odeme) {
                    if (empty($odeme['odeme_tip_id'])) { $odemeIndex++; continue; }
                    
                    $odemeId = $odeme['odeme_id'] ?? 0;
                    
                    // Dosya yükleme
                    $yeniDosya = null;
                    if (
                        isset($_FILES['odeme_dosya']['name'][$odemeIndex]) &&
                        $_FILES['odeme_dosya']['error'][$odemeIndex] === UPLOAD_ERR_OK &&
                        !empty($_FILES['odeme_dosya']['name'][$odemeIndex])
                    ) {
                        $ext = strtolower(pathinfo($_FILES['odeme_dosya']['name'][$odemeIndex], PATHINFO_EXTENSION));
                        if (in_array($ext, $izinliUzantilar)) {
                            $yeniDosya = uniqid('odeme_') . '_' . time() . '.' . $ext;
                            move_uploaded_file($_FILES['odeme_dosya']['tmp_name'][$odemeIndex], $odemeUploadDir . $yeniDosya);
                        }
                    }
                    
                    $tipBilgi = $db->fetchOne("SELECT odeme_tipi_varsayilan_durum_id, odeme_tipi_hedef FROM Sozlesme_OdemeTipleri WHERE odeme_tipi_id = ?", [$odeme['odeme_tip_id']]);
                    $varsayilanDurum = $tipBilgi['odeme_tipi_varsayilan_durum_id'] ?? 1;
                    $durumId = !empty($odeme['odeme_durum_id']) ? $odeme['odeme_durum_id'] : $varsayilanDurum;
                    
                    $hedefPersonelId = $odeme['hedef_personel_id'] ?: null;
                    $hedefBankaHesapId = $odeme['hedef_banka_hesap_id'] ?: null;
                    $odemeYapildi = !empty($odeme['odeme_yapildi']) ? 1 : 0;
                    
                    if ($odemeId > 0) {
                        // Kilit kontrolü: başkası kilitlediyse güncelleme yapma (admin ve müdür hariç)
                        $isAdmin = ($user['departman_id'] == 1 || $user['departman_id'] == 22);
                        $kilitlenen = $db->fetchOne("SELECT odeme_kilit_kullanici_id FROM Sozlesme_Odemeler WHERE odeme_id = ?", [$odemeId]);
                        if (!$isAdmin && $kilitlenen && $kilitlenen['odeme_kilit_kullanici_id'] && $kilitlenen['odeme_kilit_kullanici_id'] != $user['kullanici_id']) {
                            $odemeIndex++;
                            continue;
                        }

                        $mevcutDosya = $odeme['mevcut_dosya'] ?? null;
                        $kayitliDosya = $yeniDosya ?: $mevcutDosya;

                        if ($yeniDosya && $mevcutDosya && $yeniDosya !== $mevcutDosya) {
                            $eskiYol = $odemeUploadDir . $mevcutDosya;
                            if (file_exists($eskiYol)) unlink($eskiYol);
                        }

                        $db->execute("
                            UPDATE Sozlesme_Odemeler SET
                                odeme_tipi_id = ?,
                                odeme_belge_no = ?,
                                odeme_tarih = ?,
                                odeme_vade_tarih = ?,
                                odeme_tutar = ?,
                                odeme_personel_id = ?,
                                odeme_banka_hesap_id = ?,
                                odeme_guncel_personel_id = ?,
                                odeme_guncel_banka_hesap_id = ?,
                                odeme_durum_id = ?,
                                odeme_yapildi = ?,
                                odeme_dosyalar = ?,
                                odeme_aciklama = ?,
                                odeme_guncelleyen_id = ?,
                                odeme_guncelleme_tarihi = GETDATE()
                            WHERE odeme_id = ?
                        ", [
                            $odeme['odeme_tip_id'],
                            $odeme['odeme_belge_no'] ?: null,
                            $odeme['odeme_tarih'],
                            $odeme['odeme_vade_tarihi'] ?: null,
                            $odeme['odeme_tutar'] ?: 0,
                            $hedefPersonelId, $hedefBankaHesapId,
                            $hedefPersonelId, $hedefBankaHesapId,
                            $durumId, $odemeYapildi, $kayitliDosya,
                            $odeme['odeme_aciklama'] ?? null,
                            $user['kullanici_id'], $odemeId
                        ]);
                    } else {
                        $db->execute("
                            INSERT INTO Sozlesme_Odemeler (
                                odeme_sozlesme_id, odeme_tipi_id, odeme_durum_id,
                                odeme_belge_no, odeme_tarih, odeme_vade_tarih, odeme_tutar,
                                odeme_personel_id, odeme_banka_hesap_id,
                                odeme_guncel_personel_id, odeme_guncel_banka_hesap_id,
                                odeme_yapildi, odeme_aciklama, odeme_olusturan_id, odeme_olusturma_tarihi
                            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, GETDATE())
                        ", [
                            $currentSozlesmeId,
                            $odeme['odeme_tip_id'], $durumId,
                            $odeme['odeme_belge_no'] ?: null,
                            $odeme['odeme_tarih'],
                            $odeme['odeme_vade_tarihi'] ?: null,
                            $odeme['odeme_tutar'] ?: 0,
                            $hedefPersonelId, $hedefBankaHesapId,
                            $hedefPersonelId, $hedefBankaHesapId,
                            $odemeYapildi,
                            $odeme['odeme_aciklama'] ?? null,
                            $user['kullanici_id']
                        ]);
                        
                        if ($yeniDosya) {
                            $yeniOdeme = $db->fetchOne("SELECT TOP 1 odeme_id FROM Sozlesme_Odemeler WHERE odeme_sozlesme_id = ? ORDER BY odeme_id DESC", [$currentSozlesmeId]);
                            if ($yeniOdeme) {
                                $db->execute("UPDATE Sozlesme_Odemeler SET odeme_dosyalar = ? WHERE odeme_id = ?", [$yeniDosya, $yeniOdeme['odeme_id']]);
                            }
                        }
                    }
                    $odemeIndex++;
                }
                
                echo json_encode(['success' => true, 'message' => 'Kayıt başarıyla kaydedildi!', 'takip_id' => $takipId]);
                break;
                
            case 'odeme_kilit_toggle':
                $odemeIdKilit = intval($_POST['odeme_id'] ?? 0);
                $kilitle = intval($_POST['kilitle'] ?? 0);
                if ($odemeIdKilit <= 0) {
                    echo json_encode(['success' => false, 'message' => 'Geçersiz ödeme ID.']);
                    break;
                }
                $mevcutKilit = $db->fetchOne("SELECT odeme_kilit_kullanici_id FROM Sozlesme_Odemeler WHERE odeme_id = ?", [$odemeIdKilit]);
                if (!$mevcutKilit) {
                    echo json_encode(['success' => false, 'message' => 'Ödeme bulunamadı.']);
                    break;
                }
                $isAdmin = ($user['departman_id'] == 1 || $user['departman_id'] == 22);
                // Kilit açmak için sadece kilitleyen kişi, admin veya müdür yetkili
                if (!$kilitle && $mevcutKilit['odeme_kilit_kullanici_id'] && $mevcutKilit['odeme_kilit_kullanici_id'] != $user['kullanici_id'] && !$isAdmin) {
                    echo json_encode(['success' => false, 'message' => 'Bu kilidi sadece kilitleyen kişi açabilir.']);
                    break;
                }
                if ($kilitle) {
                    $db->execute("UPDATE Sozlesme_Odemeler SET odeme_kilit_kullanici_id = ?, odeme_kilit_tarihi = GETDATE() WHERE odeme_id = ?", [$user['kullanici_id'], $odemeIdKilit]);
                } else {
                    $db->execute("UPDATE Sozlesme_Odemeler SET odeme_kilit_kullanici_id = NULL, odeme_kilit_tarihi = NULL WHERE odeme_id = ?", [$odemeIdKilit]);
                }
                echo json_encode(['success' => true]);
                break;

            case 'get_ilceler':
                $sehirId = intval($_POST['sehir_id'] ?? 0);
                $ilcelerData = $db->fetchAll("SELECT ilceId, IlceAdi FROM Adres_Ilceler WHERE SehirId = ? ORDER BY IlceAdi", [$sehirId]);
                echo json_encode(['success' => true, 'data' => $ilcelerData]);
                break;
                
            case 'cari_ara':
                $aramaMetni = trim($_POST['arama'] ?? '');
                $params = [];
                $where = "c.cari_aktif = 1";
                if (!empty($aramaMetni)) {
                    $where .= " AND (c.cari_adi LIKE ? OR c.cari_unvan LIKE ? OR c.cari_vergi_no LIKE ? OR c.cari_telefon LIKE ?)";
                    $params = ["%$aramaMetni%", "%$aramaMetni%", "%$aramaMetni%", "%$aramaMetni%"];
                }
                $cariListesi = $db->fetchAll("
                    SELECT TOP 50
                        c.cari_id,
                        c.cari_adi,
                        c.cari_unvan,
                        c.cari_vergi_no,
                        c.cari_vergi_dairesi,
                        c.cari_telefon,
                        c.cari_yetkili_adi,
                        c.cari_yetkili_telefon,
                        c.cari_adres,
                        c.cari_sehirler,
                        c.cari_ilceler,
                        s.SehirAdi,
                        ct.cari_tipi_ad
                    FROM Cari c
                    LEFT JOIN Adres_Sehirler s ON c.cari_sehirler = s.SehirId
                    LEFT JOIN Cari_CariTipleri ct ON c.cari_tipi_id = ct.cari_tipi_id
                    WHERE $where
                    ORDER BY c.cari_adi
                ", $params);
                echo json_encode(['success' => true, 'data' => $cariListesi]);
                break;
                
            case 'cari_degistir':
                // Mevcut takip/sözleşme kayıtlarının cari_id'sini değiştir
                $yeniCariId = intval($_POST['yeni_cari_id'] ?? 0);
                $mevcutTakipId = intval($_POST['takip_id'] ?? 0);
                $mevcutSozlesmeId = intval($_POST['sozlesme_id'] ?? 0);
                
                if (!$yeniCariId) {
                    echo json_encode(['success' => false, 'message' => 'Geçersiz cari ID']);
                    exit;
                }
                
                // Yeni cari bilgilerini çek
                $yeniCari = $db->fetchOne("
                    SELECT c.*, s.SehirAdi, i.IlceAdi
                    FROM Cari c
                    LEFT JOIN Adres_Sehirler s ON c.cari_sehirler = s.SehirId
                    LEFT JOIN Adres_Ilceler i ON c.cari_ilceler = i.ilceId
                    WHERE c.cari_id = ?
                ", [$yeniCariId]);
                
                if (!$yeniCari) {
                    echo json_encode(['success' => false, 'message' => 'Cari bulunamadı']);
                    exit;
                }
                
                // Takip kaydını güncelle
                if ($mevcutTakipId > 0) {
                    $eskiTakip = $db->fetchOne("SELECT * FROM HukukTakip WHERE takip_id = ?", [$mevcutTakipId]);
                    $db->execute("
                        UPDATE HukukTakip SET
                            takip_cari_id = ?,
                            GuncelleyenKullanici = ?,
                            GuncellemeTarihi = GETDATE()
                        WHERE takip_id = ?
                    ", [$yeniCariId, $user['kullanici_id'], $mevcutTakipId]);
                    $yeniTakip = $db->fetchOne("SELECT * FROM HukukTakip WHERE takip_id = ?", [$mevcutTakipId]);
                    logKayitDegisiklikleri($db, 'form', 'HukukTakip', $mevcutTakipId, $eskiTakip, $yeniTakip, $user['kullanici_id'], 'Cari değiştirildi');
                }
                
                // Sözleşme kaydını güncelle
                if ($mevcutSozlesmeId > 0) {
                    $eskiSozlesme = $db->fetchOne("SELECT * FROM Sozlesmeler WHERE sozlesme_id = ?", [$mevcutSozlesmeId]);
                    $db->execute("
                        UPDATE Sozlesmeler SET
                            sozlesme_cari_id = ?,
                            sozlesme_guncelleme_tarihi = GETDATE()
                        WHERE sozlesme_id = ?
                    ", [$yeniCariId, $mevcutSozlesmeId]);
                    $yeniSozlesme = $db->fetchOne("SELECT * FROM Sozlesmeler WHERE sozlesme_id = ?", [$mevcutSozlesmeId]);
                    logKayitDegisiklikleri($db, 'form', 'Sozlesmeler', $mevcutSozlesmeId, $eskiSozlesme, $yeniSozlesme, $user['kullanici_id'], 'Cari değiştirildi');
                }
                
                // İlçe listesini de döndür (form için)
                $ilceler = [];
                if (!empty($yeniCari['cari_ilceler'])) {
                    $ilceler = $db->fetchAll("SELECT ilceId, IlceAdi FROM Adres_Ilceler WHERE SehirId = ? ORDER BY IlceAdi", [$yeniCari['cari_sehirler']]);
                }
                
                echo json_encode([
                    'success' => true,
                    'message' => 'Cari başarıyla değiştirildi',
                    'cari' => $yeniCari,
                    'ilceler' => $ilceler
                ]);
                break;
                
            case 'change_odeme_durum':
                $odemeIdParam = $_POST['odeme_id'] ?? 0;
                $yeniDurumId = $_POST['yeni_durum_id'] ?? 0;
                $hedefPersonelIdParam = $_POST['hedef_personel_id'] ?: null;
                $hedefBankaHesapIdParam = $_POST['hedef_banka_hesap_id'] ?: null;
                $aciklamaParam = trim($_POST['aciklama'] ?? '');
                
                $eskiOdemeDurum = $db->fetchOne("SELECT * FROM Sozlesme_Odemeler WHERE odeme_id = ?", [$odemeIdParam]);
                
                $db->execute("
                    UPDATE Sozlesme_Odemeler SET
                        odeme_durum_id = ?,
                        odeme_guncel_personel_id = ?,
                        odeme_guncel_banka_hesap_id = ?,
                        odeme_guncelleyen_id = ?,
                        odeme_guncelleme_tarihi = GETDATE()
                    WHERE odeme_id = ?
                ", [$yeniDurumId, $hedefPersonelIdParam, $hedefBankaHesapIdParam, $user['kullanici_id'], $odemeIdParam]);
                
                $yeniOdemeDurum = $db->fetchOne("SELECT * FROM Sozlesme_Odemeler WHERE odeme_id = ?", [$odemeIdParam]);
                logKayitDegisiklikleri($db, 'form', 'Sozlesme_Odemeler', $odemeIdParam, $eskiOdemeDurum, $yeniOdemeDurum, $user['kullanici_id'], 'Ödeme durumu değiştirildi');
                
                echo json_encode(['success' => true, 'message' => 'Durum güncellendi!']);
                break;
                
            case 'delete_odeme':
                $odemeIdDel = $_POST['odeme_id'] ?? 0;
                $eskiOdeme = $db->fetchOne("SELECT * FROM Sozlesme_Odemeler WHERE odeme_id = ?", [$odemeIdDel]);
                logKayitDegisiklikleri($db, 'form', 'Sozlesme_Odemeler', $odemeIdDel, $eskiOdeme, null, $user['kullanici_id'], 'Ödeme silindi');
                $db->execute("DELETE FROM Sozlesme_Odemeler WHERE odeme_id = ?", [$odemeIdDel]);
                
                if ($eskiOdeme && !empty($eskiOdeme['odeme_dosyalar'])) {
                    $dosyaYolu = __DIR__ . '/../assets/uploads/sozlesme_odemeler/' . $eskiOdeme['odeme_dosyalar'];
                    if (file_exists($dosyaYolu)) unlink($dosyaYolu);
                }
                
                echo json_encode(['success' => true, 'message' => 'Ödeme silindi!']);
                break;
                
            case 'delete_file':
                $filePath = $_POST['file_path'] ?? '';
                if ($filePath && file_exists('../' . str_replace('Admin/', '', $filePath))) {
                    unlink('../' . str_replace('Admin/', '', $filePath));
                }
                echo json_encode(['success' => true]);
                break;
                
            default:
                echo json_encode(['success' => false, 'message' => 'Geçersiz işlem!']);
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
    <link rel="stylesheet" href="/Admin/assets/css/Adminlte.min.css">
    <link href="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css" rel="stylesheet" />
    <link href="https://cdn.jsdelivr.net/npm/select2-bootstrap-5-theme@1.3.0/dist/select2-bootstrap-5-theme.min.css" rel="stylesheet" />
    <link href="https://cdn.jsdelivr.net/npm/sweetalert2@11/dist/sweetalert2.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.datatables.net/1.13.7/css/dataTables.bootstrap5.min.css">
    <link rel="stylesheet" href="/Admin/assets/css/custom.css">
    <style>
        .hareket-row .form-select, .hareket-row .form-control {
            font-size: 0.85rem;
            padding: 0.25rem 0.5rem;
        }
        .hareket-row td {
            padding: 0.25rem !important;
            vertical-align: middle;
        }
        .btn-remove-row {
            padding: 0.15rem 0.4rem;
        }
        .sticky-header {
            position: sticky;
            top: 0;
            background: #fff;
            z-index: 10;
        }
        #hareketlerTable {
            font-size: 0.85rem;
        }
        .dosya-item {
            display: inline-flex;
            align-items: center;
            background: #f8f9fa;
            border: 1px solid #dee2e6;
            border-radius: 4px;
            padding: 4px 8px;
            margin: 2px;
        }
        .dosya-item .btn-close {
            font-size: 0.6rem;
            margin-left: 8px;
        }
        /* Select2 alanlarında Bootstrap is-invalid görünümü */
        select.is-invalid + .select2 .select2-selection {
            border-color: #dc3545;
        }
        #hareketlerTable .is-invalid {
            background-color: #fff5f5;
        }
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
                                <li class="breadcrumb-item"><a href="/admin/<?= $backPage ?>"><?= match($cariTipiId) { 1 => 'Sözleşme Yönetimi', 4 => 'Suç Duyurusu Takip', default => 'Hukuk Yönetimi' } ?></a></li>
                                <li class="breadcrumb-item active"><?= htmlspecialchars($pageTitle) ?></li>
                            </ol>
                        </div>
                    </div>
                </div>
            </div>
            
            <div class="app-content">
                <div class="container-fluid">
                    <form id="hukukForm" enctype="multipart/form-data">
                        <input type="hidden" name="takip_id" value="<?= $editId ?>">
                        <input type="hidden" name="cari_id" id="cari_id" value="<?= $takip['takip_cari_id'] ?? 0 ?>">
                        <input type="hidden" name="sozlesme_id" value="<?= $sozlesmeId ?>">
                        <input type="hidden" name="mevcut_dosyalar" id="mevcut_dosyalar" value='<?= $sozlesme ? htmlspecialchars($sozlesme['sozlesme_dosyalar'] ?? '[]') : '[]' ?>'>
                        
                        <!-- ========================================= -->
                        <!-- 1. MÜŞTERİ BİLGİLERİ KARTI -->
                        <!-- ========================================= -->
                        <div class="card card-info card-outline mb-3">
                            <div class="card-header d-flex justify-content-between align-items-center">
                                <h3 class="card-title">
                                    <i class="bi bi-person-vcard"></i> Müşteri Bilgileri
                                    <?php if ($isEdit): ?>
                                    <small class="text-muted ms-2" id="seciliCariAdi">(Cari ID: <?= $takip['takip_cari_id'] ?? '-' ?>)</small>
                                    <?php endif; ?>
                                </h3>
                                <button type="button" class="btn btn-sm btn-outline-primary" onclick="cariAraModalAc()">
                                    <i class="bi bi-search"></i> Mevcut Cari Seç
                                </button>
                            </div>
                            <div class="card-body">
                                <div class="row g-3">
                                    <div class="col-md-4">
                                        <label class="form-label">Müşteri Adı <span class="text-danger">*</span></label>
                                        <input type="text" class="form-control" name="musteri_adi" id="musteri_adi" required
                                               value="<?= htmlspecialchars($takip['cari_adi'] ?? '') ?>">
                                    </div>
                                    <div class="col-md-4">
                                        <label class="form-label">Ünvan</label>
                                        <input type="text" class="form-control" name="musteri_unvan" id="musteri_unvan"
                                               value="<?= htmlspecialchars($takip['cari_unvan'] ?? '') ?>">
                                    </div>
                                    <div class="col-md-2">
                                        <label class="form-label">Vergi Dairesi</label>
                                        <input type="text" class="form-control" name="musteri_vergi_dairesi" id="musteri_vergi_dairesi"
                                               value="<?= htmlspecialchars($takip['cari_vergi_dairesi'] ?? '') ?>">
                                    </div>
                                    <div class="col-md-2">
                                        <label class="form-label">Vergi No</label>
                                        <input type="text" class="form-control" name="musteri_vergi_no" id="musteri_vergi_no"
                                               value="<?= htmlspecialchars($takip['cari_vergi_no'] ?? '') ?>">
                                    </div>
                                    <div class="col-md-2">
                                        <label class="form-label">Telefon</label>
                                        <input type="text" class="form-control" name="musteri_telefon" id="musteri_telefon"
                                               value="<?= htmlspecialchars($takip['cari_telefon'] ?? '') ?>" maxlength="10" placeholder="5XX XXX XX XX">
                                    </div>
                                    <div class="col-md-3">
                                        <label class="form-label">Yetkili Adı Soyadı</label>
                                        <input type="text" class="form-control" name="musteri_yetkili_adi" id="musteri_yetkili_adi"
                                               value="<?= htmlspecialchars($takip['cari_yetkili_adi'] ?? '') ?>">
                                    </div>
                                    <div class="col-md-2">
                                        <label class="form-label">Yetkili Telefon</label>
                                        <input type="text" class="form-control" name="musteri_yetkili_telefon" id="musteri_yetkili_telefon"
                                               value="<?= htmlspecialchars($takip['cari_yetkili_telefon'] ?? '') ?>" maxlength="10" placeholder="5XX XXX XX XX">
                                    </div>
                                    <div class="col-md-2">
                                        <label class="form-label">Şehir <span class="text-danger">*</span></label>
                                        <select class="form-select" name="musteri_sehir" id="musteri_sehir" required>
                                            <option value="">Seçiniz...</option>
                                            <?php foreach ($sehirler as $sehir): ?>
                                                <option value="<?= $sehir['SehirId'] ?>" <?= ($takip && $takip['cari_sehirler'] == $sehir['SehirId']) ? 'selected' : '' ?>>
                                                    <?= htmlspecialchars($sehir['SehirAdi']) ?>
                                                </option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                    <div class="col-md-2">
                                        <label class="form-label">İlçe</label>
                                        <select class="form-select" name="musteri_ilce" id="musteri_ilce">
                                            <option value="">Seçiniz...</option>
                                            <?php foreach ($ilceler as $ilce): ?>
                                                <option value="<?= $ilce['ilceId'] ?>" <?= ($takip && $takip['cari_ilceler'] == $ilce['ilceId']) ? 'selected' : '' ?>>
                                                    <?= htmlspecialchars($ilce['IlceAdi']) ?>
                                                </option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                    <div class="col-md-5">
                                        <label class="form-label">Adres</label>
                                        <input type="text" class="form-control" name="musteri_adres" id="musteri_adres"
                                               value="<?= htmlspecialchars($takip['cari_adres'] ?? '') ?>">
                                    </div>
                                </div>
                            </div>
                        </div>
                        
                        <!-- ========================================= -->
                        <!-- 2. HUKUK TAKİP BİLGİLERİ KARTI -->
                        <!-- ========================================= -->
                        <div class="card card-danger card-outline mb-3">
                            <div class="card-header">
                                <h3 class="card-title">
                                    <i class="bi bi-briefcase"></i> Takip Bilgileri
                                </h3>
                            </div>
                            <div class="card-body">
                                <div class="row g-3">
                                    <div class="col-md-3">
                                        <label class="form-label">Taraf</label>
                                        <select class="form-select" name="taraf_id" id="taraf_id">
                                            <option value="">Seçiniz...</option>
                                            <?php foreach ($taraflar as $taraf): ?>
                                                <option value="<?= $taraf['taraf_id'] ?>" <?= ($takip && $takip['takip_taraf_id'] == $taraf['taraf_id']) ? 'selected' : '' ?>>
                                                    <?= htmlspecialchars($taraf['taraf_ad']) ?>
                                                </option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                    <?php if ($cariTipiId != 4): ?>
                                    <div class="col-md-2">
                                        <label class="form-label">İcra Dairesi</label>
                                        <select class="form-select" name="icra_dairesi_id" id="icra_dairesi_id">
                                            <option value="">Seçiniz...</option>
                                            <?php foreach ($icraDaireleri as $daire): ?>
                                                <option value="<?= $daire['icra_dairesi_id'] ?>" <?= ($takip && $takip['takip_icra_dairesi_id'] == $daire['icra_dairesi_id']) ? 'selected' : '' ?>>
                                                    <?= htmlspecialchars($daire['icra_dairesi_ad']) ?>
                                                </option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                    <?php endif; ?>
                                    <?php if ($cariTipiId == 4): ?>
                                    <div class="col-md-2">
                                        <label class="form-label">Savcılık</label>
                                        <select class="form-select" name="savcilik_id" id="savcilik_id">
                                            <option value="">Seçiniz...</option>
                                            <?php foreach ($savciliklar as $savcilik): ?>
                                                <option value="<?= $savcilik['savcilik_id'] ?>" <?= ($takip && $takip['takip_savcilik_id'] == $savcilik['savcilik_id']) ? 'selected' : '' ?>>
                                                    <?= htmlspecialchars($savcilik['savcilik_ad']) ?>
                                                </option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                    <div class="col-md-2">
                                        <label class="form-label">Tespit Türü</label>
                                        <select class="form-select" name="tespit_turu" id="tespit_turu">
                                            <option value="">Seçiniz...</option>
                                            <option value="İHBAR KONUM" <?= ($takip && ($takip['takip_tespit_turu'] ?? '') == 'İHBAR KONUM') ? 'selected' : '' ?>>İHBAR KONUM</option>
                                            <option value="İMZALI TUTANAK" <?= ($takip && ($takip['takip_tespit_turu'] ?? '') == 'İMZALI TUTANAK') ? 'selected' : '' ?>>İMZALI TUTANAK</option>
                                            <option value="İMZASIZ TUTANAK" <?= ($takip && ($takip['takip_tespit_turu'] ?? '') == 'İMZASIZ TUTANAK') ? 'selected' : '' ?>>İMZASIZ TUTANAK</option>
                                            <option value="GÖRSEL YOK" <?= ($takip && ($takip['takip_tespit_turu'] ?? '') == 'GÖRSEL YOK') ? 'selected' : '' ?>>GÖRSEL YOK</option>
                                        </select>
                                    </div>
                                    <div class="col-md-2">
                                        <label class="form-label">Tespit Tarihi</label>
                                        <input type="date" class="form-control" name="tespit_tarihi" id="tespit_tarihi"
                                               value="<?= htmlspecialchars($takip['takip_tespit_tarihi'] ?? '') ?>">
                                    </div>
                                    <?php endif; ?>
                                    <div class="col-md-2">
                                        <label class="form-label">Statü</label>
                                        <select class="form-select" name="statu_id" id="statu_id">
                                            <option value="">Seçiniz...</option>
                                            <?php foreach ($statuler as $statu): ?>
                                                <option value="<?= $statu['statu_id'] ?>" data-urun-zorunlu="<?= intval($statu['statu_urun_zorunlu']) ?>" <?= ($takip && $takip['takip_statu_id'] == $statu['statu_id']) ? 'selected' : '' ?>>
                                                    <?= htmlspecialchars($statu['statu_ad']) ?>
                                                </option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                    <div class="col-md-2">
                                        <label class="form-label">Dosya No</label>
                                        <input type="text" class="form-control" name="dosya_no" id="dosya_no"
                                               value="<?= htmlspecialchars($takip['takip_dosya_no'] ?? '') ?>">
                                    </div>
                                    <?php if ($cariTipiId == 3): ?>
                                    <div class="col-md-2">
                                        <label class="form-label">Talimat Dosya No</label>
                                        <input type="text" class="form-control" name="talimat_dosya_no" id="talimat_dosya_no"
                                               value="<?= htmlspecialchars($takip['takip_talimat_dosya_no'] ?? '') ?>">
                                    </div>
                                    <?php endif; ?>
                                    <div class="col-md-2">
                                        <label class="form-label">Dosya Açılış Tarihi</label>
                                        <input type="datetime-local" class="form-control" name="dosya_acilis_tarihi" id="dosya_acilis_tarihi"
                                               value="<?= ($takip && !empty($takip['takip_dosya_acilis_tarihi'])) ? str_replace(' ', 'T', $takip['takip_dosya_acilis_tarihi']) : '' ?>">
                                    </div>
                                    <?php if ($cariTipiId == 3): ?>
                                    <div class="col-md-2">
                                        <label class="form-label">Haciz Randevu Tarihi</label>
                                        <input type="datetime-local" class="form-control" name="haciz_randevu_tarihi" id="haciz_randevu_tarihi"
                                               value="<?= ($takip && !empty($takip['takip_haciz_randevu_tarihi'])) ? str_replace(' ', 'T', $takip['takip_haciz_randevu_tarihi']) : '' ?>">
                                    </div>
                                    <?php endif; ?>

                                    <div class="col-md-2">
                                        <label class="form-label">Durum</label>
                                        <select class="form-select" name="takip_durum" id="takip_durum">
                                            <option value="1" <?= (!$takip || $takip['Durum'] == 1) ? 'selected' : '' ?>>Aktif</option>
                                            <option value="0" <?= ($takip && $takip['Durum'] == 0) ? 'selected' : '' ?>>Pasif</option>
                                        </select>
                                    </div>
                                    <div class="col-md-2 d-flex align-items-end">
                                        <div>
                                            <div class="form-check form-switch mb-1">
                                                <input class="form-check-input" type="checkbox" role="switch"
                                                       name="odeme_taahhutu_alindi" id="odeme_taahhutu_alindi" value="1"
                                                       <?= ($takip && !empty($takip['takip_odeme_taahhutu_alindi'])) ? 'checked' : '' ?>>
                                                <label class="form-check-label fw-semibold" for="odeme_taahhutu_alindi">Ödeme Taahhütü Alındı</label>
                                            </div>
                                            <div class="form-check form-switch mb-2">
                                                <input class="form-check-input" type="checkbox" role="switch"
                                                       name="icraya_gidildi" id="icraya_gidildi" value="1"
                                                       <?= ($takip && !empty($takip['takip_icraya_gidildi'])) ? 'checked' : '' ?>>
                                                <label class="form-check-label fw-semibold" for="icraya_gidildi">Hacze Gidildi</label>
                                            </div>
                                        </div>
                                    </div>
                                    <div class="col-md-2">
                                        <label class="form-label">Ana Tutar</label>
                                        <input type="text" class="form-control text-end" name="ana_tutar" id="ana_tutar"
                                               value="<?= $takip ? number_format($takip['takip_ana_tutar'], 2, ',', '.') : '' ?>" placeholder="0,00">
                                    </div>
                                    <div class="col-md-2">
                                        <label class="form-label">İşleyen Faiz</label>
                                        <input type="text" class="form-control text-end" id="isleyen_faiz"
                                               value="<?= $takip ? number_format($takip['takip_Isleyen_Faiz'], 2, ',', '.') : '' ?>" placeholder="0,00" disabled>
                                    </div>
                                    <div class="col-md-2">
                                        <label class="form-label">İşlemiş Faiz</label>
                                        <input type="text" class="form-control text-end" name="islemis_faiz" id="islemis_faiz"
                                               value="<?= $takip ? number_format($takip['takip_Islemis_Faiz'], 2, ',', '.') : '' ?>" placeholder="0,00">
                                    </div>
                                    <div class="col-md-2">
                                        <label class="form-label">Vekalet Ücreti</label>
                                        <input type="text" class="form-control text-end" name="vekalet_ucreti" id="vekalet_ucreti"
                                               value="<?= $takip ? number_format($takip['takip_Vekalet_Ucreti'], 2, ',', '.') : '' ?>" placeholder="0,00">
                                    </div>
                                    <div class="col-md-2">
                                        <label class="form-label">Masraf</label>
                                        <input type="text" class="form-control text-end" name="masraf" id="masraf"
                                               value="<?= $takip ? number_format($takip['takip_Masraf'], 2, ',', '.') : '' ?>" placeholder="0,00">
                                    </div>
                                    <div class="col-md-2">
                                        <label class="form-label">Tahsil Harcı</label>
                                        <input type="text" class="form-control text-end" name="tahsil_harci" id="tahsil_harci"
                                               value="<?= $takip ? number_format($takip['takip_Tahsil_Harci'], 2, ',', '.') : '' ?>" placeholder="0,00">
                                    </div>
                                    <div class="col-md-2">
                                        <label class="form-label fw-bold text-primary">Toplam Alacak</label>
                                        <input type="text" class="form-control text-end fw-bold" id="toplam_alacak"
                                               value="<?php
                                                    if ($takip) {
                                                        $toplamAlacak = floatval($takip['takip_ana_tutar'] ?? 0)
                                                            + floatval($takip['takip_Isleyen_Faiz'] ?? 0)
                                                            + floatval($takip['takip_Islemis_Faiz'] ?? 0)
                                                            + floatval($takip['takip_Vekalet_Ucreti'] ?? 0)
                                                            + floatval($takip['takip_Masraf'] ?? 0)
                                                            + floatval($takip['takip_Tahsil_Harci'] ?? 0);
                                                        echo number_format($toplamAlacak, 2, ',', '.');
                                                    }
                                               ?>" placeholder="0,00" disabled>
                                    </div>
                                    <div class="col-md-8">
                                        <label class="form-label">Açıklama</label>
                                        <textarea class="form-control" name="takip_aciklama" id="takip_aciklama" rows="3" style="resize: vertical;"><?= htmlspecialchars($takip['takip_aciklama'] ?? '') ?></textarea>
                                    </div>
                                </div>
                            </div>
                        </div>
                        
                        <?php if ($cariTipiId == 3): ?>
                        <!-- ========================================= -->
                        <!-- 2B. HACİZ MASRAFLARI KARTI -->
                        <!-- ========================================= -->
                        <div class="card card-warning card-outline mb-3">
                            <div class="card-header">
                                <h3 class="card-title">
                                    <i class="bi bi-cash-coin"></i> Haciz Masrafları
                                    <small class="text-muted ms-2">Genel masraftan bağımsız haciz gider ve tahsilat takibi</small>
                                </h3>
                            </div>
                            <div class="card-body">
                                <div class="row g-3">
                                    <div class="col-md-2">
                                        <label class="form-label">Haciz Harcı</label>
                                        <div class="input-group">
                                            <input type="text" class="form-control text-end haciz-masraf-input" name="haciz_harci" id="haciz_harci"
                                                   value="<?= $takip ? number_format(floatval($takip['takip_haciz_harci'] ?? 0), 2, ',', '.') : '0,00' ?>" placeholder="0,00">
                                            <span class="input-group-text">₺</span>
                                        </div>
                                    </div>
                                    <div class="col-md-2">
                                        <label class="form-label">Araç Ücreti</label>
                                        <div class="input-group">
                                            <input type="text" class="form-control text-end haciz-masraf-input" name="arac_ucreti" id="arac_ucreti"
                                                   value="<?= $takip ? number_format(floatval($takip['takip_arac_ucreti'] ?? 0), 2, ',', '.') : '0,00' ?>" placeholder="0,00">
                                            <span class="input-group-text">₺</span>
                                        </div>
                                    </div>
                                    <div class="col-md-2">
                                        <label class="form-label">Tevkil Ücreti</label>
                                        <div class="input-group">
                                            <input type="text" class="form-control text-end haciz-masraf-input" name="tevkil_ucreti" id="tevkil_ucreti"
                                                   value="<?= $takip ? number_format(floatval($takip['takip_tevkil_ucreti'] ?? 0), 2, ',', '.') : '0,00' ?>" placeholder="0,00">
                                            <span class="input-group-text">₺</span>
                                        </div>
                                    </div>
                                    <div class="col-md-2">
                                        <label class="form-label fw-bold text-warning">Toplam Haciz Masrafı</label>
                                        <div class="input-group">
                                            <input type="text" class="form-control text-end fw-bold" id="toplam_haciz_masraf"
                                                   value="<?php
                                                        $th = floatval($takip['takip_haciz_harci'] ?? 0) + floatval($takip['takip_arac_ucreti'] ?? 0) + floatval($takip['takip_tevkil_ucreti'] ?? 0);
                                                        echo number_format($th, 2, ',', '.');
                                                   ?>" placeholder="0,00" disabled>
                                            <span class="input-group-text">₺</span>
                                        </div>
                                    </div>
                                    <div class="col-md-2">
                                        <label class="form-label fw-bold text-success">Haciz Tahsilatı</label>
                                        <div class="input-group">
                                            <input type="text" class="form-control text-end" name="haciz_tahsilati" id="haciz_tahsilati"
                                                   value="<?= $takip ? number_format(floatval($takip['takip_haciz_tahsilati'] ?? 0), 2, ',', '.') : '0,00' ?>" placeholder="0,00">
                                            <span class="input-group-text">₺</span>
                                        </div>
                                    </div>
                                    <div class="col-md-2">
                                        <label class="form-label fw-bold text-<?= ($takip && floatval($takip['takip_haciz_tahsilati'] ?? 0) >= (floatval($takip['takip_haciz_harci'] ?? 0) + floatval($takip['takip_arac_ucreti'] ?? 0) + floatval($takip['takip_tevkil_ucreti'] ?? 0))) ? 'success' : 'danger' ?>">Karlılık</label>
                                        <div class="input-group">
                                            <input type="text" class="form-control text-end fw-bold" id="haciz_karlilik"
                                                   value="<?php
                                                        $karlilik = floatval($takip['takip_haciz_tahsilati'] ?? 0) - $th;
                                                        echo ($karlilik >= 0 ? '+' : '') . number_format($karlilik, 2, ',', '.');
                                                   ?>" placeholder="0,00" disabled>
                                            <span class="input-group-text">₺</span>
                                        </div>
                                    </div>
                                </div>

                                <!-- Haciz Masrafı Evrakları -->
                                <div class="row g-3 mt-1">
                                    <div class="col-md-4">
                                        <label class="form-label"><i class="bi bi-paperclip"></i> Haciz Evrakları</label>
                                        <input type="file" class="form-control" name="haciz_dosyalar[]" id="haciz_dosyalar" multiple
                                               accept=".pdf,.jpg,.jpeg,.png,.doc,.docx,.xls,.xlsx">
                                        <small class="text-muted">Birden fazla seçilebilir · PDF, JPG, PNG, DOC(X), XLS(X)</small>
                                        <input type="hidden" name="haciz_silinen_dosyalar" id="haciz_silinen_dosyalar" value="[]">
                                    </div>
                                    <div class="col-md-8">
                                        <label class="form-label d-block">&nbsp;</label>
                                        <div id="hacizYeniDosyalar"></div>
                                        <div id="hacizMevcutDosyalar">
                                            <?php foreach ($hacizDosyalari as $hd):
                                                $hdExt = strtolower(pathinfo($hd['HukukHacizDosyalari_DosyaAdi'], PATHINFO_EXTENSION));
                                                $hdIkon = match(true) {
                                                    $hdExt === 'pdf' => 'bi-file-earmark-pdf text-danger',
                                                    in_array($hdExt, ['jpg', 'jpeg', 'png']) => 'bi-file-earmark-image text-primary',
                                                    in_array($hdExt, ['xls', 'xlsx']) => 'bi-file-earmark-excel text-success',
                                                    default => 'bi-file-earmark-text',
                                                };
                                            ?>
                                                <span class="dosya-item" title="Yüklenme: <?= htmlspecialchars($hd['OlusturmaTarihi']) ?>">
                                                    <i class="bi <?= $hdIkon ?> me-1"></i>
                                                    <a href="/admin/assets/uploads/haciz_dosyalar/<?= rawurlencode($hd['HukukHacizDosyalari_DosyaAdi']) ?>" target="_blank"><?= htmlspecialchars($hd['HukukHacizDosyalari_OrijinalAd']) ?></a>
                                                    <button type="button" class="btn-close" onclick="removeHacizDosya(<?= (int)$hd['HukukHacizDosyalari_id'] ?>, this)"></button>
                                                </span>
                                            <?php endforeach; ?>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                        <?php endif; ?>

                        <!-- ========================================= -->
                        <!-- 3. SÖZLEŞME BİLGİLERİ KARTI -->
                        <!-- ========================================= -->
                        <div class="card card-primary card-outline mb-3">
                            <div class="card-header">
                                <h3 class="card-title">
                                    <i class="bi bi-file-earmark-text"></i> Sözleşme Bilgileri
                                </h3>
                            </div>
                            <div class="card-body">
                                <div class="row g-3">
                                    <div class="col-md-2">
                                        <label class="form-label">Sezon</label>
                                        <select class="form-select" name="sezon_id" id="sezon_id">
                                            <option value="">Seçiniz...</option>
                                            <?php foreach ($sezonlar as $sezon): ?>
                                                <?php
                                                    if ($sozlesme) {
                                                        // Duzenleme: kayıtlı sezon secili
                                                        $sezonSecili = ($sozlesme['sozlesme_sezon_id'] == $sezon['sezon_id']);
                                                    } else {
                                                        // Yeni kayit: varsayılan sezon (yoksa tek sezon varsa o) secili
                                                        $sezonSecili = ($sezon['sezon_varsayilan'] == 1) || (count($sezonlar) === 1);
                                                    }
                                                ?>
                                                <option value="<?= $sezon['sezon_id'] ?>" <?= $sezonSecili ? 'selected' : '' ?>>
                                                    <?= htmlspecialchars($sezon['sezon_ad']) ?>
                                                </option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                    <div class="col-md-2">
                                        <label class="form-label">Tarih</label>
                                        <input type="date" class="form-control" name="sozlesme_tarih" id="sozlesme_tarih"
                                               value="<?= $sozlesme && $sozlesme['sozlesme_tarih'] ? $sozlesme['sozlesme_tarih'] : date('Y-m-d') ?>">
                                    </div>
                                    <div class="col-md-2">
                                        <label class="form-label">Sözleşme No</label>
                                        <input type="text" class="form-control" name="sozlesme_no" id="sozlesme_no"
                                               value="<?= htmlspecialchars($sozlesme['sozlesme_no'] ?? '') ?>">
                                    </div>
                                    <div class="col-md-2">
                                        <label class="form-label">Sözleşme Evrağı</label>
                                        <input type="file" class="form-control" name="dosyalar[]" id="dosyalar" multiple>
                                        <small class="text-muted">PDF, DOC, DOCX, JPG, PNG</small>
                                    </div>
                                    <div class="col-md-2">
                                        <label class="form-label">Fatura No</label>
                                        <input type="text" class="form-control" name="fatura_no" id="fatura_no"
                                               value="<?= htmlspecialchars($sozlesme['sozlesme_fatura_no'] ?? '') ?>">
                                    </div>
                                    <div class="col-md-2">
                                        <label class="form-label">Fatura Dosyası</label>
                                        <input type="file" class="form-control" name="fatura_dosya" id="fatura_dosya" accept=".pdf,.jpg,.jpeg,.png">
                                        <input type="hidden" name="mevcut_fatura_dosya" id="mevcut_fatura_dosya" value="<?= htmlspecialchars($sozlesme['sozlesme_fatura_dosya'] ?? '') ?>">
                                        <?php if (!empty($sozlesme['sozlesme_fatura_dosya'])): ?>
                                            <small class="mt-1 d-block">
                                                <a href="/<?= htmlspecialchars($sozlesme['sozlesme_fatura_dosya']) ?>" target="_blank" class="text-primary">
                                                    <i class="bi bi-file-earmark-pdf"></i> Mevcut Fatura
                                                </a>
                                                <a href="#" class="text-danger ms-2" onclick="clearFaturaDosya(event)">
                                                    <i class="bi bi-x-circle"></i>
                                                </a>
                                            </small>
                                        <?php endif; ?>
                                    </div>
                                    <div class="col-md-3">
                                        <label class="form-label">Personel</label>
                                        <select class="form-select" name="personel_id" id="personel_id">
                                            <option value="">Seçiniz...</option>
                                            <?php foreach ($personeller as $personel): ?>
                                                <option value="<?= $personel['kullanici_id'] ?>" <?= ($sozlesme && $sozlesme['sozlesme_personel_id'] == $personel['kullanici_id']) ? 'selected' : '' ?>>
                                                    <?= htmlspecialchars($personel['personel_adi']) ?>
                                                </option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                    <div class="col-md-5">
                                        <label class="form-label">Sözleşme Açıklama</label>
                                        <input type="text" class="form-control" name="sozlesme_aciklama" id="sozlesme_aciklama"
                                               value="<?= htmlspecialchars($sozlesme['sozlesme_aciklama'] ?? '') ?>">
                                    </div>
                                    <div class="col-md-2">
                                        <label class="form-label">Sözleşme Durum</label>
                                        <select class="form-select" name="sozlesme_durum" id="sozlesme_durum">
                                            <option value="1" <?= (!$sozlesme || ($sozlesme['sozlesme_durum'] ?? 1) == 1) ? 'selected' : '' ?>>Aktif</option>
                                            <option value="0" <?= ($sozlesme && ($sozlesme['sozlesme_durum'] ?? 1) == 0) ? 'selected' : '' ?>>Pasif</option>
                                        </select>
                                    </div>
                                </div>
                                
                                <!-- Mevcut Dosyalar -->
                                <div class="row mt-2">
                                    <div class="col-12">
                                        <div id="mevcutDosyalarContainer">
                                            <?php 
                                            if ($sozlesme && $sozlesme['sozlesme_dosyalar']) {
                                                $dosyaList = json_decode($sozlesme['sozlesme_dosyalar'], true) ?: [];
                                                foreach ($dosyaList as $dosya):
                                                    $fileName = basename($dosya);
                                            ?>
                                                <span class="dosya-item">
                                                    <a href="/<?= htmlspecialchars($dosya) ?>" target="_blank"><?= htmlspecialchars($fileName) ?></a>
                                                    <button type="button" class="btn-close" onclick="removeDosya('<?= htmlspecialchars($dosya) ?>', this)"></button>
                                                </span>
                                            <?php 
                                                endforeach;
                                            }
                                            ?>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                        
                        <!-- ========================================= -->
                        <!-- 4. ÜRÜN/HİZMET LİSTESİ KARTI -->
                        <!-- ========================================= -->
                        <div class="card card-success card-outline mb-3">
                            <div class="card-header">
                                <h3 class="card-title">
                                    <i class="bi bi-list-check"></i> Ürün/Hizmet Listesi
                                    <span class="badge bg-warning text-dark ms-2 d-none" id="urunZorunluRozet">
                                        <i class="bi bi-exclamation-triangle"></i> Seçili statü için zorunlu
                                    </span>
                                </h3>
                                <div class="card-tools">
                                    <button type="button" class="btn btn-sm btn-success" onclick="addHareketRow()">
                                        <i class="bi bi-plus-lg"></i> Satır Ekle
                                    </button>
                                </div>
                            </div>
                            <div class="card-body p-0">
                                <div class="table-responsive">
                                    <table class="table table-bordered table-striped table-hover table-sm mb-0" id="hareketlerTable">
                                        <thead class="table-light">
                                            <tr>
                                                <th width="120">Üye Tipi</th>
                                                <th width="130">Ticari Grup</th>
                                                <th width="90">Üye No 1</th>
                                                <th width="90">Üye No 2</th>
                                                <th>Ürün/Hizmet</th>
                                                <th width="100">Fiyat</th>
                                                <th width="120">Aktivasyon Tar.</th>
                                                <th width="120">Taahhüt Bitiş</th>
                                                <th width="60">Durum</th>
                                                <th width="40">İşlem</th>
                                            </tr>
                                        </thead>
                                        <tbody id="hareketlerBody">
                                            <?php if (empty($hareketler)): ?>
                                            <tr class="hareket-row" data-row-id="0">
                                                <td>
                                                    <select class="form-select uye-tipi-select" name="uye_tipi_id[]">
                                                        <option value="">Seçiniz</option>
                                                        <?php foreach ($uyeTipleri as $tip): ?>
                                                            <option value="<?= $tip['uye_tipi_id'] ?>"><?= htmlspecialchars($tip['uye_tipi_ad']) ?></option>
                                                        <?php endforeach; ?>
                                                    </select>
                                                </td>
                                                <td>
                                                    <select class="form-select ticari-grup-select" name="ticari_grup_id[]">
                                                        <option value="">Seçiniz</option>
                                                        <?php foreach ($ticariGruplar as $grup): ?>
                                                            <option value="<?= $grup['ticari_grup_id'] ?>"><?= htmlspecialchars($grup['ticari_grup_kod']) ?> - <?= htmlspecialchars($grup['ticari_grup_ad']) ?></option>
                                                        <?php endforeach; ?>
                                                    </select>
                                                </td>
                                                <td><input type="number" class="form-control" name="uye_no_1[]" placeholder="0"></td>
                                                <td><input type="number" class="form-control" name="uye_no_2[]" placeholder="0"></td>
                                                <td>
                                                    <select class="form-select urun-select" name="urun_hizmet_id[]">
                                                        <option value="">Seçiniz</option>
                                                        <?php foreach ($urunler as $urun): ?>
                                                            <option value="<?= $urun['urun_hizmet_id'] ?>" data-hizmet-suresi="<?= intval($urun['hizmetSuresi'] ?? 0) ?>"><?= htmlspecialchars($urun['urun_hizmet_adi']) ?></option>
                                                        <?php endforeach; ?>
                                                    </select>
                                                </td>
                                                <td><input type="number" class="form-control text-end" name="fiyat[]" step="0.01" placeholder="0.00"></td>
                                                <td><input type="date" class="form-control" name="aktivasyon_tarihi[]"></td>
                                                <td><input type="date" class="form-control taahut-bitis-input" name="taahut_bitis[]"></td>
                                                <td class="text-center"><input type="checkbox" class="form-check-input" name="hareket_durum[]" value="1" checked></td>
                                                <td class="text-center">
                                                    <button type="button" class="btn btn-sm btn-outline-danger btn-remove-row" onclick="removeHareketRow(this)">
                                                        <i class="bi bi-x"></i>
                                                    </button>
                                                </td>
                                            </tr>
                                            <?php else: ?>
                                                <?php foreach ($hareketler as $index => $hareket): ?>
                                                <tr class="hareket-row" data-row-id="<?= $hareket['hareket_id'] ?>">
                                                    <td>
                                                        <select class="form-select uye-tipi-select" name="uye_tipi_id[]">
                                                            <option value="">Seçiniz</option>
                                                            <?php foreach ($uyeTipleri as $tip): ?>
                                                                <option value="<?= $tip['uye_tipi_id'] ?>" <?= $hareket['hareket_uye_tipi_id'] == $tip['uye_tipi_id'] ? 'selected' : '' ?>>
                                                                    <?= htmlspecialchars($tip['uye_tipi_ad']) ?>
                                                                </option>
                                                            <?php endforeach; ?>
                                                        </select>
                                                    </td>
                                                    <td>
                                                        <select class="form-select ticari-grup-select" name="ticari_grup_id[]">
                                                            <option value="">Seçiniz</option>
                                                            <?php foreach ($ticariGruplar as $grup): ?>
                                                                <option value="<?= $grup['ticari_grup_id'] ?>" <?= $hareket['hareket_ticari_grup_id'] == $grup['ticari_grup_id'] ? 'selected' : '' ?>>
                                                                    <?= htmlspecialchars($grup['ticari_grup_kod']) ?> - <?= htmlspecialchars($grup['ticari_grup_ad']) ?>
                                                                </option>
                                                            <?php endforeach; ?>
                                                        </select>
                                                    </td>
                                                    <td><input type="number" class="form-control" name="uye_no_1[]" value="<?= $hareket['hareket_uye_no_1'] ?>"></td>
                                                    <td><input type="number" class="form-control" name="uye_no_2[]" value="<?= $hareket['hareket_uye_no_2'] ?>"></td>
                                                    <td>
                                                        <select class="form-select urun-select" name="urun_hizmet_id[]">
                                                            <option value="">Seçiniz</option>
                                                            <?php foreach ($urunler as $urun): ?>
                                                                <option value="<?= $urun['urun_hizmet_id'] ?>" data-hizmet-suresi="<?= intval($urun['hizmetSuresi'] ?? 0) ?>" <?= $hareket['hareket_urun_hizmet_id'] == $urun['urun_hizmet_id'] ? 'selected' : '' ?>>
                                                                    <?= htmlspecialchars($urun['urun_hizmet_adi']) ?>
                                                                </option>
                                                            <?php endforeach; ?>
                                                        </select>
                                                    </td>
                                                    <td><input type="number" class="form-control text-end" name="fiyat[]" step="0.01" value="<?= number_format($hareket['hareket_fiyat'], 2, '.', '') ?>"></td>
                                                    <td><input type="date" class="form-control" name="aktivasyon_tarihi[]" value="<?= $hareket['hareket_aktivasyon_tarihi'] ?? '' ?>"></td>
                                                    <td><input type="date" class="form-control taahut-bitis-input" name="taahut_bitis[]" value="<?= $hareket['hareket_taahut_bitis'] ?? '' ?>"></td>
                                                    <td class="text-center"><input type="checkbox" class="form-check-input" name="hareket_durum[]" value="1" <?= $hareket['hareket_durum'] ? 'checked' : '' ?>></td>
                                                    <td class="text-center">
                                                        <button type="button" class="btn btn-sm btn-outline-danger btn-remove-row" onclick="removeHareketRow(this)">
                                                            <i class="bi bi-x"></i>
                                                        </button>
                                                    </td>
                                                </tr>
                                                <?php endforeach; ?>
                                            <?php endif; ?>
                                        </tbody>
                                        <tfoot class="table-light">
                                            <tr>
                                                <td colspan="5" class="text-end fw-bold">Toplam:</td>
                                                <td class="text-end fw-bold" id="toplamTutar">0,00 ₺</td>
                                                <td colspan="4"></td>
                                            </tr>
                                        </tfoot>
                                    </table>
                                </div>
                            </div>
                        </div>
                        
                        <!-- ========================================= -->
                        <!-- 5. ÖDEME YÖNTEMLERİ KARTI -->
                        <!-- ========================================= -->
                        <div class="card card-primary card-outline mb-3">
                            <div class="card-header">
                                <h5 class="card-title mb-0">
                                    <i class="bi bi-credit-card-2-front"></i> Ödeme Yöntemleri
                                </h5>
                                <div class="card-tools">
                                    <button type="button" class="btn btn-sm btn-primary" onclick="addOdemeRow()">
                                        <i class="bi bi-plus"></i> Satır Ekle
                                    </button>
                                </div>
                            </div>
                            <div class="card-body p-0">
                                <div class="table-responsive">
                                    <table class="table table-bordered table-striped mb-0" id="odemeTable">
                                        <thead class="table-light">
                                            <tr>
                                                <th style="width:120px;">Ödeme Tipi</th>
                                                <th style="width:100px;">Belge No</th>
                                                <th style="width:120px;">Vade Tarihi</th>
                                                <th style="width:120px;">Tutar</th>
                                                <th style="width:180px;">Personel/Banka Hesap</th>
                                                <th style="width:150px;">Dosya</th>
                                                <th style="width:230px;">Açıklama</th>
                                                <th style="width:120px;">Durum</th>
                                                <th style="width:120px;" class="text-center">Ödeme Yapıldı</th>
                                                <th style="width:120px;">Ödeme Tarihi</th>
                                                <th style="width:60px;" class="text-center" title="Satırı kilitle — sadece kilitleyen kişi düzenleyebilir"><i class="bi bi-lock"></i></th>
                                                <th style="width:80px;">İşlem</th>
                                            </tr>
                                        </thead>
                                        <tbody id="odemeSatirlar">
                                            <?php if (!empty($odemeler)): ?>
                                                <?php
                                                    $isAdminUser = ($user['departman_id'] == 1 || $user['departman_id'] == 22);
                                                ?>
                                                <?php foreach ($odemeler as $odeme):
                                                    $kilitliMi = !empty($odeme['odeme_kilit_kullanici_id']);
                                                    $benimKilidigim = $kilitliMi && $odeme['odeme_kilit_kullanici_id'] == $user['kullanici_id'];
                                                    $baskasiKilitledi = $kilitliMi && !$benimKilidigim;
                                                    $kilitleyen = htmlspecialchars($odeme['kilit_kullanici_adi'] ?? '');
                                                    // Admin başkasının kilitli satırını da düzenleyebilir
                                                    $dis = ($baskasiKilitledi && !$isAdminUser) ? 'disabled' : '';
                                                ?>
                                                <tr data-odeme-id="<?= $odeme['odeme_id'] ?>" <?= $baskasiKilitledi ? 'class="table-warning opacity-75"' : '' ?>>
                                                    <td>
                                                        <select class="form-select form-select-sm odeme-tip-select" name="odeme_tip_id[]" onchange="odemeTypeChanged(this)" <?= $dis ?>>
                                                            <option value="">Seçiniz</option>
                                                            <?php foreach ($odemeTipleri as $tip): ?>
                                                                <option value="<?= $tip['odeme_tipi_id'] ?>" 
                                                                    data-varsayılan-durum="<?= $tip['odeme_tipi_varsayilan_durum_id'] ?>"
                                                                    data-dosya-onek="<?= htmlspecialchars($tip['odeme_tipi_dosya_onek']) ?>"
                                                                    <?= $odeme['odeme_tipi_id'] == $tip['odeme_tipi_id'] ? 'selected' : '' ?>>
                                                                    <?= htmlspecialchars($tip['odeme_tipi_ad']) ?>
                                                                </option>
                                                            <?php endforeach; ?>
                                                        </select>
                                                        <input type="hidden" name="odeme_id[]" value="<?= $odeme['odeme_id'] ?>">
                                                    </td>
                                                    <td><input type="text" class="form-control form-control-sm" name="odeme_belge_no[]" value="<?= htmlspecialchars($odeme['odeme_belge_no'] ?? '') ?>" placeholder="Belge No" <?= $dis ?>></td>
                                                    <td><input type="date" class="form-control form-control-sm" name="odeme_vade_tarihi[]" value="<?= $odeme['odeme_vade_tarihi'] ? date('Y-m-d', strtotime($odeme['odeme_vade_tarihi'])) : '' ?>" <?= $dis ?>></td>
                                                    <td><input type="number" class="form-control form-control-sm text-end odeme-tutar" name="odeme_tutar[]" step="0.01" value="<?= number_format($odeme['odeme_tutar'], 2, '.', '') ?>" onchange="calculateOdemeToplam()" <?= $dis ?>></td>
                                                    <td class="hedef-cell">
                                                        <?php
                                                        $hedefTip = '';
                                                        foreach ($odemeDurumlari as $d) {
                                                            if ($d['odeme_durum_id'] == $odeme['odeme_durum_id']) {
                                                                $hedefTip = $d['odeme_durum_hedef_tipi'];
                                                                break;
                                                            }
                                                        }
                                                        ?>
                                                        <select class="form-select form-select-sm personel-select" name="odeme_hedef_personel_id[]" style="<?= $hedefTip == 'personel' ? '' : 'display:none;' ?>" <?= $dis ?>>
                                                            <option value="">Personel Seç</option>
                                                            <?php foreach ($personeller as $p): ?>
                                                                <option value="<?= $p['kullanici_id'] ?>" <?= $odeme['odeme_guncel_personel_id'] == $p['kullanici_id'] ? 'selected' : '' ?>>
                                                                    <?= htmlspecialchars($p['personel_adi']) ?>
                                                                </option>
                                                            <?php endforeach; ?>
                                                        </select>
                                                        <select class="form-select form-select-sm banka-hesap-select" name="odeme_hedef_banka_hesap_id[]" style="<?= $hedefTip == 'banka' ? '' : 'display:none;' ?>" <?= $dis ?>>
                                                            <option value="">Banka Hesap Seç</option>
                                                            <?php foreach ($bankaHesaplari as $bh): ?>
                                                                <option value="<?= $bh['bankaHesap_id'] ?>" <?= $odeme['odeme_guncel_banka_hesap_id'] == $bh['bankaHesap_id'] ? 'selected' : '' ?>>
                                                                    <?= htmlspecialchars($bh['banka_adi'] . ' - ' . ($bh['bankaHesap_sube_adi'] ?: '-')) ?>
                                                                </option>
                                                            <?php endforeach; ?>
                                                        </select>
                                                        <span class="no-hedef-text text-muted" style="<?= empty($hedefTip) ? '' : 'display:none;' ?>">-</span>
                                                    </td>
                                                    <td>
                                                        <?php if (!empty($odeme['odeme_dosyalar'])): ?>
                                                            <?php
                                                                $dosyaAdi = $odeme['odeme_dosyalar'];
                                                                $dosyaExt = strtolower(pathinfo($dosyaAdi, PATHINFO_EXTENSION));
                                                                $dosyaUrl = '/admin/assets/uploads/sozlesme_odemeler/' . htmlspecialchars($dosyaAdi);
                                                            ?>
                                                            <?php if (in_array($dosyaExt, ['jpg','jpeg','png'])): ?>
                                                                <a href="<?= $dosyaUrl ?>" target="_blank">
                                                                    <img src="<?= $dosyaUrl ?>" alt="Ödeme" style="max-width:60px;max-height:40px;object-fit:cover;border-radius:4px;">
                                                                </a>
                                                            <?php else: ?>
                                                                <a href="<?= $dosyaUrl ?>" target="_blank" class="btn btn-sm btn-outline-danger"><i class="bi bi-file-earmark-pdf"></i> PDF</a>
                                                            <?php endif; ?>
                                                        <?php endif; ?>
                                                        <input type="file" class="form-control form-control-sm mt-1" name="odeme_dosya[]" accept=".pdf,.jpg,.jpeg,.png" onchange="previewOdemeDosya(this)" <?= $dis ?>>
                                                        <div class="odeme-dosya-preview mt-1"></div>
                                                        <input type="hidden" name="odeme_mevcut_dosya[]" value="<?= htmlspecialchars($odeme['odeme_dosyalar'] ?? '') ?>">
                                                    </td>
                                                    <td><textarea class="form-control form-control-sm" name="odeme_aciklama[]" rows="2" style="resize:vertical;" placeholder="Açıklama" <?= $dis ?>><?= htmlspecialchars($odeme['odeme_aciklama'] ?? '') ?></textarea></td>
                                                    <td>
                                                        <span class="badge bg-<?= $odeme['odeme_durum_renk'] ?? 'secondary' ?> durum-badge" style="cursor:<?= $baskasiKilitledi ? 'default' : 'pointer' ?>;" <?= $baskasiKilitledi ? '' : 'onclick="showDurumModal(this)"' ?> title="<?= $baskasiKilitledi ? ($kilitleyen . ' tarafından kilitlendi') : 'Durum değiştirmek için tıklayın' ?>"><?= htmlspecialchars($odeme['odeme_durum_ad'] ?? 'Bekliyor') ?></span>
                                                        <input type="hidden" name="odeme_durum_id[]" value="<?= $odeme['odeme_durum_id'] ?>">
                                                    </td>
                                                    <td class="text-center">
                                                        <div class="form-check d-flex justify-content-center">
                                                            <input class="form-check-input" type="checkbox" name="odeme_yapildi[]" value="1" <?= $odeme['odeme_yapildi'] ? 'checked' : '' ?> onchange="calculateOdemeToplam()" <?= $dis ?>>
                                                        </div>
                                                    </td>
                                                    <td><input type="date" class="form-control form-control-sm" name="odeme_tarih[]" value="<?= $odeme['odeme_tarih'] ? date('Y-m-d', strtotime($odeme['odeme_tarih'])) : date('Y-m-d') ?>" <?= $dis ?>></td>
                                                    <td class="text-center">
                                                        <?php if ($baskasiKilitledi && !$isAdminUser): ?>
                                                            <span title="<?= $kilitleyen ?> tarafından kilitlendi" class="text-danger" style="cursor:default;"><i class="bi bi-lock-fill fs-5"></i></span>
                                                        <?php else: ?>
                                                            <input class="form-check-input odeme-kilit-cb" type="checkbox"
                                                                data-odeme-id="<?= $odeme['odeme_id'] ?>"
                                                                title="<?= $kilitliMi ? ($kilitleyen . ' — Kilidi aç') : 'Kilitle' ?>"
                                                                <?= $kilitliMi ? 'checked' : '' ?>
                                                                onchange="toggleOdemeKilit(this)">
                                                            <?php if ($baskasiKilitledi && $isAdminUser): ?>
                                                                <small class="text-danger d-block" style="font-size:10px;" title="<?= $kilitleyen ?> kilitledi"><i class="bi bi-shield-lock"></i></small>
                                                            <?php endif; ?>
                                                        <?php endif; ?>
                                                    </td>
                                                    <td class="text-center">
                                                        <?php if ($baskasiKilitledi && !$isAdminUser): ?>
                                                            <span class="text-muted" title="Kilitli satır silinemez"><i class="bi bi-x opacity-25"></i></span>
                                                        <?php else: ?>
                                                            <button type="button" class="btn btn-sm btn-outline-danger" onclick="removeOdemeRow(this)" title="Sil"><i class="bi bi-x"></i></button>
                                                        <?php endif; ?>
                                                    </td>
                                                </tr>
                                                <?php endforeach; ?>
                                            <?php endif; ?>
                                        </tbody>
                                        <tfoot class="table-light">
                                            <tr>
                                                <td colspan="3" class="text-end fw-bold">Ödeme Toplamı:</td>
                                                <td class="text-end fw-bold" id="odemeToplam">0,00 ₺</td>
                                                <td colspan="8"><small class="text-muted" id="odemeFark"></small></td>
                                            </tr>
                                            <tr>
                                                <td colspan="3" class="text-end fw-bold text-success">Toplam Ödenen:</td>
                                                <td class="text-end fw-bold text-success" id="toplamOdenen">0,00 ₺</td>
                                                <td colspan="8"></td>
                                            </tr>
                                            <tr>
                                                <td colspan="3" class="text-end fw-bold text-danger">Toplam Ödenecek:</td>
                                                <td class="text-end fw-bold text-danger" id="toplamOdenecek">0,00 ₺</td>
                                                <td colspan="8"></td>
                                            </tr>
                                        </tfoot>
                                    </table>
                                </div>
                            </div>
                        </div>
                        
                        <!-- Form Butonları -->
                        <div class="card card-outline mb-3">
                            <div class="card-body">
                                <div class="d-flex justify-content-between">
                                    <a href="/admin/<?= $backPage ?>" class="btn btn-secondary">
                                        <i class="bi bi-arrow-left"></i> Geri
                                    </a>
                                    <div>
                                        <?php if ($isEdit && $pagePermissions['can_delete']): ?>
                                        <button type="button" class="btn btn-danger" onclick="formKayitSil('/admin/<?= $backPage ?>', { action: 'delete', id: <?= $editId ?> }, '/admin/<?= $backPage ?>')">
                                            <i class="bi bi-trash"></i> Sil
                                        </button>
                                        <?php endif; ?>
                                        <button type="submit" class="btn btn-primary">
                                            <i class="bi bi-check-lg"></i> Kaydet
                                        </button>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </form>
                </div>
            </div>
        </main>
        
        <?php include __DIR__ . '/../includes/footer.php'; ?>
    </div>

    <!-- ========================================= -->
    <!-- CARİ ARA MODAL -->
    <!-- ========================================= -->
    <div class="modal fade" id="cariAraModal" tabindex="-1" aria-labelledby="cariAraModalLabel" aria-hidden="true">
        <div class="modal-dialog modal-xl modal-dialog-scrollable">
            <div class="modal-content">
                <div class="modal-header bg-primary text-white">
                    <h5 class="modal-title" id="cariAraModalLabel">
                        <i class="bi bi-search"></i> Mevcut Cari Seç
                    </h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="row mb-3">
                        <div class="col-md-6">
                            <div class="input-group">
                                <input type="text" class="form-control" id="cariAraInput" placeholder="Ad, ünvan, vergi no veya telefon ile ara...">
                                <button class="btn btn-primary" type="button" onclick="cariAra()">
                                    <i class="bi bi-search"></i> Ara
                                </button>
                            </div>
                        </div>
                        <div class="col-md-6 text-muted small d-flex align-items-center">
                            <i class="bi bi-info-circle me-1"></i> Seçtiğiniz cari, form alanlarını dolduracak<?php if ($isEdit): ?> ve mevcut takip/sözleşme kayıtlarına bağlanacak<?php endif; ?>.
                        </div>
                    </div>
                    <div class="table-responsive">
                        <table class="table table-hover table-sm table-bordered" id="cariAraTable">
                            <thead class="table-light">
                                <tr>
                                    <th>Cari Adı</th>
                                    <th>Ünvan</th>
                                    <th>Vergi No</th>
                                    <th>Telefon</th>
                                    <th>Şehir</th>
                                    <th>Tip</th>
                                    <th style="width:80px"></th>
                                </tr>
                            </thead>
                            <tbody id="cariAraBody">
                                <tr><td colspan="7" class="text-center text-muted py-4"><i class="bi bi-search"></i> Arama yapmak için yukarıdaki kutuyu kullanın</td></tr>
                            </tbody>
                        </table>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Kapat</button>
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/jquery@3.7.1/dist/jquery.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/overlayscrollbars@2.10.1/browser/overlayscrollbars.browser.es6.min.js"></script>
    <script src="/Admin/assets/js/Adminlte.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11/dist/sweetalert2.all.min.js"></script>
    <script src="https://cdn.datatables.net/1.13.7/js/jquery.dataTables.min.js"></script>
    <script src="https://cdn.datatables.net/1.13.7/js/dataTables.bootstrap5.min.js"></script>
    <script src="/Admin/assets/js/custom.js"></script>
    
    <script>
    let rowCounter = <?= count($hareketler) ?>;
    
    // Dropdown verileri (JavaScript için)
    const uyeTipleri = <?= json_encode($uyeTipleri) ?>;
    const ticariGruplar = <?= json_encode($ticariGruplar) ?>;
    const urunler = <?= json_encode($urunler) ?>;
    const odemeTipleri = <?= json_encode($odemeTipleri) ?>;
    const odemeDurumlari = <?= json_encode($odemeDurumlari) ?>;
    const personeller = <?= json_encode($personeller) ?>;
    const bankaHesaplari = <?= json_encode($bankaHesaplari) ?>;
    const parabirimiSimge = '<?= addslashes($parabirimiSimge) ?>';
    
    let odemeRowCounter = <?= count($odemeler) ?>;
    
    $(document).ready(function() {
        // Select2 init
        initSelect2();
        
        // Toplam hesapla
        calculateTotal();
        calculateOdemeToplam();
        calculateToplamAlacak();
        calculateHacizMasraf();

        // Toplam Alacak hesapla (tutar alanları değişince)
        $(document).on('change keyup', '#ana_tutar, #isleyen_faiz, #islemis_faiz, #vekalet_ucreti, #masraf, #tahsil_harci', function() {
            calculateToplamAlacak();
        });

        // Haciz masraf/tahsilat hesapla
        $(document).on('change keyup', '#haciz_harci, #arac_ucreti, #tevkil_ucreti, #haciz_tahsilati', function() {
            calculateHacizMasraf();
        });
        
        // Fiyat değişikliğinde toplam hesapla
        $(document).on('change keyup', 'input[name="fiyat[]"]', function() {
            calculateTotal();
            calculateOdemeToplam();
        });
        
        // Ürün/Hizmet veya Aktivasyon Tarihi değişince Taahhüt Bitiş otomatik hesapla
        $(document).on('change', '.urun-select, input[name="aktivasyon_tarihi[]"]', function() {
            updateTaahutBitisByHizmetSuresi($(this).closest('tr'));
        });
        
        // Taahhüt Bitiş validasyonu
        $(document).on('change', 'input[name="taahut_bitis[]"]', function() {
            const row = $(this).closest('tr');
            const aktivasyonTarihi = row.find('[name="aktivasyon_tarihi[]"]').val();
            const taahutInput = row.find('[name="taahut_bitis[]"]');
            const taahutBitis = taahutInput.val();
            
            if (aktivasyonTarihi) taahutInput.attr('min', aktivasyonTarihi);
            else taahutInput.removeAttr('min');
            
            if (aktivasyonTarihi && taahutBitis && new Date(taahutBitis) < new Date(aktivasyonTarihi)) {
                taahutInput.val(aktivasyonTarihi);
                showToast('Taahhüt Bitiş, Aktivasyon Tarihinden düşük olamaz!', 'warning');
            }
        });
        
        // Şehir değiştiğinde ilçeleri yükle
        $('#musteri_sehir').on('change', function() {
            const sehirId = $(this).val();
            const ilceSelect = $('#musteri_ilce');
            ilceSelect.html('<option value="">Yükleniyor...</option>');
            
            if (!sehirId) {
                ilceSelect.html('<option value="">Seçiniz...</option>');
                return;
            }
            
            $.post('', { action: 'get_ilceler', sehir_id: sehirId }, function(response) {
                let options = '<option value="">Seçiniz...</option>';
                if (response.success && response.data) {
                    response.data.forEach(function(ilce) {
                        options += '<option value="' + ilce.ilceId + '">' + ilce.IlceAdi + '</option>';
                    });
                }
                ilceSelect.html(options);
                // Select2 yenile
                if (ilceSelect.hasClass('select2-hidden-accessible')) {
                    ilceSelect.trigger('change.select2');
                }
            });
        });
        
        // Form submit
        $('#hukukForm').on('submit', function(e) {
            e.preventDefault();
            saveForm();
        });
        
        // Sayfa açılışında mevcut satırları hizmet süresine göre senkronla
        $('#hareketlerBody tr').each(function() {
            updateTaahutBitisByHizmetSuresi($(this));
        });
        
        // Sayfa açılışında mevcut ödeme satırlarının vade tarihi kontrolü
        $('#odemeSatirlar tr').each(function() {
            const tipId = $(this).find('.odeme-tip-select').val();
            const vadeTarihiInput = $(this).find('input[name="odeme_vade_tarihi[]"]');
            if (tipId == 1 || tipId == 4 || tipId == 5) {
                vadeTarihiInput.hide();
            }
        });
    });
    
    function initSelect2() {
        $('#sezon_id, #personel_id, #sozlesme_durum, #taraf_id, #icra_dairesi_id, #savcilik_id, #statu_id, #takip_durum, #tespit_turu, #musteri_sehir, #musteri_ilce').select2({
            theme: 'bootstrap-5',
            width: '100%',
            allowClear: true,
            placeholder: 'Seçiniz...'
        });
        
        $('.uye-tipi-select, .ticari-grup-select, .urun-select').select2({
            theme: 'bootstrap-5',
            width: '100%',
            minimumResultsForSearch: 10
        });
    }
    
    function addMonthsToDate(dateStr, monthsToAdd) {
        if (!dateStr || !monthsToAdd) return '';
        const source = new Date(dateStr + 'T00:00:00');
        if (isNaN(source.getTime())) return '';
        const year = source.getFullYear();
        const month = source.getMonth();
        const day = source.getDate();
        const targetMonthDate = new Date(year, month + monthsToAdd, 1);
        const lastDay = new Date(targetMonthDate.getFullYear(), targetMonthDate.getMonth() + 1, 0).getDate();
        const safeDay = Math.min(day, lastDay);
        const target = new Date(targetMonthDate.getFullYear(), targetMonthDate.getMonth(), safeDay);
        return `${target.getFullYear()}-${String(target.getMonth() + 1).padStart(2, '0')}-${String(target.getDate()).padStart(2, '0')}`;
    }
    
    function updateTaahutBitisByHizmetSuresi(row) {
        const urunSelect = row.find('.urun-select');
        const selectedOption = urunSelect.find('option:selected');
        const hizmetSuresi = parseInt(selectedOption.data('hizmet-suresi') || 0, 10);
        const aktivasyonTarihi = row.find('[name="aktivasyon_tarihi[]"]').val();
        const taahutInput = row.find('[name="taahut_bitis[]"]');
        
        if (aktivasyonTarihi) taahutInput.attr('min', aktivasyonTarihi);
        else taahutInput.removeAttr('min');
        
        if (!aktivasyonTarihi || !hizmetSuresi) return;
        taahutInput.val(addMonthsToDate(aktivasyonTarihi, hizmetSuresi));
    }
    
    // Hareket satır ekle
    function addHareketRow() {
        rowCounter++;
        
        let uyeTipiOptions = '<option value="">Seçiniz</option>';
        uyeTipleri.forEach(tip => { uyeTipiOptions += `<option value="${tip.uye_tipi_id}">${tip.uye_tipi_ad}</option>`; });
        
        let ticariGrupOptions = '<option value="">Seçiniz</option>';
        ticariGruplar.forEach(grup => { ticariGrupOptions += `<option value="${grup.ticari_grup_id}">${grup.ticari_grup_kod} - ${grup.ticari_grup_ad}</option>`; });
        
        let urunOptions = '<option value="">Seçiniz</option>';
        urunler.forEach(urun => { urunOptions += `<option value="${urun.urun_hizmet_id}" data-hizmet-suresi="${parseInt(urun.hizmetSuresi || 0, 10)}">${urun.urun_hizmet_adi}</option>`; });
        
        const newRow = `
            <tr class="hareket-row" data-row-id="new_${rowCounter}">
                <td><select class="form-select uye-tipi-select" name="uye_tipi_id[]">${uyeTipiOptions}</select></td>
                <td><select class="form-select ticari-grup-select" name="ticari_grup_id[]">${ticariGrupOptions}</select></td>
                <td><input type="number" class="form-control" name="uye_no_1[]" placeholder="0"></td>
                <td><input type="number" class="form-control" name="uye_no_2[]" placeholder="0"></td>
                <td><select class="form-select urun-select" name="urun_hizmet_id[]">${urunOptions}</select></td>
                <td><input type="number" class="form-control text-end" name="fiyat[]" step="0.01" placeholder="0.00"></td>
                <td><input type="date" class="form-control" name="aktivasyon_tarihi[]"></td>
                <td><input type="date" class="form-control taahut-bitis-input" name="taahut_bitis[]"></td>
                <td class="text-center"><input type="checkbox" class="form-check-input" name="hareket_durum[]" value="1" checked></td>
                <td class="text-center"><button type="button" class="btn btn-sm btn-outline-danger btn-remove-row" onclick="removeHareketRow(this)"><i class="bi bi-x"></i></button></td>
            </tr>
        `;
        
        $('#hareketlerBody').append(newRow);
        const lastRow = $('#hareketlerBody tr:last');
        lastRow.find('.uye-tipi-select, .ticari-grup-select, .urun-select').select2({
            theme: 'bootstrap-5', width: '100%', minimumResultsForSearch: 10
        });
    }
    
    function removeHareketRow(btn) {
        const row = $(btn).closest('tr');
        if ($('#hareketlerBody tr').length > 1) {
            row.find('.select2-hidden-accessible').select2('destroy');
            row.remove();
            calculateTotal();
        } else {
            showToast('En az bir satır olmalı!', 'warning');
        }
    }
    
    function calculateTotal() {
        let total = 0;
        $('input[name="fiyat[]"]').each(function() { total += parseFloat($(this).val()) || 0; });
        $('#toplamTutar').text(total.toLocaleString('tr-TR', { minimumFractionDigits: 2, maximumFractionDigits: 2 }) + ' ' + parabirimiSimge);
    }
    
    function parseTutar(val) {
        if (!val) return 0;
        return parseFloat(val.replace(/\./g, '').replace(',', '.')) || 0;
    }
    
    function calculateToplamAlacak() {
        let toplam = parseTutar($('#ana_tutar').val())
                   + parseTutar($('#isleyen_faiz').val())
                   + parseTutar($('#islemis_faiz').val())
                   + parseTutar($('#vekalet_ucreti').val())
                   + parseTutar($('#masraf').val())
                   + parseTutar($('#tahsil_harci').val());
        $('#toplam_alacak').val(toplam.toLocaleString('tr-TR', { minimumFractionDigits: 2, maximumFractionDigits: 2 }));
    }

    function calculateHacizMasraf() {
        let masraf = parseTutar($('#haciz_harci').val())
                   + parseTutar($('#arac_ucreti').val())
                   + parseTutar($('#tevkil_ucreti').val());
        let tahsilat = parseTutar($('#haciz_tahsilati').val());
        let karlilik = tahsilat - masraf;
        $('#toplam_haciz_masraf').val(masraf.toLocaleString('tr-TR', { minimumFractionDigits: 2, maximumFractionDigits: 2 }));
        let karlilıkStr = (karlilik >= 0 ? '+' : '') + karlilik.toLocaleString('tr-TR', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
        $('#haciz_karlilik').val(karlilıkStr).removeClass('text-success text-danger').addClass(karlilik >= 0 ? 'text-success' : 'text-danger');
    }
    
    // Seçili statü Ürün/Hizmet girilmesini zorunlu kılıyor mu?
    function statuUrunZorunluMu() {
        return parseInt($('#statu_id').find('option:selected').data('urun-zorunlu') || 0, 10) === 1;
    }

    // Zorunlu statülerde en az bir satırda Ürün/Hizmet ve Fiyat dolu olmalı
    function validateUrunZorunlulugu() {
        $('#hareketlerBody .is-invalid').removeClass('is-invalid');
        if (!statuUrunZorunluMu()) return true;

        const statuAdi = $('#statu_id').find('option:selected').text().trim();
        let gecerliSatir = 0;
        let eksikSatir = false;

        $('#hareketlerBody tr').each(function() {
            const row = $(this);
            const urunSelect = row.find('[name="urun_hizmet_id[]"]');
            const fiyatInput = row.find('[name="fiyat[]"]');
            const urunVal = urunSelect.val();
            const fiyatRaw = (fiyatInput.val() || '').trim();
            const fiyatVal = parseFloat(fiyatRaw);

            // Tamamen boş satır göz ardı edilir
            if (!urunVal && (fiyatRaw === '' || fiyatVal === 0)) return;

            if (!urunVal) {
                urunSelect.addClass('is-invalid');
                eksikSatir = true;
            }
            if (fiyatRaw === '' || isNaN(fiyatVal) || fiyatVal <= 0) {
                fiyatInput.addClass('is-invalid');
                eksikSatir = true;
            }
            if (urunVal && fiyatVal > 0) gecerliSatir++;
        });

        if (gecerliSatir === 0 && !eksikSatir) {
            const ilkSatir = $('#hareketlerBody tr').first();
            ilkSatir.find('[name="urun_hizmet_id[]"], [name="fiyat[]"]').addClass('is-invalid');
            urunUyariGoster('"' + statuAdi + '" statüsünde Ürün/Hizmet Listesine en az bir Ürün/Hizmet ve Fiyat girilmesi zorunludur!');
            return false;
        }

        if (eksikSatir) {
            urunUyariGoster('"' + statuAdi + '" statüsünde Ürün/Hizmet Listesindeki satırlarda hem Ürün/Hizmet hem de Fiyat dolu olmalıdır!');
            return false;
        }

        return true;
    }

    function urunUyariGoster(mesaj) {
        showToast(mesaj, 'warning');
        const tablo = document.getElementById('hareketlerTable');
        if (tablo) tablo.scrollIntoView({ behavior: 'smooth', block: 'center' });
    }

    // Statü değişince zorunluluk rozetini güncelle, gerekmiyorsa işaretleri temizle
    function urunZorunluRozetGuncelle() {
        const zorunlu = statuUrunZorunluMu();
        $('#urunZorunluRozet').toggleClass('d-none', !zorunlu);
        if (!zorunlu) $('#hareketlerBody .is-invalid').removeClass('is-invalid');
    }

    $(document).on('change', '#statu_id', function() {
        urunZorunluRozetGuncelle();
        if (statuUrunZorunluMu()) {
            showToast('Bu statüde Ürün/Hizmet ve Fiyat girilmesi zorunludur.', 'info');
        }
    });
    $(function() { urunZorunluRozetGuncelle(); });
    $(document).on('change input', '#hareketlerBody [name="urun_hizmet_id[]"], #hareketlerBody [name="fiyat[]"]', function() {
        $(this).removeClass('is-invalid');
    });

    // Form kaydet
    function saveForm() {
        const hareketler = [];
        let hareketHatasi = false;
        
        $('#hareketlerBody tr').each(function(idx) {
            const row = $(this);
            const aktivasyonTarihi = row.find('[name="aktivasyon_tarihi[]"]').val();
            const taahutBitis = row.find('[name="taahut_bitis[]"]').val();
            
            if (aktivasyonTarihi && taahutBitis && new Date(aktivasyonTarihi) > new Date(taahutBitis)) {
                showToast('Satır ' + (idx + 1) + ': Aktivasyon Tarihi, Taahhüt Bitiş Tarihinden önce olmalı!', 'warning');
                hareketHatasi = true;
            }
            
            hareketler.push({
                uye_tipi_id: row.find('[name="uye_tipi_id[]"]').val() || null,
                ticari_grup_id: row.find('[name="ticari_grup_id[]"]').val() || null,
                uye_no_1: row.find('[name="uye_no_1[]"]').val() || null,
                uye_no_2: row.find('[name="uye_no_2[]"]').val() || null,
                urun_hizmet_id: row.find('[name="urun_hizmet_id[]"]').val() || null,
                fiyat: row.find('[name="fiyat[]"]').val() || 0,
                aktivasyon_tarihi: aktivasyonTarihi || null,
                taahut_bitis: taahutBitis || null,
                durum: row.find('[name="hareket_durum[]"]').is(':checked') ? 1 : 0
            });
            
            if (hareketHatasi) return false;
        });
        
        if (hareketHatasi) return;

        // Statüye bağlı Ürün/Hizmet + Fiyat zorunluluğu
        if (!validateUrunZorunlulugu()) return;

        // Ödemeleri topla
        const odemeler = [];
        $('#odemeSatirlar tr').each(function(idx) {
            const row = $(this);
            // Dosya input'una satır sırasına eşit açık indeks ver.
            // Kilitli satırların dosya input'u disabled olduğundan FormData'ya girmez;
            // açık indeks sayesinde kalan satırlar backend'deki $odemeIndex ile eşleşir.
            row.find('input[type="file"][name^="odeme_dosya"]').attr('name', 'odeme_dosya[' + idx + ']');
            odemeler.push({
                odeme_id: row.find('[name="odeme_id[]"]').val() || null,
                odeme_tip_id: row.find('[name="odeme_tip_id[]"]').val() || null,
                odeme_belge_no: row.find('[name="odeme_belge_no[]"]').val() || null,
                odeme_tarih: row.find('[name="odeme_tarih[]"]').val() || null,
                odeme_vade_tarihi: row.find('[name="odeme_vade_tarihi[]"]').val() || null,
                odeme_tutar: row.find('[name="odeme_tutar[]"]').val() || 0,
                odeme_durum_id: row.find('[name="odeme_durum_id[]"]').val() || 1,
                hedef_personel_id: row.find('[name="odeme_hedef_personel_id[]"]').val() || null,
                hedef_banka_hesap_id: row.find('[name="odeme_hedef_banka_hesap_id[]"]').val() || null,
                odeme_yapildi: row.find('[name="odeme_yapildi[]"]').is(':checked') ? 1 : 0,
                mevcut_dosya: row.find('[name="odeme_mevcut_dosya[]"]').val() || null,
                odeme_aciklama: row.find('[name="odeme_aciklama[]"]').val() || null
            });
        });
        
        const formData = new FormData($('#hukukForm')[0]);
        formData.append('action', 'save');
        formData.append('hareketler', JSON.stringify(hareketler));
        formData.append('odemeler', JSON.stringify(odemeler));
        
        $.ajax({
            url: '',
            type: 'POST',
            data: formData,
            processData: false,
            contentType: false,
            success: function(response) {
                if (response.success) {
                    showToast(response.message, 'success');
                    setTimeout(function() {
                        window.location.href = '/admin/<?= $backPage ?>';
                    }, 1000);
                } else {
                    showToast(response.message, 'error');
                }
            },
            error: function() {
                showToast('Bir hata oluştu!', 'error');
            }
        });
    }
    
    // ======================================
    // CARİ ARA / SEÇ
    // ======================================
    
    let cariAraModal = null;
    const isEditMode = <?= $isEdit ? 'true' : 'false' ?>;
    const mevcutTakipId = <?= $editId ?>;
    const mevcutSozlesmeId = <?= $sozlesmeId ?>;

    function cariAraModalAc() {
        if (!cariAraModal) {
            cariAraModal = new bootstrap.Modal(document.getElementById('cariAraModal'));
        }
        // Enter ile arama
        $('#cariAraInput').off('keypress').on('keypress', function(e) {
            if (e.which === 13) cariAra();
        });
        cariAraModal.show();
        setTimeout(() => $('#cariAraInput').focus(), 400);
    }

    function cariAra() {
        const arama = $('#cariAraInput').val().trim();
        const tbody = $('#cariAraBody');
        tbody.html('<tr><td colspan="7" class="text-center py-3"><div class="spinner-border spinner-border-sm text-primary"></div> Aranıyor...</td></tr>');

        $.post('', { action: 'cari_ara', arama: arama }, function(response) {
            if (!response.success) {
                tbody.html('<tr><td colspan="7" class="text-center text-danger py-3">Hata oluştu!</td></tr>');
                return;
            }
            if (!response.data || response.data.length === 0) {
                tbody.html('<tr><td colspan="7" class="text-center text-muted py-3"><i class="bi bi-inbox"></i> Sonuç bulunamadı</td></tr>');
                return;
            }
            let html = '';
            response.data.forEach(function(c) {
                html += `<tr>
                    <td><strong>${c.cari_adi || '-'}</strong></td>
                    <td>${c.cari_unvan || '-'}</td>
                    <td>${c.cari_vergi_no || '-'}</td>
                    <td>${c.cari_telefon || '-'}</td>
                    <td>${c.SehirAdi || '-'}</td>
                    <td><span class="badge bg-secondary">${c.cari_tipi_ad || '-'}</span></td>
                    <td>
                        <button type="button" class="btn btn-sm btn-primary" onclick="cariSec(${JSON.stringify(c).replace(/"/g, '&quot;')})">
                            <i class="bi bi-check-lg"></i> Seç
                        </button>
                    </td>
                </tr>`;
            });
            tbody.html(html);
        }).fail(function() {
            tbody.html('<tr><td colspan="7" class="text-center text-danger py-3">Sunucu hatası!</td></tr>');
        });
    }

    function cariSec(cari) {
        if (isEditMode) {
            // Düzenleme modunda: DB'yi güncelle, sonra formu doldur
            confirmAction(
                '"' + cari.cari_adi + '" carisini seçmek istediğinize emin misiniz?',
                'Mevcut takip ve sözleşme kayıtları bu cariye bağlanacak.',
                function() {
                    $.post('', {
                        action: 'cari_degistir',
                        yeni_cari_id: cari.cari_id,
                        takip_id: mevcutTakipId,
                        sozlesme_id: mevcutSozlesmeId
                    }, function(response) {
                        if (response.success) {
                            formaCariDoldur(response.cari, response.ilceler);
                            $('#cari_id').val(cari.cari_id);
                            $('#seciliCariAdi').text('(Cari ID: ' + cari.cari_id + ' - ' + cari.cari_adi + ')');
                            showToast(response.message, 'success');
                            cariAraModal.hide();
                        } else {
                            showToast(response.message, 'error');
                        }
                    }).fail(function() {
                        showToast('Sunucu hatası!', 'error');
                    });
                }
            );
        } else {
            // Yeni kayıt modunda: sadece formu doldur
            formaCariDoldur(cari, null);
            $('#cari_id').val(cari.cari_id);
            showToast('"' + cari.cari_adi + '" seçildi', 'success');
            cariAraModal.hide();
        }
    }

    function formaCariDoldur(cari, ilceler) {
        $('#musteri_adi').val(cari.cari_adi || '');
        $('#musteri_unvan').val(cari.cari_unvan || '');
        $('#musteri_vergi_dairesi').val(cari.cari_vergi_dairesi || '');
        $('#musteri_vergi_no').val(cari.cari_vergi_no || '');
        $('#musteri_telefon').val(cari.cari_telefon || '');
        $('#musteri_yetkili_adi').val(cari.cari_yetkili_adi || '');
        $('#musteri_yetkili_telefon').val(cari.cari_yetkili_telefon || '');
        $('#musteri_adres').val(cari.cari_adres || '');

        // Şehir seç
        const sehirId = cari.cari_sehirler;
        if (sehirId) {
            $('#musteri_sehir').val(sehirId).trigger('change.select2');
        }

        // İlçeleri yükle ve seç
        const ilceId = cari.cari_ilceler;
        if (sehirId) {
            if (ilceler && ilceler.length > 0) {
                // Backend'den gelen ilçe listesi (cari_degistir action'ından)
                let opts = '<option value="">Seçiniz...</option>';
                ilceler.forEach(function(i) {
                    const sel = (i.ilceId == ilceId) ? ' selected' : '';
                    opts += `<option value="${i.ilceId}"${sel}>${i.IlceAdi}</option>`;
                });
                $('#musteri_ilce').html(opts).trigger('change.select2');
            } else {
                // AJAX ile ilçeleri çek (yeni kayıt modu)
                $.post('', { action: 'get_ilceler', sehir_id: sehirId }, function(res) {
                    if (res.success) {
                        let opts = '<option value="">Seçiniz...</option>';
                        res.data.forEach(function(i) {
                            const sel = (i.ilceId == ilceId) ? ' selected' : '';
                            opts += `<option value="${i.ilceId}"${sel}>${i.IlceAdi}</option>`;
                        });
                        $('#musteri_ilce').html(opts).trigger('change.select2');
                    }
                });
            }
        }
    }

    // Dosya işlemleri
    function removeDosya(filePath, btn) {
        confirmAction('Bu dosyayı kaldırmak istediğinize emin misiniz?', 'Dosya sunucudan da silinecek.', function() {
            let mevcutDosyalar = JSON.parse($('#mevcut_dosyalar').val() || '[]');
            mevcutDosyalar = mevcutDosyalar.filter(d => d !== filePath);
            $('#mevcut_dosyalar').val(JSON.stringify(mevcutDosyalar));
            $(btn).closest('.dosya-item').remove();
            $.post('', { action: 'delete_file', file_path: filePath });
            showToast('Dosya kaldırıldı', 'info');
        });
    }
    
    // Haciz masrafı evrakları
    function removeHacizDosya(dosyaId, btn) {
        confirmAction('Bu evrakı kaldırmak istediğinize emin misiniz?', 'Kaydet dediğinizde evrak listeden kaldırılacak.', function() {
            const silinenler = JSON.parse($('#haciz_silinen_dosyalar').val() || '[]');
            silinenler.push(dosyaId);
            $('#haciz_silinen_dosyalar').val(JSON.stringify(silinenler));
            $(btn).closest('.dosya-item').remove();
            showToast('Evrak kaldırıldı, kaydetmeyi unutmayın', 'info');
        });
    }

    $('#haciz_dosyalar').on('change', function() {
        const alan = $('#hacizYeniDosyalar').empty();
        Array.from(this.files).forEach(f => {
            alan.append($('<span class="dosya-item border-warning">')
                .append('<i class="bi bi-cloud-arrow-up text-warning me-1"></i>')
                .append($('<span>').text(f.name)));
        });
    });

    function clearFaturaDosya(e) {
        e.preventDefault();
        $('#mevcut_fatura_dosya').val('');
        $('#fatura_dosya').val('');
        $(e.target).closest('small').remove();
    }
    
    // ======================================
    // ÖDEME YÖNETİMİ
    // ======================================
    
    function addOdemeRow() {
        odemeRowCounter++;
        
        let tipOptions = '<option value="">Seçiniz</option>';
        odemeTipleri.forEach(tip => {
            tipOptions += `<option value="${tip.odeme_tipi_id}" data-varsayılan-durum="${tip.odeme_tipi_varsayilan_durum_id}" data-dosya-onek="${tip.odeme_tipi_dosya_onek}">${tip.odeme_tipi_ad}</option>`;
        });
        
        let personelOptions = '<option value="">Personel Seç</option>';
        personeller.forEach(p => { personelOptions += `<option value="${p.kullanici_id}">${p.personel_adi}</option>`; });
        
        let bankaHesapOptions = '<option value="">Banka Hesap Seç</option>';
        bankaHesaplari.forEach(bh => { bankaHesapOptions += `<option value="${bh.bankaHesap_id}">${bh.banka_adi} - ${bh.bankaHesap_sube_adi || '-'}</option>`; });
        
        let varsayilanBadge = 'secondary', varsayilanDurumAdi = 'Personelde', varsayilanDurumId = 1;
        odemeDurumlari.forEach(d => {
            if (d.odeme_durum_id == 1) { varsayilanBadge = d.odeme_durum_renk; varsayilanDurumAdi = d.odeme_durum_ad; }
        });
        
        const newRow = `
            <tr data-odeme-id="new_${odemeRowCounter}">
                <td>
                    <select class="form-select form-select-sm odeme-tip-select" name="odeme_tip_id[]" onchange="odemeTypeChanged(this)">${tipOptions}</select>
                    <input type="hidden" name="odeme_id[]" value="">
                </td>
                <td><input type="text" class="form-control form-control-sm" name="odeme_belge_no[]" placeholder="Belge No"></td>
                <td><input type="date" class="form-control form-control-sm" name="odeme_vade_tarihi[]"></td>
                <td><input type="number" class="form-control form-control-sm text-end odeme-tutar" name="odeme_tutar[]" step="0.01" placeholder="0.00" onchange="calculateOdemeToplam()"></td>
                <td class="hedef-cell">
                    <select class="form-select form-select-sm personel-select" name="odeme_hedef_personel_id[]">${personelOptions}</select>
                    <select class="form-select form-select-sm banka-hesap-select" name="odeme_hedef_banka_hesap_id[]" style="display:none;">${bankaHesapOptions}</select>
                    <span class="no-hedef-text text-muted" style="display:none;">-</span>
                </td>
                <td>
                    <input type="file" class="form-control form-control-sm" name="odeme_dosya[]" accept=".pdf,.jpg,.jpeg,.png" onchange="previewOdemeDosya(this)">
                    <div class="odeme-dosya-preview mt-1"></div>
                    <input type="hidden" name="odeme_mevcut_dosya[]" value="">
                </td>
                <td><textarea class="form-control form-control-sm" name="odeme_aciklama[]" rows="2" style="resize:vertical;" placeholder="Açıklama"></textarea></td>
                <td>
                    <span class="badge bg-${varsayilanBadge} durum-badge" style="cursor:pointer;" onclick="showDurumModal(this)">${varsayilanDurumAdi}</span>
                    <input type="hidden" name="odeme_durum_id[]" value="${varsayilanDurumId}">
                </td>
                <td class="text-center">
                    <div class="form-check d-flex justify-content-center">
                        <input class="form-check-input" type="checkbox" name="odeme_yapildi[]" value="1" onchange="calculateOdemeToplam()">
                    </div>
                </td>
                <td><input type="date" class="form-control form-control-sm" name="odeme_tarih[]" value="${new Date().toISOString().split('T')[0]}"></td>
                <td class="text-center"><span class="text-muted" title="Önce kaydedin"><i class="bi bi-dash"></i></span></td>
                <td class="text-center">
                    <button type="button" class="btn btn-sm btn-outline-danger" onclick="removeOdemeRow(this)" title="Sil"><i class="bi bi-x"></i></button>
                </td>
            </tr>
        `;
        
        $('#odemeSatirlar').append(newRow);
        calculateOdemeToplam();
    }
    
    function toggleOdemeKilit(cb) {
        const odemeId = $(cb).data('odeme-id');
        const kilitle = cb.checked ? 1 : 0;
        cb.disabled = true;

        $.post('', { action: 'odeme_kilit_toggle', odeme_id: odemeId, kilitle: kilitle }, function(res) {
            if (res.success) {
                const row = $(cb).closest('tr');
                if (kilitle) {
                    $(cb).attr('title', 'Kilidi aç');
                    // Satır inputlarını kilitle (kendi satırın olduğu için UI gösterimi)
                    row.find('input:not(.odeme-kilit-cb), select, .durum-badge').prop('disabled', true).css('pointer-events', 'none');
                    row.find('.btn-outline-danger').hide();
                    showToast('Satır kilitledi.', 'info');
                } else {
                    $(cb).attr('title', 'Kilitle');
                    row.find('input:not(.odeme-kilit-cb), select, .durum-badge').prop('disabled', false).css('pointer-events', '');
                    row.find('.btn-outline-danger').show();
                    showToast('Kilit açıldı.', 'info');
                }
            } else {
                cb.checked = !cb.checked;
                showToast(res.message || 'İşlem başarısız.', 'error');
            }
            cb.disabled = false;
        }, 'json').fail(function() {
            cb.checked = !cb.checked;
            cb.disabled = false;
            showToast('Sunucu hatası.', 'error');
        });
    }

    function previewOdemeDosya(input) {
        const previewDiv = $(input).siblings('.odeme-dosya-preview');
        previewDiv.empty();
        if (!input.files || !input.files[0]) return;
        const file = input.files[0];
        const ext = file.name.split('.').pop().toLowerCase();
        if (['jpg', 'jpeg', 'png'].includes(ext)) {
            const reader = new FileReader();
            reader.onload = function(e) {
                previewDiv.html(`<img src="${e.target.result}" alt="Önizleme" style="max-width:80px;max-height:50px;object-fit:cover;border-radius:4px;border:1px solid #dee2e6;">`);
            };
            reader.readAsDataURL(file);
        } else if (ext === 'pdf') {
            previewDiv.html('<span class="badge bg-danger"><i class="bi bi-file-earmark-pdf"></i> PDF seçildi</span>');
        }
    }
    
    function removeOdemeRow(btn) {
        const row = $(btn).closest('tr');
        const odemeId = row.data('odeme-id');
        
        if (odemeId && !String(odemeId).startsWith('new_')) {
            confirmAction('Bu ödemeyi silmek istediğinize emin misiniz?', 'Ödeme kaydı silinecek.', function() {
                $.post('', { action: 'delete_odeme', odeme_id: odemeId }, function(response) {
                    if (response.success) {
                        row.remove();
                        calculateOdemeToplam();
                        showToast('Ödeme silindi', 'success');
                    } else {
                        showToast(response.message, 'error');
                    }
                });
            });
        } else {
            row.remove();
            calculateOdemeToplam();
        }
    }
    
    function calculateOdemeToplam() {
        let toplam = 0, toplamOdenen = 0, toplamOdenecek = 0;
        
        $('#odemeSatirlar tr').each(function() {
            const val = parseFloat($(this).find('.odeme-tutar').val()) || 0;
            const yapıldı = $(this).find('[name="odeme_yapildi[]"]').is(':checked');
            toplam += val;
            if (yapıldı) toplamOdenen += val;
            else toplamOdenecek += val;
        });
        
        const simge = parabirimiSimge || '₺';
        const formatOpts = { minimumFractionDigits: 2, maximumFractionDigits: 2 };
        $('#odemeToplam').text(toplam.toLocaleString('tr-TR', formatOpts) + ' ' + simge);
        $('#toplamOdenen').text(toplamOdenen.toLocaleString('tr-TR', formatOpts) + ' ' + simge);
        $('#toplamOdenecek').text(toplamOdenecek.toLocaleString('tr-TR', formatOpts) + ' ' + simge);
        
        // Ürün toplamı ile karşılaştır
        let urunToplamText = $('#toplamTutar').text().replace(/[^0-9,.-]/g, '');
        urunToplamText = urunToplamText.replace(/\./g, '').replace(',', '.');
        const urunToplam = parseFloat(urunToplamText) || 0;
        const fark = toplam - urunToplam;
        
        if (Math.abs(fark) > 0.01) {
            if (fark > 0) {
                $('#odemeFark').html(`<span class="text-danger">+${fark.toLocaleString('tr-TR', formatOpts)} ${simge} fazla</span>`);
            } else {
                $('#odemeFark').html(`<span class="text-warning">${fark.toLocaleString('tr-TR', formatOpts)} ${simge} eksik</span>`);
            }
        } else {
            $('#odemeFark').html('<span class="text-success"><i class="bi bi-check-circle"></i> Eşleşti</span>');
        }
    }
    
    function odemeTypeChanged(select) {
        const row = $(select).closest('tr');
        const tipId = $(select).val();
        const selectedOption = $(select).find('option:selected');
        const varsayilanDurumId = selectedOption.data('varsayılan-durum');
        const vadeTarihiInput = row.find('input[name="odeme_vade_tarihi[]"]');
        
        if (tipId == 1 || tipId == 4 || tipId == 5) {
            vadeTarihiInput.hide().val('');
        } else {
            vadeTarihiInput.show();
        }
        
        if (tipId && varsayilanDurumId) {
            row.find('[name="odeme_durum_id[]"]').val(varsayilanDurumId);
            const durum = odemeDurumlari.find(d => d.odeme_durum_id == varsayilanDurumId);
            if (durum) {
                row.find('.badge').removeClass().addClass('badge bg-' + durum.odeme_durum_renk).text(durum.odeme_durum_ad);
                updateHedefFields(row, durum.odeme_durum_hedef_tipi, durum.odeme_durum_hedef_zorunlu);
            }
        }
    }
    
    function updateHedefFields(row, hedefTip, hedefZorunlu) {
        const personelSelect = row.find('.personel-select');
        const bankaHesapSelect = row.find('.banka-hesap-select');
        const noHedefText = row.find('.no-hedef-text');
        
        personelSelect.hide().prop('required', false);
        bankaHesapSelect.hide().prop('required', false);
        noHedefText.hide();
        
        if (hedefTip === 'personel') {
            personelSelect.show();
            if (hedefZorunlu == 1) personelSelect.prop('required', true);
        } else if (hedefTip === 'banka') {
            bankaHesapSelect.show();
            if (hedefZorunlu == 1) bankaHesapSelect.prop('required', true);
        } else {
            noHedefText.show();
        }
    }
    
    // Durum değiştirme modal
    let currentDurumRow = null;
    function showDurumModal(btn) {
        currentDurumRow = $(btn).closest('tr');
        const mevcutDurumId = currentDurumRow.find('[name="odeme_durum_id[]"]').val();
        
        let options = '';
        odemeDurumlari.forEach(d => {
            const selected = d.odeme_durum_id == mevcutDurumId ? 'selected' : '';
            options += `<option value="${d.odeme_durum_id}" data-hedef-tip="${d.odeme_durum_hedef_tipi}" data-hedef-zorunlu="${d.odeme_durum_hedef_zorunlu}" ${selected}>${d.odeme_durum_ad}</option>`;
        });
        $('#yeniDurumSelect').html('<option value="">Seçiniz</option>' + options);
        
        let personelOpts = '<option value="">Personel Seç</option>';
        personeller.forEach(p => { personelOpts += `<option value="${p.kullanici_id}">${p.personel_adi}</option>`; });
        $('#modalPersonelSelect').html(personelOpts);
        
        let bankaHesapOpts = '<option value="">Banka Hesap Seç</option>';
        bankaHesaplari.forEach(bh => { bankaHesapOpts += `<option value="${bh.bankaHesap_id}">${bh.banka_adi} - ${bh.bankaHesap_sube_adi || '-'}</option>`; });
        $('#modalBankaHesapSelect').html(bankaHesapOpts);
        
        $('#modalPersonelDiv, #modalBankaDiv').hide();
        $('#modalAciklama').val('');
        
        const modal = new bootstrap.Modal(document.getElementById('durumModal'));
        modal.show();
    }
    
    $('#yeniDurumSelect').on('change', function() {
        const selected = $(this).find('option:selected');
        const hedefTip = selected.data('hedef-tip');
        const hedefZorunlu = selected.data('hedef-zorunlu');
        
        $('#modalPersonelDiv, #modalBankaDiv').hide();
        $('#modalPersonelSelect, #modalBankaHesapSelect').prop('required', false);
        
        if (hedefTip === 'personel') {
            $('#modalPersonelDiv').show();
            if (hedefZorunlu == 1) $('#modalPersonelSelect').prop('required', true);
        } else if (hedefTip === 'banka') {
            $('#modalBankaDiv').show();
            if (hedefZorunlu == 1) $('#modalBankaHesapSelect').prop('required', true);
        }
    });
    
    function saveDurumChange() {
        const odemeId = currentDurumRow.data('odeme-id');
        const yeniDurumId = $('#yeniDurumSelect').val();
        const hedefPersonelId = $('#modalPersonelSelect').val();
        const hedefBankaHesapId = $('#modalBankaHesapSelect').val();
        const aciklama = $('#modalAciklama').val();
        
        if (!yeniDurumId) {
            showToast('Lütfen yeni durum seçin', 'warning');
            return;
        }
        
        if (!odemeId || String(odemeId).startsWith('new_')) {
            const durum = odemeDurumlari.find(d => d.odeme_durum_id == yeniDurumId);
            if (durum) {
                currentDurumRow.find('[name="odeme_durum_id[]"]').val(yeniDurumId);
                currentDurumRow.find('.badge').removeClass().addClass('badge bg-' + durum.odeme_durum_renk).text(durum.odeme_durum_ad);
                updateHedefFields(currentDurumRow, durum.odeme_durum_hedef_tipi, durum.odeme_durum_hedef_zorunlu);
                if (durum.odeme_durum_hedef_tipi === 'personel' && hedefPersonelId) {
                    currentDurumRow.find('[name="odeme_hedef_personel_id[]"]').val(hedefPersonelId);
                } else if (durum.odeme_durum_hedef_tipi === 'banka' && hedefBankaHesapId) {
                    currentDurumRow.find('[name="odeme_hedef_banka_hesap_id[]"]').val(hedefBankaHesapId);
                }
            }
            bootstrap.Modal.getInstance(document.getElementById('durumModal')).hide();
            showToast('Durum güncellendi', 'success');
            return;
        }
        
        $.post('', {
            action: 'change_odeme_durum',
            odeme_id: odemeId,
            yeni_durum_id: yeniDurumId,
            hedef_personel_id: hedefPersonelId,
            hedef_banka_hesap_id: hedefBankaHesapId,
            aciklama: aciklama
        }, function(response) {
            if (response.success) {
                const durum = odemeDurumlari.find(d => d.odeme_durum_id == yeniDurumId);
                if (durum) {
                    currentDurumRow.find('[name="odeme_durum_id[]"]').val(yeniDurumId);
                    currentDurumRow.find('.badge').removeClass().addClass('badge bg-' + durum.odeme_durum_renk).text(durum.odeme_durum_ad);
                    updateHedefFields(currentDurumRow, durum.odeme_durum_hedef_tipi, durum.odeme_durum_hedef_zorunlu);
                }
                bootstrap.Modal.getInstance(document.getElementById('durumModal')).hide();
                showToast('Durum güncellendi', 'success');
            } else {
                showToast(response.message, 'error');
            }
        });
    }
    </script>
    
    <!-- Durum Değiştirme Modalı -->
    <div class="modal fade" id="durumModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header bg-warning text-dark">
                    <h5 class="modal-title"><i class="bi bi-arrow-repeat"></i> Ödeme Durumu Değiştir</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="mb-3">
                        <label class="form-label">Yeni Durum</label>
                        <select class="form-select" id="yeniDurumSelect"><option value="">Seçiniz</option></select>
                    </div>
                    <div class="mb-3" id="modalPersonelDiv" style="display:none;">
                        <label class="form-label">Hedef Personel</label>
                        <select class="form-select" id="modalPersonelSelect"><option value="">Personel Seç</option></select>
                    </div>
                    <div class="mb-3" id="modalBankaDiv" style="display:none;">
                        <label class="form-label">Hedef Banka Hesap</label>
                        <select class="form-select" id="modalBankaHesapSelect"><option value="">Banka Hesap Seç</option></select>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Açıklama (Opsiyonel)</label>
                        <textarea class="form-control" id="modalAciklama" rows="2" placeholder="Durum değişikliği hakkında not..."></textarea>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">İptal</button>
                    <button type="button" class="btn btn-warning text-dark" onclick="saveDurumChange()">
                        <i class="bi bi-check"></i> Durumu Güncelle
                    </button>
                </div>
            </div>
        </div>
    </div>

</body>
</html>
