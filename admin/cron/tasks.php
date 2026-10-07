<?php
/**
 * Merkezi Cron Sistemi - Cekirdek Kutuphane
 *
 * Icerik:
 *   - Ayar okuma ve key dogrulama      (cronAyar, cronKeyDogrula)
 *   - Cron ifade ayristirici           (cronEslesiyor, cronAlanEslesiyor)
 *   - Telafi / catch-up                (cronKacirilanTetik)
 *   - Overlap korumasi + zombi temizligi (cronCalisiyorMu)
 *   - Calisma logu yardimcilari        (cronLogOlustur, cronLogBitir)
 *   - Dinamik tarih yer tutuculari     (dinamikTarih, dinamikParamCoz)
 *   - Parametre semasi / dropdown kaynaklari (cronParamKaynaklari, cronParamDogrula)
 *   - Gorev dagitici                   (gorevCalistir)
 *
 * Gorev sozlesmesi: gorevler/<kod>.php icinde gorev_<kod>(array $params, $db): array
 *   donus: ['durum' => 1|2, 'sonuc' => 'ozet', 'cikti' => 'tam metin']
 *
 * @version 1.0
 */

if (!defined('CRON_SISTEMI')) define('CRON_SISTEMI', true);

// CLI'da .user.ini gecerli degildir; saat dilimi burada garanti altina alinir.
if (!ini_get('date.timezone')) {
    date_default_timezone_set('Europe/Istanbul');
}
if (function_exists('mb_internal_encoding')) mb_internal_encoding('UTF-8');

require_once __DIR__ . '/../db.php';

// ============================================================
// 1) AYARLAR
// ============================================================

/**
 * CronAyarlar tablosundan tek ayar okur (istek boyunca cache'lenir).
 */
function cronAyar($db, string $anahtar, ?string $varsayilan = null): ?string
{
    static $cache = [];
    if (array_key_exists($anahtar, $cache)) return $cache[$anahtar];

    $satir = $db->fetchOne(
        "SELECT CronAyarlar_Deger FROM dbo.CronAyarlar WHERE CronAyarlar_Anahtar = ? AND Durum = 1",
        [$anahtar]
    );

    $deger = $satir['CronAyarlar_Deger'] ?? null;
    if ($deger === null || $deger === '') $deger = $varsayilan;

    return $cache[$anahtar] = $deger;
}

/**
 * Web'den gelen istekte ?key= dogrulamasi. CLI'da key istenmez.
 */
function cronKeyDogrula($db): void
{
    if (PHP_SAPI === 'cli') return;

    $beklenen = (string)cronAyar($db, 'cron_secret_key', '');
    $gelen    = (string)($_GET['key'] ?? '');

    if ($beklenen === '' || !hash_equals($beklenen, $gelen)) {
        http_response_code(403);
        exit('Yetkisiz erisim.');
    }
}

// ============================================================
// 2) CRON IFADE AYRISTIRICI
// ============================================================

/**
 * 5 alanli cron ifadesi verilen dakikayla eslesiyor mu?
 * Destek: *  ·  sabit  ·  liste (0,30)  ·  aralik (1-5)  ·  adim (*​/15, 10-50/5)
 */
function cronEslesiyor(string $ifade, DateTime $dt): bool
{
    $parts = preg_split('/\s+/', trim($ifade));
    if (count($parts) !== 5) return false;

    [$dk, $sa, $gun, $ay, $hgn] = $parts;

    return cronAlanEslesiyor($dk,  (int)$dt->format('i'))
        && cronAlanEslesiyor($sa,  (int)$dt->format('G'))
        && cronAlanEslesiyor($gun, (int)$dt->format('j'))
        && cronAlanEslesiyor($ay,  (int)$dt->format('n'))
        && cronAlanEslesiyor($hgn, (int)$dt->format('w'));   // 0 = Pazar
}

