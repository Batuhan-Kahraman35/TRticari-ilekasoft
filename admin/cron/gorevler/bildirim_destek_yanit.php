<?php
/**
 * Gorev: Destek Yaniti Bildirimleri
 *
 * Destek API'de bir ticket'a DESTEK tarafindan yanit geldiginde ilgili kullaniciya
 * can + push bildirimi olusturur. Mukerrer onleme icin ek tablo yoktur; dedup mevcut
 * dbo.Bildirimler kayitlari uzerinden yapilir.
 *
 * Kaynak: admin/cron/bildirim-cron.php (v1). v1'deki $GOREVLER dagitici dizisi kaldirildi -
 * dagitim gorevi artik merkezi cron sisteminin kendisinde. Yeni bildirim turu eklemek
 * icin bu klasore yeni bir gorev dosyasi yazilir, buraya fonksiyon eklenmez.
 *
 * Onerilen zamanlama: *​/5 * * * *   ·   TelafiEt = 0
 */

if (!defined('CRON_SISTEMI')) exit('Dogrudan calistirilamaz.');

require_once __DIR__ . '/../../includes/Bildirim.php';

function gorev_bildirim_destek_yanit(array $params, $db): array
{
    ob_start();

    // Ilk kez gorulen ticket icin: bu sureden eski yanitlar bildirilmez (saniye).
    // Kurulumdan sonraki ilk calismada gecmis tum yanitlarin bildirime donusmesini engeller.
    $ilkPencere = (int)($params['ilk_pencere_sn'] ?? 7200);

    $ayar   = $db->fetchOne("
        SELECT TOP 1 site_ayarlari_destek_api_key, site_ayarlari_destek_api_url
        FROM dbo.tanim_site_ayarlari
        ORDER BY site_ayarlari_id DESC
    ");
    $apiKey = $ayar['site_ayarlari_destek_api_key'] ?? '';
    $apiUrl = $ayar['site_ayarlari_destek_api_url'] ?? '';

    if (!$apiKey || !$apiUrl) {
        echo "[HATA] Destek API yapilandirmasi eksik (tanim_site_ayarlari).\n";
        return ['durum' => 2, 'sonuc' => 'Destek API yapilandirmasi eksik', 'cikti' => ob_get_clean()];
    }

    $kullanicilar = $db->fetchAll("
        SELECT kullanici_id, kullanici_email
        FROM dbo.kullanicilar
        WHERE kullanici_durum = 1
          AND kullanici_email IS NOT NULL
          AND LTRIM(RTRIM(kullanici_email)) <> ''
    ");

    echo "Taranacak kullanici: " . count($kullanicilar) . "\n";

    $olusan = 0;
    $bakilan = 0;
    $apiHatali = 0;

    foreach ($kullanicilar as $k) {
        $eposta = $k['kullanici_email'];
        $kid    = (int)$k['kullanici_id'];

        $liste = destekApiCagir($apiUrl, $apiKey, ['action' => 'list_tickets', 'eposta' => $eposta]);

        if (!$liste || !($liste['success'] ?? false)) {
            $apiHatali++;
            continue;
        }

        // Yapi dogrulamasi: "success" derken data anahtari hic yoksa API sozlesmesi degismis olabilir.
        if (!array_key_exists('data', $liste)) {
            echo "  UYARI: {$eposta} - yanitta 'data' anahtari yok. Gelen anahtarlar: "
               . implode(', ', array_keys($liste)) . "\n";
            $apiHatali++;
            continue;
        }

        foreach (($liste['data'] ?? []) as $t) {
            $ticketId = (int)($t['Tickets_id'] ?? 0);
            $sonYanit = $t['son_yanit_tarihi'] ?? null;

            if (!$ticketId || !$sonYanit) continue;
            $bakilan++;

            // Dedup: bu ticket icin daha once olusturulmus son "Destek Yaniti" bildirimi
            $onceki = $db->fetchOne("
                SELECT TOP 1 CONVERT(VARCHAR(19), OlusturmaTarihi, 120) AS son
                FROM dbo.Bildirimler
                WHERE bildirim_kullanici_id = ?
                  AND bildirim_baslik = N'Destek Yanıtı'
                  AND bildirim_url LIKE ?
                ORDER BY bildirim_id DESC
            ", [$kid, '%destek-detay.php?id=' . $ticketId]);

            if ($onceki && !empty($onceki['son'])) {
                if (strtotime($sonYanit) <= strtotime($onceki['son'])) continue;   // ilerlememis
            } else {
                if (strtotime($sonYanit) < time() - $ilkPencere) continue;         // ilk calisma flood onleme
            }

            // Son mesaj destekten mi? ('api' = kullanicinin kendi mesaji)
            $detay = destekApiCagir($apiUrl, $apiKey, [
                'action'    => 'ticket_detail',
                'ticket_id' => $ticketId,
                'eposta'    => $eposta,
            ]);

            $son = destekEnYeniMesaj($detay['data']['mesajlar'] ?? []);
            if (!$son || ($son['mesaj_kaynak'] ?? '') === 'api') continue;

            Bildirim::olustur($db, [
                'kullanici_id' => $kid,
                'baslik'       => 'Destek Yanıtı',
                'govde'        => 'Talebinize yanıt geldi: #' . ($t['Tickets_no'] ?? '') . ' ' . ($t['Tickets_konu'] ?? ''),
                'url'          => '/admin/pages/destek-detay.php?id=' . $ticketId,
                'tip'          => 'info',
                'push'         => true,
                'olusturan'    => 0,
            ]);

            echo "  Bildirim: kullanici {$kid}, ticket #" . ($t['Tickets_no'] ?? $ticketId) . "\n";
            $olusan++;
        }
    }

    $ozet = "{$olusan} bildirim olusturuldu (" . count($kullanicilar) . " kullanici, {$bakilan} ticket bakildi"
          . ($apiHatali ? ", {$apiHatali} API hatasi" : '') . ")";

    echo "\n[OK] {$ozet}\n";

    // Tum kullanicilarda API hata verdiyse bunu basari sayma - sessiz bos donus tuzagi
    $durum = ($kullanicilar && $apiHatali === count($kullanicilar)) ? 2 : 1;

    return ['durum' => $durum, 'sonuc' => $ozet, 'cikti' => ob_get_clean()];
}

/* ── Yardimcilar ── */

function destekApiCagir(string $url, string $apiKey, array $payload): ?array
{
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_USERAGENT      => 'TRticari-Cron/2.0',
        CURLOPT_POST           => true,
        CURLOPT_HTTPHEADER     => ['Content-Type: application/json', 'X-API-KEY: ' . $apiKey],
        CURLOPT_POSTFIELDS     => json_encode($payload, JSON_UNESCAPED_UNICODE),
        CURLOPT_TIMEOUT        => 15,
        CURLOPT_SSL_VERIFYPEER => false,
    ]);
    $res = curl_exec($ch);
    curl_close($ch);

    return $res === false ? null : json_decode($res, true);
}

function destekEnYeniMesaj(array $mesajlar): ?array
{
    $en = null;
    $enT = -1;

    foreach ($mesajlar as $m) {
        $t = strtotime($m['tarih'] ?? '');
        if ($t !== false && $t > $enT) {
            $enT = $t;
            $en  = $m;
        }
    }

    return $en;
}
