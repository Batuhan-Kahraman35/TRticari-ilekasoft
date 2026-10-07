<?php
/**
 * Admin Panel - Entegrasyon Yönetimi
 * Entegrasyonlar ve kanalları CRUD + test
 */

require_once __DIR__ . '/../auth.php';
require_once __DIR__ . '/../includes/PageAuth.php';
requireAuth();

$user = Auth::user();
$db   = Database::getInstance();

$currentPageFile = basename($_SERVER['PHP_SELF']);
$pagePermissions = PageAuth::checkPagePermissions($user['kullanici_id'], $user['departman_id'], $currentPageFile);

if (!$pagePermissions['has_access']) {
    PageAuth::accessDenied($pagePermissions['error'] ?? 'Bu sayfaya erişim yetkiniz yok.');
}

$pageInfo = $db->fetchOne("
    SELECT s.sayfalar_sayfa_adi, s.sayfalar_aciklama, m.menuler_menu_adi as menu_adi
    FROM Menu_Sayfalar s
    LEFT JOIN Menuler m ON s.sayfalar_menu_id = m.menuler_id
    WHERE s.sayfalar_sayfa_url LIKE ? AND s.sayfalar_durum = 1
", ['%' . $currentPageFile]);

$pageTitle = $pageInfo['sayfalar_sayfa_adi'] ?? 'Entegrasyon Yönetimi';
$menuAdi   = $pageInfo['menu_adi'] ?? null;
$siteAyarlari = $db->fetchOne("SELECT TOP 1 site_ayarlari_site_title FROM dbo.tanim_site_ayarlari ORDER BY site_ayarlari_id DESC");
$siteTitle = $siteAyarlari['site_ayarlari_site_title'] ?? 'Örnek Yazılım Portal';

// ─── Helper fonksiyonlar ─────────────────────────────────────────────────────
function gmailSmtpGonderEnt(string $smtpUser, string $smtpPass, string $toEmail, string $subject, string $body): array {
    $smtp = @stream_socket_client('ssl://smtp.gmail.com:465', $errno, $errstr, 15);
    if (!$smtp) return ['success' => false, 'message' => "Bağlantı hatası: {$errstr} ({$errno})"];

    $read = function() use ($smtp) {
        $out = '';
        while (!feof($smtp)) { $out .= fgets($smtp, 515); if (substr($out, 3, 1) === ' ') break; }
        return $out;
    };
    $send = fn($d) => fputs($smtp, $d . "\r\n");

    $read();
    $send('EHLO ' . (gethostname() ?: 'localhost')); $read();
    $send('AUTH LOGIN'); $read();
    $send(base64_encode($smtpUser)); $read();
    $send(base64_encode($smtpPass));
    $authResp = $read();
    if (strpos($authResp, '235') === false) {
        fclose($smtp);
        return ['success' => false, 'message' => 'Gmail kimlik doğrulama başarısız. Uygulama şifresi kontrol edin.'];
    }
    $send("MAIL FROM:<{$smtpUser}>"); $read();
    $send("RCPT TO:<{$toEmail}>"); $read();
    $send('DATA'); $read();
    $encodedSubject = '=?UTF-8?B?' . base64_encode($subject) . '?=';
    $send("From: {$smtpUser}");
    $send("To: {$toEmail}");
    $send("Subject: {$encodedSubject}");
    $send('MIME-Version: 1.0');
    $send('Content-Type: text/plain; charset=UTF-8');
    $send('Content-Transfer-Encoding: base64');
    $send('');
    $send(chunk_split(base64_encode($body)));
    $send('.'); $resp = $read();
    $send('QUIT'); fclose($smtp);

    return strpos($resp, '250') !== false
        ? ['success' => true,  'message' => 'E-posta başarıyla gönderildi.']
        : ['success' => false, 'message' => 'DATA gönderimi başarısız: ' . trim($resp)];
}

function whatsappGonderEnt(string $baseUrl, string $apiKey, string $instance, string $telefon, string $mesaj): array {
    $url = rtrim($baseUrl, '/') . '/message/sendText/' . urlencode($instance);
    $ch  = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_HTTPHEADER     => ['Content-Type: application/json', 'apikey: ' . $apiKey],
        CURLOPT_POSTFIELDS     => json_encode(['number' => $telefon, 'text' => $mesaj], JSON_UNESCAPED_UNICODE),
        CURLOPT_TIMEOUT        => 15,
        CURLOPT_SSL_VERIFYPEER => false,
    ]);
    $res  = curl_exec($ch);
    $err  = curl_error($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($res === false) return ['success' => false, 'message' => 'cURL hatası: ' . $err];
    if ($code < 200 || $code >= 300) return ['success' => false, 'message' => "HTTP {$code}: " . mb_substr($res, 0, 200)];
    return ['success' => true, 'message' => 'WhatsApp mesajı gönderildi.'];
}

function smsGonderEnt(string $baseUrl, string $username, string $password, string $sender, string $telefon, string $mesaj, int $encoding = 1): array {
    $url = rtrim($baseUrl, '/') . '/sms/create';
    $payload = [
        'type'        => 1,
        'sendingType' => 0,
        'title'       => 'CRM Otomatik Mesaj',
        'content'     => $mesaj,
        'number'      => (int) $telefon,
        'encoding'    => $encoding, // 1 = Türkçe karakter (unicode)
        'sender'      => $sender,
        'validity'    => 60,
    ];
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
        CURLOPT_USERPWD        => $username . ':' . $password,
        CURLOPT_HTTPAUTH       => CURLAUTH_BASIC,
        CURLOPT_POSTFIELDS     => json_encode($payload, JSON_UNESCAPED_UNICODE),
        CURLOPT_TIMEOUT        => 15,
        CURLOPT_SSL_VERIFYPEER => false,
    ]);
    $res  = curl_exec($ch);
    $err  = curl_error($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($res === false) return ['success' => false, 'message' => 'cURL hatası: ' . $err];
    if ($code < 200 || $code >= 300) return ['success' => false, 'message' => "HTTP {$code}: " . mb_substr($res, 0, 300)];

    // EkoMesaj başarılı yanıtında paket ID döner; hata durumunda error/message alanı
    $json = json_decode($res, true);
    if (is_array($json) && (isset($json['error']) && $json['error'] || (isset($json['success']) && !$json['success']))) {
        $hata = $json['message'] ?? $json['error'] ?? 'Bilinmeyen SMS hatası';
        return ['success' => false, 'message' => is_string($hata) ? $hata : json_encode($hata, JSON_UNESCAPED_UNICODE)];
    }
    return ['success' => true, 'message' => 'SMS başarıyla gönderildi.'];
}

