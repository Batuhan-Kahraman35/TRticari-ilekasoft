<?php
/**
 * Gorev: Fikstur Kazima
 *
 * haberciniz.biz lig gosterim servisinden cari hafta fiksturunu ceker ve
 * SporFikstur tablosuna lig|hafta|ev|deplasman anahtariyla upsert eder.
 * Parametresizdir.
 *
 * Onerilen zamanlama: 0,15,30,45 * * * *   ·   TelafiEt = 0
 */

if (!defined('CRON_SISTEMI')) exit('Dogrudan calistirilamaz.');

require_once __DIR__ . '/../../includes/FiksturHelper.php';

function gorev_fikstur_kazi(array $params, $db): array
{
    ob_start();

    $r = FiksturHelper::senkron($db, null);

    if (!$r['success']) {
        echo "[HATA] {$r['message']}\n";
        return [
            'durum' => 2,
            'sonuc' => mb_substr($r['message'], 0, 200),
            'cikti' => ob_get_clean(),
        ];
    }

    echo "Toplam islenen mac : {$r['toplam']}\n";
    echo "Yeni kayit         : {$r['eklenen']}\n";
    echo "Guncellenen        : {$r['guncellenen']}\n";

    foreach ($r['ligler'] as $kod => $adet) {
        echo "  {$kod}: {$adet} mac\n";
    }

    return [
        'durum' => 1,
        'sonuc' => $r['message'],
        'cikti' => ob_get_clean(),
    ];
}
