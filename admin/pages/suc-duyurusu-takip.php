<?php
/**
 * Suç Duyurusu Takip
 * HukukTakip tablosunu yönetir - Sadece cari_tipi_id = 4 olanlar
 */

require_once __DIR__ . '/../auth.php';
require_once __DIR__ . '/../includes/PageAuth.php';
requireAuth();

$user = Auth::user();
$db = Database::getInstance();

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

$pageTitle = $pageInfo['sayfalar_sayfa_adi'] ?? 'Suç Duyurusu Takip';
$pageDescription = $pageInfo['sayfalar_aciklama'] ?? '';
$menuAdi = $pageInfo['menu_adi'] ?? null;

$siteAyarlari = $db->fetchOne("SELECT TOP 1 site_ayarlari_site_title FROM dbo.tanim_site_ayarlari ORDER BY site_ayarlari_id DESC");
$siteTitle = $siteAyarlari['site_ayarlari_site_title'] ?? 'Portal';

// Sabit cari tipi filtresi
$cariTipiId = 4;

// "Kendi Kullanicisini Gor" yetkisi: kayit sahibi (OlusturanKullanici) veya
// carinin sozlesmesindeki personel oturum acan kullanici olmalidir.
$sahiplikWhere = "";
$sahiplikParams = [];
if (!empty($pagePermissions['can_view_own_records'])) {
    $sahiplikWhere = " AND (t.OlusturanKullanici = ?
                            OR EXISTS (SELECT 1 FROM Sozlesmeler sp
                                       WHERE sp.sozlesme_cari_id = c.cari_id
                                         AND sp.sozlesme_personel_id = ?))";
    $sahiplikParams = [$user['kullanici_id'], $user['kullanici_id']];
}

// İhtarname şablonu eşlemesi: HukukTaraflar.taraf_id -> şablon anahtarı
$ihtarnameSablonlari = [
    1  => 'yayinplatformu',
    5  => 'ssport',
    11 => 'tabii',
];

// Dropdown verileri
$taraflar = $db->fetchAll("SELECT taraf_id, taraf_ad FROM HukukTaraflar WHERE Durum = 1 ORDER BY taraf_ad");
$statuler = $db->fetchAll("SELECT statu_id, statu_ad FROM HukukStatu WHERE Durum = 1 AND statu_cari_tipi_id = ? ORDER BY statu_sira", [$cariTipiId]);
$sehirler = $db->fetchAll("SELECT SehirId, SehirAdi FROM Adres_Sehirler WHERE UlkeId = 1 ORDER BY SehirAdi");

// İhtarname basılan kayıtlara atanacak statü (ad üzerinden çözülür, ID koda gömülmez)
$ihtarStatu = $db->fetchOne("
    SELECT TOP 1 statu_id
    FROM HukukStatu
    WHERE Durum = 1 AND statu_cari_tipi_id = ? AND statu_ad LIKE 'İhtar Gönderildi%'
    ORDER BY statu_id
", [$cariTipiId]);
$ihtarStatuId = $ihtarStatu['statu_id'] ?? null;
$sezonlar = $db->fetchAll("SELECT sezon_id, sezon_ad, sezon_varsayilan FROM Sozlesme_Sezonlar WHERE sezon_durum = 1 ORDER BY sezon_ad DESC");

// Varsayılan sezon: isaretli kayit, yoksa en guncel sezon
$varsayilanSezonId = '';
foreach ($sezonlar as $sz) {
    if (!empty($sz['sezon_varsayilan'])) { $varsayilanSezonId = (string)$sz['sezon_id']; break; }
}
if ($varsayilanSezonId === '' && !empty($sezonlar)) {
    $varsayilanSezonId = (string)$sezonlar[0]['sezon_id'];
}

// Sezon, carinin son sözleşmesinden türetilir (HukukTakip'te sezon kolonu yok)
$sezonApply = "OUTER APPLY (
                        SELECT TOP 1 sn.sezon_id, sn.sezon_ad,
                               ISNULL((SELECT SUM(h.hareket_fiyat)
                                       FROM Sozlesme_StokHareketleri h
                                       WHERE h.hareket_sozlesme_id = sz.sozlesme_id), 0) AS satis_fiyati
                        FROM Sozlesmeler sz
                        INNER JOIN Sozlesme_Sezonlar sn ON sz.sozlesme_sezon_id = sn.sezon_id
                        WHERE sz.sozlesme_cari_id = c.cari_id
                        ORDER BY sz.sozlesme_id DESC
                    ) sez";

