<?php
/**
 * Gelen Cagri Webhook'u (MacroDroid)
 *
 * Bot hattina (cagri telefonu) gelen GSM cagrisinin numarasini alir,
 * Cari kaydindan isletmeyi ve sehri bulur, sehrin bagli oldugu
 * WhatsApp grubuna "arar misiniz" mesaji gonderir.
 *
 * URL : https://ticari.ornekproje.com/admin/api/cagri-webhook.php?token=<cagri_webhook_token>
 * Govde (JSON veya form): numara, cihaz
 *
 * Bildirimler cagri_instance_kod instance'indan gonderilir (bos ise botun
 * kendi instance'i). Grup eslemesi WhatsappBotAyarlari tablosundadir:
 *   cagri_grup_N_jid / cagri_grup_N_sehirler (virgullu SehirId listesi)
 *
 * Kayitli olmayan numaralar cagri_kayitsiz_jid adresine ozel mesajla
 * iletilir (bos ise yalnizca loglanir). Grubu olmayan sehirlerdeki
 * isletmeler hicbir yere gonderilmez, CagriBildirimleri'ne loglanir.
 */

require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../includes/WhatsappBot.php';

header('Content-Type: application/json; charset=utf-8');

function cikis(string $durum, string $mesaj = ''): void
{
    echo json_encode(['status' => $durum, 'message' => $mesaj], JSON_UNESCAPED_UNICODE);
    exit;
}

$db  = Database::getInstance();
$bot = new WhatsappBot($db);

// ── Token dogrulamasi ────────────────────────────────────────────
$beklenen = $bot->ayar('cagri_webhook_token');
$gelen    = $_GET['token'] ?? ($_SERVER['HTTP_X_WEBHOOK_TOKEN'] ?? '');

if ($beklenen === '') {
    http_response_code(503);
    cikis('hata', 'Webhook token tanimli degil.');
}
if (!hash_equals($beklenen, (string)$gelen)) {
    http_response_code(401);
    cikis('hata', 'Yetkisiz.');
}

// ── Gelen veri (JSON veya form) ──────────────────────────────────
$veri = json_decode((string)file_get_contents('php://input'), true);
if (!is_array($veri)) $veri = $_POST;

$hamNumara = trim((string)($veri['numara'] ?? ''));
$cihaz     = mb_substr(trim((string)($veri['cihaz'] ?? '')), 0, 50);
$telefon   = telefon10($hamNumara);

$log = [
    'CagriBildirimleri_HamNumara'   => mb_substr($hamNumara, 0, 50),
    'CagriBildirimleri_Telefon'     => $telefon !== '' ? $telefon : null,
    'CagriBildirimleri_CagriTarihi' => date('Y-m-d H:i:s'),
    'CagriBildirimleri_Cihaz'       => $cihaz !== '' ? $cihaz : null,
    'CagriBildirimleri_Gonderildi'  => 0,
    'OlusturmaTarihi'               => date('Y-m-d H:i:s'),
    'Durum'                         => 1,
];

// Gizli numara veya cozulemeyen deger
if ($telefon === '') {
    $log['CagriBildirimleri_Hata'] = 'Numara okunamadı (gizli numara olabilir).';
    $db->insert('CagriBildirimleri', $log);
    cikis('atlandi', $log['CagriBildirimleri_Hata']);
}

// ── Grup eslemesi ────────────────────────────────────────────────
$gruplar = grupHaritasi($db);

// ── Isletme ve hedef ─────────────────────────────────────────────
$cari = cariBul($db, $telefon, array_keys($gruplar));

if ($cari === null) {
    // Kayitsiz numara gruba degil, cagri_kayitsiz_jid adresine ozel iletilir
    $hedefJid = $bot->ayar('cagri_kayitsiz_jid');
    if ($hedefJid === '') {
        $log['CagriBildirimleri_Hata'] = 'Cari kaydı bulunamadı.';
        $db->insert('CagriBildirimleri', $log);
        cikis('atlandi', $log['CagriBildirimleri_Hata']);
    }
} else {
    $sehirId = (int)$cari['cari_sehirler'];
    $log['CagriBildirimleri_CariId']  = (int)$cari['cari_id'];
    $log['CagriBildirimleri_SehirId'] = $sehirId ?: null;
    $log['CagriBildirimleri_IlceId']  = $cari['cari_ilceler'] !== null ? (int)$cari['cari_ilceler'] : null;

    $hedefJid = $gruplar[$sehirId] ?? '';
    if ($hedefJid === '') {
        $log['CagriBildirimleri_Hata'] = 'Şehir için tanımlı grup yok.';
        $db->insert('CagriBildirimleri', $log);
        cikis('atlandi', $log['CagriBildirimleri_Hata']);
    }
}
$log['CagriBildirimleri_GrupJid'] = $hedefJid;

if ($bot->ayar('cagri_bildirim_aktif', '1') !== '1') {
    $log['CagriBildirimleri_Hata'] = 'Çağrı bildirimi kapalı.';
    $db->insert('CagriBildirimleri', $log);
    cikis('atlandi', $log['CagriBildirimleri_Hata']);
}

