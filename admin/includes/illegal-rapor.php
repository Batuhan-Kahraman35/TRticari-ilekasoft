<?php
/**
 * Satılan / Satılmayan İllegaller Raporu - Ortak gövde
 *
 * Çağıran sayfa $illegalRaporSatildi değişkenini belirler:
 *   true  -> statü adında "SATILDI" geçen kayıtlar (Satılan İllegaller)
 *   false -> geçmeyenler; statüsü atanmamış kayıtlar dahil (Satılmayan İllegaller)
 *
 * İsteğe bağlı $illegalRaporStatuId verilirse rapor yalnız o statüdeki kayıtları
 * listeler (örn. Almanya İllegaller). Görünüm ve işlemler Satılmayan raporuyla aynıdır.
 *
 * Kayıtlar suç duyurusu carileridir (cari_tipi_id = 4). Ürün/hizmet ve fiyat
 * bilgisi carinin sözleşmesindeki stok hareketlerinden satır satır gelir.
 * Sezon HukukTakip üzerinde tutulmadığı için sözleşmeden türetilir.
 */

if (!isset($illegalRaporSatildi)) {
    $illegalRaporSatildi = true;
}

/** Tek statüye sabitlenmiş rapor (0 = kapalı) */
$illegalRaporStatuId = (int)($illegalRaporStatuId ?? 0);

/** Rapordan dışlanan statüler (örn. kendi sayfası olan Almayan İllegaller statüsü) */
$illegalRaporHaricStatuIdler = array_values(array_filter(array_map('intval', (array)($illegalRaporHaricStatuIdler ?? []))));
$haricStatuListe = implode(',', $illegalRaporHaricStatuIdler);

/** CSV dosya adı öneki; çağıran sayfa verebilir */
$illegalRaporDosyaOnek = $illegalRaporDosyaOnek
    ?? ($illegalRaporSatildi ? 'satilan-illegaller' : 'satilmayan-illegaller');

require_once __DIR__ . '/../auth.php';
require_once __DIR__ . '/PageAuth.php';
require_once __DIR__ . '/SmsHelper.php';
require_once __DIR__ . '/WhatsappBot.php';
requireAuth();

/**
 * Sorumluluk atamasi ve bildirim yalniz Satilmayan Illegaller raporunda acilir.
 * Satilan raporu ayni govdeyi kullandigi icin bu bayrakla ayrilir.
 */
$sorumlulukAktif = !$illegalRaporSatildi;

/** Sorumlu olarak atanabilecek kullanicilarin departmanlari (SAHA SATIS, MUDUR) */
const SORUMLU_DEPARTMANLARI = [10, 22];

/** Atama ve bildirim yetkisi olan departmanlar (Bilgi Islem, Mudur) */
const ATAMA_DEPARTMANLARI = [1, 22];

/** Bildirim loglarinin EntegrasyonLoglari icinde tanindigi konu basligi */
const BILDIRIM_KONUSU = 'Sorumluluk Alanı Bildirimi';

/** Musteriye giden bildirimin log konusu */
const MUSTERI_BILDIRIM_KONUSU = 'İllegal Müşteri Bildirimi';

/** Musteri mesaj metninin tanim_whatsapp_bot_mesajlari icindeki kodu */
const MUSTERI_MESAJ_KODU = 'ILLEGAL_MUSTERI_BILDIRIM';

/** Sorumlu atamasinda gonderilen mesajin log konusu */
const ATAMA_BILDIRIM_KONUSU = 'İllegal Sorumlu Atama Bildirimi';

/** Atama mesaj metninin tanim_whatsapp_bot_mesajlari icindeki kodu */
const ATAMA_MESAJ_KODU = 'ILLEGAL_SORUMLU_ATAMA';

/**
 * Atama bildiriminin cikacagi Evolution kanali (EntegrasyonKanallari.kanal_ad).
 * Bot akisindan bagimsizdir: bot kendi instance'ini WhatsappBotAyarlari'ndan
 * okur, bu bildirim dogrudan asagidaki kanaldan gider.
 */
const ATAMA_KANAL_ADI = 'BatuhanIS';

$user = Auth::user();
$db = Database::getInstance();

$currentPageFile = basename($_SERVER['PHP_SELF']);
$pagePermissions = PageAuth::checkPagePermissions(
    $user['kullanici_id'],
    $user['departman_id'],
    $currentPageFile
);

if (!$pagePermissions['has_access']) {
    PageAuth::accessDenied($pagePermissions['error'] ?? 'Bu sayfaya erişim yetkiniz bulunmamaktadır.');
}

// Aynı dosya birden fazla sezon menüsünde tanımlı olduğu için (örn. Raporlar 2026 / 2027)
// eşleştirme URL'deki sezon parametresiyle birlikte yapılır; böylece breadcrumb doğru menüyü gösterir.
$sayfaUrlAra = $currentPageFile;
if (isset($_GET['sezon_id']) && $_GET['sezon_id'] !== '') {
    $sayfaUrlAra .= '?sezon_id=' . (int)$_GET['sezon_id'];
}

