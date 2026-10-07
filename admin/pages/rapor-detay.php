<?php
/**
 * Merkezi Rapor Detay Sayfası
 * 
 * Tüm raporlardan gelen detay verilerini tek sayfada gösterir.
 * URL parametreleriyle filtreleme yapılır.
 * 
 * Desteklenen kaynaklar (kaynak):
 * - aylık-satis: Aylık satış raporu detayları
 * - personel-satis: Personel satış raporu detayları
 * - personel-tahsilat: Personel tahsilat raporu detayları
 * 
 * Desteklenen tipler (tip):
 * - satis: Satış tutarı detayı
 * - odeme_tipi: Ödeme tipine göre tahsilat detayı
 * - kalan: Kalan tutar detayı
 * - tahsilat: Genel tahsilat detayı
 * - odenecek_senet: Ödenecek senet detayı
 */

require_once __DIR__ . '/../auth.php';
require_once __DIR__ . '/../includes/PageAuth.php';
requireAuth();

$user = Auth::user();
$db = Database::getInstance();

// URL Parametreleri
$kaynak = $_GET['kaynak'] ?? '';
$tip = $_GET['tip'] ?? '';
$yil = intval($_GET['yil'] ?? 0);
$ay = intval($_GET['ay'] ?? 0);
$odemeTipiId = $_GET['odeme_tipi_id'] ?? '';
$odemeTipiAd = $_GET['odeme_tipi'] ?? '';
$sezonId = $_GET['sezon_id'] ?? '';
$personelId = $_GET['personel_id'] ?? '';
$ulkeId = $_GET['ulke_id'] ?? '';
$sehirId = $_GET['sehir_id'] ?? '';
$paketId = $_GET['paket_id'] ?? '';

// Sayfa yetki kontrolü
$currentPageFile = basename($_SERVER['PHP_SELF']);
$pagePermissions = PageAuth::checkPagePermissions(
    $user['kullanici_id'],
    $user['departman_id'],
    $currentPageFile
);

if (!$pagePermissions['has_access']) {
    PageAuth::accessDenied($pagePermissions['error'] ?? 'Bu sayfaya erişim yetkiniz bulunmamaktadır.');
}

// Site title'ı çek
$siteAyarlari = $db->fetchOne("SELECT TOP 1 site_ayarlari_site_title FROM dbo.tanim_site_ayarlari ORDER BY site_ayarlari_id DESC");
$siteTitle = $siteAyarlari['site_ayarlari_site_title'] ?? 'Portal';

// Ay isimleri
$aylar = [
    1 => 'Ocak', 2 => 'Şubat', 3 => 'Mart', 4 => 'Nisan',
    5 => 'Mayıs', 6 => 'Haziran', 7 => 'Temmuz', 8 => 'Ağustos',
    9 => 'Eylül', 10 => 'Ekim', 11 => 'Kasım', 12 => 'Aralık'
];

// Dinamik sayfa başlığı oluştur
function buildPageTitle($kaynak, $tip, $yil, $ay, $aylar, $odemeTipiAd, $personelId, $db, $ulkeId = '', $sehirId = '') {
    $baslik = 'Rapor Detayı';
    $altBaslik = '';
    
    // Kaynak bazlı başlık
    $kaynakAdlari = [
        'aylık-satis' => 'Aylık Satış Raporu',
        'personel-satis' => 'Personel Satış Raporu',
        'personel-tahsilat' => 'Personel Tahsilat Raporu',
        'aylık-tahsilat' => 'Aylık Tahsilat Raporu',
        'ulke-satis' => 'Ülke Satış Raporu'
    ];
    
    // Tip bazlı başlık
    $tipAdlari = [
        'satis' => 'Satış Tutarı Detayı',
        'odeme_tipi' => ($odemeTipiAd ?: 'Ödeme Tipi') . ' Tahsilat Detayı',
        'kalan' => 'Kalan Tutar Detayı',
        'tahsilat' => 'Tahsilat Detayı',
        'odenecek_senet' => 'Ödenecek Senet Detayı',
        'icra' => 'İcra Detayı',
        'zaafiyet' => 'Zaafiyet Detayı'
    ];
    
    $baslik = $tipAdlari[$tip] ?? 'Detay';
    
    // Alt başlık oluştur
    $parcalar = [];
    if ($yil && $ay) {
        $parcalar[] = ($aylar[$ay] ?? $ay) . ' ' . $yil;
    } elseif ($yil) {
        $parcalar[] = $yil . ' Yılı';
    }
    
    if ($personelId) {
        $personel = $db->fetchOne("SELECT kullanici_ad + ' ' + kullanici_soyad as ad FROM kullanicilar WHERE kullanici_id = ?", [$personelId]);
        if ($personel) {
            $parcalar[] = $personel['ad'];
        }
    }
    
    if ($ulkeId) {
        $ulke = $db->fetchOne("SELECT UlkeAdi FROM Adres_Ulkeler WHERE UlkeId = ?", [$ulkeId]);
        if ($ulke) {
            $parcalar[] = $ulke['UlkeAdi'];
        }
    }
    if ($sehirId) {
        $sehir = $db->fetchOne("SELECT SehirAdi FROM Adres_Sehirler WHERE SehirId = ?", [$sehirId]);
        if ($sehir) {
            $parcalar[] = $sehir['SehirAdi'];
        }
    }
    
    $altBaslik = implode(' - ', $parcalar);
    
    return ['baslik' => $baslik, 'alt_baslik' => $altBaslik, 'kaynak_adi' => $kaynakAdlari[$kaynak] ?? ''];
}

$pageInfo = buildPageTitle($kaynak, $tip, $yil, $ay, $aylar, $odemeTipiAd, $personelId, $db, $ulkeId, $sehirId);
$pageTitle = $pageInfo['baslik'];
$pageAltBaslik = $pageInfo['alt_baslik'];
$kaynakAdi = $pageInfo['kaynak_adi'];

// Sezon listesi
$sezonlar = $db->fetchAll("SELECT sezon_id, sezon_ad FROM Sozlesme_Sezonlar WHERE sezon_durum = 1 ORDER BY sezon_ad DESC");

// Ödeme tipleri listesi
$odemeTipleri = $db->fetchAll("SELECT odeme_tipi_id, odeme_tipi_ad FROM Sozlesme_OdemeTipleri WHERE odeme_tipi_durum = 1 ORDER BY odeme_tipi_ad");

// Paket (ürün/hizmet) listesi - filtre için
$paketler = $db->fetchAll("SELECT urun_hizmet_id, urun_hizmet_adi FROM Urun_Hizmet WHERE urun_hizmet_durum = 1 ORDER BY urun_hizmet_adi");

// Ödeme tarihi / tipi / durumu kolonlarının gösterileceği tipler
$odemeDetayliTip = in_array($tip, ['tahsilat', 'icra', 'zaafiyet'], true);