// ─── AJAX ────────────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json; charset=utf-8');
    $action = $_POST['action'] ?? '';

    try {
        switch ($action) {

            // ── Stats ──────────────────────────────────────────────────────
            case 'stats':
                $toplamEnt  = $db->fetchOne("SELECT COUNT(*) c FROM Entegrasyonlar WHERE entegrasyon_durum = 1")['c'] ?? 0;
                $aktifGmail = $db->fetchOne("SELECT COUNT(*) c FROM EntegrasyonKanallari k JOIN Entegrasyonlar e ON k.kanal_entegrasyon_id = e.entegrasyon_id WHERE e.entegrasyon_kod = 'gmail' AND e.entegrasyon_durum = 1 AND k.kanal_durum = 1")['c'] ?? 0;
                $aktifWA    = $db->fetchOne("SELECT COUNT(*) c FROM EntegrasyonKanallari k JOIN Entegrasyonlar e ON k.kanal_entegrasyon_id = e.entegrasyon_id WHERE e.entegrasyon_kod = 'whatsapp_evolution' AND e.entegrasyon_durum = 1 AND k.kanal_durum = 1")['c'] ?? 0;
                $aktifSMS   = $db->fetchOne("SELECT COUNT(*) c FROM EntegrasyonKanallari k JOIN Entegrasyonlar e ON k.kanal_entegrasyon_id = e.entegrasyon_id WHERE e.entegrasyon_kod = 'ekomesaj_sms' AND e.entegrasyon_durum = 1 AND k.kanal_durum = 1")['c'] ?? 0;
                $bugunLog   = $db->fetchOne("SELECT COUNT(*) c FROM EntegrasyonLoglari WHERE CAST(OlusturmaTarihi AS DATE) = CAST(GETDATE() AS DATE)")['c'] ?? 0;
                echo json_encode(['success' => true, 'data' => compact('toplamEnt', 'aktifGmail', 'aktifWA', 'aktifSMS', 'bugunLog')]);
                break;

            // ── Entegrasyon listesi ────────────────────────────────────────
            case 'list_entegrasyon':
                $search = $_POST['search'] ?? '';
                $kod    = $_POST['kod']    ?? '';
                $durum  = $_POST['durum']  ?? '';

                $sql    = "SELECT e.*, (SELECT COUNT(*) FROM EntegrasyonKanallari k WHERE k.kanal_entegrasyon_id = e.entegrasyon_id AND k.kanal_durum = 1) as kanal_sayisi FROM Entegrasyonlar e WHERE 1=1";
                $params = [];
                if ($search) { $sql .= " AND (e.entegrasyon_ad LIKE ? OR e.entegrasyon_kod LIKE ?)"; $params[] = "%$search%"; $params[] = "%$search%"; }
                if ($kod)    { $sql .= " AND e.entegrasyon_kod = ?"; $params[] = $kod; }
                if ($durum !== '') { $sql .= " AND e.entegrasyon_durum = ?"; $params[] = $durum; }
                $sql .= " ORDER BY e.entegrasyon_id";
                echo json_encode(['success' => true, 'data' => $db->fetchAll($sql, $params)]);
                break;

            // ── Kanal listesi ─────────────────────────────────────────────
            case 'list_kanal':
                $search    = $_POST['search']     ?? '';
                $entId     = $_POST['entegrasyon'] ?? '';
                $durum     = $_POST['durum']       ?? '';

                $sql    = "SELECT k.*, e.entegrasyon_ad, e.entegrasyon_kod FROM EntegrasyonKanallari k JOIN Entegrasyonlar e ON k.kanal_entegrasyon_id = e.entegrasyon_id WHERE 1=1";
                $params = [];
                if ($search) { $sql .= " AND (k.kanal_ad LIKE ? OR k.kanal_kod LIKE ?)"; $params[] = "%$search%"; $params[] = "%$search%"; }
                if ($entId)  { $sql .= " AND k.kanal_entegrasyon_id = ?"; $params[] = $entId; }
                if ($durum !== '') { $sql .= " AND k.kanal_durum = ?"; $params[] = $durum; }
                $sql .= " ORDER BY k.kanal_id";
                $rows = $db->fetchAll($sql, $params);
                // API anahtarlarını maskele
                foreach ($rows as &$row) {
                    if (!empty($row['kanal_ayarlar'])) {
                        $ayarlar = json_decode($row['kanal_ayarlar'], true);
                        if (isset($ayarlar['sifre'])) $ayarlar['sifre'] = '••••••••';
                        $row['kanal_ayarlar_masked'] = json_encode($ayarlar, JSON_UNESCAPED_UNICODE);
                    } else {
                        $row['kanal_ayarlar_masked'] = null;
                    }
                }
                echo json_encode(['success' => true, 'data' => $rows]);
                break;

            // ── Entegrasyon kaydet ─────────────────────────────────────────
            case 'save_entegrasyon':
                if (!$pagePermissions['can_add'] && !$pagePermissions['can_edit']) {
                    echo json_encode(['success' => false, 'message' => 'Yetkiniz yok!']); break;
                }
                $id  = (int)($_POST['id'] ?? 0);
                $data = [
                    'entegrasyon_ad'      => trim($_POST['entegrasyon_ad'] ?? ''),
                    'entegrasyon_kod'     => trim($_POST['entegrasyon_kod'] ?? ''),
                    'entegrasyon_url'     => trim($_POST['entegrasyon_url'] ?? '') ?: null,
                    'entegrasyon_api_key' => trim($_POST['entegrasyon_api_key'] ?? '') ?: null,
                    'entegrasyon_aciklama'=> trim($_POST['entegrasyon_aciklama'] ?? '') ?: null,
                    'entegrasyon_durum'   => (int)($_POST['entegrasyon_durum'] ?? 1),
                    'GuncelleyenKullanici'=> $user['kullanici_id'],
                    'GuncellemeTarihi'    => date('Y-m-d H:i:s'),
                ];
                if ($id > 0) {
                    $db->update('Entegrasyonlar', $data, ['entegrasyon_id' => $id]);
                    echo json_encode(['success' => true, 'message' => 'Entegrasyon güncellendi.']);
                } else {
                    $data['OlusturanKullanici'] = $user['kullanici_id'];
                    $data['OlusturmaTarihi']    = date('Y-m-d H:i:s');
                    $db->insert('Entegrasyonlar', $data);
                    echo json_encode(['success' => true, 'message' => 'Entegrasyon eklendi.']);
                }
                break;

            // ── Entegrasyon sil ────────────────────────────────────────────
            case 'delete_entegrasyon':
                if (!$pagePermissions['can_delete']) { echo json_encode(['success' => false, 'message' => 'Silme yetkiniz yok!']); break; }
                $id = (int)($_POST['id'] ?? 0);
                $kanalSayisi = $db->fetchOne("SELECT COUNT(*) c FROM EntegrasyonKanallari WHERE kanal_entegrasyon_id = ? AND kanal_durum = 1", [$id])['c'] ?? 0;
                if ($kanalSayisi > 0) { echo json_encode(['success' => false, 'message' => "Bu entegrasyona bağlı {$kanalSayisi} aktif kanal var. Önce kanalları silin."]); break; }
                $db->update('Entegrasyonlar', ['entegrasyon_durum' => 0, 'GuncelleyenKullanici' => $user['kullanici_id']], ['entegrasyon_id' => $id]);
                echo json_encode(['success' => true, 'message' => 'Entegrasyon pasife alındı.']);
                break;

            // ── Kanal kaydet ───────────────────────────────────────────────
            case 'save_kanal':
                if (!$pagePermissions['can_add'] && !$pagePermissions['can_edit']) {
                    echo json_encode(['success' => false, 'message' => 'Yetkiniz yok!']); break;
                }
                $id  = (int)($_POST['id'] ?? 0);

                // Ayarları JSON'a çevir (gmail ve ekomesaj_sms şifre saklar)
                $ayarlar = null;
                $kanalKod = $_POST['entegrasyon_kod_kanal'] ?? '';
                if ($kanalKod === 'gmail' || $kanalKod === 'ekomesaj_sms') {
                    $sifre = trim($_POST['kanal_sifre'] ?? '');
                    if (!empty($sifre)) {
                        // Mevcut kayıtta şifre boş bırakıldıysa eski şifreyi koru
                        $ayarlar = json_encode(['sifre' => $sifre], JSON_UNESCAPED_UNICODE);
                    } elseif ($id > 0) {
                        $mevcut = $db->fetchOne("SELECT kanal_ayarlar FROM EntegrasyonKanallari WHERE kanal_id = ?", [$id]);
                        $ayarlar = $mevcut['kanal_ayarlar'] ?? null;
                    }
                }

                $data = [
                    'kanal_entegrasyon_id' => (int)($_POST['kanal_entegrasyon_id'] ?? 0),
                    'kanal_ad'             => trim($_POST['kanal_ad'] ?? ''),
                    'kanal_kod'            => trim($_POST['kanal_kod'] ?? ''),
                    'kanal_endpoint'       => trim($_POST['kanal_endpoint'] ?? '') ?: null,
                    'kanal_ayarlar'        => $ayarlar,
                    'kanal_aciklama'       => trim($_POST['kanal_aciklama'] ?? '') ?: null,
                    'kanal_durum'          => (int)($_POST['kanal_durum'] ?? 1),
                    'GuncelleyenKullanici' => $user['kullanici_id'],
                    'GuncellemeTarihi'     => date('Y-m-d H:i:s'),
                ];
                if ($id > 0) {
                    $db->update('EntegrasyonKanallari', $data, ['kanal_id' => $id]);
                    echo json_encode(['success' => true, 'message' => 'Kanal güncellendi.']);
                } else {
                    $data['OlusturanKullanici'] = $user['kullanici_id'];
                    $data['OlusturmaTarihi']    = date('Y-m-d H:i:s');
                    $db->insert('EntegrasyonKanallari', $data);
                    echo json_encode(['success' => true, 'message' => 'Kanal eklendi.']);
                }
                break;

            // ── Kanal sil ─────────────────────────────────────────────────
            case 'delete_kanal':
                if (!$pagePermissions['can_delete']) { echo json_encode(['success' => false, 'message' => 'Silme yetkiniz yok!']); break; }
                $id = (int)($_POST['id'] ?? 0);
                $db->update('EntegrasyonKanallari', ['kanal_durum' => 0, 'GuncelleyenKullanici' => $user['kullanici_id']], ['kanal_id' => $id]);
                echo json_encode(['success' => true, 'message' => 'Kanal pasife alındı.']);
                break;

            // ── Entegrasyon getir (form için) ─────────────────────────────
            case 'get_entegrasyon':
                $id   = (int)($_POST['id'] ?? 0);
                $data = $db->fetchOne("SELECT * FROM Entegrasyonlar WHERE entegrasyon_id = ?", [$id]);
                if ($data) {
                    $data['entegrasyon_api_key'] = $data['entegrasyon_api_key'] ? '••••••••' : '';
                }
                echo json_encode(['success' => true, 'data' => $data]);
                break;

            // ── Kanal getir (form için) ───────────────────────────────────
            case 'get_kanal':
                $id   = (int)($_POST['id'] ?? 0);
                $data = $db->fetchOne("SELECT k.*, e.entegrasyon_kod FROM EntegrasyonKanallari k JOIN Entegrasyonlar e ON k.kanal_entegrasyon_id = e.entegrasyon_id WHERE k.kanal_id = ?", [$id]);
                if ($data && !empty($data['kanal_ayarlar'])) {
                    $ayarlar = json_decode($data['kanal_ayarlar'], true);
                    if (isset($ayarlar['sifre'])) $ayarlar['sifre'] = '';
                    $data['kanal_ayarlar'] = $ayarlar;
                }
                echo json_encode(['success' => true, 'data' => $data]);
                break;

            // ── Entegrasyon listesi (kanal formu için) ────────────────────
            case 'get_entegrasyonlar_liste':
                $liste = $db->fetchAll("SELECT entegrasyon_id, entegrasyon_ad, entegrasyon_kod FROM Entegrasyonlar WHERE entegrasyon_durum = 1 ORDER BY entegrasyon_ad");
                echo json_encode(['success' => true, 'data' => $liste]);
                break;

            // ── Test Gmail ────────────────────────────────────────────────
            case 'test_gmail':
                $kanalId = (int)($_POST['kanal_id'] ?? 0);
                $hedef   = trim($_POST['hedef'] ?? '');

                if (empty($hedef) || !filter_var($hedef, FILTER_VALIDATE_EMAIL)) {
                    echo json_encode(['success' => false, 'message' => 'Geçerli bir e-posta adresi girin.']); break;
                }

                $kanal = $db->fetchOne("SELECT k.*, e.entegrasyon_url FROM EntegrasyonKanallari k JOIN Entegrasyonlar e ON k.kanal_entegrasyon_id = e.entegrasyon_id WHERE k.kanal_id = ?", [$kanalId]);
                if (!$kanal) { echo json_encode(['success' => false, 'message' => 'Kanal bulunamadı.']); break; }

                $ayarlar  = json_decode($kanal['kanal_ayarlar'] ?? '{}', true);
                $smtpUser = $kanal['kanal_ad'];
                $smtpPass = $ayarlar['sifre'] ?? '';

                if (empty($smtpPass)) { echo json_encode(['success' => false, 'message' => 'Kanal şifresi tanımlı değil.']); break; }

                $konu = '[TEST] ' . $siteTitle . ' - E-posta Test';
                $body = "Bu bir test e-postasıdır.\n\nGönderen kanal: {$smtpUser}\nTarih: " . date('d.m.Y H:i:s');

                $result = gmailSmtpGonderEnt($smtpUser, $smtpPass, $hedef, $konu, $body);

                // Log yaz
                $db->insert('EntegrasyonLoglari', [
                    'log_kanal_id'   => $kanalId,
                    'log_tip'        => 'email',
                    'log_alici'      => $hedef,
                    'log_konu'       => $konu,
                    'log_mesaj'      => $body,
                    'log_sonuc'      => $result['success'] ? 'başarılı' : 'basarisiz',
                    'log_hata'       => $result['success'] ? null : $result['message'],
                    'OlusturanKullanici' => $user['kullanici_id'],
                    'OlusturmaTarihi'   => date('Y-m-d H:i:s'),
                    'Durum'          => 1,
                ]);
                echo json_encode($result);
                break;

            // ── Test WhatsApp ─────────────────────────────────────────────
            case 'test_whatsapp':
                $kanalId = (int)($_POST['kanal_id'] ?? 0);
                $hedef   = preg_replace('/\D/', '', $_POST['hedef'] ?? '');

                if (strlen($hedef) < 10) { echo json_encode(['success' => false, 'message' => 'Geçerli bir telefon numarası girin.']); break; }
                if (strlen($hedef) === 10) $hedef = '90' . $hedef;

                $kanal = $db->fetchOne("SELECT k.kanal_kod, e.entegrasyon_url, e.entegrasyon_api_key FROM EntegrasyonKanallari k JOIN Entegrasyonlar e ON k.kanal_entegrasyon_id = e.entegrasyon_id WHERE k.kanal_id = ?", [$kanalId]);
                if (!$kanal) { echo json_encode(['success' => false, 'message' => 'Kanal bulunamadı.']); break; }

                $mesaj  = "[TEST] {$siteTitle}\nBu bir test mesajıdır.\nTarih: " . date('d.m.Y H:i:s');
                $result = whatsappGonderEnt($kanal['entegrasyon_url'], $kanal['entegrasyon_api_key'], $kanal['kanal_kod'], $hedef, $mesaj);

                $db->insert('EntegrasyonLoglari', [
                    'log_kanal_id'   => $kanalId,
                    'log_tip'        => 'whatsapp',
                    'log_alici'      => $hedef,
                    'log_konu'       => 'Test Mesajı',
                    'log_mesaj'      => $mesaj,
                    'log_sonuc'      => $result['success'] ? 'başarılı' : 'basarisiz',
                    'log_hata'       => $result['success'] ? null : $result['message'],
                    'OlusturanKullanici' => $user['kullanici_id'],
                    'OlusturmaTarihi'   => date('Y-m-d H:i:s'),
                    'Durum'          => 1,
                ]);
                echo json_encode($result);
                break;

            // ── Test SMS (EkoMesaj) ───────────────────────────────────────
            case 'test_sms':
                $kanalId = (int)($_POST['kanal_id'] ?? 0);
                $hedef   = preg_replace('/\D/', '', $_POST['hedef'] ?? '');

                if (strlen($hedef) < 10) { echo json_encode(['success' => false, 'message' => 'Geçerli bir telefon numarası girin.']); break; }
                if (strlen($hedef) === 10) $hedef = '90' . $hedef;

                $kanal = $db->fetchOne("SELECT k.*, e.entegrasyon_url FROM EntegrasyonKanallari k JOIN Entegrasyonlar e ON k.kanal_entegrasyon_id = e.entegrasyon_id WHERE k.kanal_id = ?", [$kanalId]);
                if (!$kanal) { echo json_encode(['success' => false, 'message' => 'Kanal bulunamadı.']); break; }

                $ayarlar  = json_decode($kanal['kanal_ayarlar'] ?? '{}', true);
                $username = $kanal['kanal_ad'];        // Basic Auth kullanıcı adı (ör. akademi)
                $password = $ayarlar['sifre'] ?? '';
                $sender   = $kanal['kanal_kod'];       // Onaylı gönderici başlığı (ör. ÖrnekAKADMi)

                if (empty($password)) { echo json_encode(['success' => false, 'message' => 'Kanal şifresi tanımlı değil.']); break; }
                if (empty($sender))   { echo json_encode(['success' => false, 'message' => 'Gönderici başlığı (Kod) tanımlı değil.']); break; }

                $mesaj  = "[TEST] {$siteTitle} - Bu bir test SMS mesajıdır. " . date('d.m.Y H:i');
                $result = smsGonderEnt($kanal['entegrasyon_url'], $username, $password, $sender, $hedef, $mesaj);

                $db->insert('EntegrasyonLoglari', [
                    'log_kanal_id'   => $kanalId,
                    'log_tip'        => 'sms',
                    'log_alici'      => $hedef,
                    'log_konu'       => 'Test SMS',
                    'log_mesaj'      => $mesaj,
                    'log_sonuc'      => $result['success'] ? 'başarılı' : 'basarisiz',
                    'log_hata'       => $result['success'] ? null : $result['message'],
                    'OlusturanKullanici' => $user['kullanici_id'],
                    'OlusturmaTarihi'   => date('Y-m-d H:i:s'),
                    'Durum'          => 1,
                ]);
                echo json_encode($result);
                break;

            // ── Log listesi ───────────────────────────────────────────────
            case 'list_log':
                $kanalId = (int)($_POST['kanal_id'] ?? 0);
                $logs    = $db->fetchAll("
                    SELECT TOP 20 l.log_id, l.log_tip, l.log_alici, l.log_konu, l.log_sonuc, l.log_hata,
                           CONVERT(VARCHAR(19), l.OlusturmaTarihi, 120) as tarih,
                           k.kanal_ad
                    FROM EntegrasyonLoglari l
                    LEFT JOIN EntegrasyonKanallari k ON l.log_kanal_id = k.kanal_id
                    WHERE l.log_kanal_id = ?
                    ORDER BY l.log_id DESC
                ", [$kanalId]);
                echo json_encode(['success' => true, 'data' => $logs]);
                break;

            default:
                echo json_encode(['success' => false, 'message' => 'Geçersiz işlem.']);
        }
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'message' => $e->getMessage()]);
    }
    exit;
}

