<?php
/**
 * WhatsApp Evolution API - Gelen Mesaj Webhook'u
 *
 * Evolution API 'messages.upsert' olayini buraya gonderir.
 * URL: https://ticari.ornekproje.com/admin/api/whatsapp-webhook.php?token=<webhook_token>
 *
 * Bu uc nokta oturum gerektirmez (disaridan cagrilir), bu yuzden
 * WhatsappBotAyarlari.webhook_token ile korunur.
 *
 * Yok sayilan mesajlar: kendi gonderdiklerimiz (fromMe), grup mesajlari,
 * durum yayinlari ve metin icermeyen mesajlar.
 */

require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../includes/WhatsappBot.php';

header('Content-Type: application/json; charset=utf-8');

// Evolution yeniden deneme yapmasin diye her durumda 200 dondurulur;
// sonuc govdede bildirilir.
function cikis(string $durum, string $mesaj = ''): void
{
    echo json_encode(['status' => $durum, 'message' => $mesaj], JSON_UNESCAPED_UNICODE);
    exit;
}

$db  = Database::getInstance();
$bot = new WhatsappBot($db);

// ── Token dogrulamasi ────────────────────────────────────────────
$beklenen = $bot->ayar('webhook_token');
$gelen    = $_GET['token'] ?? ($_SERVER['HTTP_X_WEBHOOK_TOKEN'] ?? '');

if ($beklenen === '' || $beklenen === 'DEGISTIRIN') {
    http_response_code(503);
    cikis('hata', 'Webhook token tanimli degil.');
}
if (!hash_equals($beklenen, (string)$gelen)) {
    http_response_code(401);
    cikis('hata', 'Yetkisiz.');
}

// ── Gelen veri ───────────────────────────────────────────────────
$ham = file_get_contents('php://input');
$veri = json_decode($ham, true);

if (!is_array($veri)) {
    cikis('atlandi', 'Gecersiz govde.');
}

$olay = $veri['event'] ?? '';
if ($olay !== '' && stripos($olay, 'messages.upsert') === false) {
    cikis('atlandi', 'Ilgisiz olay: ' . $olay);
}

// Evolution bazen tek nesne, bazen dizi gonderir
$data = $veri['data'] ?? [];
$mesajlar = isset($data['key']) ? [$data] : (is_array($data) ? $data : []);

$sonuclar = [];

foreach ($mesajlar as $m) {
    if (!is_array($m) || !isset($m['key'])) continue;

    $key   = $m['key'];
    $jid   = (string)($key['remoteJid'] ?? '');
    $benMi = !empty($key['fromMe']);
    $waId  = (string)($key['id'] ?? '');

    if ($jid === '' || $benMi) continue;

    // Grup ve durum mesajlari bota girmez
    if (strpos($jid, '@g.us') !== false)     continue;
    if (strpos($jid, 'broadcast') !== false) continue;

    // WhatsApp kullanici adi (LID) ile yazanlarin adresi numara yerine
    // '<id>@lid' bicimindedir. Bu mesajlar da islenir; numara varsa
    // remoteJidAlt alanindan cozulur, yoksa musteriden istenir.
    $lidMi = strpos($jid, '@lid') !== false;
    if (!$lidMi && strpos($jid, '@s.whatsapp.net') === false) continue;

    $telefon = telefonCoz($key);

    $metin = metinCikar($m['message'] ?? []);

    // Bazi Evolution surumleri secilen anket secenegini mesaj govdesi
    // yerine ust seviyedeki pollUpdates dizisinde bildirir.
    if (trim($metin) === '' && !empty($m['pollUpdates'])) {
        $metin = anketSeciminiCoz($m['pollUpdates']);
    }

    $gorunenAd = (string)($m['pushName'] ?? '');
    $mesajHam  = json_encode($m, JSON_UNESCAPED_UNICODE);

    // Metni cozulemeyen mesajlar (anket yanitlari, medya, konum) islenmez
    // ama ham hali kaydedilir; hangi tipin nasil geldigini gormek icin.
    if (trim($metin) === '') {
        cozulemeyeniKaydet($db, $jid, $waId, $mesajHam);
        $sonuclar[] = 'Metin cozulemedi, ham kaydedildi.';
        continue;
    }

    try {
        $sonuclar[] = $bot->mesajIsle($jid, $metin, $gorunenAd, $waId, $mesajHam, $telefon);
    } catch (Exception $e) {
        error_log('WhatsApp webhook hatasi: ' . $e->getMessage());
        $sonuclar[] = 'Hata: ' . $e->getMessage();
    }
}