$pageInfo = $db->fetchOne("
    SELECT TOP 1
        s.sayfalar_sayfa_adi,
        s.sayfalar_aciklama,
        m.menuler_menu_adi as menu_adi
    FROM Menu_Sayfalar s
    LEFT JOIN Menuler m ON s.sayfalar_menu_id = m.menuler_id
    WHERE s.sayfalar_sayfa_url LIKE ? AND s.sayfalar_durum = 1
    ORDER BY s.sayfalar_id
", ['%' . $sayfaUrlAra]);

$pageTitle = $pageInfo['sayfalar_sayfa_adi']
    ?? ($illegalRaporBaslik ?? ($illegalRaporSatildi ? 'Satılan İllegaller' : 'Satılmayan İllegaller'));
$pageDescription = $pageInfo['sayfalar_aciklama'] ?? '';
$menuAdi = $pageInfo['menu_adi'] ?? null;

$siteAyarlari = $db->fetchOne("SELECT TOP 1 site_ayarlari_site_title FROM dbo.tanim_site_ayarlari ORDER BY site_ayarlari_id DESC");
$siteTitle = $siteAyarlari['site_ayarlari_site_title'] ?? 'Portal';

// Suç duyurusu (illegal) carileri
$cariTipiId = 4;

// Satıldı ayrımı statü adı üzerinden yapılır; tek statülü raporda doğrudan statü id'si
if ($illegalRaporStatuId > 0) {
    $satildiKosul = " AND t.takip_statu_id = " . $illegalRaporStatuId;
} else {
    $satildiKosul = $illegalRaporSatildi
        ? " AND st.statu_ad LIKE '%SATILDI%'"
        : " AND (st.statu_ad IS NULL OR st.statu_ad NOT LIKE '%SATILDI%')";

    if ($haricStatuListe !== '') {
        $satildiKosul .= " AND ISNULL(t.takip_statu_id, 0) NOT IN (" . $haricStatuListe . ")";
    }
}

// "Kendi Kullanicisini Gor" yetkisi: kayıt sahibi veya carinin sözleşmesindeki personel
$sahiplikWhere = "";
$sahiplikParams = [];
if (!empty($pagePermissions['can_view_own_records'])) {
    $sahiplikWhere = " AND (t.OlusturanKullanici = ?
                            OR EXISTS (SELECT 1 FROM Sozlesmeler sp
                                       WHERE sp.sozlesme_cari_id = c.cari_id
                                         AND sp.sozlesme_personel_id = ?)
                            OR EXISTS (SELECT 1 FROM Sozlesmeler ss
                                       WHERE ss.sozlesme_cari_id = c.cari_id
                                         AND ss.sozlesme_sorumlu_kullanici_id = ?))";
    $sahiplikParams = [$user['kullanici_id'], $user['kullanici_id'], $user['kullanici_id']];
}

/** Atama / bildirim yetkisi */
$canAssign = $sorumlulukAktif
          && in_array((int)$user['departman_id'], ATAMA_DEPARTMANLARI, true);

