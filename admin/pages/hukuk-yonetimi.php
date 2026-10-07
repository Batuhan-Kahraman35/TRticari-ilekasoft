<?php
/**
 * Hukuk Takip Yönetimi
 * HukukTakip tablosunu yönetir - İcra dosyaları takibi
 */

require_once __DIR__ . '/../auth.php';
require_once __DIR__ . '/../includes/PageAuth.php';
require_once __DIR__ . '/../cron/tasks.php';
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

$pageTitle = $pageInfo['sayfalar_sayfa_adi'] ?? 'Hukuk Takip Yönetimi';
$pageDescription = $pageInfo['sayfalar_aciklama'] ?? '';
$menuAdi = $pageInfo['menu_adi'] ?? null;

$siteAyarlari = $db->fetchOne("SELECT TOP 1 site_ayarlari_site_title FROM dbo.tanim_site_ayarlari ORDER BY site_ayarlari_id DESC");
$siteTitle = $siteAyarlari['site_ayarlari_site_title'] ?? 'Portal';

// Dropdown verileri
$taraflar = $db->fetchAll("SELECT taraf_id, taraf_ad FROM HukukTaraflar WHERE Durum = 1 ORDER BY taraf_ad");
$icraDaireleri = $db->fetchAll("SELECT icra_dairesi_id, icra_dairesi_ad FROM HukukIcraDairesi WHERE Durum = 1 ORDER BY icra_dairesi_ad");
$statuler = $db->fetchAll("SELECT statu_id, statu_ad FROM HukukStatu WHERE Durum = 1 ORDER BY statu_sira");
$sehirler = $db->fetchAll("SELECT SehirId, SehirAdi FROM Adres_Sehirler WHERE UlkeId = 1 ORDER BY SehirAdi");