// Ayni numara kisa surede tekrar ararsa grup yeniden rahatsiz edilmez
$tekrarDk = max(0, (int)$bot->ayar('cagri_tekrar_sure_dk', '5'));
if ($tekrarDk > 0) {
    $onceki = $db->fetchOne("
        SELECT TOP 1 CagriBildirimleri_id
        FROM CagriBildirimleri
        WHERE CagriBildirimleri_Telefon = ? AND CagriBildirimleri_Gonderildi = 1
          AND CagriBildirimleri_CagriTarihi >= ?
    ", [$telefon, date('Y-m-d H:i:s', time() - $tekrarDk * 60)]);

    if ($onceki) {
        $log['CagriBildirimleri_Hata'] = 'Tekrar arama, ' . $tekrarDk . ' dk içinde bildirildi.';
        $db->insert('CagriBildirimleri', $log);
        cikis('atlandi', $log['CagriBildirimleri_Hata']);
    }
}

// ── Mesaj ve gonderim ────────────────────────────────────────────
if ($cari === null) {
    $mesaj = $bot->metin('CAGRI_BILINMEYEN', [
        'telefon' => telefonGoster($telefon),
        'saat'    => date('d.m.Y H:i'),
    ]);
} else {
    $isletme = trim((string)$cari['cari_unvan']) !== '' ? $cari['cari_unvan'] : (string)$cari['cari_adi'];

    $mesaj = $bot->metin('CAGRI_BILDIRIM', [
        'isletme' => trim($isletme) !== '' ? trim($isletme) : '-',
        'il'      => WhatsappBot::sehirGorunenAd((string)($cari['SehirAdi'] ?? '')) ?: '-',
        'ilce'    => (string)($cari['IlceAdi'] ?? '') ?: '-',
        'telefon' => telefonGoster($telefon),
        'saat'    => date('d.m.Y H:i'),
    ]);
}

$log['CagriBildirimleri_Mesaj'] = mb_substr($mesaj, 0, 1000);

// Bildirimler botun hattindan degil, cagri_instance_kod instance'indan gider
$sonuc = $bot->gonder($hedefJid, $mesaj, 'GRUP', $bot->ayar('cagri_instance_kod'));
$log['CagriBildirimleri_Gonderildi'] = $sonuc['success'] ? 1 : 0;
if (!$sonuc['success']) {
    $log['CagriBildirimleri_Hata'] = mb_substr($sonuc['message'], 0, 500);
}
$db->insert('CagriBildirimleri', $log);

cikis($sonuc['success'] ? 'tamam' : 'hata', $sonuc['message']);

/**
 * Numarayi son 10 haneye indirger (cep ve sabit hat).
 * 10 haneden kisa ise bos doner.
 */
function telefon10(string $metin): string
{
    $rakam = preg_replace('/\D/', '', $metin) ?? '';
    return strlen($rakam) >= 10 ? substr($rakam, -10) : '';
}

/** 5001234567 -> 0500 123 45 67 */
function telefonGoster(string $telefon10): string
{
    return preg_replace('/^(\d{3})(\d{3})(\d{2})(\d{2})$/', '0$1 $2 $3 $4', $telefon10) ?? $telefon10;
}

/**
 * WhatsappBotAyarlari'ndaki cagri_grup_N_* kayitlarindan
 * [SehirId => grup JID] haritasi uretir.
 */
function grupHaritasi(Database $db): array
{
    $ayar = [];
    foreach ($db->fetchAll("
        SELECT WhatsappBotAyarlari_Anahtar AS k, WhatsappBotAyarlari_Deger AS v
        FROM WhatsappBotAyarlari
        WHERE Durum = 1 AND WhatsappBotAyarlari_Anahtar LIKE 'cagri[_]grup[_]%'
    ") as $r) {
        $ayar[$r['k']] = trim((string)$r['v']);
    }

    $harita = [];
    foreach ($ayar as $k => $v) {
        if (!preg_match('/^cagri_grup_(\d+)_sehirler$/', $k, $m)) continue;
        $jid = $ayar['cagri_grup_' . $m[1] . '_jid'] ?? '';
        if ($jid === '') continue;

        foreach (explode(',', $v) as $sehirId) {
            $sehirId = (int)trim($sehirId);
            if ($sehirId > 0) $harita[$sehirId] = $jid;
        }
    }
    return $harita;
}

/**
 * Telefonu Cari'de arar (firma ve yetkili telefonu).
 * Kayitlar karisik bicimde (05xx, 5xx, bosluklu) tutuldugu icin iki taraf
 * da son 10 haneye indirgenerek karsilastirilir. Birden fazla eslesmede
 * aktif ve grubu tanimli sehirdeki kayit onceliklidir.
 */
function cariBul(Database $db, string $telefon10, array $grupSehirleri): ?array
{
    $temiz = fn(string $kolon) => "RIGHT(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(ISNULL($kolon, ''), ' ', ''), '-', ''), '(', ''), ')', ''), '+', ''), '.', ''), 10)";

    $grupSehirleri = array_map('intval', $grupSehirleri) ?: [0];
    $grupListe     = implode(',', $grupSehirleri);

    $row = $db->fetchOne("
        SELECT TOP 1 c.cari_id, c.cari_adi, c.cari_unvan, c.cari_sehirler, c.cari_ilceler,
               s.SehirAdi, i.IlceAdi
        FROM Cari c
        LEFT JOIN Adres_Sehirler s ON s.SehirId = c.cari_sehirler
        LEFT JOIN Adres_Ilceler  i ON i.ilceId  = c.cari_ilceler
        WHERE " . $temiz('c.cari_telefon') . " = ? OR " . $temiz('c.cari_yetkili_telefon') . " = ?
        ORDER BY c.cari_aktif DESC,
                 CASE WHEN c.cari_sehirler IN ($grupListe) THEN 0 ELSE 1 END,
                 c.cari_id DESC
    ", [$telefon10, $telefon10]);

    return $row ?: null;
}
