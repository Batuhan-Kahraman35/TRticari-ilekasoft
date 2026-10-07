<?php
/**
 * Merkezi Cron Sistemi - Worker (tek gorev / tek zamanlama manuel tetigi)
 *
 * CLI:
 *   php worker.php gorev=cron_log_temizle
 *   php worker.php gorev=cron_log_temizle gun=30
 *   php worker.php zamanlama=5
 *
 * Web (key zorunlu):
 *   worker.php?gorev=cron_log_temizle&key=...
 *   worker.php?zamanlama=5&key=...
 *
 * Uzun gorevlerde (MaxSureSn > 300) web istegi FastCGI zaman asimina takilir;
 * CLI kullanin - sure siniri yok, cikti canli akar.
 *
 * @version 1.0
 */

require_once __DIR__ . '/tasks.php';

$db = Database::getInstance();
cronKeyDogrula($db);

if (PHP_SAPI !== 'cli') {
    header('Content-Type: text/plain; charset=utf-8');
}

ignore_user_abort(true);
cronShutdownHandlerKur();

// ------------------------------------------------------------
// Argumanlari topla (CLI: ad=deger  ·  Web: query string)
// ------------------------------------------------------------
$arg = [];

if (PHP_SAPI === 'cli') {
    foreach (array_slice($argv, 1) as $parca) {
        $parca = ltrim($parca, '-');
        if (str_contains($parca, '=')) {
            [$k, $v] = explode('=', $parca, 2);
            $arg[trim($k)] = $v;
        } else {
            $arg[trim($parca)] = '1';
        }
    }
} else {
    $arg = $_GET;
}

unset($arg['key']);

$zamanlamaId = isset($arg['zamanlama']) ? (int)$arg['zamanlama'] : 0;
$gorevKodu   = isset($arg['gorev']) ? trim((string)$arg['gorev']) : '';
unset($arg['zamanlama'], $arg['gorev']);

// ------------------------------------------------------------
// Kaynagi coz: zamanlama mi, dogrudan gorev mi?
// ------------------------------------------------------------
$gorevId    = 0;
$maxSure    = 600;
$semaJson   = null;
$hamParams  = $arg;
$logZamanId = null;
$baslik     = '';

if ($zamanlamaId > 0) {
    $z = $db->fetchOne("
        SELECT z.CronZamanlamalar_Id, z.CronZamanlamalar_Ad, z.CronZamanlamalar_GorevId,
               z.CronZamanlamalar_SabitParametreler,
               g.CronGorevler_GorevKodu, g.CronGorevler_Ad, g.CronGorevler_MaxSureSn,
               g.CronGorevler_Parametreler
        FROM dbo.CronZamanlamalar z
        INNER JOIN dbo.CronGorevler g ON g.CronGorevler_Id = z.CronZamanlamalar_GorevId
        WHERE z.CronZamanlamalar_Id = ? AND z.Durum = 1 AND g.Durum = 1
    ", [$zamanlamaId]);

    if (!$z) {
        http_response_code(404);
        exit("Zamanlama bulunamadi: {$zamanlamaId}\n");
    }

    $gorevId    = (int)$z['CronZamanlamalar_GorevId'];
    $gorevKodu  = (string)$z['CronGorevler_GorevKodu'];
    $maxSure    = (int)$z['CronGorevler_MaxSureSn'];
    $semaJson   = $z['CronGorevler_Parametreler'];
    $logZamanId = (int)$z['CronZamanlamalar_Id'];
    $baslik     = $z['CronGorevler_Ad'] . ' / ' . $z['CronZamanlamalar_Ad'];

    // Zamanlamanin sabit parametreleri temel alinir, CLI argumani ustune yazar
    $sabit     = json_decode($z['CronZamanlamalar_SabitParametreler'] ?? '{}', true) ?: [];
    $hamParams = array_merge($sabit, $arg);

} elseif ($gorevKodu !== '') {
    $g = cronGorevGetir($db, $gorevKodu);

    if (!$g) {
        http_response_code(404);
        exit("Gorev bulunamadi veya pasif: {$gorevKodu}\n");
    }

    $gorevId  = (int)$g['CronGorevler_Id'];
    $maxSure  = (int)$g['CronGorevler_MaxSureSn'];
    $semaJson = $g['CronGorevler_Parametreler'];
    $baslik   = $g['CronGorevler_Ad'];

} else {
    exit("Kullanim: worker.php gorev=<KOD> [param=deger ...]  |  worker.php zamanlama=<ID>\n");
}

// ------------------------------------------------------------
// Overlap korumasi - manuel tetik de ayni kontrolden gecer
// ------------------------------------------------------------
if (cronCalisiyorMu($db, $gorevId, $maxSure)) {
    $bas = cronCalisanBaslangic($db, $gorevId);
    exit("Bu gorev su anda calisiyor (baslangic: " . ($bas ?? '?') . "). Tetik atlandi.\n");
}

// ------------------------------------------------------------
// Calistir
// ------------------------------------------------------------
echo "[" . date('Y-m-d H:i:s') . "] {$baslik} baslatiliyor...\n";

$logId = 0;
$bas   = microtime(true);

try {
    $params = cronParamDogrula($semaJson, dinamikParamCoz($hamParams));

    $logId = cronLogOlustur($db, $gorevId, $logZamanId, $params, PHP_SAPI === 'cli' ? 1 : 0);
    cronAktifLogAc($logId, $bas);

    set_time_limit(PHP_SAPI === 'cli' ? 0 : $maxSure);

    $sonuc = gorevCalistir($gorevKodu, $params, $db);

    cronLogBitir($db, $logId, (int)$sonuc['durum'], $sonuc['sonuc'], $bas, $sonuc['cikti']);
    cronAktifLogKapat();

    echo $sonuc['cikti'];
    echo "\n[" . date('Y-m-d H:i:s') . "] "
       . ((int)$sonuc['durum'] === 1 ? 'BASARILI' : 'HATA') . ": {$sonuc['sonuc']}\n";

    exit((int)$sonuc['durum'] === 1 ? 0 : 1);

} catch (Throwable $e) {
    $mesaj = get_class($e) . ': ' . $e->getMessage();
    if ($logId) {
        cronLogBitir($db, $logId, 2, 'HATA: ' . $mesaj, $bas);
    }
    cronAktifLogKapat();

    error_log('Cron worker hatasi (' . $gorevKodu . '): ' . $mesaj);
    echo "\n[" . date('Y-m-d H:i:s') . "] HATA: {$mesaj}\n";
    exit(1);
}