/** Sorumlu olarak atanabilecek kullanicilar */
$sorumlular = $sorumlulukAktif ? $db->fetchAll("
    SELECT kullanici_id,
           kullanici_ad + ' ' + kullanici_soyad
             + CASE WHEN ISNULL(kullanici_durum, 0) = 1 THEN '' ELSE ' (Pasif)' END AS sorumlu_adi
    FROM kullanicilar
    WHERE kullanici_departman_id IN (" . implode(',', array_fill(0, count(SORUMLU_DEPARTMANLARI), '?')) . ")
    ORDER BY ISNULL(kullanici_durum, 0) DESC, kullanici_ad, kullanici_soyad
", SORUMLU_DEPARTMANLARI) : [];

/** 5xxxxxxxxx / 0532... -> 0 5xx xxx xx xx */
function illegalTelefonGoster(?string $t): string
{
    $r = preg_replace('/\D/', '', (string)$t);
    if ($r === '') return '';
    $r = ltrim($r, '0');
    if (strlen($r) === 12 && strpos($r, '90') === 0) $r = substr($r, 2);
    if (strlen($r) !== 10) return (string)$t;
    return '0' . substr($r, 0, 3) . ' ' . substr($r, 3, 3) . ' ' . substr($r, 6, 2) . ' ' . substr($r, 8, 2);
}

/** Aktif WhatsApp (Evolution) kanal id'si; log satirinda kullanilir */
function illegalWhatsappKanalId(Database $db): int
{
    $r = $db->fetchOne("
        SELECT TOP 1 k.kanal_id
        FROM EntegrasyonKanallari k
        INNER JOIN Entegrasyonlar e ON e.entegrasyon_id = k.kanal_entegrasyon_id
        WHERE e.entegrasyon_kod = 'whatsapp_evolution'
          AND e.entegrasyon_durum = 1 AND k.kanal_durum = 1
        ORDER BY k.kanal_id
    ");
    return (int)($r['kanal_id'] ?? 0);
}

/**
 * WhatsappBot kendi mesaj tablosuna yazar, EntegrasyonLoglari'na yazmaz.
 * Iki kanalin da Entegrasyon Loglari'ndan takip edilebilmesi icin burada loglanir.
 */
function illegalWhatsappLogla(Database $db, int $kanalId, string $hedef, string $mesaj, array $sonuc, int $kullaniciId, string $konu = BILDIRIM_KONUSU): void
{
    try {
        $db->insert('EntegrasyonLoglari', [
            'log_kanal_id'       => $kanalId,
            'log_tip'            => 'whatsapp',
            'log_alici'          => $hedef,
            'log_konu'           => $konu,
            'log_mesaj'          => $mesaj,
            'log_sonuc'          => $sonuc['success'] ? 'başarılı' : 'basarisiz',
            'log_hata'           => $sonuc['success'] ? null : mb_substr((string)$sonuc['message'], 0, 500),
            'OlusturanKullanici' => $kullaniciId,
            'OlusturmaTarihi'    => date('Y-m-d H:i:s'),
            'Durum'              => 1,
        ]);
    } catch (\Throwable $e) {
        // log yazilamazsa gonderimi bozma
    }
}

/**
 * Bot akisindan bagimsiz Evolution kanali. Kanal adi ATAMA_KANAL_ADI ile
 * eslesen kayittan instance kodu, baglantidan url / api anahtari okunur.
 * Instance kodu once kanal_kod alanindan, bos ise kanal adindan alinir.
 */
function illegalAtamaKanali(Database $db): ?array
{
    static $kanal = false;

    if ($kanal === false) {
        $row = $db->fetchOne("
            SELECT TOP 1
                k.kanal_id,
                ISNULL(NULLIF(LTRIM(RTRIM(k.kanal_kod)), ''), k.kanal_ad) AS instance,
                e.entegrasyon_url,
                e.entegrasyon_api_key
            FROM EntegrasyonKanallari k
            INNER JOIN Entegrasyonlar e ON e.entegrasyon_id = k.kanal_entegrasyon_id
            WHERE e.entegrasyon_kod = 'whatsapp_evolution'
              AND e.entegrasyon_durum = 1 AND k.kanal_durum = 1
              AND k.kanal_ad = ?
            ORDER BY k.kanal_id
        ", [ATAMA_KANAL_ADI]);

        $kanal = ($row && trim((string)$row['entegrasyon_url']) !== '' && trim((string)$row['instance']) !== '')
            ? [
                'kanal_id' => (int)$row['kanal_id'],
                'url'      => rtrim((string)$row['entegrasyon_url'], '/'),
                'key'      => (string)$row['entegrasyon_api_key'],
                'instance' => trim((string)$row['instance']),
              ]
            : null;
    }

    return $kanal;
}

/** Evolution sendText cagrisi; WhatsappBot ornegi olusturulmaz */
function illegalAtamaGonder(array $kanal, string $jid, string $mesaj): array
{
    $ch = curl_init($kanal['url'] . '/message/sendText/' . rawurlencode($kanal['instance']));
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => json_encode(['number' => $jid, 'text' => $mesaj], JSON_UNESCAPED_UNICODE),
        CURLOPT_HTTPHEADER     => ['apikey: ' . $kanal['key'], 'Content-Type: application/json'],
        CURLOPT_TIMEOUT        => 20,
    ]);

    $yanit = curl_exec($ch);
    $hata  = curl_error($ch);
    $kod   = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($yanit === false) {
        return ['success' => false, 'message' => 'Bağlantı hatası: ' . $hata];
    }

    if ($kod !== 200 && $kod !== 201) {
        return ['success' => false, 'message' => 'HTTP ' . $kod . ': ' . mb_substr((string)$yanit, 0, 300)];
    }

    return ['success' => true, 'message' => 'Gönderildi.'];
}

/**
 * Musteriye gidecek metin kodda tutulmaz; tanim_whatsapp_bot_mesajlari
 * tablosundaki ILLEGAL_MUSTERI_BILDIRIM kaydindan gelir.
 * Bos kalan yer tutuculardan geriye kalan bos parantez / fazla bosluk temizlenir.
 */
function illegalMusteriMetni(Database $db, array $degiskenler): string
{
    return illegalSablonMetni($db, MUSTERI_MESAJ_KODU, $degiskenler);
}

/** Kod bazli mesaj sablonu; yer tutucular {anahtar} bicimindedir */
function illegalSablonMetni(Database $db, string $kod, array $degiskenler): string
{
    static $sablonlar = [];

    if (!array_key_exists($kod, $sablonlar)) {
        $row = $db->fetchOne("
            SELECT TOP 1 tanim_whatsapp_bot_mesajlari_Metin AS m
            FROM dbo.tanim_whatsapp_bot_mesajlari
            WHERE tanim_whatsapp_bot_mesajlari_Kod = ? AND Durum = 1
        ", [$kod]);
        $sablonlar[$kod] = (string)($row['m'] ?? '');
    }

    $sablon = $sablonlar[$kod];
    if ($sablon === '') return '';

    $metin = $sablon;
    foreach ($degiskenler as $k => $v) {
        $metin = str_replace('{' . $k . '}', trim((string)$v), $metin);
    }

    $metin = preg_replace('/\(\s*\)/u', '', $metin);
    $metin = preg_replace('/[ \t]{2,}/u', ' ', $metin);
    $metin = preg_replace('/[ \t]+([,\.])/u', '$1', $metin);

    return trim($metin);
}

// Dropdown verileri
$statuler = $db->fetchAll("
    SELECT statu_id, statu_ad
    FROM HukukStatu
    WHERE Durum = 1 AND statu_cari_tipi_id = ?" .
    ($illegalRaporStatuId > 0
        ? " AND statu_id = " . $illegalRaporStatuId
        : ($illegalRaporSatildi ? " AND statu_ad LIKE '%SATILDI%'" : " AND statu_ad NOT LIKE '%SATILDI%'")
          . ($haricStatuListe !== '' ? " AND statu_id NOT IN (" . $haricStatuListe . ")" : "")) . "
    ORDER BY statu_sira
", [$cariTipiId]);

$sehirler = $db->fetchAll("SELECT SehirId, SehirAdi FROM Adres_Sehirler WHERE UlkeId = 1 ORDER BY SehirAdi");
$sezonlar = $db->fetchAll("SELECT sezon_id, sezon_ad, sezon_varsayilan FROM Sozlesme_Sezonlar WHERE sezon_durum = 1 ORDER BY sezon_ad DESC");
$urunler  = $db->fetchAll("SELECT urun_hizmet_id, urun_hizmet_adi FROM Urun_Hizmet WHERE urun_hizmet_durum = 1 ORDER BY urun_hizmet_adi");

// Varsayılan sezon: URL parametresi (menü sezon bağlamı), yoksa işaretli sezon, o da yoksa en güncel
$varsayilanSezonId = '';
if (isset($_GET['sezon_id']) && $_GET['sezon_id'] !== '') {
    $varsayilanSezonId = (string)(int)$_GET['sezon_id'];
} else {
    foreach ($sezonlar as $sz) {
        if (!empty($sz['sezon_varsayilan'])) { $varsayilanSezonId = (string)$sz['sezon_id']; break; }
    }
    if ($varsayilanSezonId === '' && !empty($sezonlar)) {
        $varsayilanSezonId = (string)$sezonlar[0]['sezon_id'];
    }
}

// Ortak FROM bloğu: ürün/hizmet satırları sözleşme stok hareketlerinden gelir
$baseFrom = "
    FROM HukukTakip t
    INNER JOIN Cari c ON t.takip_cari_id = c.cari_id
    LEFT JOIN HukukStatu st ON t.takip_statu_id = st.statu_id
    LEFT JOIN Adres_Sehirler s ON c.cari_sehirler = s.SehirId
    LEFT JOIN Adres_Ilceler il ON c.cari_ilceler = il.ilceId
    LEFT JOIN Sozlesmeler sz ON sz.sozlesme_cari_id = c.cari_id
    LEFT JOIN Sozlesme_Sezonlar sn ON sz.sozlesme_sezon_id = sn.sezon_id
    LEFT JOIN Sozlesme_StokHareketleri h ON h.hareket_sozlesme_id = sz.sozlesme_id
    LEFT JOIN Urun_Hizmet u ON h.hareket_urun_hizmet_id = u.urun_hizmet_id
    LEFT JOIN kullanicilar ks ON ks.kullanici_id = sz.sozlesme_sorumlu_kullanici_id
";

/**
 * POST filtrelerinden WHERE cümlesi ve parametre dizisi üretir.
 */
function illegalFiltreOlustur(array $post, $cariTipiId, $satildiKosul, $sahiplikWhere, array $sahiplikParams) {
    $where  = "WHERE c.cari_tipi_id = ?" . $satildiKosul . $sahiplikWhere;
    $params = array_merge([$cariTipiId], $sahiplikParams);

    $sezonId    = $post['sezon_id'] ?? '';
    $statuId    = $post['statu_id'] ?? '';
    $musteri    = $post['musteri'] ?? '';
    $sehirId    = $post['sehir_id'] ?? '';
    $ilceId     = $post['ilce_id'] ?? '';
    $urunId     = $post['urun_id'] ?? '';
    $tespitBas  = $post['tespit_bas'] ?? '';
    $tespitBit  = $post['tespit_bit'] ?? '';
    $sozBas     = $post['sozlesme_bas'] ?? '';
    $sozBit     = $post['sozlesme_bit'] ?? '';
    $sorumluId  = $post['sorumlu_id'] ?? '';

    if ($sezonId !== '')   { $where .= " AND sz.sozlesme_sezon_id = ?"; $params[] = $sezonId; }
    if ($statuId !== '')   { $where .= " AND t.takip_statu_id = ?"; $params[] = $statuId; }
    if ($musteri !== '')   { $where .= " AND (c.cari_adi LIKE ? OR c.cari_unvan LIKE ?)"; $params[] = "%$musteri%"; $params[] = "%$musteri%"; }
    if ($sehirId !== '')   { $where .= " AND c.cari_sehirler = ?"; $params[] = $sehirId; }
    if ($ilceId !== '')    { $where .= " AND c.cari_ilceler = ?"; $params[] = $ilceId; }
    if ($urunId !== '')    { $where .= " AND h.hareket_urun_hizmet_id = ?"; $params[] = $urunId; }
    if ($tespitBas !== '') { $where .= " AND CONVERT(date, t.takip_tespit_tarihi) >= ?"; $params[] = $tespitBas; }
    if ($tespitBit !== '') { $where .= " AND CONVERT(date, t.takip_tespit_tarihi) <= ?"; $params[] = $tespitBit; }
    if ($sozBas    !== '') { $where .= " AND CONVERT(date, sz.sozlesme_olusturma_tarihi) >= ?"; $params[] = $sozBas; }
    if ($sozBit    !== '') { $where .= " AND CONVERT(date, sz.sozlesme_olusturma_tarihi) <= ?"; $params[] = $sozBit; }

    if ($sorumluId === 'yok') {
        $where .= " AND sz.sozlesme_sorumlu_kullanici_id IS NULL";
    } elseif ($sorumluId !== '') {
        $where .= " AND sz.sozlesme_sorumlu_kullanici_id = ?";
        $params[] = $sorumluId;
    }

    return [$where, $params];
}

// Sıralama: istemciden gelen kolon indeksi whitelist ile çözülür.
// Satılmayan raporunda başa seçim kutusu, sona sözleşme tarihi ve sorumlu
// kolonları eklendiği için indeksler kayar; iki rapor ayrı haritalar kullanır.
$siralamaKolonlari = $sorumlulukAktif
    ? [
        1  => 'musteri_adi',
        2  => 'sehir_adi',
        3  => 'ilce_adi',
        4  => 'urun_adi',
        5  => 'fiyat',
        6  => 'statu_ad',
        7  => 'sezon_ad',
        8  => 'tespit_tarihi',
        9  => 'sz.sozlesme_olusturma_tarihi',
        10 => 'sorumlu_adi'
      ]
    : [
        0 => 'musteri_adi',
        1 => 'sehir_adi',
        2 => 'ilce_adi',
        3 => 'urun_adi',
        4 => 'fiyat',
        5 => 'statu_ad',
        6 => 'sezon_ad',
        7 => 'tespit_tarihi'
      ];

// Varsayılan sıralama: satılmayanda en yeni sözleşmeden en eskiye
$varsayilanSiralama = $sorumlulukAktif ? 'sz.sozlesme_olusturma_tarihi' : 'musteri_adi';

// AJAX İşlemleri
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action !== 'export_excel') {
        header('Content-Type: application/json');
    }

    try {
        switch ($action) {
            case 'stats':
                list($where, $params) = illegalFiltreOlustur($_POST, $cariTipiId, $satildiKosul, $sahiplikWhere, $sahiplikParams);

                $ozet = $db->fetchOne("
                    SELECT
                        COUNT(*) as satir_adet,
                        COUNT(DISTINCT t.takip_id) as kayit_adet,
                        COUNT(DISTINCT c.cari_id) as musteri_adet,
                        COUNT(DISTINCT NULLIF(c.cari_sehirler, 0)) as sehir_adet,
                        ISNULL(SUM(h.hareket_fiyat), 0) as toplam_tutar
                    $baseFrom
                    $where
                ", $params);

                echo json_encode(['success' => true, 'data' => [
                    'satir_adet'   => intval($ozet['satir_adet'] ?? 0),
                    'kayit_adet'   => intval($ozet['kayit_adet'] ?? 0),
                    'musteri_adet' => intval($ozet['musteri_adet'] ?? 0),
                    'sehir_adet'   => intval($ozet['sehir_adet'] ?? 0),
                    'toplam_tutar' => floatval($ozet['toplam_tutar'] ?? 0)
                ]]);
                break;

            case 'get_ilceler':
                $sehirId = intval($_POST['sehir_id'] ?? 0);
                $ilceler = $db->fetchAll("SELECT ilceId, IlceAdi FROM Adres_Ilceler WHERE SehirId = ? ORDER BY IlceAdi", [$sehirId]);
                echo json_encode(['success' => true, 'data' => $ilceler]);
                break;

            case 'list':
                list($where, $params) = illegalFiltreOlustur($_POST, $cariTipiId, $satildiKosul, $sahiplikWhere, $sahiplikParams);

                $draw   = intval($_POST['draw'] ?? 1);
                $start  = max(0, intval($_POST['start'] ?? 0));
                $length = intval($_POST['length'] ?? 25);
                if ($length < 1 || $length > 500) { $length = 25; }

                $sortIndex = intval($_POST['order'][0]['column'] ?? -1);
                $sortCol   = $siralamaKolonlari[$sortIndex] ?? $varsayilanSiralama;
                $sortDir   = (strtolower($_POST['order'][0]['dir'] ?? 'asc') === 'desc') ? 'DESC' : 'ASC';

                // Filtresiz toplam (aynı rapor kapsamı)
                $toplam = $db->fetchOne("
                    SELECT COUNT(*) as sayi
                    $baseFrom
                    WHERE c.cari_tipi_id = ?" . $satildiKosul . $sahiplikWhere . "
                ", array_merge([$cariTipiId], $sahiplikParams));

                $filtreli = $db->fetchOne("
                    SELECT COUNT(*) as sayi
                    $baseFrom
                    $where
                ", $params);

                $data = $db->fetchAll("
                    SELECT
                        t.takip_id,
                        ISNULL(NULLIF(LTRIM(RTRIM(c.cari_unvan)), ''), c.cari_adi) as musteri_adi,
                        c.cari_adi as isletmeci,
                        ISNULL(s.SehirAdi, '') as sehir_adi,
                        ISNULL(il.IlceAdi, '') as ilce_adi,
                        ISNULL(u.urun_hizmet_adi, '') as urun_adi,
                        ISNULL(h.hareket_fiyat, 0) as fiyat,
                        ISNULL(st.statu_ad, '') as statu_ad,
                        ISNULL(sn.sezon_ad, '') as sezon_ad,
                        CONVERT(VARCHAR(10), t.takip_tespit_tarihi, 120) as tespit_tarihi,
                        h.hareket_id,
                        sz.sozlesme_id,
                        c.cari_id,
                        CONVERT(VARCHAR(19), sz.sozlesme_olusturma_tarihi, 120) as sozlesme_tarihi,
                        sz.sozlesme_sorumlu_kullanici_id as sorumlu_id,
                        ISNULL(ks.kullanici_ad + ' ' + ks.kullanici_soyad, '') as sorumlu_adi
                    $baseFrom
                    $where
                    ORDER BY $sortCol $sortDir, t.takip_id DESC
                    OFFSET ? ROWS FETCH NEXT ? ROWS ONLY
                ", array_merge($params, [$start, $length]));

                echo json_encode([
                    'draw'            => $draw,
                    'recordsTotal'    => intval($toplam['sayi'] ?? 0),
                    'recordsFiltered' => intval($filtreli['sayi'] ?? 0),
                    'data'            => $data
                ]);
                break;

            // ── Sorumlu atama (secili satirlarin sozlesmelerine) ─────
            case 'ata': {
                if (!$canAssign) {
                    echo json_encode(['success' => false, 'message' => 'Atama yetkiniz bulunmuyor.']);
                    break;
                }

                $sozlesmeIds = array_values(array_unique(array_filter(
                    array_map('intval', (array)($_POST['sozlesme_ids'] ?? []))
                )));
                if (!$sozlesmeIds) {
                    echo json_encode(['success' => false, 'message' => 'Sözleşmesi olan satır seçilmedi.']);
                    break;
                }

                // Bos deger atamayi kaldirir
                $sorumluId = ($_POST['sorumlu_id'] ?? '') === '' ? null : (int)$_POST['sorumlu_id'];

                if ($sorumluId !== null) {
                    $havuz = array_map('intval', array_column($sorumlular, 'kullanici_id'));
                    if (!in_array($sorumluId, $havuz, true)) {
                        echo json_encode(['success' => false, 'message' => 'Seçilen kullanıcı atanabilir listesinde değil.']);
                        break;
                    }
                }

                // Kapsam disindaki sozlesmeler POST ile gelse de islenmez:
                // yalnizca bu raporun cari tipindeki kayitlar guncellenir
                $yer = implode(',', array_fill(0, count($sozlesmeIds), '?'));
                $tipKapsami = "EXISTS (SELECT 1 FROM Cari c
                                       WHERE c.cari_id = Sozlesmeler.sozlesme_cari_id
                                         AND c.cari_tipi_id = ?)";

                $etkilenen = (int)($db->fetchOne("
                    SELECT COUNT(*) AS s FROM Sozlesmeler
                    WHERE sozlesme_id IN ($yer) AND $tipKapsami
                ", array_merge($sozlesmeIds, [$cariTipiId]))['s'] ?? 0);

                if ($etkilenen === 0) {
                    echo json_encode(['success' => false, 'message' => 'Güncellenecek sözleşme bulunamadı.']);
                    break;
                }

                $ok = $db->execute("
                    UPDATE Sozlesmeler
                    SET sozlesme_sorumlu_kullanici_id = ?,
                        sozlesme_guncelleme_tarihi = GETDATE()
                    WHERE sozlesme_id IN ($yer) AND $tipKapsami
                ", array_merge([$sorumluId], $sozlesmeIds, [$cariTipiId]));

                if (!$ok) {
                    echo json_encode(['success' => false, 'message' => 'Atama kaydedilemedi.']);
                    break;
                }

                $ozet = $etkilenen . ' sözleşme güncellendi.';

                // Atama kaldirildiysa bildirilecek sorumlu yoktur
                if ($sorumluId === null) {
                    echo json_encode(['success' => true, 'message' => $ozet], JSON_UNESCAPED_UNICODE);
                    break;
                }

                $sorumlu = $db->fetchOne("
                    SELECT kullanici_ad + ' ' + kullanici_soyad AS sorumlu_adi, kullanici_telefon
                    FROM kullanicilar WHERE kullanici_id = ?
                ", [$sorumluId]);

                $sorumluAdi = trim((string)($sorumlu['sorumlu_adi'] ?? ''));
                $tel10      = WhatsappBot::telefonNormalize((string)($sorumlu['kullanici_telefon'] ?? ''));

                if ($tel10 === '') {
                    echo json_encode([
                        'success' => true,
                        'uyari'   => true,
                        'message' => $ozet . ' Ancak ' . $sorumluAdi . ' kullanıcısının geçerli telefon '
                                   . 'numarası tanımlı olmadığı için WhatsApp bildirimi gönderilemedi.',
                    ], JSON_UNESCAPED_UNICODE);
                    break;
                }

                // Bildirime giden kayitlar: tespit tarihi, unvan, sehir/ilce, acik adres, telefonlar, aciklama
                $kayitlar = $db->fetchAll("
                    SELECT DISTINCT
                        ISNULL(NULLIF(LTRIM(RTRIM(c.cari_unvan)), ''), c.cari_adi) AS musteri_adi,
                        ISNULL(se.SehirAdi, '') AS sehir_adi,
                        ISNULL(il.IlceAdi, '')  AS ilce_adi,
                        CAST(ISNULL(c.cari_adres, '') AS NVARCHAR(MAX))     AS acik_adres,
                        CAST(ISNULL(t.takip_aciklama, '') AS NVARCHAR(MAX)) AS aciklama,
                        c.cari_telefon,
                        c.cari_yetkili_telefon,
                        CONVERT(VARCHAR(10), t.takip_tespit_tarihi, 104) AS tespit_tarihi
                    FROM Sozlesmeler s
                    INNER JOIN Cari c ON c.cari_id = s.sozlesme_cari_id AND c.cari_tipi_id = ?
                    LEFT JOIN HukukTakip t ON t.takip_cari_id = c.cari_id
                    LEFT JOIN Adres_Sehirler se ON se.SehirId = c.cari_sehirler
                    LEFT JOIN Adres_Ilceler  il ON il.ilceId  = c.cari_ilceler
                    WHERE s.sozlesme_id IN ($yer)
                    ORDER BY musteri_adi
                ", array_merge([$cariTipiId], $sozlesmeIds));

                $bloklar = [];
                $sira = 0;
                foreach ($kayitlar as $r) {
                    $sira++;
                    $konum = trim(trim((string)$r['sehir_adi']) . (($r['ilce_adi'] ?? '') ? ' / ' . $r['ilce_adi'] : ''));

                    $telefonlar = [];
                    foreach ([$r['cari_telefon'], $r['cari_yetkili_telefon']] as $t) {
                        $g = illegalTelefonGoster($t);
                        if ($g !== '' && !in_array($g, $telefonlar, true)) $telefonlar[] = $g;
                    }

                    $satir = [$sira . ') Tespit Tarihi: ' . ($r['tespit_tarihi'] ?: '-')];
                    $satir[] = 'Ünvan: ' . trim((string)$r['musteri_adi']);
                    $satir[] = 'Şehir / İlçe: ' . ($konum !== '' ? $konum : '-');
                    $adres     = trim((string)$r['acik_adres']);
                    $satir[] = 'Açık Adres: ' . ($adres !== '' ? $adres : '-');
                    $satir[] = 'Telefon: ' . ($telefonlar ? implode(' + ', $telefonlar) : '-');
                    $aciklama  = trim((string)$r['aciklama']);
                    $satir[] = 'Açıklama: ' . ($aciklama !== '' ? $aciklama : '-');

                    $bloklar[] = implode("\n", $satir);
                }

                $mesaj = illegalSablonMetni($db, ATAMA_MESAJ_KODU, [
                    'sorumlu'  => $sorumluAdi,
                    'kayitlar' => implode("\n\n", $bloklar),
                ]);

                if ($mesaj === '') {
                    echo json_encode([
                        'success' => true,
                        'uyari'   => true,
                        'message' => $ozet . ' Ancak mesaj metni tanımlı olmadığı için (' . ATAMA_MESAJ_KODU
                                   . ') WhatsApp bildirimi gönderilemedi.',
                    ], JSON_UNESCAPED_UNICODE);
                    break;
                }

                $kanal = illegalAtamaKanali($db);

                if ($kanal === null) {
                    echo json_encode([
                        'success' => true,
                        'uyari'   => true,
                        'message' => $ozet . ' Ancak ' . ATAMA_KANAL_ADI . ' WhatsApp kanalı tanımlı/aktif '
                                   . 'olmadığı için bildirim gönderilemedi.',
                    ], JSON_UNESCAPED_UNICODE);
                    break;
                }

                $sonuc = illegalAtamaGonder($kanal, WhatsappBot::jidYap($tel10), $mesaj);

                illegalWhatsappLogla(
                    $db,
                    $kanal['kanal_id'],
                    SmsHelper::normalizeTelefon((string)$sorumlu['kullanici_telefon']) ?? $tel10,
                    $mesaj,
                    $sonuc,
                    (int)$user['kullanici_id'],
                    ATAMA_BILDIRIM_KONUSU
                );

                echo json_encode(empty($sonuc['success'])
                    ? [
                        'success' => true,
                        'uyari'   => true,
                        'message' => $ozet . ' Ancak ' . $sorumluAdi . ' kullanıcısına WhatsApp bildirimi '
                                   . 'gönderilemedi: ' . $sonuc['message'],
                      ]
                    : [
                        'success' => true,
                        'message' => $ozet . ' ' . $sorumluAdi . ' kullanıcısına WhatsApp bildirimi gönderildi.',
                      ], JSON_UNESCAPED_UNICODE);
                break;
            }

            // ── WhatsApp / SMS bildirimi ─────────────────────────────
            case 'bildir': {
                if (!$canAssign) {
                    echo json_encode(['success' => false, 'message' => 'Bildirim gönderme yetkiniz bulunmuyor.']);
                    break;
                }

                $kanal = ($_POST['kanal'] ?? '') === 'sms' ? 'sms' : 'whatsapp';

                // Musteri bildirimi istege baglidir; hangi numaralara gidecegi secilir.
                $musteriGonder = !empty($_POST['musteriye']);
                $musteriTelTipi = in_array(($_POST['musteri_tel_tipi'] ?? ''), ['cari', 'yetkili', 'ikisi'], true)
                    ? $_POST['musteri_tel_tipi']
                    : 'cari';

                $sozlesmeIds = array_values(array_unique(array_filter(
                    array_map('intval', (array)($_POST['sozlesme_ids'] ?? []))
                )));
                if (!$sozlesmeIds) {
                    echo json_encode(['success' => false, 'message' => 'Sözleşmesi olan satır seçilmedi.']);
                    break;
                }

                $yer = implode(',', array_fill(0, count($sozlesmeIds), '?'));

                $satirlar = $db->fetchAll("
                    SELECT
                        k.kullanici_id,
                        k.kullanici_ad + ' ' + k.kullanici_soyad AS sorumlu_adi,
                        k.kullanici_telefon,
                        s.sozlesme_id,
                        s.sozlesme_no,
                        ISNULL(NULLIF(LTRIM(RTRIM(c.cari_unvan)), ''), c.cari_adi) AS musteri_adi,
                        ISNULL(se.SehirAdi, '') AS sehir_adi,
                        ISNULL(il.IlceAdi, '')  AS ilce_adi,
                        c.cari_id,
                        c.cari_telefon,
                        c.cari_yetkili_telefon
                    FROM Sozlesmeler s
                    INNER JOIN Cari c ON c.cari_id = s.sozlesme_cari_id AND c.cari_tipi_id = ?
                    INNER JOIN kullanicilar k
                            ON k.kullanici_id = s.sozlesme_sorumlu_kullanici_id
                    LEFT JOIN Adres_Sehirler se ON se.SehirId = c.cari_sehirler
                    LEFT JOIN Adres_Ilceler  il ON il.ilceId  = c.cari_ilceler
                    WHERE s.sozlesme_id IN ($yer)
                    ORDER BY k.kullanici_ad, musteri_adi
                ", array_merge([$cariTipiId], $sozlesmeIds));

                if (!$satirlar) {
                    echo json_encode([
                        'success' => false,
                        'message' => 'Seçilen satırların atanmış sorumlusu yok, bildirim gönderilmedi.',
                    ]);
                    break;
                }

                // Sorumlu bazinda grupla: her sorumluya tek mesaj
                $gruplar = [];
                foreach ($satirlar as $s) {
                    $gruplar[(int)$s['kullanici_id']]['bilgi']     = $s;
                    $gruplar[(int)$s['kullanici_id']]['kayitlar'][] = $s;
                }

                $bot      = $kanal === 'whatsapp' ? new WhatsappBot($db) : null;
                $waKanal  = $kanal === 'whatsapp' ? illegalWhatsappKanalId($db) : 0;
                $basarili = 0;
                $hatali   = 0;
                $detay    = [];

                foreach ($gruplar as $grup) {
                    $ad      = trim((string)$grup['bilgi']['sorumlu_adi']);
                    $telefon = (string)$grup['bilgi']['kullanici_telefon'];

                    $satirMetin = [];
                    $sira = 0;
                    foreach ($grup['kayitlar'] as $r) {
                        $sira++;
                        $konum = trim(trim((string)$r['sehir_adi']) . (($r['ilce_adi'] ?? '') ? ' / ' . $r['ilce_adi'] : ''));
                        $tel   = illegalTelefonGoster($r['cari_telefon']);

                        $parca = $sira . ') ' . trim((string)$r['musteri_adi']);
                        if ($konum !== '') $parca .= ' - ' . $konum;
                        if ($tel   !== '') $parca .= ' - ' . $tel;
                        if (!empty($r['sozlesme_no'])) $parca .= ' (Sözleşme: ' . $r['sozlesme_no'] . ')';

                        $satirMetin[] = $parca;
                    }

                    $mesaj = 'Sayın ' . $ad . ',' . "\n"
                           . 'Sorumluluk alanınızdaki kayıtlar (' . count($grup['kayitlar']) . ' adet):' . "\n\n"
                           . implode("\n", $satirMetin) . "\n\n"
                           . 'İyi çalışmalar.';

                    if ($kanal === 'sms') {
                        $sonuc = SmsHelper::gonder($telefon, $mesaj, (int)$user['kullanici_id'], BILDIRIM_KONUSU);
                    } else {
                        $tel10 = WhatsappBot::telefonNormalize($telefon);
                        $sonuc = $tel10 === ''
                            ? ['success' => false, 'message' => 'Geçersiz telefon numarası: ' . $telefon]
                            : $bot->gonder(WhatsappBot::jidYap($tel10), $mesaj, 'YETKILI');

                        illegalWhatsappLogla(
                            $db,
                            $waKanal,
                            SmsHelper::normalizeTelefon($telefon) ?? $telefon,
                            $mesaj,
                            $sonuc,
                            (int)$user['kullanici_id']
                        );
                    }

                    empty($sonuc['success']) ? $hatali++ : $basarili++;

                    $detay[] = [
                        'tur'     => 'Sorumlu',
                        'ad'      => $ad,
                        'hedef'   => illegalTelefonGoster($telefon),
                        'kayit'   => count($grup['kayitlar']),
                        'success' => (bool)($sonuc['success'] ?? false),
                        'message' => (string)($sonuc['message'] ?? ''),
                    ];
                }

                // ── Istege bagli musteri bildirimi ───────────────────
                $mBasarili = 0;
                $mHatali   = 0;

                if ($musteriGonder) {
                    // Cari bazinda tek mesaj: ayni cariye ait birden fazla satir secilse de
                    // musteri tek bildirim alir.
                    $cariler = [];
                    foreach ($satirlar as $s) {
                        $cid = (int)$s['cari_id'];
                        if (!isset($cariler[$cid])) $cariler[$cid] = $s;
                    }

                    foreach ($cariler as $c) {
                        $konum = trim(trim((string)$c['sehir_adi']) . (($c['ilce_adi'] ?? '') ? ' / ' . $c['ilce_adi'] : ''));

                        $mesajM = illegalMusteriMetni($db, [
                            'musteri'         => (string)$c['musteri_adi'],
                            'sehir'           => $konum,
                            'sorumlu'         => (string)$c['sorumlu_adi'],
                            'sorumlu_telefon' => illegalTelefonGoster($c['kullanici_telefon']),
                        ]);

                        if ($mesajM === '') {
                            $mHatali++;
                            $detay[] = [
                                'tur'     => 'Müşteri',
                                'ad'      => trim((string)$c['musteri_adi']),
                                'hedef'   => '',
                                'kayit'   => 1,
                                'success' => false,
                                'message' => 'Mesaj metni tanımlı değil (' . MUSTERI_MESAJ_KODU . ').',
                            ];
                            continue;
                        }

                        // Secilen numaralar; ayni numara iki alanda da yaziliysa tek gonderim
                        $adaylar = [];
                        if ($musteriTelTipi === 'cari' || $musteriTelTipi === 'ikisi') {
                            $adaylar[] = (string)$c['cari_telefon'];
                        }
                        if ($musteriTelTipi === 'yetkili' || $musteriTelTipi === 'ikisi') {
                            $adaylar[] = (string)$c['cari_yetkili_telefon'];
                        }

                        $hedefler = [];
                        foreach ($adaylar as $tel) {
                            $anahtar = preg_replace('/\D/', '', $tel);
                            if ($anahtar === '') continue;
                            $hedefler[$anahtar] = $tel;
                        }

                        if (!$hedefler) {
                            $mHatali++;
                            $detay[] = [
                                'tur'     => 'Müşteri',
                                'ad'      => trim((string)$c['musteri_adi']),
                                'hedef'   => '',
                                'kayit'   => 1,
                                'success' => false,
                                'message' => 'Seçilen alanda telefon numarası yok.',
                            ];
                            continue;
                        }

                        foreach ($hedefler as $tel) {
                            if ($kanal === 'sms') {
                                $sonucM = SmsHelper::gonder($tel, $mesajM, (int)$user['kullanici_id'], MUSTERI_BILDIRIM_KONUSU);
                            } else {
                                $tel10  = WhatsappBot::telefonNormalize($tel);
                                $sonucM = $tel10 === ''
                                    ? ['success' => false, 'message' => 'Geçersiz telefon numarası: ' . $tel]
                                    : $bot->gonder(WhatsappBot::jidYap($tel10), $mesajM, 'MUSTERI');

                                illegalWhatsappLogla(
                                    $db,
                                    $waKanal,
                                    SmsHelper::normalizeTelefon($tel) ?? $tel,
                                    $mesajM,
                                    $sonucM,
                                    (int)$user['kullanici_id'],
                                    MUSTERI_BILDIRIM_KONUSU
                                );
                            }

                            empty($sonucM['success']) ? $mHatali++ : $mBasarili++;

                            $detay[] = [
                                'tur'     => 'Müşteri',
                                'ad'      => trim((string)$c['musteri_adi']),
                                'hedef'   => illegalTelefonGoster($tel),
                                'kayit'   => 1,
                                'success' => (bool)($sonucM['success'] ?? false),
                                'message' => (string)($sonucM['message'] ?? ''),
                            ];
                        }
                    }
                }

                $mesajOzet = $basarili . ' sorumluya bildirim gönderildi'
                           . ($hatali > 0 ? ', ' . $hatali . ' gönderim başarısız' : '') . '.';
                if ($musteriGonder) {
                    $mesajOzet .= ' Müşteri: ' . $mBasarili . ' gönderim'
                                . ($mHatali > 0 ? ', ' . $mHatali . ' başarısız' : '') . '.';
                }

                echo json_encode([
                    'success' => ($hatali + $mHatali) === 0,
                    'message' => $mesajOzet,
                    'detay'   => $detay,
                ], JSON_UNESCAPED_UNICODE);
                break;
            }

            case 'export_excel':
                list($where, $params) = illegalFiltreOlustur($_POST, $cariTipiId, $satildiKosul, $sahiplikWhere, $sahiplikParams);

                $data = $db->fetchAll("
                    SELECT
                        ISNULL(NULLIF(LTRIM(RTRIM(c.cari_unvan)), ''), c.cari_adi) as 'Müşteri Adı',
                        ISNULL(c.cari_unvan, '') as 'Ünvan',
                        ISNULL(c.cari_telefon, '') as 'Cep Telefonu',
                        ISNULL(t.takip_tespit_turu, '') as 'Tespit Türü',
                        ISNULL(REPLACE(REPLACE(CAST(t.takip_aciklama AS NVARCHAR(MAX)), CHAR(13), ' '), CHAR(10), ' '), '') as 'Açıklama',
                        ISNULL(s.SehirAdi, '') as 'Şehir',
                        ISNULL(il.IlceAdi, '') as 'İlçe',
                        ISNULL(c.cari_adres, '') as 'Adres',
                        ISNULL(u.urun_hizmet_adi, '') as 'Ürün/Hizmet',
                        ISNULL(h.hareket_fiyat, 0) as 'Fiyat',
                        ISNULL(st.statu_ad, '') as 'Statü',
                        ISNULL(sn.sezon_ad, '') as 'Sezon',
                        CONVERT(VARCHAR(10), t.takip_tespit_tarihi, 104) as 'Tespit Tarihi'" .
                        ($sorumlulukAktif ? ",
                        CONVERT(VARCHAR(10), sz.sozlesme_olusturma_tarihi, 104) as 'Sözleşme Tarihi',
                        ISNULL(ks.kullanici_ad + ' ' + ks.kullanici_soyad, '') as 'Sorumlu'" : "") . "
                    $baseFrom
                    $where
                    ORDER BY ISNULL(NULLIF(LTRIM(RTRIM(c.cari_unvan)), ''), c.cari_adi) ASC, t.takip_id DESC
                ", $params);

                $dosyaAdi = $illegalRaporDosyaOnek . '-' . date('Y-m-d') . '.csv';

                header('Content-Type: text/csv; charset=utf-8');
                header('Content-Disposition: attachment; filename="' . $dosyaAdi . '"');
                header('Pragma: no-cache');
                header('Expires: 0');

                $output = fopen('php://output', 'w');
                fprintf($output, chr(0xEF).chr(0xBB).chr(0xBF));

                if (count($data) > 0) {
                    fputcsv($output, array_keys($data[0]), ';');
                    foreach ($data as $row) {
                        $row['Fiyat'] = number_format((float)$row['Fiyat'], 2, ',', '');
                        fputcsv($output, $row, ';');
                    }
                } else {
                    fputcsv($output, ['Veri bulunamadı'], ';');
                }

                fclose($output);
                exit;

            default:
                echo json_encode(['success' => false, 'message' => 'Geçersiz işlem']);
        }
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'message' => 'Hata: ' . $e->getMessage()]);
    }
    exit;
}

include __DIR__ . '/illegal-rapor-govde.php';
