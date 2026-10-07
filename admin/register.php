<?php
/**
 * Admin Panel - Hesap Oluşturma Talebi
 * Kullanıcı doğrudan oluşturulmaz; Destek API'ye ticket açılır.
 */

require_once __DIR__ . '/auth.php';

if (Auth::check()) {
    redirect('index.php');
}

$db = Database::getInstance();

$siteAyarlari = $db->fetchOne("
    SELECT TOP 1 site_ayarlari_footer_yazi, site_ayarlari_site_title,
                 site_ayarlari_destek_api_key, site_ayarlari_destek_api_url
    FROM dbo.tanim_site_ayarlari
    ORDER BY site_ayarlari_id DESC
");

$footerYazi = $siteAyarlari['site_ayarlari_footer_yazi'] ?? '';
$siteTitle  = $siteAyarlari['site_ayarlari_site_title']  ?? 'Örnek Yazılım Portal';
$apiKey     = $siteAyarlari['site_ayarlari_destek_api_key'] ?? '';
$apiUrl     = $siteAyarlari['site_ayarlari_destek_api_url'] ?? '';

if (empty($footerYazi)) {
    $footerYazi = '© ' . date('Y') . ' Örnek Yazılım. Tüm hakları saklıdır.';
}

$error   = '';
$success = false;

function registerApiCall(string $url, string $apiKey, array $payload): array {
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_USERAGENT      => 'TRticari-Destek-App/1.0',
        CURLOPT_POST           => true,
        CURLOPT_HTTPHEADER     => [
            'Content-Type: application/json',
            'X-API-KEY: ' . $apiKey,
            'Accept: application/json',
        ],
        CURLOPT_POSTFIELDS     => json_encode($payload, JSON_UNESCAPED_UNICODE),
        CURLOPT_TIMEOUT        => 30,
        CURLOPT_SSL_VERIFYPEER => false,
    ]);
    $res  = curl_exec($ch);
    $err  = curl_error($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($res === false) {
        return ['success' => false, 'message' => 'API bağlantı hatası: ' . ($err ?: 'Bilinmeyen hata')];
    }
    $data = json_decode($res, true);
    if ($code < 200 || $code >= 300) {
        return ['success' => false, 'message' => 'API HTTP hatası: ' . $code];
    }
    return $data ?? ['success' => false, 'message' => 'Geçersiz API yanıtı'];
}

function findMetaId(array $liste, string $aramaKelimesi): int {
    foreach ($liste as $item) {
        $ad = strtolower($item['ad'] ?? $item['name'] ?? '');
        if (str_contains($ad, strtolower($aramaKelimesi))) {
            return (int)($item['id'] ?? 0);
        }
    }
    return (int)($liste[0]['id'] ?? 0);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $adSoyad  = trim($_POST['ad_soyad'] ?? '');
    $email    = trim($_POST['email'] ?? '');
    $telefon  = trim($_POST['telefon'] ?? '');
    $aciklama = trim($_POST['aciklama'] ?? '');

    if (empty($adSoyad) || empty($email)) {
        $error = 'Ad Soyad ve E-posta alanları zorunludur!';
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error = 'Geçersiz e-posta adresi!';
    } elseif (empty($apiKey) || empty($apiUrl)) {
        $error = 'Destek sistemi şu an kullanılamıyor. Lütfen yönetici ile iletişime geçin.';
    } else {
        // Telefonu 905XXXXXXXXX formatına çevir
        $telefonFormatli = '';
        if (!empty($telefon)) {
            $telefonTemiz = preg_replace('/\D/', '', $telefon);
            if (strlen($telefonTemiz) === 10) {
                $telefonFormatli = '90' . $telefonTemiz;
            } elseif (strlen($telefonTemiz) === 11 && str_starts_with($telefonTemiz, '0')) {
                $telefonFormatli = '9' . $telefonTemiz;
            } else {
                $telefonFormatli = $telefonTemiz;
            }
        }

        // ticket_meta'dan kategori ve öncelik ID'lerini al
        $meta        = registerApiCall($apiUrl, $apiKey, ['action' => 'ticket_meta']);
        $kategoriler = $meta['data']['kategoriler'] ?? [];
        $oncelikler  = $meta['data']['oncelikler']  ?? [];
        $kategoriId  = findMetaId($kategoriler, 'diğer');
        $oncelikId   = findMetaId($oncelikler, 'normal');

        $adminUrl = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https' : 'http')
                  . '://' . $_SERVER['HTTP_HOST']
                  . rtrim(dirname($_SERVER['SCRIPT_NAME']), '/\\')
                  . '/pages/personel-yonetimi.php';

        $mesaj = "Ad Soyad : {$adSoyad}\n"
               . "E-posta  : {$email}\n"
               . "Telefon  : " . ($telefonFormatli ?: '-') . "\n\n"
               . "Açıklama : " . ($aciklama ?: '-') . "\n\n"
               . "---\nHesap oluşturmak için: {$adminUrl}";

        $nameParts = explode(' ', $adSoyad, 2);

        $payload = [
            'action'      => 'create_ticket',
            'konu'        => 'Hesap oluşturma talebi',
            'mesaj'       => $mesaj,
            'kategori_id' => $kategoriId,
            'oncelik_id'  => $oncelikId,
            'kullanici'   => [
                'kaynak_kullanici_id' => '0',
                'ad'                  => $nameParts[0],
                'soyad'               => $nameParts[1] ?? '',
                'eposta'              => $email,
                'telefon'             => $telefonFormatli,
            ],
        ];

        $result = registerApiCall($apiUrl, $apiKey, $payload);

        if (!empty($result['success'])) {
            $success = true;
            $_POST   = [];
        } else {
            $error = $result['message'] ?? 'Talep gönderilemedi. Lütfen tekrar deneyin.';
        }
    }
}
?>
<!DOCTYPE html>
<html lang="tr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Hesap Oluştur - <?= htmlspecialchars($siteTitle) ?></title>

    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/@fontsource/source-sans-3@5.0.12/index.css" integrity="sha256-tXJfXfp6Ewt1ilPzLDtQnJV4hclT9XuaZUKyUvmyr+Q=" crossorigin="anonymous">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.13.1/font/bootstrap-icons.min.css" crossorigin="anonymous">
    <link rel="stylesheet" href="assets/css/adminlte.min.css">

    <style>
        .register-box { width: 420px; }
        @media (max-width: 576px) { .register-box { width: 90%; } }
    </style>
