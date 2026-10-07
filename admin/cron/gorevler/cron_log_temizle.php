<?php
/**
 * Gorev: Cron Log Temizligi (retention)
 *
 * Saklama suresini asan CronCalismaLog kayitlarini siler.
 * Sure: parametre > CronAyarlar.log_saklama_gun > 90 (varsayilan). Alt sinir 7 gun.
 *
 * Onerilen zamanlama: 30 4 * * *   ·   TelafiEt = 0
 */

if (!defined('CRON_SISTEMI')) exit('Dogrudan calistirilamaz.');

function gorev_cron_log_temizle(array $params, $db): array
{
    ob_start();

    $gun = (int)($params['gun'] ?? cronAyar($db, 'log_saklama_gun', '90'));
    if ($gun < 7) $gun = 7;   // guvenlik alt siniri

    echo "Saklama suresi: {$gun} gun\n";

    // Buyuk tabloda tek DELETE log dosyasini sisirir ve tabloyu kilitler - partiler halinde sil.
    // SET NOCOUNT ON sayesinde DELETE resultset uretmez, @@ROWCOUNT ilk resultset olur.
    $toplam = 0;
    $tur    = 0;

    do {
        $satir = $db->fetchOne("
            SET NOCOUNT ON;
            DELETE TOP (5000) FROM dbo.CronCalismaLog
            WHERE CronCalismaLog_BaslangicTarihi < DATEADD(DAY, -?, GETDATE());
            SELECT @@ROWCOUNT AS Adet;
        ", [$gun]);

        $silinen = (int)($satir['Adet'] ?? 0);
        $toplam += $silinen;
        $tur++;

        if ($silinen > 0) echo "  Tur {$tur}: {$silinen} kayit silindi.\n";

    } while ($silinen === 5000 && $tur < 200);

    $kalan = $db->fetchOne("SELECT COUNT(*) AS Adet FROM dbo.CronCalismaLog");

    echo "[OK] {$gun} gunden eski toplam {$toplam} log kaydi silindi.\n";
    echo "Tabloda kalan: " . (int)($kalan['Adet'] ?? 0) . " kayit.\n";

    return [
        'durum' => 1,
        'sonuc' => "{$toplam} kayit silindi ({$gun} gun)",
        'cikti' => ob_get_clean(),
    ];
}
