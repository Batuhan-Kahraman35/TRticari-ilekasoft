<?php
/**
 * Admin Panel - Banka Hesap Hareketleri
 * Banka hesap hareketlerinin listelenmesi, eşleştirme ve yönetimi
 */

require_once __DIR__ . '/../auth.php';
require_once __DIR__ . '/../includes/PageAuth.php';
requireAuth();

$user = Auth::user();
$db = Database::getInstance();

// Sayfa Yetki kontrolü
$currentPageFile = basename($_SERVER['PHP_SELF']);
$pagePermissions = PageAuth::checkPagePermissions(
    $user['kullanici_id'],
    $user['departman_id'],
    $currentPageFile
);

if (!$pagePermissions['has_access']) {
    PageAuth::accessDenied($pagePermissions['error'] ?? 'Bu sayfaya erişim yetkiniz bulunmamaktadır.');
}

// Sayfa bilgilerini al
$pageInfo = $db->fetchOne("
    SELECT 
        s.sayfalar_sayfa_adi, 
        s.sayfalar_aciklama,
        m.menuler_menu_adi as menu_adi
    FROM Menu_Sayfalar s
    LEFT JOIN Menuler m ON s.sayfalar_menu_id = m.menuler_id
    WHERE s.sayfalar_sayfa_url LIKE ? AND s.sayfalar_durum = 1
", ['%' . $currentPageFile]);

$pageTitle = $pageInfo['sayfalar_sayfa_adi'] ?? 'Banka Hesap Hareketleri';
$menuAdi = $pageInfo['menu_adi'] ?? null;

$siteAyarlari = $db->fetchOne("SELECT TOP 1 site_ayarlari_site_title FROM dbo.tanim_site_ayarlari ORDER BY site_ayarlari_id DESC");
$siteTitle = $siteAyarlari['site_ayarlari_site_title'] ?? 'Ornek Yazilim Portal';

// IBAN listesi (filtre dropdown)
$ibanlar = $db->fetchAll("
    SELECT DISTINCT hareket_iban as iban 
    FROM BankaHesapHareketleri 
    WHERE hareket_iban IS NOT NULL 
    ORDER BY hareket_iban
");

// Banka hareketinden oluşturulan ödemeler her zaman EFT/Havale tipindedir.
define('BANKA_HAREKET_ODEME_TIPI_ID', 5);

$bankaOdemeTipi = $db->fetchOne("
    SELECT odeme_tipi_id, odeme_tipi_ad, odeme_tipi_varsayilan_durum_id
    FROM Sozlesme_OdemeTipleri
    WHERE odeme_tipi_id = ? AND odeme_tipi_durum = 1
", [BANKA_HAREKET_ODEME_TIPI_ID]);

$bankaOdemeTipiAd = $bankaOdemeTipi['odeme_tipi_ad'] ?? 'EFT/Havale';

// Banka listesi (filtre dropdown)
$bankalar = $db->fetchAll("
    SELECT DISTINCT hareket_banka as banka_adi 
    FROM BankaHesapHareketleri 
    WHERE hareket_banka IS NOT NULL 
    ORDER BY hareket_banka
");

/**
 * Hareketi ödemeye bağlar, ödemeyi yapıldı olarak işaretler ve varsa dekontu
 * ödeme kaydına ekler. Dekontla ilgili bilgi metni döner.
 */
function hareketiOdemeyeBagla(Database $db, int $hareketId, int $odemeId, int $kullaniciId): string
{
    $db->execute("
        UPDATE BankaHesapHareketleri
        SET hareket_odeme_id = ?, hareket_islendi = 1,
            GuncelleyenKullanici = ?, GuncellemeTarihi = GETDATE()
        WHERE hareket_id = ?
    ", [$odemeId, $kullaniciId, $hareketId]);

    $db->execute("
        UPDATE Sozlesme_Odemeler
        SET odeme_yapildi = 1,
            odeme_guncelleyen_id = ?, odeme_guncelleme_tarihi = GETDATE()
        WHERE odeme_id = ?
    ", [$kullaniciId, $odemeId]);

    // Dekont ödeme kaydına eklenir; ödemede belge varsa dokunulmaz.
    $kaynak = $db->fetchOne("
        SELECT hareket_kaynak_id, hareket_dekont_url
        FROM BankaHesapHareketleri WHERE hareket_id = ?
    ", [$hareketId]);

    if (empty($kaynak['hareket_kaynak_id']) || empty($kaynak['hareket_dekont_url'])) {
        return '';
    }

    $mevcutDosya = $db->fetchOne(
        "SELECT odeme_dosyalar FROM Sozlesme_Odemeler WHERE odeme_id = ?",
        [$odemeId]
    )['odeme_dosyalar'] ?? '';

    if (trim((string) $mevcutDosya) !== '') {
        return ' Ödemede zaten bir belge olduğu için dekont eklenmedi.';
    }

    try {
        require_once __DIR__ . '/../api/banka/PortalService.php';

        $kaynakId = (int) $kaynak['hareket_kaynak_id'];
        $dekont   = PortalService::kanaldan()->dekont($kaynakId);

        $uzanti = $dekont['tip'] === 'application/pdf' ? 'pdf'
            : (str_starts_with($dekont['tip'], 'image/') ? explode('/', $dekont['tip'])[1] : 'pdf');

        $dosyaAdi = 'dekont_' . $kaynakId . '_' . date('YmdHis') . '.' . $uzanti;
        $hedefDiz = __DIR__ . '/../assets/uploads/sozlesme_odemeler/';

        if (!is_dir($hedefDiz)) {
            mkdir($hedefDiz, 0755, true);
        }

        if (file_put_contents($hedefDiz . $dosyaAdi, $dekont['icerik']) === false) {
            return ' Dekont dosyası kaydedilemedi.';
        }

        $db->execute(
            "UPDATE Sozlesme_Odemeler SET odeme_dosyalar = ? WHERE odeme_id = ?",
            [$dosyaAdi, $odemeId]
        );

        return ' Dekont ödeme kaydına eklendi.';

    } catch (Throwable $e) {
        error_log('Dekont ödemeye eklenemedi: ' . $e->getMessage());
        return ' Dekont alınamadı: ' . $e->getMessage();
    }
}

// AJAX işlemleri
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json');
    $action = $_POST['action'] ?? '';
    
    try {
        switch ($action) {
            case 'stats':
                // Öneri sayısı artık satırda hazır tutulur (hareket_oneri_cari_id),
                // her açılışta Cari tablosu taranmaz.
                $stats = $db->fetchOne("
                    SELECT
                        COUNT(*) AS toplam,
                        SUM(CASE WHEN hareket_islendi = 1 THEN 1 ELSE 0 END) AS eslesen,
                        SUM(CASE WHEN hareket_islendi = 0 THEN 1 ELSE 0 END) AS eslesmeyen,
                        SUM(CASE WHEN hareket_islendi = 0 AND hareket_oneri_cari_id > 0 THEN 1 ELSE 0 END) AS oneri_var,
                        ISNULL(SUM(hareket_tutar), 0) AS toplam_tutar
                    FROM BankaHesapHareketleri
                    WHERE hareket_durum = 1
                ");
                echo json_encode(['success' => true, 'data' => $stats]);
                break;

            case 'list':
                $draw   = intval($_POST['draw'] ?? 1);
                $start  = max(0, intval($_POST['start'] ?? 0));
                $length = intval($_POST['length'] ?? 25);
                if ($length < 1 || $length > 500) {
                    $length = 25;
                }

                // Sıralama kolonu istemciden gelen değerle değil, whitelist ile çözülür.
                $siralanabilir = [
                    0 => 'h.hareket_id',
                    1 => 'h.hareket_tarih',
                    2 => 'h.hareket_banka',
                    3 => 'h.hareket_gonderen_ad',
                    4 => 'h.hareket_tutar',
                    5 => 'h.hareket_iban',
                    6 => 'h.hareket_aciklama',
                    7 => 'h.hareket_islendi',
                ];
                $sortIndex = intval($_POST['order'][0]['column'] ?? 1);
                $sortCol   = $siralanabilir[$sortIndex] ?? 'h.hareket_tarih';
                $sortDir   = strtolower($_POST['order'][0]['dir'] ?? 'desc') === 'asc' ? 'ASC' : 'DESC';

                $startDate = $_POST['start_date'] ?? '';
                $endDate   = $_POST['end_date'] ?? '';
                // Filtre panelinden search_text, DataTables kendi kutusundan search[value] gönderir.
                $aramaHam = $_POST['search'] ?? '';
                $search   = trim($_POST['search_text']
                    ?? (is_array($aramaHam) ? ($aramaHam['value'] ?? '') : $aramaHam));
                $iban      = $_POST['iban'] ?? '';
                $banka     = $_POST['banka'] ?? '';
                $islendi   = $_POST['islendi'] ?? '';

                $kosul  = " WHERE h.hareket_durum = 1";
                $params = [];

                // CONVERT kullanılmaz; tarih indeksinin kullanılabilmesi için aralık karşılaştırması.
                if ($startDate) {
                    $kosul   .= " AND h.hareket_tarih >= ?";
                    $params[] = $startDate . ' 00:00:00';
                }
                if ($endDate) {
                    $kosul   .= " AND h.hareket_tarih < DATEADD(DAY, 1, ?)";
                    $params[] = $endDate . ' 00:00:00';
                }
                if ($iban) {
                    $kosul   .= " AND h.hareket_iban = ?";
                    $params[] = $iban;
                }
                if ($banka) {
                    $kosul   .= " AND h.hareket_banka = ?";
                    $params[] = $banka;
                }
                if ($islendi === 'oneri') {
                    $kosul .= " AND h.hareket_islendi = 0 AND h.hareket_oneri_cari_id > 0";
                } elseif ($islendi !== '') {
                    $kosul   .= " AND h.hareket_islendi = ?";
                    $params[] = intval($islendi);
                }
                if ($search !== '') {
                    $kosul .= " AND (h.hareket_gonderen_ad LIKE ? OR h.hareket_aciklama LIKE ?
                                  OR h.hareket_vkn_tc LIKE ? OR h.hareket_referans LIKE ?)";
                    $terim = '%' . $search . '%';
                    array_push($params, $terim, $terim, $terim, $terim);
                }

                // Sayımlar JOIN'siz, tek ana tablodan yapılır.
                $recordsTotal = $db->fetchOne(
                    "SELECT COUNT(*) AS k FROM BankaHesapHareketleri WHERE hareket_durum = 1"
                )['k'] ?? 0;

                $recordsFiltered = $db->fetchOne(
                    "SELECT COUNT(*) AS k FROM BankaHesapHareketleri h" . $kosul,
                    $params
                )['k'] ?? 0;

                // 1. asama: yalnız bu sayfanın kimlikleri (ucuz, JOIN yok)
                // 2. asama: JOIN'ler sadece o N satira uygulanır
                $sql = "WITH sayfa AS (
                            SELECT h.hareket_id
                            FROM BankaHesapHareketleri h
                            {$kosul}
                            ORDER BY {$sortCol} {$sortDir}
                            OFFSET ? ROWS FETCH NEXT ? ROWS ONLY
                        )
                        SELECT
                            h.hareket_id,
                            h.hareket_referans,
                            h.hareket_banka,
                            h.hareket_gonderen_ad,
                            h.hareket_tutar,
                            h.hareket_para_birimi,
                            h.hareket_iban,
                            h.hareket_vkn_tc,
                            h.hareket_aciklama,
                            CONVERT(VARCHAR(19), h.hareket_tarih, 120) AS hareket_tarih,
                            h.hareket_dekont_url,
                            h.hareket_islendi,
                            h.hareket_islem_notu,
                            h.hareket_odeme_id,
                            CONVERT(VARCHAR(19), h.OlusturmaTarihi, 120) AS OlusturmaTarihi,
                            o.odeme_belge_no,
                            o.odeme_tutar AS odeme_tutar,
                            s.sozlesme_no,
                            ISNULL(c.cari_adi, c.cari_unvan) AS cari_ad,
                            CASE WHEN h.hareket_islendi = 0 AND h.hareket_oneri_cari_id > 0 THEN 1 ELSE 0 END AS oneri_var,
                            ISNULL(oc.cari_adi, oc.cari_unvan) AS oneri_cari_ad
                        FROM sayfa p
                        INNER JOIN BankaHesapHareketleri h ON h.hareket_id = p.hareket_id
                        LEFT JOIN Sozlesme_Odemeler o ON h.hareket_odeme_id = o.odeme_id
                        LEFT JOIN Sozlesmeler s ON o.odeme_sozlesme_id = s.sozlesme_id
                        LEFT JOIN Cari c ON s.sozlesme_cari_id = c.cari_id
                        LEFT JOIN Cari oc ON oc.cari_id = h.hareket_oneri_cari_id
                        ORDER BY {$sortCol} {$sortDir}";

                $sayfaParams   = $params;
                $sayfaParams[] = $start;
                $sayfaParams[] = $length;

                $data = $db->fetchAll($sql, $sayfaParams);

                echo json_encode([
                    'success'         => true,
                    'draw'            => $draw,
                    'recordsTotal'    => (int) $recordsTotal,
                    'recordsFiltered' => (int) $recordsFiltered,
                    'data'            => $data,
                ]);
                break;
                
            case 'senkronize':
                if (!$pagePermissions['can_edit']) {
                    echo json_encode(['success' => false, 'message' => 'Düzenleme yetkiniz yok!']);
                    break;
                }

                // Portaldan yeni hareketleri çeker. Entegrasyon logu PortalService
                // içinde yazılır; kullanıcı kimliği geçildiği için işlem kime ait belli olur.
                require_once __DIR__ . '/../api/banka/PortalService.php';

                try {
                    $servis = PortalService::kanaldan();
                    $sonuc  = $servis->senkronEt($user['kullanici_id']);

                    echo json_encode([
                        'success' => (bool) $sonuc['success'],
                        'message' => $sonuc['message'],
                        'eklenen' => $sonuc['eklenen'] ?? 0,
                        'oneri'   => $sonuc['oneri'] ?? 0,
                    ]);

                } catch (Throwable $e) {
                    error_log('Panelden senkron hatası: ' . $e->getMessage());
                    echo json_encode([
                        'success' => false,
                        'message' => 'Senkronizasyon yapılamadı: ' . $e->getMessage()
                    ]);
                }
                break;

            case 'get':
                $id = intval($_POST['id'] ?? 0);
                $data = $db->fetchOne("
                    SELECT h.*,
                        CONVERT(VARCHAR(19), h.hareket_tarih, 120) as hareket_tarih,
                        CONVERT(VARCHAR(19), h.OlusturmaTarihi, 120) as OlusturmaTarihi,
                        o.odeme_belge_no, o.odeme_tutar,
                        s.sozlesme_no, ISNULL(c.cari_adi, c.cari_unvan) as cari_ad
                    FROM BankaHesapHareketleri h
                    LEFT JOIN Sozlesme_Odemeler o ON h.hareket_odeme_id = o.odeme_id
                    LEFT JOIN Sozlesmeler s ON o.odeme_sozlesme_id = s.sozlesme_id
                    LEFT JOIN Cari c ON s.sozlesme_cari_id = c.cari_id
                    WHERE h.hareket_id = ?
                ", [$id]);
                echo json_encode(['success' => true, 'data' => $data]);
                break;

            case 'search_cari':
                $q = trim($_POST['query'] ?? '');
                if (strlen($q) < 2) {
                    echo json_encode(['success' => true, 'data' => []]);
                    break;
                }
                $cariler = $db->fetchAll("
                    SELECT TOP 20 cari_id, cari_adi, cari_unvan, cari_vergi_no 
                    FROM Cari 
                    WHERE cari_aktif = 1 AND (cari_adi LIKE ? OR cari_unvan LIKE ? OR cari_vergi_no LIKE ?)
                    ORDER BY cari_adi
                ", ["%$q%", "%$q%", "%$q%"]);
                echo json_encode(['success' => true, 'data' => $cariler]);
                break;

            case 'get_sozlesmeler':
                $cariId = intval($_POST['cari_id'] ?? 0);
                $sozlesmeler = $db->fetchAll("
                    SELECT sozlesme_id, sozlesme_no, sozlesme_aciklama,
                        CONVERT(VARCHAR(10), sozlesme_tarih, 120) as sozlesme_tarih
                    FROM Sozlesmeler 
                    WHERE sozlesme_cari_id = ? AND sozlesme_durum = 1
                    ORDER BY sozlesme_tarih DESC
                ", [$cariId]);
                echo json_encode(['success' => true, 'data' => $sozlesmeler]);
                break;

            case 'get_odemeler':
                $sozlesmeId = intval($_POST['sozlesme_id'] ?? 0);
                $hareketId  = intval($_POST['hareket_id'] ?? 0);

                // Hareketin tutar ve tarihi ile birebir uyuşan ödeme işaretlenir.
                $ol = $hareketId
                    ? $db->fetchOne("SELECT hareket_tutar, hareket_tarih FROM BankaHesapHareketleri WHERE hareket_id = ?", [$hareketId])
                    : null;
                $olcutTutar = $ol['hareket_tutar'] ?? null;
                $olcutTarih = $ol['hareket_tarih'] ?? null;
                // Bagli hareket bilgisi de dönüyor; zaten eşleşmiş ödemeler listede seçilemez.
                $odemeler = $db->fetchAll("
                    SELECT o.odeme_id, o.odeme_belge_no, o.odeme_tutar, o.odeme_yapildi,
                        CONVERT(VARCHAR(10), o.odeme_tarih, 120) as odeme_tarih,
                        CONVERT(VARCHAR(10), o.odeme_vade_tarih, 120) as odeme_vade_tarih,
                        t.odeme_tipi_ad,
                        bh.hareket_id as bagli_hareket_id,
                        CONVERT(VARCHAR(10), bh.hareket_tarih, 120) as bagli_hareket_tarih,
                        CASE WHEN ? IS NOT NULL
                              AND bh.hareket_id IS NULL
                              AND o.odeme_tutar = ?
                              AND CONVERT(date, ISNULL(o.odeme_tarih, o.odeme_vade_tarih)) = CONVERT(date, ?)
                             THEN 1 ELSE 0 END AS birebir
                    FROM Sozlesme_Odemeler o
                    LEFT JOIN Sozlesme_OdemeTipleri t ON o.odeme_tipi_id = t.odeme_tipi_id
                    OUTER APPLY (
                        SELECT TOP 1 h.hareket_id, h.hareket_tarih
                        FROM BankaHesapHareketleri h
                        WHERE h.hareket_odeme_id = o.odeme_id AND h.hareket_durum = 1
                        ORDER BY h.hareket_id
                    ) bh
                    WHERE o.odeme_sozlesme_id = ?
                    ORDER BY birebir DESC, o.odeme_vade_tarih ASC
                ", [$olcutTutar, $olcutTutar, $olcutTarih, $sozlesmeId]);
                echo json_encode(['success' => true, 'data' => $odemeler]);
                break;

            case 'eslestir':
                if (!$pagePermissions['can_edit']) {
                    echo json_encode(['success' => false, 'message' => 'Düzenleme yetkiniz yok!']);
                    break;
                }
                $hareketId = intval($_POST['hareket_id'] ?? 0);
                $odemeId = intval($_POST['odeme_id'] ?? 0);

                if (!$hareketId || !$odemeId) {
                    echo json_encode(['success' => false, 'message' => 'Hareket veya ödeme seçilmedi!']);
                    break;
                }

                $hareket = $db->fetchOne("
                    SELECT hareket_odeme_id, hareket_tutar, hareket_tarih, hareket_referans
                    FROM BankaHesapHareketleri
                    WHERE hareket_id = ? AND hareket_durum = 1
                ", [$hareketId]);

                if (!$hareket) {
                    echo json_encode(['success' => false, 'message' => 'Hareket bulunamadı!']);
                    break;
                }

                // 1) Hareket başka bir ödemeye bağlanmış mı?
                if ($hareket['hareket_odeme_id']) {
                    echo json_encode([
                        'success' => false,
                        'message' => 'Bu hareket zaten bir ödemeye eşleştirilmiş. Önce mevcut eşleştirmeyi kaldırın.'
                    ]);
                    break;
                }

                $odeme = $db->fetchOne("
                    SELECT o.odeme_id, o.odeme_tutar, o.odeme_yapildi, o.odeme_sozlesme_id,
                        CONVERT(VARCHAR(10), o.odeme_tarih, 120) as odeme_tarih,
                        CONVERT(VARCHAR(10), o.odeme_vade_tarih, 120) as odeme_vade_tarih
                    FROM Sozlesme_Odemeler o
                    WHERE o.odeme_id = ?
                ", [$odemeId]);

                if (!$odeme) {
                    echo json_encode(['success' => false, 'message' => 'Ödeme bulunamadı!']);
                    break;
                }

                // 2) Ödeme başka bir harekete bağlı mı? (mükerrer tahsilat)
                $bagliHareket = $db->fetchOne("
                    SELECT TOP 1 hareket_id, hareket_referans, hareket_tutar,
                        CONVERT(VARCHAR(10), hareket_tarih, 120) as hareket_tarih
                    FROM BankaHesapHareketleri
                    WHERE hareket_odeme_id = ? AND hareket_id <> ? AND hareket_durum = 1
                ", [$odemeId, $hareketId]);

                if ($bagliHareket) {
                    echo json_encode([
                        'success' => false,
                        'message' => 'Bu ödeme ' . $bagliHareket['hareket_tarih'] . ' tarihli '
                            . number_format((float) $bagliHareket['hareket_tutar'], 2, ',', '.')
                            . ' TL tutarındaki harekete (#' . $bagliHareket['hareket_id']
                            . ') zaten eşleştirilmiş.'
                    ]);
                    break;
                }

                // Aynı gün aynı tutarda birden fazla gerçek tahsilat olabildiği için
                // tutar/tarih bazlı mükerrer kısıtı uygulanmaz. Tek kısıt, bir ödemenin
                // yalnızca tek bir harekete bağlanabilmesidir (yukarıdaki kontrol).
                $dekontMesaj = hareketiOdemeyeBagla($db, $hareketId, $odemeId, $user['kullanici_id']);

                echo json_encode([
                    'success' => true,
                    'message' => 'Eşleştirme başarıyla yapıldı!' . $dekontMesaj
                ]);
                break;

            case 'yeni_odeme_eslestir':
                if (!$pagePermissions['can_edit']) {
                    echo json_encode(['success' => false, 'message' => 'Düzenleme yetkiniz yok!']);
                    break;
                }

                $hareketId  = intval($_POST['hareket_id'] ?? 0);
                $sozlesmeId = intval($_POST['sozlesme_id'] ?? 0);
                $aciklama   = trim($_POST['aciklama'] ?? '');

                if (!$hareketId || !$sozlesmeId) {
                    echo json_encode(['success' => false, 'message' => 'Hareket ve sözleşme seçilmelidir!']);
                    break;
                }

                $hareket = $db->fetchOne("
                    SELECT hareket_odeme_id, hareket_tutar, hareket_tarih, hareket_gonderen_ad
                    FROM BankaHesapHareketleri
                    WHERE hareket_id = ? AND hareket_durum = 1
                ", [$hareketId]);

                if (!$hareket) {
                    echo json_encode(['success' => false, 'message' => 'Hareket bulunamadı!']);
                    break;
                }

                if ($hareket['hareket_odeme_id']) {
                    echo json_encode([
                        'success' => false,
                        'message' => 'Bu hareket zaten bir ödemeye eşleştirilmiş. Önce mevcut eşleştirmeyi kaldırın.'
                    ]);
                    break;
                }

                // Tip her zaman EFT/Havale; durumu tipin tanımındaki varsayılandan gelir.
                $tip = $db->fetchOne("
                    SELECT odeme_tipi_id, odeme_tipi_ad, odeme_tipi_varsayilan_durum_id
                    FROM Sozlesme_OdemeTipleri
                    WHERE odeme_tipi_id = ? AND odeme_tipi_durum = 1
                ", [BANKA_HAREKET_ODEME_TIPI_ID]);

                if (!$tip) {
                    echo json_encode([
                        'success' => false,
                        'message' => 'EFT/Havale ödeme tipi tanımlı değil veya pasif durumda!'
                    ]);
                    break;
                }

                $tarihMetni = $hareket['hareket_tarih'] instanceof DateTimeInterface
                    ? $hareket['hareket_tarih']->format('Y-m-d')
                    : substr((string) $hareket['hareket_tarih'], 0, 10);

                $db->execute("
                    INSERT INTO Sozlesme_Odemeler (
                        odeme_sozlesme_id, odeme_tipi_id, odeme_durum_id,
                        odeme_tarih, odeme_vade_tarih, odeme_tutar,
                        odeme_yapildi, odeme_aciklama, odeme_olusturan_id, odeme_olusturma_tarihi
                    ) VALUES (?, ?, ?, ?, ?, ?, 1, ?, ?, GETDATE())
                ", [
                    $sozlesmeId,
                    $tip['odeme_tipi_id'],
                    $tip['odeme_tipi_varsayilan_durum_id'],
                    $tarihMetni,
                    $tarihMetni,
                    $hareket['hareket_tutar'],
                    $aciklama !== '' ? $aciklama : 'Banka hareketinden oluşturuldu: ' . $hareket['hareket_gonderen_ad'],
                    $user['kullanici_id'],
                ]);

                $yeniOdemeId = (int) $db->getLastInsertId();

                if (!$yeniOdemeId) {
                    echo json_encode(['success' => false, 'message' => 'Ödeme kaydı oluşturulamadı!']);
                    break;
                }

                $dekontMesaj = hareketiOdemeyeBagla($db, $hareketId, $yeniOdemeId, $user['kullanici_id']);

                echo json_encode([
                    'success' => true,
                    'message' => 'Yeni ödeme oluşturuldu ve eşleştirildi.' . $dekontMesaj
                ]);
                break;

            case 'eslestirme_kaldir':
                if (!$pagePermissions['can_edit']) {
                    echo json_encode(['success' => false, 'message' => 'Düzenleme yetkiniz yok!']);
                    break;
                }
                $hareketId = intval($_POST['hareket_id'] ?? 0);
                
                // Önce mevcut odeme_id'yi al
                $hareket = $db->fetchOne("SELECT hareket_odeme_id FROM BankaHesapHareketleri WHERE hareket_id = ?", [$hareketId]);
                
                if ($hareket && $hareket['hareket_odeme_id']) {
                    // Ödemenin yapıldı durumunu geri al
                    $db->execute("
                        UPDATE Sozlesme_Odemeler
                        SET odeme_yapildi = 0,
                            odeme_guncelleyen_id = ?, odeme_guncelleme_tarihi = GETDATE()
                        WHERE odeme_id = ?
                    ", [$user['kullanici_id'], $hareket['hareket_odeme_id']]);

                    // Eşleştirme sırasında eklenen dekont temizlenir; kullanıcının kendi
                    // yüklediği belgelere (farklı ad deseni) dokunulmaz.
                    $odemeDosya = $db->fetchOne(
                        "SELECT odeme_dosyalar FROM Sozlesme_Odemeler WHERE odeme_id = ?",
                        [$hareket['hareket_odeme_id']]
                    )['odeme_dosyalar'] ?? '';

                    if ($odemeDosya !== '' && str_starts_with((string) $odemeDosya, 'dekont_')) {
                        $dosyaYolu = __DIR__ . '/../assets/uploads/sozlesme_odemeler/' . $odemeDosya;

                        if (is_file($dosyaYolu)) {
                            @unlink($dosyaYolu);
                        }

                        $db->execute(
                            "UPDATE Sozlesme_Odemeler SET odeme_dosyalar = NULL WHERE odeme_id = ?",
                            [$hareket['hareket_odeme_id']]
                        );
                    }
                }

                // Hareket eşleştirmesini kaldır
                $db->execute("
                    UPDATE BankaHesapHareketleri 
                    SET hareket_odeme_id = NULL, hareket_islendi = 0,
                        GuncelleyenKullanici = ?, GuncellemeTarihi = GETDATE()
                    WHERE hareket_id = ?
                ", [$user['kullanici_id'], $hareketId]);

                echo json_encode(['success' => true, 'message' => 'Eşleştirme kaldırıldı!']);
                break;
                
            case 'delete':
                if (!$pagePermissions['can_delete']) {
                    echo json_encode(['success' => false, 'message' => 'Silme yetkiniz yok!']);
                    break;
                }
                $id = intval($_POST['id'] ?? 0);
                $db->execute("UPDATE BankaHesapHareketleri SET hareket_durum = 0, GuncelleyenKullanici = ?, GuncellemeTarihi = GETDATE() WHERE hareket_id = ?", [$user['kullanici_id'], $id]);
                echo json_encode(['success' => true, 'message' => 'Hareket silindi!']);
                break;

            case 'auto_match':
                $hareketId = intval($_POST['hareket_id'] ?? 0);
                
                $sonuclar = $db->fetchAll("
                    WITH CariTemiz AS (
                        SELECT
                            c.cari_id,
                            c.cari_adi,
                            c.cari_unvan,
                            c.cari_vergi_no,
                            UPPER(LTRIM(RTRIM(ISNULL(c.cari_adi,'')))) AS cari_adi_norm,
                            UPPER(LTRIM(RTRIM(ISNULL(c.cari_unvan,'')))) AS cari_unvan_norm,
                            REPLACE(ISNULL(c.cari_vergi_no,''),' ','') AS vergi_no_norm
                        FROM dbo.Cari c
                    ),
                    HareketTemiz AS (
                        SELECT
                            h.hareket_id,
                            h.hareket_gonderen_ad,
                            h.hareket_aciklama,
                            h.hareket_vkn_tc,
                            h.hareket_tutar,
                            UPPER(LTRIM(RTRIM(ISNULL(h.hareket_gonderen_ad,'')))) AS gonderen_norm,
                            UPPER(LTRIM(RTRIM(ISNULL(h.hareket_aciklama,'')))) AS aciklama_norm,
                            REPLACE(ISNULL(h.hareket_vkn_tc,''),' ','') AS vkn_norm
                        FROM dbo.BankaHesapHareketleri h
                        WHERE h.hareket_id = $hareketId
                    )
                    SELECT
                        c.cari_id,
                        c.cari_adi,
                        c.cari_unvan,
                        c.cari_vergi_no,
                        CASE
                            WHEN h.vkn_norm = c.vergi_no_norm AND h.vkn_norm <> '' THEN 'vkn_tc'
                            WHEN h.gonderen_norm = c.cari_adi_norm AND h.gonderen_norm <> '' THEN 'ad_birebir'
                            WHEN h.gonderen_norm = c.cari_unvan_norm AND h.gonderen_norm <> '' THEN 'unvan_birebir'
                            WHEN LEN(c.cari_adi_norm) >= 8 AND h.aciklama_norm LIKE '%' + c.cari_adi_norm + '%' THEN 'aciklama'
                        END AS eslesme_tipi
                    FROM HareketTemiz h
                    INNER JOIN CariTemiz c
                        ON (h.vkn_norm = c.vergi_no_norm AND h.vkn_norm <> '')
                        OR (h.gonderen_norm = c.cari_adi_norm AND h.gonderen_norm <> '')
                        OR (h.gonderen_norm = c.cari_unvan_norm AND h.gonderen_norm <> '')
                        OR (LEN(c.cari_adi_norm) >= 8 AND h.aciklama_norm LIKE '%' + c.cari_adi_norm + '%')
                ");
                
                $oneriler = [];
                $tipLabelleri = [
                    'vkn_tc' => 'VKN/TC Eşleşmesi',
                    'ad_birebir' => 'Ad Birebir Eşleşme',
                    'unvan_birebir' => 'Ünvan Birebir Eşleşme',
                    'aciklama' => 'Açıklamada Bulundu'
                ];
                $tipGuven = [
                    'vkn_tc' => 100,
                    'ad_birebir' => 95,
                    'unvan_birebir' => 95,
                    'aciklama' => 80
                ];
                
                foreach ($sonuclar as $r) {
                    $tip = $r['eslesme_tipi'] ?? 'aciklama';
                    $oneriler[] = [
                        'tip' => $tip,
                        'tip_label' => $tipLabelleri[$tip] ?? $tip,
                        'cari_id' => $r['cari_id'],
                        'cari_adi' => $r['cari_adi'],
                        'cari_unvan' => $r['cari_unvan'],
                        'cari_vergi_no' => $r['cari_vergi_no'],
                        'guven' => $tipGuven[$tip] ?? 70
                    ];
                }
                
                // Önerilen carilerde eşleştirmeye uygun ödeme var mı, mükerrer riski taşıyor mu?
                // Öneri listesi kısa olduğu için tek sorguda toplanır.
                if ($oneriler) {
                    $hareketBilgi = $db->fetchOne("
                        SELECT hareket_tutar, hareket_tarih
                        FROM BankaHesapHareketleri WHERE hareket_id = ?
                    ", [$hareketId]);

                    $cariIdler = array_values(array_unique(array_map(
                        fn($o) => (int) $o['cari_id'], $oneriler
                    )));
                    $yerTutucu = implode(',', array_fill(0, count($cariIdler), '?'));

                    $durumParams = array_merge(
                        [
                            $hareketBilgi['hareket_tutar'],
                            $hareketBilgi['hareket_tutar'],
                            $hareketBilgi['hareket_tarih'],
                        ],
                        $cariIdler
                    );

                    $durumlar = $db->fetchAll("
                        SELECT
                            s.sozlesme_cari_id AS cari_id,
                            SUM(CASE WHEN bh.hareket_id IS NULL THEN 1 ELSE 0 END) AS bagsiz_odeme,
                            SUM(CASE WHEN bh.hareket_id IS NULL AND o.odeme_tutar = ? THEN 1 ELSE 0 END) AS ayni_tutar,
                            SUM(CASE WHEN bh.hareket_id IS NULL AND o.odeme_tutar = ?
                                      AND CONVERT(date, ISNULL(o.odeme_tarih, o.odeme_vade_tarih)) = CONVERT(date, ?)
                                     THEN 1 ELSE 0 END) AS birebir
                        FROM Sozlesmeler s
                        INNER JOIN Sozlesme_Odemeler o ON o.odeme_sozlesme_id = s.sozlesme_id
                        OUTER APPLY (
                            SELECT TOP 1 h.hareket_id FROM BankaHesapHareketleri h
                            WHERE h.hareket_odeme_id = o.odeme_id AND h.hareket_durum = 1
                        ) bh
                        WHERE s.sozlesme_cari_id IN ({$yerTutucu})
                        GROUP BY s.sozlesme_cari_id
                    ", $durumParams);

                    $durumHaritasi = [];
                    foreach ($durumlar as $d) {
                        $durumHaritasi[(int) $d['cari_id']] = $d;
                    }

                    foreach ($oneriler as &$oneri) {
                        $d = $durumHaritasi[(int) $oneri['cari_id']] ?? null;

                        $oneri['bagsiz_odeme'] = (int) ($d['bagsiz_odeme'] ?? 0);
                        $oneri['ayni_tutar']   = (int) ($d['ayni_tutar'] ?? 0);
                        $oneri['birebir']      = (int) ($d['birebir'] ?? 0);

                        // Tutar ve tarihi uyuşan ödemesi olan cariler öne çıkar.
                        if ($oneri['bagsiz_odeme'] === 0) {
                            $oneri['guven'] = max(10, $oneri['guven'] - 25);
                        } elseif ($oneri['birebir'] > 0) {
                            $oneri['guven'] = min(100, $oneri['guven'] + 10);
                        } elseif ($oneri['ayni_tutar'] > 0) {
                            $oneri['guven'] = min(100, $oneri['guven'] + 5);
                        }
                    }
                    unset($oneri);
                }

                // Güven skoruna göre sırala
                usort($oneriler, function($a, $b) { return $b['guven'] - $a['guven']; });

                echo json_encode(['success' => true, 'data' => $oneriler]);
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
    <link rel="stylesheet" href="/admin/assets/css/adminlte.min.css">
    <link href="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css" rel="stylesheet" />
    <link href="https://cdn.jsdelivr.net/npm/select2-bootstrap-5-theme@1.3.0/dist/select2-bootstrap-5-theme.min.css" rel="stylesheet" />
    <link href="https://cdn.jsdelivr.net/npm/sweetalert2@11/dist/sweetalert2.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.datatables.net/1.13.7/css/dataTables.bootstrap5.min.css">
    <link rel="stylesheet" href="/admin/assets/css/custom.css">
    <style>
        .text-amount { font-family: 'Consolas', monospace; font-weight: 600; }
        .iban-text { font-family: 'Consolas', monospace; font-size: 0.85rem; }
        .info-box:hover { transform: translateY(-3px); box-shadow: 0 4px 8px rgba(0,0,0,0.15); }
        .info-box { transition: all 0.3s ease; }
        .badge-eslesti { background-color: #198754; color: white; }
        .badge-oneri { background-color: #0dcaf0; color: #000; }
        .badge-bekliyor { background-color: #ffc107; color: #000; }
        .eslestirme-adim { display: none; }
        .eslestirme-adim.active { display: block; }
        .cari-item, .sozlesme-item, .odeme-item { cursor: pointer; transition: background 0.2s; }
        .cari-item:hover, .sozlesme-item:hover, .odeme-item:hover { background-color: #e8f4fd; }
        .cari-item.selected, .sozlesme-item.selected, .odeme-item.selected { background-color: #d1ecf1; border-left: 3px solid #0d6efd; }
        .oneri-card { transition: all 0.2s ease; border: 1px solid #dee2e6; }
        .oneri-card:hover { border-color: #0d6efd; box-shadow: 0 2px 8px rgba(13,110,253,0.15); transform: translateY(-1px); }
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
                            <div class="info-box">
                                <span class="info-box-icon text-bg-primary shadow-sm">
                                    <i class="bi bi-list-ul"></i>
                                </span>
                                <div class="info-box-content">
                                    <span class="info-box-text">Toplam Hareket</span>
                                    <span class="info-box-number" id="stat-toplam">0</span>
                                </div>
                            </div>
                        </div>
                        <div class="col-12 col-sm-6 col-md-3">
                            <div class="info-box">
                                <span class="info-box-icon text-bg-success shadow-sm">
                                    <i class="bi bi-check-circle"></i>
                                </span>
                                <div class="info-box-content">
                                    <span class="info-box-text">Eşleşen</span>
                                    <span class="info-box-number" id="stat-eslesen">0</span>
                                </div>
                            </div>
                        </div>
                        <div class="col-12 col-sm-6 col-md-3">
                            <div class="info-box">
                                <span class="info-box-icon text-bg-warning shadow-sm">
                                    <i class="bi bi-exclamation-circle"></i>
                                </span>
                                <div class="info-box-content">
                                    <span class="info-box-text">Eşleşmeyen</span>
                                    <span class="info-box-number" id="stat-eslesmeyen">0</span>
                                </div>
                            </div>
                        </div>
                        <div class="col-12 col-sm-6 col-md-3">
                            <div class="info-box">
                                <span class="info-box-icon text-bg-info shadow-sm">
                                    <i class="bi bi-lightbulb"></i>
                                </span>
                                <div class="info-box-content">
                                    <span class="info-box-text">Öneri Var</span>
                                    <span class="info-box-number" id="stat-oneri">0</span>
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <!-- Filtre Kartı -->
                    <div class="card card-primary card-outline mb-3">
                        <div class="card-header">
                            <h3 class="card-title"><i class="bi bi-funnel"></i> Filtrele</h3>
                            <div class="card-tools">
                                <button type="button" class="btn btn-tool" data-bs-toggle="collapse" data-bs-target="#filterCard" aria-expanded="false">
                                    <i class="bi bi-chevron-down"></i>
                                </button>
                            </div>
                        </div>
                        <div class="card-body collapse" id="filterCard">
                            <form id="filterForm">
                                <div class="row g-3">
                                    <div class="col-md-2">
                                        <label class="form-label">Başlangıç Tarihi</label>
                                        <input type="date" class="form-control" name="start_date" id="filter_start_date">
                                    </div>
                                    <div class="col-md-2">
                                        <label class="form-label">Bitiş Tarihi</label>
                                        <input type="date" class="form-control" name="end_date" id="filter_end_date">
                                    </div>
                                    <div class="col-md-2">
                                        <label class="form-label">Banka</label>
                                        <select class="form-select" name="banka" id="filter_banka">
                                            <option value="">Tümü</option>
                                            <?php foreach ($bankalar as $b): ?>
                                            <option value="<?= htmlspecialchars($b['banka_adi']) ?>"><?= htmlspecialchars($b['banka_adi']) ?></option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                    <div class="col-md-3">
                                        <label class="form-label">IBAN</label>
                                        <select class="form-select" name="iban" id="filter_iban">
                                            <option value="">Tümü</option>
                                            <?php foreach ($ibanlar as $ib): ?>
                                            <option value="<?= htmlspecialchars($ib['iban']) ?>"><?= htmlspecialchars($ib['iban']) ?></option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                    <div class="col-md-2">
                                        <label class="form-label">Eşleşme Durumu</label>
                                        <select class="form-select" name="islendi" id="filter_islendi">
                                            <option value="">Tümü</option>
                                            <option value="1">Eşleşen</option>
                                            <option value="0">Eşleşmeyen</option>
                                            <option value="oneri">Öneri Var</option>
                                        </select>
                                    </div>
                                    <div class="col-md-3">
                                        <label class="form-label">Arama</label>
                                        <input type="text" class="form-control" name="search" id="filter_search" placeholder="İsim, Açıklama, VKN/TC, Referans...">
                                    </div>
                                    <div class="col-md-12">
                                        <button type="submit" class="btn btn-primary">
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
                    
                    <!-- Ana İçerik -->
                    <div class="card card-primary card-outline">
                        <div class="card-header d-flex justify-content-between align-items-center">
                            <h3 class="card-title mb-0"><i class="bi bi-bank"></i> Hesap Hareketleri</h3>
                            <?php if ($pagePermissions['can_edit']): ?>
                            <div class="d-flex align-items-center gap-2">
                                <small class="text-muted" id="senkronBilgi"></small>
                                <button type="button" class="btn btn-sm btn-primary" id="btnSenkronize"
                                        title="Portaldan yeni banka hareketlerini çeker">
                                    <i class="bi bi-arrow-repeat"></i> Güncelle
                                </button>
                            </div>
                            <?php endif; ?>
                        </div>
                        <div class="card-body">
                            <div class="table-responsive">
                                <table id="dataTable" class="table table-bordered table-striped table-hover">
                                    <thead>
                                        <tr>
                                            <th style="width:40px">#</th>
                                            <th>Tarih</th>
                                            <th>Banka</th>
                                            <th>Gönderen</th>
                                            <th>Tutar</th>
                                            <th>IBAN</th>
                                            <th>Açıklama</th>
                                            <th>Durum</th>
                                            <th style="width:130px">İşlemler</th>
                                        </tr>
                                    </thead>
                                    <tbody></tbody>
                                </table>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </main>
        
        <?php include __DIR__ . '/../includes/footer.php'; ?>
    </div>
    
    <!-- Detay Modal -->
    <div class="modal fade" id="detailModal" tabindex="-1">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header bg-info text-white">
                    <h5 class="modal-title"><i class="bi bi-info-circle"></i> Hareket Detayı</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body" id="detailContent"></div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Kapat</button>
                </div>
            </div>
        </div>
    </div>

    <!-- Eşleştirme Modal -->
    <div class="modal fade" id="eslestirModal" tabindex="-1">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header bg-primary text-white">
                    <h5 class="modal-title"><i class="bi bi-link-45deg"></i> Ödeme Eşleştir</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <!-- Hareket Bilgisi -->
                    <div class="alert alert-info mb-3" id="eslestir_hareket_bilgi"></div>
                    
                    <!-- Otomatik Öneriler -->
                    <div id="otomatikOnerilerSection" style="display:none;" class="mb-3">
                        <div class="d-flex justify-content-between align-items-center mb-2">
                            <h6 class="mb-0"><i class="bi bi-magic text-primary"></i> Otomatik Öneriler</h6>
                            <button type="button" class="btn btn-sm btn-outline-secondary" onclick="$('#otomatikOnerilerSection').slideUp(); eslestirmeAdimGoster(1);">
                                <i class="bi bi-search"></i> Manuel Ara
                            </button>
                        </div>
                        <div id="otomatikOnerilerContent"></div>
                    </div>
                    
                    <!-- Adım 1: Cari Seç -->
                    <div class="eslestirme-adim active" id="adim1">
                        <h6><span class="badge bg-primary">1</span> Cari Seçin</h6>
                        <select class="form-select" id="cari_select" style="width:100%">
                            <option value="">Cari adı veya VKN ile arayın...</option>
                        </select>
                    </div>
                    
                    <!-- Adım 2: Sözleşme Seç -->
                    <div class="eslestirme-adim" id="adim2">
                        <h6>
                            <span class="badge bg-primary">2</span> Sözleşme Seçin
                            <button class="btn btn-sm btn-outline-secondary ms-2" onclick="eslestirmeGeri(1)"><i class="bi bi-arrow-left"></i> Geri</button>
                        </h6>
                        <div class="mb-2"><small class="text-muted" id="secilen_cari_bilgi"></small></div>
                        <div id="sozlesme_listesi" class="list-group" style="max-height:250px;overflow-y:auto;"></div>
                    </div>
                    
                    <!-- Adım 3: Ödeme Seç -->
                    <div class="eslestirme-adim" id="adim3">
                        <h6>
                            <span class="badge bg-primary">3</span> Ödeme Seçin
                            <button class="btn btn-sm btn-outline-secondary ms-2" onclick="eslestirmeGeri(2)"><i class="bi bi-arrow-left"></i> Geri</button>
                        </h6>
                        <div class="mb-2"><small class="text-muted" id="secilen_sozlesme_bilgi"></small></div>
                        <div id="odeme_listesi" class="list-group" style="max-height:250px;overflow-y:auto;"></div>

                        <!-- Tutan ödeme yoksa hareketin tutarıyla yeni ödeme oluşturulur -->
                        <div class="mt-3 border-top pt-3">
                            <button type="button" class="btn btn-sm btn-outline-success" id="btnYeniOdemeAc">
                                <i class="bi bi-plus-circle"></i> Tutan ödeme yok, yeni ödeme oluştur
                            </button>

                            <div id="yeniOdemePanel" class="mt-3" style="display:none;">
                                <div class="alert alert-light border py-2 mb-2">
                                    <small>
                                        <i class="bi bi-info-circle"></i>
                                        Hareketin tutarı ve tarihiyle sözleşmeye yeni bir ödeme satırı eklenir,
                                        ardından bu harekete bağlanır.
                                    </small>
                                </div>
                                <div class="row g-2">
                                    <div class="col-md-6">
                                        <label class="form-label mb-1"><small>Tutar</small></label>
                                        <input type="text" class="form-control form-control-sm" id="yeni_odeme_tutar" readonly>
                                    </div>
                                    <div class="col-md-6">
                                        <label class="form-label mb-1"><small>Ödeme Tarihi</small></label>
                                        <input type="text" class="form-control form-control-sm" id="yeni_odeme_tarih" readonly>
                                    </div>
                                    <div class="col-md-6">
                                        <label class="form-label mb-1"><small>Ödeme Tipi</small></label>
                                        <input type="text" class="form-control form-control-sm"
                                               value="<?= htmlspecialchars($bankaOdemeTipiAd) ?>" readonly>
                                    </div>
                                    <div class="col-md-6">
                                        <label class="form-label mb-1"><small>Açıklama</small></label>
                                        <input type="text" class="form-control form-control-sm" id="yeni_odeme_aciklama"
                                               placeholder="Boş bırakılırsa gönderen adı yazılır">
                                    </div>
                                </div>
                                <button type="button" class="btn btn-sm btn-success mt-2" id="btnYeniOdemeKaydet">
                                    <i class="bi bi-check-lg"></i> Ödemeyi oluştur ve eşleştir
                                </button>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <input type="hidden" id="eslestir_hareket_id">
                    <input type="hidden" id="eslestir_odeme_id">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">İptal</button>
                    <button type="button" class="btn btn-primary" id="btnEslestir" disabled>
                        <i class="bi bi-link-45deg"></i> Eşleştir
                    </button>
                </div>
            </div>
        </div>
    </div>

    <!-- Scripts -->
    <script src="https://cdn.jsdelivr.net/npm/jquery@3.7.1/dist/jquery.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/overlayscrollbars@2.10.1/browser/overlayscrollbars.browser.es6.min.js"></script>
    <script src="/admin/assets/js/adminlte.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11/dist/sweetalert2.all.min.js"></script>
    <script src="https://cdn.datatables.net/1.13.7/js/jquery.dataTables.min.js"></script>
    <script src="https://cdn.datatables.net/1.13.7/js/dataTables.bootstrap5.min.js"></script>
    <script src="/admin/assets/js/custom.js"></script>
    
    <script>
        let table = null;
        let currentFilters = {};
        const detailModal = new bootstrap.Modal(document.getElementById('detailModal'));
        const eslestirModal = new bootstrap.Modal(document.getElementById('eslestirModal'));
        
        // Seçili eşleştirme verileri
        let secilenCariId = null;
        let secilenSozlesmeId = null;
        let secilenOdemeId = null;
        let secilenHareket = null;
        
        // Para formatla
        function formatMoney(amount, currency = 'TRY') {
            if (!amount && amount !== 0) return '-';
            const num = parseFloat(amount);
            const symbols = { TRY: '₺', USD: '$', EUR: '€' };
            return (symbols[currency] || '₺') + num.toLocaleString('tr-TR', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
        }
        
        // Tarih formatla
        function formatDate(dateString) {
            if (!dateString) return '-';
            try {
                const date = new Date(dateString.replace(' ', 'T'));
                if (isNaN(date.getTime())) return '-';
                return date.toLocaleDateString('tr-TR', {
                    year: 'numeric', month: '2-digit', day: '2-digit',
                    hour: '2-digit', minute: '2-digit'
                });
            } catch (e) { return '-'; }
        }
        
        // İstatistikleri yükle
        function loadStats() {
            $.post('', { action: 'stats' }, function(response) {
                if (response.success) {
                    $('#stat-toplam').text(response.data.toplam);
                    $('#stat-eslesen').text(response.data.eslesen);
                    $('#stat-eslesmeyen').text(response.data.eslesmeyen);
                    $('#stat-oneri').text(response.data.oneri_var);
                }
            });
        }
        
        // DataTable başlat
        function initDataTable() {
            table = $('#dataTable').DataTable({
                processing: true,
                serverSide: true,
                scrollX: true,
                autoWidth: false,
                // Global arama kutusu kapalı; arama filtre panelinden yapılır.
                dom: 'lrtip',
                ajax: {
                    url: '',
                    type: 'POST',
                    data: function(d) {
                        return $.extend({}, d, { action: 'list' }, currentFilters);
                    }
                },
                columns: [
                    { data: 'hareket_id' },
                    { 
                        data: 'hareket_tarih',
                        render: function(data) { return formatDate(data); }
                    },
                    { 
                        data: 'hareket_banka',
                        render: function(data) {
                            return data ? '<span class="badge bg-secondary">' + data + '</span>' : '-';
                        }
                    },
                    { 
                        data: 'hareket_gonderen_ad',
                        render: function(data, type, row) {
                            let html = '<strong>' + (data || '-') + '</strong>';
                            if (row.hareket_vkn_tc) {
                                html += '<br><small class="text-muted">VKN/TC: ' + row.hareket_vkn_tc + '</small>';
                            }
                            return html;
                        }
                    },
                    { 
                        data: 'hareket_tutar',
                        render: function(data, type, row) {
                            return '<span class="text-amount text-success">' + formatMoney(data, row.hareket_para_birimi) + '</span>';
                        }
                    },
                    { 
                        data: 'hareket_iban',
                        render: function(data) {
                            if (!data) return '-';
                            return '<span class="iban-text" title="' + data + '">' + data.substring(0, 10) + '...</span>';
                        }
                    },
                    { 
                        data: 'hareket_aciklama',
                        render: function(data) {
                            if (!data) return '-';
                            const short = data.length > 40 ? data.substring(0, 40) + '...' : data;
                            return '<span title="' + (data || '').replace(/"/g, '&quot;') + '">' + short + '</span>';
                        }
                    },
                    { 
                        data: 'hareket_islendi',
                        render: function(data, type, row) {
                            // Cari adları uzun olabiliyor; sütun taşmasın diye kısaltılır,
                            // tam hâli başlık (tooltip) olarak durur.
                            const kısalt = function(metin) {
                                if (!metin) return '';
                                return metin.length > 20 ? metin.substring(0, 20) + '…' : metin;
                            };
                            const baslik = function(metin) {
                                return (metin || '').replace(/"/g, '&quot;');
                            };

                            if (data == 1 && row.cari_ad) {
                                return '<span class="badge badge-eslesti" title="' + baslik(row.cari_ad) + '">'
                                     + '<i class="bi bi-check-circle"></i> ' + kısalt(row.cari_ad) + '</span>';
                            } else if (data == 1) {
                                return '<span class="badge badge-eslesti"><i class="bi bi-check-circle"></i> Eşleşti</span>';
                            } else if (row.oneri_var == 1) {
                                const oneri = row.oneri_cari_ad ? ': ' + kısalt(row.oneri_cari_ad) : ' Var';
                                return '<span class="badge badge-oneri" title="Önerilen cari: '
                                     + baslik(row.oneri_cari_ad) + '"><i class="bi bi-lightbulb"></i> Öneri' + oneri + '</span>';
                            }
                            return '<span class="badge badge-bekliyor"><i class="bi bi-clock"></i> Bekliyor</span>';
                        }
                    },
                    {
                        data: null,
                        orderable: false,
                        render: function(data, type, row) {
                            let buttons = '<div class="btn-group btn-group-sm">';
                            buttons += '<button class="btn btn-info btn-sm" onclick="showDetail(' + row.hareket_id + ')" title="Detay"><i class="bi bi-eye"></i></button>';
                            <?php if ($pagePermissions['can_edit']): ?>
                            if (row.hareket_islendi == 1) {
                                buttons += '<button class="btn btn-secondary btn-sm" onclick="eslestirmeKaldir(' + row.hareket_id + ')" title="Eşleştirmeyi Kaldır"><i class="bi bi-x-circle"></i></button>';
                            } else {
                                buttons += '<button class="btn btn-primary btn-sm" onclick="eslestirModalAc(' + row.hareket_id + ')" title="Eşleştir"><i class="bi bi-link-45deg"></i></button>';
                            }
                            <?php endif; ?>
                            <?php if ($pagePermissions['can_delete']): ?>
                            buttons += '<button class="btn btn-danger btn-sm" onclick="deleteRecord(' + row.hareket_id + ')" title="Sil"><i class="bi bi-trash"></i></button>';
                            <?php endif; ?>
                            buttons += '</div>';
                            return buttons;
                        }
                    }
                ],
                order: [[1, 'desc']],
                pageLength: 25,
                language: {
                    url: '//cdn.datatables.net/plug-ins/1.13.7/i18n/tr.json'
                }
            });
        }
        
        // Detay göster
        function showDetail(id) {
            $.post('', { action: 'get', id: id }, function(response) {
                if (response.success && response.data) {
                    const d = response.data;
                    let html = '<div class="row">';
                    html += '<div class="col-md-6"><strong>Gönderen:</strong><br>' + (d.hareket_gonderen_ad || '-') + '</div>';
                    html += '<div class="col-md-6"><strong>IBAN:</strong><br><span class="iban-text">' + (d.hareket_iban || '-') + '</span></div>';
                    html += '</div><hr>';
                    
                    html += '<div class="row">';
                    html += '<div class="col-md-4"><strong>Tarih:</strong><br>' + formatDate(d.hareket_tarih) + '</div>';
                    html += '<div class="col-md-4"><strong>Tutar:</strong><br><span class="text-amount text-success">' + formatMoney(d.hareket_tutar, d.hareket_para_birimi) + '</span></div>';
                    html += '<div class="col-md-4"><strong>Para Birimi:</strong><br>' + (d.hareket_para_birimi || 'TRY') + '</div>';
                    html += '</div><hr>';
                    
                    html += '<div class="row">';
                    html += '<div class="col-md-4"><strong>Banka:</strong><br>' + (d.hareket_banka || '-') + '</div>';
                    html += '<div class="col-md-4"><strong>VKN/TC:</strong><br>' + (d.hareket_vkn_tc || '-') + '</div>';
                    html += '<div class="col-md-4"><strong>Referans:</strong><br><small>' + (d.hareket_referans || '-') + '</small></div>';
                    html += '</div><hr>';
                    
                    html += '<div class="row">';
                    html += '<div class="col-12"><strong>Açıklama:</strong><br>' + (d.hareket_aciklama || '-') + '</div>';
                    html += '</div><hr>';
                    
                    // Eşleşme bilgisi
                    html += '<div class="row">';
                    if (d.hareket_islendi == 1 && d.cari_ad) {
                        html += '<div class="col-md-4"><strong>Eşleşme:</strong><br><span class="badge bg-success">Eşleşti</span></div>';
                        html += '<div class="col-md-4"><strong>Cari:</strong><br>' + (d.cari_ad || '-') + '</div>';
                        html += '<div class="col-md-4"><strong>Sözleşme:</strong><br>' + (d.sozlesme_no || '-') + '</div>';
                    } else {
                        html += '<div class="col-12"><strong>Eşleşme:</strong><br><span class="badge bg-warning text-dark">Henüz eşleşmedi</span></div>';
                    }
                    html += '</div>';
                    
                    if (d.hareket_dekont_url) {
                        html += '<hr><div class="row">';
                        html += '<div class="col-12"><strong>Dekont:</strong><br><a href="' + d.hareket_dekont_url + '" target="_blank" class="btn btn-sm btn-outline-primary"><i class="bi bi-file-earmark-pdf"></i> Dekontu Görüntüle</a></div>';
                        html += '</div>';
                    }
                    
                    $('#detailContent').html(html);
                    detailModal.show();
                } else {
                    showToast('Kayıt bulunamadı!', 'error');
                }
            });
        }
        
        // Silme
        function deleteRecord(id) {
            confirmAction(
                'Bu hareketi silmek istediğinize emin misiniz?',
                'Bu işlem geri alınamaz!',
                function() {
                    $.post('', { action: 'delete', id: id }, function(response) {
                        if (response.success) {
                            showSuccess('Silindi!', response.message);
                            // false: bulunulan sayfa korunur, listenin başına dönülmez
                            table.ajax.reload(null, false);
                            loadStats();
                        } else {
                            showError('Hata!', response.message);
                        }
                    });
                }
            );
        }
        
        // ============ EŞLEŞTİRME FONKSİYONLARI ============
        
        // Eşleştirme modal aç
        function eslestirModalAc(hareketId) {
            $.post('', { action: 'get', id: hareketId }, function(response) {
                if (response.success && response.data) {
                    const d = response.data;
                    $('#eslestir_hareket_id').val(hareketId);
                    $('#eslestir_odeme_id').val('');
                    $('#btnEslestir').prop('disabled', true);

                    // Yeni ödeme paneli hareketin tutar ve tarihini kullanır
                    secilenHareket = d;
                    $('#yeniOdemePanel').hide();
                    $('#yeni_odeme_aciklama').val('');
                    $('#yeni_odeme_tutar').val(formatMoney(d.hareket_tutar, d.hareket_para_birimi));
                    $('#yeni_odeme_tarih').val(formatDate(d.hareket_tarih));
                    
                    $('#eslestir_hareket_bilgi').html(
                        '<strong>' + (d.hareket_gonderen_ad || '-') + '</strong> - ' +
                        '<span class="text-amount">' + formatMoney(d.hareket_tutar, d.hareket_para_birimi) + '</span>' +
                        ' | ' + formatDate(d.hareket_tarih) +
                        (d.hareket_vkn_tc ? ' | VKN/TC: ' + d.hareket_vkn_tc : '')
                    );
                    
                    secilenCariId = null;
                    secilenSozlesmeId = null;
                    secilenOdemeId = null;
                    $('#otomatikOnerilerSection').hide();
                    $('#otomatikOnerilerContent').empty();
                    eslestirmeAdimGoster(1);
                    $('#cari_select').val(null).trigger('change.select2');
                    
                    eslestirModal.show();
                    
                    // Otomatik eşleştirme önerilerini getir
                    $.post('', { action: 'auto_match', hareket_id: hareketId })
                    .done(function(matchResp) {
                        console.log('auto_match raw response:', matchResp);
                        // String gelirse parse et
                        if (typeof matchResp === 'string') {
                            try { matchResp = JSON.parse(matchResp); } catch(e) { console.error('JSON parse error:', e, matchResp); return; }
                        }
                        console.log('auto_match parsed:', matchResp);
                        if (matchResp.success && matchResp.data && matchResp.data.length > 0) {
                            renderOtomatikOneriler(matchResp.data);
                            $('#otomatikOnerilerSection').slideDown();
                            // Adım 1'i gizle çünkü öneriler var
                            $('#adim1').removeClass('active');
                        } else {
                            console.log('auto_match: öneri yok veya başarısız', matchResp);
                            // Öneri yoksa eski davranış: Select2 ön arama yap
                            const aramaMetni = d.hareket_vkn_tc || d.hareket_gonderen_ad || '';
                            if (aramaMetni.length >= 2) {
                                $.post('', { action: 'search_cari', query: aramaMetni }, function(resp) {
                                    if (resp.success && resp.data.length > 0) {
                                        $('#cari_select').empty().append('<option value="">Cari adı veya VKN ile arayın...</option>');
                                        resp.data.forEach(function(c) {
                                            const ad = c.cari_adi || c.cari_unvan || '-';
                                            const label = ad + (c.cari_unvan && c.cari_adi ? ' (' + c.cari_unvan + ')' : '') + (c.cari_vergi_no ? ' [VKN: ' + c.cari_vergi_no + ']' : '');
                                            $('#cari_select').append(new Option(label, c.cari_id, false, false));
                                        });
                                        $('#cari_select').trigger('change.select2');
                                        $('#cari_select').select2('open');
                                    }
                                });
                            }
                        }
                    })
                    .fail(function(xhr, status, error) {
                        console.error('auto_match AJAX error:', status, error, xhr.responseText);
                    });
                } else {
                    showToast('Hareket bilgisi alınamadı!', 'error');
                }
            });
        }
        
        function eslestirmeAdimGoster(adim) {
            $('.eslestirme-adim').removeClass('active');
            $('#adim' + adim).addClass('active');
        }
        
        function eslestirmeGeri(adim) {
            eslestirmeAdimGoster(adim);
            if (adim <= 1) { secilenCariId = null; }
            if (adim <= 2) { secilenSozlesmeId = null; secilenOdemeId = null; }
            $('#eslestir_odeme_id').val('');
            $('#btnEslestir').prop('disabled', true);
        }
        
        // cariAra kaldırıldı - Select2 AJAX kullanılıyor
        
        function sozlesmeleriYukle(cariId) {
            secilenCariId = cariId;
            $('#sozlesme_listesi').html('<div class="list-group-item text-center"><div class="spinner-border spinner-border-sm"></div> Yükleniyor...</div>');
            eslestirmeAdimGoster(2);
            
            $.post('', { action: 'get_sozlesmeler', cari_id: cariId }, function(response) {
                if (response.success && response.data.length > 0) {
                    let html = '';
                    response.data.forEach(function(s) {
                        html += '<a href="#" class="list-group-item list-group-item-action sozlesme-item" data-id="' + s.sozlesme_id + '">';
                        html += '<div class="d-flex justify-content-between">';
                        html += '<strong>' + (s.sozlesme_no || 'Sözleşme #' + s.sozlesme_id) + '</strong>';
                        html += '<span class="text-amount">' + formatMoney(s.sozlesme_toplam_tutar) + '</span>';
                        html += '</div>';
                        if (s.sozlesme_baslangic_tarihi) {
                            html += '<small class="text-muted">' + formatDate(s.sozlesme_baslangic_tarihi) + ' - ' + formatDate(s.sozlesme_bitis_tarihi) + '</small>';
                        }
                        html += '</a>';
                    });
                    $('#sozlesme_listesi').html(html);
                } else {
                    $('#sozlesme_listesi').html('<div class="list-group-item text-muted">Bu cariye ait sözleşme bulunamadı</div>');
                }
            });
        }
        
        function odemeleriYukle(sozlesmeId) {
            secilenSozlesmeId = sozlesmeId;
            $('#odeme_listesi').html('<div class="list-group-item text-center"><div class="spinner-border spinner-border-sm"></div> Yükleniyor...</div>');
            eslestirmeAdimGoster(3);
            
            $.post('', {
                action: 'get_odemeler',
                sozlesme_id: sozlesmeId,
                hareket_id: $('#eslestir_hareket_id').val()
            }, function(response) {
                if (response.success && response.data.length > 0) {
                    let html = '';
                    response.data.forEach(function(o) {
                        const yapıldı = o.odeme_yapildi == 1;
                        const bagli   = o.bagli_hareket_id ? true : false;
                        // Yalnızca başka harekete bağlı ödemeler kilitlidir. "Ödendi" işaretli
                        // ama bağlanmamış ödemeler seçilebilir; onay sorularak eşleştirilir.
                        const kilitli = bagli;
                        const birebir = o.birebir == 1;
                        const tarih   = o.odeme_tarih || o.odeme_vade_tarih;

                        html += '<a href="#" class="list-group-item list-group-item-action odeme-item'
                             + (kilitli ? ' disabled list-group-item-light text-muted' : '')
                             + (birebir ? ' border-success border-2' : '')
                             + '" data-id="' + o.odeme_id + '" data-kilitli="' + (kilitli ? '1' : '0') + '">';
                        html += '<div class="d-flex justify-content-between">';
                        html += '<span>' + (tarih ? formatDate(tarih) : '-');
                        if (birebir) {
                            html += ' <span class="badge bg-success"><i class="bi bi-magic"></i> Tutar ve tarih birebir</span>';
                        }
                        html += '</span>';
                        html += '<span class="text-amount">' + formatMoney(o.odeme_tutar) + '</span>';
                        html += '</div>';

                        if (bagli) {
                            html += '<small><i class="bi bi-link-45deg text-secondary"></i> Hareket #' + o.bagli_hareket_id
                                 + ' ile eşleşmiş (' + formatDate(o.bagli_hareket_tarih) + ')</small>';
                        } else if (yapıldı) {
                            html += '<small><i class="bi bi-exclamation-triangle text-warning"></i> Ödendi işaretli, harekete bağlı değil</small>';
                        } else {
                            html += '<small><i class="bi bi-clock text-warning"></i> Bekliyor</small>';
                        }

                        if (o.odeme_belge_no) html += ' <small class="text-muted">- ' + o.odeme_belge_no + '</small>';
                        if (o.odeme_tipi_ad)  html += ' <span class="badge bg-light text-dark">' + o.odeme_tipi_ad + '</span>';
                        html += '</a>';
                    });
                    // Birebir uyuşan ödeme yalnızca işaretlenir ve öne alınır;
                    // seçim her zaman kullanıcıya bırakılır.
                    $('#odeme_listesi').html(html);
                } else {
                    $('#odeme_listesi').html('<div class="list-group-item text-muted">Bu sözleşmeye ait ödeme bulunamadı</div>');
                }
            });
        }
        
        // Otomatik önerileri render et
        function renderOtomatikOneriler(oneriler) {
            let html = '';
            
            // Tip bazında grupla
            const gruplar = {};
            oneriler.forEach(function(o) {
                if (!gruplar[o.tip]) gruplar[o.tip] = [];
                gruplar[o.tip].push(o);
            });
            
            const tipIkonlari = {
                'vkn_tc': 'bi-person-vcard text-success',
                'ad_birebir': 'bi-person-check text-primary',
                'unvan_birebir': 'bi-building-check text-primary',
                'aciklama': 'bi-chat-left-text text-warning'
            };
            
            oneriler.forEach(function(o, idx) {
                const ikon = tipIkonlari[o.tip] || 'bi-question-circle text-secondary';
                const guvenRenk = o.guven >= 90 ? 'success' : (o.guven >= 70 ? 'warning' : 'secondary');
                const cariAd = o.cari_adi || o.cari_unvan || '-';
                
                html += '<div class="card mb-2 oneri-card" style="cursor:pointer;" onclick="oneriSec(' + o.cari_id + ', \'' + cariAd.replace(/'/g, "\\'") + '\')">';
                html += '<div class="card-body p-2">';
                html += '<div class="d-flex justify-content-between align-items-center">';
                html += '<div>';
                html += '<i class="bi ' + ikon + ' me-1"></i> ';
                html += '<strong>' + cariAd + '</strong>';
                if (o.cari_unvan && o.cari_adi) html += ' <small class="text-muted">(' + o.cari_unvan + ')</small>';
                if (o.cari_vergi_no) html += ' <small class="text-muted ms-1">[VKN: ' + o.cari_vergi_no + ']</small>';
                html += '<br><small class="text-muted"><i class="bi bi-arrow-right-short"></i> ' + o.tip_label;
                if (o.dosya_no) html += ' → <strong>' + o.dosya_no + '</strong>';
                html += '</small>';

                // Eşleştirilebilir ödeme bilgisi
                if (o.bagsiz_odeme === 0) {
                    html += '<br><small class="text-warning"><i class="bi bi-slash-circle"></i> '
                         + 'Eşleştirilebilir açık ödeme yok</small>';
                } else if (o.birebir > 0) {
                    html += '<br><small class="text-success"><i class="bi bi-magic"></i> '
                         + o.birebir + ' ödeme tutar ve tarih olarak birebir uyuşuyor</small>';
                } else if (o.ayni_tutar > 0) {
                    html += '<br><small class="text-success"><i class="bi bi-check2-circle"></i> '
                         + o.ayni_tutar + ' ödeme bu tutarla uyuşuyor (tarih farklı)</small>';
                } else {
                    html += '<br><small class="text-muted"><i class="bi bi-list-check"></i> '
                         + o.bagsiz_odeme + ' açık ödeme</small>';
                }

                html += '</div>';
                html += '<span class="badge bg-' + guvenRenk + '">%' + o.guven + '</span>';
                html += '</div>';
                html += '</div></div>';
            });
            
            $('#otomatikOnerilerContent').html(html);
        }
        
        // Öneri seçildiğinde cariyi seç ve adım 2'ye geç
        function oneriSec(cariId, cariAd) {
            secilenCariId = cariId;
            $('#otomatikOnerilerSection').slideUp();
            $('#secilen_cari_bilgi').text('Cari: ' + cariAd);
            sozlesmeleriYukle(cariId);
        }
        
        /**
         * Eşleştirme isteği. Mükerrer kontrolleri sunucu tarafında uygulanır.
         */
        function eslestirGonder(hareketId, odemeId) {
            $.post('', {
                action: 'eslestir',
                hareket_id: hareketId,
                odeme_id: odemeId
            }, function(response) {
                if (response.success) {
                    showSuccess('Eşleştirildi!', response.message);
                    eslestirModal.hide();
                    // false: bulunulan sayfa korunur, listenin başına dönülmez
                    table.ajax.reload(null, false);
                    loadStats();
                    return;
                }

                showError('Eşleştirme yapılmadı', response.message);
            });
        }

        function eslestirmeKaldir(hareketId) {
            confirmAction(
                'Eşleştirmeyi kaldırmak istediğinize emin misiniz?',
                'Hareket tekrar "Eşleşmeyen" durumuna alınacak.',
                function() {
                    $.post('', { action: 'eslestirme_kaldir', hareket_id: hareketId }, function(response) {
                        if (response.success) {
                            showSuccess('Kaldırıldı!', response.message);
                            // false: bulunulan sayfa korunur, listenin başına dönülmez
                            table.ajax.reload(null, false);
                            loadStats();
                        } else {
                            showError('Hata!', response.message);
                        }
                    });
                }
            );
        }
        
        // ============ SAYFA HAZIR ============
        $(document).ready(function() {
            loadStats();
            initDataTable();
            
            // Select2 başlat
            $('#filter_iban').select2({
                theme: 'bootstrap-5',
                width: '100%',
                placeholder: 'IBAN Seçin...',
                allowClear: true
            });
            $('#filter_banka').select2({
                theme: 'bootstrap-5',
                width: '100%',
                placeholder: 'Banka Seçin...',
                allowClear: true
            });
            
            // Cari Select2 (AJAX arama)
            $('#cari_select').select2({
                theme: 'bootstrap-5',
                width: '100%',
                placeholder: 'Cari adı veya VKN ile arayın...',
                allowClear: true,
                dropdownParent: $('#eslestirModal'),
                minimumInputLength: 2,
                language: {
                    noResults: function() { return 'Sonuç bulunamadı'; },
                    searching: function() { return 'Aranıyor...'; },
                    inputTooShort: function() { return 'En az 2 karakter girin...'; }
                },
                ajax: {
                    url: '',
                    type: 'POST',
                    dataType: 'json',
                    delay: 400,
                    data: function(params) {
                        return { action: 'search_cari', query: params.term };
                    },
                    processResults: function(response) {
                        if (response.success) {
                            return {
                                results: response.data.map(function(c) {
                                    const ad = c.cari_adi || c.cari_unvan || '-';
                                    const label = ad + (c.cari_unvan && c.cari_adi ? ' (' + c.cari_unvan + ')' : '') + (c.cari_vergi_no ? ' [VKN: ' + c.cari_vergi_no + ']' : '');
                                    return { id: c.cari_id, text: label };
                                })
                            };
                        }
                        return { results: [] };
                    },
                    cache: true
                }
            });
            
            // Cari seçildiğinde sözleşmeleri yükle
            $('#cari_select').on('select2:select', function(e) {
                const cariId = e.params.data.id;
                $('#secilen_cari_bilgi').text('Cari: ' + e.params.data.text);
                sozlesmeleriYukle(cariId);
            });
            
            // Sözleşme seçimi
            $(document).on('click', '.sozlesme-item', function(e) {
                e.preventDefault();
                $('.sozlesme-item').removeClass('selected');
                $(this).addClass('selected');
                const sozlesmeId = $(this).data('id');
                $('#secilen_sozlesme_bilgi').text('Sözleşme: ' + $(this).find('strong').text().trim());
                odemeleriYukle(sozlesmeId);
            });
            
            // Ödeme seçimi
            $(document).on('click', '.odeme-item', function(e) {
                e.preventDefault();

                if ($(this).data('kilitli') == '1') {
                    showToast('Bu ödeme zaten eşleştirilmiş veya ödendi olarak işaretli.', 'warning');
                    return;
                }

                $('.odeme-item').removeClass('selected');
                $(this).addClass('selected');
                secilenOdemeId = $(this).data('id');
                $('#eslestir_odeme_id').val(secilenOdemeId);
                $('#btnEslestir').prop('disabled', false);
            });

            // Portaldan güncelle
            $('#btnSenkronize').on('click', function() {
                const buton = $(this);
                const eski  = buton.html();

                buton.prop('disabled', true)
                     .html('<span class="spinner-border spinner-border-sm"></span> Güncelleniyor...');
                $('#senkronBilgi').text('');

                $.post('', { action: 'senkronize' }, function(response) {
                    if (response.success) {
                        const eklenen = parseInt(response.eklenen || 0, 10);

                        if (eklenen > 0) {
                            showSuccess('Güncellendi!', response.message);
                            table.ajax.reload(null, false);
                            loadStats();
                        } else {
                            showToast('Yeni hareket bulunamadı.', 'info');
                        }

                        $('#senkronBilgi').text('Son güncelleme: ' + new Date().toLocaleTimeString('tr-TR'));
                    } else {
                        showError('Güncellenemedi', response.message);
                    }
                }).fail(function() {
                    showError('Güncellenemedi', 'Sunucuya ulaşılamadı veya işlem zaman aşımına uğradı.');
                }).always(function() {
                    buton.prop('disabled', false).html(eski);
                });
            });

            // Yeni ödeme panelini aç/kapat
            $('#btnYeniOdemeAc').on('click', function() {
                $('#yeniOdemePanel').slideToggle(150);
            });

            // Yeni ödeme oluştur ve eşleştir
            $('#btnYeniOdemeKaydet').on('click', function() {
                const hareketId = $('#eslestir_hareket_id').val();

                if (!hareketId || !secilenSozlesmeId) {
                    showToast('Önce sözleşme seçmelisiniz!', 'warning');
                    return;
                }

                const buton = $(this);
                buton.prop('disabled', true);

                $.post('', {
                    action: 'yeni_odeme_eslestir',
                    hareket_id: hareketId,
                    sozlesme_id: secilenSozlesmeId,
                    aciklama: $('#yeni_odeme_aciklama').val()
                }, function(response) {
                    buton.prop('disabled', false);

                    if (response.success) {
                        showSuccess('Oluşturuldu!', response.message);
                        eslestirModal.hide();
                        table.ajax.reload(null, false);
                        loadStats();
                    } else {
                        showError('İşlem yapılmadı', response.message);
                    }
                }).fail(function() {
                    buton.prop('disabled', false);
                    showToast('İstek başarısız oldu.', 'error');
                });
            });

            // Eşleştir butonu
            $('#btnEslestir').on('click', function() {
                const hareketId = $('#eslestir_hareket_id').val();
                const odemeId = $('#eslestir_odeme_id').val();

                if (!hareketId || !odemeId) {
                    showToast('Lütfen bir ödeme seçin!', 'warning');
                    return;
                }

                eslestirGonder(hareketId, odemeId);
            });
            
            // Eşleştirme modal kapatıldığında temizle
            $('#eslestirModal').on('hidden.bs.modal', function() {
                secilenCariId = null;
                secilenSozlesmeId = null;
                secilenOdemeId = null;
                secilenHareket = null;
                $('#cari_select').val(null).trigger('change.select2');
                $('#sozlesme_listesi').empty();
                $('#odeme_listesi').empty();
                $('#yeniOdemePanel').hide();
                $('#yeni_odeme_aciklama').val('');
                $('#otomatikOnerilerSection').hide();
                $('#otomatikOnerilerContent').empty();
                eslestirmeAdimGoster(1);
            });
            
            // Sidebar toggle
            $('[data-lte-toggle="sidebar"]').on('click', function() {
                setTimeout(function() {
                    if (table) {
                        $(window).trigger('resize');
                        table.columns.adjust().draw();
                    }
                }, 350);
            });
            
            // Filtre form submit
            $('#filterForm').on('submit', function(e) {
                e.preventDefault();
                currentFilters = {
                    start_date: $('#filter_start_date').val(),
                    end_date: $('#filter_end_date').val(),
                    search_text: $('#filter_search').val(),
                    iban: $('#filter_iban').val(),
                    banka: $('#filter_banka').val(),
                    islendi: $('#filter_islendi').val()
                };
                Object.keys(currentFilters).forEach(key => {
                    if (!currentFilters[key] && currentFilters[key] !== '0') delete currentFilters[key];
                });
                table.ajax.reload();
                showToast('Filtre uygulandı', 'info');
            });
            
            // Filtreleri temizle
            $('#clearFilters').on('click', function() {
                $('#filterForm')[0].reset();
                $('#filter_iban').val('').trigger('change.select2');
                $('#filter_banka').val('').trigger('change.select2');
                $('#filter_islendi').val('').trigger('change.select2');
                currentFilters = {};
                table.ajax.reload();
                showToast('Filtreler temizlendi', 'info');
            });
        });
    </script>
</body>
</html>
