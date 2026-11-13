<?php
/**
 * Gorev: HalkÖde Ödeme Linki Durum Sorgulama
 *
 * Gorev kodu: halkode_durum_sorgula
 * Parametreler:
 *   gun_sayisi (number) — kaç günlük linkler sorgulanacak (varsayılan: 7)
 *
 * Webhook ulaşmadığında (ağ kesintisi, hata) ödemenin sisteme düşmemesini
 * önler. Ödenmemiş link durumunu değiştirmez; başarısız denemeden sonra
 * müşteri aynı linkle tekrar ödeyebildiği için BASARISIZ ve IPTAL linkler de
 * sorgulanır. Ödeme satırı bağlama koşullu olduğundan webhook ile aynı anda
 * çalışsa da mükerrer kayıt oluşmaz.
 *
 * Önerilen zamanlama: *​/30 * * * *   ·   TelafiEt = 0   ·   MaxSureSn >= 600
 *
 * @see admin/includes/HalkodeService.php
 */

if (!defined('CRON_SISTEMI')) exit('Dogrudan calistirilamaz.');

require_once __DIR__ . '/../../includes/HalkodeService.php';

function gorev_halkode_durum_sorgula(array $params, $db): array
{
    ob_start();

    $gun = (int)($params['gun_sayisi'] ?? 0) > 0 ? (int)$params['gun_sayisi'] : 7;

    $linkler = $db->fetchAll("
        SELECT Halkode_Odeme_Linkleri_id       AS id,
               Halkode_Odeme_Linkleri_Kanal_id AS kanal_id,
               Halkode_Odeme_Linkleri_Tutar    AS tutar
        FROM dbo.Halkode_Odeme_Linkleri
        WHERE Durum = 1
          AND Halkode_Odeme_Linkleri_Odeme_id IS NULL
          AND Halkode_Odeme_Linkleri_DurumKod <> 'ODENDI'
          AND Halkode_Odeme_Linkleri_Link IS NOT NULL
          AND OlusturmaTarihi >= DATEADD(DAY, ?, GETDATE())
        ORDER BY Halkode_Odeme_Linkleri_id
    ", [-$gun]);

    echo "[BASLA] {$gun} gunluk acik link sayisi: " . count($linkler) . "\n";

    $servisler = [];
    $odenen = $bekleyen = $hata = 0;

    foreach ($linkler as $link) {
        $id      = (int)$link['id'];
        $kanalId = (int)$link['kanal_id'];

        try {
            $servis = $servisler[$kanalId] ??= new HalkodeService($db, $kanalId);
            $sonuc  = $servis->durumSorgulaVeIsle($id, 0);

            if ($sonuc['durum'] === HalkodeService::DURUM_ODENDI && $sonuc['islendi']) {
                $odenen++;
                printf("  #%-5d ODENDI %12s TL -> odeme #%d\n",
                    $id, number_format((float)$link['tutar'], 2, ',', '.'), (int)$sonuc['odeme_id']);
            } elseif ($sonuc['durum'] === HalkodeService::DURUM_ODENDI) {
                printf("  #%-5d ZATEN ISLENMIS\n", $id);
            } else {
                $bekleyen++;
            }
        } catch (Throwable $e) {
            $hata++;
            echo "  #{$id} HATA: " . $e->getMessage() . "\n";
        }
    }

    echo "[BITTI] {$odenen} yeni tahsilat, {$bekleyen} bekliyor, {$hata} hata\n";

    $ozet = "{$odenen} yeni tahsilat, {$bekleyen} bekleyen link" . ($hata > 0 ? ", {$hata} hata" : '');

    return [
        'durum' => $hata > 0 && $odenen === 0 ? 2 : 1,   // 1 = başarılı, 2 = hata
        'sonuc' => $ozet,
        'cikti' => ob_get_clean(),
    ];
}
