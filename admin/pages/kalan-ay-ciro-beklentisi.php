<?php
/**
 * Kalan Ay Ciro Beklentisi
 * Sezonun kalan aylarına şehir (bölge) bazında planlanan satış beklentisi (Sozlesme_AyBeklentileri)
 * ile gerçekleşen sözleşme tutarının (Sozlesme_StokHareketleri) karşılaştırması.
 * Kalan = Beklenti - Gerçekleşen. Gerçekleşen, ayın başından veya beklentinin başlangıç tarihinden itibaren sayılır.
 * Sayfa varsayılan olarak içinde bulunulan ayla açılır; diğer aylar filtreden seçilir.
 * Tablo sezon başına ay x şehir (~30 satır) olduğu için client-side DataTables kullanılır.
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

$canAdd = !empty($pagePermissions['can_add']);
$canEdit = !empty($pagePermissions['can_edit']);
$canDelete = !empty($pagePermissions['can_delete']);

$pageInfo = $db->fetchOne("
    SELECT s.sayfalar_sayfa_adi, m.menuler_menu_adi AS menu_adi
    FROM Menu_Sayfalar s
    LEFT JOIN Menuler m ON s.sayfalar_menu_id = m.menuler_id
    WHERE s.sayfalar_sayfa_url LIKE ? AND s.sayfalar_durum = 1
", ['%' . $currentPageFile . '%']);

$pageTitle = $pageInfo['sayfalar_sayfa_adi'] ?? 'Kalan Ay Ciro Beklentisi';
$menuAdi = $pageInfo['menu_adi'] ?? null;

$siteAyarlari = $db->fetchOne("SELECT TOP 1 site_ayarlari_site_title FROM dbo.tanim_site_ayarlari ORDER BY site_ayarlari_id DESC");
$siteTitle = $siteAyarlari['site_ayarlari_site_title'] ?? 'Örnek Yazılım Portal';

$cariTipleri = $db->fetchAll("SELECT cari_tipi_id, cari_tipi_ad FROM Cari_CariTipleri WHERE cari_tipi_durum = 1 ORDER BY cari_tipi_sira");
$sezonlar = $db->fetchAll("SELECT sezon_id, sezon_ad FROM Sozlesme_Sezonlar WHERE sezon_durum = 1 ORDER BY sezon_ad DESC");

// Menü sezon bağlamı (Raporlar 2027 -> ?sezon_id=3)
$defaultSezonId = (isset($_GET['sezon_id']) && $_GET['sezon_id'] !== '') ? (int)$_GET['sezon_id'] : 3;

$aylar = [
    1 => 'Ocak', 2 => 'Şubat', 3 => 'Mart', 4 => 'Nisan', 5 => 'Mayıs', 6 => 'Haziran',
    7 => 'Temmuz', 8 => 'Ağustos', 9 => 'Eylül', 10 => 'Ekim', 11 => 'Kasım', 12 => 'Aralık'
];

/**
 * Sezonun bölge şehirleri (Adres_Bolgeler) - ilçe bazlı şehirlerde tutar tekrar eder, MAX alınır
 */
