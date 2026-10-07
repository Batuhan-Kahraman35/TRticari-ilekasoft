<?php
/**
 * Cron Yonetimi
 *
 * Merkezi cron sisteminin paneli. Gorev merkezli kart duzeni:
 * her gorev bir kart, kart govdesinde o goreve ait zamanlayicilar.
 *
 * Sekmeler: Gorevler · Calisma Gecmisi · Sıklık Sablonlari
 *
 * NOT: Bu sayfa cron/tasks.php'yi yalnızca yardimci fonksiyonlar icin dahil eder;
 * cronKeyDogrula() BURADA CAGRILMAZ - erisim kontrolu PageAuth ile yapılır.
 */

require_once __DIR__ . '/../auth.php';
require_once __DIR__ . '/../includes/PageAuth.php';
require_once __DIR__ . '/../cron/tasks.php';
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

// ============================================================
// AJAX
// ============================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json; charset=utf-8');
    $action = $_POST['action'] ?? '';

    try {
        switch ($action) {

            // ─── InfoBox sayaclari (tablo sorgusundan ayri) ───
            case 'stats':
                $g = $db->fetchOne("SELECT COUNT(*) AS s FROM dbo.CronGorevler WHERE Durum = 1");
                $z = $db->fetchOne("SELECT COUNT(*) AS s FROM dbo.CronZamanlamalar WHERE Durum = 1");
                $b = $db->fetchOne("
                    SELECT
                        SUM(CASE WHEN CronCalismaLog_CalismaDurum = 1 THEN 1 ELSE 0 END) AS başarılı,
                        SUM(CASE WHEN CronCalismaLog_CalismaDurum = 2 THEN 1 ELSE 0 END) AS hatalı
                    FROM dbo.CronCalismaLog
                    WHERE CronCalismaLog_BaslangicTarihi >= CAST(GETDATE() AS DATE)
                ");

                echo json_encode(['success' => true, 'data' => [
                    'gorev'    => (int)($g['s'] ?? 0),
                    'zamanlama'=> (int)($z['s'] ?? 0),
                    'başarılı' => (int)($b['başarılı'] ?? 0),
                    'hatalı'   => (int)($b['hatalı'] ?? 0),
                ]]);
                break;

            // ─── Gorevler + zamanlayicilar tek istekte ───
            case 'gorev_liste':
                $gorevler = $db->fetchAll("
                    SELECT
                        CronGorevler_Id           AS id,
                        CronGorevler_Ad           AS ad,
                        CronGorevler_Aciklama     AS aciklama,
                        CronGorevler_GorevKodu    AS kod,
                        CronGorevler_Parametreler AS parametreler,
                        CronGorevler_MaxSureSn    AS max_sure
                    FROM dbo.CronGorevler
                    WHERE Durum = 1
                    ORDER BY CronGorevler_Ad
                ");

                // Son calisma bilgisi tek OUTER APPLY ile - gorev basina ayri sorgu atilmaz
                $zamanlamalar = $db->fetchAll("
                    SELECT
                        z.CronZamanlamalar_Id                                        AS id,
                        z.CronZamanlamalar_GorevId                                   AS gorev_id,
                        z.CronZamanlamalar_Ad                                        AS ad,
                        z.CronZamanlamalar_CronIfadesi                               AS ifade,
                        CONVERT(VARCHAR(19), z.CronZamanlamalar_BaslangicTarihi, 120) AS baslangic,
                        CONVERT(VARCHAR(19), z.CronZamanlamalar_BitisTarihi, 120)     AS bitis,
                        CONVERT(VARCHAR(19), z.CronZamanlamalar_SonCalisma, 120)      AS son_calisma,
                        z.CronZamanlamalar_SabitParametreler                          AS parametreler,
                        z.CronZamanlamalar_TelafiEt                                   AS telafi,
                        z.CronZamanlamalar_TelafiSaatSiniri                           AS telafi_saat,
                        z.Durum                                                       AS durum,
                        s.CalismaDurum                                                AS son_durum,
                        s.SureSaniye                                                  AS son_sure,
                        s.Sonuc                                                       AS son_sonuc
                    FROM dbo.CronZamanlamalar z
                    OUTER APPLY (
                        SELECT TOP 1
                            l.CronCalismaLog_CalismaDurum AS CalismaDurum,
                            l.CronCalismaLog_SureSaniye   AS SureSaniye,
                            l.CronCalismaLog_Sonuc        AS Sonuc
                        FROM dbo.CronCalismaLog l
                        WHERE l.CronCalismaLog_ZamanlamaId = z.CronZamanlamalar_Id
                        ORDER BY l.CronCalismaLog_Id DESC
                    ) s
                    ORDER BY z.CronZamanlamalar_Id
                ");

                echo json_encode([
                    'success'      => true,
                    'gorevler'     => $gorevler,
                    'zamanlamalar' => $zamanlamalar,
                ], JSON_UNESCAPED_UNICODE);
                break;

            // ─── select/multiselect kaynaklari (bir kez cekilip istemcide cache'lenir) ───
            case 'param_kaynaklar':
                $cikti = [];
                foreach (array_keys(cronParamKaynaklari()) as $anahtar) {
                    $cikti[$anahtar] = cronParamKaynakCoz($db, $anahtar);
                }
                echo json_encode([
                    'success'    => true,
                    'kaynaklar'  => $cikti,
                    'yer_tutucu' => dinamikTarihListesi(),
                ], JSON_UNESCAPED_UNICODE);
                break;

            // ─── Gorev kaydet ───
            case 'gorev_kaydet':
                $id = (int)($_POST['id'] ?? 0);

                if (($id && !$pagePermissions['can_edit']) || (!$id && !$pagePermissions['can_add'])) {
                    echo json_encode(['success' => false, 'message' => 'Bu işlem için yetkiniz yok!']);
                    break;
                }

                $kod = strtolower(trim($_POST['kod'] ?? ''));
                if (!preg_match('/^[a-z0-9_]+$/', $kod)) {
                    echo json_encode(['success' => false, 'message' => 'Görev kodu yalnızca küçük harf, rakam ve alt çizgi içerebilir.']);
                    break;
                }

                $sema = trim($_POST['parametreler'] ?? '');
                if ($sema !== '' && json_decode($sema, true) === null) {
                    echo json_encode(['success' => false, 'message' => 'Parametre şeması geçerli JSON değil.']);
                    break;
                }

                $veri = [
                    'CronGorevler_Ad'           => trim($_POST['ad'] ?? ''),
                    'CronGorevler_Aciklama'     => trim($_POST['aciklama'] ?? '') ?: null,
                    'CronGorevler_GorevKodu'    => $kod,
                    'CronGorevler_Parametreler' => $sema !== '' ? $sema : null,
                    'CronGorevler_MaxSureSn'    => max(30, (int)($_POST['max_sure'] ?? 600)),
                    'GuncelleyenKullanici'      => $user['kullanici_id'],
                    'GuncellemeTarihi'          => date('Y-m-d H:i:s'),
                ];

                if ($id) {
                    $db->update('dbo.CronGorevler', $veri, ['CronGorevler_Id' => $id]);
                } else {
                    $veri['OlusturanKullanici'] = $user['kullanici_id'];
                    $veri['OlusturmaTarihi']    = date('Y-m-d H:i:s');
                    $veri['Durum']              = 1;
                    $db->insert('dbo.CronGorevler', $veri);
                }

                echo json_encode(['success' => true, 'message' => 'Görev kaydedildi.']);
                break;

            // ─── Gorev sil (Durum = 0; log kayitlari FK ile bagli, gercek DELETE yapılmaz) ───
            case 'gorev_sil':
                if (!$pagePermissions['can_delete']) {
                    echo json_encode(['success' => false, 'message' => 'Bu işlem için yetkiniz yok!']);
                    break;
                }

                $id = (int)($_POST['id'] ?? 0);

                $db->update('dbo.CronGorevler', [
                    'Durum'                => 0,
                    'GuncelleyenKullanici' => $user['kullanici_id'],
                    'GuncellemeTarihi'     => date('Y-m-d H:i:s'),
                ], ['CronGorevler_Id' => $id]);

                // Goreve bagli zamanlayicilar da duraklatılır - aksi halde runner
                // pasif goreve ait zamanlamayi taramaya devam eder.
                $db->execute("
                    UPDATE dbo.CronZamanlamalar
                    SET Durum = 0, GuncellemeTarihi = GETDATE()
                    WHERE CronZamanlamalar_GorevId = ?
                ", [$id]);

                echo json_encode(['success' => true, 'message' => 'Görev pasife alındı.']);
                break;

            // ─── Zamanlayıcı kaydet ───
            case 'zamanlama_kaydet':
                $id = (int)($_POST['id'] ?? 0);

                if (($id && !$pagePermissions['can_edit']) || (!$id && !$pagePermissions['can_add'])) {
                    echo json_encode(['success' => false, 'message' => 'Bu işlem için yetkiniz yok!']);
                    break;
                }

                $gorevId = (int)($_POST['gorev_id'] ?? 0);
                $ifade   = trim($_POST['ifade'] ?? '');

                if ($hata = cronIfadeDogrula($ifade)) {
                    echo json_encode(['success' => false, 'message' => $hata]);
                    break;
                }

                $gorev = $db->fetchOne(
                    "SELECT CronGorevler_Parametreler FROM dbo.CronGorevler WHERE CronGorevler_Id = ?",
                    [$gorevId]
                );

                // Panelden gelen veriye guvenilmez - sema ile yeniden dogrula
                $gelen = json_decode($_POST['parametreler'] ?? '{}', true) ?: [];
                try {
                    // Yer tutucular ({dun} vb.) calisma anında cozulur; burada oldugu gibi saklanir.
                    cronParamDogrula($gorev['CronGorevler_Parametreler'] ?? null, $gelen);
                } catch (Throwable $e) {
                    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
                    break;
                }

                $veri = [
                    'CronZamanlamalar_GorevId'           => $gorevId,
                    'CronZamanlamalar_Ad'                => trim($_POST['ad'] ?? ''),
                    'CronZamanlamalar_CronIfadesi'       => $ifade,
                    'CronZamanlamalar_BaslangicTarihi'   => $_POST['baslangic'] ?: date('Y-m-d H:i:s'),
                    'CronZamanlamalar_BitisTarihi'       => $_POST['bitis'] ?: null,
                    'CronZamanlamalar_SabitParametreler' => $gelen ? json_encode($gelen, JSON_UNESCAPED_UNICODE) : null,
                    'CronZamanlamalar_TelafiEt'          => !empty($_POST['telafi']) ? 1 : 0,
                    'CronZamanlamalar_TelafiSaatSiniri'  => max(1, (int)($_POST['telafi_saat'] ?? 6)),
                    'GuncelleyenKullanici'               => $user['kullanici_id'],
                    'GuncellemeTarihi'                   => date('Y-m-d H:i:s'),
                ];

                if ($id) {
                    $db->update('dbo.CronZamanlamalar', $veri, ['CronZamanlamalar_Id' => $id]);
                } else {
                    $veri['OlusturanKullanici'] = $user['kullanici_id'];
                    $veri['OlusturmaTarihi']    = date('Y-m-d H:i:s');
                    $veri['Durum']              = 1;
                    $db->insert('dbo.CronZamanlamalar', $veri);
                }

                echo json_encode(['success' => true, 'message' => 'Zamanlayıcı kaydedildi.']);
                break;

            // ─── Zamanlayıcı sil (gercek DELETE - tek basina gecmis tasimaz) ───
            case 'zamanlama_sil':
                if (!$pagePermissions['can_delete']) {
                    echo json_encode(['success' => false, 'message' => 'Bu işlem için yetkiniz yok!']);
                    break;
                }

                $db->delete('dbo.CronZamanlamalar', ['CronZamanlamalar_Id' => (int)($_POST['id'] ?? 0)]);
                echo json_encode(['success' => true, 'message' => 'Zamanlayıcı silindi.']);
                break;

            // ─── Zamanlayıcı duraklat / devam ───
            case 'zamanlama_durum':
                if (!$pagePermissions['can_edit']) {
                    echo json_encode(['success' => false, 'message' => 'Bu işlem için yetkiniz yok!']);
                    break;
                }

                $id    = (int)($_POST['id'] ?? 0);
                $durum = !empty($_POST['durum']) ? 1 : 0;

                $db->update('dbo.CronZamanlamalar', [
                    'Durum'                => $durum,
                    'GuncelleyenKullanici' => $user['kullanici_id'],
                    'GuncellemeTarihi'     => date('Y-m-d H:i:s'),
                ], ['CronZamanlamalar_Id' => $id]);

                echo json_encode([
                    'success' => true,
                    'message' => $durum ? 'Zamanlayıcı devam ettirildi.' : 'Zamanlayıcı duraklatıldı.',
                ]);
                break;

            // ─── Manuel calistir (SENKRON - istek gorev bitene kadar bekler) ───
            case 'tetikle':
                if (!$pagePermissions['can_edit']) {
                    echo json_encode(['success' => false, 'message' => 'Bu işlem için yetkiniz yok!']);
                    break;
                }

                $zamanlamaId = (int)($_POST['zamanlama_id'] ?? 0);
                $gorevId     = (int)($_POST['gorev_id'] ?? 0);
                $hamParams   = json_decode($_POST['parametreler'] ?? '{}', true) ?: [];
                $logZamanId  = null;

                if ($zamanlamaId) {
                    $kayit = $db->fetchOne("
                        SELECT z.CronZamanlamalar_Id, z.CronZamanlamalar_GorevId, z.CronZamanlamalar_SabitParametreler,
                               g.CronGorevler_GorevKodu, g.CronGorevler_MaxSureSn, g.CronGorevler_Parametreler
                        FROM dbo.CronZamanlamalar z
                        INNER JOIN dbo.CronGorevler g ON g.CronGorevler_Id = z.CronZamanlamalar_GorevId
                        WHERE z.CronZamanlamalar_Id = ?
                    ", [$zamanlamaId]);

                    if (!$kayit) {
                        echo json_encode(['success' => false, 'message' => 'Zamanlayıcı bulunamadı.']);
                        break;
                    }

                    $gorevId    = (int)$kayit['CronZamanlamalar_GorevId'];
                    $logZamanId = $zamanlamaId;
                    $hamParams  = json_decode($kayit['CronZamanlamalar_SabitParametreler'] ?? '{}', true) ?: [];
                } else {
                    $kayit = $db->fetchOne("
                        SELECT CronGorevler_GorevKodu, CronGorevler_MaxSureSn, CronGorevler_Parametreler
                        FROM dbo.CronGorevler
                        WHERE CronGorevler_Id = ? AND Durum = 1
                    ", [$gorevId]);

                    if (!$kayit) {
                        echo json_encode(['success' => false, 'message' => 'Görev bulunamadı veya pasif.']);
                        break;
                    }
                }

                // Overlap korumasi - kullanici sabirsizlanip mukerrer is uretmesin
                if (cronCalisiyorMu($db, $gorevId, (int)$kayit['CronGorevler_MaxSureSn'])) {
                    $bas = cronCalisanBaslangic($db, $gorevId);
                    echo json_encode([
                        'success' => false,
                        'message' => 'Bu görev şu anda çalışıyor (başlangıç: ' . ($bas ?? '?') . '). Tetik atlandı.',
                    ]);
                    break;
                }

                ignore_user_abort(true);
                set_time_limit((int)$kayit['CronGorevler_MaxSureSn']);
                cronShutdownHandlerKur();

                $logId = 0;
                $bas   = microtime(true);

                try {
                    $params = cronParamDogrula(
                        $kayit['CronGorevler_Parametreler'] ?? null,
                        dinamikParamCoz($hamParams)
                    );

                    $logId = cronLogOlustur($db, $gorevId, $logZamanId, $params, 0, (int)$user['kullanici_id']);
                    cronAktifLogAc($logId, $bas);

                    $sonuc = gorevCalistir((string)$kayit['CronGorevler_GorevKodu'], $params, $db);

                    cronLogBitir($db, $logId, (int)$sonuc['durum'], $sonuc['sonuc'], $bas, $sonuc['cikti']);
                    cronAktifLogKapat();

                    echo json_encode([
                        'success' => true,
                        'durum'   => (int)$sonuc['durum'],
                        'message' => $sonuc['sonuc'],
                        'cikti'   => $sonuc['cikti'],
                    ], JSON_UNESCAPED_UNICODE);

                } catch (Throwable $e) {
                    $mesaj = $e->getMessage();
                    if ($logId) cronLogBitir($db, $logId, 2, 'HATA: ' . $mesaj, $bas);
                    cronAktifLogKapat();

                    echo json_encode([
                        'success' => false,
                        'durum'   => 2,
                        'message' => $mesaj,
                        'cikti'   => '',
                    ], JSON_UNESCAPED_UNICODE);
                }
                break;

            // ─── Calisma gecmisi (en fazla 500 satır) ───
            case 'log_liste':
                $kosul  = ['1 = 1'];
                $params = [];

                if (!empty($_POST['gorev_id'])) {
                    $kosul[]  = 'l.CronCalismaLog_GorevId = ?';
                    $params[] = (int)$_POST['gorev_id'];
                }
                if (isset($_POST['calisma_durum']) && $_POST['calisma_durum'] !== '') {
                    $kosul[]  = 'l.CronCalismaLog_CalismaDurum = ?';
                    $params[] = (int)$_POST['calisma_durum'];
                }
                if (isset($_POST['tetikleyen']) && $_POST['tetikleyen'] !== '') {
                    $kosul[]  = 'l.CronCalismaLog_TetikleyenTur = ?';
                    $params[] = (int)$_POST['tetikleyen'];
                }
                if (!empty($_POST['bas_tarih'])) {
                    $kosul[]  = 'l.CronCalismaLog_BaslangicTarihi >= ?';
                    $params[] = $_POST['bas_tarih'] . ' 00:00:00';
                }
                if (!empty($_POST['bit_tarih'])) {
                    $kosul[]  = 'l.CronCalismaLog_BaslangicTarihi <= ?';
                    $params[] = $_POST['bit_tarih'] . ' 23:59:59';
                }
                if (!empty($_POST['arama'])) {
                    $kosul[]  = '(l.CronCalismaLog_Sonuc LIKE ? OR g.CronGorevler_Ad LIKE ?)';
                    $params[] = '%' . $_POST['arama'] . '%';
                    $params[] = '%' . $_POST['arama'] . '%';
                }

                $where = implode(' AND ', $kosul);

                $liste = $db->fetchAll("
                    SELECT TOP 500
                        l.CronCalismaLog_Id                                        AS id,
                        g.CronGorevler_Ad                                          AS gorev,
                        z.CronZamanlamalar_Ad                                      AS zamanlama,
                        CONVERT(VARCHAR(19), l.CronCalismaLog_BaslangicTarihi, 120) AS baslangic,
                        CONVERT(VARCHAR(19), l.CronCalismaLog_BitisTarihi, 120)     AS bitis,
                        l.CronCalismaLog_SureSaniye                                 AS sure,
                        l.CronCalismaLog_CalismaDurum                               AS durum,
                        l.CronCalismaLog_TetikleyenTur                              AS tetikleyen,
                        LTRIM(RTRIM(CONCAT(k.kullanici_ad, ' ', k.kullanici_soyad))) AS kullanici,
                        l.CronCalismaLog_Sonuc                                      AS sonuc
                    FROM dbo.CronCalismaLog l
                    INNER JOIN dbo.CronGorevler g ON g.CronGorevler_Id = l.CronCalismaLog_GorevId
                    LEFT  JOIN dbo.CronZamanlamalar z ON z.CronZamanlamalar_Id = l.CronCalismaLog_ZamanlamaId
                    LEFT  JOIN dbo.kullanicilar k ON k.kullanici_id = l.CronCalismaLog_TetikleyenKullanici
                    WHERE {$where}
                    ORDER BY l.CronCalismaLog_Id DESC
                ", $params);

                echo json_encode(['success' => true, 'data' => $liste], JSON_UNESCAPED_UNICODE);
                break;

            case 'log_detay':
                $kayit = $db->fetchOne("
                    SELECT
                        l.CronCalismaLog_Id                                        AS id,
                        g.CronGorevler_Ad                                          AS gorev,
                        CONVERT(VARCHAR(19), l.CronCalismaLog_BaslangicTarihi, 120) AS baslangic,
                        CONVERT(VARCHAR(19), l.CronCalismaLog_BitisTarihi, 120)     AS bitis,
                        l.CronCalismaLog_SureSaniye                                 AS sure,
                        l.CronCalismaLog_CalismaDurum                               AS durum,
                        l.CronCalismaLog_Parametreler                               AS parametreler,
                        l.CronCalismaLog_Sonuc                                      AS sonuc,
                        l.CronCalismaLog_Cikti                                      AS cikti
                    FROM dbo.CronCalismaLog l
                    INNER JOIN dbo.CronGorevler g ON g.CronGorevler_Id = l.CronCalismaLog_GorevId
                    WHERE l.CronCalismaLog_Id = ?
                ", [(int)($_POST['id'] ?? 0)]);

                echo json_encode(['success' => (bool)$kayit, 'data' => $kayit], JSON_UNESCAPED_UNICODE);
                break;

            // ─── Sıklık sablonlari ───
            case 'sablon_liste':
                $liste = $db->fetchAll("
                    SELECT
                        s.CronSiklikSablonlari_Id     AS id,
                        s.CronSiklikSablonlari_Etiket AS etiket,
                        s.CronSiklikSablonlari_Ifade  AS ifade,
                        s.CronSiklikSablonlari_Sira   AS sira,
                        (SELECT COUNT(*) FROM dbo.CronZamanlamalar z
                          WHERE z.CronZamanlamalar_CronIfadesi = s.CronSiklikSablonlari_Ifade
                            AND z.Durum = 1) AS kullanim
                    FROM dbo.CronSiklikSablonlari s
                    WHERE s.Durum = 1
                    ORDER BY s.CronSiklikSablonlari_Sira, s.CronSiklikSablonlari_Id
                ");

                echo json_encode(['success' => true, 'data' => $liste], JSON_UNESCAPED_UNICODE);
                break;

            case 'sablon_kaydet':
                $id = (int)($_POST['id'] ?? 0);

                if (($id && !$pagePermissions['can_edit']) || (!$id && !$pagePermissions['can_add'])) {
                    echo json_encode(['success' => false, 'message' => 'Bu işlem için yetkiniz yok!']);
                    break;
                }

                $ifade = trim($_POST['ifade'] ?? '');
                if ($hata = cronIfadeDogrula($ifade)) {
                    echo json_encode(['success' => false, 'message' => $hata]);
                    break;
                }

                $veri = [
                    'CronSiklikSablonlari_Etiket' => trim($_POST['etiket'] ?? ''),
                    'CronSiklikSablonlari_Ifade'  => $ifade,
                    'CronSiklikSablonlari_Sira'   => (int)($_POST['sira'] ?? 0),
                    'GuncelleyenKullanici'        => $user['kullanici_id'],
                    'GuncellemeTarihi'            => date('Y-m-d H:i:s'),
                ];

                if ($id) {
                    $db->update('dbo.CronSiklikSablonlari', $veri, ['CronSiklikSablonlari_Id' => $id]);
                } else {
                    $veri['OlusturanKullanici'] = $user['kullanici_id'];
                    $veri['OlusturmaTarihi']    = date('Y-m-d H:i:s');
                    $veri['Durum']              = 1;
                    $db->insert('dbo.CronSiklikSablonlari', $veri);
                }

                echo json_encode(['success' => true, 'message' => 'Şablon kaydedildi.']);
                break;

            case 'sablon_sil':
                if (!$pagePermissions['can_delete']) {
                    echo json_encode(['success' => false, 'message' => 'Bu işlem için yetkiniz yok!']);
                    break;
                }

                // Durum = 0: dropdown'dan duser, o ifadeyle kayıtlı zamanlamalar etkilenmez
                $db->update('dbo.CronSiklikSablonlari', [
                    'Durum'                => 0,
                    'GuncelleyenKullanici' => $user['kullanici_id'],
                    'GuncellemeTarihi'     => date('Y-m-d H:i:s'),
                ], ['CronSiklikSablonlari_Id' => (int)($_POST['id'] ?? 0)]);

                echo json_encode(['success' => true, 'message' => 'Şablon kaldırıldı.']);
                break;

            default:
                echo json_encode(['success' => false, 'message' => 'Geçersiz işlem!']);
        }

    } catch (Throwable $e) {
        echo json_encode(['success' => false, 'message' => 'Hata: ' . $e->getMessage()]);
    }
    exit;
}

// ============================================================
// SAYFA
// ============================================================
$pageInfo = $db->fetchOne("
    SELECT s.sayfalar_sayfa_adi, s.sayfalar_aciklama, m.menuler_menu_adi AS menu_adi
    FROM Menu_Sayfalar s
    LEFT JOIN Menuler m ON s.sayfalar_menu_id = m.menuler_id
    WHERE s.sayfalar_sayfa_url LIKE ? AND s.sayfalar_durum = 1
", ['%' . $currentPageFile]);

$pageTitle       = $pageInfo['sayfalar_sayfa_adi'] ?? 'Cron Yönetimi';
$pageDescription = $pageInfo['sayfalar_aciklama'] ?? '';
$menuAdi         = $pageInfo['menu_adi'] ?? null;

$siteAyarlari = $db->fetchOne("SELECT TOP 1 site_ayarlari_site_title FROM dbo.tanim_site_ayarlari ORDER BY site_ayarlari_id DESC");
$siteTitle    = $siteAyarlari['site_ayarlari_site_title'] ?? 'Örnek Yazılım Portal';

// Sıklık sablonlari (zamanlayici modalindeki dropdown)
$sablonlar = $db->fetchAll("
    SELECT CronSiklikSablonlari_Etiket AS etiket, CronSiklikSablonlari_Ifade AS ifade
    FROM dbo.CronSiklikSablonlari
    WHERE Durum = 1
    ORDER BY CronSiklikSablonlari_Sira, CronSiklikSablonlari_Id
");

// Plesk komut satiri. Gizli anahtar BILEREK gosterilmez: Plesk tanimi "Komut calistir"
// bicimindedir ve CLI'da key istenmez. Key'i arayuze basmak, sayfayı gorebilen herkese
// runner'i web'den tetikleme yetkisi vermek olurdu.
$phpYolu     = cronAyar($db, 'php_cli_yolu', 'C:\Program Files (x86)\Plesk\Additional\PleskPHP83\php.exe');
$runnerYolu  = str_replace('/', '\\', realpath(__DIR__ . '/../cron/runner.php') ?: '');
$pleskKomut  = '"' . $phpYolu . '" -d date.timezone=Europe/Istanbul "' . $runnerYolu . '"';
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

        .gorev-karti .card-header { background: var(--bs-body-bg); }
        .gorev-kodu {
            font-family: ui-monospace, SFMono-Regular, Menlo, monospace;
            font-size: .78rem;
        }
        .cron-ifade {
            font-family: ui-monospace, SFMono-Regular, Menlo, monospace;
            color: #b02a37;
        }
        tr.pasif-satır { opacity: .5; }

        .plesk-komut {
            font-family: ui-monospace, SFMono-Regular, Menlo, monospace;
            font-size: .8rem;
        }
        .cikti-kutusu {
            background: #1e1e1e;
            color: #d4d4d4;
            padding: 1rem;
            border-radius: .375rem;
            max-height: 480px;
            overflow: auto;
            font-size: .8rem;
            white-space: pre-wrap;
            word-break: break-word;
        }
        .yer-tutucu-kutusu code { cursor: pointer; }
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

                <!-- Plesk satiri -->
                <div class="card mb-3">
                    <div class="card-body py-2">
                        <div class="d-flex align-items-center flex-wrap gap-2">
                            <span class="text-nowrap">
                                <i class="bi bi-gear-fill text-primary me-1"></i>
                                <strong>Plesk Cron (Tek Satır):</strong>
                            </span>
                            <span class="badge text-bg-secondary cron-ifade">* * * * *</span>
                            <input type="text" class="form-control form-control-sm plesk-komut flex-grow-1"
                                   id="pleskKomut" readonly value="<?= htmlspecialchars($pleskKomut) ?>">
                            <button class="btn btn-sm btn-outline-secondary" onclick="komutKopyala()" title="Kopyala">
                                <i class="bi bi-clipboard"></i>
                            </button>
                        </div>
                        <div class="form-text mt-1">
                            Plesk'te <strong>Komut çalıştır</strong> tipinde, her dakika çalışacak tek görev olarak tanımlayın.
                            Tüm zamanlamalar bu sayfadan yönetilir.
                        </div>
                    </div>
                </div>

                <!-- InfoBox -->
                <div class="row mb-3">
                    <div class="col-12 col-sm-6 col-lg-3">
                        <div class="info-box text-bg-primary">
                            <span class="info-box-icon"><i class="bi bi-list-task"></i></span>
                            <div class="info-box-content">
                                <span class="info-box-text">Tanımlı Görev</span>
                                <span class="info-box-number" id="stat-gorev">0</span>
                            </div>
                        </div>
                    </div>
                    <div class="col-12 col-sm-6 col-lg-3">
                        <div class="info-box text-bg-info">
                            <span class="info-box-icon"><i class="bi bi-clock-history"></i></span>
                            <div class="info-box-content">
                                <span class="info-box-text">Aktif Zamanlayıcı</span>
                                <span class="info-box-number" id="stat-zamanlama">0</span>
                            </div>
                        </div>
                    </div>
                    <div class="col-12 col-sm-6 col-lg-3">
                        <div class="info-box text-bg-success">
                            <span class="info-box-icon"><i class="bi bi-check-circle"></i></span>
                            <div class="info-box-content">
                                <span class="info-box-text">Bugün Başarılı</span>
                                <span class="info-box-number" id="stat-başarılı">0</span>
                            </div>
                        </div>
                    </div>
                    <div class="col-12 col-sm-6 col-lg-3">
                        <div class="info-box text-bg-danger">
                            <span class="info-box-icon"><i class="bi bi-x-circle"></i></span>
                            <div class="info-box-content">
                                <span class="info-box-text">Bugün Hatalı</span>
                                <span class="info-box-number" id="stat-hatalı">0</span>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Sekmeler -->
                <ul class="nav nav-tabs mb-3" id="cronSekme" role="tablist">
                    <li class="nav-item" role="presentation">
                        <button class="nav-link active" data-bs-toggle="tab" data-bs-target="#tab-gorevler" type="button">
                            <i class="bi bi-list-task me-1"></i>Görevler
                        </button>
                    </li>
                    <li class="nav-item" role="presentation">
                        <button class="nav-link" data-bs-toggle="tab" data-bs-target="#tab-gecmis" type="button">
                            <i class="bi bi-journal-text me-1"></i>Çalışma Geçmişi
                        </button>
                    </li>
                    <li class="nav-item" role="presentation">
                        <button class="nav-link" data-bs-toggle="tab" data-bs-target="#tab-sablon" type="button">
                            <i class="bi bi-bookmark me-1"></i>Sıklık Şablonları
                        </button>
                    </li>
                </ul>

                <div class="tab-content">

                    <!-- ══ GOREVLER ══ -->
                    <div class="tab-pane fade show active" id="tab-gorevler">
                        <?php if ($pagePermissions['can_add']): ?>
                            <div class="mb-3 text-end">
                                <button class="btn btn-primary btn-sm" onclick="gorevPenceresi()">
                                    <i class="bi bi-plus-lg me-1"></i>Yeni Görev
                                </button>
                            </div>
                        <?php endif; ?>
                        <div id="gorevKartlari">
                            <div class="text-center text-muted py-5">
                                <div class="spinner-border spinner-border-sm me-2"></div>Yükleniyor...
                            </div>
                        </div>
                    </div>

                    <!-- ══ CALISMA GECMISI ══ -->
                    <div class="tab-pane fade" id="tab-gecmis">
                        <div class="card mb-3">
                            <div class="card-header py-2">
                                <button class="btn btn-sm btn-link text-decoration-none p-0"
                                        data-bs-toggle="collapse" data-bs-target="#logFiltre">
                                    <i class="bi bi-funnel me-1"></i>Filtre
                                </button>
                            </div>
                            <div class="collapse" id="logFiltre">
                                <div class="card-body">
                                    <div class="row g-2">
                                        <div class="col-md-3">
                                            <label class="form-label">Görev</label>
                                            <select class="form-select form-select-sm secim" id="f_gorev">
                                                <option value="">Tümü</option>
                                            </select>
                                        </div>
                                        <div class="col-md-2">
                                            <label class="form-label">Çalışma Durumu</label>
                                            <select class="form-select form-select-sm secim" id="f_durum">
                                                <option value="">Tümü</option>
                                                <option value="1">Başarılı</option>
                                                <option value="2">Hata</option>
                                                <option value="0">Çalışıyor</option>
                                            </select>
                                        </div>
                                        <div class="col-md-2">
                                            <label class="form-label">Tetikleyen</label>
                                            <select class="form-select form-select-sm secim" id="f_tetikleyen">
                                                <option value="">Tümü</option>
                                                <option value="1">Otomatik</option>
                                                <option value="0">Manuel</option>
                                            </select>
                                        </div>
                                        <div class="col-md-2">
                                            <label class="form-label">Başlangıç</label>
                                            <input type="date" class="form-control form-control-sm" id="f_bas">
                                        </div>
                                        <div class="col-md-2">
                                            <label class="form-label">Bitiş</label>
                                            <input type="date" class="form-control form-control-sm" id="f_bit">
                                        </div>
                                        <div class="col-md-3">
                                            <label class="form-label">Arama</label>
                                            <input type="text" class="form-control form-control-sm" id="f_arama"
                                                   placeholder="Sonuç veya görev adı">
                                        </div>
                                        <div class="col-12 text-end">
                                            <button class="btn btn-sm btn-secondary" onclick="filtreTemizle()">
                                                <i class="bi bi-x-lg me-1"></i>Temizle
                                            </button>
                                            <button class="btn btn-sm btn-primary" onclick="loglariYukle()">
                                                <i class="bi bi-search me-1"></i>Uygula
                                            </button>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <div class="card">
                            <div class="card-body">
                                <table class="table table-sm table-hover w-100" id="logTablo">
                                    <thead>
                                        <tr>
                                            <th>#</th>
                                            <th>Görev</th>
                                            <th>Zamanlayıcı</th>
                                            <th>Başlangıç</th>
                                            <th>Süre</th>
                                            <th>Durum</th>
                                            <th>Tetikleyen</th>
                                            <th>Sonuç</th>
                                            <th>İşlem</th>
                                        </tr>
                                    </thead>
                                    <tbody></tbody>
                                </table>
                                <div class="form-text">En fazla son 500 kayıt gösterilir. Daha eskisi için filtre kullanın.</div>
                            </div>
                        </div>
                    </div>

                    <!-- ══ SIKLIK SABLONLARI ══ -->
                    <div class="tab-pane fade" id="tab-sablon">
                        <?php if ($pagePermissions['can_add']): ?>
                            <div class="mb-3 text-end">
                                <button class="btn btn-primary btn-sm" onclick="sablonPenceresi()">
                                    <i class="bi bi-plus-lg me-1"></i>Yeni Şablon
                                </button>
                            </div>
                        <?php endif; ?>
                        <div class="card">
                            <div class="card-body">
                                <table class="table table-sm table-hover w-100" id="sablonTablo">
                                    <thead>
                                        <tr>
                                            <th>Etiket</th>
                                            <th>Cron İfadesi</th>
                                            <th>Sıra</th>
                                            <th>Kullanım</th>
                                            <th>İşlem</th>
                                        </tr>
                                    </thead>
                                    <tbody></tbody>
                                </table>
                            </div>
                        </div>
                    </div>

                </div>
            </div>
        </div>
    </main>

    <?php include __DIR__ . '/../includes/footer.php'; ?>
</div>

<!-- ══ Gorev penceresi ══ -->
<div class="modal fade" id="gorevModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <form id="gorevForm">
                <div class="modal-header">
                    <h5 class="modal-title">Görev</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <input type="hidden" name="id" id="g_id">
                    <div class="row g-3">
                        <div class="col-md-7">
                            <label class="form-label">Görev Adı <span class="text-danger">*</span></label>
                            <input type="text" class="form-control" name="ad" id="g_ad" required>
                        </div>
                        <div class="col-md-5">
                            <label class="form-label">Görev Kodu <span class="text-danger">*</span></label>
                            <input type="text" class="form-control gorev-kodu" name="kod" id="g_kod" required
                                   pattern="[a-z0-9_]+">
                            <div class="form-text">
                                <code>admin/cron/gorevler/&lt;kod&gt;.php</code> dosyasındaki
                                <code>gorev_&lt;kod&gt;()</code> fonksiyonuna karşılık gelir.
                            </div>
                        </div>
                        <div class="col-12">
                            <label class="form-label">Açıklama</label>
                            <input type="text" class="form-control" name="aciklama" id="g_aciklama">
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">Maks. Süre (sn)</label>
                            <input type="number" class="form-control" name="max_sure" id="g_max_sure" value="600" min="30">
                            <div class="form-text">Zombi log eşiği bu değerin 1.5 katıdır.</div>
                        </div>
                        <div class="col-12">
                            <label class="form-label">Parametre Şeması (JSON)</label>
                            <textarea class="form-control gorev-kodu" name="parametreler" id="g_parametreler"
                                      rows="6" placeholder='[{"ad":"gun","etiket":"Gün","tip":"number","varsayılan":90}]'></textarea>
                            <div class="form-text">
                                Zamanlayıcı penceresindeki form alanları bu şemadan üretilir.
                                Tipler: <code>text</code> · <code>number</code> · <code>date</code> ·
                                <code>bool</code> · <code>select</code> · <code>multiselect</code>.
                                <code>select</code>/<code>multiselect</code> için <code>kaynak</code> alanı gerekir.
                            </div>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">İptal</button>
                    <button type="submit" class="btn btn-primary">Kaydet</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- ══ Zamanlayıcı penceresi ══ -->
<div class="modal fade" id="zamanModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <form id="zamanForm">
                <div class="modal-header">
                    <h5 class="modal-title">Zamanlayıcı — <span id="z_gorev_ad"></span></h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <input type="hidden" name="id" id="z_id">
                    <input type="hidden" name="gorev_id" id="z_gorev_id">

                    <div class="row g-3">
                        <div class="col-md-6">
                            <label class="form-label">Zamanlayıcı Adı <span class="text-danger">*</span></label>
                            <input type="text" class="form-control" name="ad" id="z_ad" required>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Sıklık Şablonu</label>
                            <select id="z_sablon" class="form-select secim">
                                <option value="">— Şablon seçin —</option>
                                <?php foreach ($sablonlar as $s): ?>
                                    <option value="<?= htmlspecialchars($s['ifade']) ?>">
                                        <?= htmlspecialchars($s['etiket']) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Cron İfadesi <span class="text-danger">*</span></label>
                            <input type="text" class="form-control cron-ifade" name="ifade" id="z_ifade" required
                                   placeholder="*/15 * * * *">
                            <div class="form-text">dakika · saat · ayın günü · ay · haftanın günü</div>
                        </div>
                        <div class="col-md-3">
                            <label class="form-label">Başlangıç <span class="text-danger">*</span></label>
                            <input type="datetime-local" class="form-control" name="baslangic" id="z_baslangic" required>
                        </div>
                        <div class="col-md-3">
                            <label class="form-label">Bitiş</label>
                            <input type="datetime-local" class="form-control" name="bitis" id="z_bitis">
                            <div class="form-text">Boş = süresiz</div>
                        </div>

                        <div class="col-12"><hr class="my-1"></div>

                        <div class="col-12">
                            <label class="form-label mb-2">Sabit Parametreler</label>
                            <div id="z_param_alanlari" class="row g-3">
                                <div class="col-12 text-muted small">Bu görev parametre almıyor.</div>
                            </div>
                        </div>

                        <div class="col-12">
                            <div class="alert alert-light border yer-tutucu-kutusu py-2 mb-0 small">
                                <strong>Dinamik tarih yer tutucuları</strong>
                                <span class="text-muted">(metin alanlarına yazılabilir, çalışma anında çözülür — tıklayınca kopyalanır)</span>
                                <div class="mt-1" id="z_yer_tutucular"></div>
                            </div>
                        </div>

                        <div class="col-12"><hr class="my-1"></div>

                        <div class="col-md-6">
                            <div class="form-check form-switch">
                                <input class="form-check-input" type="checkbox" role="switch"
                                       id="z_telafi" name="telafi" value="1">
                                <label class="form-check-label" for="z_telafi">Kaçırılan tetiği telafi et</label>
                            </div>
                            <div class="form-text">
                                Günlük/haftalık görevlerde açın. Dakikalık görevlerde anlamsızdır.
                                En fazla <strong>bir kez</strong> telafi edilir.
                            </div>
                        </div>
                        <div class="col-md-3">
                            <label class="form-label">Telafi Sınırı (saat)</label>
                            <input type="number" class="form-control" name="telafi_saat" id="z_telafi_saat"
                                   value="6" min="1" max="72">
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">İptal</button>
                    <button type="submit" class="btn btn-primary">Kaydet</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- ══ Sablon penceresi ══ -->
<div class="modal fade" id="sablonModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <form id="sablonForm">
                <div class="modal-header">
                    <h5 class="modal-title">Sıklık Şablonu</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <input type="hidden" name="id" id="s_id">
                    <div class="mb-3">
                        <label class="form-label">Etiket <span class="text-danger">*</span></label>
                        <input type="text" class="form-control" name="etiket" id="s_etiket" required
                               placeholder="Her 10 dakika">
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Cron İfadesi <span class="text-danger">*</span></label>
                        <input type="text" class="form-control cron-ifade" name="ifade" id="s_ifade" required
                               placeholder="*/10 * * * *">
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Sıra</label>
                        <input type="number" class="form-control" name="sira" id="s_sira" value="0">
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">İptal</button>
                    <button type="submit" class="btn btn-primary">Kaydet</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- ══ Cikti penceresi ══ -->
<div class="modal fade" id="ciktiModal" tabindex="-1">
    <div class="modal-dialog modal-xl">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Çalışma Çıktısı</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <div id="cikti_ozet" class="mb-3"></div>
                <pre class="cikti-kutusu mb-0" id="cikti_govde"></pre>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Kapat</button>
            </div>
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
const permissions = <?= json_encode($pagePermissions) ?>;

// Kayitlar istemcide cache'lenir; satır butonlarina yalnızca data-id konur.
// Satır verisini HTML niteligine gomek, icinde tek tirnak gecen ad/aciklamada butonu bozar.
let GOREVLER = [], ZAMANLAMALAR = [], KAYNAKLAR = {}, YER_TUTUCULAR = {}, SABLONLAR = [];
let logTablo = null, sablonTablo = null;

const gorevModal  = new bootstrap.Modal('#gorevModal');
const zamanModal  = new bootstrap.Modal('#zamanModal');
const sablonModal = new bootstrap.Modal('#sablonModal');
const ciktiModal  = new bootstrap.Modal('#ciktiModal');

const kacir = s => $('<div>').text(s ?? '').html();

/* ─── InfoBox ─── */
function istatistikYukle() {
    $.post('', { action: 'stats' }, r => {
        if (!r.success) return;
        $('#stat-gorev').text(r.data.gorev);
        $('#stat-zamanlama').text(r.data.zamanlama);
        $('#stat-başarılı').text(r.data.başarılı);
        $('#stat-hatalı').text(r.data.hatalı);
    }, 'json');
}

/* ─── Gorev kartlari ─── */
function gorevleriYukle() {
    $.post('', { action: 'gorev_liste' }, r => {
        if (!r.success) { showToast('Görevler yüklenemedi.', 'error'); return; }

        GOREVLER = r.gorevler || [];
        ZAMANLAMALAR = r.zamanlamalar || [];

        kartlariCiz();
        gorevFiltresiDoldur();
    }, 'json');
}

function kartlariCiz() {
    if (!GOREVLER.length) {
        $('#gorevKartlari').html('<div class="alert alert-info mb-0">Henüz tanımlı görev yok.</div>');
        return;
    }

    let html = '';

    GOREVLER.forEach(g => {
        const zList = ZAMANLAMALAR.filter(z => Number(z.gorev_id) === Number(g.id));

        html += `
        <div class="card mb-3 gorev-karti">
            <div class="card-header d-flex flex-wrap align-items-center gap-2">
                <strong>${kacir(g.ad)}</strong>
                <span class="badge text-bg-light gorev-kodu">${kacir(g.kod)}</span>
                <span class="text-muted small flex-grow-1">${kacir(g.aciklama)}</span>
                <div class="btn-group btn-group-sm">
                    ${permissions.can_edit ? `
                        <button class="btn btn-success" onclick="tetikle(0, ${g.id})">
                            <i class="bi bi-play-fill"></i> Manuel Çalıştır
                        </button>
                        <button class="btn btn-primary" onclick="zamanPenceresi(0, ${g.id})">
                            <i class="bi bi-plus-circle"></i> Zamanlayıcı Ekle
                        </button>
                        <button class="btn btn-outline-warning" onclick="gorevPenceresi(${g.id})" title="Düzenle">
                            <i class="bi bi-pencil"></i>
                        </button>` : ''}
                    ${permissions.can_delete ? `
                        <button class="btn btn-outline-danger" onclick="gorevSil(${g.id})" title="Kaldır">
                            <i class="bi bi-trash"></i>
                        </button>` : ''}
                </div>
            </div>
            <div class="card-body p-0">
                ${zList.length ? zamanTablosu(zList) :
                    '<div class="p-3 text-muted small">Bu görevin zamanlayıcısı yok — otomatik çalışmaz.</div>'}
            </div>
        </div>`;
    });

    $('#gorevKartlari').html(html);
}

function zamanTablosu(zList) {
    let satirlar = '';

    zList.forEach(z => {
        const pasif = Number(z.durum) !== 1;

        satirlar += `
        <tr class="${pasif ? 'pasif-satır' : ''}">
            <td>
                ${kacir(z.ad)}
                ${pasif ? '<span class="badge text-bg-secondary ms-1">duraklatıldı</span>' : ''}
                ${Number(z.telafi) === 1 ? `<span class="badge text-bg-info ms-1">telafi ${z.telafi_saat} sa</span>` : ''}
            </td>
            <td class="cron-ifade">${kacir(z.ifade)}</td>
            <td class="small">
                ${kacir(z.baslangic)}<br>
                <span class="text-muted">${z.bitis ? kacir(z.bitis) : 'Süresiz'}</span>
            </td>
            <td class="small">${z.son_calisma ? kacir(z.son_calisma) : '<span class="text-muted">—</span>'}</td>
            <td>${sonDurumRozeti(z)}</td>
            <td class="text-nowrap">
                <div class="btn-group btn-group-sm">
                    ${permissions.can_edit ? `
                        <button class="btn btn-outline-${pasif ? 'success' : 'warning'}"
                                onclick="zamanDurum(${z.id}, ${pasif ? 1 : 0})"
                                title="${pasif ? 'Devam ettir' : 'Duraklat'}">
                            <i class="bi bi-${pasif ? 'play' : 'pause'}"></i>
                        </button>
                        <button class="btn btn-success" onclick="tetikle(${z.id}, 0)" title="Şimdi çalıştır">
                            <i class="bi bi-play-fill"></i>
                        </button>
                        <button class="btn btn-warning" onclick="zamanPenceresi(${z.id}, 0)" title="Düzenle">
                            <i class="bi bi-pencil"></i>
                        </button>` : ''}
                    ${permissions.can_delete ? `
                        <button class="btn btn-danger" onclick="zamanSil(${z.id})" title="Sil">
                            <i class="bi bi-trash"></i>
                        </button>` : ''}
                </div>
            </td>
        </tr>`;
    });

    return `
    <div class="table-responsive">
        <table class="table table-sm table-hover mb-0 align-middle">
            <thead class="table-light">
                <tr>
                    <th>Zamanlayıcı Adı</th>
                    <th>Cron İfadesi</th>
                    <th>Başlangıç / Bitiş</th>
                    <th>Son Çalışma</th>
                    <th>Durum</th>
                    <th>İşlem</th>
                </tr>
            </thead>
            <tbody>${satirlar}</tbody>
        </table>
    </div>`;
}

function sonDurumRozeti(z) {
    if (z.son_durum === null || z.son_durum === undefined) {
        return '<span class="text-muted small">Hiç çalışmadı</span>';
    }

    const d = Number(z.son_durum);
    const rozet = d === 1 ? '<span class="badge text-bg-success">Başarılı</span>'
                : d === 2 ? '<span class="badge text-bg-danger">Hata</span>'
                          : '<span class="badge text-bg-warning">Çalışıyor</span>';

    const sure = (z.son_sure !== null && z.son_sure !== undefined)
        ? `<div class="text-muted small">${z.son_sure}s</div>` : '';

    return rozet + sure;
}

/* ─── Manuel calistir ─── */
function tetikle(zamanlamaId, gorevId) {
    const gorev = gorevBul(zamanlamaId, gorevId);
    const uzun  = gorev && Number(gorev.max_sure) > 300;

    Swal.fire({
        title: 'Görev çalıştırılsın mı?',
        html: `<strong>${kacir(gorev ? gorev.ad : '')}</strong>` + (uzun ? `
            <div class="alert alert-warning text-start mt-3 mb-0 small">
                Bu görevin süre sınırı <strong>${gorev.max_sure} sn</strong>.
                Web isteği sunucu zaman aşımına (genelde ~300 sn) takılabilir; görev arka planda
                devam eder ama sayfa hata verir. Uzun çalışmalar için komut satırını kullanın:
                <div class="mt-2"><code>php admin/cron/worker.php gorev=${kacir(gorev.kod)}</code></div>
            </div>` : ''),
        icon: 'question',
        showCancelButton: true,
        confirmButtonText: 'Çalıştır',
        cancelButtonText: 'İptal',
    }).then(sonuc => {
        if (!sonuc.isConfirmed) return;

        Swal.fire({
            title: 'Çalışıyor...',
            text: 'Görev bitene kadar bekleyin.',
            allowOutsideClick: false,
            didOpen: () => Swal.showLoading(),
        });

        $.post('', { action: 'tetikle', zamanlama_id: zamanlamaId, gorev_id: gorevId }, r => {
            Swal.close();

            $('#cikti_ozet').html(
                `<div class="alert alert-${r.success && Number(r.durum) === 1 ? 'success' : 'danger'} mb-0">
                    ${kacir(r.message)}
                 </div>`
            );
            $('#cikti_govde').text(r.cikti || '(çıktı yok)');
            ciktiModal.show();

            istatistikYukle();
            gorevleriYukle();

        }, 'json').fail(() => {
            Swal.close();
            showToast('İstek tamamlanamadı — görev sunucu zaman aşımına takılmış olabilir. Çalışma geçmişini kontrol edin.', 'error');
            gorevleriYukle();
        });
    });
}

function gorevBul(zamanlamaId, gorevId) {
    if (zamanlamaId) {
        const z = ZAMANLAMALAR.find(x => Number(x.id) === Number(zamanlamaId));
        if (!z) return null;
        gorevId = z.gorev_id;
    }
    return GOREVLER.find(x => Number(x.id) === Number(gorevId)) || null;
}

/* ─── Gorev penceresi ─── */
function gorevPenceresi(id = 0) {
    $('#gorevForm')[0].reset();
    $('#g_id').val(id || '');

    if (id) {
        const g = GOREVLER.find(x => Number(x.id) === Number(id));
        if (!g) return;
        $('#g_ad').val(g.ad);
        $('#g_kod').val(g.kod);
        $('#g_aciklama').val(g.aciklama || '');
        $('#g_max_sure').val(g.max_sure);
        $('#g_parametreler').val(g.parametreler ? JSON.stringify(JSON.parse(g.parametreler), null, 2) : '');
    } else {
        $('#g_max_sure').val(600);
    }

    gorevModal.show();
}

$('#gorevForm').on('submit', function (e) {
    e.preventDefault();
    $.post('', $(this).serialize() + '&action=gorev_kaydet', r => {
        showToast(r.message, r.success ? 'success' : 'error');
        if (r.success) { gorevModal.hide(); gorevleriYukle(); istatistikYukle(); }
    }, 'json');
});

function gorevSil(id) {
    const g = GOREVLER.find(x => Number(x.id) === Number(id));

    Swal.fire({
        title: 'Görev kaldırılsın mı?',
        html: `<strong>${kacir(g ? g.ad : '')}</strong>
               <div class="text-muted small mt-2">
                   Görev pasife alınır (çalışma geçmişi korunur) ve zamanlayıcıları duraklatılır.
               </div>`,
        icon: 'warning',
        showCancelButton: true,
        confirmButtonText: 'Kaldır',
        cancelButtonText: 'İptal',
        confirmButtonColor: '#dc3545',
    }).then(s => {
        if (!s.isConfirmed) return;
        $.post('', { action: 'gorev_sil', id }, r => {
            showToast(r.message, r.success ? 'success' : 'error');
            if (r.success) { gorevleriYukle(); istatistikYukle(); }
        }, 'json');
    });
}

/* ─── Zamanlayıcı penceresi ─── */
function zamanPenceresi(id = 0, gorevId = 0) {
    $('#zamanForm')[0].reset();
    $('#z_id').val(id || '');

    let z = null;
    if (id) {
        z = ZAMANLAMALAR.find(x => Number(x.id) === Number(id));
        if (!z) return;
        gorevId = z.gorev_id;
    }

    const g = GOREVLER.find(x => Number(x.id) === Number(gorevId));
    if (!g) return;

    $('#z_gorev_id').val(gorevId);
    $('#z_gorev_ad').text(g.ad);

    if (z) {
        $('#z_ad').val(z.ad);
        $('#z_ifade').val(z.ifade);
        $('#z_baslangic').val((z.baslangic || '').replace(' ', 'T'));
        $('#z_bitis').val(z.bitis ? z.bitis.replace(' ', 'T') : '');
        $('#z_telafi').prop('checked', Number(z.telafi) === 1);
        $('#z_telafi_saat').val(z.telafi_saat);

        // Ifade tabloda birebir varsa dropdown onu gosterir, yoksa bos kalır.
        $('#z_sablon').val(z.ifade).trigger('change.select2');
    } else {
        // Yeni kayıtta baslangic yerel saatle simdi
        const n = new Date();
        n.setMinutes(n.getMinutes() - n.getTimezoneOffset());
        $('#z_baslangic').val(n.toISOString().slice(0, 16));
        $('#z_telafi_saat').val(6);
        $('#z_sablon').val('').trigger('change.select2');
    }

    paramAlanlariCiz(g, z ? JSON.parse(z.parametreler || '{}') : {});
    zamanModal.show();
}

/* Parametre semasindan form alanlari uret - kullanici elle JSON yazmaz */
function paramAlanlariCiz(gorev, degerler) {
    let sema = [];
    try { sema = JSON.parse(gorev.parametreler || '[]') || []; } catch (e) { sema = []; }

    if (!Array.isArray(sema) || !sema.length) {
        $('#z_param_alanlari').html('<div class="col-12 text-muted small">Bu görev parametre almıyor.</div>');
        return;
    }

    let html = '';

    sema.forEach(alan => {
        const ad     = alan.ad;
        const tip    = alan.tip || 'text';
        const etiket = kacir(alan.etiket || ad);
        const yildiz = alan.zorunlu ? ' <span class="text-danger">*</span>' : '';
        const yardim = alan.aciklama ? `<div class="form-text">${kacir(alan.aciklama)}</div>` : '';

        let deger = degerler[ad];
        if (deger === undefined || deger === null) {
            deger = (alan.varsayılan !== undefined && alan.varsayılan !== null) ? alan.varsayılan : '';
        }

        let girdi = '';

        if (tip === 'bool') {
            girdi = `
                <div class="form-check form-switch">
                    <input class="form-check-input param-alan" type="checkbox" role="switch"
                           id="p_${ad}" data-ad="${ad}" data-tip="bool" value="1"
                           ${Number(deger) === 1 ? 'checked' : ''}>
                    <label class="form-check-label" for="p_${ad}">${etiket}</label>
                </div>${yardim}`;

            html += `<div class="col-md-6">${girdi}</div>`;
            return;
        }

        if (tip === 'select' || tip === 'multiselect') {
            const secenekler = KAYNAKLAR[alan.kaynak] || [];
            const secili = tip === 'multiselect'
                ? (Array.isArray(deger) ? deger.map(String) : [])
                : [String(deger)];

            const opsiyonlar = secenekler.map(o =>
                `<option value="${kacir(o.deger)}" ${secili.includes(String(o.deger)) ? 'selected' : ''}>${kacir(o.etiket)}</option>`
            ).join('');

            girdi = `
                <select class="form-select param-alan secim" data-ad="${ad}" data-tip="${tip}"
                        ${tip === 'multiselect' ? 'multiple' : ''}>
                    ${tip === 'select' ? '<option value="">— Seçiniz —</option>' : ''}
                    ${opsiyonlar}
                </select>`;

            if (!secenekler.length) {
                girdi += `<div class="form-text text-warning">
                            Kaynak boş veya tanımsız: <code>${kacir(alan.kaynak || '-')}</code>
                          </div>`;
            }

        } else {
            const htmlTip = tip === 'number' ? 'number' : (tip === 'date' ? 'date' : 'text');
            girdi = `<input type="${htmlTip}" class="form-control param-alan"
                            data-ad="${ad}" data-tip="${tip}" value="${kacir(deger)}">`;
        }

        html += `
        <div class="col-md-6">
            <label class="form-label">${etiket}${yildiz}</label>
            ${girdi}
            ${yardim}
        </div>`;
    });

    $('#z_param_alanlari').html(html);

    // Modal icindeki dropdown'lar da aranabilir olmalı
    $('#z_param_alanlari .secim').select2({
        theme: 'bootstrap-5',
        dropdownParent: $('#zamanModal'),
        width: '100%',
        placeholder: 'Seçiniz',
    });
}

function paramlariTopla() {
    const veri = {};

    $('#z_param_alanlari .param-alan').each(function () {
        const $e  = $(this);
        const ad  = $e.data('ad');
        const tip = $e.data('tip');

        if (tip === 'bool') {
            veri[ad] = $e.is(':checked') ? 1 : 0;
            return;
        }

        if (tip === 'multiselect') {
            const secili = $e.val() || [];
            // Bos multiselect parametreye HIC yazılmaz - "secim yok" ile "bos secildi" ayrilsin
            if (secili.length) veri[ad] = secili.map(Number);
            return;
        }

        const d = ($e.val() || '').trim();
        if (d === '') return;

        veri[ad] = (tip === 'number' && !isNaN(d)) ? Number(d)
                 : (tip === 'select' ? Number(d) : d);
    });

    return veri;
}

$('#z_sablon').on('change', function () {
    // Tek yonlu kolaylik: yalnızca cron ifadesi input'unu doldurur.
    const v = $(this).val();
    if (v) $('#z_ifade').val(v);
});

$('#zamanForm').on('submit', function (e) {
    e.preventDefault();

    const veri = {
        action:       'zamanlama_kaydet',
        id:           $('#z_id').val(),
        gorev_id:     $('#z_gorev_id').val(),
        ad:           $('#z_ad').val(),
        ifade:        $('#z_ifade').val(),
        baslangic:    ($('#z_baslangic').val() || '').replace('T', ' '),
        bitis:        ($('#z_bitis').val() || '').replace('T', ' '),
        telafi:       $('#z_telafi').is(':checked') ? 1 : 0,
        telafi_saat:  $('#z_telafi_saat').val(),
        parametreler: JSON.stringify(paramlariTopla()),
    };

    $.post('', veri, r => {
        showToast(r.message, r.success ? 'success' : 'error');
        if (r.success) { zamanModal.hide(); gorevleriYukle(); istatistikYukle(); }
    }, 'json');
});

function zamanDurum(id, durum) {
    $.post('', { action: 'zamanlama_durum', id, durum }, r => {
        showToast(r.message, r.success ? 'success' : 'error');
        if (r.success) { gorevleriYukle(); istatistikYukle(); }
    }, 'json');
}

function zamanSil(id) {
    const z = ZAMANLAMALAR.find(x => Number(x.id) === Number(id));

    Swal.fire({
        title: 'Zamanlayıcı silinsin mi?',
        html: `<strong>${kacir(z ? z.ad : '')}</strong>
               <div class="text-muted small mt-2">Bu işlem geri alınamaz.</div>`,
        icon: 'warning',
        showCancelButton: true,
        confirmButtonText: 'Sil',
        cancelButtonText: 'İptal',
        confirmButtonColor: '#dc3545',
    }).then(s => {
        if (!s.isConfirmed) return;
        $.post('', { action: 'zamanlama_sil', id }, r => {
            showToast(r.message, r.success ? 'success' : 'error');
            if (r.success) { gorevleriYukle(); istatistikYukle(); }
        }, 'json');
    });
}

/* ─── Calisma gecmisi ─── */
function gorevFiltresiDoldur() {
    const mevcut = $('#f_gorev').val();
    let html = '<option value="">Tümü</option>';
    GOREVLER.forEach(g => { html += `<option value="${g.id}">${kacir(g.ad)}</option>`; });
    $('#f_gorev').html(html).val(mevcut);
}

function loglariYukle() {
    const veri = {
        action:        'log_liste',
        gorev_id:      $('#f_gorev').val(),
        calisma_durum: $('#f_durum').val(),
        tetikleyen:    $('#f_tetikleyen').val(),
        bas_tarih:     $('#f_bas').val(),
        bit_tarih:     $('#f_bit').val(),
        arama:         $('#f_arama').val(),
    };

    $.post('', veri, r => {
        if (!r.success) { showToast('Geçmiş yüklenemedi.', 'error'); return; }

        const satirlar = (r.data || []).map(l => {
            const d = Number(l.durum);
            const rozet = d === 1 ? '<span class="badge text-bg-success">Başarılı</span>'
                        : d === 2 ? '<span class="badge text-bg-danger">Hata</span>'
                                  : '<span class="badge text-bg-warning">Çalışıyor</span>';

            const tetik = Number(l.tetikleyen) === 1
                ? '<span class="badge text-bg-secondary">Otomatik</span>'
                : `<span class="badge text-bg-info">Manuel</span> <small>${kacir(l.kullanici)}</small>`;

            return [
                l.id,
                kacir(l.gorev),
                l.zamanlama ? kacir(l.zamanlama) : '<span class="text-muted">—</span>',
                kacir(l.baslangic),
                (l.sure !== null && l.sure !== undefined) ? l.sure + 's' : '—',
                rozet,
                tetik,
                `<span class="small">${kacir(l.sonuc)}</span>`,
                `<button class="btn btn-sm btn-outline-primary" onclick="logDetay(${l.id})" title="Çıktı">
                    <i class="bi bi-terminal"></i>
                 </button>`,
            ];
        });

        if (logTablo) {
            logTablo.clear().rows.add(satirlar).draw();
        } else {
            logTablo = $('#logTablo').DataTable({
                data: satirlar,
                dom: 'lrtip',                       // global arama kapali - filtre paneli kullanılıyor
                order: [[0, 'desc']],
                pageLength: 25,
                scrollX: true,                      // sarmalayici div DEGIL, DataTables kendi secenegi
                language: { url: 'https://cdn.datatables.net/plug-ins/1.13.7/i18n/tr.json' },
                columnDefs: [{ targets: [8], orderable: false }],
            });
        }
    }, 'json');
}

function filtreTemizle() {
    $('#f_gorev, #f_durum, #f_tetikleyen').val('').trigger('change.select2');
    $('#f_bas, #f_bit, #f_arama').val('');
    loglariYukle();
}

function logDetay(id) {
    $.post('', { action: 'log_detay', id }, r => {
        if (!r.success) { showToast('Kayıt bulunamadı.', 'error'); return; }

        const l = r.data;
        const d = Number(l.durum);

        $('#cikti_ozet').html(`
            <div class="alert alert-${d === 1 ? 'success' : (d === 2 ? 'danger' : 'warning')} mb-2">
                ${kacir(l.sonuc)}
            </div>
            <div class="small text-muted">
                <strong>${kacir(l.gorev)}</strong> ·
                ${kacir(l.baslangic)} → ${l.bitis ? kacir(l.bitis) : '—'} ·
                ${(l.sure !== null && l.sure !== undefined) ? l.sure + ' sn' : '—'}
                ${l.parametreler ? ` · <code>${kacir(l.parametreler)}</code>` : ''}
            </div>`);

        $('#cikti_govde').text(l.cikti || '(çıktı yok)');
        ciktiModal.show();
    }, 'json');
}

/* ─── Sıklık sablonlari ─── */
function sablonlariYukle() {
    $.post('', { action: 'sablon_liste' }, r => {
        if (!r.success) return;
        SABLONLAR = r.data || [];

        const satirlar = SABLONLAR.map(s => [
            kacir(s.etiket),
            `<span class="cron-ifade">${kacir(s.ifade)}</span>`,
            s.sira,
            `<span class="badge text-bg-${Number(s.kullanim) ? 'primary' : 'secondary'}">${s.kullanim}</span>`,
            `<div class="btn-group btn-group-sm">
                ${permissions.can_edit ? `<button class="btn btn-warning" onclick="sablonPenceresi(${s.id})"><i class="bi bi-pencil"></i></button>` : ''}
                ${permissions.can_delete ? `<button class="btn btn-danger" onclick="sablonSil(${s.id})"><i class="bi bi-trash"></i></button>` : ''}
             </div>`,
        ]);

        if (sablonTablo) {
            sablonTablo.clear().rows.add(satirlar).draw();
        } else {
            sablonTablo = $('#sablonTablo').DataTable({
                data: satirlar,
                dom: 'lrtip',
                order: [[2, 'asc']],
                pageLength: 25,
                scrollX: true,
                language: { url: 'https://cdn.datatables.net/plug-ins/1.13.7/i18n/tr.json' },
                columnDefs: [{ targets: [4], orderable: false }],
            });
        }
    }, 'json');
}

function sablonPenceresi(id = 0) {
    $('#sablonForm')[0].reset();
    $('#s_id').val(id || '');

    if (id) {
        const s = SABLONLAR.find(x => Number(x.id) === Number(id));
        if (!s) return;
        $('#s_etiket').val(s.etiket);
        $('#s_ifade').val(s.ifade);
        $('#s_sira').val(s.sira);
    }

    sablonModal.show();
}

$('#sablonForm').on('submit', function (e) {
    e.preventDefault();
    $.post('', $(this).serialize() + '&action=sablon_kaydet', r => {
        showToast(r.message, r.success ? 'success' : 'error');
        if (r.success) { sablonModal.hide(); sablonlariYukle(); }
    }, 'json');
});

function sablonSil(id) {
    Swal.fire({
        title: 'Şablon kaldırılsın mı?',
        text: 'Dropdown\'dan düşer; bu ifadeyle kayıtlı zamanlayıcılar etkilenmez.',
        icon: 'warning',
        showCancelButton: true,
        confirmButtonText: 'Kaldır',
        cancelButtonText: 'İptal',
        confirmButtonColor: '#dc3545',
    }).then(s => {
        if (!s.isConfirmed) return;
        $.post('', { action: 'sablon_sil', id }, r => {
            showToast(r.message, r.success ? 'success' : 'error');
            if (r.success) sablonlariYukle();
        }, 'json');
    });
}

/* ─── Yardimci ─── */
function komutKopyala() {
    const alan = document.getElementById('pleskKomut');
    alan.select();
    navigator.clipboard.writeText(alan.value)
        .then(() => showToast('Komut kopyalandı.', 'success'))
        .catch(() => showToast('Kopyalanamadı, elle seçin.', 'warning'));
}

function yerTutuculariCiz() {
    const html = Object.entries(YER_TUTUCULAR)
        .map(([k, v]) => `<code class="me-2" title="${kacir(v)}" onclick="yerTutucuKopyala('${k}')">${kacir(k)}</code>`)
        .join('');
    $('#z_yer_tutucular').html(html);
}

function yerTutucuKopyala(k) {
    navigator.clipboard.writeText(k).then(() => showToast(k + ' kopyalandı.', 'info'));
}

/* ─── Baslangic ─── */
$(function () {
    $('.secim').select2({ theme: 'bootstrap-5', width: '100%' });
    $('#z_sablon').select2({ theme: 'bootstrap-5', width: '100%', dropdownParent: $('#zamanModal') });

    istatistikYukle();
    gorevleriYukle();
    sablonlariYukle();
    loglariYukle();

    // Dropdown kaynaklari bir kez cekilip cache'lenir; her pencere acilisinda sorgu atilmaz
    $.post('', { action: 'param_kaynaklar' }, r => {
        if (!r.success) return;
        KAYNAKLAR = r.kaynaklar || {};
        YER_TUTUCULAR = r.yer_tutucu || {};
        yerTutuculariCiz();
    }, 'json');

    // Gizli sekmede kolon genisligi hesaplanamaz; sekme gorununce ayarlanmali
    $('button[data-bs-toggle="tab"]').on('shown.bs.tab', () => {
        if (logTablo) logTablo.columns.adjust();
        if (sablonTablo) sablonTablo.columns.adjust();
    });
});
</script>
</body>
</html>
