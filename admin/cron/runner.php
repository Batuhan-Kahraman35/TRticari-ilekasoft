<?php
/**
 * Merkezi Cron Sistemi - Runner (tek Plesk tanimi)
 *
 * Plesk Cron (her dakika, "Komut calistir"):
 *   "C:\Program Files (x86)\Plesk\Additional\PleskPHP83\php.exe" -d date.timezone=Europe/Istanbul
 *   "D:\Inetpub\vhosts\ornekproje.com\ticari.ornekproje.com\admin\cron\runner.php"
 *
 * Web tetigi (gerekiyorsa): runner.php?key=<CronAyarlar.cron_secret_key>
 *
 * Akis:
 *   1) Aktif zamanlamalar cekilir
 *   2) Cron eslesmesi -> yoksa telafi kontrolu
 *   3) Ayni dakika cift tetik kilidi
 *   4) Overlap korumasi (SonCalisma guncellemesinden ONCE)
 *   5) Kilidi al, calistir, logla
 *
 * @version 1.0
 */

require_once __DIR__ . '/tasks.php';

$db = Database::getInstance();
cronKeyDogrula($db);

if (PHP_SAPI !== 'cli') {
    header('Content-Type: text/plain; charset=utf-8');
}

// FastCGI baglanti koparsa gorev yarida kalmasin
ignore_user_abort(true);
set_time_limit(600);

cronShutdownHandlerKur();

$simdi    = new DateTime();
$buDakika = $simdi->format('Y-m-d H:i:00');

echo "[" . $simdi->format('Y-m-d H:i:s') . "] Cron runner basladi.\n";

$zamanlamalar = $db->fetchAll("
    SELECT
        z.CronZamanlamalar_Id,
        z.CronZamanlamalar_GorevId,
        z.CronZamanlamalar_Ad,
        z.CronZamanlamalar_CronIfadesi,
        z.CronZamanlamalar_SabitParametreler,
        z.CronZamanlamalar_TelafiEt,
        z.CronZamanlamalar_TelafiSaatSiniri,
        CONVERT(VARCHAR(19), z.CronZamanlamalar_SonCalisma, 120) AS SonCalismaMetin,
        g.CronGorevler_GorevKodu,
        g.CronGorevler_Ad AS GorevAd,
        g.CronGorevler_MaxSureSn,
        g.CronGorevler_Parametreler
    FROM dbo.CronZamanlamalar z
    INNER JOIN dbo.CronGorevler g ON g.CronGorevler_Id = z.CronZamanlamalar_GorevId
    WHERE z.Durum = 1
      AND g.Durum = 1
      AND z.CronZamanlamalar_BaslangicTarihi <= GETDATE()
      AND (z.CronZamanlamalar_BitisTarihi IS NULL OR z.CronZamanlamalar_BitisTarihi >= GETDATE())
    ORDER BY z.CronZamanlamalar_Id
");

$calisan = 0;

foreach ($zamanlamalar as $z) {
    $ifade = (string)$z['CronZamanlamalar_CronIfadesi'];

    // 1) Normal eslesme
    $tetik  = cronEslesiyor($ifade, $simdi);
    $telafi = false;

    // 2) Eslesme yoksa telafi kontrolu
    if (!$tetik && (int)$z['CronZamanlamalar_TelafiEt'] === 1) {
        $telafi = cronKacirilanTetik(
            $ifade,
            $z['SonCalismaMetin'],
            (int)$z['CronZamanlamalar_TelafiSaatSiniri'],
            $simdi
        );
        $tetik = $telafi;
    }

    if (!$tetik) continue;

    // 3) Ayni dakika cift tetik kilidi
    if (!empty($z['SonCalismaMetin']) && $z['SonCalismaMetin'] >= $buDakika) {
        echo "  ATLANDI (bu dakika zaten calisti): {$z['CronZamanlamalar_Ad']}\n";
        continue;
    }

    // 4) Overlap korumasi - SonCalisma guncellemesinden ONCE
    if (cronCalisiyorMu($db, (int)$z['CronZamanlamalar_GorevId'], (int)$z['CronGorevler_MaxSureSn'])) {
        echo "  ATLANDI (gorev hala calisiyor): {$z['GorevAd']}\n";
        continue;
    }

    // 5) Kilidi al
    $simdiMetin = date('Y-m-d H:i:s');
    $db->update('dbo.CronZamanlamalar', [
        'CronZamanlamalar_SonCalisma' => $simdiMetin,
        'GuncellemeTarihi'            => $simdiMetin,
    ], ['CronZamanlamalar_Id' => (int)$z['CronZamanlamalar_Id']]);

    $ham    = json_decode($z['CronZamanlamalar_SabitParametreler'] ?? '{}', true) ?: [];
    $onek   = $telafi ? '[TELAFI] ' : '';
    $logId  = 0;
    $bas    = microtime(true);

    echo "  {$onek}CALISIYOR: {$z['GorevAd']} / {$z['CronZamanlamalar_Ad']} ({$ifade})\n";

    try {
        $params = cronParamDogrula($z['CronGorevler_Parametreler'], dinamikParamCoz($ham));

        $logId = cronLogOlustur(
            $db,
            (int)$z['CronZamanlamalar_GorevId'],
            (int)$z['CronZamanlamalar_Id'],
            $params,
            1
        );
        cronAktifLogAc($logId, $bas);

        set_time_limit((int)$z['CronGorevler_MaxSureSn']);   // her gorevde sayaci sifirla

        $sonuc = gorevCalistir((string)$z['CronGorevler_GorevKodu'], $params, $db);

        cronLogBitir($db, $logId, (int)$sonuc['durum'], $onek . $sonuc['sonuc'], $bas, $sonuc['cikti']);
        echo "  -> " . ((int)$sonuc['durum'] === 1 ? 'BASARILI' : 'HATA') . ": {$sonuc['sonuc']}\n";

    } catch (Throwable $e) {
        $mesaj = get_class($e) . ': ' . $e->getMessage();
        if ($logId) {
            cronLogBitir($db, $logId, 2, $onek . 'HATA: ' . $mesaj, $bas);
        }
        error_log('Cron gorev hatasi (' . $z['CronGorevler_GorevKodu'] . '): ' . $mesaj);
        echo "  -> HATA: {$mesaj}\n";
    }

    cronAktifLogKapat();   // ZORUNLU - atlanirsa shutdown handler son gorevi ezer
    $calisan++;
}

echo "[" . date('Y-m-d H:i:s') . "] Bitti. Taranan: " . count($zamanlamalar) . ", calisan: {$calisan}.\n";
