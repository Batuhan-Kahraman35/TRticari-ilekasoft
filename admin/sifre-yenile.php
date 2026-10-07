<?php
/**
 * Admin Panel - Yeni Şifre Belirleme
 * Token doğrular, tek kullanımlık şifre sıfırlama.
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

$token     = trim($_GET['token'] ?? '');
$error     = '';
$success   = false;
$kullanici = null;
$tokenGecersiz = false;

if (empty($token)) {
    $tokenGecersiz = true;
} else {
    $kullanici = $db->fetchOne("
        SELECT kullanici_id, kullanici_ad, kullanici_soyad, kullanici_email
        FROM kullanicilar
        WHERE kullanici_sifre_sifirlama_token  = ?
          AND kullanici_sifre_sifirlama_expire > GETDATE()
          AND kullanici_durum = 1
    ", [$token]);

    if (!$kullanici) {
        $tokenGecersiz = true;
    }
}

if (!$tokenGecersiz && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $yeniSifre      = $_POST['yeni_sifre']      ?? '';
    $yeniSifreTekrar = $_POST['yeni_sifre_tekrar'] ?? '';

    if (empty($yeniSifre) || empty($yeniSifreTekrar)) {
        $error = 'Lütfen tüm alanları doldurun!';
    } elseif (strlen($yeniSifre) < 6) {
        $error = 'Şifre en az 6 karakter olmalıdır!';
    } elseif ($yeniSifre !== $yeniSifreTekrar) {
        $error = 'Şifreler eşleşmiyor!';
    } else {
        $hash = password_hash($yeniSifre, PASSWORD_DEFAULT);

        $db->query("
            UPDATE kullanicilar
            SET kullanici_sifre_hash             = ?,
                kullanici_sifre_sifirlama_token  = NULL,
                kullanici_sifre_sifirlama_expire = NULL,
                kullanici_sifre_degistirmeli     = 0
            WHERE kullanici_id = ?
        ", [$hash, $kullanici['kullanici_id']]);

        $success = true;
    }
}
?>
<!DOCTYPE html>
<html lang="tr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Yeni Şifre Belirle - <?= htmlspecialchars($siteTitle) ?></title>

    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/@fontsource/source-sans-3@5.0.12/index.css" integrity="sha256-tXJfXfp6Ewt1ilPzLDtQnJV4hclT9XuaZUKyUvmyr+Q=" crossorigin="anonymous">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.13.1/font/bootstrap-icons.min.css" crossorigin="anonymous">
    <link rel="stylesheet" href="assets/css/adminlte.min.css">

    <style>
        .strength-bar-wrap {
            height: 6px;
            background: #e9ecef;
            border-radius: 3px;
            margin-bottom: 4px;
            overflow: hidden;
        }
        .strength-bar {
            height: 100%;
            width: 0;
            border-radius: 3px;
            transition: width .3s ease, background-color .3s ease;
        }
        .strength-label { font-size: .78rem; }
    </style>
</head>
<body class="login-page bg-body-secondary">
    <div class="login-box">
        <div class="login-logo">
            <a href="login.php"><b><?= htmlspecialchars($siteTitle) ?></b></a>
        </div>

        <div class="card">
            <div class="card-body login-card-body">
                <p class="login-box-msg">Yeni Şifre Belirle</p>

                <?php if ($tokenGecersiz): ?>

                    <div class="alert alert-danger text-center">
                        <i class="bi bi-x-circle-fill"></i><br>
                        Bu bağlantı geçersiz veya süresi dolmuş.<br>
                        <small>Şifre sıfırlama bağlantıları 1 saat geçerlidir.</small>
                    </div>
                    <div class="text-center mt-2">
                        <a href="sifre-sifirla.php" class="btn btn-sm btn-primary">
                            <i class="bi bi-arrow-repeat"></i> Yeni bağlantı talep et
                        </a>
                    </div>

                <?php elseif ($success): ?>

                    <div class="alert alert-success text-center">
                        <i class="bi bi-check-circle-fill"></i><br>
                        Şifreniz başarıyla güncellendi!
                    </div>
                    <div class="text-center mt-2">
                        <a href="login.php" class="btn btn-primary">
                            <i class="bi bi-box-arrow-in-right"></i> Giriş Yap
                        </a>
                    </div>

                <?php else: ?>

                    <?php if ($error): ?>
                        <div class="alert alert-danger alert-dismissible">
                            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                            <i class="bi bi-exclamation-triangle-fill"></i> <?= htmlspecialchars($error) ?>
                        </div>
                    <?php endif; ?>

                    <p class="text-muted text-center" style="font-size:.9rem;">
                        Merhaba <strong><?= htmlspecialchars(($kullanici['kullanici_ad'] ?? '') . ' ' . ($kullanici['kullanici_soyad'] ?? '')) ?></strong>,
                        yeni şifrenizi belirleyin.
                    </p>

                    <form method="POST" action="" id="sifreForm">
                        <div class="input-group mb-1">
                            <input
                                type="password"
                                class="form-control"
                                name="yeni_sifre"
                                id="yeniSifre"
                                placeholder="Yeni Şifre (en az 6 karakter)"
                                required
                                minlength="6"
                                autocomplete="new-password"
                            >
                            <div class="input-group-text">
                                <span class="bi bi-lock-fill"></span>
                            </div>
                        </div>

                        <!-- Şifre güç göstergesi -->
                        <div class="mb-3 px-1">
                            <div class="strength-bar-wrap">
                                <div class="strength-bar" id="strengthBar"></div>
                            </div>
                            <span class="strength-label text-muted" id="strengthLabel"></span>
                        </div>

                        <div class="input-group mb-3">
                            <input
                                type="password"
                                class="form-control"
                                name="yeni_sifre_tekrar"
                                id="yeniSifreTekrar"
                                placeholder="Yeni Şifre Tekrar"
                                required
                                minlength="6"
                                autocomplete="new-password"
                            >
                            <div class="input-group-text">
                                <span class="bi bi-lock"></span>
                            </div>
                        </div>

                        <div class="d-grid gap-2">
                            <button type="submit" class="btn btn-primary">
                                <i class="bi bi-check-lg"></i> Şifreyi Kaydet
                            </button>
                        </div>
                    </form>

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

    <script>
    (function () {
        const input    = document.getElementById('yeniSifre');
        const bar      = document.getElementById('strengthBar');
        const label    = document.getElementById('strengthLabel');
        const tekrar   = document.getElementById('yeniSifreTekrar');
        const form     = document.getElementById('sifreForm');

        if (!input) return;

        const levels = [
            { label: '',           color: '',          pct: 0   },
            { label: 'Çok Zayıf', color: '#dc3545',   pct: 20  },
            { label: 'Zayıf',     color: '#fd7e14',   pct: 40  },
            { label: 'Orta',      color: '#ffc107',   pct: 60  },
            { label: 'Güçlü',     color: '#198754',   pct: 80  },
            { label: 'Çok Güçlü', color: '#0d6efd',   pct: 100 },
        ];

        input.addEventListener('input', function () {
            const v     = this.value;
            let score   = 0;
            if (v.length >= 6)                    score++;
            if (v.length >= 10)                   score++;
            if (/[A-Z]/.test(v))                  score++;
            if (/[0-9]/.test(v))                  score++;
            if (/[^A-Za-z0-9]/.test(v))           score++;

            const lvl      = v.length === 0 ? levels[0] : levels[score];
            bar.style.width           = lvl.pct + '%';
            bar.style.backgroundColor = lvl.color;
            label.textContent         = lvl.label;
            label.style.color         = lvl.color;
        });

        form.addEventListener('submit', function (e) {
            if (input.value !== tekrar.value) {
                e.preventDefault();
                tekrar.setCustomValidity('Şifreler eşleşmiyor!');
                tekrar.reportValidity();
            } else {
                tekrar.setCustomValidity('');
            }
        });

        tekrar.addEventListener('input', function () {
            this.setCustomValidity('');
        });
    })();
    </script>
</body>
</html>
