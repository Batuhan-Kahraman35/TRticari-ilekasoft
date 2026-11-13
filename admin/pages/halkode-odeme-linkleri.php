<?php
/**
 * Admin Panel - HalkÖde Ödeme Linkleri
 *
 * Sözleşme için kredi kartı ödeme linki oluşturma, müşteriye SMS/e-posta ile
 * gönderme, durum sorgulama ve iptal.
 *
 * Ödeme sonucu callback (admin/api/halkode-callback.php) veya cron görevi
 * (halkode_durum_sorgula) ile düşer ve sözleşmeye Kredi Kartı ödeme satırı
 * açılır. Bu sayfadaki "Durumu Sorgula" aynı idempotent akışı elle tetikler.
 *
 * Sözleşme formundan ?sozlesme_id=N ile açılırsa yeni link penceresi o
 * sözleşme seçili olarak gelir.
 *
 * @see admin/includes/HalkodeService.php
 */

require_once __DIR__ . '/../auth.php';
require_once __DIR__ . '/../includes/PageAuth.php';
require_once __DIR__ . '/../includes/HalkodeService.php';
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

$pageInfo = $db->fetchOne("
    SELECT s.sayfalar_sayfa_adi, m.menuler_menu_adi AS menu_adi
    FROM Menu_Sayfalar s
    LEFT JOIN Menuler m ON s.sayfalar_menu_id = m.menuler_id
    WHERE s.sayfalar_sayfa_url LIKE ? AND s.sayfalar_durum = 1
", ['%' . $currentPageFile]);

$pageTitle    = $pageInfo['sayfalar_sayfa_adi'] ?? 'HalkÖde Ödeme Linkleri';
$menuAdi      = $pageInfo['menu_adi'] ?? null;
$siteAyarlari = $db->fetchOne("SELECT TOP 1 site_ayarlari_site_title FROM dbo.tanim_site_ayarlari ORDER BY site_ayarlari_id DESC");
$siteTitle    = $siteAyarlari['site_ayarlari_site_title'] ?? 'Ornek Yazilim Portal';

/** Link durumları: kod => [renk, ad] */
$durumlar = [
    HalkodeService::DURUM_OLUSTURULDU => ['secondary', 'Oluşturuldu'],
    HalkodeService::DURUM_GONDERILDI  => ['info',      'Gönderildi'],
    HalkodeService::DURUM_ODENDI      => ['success',   'Ödendi'],
    HalkodeService::DURUM_BASARISIZ   => ['danger',    'Başarısız'],
    HalkodeService::DURUM_IPTAL       => ['dark',      'İptal'],
];

/**
 * "Kendi kayıtlarını gör" yetkisinde kısıt: sözleşmenin personeli veya
 * linki oluşturan kullanıcı. Yetki yoksa boş döner.
 */
function halkodeYetkiKosul(array &$params, string $sozlesmeKolon = 'h.Halkode_Odeme_Linkleri_Sozlesme_id', string $olusturanKolon = 'h.OlusturanKullanici'): string
{
    global $pagePermissions, $user;

    if (empty($pagePermissions['can_view_own_records'])) {
        return '';
    }

    $uid      = (int)$user['kullanici_id'];
    $params[] = $uid;
    $params[] = $uid;

    return " AND ({$olusturanKolon} = ?
                  OR EXISTS (SELECT 1 FROM dbo.Sozlesmeler sy
                             WHERE sy.sozlesme_id = {$sozlesmeKolon} AND sy.sozlesme_personel_id = ?)) ";
}

/** Filtre koşulları: liste, sayım ve Excel aynı koşulu kullanır */
function halkodeFiltreKosul(array $girdi, array &$params): string
{
    $params = [];
    $where  = " WHERE h.Durum = 1 " . halkodeYetkiKosul($params);

    if (!empty($girdi['cari_id'])) {
        $where   .= " AND h.Halkode_Odeme_Linkleri_Cari_id = ? ";
        $params[] = (int)$girdi['cari_id'];
    }
    if (!empty($girdi['sozlesme_id'])) {
        $where   .= " AND h.Halkode_Odeme_Linkleri_Sozlesme_id = ? ";
        $params[] = (int)$girdi['sozlesme_id'];
    }
    if (!empty($girdi['durum'])) {
        $where   .= " AND h.Halkode_Odeme_Linkleri_DurumKod = ? ";
        $params[] = (string)$girdi['durum'];
    }
    if (!empty($girdi['taksitli'])) {
        $where .= " AND h.Halkode_Odeme_Linkleri_Taksit > 1 ";
    }
    if (!empty($girdi['baslangic_tarih'])) {
        $where   .= " AND h.OlusturmaTarihi >= ? ";
        $params[] = $girdi['baslangic_tarih'];
    }
    if (!empty($girdi['bitis_tarih'])) {
        $where   .= " AND h.OlusturmaTarihi < DATEADD(DAY, 1, CAST(? AS DATE)) ";
        $params[] = $girdi['bitis_tarih'];
    }
    if (isset($girdi['min_tutar']) && $girdi['min_tutar'] !== '') {
        $where   .= " AND h.Halkode_Odeme_Linkleri_Tutar >= ? ";
        $params[] = (float)$girdi['min_tutar'];
    }
    if (isset($girdi['max_tutar']) && $girdi['max_tutar'] !== '') {
        $where   .= " AND h.Halkode_Odeme_Linkleri_Tutar <= ? ";
        $params[] = (float)$girdi['max_tutar'];
    }
    if (!empty($girdi['arama'])) {
        $arama  = '%' . trim((string)$girdi['arama']) . '%';
        $where .= " AND (
                        h.Halkode_Odeme_Linkleri_SiparisNo LIKE ?
                     OR h.Halkode_Odeme_Linkleri_ProcessId LIKE ?
                     OR h.Halkode_Odeme_Linkleri_IslemNo   LIKE ?
                     OR h.Halkode_Odeme_Linkleri_Aciklama  LIKE ?
                     OR h.Halkode_Odeme_Linkleri_Telefon   LIKE ?
                     OR h.Halkode_Odeme_Linkleri_Email     LIKE ?
                    ) ";
        array_push($params, $arama, $arama, $arama, $arama, $arama, $arama);
    }

    return $where;
}

/** Sözleşmeye erişim (kendi kayıtları yetkisinde personel kısıtı) */
function halkodeSozlesmeErisim($db, int $sozlesmeId): ?array
{
    global $pagePermissions, $user;

    $sql    = "SELECT s.sozlesme_id, s.sozlesme_no, s.sozlesme_cari_id, s.sozlesme_tarih,
                      ISNULL(NULLIF(c.cari_unvan, ''), c.cari_adi) AS unvan
               FROM dbo.Sozlesmeler s
               INNER JOIN dbo.Cari c ON c.cari_id = s.sozlesme_cari_id
               WHERE s.sozlesme_id = ?";
    $params = [$sozlesmeId];

    if (!empty($pagePermissions['can_view_own_records'])) {
        $sql     .= " AND s.sozlesme_personel_id = ?";
        $params[] = (int)$user['kullanici_id'];
    }

    return $db->fetchOne($sql, $params) ?: null;
}

