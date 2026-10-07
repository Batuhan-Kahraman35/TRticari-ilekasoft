<?php
/**
 * Admin Panel - Sozlesme Form
 * Sozlesme Ekleme/Duzenleme sayfası
 */

require_once __DIR__ . '/../auth.php';
require_once __DIR__ . '/../includes/PageAuth.php';
require_once __DIR__ . '/../includes/LogHelper.php';
requireAuth();

$user = Auth::user();
$db = Database::getInstance();

// Sayfa Yetki kontrolu - sozlesme-yonetimi sayfası yetkilerini kullan
$currentPagefile = 'sozlesme-yonetimi.php';
$pagePermissions = PageAuth::checkPagePermissions(
    $user['kullanici_id'],
    $user['departman_id'],
    $currentPagefile
);

if (!$pagePermissions['has_access']) {
    PageAuth::accessDenied($pagePermissions['error'] ?? 'Bu sayfaya erisim Yetkiniz bulunmamaktadır.');
}

// Duzenleme modu kontrolu
$editid = isset($_GET['id']) ? intval($_GET['id']) : 0;
$isEdit = $editid > 0;
$sozlesme = null;
$hareketler = [];

// URL'den gelen cari_id (Cari formundan yönlendirme için)
$selectedCariId = isset($_GET['cari_id']) ? intval($_GET['cari_id']) : 0;

