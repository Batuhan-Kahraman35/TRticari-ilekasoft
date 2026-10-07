<?php
/**
 * Admin Panel - İhbar Sayfası
 *
 * Saha ekipleri için mobil/PWA odaklı hızlı ihbar kaydı.
 * Konum alınmadan form açılmaz; kayıt form.php?cari_tipi_id=4 ile
 * birebir aynı yapıda oluşur (Cari + HukukTakip + Sozlesmeler).
 */

require_once __DIR__ . '/../auth.php';
require_once __DIR__ . '/../includes/PageAuth.php';
require_once __DIR__ . '/../includes/LogHelper.php';
requireAuth();

$user = Auth::user();
$db = Database::getInstance();

// İhbar kayıtları her zaman Suç Duyurusu (cari_tipi_id = 4) olarak açılır
const IHBAR_CARI_TIPI_ID = 4;

// İhbar sayfasında seçilebilecek yayın hakkı sahipleri (HukukTaraflar):
// YayinPlatformu (1), S Sport (5), TABİİ (11). Yayıncı değişirse bu liste güncellenir.
const IHBAR_TARAF_IDLERI = [1, 5, 11];

// Yeni ihbar kaydının bildirileceği WhatsApp grubu (boş bırakılırsa bildirim gönderilmez)
const IHBAR_WHATSAPP_GRUP_JID = '120000000000000000@g.us';

$currentPageFile = 'ihbar.php';
$pagePermissions = PageAuth::checkPagePermissions(
    $user['kullanici_id'],
    $user['departman_id'],
    $currentPageFile
);

if (!$pagePermissions['has_access']) {
    PageAuth::accessDenied($pagePermissions['error'] ?? 'Bu sayfaya erişim yetkiniz bulunmamaktadır.');
}

// Yükleme dizini
$uploadDir = __DIR__ . '/../assets/uploads/ihbar/';
if (!is_dir($uploadDir)) {
    mkdir($uploadDir, 0755, true);
}
$uploadWebDir = 'Admin/assets/uploads/ihbar/';

/**
 * Yeni ihbar kaydını WhatsApp grubuna bildirir.
 *
 * Hedef grup, sayfa başındaki IHBAR_WHATSAPP_GRUP_JID sabitinde tanımlıdır.
 * Sabit boş bırakılırsa bildirim gönderilmez.
 *
 * Gönderim hatası ihbar kaydını etkilemez; hata WhatsappMesajlar tablosuna
 * ve PHP hata günlüğüne yazılır.
 */
function ihbarWhatsappBildir(Database $db, array $v): void
{
    try {
        $jid = trim(IHBAR_WHATSAPP_GRUP_JID);
        if ($jid === '') return;

        require_once __DIR__ . '/../includes/WhatsappBot.php';
        $bot = new WhatsappBot($db);

        $sehirAdi = '';
        if (!empty($v['sehir_id'])) {
            $r = $db->fetchOne("SELECT SehirAdi FROM Adres_Sehirler WHERE SehirId = ?", [$v['sehir_id']]);
            $sehirAdi = (string)($r['SehirAdi'] ?? '');
        }
        $ilceAdi = '';
        if (!empty($v['ilce_id'])) {
            $r = $db->fetchOne("SELECT IlceAdi FROM Adres_Ilceler WHERE ilceId = ?", [$v['ilce_id']]);
            $ilceAdi = (string)($r['IlceAdi'] ?? '');
        }
        $konum = trim($sehirAdi . ($ilceAdi !== '' ? ' / ' . $ilceAdi : ''));

        $host  = $_SERVER['HTTP_HOST'] ?? 'ticari.ornekproje.com';
        $detay = 'https://' . $host . '/admin/form?cari_tipi_id=' . IHBAR_CARI_TIPI_ID . '&id=' . (int)$v['takip_id'];

        $satirlar = [];
        $satirlar[] = '🚨 YENI İLLEGAL DUYURUSU';
        $satirlar[] = 'İşletme: ' . $v['isletme'];
        if ($konum !== '')                  $satirlar[] = 'Konum: ' . $konum;
        if (trim((string)$v['adres']) !== '') $satirlar[] = 'Adres: ' . $v['adres'];
        $satirlar[] = 'Tespit: ' . $v['tespit_turu'];
        if (trim((string)$v['bildiren']) !== '') $satirlar[] = 'Bildiren: ' . $v['bildiren'];
        $satirlar[] = 'Tarih: ' . date('d.m.Y H:i');

        if ($v['enlem'] !== null && $v['enlem'] !== '' && $v['boylam'] !== null && $v['boylam'] !== '') {
            $satirlar[] = 'Harita: https://maps.google.com/?q=' . $v['enlem'] . ',' . $v['boylam'];
        }

        $ekler = [];
        if ((int)$v['gorsel_adet'] > 0) $ekler[] = (int)$v['gorsel_adet'] . ' görsel';
        if (!empty($v['tutanak_var']))  $ekler[] = 'tutanak var';
        if ($ekler)                     $satirlar[] = '📎 ' . implode(', ', $ekler);

        $satirlar[] = 'Detay: ' . $detay;

        $bot->gonder($jid, implode("\n", $satirlar), 'GRUP');
    } catch (Throwable $e) {
        error_log('İhbar WhatsApp bildirimi hatasi: ' . $e->getMessage());
    }
}

/**
 * Türkçe karakterleri sadeleştirip büyük harfe çevirir (isim eşleştirme için).
 */
function ihbarAdNormalize(string $metin): string
{
    $metin = str_replace(
        ['ç', 'Ç', 'ğ', 'Ğ', 'ı', 'I', 'İ', 'i', 'ö', 'Ö', 'ş', 'Ş', 'ü', 'Ü'],
        ['C', 'C', 'G', 'G', 'I', 'I', 'I', 'I', 'O', 'O', 'S', 'S', 'U', 'U'],
        $metin
    );
    $metin = strtoupper($metin);
    return trim(preg_replace('/[^A-Z0-9]/', '', $metin));
}

/**
 * Telefonu 90XXXXXXXXXX biçimine çevirir.
 * Girdi ne olursa olsun (0501 111 11 11 / +90 501 111 11 11 / 5011111111 / 00905011111111)
 * sonuç 905011111111 olur. 10 haneye tamamlanamayan girdide boş döner.
 */
function ihbarTelefonNormalize(?string $telefon): string
{
    $t = preg_replace('/\D/', '', (string) $telefon);
    if ($t === '') return '';

    // Uluslararası önek (00) temizliği
    if (strpos($t, '00') === 0) {
        $t = substr($t, 2);
    }
    // Baştaki 0'lar (0501... -> 501...)
    $t = ltrim($t, '0');

    // Ülke kodu zaten varsa çıkar, son 10 haneyi esas al
    if (strlen($t) > 10 && strpos($t, '90') === 0) {
        $t = substr($t, 2);
    }
    if (strlen($t) > 10) {
        $t = substr($t, -10);
    }
    if (strlen($t) !== 10) return '';

    return '90' . $t;
}