// ═══════════════════════════════════════════════════
// AJAX - DataTables (server-side)
// ═══════════════════════════════════════════════════
if (($_GET['ajax'] ?? '') === '1') {
    header('Content-Type: application/json; charset=utf-8');

    $draw   = (int)($_POST['draw'] ?? 1);
    $start  = max(0, (int)($_POST['start'] ?? 0));
    $length = (int)($_POST['length'] ?? 25);

    if ($length < 1 || $length > 500) {
        $length = 25;
    }

    $params = [];
    $where  = halkodeFiltreKosul($_POST, $params);

    // Sıralama kolonu whitelist ile çözülür
    $kolonlar = [
        0 => 'h.OlusturmaTarihi',
        1 => 'h.Halkode_Odeme_Linkleri_Cari_id',
        2 => 'h.Halkode_Odeme_Linkleri_Tutar',
        3 => 'h.Halkode_Odeme_Linkleri_Taksit',
        4 => 'h.Halkode_Odeme_Linkleri_DurumKod',
        5 => 'h.Halkode_Odeme_Linkleri_GonderimTarihi',
        6 => 'h.Halkode_Odeme_Linkleri_OdemeTarihi',
    ];

    $siraKolon = $kolonlar[(int)($_POST['order'][0]['column'] ?? 0)] ?? 'h.OlusturmaTarihi';
    $siraYon   = strtoupper((string)($_POST['order'][0]['dir'] ?? 'desc')) === 'ASC' ? 'ASC' : 'DESC';

    // 1. aşama: yalnız filtre + sıralama + sayfalama ile o sayfanın id'leri
    // 2. aşama: cari ve sözleşme bilgisi yalnız bu satırlara eklenir
    $kayitlar = $db->fetchAll("
        WITH sayfa AS (
            SELECT h.Halkode_Odeme_Linkleri_id AS id
            FROM dbo.Halkode_Odeme_Linkleri h
            $where
            ORDER BY $siraKolon $siraYon, h.Halkode_Odeme_Linkleri_id DESC
            OFFSET $start ROWS FETCH NEXT $length ROWS ONLY
        )
        SELECT
            h.Halkode_Odeme_Linkleri_id             AS id,
            h.Halkode_Odeme_Linkleri_SiparisNo      AS siparis_no,
            h.Halkode_Odeme_Linkleri_Sozlesme_id    AS sozlesme_id,
            s.sozlesme_no                           AS sozlesme_no,
            ISNULL(NULLIF(c.cari_unvan, ''), c.cari_adi) AS cari_unvan,
            h.Halkode_Odeme_Linkleri_Tutar          AS tutar,
            h.Halkode_Odeme_Linkleri_ParaBirimi     AS para_birimi,
            h.Halkode_Odeme_Linkleri_Taksit         AS taksit,
            h.Halkode_Odeme_Linkleri_DurumKod       AS durum,
            h.Halkode_Odeme_Linkleri_Link           AS link,
            h.Halkode_Odeme_Linkleri_GonderimKanali AS gonderim_kanali,
            CONVERT(VARCHAR(19), h.Halkode_Odeme_Linkleri_GonderimTarihi, 120) AS gonderim_tarihi,
            CONVERT(VARCHAR(19), h.Halkode_Odeme_Linkleri_OdemeTarihi, 120)    AS odeme_tarihi,
            CONVERT(VARCHAR(19), h.OlusturmaTarihi, 120)                       AS olusturma,
            h.Halkode_Odeme_Linkleri_IslemNo        AS islem_no,
            h.Halkode_Odeme_Linkleri_SonucMesaj     AS sonuc_mesaj,
            h.Halkode_Odeme_Linkleri_Aciklama       AS aciklama,
            h.Halkode_Odeme_Linkleri_Odeme_id       AS odeme_id
        FROM sayfa x
        INNER JOIN dbo.Halkode_Odeme_Linkleri h ON h.Halkode_Odeme_Linkleri_id = x.id
        LEFT JOIN dbo.Cari c        ON c.cari_id = h.Halkode_Odeme_Linkleri_Cari_id
        LEFT JOIN dbo.Sozlesmeler s ON s.sozlesme_id = h.Halkode_Odeme_Linkleri_Sozlesme_id
        ORDER BY $siraKolon $siraYon, h.Halkode_Odeme_Linkleri_id DESC
    ", $params);

    // Sayımlar JOIN'siz, tek ana tablodan
    $toplamParams = [];
    $toplamWhere  = " WHERE h.Durum = 1 " . halkodeYetkiKosul($toplamParams);
    $toplam = (int)($db->fetchOne("SELECT COUNT(*) AS adet FROM dbo.Halkode_Odeme_Linkleri h $toplamWhere", $toplamParams)['adet'] ?? 0);
    $filtre = (int)($db->fetchOne("SELECT COUNT(*) AS adet FROM dbo.Halkode_Odeme_Linkleri h $where", $params)['adet'] ?? 0);

    echo json_encode([
        'draw'            => $draw,
        'recordsTotal'    => $toplam,
        'recordsFiltered' => $filtre,
        'data'            => $kayitlar,
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

// ═══════════════════════════════════════════════════
// AJAX - InfoBox sayımları (tablodan bağımsız)
// ═══════════════════════════════════════════════════
if (($_GET['ajax'] ?? '') === 'stats') {
    header('Content-Type: application/json; charset=utf-8');

    $ozetParams = [];
    $ozetYetki  = halkodeYetkiKosul($ozetParams);

    $ozet = $db->fetchOne("
        SELECT
            COUNT(*) AS toplam,
            ISNULL(SUM(CASE WHEN h.Halkode_Odeme_Linkleri_DurumKod = 'ODENDI'
                            THEN h.Halkode_Odeme_Linkleri_Tutar END), 0) AS odenen_tutar,
            SUM(CASE WHEN h.Halkode_Odeme_Linkleri_DurumKod IN ('OLUSTURULDU', 'GONDERILDI') THEN 1 ELSE 0 END) AS bekleyen,
            SUM(CASE WHEN h.Halkode_Odeme_Linkleri_DurumKod = 'BASARISIZ' THEN 1 ELSE 0 END) AS basarisiz
        FROM dbo.Halkode_Odeme_Linkleri h
        WHERE h.Durum = 1 $ozetYetki
    ", $ozetParams);

    echo json_encode(['success' => true, 'ozet' => $ozet], JSON_UNESCAPED_UNICODE);
    exit;
}

// ═══════════════════════════════════════════════════
// AJAX - Sözleşme arama (Select2)
// ═══════════════════════════════════════════════════
if (($_GET['ajax'] ?? '') === 'sozlesme_ara') {
    header('Content-Type: application/json; charset=utf-8');

    $q      = trim((string)($_GET['q'] ?? ''));
    $params = [];
    $where  = " WHERE ISNULL(s.sozlesme_durum, 1) = 1 ";

    if ($q !== '') {
        $where .= " AND (s.sozlesme_no LIKE ? OR c.cari_unvan LIKE ? OR c.cari_adi LIKE ? OR c.cari_telefon LIKE ?) ";
        array_push($params, "%$q%", "%$q%", "%$q%", "%$q%");
    }
    if (!empty($pagePermissions['can_view_own_records'])) {
        $where   .= " AND s.sozlesme_personel_id = ? ";
        $params[] = (int)$user['kullanici_id'];
    }

    $satirlar = $db->fetchAll("
        SELECT TOP 30 s.sozlesme_id AS id,
               CONCAT(ISNULL(s.sozlesme_no, CONCAT('#', s.sozlesme_id)), ' - ',
                      ISNULL(NULLIF(c.cari_unvan, ''), c.cari_adi),
                      ' (', CONVERT(VARCHAR(10), s.sozlesme_tarih, 104), ')') AS text
        FROM dbo.Sozlesmeler s
        INNER JOIN dbo.Cari c ON c.cari_id = s.sozlesme_cari_id
        $where
        ORDER BY s.sozlesme_id DESC
    ", $params);

    echo json_encode(['results' => $satirlar], JSON_UNESCAPED_UNICODE);
    exit;
}

// ═══════════════════════════════════════════════════
// AJAX - Cari arama (filtre, Select2)
// ═══════════════════════════════════════════════════
if (($_GET['ajax'] ?? '') === 'cari_ara') {
    header('Content-Type: application/json; charset=utf-8');

    $q      = trim((string)($_GET['q'] ?? ''));
    $params = [];
    $yetki  = halkodeYetkiKosul($params);
    $ek     = '';

    if ($q !== '') {
        $ek = " AND (c.cari_unvan LIKE ? OR c.cari_adi LIKE ?) ";
        array_push($params, "%$q%", "%$q%");
    }

    $satirlar = $db->fetchAll("
        SELECT TOP 30 c.cari_id AS id, ISNULL(NULLIF(c.cari_unvan, ''), c.cari_adi) AS text
        FROM dbo.Cari c
        WHERE EXISTS (SELECT 1 FROM dbo.Halkode_Odeme_Linkleri h
                      WHERE h.Halkode_Odeme_Linkleri_Cari_id = c.cari_id AND h.Durum = 1 $yetki)
        $ek
        ORDER BY 2
    ", $params);

    echo json_encode(['results' => $satirlar], JSON_UNESCAPED_UNICODE);
    exit;
}

// ═══════════════════════════════════════════════════
// AJAX - İşlemler
// ═══════════════════════════════════════════════════
$islemler = ['sozlesme_bilgi', 'olustur', 'detay', 'sorgula', 'gonder', 'iptal'];

if (isset($_POST['action']) && in_array($_POST['action'], $islemler, true)) {
    header('Content-Type: application/json; charset=utf-8');

    $islem = (string)$_POST['action'];

    if ($islem === 'olustur' && !$pagePermissions['can_add']) {
        echo json_encode(['success' => false, 'message' => 'Link oluşturma yetkiniz yok!']);
        exit;
    }
    if (in_array($islem, ['sorgula', 'gonder', 'iptal'], true) && !$pagePermissions['can_edit']) {
        echo json_encode(['success' => false, 'message' => 'Bu işlem için düzenleme yetkiniz yok!']);
        exit;
    }

    $kanallarGonder = function (): array {
        return array_values(array_intersect(
            (array)json_decode((string)($_POST['kanallar'] ?? '[]'), true),
            ['sms', 'mail']
        ));
    };

    try {
        // ── Sözleşme seçilince: gönderim kişileri
        if ($islem === 'sozlesme_bilgi') {
            $sozlesme = halkodeSozlesmeErisim($db, (int)($_POST['sozlesme_id'] ?? 0));

            if (!$sozlesme) {
                throw new Exception('Sözleşme bulunamadı veya erişim yetkiniz yok.');
            }

            $servis = new HalkodeService($db);

            echo json_encode([
                'success'  => true,
                'sozlesme' => $sozlesme,
                'kisiler'  => $servis->iletisimKisileri((int)$sozlesme['sozlesme_cari_id']),
            ], JSON_UNESCAPED_UNICODE);
            exit;
        }

        // ── Yeni link
        if ($islem === 'olustur') {
            $sozlesmeId = (int)($_POST['sozlesme_id'] ?? 0);

            if (!halkodeSozlesmeErisim($db, $sozlesmeId)) {
                throw new Exception('Sözleşme bulunamadı veya erişim yetkiniz yok.');
            }

            $servis = new HalkodeService($db, (int)($_POST['kanal_id'] ?? 0));
            $sonuc  = $servis->linkOlustur([
                'sozlesme_id'  => $sozlesmeId,
                'tutar'        => str_replace(',', '.', (string)($_POST['tutar'] ?? '0')),
                'taksit'       => (int)($_POST['taksit'] ?? 0),
                'aciklama'     => (string)($_POST['aciklama'] ?? ''),
                'telefon'      => (string)($_POST['telefon'] ?? ''),
                'email'        => (string)($_POST['email'] ?? ''),
                'kullanici_id' => (int)$user['kullanici_id'],
            ]);

            $gonderim = null;
            $kanallar = $kanallarGonder();

            if ($kanallar) {
                $gonderim = $servis->linkGonder($sonuc['link_id'], $kanallar, (int)$user['kullanici_id']);
            }

            echo json_encode([
                'success'  => true,
                'message'  => 'Ödeme linki oluşturuldu.',
                'link'     => $sonuc['link'],
                'link_id'  => $sonuc['link_id'],
                'gonderim' => $gonderim,
            ], JSON_UNESCAPED_UNICODE);
            exit;
        }

        // ── Mevcut link işlemleri
        $linkId = (int)($_POST['link_id'] ?? 0);
        $erisimParams = [$linkId];
        $erisimYetki  = halkodeYetkiKosul($erisimParams);

        $erisim = $db->fetchOne("
            SELECT h.Halkode_Odeme_Linkleri_Kanal_id AS kanal_id
            FROM dbo.Halkode_Odeme_Linkleri h
            WHERE h.Halkode_Odeme_Linkleri_id = ? AND h.Durum = 1 $erisimYetki
        ", $erisimParams);

        if (!$erisim) {
            throw new Exception('Ödeme linki bulunamadı veya erişim yetkiniz yok.');
        }

        $servis = new HalkodeService($db, (int)$erisim['kanal_id']);
        $link   = $servis->linkGetir($linkId);

        switch ($islem) {
            case 'detay':
                echo json_encode([
                    'success' => true,
                    'data'    => $link,
                    'kisiler' => $servis->iletisimKisileri((int)$link['Halkode_Odeme_Linkleri_Cari_id']),
                ], JSON_UNESCAPED_UNICODE);
                break;

            case 'sorgula':
                $sonuc = $servis->durumSorgulaVeIsle($linkId, (int)$user['kullanici_id']);

                if ($sonuc['durum'] === HalkodeService::DURUM_ODENDI) {
                    $mesaj = $sonuc['islendi']
                        ? 'Ödeme alındı, sözleşmeye ödeme satırı eklendi.'
                        : 'Ödeme daha önce işlenmiş, yeni kayıt açılmadı.';
                } else {
                    $mesaj = $sonuc['mesaj'] ?: 'Ödeme henüz tamamlanmamış.';
                }

                echo json_encode(['success' => true, 'durum' => $sonuc['durum'], 'message' => $mesaj], JSON_UNESCAPED_UNICODE);
                break;

            case 'gonder':
                if ($link['Halkode_Odeme_Linkleri_DurumKod'] === HalkodeService::DURUM_ODENDI) {
                    throw new Exception('Bu link için ödeme alınmış, yeniden gönderilemez.');
                }
                if ($link['Halkode_Odeme_Linkleri_DurumKod'] === HalkodeService::DURUM_IPTAL) {
                    throw new Exception('İptal edilmiş link gönderilemez.');
                }

                $kanallar = $kanallarGonder();
                if (!$kanallar) {
                    throw new Exception('Gönderim kanalı seçilmedi.');
                }

                $sonuc = $servis->linkGonder($linkId, $kanallar, (int)$user['kullanici_id'], [
                    'telefon' => trim((string)($_POST['telefon'] ?? '')),
                    'email'   => trim((string)($_POST['email'] ?? '')),
                ]);

                $basari = [];
                if (!empty($sonuc['sms']))  $basari[] = 'SMS';
                if (!empty($sonuc['mail'])) $basari[] = 'E-posta';

                echo json_encode([
                    'success' => (bool)$basari,
                    'message' => $basari ? implode(' ve ', $basari) . ' ile gönderildi.' : implode(' ', $sonuc['hatalar']),
                    'hatalar' => $sonuc['hatalar'],
                ], JSON_UNESCAPED_UNICODE);
                break;

            case 'iptal':
                if ($link['Halkode_Odeme_Linkleri_DurumKod'] === HalkodeService::DURUM_ODENDI) {
                    throw new Exception('Ödemesi alınmış link iptal edilemez.');
                }

                // Yalnız takip kaydı iptal edilir; HalkÖde tarafındaki link süresi dolana kadar açık kalabilir.
                // Bu sürede ödeme gelirse callback yine işler ve satır açılır.
                $db->update('Halkode_Odeme_Linkleri', [
                    'Halkode_Odeme_Linkleri_DurumKod'   => HalkodeService::DURUM_IPTAL,
                    'Halkode_Odeme_Linkleri_SonucMesaj' => 'Kullanıcı tarafından iptal edildi.',
                    'GuncelleyenKullanici'              => (int)$user['kullanici_id'],
                    'GuncellemeTarihi'                  => date('Y-m-d H:i:s'),
                ], ['Halkode_Odeme_Linkleri_id' => $linkId]);

                echo json_encode(['success' => true, 'message' => 'Link iptal edildi.'], JSON_UNESCAPED_UNICODE);
                break;
        }
    } catch (Throwable $e) {
        echo json_encode(['success' => false, 'message' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
    }

    exit;
}

// ═══════════════════════════════════════════════════
// Excel indirme
// ═══════════════════════════════════════════════════
if (($_GET['excel'] ?? '') === '1') {
    $params = [];
    $where  = halkodeFiltreKosul($_POST, $params);

    $kayitlar = $db->fetchAll("
        SELECT
            CONVERT(VARCHAR(19), h.OlusturmaTarihi, 120)                    AS olusturma,
            ISNULL(NULLIF(c.cari_unvan, ''), c.cari_adi)                    AS cari_unvan,
            s.sozlesme_no                                                   AS sozlesme_no,
            h.Halkode_Odeme_Linkleri_Tutar                                  AS tutar,
            h.Halkode_Odeme_Linkleri_Taksit                                 AS taksit,
            h.Halkode_Odeme_Linkleri_DurumKod                               AS durum,
            h.Halkode_Odeme_Linkleri_GonderimKanali                         AS gonderim_kanali,
            CONVERT(VARCHAR(19), h.Halkode_Odeme_Linkleri_OdemeTarihi, 120) AS odeme_tarihi,
            h.Halkode_Odeme_Linkleri_ProcessId                              AS process_id,
            h.Halkode_Odeme_Linkleri_SiparisNo                              AS siparis_no,
            h.Halkode_Odeme_Linkleri_Aciklama                               AS aciklama
        FROM dbo.Halkode_Odeme_Linkleri h
        LEFT JOIN dbo.Cari c        ON c.cari_id = h.Halkode_Odeme_Linkleri_Cari_id
        LEFT JOIN dbo.Sozlesmeler s ON s.sozlesme_id = h.Halkode_Odeme_Linkleri_Sozlesme_id
        $where
        ORDER BY h.Halkode_Odeme_Linkleri_id DESC
    ", $params);

    header('Content-Type: application/vnd.ms-excel; charset=UTF-8');
    header('Content-Disposition: attachment; filename="halkode_odeme_linkleri_' . date('Y-m-d_H-i-s') . '.xls"');
    header('Pragma: no-cache');
    header('Expires: 0');

    echo "\xEF\xBB\xBF";
    echo '<html xmlns:x="urn:schemas-microsoft-com:office:excel"><head><meta http-equiv="content-type" content="application/vnd.ms-excel; charset=UTF-8"></head><body>';
    echo '<table border="1"><thead><tr style="background-color:#00539F;color:#fff;font-weight:bold;">';
    echo '<th>Oluşturma</th><th>Cari</th><th>Sözleşme No</th><th>Tutar</th><th>Taksit</th><th>Durum</th>';
    echo '<th>Gönderim</th><th>Ödeme Tarihi</th><th>HalkÖde Sipariş No</th><th>Sipariş No</th><th>Açıklama</th>';
    echo '</tr></thead><tbody>';

    foreach ($kayitlar as $k) {
        echo '<tr>';
        echo '<td>' . htmlspecialchars($k['olusturma'] ?? '') . '</td>';
        echo '<td>' . htmlspecialchars($k['cari_unvan'] ?? '') . '</td>';
        echo '<td>' . htmlspecialchars($k['sozlesme_no'] ?? '') . '</td>';
        echo '<td style="text-align:right">' . number_format((float)($k['tutar'] ?? 0), 2, ',', '.') . '</td>';
        echo '<td style="text-align:center">' . (int)($k['taksit'] ?? 0) . '</td>';
        echo '<td>' . htmlspecialchars($durumlar[$k['durum']][1] ?? ($k['durum'] ?? '')) . '</td>';
        echo '<td>' . htmlspecialchars($k['gonderim_kanali'] ?? '') . '</td>';
        echo '<td>' . htmlspecialchars($k['odeme_tarihi'] ?? '') . '</td>';
        echo '<td>' . htmlspecialchars($k['process_id'] ?? '') . '</td>';
        echo '<td>' . htmlspecialchars($k['siparis_no'] ?? '') . '</td>';
        echo '<td>' . htmlspecialchars($k['aciklama'] ?? '') . '</td>';
        echo '</tr>';
    }

    echo '</tbody></table></body></html>';
    exit;
}

// ═══════════════════════════════════════════════════
// Sayfa verisi
// ═══════════════════════════════════════════════════
$kanalListesi = HalkodeService::kanallar($db);

// Sözleşme formundan gelindiyse yeni link penceresi bu sözleşmeyle açılır
$onSozlesme = null;
if (!empty($_GET['sozlesme_id']) && $pagePermissions['can_add']) {
    $onSozlesme = halkodeSozlesmeErisim($db, (int)$_GET['sozlesme_id']);
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
        .info-box { transition: all 0.3s ease; }
        .info-box:hover { transform: translateY(-3px); box-shadow: 0 4px 8px rgba(0,0,0,0.15); }
        .tutar-hucre { font-family: 'Consolas', monospace; font-weight: 600; }
        .link-kutu   { font-family: ui-monospace, SFMono-Regular, Menlo, monospace; font-size: .8rem; }
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
                    <div class="col-sm-6"><h3 class="mb-0"><?= htmlspecialchars($pageTitle) ?></h3></div>
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
                    <div class="col-6 col-md-3">
                        <div class="info-box">
                            <span class="info-box-icon text-bg-primary shadow-sm"><i class="bi bi-link-45deg"></i></span>
                            <div class="info-box-content">
                                <span class="info-box-text">Toplam Link</span>
                                <span class="info-box-number" id="ibToplam">—</span>
                            </div>
                        </div>
                    </div>
                    <div class="col-6 col-md-3">
                        <div class="info-box">
                            <span class="info-box-icon text-bg-success shadow-sm"><i class="bi bi-check2-circle"></i></span>
                            <div class="info-box-content">
                                <span class="info-box-text">Tahsil Edilen</span>
                                <span class="info-box-number" id="ibOdenen">—</span>
                            </div>
                        </div>
                    </div>
                    <div class="col-6 col-md-3">
                        <div class="info-box">
                            <span class="info-box-icon text-bg-warning shadow-sm"><i class="bi bi-hourglass-split"></i></span>
                            <div class="info-box-content">
                                <span class="info-box-text">Ödeme Bekleyen</span>
                                <span class="info-box-number" id="ibBekleyen">—</span>
                            </div>
                        </div>
                    </div>
                    <div class="col-6 col-md-3">
                        <div class="info-box">
                            <span class="info-box-icon text-bg-danger shadow-sm"><i class="bi bi-x-circle"></i></span>
                            <div class="info-box-content">
                                <span class="info-box-text">Başarısız</span>
                                <span class="info-box-number" id="ibBasarisiz">—</span>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Filtre Paneli -->
                <div class="card card-outline card-primary mb-3">
                    <div class="card-header" style="cursor:pointer" data-bs-toggle="collapse" data-bs-target="#filtrePanel">
                        <h3 class="card-title mb-0"><i class="bi bi-funnel me-1"></i> Filtrele</h3>
                    </div>
                    <div class="collapse show" id="filtrePanel">
                        <div class="card-body">
                            <div class="row g-2">
                                <div class="col-md-4">
                                    <label class="form-label">Cari</label>
                                    <select id="f_cari_id" class="form-select"></select>
                                </div>
                                <div class="col-md-2">
                                    <label class="form-label">Durum</label>
                                    <select id="f_durum" class="form-select select2-ara">
                                        <option value="">Tümü</option>
                                        <?php foreach ($durumlar as $kod => [, $ad]): ?>
                                            <option value="<?= $kod ?>"><?= $ad ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div class="col-md-4">
                                    <label class="form-label">Ara</label>
                                    <input type="text" id="f_arama" class="form-control" placeholder="Sipariş no, işlem no, açıklama, telefon, e-posta...">
                                </div>
                                <div class="col-md-2 d-flex align-items-end">
                                    <div class="form-check form-switch mb-2">
                                        <input class="form-check-input" type="checkbox" role="switch" id="f_taksitli" value="1">
                                        <label class="form-check-label" for="f_taksitli">Yalnızca taksitli</label>
                                    </div>
                                </div>
                                <div class="col-md-2">
                                    <label class="form-label">Başlangıç Tarihi</label>
                                    <input type="date" id="f_baslangic_tarih" class="form-control">
                                </div>
                                <div class="col-md-2">
                                    <label class="form-label">Bitiş Tarihi</label>
                                    <input type="date" id="f_bitis_tarih" class="form-control">
                                </div>
                                <div class="col-md-2">
                                    <label class="form-label">Min. Tutar</label>
                                    <input type="number" step="0.01" id="f_min_tutar" class="form-control">
                                </div>
                                <div class="col-md-2">
                                    <label class="form-label">Maks. Tutar</label>
                                    <input type="number" step="0.01" id="f_max_tutar" class="form-control">
                                </div>
                                <div class="col-md-2 d-flex align-items-end">
                                    <button type="button" id="btnTemizle" class="btn btn-outline-secondary w-100">
                                        <i class="bi bi-x-circle me-1"></i> Temizle
                                    </button>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Link Tablosu -->
                <div class="card">
                    <div class="card-header d-flex justify-content-between align-items-center">
                        <h3 class="card-title mb-0"><i class="bi bi-credit-card-2-front me-1"></i> Ödeme Linkleri</h3>
                        <div class="d-flex gap-2 ms-auto">
                            <?php if ($pagePermissions['can_add'] && $kanalListesi): ?>
                                <button type="button" id="btnYeniLink" class="btn btn-primary btn-sm">
                                    <i class="bi bi-plus-lg me-1"></i> Yeni Ödeme Linki
                                </button>
                            <?php endif; ?>
                            <button type="button" id="btnExcel" class="btn btn-success btn-sm">
                                <i class="bi bi-file-earmark-excel me-1"></i> Excel
                            </button>
                        </div>
                    </div>
                    <div class="card-body">
                        <?php if (!$kanalListesi): ?>
                            <div class="alert alert-warning mb-3">
                                <i class="bi bi-exclamation-triangle me-1"></i> Aktif HalkÖde entegrasyon kanalı yok; yeni link oluşturulamaz.
                            </div>
                        <?php endif; ?>
                        <table id="linkTable" class="table table-striped table-bordered align-middle" style="width:100%">
                            <thead>
                                <tr>
                                    <th>Oluşturma</th>
                                    <th>Cari / Sözleşme</th>
                                    <th class="text-end">Tutar</th>
                                    <th class="text-center">Taksit</th>
                                    <th class="text-center">Durum</th>
                                    <th>Gönderim</th>
                                    <th>Ödeme</th>
                                    <th class="text-center">İşlem</th>
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

<!-- Yeni Link Modalı -->
<div class="modal fade" id="yeniModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title"><i class="bi bi-plus-circle me-1"></i> Yeni Ödeme Linki</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Kapat"></button>
            </div>
            <div class="modal-body">
                <div class="row g-3">
                    <?php if (count($kanalListesi) > 1): ?>
                        <div class="col-md-12">
                            <label class="form-label">Üye İşyeri</label>
                            <select id="y_kanal_id" class="form-select select2-modal">
                                <?php foreach ($kanalListesi as $k): ?>
                                    <option value="<?= (int)$k['kanal_id'] ?>"><?= htmlspecialchars($k['kanal_ad']) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    <?php else: ?>
                        <input type="hidden" id="y_kanal_id" value="<?= (int)($kanalListesi[0]['kanal_id'] ?? 0) ?>">
                    <?php endif; ?>

                    <div class="col-md-12">
                        <label class="form-label">Sözleşme <span class="text-danger">*</span></label>
                        <select id="y_sozlesme_id" class="form-select">
                            <?php if ($onSozlesme): ?>
                                <option value="<?= (int)$onSozlesme['sozlesme_id'] ?>" selected>
                                    <?= htmlspecialchars(($onSozlesme['sozlesme_no'] ?: '#' . $onSozlesme['sozlesme_id']) . ' - ' . $onSozlesme['unvan']) ?>
                                </option>
                            <?php endif; ?>
                        </select>
                    </div>
                    <div class="col-md-4">
                        <label class="form-label">Tutar (₺) <span class="text-danger">*</span></label>
                        <input type="number" step="0.01" min="1" id="y_tutar" class="form-control">
                    </div>
                    <div class="col-md-4">
                        <label class="form-label">Taksit</label>
                        <select id="y_taksit" class="form-select select2-modal">
                            <option value="0">Müşteri seçsin</option>
                            <option value="1">Tek çekim</option>
                            <?php for ($i = 2; $i <= 12; $i++): ?>
                                <option value="<?= $i ?>"><?= $i ?> taksit</option>
                            <?php endfor; ?>
                        </select>
                    </div>
                    <div class="col-md-4">
                        <label class="form-label">Açıklama</label>
                        <input type="text" id="y_aciklama" class="form-control" maxlength="200" placeholder="Ör. Kasım taksidi">
                    </div>

                    <div class="col-md-12">
                        <div class="border rounded p-3">
                            <div class="fw-semibold mb-2"><i class="bi bi-send me-1"></i> Gönderim</div>
                            <div class="row g-2">
                                <div class="col-md-12">
                                    <label class="form-label">Gönderilecek Kişi</label>
                                    <select id="y_kisi" class="form-select select2-modal"></select>
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label">Telefon</label>
                                    <input type="tel" id="y_telefon" class="form-control" placeholder="5xxxxxxxxx">
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label">E-posta</label>
                                    <input type="email" id="y_email" class="form-control" placeholder="musteri@ornek.com">
                                </div>
                                <div class="col-md-12">
                                    <div class="form-check form-switch d-inline-block me-4">
                                        <input class="form-check-input" type="checkbox" role="switch" id="y_sms" value="1" checked>
                                        <label class="form-check-label" for="y_sms">SMS ile gönder</label>
                                    </div>
                                    <div class="form-check form-switch d-inline-block">
                                        <input class="form-check-input" type="checkbox" role="switch" id="y_mail" value="1">
                                        <label class="form-check-label" for="y_mail">E-posta ile gönder</label>
                                    </div>
                                    <div class="form-text">İkisi de kapalıysa link yalnızca ekranda gösterilir, kopyalayıp WhatsApp vb. ile iletebilirsiniz.</div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Vazgeç</button>
                <button type="button" class="btn btn-primary" id="btnOlustur">
                    <i class="bi bi-link-45deg me-1"></i> Link Oluştur
                </button>
            </div>
        </div>
    </div>
</div>

<!-- Detay / Gönderim Modalı -->
<div class="modal fade" id="detayModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-centered modal-dialog-scrollable">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title"><i class="bi bi-credit-card-2-front me-1"></i> Ödeme Linki Detayı</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Kapat"></button>
            </div>
            <div class="modal-body">
                <input type="hidden" id="d_link_id">
                <div id="d_govde"></div>

                <div id="d_gonderim_alan" class="border rounded p-3 mt-3">
                    <div class="fw-semibold mb-2"><i class="bi bi-send me-1"></i> Linki Yeniden Gönder</div>
                    <div class="row g-2 mb-3">
                        <div class="col-md-12">
                            <label class="form-label">Gönderilecek Kişi</label>
                            <select id="d_kisi" class="form-select form-select-sm select2-detay"></select>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Telefon</label>
                            <input type="tel" id="d_telefon" class="form-control form-control-sm" placeholder="5xxxxxxxxx">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">E-posta</label>
                            <input type="email" id="d_email" class="form-control form-control-sm" placeholder="musteri@ornek.com">
                        </div>
                    </div>
                    <div class="form-check form-switch d-inline-block me-4">
                        <input class="form-check-input" type="checkbox" role="switch" id="d_sms" value="1" checked>
                        <label class="form-check-label" for="d_sms">SMS</label>
                    </div>
                    <div class="form-check form-switch d-inline-block me-4">
                        <input class="form-check-input" type="checkbox" role="switch" id="d_mail" value="1">
                        <label class="form-check-label" for="d_mail">E-posta</label>
                    </div>
                    <button type="button" class="btn btn-sm btn-primary" id="btnYenidenGonder">
                        <i class="bi bi-send me-1"></i> Gönder
                    </button>
                </div>
            </div>
            <div class="modal-footer justify-content-between">
                <div class="d-flex gap-2">
                    <button type="button" class="btn btn-outline-primary btn-sm" id="btnDetaySorgula">
                        <i class="bi bi-arrow-clockwise me-1"></i> Durumu Sorgula
                    </button>
                    <button type="button" class="btn btn-outline-danger btn-sm d-none" id="btnDetayIptal">
                        <i class="bi bi-x-circle me-1"></i> Linki İptal Et
                    </button>
                </div>
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Kapat</button>
            </div>
        </div>
    </div>
</div>

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
$(function () {
    const duzenleYetki = <?= $pagePermissions['can_edit'] ? 'true' : 'false' ?>;
    const DURUM        = <?= json_encode($durumlar, JSON_UNESCAPED_UNICODE) ?>;
    const esc          = t => $('<div>').text(t == null ? '' : t).html();

    const paraFormat = (deger, birim) => parseFloat(deger || 0).toLocaleString('tr-TR', {
        minimumFractionDigits: 2, maximumFractionDigits: 2
    }) + ' ' + (birim === 'TRY' || !birim ? '₺' : birim);

    const durumRozet = d => {
        const [renk, metin] = DURUM[d] || ['secondary', d || '-'];
        return '<span class="badge text-bg-' + renk + '">' + metin + '</span>';
    };

    // Taksit 0 ise seçim ödeme sayfasında müşteriye bırakılmıştır
    const taksitMetni = d => {
        const s = parseInt(d, 10) || 0;
        return s > 1 ? s + ' taksit' : (s === 1 ? 'Tek çekim' : 'Müşteri seçimi');
    };

    const tarihKisa = d => {
        if (!d) return '-';
        const [tarih, saat] = d.split(' ');
        const p = tarih.split('-');
        return p[2] + '.' + p[1] + '.' + p[0] + '<br><small class="text-muted">' + (saat || '').substring(0, 5) + '</small>';
    };

    // ── Select2: tüm dropdown'lar aranabilir
    $('.select2-ara').select2({ theme: 'bootstrap-5', width: '100%' });
    $('.select2-modal').select2({ theme: 'bootstrap-5', width: '100%', dropdownParent: $('#yeniModal') });
    $('.select2-detay').select2({ theme: 'bootstrap-5', width: '100%', dropdownParent: $('#detayModal') });

    $('#f_cari_id').select2({
        theme: 'bootstrap-5', width: '100%', allowClear: true, placeholder: 'Tümü',
        ajax: { url: '?ajax=cari_ara', dataType: 'json', delay: 300, data: p => ({ q: p.term || '' }) }
    });

    $('#y_sozlesme_id').select2({
        theme: 'bootstrap-5', width: '100%', dropdownParent: $('#yeniModal'),
        placeholder: 'Sözleşme no, işletme adı veya telefon yazın...', minimumInputLength: 2,
        ajax: { url: '?ajax=sozlesme_ara', dataType: 'json', delay: 300, data: p => ({ q: p.term || '' }) }
    });

    // ── Tablo
    const filtreler = d => {
        d.cari_id         = $('#f_cari_id').val();
        d.durum           = $('#f_durum').val();
        d.arama           = $('#f_arama').val();
        d.taksitli        = $('#f_taksitli').is(':checked') ? 1 : '';
        d.baslangic_tarih = $('#f_baslangic_tarih').val();
        d.bitis_tarih     = $('#f_bitis_tarih').val();
        d.min_tutar       = $('#f_min_tutar').val();
        d.max_tutar       = $('#f_max_tutar').val();
    };

    const table = $('#linkTable').DataTable({
        processing: true,
        serverSide: true,
        scrollX: true,
        dom: 'lrtip',
        pageLength: 25,
        lengthMenu: [10, 25, 50, 100],
        order: [[0, 'desc']],
        ajax: { url: '?ajax=1', type: 'POST', data: filtreler },
        columns: [
            { data: 'olusturma', render: tarihKisa },
            {
                data: 'cari_unvan',
                render: (d, t, row) => esc(d) +
                    '<br><small><a href="sozlesme-form.php?id=' + row.sozlesme_id + '" target="_blank">' +
                    esc(row.sozlesme_no || ('#' + row.sozlesme_id)) + '</a></small>' +
                    (row.aciklama ? '<small class="text-muted"> · ' + esc(row.aciklama) + '</small>' : '')
            },
            { data: 'tutar', className: 'text-end tutar-hucre', render: (d, t, row) => paraFormat(d, row.para_birimi) },
            { data: 'taksit', className: 'text-center', render: d => taksitMetni(d) },
            {
                data: 'durum', className: 'text-center',
                render: (d, t, row) => durumRozet(d) +
                    (d === 'BASARISIZ' && row.sonuc_mesaj ? '<br><small class="text-danger">' + esc(row.sonuc_mesaj) + '</small>' : '')
            },
            {
                data: 'gonderim_kanali',
                render: (d, t, row) => d
                    ? esc(d) + '<br><small class="text-muted">' + tarihKisa(row.gonderim_tarihi).replace('<br>', ' ') + '</small>'
                    : '<span class="text-muted">Gönderilmedi</span>'
            },
            {
                data: 'odeme_tarihi',
                render: (d, t, row) => d
                    ? tarihKisa(d) + (row.odeme_id ? ' <span class="badge text-bg-success">Ödeme #' + row.odeme_id + '</span>' : '')
                    : '<span class="text-muted">—</span>'
            },
            {
                data: 'id', className: 'text-center text-nowrap', orderable: false,
                render: (d, t, row) =>
                    (row.link ? '<button class="btn btn-sm btn-outline-secondary btn-kopyala me-1" data-link="' + esc(row.link) +
                                '" title="Linki kopyala"><i class="bi bi-clipboard"></i></button>' : '') +
                    '<button class="btn btn-sm btn-outline-primary btn-detay" data-id="' + d +
                    '" title="Detay ve işlemler"><i class="bi bi-three-dots"></i></button>'
            }
        ],
        language: { url: 'https://cdn.datatables.net/plug-ins/1.13.7/i18n/tr.json' }
    });

    $('#f_cari_id, #f_durum, #f_baslangic_tarih, #f_bitis_tarih, #f_taksitli').on('change', () => table.ajax.reload());

    let zamanlayici = null;
    $('#f_arama, #f_min_tutar, #f_max_tutar').on('keyup change', function () {
        clearTimeout(zamanlayici);
        zamanlayici = setTimeout(() => table.ajax.reload(), 400);
    });

    $('#btnTemizle').on('click', function () {
        $('#f_cari_id').val(null).trigger('change.select2');
        $('#f_durum').val('').trigger('change.select2');
        $('#f_arama, #f_baslangic_tarih, #f_bitis_tarih, #f_min_tutar, #f_max_tutar').val('');
        $('#f_taksitli').prop('checked', false);
        table.ajax.reload();
    });

    // ── InfoBox: tablo yüklendikten sonra ayrı istekle
    function ozetYukle() {
        $.getJSON('?ajax=stats', function (res) {
            if (!res.success) return;
            const o = res.ozet || {};
            $('#ibToplam').text(o.toplam ?? 0);
            $('#ibOdenen').text(paraFormat(o.odenen_tutar));
            $('#ibBekleyen').text(o.bekleyen ?? 0);
            $('#ibBasarisiz').text(o.basarisiz ?? 0);
        });
    }
    table.one('draw', ozetYukle);

    // ── Kişi listesi (yeni + detay ortak)
    function kisileriDoldur(secim, kisiler, telefonAlan, emailAlan, kayitliTelefon, kayitliEmail) {
        secim.empty();
        let secili = 0;

        kisiler.forEach(function (kisi, i) {
            const eksik = [];
            if (!kisi.telefon) eksik.push('telefon yok');
            if (!kisi.email)   eksik.push('e-posta yok');

            secim.append(new Option(kisi.ad + ' — ' + kisi.gorev + (eksik.length ? ' (' + eksik.join(', ') + ')' : ''), i));

            if ((kayitliTelefon && kisi.telefon === kayitliTelefon)
                || (kayitliEmail && (kisi.email || '').toLowerCase() === kayitliEmail)) {
                secili = i;
            }
        });

        secim.val(kisiler.length ? secili : null).trigger('change.select2');
        secim.data('kisiler', kisiler);

        telefonAlan.val(kayitliTelefon || (kisiler[secili] ? kisiler[secili].telefon : ''));
        emailAlan.val(kayitliEmail || (kisiler[secili] ? kisiler[secili].email : ''));
    }

    $('#y_kisi, #d_kisi').on('change', function () {
        const kisi = ($(this).data('kisiler') || [])[parseInt($(this).val(), 10)];
        if (!kisi) return;

        const onek = this.id.charAt(0);
        $('#' + onek + '_telefon').val(kisi.telefon || '');
        $('#' + onek + '_email').val(kisi.email || '');
    });

    // ── Yeni link
    const yeniModal = new bootstrap.Modal(document.getElementById('yeniModal'));

    function yeniFormSifirla(sozlesmeKoru) {
        if (!sozlesmeKoru) $('#y_sozlesme_id').val(null).trigger('change.select2');
        $('#y_tutar, #y_aciklama, #y_telefon, #y_email').val('');
        $('#y_taksit').val('0').trigger('change.select2');
        $('#y_kisi').empty().trigger('change.select2');
        $('#y_sms').prop('checked', true);
        $('#y_mail').prop('checked', false);
    }

    function sozlesmeBilgiYukle(id) {
        if (!id) return;
        $.post('', { action: 'sozlesme_bilgi', sozlesme_id: id }, function (res) {
            if (!res.success) { showError('Hata', res.message); return; }
            kisileriDoldur($('#y_kisi'), res.kisiler || [], $('#y_telefon'), $('#y_email'), '', '');
        }, 'json');
    }

    $('#y_sozlesme_id').on('change', function () { sozlesmeBilgiYukle($(this).val()); });

    $('#btnYeniLink').on('click', function () {
        yeniFormSifirla(false);
        yeniModal.show();
    });

    $('#btnOlustur').on('click', function () {
        const sozlesmeId = $('#y_sozlesme_id').val();
        const tutar      = parseFloat(($('#y_tutar').val() || '').replace(',', '.'));
        const kanallar   = [];

        if ($('#y_sms').is(':checked'))  kanallar.push('sms');
        if ($('#y_mail').is(':checked')) kanallar.push('mail');

        if (!sozlesmeId)          { showWarning('Eksik Bilgi', 'Sözleşme seçin.'); return; }
        if (!(tutar > 0))         { showWarning('Eksik Bilgi', 'Geçerli bir tutar girin.'); return; }

        const btn = $(this).prop('disabled', true);

        $.post('', {
            action: 'olustur',
            kanal_id: $('#y_kanal_id').val(),
            sozlesme_id: sozlesmeId,
            tutar: tutar,
            taksit: $('#y_taksit').val(),
            aciklama: $('#y_aciklama').val(),
            telefon: $('#y_telefon').val(),
            email: $('#y_email').val(),
            kanallar: JSON.stringify(kanallar)
        }, function (res) {
            if (!res.success) { showError('Link Oluşturulamadı', res.message); return; }

            yeniModal.hide();
            table.ajax.reload(null, false);
            ozetYukle();

            let gonderimMetni = 'Link gönderilmedi; kopyalayıp iletebilirsiniz.';
            if (res.gonderim) {
                const ok = [];
                if (res.gonderim.sms)  ok.push('SMS');
                if (res.gonderim.mail) ok.push('E-posta');
                gonderimMetni = (ok.length ? ok.join(' ve ') + ' ile gönderildi.' : '') +
                    (res.gonderim.hatalar && res.gonderim.hatalar.length ? '<br><span class="text-danger">' + res.gonderim.hatalar.map(esc).join('<br>') + '</span>' : '');
            }

            Swal.fire({
                icon: 'success',
                title: 'Ödeme Linki Hazır',
                html: '<div class="input-group input-group-sm mb-2"><input type="text" class="form-control link-kutu" readonly value="' + esc(res.link) + '">' +
                      '<button class="btn btn-outline-secondary" type="button" id="swalKopyala"><i class="bi bi-clipboard"></i></button></div>' +
                      '<small>' + gonderimMetni + '</small>',
                didOpen: () => $('#swalKopyala').on('click', () => navigator.clipboard.writeText(res.link).then(() => showToast('Link kopyalandı', 'success')))
            });
        }, 'json').fail(() => showError('Hata', 'İstek başarısız.'))
          .always(() => btn.prop('disabled', false));
    });

    // Sözleşme formundan gelindiyse pencere açık başlar
    <?php if ($onSozlesme): ?>
    yeniFormSifirla(true);
    sozlesmeBilgiYukle(<?= (int)$onSozlesme['sozlesme_id'] ?>);
    yeniModal.show();
    <?php endif; ?>

    // ── Linki kopyala
    $(document).on('click', '.btn-kopyala', function () {
        navigator.clipboard.writeText($(this).data('link')).then(
            () => showToast('Ödeme linki panoya kopyalandı.', 'success'),
            () => showError('Hata', 'Link kopyalanamadı, detay ekranından elle kopyalayın.')
        );
    });

    // ── Detay
    const detayModal = new bootstrap.Modal(document.getElementById('detayModal'));

    function detayAc(id) {
        $('#d_link_id').val(id);

        $.post('', { action: 'detay', link_id: id }, function (res) {
            if (!res.success) { showError('Hata', res.message); return; }

            const k      = res.data;
            const durum  = k.Halkode_Odeme_Linkleri_DurumKod;
            const odendi = durum === 'ODENDI';
            const satir  = (etiket, deger) => deger
                ? '<div class="row mb-1"><div class="col-4 text-muted">' + etiket + '</div><div class="col-8">' + deger + '</div></div>'
                : '';
            const tarih  = d => d ? tarihKisa(String(d).substring(0, 19)).replace('<br>', ' ') : '';

            let govde = ''
                + satir('Cari', esc(k.cari_unvan))
                + satir('Sözleşme', '<a href="sozlesme-form.php?id=' + k.Halkode_Odeme_Linkleri_Sozlesme_id + '" target="_blank">' +
                        esc(k.sozlesme_no || ('#' + k.Halkode_Odeme_Linkleri_Sozlesme_id)) + '</a>')
                + satir('Tutar', paraFormat(k.Halkode_Odeme_Linkleri_Tutar, k.Halkode_Odeme_Linkleri_ParaBirimi))
                + satir('Taksit', taksitMetni(k.Halkode_Odeme_Linkleri_Taksit))
                + satir('Durum', durumRozet(durum))
                + satir('Sipariş No', '<span class="link-kutu">' + esc(k.Halkode_Odeme_Linkleri_SiparisNo) + '</span>')
                + satir('HalkÖde Sipariş No', k.Halkode_Odeme_Linkleri_ProcessId ? '<span class="link-kutu">' + esc(k.Halkode_Odeme_Linkleri_ProcessId) + '</span>' : '')
                + satir('İşlem No', esc(k.Halkode_Odeme_Linkleri_IslemNo))
                + satir('Referans No', esc(k.Halkode_Odeme_Linkleri_RefNo))
                + satir('Telefon', esc(k.Halkode_Odeme_Linkleri_Telefon || k.cari_telefon))
                + satir('E-posta', esc(k.Halkode_Odeme_Linkleri_Email || k.cari_email))
                + satir('Gönderim', k.Halkode_Odeme_Linkleri_GonderimKanali
                        ? esc(k.Halkode_Odeme_Linkleri_GonderimKanali) + ' — ' + tarih(k.Halkode_Odeme_Linkleri_GonderimTarihi) : '')
                + satir('Ödeme Tarihi', tarih(k.Halkode_Odeme_Linkleri_OdemeTarihi))
                + satir('Sonuç', esc(k.Halkode_Odeme_Linkleri_SonucMesaj))
                + satir('Açıklama', esc(k.Halkode_Odeme_Linkleri_Aciklama));

            if (k.Halkode_Odeme_Linkleri_Odeme_id) {
                govde += satir('Ödeme Satırı', '<span class="badge text-bg-success">#' + k.Halkode_Odeme_Linkleri_Odeme_id +
                               '</span> <small class="text-muted">sözleşmeye otomatik eklendi</small>');
            }

            if (k.Halkode_Odeme_Linkleri_Link) {
                govde += '<div class="input-group input-group-sm mt-3">' +
                         '<input type="text" class="form-control link-kutu" readonly value="' + esc(k.Halkode_Odeme_Linkleri_Link) + '">' +
                         '<button class="btn btn-outline-secondary btn-kopyala" type="button" data-link="' + esc(k.Halkode_Odeme_Linkleri_Link) +
                         '"><i class="bi bi-clipboard"></i> Kopyala</button></div>';
            }

            $('#d_govde').html(govde);

            kisileriDoldur($('#d_kisi'), res.kisiler || [], $('#d_telefon'), $('#d_email'),
                (k.Halkode_Odeme_Linkleri_Telefon || '').replace(/\D/g, ''),
                (k.Halkode_Odeme_Linkleri_Email || '').toLowerCase());

            $('#d_gonderim_alan').toggleClass('d-none', odendi || durum === 'IPTAL' || !duzenleYetki || !k.Halkode_Odeme_Linkleri_Link);
            $('#btnDetayIptal').toggleClass('d-none', odendi || durum === 'IPTAL' || !duzenleYetki);
            $('#btnDetaySorgula').toggleClass('d-none', odendi || !duzenleYetki || !k.Halkode_Odeme_Linkleri_Link);

            detayModal.show();
        }, 'json').fail(() => showError('Hata', 'Detay alınamadı.'));
    }

    $('#linkTable').on('click', '.btn-detay', function () { detayAc($(this).data('id')); });

    $('#btnDetaySorgula').on('click', function () {
        const btn = $(this).prop('disabled', true);
        const id  = $('#d_link_id').val();

        $.post('', { action: 'sorgula', link_id: id }, function (res) {
            if (!res.success) { showError('Hata', res.message); return; }

            if (res.durum === 'ODENDI') showSuccess('Ödeme Alındı', res.message);
            else                        showWarning('Bekliyor', res.message);

            table.ajax.reload(null, false);
            ozetYukle();
            detayAc(id);
        }, 'json').fail(() => showError('Hata', 'Sorgulama başarısız.'))
          .always(() => btn.prop('disabled', false));
    });

    $('#btnYenidenGonder').on('click', function () {
        const kanallar = [];
        if ($('#d_sms').is(':checked'))  kanallar.push('sms');
        if ($('#d_mail').is(':checked')) kanallar.push('mail');

        if (!kanallar.length) { showWarning('Eksik Bilgi', 'En az bir gönderim kanalı seçin.'); return; }

        const btn = $(this).prop('disabled', true);

        $.post('', {
            action: 'gonder',
            link_id: $('#d_link_id').val(),
            kanallar: JSON.stringify(kanallar),
            telefon: $('#d_telefon').val(),
            email: $('#d_email').val()
        }, function (res) {
            if (res.success) { showSuccess('Gönderildi', res.message); table.ajax.reload(null, false); }
            else             { showError('Gönderilemedi', res.message); }
        }, 'json').fail(() => showError('Hata', 'Gönderim başarısız.'))
          .always(() => btn.prop('disabled', false));
    });

    $('#btnDetayIptal').on('click', function () {
        Swal.fire({
            title: 'Link iptal edilsin mi?',
            text: 'Takip kaydı iptal olarak işaretlenir. Müşteri linki HalkÖde tarafında süresi dolmadan kullanırsa ödeme yine sözleşmeye işlenir.',
            icon: 'warning',
            showCancelButton: true,
            confirmButtonText: 'İptal Et',
            cancelButtonText: 'Vazgeç',
            confirmButtonColor: '#dc3545'
        }).then(function (secim) {
            if (!secim.isConfirmed) return;

            $.post('', { action: 'iptal', link_id: $('#d_link_id').val() }, function (res) {
                if (res.success) {
                    showSuccess('İptal Edildi', res.message);
                    detayModal.hide();
                    table.ajax.reload(null, false);
                    ozetYukle();
                } else {
                    showError('Hata', res.message);
                }
            }, 'json').fail(() => showError('Hata', 'İptal işlemi başarısız.'));
        });
    });

    // ── Excel: filtreler POST ile
    $('#btnExcel').on('click', function () {
        const veri = {};
        filtreler(veri);

        const form = $('<form>', { method: 'POST', action: '?excel=1' });
        $.each(veri, function (ad, deger) {
            if (deger !== '' && deger != null) form.append($('<input>', { type: 'hidden', name: ad, value: deger }));
        });
        form.appendTo('body').submit().remove();
    });
});
</script>
</body>
</html>