if ($isEdit) {
    if (!$pagePermissions['can_edit']) {
        PageAuth::accessDenied('Duzenleme Yetkiniz bulunmamaktadır.');
    }
    
    $sozlesme = $db->fetchOne("
        SELECT 
            sozlesme_id,
            sozlesme_sezon_id,
            sozlesme_cari_id,
            sozlesme_personel_id,
            sozlesme_sorumlu_kullanici_id,
            sozlesme_no,
            sozlesme_aciklama,
            sozlesme_fatura_no,
            sozlesme_fatura_dosya,
            sozlesme_dosyalar,
            sozlesme_durum,
            CONVERT(VARCHAR(10), sozlesme_tarih, 120) as sozlesme_tarih,
            CONVERT(VARCHAR(19), sozlesme_olusturma_tarihi, 120) as sozlesme_olusturma_tarihi
        FROM Sozlesmeler 
        WHERE sozlesme_id = ?
    ", [$editid]);
    if (!$sozlesme) {
        header('Location: /admin/sozlesme-yonetimi');
        exit;
    }
    
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
    ", [$editid]);
}

// Sayfa bilgilerini veritabanından çek
$pageinfo = $db->fetchOne("
    SELECT 
        s.sayfalar_sayfa_adi, 
        s.sayfalar_aciklama,
        m.menuler_menu_adi as menu_adi
    FROM Menu_Sayfalar s
    LEFT JOIN Menuler m ON s.sayfalar_menu_id = m.menuler_id
    WHERE s.sayfalar_sayfa_url LIKE ? AND s.sayfalar_durum = 1
", ['%' . $currentPagefile]);

$pageTitle = $pageinfo['sayfalar_sayfa_adi'] ?? 'Sözleşme Form';
$pageDescription = $pageinfo['sayfalar_aciklama'] ?? '';
$menuAdi = $pageinfo['menu_adi'] ?? null;

// Düzenleme/Ekleme moduna göre başlık ayarla
if ($isEdit) {
    $pageTitle = $pageTitle . ' - Düzenle';
} else {
    $pageTitle = $pageTitle . ' - Yeni';
}

// site title
$siteAyarlari = $db->fetchOne("SELECT TOP 1 site_ayarlari_site_title FROM dbo.tanim_site_ayarlari ORDER BY site_ayarlari_id DESC");
$siteTitle = $siteAyarlari['site_ayarlari_site_title'] ?? 'Örnek Yazılım Portal';

// Dropdown verileri
$sezonlar = $db->fetchAll("SELECT sezon_id, sezon_ad FROM Sozlesme_Sezonlar WHERE sezon_durum = 1 ORDER BY sezon_ad DESC");
// Müşteri bilgileri (düzenleme veya cari_id ile yönlendirme)
$cariDetay = null;
$cariDosyalar = [];
$editCariId = 0;
if ($isEdit && !empty($sozlesme['sozlesme_cari_id'])) {
    $editCariId = $sozlesme['sozlesme_cari_id'];
} elseif ($selectedCariId > 0) {
    $editCariId = $selectedCariId;
}

if ($editCariId > 0) {
    $cariDetay = $db->fetchOne("
        SELECT cari_id, cari_adi, cari_unvan, cari_vergi_dairesi, cari_vergi_no,
               cari_telefon, cari_yetkili_adi, cari_yetkili_telefon,
               cari_sehirler, cari_ilceler, cari_adres, cari_ulke, cari_web_sitesi
        FROM Cari WHERE cari_id = ?
    ", [$editCariId]);
    
    // Cari dosyaları
    $cariDosyalar = $db->fetchAll("
        SELECT dosya_id, dosya_adi, dosya_orijinal, dosya_yol, dosya_boyut, dosya_tip,
               dosya_aciklama, CONVERT(VARCHAR(19), dosya_tarih, 120) as dosya_tarih
        FROM Cari_Dosyalar WHERE cari_id = ? ORDER BY dosya_id DESC
    ", [$editCariId]);
}

// Şehirler
$sehirler = $db->fetchAll("SELECT SehirId, SehirAdi FROM Adres_Sehirler WHERE UlkeId = 1 ORDER BY SehirAdi");

// İlçeler (cari varsa şehrine göre)
$ilceler = [];
if ($cariDetay && !empty($cariDetay['cari_sehirler'])) {
    $ilceler = $db->fetchAll("SELECT ilceId, IlceAdi FROM Adres_Ilceler WHERE SehirId = ? ORDER BY IlceAdi", [$cariDetay['cari_sehirler']]);
}
// Pasif personel de secilebilir; pasiflik yalnizca panele girisi engeller (bkz. admin/auth.php)
$personeller = $db->fetchAll("
    SELECT kullanici_id,
           kullanici_ad + ' ' + kullanici_soyad
             + CASE WHEN ISNULL(kullanici_durum, 0) = 1 THEN '' ELSE ' (Pasif)' END AS personel_adi
    FROM kullanicilar
    ORDER BY ISNULL(kullanici_durum, 0) DESC, kullanici_ad
");

// Sorumluluk alani: sorumlu olarak atanabilecek kullanicilar (SAHA SATIS, MUDUR).
// Ayni havuz Satilmayan Illegaller raporunda da kullanilir; iki liste ayrisirsa
// raporda atanan sorumlu bu formda gorunmez ve kayitta silinir
// (bkz. admin/includes/illegal-rapor.php - SORUMLU_DEPARTMANLARI).
$sorumlular = $db->fetchAll("
    SELECT
        k.kullanici_id,
        k.kullanici_ad + ' ' + k.kullanici_soyad
          + CASE WHEN ISNULL(k.kullanici_durum, 0) = 1 THEN '' ELSE ' (Pasif)' END AS sorumlu_adi,
        d.departman_adi
    FROM kullanicilar k
    LEFT JOIN kullanici_Departmanlar d ON d.departman_id = k.kullanici_departman_id
    WHERE k.kullanici_departman_id IN (10, 22)
    ORDER BY ISNULL(k.kullanici_durum, 0) DESC, k.kullanici_ad, k.kullanici_soyad
");
$uyeTipleri = $db->fetchAll("SELECT uye_tipi_id, uye_tipi_ad FROM Sozlesme_UyeTipleri WHERE uye_tipi_durum = 1 ORDER BY uye_tipi_ad");
$ticariGruplar = $db->fetchAll("SELECT ticari_grup_id, ticari_grup_kod, ticari_grup_ad FROM Sozlesme_TicariGruplar WHERE ticari_grup_durum = 1 ORDER BY ticari_grup_kod");
$urunler = $db->fetchAll("SELECT urun_hizmet_id, urun_hizmet_adi, ISNULL(hizmetSuresi, 0) as hizmetSuresi FROM Urun_Hizmet WHERE urun_hizmet_durum = 1 ORDER BY urun_hizmet_kodu");

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

// Varsayılan para birimi simgesi (düzenleme modunda cari'den alınır)
$parabirimiSimge = '₺';
if ($isEdit && !empty($sozlesme['sozlesme_cari_id'])) {
    $cariParabirimi = $db->fetchOne("
        SELECT ISNULL(u.ParaBirimiSimge, '₺') as simge 
        FROM Cari c 
        LEFT JOIN Adres_Ulkeler u ON c.cari_ulke = u.UlkeId 
        WHERE c.cari_id = ?
    ", [$sozlesme['sozlesme_cari_id']]);
    $parabirimiSimge = $cariParabirimi['simge'] ?? '₺';
}

// Mevcut Ödemeler (düzenleme modunda)
$odemeler = [];
if ($isEdit) {
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
        LEFT JOiN Sozlesme_OdemeTipleri t ON o.odeme_tipi_id = t.odeme_tipi_id
        LEFT JOiN Sozlesme_OdemeDurumlari d ON o.odeme_durum_id = d.odeme_durum_id
        LEFT JOIN kullanicilar k ON o.odeme_kilit_kullanici_id = k.kullanici_id
        WHERE o.odeme_sozlesme_id = ?
        ORDER BY o.odeme_id
    ", [$editid]);
}

// Dosya yukleme dizini
$uploaddir = '../assets/uploads/sozlesmeler/';
if (!is_dir($uploaddir)) {
    mkdir($uploaddir, 0755, true);
}

// Cari dosyaları yükleme dizini
$cariDosyaUploadDir = '../assets/uploads/cari_dosyalar/';
if (!is_dir($cariDosyaUploadDir)) {
    mkdir($cariDosyaUploadDir, 0755, true);
}

// AJAX islemleri
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
                $cariWebSitesi = trim($_POST['musteri_web_sitesi'] ?? '');
                
                // Ana form verileri
                $sezonid = $_POST['sezon_id'] ?? null;
                $tarih = $_POST['tarih'] ?? date('Y-m-d');
                $sozlesmeNo = trim($_POST['sozlesme_no'] ?? '');
                $aciklama = trim($_POST['aciklama'] ?? '');
                $personelid = $_POST['personel_id'] ?: null;
                $sorumluid  = $_POST['sorumlu_kullanici_id'] ?: null;
                $faturaNo = trim($_POST['fatura_no'] ?? '');
                $durum = $_POST['durum'] ?? 1;
                $mevcutDosyalar = $_POST['mevcut_dosyalar'] ?? '[]';
                $mevcutFaturaDosya = $_POST['mevcut_fatura_dosya'] ?? '';
                $sozlesmeid = $_POST['sozlesme_id'] ?? 0;
                
                // Validasyon
                if (empty($cariAdi)) {
                    echo json_encode(['success' => false, 'message' => 'Müşteri adı zorunludur!']);
                    exit;
                }
                if (!$sezonid) {
                    echo json_encode(['success' => false, 'message' => 'Sezon secilmeli!']);
                    exit;
                }
                
                // === MÜŞTERİ KAYDET/GÜNCELLE ===
                if ($cariId > 0) {
                    $db->execute("
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
                            cari_web_sitesi = ?,
                            cari_guncelleme_tarihi = GETDATE()
                        WHERE cari_id = ?
                    ", [
                        $cariAdi, $cariUnvan, $cariVergiDairesi, $cariVergiNo,
                        $cariTelefon, $cariYetkiliAdi, $cariYetkiliTelefon,
                        $cariSehir, $cariIlce, $cariAdres, $cariWebSitesi, $cariId
                    ]);
                } else {
                    $db->execute("
                        INSERT INTO Cari (
                            cari_adi, cari_unvan, cari_vergi_dairesi, cari_vergi_no,
                            cari_telefon, cari_yetkili_adi, cari_yetkili_telefon,
                            cari_adres, cari_web_sitesi, cari_ulke, cari_sehirler, cari_ilceler,
                            cari_tipi_id, cari_aktif, cari_musteri, cari_tedarikci, 
                            cari_olusturan_kullanici, cari_olusturma_tarihi
                        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 1, ?, ?, 1, 1, 1, 0, ?, GETDATE())
                    ", [
                        $cariAdi, $cariUnvan, $cariVergiDairesi, $cariVergiNo,
                        $cariTelefon, $cariYetkiliAdi, $cariYetkiliTelefon,
                        $cariAdres, $cariWebSitesi, $cariSehir, $cariIlce, $user['kullanici_id']
                    ]);
                    $newCari = $db->fetchOne("SELECT TOP 1 cari_id FROM Cari ORDER BY cari_id DESC");
                    $cariId = $newCari['cari_id'];
                }
                
                // === CARİ DOSYALARI YÜKLE ===
                if (!empty($_FILES['cari_dosyalar']['name'][0])) {
                    $izinliExt = ['pdf', 'jpg', 'jpeg', 'png', 'doc', 'docx', 'xls', 'xlsx'];
                    foreach ($_FILES['cari_dosyalar']['tmp_name'] as $key => $tmpName) {
                        if ($_FILES['cari_dosyalar']['error'][$key] === UPLOAD_ERR_OK) {
                            $originalName = $_FILES['cari_dosyalar']['name'][$key];
                            $extension = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));
                            if (!in_array($extension, $izinliExt)) continue;
                            $newName = uniqid('cari_') . '.' . $extension;
                            $targetPath = $cariDosyaUploadDir . $newName;
                            
                            if (move_uploaded_file($tmpName, $targetPath)) {
                                $dosyaBoyut = $_FILES['cari_dosyalar']['size'][$key];
                                $dosyaTip = $_FILES['cari_dosyalar']['type'][$key];
                                $db->execute("
                                    INSERT INTO Cari_Dosyalar (cari_id, dosya_adi, dosya_orijinal, dosya_yol, dosya_boyut, dosya_tip, dosya_yukleyen)
                                    VALUES (?, ?, ?, ?, ?, ?, ?)
                                ", [$cariId, $newName, $originalName, 'admin/assets/uploads/cari_dosyalar/' . $newName, $dosyaBoyut, $dosyaTip, $user['kullanici_id']]);
                            }
                        }
                    }
                }
                
                $cariid = $cariId;
                
                // Dosya yukleme islemi
                $dosyalar = json_decode($mevcutDosyalar, true) ?: [];
                
                if (!empty($_FILES['dosyalar']['name'][0])) {
                    foreach ($_FILES['dosyalar']['tmp_name'] as $key => $tmpName) {
                        if ($_FILES['dosyalar']['error'][$key] === UPLOAD_ERR_OK) {
                            $originalName = $_FILES['dosyalar']['name'][$key];
                            $extension = pathinfo($originalName, PATHINFO_EXTENSION);
                            $newName = uniqid('sozlesme_') . '.' . $extension;
                            $targetPath = $uploaddir . $newName;
                            
                            if (move_uploaded_file($tmpName, $targetPath)) {
                                $dosyalar[] = 'Admin/assets/uploads/sozlesmeler/' . $newName;
                            }
                        }
                    }
                }
                
                $dosyalarJson = json_encode($dosyalar, JSON_UNESCAPED_UNICODE);
                
                // Fatura dosyası yükleme
                $faturaDosya = $mevcutFaturaDosya;
                if (!empty($_FILES['fatura_dosya']['name']) && $_FILES['fatura_dosya']['error'] === UPLOAD_ERR_OK) {
                    $faturaTmpName = $_FILES['fatura_dosya']['tmp_name'];
                    $faturaOriginalName = $_FILES['fatura_dosya']['name'];
                    $faturaExt = strtolower(pathinfo($faturaOriginalName, PATHINFO_EXTENSION));
                    $faturaNewName = uniqid('fatura_') . '.' . $faturaExt;
                    $faturaDosyaDir = __DIR__ . '/../assets/uploads/sozlesmeler/';
                    if (move_uploaded_file($faturaTmpName, $faturaDosyaDir . $faturaNewName)) {
                        // Eski dosyayı sil
                        if ($mevcutFaturaDosya && file_exists($faturaDosyaDir . basename($mevcutFaturaDosya))) {
                            unlink($faturaDosyaDir . basename($mevcutFaturaDosya));
                        }
                        $faturaDosya = 'Admin/assets/uploads/sozlesmeler/' . $faturaNewName;
                    }
                }
                
                if ($sozlesmeid > 0) {
                    // Güncelleme - önce eski kaydı al (log için)
                    $eskiSozlesme = $db->fetchOne("SELECT * FROM Sozlesmeler WHERE sozlesme_id = ?", [$sozlesmeid]);
                    
                    $db->execute("
                        UPDATE Sozlesmeler SET
                            sozlesme_sezon_id = ?,
                            sozlesme_tarih = ?,
                            sozlesme_cari_id = ?,
                            sozlesme_no = ?,
                            sozlesme_aciklama = ?,
                            sozlesme_personel_id = ?,
                            sozlesme_sorumlu_kullanici_id = ?,
                            sozlesme_fatura_no = ?,
                            sozlesme_fatura_dosya = ?,
                            sozlesme_dosyalar = ?,
                            sozlesme_guncelleme_tarihi = GETDATE(),
                            sozlesme_durum = ?
                        WHERE sozlesme_id = ?
                    ", [
                        $sezonid, $tarih, $cariid, $sozlesmeNo, $aciklama,
                        $personelid, $sorumluid, $faturaNo, $faturaDosya, $dosyalarJson, $durum, $sozlesmeid
                    ]);
                    
                    // Yeni kaydı al ve log oluştur
                    $yeniSozlesme = $db->fetchOne("SELECT * FROM Sozlesmeler WHERE sozlesme_id = ?", [$sozlesmeid]);
                    logKayitDegisiklikleri($db, 'sozlesme-form', 'Sozlesmeler', $sozlesmeid, $eskiSozlesme, $yeniSozlesme, $user['kullanici_id'], 'Sözleşme güncellendi');
                } else {
                    // Yeni kayit
                    $db->execute("
                        INSERT INTO Sozlesmeler (
                            sozlesme_sezon_id, sozlesme_tarih, sozlesme_cari_id,
                            sozlesme_no, sozlesme_aciklama, sozlesme_personel_id, sozlesme_sorumlu_kullanici_id,
                            sozlesme_fatura_no, sozlesme_fatura_dosya, sozlesme_dosyalar, sozlesme_kullanici_id,
                            sozlesme_olusturma_tarihi, sozlesme_durum
                        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, GETDATE(), ?)
                    ", [
                        $sezonid, $tarih, $cariid, $sozlesmeNo, $aciklama,
                        $personelid, $sorumluid, $faturaNo, $faturaDosya, $dosyalarJson, $user['kullanici_id'], $durum
                    ]);
                    
                    // Yeni id'yi al
                    $sozlesmeid = $db->getLastInsertId();
                    
                    // Yeni kaydı al ve log oluştur
                    $yeniSozlesme = $db->fetchOne("SELECT * FROM Sozlesmeler WHERE sozlesme_id = ?", [$sozlesmeid]);
                    logKayitDegisiklikleri($db, 'sozlesme-form', 'Sozlesmeler', $sozlesmeid, null, $yeniSozlesme, $user['kullanici_id'], 'Yeni sözleşme oluşturuldu');
                }
                
                // Stok hareketlerini kaydet
                // Silme öncesi mevcut hareketleri al (log için)
                $eskiHareketler = $db->fetchAll("SELECT * FROM Sozlesme_StokHareketleri WHERE hareket_sozlesme_id = ?", [$sozlesmeid]);
                
                // Mevcut hareketleri sil ve logla
                foreach ($eskiHareketler as $eskiHareket) {
                    logKayitDegisiklikleri($db, 'sozlesme-form', 'Sozlesme_StokHareketleri', $eskiHareket['hareket_id'], $eskiHareket, null, $user['kullanici_id'], 'Ürün/Hizmet kaydı silindi');
                }
                $db->execute("DELETE FROM Sozlesme_StokHareketleri WHERE hareket_sozlesme_id = ?", [$sozlesmeid]);
                
                // Yeni hareketleri ekle
                $hareketler = json_decode($_POST['hareketler'] ?? '[]', true);
                
                // Hareket evrak dosyalarini isle
                $evrakUploadDir = '../assets/uploads/';
                $hareketEvraklar = [];
                if (!empty($_FILES['hareket_evrak']['name'])) {
                    foreach ($_FILES['hareket_evrak']['tmp_name'] as $key => $tmpName) {
                        if ($_FILES['hareket_evrak']['error'][$key] === UPLOAD_ERR_OK && !empty($tmpName)) {
                            $originalName = $_FILES['hareket_evrak']['name'][$key];
                            $extension = pathinfo($originalName, PATHINFO_EXTENSION);
                            $newName = uniqid('sozlesme_') . '.' . $extension;
                            $targetPath = $evrakUploadDir . $newName;
                            
                            if (move_uploaded_file($tmpName, $targetPath)) {
                                $hareketEvraklar[$key] = $newName; // Sadece dosya adi
                            }
                        }
                    }
                }
                
                $hareketIndex = 0;
                foreach ($hareketler as $hareket) {
                    if (empty($hareket['urun_hizmet_id'])) {
                        $hareketIndex++;
                        continue;
                    }
                    
                    // Yeni yuklenen evrak varsa kullan, yoksa mevcut evrak
                    $evrakDosya = $hareketEvraklar[$hareketIndex] ?? ($hareket['mevcut_evrak'] ?: null);
                    
                    $db->execute("
                        INSERT INTO Sozlesme_StokHareketleri (
                            hareket_sozlesme_id, hareket_uye_tipi_id, hareket_ticari_grup_id,
                            hareket_uye_no_1, hareket_uye_no_2, hareket_urun_hizmet_id,
                            hareket_fiyat, hareket_aktivasyon_tarihi, hareket_taahut_bitis,
                            hareket_durum, hareket_olusturma_tarihi
                        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, GETDATE())
                    ", [
                        $sozlesmeid,
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
                    
                    // Yeni eklenen hareketi logla
                    $yeniHareketId = $db->fetchOne("SELECT SCOPE_IDENTITY() as id")['id'];
                    if ($yeniHareketId) {
                        $yeniHareket = $db->fetchOne("SELECT * FROM Sozlesme_StokHareketleri WHERE hareket_id = ?", [$yeniHareketId]);
                        logKayitDegisiklikleri($db, 'sozlesme-form', 'Sozlesme_StokHareketleri', $yeniHareketId, null, $yeniHareket, $user['kullanici_id'], 'Yeni Ürün/Hizmet eklendi');
                    }
                    
                    $hareketIndex++;
                }
                
                // ödemeleri kaydet
                $odemelerData = json_decode($_POST['odemeler'] ?? '[]', true);
                
                // Mevcut ödemelerin id'lerini al
                $mevcutOdemeids = [];
                foreach ($odemelerData as $odeme) {
                    if (!empty($odeme['odeme_id'])) {
                        $mevcutOdemeids[] = $odeme['odeme_id'];
                    }
                }
                
                // Listede olmayan ödemeleri sil
                if (!empty($mevcutOdemeids)) {
                    $placeholders = implode(',', array_fill(0, count($mevcutOdemeids), '?'));
                    $db->execute("DELETE FROM Sozlesme_Odemeler WHERE odeme_sozlesme_id = ? AND odeme_id NOT iN ($placeholders)", array_merge([$sozlesmeid], $mevcutOdemeids));
                } else {
                    $db->execute("DELETE FROM Sozlesme_Odemeler WHERE odeme_sozlesme_id = ?", [$sozlesmeid]);
                }
                
                $odemeIndex = 0;
                $uploadDir = __DIR__ . '/../assets/uploads/sozlesme_odemeler/';
                $uploadUrlBase = '/admin/assets/uploads/sozlesme_odemeler/';
                $izinliUzantilar = ['pdf', 'jpg', 'jpeg', 'png'];
                
                foreach ($odemelerData as $odeme) {
                    if (empty($odeme['odeme_tip_id'])) { $odemeIndex++; continue; }
                    
                    $odemeid = $odeme['odeme_id'] ?? 0;
                    
                    // Dosya yükleme işlemi
                    $yeniDosya = null;
                    if (
                        isset($_FILES['odeme_dosya']['name'][$odemeIndex]) &&
                        $_FILES['odeme_dosya']['error'][$odemeIndex] === UPLOAD_ERR_OK &&
                        !empty($_FILES['odeme_dosya']['name'][$odemeIndex])
                    ) {
                        $ext = strtolower(pathinfo($_FILES['odeme_dosya']['name'][$odemeIndex], PATHINFO_EXTENSION));
                        if (in_array($ext, $izinliUzantilar)) {
                            $yeniDosya = uniqid('odeme_') . '_' . time() . '.' . $ext;
                            move_uploaded_file($_FILES['odeme_dosya']['tmp_name'][$odemeIndex], $uploadDir . $yeniDosya);
                        }
                    }
                    
                    // Varsayılan durum al
                    $tipbilgi = $db->fetchOne("SELECT odeme_tipi_varsayilan_durum_id, odeme_tipi_hedef FROM Sozlesme_OdemeTipleri WHERE odeme_tipi_id = ?", [$odeme['odeme_tip_id']]);
                    $varsayilanDurum = $tipbilgi['odeme_tipi_varsayilan_durum_id'] ?? 1;
                    $hedef = $tipbilgi['odeme_tipi_hedef'] ?? 'personel';
                    
                    // Durum değerini belirle (mevcut kayit için frontend'den gelen değer, yeni kayit için varsayılan)
                    $durumid = !empty($odeme['odeme_durum_id']) ? $odeme['odeme_durum_id'] : $varsayilanDurum;
                    
                    // Hedef değerlerini belirle
                    $hedefPersonelid = $odeme['hedef_personel_id'] ?: null;
                    $hedefBankaHesapid = $odeme['hedef_banka_hesap_id'] ?: null;
                    $odemeYapildi = !empty($odeme['odeme_yapildi']) ? 1 : 0;
                    
                    if ($odemeid > 0) {
                        // Kilit kontrolü: başkası kilitlediyse güncelleme yapma (admin hariç)
                        $isAdmin = ($user['departman_id'] == 1);
                        $kilitlenen = $db->fetchOne("SELECT odeme_kilit_kullanici_id FROM Sozlesme_Odemeler WHERE odeme_id = ?", [$odemeid]);
                        if (!$isAdmin && $kilitlenen && $kilitlenen['odeme_kilit_kullanici_id'] && $kilitlenen['odeme_kilit_kullanici_id'] != $user['kullanici_id']) {
                            $odemeIndex++;
                            continue;
                        }

                        // güncelleme
                        $mevcutDosya = $odeme['mevcut_dosya'] ?? null;
                        $kayitliDosya = $yeniDosya ?: $mevcutDosya;
                        
                        // Yeni dosya yüklendiyse eskiyi sil
                        if ($yeniDosya && $mevcutDosya && $yeniDosya !== $mevcutDosya) {
                            $eskiYol = $uploadDir . $mevcutDosya;
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
                            $hedefPersonelid,
                            $hedefBankaHesapid,
                            $hedefPersonelid,
                            $hedefBankaHesapid,
                            $durumid,
                            $odemeYapildi,
                            $kayitliDosya,
                            $odeme['odeme_aciklama'] ?? null,
                            $user['kullanici_id'],
                            $odemeid
                        ]);
                    } else {
                        // Yeni kayit
                        $db->execute("
                            INSERT INTO Sozlesme_Odemeler (
                                odeme_sozlesme_id, odeme_tipi_id, odeme_durum_id,
                                odeme_belge_no, odeme_tarih, odeme_vade_tarih, odeme_tutar,
                                odeme_personel_id, odeme_banka_hesap_id,
                                odeme_guncel_personel_id, odeme_guncel_banka_hesap_id,
                                odeme_yapildi, odeme_aciklama, odeme_olusturan_id, odeme_olusturma_tarihi
                            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, GETDATE())
                        ", [
                            $sozlesmeid,
                            $odeme['odeme_tip_id'],
                            $durumid,
                            $odeme['odeme_belge_no'] ?: null,
                            $odeme['odeme_tarih'],
                            $odeme['odeme_vade_tarihi'] ?: null,
                            $odeme['odeme_tutar'] ?: 0,
                            $hedefPersonelid,
                            $hedefBankaHesapid,
                            $hedefPersonelid,
                            $hedefBankaHesapid,
                            $odemeYapildi,
                            $odeme['odeme_aciklama'] ?? null,
                            $user['kullanici_id']
                        ]);
                        
                        // Yeni Ödeme id'sini al ve log kaydı oluştur
                        $yeniOdeme = $db->fetchOne("SELECT TOP 1 * FROM Sozlesme_Odemeler WHERE odeme_sozlesme_id = ? ORDER BY odeme_id DESC", [$sozlesmeid]);
                        if ($yeniOdeme) {
                            // Dosya yüklendiyse kaydet
                            if ($yeniDosya) {
                                $db->execute("UPDATE Sozlesme_Odemeler SET odeme_dosyalar = ? WHERE odeme_id = ?", [$yeniDosya, $yeniOdeme['odeme_id']]);
                            }
                            // Log kaydı oluştur (yeni ödeme eklendi)
                            logKayitDegisiklikleri($db, 'sozlesme-form', 'Sozlesme_Odemeler', $yeniOdeme['odeme_id'], null, $yeniOdeme, $user['kullanici_id'], 'Yeni ödeme eklendi');
                        }
                    }
                    
                    $odemeIndex++;
                }
                
                echo json_encode(['success' => true, 'message' => 'Sozlesme kaydedildi!', 'id' => $sozlesmeid]);
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
                $isAdminKilit = ($user['departman_id'] == 1);
                if (!$kilitle && $mevcutKilit['odeme_kilit_kullanici_id'] && $mevcutKilit['odeme_kilit_kullanici_id'] != $user['kullanici_id'] && !$isAdminKilit) {
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

            case 'change_odeme_durum':
                $odemeid = $_POST['odeme_id'] ?? 0;
                $yenidurumid = $_POST['yeni_durum_id'] ?? 0;
                $hedefPersonelid = $_POST['hedef_personel_id'] ?: null;
                $hedefBankaHesapid = $_POST['hedef_banka_hesap_id'] ?: null;
                $aciklama = trim($_POST['aciklama'] ?? '');
                
                // Mevcut durumu al (log için)
                $eskiOdeme = $db->fetchOne("SELECT * FROM Sozlesme_Odemeler WHERE odeme_id = ?", [$odemeid]);
                if (!$eskiOdeme) {
                    echo json_encode(['success' => false, 'message' => 'Ödeme bulunamadı!']);
                    exit;
                }
                
                // ödemeyi güncelle
                $db->execute("
                    UPDATE Sozlesme_Odemeler SET
                        odeme_durum_id = ?,
                        odeme_guncel_personel_id = ?,
                        odeme_guncel_banka_hesap_id = ?,
                        odeme_guncelleyen_id = ?,
                        odeme_guncelleme_tarihi = GETDATE()
                    WHERE odeme_id = ?
                ", [$yenidurumid, $hedefPersonelid, $hedefBankaHesapid, $user['kullanici_id'], $odemeid]);
                
                // Log kaydı oluştur
                $yeniOdeme = $db->fetchOne("SELECT * FROM Sozlesme_Odemeler WHERE odeme_id = ?", [$odemeid]);
                logKayitDegisiklikleri($db, 'sozlesme-form', 'Sozlesme_Odemeler', $odemeid, $eskiOdeme, $yeniOdeme, $user['kullanici_id'], $aciklama ?: 'Durum değiştirildi');
                
                echo json_encode(['success' => true, 'message' => 'Durum güncellendi!']);
                break;
                
            case 'delete_odeme':
                $odemeid = $_POST['odeme_id'] ?? 0;
                
                if (!$odemeid) {
                    echo json_encode(['success' => false, 'message' => 'Ödeme id gerekli!']);
                    exit;
                }
                
                // Ödeme bilgisini al (dosya silmek ve log için)
                $eskiOdeme = $db->fetchOne("SELECT * FROM Sozlesme_Odemeler WHERE odeme_id = ?", [$odemeid]);
                
                // Log kaydı oluştur (silme)
                logKayitDegisiklikleri($db, 'sozlesme-form', 'Sozlesme_Odemeler', $odemeid, $eskiOdeme, null, $user['kullanici_id'], 'Ödeme silindi');
                
                // ödemeyi sil
                $db->execute("DELETE FROM Sozlesme_Odemeler WHERE odeme_id = ?", [$odemeid]);
                
                // Dosya varsa sil
                if ($eskiOdeme && !empty($eskiOdeme['odeme_dosyalar'])) {
                    $dosyaYolu = __DIR__ . '/../assets/uploads/sozlesme_odemeler/' . $eskiOdeme['odeme_dosyalar'];
                    if (file_exists($dosyaYolu)) {
                        unlink($dosyaYolu);
                    }
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
                
            case 'get_ilceler':
                $sehirId = intval($_POST['sehir_id'] ?? 0);
                $ilcelerData = $db->fetchAll("SELECT ilceId, IlceAdi FROM Adres_Ilceler WHERE SehirId = ? ORDER BY IlceAdi", [$sehirId]);
                echo json_encode(['success' => true, 'data' => $ilcelerData]);
                break;
                
            case 'delete_cari_dosya':
                $dosyaId = intval($_POST['dosya_id'] ?? 0);
                if (!$dosyaId) {
                    echo json_encode(['success' => false, 'message' => 'Dosya ID gerekli!']);
                    exit;
                }
                $dosyaKayit = $db->fetchOne("SELECT dosya_yol FROM Cari_Dosyalar WHERE dosya_id = ?", [$dosyaId]);
                if ($dosyaKayit) {
                    $dosyaYolu = '../' . str_replace('admin/', '', $dosyaKayit['dosya_yol']);
                    if (file_exists($dosyaYolu)) {
                        unlink($dosyaYolu);
                    }
                    $db->execute("DELETE FROM Cari_Dosyalar WHERE dosya_id = ?", [$dosyaId]);
                }
                echo json_encode(['success' => true, 'message' => 'Dosya silindi!']);
                break;
                
            default:
                echo json_encode(['success' => false, 'message' => 'Gecersiz islem!']);
        }
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'message' => 'Hata: ' . $e->getMessage()]);
    }
    exit;
}