// ─────────────────────────────────────────────
// AJAX İşlemleri
// ─────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json; charset=utf-8');
    $action = $_POST['action'] ?? '';

    try {
        switch ($action) {

            // ── İlçe listesi ──
            case 'get_ilceler':
                $sehirId = intval($_POST['sehir_id'] ?? 0);
                $ilceler = $sehirId
                    ? $db->fetchAll("SELECT ilceId, IlceAdi FROM Adres_Ilceler WHERE SehirId = ? ORDER BY IlceAdi", [$sehirId])
                    : [];
                echo json_encode(['success' => true, 'data' => $ilceler]);
                break;

            // ── Konumdan adres çözümleme (OpenStreetMap Nominatim) ──
            case 'konum_coz':
                $enlem  = trim($_POST['enlem'] ?? '');
                $boylam = trim($_POST['boylam'] ?? '');
                if (!is_numeric($enlem) || !is_numeric($boylam)) {
                    echo json_encode(['success' => false, 'message' => 'Geçersiz koordinat']);
                    break;
                }

                $url = 'https://nominatim.openstreetmap.org/reverse?format=jsonv2&zoom=18&addressdetails=1'
                     . '&accept-language=tr&lat=' . urlencode($enlem) . '&lon=' . urlencode($boylam);

                $ch = curl_init($url);
                curl_setopt_array($ch, [
                    CURLOPT_RETURNTRANSFER => true,
                    CURLOPT_TIMEOUT        => 8,
                    // Nominatim kullanım şartı: tanımlayıcı User-Agent zorunlu
                    CURLOPT_USERAGENT      => 'ticari.ornekproje.com ihbar sayfasi (destek@ornekproje.com)',
                ]);
                $cevap = curl_exec($ch);
                $httpKod = curl_getinfo($ch, CURLINFO_HTTP_CODE);
                curl_close($ch);

                if ($cevap === false || $httpKod !== 200) {
                    echo json_encode(['success' => false, 'message' => 'Adres servisine ulaşılamadı']);
                    break;
                }

                $veri = json_decode($cevap, true);
                $adr  = $veri['address'] ?? [];
                if (empty($adr)) {
                    echo json_encode(['success' => false, 'message' => 'Bu konum için adres bulunamadı']);
                    break;
                }

                // İl: Nominatim'de province / state alanında gelir
                $ilAdi = $adr['province'] ?? $adr['state'] ?? '';
                // İlçe: county / town / city_district / municipality sırasıyla denenir
                $ilceAdi = $adr['county'] ?? $adr['town'] ?? $adr['city_district'] ?? $adr['municipality'] ?? $adr['district'] ?? '';

                // İl eşleştirme (Türkçe karakter farkı yok sayılır)
                $sehirId = null;
                if ($ilAdi !== '') {
                    $hedef = ihbarAdNormalize($ilAdi);
                    foreach ($db->fetchAll("SELECT SehirId, SehirAdi FROM Adres_Sehirler WHERE UlkeId = 1") as $s) {
                        if (ihbarAdNormalize($s['SehirAdi']) === $hedef) {
                            $sehirId = intval($s['SehirId']);
                            break;
                        }
                    }
                }

                // İlçe eşleştirme (il bulunduysa)
                $ilceId = null;
                if ($sehirId && $ilceAdi !== '') {
                    $hedef = ihbarAdNormalize($ilceAdi);
                    foreach ($db->fetchAll("SELECT ilceId, IlceAdi FROM Adres_Ilceler WHERE SehirId = ?", [$sehirId]) as $i) {
                        if (ihbarAdNormalize($i['IlceAdi']) === $hedef) {
                            $ilceId = intval($i['ilceId']);
                            break;
                        }
                    }
                }

                // Açık adres: mahalle + cadde/sokak + kapı no + posta kodu
                $parcalar = array_filter([
                    $adr['neighbourhood'] ?? $adr['suburb'] ?? $adr['quarter'] ?? null,
                    $adr['road'] ?? null,
                    $adr['house_number'] ?? null,
                    $adr['postcode'] ?? null,
                ]);
                $acikAdres = implode(' ', $parcalar);

                echo json_encode([
                    'success'    => true,
                    'sehir_id'   => $sehirId,
                    'ilce_id'    => $ilceId,
                    'il_adi'     => $ilAdi,
                    'ilce_adi'   => $ilceAdi,
                    'adres'      => $acikAdres,
                    'tam_adres'  => $veri['display_name'] ?? ''
                ]);
                break;

            // ── Cari kontrol (aynı isimde kayıt var mı?) ──
            case 'cari_ara':
                $arama = trim($_POST['arama'] ?? '');
                if (mb_strlen($arama) < 3) {
                    echo json_encode(['success' => true, 'data' => []]);
                    break;
                }
                $like = '%' . $arama . '%';
                $sonuclar = $db->fetchAll("
                    SELECT TOP 10
                        c.cari_id,
                        c.cari_adi,
                        c.cari_unvan,
                        c.cari_adres,
                        c.cari_sehirler,
                        c.cari_ilceler,
                        s.SehirAdi,
                        i.IlceAdi
                    FROM Cari c
                    LEFT JOIN Adres_Sehirler s ON c.cari_sehirler = s.SehirId
                    LEFT JOIN Adres_Ilceler  i ON c.cari_ilceler  = i.ilceId
                    WHERE c.cari_aktif = 1
                      AND (c.cari_adi LIKE ? OR c.cari_unvan LIKE ?)
                    ORDER BY c.cari_id DESC
                ", [$like, $like]);
                echo json_encode(['success' => true, 'data' => $sonuclar]);
                break;

            // ── İhbar listesi (mobil kart listesi, sayfalı) ──
            case 'liste':
                $sayfa  = max(1, intval($_POST['sayfa'] ?? 1));
                $arama  = trim($_POST['arama'] ?? '');
                $sadeceBenim = intval($_POST['sadece_benim'] ?? 0);
                $adet   = 20;
                $atla   = ($sayfa - 1) * $adet;

                $where  = "WHERE c.cari_tipi_id = ? AND t.takip_enlem IS NOT NULL";
                $params = [IHBAR_CARI_TIPI_ID];

                // Yetki: sadece kendi kayıtlarını görebilenler + kullanıcının kendi filtresi
                if (!empty($pagePermissions['can_view_own_records']) || $sadeceBenim === 1) {
                    $where .= " AND t.OlusturanKullanici = ?";
                    $params[] = $user['kullanici_id'];
                }
                if ($arama !== '') {
                    $where .= " AND (c.cari_adi LIKE ? OR c.cari_adres LIKE ? OR t.takip_aciklama LIKE ?)";
                    $like = '%' . $arama . '%';
                    $params[] = $like; $params[] = $like; $params[] = $like;
                }

                $toplam = $db->fetchOne("
                    SELECT COUNT(*) AS adet
                    FROM HukukTakip t
                    INNER JOIN Cari c ON t.takip_cari_id = c.cari_id
                    $where
                ", $params)['adet'] ?? 0;

                $kayitlar = $db->fetchAll("
                    SELECT
                        t.takip_id,
                        c.cari_adi,
                        c.cari_adres,
                        c.cari_telefon,
                        s.SehirAdi,
                        i.IlceAdi,
                        t.takip_enlem,
                        t.takip_boylam,
                        t.takip_gorseller,
                        t.takip_tutanak_dosya,
                        t.takip_aciklama,
                        t.takip_tespit_turu,
                        st.statu_ad,
                        CONVERT(VARCHAR(16), t.OlusturmaTarihi, 120) AS olusturma_tarihi,
                        k.kullanici_ad + ' ' + k.kullanici_soyad AS olusturan_adi
                    FROM HukukTakip t
                    INNER JOIN Cari c ON t.takip_cari_id = c.cari_id
                    LEFT JOIN Adres_Sehirler s ON c.cari_sehirler = s.SehirId
                    LEFT JOIN Adres_Ilceler  i ON c.cari_ilceler  = i.ilceId
                    LEFT JOIN HukukStatu st ON t.takip_statu_id = st.statu_id
                    LEFT JOIN kullanicilar k ON t.OlusturanKullanici = k.kullanici_id
                    $where
                    ORDER BY t.takip_id DESC
                    OFFSET ? ROWS FETCH NEXT ? ROWS ONLY
                ", array_merge($params, [$atla, $adet]));

                echo json_encode([
                    'success'  => true,
                    'data'     => $kayitlar,
                    'toplam'   => intval($toplam),
                    'sayfa'    => $sayfa,
                    'devam_var'=> ($atla + count($kayitlar)) < intval($toplam)
                ]);
                break;

            // ── İhbar kaydet ──
            case 'save':
                $cariId      = intval($_POST['cari_id'] ?? 0);
                $isletmeAdi  = trim($_POST['isletme_adi'] ?? '');
                $sehirId     = intval($_POST['sehir_id'] ?? 0) ?: null;
                $ilceId      = intval($_POST['ilce_id'] ?? 0) ?: null;
                $adres       = trim($_POST['adres'] ?? '');
                $telefon     = ihbarTelefonNormalize($_POST['telefon'] ?? '');
                $sezonId     = intval($_POST['sezon_id'] ?? 0) ?: null;
                $aciklama    = trim($_POST['aciklama'] ?? '');
                $fiksturId   = intval($_POST['fikstur_id'] ?? 0) ?: null;
                $tarafId     = intval($_POST['taraf_id'] ?? 0) ?: null;
                $enlem       = trim($_POST['enlem'] ?? '');
                $boylam      = trim($_POST['boylam'] ?? '');
                $dogruluk    = intval($_POST['dogruluk'] ?? 0) ?: null;

                // Validasyon
                if ($enlem === '' || $boylam === '' || !is_numeric($enlem) || !is_numeric($boylam)) {
                    echo json_encode(['success' => false, 'message' => 'Konum bilgisi alınamadı! Konum izni vermeden kayıt yapılamaz.']);
                    exit;
                }
                if ($isletmeAdi === '') {
                    echo json_encode(['success' => false, 'message' => 'İşletme adı zorunludur!']);
                    exit;
                }
                if (!$sehirId) {
                    echo json_encode(['success' => false, 'message' => 'İl seçimi zorunludur!']);
                    exit;
                }
                if ($adres === '') {
                    echo json_encode(['success' => false, 'message' => 'Açık adres zorunludur!']);
                    exit;
                }
                // Yayın hakkı seçimi zorunlu (sayfada gösterilen taraflardan biri olmalı)
                $gecerliTaraf = $db->fetchOne("
                    SELECT taraf_id FROM HukukTaraflar
                    WHERE taraf_id = ? AND Durum = 1
                      AND taraf_id IN (" . implode(',', IHBAR_TARAF_IDLERI) . ")
                ", [$tarafId]);
                if (!$tarafId || !$gecerliTaraf) {
                    echo json_encode(['success' => false, 'message' => 'Yayın hakkı seçimi zorunludur!']);
                    exit;
                }

                // ── 1. CARİ (yeni aç veya mevcudu güncelle) ──
                if ($cariId > 0) {
                    $eskiCari = $db->fetchOne("SELECT * FROM Cari WHERE cari_id = ?", [$cariId]);
                    if (!$eskiCari) {
                        echo json_encode(['success' => false, 'message' => 'Seçilen cari bulunamadı!']);
                        exit;
                    }
                    $db->execute("
                        UPDATE Cari SET
                            cari_adi = ?,
                            cari_sehirler = ?,
                            cari_ilceler = ?,
                            cari_adres = ?,
                            cari_telefon = CASE WHEN ? <> '' THEN ? ELSE cari_telefon END,
                            cari_guncelleme_tarihi = GETDATE()
                        WHERE cari_id = ?
                    ", [$isletmeAdi, $sehirId, $ilceId, $adres, $telefon, $telefon, $cariId]);
                    $yeniCari = $db->fetchOne("SELECT * FROM Cari WHERE cari_id = ?", [$cariId]);
                    logKayitDegisiklikleri($db, 'ihbar', 'Cari', $cariId, $eskiCari, $yeniCari, $user['kullanici_id'], 'İhbar sayfasından cari güncellendi');
                } else {
                    $db->execute("
                        INSERT INTO Cari (
                            cari_adi, cari_unvan, cari_telefon, cari_adres,
                            cari_ulke, cari_sehirler, cari_ilceler,
                            cari_tipi_id, cari_aktif, cari_musteri, cari_tedarikci,
                            cari_olusturan_kullanici, cari_olusturma_tarihi
                        ) VALUES (?, '', ?, ?, 1, ?, ?, ?, 1, 1, 0, ?, GETDATE())
                    ", [$isletmeAdi, $telefon, $adres, $sehirId, $ilceId, IHBAR_CARI_TIPI_ID, $user['kullanici_id']]);

                    $newCari = $db->fetchOne("SELECT TOP 1 cari_id FROM Cari ORDER BY cari_id DESC");
                    $cariId = intval($newCari['cari_id'] ?? 0);
                    if (!$cariId) {
                        echo json_encode(['success' => false, 'message' => 'Cari oluşturulamadı!']);
                        exit;
                    }
                    $yeniCari = $db->fetchOne("SELECT * FROM Cari WHERE cari_id = ?", [$cariId]);
                    logKayitDegisiklikleri($db, 'ihbar', 'Cari', $cariId, null, $yeniCari, $user['kullanici_id'], 'İhbar sayfasından yeni cari oluşturuldu');
                }

                // ── 2. FOTOĞRAFLAR ──
                $gorseller = [];
                $izinliGorsel = ['jpg', 'jpeg', 'png', 'webp'];
                if (!empty($_FILES['gorseller']['name'][0])) {
                    foreach ($_FILES['gorseller']['tmp_name'] as $key => $tmpName) {
                        if ($_FILES['gorseller']['error'][$key] !== UPLOAD_ERR_OK) continue;
                        $ext = strtolower(pathinfo($_FILES['gorseller']['name'][$key], PATHINFO_EXTENSION));
                        if (!in_array($ext, $izinliGorsel)) continue;
                        $newName = uniqid('ihbar_') . '_' . $key . '.' . $ext;
                        if (move_uploaded_file($tmpName, $uploadDir . $newName)) {
                            $gorseller[] = $uploadWebDir . $newName;
                        }
                    }
                }
                $gorsellerJson = !empty($gorseller) ? json_encode($gorseller, JSON_UNESCAPED_UNICODE) : null;

                // ── 3. TUTANAK EVRAĞI ──
                $tutanakDosya = null;
                $izinliTutanak = ['pdf', 'jpg', 'jpeg', 'png', 'webp'];
                if (!empty($_FILES['tutanak']['name']) && $_FILES['tutanak']['error'] === UPLOAD_ERR_OK) {
                    $ext = strtolower(pathinfo($_FILES['tutanak']['name'], PATHINFO_EXTENSION));
                    if (in_array($ext, $izinliTutanak)) {
                        $newName = uniqid('tutanak_') . '.' . $ext;
                        if (move_uploaded_file($_FILES['tutanak']['tmp_name'], $uploadDir . $newName)) {
                            $tutanakDosya = $uploadWebDir . $newName;
                        }
                    }
                }

                // Seçilen maç açıklamanın başına yazılır (lig + takımlar)
                if ($fiksturId) {
                    $mac = $db->fetchOne("
                        SELECT SporFikstur_LigAdi, SporFikstur_EvSahibi, SporFikstur_Deplasman,
                               CONVERT(VARCHAR(16), SporFikstur_MacTarihi, 120) AS mac_tarihi
                        FROM dbo.SporFikstur
                        WHERE SporFikstur_Id = ? AND Durum = 1
                    ", [$fiksturId]);

                    if ($mac) {
                        $macMetin = 'MAÇ: ' . $mac['SporFikstur_EvSahibi'] . ' - ' . $mac['SporFikstur_Deplasman']
                                  . ' (' . $mac['SporFikstur_LigAdi'] . ')';
                        if (!empty($mac['mac_tarihi'])) {
                            $macMetin .= ' ' . substr($mac['mac_tarihi'], 11, 5);
                        }
                        $aciklama = $aciklama !== '' ? $macMetin . "\n" . $aciklama : $macMetin;
                    }
                }

                // Tespit türü: tutanak yüklendiyse imzasız tutanak, değilse ihbar konum
                $tespitTuru = $tutanakDosya ? 'İMZASIZ TUTANAK' : 'İHBAR KONUM';

                // Statü ihbar aşamasında atanmaz; hukuk ekibi form.php üzerinden belirler.
                $statuId = null;

                // ── 4. HUKUK TAKİP ──
                $takipResult = $db->execute("
                    INSERT INTO HukukTakip (
                        takip_cari_id, takip_taraf_id, takip_statu_id, takip_dosya_no, takip_talimat_dosya_no, takip_aciklama,
                        takip_tespit_turu, takip_tespit_tarihi,
                        takip_ana_tutar, takip_Isleyen_Faiz, takip_Islemis_Faiz,
                        takip_Vekalet_Ucreti, takip_Masraf, takip_Tahsil_Harci,
                        takip_enlem, takip_boylam, takip_konum_dogruluk, takip_konum_tarihi,
                        takip_gorseller, takip_tutanak_dosya,
                        Durum, OlusturanKullanici, OlusturmaTarihi
                    ) VALUES (?, ?, ?, '', '', ?, ?, CAST(GETDATE() AS DATE), 0, 0, 0, 0, 0, 0, ?, ?, ?, GETDATE(), ?, ?, 1, ?, GETDATE())
                ", [
                    $cariId, $tarafId, $statuId, $aciklama, $tespitTuru,
                    $enlem, $boylam, $dogruluk,
                    $gorsellerJson, $tutanakDosya,
                    $user['kullanici_id']
                ]);

                if (!$takipResult) {
                    $errors = sqlsrv_errors();
                    $errMsg = $errors ? $errors[0]['message'] : 'Bilinmeyen hata';
                    echo json_encode(['success' => false, 'message' => 'Takip kaydı oluşturulamadı: ' . $errMsg]);
                    exit;
                }

                $newTakip = $db->fetchOne("SELECT TOP 1 takip_id FROM HukukTakip WHERE takip_cari_id = ? ORDER BY takip_id DESC", [$cariId]);
                $takipId = intval($newTakip['takip_id'] ?? 0);
                if (!$takipId) {
                    echo json_encode(['success' => false, 'message' => 'Takip kaydı okunamadı!']);
                    exit;
                }
                $yeniTakip = $db->fetchOne("SELECT * FROM HukukTakip WHERE takip_id = ?", [$takipId]);
                logKayitDegisiklikleri($db, 'ihbar', 'HukukTakip', $takipId, null, $yeniTakip, $user['kullanici_id'], 'İhbar sayfasından yeni takip oluşturuldu');

                // ── 5. SÖZLEŞME (sezon bilgisi burada tutulur) ──
                // Suç duyurusu listesinde sezon, cariye bağlı son sözleşmeden türetilir.
                $mevcutSozlesme = $db->fetchOne("
                    SELECT TOP 1 sozlesme_id
                    FROM Sozlesmeler
                    WHERE sozlesme_cari_id = ? AND sozlesme_sezon_id = ?
                    ORDER BY sozlesme_id DESC
                ", [$cariId, $sezonId]);

                if (!$mevcutSozlesme) {
                    $sozlesmeResult = $db->execute("
                        INSERT INTO Sozlesmeler (
                            sozlesme_sezon_id, sozlesme_tarih, sozlesme_cari_id,
                            sozlesme_no, sozlesme_aciklama, sozlesme_personel_id,
                            sozlesme_fatura_no, sozlesme_fatura_dosya, sozlesme_dosyalar,
                            sozlesme_kullanici_id, sozlesme_olusturma_tarihi, sozlesme_durum
                        ) VALUES (?, CAST(GETDATE() AS DATE), ?, '', ?, ?, '', '', '[]', ?, GETDATE(), 1)
                    ", [
                        $sezonId, $cariId, 'İhbar kaydı (takip #' . $takipId . ')',
                        $user['kullanici_id'],   // Personel: ihbarı giren saha personeli
                        $user['kullanici_id']
                    ]);

                    if (!$sozlesmeResult) {
                        $errors = sqlsrv_errors();
                        $errMsg = $errors ? $errors[0]['message'] : 'Bilinmeyen hata';
                        echo json_encode([
                            'success' => false,
                            'message' => 'İhbar kaydedildi (Takip #' . $takipId . ') ancak sezon kaydı oluşturulamadı: ' . $errMsg
                        ]);
                        exit;
                    }

                    $newSozlesme = $db->fetchOne("SELECT TOP 1 sozlesme_id FROM Sozlesmeler WHERE sozlesme_cari_id = ? ORDER BY sozlesme_id DESC", [$cariId]);
                    if ($newSozlesme) {
                        $yeniSozlesme = $db->fetchOne("SELECT * FROM Sozlesmeler WHERE sozlesme_id = ?", [$newSozlesme['sozlesme_id']]);
                        logKayitDegisiklikleri($db, 'ihbar', 'Sozlesmeler', $newSozlesme['sozlesme_id'], null, $yeniSozlesme, $user['kullanici_id'], 'İhbar sayfasından sezon kaydı oluşturuldu');
                    }
                }

                // ── 6. WHATSAPP GRUP BİLDİRİMİ ──
                // Gönderim hatası ihbar kaydını etkilemez; hata WhatsappMesajlar tablosuna düşer.
                ihbarWhatsappBildir($db, [
                    'takip_id'     => $takipId,
                    'isletme'      => $isletmeAdi,
                    'sehir_id'     => $sehirId,
                    'ilce_id'      => $ilceId,
                    'adres'        => $adres,
                    'tespit_turu'  => $tespitTuru,
                    'enlem'        => $enlem,
                    'boylam'       => $boylam,
                    'gorsel_adet'  => count($gorseller),
                    'tutanak_var'  => (bool)$tutanakDosya,
                    'bildiren'     => $user['name'] ?? '',
                ]);

                echo json_encode([
                    'success'  => true,
                    'message'  => 'İhbar kaydedildi!',
                    'takip_id' => $takipId,
                    'detay_url' => '/admin/form?cari_tipi_id=' . IHBAR_CARI_TIPI_ID . '&id=' . $takipId
                ]);
                break;

            default:
                echo json_encode(['success' => false, 'message' => 'Geçersiz işlem!']);
        }
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'message' => 'Hata: ' . $e->getMessage()]);
    }
    exit;
}

// ─────────────────────────────────────────────
// Sayfa verileri
// ─────────────────────────────────────────────
$pageInfo = $db->fetchOne("
    SELECT s.sayfalar_sayfa_adi, s.sayfalar_aciklama, m.menuler_menu_adi as menu_adi
    FROM Menu_Sayfalar s
    LEFT JOIN Menuler m ON s.sayfalar_menu_id = m.menuler_id
    WHERE s.sayfalar_sayfa_url LIKE ? AND s.sayfalar_durum = 1
", ['%' . $currentPageFile]);

$pageTitle = $pageInfo['sayfalar_sayfa_adi'] ?? 'İhbar';
$pageDescription = $pageInfo['sayfalar_aciklama'] ?? '';
$menuAdi = $pageInfo['menu_adi'] ?? null;

$siteAyarlari = $db->fetchOne("SELECT TOP 1 site_ayarlari_site_title FROM dbo.tanim_site_ayarlari ORDER BY site_ayarlari_id DESC");
$siteTitle = $siteAyarlari['site_ayarlari_site_title'] ?? 'Portal';

// Sezon kullanıcıya sorulmaz: varsayılan işaretli sezon, yoksa en yeni aktif sezon kullanılır.
$varsayilanSezon = $db->fetchOne("SELECT TOP 1 sezon_id, sezon_ad FROM Sozlesme_Sezonlar WHERE sezon_durum = 1 ORDER BY sezon_varsayilan DESC, sezon_ad DESC");
$sehirler = $db->fetchAll("SELECT SehirId, SehirAdi FROM Adres_Sehirler WHERE UlkeId = 1 ORDER BY SehirAdi");

$ihbarTaraflar = $db->fetchAll("
    SELECT taraf_id, taraf_ad, taraf_renk, taraf_yazi_renk
    FROM HukukTaraflar
    WHERE Durum = 1 AND taraf_id IN (" . implode(',', IHBAR_TARAF_IDLERI) . ")
    ORDER BY taraf_id
");

// Bugünün maçları (fikstür seçimi)
$bugunMaclar = $db->fetchAll("
    SELECT
        SporFikstur_Id,
        SporFikstur_LigAdi,
        SporFikstur_EvSahibi,
        SporFikstur_Deplasman,
        CONVERT(VARCHAR(5), SporFikstur_MacTarihi, 108) AS mac_saati
    FROM dbo.SporFikstur
    WHERE Durum = 1
      AND CAST(SporFikstur_MacTarihi AS DATE) = CAST(GETDATE() AS DATE)
    ORDER BY SporFikstur_MacTarihi, SporFikstur_LigAdi
");

// Bugün bu kullanıcının girdiği ihbar sayısı (özet)
$bugunSayi = $db->fetchOne("
    SELECT COUNT(*) AS adet
    FROM HukukTakip
    WHERE OlusturanKullanici = ?
      AND CONVERT(date, OlusturmaTarihi) = CONVERT(date, GETDATE())
      AND takip_enlem IS NOT NULL
", [$user['kullanici_id']])['adet'] ?? 0;
?>
<!DOCTYPE html>
<html lang="tr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
    <title><?= htmlspecialchars($pageTitle) ?> - <?= htmlspecialchars($siteTitle) ?></title>

    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/@fontsource/source-sans-3@5.0.12/index.css" crossorigin="anonymous">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.13.1/font/bootstrap-icons.min.css" crossorigin="anonymous">
    <link rel="stylesheet" href="/Admin/assets/css/Adminlte.min.css">
    <link href="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css" rel="stylesheet" />
    <link href="https://cdn.jsdelivr.net/npm/select2-bootstrap-5-theme@1.3.0/dist/select2-bootstrap-5-theme.min.css" rel="stylesheet" />
    <link href="https://cdn.jsdelivr.net/npm/sweetalert2@11/dist/sweetalert2.min.css" rel="stylesheet">
    <link rel="stylesheet" href="/Admin/assets/css/custom.css">
    <style>
        /* Saha kullanımı: büyük dokunma alanları */
        .ihbar-form .form-control,
        .ihbar-form .form-select,
        .ihbar-form .select2-selection {
            min-height: 48px;
            font-size: 1rem;
        }
        .ihbar-form .select2-container--bootstrap-5 .select2-selection--single {
            min-height: 48px;
            display: flex;
            align-items: center;
        }
        .ihbar-form .form-label {
            font-weight: 600;
            margin-bottom: .35rem;
        }
        .ihbar-form textarea.form-control {
            min-height: 90px;
        }

        /* Konum şeridi */
        #konumSerit {
            border-radius: .5rem;
            padding: .85rem 1rem;
            margin-bottom: 1rem;
        }
        .konum-bekliyor { background: #fff3cd; border: 1px solid #ffe69c; color: #664d03; }
        .konum-hata     { background: #f8d7da; border: 1px solid #f1aeb5; color: #58151c; }
        .konum-tamam    { background: #d1e7dd; border: 1px solid #a3cfbb; color: #0a3622; }

        /* Konum alınana kadar formu kilitle */
        #formAlanlari.kilitli {
            opacity: .45;
            pointer-events: none;
            filter: grayscale(.6);
        }

        /* Fotoğraf önizleme */
        #fotoOnizleme {
            display: flex;
            flex-wrap: wrap;
            gap: .5rem;
            margin-top: .5rem;
        }
        .foto-kutu {
            position: relative;
            width: 88px;
            height: 88px;
            border-radius: .5rem;
            overflow: hidden;
            border: 1px solid #dee2e6;
        }
        .foto-kutu img { width: 100%; height: 100%; object-fit: cover; }
        .foto-kutu .foto-sil {
            position: absolute;
            top: 2px; right: 2px;
            width: 24px; height: 24px;
            border: none;
            border-radius: 50%;
            background: rgba(0,0,0,.6);
            color: #fff;
            line-height: 1;
            font-size: .8rem;
        }
        .foto-ekle-btn {
            width: 88px; height: 88px;
            border: 2px dashed #adb5bd;
            border-radius: .5rem;
            background: #f8f9fa;
            color: #6c757d;
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            gap: .2rem;
            font-size: .75rem;
        }

        /* Sabit kaydet butonu */
        #kaydetCubugu {
            position: sticky;
            bottom: 0;
            background: #fff;
            border-top: 1px solid #dee2e6;
            padding: .75rem 0 calc(.75rem + env(safe-area-inset-bottom));
            margin-top: 1rem;
            z-index: 20;
        }
        #btnKaydet {
            min-height: 56px;
            font-size: 1.1rem;
            font-weight: 600;
        }

        /* Yayın hakkı seçimi: dar ekranda alt alta sarar, dokunma alanı büyük */
        .taraf-secim {
            display: flex;
            flex-wrap: wrap;
            gap: .5rem;
        }
        /* Renkler HukukTaraflar.taraf_renk / taraf_yazi_renk kolonlarından gelir,
           label üzerinde --taraf-renk / --taraf-yazi CSS değişkenleri olarak basılır. */
        .taraf-secim .btn-taraf {
            flex: 1 1 30%;
            min-height: 52px;
            font-weight: 600;
            border: 2px solid var(--taraf-renk);
            border-radius: .375rem;
            background: color-mix(in srgb, var(--taraf-renk) 12%, #fff);
            color: var(--taraf-renk);
            transition: background-color .15s ease, color .15s ease;
        }
        .taraf-secim .btn-taraf:hover {
            background: color-mix(in srgb, var(--taraf-renk) 25%, #fff);
        }
        .btn-check:checked + .btn-taraf {
            background: var(--taraf-renk);
            color: var(--taraf-yazi);
        }
        .btn-check:focus-visible + .btn-taraf {
            box-shadow: 0 0 0 .25rem color-mix(in srgb, var(--taraf-renk) 35%, transparent);
        }

        /* Cari kontrol sonuçları */
        #cariSonuc .list-group-item { cursor: pointer; }

        /* İhbar listesi kartları */
        .ihbar-kart {
            border: 1px solid #dee2e6;
            border-left: 4px solid #dc3545;
            border-radius: .5rem;
            background: #fff;
            padding: .85rem;
            margin-bottom: .75rem;
        }
        .ihbar-kart .kart-baslik {
            font-weight: 600;
            font-size: 1rem;
            line-height: 1.3;
        }
        .ihbar-kart .kart-satir {
            font-size: .875rem;
            color: #6c757d;
            margin-top: .2rem;
        }
        .ihbar-kart .kart-butonlar {
            display: flex;
            gap: .4rem;
            flex-wrap: wrap;
            margin-top: .6rem;
        }
        .ihbar-kart .kart-butonlar .btn {
            flex: 1 1 auto;
            min-height: 40px;
        }
        .ihbar-kart .kart-foto {
            display: flex;
            gap: .35rem;
            overflow-x: auto;
            margin-top: .6rem;
        }
        .ihbar-kart .kart-foto img {
            width: 64px;
            height: 64px;
            object-fit: cover;
            border-radius: .35rem;
            border: 1px solid #dee2e6;
            flex: 0 0 auto;
        }

        @media (max-width: 576px) {
            .app-content { padding-top: .5rem; }
            .ihbar-form .card-body { padding: .9rem; }
        }
    </style>
</head>
<body class="layout-fixed sidebar-expand-lg bg-body-tertiary">
    <div class="app-wrapper">
        <?php include __DIR__ . '/../includes/header.php'; ?>
        <?php include __DIR__ . '/../includes/sidebar.php'; ?>

        <main class="app-main">
            <div class="app-content-header">
                <div class="container-fluid">
                    <div class="d-flex justify-content-between align-items-center">
                        <h3 class="mb-0"><i class="bi bi-geo-alt-fill text-danger"></i> <?= htmlspecialchars($pageTitle) ?></h3>
                        <div class="d-flex align-items-center gap-2">
                            <span class="badge text-bg-secondary">Bugün: <?= intval($bugunSayi) ?></span>
                            <button type="button" class="btn btn-sm btn-outline-secondary" data-bs-toggle="modal" data-bs-target="#yardimModal">
                                <i class="bi bi-question-circle"></i> Nasıl kullanılır?
                            </button>
                        </div>
                    </div>
                </div>
            </div>

            <div class="app-content">
                <div class="container-fluid" style="max-width: 720px;">

                    <!-- Sekmeler -->
                    <ul class="nav nav-pills nav-fill mb-3" id="ihbarSekme">
                        <li class="nav-item">
                            <button class="nav-link active" data-hedef="yeni" type="button">
                                <i class="bi bi-plus-circle"></i> Yeni İhbar
                            </button>
                        </li>
                        <li class="nav-item">
                            <button class="nav-link" data-hedef="liste" type="button">
                                <i class="bi bi-list-ul"></i> İhbarlar
                            </button>
                        </li>
                    </ul>

                    <!-- ══ LİSTE SEKMESİ ══ -->
                    <div id="sekmeListe" class="d-none">
                        <div class="input-group mb-2">
                            <span class="input-group-text"><i class="bi bi-search"></i></span>
                            <input type="text" class="form-control" id="listeArama" placeholder="İşletme, adres veya açıklamada ara...">
                        </div>
                        <?php if (empty($pagePermissions['can_view_own_records'])): ?>
                        <div class="form-check form-switch mb-3">
                            <input class="form-check-input" type="checkbox" role="switch" id="sadeceBenim" name="sadece_benim" value="1">
                            <label class="form-check-label" for="sadeceBenim">Sadece benim kayıtlarım</label>
                        </div>
                        <?php endif; ?>

                        <div id="listeOzet" class="small text-muted mb-2"></div>
                        <div id="listeKapsayici"></div>
                        <div class="text-center py-3">
                            <button type="button" class="btn btn-outline-secondary d-none" id="btnDahaFazla">
                                <i class="bi bi-arrow-down-circle"></i> Daha Fazla Yükle
                            </button>
                        </div>
                    </div>

                    <!-- ══ YENİ İHBAR SEKMESİ ══ -->
                    <div id="sekmeYeni">

                    <!-- Konum durumu -->
                    <div id="konumSerit" class="konum-bekliyor">
                        <div class="d-flex align-items-center gap-2">
                            <i class="bi bi-crosshair fs-4"></i>
                            <div class="flex-grow-1">
                                <div id="konumBaslik" class="fw-bold">Konum alınıyor...</div>
                                <div id="konumDetay" class="small">Lütfen konum iznini onaylayın.</div>
                            </div>
                            <button type="button" class="btn btn-sm btn-dark" id="btnKonumYenile">
                                <i class="bi bi-arrow-clockwise"></i>
                            </button>
                        </div>
                    </div>

                    <form id="ihbarForm" class="ihbar-form" enctype="multipart/form-data" autocomplete="off">
                        <input type="hidden" name="action" value="save">
                        <input type="hidden" name="cari_id" id="cari_id" value="0">
                        <input type="hidden" name="enlem" id="enlem" value="">
                        <input type="hidden" name="boylam" id="boylam" value="">
                        <input type="hidden" name="dogruluk" id="dogruluk" value="">

                        <div id="formAlanlari" class="kilitli">

                            <!-- İşletme -->
                            <div class="card card-outline card-primary mb-3">
                                <div class="card-header py-2">
                                    <h3 class="card-title fs-6 mb-0"><i class="bi bi-shop"></i> İşletme</h3>
                                </div>
                                <div class="card-body">
                                    <div class="mb-3">
                                        <label class="form-label" for="isletme_adi">İşletme Adı <span class="text-danger">*</span></label>
                                        <input type="text" class="form-control" id="isletme_adi" name="isletme_adi" required>
                                        <div id="cariDurum" class="small text-muted mt-1"></div>
                                        <div id="cariSonuc" class="list-group mt-2 d-none"></div>
                                        <div id="cariSecili" class="alert alert-info py-2 px-3 mt-2 d-none">
                                            <div class="d-flex justify-content-between align-items-center">
                                                <span class="small"><i class="bi bi-link-45deg"></i> Mevcut cariye bağlanıyor: <b id="cariSeciliAd"></b></span>
                                                <button type="button" class="btn btn-sm btn-outline-secondary" id="btnCariKaldir">Yeni Aç</button>
                                            </div>
                                        </div>
                                    </div>

                                    <div class="row g-3">
                                        <div class="col-6">
                                            <label class="form-label" for="sehir_id">İl <span class="text-danger">*</span></label>
                                            <select class="form-select" id="sehir_id" name="sehir_id" required>
                                                <option value="">Seçiniz...</option>
                                                <?php foreach ($sehirler as $sehir): ?>
                                                    <option value="<?= $sehir['SehirId'] ?>"><?= htmlspecialchars($sehir['SehirAdi']) ?></option>
                                                <?php endforeach; ?>
                                            </select>
                                        </div>
                                        <div class="col-6">
                                            <label class="form-label" for="ilce_id">İlçe</label>
                                            <select class="form-select" id="ilce_id" name="ilce_id">
                                                <option value="">Önce il seçin</option>
                                            </select>
                                        </div>
                                        <div class="col-12">
                                            <label class="form-label" for="adres">Açık Adres <span class="text-danger">*</span></label>
                                            <textarea class="form-control" id="adres" name="adres" rows="2" required></textarea>
                                            <div id="adresDurum" class="form-text"></div>
                                        </div>
                                        <div class="col-12">
                                            <label class="form-label" for="telefon">Telefon</label>
                                            <input type="tel" class="form-control" id="telefon" name="telefon" inputmode="tel" placeholder="0501 111 11 11">
                                            <div id="telefonOnizleme" class="form-text"></div>
                                        </div>
                                    </div>
                                </div>
                            </div>

                            <!-- Tespit -->
                            <div class="card card-outline card-danger mb-3">
                                <div class="card-header py-2">
                                    <h3 class="card-title fs-6 mb-0"><i class="bi bi-camera-fill"></i> Tespit</h3>
                                </div>
                                <div class="card-body">
                                    <input type="hidden" id="sezon_id" name="sezon_id" value="<?= (int)($varsayilanSezon['sezon_id'] ?? 0) ?>">

                                    <div class="mb-3">
                                        <label class="form-label" for="fikstur_id">
                                            Maç <span class="text-muted small">(bugünkü fikstür)</span>
                                        </label>
                                        <?php if (empty($bugunMaclar)): ?>
                                            <div class="alert alert-light border py-2 px-3 mb-0 small">
                                                <i class="bi bi-calendar-x"></i> Bugün için fikstürde maç yok.
                                            </div>
                                            <input type="hidden" name="fikstur_id" id="fikstur_id" value="">
                                        <?php else: ?>
                                            <select class="form-select" id="fikstur_id" name="fikstur_id">
                                                <option value="">Seçiniz...</option>
                                                <?php foreach ($bugunMaclar as $mac): ?>
                                                    <option value="<?= $mac['SporFikstur_Id'] ?>">
                                                        <?= htmlspecialchars(
                                                            ($mac['mac_saati'] ? $mac['mac_saati'] . ' · ' : '') .
                                                            $mac['SporFikstur_EvSahibi'] . ' - ' . $mac['SporFikstur_Deplasman'] .
                                                            ' (' . $mac['SporFikstur_LigAdi'] . ')'
                                                        ) ?>
                                                    </option>
                                                <?php endforeach; ?>
                                            </select>
                                            <div class="form-text">Seçilen maç ve lig, açıklamanın başına eklenir.</div>
                                        <?php endif; ?>
                                    </div>

                                    <?php if (!empty($ihbarTaraflar)): ?>
                                    <div class="mb-3">
                                        <label class="form-label">Yayın Hakkı <span class="text-danger">*</span></label>
                                        <div class="taraf-secim" role="group" aria-label="Yayın hakkı sahibi">
                                            <?php foreach ($ihbarTaraflar as $t): ?>
                                                <input type="radio" class="btn-check" name="taraf_id"
                                                       id="taraf_<?= $t['taraf_id'] ?>" value="<?= $t['taraf_id'] ?>" autocomplete="off">
                                                <?php
                                                // Buton üzerinde yalnızca yayıncı adı yazar:
                                                // "ÖRNEK/YAYINPLATFORMU" -> "YAYINPLATFORMU"
                                                $tarafParcalari = explode('/', $t['taraf_ad']);
                                                $tarafEtiketi = trim(end($tarafParcalari));

                                                // Renk DB'den gelir; tanımsızsa nötr gri kullanılır.
                                                $tarafRenk = preg_match('/^#[0-9A-Fa-f]{6}$/', (string)($t['taraf_renk'] ?? '')) ? $t['taraf_renk'] : '#6c757d';
                                                $tarafYazi = preg_match('/^#[0-9A-Fa-f]{6}$/', (string)($t['taraf_yazi_renk'] ?? '')) ? $t['taraf_yazi_renk'] : '#ffffff';
                                                ?>
                                                <label class="btn btn-taraf" for="taraf_<?= $t['taraf_id'] ?>"
                                                       style="--taraf-renk: <?= $tarafRenk ?>; --taraf-yazi: <?= $tarafYazi ?>;">
                                                    <?= htmlspecialchars($tarafEtiketi !== '' ? $tarafEtiketi : $t['taraf_ad']) ?>
                                                </label>
                                            <?php endforeach; ?>
                                        </div>
                                    </div>
                                    <?php endif; ?>

                                    <div class="mb-3">
                                        <label class="form-label">Fotoğraflar</label>
                                        <!-- Kamera: capture ile doğrudan kamerayı açar · Galeri: capture yok, cihaz galerisi -->
                                        <input type="file" id="fotoInputKamera" accept="image/*" multiple capture="environment" class="d-none">
                                        <input type="file" id="fotoInputGaleri" accept="image/*" multiple class="d-none">
                                        <div id="fotoOnizleme">
                                            <button type="button" class="foto-ekle-btn" id="btnFotoKamera">
                                                <i class="bi bi-camera fs-4"></i>
                                                <span>Kamera</span>
                                            </button>
                                            <button type="button" class="foto-ekle-btn" id="btnFotoGaleri">
                                                <i class="bi bi-images fs-4"></i>
                                                <span>Galeri</span>
                                            </button>
                                        </div>
                                        <div class="form-text">Fotoğraflar gönderilmeden önce otomatik küçültülür.</div>
                                    </div>

                                    <div class="mb-3">
                                        <label class="form-label" for="tutanak">Tutanak Evrağı <span class="text-muted small">(varsa)</span></label>
                                        <input type="file" class="form-control" id="tutanak" name="tutanak" accept=".pdf,image/*">
                                    </div>

                                    <div>
                                        <label class="form-label" for="aciklama">Açıklama</label>
                                        <textarea class="form-control" id="aciklama" name="aciklama" rows="3" placeholder="Tespit ile ilgili notlar..."></textarea>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <div id="kaydetCubugu">
                            <button type="submit" class="btn btn-danger w-100" id="btnKaydet" disabled>
                                <i class="bi bi-send-fill"></i> İHBARI KAYDET
                            </button>
                        </div>
                    </form>
                    </div><!-- /sekmeYeni -->
                </div>
            </div>
        </main>

        <?php include __DIR__ . '/../includes/footer.php'; ?>
    </div>

    <!-- Kullanım rehberi -->
    <div class="modal fade" id="yardimModal" tabindex="-1">
        <div class="modal-dialog modal-dialog-scrollable modal-fullscreen-sm-down">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title"><i class="bi bi-question-circle"></i> İhbar Sayfası Nasıl Kullanılır?</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">

                    <div class="alert alert-danger py-2 px-3">
                        <b>Telefonun konum (GPS) ayarı açık olmalı.</b><br>
                        <span class="small">Konum izni verilmeden form açılmaz, kaydet butonu çalışmaz.
                        Kaydı mutlaka tespitin yapıldığı yerde gir.</span>
                    </div>

                    <ol class="ps-3 mb-4" style="line-height:1.7">
                        <li class="mb-2">
                            <b>Konum iznini ver.</b> Sayfa açılınca tarayıcı konum sorar.
                            Üstteki şerit yeşile döndüğünde form açılır.
                        </li>
                        <li class="mb-2">
                            <b>İşletme adını yaz.</b> Üç harften sonra benzer kayıtlar listelenir.
                            Aynı işletme ise üzerine dokun — yeni cari açılmaz, mevcuda bağlanır.
                        </li>
                        <li class="mb-2">
                            <b>İl, ilçe, adres kendiliğinden dolar.</b> Yanlışsa üzerine yaz;
                            otomatik gelen bilgi senin yazdığının üstüne geçmez.
                        </li>
                        <li class="mb-2">
                            <b>Telefon</b> nasıl yazılırsa yazılsın aynı biçimde kaydedilir. Zorunlu değil.
                        </li>
                        <li class="mb-2">
                            <b>Sezon hazır gelir.</b> Maç listesinde yalnızca bugünkü maçlar var;
                            seçtiğin maç ve lig açıklamanın başına yazılır.
                        </li>
                        <li class="mb-2">
                            <b>Fotoğraf çek.</b> Birden fazla ekleyebilirsin, yanlış olanı çarpıyla sil.
                            Tutanak varsa ayrı alandan yükle (PDF veya fotoğraf).
                        </li>
                        <li>
                            <b>Açıklamayı yaz ve kaydet.</b> Kayıt sonrası takip numarası görünür.
                        </li>
                    </ol>

                    <h6 class="fw-bold">Takıldığın yerler</h6>
                    <dl class="small mb-4">
                        <dt>Konum alınamadı, form açılmıyor</dt>
                        <dd class="text-muted">Telefonun konum ayarını aç, şeritteki yenile düğmesine bas.
                            Bina içinde GPS geç yakalar, açık alana çık.</dd>

                        <dt>"Konum izni reddedildi"</dt>
                        <dd class="text-muted">
                            <b>Android:</b> adres çubuğundaki kilit → İzinler → Konum → İzin Ver, sayfayı yenile.<br>
                            <b>iPhone:</b> Ayarlar → Safari → Konum → İzin Ver, sayfayı yenile.
                        </dd>

                        <dt>Adres otomatik gelmedi</dt>
                        <dd class="text-muted">Kaydı engellemez. Elle gir; konum yine de doğru kaydedilir.</dd>

                        <dt>"Bağlantı hatası" çıktı</dt>
                        <dd class="text-muted">İnternet zayıf. Yazdıkların ekranda duruyor, çekim gelince tekrar kaydet.</dd>

                        <dt>Adres komşu sokağı gösteriyor</dt>
                        <dd class="text-muted">Adres haritadan tahmin ediliyor. Elle düzelt; koordinat zaten doğru.</dd>
                    </dl>

                    <h6 class="fw-bold">Kısa kurallar</h6>
                    <ul class="small text-muted mb-0" style="line-height:1.7">
                        <li>Zorunlu alanlar: konum, işletme adı, il, açık adres.</li>
                        <li>Aynı işletmeyi ikinci kez girmeden benzer kayıtları kontrol et.</li>
                        <li>Ekran görünecek şekilde en az bir fotoğraf çek.</li>
                        <li>Statüyü sen belirlemiyorsun; kaydı hukuk ekibi devralır.</li>
                    </ul>

                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Kapat</button>
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/jquery@3.7.1/dist/jquery.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/overlayscrollbars@2.10.1/browser/overlayscrollbars.browser.es6.min.js"></script>
    <script src="/Admin/assets/js/Adminlte.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11/dist/sweetalert2.all.min.js"></script>
    <script>
    let konumTamam = false;
    let fotolar = [];          // { id, blob, url }
    let fotoSayac = 0;
    let cariAramaZaman = null;
    let listeSayfa = 0;
    let listeYukleniyor = false;
    let listeAramaZaman = null;

    $(function () {
        $('#sehir_id, #ilce_id, select#fikstur_id').select2({
            theme: 'bootstrap-5',
            width: '100%',
            placeholder: 'Seçiniz...'
        });

        konumAl();

        $('#btnKonumYenile').on('click', konumAl);
        $('#btnFotoKamera').on('click', () => $('#fotoInputKamera').trigger('click'));
        $('#btnFotoGaleri').on('click', () => $('#fotoInputGaleri').trigger('click'));
        $('#fotoInputKamera, #fotoInputGaleri').on('change', fotoSecildi);
        $('#sehir_id').on('change', ilceleriYukle);
        $('#isletme_adi').on('input', cariKontrolTetikle);
        $('#telefon').on('input', telefonOnizle);
        $('#btnCariKaldir').on('click', cariSecimKaldir);
        $('#ihbarForm').on('submit', function (e) { e.preventDefault(); kaydet(); });

        // Sekmeler
        $('#ihbarSekme .nav-link').on('click', function () {
            const hedef = $(this).data('hedef');
            $('#ihbarSekme .nav-link').removeClass('active');
            $(this).addClass('active');
            $('#sekmeYeni').toggleClass('d-none', hedef !== 'yeni');
            $('#sekmeListe').toggleClass('d-none', hedef !== 'liste');
            if (hedef === 'liste' && listeSayfa === 0) listeYukle(true);
        });

        // Liste arama / filtre
        $('#listeArama').on('input', function () {
            clearTimeout(listeAramaZaman);
            listeAramaZaman = setTimeout(function () { listeYukle(true); }, 500);
        });
        $('#sadeceBenim').on('change', function () { listeYukle(true); });
        $('#btnDahaFazla').on('click', function () { listeYukle(false); });
    });

    // ── İhbar listesi ──
    function listeYukle(bastan) {
        if (listeYukleniyor) return;
        listeYukleniyor = true;

        if (bastan) {
            listeSayfa = 0;
            $('#listeKapsayici').html('<div class="text-center py-4 text-muted"><span class="spinner-border spinner-border-sm"></span> Yükleniyor...</div>');
        }

        const btn = $('#btnDahaFazla');
        btn.prop('disabled', true);

        $.post('', {
            action: 'liste',
            sayfa: listeSayfa + 1,
            arama: $('#listeArama').val() || '',
            sadece_benim: $('#sadeceBenim').is(':checked') ? 1 : 0
        }, function (res) {
            listeYukleniyor = false;
            btn.prop('disabled', false);

            if (!res.success) {
                $('#listeKapsayici').html('<div class="alert alert-danger">Liste alınamadı.</div>');
                return;
            }

            if (bastan) $('#listeKapsayici').empty();
            listeSayfa = res.sayfa;

            $('#listeOzet').text('Toplam ' + res.toplam + ' ihbar');

            if (!res.data.length && bastan) {
                $('#listeKapsayici').html('<div class="text-center py-4 text-muted"><i class="bi bi-inbox fs-1 d-block mb-2"></i>Kayıt bulunamadı.</div>');
                btn.addClass('d-none');
                return;
            }

            res.data.forEach(function (k) { $('#listeKapsayici').append(kartYap(k)); });
            btn.toggleClass('d-none', !res.devam_var);
        }, 'json').fail(function () {
            listeYukleniyor = false;
            btn.prop('disabled', false);
            $('#listeKapsayici').html('<div class="alert alert-danger">Bağlantı hatası. Tekrar deneyin.</div>');
        });
    }

    function kartYap(k) {
        const g = function (s) { return $('<div>').text(s == null ? '' : s).html(); };
        const yer = [k.IlceAdi, k.SehirAdi].filter(Boolean).join(' / ');

        let fotolarHtml = '';
        try {
            const liste = k.takip_gorseller ? JSON.parse(k.takip_gorseller) : [];
            if (liste.length) {
                fotolarHtml = '<div class="kart-foto">' + liste.map(function (yol) {
                    const url = '/' + String(yol).replace(/^\/+/, '');
                    return '<a href="' + g(url) + '" target="_blank"><img src="' + g(url) + '" alt=""></a>';
                }).join('') + '</div>';
            }
        } catch (e) { /* bozuk JSON: fotoğraf gösterilmez */ }

        const maps = 'https://www.google.com/maps?q=' + k.takip_enlem + ',' + k.takip_boylam;

        return '' +
            '<div class="ihbar-kart">' +
                '<div class="kart-baslik">#' + k.takip_id + ' &middot; ' + g(k.cari_adi) + '</div>' +
                (yer ? '<div class="kart-satir"><i class="bi bi-geo-alt"></i> ' + g(yer) + '</div>' : '') +
                (k.cari_adres ? '<div class="kart-satir">' + g(k.cari_adres) + '</div>' : '') +
                '<div class="kart-satir">' +
                    '<i class="bi bi-clock"></i> ' + g(k.olusturma_tarihi) +
                    (k.olusturan_adi ? ' &middot; ' + g(k.olusturan_adi) : '') +
                '</div>' +
                '<div class="kart-satir">' +
                    '<span class="badge text-bg-secondary">' + g(k.takip_tespit_turu || '-') + '</span> ' +
                    (k.statu_ad ? '<span class="badge text-bg-info">' + g(k.statu_ad) + '</span>' : '<span class="badge text-bg-light text-dark">Statü yok</span>') +
                    (k.takip_tutanak_dosya ? ' <span class="badge text-bg-dark"><i class="bi bi-paperclip"></i> Tutanak</span>' : '') +
                '</div>' +
                (k.takip_aciklama ? '<div class="kart-satir fst-italic">' + g(k.takip_aciklama) + '</div>' : '') +
                fotolarHtml +
                '<div class="kart-butonlar">' +
                    '<a class="btn btn-sm btn-outline-primary" href="' + maps + '" target="_blank"><i class="bi bi-map"></i> Haritada Aç</a>' +
                    (k.cari_telefon ? '<a class="btn btn-sm btn-outline-success" href="tel:' + g(k.cari_telefon) + '"><i class="bi bi-telephone"></i> Ara</a>' : '') +
                    '<a class="btn btn-sm btn-outline-secondary" href="/admin/form?cari_tipi_id=4&id=' + k.takip_id + '"><i class="bi bi-pencil"></i> Detay</a>' +
                '</div>' +
            '</div>';
    }

    // ── Konum ──
    function konumAl() {
        if (!navigator.geolocation) {
            konumHata('Cihaz konum desteklemiyor', 'Bu tarayıcı konum bilgisi veremiyor. Lütfen Chrome veya Safari kullanın.');
            return;
        }

        konumTamam = false;
        $('#btnKaydet').prop('disabled', true);
        $('#formAlanlari').addClass('kilitli');
        $('#konumSerit').removeClass('konum-hata konum-tamam').addClass('konum-bekliyor');
        $('#konumBaslik').text('Konum alınıyor...');
        $('#konumDetay').text('Lütfen konum iznini onaylayın.');

        navigator.geolocation.getCurrentPosition(
            function (pos) {
                const enlem = pos.coords.latitude.toFixed(6);
                const boylam = pos.coords.longitude.toFixed(6);
                const dogruluk = Math.round(pos.coords.accuracy || 0);

                $('#enlem').val(enlem);
                $('#boylam').val(boylam);
                $('#dogruluk').val(dogruluk);

                konumTamam = true;
                $('#konumSerit').removeClass('konum-bekliyor konum-hata').addClass('konum-tamam');
                $('#konumBaslik').html('<i class="bi bi-check-circle-fill"></i> Konum alındı');
                $('#konumDetay').html(enlem + ', ' + boylam + ' &middot; ±' + dogruluk + ' m');

                $('#formAlanlari').removeClass('kilitli');
                $('#btnKaydet').prop('disabled', false);

                adresCoz(enlem, boylam);
            },
            function (err) {
                let baslik = 'Konum alınamadı';
                let detay;
                if (err.code === err.PERMISSION_DENIED) {
                    detay = 'Konum izni reddedildi. Tarayıcı ayarlarından bu siteye konum izni verip yenileyin. (Android: adres çubuğundaki kilit > İzinler > Konum · iPhone: Ayarlar > Safari > Konum)';
                } else if (err.code === err.POSITION_UNAVAILABLE) {
                    detay = 'Konum servisi kapalı görünüyor. Telefonun GPS/Konum ayarını açıp tekrar deneyin.';
                } else {
                    detay = 'Konum çok uzun sürdü. Açık alana çıkıp tekrar deneyin.';
                }
                konumHata(baslik, detay);
            },
            { enableHighAccuracy: true, timeout: 20000, maximumAge: 0 }
        );
    }

    function konumHata(baslik, detay) {
        konumTamam = false;
        $('#enlem, #boylam, #dogruluk').val('');
        $('#konumSerit').removeClass('konum-bekliyor konum-tamam').addClass('konum-hata');
        $('#konumBaslik').html('<i class="bi bi-exclamation-triangle-fill"></i> ' + baslik);
        $('#konumDetay').text(detay);
        $('#formAlanlari').addClass('kilitli');
        $('#btnKaydet').prop('disabled', true);
    }

    // ── Konumdan adres doldurma ──
    // Kullanıcının elle girdiği değerlerin üzerine yazılmaz; sadece boş alanlar doldurulur.
    function adresCoz(enlem, boylam) {
        const bilgi = $('#adresDurum');
        bilgi.html('<span class="text-muted"><i class="bi bi-hourglass-split"></i> Konumdan adres bulunuyor...</span>');

        $.post('', { action: 'konum_coz', enlem: enlem, boylam: boylam }, function (res) {
            if (!res.success) {
                bilgi.html('<span class="text-muted">Adres otomatik bulunamadı, elle girin.</span>');
                return;
            }

            const cariSecili = parseInt($('#cari_id').val() || 0) > 0;

            // İl (elle seçilmemişse ve mevcut cariye bağlanılmıyorsa)
            if (res.sehir_id && !cariSecili && !$('#sehir_id').val()) {
                $('#sehir_id').val(res.sehir_id).trigger('change.select2');

                $.post('', { action: 'get_ilceler', sehir_id: res.sehir_id }, function (r2) {
                    let html = '<option value="">Seçiniz...</option>';
                    if (r2.success) {
                        r2.data.forEach(function (i) {
                            html += '<option value="' + i.ilceId + '"' + (i.ilceId == res.ilce_id ? ' selected' : '') + '>' + i.IlceAdi + '</option>';
                        });
                    }
                    $('#ilce_id').html(html).trigger('change.select2');
                }, 'json');
            }

            // Açık adres (boşsa)
            if (res.adres && !cariSecili && $('#adres').val().trim() === '') {
                $('#adres').val(res.adres);
            }

            const bulunan = [res.ilce_adi, res.il_adi].filter(Boolean).join(' / ');
            bilgi.html(bulunan
                ? '<span class="text-success"><i class="bi bi-geo"></i> Konumdan bulundu: <b>' + $('<div>').text(bulunan).html() + '</b> &middot; yanlışsa düzeltin</span>'
                : '<span class="text-muted">Adres otomatik bulunamadı, elle girin.</span>');
        }, 'json').fail(function () {
            bilgi.html('<span class="text-muted">Adres servisine ulaşılamadı, elle girin.</span>');
        });
    }

    // ── Telefon (PHP tarafındaki ihbarTelefonNormalize ile aynı kural) ──
    function telefonNormalize(deger) {
        let t = String(deger || '').replace(/\D/g, '');
        if (t === '') return '';
        if (t.indexOf('00') === 0) t = t.substring(2);
        t = t.replace(/^0+/, '');
        if (t.length > 10 && t.indexOf('90') === 0) t = t.substring(2);
        if (t.length > 10) t = t.slice(-10);
        return t.length === 10 ? '90' + t : '';
    }

    function telefonOnizle() {
        const ham = $('#telefon').val().trim();
        const kutu = $('#telefonOnizleme');
        if (ham === '') { kutu.empty(); return; }
        const t = telefonNormalize(ham);
        kutu.html(t
            ? '<span class="text-success">Kaydedilecek: <b>' + t + '</b></span>'
            : '<span class="text-danger">Numara 10 haneye tamamlanmadı, boş kaydedilecek.</span>');
    }

    // ── İlçeler ──
    function ilceleriYukle() {
        const sehirId = $('#sehir_id').val();
        const ilce = $('#ilce_id');
        ilce.html('<option value="">Yükleniyor...</option>').trigger('change.select2');

        if (!sehirId) {
            ilce.html('<option value="">Önce il seçin</option>').trigger('change.select2');
            return;
        }

        $.post('', { action: 'get_ilceler', sehir_id: sehirId }, function (res) {
            let html = '<option value="">Seçiniz...</option>';
            if (res.success) {
                res.data.forEach(function (i) {
                    html += '<option value="' + i.ilceId + '">' + i.IlceAdi + '</option>';
                });
            }
            ilce.html(html).trigger('change.select2');
        }, 'json');
    }

    // ── Cari kontrol ──
    function cariKontrolTetikle() {
        const q = $('#isletme_adi').val().trim();
        $('#cari_id').val(0);
        $('#cariSecili').addClass('d-none');

        clearTimeout(cariAramaZaman);
        if (q.length < 3) {
            $('#cariSonuc').addClass('d-none').empty();
            $('#cariDurum').text('');
            return;
        }

        $('#cariDurum').html('<i class="bi bi-hourglass-split"></i> Mevcut kayıtlar kontrol ediliyor...');
        cariAramaZaman = setTimeout(function () {
            $.post('', { action: 'cari_ara', arama: q }, function (res) {
                const kutu = $('#cariSonuc');
                kutu.empty();

                if (!res.success || !res.data.length) {
                    $('#cariDurum').html('<span class="text-success"><i class="bi bi-plus-circle"></i> Bu isimde kayıt yok, yeni cari açılacak.</span>');
                    kutu.addClass('d-none');
                    return;
                }

                $('#cariDurum').html('<span class="text-warning"><i class="bi bi-exclamation-circle"></i> ' + res.data.length + ' benzer kayıt bulundu. Aynı işletme ise seçin:</span>');
                res.data.forEach(function (c) {
                    const yer = [c.IlceAdi, c.SehirAdi].filter(Boolean).join(' / ');
                    const ad = c.cari_unvan && c.cari_unvan.trim() ? c.cari_unvan : c.cari_adi;
                    kutu.append(
                        '<button type="button" class="list-group-item list-group-item-action py-2"' +
                        ' data-id="' + c.cari_id + '"' +
                        ' data-ad="' + $('<div>').text(ad).html() + '"' +
                        ' data-sehir="' + (c.cari_sehirler || '') + '"' +
                        ' data-ilce="' + (c.cari_ilceler || '') + '"' +
                        ' data-adres="' + $('<div>').text(c.cari_adres || '').html() + '">' +
                        '<div class="fw-semibold">' + $('<div>').text(ad).html() + '</div>' +
                        '<div class="small text-muted">' + $('<div>').text(yer).html() + '</div>' +
                        '</button>'
                    );
                });
                kutu.removeClass('d-none');
                kutu.find('button').on('click', function () { cariSec($(this)); });
            }, 'json');
        }, 500);
    }

    function cariSec($btn) {
        const sehir = $btn.data('sehir');
        const ilce = $btn.data('ilce');

        $('#cari_id').val($btn.data('id'));
        $('#cariSeciliAd').text($btn.data('ad'));
        $('#cariSecili').removeClass('d-none');
        $('#cariSonuc').addClass('d-none');
        $('#cariDurum').html('<span class="text-info"><i class="bi bi-info-circle"></i> Mevcut cari seçildi, yeni cari açılmayacak.</span>');

        if ($('#adres').val().trim() === '' && $btn.data('adres')) {
            $('#adres').val($btn.data('adres'));
        }
        if (sehir) {
            $('#sehir_id').val(sehir).trigger('change.select2');
            $.post('', { action: 'get_ilceler', sehir_id: sehir }, function (res) {
                let html = '<option value="">Seçiniz...</option>';
                if (res.success) {
                    res.data.forEach(function (i) {
                        html += '<option value="' + i.ilceId + '"' + (i.ilceId == ilce ? ' selected' : '') + '>' + i.IlceAdi + '</option>';
                    });
                }
                $('#ilce_id').html(html).trigger('change.select2');
            }, 'json');
        }
    }

    function cariSecimKaldir() {
        $('#cari_id').val(0);
        $('#cariSecili').addClass('d-none');
        $('#cariDurum').html('<span class="text-success"><i class="bi bi-plus-circle"></i> Yeni cari açılacak.</span>');
    }

    // ── Fotoğraf ──
    // Seçilen dosyalar SIRAYLA işlenir. Hepsi aynı anda küçültülürse
    // telefonda bellek dolar ve tarayıcı sekmeyi kapatır.
    let fotoIsleniyor = false;
    const fotoKuyrugu = [];

    function fotoSecildi(e) {
        const dosyalar = Array.from(e.target.files || []);
        e.target.value = '';

        dosyalar.forEach(function (dosya) {
            if (dosya.type.startsWith('image/')) fotoKuyrugu.push(dosya);
        });
        kuyrugaDevam();
    }

    function kuyrugaDevam() {
        if (fotoIsleniyor) return;
        const dosya = fotoKuyrugu.shift();
        if (!dosya) return;

        fotoIsleniyor = true;
        kucult(dosya, function (blob) {
            fotoEkle(blob);
            fotoIsleniyor = false;
            kuyrugaDevam();
        });
    }

    function fotoEkle(blob) {
        const id = ++fotoSayac;
        const url = URL.createObjectURL(blob);
        fotolar.push({ id: id, blob: blob, url: url });

        const kutu = $(
            '<div class="foto-kutu" data-id="' + id + '">' +
            '<img src="' + url + '" alt="">' +
            '<button type="button" class="foto-sil">&times;</button>' +
            '</div>'
        );
        kutu.find('.foto-sil').on('click', function () {
            fotolar = fotolar.filter(function (f) { return f.id !== id; });
            URL.revokeObjectURL(url);
            kutu.remove();
        });
        $('#btnFotoKamera').before(kutu);
    }

    /**
     * Telefondan gelen büyük fotoğrafı 1600px'e küçültüp JPEG %75 yapar.
     *
     * Dosya base64'e (readAsDataURL) çevrilmez; blob URL ile okunur.
     * createImageBitmap destekleniyorsa görüntü doğrudan küçültülmüş
     * çözünürlükte çözülür, tam boy bitmap hiç belleğe alınmaz.
     */
    function kucult(dosya, tamam) {
        const maks = 1600;

        function olcek(g, y) {
            if (g <= maks && y <= maks) return [g, y];
            return g > y
                ? [maks, Math.round(y * maks / g)]
                : [Math.round(g * maks / y), maks];
        }

        function cizVeBitir(kaynak, g, y, temizle) {
            const c = document.createElement('canvas');
            c.width = g; c.height = y;
            c.getContext('2d').drawImage(kaynak, 0, 0, g, y);
            c.toBlob(function (blob) {
                if (temizle) temizle();
                c.width = 0; c.height = 0;          // canvas belleğini bırak
                tamam(blob || dosya);
            }, 'image/jpeg', 0.75);
        }

        if (typeof createImageBitmap === 'function') {
            createImageBitmap(dosya)
                .then(function (bmp) {
                    const [g, y] = olcek(bmp.width, bmp.height);
                    if (bmp.width === g && bmp.height === y) {
                        cizVeBitir(bmp, g, y, function () { bmp.close(); });
                        return;
                    }
                    // Ölçekli yeniden çözümleme: tam boy bitmap serbest bırakılır
                    return createImageBitmap(bmp, { resizeWidth: g, resizeHeight: y, resizeQuality: 'high' })
                        .then(function (kucukBmp) {
                            bmp.close();
                            cizVeBitir(kucukBmp, g, y, function () { kucukBmp.close(); });
                        })
                        .catch(function () {
                            cizVeBitir(bmp, g, y, function () { bmp.close(); });
                        });
                })
                .catch(function () { imgIleKucult(); });
            return;
        }

        imgIleKucult();

        // createImageBitmap yoksa blob URL üzerinden <img> ile küçült
        function imgIleKucult() {
            const url = URL.createObjectURL(dosya);
            const img = new Image();
            img.onload = function () {
                const [g, y] = olcek(img.naturalWidth, img.naturalHeight);
                cizVeBitir(img, g, y, function () {
                    URL.revokeObjectURL(url);
                    img.src = '';
                });
            };
            img.onerror = function () {
                URL.revokeObjectURL(url);
                tamam(dosya);
            };
            img.src = url;
        }
    }

    // ── Kaydet ──
    function kaydet() {
        if (!konumTamam) {
            Swal.fire({ icon: 'error', title: 'Konum yok', text: 'Konum alınmadan ihbar kaydedilemez.' });
            return;
        }
        if (!$('#isletme_adi').val().trim()) {
            Swal.fire({ icon: 'warning', title: 'Eksik bilgi', text: 'İşletme adı zorunludur.' });
            return;
        }
        if (!$('#sehir_id').val()) {
            Swal.fire({ icon: 'warning', title: 'Eksik bilgi', text: 'İl seçimi zorunludur.' });
            return;
        }
        if (!$('#adres').val().trim()) {
            Swal.fire({ icon: 'warning', title: 'Eksik bilgi', text: 'Açık adres zorunludur.' });
            return;
        }
        if ($('input[name="taraf_id"]').length && !$('input[name="taraf_id"]:checked').length) {
            Swal.fire({ icon: 'warning', title: 'Eksik bilgi', text: 'Yayın hakkı seçimi zorunludur.' });
            $('.taraf-secim')[0].scrollIntoView({ behavior: 'smooth', block: 'center' });
            return;
        }

        const fd = new FormData();
        fd.append('action', 'save');
        fd.append('cari_id', $('#cari_id').val());
        fd.append('isletme_adi', $('#isletme_adi').val().trim());
        fd.append('sehir_id', $('#sehir_id').val());
        fd.append('ilce_id', $('#ilce_id').val() || '');
        fd.append('adres', $('#adres').val().trim());
        fd.append('telefon', $('#telefon').val() || '');
        fd.append('sezon_id', $('#sezon_id').val() || '');
        fd.append('fikstur_id', $('#fikstur_id').val() || '');
        fd.append('taraf_id', $('input[name="taraf_id"]:checked').val() || '');
        fd.append('aciklama', $('#aciklama').val() || '');
        fd.append('enlem', $('#enlem').val());
        fd.append('boylam', $('#boylam').val());
        fd.append('dogruluk', $('#dogruluk').val() || 0);

        fotolar.forEach(function (f, i) {
            fd.append('gorseller[]', f.blob, 'foto_' + (i + 1) + '.jpg');
        });

        const tutanak = document.getElementById('tutanak').files[0];
        if (tutanak) fd.append('tutanak', tutanak);

        const btn = $('#btnKaydet');
        btn.prop('disabled', true).html('<span class="spinner-border spinner-border-sm"></span> Kaydediliyor...');

        $.ajax({
            url: '',
            type: 'POST',
            data: fd,
            processData: false,
            contentType: false,
            dataType: 'json'
        }).done(function (res) {
            if (res.success) {
                Swal.fire({
                    icon: 'success',
                    title: 'İhbar kaydedildi',
                    text: 'Takip No: ' + res.takip_id,
                    showCancelButton: true,
                    confirmButtonText: 'Yeni İhbar',
                    cancelButtonText: 'Kaydı Aç'
                }).then(function (r) {
                    if (r.isConfirmed) {
                        window.location.reload();
                    } else {
                        window.location.href = res.detay_url;
                    }
                });
            } else {
                Swal.fire({ icon: 'error', title: 'Kaydedilemedi', text: res.message || 'Bilinmeyen hata' });
                btn.prop('disabled', false).html('<i class="bi bi-send-fill"></i> İHBARI KAYDET');
            }
        }).fail(function () {
            Swal.fire({
                icon: 'error',
                title: 'Bağlantı hatası',
                text: 'İnternet bağlantınızı kontrol edip tekrar deneyin. Girdiğiniz bilgiler sayfada duruyor.'
            });
            btn.prop('disabled', false).html('<i class="bi bi-send-fill"></i> İHBARI KAYDET');
        });
    }
    </script>
</body>
</html>