// Entegrasyon listesi (kanal formu için PHP tarafında)
$entegrasyonListe = $db->fetchAll("SELECT entegrasyon_id, entegrasyon_ad, entegrasyon_kod FROM Entegrasyonlar WHERE entegrasyon_durum = 1 ORDER BY entegrasyon_ad");
?>
<!DOCTYPE html>
<html lang="tr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars($pageTitle) ?> - <?= htmlspecialchars($siteTitle) ?></title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/@fontsource/source-sans-3@5.0.12/index.css" crossorigin="anonymous">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.13.1/font/bootstrap-icons.min.css" crossorigin="anonymous">
    <link rel="stylesheet" href="/admin/assets/css/adminlte.min.css">
    <link href="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/select2-bootstrap-5-theme@1.3.0/dist/select2-bootstrap-5-theme.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/sweetalert2@11/dist/sweetalert2.min.css" rel="stylesheet">
    <link rel="stylesheet" href="/admin/assets/css/custom.css">
    <style>
        .api-key-cell { font-family: monospace; letter-spacing: 2px; }
        .log-sonuc-başarılı { color: #198754; font-weight: 600; }
        .log-sonuc-basarisiz { color: #dc3545; font-weight: 600; }
        #filterCard { transition: all .3s ease; }
        .info-box { transition: transform .2s; }
        .info-box:hover { transform: translateY(-4px); box-shadow: 0 4px 8px rgba(0,0,0,.1); }
    </style>
</head>
<body class="layout-fixed sidebar-expand-lg sidebar-open bg-body-tertiary">
<div class="app-wrapper">
    <?php include __DIR__ . '/../includes/header.php'; ?>
    <?php include __DIR__ . '/../includes/sidebar.php'; ?>

    <main class="app-main">
        <div class="app-content-header">
            <div class="container-fluid">
                <div class="row">
                    <div class="col-sm-6"><h3 class="mb-0"><?= htmlspecialchars($pageTitle) ?></h3></div>
                    <div class="col-sm-6">
                        <ol class="breadcrumb float-sm-end">
                            <?php if ($menuAdi): ?><li class="breadcrumb-item"><?= htmlspecialchars($menuAdi) ?></li><?php endif; ?>
                            <li class="breadcrumb-item active"><?= htmlspecialchars($pageTitle) ?></li>
                        </ol>
                    </div>
                </div>
            </div>
        </div>

        <div class="app-content">
            <div class="container-fluid">

                <!-- InfoBoxes -->
                <div class="row mb-3">
                    <div class="col-12 col-sm-6 col-md-3">
                        <div class="info-box">
                            <span class="info-box-icon text-bg-primary shadow-sm"><i class="bi bi-plug"></i></span>
                            <div class="info-box-content">
                                <span class="info-box-text">Toplam Entegrasyon</span>
                                <span class="info-box-number" id="stat-toplam">—</span>
                            </div>
                        </div>
                    </div>
                    <div class="col-12 col-sm-6 col-md-3">
                        <div class="info-box">
                            <span class="info-box-icon text-bg-danger shadow-sm"><i class="bi bi-envelope-fill"></i></span>
                            <div class="info-box-content">
                                <span class="info-box-text">Aktif Gmail Kanalı</span>
                                <span class="info-box-number" id="stat-gmail">—</span>
                            </div>
                        </div>
                    </div>
                    <div class="col-12 col-sm-6 col-md-3">
                        <div class="info-box">
                            <span class="info-box-icon text-bg-success shadow-sm"><i class="bi bi-whatsapp"></i></span>
                            <div class="info-box-content">
                                <span class="info-box-text">Aktif WA Kanalı</span>
                                <span class="info-box-number" id="stat-wa">—</span>
                            </div>
                        </div>
                    </div>
                    <div class="col-12 col-sm-6 col-md-3">
                        <div class="info-box">
                            <span class="info-box-icon text-bg-warning shadow-sm"><i class="bi bi-chat-dots-fill"></i></span>
                            <div class="info-box-content">
                                <span class="info-box-text">Aktif SMS Kanalı</span>
                                <span class="info-box-number" id="stat-sms">—</span>
                            </div>
                        </div>
                    </div>
                    <div class="col-12 col-sm-6 col-md-3">
                        <div class="info-box">
                            <span class="info-box-icon text-bg-info shadow-sm"><i class="bi bi-journal-text"></i></span>
                            <div class="info-box-content">
                                <span class="info-box-text">Bugün Log</span>
                                <span class="info-box-number" id="stat-log">—</span>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Filtre -->
                <div class="card card-primary card-outline mb-3 collapse" id="filterCard">
                    <div class="card-header"><h3 class="card-title"><i class="bi bi-funnel"></i> Filtreler</h3></div>
                    <div class="card-body">
                        <form id="filterForm">
                            <div class="row g-3">
                                <div class="col-md-4">
                                    <label class="form-label">Arama</label>
                                    <input type="text" class="form-control" id="f_search" name="search" placeholder="Ad veya kod...">
                                </div>
                                <div class="col-md-3">
                                    <label class="form-label">Tip</label>
                                    <select class="form-select" id="f_kod" name="kod">
                                        <option value="">Tümü</option>
                                        <option value="gmail">Gmail SMTP</option>
                                        <option value="whatsapp_evolution">WhatsApp Evolution</option>
                                        <option value="ekomesaj_sms">EkoMesaj SMS</option>
                                    </select>
                                </div>
                                <div class="col-md-2">
                                    <label class="form-label">Durum</label>
                                    <select class="form-select" id="f_durum" name="durum">
                                        <option value="">Tümü</option>
                                        <option value="1">Aktif</option>
                                        <option value="0">Pasif</option>
                                    </select>
                                </div>
                                <div class="col-md-3 d-flex align-items-end gap-2">
                                    <button type="submit" class="btn btn-primary"><i class="bi bi-search"></i> Filtrele</button>
                                    <button type="button" class="btn btn-secondary" id="btnClearFilter"><i class="bi bi-x-circle"></i> Temizle</button>
                                </div>
                            </div>
                        </form>
                    </div>
                </div>

                <!-- Sekmeler -->
                <div class="card card-primary card-outline">
                    <div class="card-header p-0">
                        <ul class="nav nav-tabs" id="mainTabs">
                            <li class="nav-item">
                                <a class="nav-link active" data-bs-toggle="tab" href="#tabEntegrasyon">
                                    <i class="bi bi-plug"></i> Entegrasyonlar
                                </a>
                            </li>
                            <li class="nav-item">
                                <a class="nav-link" data-bs-toggle="tab" href="#tabKanal" id="tabKanalLink">
                                    <i class="bi bi-broadcast"></i> Kanallar
                                </a>
                            </li>
                        </ul>
                    </div>

                    <!-- Sekme: Entegrasyonlar -->
                    <div class="tab-content">
                        <div class="tab-pane fade show active" id="tabEntegrasyon">
                            <div class="card-header border-top-0">
                                <h3 class="card-title"><i class="bi bi-list-ul"></i> Entegrasyon Listesi</h3>
                                <div class="card-tools">
                                    <button class="btn btn-sm btn-secondary" data-bs-toggle="collapse" data-bs-target="#filterCard">
                                        <i class="bi bi-funnel"></i> Filtrele
                                    </button>
                                    <?php if ($pagePermissions['can_add']): ?>
                                    <button class="btn btn-sm btn-primary" id="btnYeniEntegrasyon">
                                        <i class="bi bi-plus-circle"></i> Yeni Entegrasyon
                                    </button>
                                    <?php endif; ?>
                                </div>
                            </div>
                            <div class="card-body p-0">
                                <table class="table table-bordered table-striped table-hover mb-0" id="dtEntegrasyon">
                                    <thead>
                                        <tr>
                                            <th width="60">ID</th>
                                            <th>Ad</th>
                                            <th>Tip</th>
                                            <th>URL</th>
                                            <th>API Key</th>
                                            <th width="80" class="text-center">Kanallar</th>
                                            <th width="80" class="text-center">Durum</th>
                                            <th width="110" class="text-center">İşlemler</th>
                                        </tr>
                                    </thead>
                                    <tbody id="tbodyEntegrasyon">
                                        <tr><td colspan="8" class="text-center py-3">Yükleniyor...</td></tr>
                                    </tbody>
                                </table>
                            </div>
                        </div>

                        <!-- Sekme: Kanallar -->
                        <div class="tab-pane fade" id="tabKanal">
                            <div class="card-header border-top-0">
                                <h3 class="card-title"><i class="bi bi-list-ul"></i> Kanal Listesi</h3>
                                <div class="card-tools">
                                    <?php if ($pagePermissions['can_add']): ?>
                                    <button class="btn btn-sm btn-primary" id="btnYeniKanal">
                                        <i class="bi bi-plus-circle"></i> Yeni Kanal
                                    </button>
                                    <?php endif; ?>
                                </div>
                            </div>
                            <div class="card-body p-0">
                                <table class="table table-bordered table-striped table-hover mb-0" id="dtKanal">
                                    <thead>
                                        <tr>
                                            <th width="60">ID</th>
                                            <th>Entegrasyon</th>
                                            <th>Kanal Adı</th>
                                            <th>Kod</th>
                                            <th>Ayarlar</th>
                                            <th width="80" class="text-center">Durum</th>
                                            <th width="160" class="text-center">İşlemler</th>
                                        </tr>
                                    </thead>
                                    <tbody id="tbodyKanal">
                                        <tr><td colspan="7" class="text-center py-3">Yükleniyor...</td></tr>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </div>
                </div>

            </div>
        </div>
    </main>

    <?php include __DIR__ . '/../includes/footer.php'; ?>
</div>

<!-- Modal: Entegrasyon Ekle/Düzenle -->
<div class="modal fade" id="modalEntegrasyon" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="modalEntTitle">Yeni Entegrasyon</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <form id="formEntegrasyon">
                <div class="modal-body">
                    <input type="hidden" id="ent_id" name="id">
                    <div class="row g-3">
                        <div class="col-md-6">
                            <label class="form-label">Ad <span class="text-danger">*</span></label>
                            <input type="text" class="form-control" id="ent_ad" name="entegrasyon_ad" required>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Tip (Kod) <span class="text-danger">*</span></label>
                            <select class="form-select" id="ent_kod" name="entegrasyon_kod" required>
                                <option value="">Seçiniz...</option>
                                <option value="gmail">Gmail SMTP</option>
                                <option value="whatsapp_evolution">WhatsApp Evolution API</option>
                                <option value="ekomesaj_sms">EkoMesaj SMS</option>
                            </select>
                        </div>
                        <div class="col-md-8">
                            <label class="form-label">URL / Host</label>
                            <input type="text" class="form-control" id="ent_url" name="entegrasyon_url" placeholder="ör. smtp.gmail.com veya https://...">
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">Durum</label>
                            <select class="form-select" id="ent_durum" name="entegrasyon_durum">
                                <option value="1">Aktif</option>
                                <option value="0">Pasif</option>
                            </select>
                        </div>
                        <div class="col-md-12">
                            <label class="form-label">Global API Key</label>
                            <div class="input-group">
                                <input type="password" class="form-control" id="ent_api_key" name="entegrasyon_api_key" placeholder="Boş bırakırsanız mevcut değer korunur (düzenleme)">
                                <button class="btn btn-outline-secondary" type="button" id="btnToggleApiKey"><i class="bi bi-eye"></i></button>
                            </div>
                            <small class="text-muted">WhatsApp için GlobalApiKey. Gmail için boş bırakın.</small>
                        </div>
                        <div class="col-md-12">
                            <label class="form-label">Açıklama</label>
                            <textarea class="form-control" id="ent_aciklama" name="entegrasyon_aciklama" rows="2"></textarea>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal"><i class="bi bi-x-circle"></i> İptal</button>
                    <button type="submit" class="btn btn-primary"><i class="bi bi-save"></i> Kaydet</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Modal: Kanal Ekle/Düzenle -->
<div class="modal fade" id="modalKanal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="modalKanalTitle">Yeni Kanal</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <form id="formKanal">
                <div class="modal-body">
                    <input type="hidden" id="kanal_id" name="id">
                    <input type="hidden" id="kanal_entegrasyon_kod" name="entegrasyon_kod_kanal">
                    <div class="row g-3">
                        <div class="col-md-12">
                            <label class="form-label">Entegrasyon <span class="text-danger">*</span></label>
                            <select class="form-select" id="kanal_entegrasyon_id" name="kanal_entegrasyon_id" required>
                                <option value="">Seçiniz...</option>
                                <?php foreach ($entegrasyonListe as $e): ?>
                                    <option value="<?= $e['entegrasyon_id'] ?>" data-kod="<?= $e['entegrasyon_kod'] ?>">
                                        <?= htmlspecialchars($e['entegrasyon_ad']) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Kanal Adı <span class="text-danger">*</span></label>
                            <input type="text" class="form-control" id="kanal_ad" name="kanal_ad" required
                                   placeholder="ör. bilgi@firma.com veya BatuhanIS">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Kod / Instance</label>
                            <input type="text" class="form-control" id="kanal_kod" name="kanal_kod"
                                   placeholder="ör. BatuhanIS">
                        </div>
                        <!-- Gmail / SMS özel şifre alanı -->
                        <div class="col-md-12" id="gmailAyarlar" style="display:none;">
                            <label class="form-label" id="sifreLabel">Şifre</label>
                            <div class="input-group">
                                <input type="password" class="form-control" id="kanal_sifre" name="kanal_sifre"
                                       placeholder="Düzenlemede boş bırakırsanız mevcut şifre korunur">
                                <button class="btn btn-outline-secondary" type="button" id="btnToggleKanalSifre"><i class="bi bi-eye"></i></button>
                            </div>
                            <small class="text-muted" id="sifreHint">Google Hesabı → Güvenlik → 2FA → Uygulama Şifreleri</small>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Endpoint (opsiyonel)</label>
                            <input type="text" class="form-control" id="kanal_endpoint" name="kanal_endpoint">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Durum</label>
                            <select class="form-select" id="kanal_durum" name="kanal_durum">
                                <option value="1">Aktif</option>
                                <option value="0">Pasif</option>
                            </select>
                        </div>
                        <div class="col-md-12">
                            <label class="form-label">Açıklama</label>
                            <textarea class="form-control" id="kanal_aciklama" name="kanal_aciklama" rows="2"></textarea>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal"><i class="bi bi-x-circle"></i> İptal</button>
                    <button type="submit" class="btn btn-primary"><i class="bi bi-save"></i> Kaydet</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Modal: Test -->
<div class="modal fade" id="modalTest" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title"><i class="bi bi-send-check"></i> Kanal Test</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <input type="hidden" id="test_kanal_id">
                <input type="hidden" id="test_tip">
                <p class="text-muted small mb-3" id="testAciklama"></p>
                <div class="mb-3">
                    <label class="form-label" id="testHedefLabel">Alıcı</label>
                    <input type="text" class="form-control" id="test_hedef" placeholder="">
                </div>
                <div id="testSonuc" style="display:none;"></div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Kapat</button>
                <button type="button" class="btn btn-primary" id="btnTestGonder">
                    <i class="bi bi-send"></i> Test Gönder
                </button>
            </div>
        </div>
    </div>
</div>

<!-- Modal: Log -->
<div class="modal fade" id="modalLog" tabindex="-1">
    <div class="modal-dialog modal-xl">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title"><i class="bi bi-journal-text"></i> Kanal Logları <span id="logKanalAd" class="text-muted small"></span></h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body p-0">
                <table class="table table-bordered table-sm mb-0">
                    <thead><tr><th>ID</th><th>Tip</th><th>Alıcı</th><th>Konu</th><th>Sonuç</th><th>Hata</th><th>Tarih</th></tr></thead>
                    <tbody id="tbodyLog"><tr><td colspan="7" class="text-center py-2">Yükleniyor...</td></tr></tbody>
                </table>
            </div>
            <div class="modal-footer"><button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Kapat</button></div>
        </div>
    </div>
</div>

<script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/@popperjs/core@2.11.8/dist/umd/popper.min.js" crossorigin="anonymous"></script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.7/dist/js/bootstrap.min.js" crossorigin="anonymous"></script>
<script src="/admin/assets/js/adminlte.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11/dist/sweetalert2.all.min.js"></script>
<script src="/admin/assets/js/custom.js"></script>

<script>
const permissions = <?= json_encode($pagePermissions) ?>;
const modalEnt    = new bootstrap.Modal('#modalEntegrasyon');
const modalKanal  = new bootstrap.Modal('#modalKanal');
const modalTest   = new bootstrap.Modal('#modalTest');
const modalLog    = new bootstrap.Modal('#modalLog');

let currentFilters = {};

// ── Stats ────────────────────────────────────────────────────────────────────
function loadStats() {
    $.post('', { action: 'stats' }, r => {
        if (!r.success) return;
        $('#stat-toplam').text(r.data.toplamEnt);
        $('#stat-gmail').text(r.data.aktifGmail);
        $('#stat-wa').text(r.data.aktifWA);
        $('#stat-sms').text(r.data.aktifSMS);
        $('#stat-log').text(r.data.bugunLog);
    });
}

// ── Entegrasyon listesi ──────────────────────────────────────────────────────
function loadEntegrasyon() {
    $.post('', { action: 'list_entegrasyon', ...currentFilters }, r => {
        const tbody = $('#tbodyEntegrasyon').empty();
        if (!r.success || !r.data.length) {
            tbody.html('<tr><td colspan="8" class="text-center py-3">Kayıt bulunamadı.</td></tr>'); return;
        }
        const tipLabel = { gmail: '<span class="badge bg-danger">Gmail</span>', whatsapp_evolution: '<span class="badge bg-success">WhatsApp</span>', ekomesaj_sms: '<span class="badge bg-warning text-dark">SMS</span>' };
        r.data.forEach(e => {
            const durum = e.entegrasyon_durum == 1 ? '<span class="badge bg-success">Aktif</span>' : '<span class="badge bg-secondary">Pasif</span>';
            const tip   = tipLabel[e.entegrasyon_kod] || `<span class="badge bg-secondary">${e.entegrasyon_kod}</span>`;
            tbody.append(`<tr>
                <td>${e.entegrasyon_id}</td>
                <td>${escHtml(e.entegrasyon_ad)}</td>
                <td>${tip}</td>
                <td><small>${escHtml(e.entegrasyon_url || '—')}</small></td>
                <td class="api-key-cell">${e.entegrasyon_api_key ? '••••••••' : '<span class="text-muted">—</span>'}</td>
                <td class="text-center"><a href="#" class="badge bg-primary text-decoration-none" onclick="goKanalTab(${e.entegrasyon_id})">${e.kanal_sayisi} kanal</a></td>
                <td class="text-center">${durum}</td>
                <td class="text-center">
                    ${permissions.can_edit ? `<button class="btn btn-sm btn-warning" onclick="editEnt(${e.entegrasyon_id})" title="Düzenle"><i class="bi bi-pencil"></i></button>` : ''}
                    ${permissions.can_delete ? `<button class="btn btn-sm btn-danger" onclick="deleteEnt(${e.entegrasyon_id})" title="Pasife Al"><i class="bi bi-x-circle"></i></button>` : ''}
                </td>
            </tr>`);
        });
    });
}

// ── Kanal listesi ────────────────────────────────────────────────────────────
let kanalEntFilter = '';
function loadKanal() {
    $.post('', { action: 'list_kanal', ...currentFilters, entegrasyon: kanalEntFilter }, r => {
        const tbody = $('#tbodyKanal').empty();
        if (!r.success || !r.data.length) {
            tbody.html('<tr><td colspan="7" class="text-center py-3">Kayıt bulunamadı.</td></tr>'); return;
        }
        r.data.forEach(k => {
            const durum = k.kanal_durum == 1 ? '<span class="badge bg-success">Aktif</span>' : '<span class="badge bg-secondary">Pasif</span>';
            const ayarlar = k.kanal_ayarlar_masked
                ? `<small class="text-muted font-monospace">${escHtml(k.kanal_ayarlar_masked)}</small>`
                : '<span class="text-muted">—</span>';
            const testBtn = k.kanal_durum == 1
                ? `<button class="btn btn-sm btn-info" onclick="openTest(${k.kanal_id},'${k.entegrasyon_kod}')" title="Test Et"><i class="bi bi-send-check"></i></button>`
                : '';
            tbody.append(`<tr>
                <td>${k.kanal_id}</td>
                <td><small>${escHtml(k.entegrasyon_ad)}</small></td>
                <td>${escHtml(k.kanal_ad)}</td>
                <td><code>${escHtml(k.kanal_kod)}</code></td>
                <td>${ayarlar}</td>
                <td class="text-center">${durum}</td>
                <td class="text-center">
                    ${testBtn}
                    <button class="btn btn-sm btn-secondary" onclick="openLog(${k.kanal_id},'${escHtml(k.kanal_ad)}')" title="Loglar"><i class="bi bi-journal-text"></i></button>
                    ${permissions.can_edit ? `<button class="btn btn-sm btn-warning" onclick="editKanal(${k.kanal_id})" title="Düzenle"><i class="bi bi-pencil"></i></button>` : ''}
                    ${permissions.can_delete ? `<button class="btn btn-sm btn-danger" onclick="deleteKanal(${k.kanal_id})" title="Pasife Al"><i class="bi bi-x-circle"></i></button>` : ''}
                </td>
            </tr>`);
        });
    });
}

// ── Entegrasyon CRUD ─────────────────────────────────────────────────────────
$('#btnYeniEntegrasyon').on('click', () => {
    $('#modalEntTitle').text('Yeni Entegrasyon');
    $('#formEntegrasyon')[0].reset();
    $('#ent_id').val('');
    $('#ent_kod, #ent_durum').select2({ theme: 'bootstrap-5', dropdownParent: $('#modalEntegrasyon'), minimumResultsForSearch: Infinity });
    modalEnt.show();
});

function editEnt(id) {
    $.post('', { action: 'get_entegrasyon', id }, r => {
        if (!r.success) { showToast(r.message, 'error'); return; }
        const d = r.data;
        $('#modalEntTitle').text('Entegrasyon Düzenle');
        $('#ent_id').val(d.entegrasyon_id);
        $('#ent_ad').val(d.entegrasyon_ad);
        $('#ent_kod').val(d.entegrasyon_kod);
        $('#ent_url').val(d.entegrasyon_url);
        $('#ent_api_key').val('');
        $('#ent_durum').val(d.entegrasyon_durum);
        $('#ent_aciklama').val(d.entegrasyon_aciklama);
        $('#ent_kod, #ent_durum').select2({ theme: 'bootstrap-5', dropdownParent: $('#modalEntegrasyon'), minimumResultsForSearch: Infinity });
        modalEnt.show();
    });
}

$('#formEntegrasyon').on('submit', function(e) {
    e.preventDefault();
    $.post('', $(this).serialize() + '&action=save_entegrasyon', r => {
        if (r.success) { showToast(r.message, 'success'); modalEnt.hide(); loadStats(); loadEntegrasyon(); }
        else showToast(r.message, 'error');
    });
});

function deleteEnt(id) {
    confirmAction('Bu entegrasyonu pasife almak istediğinize emin misiniz?', '', () => {
        $.post('', { action: 'delete_entegrasyon', id }, r => {
            showToast(r.message, r.success ? 'success' : 'error');
            if (r.success) { loadStats(); loadEntegrasyon(); }
        });
    });
}

// ── Kanal CRUD ───────────────────────────────────────────────────────────────
$('#btnYeniKanal').on('click', () => {
    $('#modalKanalTitle').text('Yeni Kanal');
    $('#formKanal')[0].reset();
    $('#kanal_id').val('');
    $('#gmailAyarlar').hide();
    initKanalSelect2();
    modalKanal.show();
});

function editKanal(id) {
    $.post('', { action: 'get_kanal', id }, r => {
        if (!r.success) { showToast(r.message, 'error'); return; }
        const d = r.data;
        $('#modalKanalTitle').text('Kanal Düzenle');
        $('#kanal_id').val(d.kanal_id);
        $('#kanal_entegrasyon_id').val(d.kanal_entegrasyon_id);
        $('#kanal_entegrasyon_kod').val(d.entegrasyon_kod);
        $('#kanal_ad').val(d.kanal_ad);
        $('#kanal_kod').val(d.kanal_kod);
        $('#kanal_endpoint').val(d.kanal_endpoint);
        $('#kanal_aciklama').val(d.kanal_aciklama);
        $('#kanal_durum').val(d.kanal_durum);
        $('#kanal_sifre').val('');
        toggleKanalAlanlari(d.entegrasyon_kod);
        initKanalSelect2();
        modalKanal.show();
    });
}

// Kanal tipine göre şifre alanını göster/gizle ve etiketleri ayarla
function toggleKanalAlanlari(kod) {
    const sifreliMi = (kod === 'gmail' || kod === 'ekomesaj_sms');
    $('#gmailAyarlar').toggle(sifreliMi);
    if (kod === 'ekomesaj_sms') {
        $('#sifreLabel').text('SMS API Şifresi');
        $('#sifreHint').text('EkoMesaj panel şifresi. Kanal Adı = API kullanıcı adı, Kod = onaylı gönderici başlığı.');
    } else if (kod === 'gmail') {
        $('#sifreLabel').text('Gmail Uygulama Şifresi');
        $('#sifreHint').text('Google Hesabı → Güvenlik → 2FA → Uygulama Şifreleri');
    }
}

$('#kanal_entegrasyon_id').on('change', function() {
    const kod = $(this).find(':selected').data('kod') || '';
    $('#kanal_entegrasyon_kod').val(kod);
    toggleKanalAlanlari(kod);
});

$('#formKanal').on('submit', function(e) {
    e.preventDefault();
    $.post('', $(this).serialize() + '&action=save_kanal', r => {
        if (r.success) { showToast(r.message, 'success'); modalKanal.hide(); loadStats(); loadKanal(); }
        else showToast(r.message, 'error');
    });
});

function deleteKanal(id) {
    confirmAction('Bu kanalı pasife almak istediğinize emin misiniz?', '', () => {
        $.post('', { action: 'delete_kanal', id }, r => {
            showToast(r.message, r.success ? 'success' : 'error');
            if (r.success) { loadStats(); loadKanal(); }
        });
    });
}

// ── Kanal sekmesine filtreyle git ────────────────────────────────────────────
function goKanalTab(entId) {
    kanalEntFilter = entId;
    $('#tabKanalLink').tab('show');
}

// ── Test ─────────────────────────────────────────────────────────────────────
function openTest(kanalId, tip) {
    $('#test_kanal_id').val(kanalId);
    $('#test_tip').val(tip);
    $('#testSonuc').hide().html('');
    if (tip === 'gmail') {
        $('#testHedefLabel').text('Alıcı E-posta');
        $('#test_hedef').attr('type', 'email').val('<?= addslashes($user['kullanici_email'] ?? '') ?>');
        $('#testAciklama').text('Girdiğiniz e-posta adresine test maili gönderilecek ve EntegrasyonLoglari\'na kaydedilecek.');
    } else if (tip === 'ekomesaj_sms') {
        $('#testHedefLabel').text('Alıcı Telefon (5XXXXXXXXX)');
        $('#test_hedef').attr('type', 'tel').val('<?= addslashes(preg_replace('/^90/', '', preg_replace('/\D/', '', $user['kullanici_telefon'] ?? ''))) ?>');
        $('#testAciklama').text('Girdiğiniz numaraya test SMS gönderilecek ve EntegrasyonLoglari\'na kaydedilecek.');
    } else {
        $('#testHedefLabel').text('Alıcı Telefon (5XXXXXXXXX)');
        $('#test_hedef').attr('type', 'tel').val('<?= addslashes(preg_replace('/^90/', '', preg_replace('/\D/', '', $user['kullanici_telefon'] ?? ''))) ?>');
        $('#testAciklama').text('Girdiğiniz numaraya WhatsApp test mesajı gönderilecek ve EntegrasyonLoglari\'na kaydedilecek.');
    }
    modalTest.show();
}

$('#btnTestGonder').on('click', function() {
    const kanalId = $('#test_kanal_id').val();
    const tip     = $('#test_tip').val();
    const hedef   = $('#test_hedef').val().trim();
    const action  = tip === 'gmail' ? 'test_gmail' : (tip === 'ekomesaj_sms' ? 'test_sms' : 'test_whatsapp');

    $(this).prop('disabled', true).html('<i class="bi bi-hourglass-split"></i> Gönderiliyor...');

    $.post('', { action, kanal_id: kanalId, hedef }, r => {
        const cls  = r.success ? 'alert-success' : 'alert-danger';
        const icon = r.success ? 'bi-check-circle-fill' : 'bi-x-circle-fill';
        $('#testSonuc').html(`<div class="alert ${cls}"><i class="bi ${icon}"></i> ${r.message}</div>`).show();
        loadStats();
    }).always(() => {
        $('#btnTestGonder').prop('disabled', false).html('<i class="bi bi-send"></i> Test Gönder');
    });
});

// ── Log ──────────────────────────────────────────────────────────────────────
function openLog(kanalId, kanalAd) {
    $('#logKanalAd').text('— ' + kanalAd);
    $('#tbodyLog').html('<tr><td colspan="7" class="text-center py-2">Yükleniyor...</td></tr>');
    modalLog.show();
    $.post('', { action: 'list_log', kanal_id: kanalId }, r => {
        const tbody = $('#tbodyLog').empty();
        if (!r.success || !r.data.length) { tbody.html('<tr><td colspan="7" class="text-center py-2">Log bulunamadı.</td></tr>'); return; }
        r.data.forEach(l => {
            const sonucCls = l.log_sonuc === 'başarılı' ? 'log-sonuc-başarılı' : 'log-sonuc-basarisiz';
            const tipBadge = l.log_tip === 'email' ? '<span class="badge bg-danger">E-posta</span>'
                : (l.log_tip === 'sms' ? '<span class="badge bg-warning text-dark">SMS</span>' : '<span class="badge bg-success">WhatsApp</span>');
            tbody.append(`<tr>
                <td>${l.log_id}</td><td>${tipBadge}</td>
                <td>${escHtml(l.log_alici || '—')}</td>
                <td>${escHtml(l.log_konu || '—')}</td>
                <td class="${sonucCls}">${l.log_sonuc}</td>
                <td><small class="text-danger">${escHtml(l.log_hata || '')}</small></td>
                <td><small>${l.tarih}</small></td>
            </tr>`);
        });
    });
}

// ── Filtre ───────────────────────────────────────────────────────────────────
$('#filterForm').on('submit', function(e) {
    e.preventDefault();
    currentFilters = {};
    const s = $('#f_search').val(), k = $('#f_kod').val(), d = $('#f_durum').val();
    if (s) currentFilters.search = s;
    if (k) currentFilters.kod    = k;
    if (d !== '') currentFilters.durum = d;
    loadEntegrasyon(); loadKanal();
    showToast('Filtre uygulandı', 'info');
});

$('#btnClearFilter').on('click', () => {
    $('#filterForm')[0].reset();
    currentFilters = {}; kanalEntFilter = '';
    loadEntegrasyon(); loadKanal();
    showToast('Filtreler temizlendi', 'info');
});

// Sekme değişince kanal listesini yenile
$('#tabKanalLink').on('shown.bs.tab', () => loadKanal());

// ── Yardımcılar ──────────────────────────────────────────────────────────────
function escHtml(s) {
    if (!s) return '';
    return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
}

function initKanalSelect2() {
    $('#kanal_entegrasyon_id, #kanal_durum').select2({ theme: 'bootstrap-5', dropdownParent: $('#modalKanal'), minimumResultsForSearch: Infinity });
}

$('#btnToggleApiKey').on('click', function() {
    const inp = $('#ent_api_key');
    const t   = inp.attr('type') === 'password' ? 'text' : 'password';
    inp.attr('type', t);
    $(this).find('i').toggleClass('bi-eye bi-eye-slash');
});

$('#btnToggleKanalSifre').on('click', function() {
    const inp = $('#kanal_sifre');
    const t   = inp.attr('type') === 'password' ? 'text' : 'password';
    inp.attr('type', t);
    $(this).find('i').toggleClass('bi-eye bi-eye-slash');
});

// ── Sayfa yükle ──────────────────────────────────────────────────────────────
$(document).ready(() => {
    $('#f_kod, #f_durum').select2({ theme: 'bootstrap-5', minimumResultsForSearch: Infinity, allowClear: true, placeholder: 'Tümü' });
    loadStats();
    loadEntegrasyon();
});
</script>
</body>
</html>
