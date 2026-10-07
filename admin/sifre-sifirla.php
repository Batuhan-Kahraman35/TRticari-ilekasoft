<?php
/**
 * Admin Panel - Şifre Sıfırlama Talebi
 * Token üretir, Gmail ve/veya WhatsApp ile link gönderir.
 */

require_once __DIR__ . '/auth.php';

if (Auth::check()) {
    redirect('index.php');
}

$db = Database::getInstance();

$siteAyarlari = $db->fetchOne("
    SELECT TOP 1 site_ayarlari_footer_yazi, site_ayarlari_site_title
    FROM dbo.tanim_site_ayarlari
    ORDER BY site_ayarlari_id DESC
");

$footerYazi = $siteAyarlari['site_ayarlari_footer_yazi'] ?? '';
$siteTitle  = $siteAyarlari['site_ayarlari_site_title']  ?? 'Örnek Yazılım Portal';

if (empty($footerYazi)) {
    $footerYazi = '© ' . date('Y') . ' Örnek Yazılım. Tüm hakları saklıdır.';
}

// ─── Gmail SMTP gönderimi ───────────────────────────────────────────────────
function gmailSmtpGonder(string $smtpUser, string $smtpPass, string $toEmail, string $subject, string $body, string $fromName): bool {
    $smtp = @stream_socket_client('ssl://smtp.gmail.com:465', $errno, $errstr, 15);
    if (!$smtp) return false;
    stream_set_timeout($smtp, 15);

    // Çok satırlı yanıtlarda ("250-..." ... "250 ...") son satıra kadar okur
    $readLine = function() use ($smtp) {
        $response = '';
        while (($line = fgets($smtp, 515)) !== false) {
            $response .= $line;
            if (substr($line, 3, 1) === ' ') break;
            if (stream_get_meta_data($smtp)['timed_out']) break;
        }
        return $response;
    };

    $sendLine = function(string $data) use ($smtp) {
        return fputs($smtp, $data . "\r\n");
    };

    $readLine(); // 220 greeting

    $host = gethostname() ?: 'localhost';
    $sendLine("EHLO {$host}"); $readLine();

    $sendLine('AUTH LOGIN'); $readLine();
    $sendLine(base64_encode($smtpUser)); $readLine();
    $sendLine(base64_encode($smtpPass)); $response = $readLine();
    if (strpos($response, '235') === false) {
        fclose($smtp);
        return false;
    }

    $sendLine("MAIL FROM:<{$smtpUser}>"); $readLine();
    $sendLine("RCPT TO:<{$toEmail}>"); $readLine();
    $sendLine('DATA'); $readLine();

    $encodedSubject = '=?UTF-8?B?' . base64_encode($subject) . '?=';
    $encodedBody    = chunk_split(base64_encode($body));

    $sendLine('From: =?UTF-8?B?' . base64_encode($fromName) . "?= <{$smtpUser}>");
    $sendLine("To: {$toEmail}");
    $sendLine("Subject: {$encodedSubject}");
    $sendLine('MIME-Version: 1.0');
    $sendLine('Content-Type: text/plain; charset=UTF-8');
    $sendLine('Content-Transfer-Encoding: base64');
    $sendLine('');
    $sendLine($encodedBody);
    $sendLine('.'); $response = $readLine();

    $sendLine('QUIT');
    fclose($smtp);

    return strpos($response, '250') !== false;
}

// ─── WhatsApp Evolution API gönderimi ──────────────────────────────────────
function whatsappGonder(string $baseUrl, string $apiKey, string $instance, string $telefon, string $mesaj): bool {
    $url = rtrim($baseUrl, '/') . '/message/sendText/' . urlencode($instance);
    $ch  = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_HTTPHEADER     => [
            'Content-Type: application/json',
            'apikey: ' . $apiKey,
        ],
        CURLOPT_POSTFIELDS     => json_encode(['number' => $telefon, 'text' => $mesaj], JSON_UNESCAPED_UNICODE),
        CURLOPT_TIMEOUT        => 15,
        CURLOPT_SSL_VERIFYPEER => false,
    ]);
    $res  = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return $res !== false && $code >= 200 && $code < 300;
}