// AJAX İşlemleri
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json');
    $action = $_POST['action'] ?? '';
    
    try {
        switch ($action) {
            case 'stats':
                $tarafId = $_POST['taraf_id'] ?? '';
                $statuId = $_POST['statu_id'] ?? '';
                $musteri = $_POST['musteri'] ?? '';
                $sehirId = $_POST['sehir_id'] ?? '';
                $ilceId = $_POST['ilce_id'] ?? '';
                $tespitTuru = $_POST['tespit_turu'] ?? '';
                $tespitBas = $_POST['tespit_bas'] ?? '';
                $tespitBit = $_POST['tespit_bit'] ?? '';
                $sezonId = $_POST['sezon_id'] ?? '';

                $where = "WHERE c.cari_tipi_id = ?" . $sahiplikWhere;
                $params = array_merge([$cariTipiId], $sahiplikParams);

                if ($tarafId !== '') { $where .= " AND t.takip_taraf_id = ?"; $params[] = $tarafId; }
                if ($statuId !== '') { $where .= " AND t.takip_statu_id = ?"; $params[] = $statuId; }
                if ($musteri !== '') { $where .= " AND c.cari_adi LIKE ?"; $params[] = "%$musteri%"; }
                if ($sehirId !== '') { $where .= " AND c.cari_sehirler = ?"; $params[] = $sehirId; }
                if ($ilceId !== '') { $where .= " AND c.cari_ilceler = ?"; $params[] = $ilceId; }
                if ($tespitTuru !== '') { $where .= " AND t.takip_tespit_turu = ?"; $params[] = $tespitTuru; }
                if ($tespitBas !== '') { $where .= " AND CONVERT(date, t.takip_tespit_tarihi) >= ?"; $params[] = $tespitBas; }
                if ($tespitBit !== '') { $where .= " AND CONVERT(date, t.takip_tespit_tarihi) <= ?"; $params[] = $tespitBit; }
                if ($sezonId !== '') { $where .= " AND sez.sezon_id = ?"; $params[] = $sezonId; }

                $telBosFiltre = $_POST['tel_bos'] ?? '';
                if ($telBosFiltre === '1') { $where .= " AND (c.cari_telefon IS NULL OR c.cari_telefon = '')"; }

                $konumFiltre = $_POST['konum'] ?? '';
                if ($konumFiltre === '1') { $where .= " AND t.takip_enlem IS NOT NULL"; }
                elseif ($konumFiltre === '0') { $where .= " AND t.takip_enlem IS NULL"; }

                $odemeYapildiFiltre = $_POST['odeme_yapildi'] ?? '';
                if ($odemeYapildiFiltre === '1') { $where .= " AND EXISTS (SELECT 1 FROM Sozlesme_Odemeler od INNER JOIN Sozlesmeler sz ON od.odeme_sozlesme_id = sz.sozlesme_id WHERE sz.sozlesme_cari_id = c.cari_id AND od.odeme_yapildi = 1)"; }

                $baseQuery = "FROM HukukTakip t
                    INNER JOIN Cari c ON t.takip_cari_id = c.cari_id
                    LEFT JOIN HukukTaraflar tr ON t.takip_taraf_id = tr.taraf_id
                    LEFT JOIN HukukIcraDairesi i ON t.takip_icra_dairesi_id = i.icra_dairesi_id
                    LEFT JOIN HukukStatu st ON t.takip_statu_id = st.statu_id
                    LEFT JOIN Adres_Sehirler s ON c.cari_sehirler = s.SehirId
                    LEFT JOIN Adres_Ilceler il ON c.cari_ilceler = il.ilceId
                    $sezonApply
                    $where";
                
                $toplam = $db->fetchOne("SELECT COUNT(*) as sayi $baseQuery", $params);
                $telBos = $db->fetchOne("SELECT COUNT(*) as sayi $baseQuery AND (c.cari_telefon IS NULL OR c.cari_telefon = '')", $params);
                
                // Ödeme yapılan adet/tutar
                $odemeWhere = "WHERE od.odeme_yapildi = 1 AND c2.cari_tipi_id = ?";
                $odemeParams = [$cariTipiId];
                if ($sezonId !== '') { $odemeWhere .= " AND sz.sozlesme_sezon_id = ?"; $odemeParams[] = $sezonId; }

                $odemeYapilan = $db->fetchOne("
                    SELECT COUNT(od.odeme_id) as adet, ISNULL(SUM(od.odeme_tutar), 0) as tutar
                    FROM Sozlesme_Odemeler od
                    INNER JOIN Sozlesmeler sz ON od.odeme_sozlesme_id = sz.sozlesme_id
                    INNER JOIN Cari c2 ON sz.sozlesme_cari_id = c2.cari_id
                    $odemeWhere
                ", $odemeParams);
                
                // Statü bazlı adetler (0 olanlar dahil)
                $statuAdetleri = $db->fetchAll("
                    SELECT hs.statu_id, hs.statu_ad, COUNT(t.takip_id) as adet
                    FROM HukukStatu hs
                    LEFT JOIN (
                        SELECT t.takip_statu_id, t.takip_id
                        $baseQuery
                    ) t ON hs.statu_id = t.takip_statu_id
                    WHERE hs.Durum = 1 AND hs.statu_cari_tipi_id = ?
                    GROUP BY hs.statu_id, hs.statu_ad
                    ORDER BY hs.statu_ad
                ", array_merge($params, [$cariTipiId]));
                
                echo json_encode(['success' => true, 'data' => [
                    'toplam' => intval($toplam['sayi'] ?? 0),
                    'tel_bos' => intval($telBos['sayi'] ?? 0),
                    'odeme_adet' => intval($odemeYapilan['adet'] ?? 0),
                    'odeme_tutar' => floatval($odemeYapilan['tutar'] ?? 0),
                    'statu_adetleri' => $statuAdetleri
                ]]);
                break;
                
            case 'list':
                $tarafId = $_POST['taraf_id'] ?? '';
                $statuId = $_POST['statu_id'] ?? '';
                $musteri = $_POST['musteri'] ?? '';
                $sehirId = $_POST['sehir_id'] ?? '';
                $ilceId = $_POST['ilce_id'] ?? '';
                $tespitTuru = $_POST['tespit_turu'] ?? '';
                $tespitBas = $_POST['tespit_bas'] ?? '';
                $tespitBit = $_POST['tespit_bit'] ?? '';
                
                $where = "WHERE c.cari_tipi_id = ?" . $sahiplikWhere;
                $params = array_merge([$cariTipiId], $sahiplikParams);
                
                if ($tarafId !== '') {
                    $where .= " AND t.takip_taraf_id = ?";
                    $params[] = $tarafId;
                }
                if ($statuId !== '') {
                    $where .= " AND t.takip_statu_id = ?";
                    $params[] = $statuId;
                }
                if ($musteri !== '') {
                    $where .= " AND c.cari_adi LIKE ?";
                    $params[] = "%$musteri%";
                }
                if ($sehirId !== '') {
                    $where .= " AND c.cari_sehirler = ?";
                    $params[] = $sehirId;
                }
                if ($ilceId !== '') {
                    $where .= " AND c.cari_ilceler = ?";
                    $params[] = $ilceId;
                }
                if ($tespitTuru !== '') {
                    $where .= " AND t.takip_tespit_turu = ?";
                    $params[] = $tespitTuru;
                }
                if ($tespitBas !== '') {
                    $where .= " AND CONVERT(date, t.takip_tespit_tarihi) >= ?";
                    $params[] = $tespitBas;
                }
                if ($tespitBit !== '') {
                    $where .= " AND CONVERT(date, t.takip_tespit_tarihi) <= ?";
                    $params[] = $tespitBit;
                }
                $sezonId = $_POST['sezon_id'] ?? '';
                if ($sezonId !== '') {
                    $where .= " AND sez.sezon_id = ?";
                    $params[] = $sezonId;
                }

                $telBosFiltre = $_POST['tel_bos'] ?? '';
                if ($telBosFiltre === '1') {
                    $where .= " AND (c.cari_telefon IS NULL OR c.cari_telefon = '')";
                }
                
                $konumFiltre = $_POST['konum'] ?? '';
                if ($konumFiltre === '1') { $where .= " AND t.takip_enlem IS NOT NULL"; }
                elseif ($konumFiltre === '0') { $where .= " AND t.takip_enlem IS NULL"; }

                $odemeYapildiFiltre = $_POST['odeme_yapildi'] ?? '';
                if ($odemeYapildiFiltre === '1') {
                    $where .= " AND EXISTS (SELECT 1 FROM Sozlesme_Odemeler od INNER JOIN Sozlesmeler sz ON od.odeme_sozlesme_id = sz.sozlesme_id WHERE sz.sozlesme_cari_id = c.cari_id AND od.odeme_yapildi = 1)";
                }
                
                $data = $db->fetchAll("
                    SELECT 
                        t.takip_id,
                        t.takip_dosya_no,
                        t.takip_aciklama,
                        t.takip_tespit_adet,
                        t.takip_tespit_turu,
                        t.takip_statu_id,
                        t.takip_taraf_id,
                        t.Durum,
                        t.takip_enlem,
                        t.takip_boylam,
                        t.takip_gorseller,
                        t.takip_tutanak_dosya,
                        CONVERT(VARCHAR(10), t.takip_tespit_tarihi, 120) as tespit_tarihi,
                        CONVERT(VARCHAR(19), t.OlusturmaTarihi, 120) as OlusturmaTarihi,
                        c.cari_adi as isletmeci,
                        c.cari_unvan as isyeri_adi,
                        c.cari_adres as adres,
                        c.cari_telefon,
                        c.cari_yetkili_telefon,
                        c.cari_sehirler,
                        c.cari_ilceler,
                        tr.taraf_ad,
                        st.statu_ad,
                        s.SehirAdi as sehir_adi,
                        il.IlceAdi as ilce_adi,
                        sez.sezon_ad,
                        sez.satis_fiyati
                    FROM HukukTakip t
                    INNER JOIN Cari c ON t.takip_cari_id = c.cari_id
                    LEFT JOIN HukukTaraflar tr ON t.takip_taraf_id = tr.taraf_id
                    LEFT JOIN HukukStatu st ON t.takip_statu_id = st.statu_id
                    LEFT JOIN Adres_Sehirler s ON c.cari_sehirler = s.SehirId
                    LEFT JOIN Adres_Ilceler il ON c.cari_ilceler = il.ilceId
                    $sezonApply
                    $where
                    ORDER BY t.OlusturmaTarihi DESC
                ", $params);

                echo json_encode(['success' => true, 'data' => $data]);
                break;
                
            case 'delete':
                if (!$pagePermissions['can_delete']) {
                    echo json_encode(['success' => false, 'message' => 'Silme yetkiniz bulunmamaktadır']);
                    break;
                }
                $id = intval($_POST['id'] ?? 0);
                // Silmeden önce cari_tipi_id kontrolü
                $check = $db->fetchOne("
                    SELECT t.takip_id FROM HukukTakip t
                    INNER JOIN Cari c ON t.takip_cari_id = c.cari_id
                    WHERE t.takip_id = ? AND c.cari_tipi_id = ?" . $sahiplikWhere . "
                ", array_merge([$id, $cariTipiId], $sahiplikParams));
                if (!$check) {
                    echo json_encode(['success' => false, 'message' => 'Kayıt bulunamadı veya yetkiniz yok']);
                    break;
                }
                $db->execute("DELETE FROM HukukTakip WHERE takip_id = ?", [$id]);
                echo json_encode(['success' => true, 'message' => 'Takip kaydı silindi']);
                break;
                
            case 'ihtarname_statu':
                // İhtarname basılan kayıtları "İhtar Gönderildi" statüsüne çeker
                if (!$pagePermissions['can_edit']) {
                    echo json_encode(['success' => false, 'message' => 'Düzenleme yetkiniz bulunmamaktadır']);
                    break;
                }
                if (!$ihtarStatuId) {
                    echo json_encode(['success' => false, 'message' => 'İhtar Gönderildi statüsü tanımlı değil']);
                    break;
                }

                $idler = json_decode($_POST['idler'] ?? '[]', true);
                $idler = is_array($idler) ? array_slice(array_unique(array_map('intval', $idler)), 0, 500) : [];
                $idler = array_values(array_filter($idler));
                if (!$idler) {
                    echo json_encode(['success' => false, 'message' => 'Güncellenecek kayıt yok']);
                    break;
                }

                // Yalnızca bu sayfanın kapsamındaki (cari_tipi_id = 4) kayıtlar güncellenir
                $isaretler = implode(',', array_fill(0, count($idler), '?'));
                $sonuc = $db->execute("
                    UPDATE t SET
                        t.takip_statu_id = ?,
                        t.GuncelleyenKullanici = ?,
                        t.GuncellemeTarihi = GETDATE()
                    FROM HukukTakip t
                    INNER JOIN Cari c ON t.takip_cari_id = c.cari_id
                    WHERE t.takip_id IN ($isaretler) AND c.cari_tipi_id = ?" . $sahiplikWhere . "
                ", array_merge([$ihtarStatuId, $user['kullanici_id']], $idler, [$cariTipiId], $sahiplikParams));

                if ($sonuc === false) {
                    echo json_encode(['success' => false, 'message' => 'Statü güncellenemedi']);
                    break;
                }
                echo json_encode(['success' => true, 'adet' => count($idler)]);
                break;

            case 'get_ilceler':
                $sehirId = intval($_POST['sehir_id'] ?? 0);
                $ilceler = $db->fetchAll("SELECT ilceId, IlceAdi FROM Adres_Ilceler WHERE SehirId = ? ORDER BY IlceAdi", [$sehirId]);
                echo json_encode(['success' => true, 'data' => $ilceler]);
                break;
                
            case 'export_excel':
                $tarafId = $_POST['taraf_id'] ?? '';
                $statuId = $_POST['statu_id'] ?? '';
                $musteri = $_POST['musteri'] ?? '';
                $sehirId = $_POST['sehir_id'] ?? '';
                $ilceId = $_POST['ilce_id'] ?? '';
                $tespitTuru = $_POST['tespit_turu'] ?? '';
                $tespitBas = $_POST['tespit_bas'] ?? '';
                $tespitBit = $_POST['tespit_bit'] ?? '';
                
                $where = "WHERE c.cari_tipi_id = ?" . $sahiplikWhere;
                $params = array_merge([$cariTipiId], $sahiplikParams);
                
                if ($tarafId !== '') { $where .= " AND t.takip_taraf_id = ?"; $params[] = $tarafId; }
                if ($statuId !== '') { $where .= " AND t.takip_statu_id = ?"; $params[] = $statuId; }
                if ($musteri !== '') { $where .= " AND c.cari_adi LIKE ?"; $params[] = "%$musteri%"; }
                if ($sehirId !== '') { $where .= " AND c.cari_sehirler = ?"; $params[] = $sehirId; }
                if ($ilceId !== '') { $where .= " AND c.cari_ilceler = ?"; $params[] = $ilceId; }
                if ($tespitTuru !== '') { $where .= " AND t.takip_tespit_turu = ?"; $params[] = $tespitTuru; }
                if ($tespitBas !== '') { $where .= " AND CONVERT(date, t.takip_tespit_tarihi) >= ?"; $params[] = $tespitBas; }
                if ($tespitBit !== '') { $where .= " AND CONVERT(date, t.takip_tespit_tarihi) <= ?"; $params[] = $tespitBit; }
                $sezonId = $_POST['sezon_id'] ?? '';
                if ($sezonId !== '') { $where .= " AND sez.sezon_id = ?"; $params[] = $sezonId; }

                $telBosFiltre = $_POST['tel_bos'] ?? '';
                if ($telBosFiltre === '1') { $where .= " AND (c.cari_telefon IS NULL OR c.cari_telefon = '')"; }

                $konumFiltre = $_POST['konum'] ?? '';
                if ($konumFiltre === '1') { $where .= " AND t.takip_enlem IS NOT NULL"; }
                elseif ($konumFiltre === '0') { $where .= " AND t.takip_enlem IS NULL"; }

                $odemeYapildiFiltre = $_POST['odeme_yapildi'] ?? '';
                if ($odemeYapildiFiltre === '1') { $where .= " AND EXISTS (SELECT 1 FROM Sozlesme_Odemeler od INNER JOIN Sozlesmeler sz ON od.odeme_sozlesme_id = sz.sozlesme_id WHERE sz.sozlesme_cari_id = c.cari_id AND od.odeme_yapildi = 1)"; }

                $data = $db->fetchAll("
                    SELECT
                        t.takip_tespit_adet as 'Tespit Adet',
                        CONVERT(VARCHAR(10), t.takip_tespit_tarihi, 104) as 'Tespit Tarihi',
                        ISNULL(sez.sezon_ad, '') as 'Sezon',
                        t.takip_tespit_turu as 'Tür',
                        c.cari_adi as 'İşletmeci',
                        c.cari_unvan as 'İşyeri Adı',
                        c.cari_telefon as 'Telefon',
                        c.cari_yetkili_telefon as 'Yetkili Telefon',
                        s.SehirAdi as 'İl',
                        il.IlceAdi as 'İlçe',
                        tr.taraf_ad as 'Taraf',
                        c.cari_adres as 'Adres',
                        t.takip_aciklama as 'Açıklama',
                        ISNULL(sez.satis_fiyati, 0) as 'Satış Fiyatı',
                        st.statu_ad as 'Statü',
                        CASE WHEN t.takip_enlem IS NOT NULL AND t.takip_boylam IS NOT NULL
                             THEN CAST(t.takip_enlem AS VARCHAR(20)) + ',' + CAST(t.takip_boylam AS VARCHAR(20))
                             ELSE '' END as 'Konum',
                        CASE WHEN t.takip_enlem IS NOT NULL AND t.takip_boylam IS NOT NULL
                             THEN 'https://www.google.com/maps?q=' + CAST(t.takip_enlem AS VARCHAR(20)) + ',' + CAST(t.takip_boylam AS VARCHAR(20))
                             ELSE '' END as 'Harita Linki',
                        CASE WHEN t.takip_gorseller IS NULL OR t.takip_gorseller = '' THEN 0
                             ELSE LEN(t.takip_gorseller) - LEN(REPLACE(t.takip_gorseller, ',', '')) + 1 END as 'Fotoğraf Adet',
                        CASE WHEN t.takip_tutanak_dosya IS NOT NULL AND t.takip_tutanak_dosya <> '' THEN 'Var' ELSE '' END as 'Tutanak',
                        CONVERT(VARCHAR(19), t.OlusturmaTarihi, 120) as 'Oluşturma Tarihi'
                    FROM HukukTakip t
                    INNER JOIN Cari c ON t.takip_cari_id = c.cari_id
                    LEFT JOIN HukukTaraflar tr ON t.takip_taraf_id = tr.taraf_id
                    LEFT JOIN HukukStatu st ON t.takip_statu_id = st.statu_id
                    LEFT JOIN Adres_Sehirler s ON c.cari_sehirler = s.SehirId
                    LEFT JOIN Adres_Ilceler il ON c.cari_ilceler = il.ilceId
                    $sezonApply
                    $where
                    ORDER BY t.OlusturmaTarihi DESC
                ", $params);

                // CSV olarak Excel uyumlu export
                header('Content-Type: text/csv; charset=utf-8');
                header('Content-Disposition: attachment; filename="suc-duyurusu-takip-' . date('Y-m-d') . '.csv"');
                header('Pragma: no-cache');
                header('Expires: 0');
                
                $output = fopen('php://output', 'w');
                // UTF-8 BOM
                fprintf($output, chr(0xEF).chr(0xBB).chr(0xBF));
                
                if (count($data) > 0) {
                    fputcsv($output, array_keys($data[0]), ';');
                    foreach ($data as $row) {
                        fputcsv($output, $row, ';');
                    }
                } else {
                    fputcsv($output, ['Veri bulunamadı'], ';');
                }
                
                fclose($output);
                exit;
                
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
                            <div class="info-box text-bg-primary" style="cursor:pointer" onclick="filterByInfoBox('toplam')">
                                <span class="info-box-icon"><i class="bi bi-exclamation-triangle"></i></span>
                                <div class="info-box-content">
                                    <span class="info-box-text">Toplam Kayıt</span>
                                    <span class="info-box-number" id="stat-toplam">0</span>
                                </div>
                            </div>
                        </div>
                        <div class="col-12 col-sm-6 col-md-3">
                            <div class="info-box text-bg-danger" style="cursor:pointer" onclick="filterByInfoBox('tel_bos')">
                                <span class="info-box-icon"><i class="bi bi-telephone-x"></i></span>
                                <div class="info-box-content">
                                    <span class="info-box-text">Telefonu Boş</span>
                                    <span class="info-box-number" id="stat-tel-bos">0</span>
                                </div>
                            </div>
                        </div>
                        <div class="col-12 col-sm-6 col-md-3">
                            <div class="info-box text-bg-success" style="cursor:pointer" onclick="filterByInfoBox('odeme_yapildi')">
                                <span class="info-box-icon"><i class="bi bi-cash-coin"></i></span>
                                <div class="info-box-content">
                                    <span class="info-box-text">Ödeme Yapılan</span>
                                    <span class="info-box-number" id="stat-odeme">0 / 0 ₺</span>
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <!-- Statü Info Boxes -->
                    <div class="row mb-3" id="statuInfoBoxes"></div>
                    
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
                                    <div class="col-md-2">
                                        <label class="form-label">Tespit Türü</label>
                                        <select class="form-select" id="filter_tespit_turu" name="tespit_turu">
                                            <option value="">Tümü</option>
                                            <option value="İHBAR KONUM">İHBAR KONUM</option>
                                            <option value="İMZALI TUTANAK">İMZALI TUTANAK</option>
                                            <option value="İMZASIZ TUTANAK">İMZASIZ TUTANAK</option>
                                            <option value="GÖRSEL YOK">GÖRSEL YOK</option>
                                        </select>
                                    </div>
                                    <div class="col-md-2">
                                        <label class="form-label">Taraf</label>
                                        <select class="form-select" id="filter_taraf_id" name="taraf_id">
                                            <option value="">Tümü</option>
                                            <?php foreach ($taraflar as $t): ?>
                                                <option value="<?= $t['taraf_id'] ?>"><?= htmlspecialchars($t['taraf_ad']) ?></option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                    <div class="col-md-2">
                                        <label class="form-label">İşletmeci</label>
                                        <input type="text" class="form-control" id="filter_musteri" name="musteri" placeholder="İşletmeci ara...">
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
                                </div>
                                <div class="row g-3 mt-2">
                                    <div class="col-md-2">
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
                                    <div class="col-md-2">
                                        <label class="form-label">Konum</label>
                                        <select class="form-select" id="filter_konum" name="konum">
                                            <option value="">Tümü</option>
                                            <option value="1">Konumu Olan</option>
                                            <option value="0">Konumu Olmayan</option>
                                        </select>
                                    </div>
                                    <div class="col-md-4 d-flex align-items-end">
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
                            <h3 class="card-title"><i class="bi bi-exclamation-triangle"></i> Suç Duyurusu Dosyaları</h3>
                            <div class="card-tools">
                                <?php if ($pagePermissions['can_add']): ?>
                                <a href="/admin/suc-duyurusu-toplu-import" class="btn btn-sm btn-info">
                                    <i class="bi bi-upload"></i> Toplu Import
                                </a>
                                <?php endif; ?>
                                <button type="button" class="btn btn-sm btn-dark" id="ihtarnameBtn">
                                    <i class="bi bi-printer"></i> İhtarname Yazdır
                                    <span class="badge text-bg-light ms-1" id="seciliAdet">0</span>
                                </button>
                                <button type="button" class="btn btn-sm btn-success" onclick="exportExcel()">
                                    <i class="bi bi-file-earmark-excel"></i> Excel
                                </button>
                                <button type="button" class="btn btn-sm btn-secondary" data-bs-toggle="collapse" data-bs-target="#filterCard">
                                    <i class="bi bi-funnel"></i> Filtre
                                </button>
                                <?php if ($pagePermissions['can_add']): ?>
                                <a href="/admin/form?cari_tipi_id=4" class="btn btn-sm btn-primary">
                                    <i class="bi bi-plus-lg"></i> Yeni Dosya
                                </a>
                                <?php endif; ?>
                            </div>
                        </div>
                        <div class="card-body p-0">
                                <table class="table table-bordered table-striped table-hover" id="mainTable">
                                    <thead>
                                        <tr>
                                            <th width="40" class="text-center">
                                                <div class="form-check form-switch d-flex justify-content-center">
                                                    <input class="form-check-input secTumu" type="checkbox" role="switch" title="Tümünü seç">
                                                </div>
                                            </th>
                                            <th width="50">#</th>
                                            <th>Tespit Adet</th>
                                            <th>Tespit Tarihi</th>
                                            <th>Sezon</th>
                                            <th>Tür</th>
                                            <th>İşletmeci</th>
                                            <th>İşyeri Adı</th>
                                            <th>Telefon</th>
                                            <th>İl</th>
                                            <th>İlçe</th>
                                            <th>Taraf</th>
                                            <th>Adres</th>
                                            <th>Açıklama</th>
                                            <th>Satış Fiyatı</th>
                                            <th>Statü</th>
                                            <th width="70">Konum</th>
                                            <th width="70">Görsel</th>
                                            <th width="100">İşlemler</th>
                                        </tr>
                                    </thead>
                                    <tbody id="tableBody">
                                        <tr><td colspan="19" class="text-center">Yükleniyor...</td></tr>
                                    </tbody>
                                </table>
                        </div>
                    </div>
                    
                </div>
            </div>
        </main>
        
        <?php include __DIR__ . '/../includes/footer.php'; ?>

    <!-- İhbar fotoğrafları önizleme -->
    <div class="modal fade" id="gorselModal" tabindex="-1">
        <div class="modal-dialog modal-dialog-scrollable modal-lg">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="gorselModalBaslik">Fotoğraflar</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body" id="gorselModalGovde"></div>
            </div>
        </div>
    </div>

    <!-- İhtarname yazdırma -->
    <div class="modal fade" id="ihtarnameModal" tabindex="-1">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title"><i class="bi bi-printer"></i> İhtarname Yazdır</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="row g-3 mb-3">
                        <div class="col-md-4">
                            <label class="form-label">İhtar Tarihi</label>
                            <input type="date" class="form-control" id="ihtarTarihi">
                        </div>
                        <div class="col-md-8">
                            <label class="form-label">Basılacak İhtarnameler</label>
                            <div id="ihtarnameOzet"></div>
                        </div>
                    </div>
                    <div id="ihtarnameUyarilar" class="mt-3"></div>
                </div>
                <div class="modal-footer justify-content-between">
                    <small class="text-muted">
                        <?php if ($ihtarStatuId && $pagePermissions['can_edit']): ?>
                            <i class="bi bi-info-circle"></i> Basılan kayıtların statüsü <b>İhtar Gönderildi</b> olarak güncellenecek.
                        <?php elseif (!$ihtarStatuId): ?>
                            <i class="bi bi-exclamation-triangle text-warning"></i> "İhtar Gönderildi" statüsü tanımlı olmadığı için statü güncellenmeyecek.
                        <?php endif; ?>
                    </small>
                    <div>
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">İptal</button>
                    <button type="button" class="btn btn-dark" id="ihtarnameYazdirBtn">
                        <i class="bi bi-printer"></i> Yazdır
                    </button>
                    </div>
                </div>
            </div>
        </div>
    </div>
    </div>

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
        let currentFilters = varsayilanSezonId ? { sezon_id: varsayilanSezonId } : {};
        const canAdd = <?= json_encode($pagePermissions['can_add']) ?>;
        const canEdit = <?= json_encode($pagePermissions['can_edit']) ?>;
        const canDelete = <?= json_encode($pagePermissions['can_delete']) ?>;
        let table = null;
        
        // Toplu ihtarname için seçili takip_id listesi (sayfa/filtre değişse de korunur)
        const seciliIdler = new Set();

        // taraf_id -> ihtarname şablonu eşlemesi
        const ihtarnameSablonlari = <?= json_encode($ihtarnameSablonlari, JSON_UNESCAPED_UNICODE) ?>;
        const ihtarStatuTanimli = <?= json_encode($ihtarStatuId !== null) ?>;

        const statuRenkler = ['text-bg-secondary', 'text-bg-danger', 'text-bg-dark', 'text-bg-primary', 'text-bg-info', 'text-bg-success', 'text-bg-warning'];
        const statuIkonlar = ['bi-folder', 'bi-exclamation-circle', 'bi-clock-history', 'bi-arrow-repeat', 'bi-shield-check', 'bi-check2-all', 'bi-archive'];
        
        function filterByInfoBox(type) {
            // Sezon seçimi korunur, diğer filtreler temizlenir
            const seciliSezon = $('#filter_sezon_id').val();
            $('#filterForm')[0].reset();
            $('#filter_sezon_id').val(seciliSezon).trigger('change.select2');
            $('#filter_tespit_turu').val('').trigger('change.select2');
            $('#filter_taraf_id').val('').trigger('change.select2');
            $('#filter_statu_id').val('').trigger('change.select2');
            $('#filter_sehir_id').val('').trigger('change.select2');
            $('#filter_konum').val('').trigger('change.select2');
            $('#filter_ilce_id').html('<option value="">Önce Şehir Seç</option>').prop('disabled', true);
            currentFilters = {};
            if (seciliSezon) currentFilters.sezon_id = seciliSezon;

            switch(type) {
                case 'toplam':
                    // Tüm filtreleri temizle (zaten temizlendi)
                    break;
                case 'tel_bos':
                    currentFilters.tel_bos = '1';
                    break;
                case 'odeme_yapildi':
                    currentFilters.odeme_yapildi = '1';
                    break;
                default:
                    // Statü ID ile filtre
                    if (type.startsWith('statu_')) {
                        const statuId = type.replace('statu_', '');
                        currentFilters.statu_id = statuId;
                        $('#filter_statu_id').val(statuId).trigger('change.select2');
                    }
                    break;
            }
            
            table.ajax.reload();
            showToast('Filtre uygulandı', 'info');
        }
        
        function loadStats() {
            $.post('', { action: 'stats', ...currentFilters }, function(res) {
                if (res.success) {
                    $('#stat-toplam').text(res.data.toplam);
                    $('#stat-tel-bos').text(res.data.tel_bos);
                    
                    // Ödeme bilgisi
                    const tutar = parseFloat(res.data.odeme_tutar || 0).toLocaleString('tr-TR', {minimumFractionDigits: 2, maximumFractionDigits: 2});
                    $('#stat-odeme').text(res.data.odeme_adet + ' / ' + tutar + ' ₺');
                    
                    // Statü info box'larını render et
                    let statuHtml = '';
                    if (res.data.statu_adetleri && res.data.statu_adetleri.length > 0) {
                        res.data.statu_adetleri.forEach(function(s, i) {
                            const renk = statuRenkler[i % statuRenkler.length];
                            const ikon = statuIkonlar[i % statuIkonlar.length];
                            statuHtml += `
                                <div class="col-12 col-sm-6 col-md-3">
                                    <div class="info-box ${renk}" style="cursor:pointer" onclick="filterByInfoBox('statu_${s.statu_id}')">
                                        <span class="info-box-icon"><i class="bi ${ikon}"></i></span>
                                        <div class="info-box-content">
                                            <span class="info-box-text">${s.statu_ad}</span>
                                            <span class="info-box-number">${s.adet}</span>
                                        </div>
                                    </div>
                                </div>`;
                        });
                    }
                    $('#statuInfoBoxes').html(statuHtml);
                }
            });
        }
        
        function initDataTable() {
            table = $('#mainTable').DataTable({
                processing: true,
                stateSave: true,
                scrollX: true,
                autoWidth: false,
                language: { url: '//cdn.datatables.net/plug-ins/1.13.7/i18n/tr.json' },
                ajax: {
                    url: '',
                    type: 'POST',
                    data: function(d) {
                        return { action: 'list', ...currentFilters };
                    },
                    dataSrc: function(json) {
                        if (json.success) {
                            return json.data;
                        }
                        return [];
                    }
                },
                columns: [
                    {
                        // Toplu ihtarname seçimi
                        data: null,
                        orderable: false,
                        searchable: false,
                        className: 'text-center',
                        render: function(data) {
                            const isaretli = seciliIdler.has(data.takip_id) ? 'checked' : '';
                            return `<div class="form-check form-switch d-flex justify-content-center">
                                        <input class="form-check-input satirSec" type="checkbox" role="switch" value="${data.takip_id}" ${isaretli}>
                                    </div>`;
                        }
                    },
                    { data: null, orderable: false, render: (data, type, row, meta) => meta.row + 1 },
                    { data: 'takip_tespit_adet', defaultContent: '-' },
                    { data: 'tespit_tarihi', defaultContent: '-', render: data => data ? formatDate(data) : '-' },
                    {
                        data: 'sezon_ad',
                        defaultContent: '-',
                        render: data => data ? `<span class="badge bg-secondary">${data}</span>` : '-'
                    },
                    { data: 'takip_tespit_turu', defaultContent: '-' },
                    { data: 'isletmeci', defaultContent: '-' },
                    { data: 'isyeri_adi', defaultContent: '-' },
                    { 
                        data: null,
                        defaultContent: '-',
                        render: function(data) {
                            let phones = [];
                            if (data.cari_telefon) phones.push(data.cari_telefon);
                            if (data.cari_yetkili_telefon) phones.push(data.cari_yetkili_telefon);
                            return phones.length > 0 ? phones.join('<br>') : '-';
                        }
                    },
                    { data: 'sehir_adi', defaultContent: '-' },
                    { data: 'ilce_adi', defaultContent: '-' },
                    { data: 'taraf_ad', defaultContent: '-' },
                    { data: 'adres', defaultContent: '-', render: data => data ? `<span title="${data}">${data.substring(0,30)}${data.length > 30 ? '...' : ''}</span>` : '-' },
                    { data: 'takip_aciklama', defaultContent: '-', render: data => data ? `<span title="${data}">${data.substring(0,30)}${data.length > 30 ? '...' : ''}</span>` : '-' },
                    {
                        data: 'satis_fiyati',
                        defaultContent: '-',
                        className: 'text-end',
                        render: (data, type) => {
                            const v = parseFloat(data) || 0;
                            if (type === 'sort' || type === 'type') return v;
                            return v > 0 ? v.toLocaleString('tr-TR', { minimumFractionDigits: 2, maximumFractionDigits: 2 }) + ' ₺' : '-';
                        }
                    },
                    { 
                        data: 'statu_ad', 
                        render: data => data ? `<span class="badge bg-info">${data}</span>` : '-' 
                    },
                    {
                        // Konum: ihbar sayfasından girilen kayıtlarda dolu gelir
                        data: null,
                        orderable: false,
                        className: 'text-center',
                        render: function(data) {
                            if (!data.takip_enlem || !data.takip_boylam) return '-';
                            const url = `https://www.google.com/maps?q=${data.takip_enlem},${data.takip_boylam}`;
                            return `<a href="${url}" target="_blank" class="btn btn-sm btn-outline-primary" title="${data.takip_enlem}, ${data.takip_boylam}"><i class="bi bi-geo-alt-fill"></i></a>`;
                        }
                    },
                    {
                        // Görsel: fotoğraf sayısı + tutanak rozeti
                        data: null,
                        orderable: false,
                        className: 'text-center',
                        render: function(data) {
                            let html = '';
                            let adet = 0;
                            try {
                                const liste = data.takip_gorseller ? JSON.parse(data.takip_gorseller) : [];
                                adet = Array.isArray(liste) ? liste.length : 0;
                            } catch (e) { adet = 0; }

                            if (adet > 0) {
                                html += `<button type="button" class="btn btn-sm btn-outline-secondary" onclick="gorselleriAc(${data.takip_id})" title="Fotoğrafları göster"><i class="bi bi-images"></i> ${adet}</button> `;
                            }
                            if (data.takip_tutanak_dosya) {
                                html += `<a href="/${data.takip_tutanak_dosya}" target="_blank" class="btn btn-sm btn-outline-dark" title="Tutanak evrağı"><i class="bi bi-paperclip"></i></a>`;
                            }
                            return html || '-';
                        }
                    },
                    {
                        data: null,
                        orderable: false,
                        render: data => {
                            let buttons = '';
                            if (canEdit) {
                                buttons += `<a href="/admin/form?id=${data.takip_id}&cari_tipi_id=4" class="btn btn-sm btn-warning" title="Düzenle"><i class="bi bi-pencil"></i></a> `;
                            }
                            if (canDelete) {
                                buttons += `<button class="btn btn-sm btn-danger" onclick="deleteRecord(${data.takip_id})" title="Sil"><i class="bi bi-trash"></i></button>`;
                            }
                            return buttons || '-';
                        }
                    }
                ],
                order: [[2, 'desc']],
                drawCallback: function() { seciliSayaciGuncelle(); }
            });
        }
        
        // İhbar fotoğraflarını modalda göster
        function gorselleriAc(takipId) {
            const satir = table.rows().data().toArray().find(r => r.takip_id == takipId);
            if (!satir) return;

            let liste = [];
            try { liste = satir.takip_gorseller ? JSON.parse(satir.takip_gorseller) : []; } catch (e) { liste = []; }
            if (!liste.length) return;

            const govde = liste.map(yol => {
                const url = '/' + String(yol).replace(/^\/+/, '');
                return `<a href="${url}" target="_blank" class="d-block mb-2">
                            <img src="${url}" class="img-fluid rounded border" alt="">
                        </a>`;
            }).join('');

            $('#gorselModalBaslik').text(`#${takipId} · ${satir.isyeri_adi || satir.isletmeci || ''} (${liste.length} fotoğraf)`);
            $('#gorselModalGovde').html(govde);
            new bootstrap.Modal(document.getElementById('gorselModal')).show();
        }

        function deleteRecord(id) {
            confirmAction('Bu dosyayı silmek istediğinize emin misiniz?', null, function() {
                $.post('', { action: 'delete', id: id }, function(res) {
                    if (res.success) {
                        showSuccess('Silindi!', res.message);
                        loadStats();
                        table.ajax.reload(null, false);
                    } else {
                        showError('Hata!', res.message);
                    }
                });
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
        
        /* ==================== İHTARNAME YAZDIRMA ==================== */

        function ihtarEsc(v) {
            return String(v ?? '').replace(/[&<>"]/g, function(k) {
                return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' }[k];
            });
        }

        function seciliSayaciGuncelle() {
            $('#seciliAdet').text(seciliIdler.size);
            const sayfaKutulari = $('#mainTable tbody .satirSec');
            const isaretli = sayfaKutulari.filter(':checked').length;
            $('.secTumu').prop('checked', sayfaKutulari.length > 0 && isaretli === sayfaKutulari.length);
        }

        // Seçili takip_id'lere karşılık gelen satır verisi
        function seciliKayitlar() {
            return table.rows().data().toArray().filter(r => seciliIdler.has(r.takip_id));
        }

        // Tespit tarihini gg.aa.yyyy biçimine çevirir
        function ihtarTarihBicim(deger) {
            if (!deger) return '';
            const p = String(deger).substring(0, 10).split('-');
            return p.length === 3 ? `${p[2]}.${p[1]}.${p[0]}` : String(deger);
        }

        /*
         * Açıklamadan karşılaşan takımları ayrıştırır. İki biçim desteklenir:
         *  - Toplu import / elle giriş: açıklamanın kendisi maçtır ("AMED-TRABZONSPOR")
         *  - İhbar sayfası: "MAÇ: Ev Sahibi - Deplasman (Lig) 21:45" satırı
         * Lig adı ve saat ihtarnamede kullanılmadığı için ayıklanır.
         */
        function macBilgisiAyristir(aciklama) {
            if (!aciklama) return '';
            const satirlar = String(aciklama).split(/\r?\n/).map(s => s.trim()).filter(Boolean);
            if (!satirlar.length) return '';

            const macSatiri = satirlar.find(s => /MAÇ\s*:/i.test(s));
            let govde = macSatiri ? macSatiri.replace(/^.*?MAÇ\s*:\s*/i, '').trim() : satirlar[0];

            govde = govde.replace(/\s+\d{1,2}:\d{2}\s*$/, '').trim();
            const parantez = govde.match(/^(.*?)\s*\(([^)]*)\)\s*$/);
            return (parantez ? parantez[1] : govde).trim();
        }

        // Bir kayıttan şablon değişkenlerini hazırlar
        function ihtarnameVerisi(k, ihtarTarihi) {
            const mac = macBilgisiAyristir(k.takip_aciklama);
            const yer = [k.ilce_adi, k.sehir_adi].filter(Boolean).join(' / ');
            const adresParcalari = [k.adres, yer].filter(Boolean);
            return {
                il: (k.sehir_adi || '').toLocaleUpperCase('tr-TR'),
                isyeri: (k.isyeri_adi || k.isletmeci || '').toLocaleUpperCase('tr-TR'),
                adres: adresParcalari.join(' ').toLocaleUpperCase('tr-TR'),
                tespitTarihi: ihtarTarihBicim(k.tespit_tarihi),
                mac: mac.toLocaleUpperCase('tr-TR'),
                sezon: k.sezon_ad || '',
                ihtarTarihi: ihtarTarihBicim(ihtarTarihi),
                logo: location.origin + '/admin/assets/img/ihtarname/'
            };
        }

        const ihtarnameImza = `
            <div class="ih-imza">
                <div>Hukuk Birimi İrtibat No : 0500 000 00 03</div>
                <div class="ih-imza-alt">0500 000 00 02</div>
            </div>`;

        function ihtarnameYayinPlatformu(v) {
            const sezon = v.sezon ? ihtarEsc(v.sezon) : '';
            return `
            <div class="ihtarname">
                <div class="ih-logo"><img src="${v.logo}yayinplatformu.jpg" alt=""></div>
                <div class="ih-baslik">İHTARNAME</div>
                <table class="ih-taraf">
                    <tr>
                        <td class="ih-etiket">İHTAR EDEN</td>
                        <td class="ih-ikinokta">:</td>
                        <td>
                            YAYINPLATFORMU <b>${ihtarEsc(v.il)}</b> İLİ ${sezon} FUTBOL SEZONU TİCARİ İŞLETME SATIŞ YETKİLİSİ<br>
                            ÖRNEK AKADEMİ EĞİTİM DANIŞMANLIK VE MESLEKİ YETERLİLİK BELGELENDİRME A.Ş.<br>
                            İSMET KAPTAN MAH ŞAİR EŞREF BULV. NO:31/4<br>
                            KONAK/İZMİR
                        </td>
                    </tr>
                    <tr>
                        <td class="ih-etiket">MUHATAP</td>
                        <td class="ih-ikinokta">:</td>
                        <td>Sayın <b>${ihtarEsc(v.isyeri)}</b> yetkilisi<br>${ihtarEsc(v.adres)}</td>
                    </tr>
                </table>
                <p><span class="ih-etiket-satir">İHTARIN KONUSU :</span> YAYINPLATFORMU'ün hak sahibi olduğu ve Şirketimizin
                <b>${ihtarEsc(v.il)}</b> ili münhasır ticari işletme satış yetkilisi olduğu Yayınların İşyerinde Haksız ve
                Yetkisiz Kullanımından Dolayı Verilen Zararların Tazmini İçin Uyarı</p>
                <div class="ih-etiket-satir">AÇIKLAMALAR :</div>
                <p><b>Sayın Muhatap,</b> YAYINPLATFORMU platformu ${sezon} sezonu boyunca Trendyol Süperlig, Trendyol 1. Lig,
                Türkiye Sigorta Basketbol Süper Ligi, Premier League, WTA Tenis Turnuvaları LIGUE 1, Formula 1 gibi birçok
                içeriğin Türkiye yayın haklarına sahip olup, bu yayınlar üyelerine vermiş olduğu şifreli decoder uydu
                cihazları ile ticari işletmelerde yayınlanmaktadır.</p>
                <p>Hak sahibi olduğu yayınların şifre kırıcı cihazlarla, bireysel üyelikle alınan YayinPlatformu üyeliğinin ticari
                işletmede kullanılması yolu ile veya internet üzerinden illegal yayın veren sitelerden elde edilerek ticari
                işletmenizde umuma açık yayınlanması, hem cezai hem hukuki yaptırımları içermektedir. Ticari işletmelerde maç
                yayınının gösterilebilmesi için mutlaka yasal ticari abonelik yapılması gerekmektedir.</p>
                <p>YayinPlatformu'ün münhasır yayın hakkı çerçevesinde <b>${ihtarEsc(v.il)}</b> sınırlarında ticari işletmelerin
                satış yetkisini ÖRNEK AKADEMİ EĞİTİM DANIŞMANLIK VE MESLEKİ YETERLİLİK BELGELENDİRME A.Ş.'dedir. Bu hak
                çerçevesinde, şirketimiz ile yasalara uygun bir <b>Ticari İşyeri Abonelik Sözleşmesi yapmaksızın;
                Trendyol Süper Lig kapsamında ${ihtarEsc(v.tespitTarihi)} tarihinde yayınlanan ${ihtarEsc(v.mac)} karşılaşmasını
                ${ihtarEsc(v.isyeri)} isimli işyerinde toplu gösterim yapılarak arz edildiği, görüntü ve tutanak altına
                alınmıştır.</b></p>
                <p>Mevcut durumun tespit edilmiş olması sebebiyle, hakkınızda <b>TCK 163/2</b> kapsamında "Telefon hatları ile
                frekanslarından veya elektromanyetik dalgalarla yapılan şifreli veya şifresiz yayınlardan sahibinin veya
                zilyedinin rızası olmadan yararlanan kişi, altı aydan iki yıla kadar hapis veya adlî para cezası ile
                cezalandırılır." maddesi gereğince Türk Ceza Kanunu'na muhalefet etmeniz nedeniyle şikayette bulunulacaktır.
                Tespit edilen deliller sebebiyle TCK 163/2 kapsamında yargılanmanız söz konusu olabilecektir.</p>
                <p>Ayrıca, <b>Fikir ve Sanat Eserleri Kanunu'nun 68. maddesi</b> uyarınca, "Eseri, icrayı, fonogramı veya
                yapımları hak sahiplerinden bu Kanuna uygun yazılı izni almadan işleyen, çoğaltan, çoğaltılmış nüshaları yayan,
                temsil eden veya her türlü işaret, ses veya görüntü nakline yarayan araçlarla umuma iletenlerden, izni
                alınmamış hak sahipleri, sözleşme yapılmış olması halinde isteyebileceği bedelin veya bu Kanun hükümleri
                uyarınca tespit edilecek rayiç bedelin en çok üç kat fazlasını isteyebilir." Dolayısıyla, şirketin yıllık
                ticari abonelik bedelinin üç katını talep ederek hukuk davası açma hakkı saklıdır.</p>
                <div class="ih-etiket-satir">SONUÇ – İSTEM :</div>
                <p>Yukarıda arz ve izah edilen dava ve takiplere maruz kalmamanız ve şirketin mağduriyetinin aşağıda
                belirtilmiş olan talepler doğrultusunda giderilerek UZLAŞMA sağlamak için;</p>
                <p>İşbu ihtarnamenin tarafınıza tebliğinden itibaren üç gün içerisinde :</p>
                <p>Haksız ve yetkisiz kullanımınızdan kaynaklı uzlaşma şartları için ve Ticari İşyeri aboneliği yaptırmanız
                için aşağıda belirtilen telefon numarası ile irtibata geçmenizi aksi takdirde her türlü hukuksal yola
                başvuracağımız, ve bu başvurular sonucu yargılama giderleri ve vekalet ücreti ödemek durumunda kalacağınız
                ihtar olunur. ${ihtarEsc(v.ihtarTarihi)}</p>
                <div class="ih-kapanis">
                    YAYINPLATFORMU<br>
                    <b>${ihtarEsc(v.il)}</b> İLİ MÜNHASIR SATIŞ YETKİLİSİ<br>
                    ÖRNEK AKADEMİ EĞİTİM DANIŞMANLIK VE MESLEKİ YETERLİLİK<br>
                    BELGELENDİRME A.Ş. HUKUK MÜŞAVİRLİĞİ
                </div>
                ${ihtarnameImza}
            </div>`;
        }

        function ihtarnameSSport(v) {
            const sezon = v.sezon ? ihtarEsc(v.sezon) : '';
            return `
            <div class="ihtarname">
                <div class="ih-logo"><img src="${v.logo}ssport.jpg" alt=""></div>
                <div class="ih-baslik">İHTARNAME</div>
                <table class="ih-taraf">
                    <tr>
                        <td class="ih-etiket">İHTAR EDEN</td>
                        <td class="ih-ikinokta">:</td>
                        <td>
                            SARAN İNTERNET TELEVİZYON YAYINCILIK A.Ş (SSPORT+)<br>
                            TÜRKİYE TİCARİ SATIŞ YETKİLİSİ<br>
                            ÖRNEK AKADEMİ EĞİTİM DANIŞMANLIK VE MESLEKİ YETERLİLİK BELGELENDİRME A.Ş.<br>
                            İSMET KAPTAN MAH ŞAİR EŞREF BULV. NO:31/4<br>
                            KONAK/İZMİR
                        </td>
                    </tr>
                    <tr>
                        <td class="ih-etiket">MUHATAP</td>
                        <td class="ih-ikinokta">:</td>
                        <td>Sayın ${ihtarEsc(v.isyeri)} yetkilisi,<br>${ihtarEsc(v.adres)}</td>
                    </tr>
                </table>
                <p><span class="ih-etiket-satir">İHTARIN KONUSU :</span> SARAN İNTERNET TELEVİZYON YAYINCILIK A.Ş
                (SSPORT+)'un Hak Sahibi Olduğu ve Şirketimizin TÜRKİYE münhasır ticari işletme satış yetkilisi olduğu
                Yayınların İşyerinde Haksız ve Yetkisiz Kullanımından Dolayı Verilen Zararların Tazmini İçin Uyarı</p>
                <div class="ih-etiket-satir">AÇIKLAMALAR :</div>
                <p><b>Sayın Muhatap,</b> SSPORT+ platformu ${sezon} sezonu boyunca BUNDESLIGA, LA LIGA, SERIE A, LIGA
                PORTUGAL, NBA, EUROLEAGUE, MOTO GP, WIMBLEDON, NFL ve UFC gibi birçok içeriğin ve büyük takımların hazırlık
                maçlarının Türkiye yayın haklarına sahip olup, bu yayınlar şifreli olarak internet ortamında
                yayınlanmaktadır.</p>
                <p>SARAN İNTERNET TELEVİZYON YAYINCILIK A.Ş (SSPORT+)'nin münhasır yayın hakkı çerçevesinde tüm
                Türkiye'deki ticari işletmelerin satış yetkisini ve bu doğrultuda yayınların hukuki korunması yetkisini
                ÖRNEK AKADEMİ EĞİTİM DANIŞMANLIK VE MESLEKİ YETERLİLİK BELGELENDİRME A.Ş.'ye devretmiştir. Bu hak
                çerçevesinde, şirketimiz ile yasalara uygun bir <b>Ticari İşyeri Abonelik Sözleşmesi yapmaksızın;
                ${ihtarEsc(v.tespitTarihi)} tarihinde ${ihtarEsc(v.mac)} karşılaşmasını ${ihtarEsc(v.isyeri)} isimli
                işyerinde toplu gösterim yapılarak arz edildiği, görüntü ve tutanak altına alınmıştır.</b></p>
                <p>Mevcut durumun tespit edilmiş olması sebebiyle, hakkınızda TCK 163/2 kapsamında "Telefon hatları ile
                frekanslarından veya elektromanyetik dalgalarla yapılan şifreli veya şifresiz yayınlardan sahibinin veya
                zilyedinin rızası olmadan yararlanan kişi, altı aydan iki yıla kadar hapis veya adlî para cezası ile
                cezalandırılır." maddesi gereğince Türk Ceza Kanunu'na muhalefet etmeniz nedeniyle şikayette bulunulacaktır.
                Tespit edilen deliller sebebiyle TCK 163/2 kapsamında yargılanmanız söz konusu olabilecektir.</p>
                <p>Ayrıca, Fikir ve Sanat Eserleri Kanunu'nun 68. maddesi uyarınca, "Eseri, icrayı, fonogramı veya yapımları
                hak sahiplerinden bu Kanuna uygun yazılı izni almadan işleyen, çoğaltan, çoğaltılmış nüshaları yayan, temsil
                eden veya her türlü işaret, ses veya görüntü nakline yarayan araçlarla umuma iletenlerden, izni alınmamış hak
                sahipleri, sözleşme yapılmış olması halinde isteyebileceği bedelin veya bu Kanun hükümleri uyarınca tespit
                edilecek rayiç bedelin en çok üç kat fazlasını isteyebilir." Dolayısıyla, müvekkil şirketin yıllık ticari
                abonelik bedelinin üç katını talep ederek hukuk davası açma hakkı saklıdır.</p>
                <div class="ih-etiket-satir">SONUÇ – İSTEM :</div>
                <p>Yukarıda arz ve izah edilen dava ve takiplere maruz kalmamanız ve müvekkil şirketin mağduriyetinin
                aşağıda belirtilmiş olan talepler doğrultusunda giderilerek UZLAŞMA sağlamak için;</p>
                <p>İşbu ihtarnamenin tarafınıza tebliğinden itibaren:</p>
                <p>1-) Haksız ve yetkisiz kullanımınızdan kaynaklı uzlaşma şartları için tebliğden itibaren 3 gün içinde
                şirket yetkilileri ile irtibata geçmenizi<br>
                2-) İvedilikle Ticari İşyeri aboneliği yaptırmanızı;</p>
                <p>aksi takdirde her türlü hukuksal yola başvuracağımız, ve bu başvurular sonucu yargılama giderleri ve
                vekalet ücreti ödemek durumunda kalacağınız müvekkil şirketler adına vekaleten ihtar olunur
                ${ihtarEsc(v.ihtarTarihi)}</p>
                <div class="ih-kapanis">
                    SSPORT +<br>
                    MÜNHASIR SATIŞ YETKİLİSİ<br>
                    ÖRNEK AKADEMİ EĞİTİM DANIŞMANLIK VE MESLEKİ YETERLİLİK<br>
                    BELGELENDİRME A.Ş. HUKUK MÜŞAVİRLİĞİ
                </div>
                <div class="ih-imza"><div>Hukuk Birimi İrtibat No : 0500 000 00 03</div></div>
            </div>`;
        }

        function ihtarnameTabii(v) {
            return `
            <div class="ihtarname">
                <div class="ih-baslik ih-baslik-bosluksuz">İHTARNAME</div>
                <table class="ih-taraf">
                    <tr>
                        <td class="ih-etiket">İHTAR EDEN</td>
                        <td class="ih-ikinokta">:</td>
                        <td>
                            TRT/Türkiye Radyo Televizyon Kurumu (TABİİ)<br>
                            <b>${ihtarEsc(v.il)}</b> İLİ SATIŞ YETKİLİSİ<br><br>
                            ÖRNEK AKADEMİ EĞİTİM DANIŞMANLIK VE MESLEKİ YETERLİLİK BELGELENDİRME A.Ş.<br>
                            İSMET KAPTAN MAH ŞAİR EŞREF BULV. NO:31/4<br>
                            KONAK/İZMİR
                        </td>
                    </tr>
                    <tr>
                        <td class="ih-etiket">MUHATAP</td>
                        <td class="ih-ikinokta">:</td>
                        <td>Sayın <b>${ihtarEsc(v.isyeri)}</b> Yetkilisi,<br>${ihtarEsc(v.adres)}</td>
                    </tr>
                </table>
                <p><span class="ih-etiket-satir">İHTARIN KONUSU :</span> TRT/ TABİİ'nin Hak Sahibi Olduğu ve şirketimizin
                <b>${ihtarEsc(v.il)}</b> ili münhasır ticari işletme satış yetkilisi olduğu Yayınların İşyerinde Haksız ve
                Yetkisiz Kullanımından Dolayı Verilen Zararların Tazmini</p>
                <div class="ih-etiket-satir">AÇIKLAMALAR :</div>
                <p>Sayın Muhatap,</p>
                <p>UEFA Şampiyonlar Ligi, UEFA Avrupa Ligi, UEFA Konferans Ligi ve UEFA Süper Kupa, FA Cup (İngiltere
                Federasyon Kupası), maçlarının Türkiye sınırların içerisinde yayın hakkı TRT ve TRT'nin Şifreli olarak
                internet ortamında yayın veren TABİİ Platformuna aittir. TRT yayın hakkı çerçevesinde Ticari satış yetkisini
                Güneş Telekomünikasyon ve İletişim Hiz. Ticaret Ltd. Şti'ye devretmiştir. İlgili şirket de
                <b>${ihtarEsc(v.il)}</b> ilinde ticari işletme satış yetkisini ÖRNEK AKADEMİ EĞİTİM DANIŞMANLIK VE MESLEKİ
                YETERLİLİK BELGELENDİRME A.Ş'ye devretmiştir. Bu hak çerçevesinde, şirketimizle yasalara uygun
                <b>Ticari İşyeri Abonelik Sözleşmesi yapmaksızın;</b> ${ihtarEsc(v.tespitTarihi)} tarihinde
                <b>${ihtarEsc(v.mac)}</b> maç yayınında <b>${ihtarEsc(v.isyeri)}</b> isimli işyerinde toplu gösterim
                yapılarak arz edildiği, görüntü ve tutanak altına alınmıştır.</p>
                <p>Mevcut durumun tespit edilmesi ile Türk Ceza Kanunu'na muhalefet etmenizle ilgili şikâyette bulunulacak
                olup düzenlenmiş olan tutanaktaki bulgular sebebi ile <b>TCK 163/2 kapsamında</b> yargılanmanız söz konusu
                olacaktır.</p>
                <p>( Türk Ceza Kanunu 163/2: Telefon hatları ile frekanslarından veya elektromanyetik dalgalarla yapılan
                şifreli veya şifresiz yayınlardan sahibinin veya zilyedinin rızası olmadan yararlanan kişi, <b>altı aydan iki
                yıla kadar</b> hapis veya adlî para cezası ile cezalandırılır.)</p>
                <p>Ayrıca <b>Fikir ve Sanat Eserleri Kanunu Madde 68</b> uyarınca 'Eseri, icrayı, fonogramı veya yapımları
                hak sahiplerinden bu Kanuna uygun yazılı izni almadan, işleyen, çoğaltan, çoğaltılmış nüshaları yayan, temsil
                eden veya her türlü işaret, ses veya görüntü nakline yarayan araçlarla umuma iletenlerden, izni alınmamış hak
                sahipleri sözleşme yapılmış olması halinde isteyebileceği bedelin veya bu Kanun hükümleri uyarınca tespit
                edilecek rayiç bedelin en çok üç kat fazlasını isteyebilir. Dolayısı ile müvekkil şirketin yıllık Ticari
                Abonelik bedelinin 3 katını talep ederek hukuk davası açma hakkı saklıdır.</p>
                <div class="ih-etiket-satir">SONUÇ – İSTEM :</div>
                <p>Yukarıda arz ve izah edilen dava ve takiplere maruz kalmamanız ve müvekkil şirketin mağduriyetinin
                aşağıda belirtilmiş olan talepler doğrultusunda giderilerek uzlaşma sağlamak için;</p>
                <p>İşbu ihtarnamenin tarafınıza tebliğinden itibaren:<br>
                1-) Usulsüz kullanımınıza son vermeniz;<br>
                2-) Ticari İşyeri aboneliği yaptırmanız;<br>
                3-) Haksız ve yetkisiz kullanımınızdan kaynaklı uzlaşma şartları için şirket yetkilileri ile irtibata
                geçmenizi aksi takdirde her türlü hukuksal yola başvuracağımız ve bu başvurular sonucu yargılama giderleri ve
                vekâlet ücreti ödemek durumunda kalacağınız müvekkil şirketler adına vekâleten ihtar olunur.
                ${ihtarEsc(v.ihtarTarihi)}</p>
                <div class="ih-kapanis">
                    ÖRNEK AKADEMİ EĞİTİM DANIŞMANLIK VE MESLEKİ YETERLİLİK BELGELENDİRME A.Ş.
                </div>
                ${ihtarnameImza}
            </div>`;
        }

        const ihtarnameUreticiler = {
            yayinplatformu: ihtarnameYayinPlatformu,
            ssport: ihtarnameSSport,
            tabii: ihtarnameTabii
        };

        function ihtarnameCss() {
            return `
                @page { size: A4; margin: 15mm 18mm; }
                html, body { margin: 0; padding: 0; }
                body { font-family: "Times New Roman", Times, serif; font-size: 10.5pt; color: #000; background: #fff; }
                .ihtarname { page-break-after: always; break-after: page; text-align: justify; line-height: 1.35; }
                .ihtarname:last-child { page-break-after: auto; break-after: auto; }
                .ih-logo { margin-bottom: 6mm; }
                .ih-logo img { height: 16mm; }
                .ih-baslik { text-align: center; font-weight: 700; font-size: 12pt; margin-bottom: 5mm; }
                .ih-baslik-bosluksuz { margin-top: 0; }
                .ih-taraf { width: 100%; border-collapse: collapse; margin-bottom: 4mm; }
                .ih-taraf td { vertical-align: top; padding: 0 0 2mm 0; }
                .ih-etiket { width: 32mm; font-weight: 700; text-decoration: underline; white-space: nowrap; }
                .ih-ikinokta { width: 4mm; }
                .ih-etiket-satir { font-weight: 700; text-decoration: underline; }
                .ihtarname p { margin: 0 0 3mm 0; text-indent: 12mm; }
                .ih-kapanis { text-align: center; font-weight: 700; margin-top: 8mm; line-height: 1.4; }
                .ih-imza { margin-top: 10mm; font-weight: 700; font-size: 10pt; }
                .ih-imza-alt { margin-left: 42mm; }
                @media screen {
                    body { background: #e9ecef; }
                    .ihtarname { width: 210mm; min-height: 297mm; padding: 15mm 18mm; margin: 0 auto 8mm;
                                 background: #fff; box-sizing: border-box; box-shadow: 0 0 6px rgba(0,0,0,.2); }
                }`;
        }
        // Modalı seçili kayıtların özeti ile doldurur
        function ihtarnameModalHazirla() {
            const kayitlar = seciliKayitlar();
            const gruplar = {};
            const desteklenmeyen = [];
            const eksikMac = [];
            const eksikAdres = [];

            kayitlar.forEach(function(k) {
                const sablon = ihtarnameSablonlari[k.takip_taraf_id];
                if (!sablon) {
                    desteklenmeyen.push(k);
                    return;
                }
                gruplar[sablon] = (gruplar[sablon] || 0) + 1;
                if (!macBilgisiAyristir(k.takip_aciklama)) eksikMac.push(k);
                if (!k.adres) eksikAdres.push(k);
            });


            const sablonAdlari = { yayinplatformu: 'YayinPlatformu', ssport: 'S SPORT+', tabii: 'TABİİ' };
            let ozet = '';
            Object.keys(gruplar).forEach(function(s) {
                ozet += `<span class="badge text-bg-dark me-1">${sablonAdlari[s]}: ${gruplar[s]} adet</span>`;
            });
            $('#ihtarnameOzet').html(ozet || '<span class="text-muted">Basılacak kayıt yok</span>');

            const satirlar = k => k.map(x => `${x.isyeri_adi || x.isletmeci || '-'} (#${x.takip_id})`).join(', ');
            let uyari = '';
            if (desteklenmeyen.length) {
                uyari += `<div class="alert alert-danger py-2 mb-2">
                    <b>${desteklenmeyen.length} kayıt atlanacak</b> — şablonu tanımlı olmayan taraf:
                    <br><small>${satirlar(desteklenmeyen)}</small></div>`;
            }
            if (eksikMac.length) {
                uyari += `<div class="alert alert-warning py-2 mb-2">
                    <b>${eksikMac.length} kayıtta maç bilgisi yok</b> — açıklama boş olduğu için karşılaşma alanı boş basılacak.
                    <br><small>${satirlar(eksikMac)}</small></div>`;
            }
            if (eksikAdres.length) {
                uyari += `<div class="alert alert-warning py-2 mb-2">
                    <b>${eksikAdres.length} kayıtta adres boş.</b>
                    <br><small>${satirlar(eksikAdres)}</small></div>`;
            }
            $('#ihtarnameUyarilar').html(uyari);

            return Object.values(gruplar).reduce((a, b) => a + b, 0);
        }

        $(document).on('change', '#mainTable tbody .satirSec', function() {
            const id = parseInt(this.value, 10);
            if (this.checked) seciliIdler.add(id); else seciliIdler.delete(id);
            seciliSayaciGuncelle();
        });

        $(document).on('change', '.secTumu', function() {
            const isaretli = this.checked;
            $('#mainTable tbody .satirSec').each(function() {
                this.checked = isaretli;
                const id = parseInt(this.value, 10);
                if (isaretli) seciliIdler.add(id); else seciliIdler.delete(id);
            });
            seciliSayaciGuncelle();
        });

        $('#ihtarnameBtn').on('click', function() {
            if (seciliIdler.size === 0) {
                showToast('Önce ihtarname basılacak kayıtları seçin', 'warning');
                return;
            }
            const bugun = new Date();
            const yerel = new Date(bugun.getTime() - bugun.getTimezoneOffset() * 60000);
            $('#ihtarTarihi').val(yerel.toISOString().substring(0, 10));
            ihtarnameModalHazirla();
            new bootstrap.Modal(document.getElementById('ihtarnameModal')).show();
        });

        $('#ihtarnameYazdirBtn').on('click', function() {
            const ihtarTarihi = $('#ihtarTarihi').val();
            if (!ihtarTarihi) { showToast('İhtar tarihi seçin', 'warning'); return; }

            // Açıklamasında maç bilgisi olmayan kayıt varsa onay iste
            const basilacak = seciliKayitlar().filter(k => ihtarnameSablonlari[k.takip_taraf_id]);
            const macsiz = basilacak.filter(k => !macBilgisiAyristir(k.takip_aciklama));
            if (macsiz.length) {
                confirmAction(
                    `${macsiz.length} kayıtta açıklama boş olduğu için karşılaşma bilgisi basılamayacak. Devam edilsin mi?`,
                    null,
                    function() { ihtarnameleriYazdir(ihtarTarihi); }
                );
                return;
            }
            ihtarnameleriYazdir(ihtarTarihi);
        });

        function ihtarnameleriYazdir(ihtarTarihi) {
            const basilanlar = seciliKayitlar().filter(k => ihtarnameSablonlari[k.takip_taraf_id]);
            const govde = basilanlar
                .map(k => ihtarnameUreticiler[ihtarnameSablonlari[k.takip_taraf_id]](ihtarnameVerisi(k, ihtarTarihi)))
                .join('');

            if (!govde) { showToast('Basılabilecek kayıt bulunamadı', 'warning'); return; }

            const pencere = window.open('', '_blank');
            if (!pencere) {
                Swal.fire('Uyarı', 'Pop-up engellendi. Tarayıcı ayarlarından izin verin.', 'warning');
                return;
            }
            pencere.document.open();
            pencere.document.write(
                '<!DOCTYPE html><html lang="tr"><head><meta charset="UTF-8"><title>İhtarname</title>' +
                '<style>' + ihtarnameCss() + '</style></head><body>' + govde +
                '<scr' + 'ipt>window.onload = function(){ setTimeout(function(){ window.print(); }, 600); };</scr' + 'ipt>' +
                '</body></html>'
            );
            pencere.document.close();
            bootstrap.Modal.getInstance(document.getElementById('ihtarnameModal')).hide();

            // Basılan kayıtların statüsünü "İhtar Gönderildi" yap
            if (!ihtarStatuTanimli || !canEdit) return;
            $.post('', { action: 'ihtarname_statu', idler: JSON.stringify(basilanlar.map(k => k.takip_id)) }, function(res) {
                if (res.success) {
                    seciliIdler.clear();
                    showToast(res.adet + ' kaydın statüsü "İhtar Gönderildi" olarak güncellendi', 'success');
                    loadStats();
                    table.ajax.reload(null, false);
                } else {
                    showError('Statü güncellenemedi', res.message);
                }
            });
        }

        $('#filterForm').on('submit', function(e) {
            e.preventDefault();
            currentFilters = {
                sezon_id: $('#filter_sezon_id').val(),
                taraf_id: $('#filter_taraf_id').val(),
                statu_id: $('#filter_statu_id').val(),
                musteri: $('#filter_musteri').val(),
                sehir_id: $('#filter_sehir_id').val(),
                ilce_id: $('#filter_ilce_id').val(),
                tespit_turu: $('#filter_tespit_turu').val(),
                tespit_bas: $('#filter_tespit_bas').val(),
                tespit_bit: $('#filter_tespit_bit').val(),
                konum: $('#filter_konum').val()
            };
            Object.keys(currentFilters).forEach(k => {
                if (currentFilters[k] === '') delete currentFilters[k];
            });
            table.ajax.reload();
            loadStats();
            showToast('Filtre uygulandı', 'info');
        });
        
        $('#clearFilters').on('click', function() {
            $('#filterForm')[0].reset();
            $('#filter_sezon_id').val(varsayilanSezonId).trigger('change.select2');
            $('#filter_tespit_turu').val('').trigger('change.select2');
            $('#filter_taraf_id').val('').trigger('change.select2');
            $('#filter_statu_id').val('').trigger('change.select2');
            $('#filter_musteri').val('');
            $('#filter_sehir_id').val('').trigger('change.select2');
            $('#filter_ilce_id').html('<option value="">Önce Şehir Seç</option>').prop('disabled', true);
            $('#filter_tespit_bas').val('');
            $('#filter_tespit_bit').val('');
            $('#filter_konum').val('').trigger('change.select2');
            currentFilters = varsayilanSezonId ? { sezon_id: varsayilanSezonId } : {};
            table.ajax.reload();
            loadStats();
            showToast('Filtreler temizlendi', 'info');
        });
        
        $(document).ready(function() {
            // Filtre Select2 başlat
            $('#filter_sezon_id, #filter_tespit_turu, #filter_taraf_id, #filter_statu_id, #filter_sehir_id, #filter_konum').select2({
                theme: 'bootstrap-5',
                placeholder: 'Seçiniz...',
                allowClear: true,
                language: {
                    noResults: function() { return "Sonuç bulunamadı"; },
                    searching: function() { return "Aranıyor..."; }
                }
            });
            
            // Filtre şehir değişince ilçeleri yükle
            $('#filter_sehir_id').on('change', function() {
                const sehirId = $(this).val();
                const ilceSelect = $('#filter_ilce_id');
                
                if (!sehirId) {
                    ilceSelect.html('<option value="">Önce Şehir Seç</option>').prop('disabled', true);
                    return;
                }
                
                ilceSelect.html('<option value="">Yükleniyor...</option>').prop('disabled', true);
                
                $.post('', { action: 'get_ilceler', sehir_id: sehirId }, function(res) {
                    if (res.success) {
                        let options = '<option value="">Tümü</option>';
                        res.data.forEach(i => {
                            options += `<option value="${i.ilceId}">${i.IlceAdi}</option>`;
                        });
                        ilceSelect.html(options).prop('disabled', false);
                        ilceSelect.select2({
                            theme: 'bootstrap-5',
                            placeholder: 'Seçiniz...',
                            allowClear: true,
                            language: {
                                noResults: function() { return "Sonuç bulunamadı"; },
                                searching: function() { return "Aranıyor..."; }
                            }
                        });
                    }
                });
            });
            
            loadStats();
            initDataTable();
            
            // Sidebar toggle ve pencere boyutu değişiminde DataTable'ı hizala
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
