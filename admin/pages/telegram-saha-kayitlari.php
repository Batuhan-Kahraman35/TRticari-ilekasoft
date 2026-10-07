<?php
/**
 * Telegram Saha Kayıtları
 *
 * Teklif / Satış / Tahsilat kayıtları tek tabloda (Telegram_Kayitlar) tutulur.
 * Tipler ve her tipte görünecek alanlar tanim_telegram_kayit_tipleri tablosundan
 * okunur; yeni tip eklemek için kod değişikliği gerekmez.
 *
 * Konum bilgisi bu tabloda tutulmaz; Telegram_Konumlar tablosunda
 * Telegram_Konumlar_KayitId ile kayda bağlanır.
 *
 * Canlı sistem tablolarına (Sozlesmeler / Sozlesme_Odemeler) yazılmaz.
 */

require_once __DIR__ . '/../auth.php';
require_once __DIR__ . '/../includes/PageAuth.php';
requireAuth();

$user = Auth::user();
$db   = Database::getInstance();

$currentPageFile = basename($_SERVER['PHP_SELF']);
$pagePermissions = PageAuth::checkPagePermissions(
    $user['kullanici_id'],
    $user['departman_id'],
    $currentPageFile
);

if (!$pagePermissions['has_access']) {
    PageAuth::accessDenied($pagePermissions['error'] ?? 'Bu sayfaya erişim yetkiniz bulunmamaktadır.');
}

// Görsel yükleme
const GORSEL_DIZIN     = __DIR__ . '/../assets/uploads/';
const GORSEL_WEB_YOL   = '/admin/assets/uploads/';
const GORSEL_MAX_BOYUT = 8388608; // 8 MB
const GORSEL_UZANTILAR = ['jpg', 'jpeg', 'png', 'webp', 'heic'];

/** Yalnız kendi kayıtlarını görebilen kullanıcı için WHERE parçası */
function kendiKisitWhere(array $izin, int $kullaniciId): array
{
    if (empty($izin['can_view_own_records'])) {
        return ['', []];
    }
    return ['t.Telegram_Kayitlar_PersonelId = ?', [$kullaniciId]];
}

/** Gönderilen görselleri kaydeder, dosya adlarını döner */
function gorselleriYukle(array $files): array
{
    if (empty($files['name'][0])) {
        return [];
    }

    if (!is_dir(GORSEL_DIZIN)) {
        mkdir(GORSEL_DIZIN, 0755, true);
    }

    $kaydedilen = [];
    foreach ($files['tmp_name'] as $i => $tmp) {
        if ($files['error'][$i] !== UPLOAD_ERR_OK || $tmp === '') {
            continue;
        }
        if ($files['size'][$i] > GORSEL_MAX_BOYUT) {
            continue;
        }

        $uzanti = strtolower(pathinfo($files['name'][$i], PATHINFO_EXTENSION));
        if (!in_array($uzanti, GORSEL_UZANTILAR, true)) {
            continue;
        }

        $yeniAd = uniqid('tgsaha_', true) . '.' . $uzanti;
        if (move_uploaded_file($tmp, GORSEL_DIZIN . $yeniAd)) {
            $kaydedilen[] = $yeniAd;
        }
    }

    return $kaydedilen;
}