// ─── EntegrasyonLoglari'na kayıt ───────────────────────────────────────────
function entegrasyonLogYaz($db, int $kanalId, string $tip, string $alici, string $konu, string $mesaj, bool $basarili, string $hata = ''): void {
    $db->insert('EntegrasyonLoglari', [
        'log_kanal_id'  => $kanalId,
        'log_tip'       => $tip,
        'log_alici'     => $alici,
        'log_konu'      => $konu,
        'log_mesaj'     => $mesaj,
        'log_sonuc'     => $basarili ? 'başarılı' : 'basarisiz',
        'log_hata'      => $basarili ? null : $hata,
        'OlusturmaTarihi' => date('Y-m-d H:i:s'),
        'Durum'         => 1,
    ]);
}

$success = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = trim($_POST['email'] ?? '');

    if (!empty($email) && filter_var($email, FILTER_VALIDATE_EMAIL)) {
        // Aktif kullanıcıyı bul
        $kullanici = $db->fetchOne("
            SELECT kullanici_id, kullanici_ad, kullanici_soyad, kullanici_email, kullanici_telefon
            FROM kullanicilar
            WHERE kullanici_email = ? AND kullanici_durum = 1
        ", [$email]);

        if ($kullanici) {
            $token  = bin2hex(random_bytes(32));
            $expire = date('Y-m-d H:i:s', strtotime('+1 hour'));

            $db->query("
                UPDATE kullanicilar
                SET kullanici_sifre_sifirlama_token  = ?,
                    kullanici_sifre_sifirlama_expire = ?
                WHERE kullanici_id = ?
            ", [$token, $expire, $kullanici['kullanici_id']]);

            $protokol   = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on') ? 'https' : 'http';
            $sifirlaUrl = $protokol . '://' . $_SERVER['HTTP_HOST']
                        . rtrim(dirname($_SERVER['SCRIPT_NAME']), '/\\')
                        . '/sifre-yenile.php?token=' . $token;

            $adSoyad = trim(($kullanici['kullanici_ad'] ?? '') . ' ' . ($kullanici['kullanici_soyad'] ?? ''));

            // ─── Gmail gönderimi ────────────────────────────────────────────
            $gmailKanal = $db->fetchOne("
                SELECT k.kanal_id, k.kanal_ad, k.kanal_ayarlar
                FROM EntegrasyonKanallari k
                INNER JOIN Entegrasyonlar e ON k.kanal_entegrasyon_id = e.entegrasyon_id
                WHERE e.entegrasyon_kod = 'gmail' AND e.entegrasyon_durum = 1 AND k.kanal_durum = 1
            ");

            if ($gmailKanal) {
                $gmailAyarlar = json_decode($gmailKanal['kanal_ayarlar'] ?? '{}', true);
                $smtpUser     = $gmailKanal['kanal_ad'];
                $smtpPass     = $gmailAyarlar['sifre'] ?? '';

                $mailKonu = $siteTitle . ' - Şifre Sıfırlama';
                $mailBody = "Merhaba {$adSoyad},\n\n"
                          . "Şifre sıfırlama talebiniz alındı.\n\n"
                          . "Aşağıdaki bağlantıya tıklayarak yeni şifrenizi belirleyebilirsiniz:\n"
                          . $sifirlaUrl . "\n\n"
                          . "Bu bağlantı 1 saat geçerlidir.\n\n"
                          . "Eğer bu talebi siz yapmadıysanız bu e-postayı dikkate almayınız.\n\n"
                          . "— " . $siteTitle;

                $basarili = !empty($smtpPass) && gmailSmtpGonder($smtpUser, $smtpPass, $kullanici['kullanici_email'], $mailKonu, $mailBody, $siteTitle);
                entegrasyonLogYaz($db, (int)$gmailKanal['kanal_id'], 'email', $kullanici['kullanici_email'], $mailKonu, $mailBody, $basarili, $basarili ? '' : 'SMTP gönderimi başarısız');
            }

            // ─── WhatsApp gönderimi ─────────────────────────────────────────
            $wpKanal = $db->fetchOne("
                SELECT k.kanal_id, k.kanal_kod,
                       e.entegrasyon_url, e.entegrasyon_api_key
                FROM EntegrasyonKanallari k
                INNER JOIN Entegrasyonlar e ON k.kanal_entegrasyon_id = e.entegrasyon_id
                WHERE e.entegrasyon_kod = 'whatsapp_evolution' AND e.entegrasyon_durum = 1 AND k.kanal_durum = 1
            ");

            if ($wpKanal && !empty($kullanici['kullanici_telefon'])) {
                $telefonTemiz = preg_replace('/\D/', '', $kullanici['kullanici_telefon']);
                if (strlen($telefonTemiz) === 10) {
                    $telefonWp = '90' . $telefonTemiz;
                } elseif (strlen($telefonTemiz) === 11 && str_starts_with($telefonTemiz, '0')) {
                    $telefonWp = '9' . $telefonTemiz;
                } else {
                    $telefonWp = $telefonTemiz;
                }

                $wpMesaj = "Merhaba {$adSoyad},\n\n"
                         . "{$siteTitle} şifre sıfırlama bağlantınız:\n"
                         . $sifirlaUrl . "\n\n"
                         . "Bu bağlantı 1 saat geçerlidir.";

                $basarili = whatsappGonder(
                    $wpKanal['entegrasyon_url'],
                    $wpKanal['entegrasyon_api_key'],
                    $wpKanal['kanal_kod'],
                    $telefonWp,
                    $wpMesaj
                );
                entegrasyonLogYaz($db, (int)$wpKanal['kanal_id'], 'whatsapp', $telefonWp, 'Şifre Sıfırlama', $wpMesaj, $basarili, $basarili ? '' : 'WhatsApp gönderimi başarısız');
            }
        }
        // Güvenlik: kullanıcı bulunsa da bulunmasa da aynı mesaj
        $success = true;
    }
}
?>
<!DOCTYPE html>
<html lang="tr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Şifremi Unuttum - <?= htmlspecialchars($siteTitle) ?></title>

    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/@fontsource/source-sans-3@5.0.12/index.css" integrity="sha256-tXJfXfp6Ewt1ilPzLDtQnJV4hclT9XuaZUKyUvmyr+Q=" crossorigin="anonymous">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.13.1/font/bootstrap-icons.min.css" crossorigin="anonymous">
    <link rel="stylesheet" href="assets/css/adminlte.min.css">
</head>
<body class="login-page bg-body-secondary">
    <div class="login-box">
        <div class="login-logo">
            <a href="login.php"><b><?= htmlspecialchars($siteTitle) ?></b></a>
        </div>

        <div class="card">
            <div class="card-body login-card-body">
                <p class="login-box-msg">Şifremi Unuttum</p>

                <?php if ($success): ?>
                    <div class="alert alert-success">
                        <i class="bi bi-check-circle-fill"></i>
                        E-posta adresiniz sistemde kayıtlıysa sıfırlama bağlantısı gönderildi.
                        Lütfen e-postanızı veya telefonunuzu kontrol edin.
                    </div>
                    <div class="text-center mt-2">
                        <a href="login.php" class="btn btn-sm btn-outline-secondary">
                            <i class="bi bi-arrow-left"></i> Giriş sayfasına dön
                        </a>
                    </div>
                <?php else: ?>

                <p class="text-muted text-center" style="font-size:0.9rem;">
                    E-posta adresinizi girin, şifre sıfırlama bağlantısı gönderelim.
                </p>

                <form method="POST" action="">
                    <div class="input-group mb-3">
                        <input
                            type="email"
                            class="form-control"
                            name="email"
                            placeholder="E-posta adresiniz"
                            required
                            autofocus
                            value="<?= htmlspecialchars($_POST['email'] ?? '') ?>"
                        >
                        <div class="input-group-text">
                            <span class="bi bi-envelope"></span>
                        </div>
                    </div>

                    <div class="d-grid gap-2">
                        <button type="submit" class="btn btn-primary">
                            <i class="bi bi-send"></i> Sıfırlama Bağlantısı Gönder
                        </button>
                    </div>
                </form>

                <div class="d-flex justify-content-between mt-3 mb-0">
                    <a href="login.php"><i class="bi bi-arrow-left"></i> Giriş yap</a>
                    <a href="register.php"><i class="bi bi-person-plus"></i> Hesap oluştur</a>
                </div>

                <?php endif; ?>

                <p class="mt-3 mb-1 text-center">
                    <small class="text-muted"><?= htmlspecialchars($footerYazi) ?></small>
                </p>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/@popperjs/core@2.11.8/dist/umd/popper.min.js" crossorigin="anonymous"></script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.7/dist/js/bootstrap.min.js" crossorigin="anonymous"></script>
    <script src="assets/js/adminlte.min.js"></script>
</body>
</html>
