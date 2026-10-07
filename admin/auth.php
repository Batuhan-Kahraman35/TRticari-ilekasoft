<?php
/**
 * Kimlik Doğrulama Fonksiyonları
 * Portal Örnek Yazılım
 */

// Türkiye timezone'u
date_default_timezone_set('Europe/Istanbul');

// session_start() öncesi DB erişimi gerekir: oturum ömrü tanim_site_ayarlari'ndan okunur
require_once __DIR__ . '/db.php';

/**
 * Oturum timeout süresi (saniye)
 * Kaynak: dbo.tanim_site_ayarlari.site_ayarlari_session_timeout_min
 * Ayar kaydı yoksa veya okunamazsa varsayılan 120 dk kullanılır.
 */
function oturumTimeoutSaniye(): int {
    static $cache = null;
    if ($cache !== null) {
        return $cache;
    }

    $dakika = 120; // varsayılan

    try {
        $row = Database::getInstance()->fetchOne(
            "SELECT TOP 1 site_ayarlari_session_timeout_min
             FROM dbo.tanim_site_ayarlari
             ORDER BY site_ayarlari_id DESC"
        );
        if ($row && (int)$row['site_ayarlari_session_timeout_min'] > 0) {
            $dakika = (int)$row['site_ayarlari_session_timeout_min'];
        }
    } catch (Exception $e) {
        // DB erişilemezse varsayılan ile devam et
    }

    $cache = $dakika * 60;
    return $cache;
}

// PHP'nin kendi oturum ömrü de aynı değere bağlanır.
// Aksi halde gc_maxlifetime (varsayılan 1440 sn = 24 dk) uygulama timeout'undan
// önce devreye girip oturumu erkenden düşürür.
ini_set('session.gc_maxlifetime', (string)oturumTimeoutSaniye());
session_set_cookie_params(['lifetime' => 0, 'path' => '/']);

session_start();

class Auth {
    private $db;
    
    public function __construct() {
        $this->db = Database::getInstance();
    }
    
    /**
     * Kullanıcı girişi
     */
    public function login($email, $password) {
        $sql = "SELECT k.* FROM kullanicilar k WHERE k.kullanici_email = ? AND k.kullanici_durum = 1";
        $user = $this->db->fetchOne($sql, [$email]);
        
        if (!$user) {
            return [
                'success' => false,
                'message' => 'E-posta veya şifre hatalı!'
            ];
        }
        
        if (!password_verify($password, $user['kullanici_sifre_hash'])) {
            return [
                'success' => false,
                'message' => 'E-posta veya şifre hatalı!'
            ];
        }
        
        // Son giriş tarihini güncelle
        $updateSql = "UPDATE kullanicilar SET kullanici_son_giris_tarihi = GETDATE() WHERE kullanici_id = ?";
        $this->db->execute($updateSql, [$user['kullanici_id']]);
        
        // Session bilgilerini kaydet
        $_SESSION['user_id'] = $user['kullanici_id'];
        $_SESSION['user_email'] = $user['kullanici_email'];
        $_SESSION['user_name'] = $user['kullanici_ad'] . ' ' . $user['kullanici_soyad'];
        $_SESSION['user_departman_id'] = $user['kullanici_departman_id'];
        $_SESSION['logged_in'] = true;
        $_SESSION['login_time'] = time();
        $_SESSION['sifre_degistirmeli'] = $user['kullanici_sifre_degistirmeli'] ?? 0;
        
        // Log kaydı
        $this->logActivity($user['kullanici_id'], 'Başarılı giriş');
        
        return [
            'success' => true,
            'message' => 'Giriş başarılı!',
            'user' => [
                'id' => $user['kullanici_id'],
                'email' => $user['kullanici_email'],
                'name' => $user['kullanici_ad'] . ' ' . $user['kullanici_soyad']
            ]
        ];
    }
    
    /**
     * Oturum kontrolü
     */
    public static function check() {
        if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true) {
            return false;
        }
        