// ==================== AJAX ====================
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json; charset=utf-8');
    $action = $_POST['action'] ?? '';

    try {
        switch ($action) {

            // ---------- InfoBox sayımları (tablo sorgusundan ayrı) ----------
            case 'stats': {
                [$kisitW, $kisitP] = kendiKisitWhere($pagePermissions, (int)$user['kullanici_id']);
                $where  = ['t.Durum = 1'];
                $params = [];
                if ($kisitW !== '') {
                    $where[]  = $kisitW;
                    $params   = array_merge($params, $kisitP);
                }
                $w = implode(' AND ', $where);

                $satirlar = $db->fetchAll("
                    SELECT tp.tanim_telegram_kayit_tipleri_Kod AS kod,
                           COUNT(*)                            AS adet,
                           ISNULL(SUM(t.Telegram_Kayitlar_Tutar), 0) AS tutar
                    FROM Telegram_Kayitlar t
                    INNER JOIN tanim_telegram_kayit_tipleri tp
                            ON tp.tanim_telegram_kayit_tipleri_id = t.Telegram_Kayitlar_TipId
                    WHERE $w
                    GROUP BY tp.tanim_telegram_kayit_tipleri_Kod
                ", $params);

                $stats = ['toplam' => 0];
                foreach ($satirlar as $s) {
                    $stats[$s['kod']]            = (int)$s['adet'];
                    $stats[$s['kod'] . '_tutar'] = (float)$s['tutar'];
                    $stats['toplam']            += (int)$s['adet'];
                }

                echo json_encode(['success' => true, 'data' => $stats]);
                break;
            }

            // ---------- Server-side liste ----------
            case 'list': {
                $draw   = (int)($_POST['draw'] ?? 1);
                $start  = max(0, (int)($_POST['start'] ?? 0));
                $length = (int)($_POST['length'] ?? 25);
                if ($length < 1 || $length > 200) {
                    $length = 25;
                }

                // Temel kısıt (yetki)
                [$kisitW, $kisitP] = kendiKisitWhere($pagePermissions, (int)$user['kullanici_id']);
                $whereBase  = ['t.Durum = 1'];
                $paramsBase = [];
                if ($kisitW !== '') {
                    $whereBase[] = $kisitW;
                    $paramsBase  = array_merge($paramsBase, $kisitP);
                }

                // recordsTotal — JOIN'siz, tek ana tablodan
                $wBase = implode(' AND ', $whereBase);
                $recordsTotal = (int)($db->fetchOne(
                    "SELECT COUNT(*) AS s FROM Telegram_Kayitlar t WHERE $wBase",
                    $paramsBase
                )['s'] ?? 0);

                // Kullanıcı filtreleri
                $whereFilt  = $whereBase;
                $paramsFilt = $paramsBase;

                if (!empty($_POST['tip_id'])) {
                    $whereFilt[]  = 't.Telegram_Kayitlar_TipId = ?';
                    $paramsFilt[] = (int)$_POST['tip_id'];
                }
                if (!empty($_POST['start_date'])) {
                    $whereFilt[]  = 't.Telegram_Kayitlar_Tarih >= ?';
                    $paramsFilt[] = $_POST['start_date'];
                }
                if (!empty($_POST['end_date'])) {
                    $whereFilt[]  = 't.Telegram_Kayitlar_Tarih <= ?';
                    $paramsFilt[] = $_POST['end_date'];
                }
                if (!empty($_POST['personel_id'])) {
                    $whereFilt[]  = 't.Telegram_Kayitlar_PersonelId = ?';
                    $paramsFilt[] = (int)$_POST['personel_id'];
                }
                if (!empty($_POST['sehir'])) {
                    $whereFilt[]  = 't.Telegram_Kayitlar_Sehir LIKE ?';
                    $paramsFilt[] = '%' . $_POST['sehir'] . '%';
                }
                if (!empty($_POST['para_birimi'])) {
                    $whereFilt[]  = 't.Telegram_Kayitlar_ParaBirimi = ?';
                    $paramsFilt[] = $_POST['para_birimi'];
                }
                if (!empty($_POST['kaynak'])) {
                    $whereFilt[]  = 't.Telegram_Kayitlar_Kaynak = ?';
                    $paramsFilt[] = $_POST['kaynak'];
                }
                if (isset($_POST['aktarildi']) && $_POST['aktarildi'] !== '') {
                    $whereFilt[] = $_POST['aktarildi'] === '1'
                        ? 't.Telegram_Kayitlar_AktarilanId IS NOT NULL'
                        : 't.Telegram_Kayitlar_AktarilanId IS NULL';
                }
                if (!empty($_POST['search_text'])) {
                    $whereFilt[]  = '(t.Telegram_Kayitlar_IsletmeAdi LIKE ? OR t.Telegram_Kayitlar_Aciklama LIKE ?)';
                    $ara          = '%' . $_POST['search_text'] . '%';
                    $paramsFilt[] = $ara;
                    $paramsFilt[] = $ara;
                }

                $wFilt = implode(' AND ', $whereFilt);
                $recordsFiltered = (int)($db->fetchOne(
                    "SELECT COUNT(*) AS s FROM Telegram_Kayitlar t WHERE $wFilt",
                    $paramsFilt
                )['s'] ?? 0);

                // Sıralama — kolon index whitelist ile çözülür
                $orderMap = [
                    0 => 't.Telegram_Kayitlar_Tarih',
                    1 => 't.Telegram_Kayitlar_TipId',
                    2 => 't.Telegram_Kayitlar_IsletmeAdi',
                    3 => 't.Telegram_Kayitlar_Sehir',
                    4 => 't.Telegram_Kayitlar_Tutar',
                    5 => 't.Telegram_Kayitlar_PersonelId',
                    6 => 't.OlusturmaTarihi',
                ];
                $orderIdx = (int)($_POST['order'][0]['column'] ?? 0);
                $orderBy  = $orderMap[$orderIdx] ?? 't.Telegram_Kayitlar_Tarih';
                $orderDir = strtolower($_POST['order'][0]['dir'] ?? 'desc') === 'asc' ? 'ASC' : 'DESC';

                $data = [];
                if ($recordsFiltered > 0) {
                    // 1) Ucuz CTE: yalnız filtre + sıralama + sayfalama
                    // 2) Dış SELECT: JOIN'ler ve konum alt sorgusu yalnız bu N satıra
                    $data = $db->fetchAll("
                        WITH Sayfa AS (
                            SELECT t.Telegram_Kayitlar_id
                            FROM Telegram_Kayitlar t
                            WHERE $wFilt
                            ORDER BY $orderBy $orderDir, t.Telegram_Kayitlar_id DESC
                            OFFSET $start ROWS FETCH NEXT $length ROWS ONLY
                        )
                        SELECT
                            t.Telegram_Kayitlar_id                AS id,
                            CONVERT(VARCHAR(10), t.Telegram_Kayitlar_Tarih, 120) AS tarih,
                            tp.tanim_telegram_kayit_tipleri_Kod   AS tip_kod,
                            tp.tanim_telegram_kayit_tipleri_Ad    AS tip_adi,
                            tp.tanim_telegram_kayit_tipleri_Renk  AS tip_renk,
                            tp.tanim_telegram_kayit_tipleri_Ikon  AS tip_ikon,
                            t.Telegram_Kayitlar_ParaBirimi        AS para_birimi,
                            t.Telegram_Kayitlar_Sehir             AS sehir,
                            t.Telegram_Kayitlar_Ilce              AS ilce,
                            t.Telegram_Kayitlar_CariId            AS cari_id,
                            c.cari_adi                            AS cari_adi,
                            t.Telegram_Kayitlar_IsletmeAdi        AS isletme_adi,
                            t.Telegram_Kayitlar_Tutar             AS tutar,
                            t.Telegram_Kayitlar_MusteriBeklentisi AS musteri_beklentisi,
                            t.Telegram_Kayitlar_Gorseller         AS gorseller,
                            t.Telegram_Kayitlar_Kaynak            AS kaynak,
                            t.Telegram_Kayitlar_AktarilanId       AS aktarilan_id,
                            k.kullanici_ad                        AS personel_ad,
                            k.kullanici_soyad                     AS personel_soyad,
                            kn.Telegram_Konumlar_Enlem            AS enlem,
                            kn.Telegram_Konumlar_Boylam           AS boylam,
                            CONVERT(VARCHAR(19), t.OlusturmaTarihi, 120) AS olusturma_tarihi
                        FROM Sayfa s
                        INNER JOIN Telegram_Kayitlar t
                                ON t.Telegram_Kayitlar_id = s.Telegram_Kayitlar_id
                        INNER JOIN tanim_telegram_kayit_tipleri tp
                                ON tp.tanim_telegram_kayit_tipleri_id = t.Telegram_Kayitlar_TipId
                        LEFT JOIN kullanicilar k
                               ON k.kullanici_id = t.Telegram_Kayitlar_PersonelId
                        LEFT JOIN Cari c
                               ON c.cari_id = t.Telegram_Kayitlar_CariId
                        OUTER APPLY (
                            SELECT TOP 1 x.Telegram_Konumlar_Enlem, x.Telegram_Konumlar_Boylam
                            FROM Telegram_Konumlar x
                            WHERE x.Telegram_Konumlar_KayitId = t.Telegram_Kayitlar_id
                            ORDER BY x.Telegram_Konumlar_id DESC
                        ) kn
                        ORDER BY $orderBy $orderDir, t.Telegram_Kayitlar_id DESC
                    ", $paramsFilt);
                }

                echo json_encode([
                    'draw'            => $draw,
                    'recordsTotal'    => $recordsTotal,
                    'recordsFiltered' => $recordsFiltered,
                    'data'            => $data,
                ]);
                break;
            }

            // ---------- Tek kayıt ----------
            case 'get': {
                $id = (int)($_POST['id'] ?? 0);

                [$kisitW, $kisitP] = kendiKisitWhere($pagePermissions, (int)$user['kullanici_id']);
                $where  = ['t.Telegram_Kayitlar_id = ?'];
                $params = [$id];
                if ($kisitW !== '') {
                    $where[] = $kisitW;
                    $params  = array_merge($params, $kisitP);
                }
                $w = implode(' AND ', $where);

                $kayit = $db->fetchOne("
                    SELECT t.*, c.cari_adi
                    FROM Telegram_Kayitlar t
                    LEFT JOIN Cari c ON c.cari_id = t.Telegram_Kayitlar_CariId
                    WHERE $w
                ", $params);

                if (!$kayit) {
                    echo json_encode(['success' => false, 'message' => 'Kayıt bulunamadı.']);
                    break;
                }

                $kayit['gorsel_listesi'] = json_decode($kayit['Telegram_Kayitlar_Gorseller'] ?? '[]', true) ?: [];
                $kayit['gorsel_yolu']    = GORSEL_WEB_YOL;

                echo json_encode(['success' => true, 'data' => $kayit]);
                break;
            }

            // ---------- Kaydet ----------
            case 'save': {
                $id = (int)($_POST['id'] ?? 0);

                if ($id > 0 && !$pagePermissions['can_edit']) {
                    echo json_encode(['success' => false, 'message' => 'Düzenleme yetkiniz yok!']);
                    break;
                }
                if ($id === 0 && !$pagePermissions['can_add']) {
                    echo json_encode(['success' => false, 'message' => 'Ekleme yetkiniz yok!']);
                    break;
                }

                $tipId = (int)($_POST['tip_id'] ?? 0);
                if ($tipId <= 0) {
                    echo json_encode(['success' => false, 'message' => 'Kayıt tipi seçilmelidir.']);
                    break;
                }

                $tip = $db->fetchOne("
                    SELECT tanim_telegram_kayit_tipleri_Kod AS kod
                    FROM tanim_telegram_kayit_tipleri
                    WHERE tanim_telegram_kayit_tipleri_id = ? AND Durum = 1
                ", [$tipId]);
                if (!$tip) {
                    echo json_encode(['success' => false, 'message' => 'Geçersiz kayıt tipi.']);
                    break;
                }

                $isletmeAdi = trim($_POST['isletme_adi'] ?? '');
                $cariId     = !empty($_POST['cari_id']) ? (int)$_POST['cari_id'] : null;

                if ($isletmeAdi === '' && $cariId === null) {
                    echo json_encode(['success' => false, 'message' => 'İşletme adı girilmeli veya cari seçilmelidir.']);
                    break;
                }

                $veri = [
                    'Telegram_Kayitlar_TipId'             => $tipId,
                    'Telegram_Kayitlar_Tarih'             => $_POST['tarih'] ?: date('Y-m-d'),
                    'Telegram_Kayitlar_ParaBirimi'        => trim($_POST['para_birimi'] ?? '') ?: null,
                    'Telegram_Kayitlar_Sehir'             => trim($_POST['sehir'] ?? '') ?: null,
                    'Telegram_Kayitlar_Ilce'              => trim($_POST['ilce'] ?? '') ?: null,
                    'Telegram_Kayitlar_CariId'            => $cariId,
                    'Telegram_Kayitlar_IsletmeAdi'        => $isletmeAdi ?: null,
                    'Telegram_Kayitlar_Tutar'             => ($_POST['tutar'] ?? '') !== '' ? (float)$_POST['tutar'] : null,
                    'Telegram_Kayitlar_MusteriBeklentisi' => ($_POST['musteri_beklentisi'] ?? '') !== '' ? (float)$_POST['musteri_beklentisi'] : null,
                    'Telegram_Kayitlar_Aciklama'          => trim($_POST['aciklama'] ?? '') ?: null,
                ];

                // Görseller — mevcutlara ekleme yapılır
                $mevcutGorseller = [];
                if ($id > 0) {
                    $eski = $db->fetchOne(
                        "SELECT Telegram_Kayitlar_Gorseller AS g FROM Telegram_Kayitlar WHERE Telegram_Kayitlar_id = ?",
                        [$id]
                    );
                    $mevcutGorseller = json_decode($eski['g'] ?? '[]', true) ?: [];
                }

                // Kullanıcının silmek istedikleri
                $silinecek = json_decode($_POST['silinen_gorseller'] ?? '[]', true) ?: [];
                if ($silinecek) {
                    $mevcutGorseller = array_values(array_diff($mevcutGorseller, $silinecek));
                    foreach ($silinecek as $dosya) {
                        $yol = GORSEL_DIZIN . basename($dosya);
                        if (is_file($yol)) {
                            @unlink($yol);
                        }
                    }
                }

                if (!empty($_FILES['gorseller'])) {
                    $mevcutGorseller = array_merge($mevcutGorseller, gorselleriYukle($_FILES['gorseller']));
                }
                $veri['Telegram_Kayitlar_Gorseller'] = $mevcutGorseller
                    ? json_encode(array_values($mevcutGorseller), JSON_UNESCAPED_UNICODE)
                    : null;

                if ($id > 0) {
                    $veri['GuncelleyenKullanici'] = $user['kullanici_id'];
                    $veri['GuncellemeTarihi']     = date('Y-m-d H:i:s');
                    $db->update('Telegram_Kayitlar', $veri, ['Telegram_Kayitlar_id' => $id]);
                    echo json_encode(['success' => true, 'message' => 'Kayıt güncellendi.']);
                } else {
                    $veri['Telegram_Kayitlar_PersonelId'] = $user['kullanici_id'];
                    $veri['Telegram_Kayitlar_Kaynak']     = 'panel';
                    $veri['OlusturanKullanici']           = $user['kullanici_id'];
                    $veri['OlusturmaTarihi']              = date('Y-m-d H:i:s');
                    $veri['Durum']                        = 1;
                    $db->insert('Telegram_Kayitlar', $veri);
                    echo json_encode(['success' => true, 'message' => 'Kayıt eklendi.']);
                }
                break;
            }

            // ---------- Sil (soft delete) ----------
            case 'delete': {
                if (!$pagePermissions['can_delete']) {
                    echo json_encode(['success' => false, 'message' => 'Silme yetkiniz yok!']);
                    break;
                }

                $id = (int)($_POST['id'] ?? 0);
                $db->update('Telegram_Kayitlar', [
                    'Durum'                => 0,
                    'GuncelleyenKullanici' => $user['kullanici_id'],
                    'GuncellemeTarihi'     => date('Y-m-d H:i:s'),
                ], ['Telegram_Kayitlar_id' => $id]);

                echo json_encode(['success' => true, 'message' => 'Kayıt silindi.']);
                break;
            }

            // ---------- Cari arama (Select2 remote) ----------
            case 'cari_ara': {
                $terim = trim($_POST['q'] ?? '');
                $sql   = "SELECT TOP 30 cari_id, cari_adi FROM Cari WHERE cari_aktif = 1";
                $params = [];
                if ($terim !== '') {
                    $sql .= " AND cari_adi LIKE ?";
                    $params[] = '%' . $terim . '%';
                }
                $sql .= " ORDER BY cari_adi";

                echo json_encode(['success' => true, 'data' => $db->fetchAll($sql, $params)]);
                break;
            }

            // ---------- Kayda bağlı konum geçmişi ----------
            case 'konum': {
                $id = (int)($_POST['id'] ?? 0);
                $konumlar = $db->fetchAll("
                    SELECT ot.tanim_telegram_konum_olay_tipleri_Ad   AS olay_adi,
                           ot.tanim_telegram_konum_olay_tipleri_Renk AS olay_renk,
                           kn.Telegram_Konumlar_Enlem   AS enlem,
                           kn.Telegram_Konumlar_Boylam  AS boylam,
                           kn.Telegram_Konumlar_Dogruluk AS dogruluk,
                           kn.Telegram_Konumlar_Adres   AS adres,
                           CONVERT(VARCHAR(19), kn.OlusturmaTarihi, 120) AS tarih
                    FROM Telegram_Konumlar kn
                    INNER JOIN tanim_telegram_konum_olay_tipleri ot
                            ON ot.tanim_telegram_konum_olay_tipleri_id = kn.Telegram_Konumlar_OlayTipiId
                    WHERE kn.Telegram_Konumlar_KayitId = ? AND kn.Durum = 1
                    ORDER BY kn.Telegram_Konumlar_id DESC
                ", [$id]);

                echo json_encode(['success' => true, 'data' => $konumlar]);
                break;
            }

            default:
                echo json_encode(['success' => false, 'message' => 'Geçersiz işlem!']);
        }
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'message' => 'Hata: ' . $e->getMessage()]);
    }
    exit;
}

// ==================== SAYFA VERİLERİ ====================
$pageInfo = $db->fetchOne("
    SELECT s.sayfalar_sayfa_adi, s.sayfalar_aciklama, m.menuler_menu_adi AS menu_adi
    FROM Menu_Sayfalar s
    LEFT JOIN Menuler m ON s.sayfalar_menu_id = m.menuler_id
    WHERE s.sayfalar_sayfa_url LIKE ? AND s.sayfalar_durum = 1
", ['%' . $currentPageFile]);

$pageTitle       = $pageInfo['sayfalar_sayfa_adi'] ?? 'Telegram Saha Kayıtları';
$pageDescription = $pageInfo['sayfalar_aciklama'] ?? '';
$menuAdi         = $pageInfo['menu_adi'] ?? null;

$siteAyarlari = $db->fetchOne("SELECT TOP 1 site_ayarlari_site_title FROM dbo.tanim_site_ayarlari ORDER BY site_ayarlari_id DESC");
$siteTitle    = $siteAyarlari['site_ayarlari_site_title'] ?? 'Örnek Yazılım Portal';

// Kayıt tipleri (alan görünürlüğü JSON'dan)
$tipler = $db->fetchAll("
    SELECT tanim_telegram_kayit_tipleri_id          AS id,
           tanim_telegram_kayit_tipleri_Kod         AS kod,
           tanim_telegram_kayit_tipleri_Ad          AS ad,
           tanim_telegram_kayit_tipleri_Renk        AS renk,
           tanim_telegram_kayit_tipleri_Ikon        AS ikon,
           tanim_telegram_kayit_tipleri_TutarEtiket AS tutar_etiket,
           tanim_telegram_kayit_tipleri_Alanlar     AS alanlar
    FROM tanim_telegram_kayit_tipleri
    WHERE Durum = 1
    ORDER BY tanim_telegram_kayit_tipleri_Sira, tanim_telegram_kayit_tipleri_id
");
foreach ($tipler as &$t) {
    $t['alanlar'] = json_decode($t['alanlar'] ?? '[]', true) ?: [];
}
unset($t);

// Para birimleri — Adres_Ulkeler'e ülke eklendikçe otomatik listeye düşer
$paraBirimleri = $db->fetchAll("
    SELECT DISTINCT ParaBirimi AS kod, ParaBirimiSimge AS simge
    FROM Adres_Ulkeler
    WHERE ParaBirimi IS NOT NULL AND LTRIM(RTRIM(ParaBirimi)) <> ''
    ORDER BY ParaBirimi
");

// Filtre için personel listesi (yalnız kayıt girmiş olanlar)
$personeller = $db->fetchAll("
    SELECT DISTINCT k.kullanici_id AS id, k.kullanici_ad AS ad, k.kullanici_soyad AS soyad
    FROM Telegram_Kayitlar t
    INNER JOIN kullanicilar k ON k.kullanici_id = t.Telegram_Kayitlar_PersonelId
    WHERE t.Durum = 1
    ORDER BY k.kullanici_ad, k.kullanici_soyad
");

// Şehir/ilçe datalist önerileri (daha önce girilmiş değerler)
$sehirOnerileri = $db->fetchAll("
    SELECT DISTINCT Telegram_Kayitlar_Sehir AS deger
    FROM Telegram_Kayitlar
    WHERE Telegram_Kayitlar_Sehir IS NOT NULL AND LTRIM(RTRIM(Telegram_Kayitlar_Sehir)) <> ''
    ORDER BY Telegram_Kayitlar_Sehir
");
$ilceOnerileri = $db->fetchAll("
    SELECT DISTINCT Telegram_Kayitlar_Ilce AS deger
    FROM Telegram_Kayitlar
    WHERE Telegram_Kayitlar_Ilce IS NOT NULL AND LTRIM(RTRIM(Telegram_Kayitlar_Ilce)) <> ''
    ORDER BY Telegram_Kayitlar_Ilce
");
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
    <link href="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/select2-bootstrap-5-theme@1.3.0/dist/select2-bootstrap-5-theme.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/sweetalert2@11/dist/sweetalert2.min.css" rel="stylesheet">
    <link href="https://cdn.datatables.net/1.13.7/css/dataTables.bootstrap5.min.css" rel="stylesheet">
    <link rel="stylesheet" href="/admin/assets/css/custom.css">
    <style>
        .info-box { transition: transform .2s; }
        .info-box:hover { transform: translateY(-5px); box-shadow: 0 4px 6px rgba(0,0,0,.1); }
        .badge { font-size: .75rem; padding: .35em .65em; }
        .modal-body .form-label { font-weight: 500; margin-bottom: .3rem; }

        /* Görsel önizleme */
        .gorsel-kutu { position: relative; display: inline-block; margin: 0 .5rem .5rem 0; }
        .gorsel-kutu img { width: 90px; height: 90px; object-fit: cover; border-radius: .375rem; border: 1px solid var(--bs-border-color); }
        .gorsel-kutu .btn-sil { position: absolute; top: -6px; right: -6px; padding: 0 .35rem; line-height: 1.2; border-radius: 50%; }

        /* Tip sekmeleri */
        #tipSekmeleri .nav-link { cursor: pointer; }
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
                        <?php if ($pageDescription): ?>
                            <small class="text-muted"><?= htmlspecialchars($pageDescription) ?></small>
                        <?php endif; ?>
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

                <!-- InfoBox -->
                <div class="row mb-3">
                    <div class="col-12 col-sm-6 col-md-3">
                        <div class="info-box">
                            <span class="info-box-icon text-bg-secondary shadow-sm"><i class="bi bi-collection"></i></span>
                            <div class="info-box-content">
                                <span class="info-box-text">Toplam Kayıt</span>
                                <span class="info-box-number" id="stat-toplam">0</span>
                            </div>
                        </div>
                    </div>
                    <?php foreach ($tipler as $tip): ?>
                        <div class="col-12 col-sm-6 col-md-3">
                            <div class="info-box">
                                <span class="info-box-icon text-bg-<?= htmlspecialchars($tip['renk'] ?: 'primary') ?> shadow-sm">
                                    <i class="bi <?= htmlspecialchars($tip['ikon'] ?: 'bi-file-earmark') ?>"></i>
                                </span>
                                <div class="info-box-content">
                                    <span class="info-box-text"><?= htmlspecialchars($tip['ad']) ?></span>
                                    <span class="info-box-number" id="stat-<?= htmlspecialchars($tip['kod']) ?>">0</span>
                                    <span class="info-box-text small text-muted" id="stat-<?= htmlspecialchars($tip['kod']) ?>-tutar"></span>
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>

                <!-- Filtre -->
                <div class="card card-primary card-outline mb-3 collapse" id="filterCard">
                    <div class="card-header">
                        <h3 class="card-title"><i class="bi bi-funnel"></i> Filtreler</h3>
                    </div>
                    <div class="card-body">
                        <form id="filterForm">
                            <div class="row g-3">
                                <div class="col-md-2">
                                    <label class="form-label">Başlangıç Tarihi</label>
                                    <input type="date" class="form-control" id="filter_start_date">
                                </div>
                                <div class="col-md-2">
                                    <label class="form-label">Bitiş Tarihi</label>
                                    <input type="date" class="form-control" id="filter_end_date">
                                </div>
                                <div class="col-md-3">
                                    <label class="form-label">Arama</label>
                                    <input type="text" class="form-control" id="filter_search" placeholder="İşletme, açıklama, beklenti...">
                                </div>
                                <div class="col-md-2">
                                    <label class="form-label">Personel</label>
                                    <select class="form-select" id="filter_personel">
                                        <option value="">Tümü</option>
                                        <?php foreach ($personeller as $p): ?>
                                            <option value="<?= (int)$p['id'] ?>">
                                                <?= htmlspecialchars(trim($p['ad'] . ' ' . $p['soyad'])) ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div class="col-md-2">
                                    <label class="form-label">Şehir</label>
                                    <input type="text" class="form-control" id="filter_sehir" list="sehirListesi">
                                </div>
                                <div class="col-md-1">
                                    <label class="form-label">Para Birimi</label>
                                    <select class="form-select" id="filter_para_birimi">
                                        <option value="">Tümü</option>
                                        <?php foreach ($paraBirimleri as $pb): ?>
                                            <option value="<?= htmlspecialchars($pb['kod']) ?>"><?= htmlspecialchars($pb['kod']) ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div class="col-md-2">
                                    <label class="form-label">Kaynak</label>
                                    <select class="form-select" id="filter_kaynak">
                                        <option value="">Tümü</option>
                                        <option value="telegram">Telegram</option>
                                        <option value="panel">Panel</option>
                                    </select>
                                </div>
                                <div class="col-md-2">
                                    <label class="form-label">Aktarım</label>
                                    <select class="form-select" id="filter_aktarildi">
                                        <option value="">Tümü</option>
                                        <option value="0">Aktarılmadı</option>
                                        <option value="1">Aktarıldı</option>
                                    </select>
                                </div>
                                <div class="col-12">
                                    <button type="submit" class="btn btn-primary"><i class="bi bi-search"></i> Filtrele</button>
                                    <button type="button" class="btn btn-secondary" id="clearFilters"><i class="bi bi-x-circle"></i> Temizle</button>
                                </div>
                            </div>
                        </form>
                    </div>
                </div>

                <!-- Liste -->
                <div class="card card-primary card-outline">
                    <div class="card-header">
                        <h3 class="card-title"><i class="bi bi-list-ul"></i> Saha Kayıtları</h3>
                        <div class="card-tools">
                            <button type="button" class="btn btn-sm btn-secondary" data-bs-toggle="collapse" data-bs-target="#filterCard">
                                <i class="bi bi-funnel"></i> Filtrele
                            </button>
                            <?php if ($pagePermissions['can_add']): ?>
                                <button type="button" class="btn btn-sm btn-primary" id="btnYeniEkle">
                                    <i class="bi bi-plus-circle"></i> Yeni Kayıt
                                </button>
                            <?php endif; ?>
                        </div>
                    </div>

                    <div class="card-body">
                        <!-- Tip sekmeleri -->
                        <ul class="nav nav-tabs mb-3" id="tipSekmeleri">
                            <li class="nav-item">
                                <a class="nav-link active" data-tip-id="">Tümü</a>
                            </li>
                            <?php foreach ($tipler as $tip): ?>
                                <li class="nav-item">
                                    <a class="nav-link" data-tip-id="<?= (int)$tip['id'] ?>">
                                        <i class="bi <?= htmlspecialchars($tip['ikon'] ?: 'bi-file-earmark') ?>"></i>
                                        <?= htmlspecialchars($tip['ad']) ?>
                                    </a>
                                </li>
                            <?php endforeach; ?>
                        </ul>

                        <table class="table table-bordered table-striped table-hover w-100" id="kayitTable">
                            <thead>
                                <tr>
                                    <th>Tarih</th>
                                    <th>Tip</th>
                                    <th>İşletme</th>
                                    <th>Şehir / İlçe</th>
                                    <th>Tutar</th>
                                    <th>Personel</th>
                                    <th>Kayıt Zamanı</th>
                                    <th class="text-center" width="140">İşlemler</th>
                                </tr>
                            </thead>
                            <tbody></tbody>
                        </table>
                    </div>
                </div>

            </div>
        </div>
    </main>

    <?php include __DIR__ . '/../includes/footer.php'; ?>
</div>

<!-- Datalist önerileri -->
<datalist id="sehirListesi">
    <?php foreach ($sehirOnerileri as $s): ?>
        <option value="<?= htmlspecialchars($s['deger']) ?>"></option>
    <?php endforeach; ?>
</datalist>
<datalist id="ilceListesi">
    <?php foreach ($ilceOnerileri as $i): ?>
        <option value="<?= htmlspecialchars($i['deger']) ?>"></option>
    <?php endforeach; ?>
</datalist>

<!-- Modal: Kayıt Ekle/Düzenle -->
<div class="modal fade" id="modalForm" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="modalTitle">Yeni Kayıt</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Kapat"></button>
            </div>
            <form id="saveForm" enctype="multipart/form-data">
                <div class="modal-body">
                    <input type="hidden" id="kayit_id" name="id">
                    <input type="hidden" id="silinen_gorseller" name="silinen_gorseller" value="[]">

                    <div class="row g-3">
                        <div class="col-md-6">
                            <label class="form-label">Kayıt Tipi <span class="text-danger">*</span></label>
                            <select class="form-select" id="tip_id" name="tip_id" required>
                                <option value="">Seçiniz...</option>
                                <?php foreach ($tipler as $tip): ?>
                                    <option value="<?= (int)$tip['id'] ?>"><?= htmlspecialchars($tip['ad']) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="col-md-6">
                            <label class="form-label">Tarih <span class="text-danger">*</span></label>
                            <input type="date" class="form-control" id="tarih" name="tarih" required>
                        </div>

                        <div class="col-md-4 alan" data-alan="para_birimi">
                            <label class="form-label">Para Birimi</label>
                            <select class="form-select" id="para_birimi" name="para_birimi">
                                <option value="">Seçiniz...</option>
                                <?php foreach ($paraBirimleri as $pb): ?>
                                    <option value="<?= htmlspecialchars($pb['kod']) ?>">
                                        <?= htmlspecialchars(trim($pb['kod'] . ' ' . ($pb['simge'] ?? ''))) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="col-md-4 alan" data-alan="sehir">
                            <label class="form-label">İl</label>
                            <input type="text" class="form-control" id="sehir" name="sehir" list="sehirListesi" maxlength="100">
                        </div>

                        <div class="col-md-4 alan" data-alan="ilce">
                            <label class="form-label">İlçe</label>
                            <input type="text" class="form-control" id="ilce" name="ilce" list="ilceListesi" maxlength="100">
                        </div>

                        <div class="col-md-6 alan" data-alan="cari">
                            <label class="form-label">İşletme (Cari)</label>
                            <select class="form-select" id="cari_id" name="cari_id"></select>
                            <small class="text-muted">Sistemde kayıtlı cariler arasından seçin.</small>
                        </div>

                        <div class="col-md-6 alan" data-alan="isletme_adi">
                            <label class="form-label">İşletme Adı</label>
                            <input type="text" class="form-control" id="isletme_adi" name="isletme_adi" maxlength="255">
                            <small class="text-muted">Cari kayıtlı değilse buraya yazın.</small>
                        </div>

                        <div class="col-md-6 alan" data-alan="tutar">
                            <label class="form-label" id="tutarEtiket">Tutar</label>
                            <input type="number" step="0.01" min="0" class="form-control" id="tutar" name="tutar">
                        </div>

                        <div class="col-md-6 alan" data-alan="musteri_beklentisi">
                            <label class="form-label">Müşteri Beklentisi</label>
                            <input type="number" step="0.01" min="0" class="form-control" id="musteri_beklentisi" name="musteri_beklentisi">
                            <small class="text-muted">Müşterinin talep ettiği tutar.</small>
                        </div>

                        <div class="col-12 alan" data-alan="gorseller">
                            <label class="form-label">İşletme Görselleri</label>
                            <input type="file" class="form-control" id="gorseller" name="gorseller[]"
                                   accept="image/*" capture="environment" multiple>
                            <small class="text-muted">Galeriden seçebilir veya doğrudan fotoğraf çekebilirsiniz.</small>
                            <div id="gorselOnizleme" class="mt-2"></div>
                        </div>

                        <div class="col-12 alan" data-alan="aciklama">
                            <label class="form-label">Açıklama / Notlar</label>
                            <textarea class="form-control" id="aciklama" name="aciklama" rows="3"></textarea>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal"><i class="bi bi-x-circle"></i> İptal</button>
                    <button type="submit" class="btn btn-primary"><i class="bi bi-save"></i> Kaydet</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Modal: Konum Geçmişi -->
<div class="modal fade" id="modalKonum" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title"><i class="bi bi-geo-alt"></i> Konum Geçmişi</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Kapat"></button>
            </div>
            <div class="modal-body" id="konumIcerik"></div>
        </div>
    </div>
</div>

<script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/@popperjs/core@2.11.8/dist/umd/popper.min.js" crossorigin="anonymous"></script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.7/dist/js/bootstrap.min.js" crossorigin="anonymous"></script>
<script src="/admin/assets/js/adminlte.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11/dist/sweetalert2.all.min.js"></script>
<script src="https://cdn.datatables.net/1.13.7/js/jquery.dataTables.min.js"></script>
<script src="https://cdn.datatables.net/1.13.7/js/dataTables.bootstrap5.min.js"></script>
<script src="/admin/assets/js/custom.js"></script>

<script>
const permissions   = <?= json_encode($pagePermissions) ?>;
const tipler        = <?= json_encode($tipler, JSON_UNESCAPED_UNICODE) ?>;
const paraSimgeleri = <?= json_encode(array_column($paraBirimleri, 'simge', 'kod'), JSON_UNESCAPED_UNICODE) ?>;
const gorselYolu    = '<?= GORSEL_WEB_YOL ?>';

let tablo, modal, modalKonum;
let currentFilters = {};
let silinenGorseller = [];

function esc(s) {
    if (s === null || s === undefined) return '';
    return String(s).replace(/[&<>"']/g, c => ({
        '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;'
    })[c]);
}

function tutarFormat(deger, paraBirimi) {
    if (deger === null || deger === undefined || deger === '') return '-';
    const sayi = Number(deger).toLocaleString('tr-TR', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
    const simge = paraSimgeleri[paraBirimi] || paraBirimi || '';
    return sayi + (simge ? ' ' + esc(simge) : '');
}

function tarihFormat(t) {
    if (!t) return '-';
    const d = new Date(String(t).replace(' ', 'T'));
    if (isNaN(d.getTime())) return esc(t);
    return d.toLocaleDateString('tr-TR');
}

function tarihSaatFormat(t) {
    if (!t) return '-';
    const d = new Date(String(t).replace(' ', 'T'));
    if (isNaN(d.getTime())) return esc(t);
    return d.toLocaleDateString('tr-TR') + ' ' + d.toLocaleTimeString('tr-TR', { hour: '2-digit', minute: '2-digit' });
}

// ---------- InfoBox ----------
function loadStats() {
    $.post('', { action: 'stats' }, res => {
        if (!res.success) return;
        $('#stat-toplam').text(res.data.toplam || 0);
        tipler.forEach(tip => {
            $('#stat-' + tip.kod).text(res.data[tip.kod] || 0);
            const tutar = res.data[tip.kod + '_tutar'];
            $('#stat-' + tip.kod + '-tutar').text(
                tutar ? Number(tutar).toLocaleString('tr-TR', { maximumFractionDigits: 0 }) : ''
            );
        });
    }, 'json');
}

// ---------- Tablo ----------
function tabloBaslat() {
    tablo = $('#kayitTable').DataTable({
        serverSide: true,
        processing: true,
        scrollX: true,
        dom: 'lrtip',
        pageLength: 25,
        order: [[0, 'desc']],
        language: { url: '//cdn.datatables.net/plug-ins/1.13.7/i18n/tr.json' },
        ajax: {
            url: '',
            type: 'POST',
            data: d => Object.assign(d, { action: 'list' }, currentFilters)
        },
        columns: [
            { data: 'tarih', render: (d, t) => t === 'display' ? tarihFormat(d) : d },
            {
                data: 'tip_adi',
                render: (d, t, row) => t === 'display'
                    ? `<span class="badge bg-${esc(row.tip_renk || 'primary')}"><i class="bi ${esc(row.tip_ikon || '')}"></i> ${esc(d)}</span>`
                    : d
            },
            {
                data: 'isletme_adi',
                render: (d, t, row) => {
                    if (t !== 'display') return d;
                    const ad = row.cari_adi || d || '-';
                    const rozet = row.cari_id ? ' <span class="badge bg-secondary">Cari</span>' : '';
                    const kaynak = row.kaynak === 'telegram'
                        ? ' <i class="bi bi-telegram text-primary" title="Telegram"></i>' : '';
                    return esc(ad) + rozet + kaynak;
                }
            },
            {
                data: 'sehir',
                render: (d, t, row) => t === 'display'
                    ? esc(d || '-') + (row.ilce ? ' / ' + esc(row.ilce) : '')
                    : d
            },
            {
                data: 'tutar',
                render: (d, t, row) => {
                    if (t !== 'display') return d;
                    let html = tutarFormat(d, row.para_birimi);
                    if (row.musteri_beklentisi !== null && row.musteri_beklentisi !== undefined && row.musteri_beklentisi !== '') {
                        html += `<br><small class="text-muted">Beklenti: ${tutarFormat(row.musteri_beklentisi, row.para_birimi)}</small>`;
                    }
                    return html;
                }
            },
            {
                data: 'personel_ad',
                render: (d, t, row) => t === 'display'
                    ? esc([row.personel_ad, row.personel_soyad].filter(Boolean).join(' ') || '-')
                    : d
            },
            { data: 'olusturma_tarihi', render: (d, t) => t === 'display' ? tarihSaatFormat(d) : d },
            {
                data: null,
                orderable: false,
                className: 'text-center',
                render: (d, t, row) => {
                    if (t !== 'display') return '';
                    let html = '';
                    if (row.enlem && row.boylam) {
                        html += `<button class="btn btn-sm btn-info" onclick="konumGoster(${row.id})" title="Konum"><i class="bi bi-geo-alt"></i></button>`;
                    }
                    if (permissions.can_edit) {
                        html += `<button class="btn btn-sm btn-warning" onclick="kayitDuzenle(${row.id})" title="Düzenle"><i class="bi bi-pencil"></i></button>`;
                    }
                    if (permissions.can_delete) {
                        html += `<button class="btn btn-sm btn-danger" onclick="kayitSil(${row.id})" title="Sil"><i class="bi bi-trash"></i></button>`;
                    }
                    return html || '-';
                }
            }
        ]
    });
}

// ---------- Form alan görünürlüğü ----------
function alanlariAyarla(tipId) {
    const tip = tipler.find(t => String(t.id) === String(tipId));
    if (!tip) {
        $('.alan').hide();
        return;
    }
    $('.alan').each(function () {
        $(this).toggle(tip.alanlar.includes($(this).data('alan')));
    });
    $('#tutarEtiket').text(tip.tutar_etiket || 'Tutar');
}

function cariSelect2Baslat() {
    $('#cari_id').select2({
        theme: 'bootstrap-5',
        dropdownParent: $('#modalForm'),
        placeholder: 'Cari ara...',
        allowClear: true,
        minimumInputLength: 0,
        ajax: {
            url: '',
            type: 'POST',
            dataType: 'json',
            delay: 300,
            data: params => ({ action: 'cari_ara', q: params.term || '' }),
            processResults: res => ({
                results: (res.data || []).map(c => ({ id: c.cari_id, text: c.cari_adi }))
            })
        },
        language: {
            noResults: () => 'Sonuç bulunamadı',
            searching: () => 'Aranıyor...',
            inputTooShort: () => 'Aramak için yazın'
        }
    });
}

function gorselleriGoster(liste) {
    const kap = $('#gorselOnizleme').empty();
    (liste || []).forEach(dosya => {
        kap.append(`
            <div class="gorsel-kutu" data-dosya="${esc(dosya)}">
                <a href="${gorselYolu}${esc(dosya)}" target="_blank">
                    <img src="${gorselYolu}${esc(dosya)}" alt="">
                </a>
                <button type="button" class="btn btn-sm btn-danger btn-sil" onclick="gorselSil('${esc(dosya)}')">&times;</button>
            </div>
        `);
    });
}

function gorselSil(dosya) {
    silinenGorseller.push(dosya);
    $('#silinen_gorseller').val(JSON.stringify(silinenGorseller));
    $(`.gorsel-kutu[data-dosya="${dosya}"]`).remove();
}

// ---------- İşlemler ----------
$('#btnYeniEkle').on('click', function () {
    $('#modalTitle').text('Yeni Kayıt');
    $('#saveForm')[0].reset();
    $('#kayit_id').val('');
    silinenGorseller = [];
    $('#silinen_gorseller').val('[]');
    $('#gorselOnizleme').empty();
    $('#cari_id').val(null).trigger('change');
    $('#tarih').val(new Date().toISOString().slice(0, 10));
    $('.alan').hide();
    modal.show();
});

function kayitDuzenle(id) {
    $.post('', { action: 'get', id: id }, res => {
        if (!res.success) {
            showToast(res.message, 'error');
            return;
        }
        const d = res.data;

        $('#modalTitle').text('Kayıt Düzenle');
        $('#saveForm')[0].reset();
        silinenGorseller = [];
        $('#silinen_gorseller').val('[]');

        $('#kayit_id').val(d.Telegram_Kayitlar_id);
        $('#tip_id').val(d.Telegram_Kayitlar_TipId).trigger('change');
        $('#tarih').val(d.Telegram_Kayitlar_Tarih ? String(d.Telegram_Kayitlar_Tarih).slice(0, 10) : '');
        $('#para_birimi').val(d.Telegram_Kayitlar_ParaBirimi || '');
        $('#sehir').val(d.Telegram_Kayitlar_Sehir || '');
        $('#ilce').val(d.Telegram_Kayitlar_Ilce || '');
        $('#isletme_adi').val(d.Telegram_Kayitlar_IsletmeAdi || '');
        $('#tutar').val(d.Telegram_Kayitlar_Tutar || '');
        $('#musteri_beklentisi').val(d.Telegram_Kayitlar_MusteriBeklentisi || '');
        $('#aciklama').val(d.Telegram_Kayitlar_Aciklama || '');

        // Cari (Select2 remote — option elle eklenir)
        $('#cari_id').empty();
        if (d.Telegram_Kayitlar_CariId) {
            $('#cari_id').append(new Option(d.cari_adi || '', d.Telegram_Kayitlar_CariId, true, true));
        }
        $('#cari_id').trigger('change');

        gorselleriGoster(d.gorsel_listesi);
        alanlariAyarla(d.Telegram_Kayitlar_TipId);
        modal.show();
    }, 'json');
}

function kayitSil(id) {
    confirmAction('Bu kaydı silmek istediğinize emin misiniz?', 'Kayıt listeden kaldırılacak.', function () {
        $.post('', { action: 'delete', id: id }, res => {
            if (res.success) {
                showToast(res.message, 'success');
                tablo.ajax.reload(null, false);
                loadStats();
            } else {
                showToast(res.message, 'error');
            }
        }, 'json');
    });
}

function konumGoster(id) {
    $.post('', { action: 'konum', id: id }, res => {
        const kap = $('#konumIcerik');
        if (!res.success || !res.data.length) {
            kap.html('<p class="text-muted mb-0">Bu kayda bağlı konum bulunamadı.</p>');
            modalKonum.show();
            return;
        }

        let html = '<div class="table-responsive"><table class="table table-sm table-bordered mb-0"><thead><tr>'
                 + '<th>Olay</th><th>Tarih</th><th>Koordinat</th><th>Doğruluk</th><th>Adres</th></tr></thead><tbody>';
        res.data.forEach(k => {
            const harita = (k.enlem && k.boylam)
                ? `<a href="https://www.google.com/maps?q=${k.enlem},${k.boylam}" target="_blank">${k.enlem}, ${k.boylam}</a>`
                : '-';
            html += `<tr>
                <td><span class="badge bg-${esc(k.olay_renk || 'secondary')}">${esc(k.olay_adi)}</span></td>
                <td>${tarihSaatFormat(k.tarih)}</td>
                <td>${harita}</td>
                <td>${k.dogruluk ? esc(k.dogruluk) + ' m' : '-'}</td>
                <td>${esc(k.adres || '-')}</td>
            </tr>`;
        });
        html += '</tbody></table></div>';

        kap.html(html);
        modalKonum.show();
    }, 'json');
}

// ---------- Kaydet ----------
$('#saveForm').on('submit', function (e) {
    e.preventDefault();

    const formData = new FormData(this);
    formData.append('action', 'save');

    $.ajax({
        url: '',
        type: 'POST',
        data: formData,
        processData: false,
        contentType: false,
        dataType: 'json',
        success: res => {
            if (res.success) {
                showToast(res.message, 'success');
                modal.hide();
                tablo.ajax.reload(null, false);
                loadStats();
            } else {
                showToast(res.message, 'error');
            }
        },
        error: (xhr, status, error) => showToast('Kayıt başarısız: ' + error, 'error')
    });
});

$('#tip_id').on('change', function () {
    alanlariAyarla($(this).val());
});

// ---------- Sekmeler ve filtreler ----------
$('#tipSekmeleri').on('click', '.nav-link', function () {
    $('#tipSekmeleri .nav-link').removeClass('active');
    $(this).addClass('active');

    const tipId = $(this).data('tip-id');
    if (tipId) {
        currentFilters.tip_id = tipId;
    } else {
        delete currentFilters.tip_id;
    }
    tablo.ajax.reload();
});

$('#filterForm').on('submit', function (e) {
    e.preventDefault();

    const tipId = currentFilters.tip_id;
    currentFilters = {
        start_date:  $('#filter_start_date').val(),
        end_date:    $('#filter_end_date').val(),
        search_text: $('#filter_search').val(),
        personel_id: $('#filter_personel').val(),
        sehir:       $('#filter_sehir').val(),
        para_birimi: $('#filter_para_birimi').val(),
        kaynak:      $('#filter_kaynak').val(),
        aktarildi:   $('#filter_aktarildi').val()
    };
    Object.keys(currentFilters).forEach(k => {
        if (!currentFilters[k]) delete currentFilters[k];
    });
    if (tipId) currentFilters.tip_id = tipId;

    tablo.ajax.reload();
    showToast('Filtre uygulandı', 'info');
});

$('#clearFilters').on('click', function () {
    $('#filterForm')[0].reset();
    $('#filter_personel, #filter_para_birimi, #filter_kaynak, #filter_aktarildi').val('').trigger('change.select2');

    const tipId = currentFilters.tip_id;
    currentFilters = tipId ? { tip_id: tipId } : {};

    tablo.ajax.reload();
    showToast('Filtreler temizlendi', 'info');
});

// ---------- Başlangıç ----------
$(document).ready(function () {
    modal      = new bootstrap.Modal('#modalForm');
    modalKonum = new bootstrap.Modal('#modalKonum');

    $('#filter_personel, #filter_para_birimi, #filter_kaynak, #filter_aktarildi').select2({
        theme: 'bootstrap-5',
        placeholder: 'Tümü',
        allowClear: true,
        language: {
            noResults: () => 'Sonuç bulunamadı',
            searching: () => 'Aranıyor...'
        }
    });

    $('#tip_id, #para_birimi').select2({
        theme: 'bootstrap-5',
        dropdownParent: $('#modalForm'),
        placeholder: 'Seçiniz...',
        allowClear: true,
        language: {
            noResults: () => 'Sonuç bulunamadı',
            searching: () => 'Aranıyor...'
        }
    });

    cariSelect2Baslat();
    tabloBaslat();
    loadStats();
});
</script>
</body>
</html>