$pageTitle = $isEdit ? 'Sözleşme Düzenle' : 'Yeni Sözleşme';
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
            background: #fff;
            border: 1px solid #e9ecef;
            border-radius: 6px;
            padding: 6px 10px;
            transition: background 0.2s;
        }
        .dosya-item:hover {
            background: #f0f4ff;
            border-color: #c5d1e8;
        }
        .dosya-item .btn-close {
            font-size: 0.55rem;
        }
        /* Sürükle-Bırak Dropzone */
        .dropzone-area {
            border: 2px dashed #adb5bd;
            border-radius: 8px;
            padding: 25px 15px;
            text-align: center;
            cursor: pointer;
            transition: all 0.3s ease;
            background: #f8f9fa;
            min-height: 120px;
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
        }
        .dropzone-area:hover {
            border-color: #0d6efd;
            background: #e8f0fe;
        }
        .dropzone-area.dragover {
            border-color: #0d6efd;
            background: #cfe2ff;
            border-style: solid;
        }
        .dropzone-area .dropzone-icon {
            font-size: 2rem;
            color: #6c757d;
            margin-bottom: 8px;
        }
        .dropzone-area.dragover .dropzone-icon {
            color: #0d6efd;
        }
        .dropzone-area .dropzone-text {
            color: #6c757d;
            font-size: 0.9rem;
        }
        .dropzone-area .dropzone-text strong {
            color: #0d6efd;
            cursor: pointer;
        }
        .dropzone-preview {
            display: flex;
            flex-wrap: wrap;
            gap: 6px;
            margin-top: 10px;
        }
        .dropzone-preview .preview-item {
            display: inline-flex;
            align-items: center;
            background: #e9ecef;
            border: 1px solid #ced4da;
            border-radius: 4px;
            padding: 4px 8px;
            font-size: 0.82rem;
        }
        .dropzone-preview .preview-item .btn-close {
            font-size: 0.55rem;
            margin-left: 6px;
        }
    </style>