function beklentiSehirleri($db, int $sezonId): array
{
    return $db->fetchAll("
        SELECT b.BolgeSehirId AS sehir_id, sh.SehirAdi AS sehir_adi, MAX(b.BolgeTutar) AS bolge_tutar
        FROM Adres_Bolgeler b
        INNER JOIN Adres_Sehirler sh ON sh.SehirId = b.BolgeSehirId
        WHERE b.BolgeSezonId = ? AND b.Durum = 1
        GROUP BY b.BolgeSehirId, sh.SehirAdi
        ORDER BY MAX(b.BolgeTutar) DESC, sh.SehirAdi
    ", [$sezonId]);
}

/**
 * Sezonda beklentisi girilmiş aylar + varsayılan ay (bu ay; yoksa ilk gelecek ay; o da yoksa son ay)
 * Ay anahtarı 'YYYY-MM' biçimindedir.
 */
function beklentiAylari($db, int $sezonId, array $aylar): array
{
    $rows = $db->fetchAll("
        SELECT DISTINCT ay_beklenti_yil AS yil, ay_beklenti_ay AS ay
        FROM Sozlesme_AyBeklentileri
        WHERE ay_beklenti_sezon_id = ? AND Durum = 1
        ORDER BY ay_beklenti_yil, ay_beklenti_ay
    ", [$sezonId]);

    $buAy = date('Y-m');
    $liste = [];
    $varsayilan = '';
    foreach ($rows as $r) {
        $anahtar = sprintf('%04d-%02d', $r['yil'], $r['ay']);
        $liste[] = ['anahtar' => $anahtar, 'ad' => $aylar[(int)$r['ay']] . ' ' . $r['yil']];
        if ($varsayilan === '' && $anahtar >= $buAy) {
            $varsayilan = $anahtar;
        }
    }
    if ($varsayilan === '' && $liste) {
        $varsayilan = end($liste)['anahtar'];
    }
    return ['liste' => $liste, 'varsayilan' => $varsayilan];
}

/**
 * Satış filtresi (sezon + cari tipi); sorguda Sozlesmeler = s, Cari = c
 */
function beklentiSatisFiltre(int $sezonId, string $cariTipiId): array
{
    $where = " AND s.sozlesme_sezon_id = ?";
    $params = [$sezonId];
    if ($cariTipiId !== '') {
        $where .= " AND c.cari_tipi_id = ?";
        $params[] = (int)$cariTipiId;
    }
    return [$where, $params];
}

/**
 * Formdan gelen beklenti satırını doğrular ve normalize eder; hatada Exception fırlatır
 */
function beklentiDogrula(array $girdi, array $gecerliSehirler, array $aylar): array
{
    $sehirId = (int)($girdi['sehir_id'] ?? 0);
    $yil = (int)($girdi['yil'] ?? 0);
    $ay = (int)($girdi['ay'] ?? 0);
    $tutar = str_replace(['.', ' '], '', (string)($girdi['tutar'] ?? ''));
    $tutar = (float)str_replace(',', '.', $tutar);
    $baslangic = trim((string)($girdi['baslangic'] ?? ''));
    $aciklama = trim((string)($girdi['aciklama'] ?? ''));

    if (!isset($gecerliSehirler[$sehirId])) {
        throw new Exception('Sezonun bölgelerinden bir şehir seçin.');
    }
    if ($yil < 2000 || $yil > 2100 || $ay < 1 || $ay > 12) {
        throw new Exception('Geçerli bir yıl ve ay seçin.');
    }
    $etiket = $gecerliSehirler[$sehirId] . ' - ' . $aylar[$ay] . ' ' . $yil;
    if ($tutar <= 0) {
        throw new Exception($etiket . ': beklenti tutarı sıfırdan büyük olmalı.');
    }
    if ($baslangic !== '') {
        $bt = DateTime::createFromFormat('Y-m-d', $baslangic);
        if (!$bt || (int)$bt->format('Y') !== $yil || (int)$bt->format('n') !== $ay) {
            throw new Exception($etiket . ': başlangıç tarihi seçilen ayın içinde olmalı.');
        }
    }

    return [
        'id' => (int)($girdi['id'] ?? 0),
        'sehir_id' => $sehirId,
        'yil' => $yil,
        'ay' => $ay,
        'tutar' => $tutar,
        'baslangic' => $baslangic !== '' ? $baslangic : null,
        'aciklama' => $aciklama !== '' ? mb_substr($aciklama, 0, 500) : null,
        'anahtar' => $sehirId . '-' . $yil . '-' . $ay,
        'etiket' => $etiket
    ];
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json');
    $action = $_POST['action'] ?? '';
    $sezonId = (int)($_POST['sezon_id'] ?? $defaultSezonId);
    $cariTipiId = (string)($_POST['cari_tipi_id'] ?? '');

    try {
        switch ($action) {
            case 'aylar':
                echo json_encode(['success' => true, 'data' => beklentiAylari($db, $sezonId, $aylar)]);
                break;

            case 'stats':
                [$fWhere, $fParams] = beklentiSatisFiltre($sezonId, $cariTipiId);
                $sehirler = beklentiSehirleri($db, $sezonId);
                $bolgeTutar = array_sum(array_map(fn($s) => floatval($s['bolge_tutar']), $sehirler));

                // Bölge kalanı yalnız bölge şehirlerindeki satıştan düşülür
                $sehirIdler = array_map(fn($s) => (int)$s['sehir_id'], $sehirler);
                $satisTutar = 0;
                if ($sehirIdler) {
                    $in = implode(',', array_fill(0, count($sehirIdler), '?'));
                    $satis = $db->fetchOne("
                        SELECT ISNULL(SUM(h.hareket_fiyat), 0) AS toplam
                        FROM Sozlesme_StokHareketleri h
                        INNER JOIN Sozlesmeler s ON s.sozlesme_id = h.hareket_sozlesme_id
                        INNER JOIN Cari c ON c.cari_id = s.sozlesme_cari_id
                        WHERE c.cari_sehirler IN ($in) $fWhere
                    ", array_merge($sehirIdler, $fParams));
                    $satisTutar = floatval($satis['toplam'] ?? 0);
                }

                echo json_encode(['success' => true, 'data' => [
                    'bolge_tutar' => $bolgeTutar,
                    'sezon_satis' => $satisTutar,
                    'bolge_kalan' => $bolgeTutar - $satisTutar
                ]]);
                break;

            case 'report':
                [$fWhere, $fParams] = beklentiSatisFiltre($sezonId, $cariTipiId);
                $ayAnahtar = (string)($_POST['ay'] ?? '');
                $sehirFiltre = (int)($_POST['sehir_id'] ?? 0);

                $where = "b.ay_beklenti_sezon_id = ? AND b.Durum = 1";
                $params = [$sezonId];
                if (preg_match('/^(\d{4})-(\d{2})$/', $ayAnahtar, $m)) {
                    $where .= " AND b.ay_beklenti_yil = ? AND b.ay_beklenti_ay = ?";
                    $params[] = (int)$m[1];
                    $params[] = (int)$m[2];
                }
                if ($sehirFiltre) {
                    $where .= " AND b.ay_beklenti_sehir_id = ?";
                    $params[] = $sehirFiltre;
                }

                // Beklenti satırı sayısı küçük (ay x şehir); APPLY her satır için tek sorgu
                $rows = $db->fetchAll("
                    SELECT
                        b.ay_beklenti_id,
                        b.ay_beklenti_sehir_id AS sehir_id,
                        sh.SehirAdi AS sehir_adi,
                        b.ay_beklenti_yil AS yil,
                        b.ay_beklenti_ay AS ay,
                        b.ay_beklenti_tutar AS beklenti,
                        CONVERT(VARCHAR(10), b.ay_beklenti_baslangic_tarihi, 23) AS baslangic,
                        b.ay_beklenti_aciklama AS aciklama,
                        ISNULL(g.tutar, 0) AS gerceklesen,
                        ISNULL(g.adet, 0) AS adet
                    FROM Sozlesme_AyBeklentileri b
                    LEFT JOIN Adres_Sehirler sh ON sh.SehirId = b.ay_beklenti_sehir_id
                    OUTER APPLY (
                        SELECT SUM(h.hareket_fiyat) AS tutar, COUNT(DISTINCT s.sozlesme_id) AS adet
                        FROM Sozlesmeler s
                        INNER JOIN Sozlesme_StokHareketleri h ON h.hareket_sozlesme_id = s.sozlesme_id
                        INNER JOIN Cari c ON c.cari_id = s.sozlesme_cari_id
                        WHERE c.cari_sehirler = b.ay_beklenti_sehir_id
                          AND s.sozlesme_tarih >= ISNULL(CAST(b.ay_beklenti_baslangic_tarihi AS DATETIME), DATEFROMPARTS(b.ay_beklenti_yil, b.ay_beklenti_ay, 1))
                          AND s.sozlesme_tarih < DATEADD(MONTH, 1, DATEFROMPARTS(b.ay_beklenti_yil, b.ay_beklenti_ay, 1))
                          $fWhere
                    ) g
                    WHERE $where
                    ORDER BY b.ay_beklenti_yil, b.ay_beklenti_ay, b.ay_beklenti_tutar DESC, sh.SehirAdi
                ", array_merge($fParams, $params));

                $buAy = date('Y-m');
                $data = [];
                $toplam = ['beklenti' => 0, 'gerceklesen' => 0, 'adet' => 0, 'kalan' => 0, 'kalan_beklenti' => 0];

                foreach ($rows as $r) {
                    $beklenti = floatval($r['beklenti']);
                    $gerceklesen = floatval($r['gerceklesen']);
                    $kalan = $beklenti - $gerceklesen;   // eksi = beklenti aşıldı
                    $anahtar = sprintf('%04d-%02d', $r['yil'], $r['ay']);
                    $donem = $anahtar < $buAy ? 'gecmis' : ($anahtar === $buAy ? 'bu_ay' : 'gelecek');

                    $toplam['beklenti'] += $beklenti;
                    $toplam['gerceklesen'] += $gerceklesen;
                    $toplam['adet'] += (int)$r['adet'];
                    $toplam['kalan'] += $kalan;
                    if ($donem !== 'gecmis') {
                        $toplam['kalan_beklenti'] += max(0, $kalan);
                    }

                    $data[] = [
                        'id' => (int)$r['ay_beklenti_id'],
                        'ay_anahtar' => $anahtar,
                        'ay_adi' => $aylar[(int)$r['ay']] . ' ' . $r['yil'],
                        'yil' => (int)$r['yil'],
                        'ay' => (int)$r['ay'],
                        'sehir_id' => (int)$r['sehir_id'],
                        'sehir_adi' => $r['sehir_adi'] ?? '-',
                        'beklenti' => $beklenti,
                        'gerceklesen' => $gerceklesen,
                        'adet' => (int)$r['adet'],
                        'kalan' => $kalan,
                        'oran' => $beklenti > 0 ? round($gerceklesen / $beklenti * 100, 1) : null,
                        'baslangic' => $r['baslangic'],
                        'aciklama' => $r['aciklama'],
                        'donem' => $donem
                    ];
                }
                $toplam['oran'] = $toplam['beklenti'] > 0 ? round($toplam['gerceklesen'] / $toplam['beklenti'] * 100, 1) : null;

                echo json_encode(['success' => true, 'data' => $data, 'toplam' => $toplam]);
                break;

            case 'detay':
                // Gerçekleşen hücresinin sözleşmeleri: rapordaki OUTER APPLY ile aynı kriterler
                [$fWhere, $fParams] = beklentiSatisFiltre($sezonId, $cariTipiId);
                $bid = (int)($_POST['id'] ?? 0);
                $b = $db->fetchOne("
                    SELECT ay_beklenti_sehir_id, ay_beklenti_yil, ay_beklenti_ay,
                           CONVERT(VARCHAR(10), ay_beklenti_baslangic_tarihi, 23) AS baslangic
                    FROM Sozlesme_AyBeklentileri
                    WHERE ay_beklenti_id = ? AND Durum = 1
                ", [$bid]);
                if (!$b) {
                    throw new Exception('Beklenti kaydı bulunamadı.');
                }

                $data = $db->fetchAll("
                    SELECT
                        s.sozlesme_id,
                        s.sozlesme_no,
                        CONVERT(VARCHAR(10), s.sozlesme_tarih, 104) AS sozlesme_tarih,
                        c.cari_adi,
                        ISNULL(ilc.IlceAdi, '-') AS ilce_adi,
                        ISNULL(k.kullanici_ad + ' ' + k.kullanici_soyad, '-') AS personel_adi,
                        SUM(h.hareket_fiyat) AS tutar
                    FROM Sozlesmeler s
                    INNER JOIN Sozlesme_StokHareketleri h ON h.hareket_sozlesme_id = s.sozlesme_id
                    INNER JOIN Cari c ON c.cari_id = s.sozlesme_cari_id
                    LEFT JOIN Adres_Ilceler ilc ON ilc.ilceId = c.cari_ilceler
                    LEFT JOIN kullanicilar k ON k.kullanici_id = s.sozlesme_personel_id
                    WHERE c.cari_sehirler = ?
                      AND s.sozlesme_tarih >= ISNULL(CAST(? AS DATETIME), DATEFROMPARTS(?, ?, 1))
                      AND s.sozlesme_tarih < DATEADD(MONTH, 1, DATEFROMPARTS(?, ?, 1))
                      $fWhere
                    GROUP BY s.sozlesme_id, s.sozlesme_no, s.sozlesme_tarih, c.cari_adi, ilc.IlceAdi, k.kullanici_ad, k.kullanici_soyad
                    ORDER BY s.sozlesme_tarih DESC, s.sozlesme_id DESC
                ", array_merge([
                    (int)$b['ay_beklenti_sehir_id'],
                    $b['baslangic'],
                    (int)$b['ay_beklenti_yil'], (int)$b['ay_beklenti_ay'],
                    (int)$b['ay_beklenti_yil'], (int)$b['ay_beklenti_ay']
                ], $fParams));

                echo json_encode(['success' => true, 'data' => $data]);
                break;

            case 'save':
                $id = (int)($_POST['id'] ?? 0);
                if ($id ? !$canEdit : !$canAdd) {
                    throw new Exception('Bu işlem için yetkiniz yok.');
                }

                $gecerliSehirler = array_column(beklentiSehirleri($db, $sezonId), 'sehir_adi', 'sehir_id');
                $k = beklentiDogrula($_POST, $gecerliSehirler, $aylar);

                $mukerrer = $db->fetchOne("
                    SELECT ay_beklenti_id FROM Sozlesme_AyBeklentileri
                    WHERE ay_beklenti_sezon_id = ? AND ay_beklenti_sehir_id = ? AND ay_beklenti_yil = ? AND ay_beklenti_ay = ?
                      AND Durum = 1 AND ay_beklenti_id <> ?
                ", [$sezonId, $k['sehir_id'], $k['yil'], $k['ay'], $id]);
                if ($mukerrer) {
                    throw new Exception($k['etiket'] . ' beklentisi zaten var; mevcut kaydı düzenleyin.');
                }

                if ($id) {
                    $ok = $db->execute("
                        UPDATE Sozlesme_AyBeklentileri
                        SET ay_beklenti_sehir_id = ?, ay_beklenti_yil = ?, ay_beklenti_ay = ?, ay_beklenti_tutar = ?,
                            ay_beklenti_baslangic_tarihi = ?, ay_beklenti_aciklama = ?,
                            GuncelleyenKullanici = ?, GuncellemeTarihi = GETDATE()
                        WHERE ay_beklenti_id = ? AND Durum = 1
                    ", [$k['sehir_id'], $k['yil'], $k['ay'], $k['tutar'], $k['baslangic'], $k['aciklama'], $user['kullanici_id'], $id]);
                } else {
                    $ok = $db->execute("
                        INSERT INTO Sozlesme_AyBeklentileri
                            (ay_beklenti_sezon_id, ay_beklenti_sehir_id, ay_beklenti_yil, ay_beklenti_ay, ay_beklenti_tutar,
                             ay_beklenti_baslangic_tarihi, ay_beklenti_aciklama,
                             OlusturanKullanici, OlusturmaTarihi, Durum)
                        VALUES (?, ?, ?, ?, ?, ?, ?, ?, GETDATE(), 1)
                    ", [$sezonId, $k['sehir_id'], $k['yil'], $k['ay'], $k['tutar'], $k['baslangic'], $k['aciklama'], $user['kullanici_id']]);
                }

                if (!$ok) {
                    throw new Exception('Kayıt yapılamadı.');
                }
                echo json_encode(['success' => true, 'message' => 'Beklenti kaydedildi.']);
                break;

            case 'save_toplu':
                if (!$canEdit) {
                    throw new Exception('Bu işlem için yetkiniz yok.');
                }

                $satirlar = json_decode($_POST['satirlar'] ?? '[]', true);
                if (!is_array($satirlar) || !$satirlar) {
                    throw new Exception('Kaydedilecek satır yok.');
                }

                $gecerliSehirler = array_column(beklentiSehirleri($db, $sezonId), 'sehir_adi', 'sehir_id');
                $mevcut = $db->fetchAll("
                    SELECT ay_beklenti_id, ay_beklenti_sehir_id, ay_beklenti_yil, ay_beklenti_ay
                    FROM Sozlesme_AyBeklentileri
                    WHERE ay_beklenti_sezon_id = ? AND Durum = 1
                ", [$sezonId]);
                $mevcutMap = [];
                foreach ($mevcut as $m) {
                    $mevcutMap[(int)$m['ay_beklenti_id']] = $m['ay_beklenti_sehir_id'] . '-' . $m['ay_beklenti_yil'] . '-' . $m['ay_beklenti_ay'];
                }

                // Doğrulama: son durumda aynı şehir + ay iki kez olamaz (gönderilmeyen kayıtlar da dahil)
                $yeni = [];
                $anahtarlar = [];
                foreach ($satirlar as $girdi) {
                    $k = beklentiDogrula((array)$girdi, $gecerliSehirler, $aylar);
                    if (!isset($mevcutMap[$k['id']])) {
                        throw new Exception('Kayıt bulunamadı (#' . $k['id'] . '). Sayfayı yenileyin.');
                    }
                    if (isset($anahtarlar[$k['anahtar']])) {
                        throw new Exception($k['etiket'] . ' birden fazla satırda var.');
                    }
                    $anahtarlar[$k['anahtar']] = true;
                    $yeni[$k['id']] = $k;
                }
                foreach ($mevcutMap as $mid => $anahtar) {
                    if (!isset($yeni[$mid]) && isset($anahtarlar[$anahtar])) {
                        throw new Exception('Seçilen şehir/ay için başka bir kayıt zaten var. Önce o kaydı düzenleyin veya silin.');
                    }
                }

                // Ay/şehir takaslarında tekil indeks çakışmasın: önce pasif, sonra yeni değerlerle aktif
                $conn = $db->getConnection();
                sqlsrv_begin_transaction($conn);
                try {
                    $idler = array_keys($yeni);
                    $in = implode(',', array_fill(0, count($idler), '?'));
                    if (!$db->execute("UPDATE Sozlesme_AyBeklentileri SET Durum = 0 WHERE ay_beklenti_id IN ($in)", $idler)) {
                        throw new Exception('Kayıtlar güncellenemedi.');
                    }
                    foreach ($yeni as $k) {
                        $ok = $db->execute("
                            UPDATE Sozlesme_AyBeklentileri
                            SET ay_beklenti_sehir_id = ?, ay_beklenti_yil = ?, ay_beklenti_ay = ?, ay_beklenti_tutar = ?,
                                ay_beklenti_baslangic_tarihi = ?, ay_beklenti_aciklama = ?,
                                GuncelleyenKullanici = ?, GuncellemeTarihi = GETDATE(), Durum = 1
                            WHERE ay_beklenti_id = ?
                        ", [$k['sehir_id'], $k['yil'], $k['ay'], $k['tutar'], $k['baslangic'], $k['aciklama'], $user['kullanici_id'], $k['id']]);
                        if (!$ok) {
                            throw new Exception($k['etiket'] . ' kaydedilemedi.');
                        }
                    }
                    sqlsrv_commit($conn);
                } catch (Exception $e) {
                    sqlsrv_rollback($conn);
                    throw $e;
                }

                echo json_encode(['success' => true, 'message' => count($yeni) . ' beklenti güncellendi.']);
                break;

            case 'delete':
                if (!$canDelete) {
                    throw new Exception('Bu işlem için yetkiniz yok.');
                }
                $id = (int)($_POST['id'] ?? 0);
                $ok = $db->execute("
                    UPDATE Sozlesme_AyBeklentileri
                    SET Durum = 0, GuncelleyenKullanici = ?, GuncellemeTarihi = GETDATE()
                    WHERE ay_beklenti_id = ?
                ", [$user['kullanici_id'], $id]);
                if (!$ok) {
                    throw new Exception('Silinemedi.');
                }
                echo json_encode(['success' => true, 'message' => 'Beklenti silindi.']);
                break;

            default:
                echo json_encode(['success' => false, 'message' => 'Geçersiz işlem']);
        }
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'message' => $e->getMessage()]);
    }
    exit;
}

$sehirSecenekleri = beklentiSehirleri($db, $defaultSezonId);
$ayBilgisi = beklentiAylari($db, $defaultSezonId, $aylar);
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
    <link href="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css" rel="stylesheet" />
    <link href="https://cdn.jsdelivr.net/npm/select2-bootstrap-5-theme@1.3.0/dist/select2-bootstrap-5-theme.min.css" rel="stylesheet" />
    <link href="https://cdn.jsdelivr.net/npm/sweetalert2@11/dist/sweetalert2.min.css" rel="stylesheet">
    <link rel="stylesheet" href="/admin/assets/css/custom.css">
    <style>
        .info-box { transition: transform 0.2s; }
        .info-box:hover { transform: translateY(-5px); box-shadow: 0 4px 6px rgba(0,0,0,0.1); }
        #reportTable th { white-space: nowrap; }
        #reportTable td { white-space: nowrap; vertical-align: middle; }
        #reportTable tfoot th { background-color: #e9ecef; }
        #reportTable tr.donem-bu_ay > td { background-color: #fff8e1; }
        #reportTable tr.donem-gecmis > td { color: #6c757d; }
        .progress { height: 8px; min-width: 90px; }
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
                        <div class="col-12 col-sm-6 col-lg">
                            <div class="info-box">
                                <span class="info-box-icon text-bg-secondary shadow-sm"><i class="bi bi-map"></i></span>
                                <div class="info-box-content">
                                    <span class="info-box-text">Bölge Tutarı</span>
                                    <span class="info-box-number" id="stat-bolge-tutar">-</span>
                                </div>
                            </div>
                        </div>
                        <div class="col-12 col-sm-6 col-lg">
                            <div class="info-box">
                                <span class="info-box-icon text-bg-primary shadow-sm"><i class="bi bi-cart-check"></i></span>
                                <div class="info-box-content">
                                    <span class="info-box-text">Sezon Satışı</span>
                                    <span class="info-box-number" id="stat-sezon-satis">-</span>
                                </div>
                            </div>
                        </div>
                        <div class="col-12 col-sm-6 col-lg">
                            <div class="info-box">
                                <span class="info-box-icon text-bg-danger shadow-sm"><i class="bi bi-flag"></i></span>
                                <div class="info-box-content">
                                    <span class="info-box-text">Bölge Kalanı</span>
                                    <span class="info-box-number" id="stat-bolge-kalan">-</span>
                                </div>
                            </div>
                        </div>
                        <div class="col-12 col-sm-6 col-lg">
                            <div class="info-box">
                                <span class="info-box-icon text-bg-info shadow-sm"><i class="bi bi-calendar-range"></i></span>
                                <div class="info-box-content">
                                    <span class="info-box-text" id="stat-beklenti-baslik">Beklenti</span>
                                    <span class="info-box-number" id="stat-beklenti">-</span>
                                </div>
                            </div>
                        </div>
                        <div class="col-12 col-sm-6 col-lg">
                            <div class="info-box">
                                <span class="info-box-icon text-bg-warning shadow-sm"><i class="bi bi-hourglass-split"></i></span>
                                <div class="info-box-content">
                                    <span class="info-box-text" id="stat-kalan-baslik">Kalan</span>
                                    <span class="info-box-number" id="stat-kalan">-</span>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Filtre -->
                    <div class="card card-primary card-outline mb-3">
                        <div class="card-header">
                            <h3 class="card-title"><i class="bi bi-funnel"></i> Filtrele</h3>
                            <div class="card-tools">
                                <button type="button" class="btn btn-tool" data-bs-toggle="collapse" data-bs-target="#filterCollapse">
                                    <i class="bi bi-chevron-down"></i>
                                </button>
                            </div>
                        </div>
                        <div class="card-body collapse show" id="filterCollapse">
                            <form id="filterForm">
                                <div class="row g-3">
                                    <div class="col-md-2">
                                        <label class="form-label">Sezon</label>
                                        <select class="form-select" id="filter_sezon">
                                            <?php foreach ($sezonlar as $szn): ?>
                                                <option value="<?= $szn['sezon_id'] ?>" <?= $szn['sezon_id'] == $defaultSezonId ? 'selected' : '' ?>><?= htmlspecialchars($szn['sezon_ad']) ?></option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                    <div class="col-md-2">
                                        <label class="form-label">Ay</label>
                                        <select class="form-select" id="filter_ay">
                                            <option value="">Tüm Aylar</option>
                                            <?php foreach ($ayBilgisi['liste'] as $a): ?>
                                                <option value="<?= $a['anahtar'] ?>" <?= $a['anahtar'] === $ayBilgisi['varsayilan'] ? 'selected' : '' ?>><?= htmlspecialchars($a['ad']) ?></option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                    <div class="col-md-2">
                                        <label class="form-label">Şehir</label>
                                        <select class="form-select" id="filter_sehir">
                                            <option value="">Tümü</option>
                                            <?php foreach ($sehirSecenekleri as $sh): ?>
                                                <option value="<?= $sh['sehir_id'] ?>"><?= htmlspecialchars($sh['sehir_adi']) ?></option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                    <div class="col-md-2">
                                        <label class="form-label">Cari Tip</label>
                                        <select class="form-select" id="filter_cari_tipi">
                                            <option value="">Tümü</option>
                                            <?php foreach ($cariTipleri as $ct): ?>
                                                <option value="<?= $ct['cari_tipi_id'] ?>" <?= $ct['cari_tipi_id'] == 1 ? 'selected' : '' ?>><?= htmlspecialchars($ct['cari_tipi_ad']) ?></option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                    <div class="col-md-4 d-flex align-items-end">
                                        <button type="submit" class="btn btn-primary me-2"><i class="bi bi-search"></i> Filtrele</button>
                                        <button type="button" class="btn btn-secondary" id="clearFilters"><i class="bi bi-x-circle"></i> Temizle</button>
                                    </div>
                                </div>
                            </form>
                        </div>
                    </div>

                    <!-- Rapor -->
                    <div class="card card-primary card-outline">
                        <div class="card-header">
                            <h3 class="card-title"><i class="bi bi-graph-up-arrow"></i> <span id="tabloBaslik">Ciro Beklentisi</span></h3>
                            <div class="card-tools">
                                <?php if ($canAdd): ?>
                                <button type="button" class="btn btn-sm btn-primary" id="btnEkle"><i class="bi bi-plus-lg"></i> Beklenti Ekle</button>
                                <?php endif; ?>
                                <?php if ($canEdit): ?>
                                <button type="button" class="btn btn-sm btn-warning" id="btnTopluDuzenle"><i class="bi bi-pencil-square"></i> Beklentileri Düzenle</button>
                                <?php endif; ?>
                                <button type="button" class="btn btn-sm btn-success" id="btnExport"><i class="bi bi-file-earmark-excel"></i> Excel</button>
                            </div>
                        </div>
                        <div class="card-body">
                            <table class="table table-bordered table-hover w-100" id="reportTable">
                                <thead>
                                    <tr>
                                        <th>Ay</th>
                                        <th>Şehir</th>
                                        <th class="text-end">Beklenti</th>
                                        <th class="text-center">Adet</th>
                                        <th class="text-end">Gerçekleşen</th>
                                        <th>Gerçekleşme</th>
                                        <th class="text-end">Kalan</th>
                                        <th class="text-center">İşlem</th>
                                    </tr>
                                </thead>
                                <tbody></tbody>
                                <tfoot>
                                    <tr>
                                        <th>TOPLAM</th>
                                        <th></th>
                                        <th class="text-end" id="f-beklenti"></th>
                                        <th class="text-center" id="f-adet"></th>
                                        <th class="text-end" id="f-gerceklesen"></th>
                                        <th id="f-oran"></th>
                                        <th class="text-end" id="f-kalan"></th>
                                        <th></th>
                                    </tr>
                                </tfoot>
                            </table>
                            <small class="text-muted">
                                Gerçekleşen: ilgili ayda o şehirdeki carilerin sözleşme tutarı. Kalan = Beklenti - Gerçekleşen (eksi değer beklentinin aşıldığını gösterir).
                                Başlangıç tarihi girilen aylarda yalnız o tarihten sonraki sözleşmeler sayılır.
                            </small>
                        </div>
                    </div>

                </div>
            </div>
        </main>

        <?php include __DIR__ . '/../includes/footer.php'; ?>
    </div>

    <?php if ($canAdd || $canEdit): ?>
    <!-- Tekil Beklenti Modal -->
    <div class="modal fade" id="beklentiModal" tabindex="-1">
        <div class="modal-dialog">
            <form class="modal-content" id="beklentiForm">
                <div class="modal-header">
                    <h5 class="modal-title" id="beklentiModalBaslik">Beklenti Ekle</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <input type="hidden" id="b_id">
                    <div class="row g-3">
                        <div class="col-12">
                            <label class="form-label">Şehir</label>
                            <select class="form-select" id="b_sehir" required>
                                <?php foreach ($sehirSecenekleri as $sh): ?>
                                    <option value="<?= $sh['sehir_id'] ?>"><?= htmlspecialchars($sh['sehir_adi']) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-6">
                            <label class="form-label">Yıl</label>
                            <input type="number" class="form-control" id="b_yil" min="2000" max="2100" required>
                        </div>
                        <div class="col-6">
                            <label class="form-label">Ay</label>
                            <select class="form-select" id="b_ay" required>
                                <?php foreach ($aylar as $no => $ad): ?>
                                    <option value="<?= $no ?>"><?= $ad ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-12">
                            <label class="form-label">Beklenti Tutarı (₺)</label>
                            <input type="text" class="form-control" id="b_tutar" inputmode="decimal" required placeholder="8.000.000">
                        </div>
                        <div class="col-12">
                            <label class="form-label">Başlangıç Tarihi <small class="text-muted">(opsiyonel)</small></label>
                            <input type="date" class="form-control" id="b_baslangic">
                            <div class="form-text">Ayın ortasında verilen "kalan" beklentilerde gerçekleşen bu tarihten itibaren sayılır.</div>
                        </div>
                        <div class="col-12">
                            <label class="form-label">Açıklama</label>
                            <input type="text" class="form-control" id="b_aciklama" maxlength="500">
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Vazgeç</button>
                    <button type="submit" class="btn btn-primary"><i class="bi bi-check-lg"></i> Kaydet</button>
                </div>
            </form>
        </div>
    </div>
    <?php endif; ?>

    <!-- Gerçekleşen Detay Modal -->
    <div class="modal fade" id="detayModal" tabindex="-1">
        <div class="modal-dialog modal-xl modal-dialog-scrollable">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title"><i class="bi bi-list-ul"></i> <span id="detayBaslik">Gerçekleşen Sözleşmeler</span></h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="row g-2 mb-3" id="detayOzet"></div>
                    <table class="table table-sm table-striped table-hover w-100" id="detayTable">
                        <thead>
                            <tr>
                                <th>Tarih</th>
                                <th>Sözleşme No</th>
                                <th>Cari</th>
                                <th>İlçe</th>
                                <th>Personel</th>
                                <th class="text-end">Tutar</th>
                                <th class="text-center">Görüntüle</th>
                            </tr>
                        </thead>
                        <tbody></tbody>
                        <tfoot>
                            <tr>
                                <th colspan="5" class="text-end">Toplam</th>
                                <th class="text-end" id="detayToplam"></th>
                                <th></th>
                            </tr>
                        </tfoot>
                    </table>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Kapat</button>
                </div>
            </div>
        </div>
    </div>

    <?php if ($canEdit): ?>
    <!-- Toplu Düzenleme Modal -->
    <div class="modal fade" id="topluModal" tabindex="-1">
        <div class="modal-dialog modal-xl modal-dialog-scrollable">
            <form class="modal-content" id="topluForm">
                <div class="modal-header">
                    <h5 class="modal-title"><i class="bi bi-pencil-square"></i> Beklentileri Düzenle</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="table-responsive">
                        <table class="table table-sm align-middle mb-0">
                            <thead>
                                <tr>
                                    <th style="min-width:160px">Şehir</th>
                                    <th style="width:100px">Yıl</th>
                                    <th style="min-width:130px">Ay</th>
                                    <th style="min-width:150px">Tutar (₺)</th>
                                    <th style="min-width:150px">Başlangıç</th>
                                    <th style="min-width:160px">Açıklama</th>
                                </tr>
                            </thead>
                            <tbody id="topluBody"></tbody>
                            <tfoot>
                                <tr class="fw-bold">
                                    <td colspan="3" class="text-end">Toplam</td>
                                    <td id="topluToplam" class="text-end"></td>
                                    <td colspan="2"></td>
                                </tr>
                            </tfoot>
                        </table>
                    </div>
                    <div class="form-text">Listede tabloda görünen (filtrelenmiş) kayıtlar yer alır. Silme işlemi tablodaki çöp kutusu ile yapılır.</div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Vazgeç</button>
                    <button type="submit" class="btn btn-primary"><i class="bi bi-check-lg"></i> Tümünü Kaydet</button>
                </div>
            </form>
        </div>
    </div>
    <?php endif; ?>

    <script src="https://cdn.jsdelivr.net/npm/jquery@3.7.1/dist/jquery.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/@popperjs/core@2.11.8/dist/umd/popper.min.js" crossorigin="anonymous"></script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.min.js" crossorigin="anonymous"></script>
    <script src="/admin/assets/js/adminlte.min.js"></script>
    <script src="https://cdn.datatables.net/1.13.7/js/jquery.dataTables.min.js"></script>
    <script src="https://cdn.datatables.net/1.13.7/js/dataTables.bootstrap5.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11/dist/sweetalert2.all.min.js"></script>
    <script src="/admin/assets/js/custom.js"></script>

    <script>
        const YETKI = { duzenle: <?= $canEdit ? 'true' : 'false' ?>, sil: <?= $canDelete ? 'true' : 'false' ?> };
        const SEHIR_SECENEK = <?= json_encode(array_map(fn($s) => ['id' => (int)$s['sehir_id'], 'ad' => $s['sehir_adi']], $sehirSecenekleri), JSON_UNESCAPED_UNICODE) ?>;
        const AY_SECENEK = <?= json_encode($aylar, JSON_UNESCAPED_UNICODE) ?>;
        const S2DIL = { noResults: () => 'Sonuç bulunamadı', searching: () => 'Aranıyor...' };
        let VARSAYILAN_AY = '<?= $ayBilgisi['varsayilan'] ?>';
        const VARSAYILAN = { sezon_id: '<?= $defaultSezonId ?>', cari_tipi_id: '1' };

        let currentFilters = { ...VARSAYILAN, ay: VARSAYILAN_AY };
        let table = null;
        let kayitlar = {};   // ay_beklenti_id => satır

        function formatTutar(amount) {
            if (amount === null || amount === undefined) return '-';
            return parseFloat(amount).toLocaleString('tr-TR', { maximumFractionDigits: 0 }) + ' ₺';
        }

        function esc(s) {
            return $('<div>').text(s ?? '').html();
        }

        function kalanHtml(kalan) {
            return `<span class="fw-bold ${kalan > 0 ? 'text-danger' : 'text-success'}">${formatTutar(kalan)}</span>`;
        }

        function oranHtml(oran) {
            if (oran === null || oran === undefined) return '-';
            const renk = oran >= 100 ? 'bg-success' : (oran >= 70 ? 'bg-warning' : 'bg-danger');
            return `<div class="d-flex align-items-center gap-2">
                        <div class="progress flex-grow-1"><div class="progress-bar ${renk}" style="width:${Math.min(oran, 100)}%"></div></div>
                        <span class="small fw-bold">%${oran.toLocaleString('tr-TR')}</span>
                    </div>`;
        }

        const DONEM = {
            gecmis: '<span class="badge text-bg-secondary ms-1">Geçti</span>',
            bu_ay: '<span class="badge text-bg-warning ms-1">Bu ay</span>',
            gelecek: ''
        };

        function seciliAyAdi() {
            return currentFilters.ay ? $('#filter_ay option:selected').text() : 'Tüm Aylar';
        }

        function initTable() {
            table = $('#reportTable').DataTable({
                data: [],
                dom: 'lrtip',
                scrollX: true,
                paging: true,
                pageLength: 25,
                lengthMenu: [[10, 25, 50, -1], [10, 25, 50, 'Tümü']],
                order: [[0, 'asc'], [2, 'desc']],
                language: { url: '//cdn.datatables.net/plug-ins/1.13.7/i18n/tr.json', emptyTable: 'Seçilen dönem için beklenti girilmemiş' },
                createdRow: (tr, row) => $(tr).addClass('donem-' + row.donem),
                // tr.json asenkron yüklenir; ilk veri tablo hazır olunca çekilir
                initComplete: () => yukle(),
                columns: [
                    {
                        data: 'ay_anahtar',
                        render: (v, type, row) => {
                            if (type !== 'display') return v;
                            const bas = row.baslangic ? `<div class="small text-muted">${row.baslangic.split('-').reverse().join('.')} itibarıyla</div>` : '';
                            return `<strong>${row.ay_adi}</strong>${DONEM[row.donem]}${bas}`;
                        }
                    },
                    {
                        data: 'sehir_adi',
                        render: (v, type, row) => type === 'display'
                            ? `<strong>${esc(v)}</strong>${row.aciklama ? `<div class="small text-muted">${esc(row.aciklama)}</div>` : ''}`
                            : v
                    },
                    { data: 'beklenti', className: 'text-end', render: (v, type) => type === 'display' ? formatTutar(v) : v },
                    { data: 'adet', className: 'text-center' },
                    {
                        data: 'gerceklesen', className: 'text-end',
                        render: (v, type, row) => type === 'display'
                            ? `<a href="#" class="gerceklesen-detay text-decoration-none" data-id="${row.id}" title="Sözleşmeleri görüntüle">${formatTutar(v)} <i class="bi bi-search small"></i></a>`
                            : v
                    },
                    { data: 'oran', render: (v, type) => type === 'display' ? oranHtml(v) : (v ?? 0) },
                    { data: 'kalan', className: 'text-end', render: (v, type) => type === 'display' ? kalanHtml(v) : v },
                    {
                        data: 'id', className: 'text-center text-nowrap', orderable: false,
                        visible: YETKI.duzenle || YETKI.sil,
                        render: id => {
                            let b = '';
                            if (YETKI.duzenle) b += `<button class="btn btn-sm btn-outline-primary me-1" onclick="duzenle(${id})" title="Düzenle"><i class="bi bi-pencil"></i></button>`;
                            if (YETKI.sil) b += `<button class="btn btn-sm btn-outline-danger" onclick="sil(${id})" title="Sil"><i class="bi bi-trash"></i></button>`;
                            return b;
                        }
                    }
                ]
            });
        }

        function loadStats() {
            $.post('', { action: 'stats', ...currentFilters }, function (r) {
                if (!r.success) return showToast(r.message, 'error');
                $('#stat-bolge-tutar').text(formatTutar(r.data.bolge_tutar));
                $('#stat-sezon-satis').text(formatTutar(r.data.sezon_satis));
                $('#stat-bolge-kalan').text(formatTutar(r.data.bolge_kalan));
            });
        }

        function loadReport() {
            $.post('', { action: 'report', ...currentFilters }, function (r) {
                if (!r.success) return showToast(r.message, 'error');
                kayitlar = {};
                r.data.forEach(row => { kayitlar[row.id] = row; });

                // Tek ay seçiliyse Ay kolonu gereksiz
                table.column(0).visible(!currentFilters.ay, false);
                table.clear().rows.add(r.data).draw();
                table.columns.adjust();

                const t = r.toplam;
                $('#f-beklenti').text(formatTutar(t.beklenti));
                $('#f-adet').text(t.adet);
                $('#f-gerceklesen').text(formatTutar(t.gerceklesen));
                $('#f-oran').html(oranHtml(t.oran));
                $('#f-kalan').html(kalanHtml(t.kalan));

                const ayAdi = seciliAyAdi();
                $('#tabloBaslik').text(ayAdi + ' - Şehir Bazında Ciro Beklentisi');
                $('#stat-beklenti-baslik').text(ayAdi + ' Beklentisi');
                $('#stat-beklenti').text(formatTutar(t.beklenti));
                $('#stat-kalan-baslik').text(currentFilters.ay ? ayAdi + ' Kalanı' : 'Kalan Beklenti');
                $('#stat-kalan').text(formatTutar(currentFilters.ay ? Math.max(0, t.kalan) : t.kalan_beklenti));
            });
        }

        function yukle() {
            loadReport();
            loadStats();
        }

        // Sezonun beklenti aylarını yükle; secili verilirse onu korur, yoksa varsayılan ay
        function loadAylar(secili, cb) {
            $.post('', { action: 'aylar', sezon_id: $('#filter_sezon').val() }, function (r) {
                if (!r.success) return showToast(r.message, 'error');
                VARSAYILAN_AY = r.data.varsayilan;
                const $s = $('#filter_ay').empty().append('<option value="">Tüm Aylar</option>');
                r.data.liste.forEach(a => $s.append(`<option value="${a.anahtar}">${a.ad}</option>`));
                const var_mi = secili === '' || r.data.liste.some(a => a.anahtar === secili);
                $s.val(secili !== undefined && var_mi ? secili : VARSAYILAN_AY).trigger('change.select2');
                if (cb) cb();
            });
        }

        function filtreleriOku() {
            currentFilters = {
                sezon_id: $('#filter_sezon').val(),
                ay: $('#filter_ay').val(),
                sehir_id: $('#filter_sehir').val(),
                cari_tipi_id: $('#filter_cari_tipi').val()
            };
        }

        // ---- Gerçekleşen detayı ----
        let detayTable = null;

        $('#reportTable').on('click', '.gerceklesen-detay', function (e) {
            e.preventDefault();
            const k = kayitlar[$(this).data('id')];
            if (!k) return;

            $('#detayBaslik').text(`${k.sehir_adi} - ${k.ay_adi} Gerçekleşen Sözleşmeler`);
            const ozet = (baslik, deger, renk) =>
                `<div class="col-6 col-md-3"><div class="border rounded p-2 h-100"><div class="small text-muted">${baslik}</div><div class="fw-bold ${renk || ''}">${deger}</div></div></div>`;
            $('#detayOzet').html(
                ozet('Beklenti', formatTutar(k.beklenti)) +
                ozet('Gerçekleşen', formatTutar(k.gerceklesen) + ` <span class="small text-muted">(${k.adet} sözleşme)</span>`) +
                ozet('Kalan', formatTutar(k.kalan), k.kalan > 0 ? 'text-danger' : 'text-success') +
                ozet('Sayım Başlangıcı', k.baslangic ? k.baslangic.split('-').reverse().join('.') : 'Ay başı')
            );

            const modal = bootstrap.Modal.getOrCreateInstance(document.getElementById('detayModal'));
            if (detayTable) detayTable.clear().draw();
            modal.show();

            $.post('', { action: 'detay', id: k.id, sezon_id: currentFilters.sezon_id, cari_tipi_id: currentFilters.cari_tipi_id }, function (r) {
                if (!r.success) return showToast(r.message, 'error');
                let toplam = 0;
                r.data.forEach(d => { toplam += parseFloat(d.tutar) || 0; });
                $('#detayToplam').text(formatTutar(toplam));

                if (!detayTable) {
                    detayTable = $('#detayTable').DataTable({
                        data: r.data,
                        dom: 'lrtip',
                        pageLength: 25,
                        order: [],
                        language: { url: '//cdn.datatables.net/plug-ins/1.13.7/i18n/tr.json' },
                        columns: [
                            {
                                data: 'sozlesme_tarih',
                                // dd.mm.yyyy -> sıralama için yyyymmdd
                                render: (v, type) => type === 'sort' ? (v || '').split('.').reverse().join('') : v
                            },
                            { data: 'sozlesme_no', render: v => esc(v || '-') },
                            { data: 'cari_adi', render: v => esc(v) },
                            { data: 'ilce_adi', render: v => esc(v) },
                            { data: 'personel_adi', render: v => esc(v) },
                            { data: 'tutar', className: 'text-end', render: (v, type) => type === 'display' ? formatTutar(v) : parseFloat(v) },
                            {
                                data: 'sozlesme_id', className: 'text-center', orderable: false,
                                render: id => `<a href="/admin/pages/sozlesme-form.php?id=${id}" class="btn btn-sm btn-outline-primary" target="_blank" title="Görüntüle"><i class="bi bi-eye"></i></a>`
                            }
                        ]
                    });
                } else {
                    detayTable.clear().rows.add(r.data).draw();
                }
            });
        });

        $('#detayModal').on('shown.bs.modal', () => detayTable && detayTable.columns.adjust());

        // ---- Tekil ekle / düzenle / sil ----
        function modalAc(h) {
            const bugun = new Date();
            const [fy, fa] = (currentFilters.ay || '').split('-');
            $('#beklentiModalBaslik').text(h ? 'Beklenti Düzenle' : 'Beklenti Ekle');
            $('#b_id').val(h ? h.id : '');
            $('#b_sehir').val(h ? h.sehir_id : ($('#filter_sehir').val() || $('#b_sehir option:first').val())).trigger('change.select2');
            $('#b_yil').val(h ? h.yil : (fy || bugun.getFullYear()));
            $('#b_ay').val(h ? h.ay : (fa ? +fa : bugun.getMonth() + 1)).trigger('change.select2');
            $('#b_tutar').val(h ? h.beklenti.toLocaleString('tr-TR', { maximumFractionDigits: 2 }) : '');
            $('#b_baslangic').val(h ? (h.baslangic || '') : '');
            $('#b_aciklama').val(h ? (h.aciklama || '') : '');
            bootstrap.Modal.getOrCreateInstance(document.getElementById('beklentiModal')).show();
        }

        function duzenle(id) {
            modalAc(kayitlar[id]);
        }

        function kayitSonrasi() {
            loadAylar(currentFilters.ay, yukle);
        }

        function sil(id) {
            const h = kayitlar[id];
            Swal.fire({
                title: 'Silinsin mi?',
                text: `${h.sehir_adi} - ${h.ay_adi} beklentisi (${formatTutar(h.beklenti)}) silinecek.`,
                icon: 'warning',
                showCancelButton: true,
                confirmButtonText: 'Sil',
                cancelButtonText: 'Vazgeç',
                confirmButtonColor: '#dc3545'
            }).then(res => {
                if (!res.isConfirmed) return;
                $.post('', { action: 'delete', id, sezon_id: currentFilters.sezon_id }, function (r) {
                    showToast(r.message, r.success ? 'success' : 'error');
                    if (r.success) kayitSonrasi();
                });
            });
        }

        $('#btnEkle').on('click', () => modalAc(null));

        $('#beklentiForm').on('submit', function (e) {
            e.preventDefault();
            $.post('', {
                action: 'save',
                sezon_id: currentFilters.sezon_id,
                id: $('#b_id').val(),
                sehir_id: $('#b_sehir').val(),
                yil: $('#b_yil').val(),
                ay: $('#b_ay').val(),
                tutar: $('#b_tutar').val(),
                baslangic: $('#b_baslangic').val(),
                aciklama: $('#b_aciklama').val()
            }, function (r) {
                showToast(r.message, r.success ? 'success' : 'error');
                if (r.success) {
                    bootstrap.Modal.getInstance(document.getElementById('beklentiModal')).hide();
                    kayitSonrasi();
                }
            });
        });

        // ---- Toplu düzenleme ----
        function tutarSayi(v) {
            return parseFloat(String(v || '').replace(/[.\s]/g, '').replace(',', '.')) || 0;
        }

        function topluToplamGuncelle() {
            let t = 0;
            $('#topluBody .t-tutar').each(function () { t += tutarSayi($(this).val()); });
            $('#topluToplam').text(formatTutar(t));
        }

        $('#btnTopluDuzenle').on('click', function () {
            const liste = Object.values(kayitlar).sort((a, b) =>
                a.ay_anahtar.localeCompare(b.ay_anahtar) || b.beklenti - a.beklenti);
            if (!liste.length) return showToast('Düzenlenecek beklenti yok', 'warning');

            let html = '';
            liste.forEach(h => {
                const sehirOpt = SEHIR_SECENEK.map(s => `<option value="${s.id}" ${s.id === h.sehir_id ? 'selected' : ''}>${esc(s.ad)}</option>`).join('');
                const ayOpt = Object.entries(AY_SECENEK).map(([no, ad]) => `<option value="${no}" ${+no === h.ay ? 'selected' : ''}>${ad}</option>`).join('');
                html += `<tr data-id="${h.id}">
                    <td><select class="form-select form-select-sm t-sehir">${sehirOpt}</select></td>
                    <td><input type="number" class="form-control form-control-sm t-yil" min="2000" max="2100" value="${h.yil}" required></td>
                    <td><select class="form-select form-select-sm t-ay">${ayOpt}</select></td>
                    <td><input type="text" class="form-control form-control-sm text-end t-tutar" inputmode="decimal" required value="${h.beklenti.toLocaleString('tr-TR', { maximumFractionDigits: 2 })}"></td>
                    <td><input type="date" class="form-control form-control-sm t-baslangic" value="${h.baslangic || ''}"></td>
                    <td><input type="text" class="form-control form-control-sm t-aciklama" maxlength="500" value="${esc(h.aciklama)}"></td>
                </tr>`;
            });
            $('#topluBody').html(html);
            $('#topluBody .t-sehir, #topluBody .t-ay').select2({ theme: 'bootstrap-5', language: S2DIL, dropdownParent: $('#topluModal'), width: '100%' });
            topluToplamGuncelle();
            bootstrap.Modal.getOrCreateInstance(document.getElementById('topluModal')).show();
        });

        $('#topluBody').on('input', '.t-tutar', topluToplamGuncelle);

        $('#topluForm').on('submit', function (e) {
            e.preventDefault();
            const satirlar = $('#topluBody tr').map(function () {
                const $tr = $(this);
                return {
                    id: $tr.data('id'),
                    sehir_id: $tr.find('.t-sehir').val(),
                    yil: $tr.find('.t-yil').val(),
                    ay: $tr.find('.t-ay').val(),
                    tutar: $tr.find('.t-tutar').val(),
                    baslangic: $tr.find('.t-baslangic').val(),
                    aciklama: $tr.find('.t-aciklama').val()
                };
            }).get();

            $.post('', { action: 'save_toplu', sezon_id: currentFilters.sezon_id, satirlar: JSON.stringify(satirlar) }, function (r) {
                showToast(r.message, r.success ? 'success' : 'error');
                if (r.success) {
                    bootstrap.Modal.getInstance(document.getElementById('topluModal')).hide();
                    kayitSonrasi();
                }
            });
        });

        // ---- Excel (DataTable verisinden, filtre sırasıyla) ----
        $('#btnExport').on('click', function () {
            const satirlar = [['Ay', 'Şehir', 'Beklenti', 'Adet', 'Gerçekleşen', 'Gerçekleşme %', 'Kalan', 'Başlangıç', 'Açıklama']];
            table.rows({ search: 'applied', order: 'applied' }).data().each(r => {
                satirlar.push([r.ay_adi, r.sehir_adi, r.beklenti, r.adet, r.gerceklesen, r.oran ?? '', r.kalan, r.baslangic || '', r.aciklama || '']);
            });
            const csv = satirlar.map(s => s.map(v => '"' + String(v).replace(/"/g, '""') + '"').join(';')).join('\n');
            const blob = new Blob(['﻿' + csv], { type: 'text/csv;charset=utf-8;' });
            const a = document.createElement('a');
            a.href = URL.createObjectURL(blob);
            a.download = 'kalan-ay-ciro-beklentisi' + (currentFilters.ay ? '-' + currentFilters.ay : '') + '.csv';
            a.click();
            URL.revokeObjectURL(a.href);
            showToast('Excel dosyası indirildi', 'success');
        });

        // ---- Filtre ----
        $('#filterForm').on('submit', function (e) {
            e.preventDefault();
            filtreleriOku();
            yukle();
            showToast('Filtre uygulandı', 'info');
        });

        $('#clearFilters').on('click', function () {
            $('#filter_sezon').val(VARSAYILAN.sezon_id).trigger('change.select2');
            $('#filter_sehir').val('').trigger('change.select2');
            $('#filter_cari_tipi').val(VARSAYILAN.cari_tipi_id).trigger('change.select2');
            loadAylar(undefined, function () {
                filtreleriOku();
                yukle();
                showToast('Filtreler temizlendi', 'info');
            });
        });

        $(document).ready(function () {
            $('#filter_sezon, #filter_ay, #filter_sehir, #filter_cari_tipi').select2({ theme: 'bootstrap-5', language: S2DIL });
            $('#b_sehir, #b_ay').select2({ theme: 'bootstrap-5', language: S2DIL, dropdownParent: $('#beklentiModal') });

            // Sezon değişince o sezonun ayları gelir (varsayılan: bu ay)
            $('#filter_sezon').on('change', () => loadAylar());

            initTable();
        });
    </script>
</body>
</html>