        // Timeout kontrolü (süre: tanim_site_ayarlari.site_ayarlari_session_timeout_min)
        if (isset($_SESSION['login_time']) && (time() - $_SESSION['login_time'] > oturumTimeoutSaniye())) {
            self::logout();
            return false;
        }
        
        return true;
    }
    
    /**
     * Çıkış işlemi
     */
    public static function logout() {
        session_unset();
        session_destroy();
        return true;
    }
    
    /**
     * Kullanıcı bilgisi
     */
    public static function user() {
        if (!self::check()) {
            return null;
        }
        
        return [
            'kullanici_id' => $_SESSION['user_id'] ?? null,
            'kullanici_email' => $_SESSION['user_email'] ?? null,
            'departman_id' => $_SESSION['user_departman_id'] ?? null,
            // Geriye dönük uyumluluk
            'id' => $_SESSION['user_id'] ?? null,
            'email' => $_SESSION['user_email'] ?? null,
            'name' => $_SESSION['user_name'] ?? null
        ];
    }
    
    /**
     * Başka bir kullanıcı olarak giriş yap (sadece admin)
     */
    public static function impersonate(int $hedefId): bool
    {
        if (!self::check()) return false;
        if (($_SESSION['user_departman_id'] ?? null) != 1) return false;
        if (isset($_SESSION['_impersonator'])) return false;
        if ($hedefId == ($_SESSION['user_id'] ?? null)) return false;

        $db = Database::getInstance();
        $hedef = $db->fetchOne(
            "SELECT kullanici_id, kullanici_email, kullanici_ad, kullanici_soyad, kullanici_departman_id
             FROM kullanicilar
             WHERE kullanici_id = ? AND kullanici_durum = 1",
            [$hedefId]
        );
        if (!$hedef) return false;

        $_SESSION['_impersonator'] = [
            'user_id'           => $_SESSION['user_id'],
            'user_email'        => $_SESSION['user_email'],
            'user_name'         => $_SESSION['user_name'],
            'user_departman_id' => $_SESSION['user_departman_id'],
            'login_time'        => $_SESSION['login_time'],
        ];

        $_SESSION['user_id']            = $hedef['kullanici_id'];
        $_SESSION['user_email']         = $hedef['kullanici_email'];
        $_SESSION['user_name']          = trim($hedef['kullanici_ad'] . ' ' . $hedef['kullanici_soyad']);
        $_SESSION['user_departman_id']  = $hedef['kullanici_departman_id'];
        $_SESSION['login_time']         = time();
        $_SESSION['sifre_degistirmeli'] = 0;

        return true;
    }

    /**
     * Impersonate modundan çık, orijinal admin hesabına dön
     */
    public static function stopImpersonate(): bool
    {
        if (!isset($_SESSION['_impersonator'])) return false;

        $imp = $_SESSION['_impersonator'];
        unset($_SESSION['_impersonator']);

        $_SESSION['user_id']            = $imp['user_id'];
        $_SESSION['user_email']         = $imp['user_email'];
        $_SESSION['user_name']          = $imp['user_name'];
        $_SESSION['user_departman_id']  = $imp['user_departman_id'];
        $_SESSION['login_time']         = $imp['login_time'];
        $_SESSION['sifre_degistirmeli'] = 0;

        return true;
    }

    public static function isImpersonating(): bool
    {
        return isset($_SESSION['_impersonator']);
    }

    public static function impersonator(): ?array
    {
        return $_SESSION['_impersonator'] ?? null;
    }

    /**
     * Aktivite logu
     */
    private function logActivity($userId, $action) {
        $logFile = __DIR__ . '/../logs/auth_' . date('Y-m-d') . '.log';
        $logDir = dirname($logFile);
        
        if (!is_dir($logDir)) {
            mkdir($logDir, 0755, true);
        }
        
        $logMessage = sprintf(
            "[%s] User ID: %d | Action: %s | IP: %s | User Agent: %s\n",
            date('Y-m-d H:i:s'),
            $userId,
            $action,
            $_SERVER['REMOTE_ADDR'] ?? 'unknown',
            $_SERVER['HTTP_USER_AGENT'] ?? 'unknown'
        );
        
        file_put_contents($logFile, $logMessage, FILE_APPEND);
    }
}