</head>
<body class="layout-fixed sidebar-expand-lg sidebar-open bg-body-tertiary">
    <div class="app-wrapper">
        <?php include __DIR__ . '/../includes/header.php'; ?>
        <?php include __DIR__ . '/../includes/sidebar.php'; ?>
        
        <main class="app-main">
            <!-- Sayfa Basligi -->
            <div class="app-content-header">
                <div class="container-fluid">
                    <div class="row">
                        <div class="col-sm-6">
                            <h3 class="mb-0"><?= htmlspecialchars($pageTitle) ?></h3>
                        </div>
                        <div class="col-sm-6">
                            <ol class="breadcrumb float-sm-end">
                                <li class="breadcrumb-item"><a href="/admin/sozlesme-yonetimi">Sözleşme Yönetimi</a></li>
                                <li class="breadcrumb-item active"><?= htmlspecialchars($pageTitle) ?></li>
                            </ol>
                        </div>
                    </div>
                </div>
            </div>
            
            <div class="app-content">
                <div class="container-fluid">
                    <form id="sozlesmeForm" enctype="multipart/form-data">
                        <input type="hidden" name="sozlesme_id" value="<?= $editid ?>">
                        <input type="hidden" name="cari_id" id="cari_id" value="<?= $cariDetay['cari_id'] ?? 0 ?>">
                        <input type="hidden" name="mevcut_dosyalar" id="mevcut_dosyalar" value='<?= $sozlesme ? htmlspecialchars($sozlesme['sozlesme_dosyalar'] ?? '[]') : '[]' ?>'>
                        
                        <!-- ========================================= -->
                        <!-- 1. MÜŞTERİ BİLGİLERİ KARTI -->
                        <!-- ========================================= -->
                        <div class="card card-info card-outline mb-3">
                            <div class="card-header">
                                <h3 class="card-title">
                                    <i class="bi bi-person-vcard"></i> Müşteri Bilgileri
                                </h3>
                            </div>
                            <div class="card-body">
                                <div class="row g-3">
                                    <div class="col-md-4">
                                        <label class="form-label">Müşteri Adı <span class="text-danger">*</span></label>
                                        <input type="text" class="form-control" name="musteri_adi" id="musteri_adi" required
                                               value="<?= htmlspecialchars($cariDetay['cari_adi'] ?? '') ?>">
                                    </div>
                                    <div class="col-md-4">
                                        <label class="form-label">Ünvan</label>
                                        <input type="text" class="form-control" name="musteri_unvan" id="musteri_unvan"
                                               value="<?= htmlspecialchars($cariDetay['cari_unvan'] ?? '') ?>">
                                    </div>
                                    <div class="col-md-2">
                                        <label class="form-label">Vergi Dairesi</label>
                                        <input type="text" class="form-control" name="musteri_vergi_dairesi" id="musteri_vergi_dairesi"
                                               value="<?= htmlspecialchars($cariDetay['cari_vergi_dairesi'] ?? '') ?>">
                                    </div>
                                    <div class="col-md-2">
                                        <label class="form-label">Vergi No</label>
                                        <input type="text" class="form-control" name="musteri_vergi_no" id="musteri_vergi_no"
                                               value="<?= htmlspecialchars($cariDetay['cari_vergi_no'] ?? '') ?>">
                                    </div>
                                    <div class="col-md-2">
                                        <label class="form-label">Telefon</label>
                                        <input type="text" class="form-control" name="musteri_telefon" id="musteri_telefon"
                                               value="<?= htmlspecialchars($cariDetay['cari_telefon'] ?? '') ?>" maxlength="10" placeholder="5XX XXX XX XX">
                                    </div>
                                    <div class="col-md-3">
                                        <label class="form-label">Yetkili Adı Soyadı</label>
                                        <input type="text" class="form-control" name="musteri_yetkili_adi" id="musteri_yetkili_adi"
                                               value="<?= htmlspecialchars($cariDetay['cari_yetkili_adi'] ?? '') ?>">
                                    </div>
                                    <div class="col-md-2">
                                        <label class="form-label">Yetkili Telefon</label>
                                        <input type="text" class="form-control" name="musteri_yetkili_telefon" id="musteri_yetkili_telefon"
                                               value="<?= htmlspecialchars($cariDetay['cari_yetkili_telefon'] ?? '') ?>" maxlength="10" placeholder="5XX XXX XX XX">
                                    </div>
                                    <div class="col-md-2">
                                        <label class="form-label">Şehir</label>
                                        <select class="form-select" name="musteri_sehir" id="musteri_sehir">
                                            <option value="">Seçiniz...</option>
                                            <?php foreach ($sehirler as $sehir): ?>
                                                <option value="<?= $sehir['SehirId'] ?>" <?= ($cariDetay && $cariDetay['cari_sehirler'] == $sehir['SehirId']) ? 'selected' : '' ?>>
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
                                                <option value="<?= $ilce['ilceId'] ?>" <?= ($cariDetay && $cariDetay['cari_ilceler'] == $ilce['ilceId']) ? 'selected' : '' ?>>
                                                    <?= htmlspecialchars($ilce['IlceAdi']) ?>
                                                </option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                    <div class="col-md-5">
                                        <label class="form-label">Adres</label>
                                        <input type="text" class="form-control" name="musteri_adres" id="musteri_adres"
                                               value="<?= htmlspecialchars($cariDetay['cari_adres'] ?? '') ?>">
                                    </div>
                                    <div class="col-md-5">
                                        <label class="form-label">Web Sitesi</label>
                                        <input type="url" class="form-control" name="musteri_web_sitesi" id="musteri_web_sitesi"
                                               value="<?= htmlspecialchars($cariDetay['cari_web_sitesi'] ?? '') ?>" placeholder="https://">
                                    </div>
                                </div>
                                
                                <!-- Cari Dosyalar -->
                                <hr class="my-3">
                                <div class="row">
                                    <div class="col-md-5">
                                        <label class="form-label"><i class="bi bi-paperclip"></i> Müşteri Dosyaları</label>
                                        <input type="file" class="form-control d-none" name="cari_dosyalar[]" id="cari_dosyalar" multiple>
                                        <div class="dropzone-area" id="cariDropzone">
                                            <i class="bi bi-cloud-arrow-up dropzone-icon"></i>
                                            <div class="dropzone-text">
                                                Dosyaları buraya <strong>sürükleyin</strong> veya <strong>tıklayarak seçin</strong>
                                            </div>
                                            <small class="text-muted mt-1">PDF, DOC, DOCX, XLS, XLSX, JPG, PNG</small>
                                        </div>
                                        <div class="dropzone-preview" id="cariDropzonePreview"></div>
                                    </div>
                                    <div class="col-md-7">
                                        <label class="form-label">Mevcut Dosyalar <span class="badge bg-secondary" id="cariDosyaSayisi"><?= count($cariDosyalar) ?></span></label>
                                        <div id="cariDosyalarContainer" class="border rounded p-2" style="max-height: 220px; overflow-y: auto; background: #fafbfc;">
                                            <?php if (!empty($cariDosyalar)): ?>
                                                <?php foreach ($cariDosyalar as $dosya): 
                                                    $ext = strtolower(pathinfo($dosya['dosya_orijinal'] ?? '', PATHINFO_EXTENSION));
                                                    $icon = 'bi-file-earmark';
                                                    $iconColor = '#6c757d';
                                                    if (in_array($ext, ['jpg','jpeg','png','gif'])) { $icon = 'bi-file-earmark-image'; $iconColor = '#198754'; }
                                                    elseif ($ext === 'pdf') { $icon = 'bi-file-earmark-pdf'; $iconColor = '#dc3545'; }
                                                    elseif (in_array($ext, ['doc','docx'])) { $icon = 'bi-file-earmark-word'; $iconColor = '#0d6efd'; }
                                                    elseif (in_array($ext, ['xls','xlsx'])) { $icon = 'bi-file-earmark-excel'; $iconColor = '#198754'; }
                                                ?>
                                                    <div class="dosya-item d-flex align-items-center mb-1" data-dosya-id="<?= $dosya['dosya_id'] ?>" style="background:#fff;">
                                                        <i class="bi <?= $icon ?> me-2" style="font-size:1.2rem; color:<?= $iconColor ?>"></i>
                                                        <a href="/<?= htmlspecialchars($dosya['dosya_yol']) ?>" target="_blank" class="text-truncate" style="max-width:200px;" title="<?= htmlspecialchars($dosya['dosya_orijinal']) ?>">
                                                            <?= htmlspecialchars($dosya['dosya_orijinal']) ?>
                                                        </a>
                                                        <small class="text-muted ms-2">(<?= round(($dosya['dosya_boyut'] ?? 0) / 1024) ?> KB)</small>
                                                        <?php if (!empty($dosya['dosya_tarih'])): ?>
                                                            <small class="text-muted ms-2"><i class="bi bi-clock"></i> <?= date('d.m.Y', strtotime($dosya['dosya_tarih'])) ?></small>
                                                        <?php endif; ?>
                                                        <button type="button" class="btn-close ms-auto" onclick="removeCariDosya(<?= $dosya['dosya_id'] ?>, this)"></button>
                                                    </div>
                                                <?php endforeach; ?>
                                            <?php else: ?>
                                                <span class="text-muted" id="cariDosyaBos">Henüz dosya yüklenmemiş</span>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                        
                        <!-- ========================================= -->
                        <!-- 2. SÖZLEŞME BİLGİLERİ KARTI -->
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
                                        <label class="form-label">Sezon <span class="text-danger">*</span></label>
                                        <select class="form-select" name="sezon_id" id="sezon_id" required>
                                            <option value="">Seciniz...</option>
                                            <?php foreach ($sezonlar as $sezon): ?>
                                                <option value="<?= $sezon['sezon_id'] ?>" <?= ($sozlesme && $sozlesme['sozlesme_sezon_id'] == $sezon['sezon_id']) ? 'selected' : ((!$sozlesme && count($sezonlar) === 1) ? 'selected' : '') ?>>
                                                    <?= htmlspecialchars($sezon['sezon_ad']) ?>
                                                </option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                    <div class="col-md-2">
                                        <label class="form-label">tarih <span class="text-danger">*</span></label>
                                        <input type="date" class="form-control" name="tarih" id="tarih" required
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
                                            <option value="">Seciniz...</option>
                                            <?php foreach ($personeller as $personel): ?>
                                                <option value="<?= $personel['kullanici_id'] ?>" <?= ($sozlesme && $sozlesme['sozlesme_personel_id'] == $personel['kullanici_id']) ? 'selected' : '' ?>>
                                                    <?= htmlspecialchars($personel['personel_adi']) ?>
                                                </option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                    <div class="col-md-3">
                                        <label class="form-label">Sorumluluk Alanı</label>
                                        <select class="form-select" name="sorumlu_kullanici_id" id="sorumlu_kullanici_id">
                                            <option value="">Seciniz...</option>
                                            <?php foreach ($sorumlular as $sorumlu): ?>
                                                <option value="<?= $sorumlu['kullanici_id'] ?>" <?= ($sozlesme && $sozlesme['sozlesme_sorumlu_kullanici_id'] == $sorumlu['kullanici_id']) ? 'selected' : '' ?>>
                                                    <?= htmlspecialchars($sorumlu['sorumlu_adi']) ?><?= $sorumlu['departman_adi'] ? ' (' . htmlspecialchars($sorumlu['departman_adi']) . ')' : '' ?>
                                                </option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                    <div class="col-md-2">
                                        <label class="form-label">Açıklama</label>
                                        <input type="text" class="form-control" name="aciklama" id="aciklama"
                                               value="<?= htmlspecialchars($sozlesme['sozlesme_aciklama'] ?? '') ?>">
                                    </div>
                                    <div class="col-md-2">
                                        <label class="form-label">Durum</label>
                                        <select class="form-select" name="durum" id="durum">
                                            <option value="1" <?= (!$sozlesme || $sozlesme['sozlesme_durum'] == 1) ? 'selected' : '' ?>>Aktif</option>
                                            <option value="0" <?= ($sozlesme && $sozlesme['sozlesme_durum'] == 0) ? 'selected' : '' ?>>Pasif</option>
                                        </select>
                                    </div>
                                </div>
                                
                                <!-- Mevcut Dosyalar -->
                                <div class="row mt-2">
                                    <div class="col-12">
                                        <div id="mevcutDosyalarContainer">
                                            <?php 
                                            if ($sozlesme && $sozlesme['sozlesme_dosyalar']) {
                                                $dosyalar = json_decode($sozlesme['sozlesme_dosyalar'], true) ?: [];
                                                foreach ($dosyalar as $dosya):
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
                        
                        <!-- Stok Hareketleri Karti -->
                        <div class="card card-success card-outline mb-3">
                            <div class="card-header">
                                <h3 class="card-title">
                                    <i class="bi bi-list-check"></i> Ürün/Hizmet Listesi
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
                                            <!-- Bos satır -->
                                            <tr class="hareket-row" data-row-id="0">
                                                <td>
                                                    <select class="form-select uye-tipi-select" name="uye_tipi_id[]">
                                                        <option value="">Seciniz</option>
                                                        <?php foreach ($uyeTipleri as $tip): ?>
                                                            <option value="<?= $tip['uye_tipi_id'] ?>"><?= htmlspecialchars($tip['uye_tipi_ad']) ?></option>
                                                        <?php endforeach; ?>
                                                    </select>
                                                </td>
                                                <td>
                                                    <select class="form-select ticari-grup-select" name="ticari_grup_id[]">
                                                        <option value="">Seciniz</option>
                                                        <?php foreach ($ticariGruplar as $grup): ?>
                                                            <option value="<?= $grup['ticari_grup_id'] ?>"><?= htmlspecialchars($grup['ticari_grup_kod']) ?> - <?= htmlspecialchars($grup['ticari_grup_ad']) ?></option>
                                                        <?php endforeach; ?>
                                                    </select>
                                                </td>
                                                <td>
                                                    <input type="number" class="form-control" name="uye_no_1[]" placeholder="0">
                                                </td>
                                                <td>
                                                    <input type="number" class="form-control" name="uye_no_2[]" placeholder="0">
                                                </td>
                                                <td>
                                                    <select class="form-select urun-select" name="urun_hizmet_id[]">
                                                        <option value="">Seciniz</option>
                                                        <?php foreach ($urunler as $urun): ?>
                                                            <option value="<?= $urun['urun_hizmet_id'] ?>" data-hizmet-suresi="<?= intval($urun['hizmetSuresi'] ?? 0) ?>"><?= htmlspecialchars($urun['urun_hizmet_adi']) ?></option>
                                                        <?php endforeach; ?>
                                                    </select>
                                                </td>
                                                <td>
                                                    <input type="number" class="form-control text-end" name="fiyat[]" step="0.01" placeholder="0.00">
                                                </td>
                                                <td>
                                                    <input type="date" class="form-control" name="aktivasyon_tarihi[]">
                                                </td>
                                                <td>
                                                    <input type="date" class="form-control taahut-bitis-input" name="taahut_bitis[]">
                                                </td>
                                                <td class="text-center">
                                                    <input type="checkbox" class="form-check-input" name="hareket_durum[]" value="1" checked>
                                                </td>
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
                                                            <option value="">Seciniz</option>
                                                            <?php foreach ($uyeTipleri as $tip): ?>
                                                                <option value="<?= $tip['uye_tipi_id'] ?>" <?= $hareket['hareket_uye_tipi_id'] == $tip['uye_tipi_id'] ? 'selected' : '' ?>>
                                                                    <?= htmlspecialchars($tip['uye_tipi_ad']) ?>
                                                                </option>
                                                            <?php endforeach; ?>
                                                        </select>
                                                    </td>
                                                    <td>
                                                        <select class="form-select ticari-grup-select" name="ticari_grup_id[]">
                                                            <option value="">Seciniz</option>
                                                            <?php foreach ($ticariGruplar as $grup): ?>
                                                                <option value="<?= $grup['ticari_grup_id'] ?>" <?= $hareket['hareket_ticari_grup_id'] == $grup['ticari_grup_id'] ? 'selected' : '' ?>>
                                                                    <?= htmlspecialchars($grup['ticari_grup_kod']) ?> - <?= htmlspecialchars($grup['ticari_grup_ad']) ?>
                                                                </option>
                                                            <?php endforeach; ?>
                                                        </select>
                                                    </td>
                                                    <td>
                                                        <input type="number" class="form-control" name="uye_no_1[]" value="<?= $hareket['hareket_uye_no_1'] ?>">
                                                    </td>
                                                    <td>
                                                        <input type="number" class="form-control" name="uye_no_2[]" value="<?= $hareket['hareket_uye_no_2'] ?>">
                                                    </td>
                                                    <td>
                                                        <select class="form-select urun-select" name="urun_hizmet_id[]">
                                                            <option value="">Seciniz</option>
                                                            <?php foreach ($urunler as $urun): ?>
                                                                <option value="<?= $urun['urun_hizmet_id'] ?>" data-hizmet-suresi="<?= intval($urun['hizmetSuresi'] ?? 0) ?>" <?= $hareket['hareket_urun_hizmet_id'] == $urun['urun_hizmet_id'] ? 'selected' : '' ?>>
                                                                    <?= htmlspecialchars($urun['urun_hizmet_adi']) ?>
                                                                </option>
                                                            <?php endforeach; ?>
                                                        </select>
                                                    </td>
                                                    <td>
                                                        <input type="number" class="form-control text-end" name="fiyat[]" step="0.01" value="<?= number_format($hareket['hareket_fiyat'], 2, '.', '') ?>">
                                                    </td>
                                                    <td>
                                                        <input type="date" class="form-control" name="aktivasyon_tarihi[]" 
                                                               value="<?= $hareket['hareket_aktivasyon_tarihi'] ?? '' ?>">
                                                    </td>
                                                    <td>
                                                        <input type="date" class="form-control taahut-bitis-input" name="taahut_bitis[]"
                                                               value="<?= $hareket['hareket_taahut_bitis'] ?? '' ?>">
                                                    </td>
                                                    <td class="text-center">
                                                        <input type="checkbox" class="form-check-input" name="hareket_durum[]" value="1" <?= $hareket['hareket_durum'] ? 'checked' : '' ?>>
                                                    </td>
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
                                                <td class="text-end fw-bold" id="toplamTutar">0,00 TL</td>
                                                <td colspan="4"></td>
                                            </tr>
                                        </tfoot>
                                    </table>
                                </div>
                            </div>
                        </div>
                        
                        <!-- Ödeme Yöntemleri -->
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
                                                <th style="width:150px;">Açıklama</th>
                                                <th style="width:120px;">Durum</th>
                                                <th style="width:120px;" class="text-center">Ödeme Yapıldı</th>
                                                <th style="width:120px;">Ödeme Tarihi</th>
                                                <th style="width:60px;" class="text-center" title="Satırı kilitle — sadece kilitleyen kişi düzenleyebilir"><i class="bi bi-lock"></i></th>
                                                <th style="width:80px;">İşlem</th>
                                            </tr>
                                        </thead>
                                        <tbody id="odemeSatirlar">
                                            <?php if (!empty($odemeler)): ?>
                                                <?php $isAdminUser = ($user['departman_id'] == 1); ?>
                                                <?php foreach ($odemeler as $odeme):
                                                    $kilitliMi = !empty($odeme['odeme_kilit_kullanici_id']);
                                                    $benimKilidigim = $kilitliMi && $odeme['odeme_kilit_kullanici_id'] == $user['kullanici_id'];
                                                    $baskasiKilitledi = $kilitliMi && !$benimKilidigim;
                                                    $kilitleyen = htmlspecialchars($odeme['kilit_kullanici_adi'] ?? '');
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
                                                                    <img src="<?= $dosyaUrl ?>" alt="Ödeme Görseli" style="max-width:60px;max-height:40px;object-fit:cover;border-radius:4px;cursor:pointer;" title="Büyütmek için tıklayın">
                                                                </a>
                                                            <?php else: ?>
                                                                <a href="<?= $dosyaUrl ?>" target="_blank" class="btn btn-sm btn-outline-danger"><i class="bi bi-file-earmark-pdf"></i> PDF</a>
                                                            <?php endif; ?>
                                                        <?php endif; ?>
                                                        <input type="file" class="form-control form-control-sm mt-1" name="odeme_dosya[]" accept=".pdf,.jpg,.jpeg,.png" onchange="previewOdemeDosya(this)" <?= $dis ?>>
                                                        <div class="odeme-dosya-preview mt-1"></div>
                                                        <input type="hidden" name="odeme_mevcut_dosya[]" value="<?= htmlspecialchars($odeme['odeme_dosyalar'] ?? '') ?>">
                                                    </td>
                                                    <td><input type="text" class="form-control form-control-sm" name="odeme_aciklama[]" value="<?= htmlspecialchars($odeme['odeme_aciklama'] ?? '') ?>" placeholder="Açıklama" <?= $dis ?>></td>
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
                                                <td class="text-end fw-bold" id="odemeToplam">0,00 <?= $parabirimiSimge ?></td>
                                                <td colspan="8"><small class="text-muted" id="odemeFark"></small></td>
                                            </tr>
                                            <tr>
                                                <td colspan="3" class="text-end fw-bold text-success">Toplam Ödenen:</td>
                                                <td class="text-end fw-bold text-success" id="toplamOdenen">0,00 <?= $parabirimiSimge ?></td>
                                                <td colspan="8"></td>
                                            </tr>
                                            <tr>
                                                <td colspan="3" class="text-end fw-bold text-danger">Toplam Ödenecek:</td>
                                                <td class="text-end fw-bold text-danger" id="toplamOdenecek">0,00 <?= $parabirimiSimge ?></td>
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
                                    <div>
                                        <a href="/admin/sozlesme-yonetimi" class="btn btn-secondary">
                                            <i class="bi bi-arrow-left"></i> Geri
                                        </a>
                                        <?php if ($isEdit): ?>
                                        <a href="/admin/pages/degisiklik-log.php?sozlesme_id=<?= $editid ?>" class="btn btn-info" target="_blank">
                                            <i class="bi bi-clock-history"></i> Log Geçmişi
                                        </a>
                                        <?php endif; ?>
                                    </div>
                                    <div>
                                        <?php if ($isEdit && $pagePermissions['can_delete']): ?>
                                        <button type="button" class="btn btn-danger" onclick="formKayitSil('/admin/sozlesme-yonetimi', { action: 'delete', id: <?= $editid ?> }, '/admin/sozlesme-yonetimi', 'Bu sözleşmeyi silmek istediğinize emin misiniz?', 'Sözleşmeye ait tüm stok hareketleri de silinecek!')">
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
    let rowcounter = <?= count($hareketler) ?>;
    
    // Dropdown verileri (JavaScript icin)
    const uyeTipleri = <?= json_encode($uyeTipleri) ?>;
    const ticariGruplar = <?= json_encode($ticariGruplar) ?>;
    const urunler = <?= json_encode($urunler) ?>;
    const sezonlarJS = <?= json_encode($sezonlar) ?>;
    
    $(document).ready(function() {
        // Select2 init
        initSelect2();
        
        // Toplam hesapla
        calculateTotal();
        
        // Fiyat degisikliginde toplam hesapla
        $(document).on('change keyup', 'input[name="fiyat[]"]', function() {
            calculateTotal();
            calculateOdemeToplam(); // ürün toplami değişince fark da güncellenir
        });

        // Ürün/Hizmet veya Aktivasyon Tarihi değişince Taahhüt Bitiş'i otomatik hesapla
        $(document).on('change', '.urun-select, input[name="aktivasyon_tarihi[]"]', function() {
            const row = $(this).closest('tr');
            updateTaahutBitisByHizmetSuresi(row);
        });

        // Taahhüt Bitiş elle değiştirilebilir; aktivasyon tarihinden düşük olamaz
        $(document).on('change', 'input[name="taahut_bitis[]"]', function() {
            const row = $(this).closest('tr');
            const aktivasyonTarihi = row.find('[name="aktivasyon_tarihi[]"]').val();
            const taahutInput = row.find('[name="taahut_bitis[]"]');
            const taahutBitis = taahutInput.val();

            if (aktivasyonTarihi) {
                taahutInput.attr('min', aktivasyonTarihi);
            } else {
                taahutInput.removeAttr('min');
            }

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
                if (ilceSelect.hasClass('select2-hidden-accessible')) {
                    ilceSelect.trigger('change.select2');
                }
            });
        });
        
        // Form submit
        $('#sozlesmeForm').on('submit', function(e) {
            e.preventDefault();
            saveForm();
        });

        // Sayfa açılışında mevcut satırları hizmet süresine göre senkronla
        $('#hareketlerBody tr').each(function() {
            updateTaahutBitisByHizmetSuresi($(this));
        });
        
        // Sayfa açılışında mevcut ödeme satırlarının vade tarihi kontrolü
        $('#odemeSatirlar tr').each(function() {
            const tipid = $(this).find('.odeme-tip-select').val();
            const vadeTarihiInput = $(this).find('input[name="odeme_vade_tarihi[]"]');
            // Ödeme tipi 1, 4 veya 5 ise vade tarihini gizle
            if (tipid == 1 || tipid == 4 || tipid == 5) {
                vadeTarihiInput.hide();
            }
        });
    });
    
    function initSelect2() {
        $('#sezon_id, #personel_id, #sorumlu_kullanici_id, #durum, #musteri_sehir, #musteri_ilce').select2({
            theme: 'bootstrap-5',
            width: '100%',
            allowClear: true,
            placeholder: 'Seciniz...'
        });
        
        // Satır select'leri icin basit select2 (performans icin)
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

        const y = target.getFullYear();
        const m = String(target.getMonth() + 1).padStart(2, '0');
        const d = String(target.getDate()).padStart(2, '0');
        return `${y}-${m}-${d}`;
    }

    function updateTaahutBitisByHizmetSuresi(row) {
        const urunSelect = row.find('.urun-select');
        const selectedOption = urunSelect.find('option:selected');
        const hizmetSuresi = parseInt(selectedOption.data('hizmet-suresi') || 0, 10);
        const aktivasyonTarihi = row.find('[name="aktivasyon_tarihi[]"]').val();
        const taahutInput = row.find('[name="taahut_bitis[]"]');

        if (aktivasyonTarihi) {
            taahutInput.attr('min', aktivasyonTarihi);
        } else {
            taahutInput.removeAttr('min');
        }

        if (!aktivasyonTarihi || !hizmetSuresi) {
            return;
        }

        taahutInput.val(addMonthsToDate(aktivasyonTarihi, hizmetSuresi));
    }
    
    // Satır ekle
    function addHareketRow() {
        rowcounter++;
        
        let uyeTipiOptions = '<option value="">Seciniz</option>';
        uyeTipleri.forEach(tip => {
            uyeTipiOptions += `<option value="${tip.uye_tipi_id}">${tip.uye_tipi_ad}</option>`;
        });
        
        let ticariGrupOptions = '<option value="">Seciniz</option>';
        ticariGruplar.forEach(grup => {
            ticariGrupOptions += `<option value="${grup.ticari_grup_id}">${grup.ticari_grup_kod} - ${grup.ticari_grup_ad}</option>`;
        });
        
        let urunOptions = '<option value="">Seciniz</option>';
        urunler.forEach(urun => {
            urunOptions += `<option value="${urun.urun_hizmet_id}" data-hizmet-suresi="${parseInt(urun.hizmetSuresi || 0, 10)}">${urun.urun_hizmet_adi}</option>`;
        });
        
        const newRow = `
            <tr class="hareket-row" data-row-id="new_${rowcounter}">
                <td>
                    <select class="form-select uye-tipi-select" name="uye_tipi_id[]">
                        ${uyeTipiOptions}
                    </select>
                </td>
                <td>
                    <select class="form-select ticari-grup-select" name="ticari_grup_id[]">
                        ${ticariGrupOptions}
                    </select>
                </td>
                <td>
                    <input type="number" class="form-control" name="uye_no_1[]" placeholder="0">
                </td>
                <td>
                    <input type="number" class="form-control" name="uye_no_2[]" placeholder="0">
                </td>
                <td>
                    <select class="form-select urun-select" name="urun_hizmet_id[]">
                        ${urunOptions}
                    </select>
                </td>
                <td>
                    <input type="number" class="form-control text-end" name="fiyat[]" step="0.01" placeholder="0.00">
                </td>
                <td>
                    <input type="date" class="form-control" name="aktivasyon_tarihi[]">
                </td>
                <td>
                    <input type="date" class="form-control taahut-bitis-input" name="taahut_bitis[]">
                </td>
                <td class="text-center">
                    <input type="checkbox" class="form-check-input" name="hareket_durum[]" value="1" checked>
                </td>
                <td class="text-center">
                    <button type="button" class="btn btn-sm btn-outline-danger btn-remove-row" onclick="removeHareketRow(this)">
                        <i class="bi bi-x"></i>
                    </button>
                </td>
            </tr>
        `;
        
        $('#hareketlerBody').append(newRow);
        
        // Yeni satır select2 init
        const lastRow = $('#hareketlerBody tr:last');
        lastRow.find('.uye-tipi-select, .ticari-grup-select, .urun-select').select2({
            theme: 'bootstrap-5',
            width: '100%',
            minimumResultsForSearch: 10
        });
    }
    
    // Satır sil
    function removeHareketRow(btn) {
        const row = $(btn).closest('tr');
        
        // En az bir satır kalmali
        if ($('#hareketlerBody tr').length > 1) {
            row.find('.select2-hidden-accessible').select2('destroy');
            row.remove();
            calculateTotal();
        } else {
            showToast('En az bir satır olmalı!', 'warning');
        }
    }
    
    // Toplam hesapla
    function calculateTotal() {
        let total = 0;
        $('input[name="fiyat[]"]').each(function() {
            const val = parseFloat($(this).val()) || 0;
            total += val;
        });
        
        // Seçili cari'nin para birimi simgesini al
        const simge = '₺';
        
        $('#toplamTutar').text(total.toLocaleString('tr-TR', { minimumFractionDigits: 2, maximumFractionDigits: 2 }) + ' ' + simge);
    }
    
    // Form kaydet
    function saveForm() {
        // Hareketleri JSON olarak topla
        const hareketler = [];
        let hareketHatasi = false;
    
        $('#hareketlerBody tr').each(function(idx) {
            const row = $(this);
                    const aktivasyonTarihi = row.find('[name="aktivasyon_tarihi[]"]').val();
                    const taahutBitis = row.find('[name="taahut_bitis[]"]').val();
        
                    // Tarih validasyonu (ikisi de dolduysa, aktivasyon < taahut olmalı)
                    if (aktivasyonTarihi && taahutBitis) {
                        if (new Date(aktivasyonTarihi) > new Date(taahutBitis)) {
                            showToast('Satır ' + (idx + 1) + ': Aktivasyon Tarihi, Taahhüt Bitiş Tarihinden önce olmalı!', 'warning');
                            hareketHatasi = true;
                        }
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
                sezon_id: row.find('[name="hareket_sezon_id[]"]').val() || null,
                mevcut_evrak: row.find('[name="mevcut_evrak[]"]').val() || null,
                durum: row.find('[name="hareket_durum[]"]').is(':checked') ? 1 : 0
            });

            if (hareketHatasi) {
                return false;
            }
        });

        if (hareketHatasi) {
            return;
        }
        
        // ödemeleri JSON olarak topla
        const odemeler = [];
        $('#odemeSatirlar tr').each(function() {
            const row = $(this);
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
        
        // Form verilerini hazirla
        const formData = new FormData($('#sozlesmeForm')[0]);
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
                        window.location.href = '/admin/sozlesme-yonetimi';
                    }, 1000);
                } else {
                    showToast(response.message, 'error');
                }
            },
            error: function() {
                showToast('bir hata olustu!', 'error');
            }
        });
    }
    
    // Mevcut dosya sil
    function removeDosya(filePath, btn) {
        confirmAction(
            'Bu dosyayi kaldırmak istediginize emin misiniz?',
            'Dosya sunucudan da silinecek.', 
            function() {
                // Mevcut dosyalar listesinden kaldır
                let mevcutDosyalar = JSON.parse($('#mevcut_dosyalar').val() || '[]');
                mevcutDosyalar = mevcutDosyalar.filter(d => d !== filePath);
                $('#mevcut_dosyalar').val(JSON.stringify(mevcutDosyalar));
                
                // DOM'dan kaldır
                $(btn).closest('.dosya-item').remove();
                
                // Sunucudan sil
                $.post('', { action: 'delete_file', file_path: filePath });
                
                showToast('Dosya kaldırıldı', 'info');
            }
        );
    }
    
    // Cari dosya sil (Cari_Dosyalar tablosundan)
    function removeCariDosya(dosyaId, btn) {
        confirmAction(
            'Bu dosyayı silmek istediğinize emin misiniz?',
            'Dosya sunucudan da silinecek.',
            function() {
                $.post('', { action: 'delete_cari_dosya', dosya_id: dosyaId }, function(response) {
                    if (response.success) {
                        $(btn).closest('.dosya-item').remove();
                        // Sayacı güncelle
                        let kalan = $('#cariDosyalarContainer .dosya-item').length;
                        $('#cariDosyaSayisi').text(kalan);
                        showToast('Dosya silindi', 'info');
                        if (kalan === 0) {
                            $('#cariDosyalarContainer').html('<span class="text-muted" id="cariDosyaBos">Henüz dosya yüklenmemiş</span>');
                        }
                    } else {
                        showToast(response.message || 'Dosya silinemedi!', 'error');
                    }
                });
            }
        );
    }
    
    // Fatura dosyasını temizle
    function clearFaturaDosya(e) {
        e.preventDefault();
        $('#mevcut_fatura_dosya').val('');
        $('#fatura_dosya').val('');
        $(e.target).closest('small').remove();
    }
    
    // ======================================
    // SÜRÜKLE-BIRAK DROPZONE
    // ======================================
    const dropzone = document.getElementById('cariDropzone');
    const fileInput = document.getElementById('cari_dosyalar');
    const previewContainer = document.getElementById('cariDropzonePreview');
    const izinliUzantilar = ['pdf', 'doc', 'docx', 'xls', 'xlsx', 'jpg', 'jpeg', 'png'];
    let droppedFiles = new DataTransfer();
    
    // Tıklayınca file input aç
    dropzone.addEventListener('click', function() {
        fileInput.click();
    });
    
    // Drag olayları
    dropzone.addEventListener('dragover', function(e) {
        e.preventDefault();
        e.stopPropagation();
        this.classList.add('dragover');
    });
    dropzone.addEventListener('dragleave', function(e) {
        e.preventDefault();
        e.stopPropagation();
        this.classList.remove('dragover');
    });
    dropzone.addEventListener('drop', function(e) {
        e.preventDefault();
        e.stopPropagation();
        this.classList.remove('dragover');
        handleDroppedFiles(e.dataTransfer.files);
    });
    
    // File inputtan seçilince
    fileInput.addEventListener('change', function() {
        handleDroppedFiles(this.files);
    });
    
    function handleDroppedFiles(files) {
        for (let i = 0; i < files.length; i++) {
            const file = files[i];
            const ext = file.name.split('.').pop().toLowerCase();
            if (!izinliUzantilar.includes(ext)) {
                showToast(file.name + ' - desteklenmeyen dosya formatı!', 'warning');
                continue;
            }
            // Aynı isimde eklenmiş mi kontrol
            let duplicate = false;
            for (let j = 0; j < droppedFiles.files.length; j++) {
                if (droppedFiles.files[j].name === file.name && droppedFiles.files[j].size === file.size) {
                    duplicate = true;
                    break;
                }
            }
            if (duplicate) continue;
            droppedFiles.items.add(file);
        }
        fileInput.files = droppedFiles.files;
        renderDropzonePreview();
    }
    
    function renderDropzonePreview() {
        previewContainer.innerHTML = '';
        for (let i = 0; i < droppedFiles.files.length; i++) {
            const file = droppedFiles.files[i];
            const sizeKB = Math.round(file.size / 1024);
            const ext = file.name.split('.').pop().toLowerCase();
            let icon = 'bi-file-earmark';
            if (['jpg','jpeg','png'].includes(ext)) icon = 'bi-file-earmark-image';
            else if (ext === 'pdf') icon = 'bi-file-earmark-pdf';
            else if (['doc','docx'].includes(ext)) icon = 'bi-file-earmark-word';
            else if (['xls','xlsx'].includes(ext)) icon = 'bi-file-earmark-excel';
            
            const item = document.createElement('span');
            item.className = 'preview-item';
            item.innerHTML = '<i class="bi ' + icon + ' me-1"></i>' + file.name + ' <small class="text-muted ms-1">(' + sizeKB + ' KB)</small>' +
                '<button type="button" class="btn-close" data-idx="' + i + '"></button>';
            item.querySelector('.btn-close').addEventListener('click', function() {
                removeDroppedFile(parseInt(this.getAttribute('data-idx')));
            });
            previewContainer.appendChild(item);
        }
    }
    
    function removeDroppedFile(idx) {
        const newDt = new DataTransfer();
        for (let i = 0; i < droppedFiles.files.length; i++) {
            if (i !== idx) newDt.items.add(droppedFiles.files[i]);
        }
        droppedFiles = newDt;
        fileInput.files = droppedFiles.files;
        renderDropzonePreview();
    }
    
    // ======================================
    // ÖDEME YÖNETİMİ Fonksiyonlari
    // ======================================
    
    // Dropdown verileri (ödeme için)
    const odemeTipleri = <?= json_encode($odemeTipleri) ?>;
    const odemeDurumlari = <?= json_encode($odemeDurumlari) ?>;
    const personeller = <?= json_encode($personeller) ?>;
    const bankalar = <?= json_encode($bankalar) ?>;
    const bankaHesaplari = <?= json_encode($bankaHesaplari) ?>;
    const parabirimiSimge = '<?= addslashes($parabirimiSimge) ?>';
    
    let odemeRowcounter = <?= count($odemeler) ?>;
    
    // Ödeme satiri ekle
    function addOdemeRow() {
        odemeRowcounter++;
        
        let tipOptions = '<option value="">Seçiniz</option>';
        odemeTipleri.forEach(tip => {
            tipOptions += `<option value="${tip.odeme_tipi_id}" data-varsayılan-durum="${tip.odeme_tipi_varsayilan_durum_id}" data-dosya-onek="${tip.odeme_tipi_dosya_onek}">${tip.odeme_tipi_ad}</option>`;
        });
        
        let personelOptions = '<option value="">Personel Seç</option>';
        personeller.forEach(p => {
            personelOptions += `<option value="${p.kullanici_id}">${p.personel_adi}</option>`;
        });
        
        let bankaHesapOptions = '<option value="">Banka Hesap Seç</option>';
        bankaHesaplari.forEach(bh => {
            bankaHesapOptions += `<option value="${bh.bankaHesap_id}">${bh.banka_adi} - ${bh.bankaHesap_sube_adi || '-'}</option>`;
        });
        
        // Varsayılan durum bul (Personelde)
        let varsayilanDurumid = 1;
        let varsayilanBadge = 'secondary';
        let varsayilanDurumAdi = 'Personelde';
        odemeDurumlari.forEach(d => {
            if (d.odeme_durum_id == 1) {
                varsayilanBadge = d.odeme_durum_renk;
                varsayilanDurumAdi = d.odeme_durum_ad;
            }
        });
        
        const newRow = `
            <tr data-odeme-id="new_${odemeRowcounter}">
                <td>
                    <select class="form-select form-select-sm odeme-tip-select" name="odeme_tip_id[]" onchange="odemeTypeChanged(this)">
                        ${tipOptions}
                    </select>
                    <input type="hidden" name="odeme_id[]" value="">
                </td>
                <td>
                    <input type="text" class="form-control form-control-sm" name="odeme_belge_no[]" placeholder="Belge No">
                </td>
                <td>
                    <input type="date" class="form-control form-control-sm" name="odeme_vade_tarihi[]">
                </td>
                <td>
                    <input type="number" class="form-control form-control-sm text-end odeme-tutar" name="odeme_tutar[]" step="0.01" placeholder="0.00" onchange="calculateOdemeToplam()">
                </td>
                <td class="hedef-cell">
                    <select class="form-select form-select-sm personel-select" name="odeme_hedef_personel_id[]">
                        ${personelOptions}
                    </select>
                    <select class="form-select form-select-sm banka-hesap-select" name="odeme_hedef_banka_hesap_id[]" style="display:none;">
                        ${bankaHesapOptions}
                    </select>
                    <span class="no-hedef-text text-muted" style="display:none;">-</span>
                </td>
                <td>
                    <input type="file" class="form-control form-control-sm" name="odeme_dosya[]" accept=".pdf,.jpg,.jpeg,.png" onchange="previewOdemeDosya(this)">
                    <div class="odeme-dosya-preview mt-1"></div>
                    <input type="hidden" name="odeme_mevcut_dosya[]" value="">
                </td>
                <td>
                    <input type="text" class="form-control form-control-sm" name="odeme_aciklama[]" placeholder="Açıklama">
                </td>
                <td>
                    <span class="badge bg-${varsayilanBadge} durum-badge" style="cursor:pointer;" onclick="showDurumModal(this)" title="Durum değiştirmek için tıklayın">${varsayilanDurumAdi}</span>
                    <input type="hidden" name="odeme_durum_id[]" value="${varsayilanDurumid}">
                </td>
                <td class="text-center">
                    <div class="form-check d-flex justify-content-center">
                        <input class="form-check-input" type="checkbox" name="odeme_yapildi[]" value="1" onchange="calculateOdemeToplam()">
                    </div>
                </td>
                <td>
                    <input type="date" class="form-control form-control-sm" name="odeme_tarih[]" value="${new Date().toISOString().split('T')[0]}">
                </td>
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

    // Ödeme dosya önizleme
    function previewOdemeDosya(input) {
        const previewDiv = $(input).siblings('.odeme-dosya-preview');
        previewDiv.empty();
        
        if (!input.files || !input.files[0]) return;
        
        const file = input.files[0];
        const ext = file.name.split('.').pop().toLowerCase();
        
        if (['jpg', 'jpeg', 'png'].includes(ext)) {
            const reader = new FileReader();
            reader.onload = function(e) {
                previewDiv.html(`
                    <img src="${e.target.result}" alt="Önizleme" 
                         style="max-width:80px;max-height:50px;object-fit:cover;border-radius:4px;border:1px solid #dee2e6;">
                `);
            };
            reader.readAsDataURL(file);
        } else if (ext === 'pdf') {
            previewDiv.html('<span class="badge bg-danger"><i class="bi bi-file-earmark-pdf"></i> PDF seçildi</span>');
        }
    }
    
    // Ödeme satiri sil
    function removeOdemeRow(btn) {
        const row = $(btn).closest('tr');
        const odemeid = row.data('odeme-id');
        
        // Yeni satır mi yoksa mevcut kayit mi?
        if (odemeid && !String(odemeid).startsWith('new_')) {
            confirmAction(
                'Bu ödemeyi silmek istediğinize emin misiniz?',
                'Ödeme kaydi ve ilişkili hareketler silinecek.',
                function() {
                    $.post('', { action: 'delete_odeme', odeme_id: odemeid }, function(response) {
                        if (response.success) {
                            row.remove();
                            calculateOdemeToplam();
                            showToast('Ödeme silindi', 'success');
                        } else {
                            showToast(response.message, 'error');
                        }
                    });
                }
            );
        } else {
            row.remove();
            calculateOdemeToplam();
        }
    }
    
    // Ödeme toplamını hesapla
    function calculateOdemeToplam() {
        let toplam = 0;
        let toplamOdenen = 0;
        let toplamOdenecek = 0;
        
        $('#odemeSatirlar tr').each(function() {
            const val = parseFloat($(this).find('.odeme-tutar').val()) || 0;
            const yapıldı = $(this).find('[name="odeme_yapildi[]"]').is(':checked');
            toplam += val;
            if (yapıldı) {
                toplamOdenen += val;
            } else {
                toplamOdenecek += val;
            }
        });
        
        // Seçili cari'den para birimi simgesini al
        const simge = $('#cari_id option:selected').data('para-simge') || parabirimiSimge || '₺';
        const formatOpts = { minimumFractionDigits: 2, maximumFractionDigits: 2 };
        $('#odemeToplam').text(toplam.toLocaleString('tr-TR', formatOpts) + ' ' + simge);
        $('#toplamOdenen').text(toplamOdenen.toLocaleString('tr-TR', formatOpts) + ' ' + simge);
        $('#toplamOdenecek').text(toplamOdenecek.toLocaleString('tr-TR', formatOpts) + ' ' + simge);
        
        // ürün toplami ile karşılaştır (Türkçe format: 1.300,00 -> önce binlik ayraci kaldır, sonra virgülü noktaya çevir)
        let urunToplamText = $('#toplamTutar').text().replace(/[^0-9,.-]/g, ''); // "1.300,00"
        urunToplamText = urunToplamText.replace(/\./g, ''); // Binlik ayraci kaldır: "1300,00"
        urunToplamText = urunToplamText.replace(',', '.'); // Virgülü noktaya çevir: "1300.00"
        const urunToplam = parseFloat(urunToplamText) || 0;
        const fark = toplam - urunToplam;
        
        if (Math.abs(fark) > 0.01) {
            if (fark > 0) {
                $('#odemeFark').html(`<span class="text-danger">+${fark.toLocaleString('tr-TR', { minimumFractionDigits: 2, maximumFractionDigits: 2 })} ${simge} fazla</span>`);
            } else {
                $('#odemeFark').html(`<span class="text-warning">${fark.toLocaleString('tr-TR', { minimumFractionDigits: 2, maximumFractionDigits: 2 })} ${simge} eksik</span>`);
            }
        } else {
            $('#odemeFark').html('<span class="text-success"><i class="bi bi-check-circle"></i> Eşleşti</span>');
        }
    }
    
    // Ödeme tipi değiştiğinde
    function odemeTypeChanged(select) {
        const row = $(select).closest('tr');
        const tipid = $(select).val();
        const selectedOption = $(select).find('option:selected');
        const varsayilanDurumid = selectedOption.data('varsayılan-durum');
        
        // Vade tarihi inputunu bul (3. td - index 2)
        const vadeTarihiInput = row.find('input[name="odeme_vade_tarihi[]"]');
        
        // Ödeme tipi 1, 4 veya 5 ise vade tarihini gizle, aksi halde göster
        if (tipid == 1 || tipid == 4 || tipid == 5) {
            vadeTarihiInput.hide().val('');
        } else {
            vadeTarihiInput.show();
        }
        
        if (tipid && varsayilanDurumid) {
            // Varsayılan durumu ayarla
            row.find('[name="odeme_durum_id[]"]').val(varsayilanDurumid);
            
            // Durum badge'ini güncelle
            const durum = odemeDurumlari.find(d => d.odeme_durum_id == varsayilanDurumid);
            if (durum) {
                row.find('.badge').removeClass().addClass('badge bg-' + durum.odeme_durum_renk).text(durum.odeme_durum_ad);
                updateHedefFields(row, durum.odeme_durum_hedef_tipi, durum.odeme_durum_hedef_zorunlu);
            }
        }
    }
    
    // Hedef alanlarini güncelle
    function updateHedefFields(row, hedefTip, hedefZorunlu) {
        const personelSelect = row.find('.personel-select');
        const bankaHesapSelect = row.find('.banka-hesap-select');
        const noHedefText = row.find('.no-hedef-text');
        
        // Hepsini gizle ve required kaldır
        personelSelect.hide().prop('required', false);
        bankaHesapSelect.hide().prop('required', false);
        noHedefText.hide();
        
        if (hedefTip === 'personel') {
            personelSelect.show();
            if (hedefZorunlu == 1) {
                personelSelect.prop('required', true);
            }
        } else if (hedefTip === 'banka') {
            bankaHesapSelect.show();
            if (hedefZorunlu == 1) {
                bankaHesapSelect.prop('required', true);
            }
        } else {
            noHedefText.show();
        }
    }
    
    // Durum değiştirme modalini aç
    let currentDurumRow = null;
    function showDurumModal(btn) {
        currentDurumRow = $(btn).closest('tr');
        const mevcutDurumid = currentDurumRow.find('[name="odeme_durum_id[]"]').val();
        
        // Durum seçeneklerini doldur
        let options = '';
        odemeDurumlari.forEach(d => {
            const selected = d.odeme_durum_id == mevcutDurumid ? 'selected' : '';
            options += `<option value="${d.odeme_durum_id}" data-hedef-tip="${d.odeme_durum_hedef_tipi}" data-hedef-zorunlu="${d.odeme_durum_hedef_zorunlu}" data-final="${d.odeme_durum_final}" ${selected}>${d.odeme_durum_ad}</option>`;
        });
        $('#yenidurumSelect').html('<option value="">Seçiniz</option>' + options);
        
        // Personel seçenekleri
        let personelOpts = '<option value="">Personel Seç</option>';
        personeller.forEach(p => {
            personelOpts += `<option value="${p.kullanici_id}">${p.personel_adi}</option>`;
        });
        $('#modalPersonelSelect').html(personelOpts);
        
        // Banka Hesap seçenekleri
        let bankaHesapOpts = '<option value="">Banka Hesap Seç</option>';
        bankaHesaplari.forEach(bh => {
            bankaHesapOpts += `<option value="${bh.bankaHesap_id}">${bh.banka_adi} - ${bh.bankaHesap_sube_adi || '-'}</option>`;
        });
        $('#modalBankaHesapSelect').html(bankaHesapOpts);
        
        // Hedef alanlarini gizle
        $('#modalPersonelDiv, #modalBankaDiv').hide();
        $('#modalAciklama').val('');
        
        const modal = new bootstrap.Modal(document.getElementById('durumModal'));
        modal.show();
    }
    
    // Durum seçimi değiştiğinde
    $('#yenidurumSelect').on('change', function() {
        const selected = $(this).find('option:selected');
        const hedefTip = selected.data('hedef-tip');
        const hedefZorunlu = selected.data('hedef-zorunlu');
        
        $('#modalPersonelDiv, #modalBankaDiv').hide();
        $('#modalPersonelSelect, #modalBankaHesapSelect').prop('required', false);
        
        if (hedefTip === 'personel') {
            $('#modalPersonelDiv').show();
            if (hedefZorunlu == 1) {
                $('#modalPersonelSelect').prop('required', true);
            }
        } else if (hedefTip === 'banka') {
            $('#modalBankaDiv').show();
            if (hedefZorunlu == 1) {
                $('#modalBankaHesapSelect').prop('required', true);
            }
        }
    });
    
    // Durumu kaydet
    function saveDurumChange() {
        const odemeid = currentDurumRow.data('odeme-id');
        const yenidurumid = $('#yenidurumSelect').val();
        const hedefPersonelid = $('#modalPersonelSelect').val();
        const hedefBankaHesapid = $('#modalBankaHesapSelect').val();
        const aciklama = $('#modalAciklama').val();
        
        if (!yenidurumid) {
            showToast('Lütfen yeni durum seçin', 'warning');
            return;
        }
        
        // Yeni kayitsa sadece frontend güncelle
        if (!odemeid || String(odemeid).startsWith('new_')) {
            const durum = odemeDurumlari.find(d => d.odeme_durum_id == yenidurumid);
            if (durum) {
                currentDurumRow.find('[name="odeme_durum_id[]"]').val(yenidurumid);
                currentDurumRow.find('.badge').removeClass().addClass('badge bg-' + durum.odeme_durum_renk).text(durum.odeme_durum_ad);
                updateHedefFields(currentDurumRow, durum.odeme_durum_hedef_tipi, durum.odeme_durum_hedef_zorunlu);
                
                // Hedef değerlerini ayarla
                if (durum.odeme_durum_hedef_tipi === 'personel' && hedefPersonelid) {
                    currentDurumRow.find('[name="odeme_hedef_personel_id[]"]').val(hedefPersonelid);
                } else if (durum.odeme_durum_hedef_tipi === 'banka' && hedefBankaHesapid) {
                    currentDurumRow.find('[name="odeme_hedef_banka_hesap_id[]"]').val(hedefBankaHesapid);
                }
            }
            bootstrap.Modal.getInstance(document.getElementById('durumModal')).hide();
            showToast('Durum güncellendi', 'success');
            return;
        }
        
        // Mevcut kayit - AJAX ile güncelle
        $.post('', {
            action: 'change_odeme_durum',
            odeme_id: odemeid,
            yeni_durum_id: yenidurumid,
            hedef_personel_id: hedefPersonelid,
            hedef_banka_hesap_id: hedefBankaHesapid,
            aciklama: aciklama
        }, function(response) {
            if (response.success) {
                const durum = odemeDurumlari.find(d => d.odeme_durum_id == yenidurumid);
                if (durum) {
                    currentDurumRow.find('[name="odeme_durum_id[]"]').val(yenidurumid);
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
    
    
    // Sayfa yüklendiğinde Ödeme toplamini hesapla
    $(document).ready(function() {
        calculateOdemeToplam();
    });
    </script>
    
    <!-- Durum Değiştirme Modali -->
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
                        <select class="form-select" id="yenidurumSelect">
                            <option value="">Seçiniz</option>
                        </select>
                    </div>
                    <div class="mb-3" id="modalPersonelDiv" style="display:none;">
                        <label class="form-label">Hedef Personel</label>
                        <select class="form-select" id="modalPersonelSelect">
                            <option value="">Personel Seç</option>
                        </select>
                    </div>
                    <div class="mb-3" id="modalBankaDiv" style="display:none;">
                        <label class="form-label">Hedef Banka Hesap</label>
                        <select class="form-select" id="modalBankaHesapSelect">
                            <option value="">Banka Hesap Seç</option>
                        </select>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Açıklama (Opsiyonel)</label>
                        <textarea class="form-control" id="modalAciklama" rows="2" placeholder="Durum değişikliği hakkında not..."></textarea>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">iptal</button>
                    <button type="button" class="btn btn-warning text-dark" onclick="saveDurumChange()">
                        <i class="bi bi-check"></i> Durumu güncelle
                    </button>
                </div>
            </div>
        </div>
    </div>
    

</body>
</html>
