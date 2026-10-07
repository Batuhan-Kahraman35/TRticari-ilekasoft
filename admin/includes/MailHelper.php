<?php
/**
 * MailHelper - Merkezi e-posta gonderimi
 *
 * Gmail SMTP kanali dbo.EntegrasyonKanallari uzerinden okunur; sifre kodda tutulmaz.
 * Cok aliciyi tek oturumda gonderir, HTML govde destekler, sonucu EntegrasyonLoglari'na yazar.
 *
 * Kullanim:
 *   require_once __DIR__ . '/MailHelper.php';
 *   $sonuc = MailHelper::gonder($db, ['a@x.com', 'b@x.com'], 'Konu', '<p>Govde</p>', true);
 *
 * @version 1.0
 */

class MailHelper
{
    /**
     * Aktif Gmail SMTP kanalini doner. Yoksa null.
     */
    public static function kanalGetir($db): ?array
    {
        return $db->fetchOne("
            SELECT TOP 1 k.kanal_id, k.kanal_ad, k.kanal_ayarlar, e.entegrasyon_url
            FROM dbo.EntegrasyonKanallari k
            INNER JOIN dbo.Entegrasyonlar e ON k.kanal_entegrasyon_id = e.entegrasyon_id
            WHERE e.entegrasyon_kod = 'gmail'
              AND e.entegrasyon_durum = 1
              AND k.kanal_durum = 1
            ORDER BY k.kanal_id
        ") ?: null;
    }

    /**
     * E-posta gonderir.
     *
     * @param array<string> $alicilar
     * @return array ['success' => bool, 'message' => string, 'gonderilen' => array, 'reddedilen' => array]
     */
    public static function gonder($db, array $alicilar, string $konu, string $govde, bool $html = true, string $gondericiAd = ''): array
    {
        $alicilar = array_values(array_unique(array_filter(
            array_map('trim', $alicilar),
            fn($e) => $e !== '' && filter_var($e, FILTER_VALIDATE_EMAIL)
        )));

        if (!$alicilar) {
            return ['success' => false, 'message' => 'Gecerli alici yok.', 'gonderilen' => [], 'reddedilen' => []];
        }

        $kanal = self::kanalGetir($db);
        if (!$kanal) {
            return ['success' => false, 'message' => 'Aktif Gmail SMTP kanali bulunamadi (Entegrasyonlar).', 'gonderilen' => [], 'reddedilen' => $alicilar];
        }

        $ayarlar  = json_decode($kanal['kanal_ayarlar'] ?? '{}', true) ?: [];
        $smtpUser = (string)$kanal['kanal_ad'];
        $smtpPass = (string)($ayarlar['sifre'] ?? '');

        if ($smtpUser === '' || $smtpPass === '') {
            return ['success' => false, 'message' => 'SMTP kullanici adi veya sifresi tanimli degil.', 'gonderilen' => [], 'reddedilen' => $alicilar];
        }

        // entegrasyon_url host olarak verilebilir (ornek: smtp.gmail.com). Sema/port yazilmissa da temizlenir.
        $host = trim((string)($kanal['entegrasyon_url'] ?? ''));
        $host = preg_replace('#^[a-z]+://#i', '', $host);
        $host = explode('/', $host)[0];
        $host = explode(':', $host)[0];
        if ($host === '' || !preg_match('/^[a-z0-9.\-]+$/i', $host)) $host = 'smtp.gmail.com';

        $sonuc = self::smtpGonder($host, $smtpUser, $smtpPass, $alicilar, $konu, $govde, $html, $gondericiAd ?: $smtpUser);

        self::logYaz(
            $db,
            (int)$kanal['kanal_id'],
            implode(', ', $alicilar),
            $konu,
            $govde,
            $sonuc['success'],
            $sonuc['success'] ? '' : $sonuc['message']
        );

        return $sonuc;
    }

    /**
     * Tek SMTP oturumunda coklu RCPT TO ile gonderim.
     */
    private static function smtpGonder(string $host, string $user, string $pass, array $alicilar, string $konu, string $govde, bool $html, string $gondericiAd): array
    {
        $baglanti = @stream_socket_client('ssl://' . $host . ':465', $errno, $errstr, 20);
        if (!$baglanti) {
            return ['success' => false, 'message' => "SMTP baglanti hatasi: {$errstr} ({$errno})", 'gonderilen' => [], 'reddedilen' => $alicilar];
        }
        stream_set_timeout($baglanti, 30);

        $oku = function () use ($baglanti) {
            $cikti = '';
            while (!feof($baglanti)) {
                $satir = fgets($baglanti, 515);
                if ($satir === false) break;
                $cikti .= $satir;
                if (strlen($satir) > 3 && substr($satir, 3, 1) === ' ') break;
            }
            return $cikti;
        };
        $yaz = fn(string $d) => fputs($baglanti, $d . "\r\n");

        $oku();                                                  // 220 greeting
        $yaz('EHLO ' . (gethostname() ?: 'localhost')); $oku();
        $yaz('AUTH LOGIN'); $oku();
        $yaz(base64_encode($user)); $oku();
        $yaz(base64_encode($pass));

        if (strpos($oku(), '235') === false) {
            fclose($baglanti);
            return ['success' => false, 'message' => 'SMTP kimlik dogrulama basarisiz (uygulama sifresini kontrol edin).', 'gonderilen' => [], 'reddedilen' => $alicilar];
        }

        $yaz("MAIL FROM:<{$user}>");
        if (strpos($oku(), '250') === false) {
            $yaz('QUIT'); fclose($baglanti);
            return ['success' => false, 'message' => 'MAIL FROM reddedildi.', 'gonderilen' => [], 'reddedilen' => $alicilar];
        }

        // Bir alici reddedilirse digerleri iptal olmasin; kabul edilenlerle devam edilir.
        $kabul = [];
        $red   = [];
        foreach ($alicilar as $alici) {
            $yaz("RCPT TO:<{$alici}>");
            if (strpos($oku(), '250') !== false) $kabul[] = $alici; else $red[] = $alici;
        }

        if (!$kabul) {
            $yaz('QUIT'); fclose($baglanti);
            return ['success' => false, 'message' => 'Tum alicilar sunucu tarafindan reddedildi.', 'gonderilen' => [], 'reddedilen' => $red];
        }

        $yaz('DATA');
        if (strpos($oku(), '354') === false) {
            $yaz('QUIT'); fclose($baglanti);
            return ['success' => false, 'message' => 'DATA komutu reddedildi.', 'gonderilen' => [], 'reddedilen' => $alicilar];
        }

        $baslik = [
            'From: =?UTF-8?B?' . base64_encode($gondericiAd) . "?= <{$user}>",
            'To: ' . implode(', ', $kabul),
            'Subject: =?UTF-8?B?' . base64_encode($konu) . '?=',
            'Date: ' . date('r'),
            'Message-ID: <' . bin2hex(random_bytes(12)) . '@' . $host . '>',
            'MIME-Version: 1.0',
            'Content-Type: ' . ($html ? 'text/html' : 'text/plain') . '; charset=UTF-8',
            'Content-Transfer-Encoding: base64',
        ];

        $yaz(implode("\r\n", $baslik));
        $yaz('');
        $yaz(rtrim(chunk_split(base64_encode($govde), 76, "\r\n")));
        $yaz('.');
        $cevap = $oku();

        $yaz('QUIT');
        fclose($baglanti);

        $basarili = strpos($cevap, '250') !== false;

        return [
            'success'    => $basarili,
            'message'    => $basarili
                ? count($kabul) . ' aliciya gonderildi' . ($red ? ', ' . count($red) . ' alici reddedildi' : '') . '.'
                : 'Gonderim basarisiz: ' . trim($cevap),
            'gonderilen' => $basarili ? $kabul : [],
            'reddedilen' => $basarili ? $red : $alicilar,
        ];
    }

    private static function logYaz($db, int $kanalId, string $alici, string $konu, string $mesaj, bool $basarili, string $hata = ''): void
    {
        try {
            $db->insert('dbo.EntegrasyonLoglari', [
                'log_kanal_id'    => $kanalId,
                'log_tip'         => 'email',
                'log_alici'       => mb_substr($alici, 0, 500),
                'log_konu'        => mb_substr($konu, 0, 250),
                'log_mesaj'       => mb_substr($mesaj, 0, 4000),
                'log_sonuc'       => $basarili ? 'başarılı' : 'basarisiz',
                'log_hata'        => $basarili ? null : mb_substr($hata, 0, 500),
                'OlusturmaTarihi' => date('Y-m-d H:i:s'),
                'Durum'           => 1,
            ]);
        } catch (Throwable $e) {
            // Log yazilamamasi gonderimi basarisiz saydirmasin
            error_log('MailHelper log hatasi: ' . $e->getMessage());
        }
    }
}