/**
 * Yönlendirme fonksiyonu
 */
function redirect($url) {
    header("Location: $url");
    exit;
}

/**
 * İstek AJAX / API çağrısı mı?
 *
 * Böyle bir isteğe login.php'ye 302 döndürülürse tarayıcı yönlendirmeyi izler,
 * JS'e JSON yerine login sayfasının HTML'i ulaşır ve sayfada anlamsız bir
 * "sunucu hatası" belirir. Bu istekler 302 yerine 401 JSON almalıdır.
 *
 * Normal form gönderimleri (Sec-Fetch-Dest: document) kapsam dışıdır;
 * onlarda yönlendirme doğru davranıştır.
 */
function istekAjaxMi(): bool {
    // jQuery $.ajax bu başlığı kendiliğinden ekler
    if (!empty($_SERVER['HTTP_X_REQUESTED_WITH'])
        && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest') {
        return true;
    }

    // Gercek sayfa gezintisi asla AJAX sayilmaz. PWA service worker gezintiyi kendi
    // fetch'i uzerinden gecirdiginde Sec-Fetch-Dest 'document' yerine 'empty' gelebiliyor;
    // bu kontrol olmadan sayfa istegine login yerine JSON donuyordu.
    if (($_SERVER['HTTP_SEC_FETCH_MODE'] ?? '') === 'navigate') {
        return false;
    }
    if (strpos($_SERVER['HTTP_ACCEPT'] ?? '', 'text/html') !== false) {
        return false;
    }

    // fetch() / XHR: tarayıcı bu başlığı 'empty' gönderir, sayfa gezintisinde 'document'
    if (($_SERVER['HTTP_SEC_FETCH_DEST'] ?? '') === 'empty') {
        return true;
    }

    // JSON bekleyen istemciler
    if (strpos($_SERVER['HTTP_ACCEPT'] ?? '', 'application/json') !== false) {
        return true;
    }

    // API uç noktaları
    if (strpos($_SERVER['REQUEST_URI'] ?? '', '/api/') !== false) {
        return true;
    }

    return false;
}

/**
 * Oturum kontrolü ve yönlendirme
 */
function requireAuth() {
    if (!Auth::check()) {
        // AJAX / API isteklerine yönlendirme yerine 401 JSON dönülür
        if (istekAjaxMi()) {
            http_response_code(401);
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode([
                'success'      => false,
                'oturum_bitti' => true,
                'message'      => 'Oturum süreniz doldu. Lütfen tekrar giriş yapın.'
            ], JSON_UNESCAPED_UNICODE);
            exit;
        }

        // Giriş sonrası kullanıcının gitmek istediği sayfaya dönebilmek için hedefi sakla.
        // Sadece normal sayfa görüntülemeleri saklanır.
        if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'GET') {
            // Oturum zaman aşımında Auth::check() session'ı yok eder; yeniden başlat
            if (session_status() !== PHP_SESSION_ACTIVE) {
                session_start();
            }
            $_SESSION['giris_sonrasi_url'] = $_SERVER['REQUEST_URI'] ?? '';
        }
        // Absolute URL kullan (SEO rewrite sorununu önlemek için)
        redirect('/admin/login.php');
    }
    
    // İlk girişte şifre değiştirme zorunluluğu kontrolü
    if (!empty($_SESSION['sifre_degistirmeli'])) {
        $currentPage = basename($_SERVER['SCRIPT_FILENAME'], '.php');
        // Profil sayfası ve logout hariç her sayfada yönlendir
        if ($currentPage !== 'profil' && $currentPage !== 'logout') {
            // Absolute URL kullan (SEO rewrite sorununu önlemek için)
            redirect('/admin/pages/profil.php?sifre_degistir=1');
        }
    }
}