cikis('tamam', implode(' | ', $sonuclar));

/**
 * Mesaj anahtarindan musterinin telefon numarasini cozer.
 *
 * Numarali adreslerde numara dogrudan adresin icindedir. Kullanici adi
 * (LID) ile yazanlarda ise adres numara tasimaz; WhatsApp numarayi
 * remoteJidAlt / participantAlt alanlarinda gonderebilir, gondermezse
 * numara ogrenilemez ve musteriden istenir.
 *
 * @return string 10 haneli numara, cozulemezse bos
 */
function telefonCoz(array $key): string
{
    foreach (['remoteJid', 'remoteJidAlt', 'participant', 'participantAlt'] as $alan) {
        $deger = (string)($key[$alan] ?? '');
        if ($deger === '' || strpos($deger, '@lid') !== false) continue;

        $telefon = WhatsappBot::jidTelefon($deger);
        if ($telefon !== '') return $telefon;
    }
    return '';
}

/**
 * pollUpdates dizisinden oy verilen secenegin adini bulur.
 * Tek secim bekledigimiz icin oy almis ilk secenek dondurulur.
 */
function anketSeciminiCoz(array $pollUpdates): string
{
    foreach ($pollUpdates as $secenek) {
        if (!empty($secenek['voters']) && isset($secenek['name'])) {
            return (string)$secenek['name'];
        }
    }
    return '';
}

/**
 * Metni cozulemeyen gelen mesaji yalnizca ham haliyle kaydeder.
 * Bot akisi tetiklenmez; amac gelen tipi inceleyebilmektir.
 */
function cozulemeyeniKaydet(Database $db, string $jid, string $waId, string $ham): void
{
    try {
        $k = $db->fetchOne(
            "SELECT WhatsappKonusmalar_id FROM WhatsappKonusmalar WHERE WhatsappKonusmalar_Jid = ?",
            [$jid]
        );
        $db->insert('WhatsappMesajlar', [
            'WhatsappMesajlar_KonusmaId' => $k ? (int)$k['WhatsappKonusmalar_id'] : null,
            'WhatsappMesajlar_Jid'       => mb_substr($jid, 0, 60),
            'WhatsappMesajlar_Yon'       => 'GELEN',
            'WhatsappMesajlar_WaMesajId' => mb_substr($waId, 0, 80),
            'WhatsappMesajlar_Icerik'    => null,
            'WhatsappMesajlar_Ham'       => $ham,
            'WhatsappMesajlar_Basarili'  => 0,
            'WhatsappMesajlar_Hata'      => 'Metin cozulemedi (islenmedi).',
            'OlusturanKullanici'         => 0,
        ]);
    } catch (Exception $e) {
        error_log('WhatsApp ham kayit hatasi: ' . $e->getMessage());
    }
}

/**
 * Evolution mesaj nesnesinden duz metni cikarir.
 * Desteklenen tipler disindaki mesajlar (resim, ses, konum) bos doner.
 */
function metinCikar(array $mesaj): string
{
    if (isset($mesaj['conversation'])) {
        return (string)$mesaj['conversation'];
    }
    if (isset($mesaj['extendedTextMessage']['text'])) {
        return (string)$mesaj['extendedTextMessage']['text'];
    }
    // Anket yaniti: Evolution secilen secenegi cozup duz metin olarak verir
    if (isset($mesaj['pollUpdateMessage']['vote']['selectedOptions'][0])) {
        return (string)$mesaj['pollUpdateMessage']['vote']['selectedOptions'][0];
    }
    // Buton / liste yanitlari
    if (isset($mesaj['buttonsResponseMessage']['selectedDisplayText'])) {
        return (string)$mesaj['buttonsResponseMessage']['selectedDisplayText'];
    }
    if (isset($mesaj['listResponseMessage']['title'])) {
        return (string)$mesaj['listResponseMessage']['title'];
    }
    // Resim / video aciklamasi
    foreach (['imageMessage', 'videoMessage', 'documentMessage'] as $tip) {
        if (isset($mesaj[$tip]['caption'])) {
            return (string)$mesaj[$tip]['caption'];
        }
    }
    return '';
}