function cronAlanEslesiyor(string $alan, int $deger): bool
{
    $alan = trim($alan);
    if ($alan === '*') return true;

    foreach (explode(',', $alan) as $parca) {
        $parca = trim($parca);
        if ($parca === '') continue;

        if (str_contains($parca, '/')) {
            [$aralik, $adim] = explode('/', $parca, 2);
            $adim = (int)$adim;
            if ($adim < 1) continue;

            if ($aralik === '*') {
                [$bas, $son] = [0, 59];
            } else {
                $sinir = array_map('intval', explode('-', $aralik));
                $bas   = $sinir[0];
                $son   = $sinir[1] ?? 59;
            }
            if ($deger >= $bas && $deger <= $son && ($deger - $bas) % $adim === 0) return true;

        } elseif (str_contains($parca, '-')) {
            [$bas, $son] = array_map('intval', explode('-', $parca));
            if ($deger >= $bas && $deger <= $son) return true;

        } elseif ((int)$parca === $deger) {
            return true;
        }
    }

    return false;
}

/**
 * Bir cron alaninin kapsadigi degerleri doner (panel dogrulamasi icin).
 * En fazla 60 iterasyon - ifadeyi dakika dakika taramaya gerek yoktur.
 */
function cronAlanDegerleri(string $alan, int $min, int $max): array
{
    $degerler = [];
    for ($i = $min; $i <= $max; $i++) {
        if (cronAlanEslesiyor($alan, $i)) $degerler[] = $i;
    }
    return $degerler;
}

/**
 * Cron ifadesi gecerli mi? Bes alanin her biri en az bir degere denk gelmelidir.
 *
 * @return string|null hata mesaji, gecerliyse null
 */
function cronIfadeDogrula(string $ifade): ?string
{
    $parts = preg_split('/\s+/', trim($ifade));

    if (count($parts) !== 5) {
        return 'Cron ifadesi 5 alandan oluşmalıdır. Örnek: */15 * * * *';
    }

    $alanlar = [
        ['ad' => 'dakika',         'min' => 0, 'max' => 59],
        ['ad' => 'saat',           'min' => 0, 'max' => 23],
        ['ad' => 'ayın günü',      'min' => 1, 'max' => 31],
        ['ad' => 'ay',             'min' => 1, 'max' => 12],
        ['ad' => 'haftanın günü',  'min' => 0, 'max' => 6],
    ];

    foreach ($alanlar as $i => $a) {
        if (!preg_match('/^[0-9*\/,\-]+$/', $parts[$i])) {
            return "Geçersiz karakter ({$a['ad']} alanı: {$parts[$i]}). "
                 . 'Yalnızca rakam, * , - / kullanılabilir; MON / JAN gibi kısaltmalar desteklenmez.';
        }

        if (!cronAlanDegerleri($parts[$i], $a['min'], $a['max'])) {
            return "Cron ifadesinin {$a['ad']} alanı ({$parts[$i]}) hiçbir geçerli değere denk gelmiyor "
                 . "(izin verilen aralık: {$a['min']}-{$a['max']}).";
        }
    }

    return null;
}

/**
 * Son calismadan bu yana kacirilmis tetik var mi?
 * SonCalisma NULL ise telafi YAPILMAZ - yeni olusturulan zamanlama kurulum aninda tetiklenmesin.
 * Kacirilan tetik sayisi ne olursa olsun EN FAZLA BIR KEZ telafi edilir.
 */
function cronKacirilanTetik(string $ifade, ?string $sonCalisma, int $sinirSaat, DateTime $simdi): bool
{
    if ($sonCalisma === null || $sonCalisma === '') return false;
    if ($sinirSaat < 1) $sinirSaat = 1;

    $enErken = (clone $simdi)->modify("-{$sinirSaat} hours");

    try {
        $tarama = new DateTime($sonCalisma);
    } catch (Throwable) {
        return false;
    }
    $tarama->setTime((int)$tarama->format('G'), (int)$tarama->format('i'), 0);
    $tarama->modify('+1 minute');

    if ($tarama < $enErken) $tarama = clone $enErken;

    $simdiDakika = (clone $simdi)->setTime((int)$simdi->format('G'), (int)$simdi->format('i'), 0);
    $adim = 0;
    $ustSinir = ($sinirSaat * 60) + 1;

    while ($tarama < $simdiDakika && $adim < $ustSinir) {
        if (cronEslesiyor($ifade, $tarama)) return true;
        $tarama->modify('+1 minute');
        $adim++;
    }

    return false;
}