// AJAX İşlemleri
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json');
    $action = $_POST['action'] ?? '';
    
    try {
        switch ($action) {
            case 'stats':
                $tarafId = $_POST['taraf_id'] ?? '';
                $icraDairesiId = $_POST['icra_dairesi_id'] ?? '';
                $statuId = $_POST['statu_id'] ?? '';
                $dosyaNo = $_POST['dosya_no'] ?? '';
                $musteri = $_POST['musteri'] ?? '';
                $sehirId = $_POST['sehir_id'] ?? '';
                $ilceId = $_POST['ilce_id'] ?? '';
                $durum = $_POST['durum'] ?? '';
                $dosyaAcilisBas = $_POST['dosya_acilis_bas'] ?? '';
                $dosyaAcilisBit = $_POST['dosya_acilis_bit'] ?? '';
                $icrayayGidildi = $_POST['icraya_gidildi'] ?? '';

                $where = "WHERE c.cari_tipi_id = 3";
                $params = [];

                if ($tarafId !== '') { $where .= " AND t.takip_taraf_id = ?"; $params[] = $tarafId; }
                if ($icraDairesiId !== '') { $where .= " AND t.takip_icra_dairesi_id = ?"; $params[] = $icraDairesiId; }
                if ($statuId !== '') { $where .= " AND t.takip_statu_id = ?"; $params[] = $statuId; }
                if ($dosyaNo !== '') { $where .= " AND t.takip_dosya_no LIKE ?"; $params[] = "%$dosyaNo%"; }
                if ($musteri !== '') { $where .= " AND c.cari_adi LIKE ?"; $params[] = "%$musteri%"; }
                if ($sehirId !== '') { $where .= " AND c.cari_sehirler = ?"; $params[] = $sehirId; }
                if ($ilceId !== '') { $where .= " AND c.cari_ilceler = ?"; $params[] = $ilceId; }
                if ($durum !== '') { $where .= " AND t.Durum = ?"; $params[] = $durum; }
                if ($dosyaAcilisBas !== '') { $where .= " AND CONVERT(date, t.takip_dosya_acilis_tarihi) >= ?"; $params[] = $dosyaAcilisBas; }
                if ($dosyaAcilisBit !== '') { $where .= " AND CONVERT(date, t.takip_dosya_acilis_tarihi) <= ?"; $params[] = $dosyaAcilisBit; }
                if ($icrayayGidildi !== '') { $where .= " AND t.takip_icraya_gidildi = ?"; $params[] = $icrayayGidildi; }
                
                $baseQuery = "FROM HukukTakip t
                    LEFT JOIN Cari c ON t.takip_cari_id = c.cari_id
                    LEFT JOIN HukukTaraflar tr ON t.takip_taraf_id = tr.taraf_id
                    LEFT JOIN HukukIcraDairesi i ON t.takip_icra_dairesi_id = i.icra_dairesi_id
                    LEFT JOIN HukukStatu st ON t.takip_statu_id = st.statu_id
                    LEFT JOIN Adres_Sehirler s ON c.cari_sehirler = s.SehirId
                    LEFT JOIN Adres_Ilceler il ON c.cari_ilceler = il.ilceId
                    $where";
                
                $toplam = $db->fetchOne("SELECT COUNT(*) as sayi $baseQuery", $params);
                $aktif = $db->fetchOne("SELECT COUNT(*) as sayi $baseQuery AND t.Durum = 1", $params);
                $toplamAnaTutar = $db->fetchOne("SELECT ISNULL(SUM(t.takip_ana_tutar), 0) as toplam $baseQuery AND t.Durum = 1", $params);
                $toplamIsleyenFaiz = $db->fetchOne("SELECT ISNULL(SUM(t.takip_Isleyen_Faiz), 0) as toplam $baseQuery AND t.Durum = 1", $params);
                $toplamIslemisFaiz = $db->fetchOne("SELECT ISNULL(SUM(t.takip_Islemis_Faiz), 0) as toplam $baseQuery AND t.Durum = 1", $params);
                $toplamVekaletUcreti = $db->fetchOne("SELECT ISNULL(SUM(t.takip_Vekalet_Ucreti), 0) as toplam $baseQuery AND t.Durum = 1", $params);
                $toplamMasraf = $db->fetchOne("SELECT ISNULL(SUM(t.takip_Masraf), 0) as toplam $baseQuery AND t.Durum = 1", $params);
                $toplamTahsilHarci = $db->fetchOne("SELECT ISNULL(SUM(t.takip_Tahsil_Harci), 0) as toplam $baseQuery AND t.Durum = 1", $params);
                $toplamAlacak = $db->fetchOne("SELECT ISNULL(SUM(ISNULL(t.takip_ana_tutar,0) + ISNULL(t.takip_Isleyen_Faiz,0) + ISNULL(t.takip_Islemis_Faiz,0) + ISNULL(t.takip_Vekalet_Ucreti,0) + ISNULL(t.takip_Masraf,0) + ISNULL(t.takip_Tahsil_Harci,0)), 0) as toplam $baseQuery AND t.Durum = 1", $params);

                // Haciz masraf istatistikleri (sadece hacze gidilen dosyalar)
                $hacizBaseQuery = $baseQuery . " AND t.takip_icraya_gidildi = 1";
                $hacizParams = $params;
                $toplamHacizMasraf = $db->fetchOne("SELECT ISNULL(SUM(ISNULL(t.takip_haciz_harci,0) + ISNULL(t.takip_arac_ucreti,0) + ISNULL(t.takip_tevkil_ucreti,0)), 0) as toplam $hacizBaseQuery AND t.Durum = 1", $hacizParams);
                $toplamHacizTahsilati = $db->fetchOne("SELECT ISNULL(SUM(t.takip_haciz_tahsilati), 0) as toplam $hacizBaseQuery AND t.Durum = 1", $hacizParams);
                $hacizDosyaSayisi = $db->fetchOne("SELECT COUNT(*) as sayi $hacizBaseQuery AND t.Durum = 1", $hacizParams);

                echo json_encode(['success' => true, 'data' => [
                    'toplam' => intval($toplam['sayi'] ?? 0),
                    'aktif' => intval($aktif['sayi'] ?? 0),
                    'toplam_ana_tutar' => floatval($toplamAnaTutar['toplam'] ?? 0),
                    'toplam_isleyen_faiz' => floatval($toplamIsleyenFaiz['toplam'] ?? 0),
                    'toplam_islemis_faiz' => floatval($toplamIslemisFaiz['toplam'] ?? 0),
                    'toplam_vekalet_ucreti' => floatval($toplamVekaletUcreti['toplam'] ?? 0),
                    'toplam_masraf' => floatval($toplamMasraf['toplam'] ?? 0),
                    'toplam_tahsil_harci' => floatval($toplamTahsilHarci['toplam'] ?? 0),
                    'toplam_alacak' => floatval($toplamAlacak['toplam'] ?? 0),
                    'haciz_dosya_sayisi' => intval($hacizDosyaSayisi['sayi'] ?? 0),
                    'toplam_haciz_masraf' => floatval($toplamHacizMasraf['toplam'] ?? 0),
                    'toplam_haciz_tahsilati' => floatval($toplamHacizTahsilati['toplam'] ?? 0)
                ]]);
                break;
                
            case 'list':
                $tarafId = $_POST['taraf_id'] ?? '';
                $icraDairesiId = $_POST['icra_dairesi_id'] ?? '';
                $statuId = $_POST['statu_id'] ?? '';
                $dosyaNo = $_POST['dosya_no'] ?? '';
                $musteri = $_POST['musteri'] ?? '';
                $sehirId = $_POST['sehir_id'] ?? '';
                $ilceId = $_POST['ilce_id'] ?? '';
                $durum = $_POST['durum'] ?? '';
                $dosyaAcilisBas = $_POST['dosya_acilis_bas'] ?? '';
                $dosyaAcilisBit = $_POST['dosya_acilis_bit'] ?? '';
                $icrayayGidildi = $_POST['icraya_gidildi'] ?? '';

                $where = "WHERE c.cari_tipi_id = 3";
                $params = [];

                if ($tarafId !== '') { $where .= " AND t.takip_taraf_id = ?"; $params[] = $tarafId; }
                if ($icraDairesiId !== '') { $where .= " AND t.takip_icra_dairesi_id = ?"; $params[] = $icraDairesiId; }
                if ($statuId !== '') { $where .= " AND t.takip_statu_id = ?"; $params[] = $statuId; }
                if ($dosyaNo !== '') { $where .= " AND t.takip_dosya_no LIKE ?"; $params[] = "%$dosyaNo%"; }
                if ($musteri !== '') { $where .= " AND c.cari_adi LIKE ?"; $params[] = "%$musteri%"; }
                if ($sehirId !== '') { $where .= " AND c.cari_sehirler = ?"; $params[] = $sehirId; }
                if ($ilceId !== '') { $where .= " AND c.cari_ilceler = ?"; $params[] = $ilceId; }
                if ($durum !== '') { $where .= " AND t.Durum = ?"; $params[] = $durum; }
                if ($dosyaAcilisBas !== '') { $where .= " AND CONVERT(date, t.takip_dosya_acilis_tarihi) >= ?"; $params[] = $dosyaAcilisBas; }
                if ($dosyaAcilisBit !== '') { $where .= " AND CONVERT(date, t.takip_dosya_acilis_tarihi) <= ?"; $params[] = $dosyaAcilisBit; }
                if ($icrayayGidildi !== '') { $where .= " AND t.takip_icraya_gidildi = ?"; $params[] = $icrayayGidildi; }
                
                $data = $db->fetchAll("
                    SELECT
                        t.takip_id,
                        t.takip_dosya_no,
                        t.takip_talimat_dosya_no,
                        t.takip_aciklama,
                        t.takip_ana_tutar,
                        t.takip_Isleyen_Faiz,
                        t.takip_Islemis_Faiz,
                        t.takip_Vekalet_Ucreti,
                        t.takip_Masraf,
                        (ISNULL(t.takip_ana_tutar,0) + ISNULL(t.takip_Isleyen_Faiz,0) + ISNULL(t.takip_Islemis_Faiz,0) + ISNULL(t.takip_Vekalet_Ucreti,0) + ISNULL(t.takip_Masraf,0) + ISNULL(t.takip_Tahsil_Harci,0)) as toplam_alacak,
                        t.takip_statu_id,
                        t.Durum,
                        t.takip_pasif_nedeni,
                        CONVERT(VARCHAR(19), t.takip_dosya_acilis_tarihi, 120) as dosya_acilis_tarihi,
                        CONVERT(VARCHAR(19), t.takip_haciz_randevu_tarihi, 120) as haciz_randevu_tarihi,
                        CONVERT(VARCHAR(19), t.OlusturmaTarihi, 120) as OlusturmaTarihi,
                        CONVERT(VARCHAR(19), t.GuncellemeTarihi, 120) as GuncellemeTarihi,
                        c.cari_adi as musteri_adi,
                        c.cari_telefon,
                        c.cari_Yetkili_telefon,
                        c.cari_sehirler,
                        c.cari_ilceler,
                        tr.taraf_ad,
                        i.icra_dairesi_ad,
                        st.statu_ad,
                        s.SehirAdi as sehir_adi,
                        il.IlceAdi as ilce_adi
                    FROM HukukTakip t
                    LEFT JOIN Cari c ON t.takip_cari_id = c.cari_id
                    LEFT JOIN HukukTaraflar tr ON t.takip_taraf_id = tr.taraf_id
                    LEFT JOIN HukukIcraDairesi i ON t.takip_icra_dairesi_id = i.icra_dairesi_id
                    LEFT JOIN HukukStatu st ON t.takip_statu_id = st.statu_id
                    LEFT JOIN Adres_Sehirler s ON c.cari_sehirler = s.SehirId
                    LEFT JOIN Adres_Ilceler il ON c.cari_ilceler = il.ilceId
                    $where
                    ORDER BY t.OlusturmaTarihi DESC
                ", $params);
                
                echo json_encode(['success' => true, 'data' => $data]);
                break;
                
            case 'toggle_durum':
                if (!$pagePermissions['can_edit']) {
                    echo json_encode(['success' => false, 'message' => 'Düzenleme yetkiniz bulunmamaktadır']);
                    break;
                }
                $id = intval($_POST['id'] ?? 0);
                $yeniDurum = intval($_POST['durum'] ?? 0);
                $neden = trim($_POST['neden'] ?? '');
                if ($yeniDurum == 0) {
                    $db->execute(
                        "UPDATE HukukTakip SET Durum = 0, takip_pasif_nedeni = ?, GuncelleyenKullanici = ?, GuncellemeTarihi = GETDATE() WHERE takip_id = ?",
                        [$neden, $user['kullanici_id'], $id]
                    );
                    echo json_encode(['success' => true, 'message' => 'Dosya pasif yapıldı']);
                } else {
                    $db->execute(
                        "UPDATE HukukTakip SET Durum = 1, takip_pasif_nedeni = NULL, GuncelleyenKullanici = ?, GuncellemeTarihi = GETDATE() WHERE takip_id = ?",
                        [$user['kullanici_id'], $id]
                    );
                    echo json_encode(['success' => true, 'message' => 'Dosya aktif yapıldı']);
                }
                break;

            case 'delete':
                if (!$pagePermissions['can_delete']) {
                    echo json_encode(['success' => false, 'message' => 'Silme yetkiniz bulunmamaktadır']);
                    break;
                }
                $id = intval($_POST['id'] ?? 0);
                $db->execute("DELETE FROM HukukTakip WHERE takip_id = ?", [$id]);
                echo json_encode(['success' => true, 'message' => 'Takip kaydı silindi']);
                break;
                
            // Faiz hesaplama: merkezi cron gorevi oturum yetkisiyle calistirilir (key gerekmez)
            case 'faiz_hesapla':
                if (!$pagePermissions['can_delete']) {
                    echo json_encode(['success' => false, 'message' => 'Bu işlem için yetkiniz yok!']);
                    break;
                }

                $gorev = cronGorevGetir($db, 'hukuk_faiz_hesapla');
                if (!$gorev) {
                    echo json_encode(['success' => false, 'message' => 'Faiz hesaplama görevi tanımlı değil.']);
                    break;
                }

                $gorevId = (int)$gorev['CronGorevler_Id'];
                if (cronCalisiyorMu($db, $gorevId, (int)$gorev['CronGorevler_MaxSureSn'])) {
                    echo json_encode(['success' => false, 'message' => 'Faiz hesaplama şu anda çalışıyor, lütfen bekleyin.']);
                    break;
                }

                ignore_user_abort(true);
                set_time_limit((int)$gorev['CronGorevler_MaxSureSn']);
                cronShutdownHandlerKur();

                $bas    = microtime(true);
                $params = ['force' => 1];
                $logId  = cronLogOlustur($db, $gorevId, null, $params, 0, (int)$user['kullanici_id']);
                cronAktifLogAc($logId, $bas);

                try {
                    $sonuc = gorevCalistir('hukuk_faiz_hesapla', $params, $db);
                    cronLogBitir($db, $logId, (int)$sonuc['durum'], $sonuc['sonuc'], $bas, $sonuc['cikti']);
                    cronAktifLogKapat();
                    echo json_encode(['success' => (int)$sonuc['durum'] === 1, 'message' => $sonuc['sonuc']], JSON_UNESCAPED_UNICODE);
                } catch (Throwable $e) {
                    cronLogBitir($db, $logId, 2, 'HATA: ' . $e->getMessage(), $bas);
                    cronAktifLogKapat();
                    echo json_encode(['success' => false, 'message' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
                }
                break;

            case 'get_ilceler':
                $sehirId = intval($_POST['sehir_id'] ?? 0);
                $ilceler = $db->fetchAll("SELECT ilceId, IlceAdi FROM Adres_Ilceler WHERE SehirId = ? ORDER BY IlceAdi", [$sehirId]);
                echo json_encode(['success' => true, 'data' => $ilceler]);
                break;
                
            case 'export_excel':
                $tarafId = $_POST['taraf_id'] ?? '';
                $icraDairesiId = $_POST['icra_dairesi_id'] ?? '';
                $statuId = $_POST['statu_id'] ?? '';
                $dosyaNo = $_POST['dosya_no'] ?? '';
                $musteri = $_POST['musteri'] ?? '';
                $sehirId = $_POST['sehir_id'] ?? '';
                $ilceId = $_POST['ilce_id'] ?? '';
                $durum = $_POST['durum'] ?? '';
                $dosyaAcilisBas = $_POST['dosya_acilis_bas'] ?? '';
                $dosyaAcilisBit = $_POST['dosya_acilis_bit'] ?? '';
                $icrayayGidildi = $_POST['icraya_gidildi'] ?? '';

                $where = "WHERE c.cari_tipi_id = 3";
                $params = [];

                if ($tarafId !== '') { $where .= " AND t.takip_taraf_id = ?"; $params[] = $tarafId; }
                if ($icraDairesiId !== '') { $where .= " AND t.takip_icra_dairesi_id = ?"; $params[] = $icraDairesiId; }
                if ($statuId !== '') { $where .= " AND t.takip_statu_id = ?"; $params[] = $statuId; }
                if ($dosyaNo !== '') { $where .= " AND t.takip_dosya_no LIKE ?"; $params[] = "%$dosyaNo%"; }
                if ($musteri !== '') { $where .= " AND c.cari_adi LIKE ?"; $params[] = "%$musteri%"; }
                if ($sehirId !== '') { $where .= " AND c.cari_sehirler = ?"; $params[] = $sehirId; }
                if ($ilceId !== '') { $where .= " AND c.cari_ilceler = ?"; $params[] = $ilceId; }
                if ($durum !== '') { $where .= " AND t.Durum = ?"; $params[] = $durum; }
                if ($dosyaAcilisBas !== '') { $where .= " AND CONVERT(date, t.takip_dosya_acilis_tarihi) >= ?"; $params[] = $dosyaAcilisBas; }
                if ($dosyaAcilisBit !== '') { $where .= " AND CONVERT(date, t.takip_dosya_acilis_tarihi) <= ?"; $params[] = $dosyaAcilisBit; }
                if ($icrayayGidildi !== '') { $where .= " AND t.takip_icraya_gidildi = ?"; $params[] = $icrayayGidildi; }
                
                $data = $db->fetchAll("
                    SELECT 
                        tr.taraf_ad as 'Taraf',
                        i.icra_dairesi_ad as 'İcra Dairesi',
                        t.takip_dosya_no as 'Dosya No',
                        t.takip_talimat_dosya_no as 'Talimat Dosya No',
                        c.cari_adi as 'Müşteri',
                        c.cari_telefon as 'Telefon',
                        c.cari_Yetkili_telefon as 'Yetkili Telefon',
                        s.SehirAdi as 'Şehir',
                        il.IlceAdi as 'İlçe',
                        st.statu_ad as 'Statü',
                        t.takip_ana_tutar as 'Ana Tutar',
                        t.takip_Isleyen_Faiz as 'İşleyen Faiz',
                        t.takip_Islemis_Faiz as 'İşlemiş Faiz',
                        t.takip_Vekalet_Ucreti as 'Vekalet Ücreti',
                        t.takip_Masraf as 'Masraf',
                        (ISNULL(t.takip_ana_tutar,0) + ISNULL(t.takip_Isleyen_Faiz,0) + ISNULL(t.takip_Islemis_Faiz,0) + ISNULL(t.takip_Vekalet_Ucreti,0) + ISNULL(t.takip_Masraf,0) + ISNULL(t.takip_Tahsil_Harci,0)) as 'Toplam Alacak',
                        CASE WHEN t.Durum = 1 THEN 'Aktif' ELSE 'Pasif' END as 'Durum',
                        t.takip_aciklama as 'Açıklama',
                        CONVERT(VARCHAR(10), t.takip_dosya_acilis_tarihi, 104) as 'Dosya Açılış Tarihi',
                        CONVERT(VARCHAR(10), t.takip_haciz_randevu_tarihi, 104) as 'Haciz Randevu Tarihi',
                        CONVERT(VARCHAR(19), t.OlusturmaTarihi, 120) as 'Oluşturma Tarihi'
                    FROM HukukTakip t
                    LEFT JOIN Cari c ON t.takip_cari_id = c.cari_id
                    LEFT JOIN HukukTaraflar tr ON t.takip_taraf_id = tr.taraf_id
                    LEFT JOIN HukukIcraDairesi i ON t.takip_icra_dairesi_id = i.icra_dairesi_id
                    LEFT JOIN HukukStatu st ON t.takip_statu_id = st.statu_id
                    LEFT JOIN Adres_Sehirler s ON c.cari_sehirler = s.SehirId
                    LEFT JOIN Adres_Ilceler il ON c.cari_ilceler = il.ilceId
                    $where
                    ORDER BY t.OlusturmaTarihi DESC
                ", $params);
                
                // CSV olarak Excel uyumlu export
                header('Content-Type: text/csv; charset=utf-8');
                header('Content-Disposition: attachment; filename="hukuk-takip-' . date('Y-m-d') . '.csv"');
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
        .info-box-number { white-space: nowrap; font-size: 1.1rem; }
        .info-box-text { white-space: nowrap; font-size: 0.8rem; }
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
                        <div class="col-6 col-sm-4 col-xl">
                            <div class="info-box text-bg-primary">
                                <span class="info-box-icon"><i class="bi bi-folder2-open"></i></span>
                                <div class="info-box-content">
                                    <span class="info-box-text">Toplam Dosya</span>
                                    <span class="info-box-number" id="stat-toplam">0</span>
                                </div>
                            </div>
                        </div>
                        <div class="col-6 col-sm-4 col-xl">
                            <div class="info-box text-bg-info">
                                <span class="info-box-icon"><i class="bi bi-cash-coin"></i></span>
                                <div class="info-box-content">
                                    <span class="info-box-text">Toplam Ana Tutar</span>
                                    <span class="info-box-number" id="stat-ana-tutar">0 ₺</span>
                                </div>
                            </div>
                        </div>
                        <div class="col-6 col-sm-4 col-xl">
                            <div class="info-box text-bg-warning">
                                <span class="info-box-icon"><i class="bi bi-calculator"></i></span>
                                <div class="info-box-content">
                                    <span class="info-box-text">İşleyen Faiz</span>
                                    <span class="info-box-number" id="stat-isleyen-faiz">0 ₺</span>
                                </div>
                            </div>
                        </div>
                        <div class="col-6 col-sm-4 col-xl">
                            <div class="info-box text-bg-secondary">
                                <span class="info-box-icon"><i class="bi bi-percent"></i></span>
                                <div class="info-box-content">
                                    <span class="info-box-text">İşlemiş Faiz</span>
                                    <span class="info-box-number" id="stat-islemis-faiz">0 ₺</span>
                                </div>
                            </div>
                        </div>
                        <div class="col-6 col-sm-4 col-xl">
                            <div class="info-box text-bg-dark">
                                <span class="info-box-icon"><i class="bi bi-briefcase"></i></span>
                                <div class="info-box-content">
                                    <span class="info-box-text">Vekalet Ücreti</span>
                                    <span class="info-box-number" id="stat-vekalet-ucreti">0 ₺</span>
                                </div>
                            </div>
                        </div>
                        <div class="col-6 col-sm-4 col-xl">
                            <div class="info-box text-bg-light">
                                <span class="info-box-icon"><i class="bi bi-receipt"></i></span>
                                <div class="info-box-content">
                                    <span class="info-box-text">Masraf</span>
                                    <span class="info-box-number" id="stat-masraf">0 ₺</span>
                                </div>
                            </div>
                        </div>
                        <div class="col-6 col-sm-4 col-xl">
                            <div class="info-box text-bg-success">
                                <span class="info-box-icon"><i class="bi bi-bank"></i></span>
                                <div class="info-box-content">
                                    <span class="info-box-text">Tahsil Harcı</span>
                                    <span class="info-box-number" id="stat-tahsil-harci">0 ₺</span>
                                </div>
                            </div>
                        </div>
                        <div class="col-6 col-sm-4 col-xl">
                            <div class="info-box text-bg-danger">
                                <span class="info-box-icon"><i class="bi bi-cash-stack"></i></span>
                                <div class="info-box-content">
                                    <span class="info-box-text">Toplam Alacak</span>
                                    <span class="info-box-number" id="stat-toplam-alacak">0 ₺</span>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Haciz İstatistik Kartları -->
                    <div class="row g-2 mb-3">
                        <div class="col-12">
                            <small class="text-muted fw-semibold"><i class="bi bi-hammer"></i> Haciz Operasyonu İstatistikleri</small>
                        </div>
                        <div class="col-6 col-md-4">
                            <div class="info-box text-bg-secondary mb-0">
                                <span class="info-box-icon"><i class="bi bi-hammer"></i></span>
                                <div class="info-box-content">
                                    <span class="info-box-text">Haciz Dosya Sayısı</span>
                                    <span class="info-box-number" id="stat-haciz-dosya">0</span>
                                </div>
                            </div>
                        </div>
                        <div class="col-6 col-md-4">
                            <div class="info-box text-bg-warning mb-0">
                                <span class="info-box-icon"><i class="bi bi-cash-coin"></i></span>
                                <div class="info-box-content">
                                    <span class="info-box-text">Toplam Haciz Masrafı</span>
                                    <span class="info-box-number" id="stat-haciz-masraf">0 ₺</span>
                                </div>
                            </div>
                        </div>
                        <div class="col-6 col-md-4">
                            <div class="info-box text-bg-success mb-0">
                                <span class="info-box-icon"><i class="bi bi-piggy-bank"></i></span>
                                <div class="info-box-content">
                                    <span class="info-box-text">Haciz Tahsilatı</span>
                                    <span class="info-box-number" id="stat-haciz-tahsilati">0 ₺</span>
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
                                        <label class="form-label">Taraf</label>
                                        <select class="form-select" id="filter_taraf_id" name="taraf_id">
                                            <option value="">Tümü</option>
                                            <?php foreach ($taraflar as $t): ?>
                                                <option value="<?= $t['taraf_id'] ?>"><?= htmlspecialchars($t['taraf_ad']) ?></option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                    <div class="col-md-2">
                                        <label class="form-label">İcra Dairesi</label>
                                        <select class="form-select" id="filter_icra_dairesi_id" name="icra_dairesi_id">
                                            <option value="">Tümü</option>
                                            <?php foreach ($icraDaireleri as $i): ?>
                                                <option value="<?= $i['icra_dairesi_id'] ?>"><?= htmlspecialchars($i['icra_dairesi_ad']) ?></option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                    <div class="col-md-2">
                                        <label class="form-label">Dosya No</label>
                                        <input type="text" class="form-control" id="filter_dosya_no" name="dosya_no" placeholder="Dosya no ara...">
                                    </div>
                                    <div class="col-md-2">
                                        <label class="form-label">Müşteri</label>
                                        <input type="text" class="form-control" id="filter_musteri" name="musteri" placeholder="Müşteri ara...">
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
                                        <label class="form-label">Durum</label>
                                        <select class="form-select" id="filter_durum" name="durum">
                                            <option value="">Tümü</option>
                                            <option value="1">Aktif</option>
                                            <option value="0">Pasif</option>
                                        </select>
                                    </div>
                                    <div class="col-md-2">
                                        <label class="form-label">Hacze Gidildi</label>
                                        <select class="form-select" id="filter_icraya_gidildi" name="icraya_gidildi">
                                            <option value="">Tümü</option>
                                            <option value="1">Gidildi</option>
                                            <option value="0">Gidilmedi</option>
                                        </select>
                                    </div>
                                    <div class="col-md-2">
                                        <label class="form-label">Açılış Başlangıç</label>
                                        <input type="date" class="form-control" id="filter_dosya_acilis_bas" name="dosya_acilis_bas">
                                    </div>
                                    <div class="col-md-2">
                                        <label class="form-label">Açılış Bitiş</label>
                                        <input type="date" class="form-control" id="filter_dosya_acilis_bit" name="dosya_acilis_bit">
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
                            <h3 class="card-title"><i class="bi bi-folder2-open"></i> Hukuk Takip Dosyaları</h3>
                            <div class="card-tools">
                                <?php if ($pagePermissions['can_delete']): ?>
                                <button type="button" class="btn btn-sm btn-outline-warning" onclick="runFaizHesapla()">
                                    <i class="bi bi-calculator"></i> Faiz Hesapla
                                </button>
                                <?php endif; ?>
                                <button type="button" class="btn btn-sm btn-success" onclick="exportExcel()">
                                    <i class="bi bi-file-earmark-excel"></i> Excel
                                </button>
                                <button type="button" class="btn btn-sm btn-secondary" data-bs-toggle="collapse" data-bs-target="#filterCard">
                                    <i class="bi bi-funnel"></i> Filtre
                                </button>
                                <?php if ($pagePermissions['can_add']): ?>
                                <a href="/admin/form?cari_tipi_id=3" class="btn btn-sm btn-primary">
                                    <i class="bi bi-plus-lg"></i> Yeni Dosya
                                </a>
                                <?php endif; ?>
                            </div>
                        </div>
                        <div class="card-body p-0">
                                <table class="table table-bordered table-striped table-hover" id="mainTable">
                                    <thead>
                                        <tr>
                                            <th width="50">#</th>
                                            <th>Taraf</th>
                                            <th>İcra Dairesi</th>
                                            <th>Dosya No</th>
                                            <th>Talimat Dosya No</th>
                                            <th>Dosya Açılış</th>
                                            <th>Haciz Randevu</th>
                                            <th>Müşteri</th>
                                            <th>Telefonlar</th>
                                            <th>Şehir</th>
                                            <th>İlçe</th>
                                            <th>Statü</th>
                                            <th class="text-end">Ana Tutar</th>
                                            <th class="text-end">İşleyen Faiz</th>
                                            <th class="text-end">Toplam Alacak</th>
                                            <th width="80">Durum</th>
                                            <th>Güncelleme Tarihi</th>
                                            <th width="100">İşlemler</th>
                                        </tr>
                                    </thead>
                                    <tbody id="tableBody">
                                        <tr><td colspan="18" class="text-center">Yükleniyor...</td></tr>
                                    </tbody>
                                </table>
                        </div>
                    </div>
                    
                </div>
            </div>
        </main>
        
        <?php include __DIR__ . '/../includes/footer.php'; ?>
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
        const canAdd = <?= json_encode($pagePermissions['can_add']) ?>;
        const canEdit = <?= json_encode($pagePermissions['can_edit']) ?>;
        const canDelete = <?= json_encode($pagePermissions['can_delete']) ?>;
        let currentFilters = {};
        let table = null;
        
        function runFaizHesapla() {
            confirmAction('Faiz hesaplama işlemini çalıştırmak istediğinize emin misiniz?', 'Tüm aktif dosyalar için işleyen faiz yeniden hesaplanacak.', function() {
                showToast('Faiz hesaplama başlatıldı...', 'info');
                $.post('', { action: 'faiz_hesapla' }, function(res) {
                    if (!res.success) {
                        showError('Hata!', res.message || 'Faiz hesaplama sırasında bir hata oluştu.');
                        return;
                    }
                    showSuccess('Tamamlandı!', res.message);
                    loadStats();
                    table.ajax.reload(null, false);
                }, 'json').fail(function() {
                    showError('Hata!', 'Faiz hesaplama sırasında bir hata oluştu.');
                });
            });
        }

        function loadStats() {
            $.post('', { action: 'stats', ...currentFilters }, function(res) {
                if (res.success) {
                    $('#stat-toplam').text(res.data.toplam);
                    $('#stat-aktif').text(res.data.aktif);
                    $('#stat-ana-tutar').text(formatCurrency(res.data.toplam_ana_tutar));
                    $('#stat-isleyen-faiz').text(formatCurrency(res.data.toplam_isleyen_faiz));
                    $('#stat-islemis-faiz').text(formatCurrency(res.data.toplam_islemis_faiz));
                    $('#stat-vekalet-ucreti').text(formatCurrency(res.data.toplam_vekalet_ucreti));
                    $('#stat-masraf').text(formatCurrency(res.data.toplam_masraf));
                    $('#stat-tahsil-harci').text(formatCurrency(res.data.toplam_tahsil_harci));
                    $('#stat-toplam-alacak').text(formatCurrency(res.data.toplam_alacak));
                    $('#stat-haciz-dosya').text(res.data.haciz_dosya_sayisi);
                    $('#stat-haciz-masraf').text(formatCurrency(res.data.toplam_haciz_masraf));
                    $('#stat-haciz-tahsilati').text(formatCurrency(res.data.toplam_haciz_tahsilati));
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
                    { data: null, render: (data, type, row, meta) => meta.row + 1 },
                    { data: 'taraf_ad', defaultContent: '-' },
                    { data: 'icra_dairesi_ad', defaultContent: '-' },
                    { data: 'takip_dosya_no', render: data => `<strong>${data}</strong>` },
                    { data: 'takip_talimat_dosya_no', defaultContent: '-' },
                    { data: 'dosya_acilis_tarihi', defaultContent: '-', render: data => data ? formatDate(data) : '-' },
                    { data: 'haciz_randevu_tarihi', defaultContent: '-', render: data => data ? formatDate(data) : '-' },
                    { data: 'musteri_adi', defaultContent: '-' },
                    {
                        data: null,
                        orderable: false,
                        render: (data, type, row) => {
                            const tels = [];
                            if (row.cari_telefon) tels.push(`<a href="tel:${row.cari_telefon}">${row.cari_telefon}</a>`);
                            if (row.cari_Yetkili_telefon) tels.push(`<a href="tel:${row.cari_Yetkili_telefon}">${row.cari_Yetkili_telefon}</a>`);
                            return tels.length ? tels.join('<br>') : '-';
                        }
                    },
                    { data: 'sehir_adi', defaultContent: '-' },
                    { data: 'ilce_adi', defaultContent: '-' },
                    { 
                        data: 'statu_ad', 
                        render: (data, type, row) => data ? `<span class="badge bg-info" style="cursor:pointer" onclick="filterByStatu(${row.takip_statu_id})" title="Bu statüye göre filtrele">${data}</span>` : '-' 
                    },
                    { 
                        data: 'takip_ana_tutar', 
                        className: 'text-end',
                        render: data => formatCurrency(data) 
                    },
                    { 
                        data: 'takip_Isleyen_Faiz', 
                        className: 'text-end',
                        render: data => formatCurrency(data) 
                    },
                    { 
                        data: 'toplam_alacak', 
                        className: 'text-end fw-bold',
                        render: data => formatCurrency(data) 
                    },
                    {
                        data: null,
                        render: (data, type, row) => {
                            if (row.Durum == 1) {
                                return '<span class="badge bg-success">Aktif</span>';
                            }
                            const neden = row.takip_pasif_nedeni ? ` title="${row.takip_pasif_nedeni.replace(/"/g, '&quot;')}"` : '';
                            return `<span class="badge bg-secondary" style="cursor:help"${neden}>Pasif${row.takip_pasif_nedeni ? ' <i class="bi bi-info-circle"></i>' : ''}</span>`;
                        }
                    },
                    {
                        data: 'GuncellemeTarihi',
                        defaultContent: '-',
                        render: function(data, type) {
                            if (type === 'sort' || type === 'type') {
                                return data || '';
                            }
                            return data ? formatDate(data) : '-';
                        }
                    },
                    {
                        data: null,
                        orderable: false,
                        render: (data, type, row) => {
                            let buttons = '';
                            if (canEdit) {
                                buttons += `<a href="/admin/form?id=${row.takip_id}&cari_tipi_id=3" class="btn btn-sm btn-warning" title="Düzenle"><i class="bi bi-pencil"></i></a> `;
                                if (row.Durum == 1) {
                                    buttons += `<button class="btn btn-sm btn-secondary" onclick="toggleDurum(${row.takip_id}, 0)" title="Pasif Yap"><i class="bi bi-toggle-off"></i></button> `;
                                } else {
                                    buttons += `<button class="btn btn-sm btn-success" onclick="toggleDurum(${row.takip_id}, 1)" title="Aktif Yap"><i class="bi bi-toggle-on"></i></button> `;
                                }
                            }
                            if (canDelete) {
                                buttons += `<button class="btn btn-sm btn-danger" onclick="deleteRecord(${row.takip_id})" title="Sil"><i class="bi bi-trash"></i></button>`;
                            }
                            return buttons || '-';
                        }
                    }
                ],
                order: [[14, 'desc']]
            });
        }
        
        function toggleDurum(id, yeniDurum) {
            if (yeniDurum == 0) {
                Swal.fire({
                    title: 'Dosyayı Pasif Yap',
                    html: '<p class="text-muted mb-2">Bu dosya raporlardan ve toplam tutardan düşecektir.</p>' +
                          '<textarea id="pasif-neden" class="form-control" rows="3" placeholder="Pasif yapma nedenini girin (zorunlu)"></textarea>',
                    icon: 'warning',
                    showCancelButton: true,
                    confirmButtonText: 'Pasif Yap',
                    cancelButtonText: 'İptal',
                    confirmButtonColor: '#6c757d',
                    preConfirm: () => {
                        const neden = document.getElementById('pasif-neden').value.trim();
                        if (!neden) {
                            Swal.showValidationMessage('Lütfen bir neden girin');
                            return false;
                        }
                        return neden;
                    }
                }).then(result => {
                    if (result.isConfirmed) {
                        $.post('', { action: 'toggle_durum', id: id, durum: 0, neden: result.value }, function(res) {
                            if (res.success) {
                                showSuccess('Pasif Yapıldı!', res.message);
                                loadStats();
                                table.ajax.reload(null, false);
                            } else {
                                showError('Hata!', res.message);
                            }
                        });
                    }
                });
            } else {
                confirmAction('Dosyayı tekrar aktif yapmak istediğinize emin misiniz?', 'Pasif yapılma nedeni temizlenecek ve raporlara dahil edilecektir.', function() {
                    $.post('', { action: 'toggle_durum', id: id, durum: 1, neden: '' }, function(res) {
                        if (res.success) {
                            showSuccess('Aktif Yapıldı!', res.message);
                            loadStats();
                            table.ajax.reload(null, false);
                        } else {
                            showError('Hata!', res.message);
                        }
                    });
                });
            }
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
        
        // Statü badge'ine tıklayınca filtrele
        window.filterByStatu = function(statuId) {
            $('#filter_statu_id').val(statuId).trigger('change.select2');
            $('#filterCard').addClass('show');
            $('#filterForm').trigger('submit');
        };

        $('#filterForm').on('submit', function(e) {
            e.preventDefault();
            currentFilters = {
                taraf_id: $('#filter_taraf_id').val(),
                icra_dairesi_id: $('#filter_icra_dairesi_id').val(),
                statu_id: $('#filter_statu_id').val(),
                dosya_no: $('#filter_dosya_no').val(),
                musteri: $('#filter_musteri').val(),
                sehir_id: $('#filter_sehir_id').val(),
                ilce_id: $('#filter_ilce_id').val(),
                durum: $('#filter_durum').val(),
                icraya_gidildi: $('#filter_icraya_gidildi').val(),
                dosya_acilis_bas: $('#filter_dosya_acilis_bas').val(),
                dosya_acilis_bit: $('#filter_dosya_acilis_bit').val()
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
            $('#filter_taraf_id').val('').trigger('change.select2');
            $('#filter_icra_dairesi_id').val('').trigger('change.select2');
            $('#filter_statu_id').val('').trigger('change.select2');
            $('#filter_dosya_no').val('');
            $('#filter_musteri').val('');
            $('#filter_sehir_id').val('').trigger('change.select2');
            $('#filter_ilce_id').html('<option value="">Önce Şehir Seç</option>').prop('disabled', true);
            $('#filter_durum').val('').trigger('change.select2');
            $('#filter_icraya_gidildi').val('').trigger('change.select2');
            $('#filter_dosya_acilis_bas').val('');
            $('#filter_dosya_acilis_bit').val('');
            currentFilters = {};
            table.ajax.reload();
            loadStats();
            showToast('Filtreler temizlendi', 'info');
        });
        
        $(document).ready(function() {
            // Filtre Select2 başlat
            $('#filter_taraf_id, #filter_icra_dairesi_id, #filter_statu_id, #filter_sehir_id, #filter_durum, #filter_icraya_gidildi').select2({
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

            // URL parametrelerini DataTable başlatılmadan önce currentFilters'a yükle
            const urlParams = new URLSearchParams(window.location.search);
            const urlStatu = urlParams.get('statu');
            const urlTaraf = urlParams.get('taraf');
            const urlIcraDairesi = urlParams.get('icra_dairesi');
            const urlDurum = urlParams.get('durum');
            const urlSehir = urlParams.get('sehir');
            const urlIcrayaGidildi = urlParams.get('icraya_gidildi');

            if (urlStatu || urlTaraf || urlIcraDairesi || urlDurum || urlSehir || urlIcrayaGidildi !== null) {
                if (urlStatu) { $('#filter_statu_id').val(urlStatu).trigger('change.select2'); currentFilters.statu_id = urlStatu; }
                if (urlTaraf) { $('#filter_taraf_id').val(urlTaraf).trigger('change.select2'); currentFilters.taraf_id = urlTaraf; }
                if (urlIcraDairesi) { $('#filter_icra_dairesi_id').val(urlIcraDairesi).trigger('change.select2'); currentFilters.icra_dairesi_id = urlIcraDairesi; }
                if (urlDurum) { $('#filter_durum').val(urlDurum).trigger('change.select2'); currentFilters.durum = urlDurum; }
                if (urlSehir) { $('#filter_sehir_id').val(urlSehir).trigger('change.select2'); currentFilters.sehir_id = urlSehir; }
                if (urlIcrayaGidildi !== null) { $('#filter_icraya_gidildi').val(urlIcrayaGidildi).trigger('change.select2'); currentFilters.icraya_gidildi = urlIcrayaGidildi; }
                $('#filterCard').addClass('show');
            }

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