</head>
<body class="register-page bg-body-secondary">
    <div class="register-box">
        <div class="register-logo">
            <a href="login.php"><b><?= htmlspecialchars($siteTitle) ?></b></a>
        </div>

        <div class="card">
            <div class="card-body register-card-body">
                <p class="login-box-msg">Hesap Oluşturma Talebi</p>

                <?php if ($error): ?>
                    <div class="alert alert-danger alert-dismissible">
                        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                        <i class="bi bi-exclamation-triangle-fill"></i> <?= htmlspecialchars($error) ?>
                    </div>
                <?php endif; ?>

                <?php if ($success): ?>
                    <div class="alert alert-success">
                        <i class="bi bi-check-circle-fill"></i>
                        Talebiniz alındı! Yönetici hesabınızı oluşturduktan sonra giriş yapabileceksiniz.
                    </div>
                    <div class="text-center mt-3">
                        <a href="login.php" class="btn btn-primary">
                            <i class="bi bi-box-arrow-in-right"></i> Giriş Sayfasına Dön
                        </a>
                    </div>
                <?php else: ?>

                <form method="POST" action="">
                    <div class="input-group mb-3">
                        <input
                            type="text"
                            class="form-control"
                            name="ad_soyad"
                            placeholder="Ad Soyad *"
                            required
                            value="<?= htmlspecialchars($_POST['ad_soyad'] ?? '') ?>"
                        >
                        <div class="input-group-text">
                            <span class="bi bi-person"></span>
                        </div>
                    </div>

                    <div class="input-group mb-3">
                        <input
                            type="email"
                            class="form-control"
                            name="email"
                            placeholder="E-posta *"
                            required
                            value="<?= htmlspecialchars($_POST['email'] ?? '') ?>"
                        >
                        <div class="input-group-text">
                            <span class="bi bi-envelope"></span>
                        </div>
                    </div>

                    <div class="input-group mb-3">
                        <input
                            type="tel"
                            class="form-control"
                            name="telefon"
                            placeholder="Telefon (5XX XXX XX XX)"
                            pattern="[0-9]{10}"
                            maxlength="10"
                            value="<?= htmlspecialchars($_POST['telefon'] ?? '') ?>"
                        >
                        <div class="input-group-text">
                            <span class="bi bi-telephone"></span>
                        </div>
                    </div>

                    <div class="input-group mb-3">
                        <textarea
                            class="form-control"
                            name="aciklama"
                            placeholder="Açıklama (opsiyonel)"
                            rows="3"
                        ><?= htmlspecialchars($_POST['aciklama'] ?? '') ?></textarea>
                        <div class="input-group-text">
                            <span class="bi bi-chat-text"></span>
                        </div>
                    </div>

                    <div class="d-grid gap-2">
                        <button type="submit" class="btn btn-primary">
                            <i class="bi bi-send"></i> Talep Gönder
                        </button>
                    </div>
                </form>

                <p class="mt-3 mb-0 text-center">
                    <a href="login.php">
                        <i class="bi bi-box-arrow-in-right"></i> Zaten hesabım var
                    </a>
                </p>

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