// ============================================================
// 3) OVERLAP KORUMASI
// ============================================================

/**
 * Gorev su anda calisiyor mu? Calisiyorsa true doner ve tetik atlanir.
 * Zombi kayitlar (process cokmus, log acik kalmis) MaxSureSn x 1.5 esigiyle otomatik kapatilir.
 */
function cronCalisiyorMu($db, int $gorevId, int $maxSureSn): bool
{
    $kayit = $db->fetchOne("
        SELECT TOP 1
            CronCalismaLog_Id AS LogId,
            DATEDIFF(SECOND, CronCalismaLog_BaslangicTarihi, GETDATE()) AS GecenSaniye,
            CONVERT(VARCHAR(19), CronCalismaLog_BaslangicTarihi, 120) AS BaslangicMetin
        FROM dbo.CronCalismaLog
        WHERE CronCalismaLog_GorevId = ?
          AND CronCalismaLog_CalismaDurum = 0
        ORDER BY CronCalismaLog_Id DESC
    ", [$gorevId]);

    if (!$kayit) return false;

    $zombiEsik = max(120, (int)($maxSureSn * 1.5));

    if ((int)$kayit['GecenSaniye'] > $zombiEsik) {
        $simdi = date('Y-m-d H:i:s');
        $db->update('dbo.CronCalismaLog', [
            'CronCalismaLog_BitisTarihi'  => $simdi,
            'CronCalismaLog_SureSaniye'   => (int)$kayit['GecenSaniye'],
            'CronCalismaLog_CalismaDurum' => 2,
            'CronCalismaLog_Sonuc'        => 'Zombi kayit: ' . $zombiEsik . ' sn asildi, otomatik kapatildi.',
            'GuncelleyenKullanici'        => 0,
            'GuncellemeTarihi'            => $simdi,
        ], ['CronCalismaLog_Id' => (int)$kayit['LogId']]);

        return false;   // yol acildi
    }

    return true;
}

/**
 * Calisan kaydin baslangic saatini metin olarak doner (panel uyarisi icin). Yoksa null.
 */
function cronCalisanBaslangic($db, int $gorevId): ?string
{
    $kayit = $db->fetchOne("
        SELECT TOP 1 CONVERT(VARCHAR(19), CronCalismaLog_BaslangicTarihi, 120) AS BaslangicMetin
        FROM dbo.CronCalismaLog
        WHERE CronCalismaLog_GorevId = ? AND CronCalismaLog_CalismaDurum = 0
        ORDER BY CronCalismaLog_Id DESC
    ", [$gorevId]);

    return $kayit['BaslangicMetin'] ?? null;
}

// ============================================================
// 4) CALISMA LOGU
// ============================================================

function cronLogOlustur($db, int $gorevId, ?int $zamanlamaId, array $params, int $tetikTur, ?int $kullanici = null): int
{
    $simdi = date('Y-m-d H:i:s');

    return (int)$db->insert('dbo.CronCalismaLog', [
        'CronCalismaLog_GorevId'             => $gorevId,
        'CronCalismaLog_ZamanlamaId'         => $zamanlamaId,
        'CronCalismaLog_BaslangicTarihi'     => $simdi,
        'CronCalismaLog_Parametreler'        => $params ? json_encode($params, JSON_UNESCAPED_UNICODE) : null,
        'CronCalismaLog_CalismaDurum'        => 0,
        'CronCalismaLog_TetikleyenTur'       => $tetikTur,
        'CronCalismaLog_TetikleyenKullanici' => $kullanici,
        'OlusturanKullanici'                 => $kullanici ?? 0,
        'OlusturmaTarihi'                    => $simdi,
        'GuncelleyenKullanici'               => $kullanici ?? 0,
        'GuncellemeTarihi'                   => $simdi,
        'Durum'                              => 1,
    ]);
}

function cronLogBitir($db, int $logId, int $durum, string $sonuc, float $basZaman, string $cikti = ''): void
{
    $simdi = date('Y-m-d H:i:s');

    $db->update('dbo.CronCalismaLog', [
        'CronCalismaLog_BitisTarihi'  => $simdi,
        'CronCalismaLog_SureSaniye'   => (int)(microtime(true) - $basZaman),
        'CronCalismaLog_CalismaDurum' => $durum,
        'CronCalismaLog_Sonuc'        => mb_substr($sonuc, 0, 2000),
        'CronCalismaLog_Cikti'        => $cikti !== '' ? mb_substr($cikti, 0, 100000) : null,
        'GuncelleyenKullanici'        => 0,
        'GuncellemeTarihi'            => $simdi,
    ], ['CronCalismaLog_Id' => $logId]);
}

// ============================================================
// 5) DINAMIK TARIH YER TUTUCULARI
// ============================================================

function dinamikTarih(string $deger): string
{
    $bugun = new DateTime();

    $map = [
        '{bugun}'         => fn() => $bugun->format('d.m.Y'),
        '{dun}'           => fn() => (clone $bugun)->modify('-1 day')->format('d.m.Y'),
        '{yarin}'         => fn() => (clone $bugun)->modify('+1 day')->format('d.m.Y'),
        '{7gun_once}'     => fn() => (clone $bugun)->modify('-7 days')->format('d.m.Y'),
        '{30gun_once}'    => fn() => (clone $bugun)->modify('-30 days')->format('d.m.Y'),
        '{ay_basi}'       => fn() => $bugun->format('01.m.Y'),
        '{ay_sonu}'       => fn() => (new DateTime('last day of this month'))->format('d.m.Y'),
        '{gecen_ay_basi}' => fn() => (new DateTime('first day of last month'))->format('d.m.Y'),
        '{gecen_ay_sonu}' => fn() => (new DateTime('last day of last month'))->format('d.m.Y'),
        '{3ay_once}'      => fn() => (clone $bugun)->modify('-3 months')->format('d.m.Y'),
        '{6ay_once}'      => fn() => (clone $bugun)->modify('-6 months')->format('d.m.Y'),
        '{yil_basi}'      => fn() => $bugun->format('01.01.Y'),
    ];

    return isset($map[$deger]) ? ($map[$deger])() : $deger;
}

/**
 * Panelde tanitilan yer tutucu listesi (yardim kutusu DB'den degil buradan beslenir).
 */
function dinamikTarihListesi(): array
{
    return [
        '{bugun}'         => 'Bugun',
        '{dun}'           => 'Dun',
        '{yarin}'         => 'Yarin',
        '{7gun_once}'     => '7 gun once',
        '{30gun_once}'    => '30 gun once',
        '{ay_basi}'       => 'Icinde bulunulan ayin 1. gunu',
        '{ay_sonu}'       => 'Icinde bulunulan ayin son gunu',
        '{gecen_ay_basi}' => 'Gecen ayin 1. gunu',
        '{gecen_ay_sonu}' => 'Gecen ayin son gunu',
        '{3ay_once}'      => '3 ay once',
        '{6ay_once}'      => '6 ay once',
        '{yil_basi}'      => 'Yilin 1. gunu',
    ];
}

function dinamikParamCoz(array $params): array
{
    return array_map(fn($v) => is_string($v) ? dinamikTarih($v) : $v, $params);
}

// ============================================================
// 6) PARAMETRE SEMASI VE DROPDOWN KAYNAKLARI
// ============================================================

/**
 * select / multiselect parametreleri icin secenek kaynaklari.
 * Sorgu HER ZAMAN "deger" ve "etiket" takma adlariyla donmelidir.
 * Yeni kaynak eklemek tek satirdir; panel JS'ine dokunulmaz.
 */
function cronParamKaynaklari(): array
{
    return [
        'firmalar' => [
            'aciklama' => 'Aktif firmalar',
            'sorgu'    => "SELECT firma_id AS deger, firma_adi AS etiket
                             FROM dbo.Firmalar
                            WHERE firma_durum = 1
                            ORDER BY firma_adi",
        ],
        'subeler' => [
            'aciklama' => 'Aktif subeler',
            'sorgu'    => "SELECT sube_id AS deger, sube_adi AS etiket
                             FROM dbo.FirmalarSubeler
                            WHERE sube_durum = 1
                            ORDER BY sube_adi",
        ],
        'departmanlar' => [
            'aciklama' => 'Aktif departmanlar',
            'sorgu'    => "SELECT departman_id AS deger, departman_adi AS etiket
                             FROM dbo.Departmanlar
                            WHERE departman_durum = 1
                            ORDER BY departman_adi",
        ],
        'personeller' => [
            'aciklama' => 'E-posta adresi tanimli aktif personeller',
            'sorgu'    => "SELECT kullanici_id AS deger,
                                  LTRIM(RTRIM(ISNULL(kullanici_ad, '') + ' ' + ISNULL(kullanici_soyad, '')))
                                  + ' (' + kullanici_email + ')' AS etiket
                             FROM dbo.kullanicilar
                            WHERE kullanici_durum = 1
                              AND kullanici_email IS NOT NULL
                              AND LTRIM(RTRIM(kullanici_email)) <> ''
                            ORDER BY kullanici_ad, kullanici_soyad",
        ],
        'sezonlar' => [
            'aciklama' => 'Aktif sozlesme sezonlari',
            'sorgu'    => "SELECT sezon_id AS deger, sezon_ad AS etiket
                             FROM dbo.Sozlesme_Sezonlar
                            WHERE sezon_durum = 1
                            ORDER BY sezon_ad DESC",
        ],
    ];
}

/**
 * Tek kaynagi calistirip [{deger, etiket}] doner.
 * Tanimsiz kaynak bos dizi doner - semadaki yazim hatasi tum pencereyi kirmasin.
 */
function cronParamKaynakCoz($db, string $anahtar): array
{
    $kaynaklar = cronParamKaynaklari();
    if (!isset($kaynaklar[$anahtar])) return [];

    try {
        return $db->fetchAll($kaynaklar[$anahtar]['sorgu']) ?: [];
    } catch (Throwable $e) {
        error_log('Cron param kaynagi hatasi (' . $anahtar . '): ' . $e->getMessage());
        return [];
    }
}

/**
 * Gorev semasina gore gelen parametreleri dogrular ve tiplerine gore cast eder.
 * Panelden gelen veriye guvenilmez; sunucu tarafinda yeniden dogrulanir.
 *
 * @throws RuntimeException zorunlu alan bos ise
 */
function cronParamDogrula(?string $semaJson, array $gelen): array
{
    $sema = $semaJson ? json_decode($semaJson, true) : null;
    if (!is_array($sema) || !$sema) return $gelen;   // sema yoksa gelen aynen kullanilir

    $sonuc = [];

    foreach ($sema as $alan) {
        if (!is_array($alan) || empty($alan['ad'])) continue;

        $ad      = (string)$alan['ad'];
        $tip     = $alan['tip'] ?? 'text';
        $etiket  = $alan['etiket'] ?? $ad;
        $zorunlu = !empty($alan['zorunlu']);
        $deger   = $gelen[$ad] ?? null;

        // Bos ise varsayilan devreye girer
        if ($deger === null || $deger === '' || $deger === []) {
            $deger = array_key_exists('varsayilan', $alan) ? $alan['varsayilan'] : null;
        }

        if ($tip === 'multiselect') {
            // CLI'dan virgullu metin de kabul edilir: hedefler=2,3
            if (is_string($deger)) {
                $deger = array_filter(array_map('trim', explode(',', $deger)), fn($v) => $v !== '');
            }
            $deger = is_array($deger) ? array_values(array_map('intval', $deger)) : [];

            if ($zorunlu && !$deger) {
                throw new RuntimeException("Zorunlu alan bos: {$etiket}");
            }
            // Bos multiselect parametreye HIC yazilmaz - "secim yok" ile "bos secildi" ayrilsin
            if ($deger) $sonuc[$ad] = $deger;
            continue;
        }

        if ($deger === null || $deger === '') {
            if ($zorunlu) throw new RuntimeException("Zorunlu alan bos: {$etiket}");
            continue;
        }

        $sonuc[$ad] = match ($tip) {
            'number' => is_numeric($deger) ? $deger + 0 : throw new RuntimeException("Sayi bekleniyor: {$etiket}"),
            'select' => (int)$deger,
            'bool'   => (int)(bool)$deger,
            default  => (string)$deger,
        };
    }

    return $sonuc;
}

// ============================================================
// 7) GOREV DAGITICI
// ============================================================

/**
 * GorevKodu -> gorevler/<kod>.php icindeki gorev_<kod>() fonksiyonu.
 *
 * @return array ['durum' => 1|2, 'sonuc' => string, 'cikti' => string]
 * @throws RuntimeException gorev bulunamazsa
 */
function gorevCalistir(string $gorevKodu, array $params, $db): array
{
    $kod = strtolower(trim($gorevKodu));

    if ($kod === '' || !preg_match('/^[a-z0-9_]+$/', $kod)) {
        throw new RuntimeException('Gecersiz gorev kodu: ' . $gorevKodu);
    }

    $fn = 'gorev_' . $kod;

    if (!function_exists($fn)) {
        $dosya = __DIR__ . '/gorevler/' . $kod . '.php';
        if (!is_file($dosya)) {
            throw new RuntimeException("Gorev dosyasi bulunamadi: gorevler/{$kod}.php");
        }
        require_once $dosya;
    }

    if (!function_exists($fn)) {
        throw new RuntimeException("Gorev fonksiyonu bulunamadi: {$fn}()");
    }

    $sonuc = $fn($params, $db);

    if (!is_array($sonuc)) {
        return ['durum' => 1, 'sonuc' => 'Tamamlandi', 'cikti' => (string)$sonuc];
    }

    return [
        'durum' => (int)($sonuc['durum'] ?? 1),
        'sonuc' => (string)($sonuc['sonuc'] ?? 'Tamamlandi'),
        'cikti' => (string)($sonuc['cikti'] ?? ''),
    ];
}

/**
 * Gorev kodundan gorev satirini ceker (worker ve panel icin ortak).
 */
function cronGorevGetir($db, string $gorevKodu): ?array
{
    return $db->fetchOne("
        SELECT CronGorevler_Id, CronGorevler_Ad, CronGorevler_GorevKodu,
               CronGorevler_Parametreler, CronGorevler_MaxSureSn
        FROM dbo.CronGorevler
        WHERE CronGorevler_GorevKodu = ? AND Durum = 1
    ", [$gorevKodu]);
}

// ============================================================
// 8) SHUTDOWN HANDLER
// ============================================================

/**
 * Acik log isaretcisi. Gorev basinda doldurulur, log kapaninca SIFIRLANIR.
 * Sifirlanmazsa shutdown handler normal biten son gorevi de "kesildi" diye ezer.
 */
$GLOBALS['cronAktifLog'] = ['id' => null, 'bas' => null];

/**
 * runner.php ve worker.php basinda bir kez cagrilir.
 * set_time_limit asimi ve fatal error yakalanabilir Throwable uretmez;
 * try/catch bu bosluğu kapatamaz, shutdown handler kapatir.
 */
function cronShutdownHandlerKur(): void
{
    static $kuruldu = false;
    if ($kuruldu) return;
    $kuruldu = true;

    register_shutdown_function(function () {
        $aktif = $GLOBALS['cronAktifLog'] ?? null;
        if (empty($aktif['id'])) return;   // acik log yok, normal cikis

        $hata = error_get_last();
        $msg  = $hata && in_array($hata['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true)
            ? 'Fatal: ' . $hata['message']
            : 'Gorev yarida kesildi (zaman asimi veya process sonlandirildi).';

        try {
            cronLogBitir(Database::getInstance(), (int)$aktif['id'], 2, $msg, (float)$aktif['bas']);
        } catch (Throwable) {
            // shutdown sirasinda DB baglantisi da dusmus olabilir; log kaybini sessiz gec
        }
    });
}

function cronAktifLogAc(int $logId, float $basZaman): void
{
    $GLOBALS['cronAktifLog'] = ['id' => $logId, 'bas' => $basZaman];
}

function cronAktifLogKapat(): void
{
    $GLOBALS['cronAktifLog'] = ['id' => null, 'bas' => null];
}
