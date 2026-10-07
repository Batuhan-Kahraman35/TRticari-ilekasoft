<?php
/**
 * Gorev: Hukuk Takip Gunluk Faiz Hesaplama
 *
 * takip_dosya_acilis_tarihi'nden bugune gecen gun sayisina gore yillik faiz oranini
 * gunluge cevirip takip_Isleyen_Faiz'i gunceller.
 *
 * Formul: isleyen_faiz = ana_tutar x (yillik_oran / 100 / 365) x gun_sayisi
 *
 * Kaynak: admin/cron/hukuk-faiz-hesapla.php (v1). Degisenler:
 *   - Kodda gomulu CRON_SECRET_KEY kaldirildi; key artik CronAyarlar tablosunda.
 *   - Ayri log dosyasi (logs/hukuk-faiz-YYYY-MM.log) kaldirildi; cikti CronCalismaLog'a yazilir.
 *   - "Bugun zaten calisti" kontrolu kayit bazina indirildi; bugun hesaplanmamis kayitlar islenir.
 *   - Guncelleme tek tek UPDATE yerine ayni sekilde satir bazinda; toplu UPDATE yapilmadi
 *     cunku her satirin faizi kendi oranindan turuyor ve hata izolasyonu isteniyor.
 *
 * Onerilen zamanlama: 0 6 * * *   ·   TelafiEt = 1
 */

if (!defined('CRON_SISTEMI')) exit('Dogrudan calistirilamaz.');

function gorev_hukuk_faiz_hesapla(array $params, $db): array
{
    ob_start();

    $dryRun = !empty($params['dry_run']);
    $force  = !empty($params['force']);
    $bugun  = date('Y-m-d');

    echo "Faiz hesaplama - {$bugun}"
       . ($dryRun ? ' [DRY-RUN]' : '')
       . ($force  ? ' [FORCE]'   : '') . "\n";

    // Mukerrer kontrol KAYIT bazindadir: bugun zaten hesaplanmis kayitlar atlanir,
    // digerleri islenir. Onceki surumde tek bir kayit bugun islenmisse tum gorev
    // atlaniyordu; gun icinde tutari girilen yeni dosyalar o gun hic hesaplanmiyordu.
    $mukerrerSarti = $force ? '' : "
          AND (takip_son_faiz_tarihi IS NULL
               OR CONVERT(DATE, takip_son_faiz_tarihi) <> CONVERT(DATE, GETDATE()))";

    $kayitlar = $db->fetchAll("
        SELECT
            takip_id,
            takip_dosya_no,
            takip_ana_tutar,
            takip_Isleyen_Faiz,
            takip_faiz_orani,
            CONVERT(VARCHAR(10), takip_dosya_acilis_tarihi, 120) AS acilis_tarihi,
            DATEDIFF(DAY, takip_dosya_acilis_tarihi, GETDATE()) AS gecen_gun
        FROM dbo.HukukTakip
        WHERE Durum = 1
          AND takip_dosya_acilis_tarihi IS NOT NULL
          AND takip_ana_tutar IS NOT NULL
          AND takip_ana_tutar > 0
          AND takip_faiz_orani IS NOT NULL
          AND takip_faiz_orani > 0
          {$mukerrerSarti}
    ");

    $toplamKayit = count($kayitlar);
    echo "{$toplamKayit} adet islenecek kayit bulundu.\n\n";

    if ($toplamKayit === 0) {
        return ['durum' => 1, 'sonuc' => 'Islenecek kayit bulunamadi (0 kayit)', 'cikti' => ob_get_clean()];
    }

    $guncellenen = 0;
    $atlanan     = 0;
    $toplamFaiz  = 0.0;
    $hatalar     = [];

    foreach ($kayitlar as $k) {
        $takipId    = (int)$k['takip_id'];
        $dosyaNo    = $k['takip_dosya_no'];
        $anaTutar   = (float)$k['takip_ana_tutar'];
        $eskiFaiz   = (float)($k['takip_Isleyen_Faiz'] ?? 0);
        $yillikOran = (float)$k['takip_faiz_orani'];
        $gecenGun   = (int)$k['gecen_gun'];

        // Acilis tarihi gelecekteyse hesap yapilmaz
        if ($gecenGun <= 0) {
            echo "  [{$dosyaNo}] Acilis tarihi gelecekte, atlandi (gun: {$gecenGun}).\n";
            $atlanan++;
            continue;
        }

        $gunlukOran = $yillikOran / 100 / 365;
        $faizTutari = $anaTutar * $gunlukOran * $gecenGun;
        $yeniFaiz   = round($faizTutari, 2);

        $toplamFaiz += $faizTutari;

        $detay = "[{$dosyaNo}] Ana: " . number_format($anaTutar, 2)
               . " | Oran: %{$yillikOran} | Gun: {$gecenGun}"
               . " | Eski: " . number_format($eskiFaiz, 2)
               . " | Yeni: " . number_format($yeniFaiz, 2);

        if ($dryRun) {
            echo "  [DRY-RUN] {$detay}\n";
            $guncellenen++;
            continue;
        }

        try {
            $db->execute("
                UPDATE dbo.HukukTakip
                SET takip_Isleyen_Faiz    = ?,
                    takip_son_faiz_tarihi = GETDATE(),
                    GuncellemeTarihi      = GETDATE()
                WHERE takip_id = ?
            ", [$yeniFaiz, $takipId]);

            echo "  OK: {$detay}\n";
            $guncellenen++;

        } catch (Throwable $e) {
            $hatalar[] = "#{$takipId} ({$dosyaNo}): " . $e->getMessage();
            echo "  HATA: [{$dosyaNo}] " . $e->getMessage() . "\n";
        }
    }

    $ozet = ($dryRun ? '[DRY-RUN] ' : '')
          . "Toplam: {$toplamKayit} | Guncellenen: {$guncellenen} | Atlanan: {$atlanan}"
          . " | Toplam Faiz: " . number_format($toplamFaiz, 2) . " TL"
          . ($hatalar ? ' | Hata: ' . count($hatalar) : '');

    echo "\n[OK] {$ozet}\n";

    if ($hatalar) {
        echo "\nHatalar:\n";
        foreach ($hatalar as $h) echo "  - {$h}\n";
    }

    return [
        'durum' => $hatalar ? 2 : 1,
        'sonuc' => $ozet,
        'cikti' => ob_get_clean(),
    ];
}