// AJAX İşlemleri
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json');
    $action = $_POST['action'] ?? '';
    
    try {
        switch ($action) {
            case 'stats':
                // İstatistikleri getir
                $kaynak = $_POST['kaynak'] ?? '';
                $tip = $_POST['tip'] ?? '';
                $yil = intval($_POST['yil'] ?? 0);
                $ay = intval($_POST['ay'] ?? 0);
                $odemeTipiId = $_POST['odeme_tipi_id'] ?? '';
                $sezonId = $_POST['sezon_id'] ?? '';
                $personelId = $_POST['personel_id'] ?? '';
                $ulkeId = $_POST['ulke_id'] ?? '';
                $sehirId = $_POST['sehir_id'] ?? '';
                $paketId = $_POST['paket_id'] ?? '';

                $whereClause = "WHERE 1=1";
                $params = [];

                if ($yil) {
                    $whereClause .= " AND YEAR(s.sozlesme_tarih) = ?";
                    $params[] = $yil;
                }
                if ($ay) {
                    $whereClause .= " AND MONTH(s.sozlesme_tarih) = ?";
                    $params[] = $ay;
                }
                if ($sezonId) {
                    $whereClause .= " AND s.sozlesme_sezon_id = ?";
                    $params[] = $sezonId;
                }
                if ($personelId) {
                    $whereClause .= " AND s.sozlesme_personel_id = ?";
                    $params[] = $personelId;
                }
                if ($ulkeId) {
                    $whereClause .= " AND EXISTS (SELECT 1 FROM Cari cu WHERE cu.cari_id = s.sozlesme_cari_id AND cu.cari_ulke = ?)";
                    $params[] = $ulkeId;
                }
                if ($sehirId) {
                    $whereClause .= " AND EXISTS (SELECT 1 FROM Cari cu WHERE cu.cari_id = s.sozlesme_cari_id AND cu.cari_sehirler = ?)";
                    $params[] = $sehirId;
                }
                if ($paketId) {
                    $whereClause .= " AND EXISTS (SELECT 1 FROM Sozlesme_StokHareketleri hp WHERE hp.hareket_sozlesme_id = s.sozlesme_id AND hp.hareket_urun_hizmet_id = ?)";
                    $params[] = $paketId;
                }

                // Kayıt sayısı
                $kayitSayisi = $db->fetchOne("
                    SELECT COUNT(DISTINCT s.sozlesme_id) as sayi
                    FROM Sozlesmeler s
                    $whereClause
                ", $params);
                
                // Toplam satış
                $toplamSatis = $db->fetchOne("
                    SELECT ISNULL(SUM(h.hareket_fiyat), 0) as toplam
                    FROM Sozlesmeler s
                    LEFT JOIN Sozlesme_StokHareketleri h ON h.hareket_sozlesme_id = s.sozlesme_id
                    $whereClause
                ", $params);
                
                // Toplam tahsilat
                $toplamTahsilat = $db->fetchOne("
                    SELECT ISNULL(SUM(o.odeme_tutar), 0) as toplam
                    FROM Sozlesmeler s
                    LEFT JOIN Sozlesme_Odemeler o ON o.odeme_sozlesme_id = s.sozlesme_id AND o.odeme_yapildi = 1
                    $whereClause
                ", $params);
                
                // Ödenecek Senet toplamı
                $senetTipi = $db->fetchOne("SELECT odeme_tipi_id FROM Sozlesme_OdemeTipleri WHERE odeme_tipi_ad = 'Senet' AND odeme_tipi_durum = 1");
                $senetTipiId = $senetTipi['odeme_tipi_id'] ?? 0;
                $odenecekSenetParams = array_merge($params, [$senetTipiId]);
                $toplamOdenecekSenet = $db->fetchOne("
                    SELECT ISNULL(SUM(o.odeme_tutar), 0) as toplam
                    FROM Sozlesmeler s
                    LEFT JOIN Sozlesme_Odemeler o ON o.odeme_sozlesme_id = s.sozlesme_id AND o.odeme_yapildi = 0 AND o.odeme_tipi_id = ?
                    $whereClause
                ", $odenecekSenetParams);
                
                // İcra toplamı (odeme_durum_id = 7)
                $toplamIcra = $db->fetchOne("
                    SELECT ISNULL(SUM(o.odeme_tutar), 0) as toplam
                    FROM Sozlesmeler s
                    LEFT JOIN Sozlesme_Odemeler o ON o.odeme_sozlesme_id = s.sozlesme_id AND o.odeme_durum_id = 7
                    $whereClause
                ", $params);
                
                // Zaafiyet toplamı (odeme_durum_id = 8)
                $toplamZaafiyet = $db->fetchOne("
                    SELECT ISNULL(SUM(o.odeme_tutar), 0) as toplam
                    FROM Sozlesmeler s
                    LEFT JOIN Sozlesme_Odemeler o ON o.odeme_sozlesme_id = s.sozlesme_id AND o.odeme_durum_id = 8
                    $whereClause
                ", $params);
                
                $satis = floatval($toplamSatis['toplam'] ?? 0);
                $tahsilat = floatval($toplamTahsilat['toplam'] ?? 0);
                $odenecekSenet = floatval($toplamOdenecekSenet['toplam'] ?? 0);
                $icra = floatval($toplamIcra['toplam'] ?? 0);
                $zaafiyet = floatval($toplamZaafiyet['toplam'] ?? 0);
                
                echo json_encode([
                    'success' => true,
                    'data' => [
                        'kayit_sayisi' => intval($kayitSayisi['sayi'] ?? 0),
                        'toplam_satis' => $satis,
                        'toplam_tahsilat' => $tahsilat,
                        'toplam_kalan' => $satis - $tahsilat - $odenecekSenet - $icra - $zaafiyet
                    ]
                ]);
                break;
                
            case 'list':
                $kaynak = $_POST['kaynak'] ?? '';
                $tip = $_POST['tip'] ?? '';
                $yil = intval($_POST['yil'] ?? 0);
                $ay = intval($_POST['ay'] ?? 0);
                $odemeTipiId = $_POST['odeme_tipi_id'] ?? '';
                $sezonId = $_POST['sezon_id'] ?? '';
                $personelId = $_POST['personel_id'] ?? '';
                $ulkeId = $_POST['ulke_id'] ?? '';
                $sehirId = $_POST['sehir_id'] ?? '';
                $paketId = $_POST['paket_id'] ?? '';

                $whereClause = "WHERE 1=1";
                $params = [];

                if ($yil) {
                    $whereClause .= " AND YEAR(s.sozlesme_tarih) = ?";
                    $params[] = $yil;
                }
                if ($ay) {
                    $whereClause .= " AND MONTH(s.sozlesme_tarih) = ?";
                    $params[] = $ay;
                }
                if ($sezonId) {
                    $whereClause .= " AND s.sozlesme_sezon_id = ?";
                    $params[] = $sezonId;
                }
                if ($personelId) {
                    $whereClause .= " AND s.sozlesme_personel_id = ?";
                    $params[] = $personelId;
                }
                if ($ulkeId) {
                    $whereClause .= " AND c.cari_ulke = ?";
                    $params[] = $ulkeId;
                }
                if ($sehirId) {
                    $whereClause .= " AND c.cari_sehirler = ?";
                    $params[] = $sehirId;
                }
                if ($paketId) {
                    $whereClause .= " AND EXISTS (SELECT 1 FROM Sozlesme_StokHareketleri hp WHERE hp.hareket_sozlesme_id = s.sozlesme_id AND hp.hareket_urun_hizmet_id = ?)";
                    $params[] = $paketId;
                }

                // Sözleşmedeki paketleri tek satırda birleştirir (aynı paket birden fazla harekette olabilir)
                $paketlerSubquery = "(
                    SELECT STRING_AGG(px.ad, ', ') WITHIN GROUP (ORDER BY px.ad)
                    FROM (
                        SELECT DISTINCT ISNULL(uh.urun_hizmet_adi, 'Tanımsız') AS ad
                        FROM Sozlesme_StokHareketleri hx
                        LEFT JOIN Urun_Hizmet uh ON uh.urun_hizmet_id = hx.hareket_urun_hizmet_id
                        WHERE hx.hareket_sozlesme_id = s.sozlesme_id
                    ) px
                ) as paketler";

                // Sözleşmedeki üye numaraları (Üye No 1 + Üye No 2, hareket bazında tutulur, tek satırda birleştirilir)
                $uyeNoSubquery = "(
                    SELECT STRING_AGG(ux.no, ', ') WITHIN GROUP (ORDER BY ux.no)
                    FROM (
                        SELECT DISTINCT CAST(v.no AS NVARCHAR(50)) AS no
                        FROM Sozlesme_StokHareketleri hu
                        CROSS APPLY (VALUES (hu.hareket_uye_no_1), (hu.hareket_uye_no_2)) v(no)
                        WHERE hu.hareket_sozlesme_id = s.sozlesme_id AND v.no IS NOT NULL
                    ) ux
                ) as uye_no";

                $data = [];
                
                if ($tip === 'satis') {
                    // Satış tutarı detayı
                    $data = $db->fetchAll("
                        SELECT 
                            s.sozlesme_id,
                            c.cari_adi,
                            ISNULL(seh.SehirAdi, '-') as il_adi,
                            ISNULL(ilc.IlceAdi, '-') as ilce_adi,
                            ISNULL(c.cari_telefon, '-') as cari_telefon,
                            CONVERT(VARCHAR(10), s.sozlesme_tarih, 104) as sozlesme_tarih,
                            k.kullanici_ad + ' ' + k.kullanici_soyad as personel_adi,
                            $paketlerSubquery,
                            $uyeNoSubquery,
                            ISNULL(SUM(h.hareket_fiyat), 0) as tutar
                        FROM Sozlesmeler s
                        LEFT JOIN Cari c ON s.sozlesme_cari_id = c.cari_id
                        LEFT JOIN Adres_Sehirler seh ON seh.SehirId = c.cari_sehirler
                        LEFT JOIN Adres_Ilceler ilc ON ilc.ilceId = c.cari_ilceler
                        LEFT JOIN kullanicilar k ON s.sozlesme_personel_id = k.kullanici_id
                        LEFT JOIN Sozlesme_StokHareketleri h ON h.hareket_sozlesme_id = s.sozlesme_id
                        $whereClause
                        GROUP BY s.sozlesme_id, c.cari_adi, seh.SehirAdi, ilc.IlceAdi, c.cari_telefon, s.sozlesme_tarih, k.kullanici_ad, k.kullanici_soyad
                        HAVING ISNULL(SUM(h.hareket_fiyat), 0) > 0
                        ORDER BY s.sozlesme_tarih DESC
                    ", $params);

                } elseif ($tip === 'odeme_tipi' && $odemeTipiId !== '') {
                    // Ödeme tipine göre tahsilat detayı
                    $params[] = $odemeTipiId;
                    $data = $db->fetchAll("
                        SELECT 
                            s.sozlesme_id,
                            c.cari_adi,
                            ISNULL(seh.SehirAdi, '-') as il_adi,
                            ISNULL(ilc.IlceAdi, '-') as ilce_adi,
                            ISNULL(c.cari_telefon, '-') as cari_telefon,
                            CONVERT(VARCHAR(10), s.sozlesme_tarih, 104) as sozlesme_tarih,
                            k.kullanici_ad + ' ' + k.kullanici_soyad as personel_adi,
                            $paketlerSubquery,
                            $uyeNoSubquery,
                            ot.odeme_tipi_ad,
                            ISNULL(SUM(o.odeme_tutar), 0) as tutar
                        FROM Sozlesmeler s
                        LEFT JOIN Cari c ON s.sozlesme_cari_id = c.cari_id
                        LEFT JOIN Adres_Sehirler seh ON seh.SehirId = c.cari_sehirler
                        LEFT JOIN Adres_Ilceler ilc ON ilc.ilceId = c.cari_ilceler
                        LEFT JOIN kullanicilar k ON s.sozlesme_personel_id = k.kullanici_id
                        INNER JOIN Sozlesme_Odemeler o ON o.odeme_sozlesme_id = s.sozlesme_id
                        INNER JOIN Sozlesme_OdemeTipleri ot ON ot.odeme_tipi_id = o.odeme_tipi_id
                        $whereClause AND o.odeme_tipi_id = ? AND o.odeme_yapildi = 1
                        GROUP BY s.sozlesme_id, c.cari_adi, seh.SehirAdi, ilc.IlceAdi, c.cari_telefon, s.sozlesme_tarih, k.kullanici_ad, k.kullanici_soyad, ot.odeme_tipi_ad
                        HAVING ISNULL(SUM(o.odeme_tutar), 0) > 0
                        ORDER BY s.sozlesme_tarih DESC
                    ", $params);
                    
                } elseif ($tip === 'tahsilat') {
                    // Genel tahsilat detayı (tüm ödeme tipleri) - Ödeme bazında detay
                    $data = $db->fetchAll("
                        SELECT 
                            s.sozlesme_id,
                            c.cari_adi,
                            ISNULL(seh.SehirAdi, '-') as il_adi,
                            ISNULL(ilc.IlceAdi, '-') as ilce_adi,
                            ISNULL(c.cari_telefon, '-') as cari_telefon,
                            CONVERT(VARCHAR(10), o.odeme_tarih, 104) as odeme_tarih,
                            CONVERT(VARCHAR(10), s.sozlesme_tarih, 104) as sozlesme_tarih,
                            k.kullanici_ad + ' ' + k.kullanici_soyad as personel_adi,
                            $paketlerSubquery,
                            $uyeNoSubquery,
                            o.odeme_id,
                            o.odeme_durum_id,
                            od.odeme_durum_ad,
                            od.odeme_durum_renk,
                            od.odeme_durum_icon,
                            ot.odeme_tipi_ad,
                            o.odeme_tutar as tutar
                        FROM Sozlesmeler s
                        LEFT JOIN Cari c ON s.sozlesme_cari_id = c.cari_id
                        LEFT JOIN Adres_Sehirler seh ON seh.SehirId = c.cari_sehirler
                        LEFT JOIN Adres_Ilceler ilc ON ilc.ilceId = c.cari_ilceler
                        LEFT JOIN kullanicilar k ON s.sozlesme_personel_id = k.kullanici_id
                        INNER JOIN Sozlesme_Odemeler o ON o.odeme_sozlesme_id = s.sozlesme_id
                        LEFT JOIN Sozlesme_OdemeDurumlari od ON od.odeme_durum_id = o.odeme_durum_id
                        LEFT JOIN Sozlesme_OdemeTipleri ot ON ot.odeme_tipi_id = o.odeme_tipi_id
                        $whereClause AND o.odeme_yapildi = 1
                        ORDER BY o.odeme_tarih DESC, s.sozlesme_tarih DESC
                    ", $params);
                    
                } elseif ($tip === 'kalan') {
                    // Kalan tutar detayı (satış - tahsilat - ödenecek senet - icra - zaafiyet)
                    $senetTipi = $db->fetchOne("SELECT odeme_tipi_id FROM Sozlesme_OdemeTipleri WHERE odeme_tipi_ad = 'Senet' AND odeme_tipi_durum = 1");
                    $senetTipiId = $senetTipi['odeme_tipi_id'] ?? 0;
                    
                    $rawData = $db->fetchAll("
                        SELECT 
                            s.sozlesme_id,
                            c.cari_adi,
                            ISNULL(seh.SehirAdi, '-') as il_adi,
                            ISNULL(ilc.IlceAdi, '-') as ilce_adi,
                            ISNULL(c.cari_telefon, '-') as cari_telefon,
                            CONVERT(VARCHAR(10), s.sozlesme_tarih, 104) as sozlesme_tarih,
                            k.kullanici_ad + ' ' + k.kullanici_soyad as personel_adi,
                            $paketlerSubquery,
                            $uyeNoSubquery,
                            ISNULL(SUM(h.hareket_fiyat), 0) as satis_tutari,
                            ISNULL((
                                SELECT SUM(o2.odeme_tutar)
                                FROM Sozlesme_Odemeler o2
                                WHERE o2.odeme_sozlesme_id = s.sozlesme_id AND o2.odeme_yapildi = 1
                            ), 0) as tahsilat_tutari,
                            ISNULL((
                                SELECT SUM(o3.odeme_tutar)
                                FROM Sozlesme_Odemeler o3
                                WHERE o3.odeme_sozlesme_id = s.sozlesme_id AND o3.odeme_yapildi = 0 AND o3.odeme_tipi_id = ? AND ISNULL(o3.odeme_durum_id, 0) NOT IN (7, 8)
                            ), 0) as odenecek_senet_tutari,
                            ISNULL((
                                SELECT SUM(o4.odeme_tutar)
                                FROM Sozlesme_Odemeler o4
                                WHERE o4.odeme_sozlesme_id = s.sozlesme_id AND o4.odeme_durum_id = 7 AND o4.odeme_yapildi = 0
                            ), 0) as icra_tutari,
                            ISNULL((
                                SELECT SUM(o5.odeme_tutar)
                                FROM Sozlesme_Odemeler o5
                                WHERE o5.odeme_sozlesme_id = s.sozlesme_id AND o5.odeme_durum_id = 8 AND o5.odeme_yapildi = 0
                            ), 0) as zaafiyet_tutari
                        FROM Sozlesmeler s
                        LEFT JOIN Cari c ON s.sozlesme_cari_id = c.cari_id
                        LEFT JOIN Adres_Sehirler seh ON seh.SehirId = c.cari_sehirler
                        LEFT JOIN Adres_Ilceler ilc ON ilc.ilceId = c.cari_ilceler
                        LEFT JOIN kullanicilar k ON s.sozlesme_personel_id = k.kullanici_id
                        LEFT JOIN Sozlesme_StokHareketleri h ON h.hareket_sozlesme_id = s.sozlesme_id
                        $whereClause
                        GROUP BY s.sozlesme_id, c.cari_adi, seh.SehirAdi, ilc.IlceAdi, c.cari_telefon, s.sozlesme_tarih, k.kullanici_ad, k.kullanici_soyad
                        HAVING ISNULL(SUM(h.hareket_fiyat), 0) > 0
                        ORDER BY s.sozlesme_tarih DESC
                    ", array_merge([$senetTipiId], $params));
                    
                    // Kalan hesapla (sadece pozitif kalan göster, negatif = fazla tahsilat)
                    foreach ($rawData as $row) {
                        $kalan = floatval($row['satis_tutari']) - floatval($row['tahsilat_tutari']) - floatval($row['odenecek_senet_tutari']) - floatval($row['icra_tutari']) - floatval($row['zaafiyet_tutari']);
                        if ($kalan > 0) {
                            $row['tutar'] = $kalan;
                            $data[] = $row;
                        }
                    }
                    
                } elseif ($tip === 'odenecek_senet') {
                    // Ödenecek senet detayı
                    $senetTipi = $db->fetchOne("SELECT odeme_tipi_id FROM Sozlesme_OdemeTipleri WHERE odeme_tipi_ad = 'Senet' AND odeme_tipi_durum = 1");
                    $senetTipiId = $senetTipi['odeme_tipi_id'] ?? 0;
                    
                    $params[] = $senetTipiId;
                    $data = $db->fetchAll("
                        SELECT 
                            s.sozlesme_id,
                            c.cari_adi,
                            ISNULL(seh.SehirAdi, '-') as il_adi,
                            ISNULL(ilc.IlceAdi, '-') as ilce_adi,
                            ISNULL(c.cari_telefon, '-') as cari_telefon,
                            CONVERT(VARCHAR(10), s.sozlesme_tarih, 104) as sozlesme_tarih,
                            k.kullanici_ad + ' ' + k.kullanici_soyad as personel_adi,
                            $paketlerSubquery,
                            $uyeNoSubquery,
                            ISNULL(SUM(o.odeme_tutar), 0) as tutar
                        FROM Sozlesmeler s
                        LEFT JOIN Cari c ON s.sozlesme_cari_id = c.cari_id
                        LEFT JOIN Adres_Sehirler seh ON seh.SehirId = c.cari_sehirler
                        LEFT JOIN Adres_Ilceler ilc ON ilc.ilceId = c.cari_ilceler
                        LEFT JOIN kullanicilar k ON s.sozlesme_personel_id = k.kullanici_id
                        INNER JOIN Sozlesme_Odemeler o ON o.odeme_sozlesme_id = s.sozlesme_id
                        $whereClause AND o.odeme_tipi_id = ? AND o.odeme_yapildi = 0 AND ISNULL(o.odeme_durum_id, 0) NOT IN (7, 8)
                        GROUP BY s.sozlesme_id, c.cari_adi, seh.SehirAdi, ilc.IlceAdi, c.cari_telefon, s.sozlesme_tarih, k.kullanici_ad, k.kullanici_soyad
                        HAVING ISNULL(SUM(o.odeme_tutar), 0) > 0
                        ORDER BY s.sozlesme_tarih DESC
                    ", $params);
                    
                } elseif ($tip === 'icra') {
                    // İcra detayı (odeme_durum_id = 7)
                    $data = $db->fetchAll("
                        SELECT 
                            s.sozlesme_id,
                            c.cari_adi,
                            ISNULL(seh.SehirAdi, '-') as il_adi,
                            ISNULL(ilc.IlceAdi, '-') as ilce_adi,
                            ISNULL(c.cari_telefon, '-') as cari_telefon,
                            CONVERT(VARCHAR(10), o.odeme_tarih, 104) as odeme_tarih,
                            CONVERT(VARCHAR(10), s.sozlesme_tarih, 104) as sozlesme_tarih,
                            k.kullanici_ad + ' ' + k.kullanici_soyad as personel_adi,
                            $paketlerSubquery,
                            $uyeNoSubquery,
                            o.odeme_id,
                            od.odeme_durum_ad,
                            od.odeme_durum_renk,
                            od.odeme_durum_icon,
                            ot.odeme_tipi_ad,
                            o.odeme_tutar as tutar
                        FROM Sozlesmeler s
                        LEFT JOIN Cari c ON s.sozlesme_cari_id = c.cari_id
                        LEFT JOIN Adres_Sehirler seh ON seh.SehirId = c.cari_sehirler
                        LEFT JOIN Adres_Ilceler ilc ON ilc.ilceId = c.cari_ilceler
                        LEFT JOIN kullanicilar k ON s.sozlesme_personel_id = k.kullanici_id
                        INNER JOIN Sozlesme_Odemeler o ON o.odeme_sozlesme_id = s.sozlesme_id
                        LEFT JOIN Sozlesme_OdemeDurumlari od ON od.odeme_durum_id = o.odeme_durum_id
                        LEFT JOIN Sozlesme_OdemeTipleri ot ON ot.odeme_tipi_id = o.odeme_tipi_id
                        $whereClause AND o.odeme_durum_id = 7
                        ORDER BY o.odeme_tarih DESC, s.sozlesme_tarih DESC
                    ", $params);
                    
                } elseif ($tip === 'zaafiyet') {
                    // Zaafiyet detayı (odeme_durum_id = 8)
                    $data = $db->fetchAll("
                        SELECT 
                            s.sozlesme_id,
                            c.cari_adi,
                            ISNULL(seh.SehirAdi, '-') as il_adi,
                            ISNULL(ilc.IlceAdi, '-') as ilce_adi,
                            ISNULL(c.cari_telefon, '-') as cari_telefon,
                            CONVERT(VARCHAR(10), o.odeme_tarih, 104) as odeme_tarih,
                            CONVERT(VARCHAR(10), s.sozlesme_tarih, 104) as sozlesme_tarih,
                            k.kullanici_ad + ' ' + k.kullanici_soyad as personel_adi,
                            $paketlerSubquery,
                            $uyeNoSubquery,
                            o.odeme_id,
                            od.odeme_durum_ad,
                            od.odeme_durum_renk,
                            od.odeme_durum_icon,
                            ot.odeme_tipi_ad,
                            o.odeme_tutar as tutar
                        FROM Sozlesmeler s
                        LEFT JOIN Cari c ON s.sozlesme_cari_id = c.cari_id
                        LEFT JOIN Adres_Sehirler seh ON seh.SehirId = c.cari_sehirler
                        LEFT JOIN Adres_Ilceler ilc ON ilc.ilceId = c.cari_ilceler
                        LEFT JOIN kullanicilar k ON s.sozlesme_personel_id = k.kullanici_id
                        INNER JOIN Sozlesme_Odemeler o ON o.odeme_sozlesme_id = s.sozlesme_id
                        LEFT JOIN Sozlesme_OdemeDurumlari od ON od.odeme_durum_id = o.odeme_durum_id
                        LEFT JOIN Sozlesme_OdemeTipleri ot ON ot.odeme_tipi_id = o.odeme_tipi_id
                        $whereClause AND o.odeme_durum_id = 8
                        ORDER BY o.odeme_tarih DESC, s.sozlesme_tarih DESC
                    ", $params);
                }
                
                echo json_encode(['success' => true, 'data' => $data]);
                break;

            case 'paket_ozet':
                // Paket (ürün/hizmet) bazlı satış özeti
                $yil = intval($_POST['yil'] ?? 0);
                $ay = intval($_POST['ay'] ?? 0);
                $sezonId = $_POST['sezon_id'] ?? '';
                $personelId = $_POST['personel_id'] ?? '';
                $ulkeId = $_POST['ulke_id'] ?? '';
                $sehirId = $_POST['sehir_id'] ?? '';
                $paketId = $_POST['paket_id'] ?? '';

                $whereClause = "WHERE 1=1";
                $params = [];

                if ($yil) {
                    $whereClause .= " AND YEAR(s.sozlesme_tarih) = ?";
                    $params[] = $yil;
                }
                if ($ay) {
                    $whereClause .= " AND MONTH(s.sozlesme_tarih) = ?";
                    $params[] = $ay;
                }
                if ($sezonId) {
                    $whereClause .= " AND s.sozlesme_sezon_id = ?";
                    $params[] = $sezonId;
                }
                if ($personelId) {
                    $whereClause .= " AND s.sozlesme_personel_id = ?";
                    $params[] = $personelId;
                }
                if ($ulkeId) {
                    $whereClause .= " AND c.cari_ulke = ?";
                    $params[] = $ulkeId;
                }
                if ($sehirId) {
                    $whereClause .= " AND c.cari_sehirler = ?";
                    $params[] = $sehirId;
                }
                if ($paketId) {
                    $whereClause .= " AND EXISTS (SELECT 1 FROM Sozlesme_StokHareketleri hp WHERE hp.hareket_sozlesme_id = s.sozlesme_id AND hp.hareket_urun_hizmet_id = ?)";
                    $params[] = $paketId;
                }

                $paketOzet = $db->fetchAll("
                    SELECT
                        ISNULL(uh.urun_hizmet_adi, 'Tanımsız') as paket_adi,
                        ISNULL(m.marka_renk, '#6c757d') as paket_renk,
                        COUNT(DISTINCT s.sozlesme_id) as sozlesme_sayisi,
                        ISNULL(SUM(h.hareket_fiyat), 0) as toplam_tutar
                    FROM Sozlesmeler s
                    LEFT JOIN Cari c ON s.sozlesme_cari_id = c.cari_id
                    INNER JOIN Sozlesme_StokHareketleri h ON h.hareket_sozlesme_id = s.sozlesme_id
                    LEFT JOIN Urun_Hizmet uh ON uh.urun_hizmet_id = h.hareket_urun_hizmet_id
                    LEFT JOIN UrunHizmet_Markalar m ON m.marka_id = uh.urun_hizmet_marka_id
                    $whereClause
                    GROUP BY uh.urun_hizmet_adi, m.marka_renk
                    HAVING ISNULL(SUM(h.hareket_fiyat), 0) <> 0
                    ORDER BY toplam_tutar DESC
                ", $params);

                echo json_encode(['success' => true, 'data' => $paketOzet]);
                break;

            default:
                echo json_encode(['success' => false, 'message' => 'Geçersiz işlem']);
        }
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'message' => $e->getMessage()]);
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
    <link href="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css" rel="stylesheet" />
    <link href="https://cdn.jsdelivr.net/npm/select2-bootstrap-5-theme@1.3.0/dist/select2-bootstrap-5-theme.min.css" rel="stylesheet" />
    <link href="https://cdn.jsdelivr.net/npm/sweetalert2@11/dist/sweetalert2.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.datatables.net/1.13.7/css/dataTables.bootstrap5.min.css">
    <link rel="stylesheet" href="/admin/assets/css/custom.css">
    <style>
        .info-box { transition: transform 0.2s; }
        .info-box:hover { transform: translateY(-5px); box-shadow: 0 4px 6px rgba(0,0,0,0.1); }
        .clickable-cell { cursor: pointer; transition: background-color 0.2s; }
        .clickable-cell:hover { background-color: #e3f2fd !important; }
        .paket-badge { font-size: 0.75rem; font-weight: 500; margin: 1px 2px 1px 0; }
        .paket-ozet-item { border-left: 4px solid #6c757d; background: #f8f9fa; border-radius: 4px; padding: 10px 12px; height: 100%; }
        .paket-ozet-item .paket-ad { font-weight: 600; font-size: 0.85rem; }
        .paket-ozet-item .paket-tutar { font-size: 1.05rem; font-weight: 700; }
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
                <h3 class="mb-0">
                    <?= htmlspecialchars($pageTitle) ?>
                    <?php if ($pageAltBaslik): ?>
                        <small class="text-muted fs-6">(<?= htmlspecialchars($pageAltBaslik) ?>)</small>
                    <?php endif; ?>
                </h3>
            </div>
            <div class="col-sm-6">
                <ol class="breadcrumb float-sm-end">
                    <li class="breadcrumb-item"><a href="/admin/pages/anasayfa.php">Ana Sayfa</a></li>
                    <?php if ($kaynakAdi): ?>
                        <li class="breadcrumb-item"><a href="javascript:history.back()"><?= htmlspecialchars($kaynakAdi) ?></a></li>
                    <?php endif; ?>
                    <li class="breadcrumb-item active"><?= htmlspecialchars($pageTitle) ?></li>
                </ol>
            </div>
        </div>
    </div>
</div>

<div class="app-content">
    <div class="container-fluid">
        
        <!-- Info Box'lar -->
        <div class="row mb-3">
            <div class="col-md-3">
                <div class="info-box text-bg-primary">
                    <span class="info-box-icon"><i class="bi bi-file-text"></i></span>
                    <div class="info-box-content">
                        <span class="info-box-text">Kayıt Sayısı</span>
                        <span class="info-box-number" id="stat-kayit">0</span>
                    </div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="info-box text-bg-info">
                    <span class="info-box-icon"><i class="bi bi-cart-check"></i></span>
                    <div class="info-box-content">
                        <span class="info-box-text">Toplam Satış</span>
                        <span class="info-box-number" id="stat-satis">0 ₺</span>
                    </div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="info-box text-bg-success">
                    <span class="info-box-icon"><i class="bi bi-cash-stack"></i></span>
                    <div class="info-box-content">
                        <span class="info-box-text">Toplam Tahsilat</span>
                        <span class="info-box-number" id="stat-tahsilat">0 ₺</span>
                    </div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="info-box text-bg-warning">
                    <span class="info-box-icon"><i class="bi bi-hourglass-split"></i></span>
                    <div class="info-box-content">
                        <span class="info-box-text">Kalan</span>
                        <span class="info-box-number" id="stat-kalan">0 ₺</span>
                    </div>
                </div>
            </div>
        </div>

        <!-- Filtre Kartı -->
        <div class="card card-primary card-outline mb-3">
            <div class="card-header">
                <h3 class="card-title">
                    <i class="bi bi-funnel"></i> Filtreler
                </h3>
                <div class="card-tools">
                    <button type="button" class="btn btn-tool" data-bs-toggle="collapse" data-bs-target="#filterCard">
                        <i class="bi bi-chevron-down"></i>
                    </button>
                </div>
            </div>
            <div class="card-body collapse" id="filterCard">
                <form id="filterForm">
                    <input type="hidden" id="filter_kaynak" name="kaynak" value="<?= htmlspecialchars($kaynak) ?>">
                    <input type="hidden" id="filter_tip" name="tip" value="<?= htmlspecialchars($tip) ?>">
                    <input type="hidden" id="filter_odeme_tipi_id" name="odeme_tipi_id" value="<?= htmlspecialchars($odemeTipiId) ?>">
                    
                    <div class="row g-3">
                        <div class="col-md-2">
                            <label class="form-label">Yıl</label>
                            <select class="form-select" id="filter_yil" name="yil">
                                <option value="">Tümü</option>
                                <?php for ($y = date('Y'); $y >= 2020; $y--): ?>
                                    <option value="<?= $y ?>" <?= $y == $yil ? 'selected' : '' ?>><?= $y ?></option>
                                <?php endfor; ?>
                            </select>
                        </div>
                        <div class="col-md-2">
                            <label class="form-label">Ay</label>
                            <select class="form-select" id="filter_ay" name="ay">
                                <option value="">Tümü</option>
                                <?php foreach ($aylar as $ayNo => $ayAdi): ?>
                                    <option value="<?= $ayNo ?>" <?= $ayNo == $ay ? 'selected' : '' ?>><?= $ayAdi ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-3">
                            <label class="form-label">Sezon</label>
                            <select class="form-select" id="filter_sezon" name="sezon_id">
                                <option value="">Tümü</option>
                                <?php foreach ($sezonlar as $sezon): ?>
                                    <option value="<?= $sezon['sezon_id'] ?>" <?= $sezon['sezon_id'] == $sezonId ? 'selected' : '' ?>><?= htmlspecialchars($sezon['sezon_ad']) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-3">
                            <label class="form-label">Paket</label>
                            <select class="form-select" id="filter_paket" name="paket_id">
                                <option value="">Tümü</option>
                                <?php foreach ($paketler as $paket): ?>
                                    <option value="<?= $paket['urun_hizmet_id'] ?>" <?= $paket['urun_hizmet_id'] == $paketId ? 'selected' : '' ?>><?= htmlspecialchars($paket['urun_hizmet_adi']) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-2 d-flex align-items-end">
                            <button type="submit" class="btn btn-primary me-2">
                                <i class="bi bi-search"></i> Filtrele
                            </button>
                            <button type="button" class="btn btn-secondary me-2" id="clearFilters">
                                <i class="bi bi-x-circle"></i> Temizle
                            </button>
                        </div>
                    </div>
                </form>
            </div>
        </div>

        <!-- Paket Bazlı Özet -->
        <div class="card card-info card-outline mb-3">
            <div class="card-header">
                <h3 class="card-title">
                    <i class="bi bi-box-seam"></i> Paket Bazlı Satış Özeti
                </h3>
                <div class="card-tools">
                    <button type="button" class="btn btn-tool" data-bs-toggle="collapse" data-bs-target="#paketOzetCard">
                        <i class="bi bi-chevron-up"></i>
                    </button>
                </div>
            </div>
            <div class="card-body collapse show" id="paketOzetCard">
                <div class="row g-2" id="paketOzetBody">
                    <div class="col-12 text-center text-muted">Yükleniyor...</div>
                </div>
            </div>
        </div>

        <!-- Ana Tablo -->
        <div class="card card-primary card-outline">
            <div class="card-header">
                <h3 class="card-title">
                    <i class="bi bi-table"></i> <?= htmlspecialchars($pageTitle) ?>
                    <?php if ($pageAltBaslik): ?>
                        <small class="text-muted">(<?= htmlspecialchars($pageAltBaslik) ?>)</small>
                    <?php endif; ?>
                </h3>
                <div class="card-tools">
                    <button type="button" class="btn btn-sm btn-success me-1" id="exportExcel">
                        <i class="bi bi-file-earmark-excel"></i> Excel
                    </button>
                    <a href="javascript:history.back()" class="btn btn-sm btn-outline-secondary">
                        <i class="bi bi-arrow-left"></i> Geri
                    </a>
                </div>
            </div>
            <div class="card-body">
                <div class="table-responsive">
                    <table class="table table-bordered table-striped table-hover w-100" id="dataTable">
                        <thead class="table-dark">
                            <?php if ($odemeDetayliTip): ?>
                            <tr>
                                <th>Cari</th>
                                <th>Üye No</th>
                                <th>İl</th>
                                <th>İlçe</th>
                                <th>Telefon</th>
                                <th>Personel</th>
                                <th>Paket</th>
                                <th>Ödeme Tarihi</th>
                                <th>Ödeme Tipi</th>
                                <th>Durum</th>
                                <th class="text-end">Tutar</th>
                                <th class="text-center">İşlem</th>
                            </tr>
                            <?php else: ?>
                            <tr>
                                <th>Cari</th>
                                <th>Üye No</th>
                                <th>İl</th>
                                <th>İlçe</th>
                                <th>Telefon</th>
                                <th>Personel</th>
                                <th>Paket</th>
                                <th>Tarih</th>
                                <th class="text-end">Tutar</th>
                                <th class="text-center">İşlem</th>
                            </tr>
                            <?php endif; ?>
                        </thead>
                        <tbody></tbody>
                        <tfoot class="table-secondary">
                            <tr>
                                <th colspan="<?= $odemeDetayliTip ? 10 : 8 ?>" class="text-end">Toplam:</th>
                                <th class="text-end" id="tableToplam">0 ₺</th>
                                <th></th>
                            </tr>
                        </tfoot>
                    </table>
                </div>
            </div>
        </div>

        </div>
        </main>
        
        <?php include __DIR__ . '/../includes/footer.php'; ?>
    </div>

<script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script src="/admin/assets/js/adminlte.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11/dist/sweetalert2.all.min.js"></script>
<script src="https://cdn.datatables.net/1.13.7/js/jquery.dataTables.min.js"></script>
<script src="https://cdn.datatables.net/1.13.7/js/dataTables.bootstrap5.min.js"></script>
<script src="/admin/assets/js/custom.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/xlsx/0.18.5/xlsx.full.min.js"></script>

<script>
    // URL'den gelen filtreler
    let currentFilters = {
        kaynak: '<?= addslashes($kaynak) ?>',
        tip: '<?= addslashes($tip) ?>',
        yil: <?= $yil ?: 0 ?>,
        ay: <?= $ay ?: 0 ?>,
        odeme_tipi_id: '<?= addslashes($odemeTipiId) ?>',
        sezon_id: '<?= addslashes($sezonId) ?>',
        personel_id: '<?= addslashes($personelId) ?>',
        ulke_id: '<?= addslashes($ulkeId) ?>',
        sehir_id: '<?= addslashes($sehirId) ?>',
        paket_id: '<?= addslashes($paketId) ?>'
    };

    const odemeDetayliTip = <?= $odemeDetayliTip ? 'true' : 'false' ?>;
    let table = null;

    // Para formatla
    function formatMoney(val) {
        return parseFloat(val || 0).toLocaleString('tr-TR', {minimumFractionDigits: 2, maximumFractionDigits: 2}) + ' ₺';
    }

    // HTML kaçış
    function escapeHtml(val) {
        return $('<div>').text(val ?? '').html();
    }

    // Paket adlarını badge olarak göster
    function renderPaketler(data) {
        if (!data) return '<span class="text-muted">-</span>';
        return data.split(',')
            .map(p => p.trim())
            .filter(p => p.length > 0)
            .map(p => '<span class="badge text-bg-secondary paket-badge">' + escapeHtml(p) + '</span>')
            .join(' ');
    }
    
    // İstatistikleri yükle
    function loadStats() {
        $.post('', { action: 'stats', ...currentFilters }, function(response) {
            if (response.success) {
                $('#stat-kayit').text(response.data.kayit_sayisi + ' Adet');
                $('#stat-satis').text(formatMoney(response.data.toplam_satis));
                $('#stat-tahsilat').text(formatMoney(response.data.toplam_tahsilat));
                $('#stat-kalan').text(formatMoney(response.data.toplam_kalan));
            }
        });
    }
    
    // dd.mm.yyyy -> yyyymmdd (DataTable'ın tarihi metin gibi sıralamaması için)
    function tarihSortKey(val) {
        if (!val) return 0;
        const p = val.split('.');
        return p.length === 3 ? parseInt(p[2] + p[1] + p[0], 10) : 0;
    }

    // Paket bazlı özeti yükle
    function loadPaketOzet() {
        $.post('', { action: 'paket_ozet', ...currentFilters }, function(response) {
            if (!response.success || !response.data || response.data.length === 0) {
                $('#paketOzetBody').html('<div class="col-12 text-center text-muted">Paket bilgisi bulunamadı</div>');
                return;
            }

            let html = '';
            response.data.forEach(row => {
                const renk = row.paket_renk || '#6c757d';
                html += `<div class="col-md-3 col-sm-6">
                    <div class="paket-ozet-item" style="border-left-color: ${renk}">
                        <div class="paket-ad" title="${escapeHtml(row.paket_adi)}">${escapeHtml(row.paket_adi)}</div>
                        <div class="paket-tutar text-success">${formatMoney(row.toplam_tutar)}</div>
                        <div class="text-muted small">${row.sozlesme_sayisi} sözleşme</div>
                    </div>
                </div>`;
            });

            $('#paketOzetBody').html(html);
        }).fail(function() {
            $('#paketOzetBody').html('<div class="col-12 text-center text-danger">Paket özeti yüklenemedi</div>');
        });
    }

    // DataTable kolonları
    function buildColumns() {
        const cols = [
            { data: 'cari_adi', render: d => escapeHtml(d) || '-' },
            { data: 'uye_no', render: d => escapeHtml(d) || '-' },
            { data: 'il_adi', render: d => escapeHtml(d) || '-' },
            { data: 'ilce_adi', render: d => escapeHtml(d) || '-' },
            { data: 'cari_telefon', render: d => escapeHtml(d) || '-' },
            { data: 'personel_adi', render: d => escapeHtml(d) || '-' },
            {
                data: 'paketler',
                render: function(data, type) {
                    // Arama ve sıralama ham metin üzerinden yapılsın
                    if (type === 'display') return renderPaketler(data);
                    return data || '';
                }
            }
        ];

        if (odemeDetayliTip) {
            cols.push({
                data: 'odeme_tarih',
                render: function(data, type, row) {
                    const tarih = data || row.sozlesme_tarih;
                    if (type === 'sort' || type === 'type') return tarihSortKey(tarih);
                    return escapeHtml(tarih) || '-';
                }
            });
            cols.push({ data: 'odeme_tipi_ad', render: d => escapeHtml(d) || '-' });
            cols.push({
                data: 'odeme_durum_ad',
                render: function(data, type, row) {
                    if (type !== 'display') return data || '';
                    const renk = row.odeme_durum_renk || '#6c757d';
                    const icon = row.odeme_durum_icon || 'bi-question-circle';
                    return `<span class="badge" style="background-color: ${renk}"><i class="bi ${icon} me-1"></i>${escapeHtml(data || '-')}</span>`;
                }
            });
        } else {
            cols.push({
                data: 'sozlesme_tarih',
                render: function(data, type) {
                    if (type === 'sort' || type === 'type') return tarihSortKey(data);
                    return escapeHtml(data) || '-';
                }
            });
        }

        cols.push({
            data: 'tutar',
            className: 'text-end',
            render: function(data, type) {
                if (type !== 'display') return parseFloat(data || 0);
                const tutar = parseFloat(data || 0);
                const cls = tutar < 0 ? 'text-danger' : (tutar > 0 ? 'text-success' : '');
                return `<span class="${cls}">${formatMoney(tutar)}</span>`;
            }
        });

        cols.push({
            data: 'sozlesme_id',
            className: 'text-center',
            orderable: false,
            searchable: false,
            render: function(data) {
                return `<a href="/admin/pages/sozlesme-form.php?id=${data}" class="btn btn-sm btn-outline-primary" target="_blank" title="Görüntüle"><i class="bi bi-eye"></i></a>`;
            }
        });

        return cols;
    }

    // DataTable başlat
    function initTable() {
        table = $('#dataTable').DataTable({
            processing: true,
            serverSide: false,
            ajax: {
                url: '',
                type: 'POST',
                data: function() {
                    return { action: 'list', ...currentFilters };
                },
                dataSrc: function(json) {
                    if (!json.success) {
                        showToast('Hata: ' + (json.message || 'Veri alınamadı'), 'danger');
                        return [];
                    }
                    return json.data;
                }
            },
            columns: buildColumns(),
            order: [[7, 'desc']], // Tarih kolonu
            footerCallback: function(row, data, start, end, display) {
                // Aramada süzülen satırların toplamını göster
                const api = this.api();
                const tutarIndex = odemeDetayliTip ? 10 : 8;
                const toplam = api.column(tutarIndex, { search: 'applied' })
                    .data()
                    .reduce((a, b) => parseFloat(a || 0) + parseFloat(b || 0), 0);
                $('#tableToplam').text(formatMoney(toplam));
            },
            language: {
                url: 'https://cdn.datatables.net/plug-ins/1.13.7/i18n/tr.json'
            },
            pageLength: 25
        });
    }
    
    // Sayfa yüklendiğinde
    $(document).ready(function() {
        // Select2 başlat (tüm dropdown'lar aranabilir)
        $('.form-select').select2({
            theme: 'bootstrap-5',
            width: '100%',
            allowClear: true
        });

        // Verileri yükle
        initTable();
        loadStats();
        loadPaketOzet();

        // Filtre submit
        $('#filterForm').on('submit', function(e) {
            e.preventDefault();

            currentFilters.yil = $('#filter_yil').val() || 0;
            currentFilters.ay = $('#filter_ay').val() || 0;
            currentFilters.sezon_id = $('#filter_sezon').val() || '';
            currentFilters.paket_id = $('#filter_paket').val() || '';

            // URL'yi güncelle (history)
            const params = new URLSearchParams(window.location.search);
            if (currentFilters.yil) params.set('yil', currentFilters.yil); else params.delete('yil');
            if (currentFilters.ay) params.set('ay', currentFilters.ay); else params.delete('ay');
            if (currentFilters.sezon_id) params.set('sezon_id', currentFilters.sezon_id); else params.delete('sezon_id');
            if (currentFilters.paket_id) params.set('paket_id', currentFilters.paket_id); else params.delete('paket_id');

            history.pushState(null, '', '?' + params.toString());

            table.ajax.reload();
            loadStats();
            loadPaketOzet();
            showToast('Filtre uygulandı', 'info');
        });

        // Filtreleri temizle
        $('#clearFilters').on('click', function() {
            $('#filter_yil').val('').trigger('change.select2');
            $('#filter_ay').val('').trigger('change.select2');
            $('#filter_sezon').val('').trigger('change.select2');
            $('#filter_paket').val('').trigger('change.select2');

            currentFilters.yil = 0;
            currentFilters.ay = 0;
            currentFilters.sezon_id = '';
            currentFilters.paket_id = '';

            const params = new URLSearchParams(window.location.search);
            ['yil', 'ay', 'sezon_id', 'paket_id'].forEach(p => params.delete(p));
            history.pushState(null, '', '?' + params.toString());

            table.ajax.reload();
            loadStats();
            loadPaketOzet();
            showToast('Filtreler temizlendi', 'info');
        });
        
        // Excel export - ekrandaki arama/filtre sonucunu aktarır
        $('#exportExcel').on('click', function() {
            const btn = $(this);

            // Aramada süzülmüş satırlar
            const data = table.rows({ search: 'applied' }).data().toArray();

            if (data.length === 0) {
                showToast('Dışa aktarılacak veri bulunamadı', 'warning');
                return;
            }

            btn.prop('disabled', true).html('<i class="bi bi-hourglass-split"></i> Hazırlanıyor...');

            let rows = [];

            if (odemeDetayliTip) {
                rows.push(['Cari', 'Üye No', 'İl', 'İlçe', 'Telefon', 'Personel', 'Paket', 'Ödeme Tarihi', 'Sözleşme Tarihi', 'Ödeme Tipi', 'Durum', 'Tutar (₺)']);
            } else {
                rows.push(['Cari', 'Üye No', 'İl', 'İlçe', 'Telefon', 'Personel', 'Paket', 'Sözleşme Tarihi', 'Tutar (₺)']);
            }

            data.forEach(row => {
                if (odemeDetayliTip) {
                    rows.push([
                        row.cari_adi || '',
                        row.uye_no || '',
                        row.il_adi || '',
                        row.ilce_adi || '',
                        row.cari_telefon || '',
                        row.personel_adi || '',
                        row.paketler || '',
                        row.odeme_tarih || '',
                        row.sozlesme_tarih || '',
                        row.odeme_tipi_ad || '',
                        row.odeme_durum_ad || '',
                        parseFloat(row.tutar || 0)
                    ]);
                } else {
                    rows.push([
                        row.cari_adi || '',
                        row.uye_no || '',
                        row.il_adi || '',
                        row.ilce_adi || '',
                        row.cari_telefon || '',
                        row.personel_adi || '',
                        row.paketler || '',
                        row.sozlesme_tarih || '',
                        parseFloat(row.tutar || 0)
                    ]);
                }
            });

            const ws = XLSX.utils.aoa_to_sheet(rows);

            ws['!cols'] = odemeDetayliTip
                ? [{ wch: 35 }, { wch: 15 }, { wch: 15 }, { wch: 15 }, { wch: 15 }, { wch: 25 }, { wch: 30 }, { wch: 14 }, { wch: 14 }, { wch: 20 }, { wch: 20 }, { wch: 14 }]
                : [{ wch: 35 }, { wch: 15 }, { wch: 15 }, { wch: 15 }, { wch: 15 }, { wch: 25 }, { wch: 30 }, { wch: 14 }, { wch: 14 }];

            const wb = XLSX.utils.book_new();
            XLSX.utils.book_append_sheet(wb, ws, 'Rapor Detayı');

            const fileName = 'rapor-detay-' + new Date().toISOString().slice(0, 10) + '.xlsx';
            XLSX.writeFile(wb, fileName);

            showToast('Excel dosyası indirildi', 'success');
            btn.prop('disabled', false).html('<i class="bi bi-file-earmark-excel"></i> Excel');
        });
    });
</script>

</body>
</html>
