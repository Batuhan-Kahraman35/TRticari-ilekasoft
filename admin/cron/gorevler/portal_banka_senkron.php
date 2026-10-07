<?php
/**
 * Gorev: Ornek Portal Banka Hareket Senkronu
 *
 * portal.ornekproje.com/api/v1 uzerinden alacak hareketlerini ceker.
 *
 * Kaynak: admin/cron/portal-banka-senkron.php (v1). Degisenler:
 *   - Dosya kilidi (logs/portal-banka-senkron.lock) KALDIRILDI; overlap korumasi artik
 *     merkezi (CronCalismaLog.CalismaDurum = 0 kontrolu). Iki kilit birlikte calisirsa
 *     bayat kilit senaryosunda gorev sessizce hic calismaz.
 *   - Ayri gunluk log dosyasi kaldirildi; cikti CronCalismaLog.Cikti alaninda.
 *   - exit(1) cagrilari kaldirildi; hata durum=2 olarak doner (exit include'da runner'i oldururdu).
 *   - CLI-only guard kaldirildi; panelden manuel tetikleme artik desteklenir.
 *
 * Sayfa siniri: bir calismada tum kayitlar gelmeyebilir. devam_var = true ise
 * "otomatik_devam" parametresi ile ayni calismada tur tekrarlanir (varsayilan 5 tur).
 *
 * Onerilen zamanlama: *​/15 * * * *   ·   TelafiEt = 0   ·   MaxSureSn >= 900
 */

if (!defined('CRON_SISTEMI')) exit('Dogrudan calistirilamaz.');

require_once __DIR__ . '/../../api/banka/PortalService.php';

function gorev_portal_banka_senkron(array $params, $db): array
{
    ob_start();

    $kanalKod = trim((string)($params['kanal'] ?? 'banka_hareket'));
    $imlec    = isset($params['imlec']) && ctype_digit((string)$params['imlec']) ? (int)$params['imlec'] : null;
    $maxTur   = (int)($params['max_tur'] ?? 5);
    if ($maxTur < 1)  $maxTur = 1;
    if ($maxTur > 50) $maxTur = 50;

    // Saat dilimi sapmasi kayit tarihlerini kaydirir - erken uyar.
    if (ini_get('date.timezone') !== 'Europe/Istanbul' && date_default_timezone_get() !== 'Europe/Istanbul') {
        echo "UYARI: Saat dilimi Europe/Istanbul degil ("
           . (date_default_timezone_get() ?: 'tanimsiz') . ").\n";
    }

    echo "Kanal: {$kanalKod}\n";

    $servis = PortalService::kanaldan($kanalKod);

    if ($imlec !== null) {
        $eski = $servis->imlec();
        $servis->imlecAyarla($imlec);
        echo "Imlec elle ayarlandi: {$eski} -> {$imlec}\n";
    }

    echo "Baslangic imleci: " . $servis->imlec() . "\n\n";

    $toplamEklenen = 0;
    $tur           = 0;
    $devamVar      = false;
    $hataMesaji    = null;

    do {
        $tur++;
        $sonuc = $servis->senkronEt();

        $eklenen = (int)($sonuc['eklenen'] ?? 0);
        $toplamEklenen += $eklenen;

        echo "Tur {$tur}: " . ($sonuc['message'] ?? '-') . "\n";

        if (empty($sonuc['success'])) {
            $hataMesaji = (string)($sonuc['message'] ?? 'Bilinmeyen senkron hatasi');
            echo "  HATA: {$hataMesaji}\n";
            echo "  Kismi sonuc: {$eklenen} eklendi, imlec " . ($sonuc['son_id'] ?? '?') . ".\n";
            break;
        }

        $devamVar = !empty($sonuc['devam_var']);

        if ($devamVar && $tur >= $maxTur) {
            echo "  UYARI: {$maxTur} tur siniri doldu, kalan kayitlar bir sonraki calismada alinacak.\n";
            break;
        }

    } while ($devamVar);

    echo "\nBitis imleci: " . $servis->imlec() . "\n";

    if ($hataMesaji !== null) {
        $ozet = "HATA: {$hataMesaji} ({$toplamEklenen} hareket eklendi)";
        echo "[HATA] {$ozet}\n";
        return ['durum' => 2, 'sonuc' => $ozet, 'cikti' => ob_get_clean()];
    }

    $ozet = "{$toplamEklenen} hareket eklendi ({$tur} tur)"
          . ($devamVar ? ', devam var' : '');

    echo "[OK] {$ozet}\n";

    return ['durum' => 1, 'sonuc' => $ozet, 'cikti' => ob_get_clean()];
}
