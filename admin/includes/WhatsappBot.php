<?php
/**
 * WhatsApp Lead Botu
 *
 * Musteriden gelen fiyat taleplerini adim adim toplar ve yonlendirir:
 *
 *   Karsilama -> Talep tipi
 *        |-- ISLETME  : sehir -> ilce -> telefon -> satis grubu
 *        `-- BIREYSEL : telefon -> bireysel yetkili (ozel mesaj)
 *
 * Bolge kontrolu Adres_Bolgeler tablosundan yapilir; bu tablo sezon
 * bazlidir ve WhatsApp'a ozel degildir.
 *
 * Bot metinlerinin tamami tanim_whatsapp_bot_mesajlari tablosundadir,
 * kod icinde musteriye gidecek sabit metin yoktur.
 *
 * GIZLILIK: Bireysel taleplerin iletildigi numara yalnizca
 * WhatsappBotAyarlari tablosunda tutulur. Bu numara musteriye verilmez,
 * satis grubuna yazilmaz ve hicbir log satirina dusurulmez.
 */

require_once __DIR__ . '/../db.php';

class WhatsappBot
{
    /** Konusma adimlari */
    const ADIM_YENI             = 'YENI';
    const ADIM_TALEP_TIPI       = 'TALEP_TIPI';
    const ADIM_SEHIR            = 'SEHIR';
    const ADIM_ILCE             = 'ILCE';
    const ADIM_TELEFON          = 'TELEFON';
    const ADIM_BIREYSEL_TELEFON = 'BIREYSEL_TELEFON';
    const ADIM_TAMAM            = 'TAMAM';

    const TIP_ISLETME  = 'ISLETME';
    const TIP_BIREYSEL = 'BIREYSEL';

    private $db;
    private $ayarlar = null;
    private $sezonId = null;

    /**
     * Islenmekte olan konusma. Giden mesajlar bu konusmaya baglanir;
     * boylece panelde konusma gecmisi gelen ve giden mesajlarin tamamini
     * gosterir. Gruba veya yetkiliye giden bildirimler baglanmaz.
     */
    private $aktifKonusmaId = null;

    public function __construct(Database $db = null)
    {
        $this->db = $db ?: Database::getInstance();
    }

    // ==========================================================
    // Ayarlar ve metinler
    // ==========================================================

    /** Ayar degeri okur (tablo tek seferde belleğe alinir) */
    public function ayar(string $anahtar, string $varsayilan = ''): string
    {
        if ($this->ayarlar === null) {
            $this->ayarlar = [];
            foreach ($this->db->fetchAll("
                SELECT WhatsappBotAyarlari_Anahtar AS k, WhatsappBotAyarlari_Deger AS v
                FROM WhatsappBotAyarlari WHERE Durum = 1
            ") as $r) {
                $this->ayarlar[$r['k']] = (string)$r['v'];
            }
        }
        return $this->ayarlar[$anahtar] ?? $varsayilan;
    }

    /** Yetki kontrolunde kullanilacak sezon (ayarda yoksa varsayilan sezon) */
    public function sezonId(): int
    {
        if ($this->sezonId === null) {
            $ayar = (int)$this->ayar('yetki_sezon_id', '0');
            if ($ayar > 0) {
                $this->sezonId = $ayar;
            } else {
                $row = $this->db->fetchOne("
                    SELECT TOP 1 sezon_id FROM Sozlesme_Sezonlar
                    WHERE sezon_varsayilan = 1 AND sezon_durum = 1
                ");
                $this->sezonId = $row ? (int)$row['sezon_id'] : 0;
            }
        }
        return $this->sezonId;
    }

    /**
     * Bot metnini getirir ve {degisken} yer tutucularini doldurur.
     * Metin tabloda yoksa bos doner; bot bos mesaj gondermez.
     */
    public function metin(string $kod, array $degiskenler = []): string
    {
        $row = $this->db->fetchOne("
            SELECT tanim_whatsapp_bot_mesajlari_Metin AS m
            FROM tanim_whatsapp_bot_mesajlari
            WHERE tanim_whatsapp_bot_mesajlari_Kod = ? AND Durum = 1
        ", [$kod]);

        if (!$row) return '';

        $metin = (string)$row['m'];
        foreach ($degiskenler as $k => $v) {
            $metin = str_replace('{' . $k . '}', (string)$v, $metin);
        }
        return $metin;
    }

    // ==========================================================
    // Metin normalize / cozumleme
    // ==========================================================

    /**
     * Bir bot mesajinin anket seceneklerini dondurur.
     * Secenek tanimi bos ise mesaj duz metindir ve bos dizi doner.
     *
     * Ozel yer tutucu '{sehirler}': secenekler sabit degildir, aktif
     * sezonda yetkili oldugumuz sehirlerden uretilir.
     */
    public function secenekler(string $kod): array
    {
        if ($this->ayar('anket_aktif', '1') !== '1') return [];

        $row = $this->db->fetchOne("
            SELECT tanim_whatsapp_bot_mesajlari_Secenekler AS s
            FROM tanim_whatsapp_bot_mesajlari
            WHERE tanim_whatsapp_bot_mesajlari_Kod = ? AND Durum = 1
        ", [$kod]);

        $tanim = trim((string)($row['s'] ?? ''));
        if ($tanim === '') return [];

        if ($tanim === '{sehirler}') return $this->yetkiliSehirSecenekleri();

        $liste = array_values(array_filter(array_map('trim', explode(',', $tanim))));
        return array_slice($liste, 0, 12);   // WhatsApp anket siniri
    }

    /**
     * Anket icin sehir listesi: yetkili oldugumuz iller + "diger".
     * Ayni ilin bayi bolgeleri (Izmir-2 / Izmir-7) tek satirda birlesir.
     */
    private function yetkiliSehirSecenekleri(): array
    {
        $satirlar = $this->db->fetchAll("
            SELECT DISTINCT s.SehirAdi
            FROM Adres_Bolgeler b
            INNER JOIN Adres_Sehirler s ON s.SehirId = b.BolgeSehirId
            WHERE b.BolgeSezonId = ? AND b.Durum = 1
        ", [$this->sezonId()]);

        $sehirler = [];
        foreach ($satirlar as $r) {
            $ad = self::sehirGorunenAd((string)$r['SehirAdi']);
            if ($ad !== '' && !in_array($ad, $sehirler, true)) {
                $sehirler[] = $ad;
            }
        }
        sort($sehirler, SORT_LOCALE_STRING);

        $diger = $this->metin('SEHIR_DIGER_ETIKET');
        if ($diger !== '') $sehirler[] = $diger;

        return array_slice($sehirler, 0, 12);
    }

    /**
     * Gelen yanitin hangi secenege denk geldigini bulur.
     * Musteri ankete tiklamis olabilecegi gibi secenegin sirasini
     * (1, 2, ...) da yazmis olabilir.
     *
     * @return int|null Sifir tabanli secenek sirasi
     */
    public static function secenekEslestir(string $yanit, array $secenekler): ?int
    {
        if (!$secenekler) return null;

        $n = self::normalize($yanit);
        if ($n === '') return null;

        foreach ($secenekler as $i => $s) {
            if (self::normalize($s) === $n) return (int)$i;
        }

        // "1" / "2" gibi sira numarasi
        if (ctype_digit($n)) {
            $sira = (int)$n - 1;
            if ($sira >= 0 && $sira < count($secenekler)) return $sira;
        }

        return null;
    }

    /**
     * Turkce metni karsilastirmaya hazirlar:
     * kucuk harf, aksan kaldirma, harf disi karakterleri atma.
     * 'Ödemiş' / 'ODEMIS' / 'odemis.' hepsi 'odemis' olur.
     */
    public static function normalize(string $metin): string
    {
        $harita = [
            'İ'=>'i','I'=>'i','ı'=>'i','Ş'=>'s','ş'=>'s','Ğ'=>'g','ğ'=>'g',
            'Ü'=>'u','ü'=>'u','Ö'=>'o','ö'=>'o','Ç'=>'c','ç'=>'c',
            'Â'=>'a','â'=>'a','Î'=>'i','î'=>'i','Û'=>'u','û'=>'u',
        ];
        $metin = strtr($metin, $harita);
        $metin = mb_strtolower($metin, 'UTF-8');
        $metin = preg_replace('/[^a-z0-9]+/u', '', $metin);
        return $metin ?? '';
    }

    /**
     * Telefon numarasini 10 haneye indirger (5xxxxxxxxx).
     * Gecersizse bos doner.
     */
    public static function telefonNormalize(string $metin): string
    {
        $rakam = preg_replace('/\D/', '', $metin);
        if ($rakam === '') return '';

        // Ulke kodu / bas sifir temizligi, sirayla soyulur:
        // 00905000000004 -> 905000000004 -> 5000000004
        if (strlen($rakam) > 10 && strpos($rakam, '00') === 0) $rakam = substr($rakam, 2);
        if (strlen($rakam) > 10 && strpos($rakam, '90') === 0) $rakam = substr($rakam, 2);
        if (strlen($rakam) === 11 && $rakam[0] === '0')        $rakam = substr($rakam, 1);

        // Turkiye cep numarasi 5 ile baslar ve 10 hanedir
        return (strlen($rakam) === 10 && $rakam[0] === '5') ? $rakam : '';
    }

    /** 10 haneli numarayi WhatsApp adresine cevirir */
    public static function jidYap(string $telefon10): string
    {
        return '90' . $telefon10 . '@s.whatsapp.net';
    }

    /** WhatsApp adresindeki ham rakamlar (ulke kodu dahil) */
    public static function jidRakam(string $jid): string
    {
        return preg_replace('/\D/', '', explode('@', $jid)[0]) ?? '';
    }

    /**
     * WhatsApp adresinden numarayi cikarir.
     *
     * Turkiye numaralari 10 haneye indirgenir (5xxxxxxxxx); yurt disi
     * numaralarda boyle bir sadelestirme yapilamayacagi icin ulke kodu
     * dahil ham numara dondurulur.
     */
    public static function jidTelefon(string $jid): string
    {
        $rakam = self::jidRakam($jid);
        $tr    = self::telefonNormalize($rakam);
        return $tr !== '' ? $tr : $rakam;
    }

    /** Musteriye gosterilecek sehir adi: 'İzmir-2' -> 'İzmir' */
    public static function sehirGorunenAd(string $sehirAdi): string
    {
        return trim(preg_split('/\s*-\s*\d+$/u', $sehirAdi)[0]);
    }

    /**
     * Yazilan metinden sehri cozer.
     * Ayni il birden fazla kayit olabildigi icin (Izmir / Izmir-2 / Izmir-7)
     * sonuc plaka bazindadir.
     *
     * @return array|null ['plaka' => int, 'ad' => string]
     */
    public function sehirCoz(string $metin): ?array
    {
        $aranan = self::normalize($metin);
        if ($aranan === '') return null;

        $sehirler = $this->db->fetchAll("
            SELECT SehirId, SehirAdi, PlakaNo FROM Adres_Sehirler WHERE PlakaNo IS NOT NULL
        ");

        $kismi = null;
        foreach ($sehirler as $s) {
            $ad   = self::sehirGorunenAd((string)$s['SehirAdi']);
            $norm = self::normalize($ad);
            if ($norm === '') continue;

            if ($norm === $aranan) {
                return ['plaka' => (int)$s['PlakaNo'], 'ad' => $ad];
            }
            // 'antalyadayim' gibi yazimlar icin gevsek eslesme; tam eslesme onceliklidir
            if ($kismi === null && mb_strlen($norm) >= 4 && mb_strpos($aranan, $norm) !== false) {
                $kismi = ['plaka' => (int)$s['PlakaNo'], 'ad' => $ad];
            }
        }
        return $kismi;
    }

    /**
     * Sehir icinde ilceyi cozer. Once resmi ilce adlari, bulunamazsa
     * Adres_Bolgeler.BolgeTakmaAdlar icindeki mahalle/belde adlari denenir.
     *
     * @return array|null ['ilceId' => int, 'ilceAdi' => string, 'sehirId' => int]
     */
    public function ilceCoz(int $plaka, string $metin): ?array
    {
        $aranan = self::normalize($metin);
        if ($aranan === '') return null;

        $ilceler = $this->db->fetchAll("
            SELECT i.ilceId, i.IlceAdi, i.SehirId
            FROM Adres_Ilceler i
            INNER JOIN Adres_Sehirler s ON s.SehirId = i.SehirId
            WHERE s.PlakaNo = ?
        ", [$plaka]);

        $kismi = null;
        foreach ($ilceler as $i) {
            $norm = self::normalize((string)$i['IlceAdi']);
            if ($norm === '') continue;

            if ($norm === $aranan) {
                return [
                    'ilceId'  => (int)$i['ilceId'],
                    'ilceAdi' => (string)$i['IlceAdi'],
                    'sehirId' => (int)$i['SehirId'],
                ];
            }
            if ($kismi === null && mb_strlen($norm) >= 4 && mb_strpos($aranan, $norm) !== false) {
                $kismi = [
                    'ilceId'  => (int)$i['ilceId'],
                    'ilceAdi' => (string)$i['IlceAdi'],
                    'sehirId' => (int)$i['SehirId'],
                ];
            }
        }
        if ($kismi !== null) return $kismi;

        return $this->takmaAdCoz($plaka, $aranan);
    }

    /** Mahalle / belde adindan ilce bulur (Adres_Bolgeler.BolgeTakmaAdlar) */
    private function takmaAdCoz(int $plaka, string $aranan): ?array
    {
        $satirlar = $this->db->fetchAll("
            SELECT b.BolgeTakmaAdlar, i.ilceId, i.IlceAdi, i.SehirId
            FROM Adres_Bolgeler b
            INNER JOIN Adres_Ilceler  i ON i.ilceId  = b.BolgeIlceId
            INNER JOIN Adres_Sehirler s ON s.SehirId = b.BolgeSehirId
            WHERE b.BolgeSezonId = ? AND b.Durum = 1
              AND s.PlakaNo = ? AND b.BolgeTakmaAdlar IS NOT NULL
        ", [$this->sezonId(), $plaka]);

        foreach ($satirlar as $r) {
            foreach (explode(',', (string)$r['BolgeTakmaAdlar']) as $takma) {
                if (self::normalize($takma) === $aranan) {
                    return [
                        'ilceId'  => (int)$r['ilceId'],
                        'ilceAdi' => (string)$r['IlceAdi'],
                        'sehirId' => (int)$r['SehirId'],
                    ];
                }
            }
        }
        return null;
    }

    /**
     * Bu ilde herhangi bir yetkimiz var mi?
     *
     * Sehir adiminda sorulur: hic yetkimiz olmayan bir ilde ilce ve
     * telefon sormanin anlami yoktur, musteri bosuna oyalanmis olur.
     * Kismi yetkili illerde (ornek: Izmir) true doner ve akis ilce
     * sorusuyla devam eder; kesin karar orada verilir.
     */
    public function sehirdeYetkiVarMi(int $plaka): bool
    {
        $row = $this->db->fetchOne("
            SELECT TOP 1 b.BolgeId
            FROM Adres_Bolgeler b
            INNER JOIN Adres_Sehirler s ON s.SehirId = b.BolgeSehirId
            WHERE b.BolgeSezonId = ? AND b.Durum = 1 AND s.PlakaNo = ?
        ", [$this->sezonId(), $plaka]);

        return $row !== null;
    }

    /**
     * Bolge icinde miyiz?
     * Sehir "tum il" olarak tanimliysa (BolgeIlceId NULL) ilce onemsizdir.
     */
    public function bolgeIcindeMi(int $sehirId, ?int $ilceId): bool
    {
        $row = $this->db->fetchOne("
            SELECT TOP 1 BolgeId FROM Adres_Bolgeler
            WHERE BolgeSezonId = ? AND Durum = 1 AND BolgeSehirId = ?
              AND (BolgeIlceId IS NULL OR BolgeIlceId = ?)
        ", [$this->sezonId(), $sehirId, $ilceId]);

        return $row !== null;
    }

    // ==========================================================
    // Konusma yonetimi
    // ==========================================================

    /**
     * Konusmayi getirir, yoksa olusturur. Zaman asimi gecmisse sifirlar.
     *
     * @param string $telefon Webhook'un cozdugu numara. Kullanici adi (LID)
     *                        ile yazanlarda bos gelebilir; o durumda numara
     *                        akis icinde musteriden istenir.
     */
    public function konusmaAl(string $jid, string $gorunenAd = '', string $telefon = ''): array
    {
        if ($telefon === '') $telefon = self::jidTelefon($jid);

        $k = $this->db->fetchOne("
            SELECT * FROM WhatsappKonusmalar WHERE WhatsappKonusmalar_Jid = ?
        ", [$jid]);

        if (!$k) {
            $this->db->insert('WhatsappKonusmalar', [
                'WhatsappKonusmalar_Jid'            => $jid,
                'WhatsappKonusmalar_Telefon'        => $telefon,
                'WhatsappKonusmalar_GorunenAd'      => $gorunenAd,
                'WhatsappKonusmalar_Adim'           => self::ADIM_YENI,
                'WhatsappKonusmalar_SonMesajTarihi' => date('Y-m-d H:i:s'),
                'OlusturanKullanici'                => 0,
            ]);
            return $this->db->fetchOne("
                SELECT * FROM WhatsappKonusmalar WHERE WhatsappKonusmalar_Jid = ?
            ", [$jid]);
        }

        // Uzun sessizlikten sonra gelen mesaj yeni bir taleptir
        $zamanAsimiDk = (int)$this->ayar('konusma_zaman_asimi_dk', '60');
        $son = $k['WhatsappKonusmalar_SonMesajTarihi'];
        if ($zamanAsimiDk > 0 && $son instanceof DateTime) {
            $gecen = (time() - $son->getTimestamp()) / 60;
            if ($gecen > $zamanAsimiDk) {
                $this->konusmaSifirla((int)$k['WhatsappKonusmalar_id']);
                return $this->db->fetchOne("
                    SELECT * FROM WhatsappKonusmalar WHERE WhatsappKonusmalar_id = ?
                ", [(int)$k['WhatsappKonusmalar_id']]);
            }
        }

        if ($gorunenAd !== '' && (string)$k['WhatsappKonusmalar_GorunenAd'] === '') {
            $this->konusmaGuncelle((int)$k['WhatsappKonusmalar_id'], [
                'WhatsappKonusmalar_GorunenAd' => $gorunenAd,
            ]);
            $k['WhatsappKonusmalar_GorunenAd'] = $gorunenAd;
        }

        // Numara sonradan cozulmus olabilir (LID kullanicisinin numarasi
        // ilk mesajda gelmeyip sonraki mesajda gelebiliyor)
        if ($telefon !== '' && (string)$k['WhatsappKonusmalar_Telefon'] === '') {
            $this->konusmaGuncelle((int)$k['WhatsappKonusmalar_id'], [
                'WhatsappKonusmalar_Telefon' => $telefon,
            ]);
            $k['WhatsappKonusmalar_Telefon'] = $telefon;
        }

        return $k;
    }

    private function konusmaGuncelle(int $id, array $veri): void
    {
        $veri['GuncellemeTarihi'] = date('Y-m-d H:i:s');
        $this->db->update('WhatsappKonusmalar', $veri, ['WhatsappKonusmalar_id' => $id]);
    }

    private function konusmaSifirla(int $id): void
    {
        $this->konusmaGuncelle($id, [
            'WhatsappKonusmalar_Adim'            => self::ADIM_YENI,
            'WhatsappKonusmalar_TalepTipi'       => null,
            'WhatsappKonusmalar_SehirId'         => null,
            'WhatsappKonusmalar_SehirMetin'      => null,
            'WhatsappKonusmalar_IlceId'          => null,
            'WhatsappKonusmalar_IlceMetin'       => null,
            'WhatsappKonusmalar_IletisimTelefon' => null,
            'WhatsappKonusmalar_HataSayaci'      => 0,
            'WhatsappKonusmalar_BotAktif'        => 1,
            'WhatsappKonusmalar_DevredildiTarihi' => null,
        ]);
    }

    // ==========================================================
    // Ana akis
    // ==========================================================

    /**
     * Gelen bir musteri mesajini isler ve gerekli yanitlari gonderir.
     *
     * @return string Islem sonucu ozeti (log icin)
     */
    public function mesajIsle(string $jid, string $metin, string $gorunenAd = '', string $waMesajId = '', string $ham = '', string $telefon = ''): string
    {
        if ($this->ayar('bot_aktif', '1') !== '1') {
            return 'Bot kapali.';
        }

        $konusma = $this->konusmaAl($jid, $gorunenAd, $telefon);
        if (!$konusma) return 'Konusma olusturulamadi.';

        $konusmaId = (int)$konusma['WhatsappKonusmalar_id'];
        $this->aktifKonusmaId = $konusmaId;
        $this->mesajKaydet($konusmaId, $jid, 'GELEN', $metin, $waMesajId, true, '', $ham);
        $this->konusmaGuncelle($konusmaId, [
            'WhatsappKonusmalar_SonMesajTarihi' => date('Y-m-d H:i:s'),
        ]);

        // Ulke kurali: bazi ulkelerde soru sorulmaz, mesaj dogrudan
        // yetkiliye iletilir (ornek: Azerbaycan).
        $kural = $this->ulkeKurali($jid, (string)$konusma['WhatsappKonusmalar_Telefon']);
        if ($kural !== null && empty($kural['bot_calissin'])) {
            return $this->ulkeyeGoreYonlendir($konusma, $kural, $metin);
        }

        // Konusmayi bir insan devraldiysa bot susar
        if (!$konusma['WhatsappKonusmalar_BotAktif']) {
            return 'Bot bu konusmada pasif.';
        }

        $adim = (string)$konusma['WhatsappKonusmalar_Adim'];

        switch ($adim) {
            case self::ADIM_YENI:
                return $this->adimYeni($konusma);

            case self::ADIM_TALEP_TIPI:
                return $this->adimTalepTipi($konusma, $metin);

            case self::ADIM_SEHIR:
                return $this->adimSehir($konusma, $metin);

            case self::ADIM_ILCE:
                return $this->adimIlce($konusma, $metin);

            case self::ADIM_TELEFON:
                return $this->adimTelefon($konusma, $metin, self::TIP_ISLETME);

            case self::ADIM_BIREYSEL_TELEFON:
                return $this->adimTelefon($konusma, $metin, self::TIP_BIREYSEL);

            case self::ADIM_TAMAM:
                // Talep alindi, konusma kapandi. Yeni mesaj gelirse bir kez
                // bilgilendirip susulur; zaman asimi gecince yeni talep acilir.
                //
                // Bolge disi taleplerde "temsilcimize iletildi" demek yanlis
                // olur; talep kimseye iletilmedi, kimse aramayacak.
                $kod = $this->sonTalepBolgeIciMi($konusmaId) ? 'DEVREDISI' : 'TAMAM_BOLGE_DISI';

                $this->gonder($jid, $this->metin($kod));
                $this->konusmaGuncelle($konusmaId, ['WhatsappKonusmalar_BotAktif' => 0]);
                return 'Talep zaten alinmis (' . $kod . '), bot pasife alindi.';
        }

        return 'Bilinmeyen adim: ' . $adim;
    }

    // ==========================================================
    // Ulke bazli yonlendirme
    // ==========================================================

    /**
     * Numaranin ulke kurali (tanim_whatsapp_ulke_kurallari).
     * Kural yoksa null doner ve normal bot akisi isler.
     *
     * En uzun eslesen ulke kodu secilir; boylece ic ice gecen
     * kodlarda (ornek 9 / 90 / 994) dogru kural bulunur.
     */
    public function ulkeKurali(string $jid, string $telefon = ''): ?array
    {
        // Kullanici adi (LID) ile yazanlarda adres numara tasimaz;
        // konusmada cozulmus numara varsa oncelikle o kullanilir.
        $rakam = $telefon !== '' ? preg_replace('/\D/', '', $telefon) : '';
        if ($rakam === '') $rakam = self::jidRakam($jid);
        if ($rakam === '') return null;

        $kurallar = $this->db->fetchAll("
            SELECT tanim_whatsapp_ulke_kurallari_UlkeKodu    AS kod,
                   tanim_whatsapp_ulke_kurallari_UlkeAdi     AS ulke,
                   tanim_whatsapp_ulke_kurallari_BotCalissin AS bot_calissin,
                   tanim_whatsapp_ulke_kurallari_HedefJid    AS hedef,
                   tanim_whatsapp_ulke_kurallari_MesajKodu   AS mesaj_kodu
            FROM tanim_whatsapp_ulke_kurallari
            WHERE Durum = 1
            ORDER BY LEN(tanim_whatsapp_ulke_kurallari_UlkeKodu) DESC
        ");

        foreach ($kurallar as $k) {
            $kod = (string)$k['kod'];
            if ($kod !== '' && strpos($rakam, $kod) === 0) {
                return $k;
            }
        }
        return null;
    }

    /**
     * Ulke kurali olan numaralarda: musteriye tek bir karsilama
     * gonderilir ve gelen her mesaj yetkiliye iletilir.
     *
     * Karsilama yalnizca ilk mesajda gonderilir; sonraki mesajlarda
     * musteri rahatsiz edilmez, yalniz iletim yapilir.
     */
    private function ulkeyeGoreYonlendir(array $konusma, array $kural, string $metin): string
    {
        $jid  = (string)$konusma['WhatsappKonusmalar_Jid'];
        $id   = (int)$konusma['WhatsappKonusmalar_id'];
        $ulke = (string)$kural['ulke'];

        $ilkMesaj = (string)$konusma['WhatsappKonusmalar_Adim'] === self::ADIM_YENI;

        if ($ilkMesaj) {
            $mesajKodu = (string)($kural['mesaj_kodu'] ?? '');
            if ($mesajKodu !== '') {
                $this->gonder($jid, $this->metin($mesajKodu));
            }
            $this->konusmaGuncelle($id, [
                'WhatsappKonusmalar_Adim'      => self::ADIM_TAMAM,
                'WhatsappKonusmalar_TalepTipi' => null,
                'WhatsappKonusmalar_BotAktif'  => 0,
            ]);
        }

        // Hedef bos ise bireysel yonlendirme hedefi kullanilir
        $hedef = trim((string)($kural['hedef'] ?? ''));
        if ($hedef === '') $hedef = $this->ayar('bireysel_yonlendirme_jid');
        if ($hedef === '') return 'Ulke kurali var ama hedef tanimsiz.';

        $ad = trim((string)$konusma['WhatsappKonusmalar_GorunenAd']);

        $bildirim = $this->metin('ULKE_BILDIRIM', [
            'ulke'    => $ulke,
            'telefon' => $this->telefonGoster((string)$konusma['WhatsappKonusmalar_Telefon']),
            'ad'      => $ad !== '' ? $ad : '-',
            'mesaj'   => mb_substr(trim($metin), 0, 300),
        ]);

        $sonuc = $this->gonder($hedef, $bildirim, 'YETKILI');

        // Ilk mesajda talep kaydi olusturulur; sonraki mesajlar yalniz iletilir
        if ($ilkMesaj) {
            $konusma['WhatsappKonusmalar_SehirId']    = null;
            $konusma['WhatsappKonusmalar_SehirMetin'] = $ulke;
            $konusma['WhatsappKonusmalar_IlceId']     = null;
            $konusma['WhatsappKonusmalar_IlceMetin']  = null;

            $talepId = $this->talepOlustur(
                $konusma,
                (string)$konusma['WhatsappKonusmalar_Telefon'],
                self::TIP_ISLETME,
                false
            );

            $this->db->update('WhatsappTalepler', [
                'WhatsappTalepler_GrupMesaji'        => mb_substr($bildirim, 0, 500),
                'WhatsappTalepler_YonlendirmeHedefi' => 'BIREYSEL_YETKILI',
                'WhatsappTalepler_GrupGonderildi'    => $sonuc['success'] ? 1 : 0,
                'WhatsappTalepler_GrupTarihi'        => date('Y-m-d H:i:s'),
                'WhatsappTalepler_GrupHata'          => $sonuc['success'] ? null : mb_substr($sonuc['message'], 0, 500),
                'GuncellemeTarihi'                   => date('Y-m-d H:i:s'),
            ], ['WhatsappTalepler_id' => $talepId]);

            return $ulke . ' talebi yetkiliye iletildi (#' . $talepId . ').';
        }

        return $ulke . ' mesaji yetkiliye iletildi.';
    }

    /**
     * Konusmayi bizim tarafimizdan baslatir.
     *
     * Musteri webhook baglanmadan once yazmissa veya konusma yarim
     * kalmissa kullanilir: karsilama gonderilir ve akis bastan isler.
     * Mevcut bir konusma varsa once sifirlanir.
     *
     * @param string $hedef 10 haneli numara (5XXXXXXXXX) veya tam WhatsApp
     *                      adresi. Kullanici adi (LID) ile yazanlarin
     *                      numarasi olmadigi icin adres verilebilir.
     */
    public function konusmaBaslat(string $hedef, string $gorunenAd = ''): string
    {
        if (strpos($hedef, '@') !== false) {
            $jid     = $hedef;
            $telefon = self::jidTelefon($hedef);
        } else {
            $telefon = self::telefonNormalize($hedef);
            if ($telefon === '') return 'Gecersiz numara.';
            $jid = self::jidYap($telefon);
        }

        $konusma = $this->konusmaAl($jid, $gorunenAd, $telefon);
        if (!$konusma) return 'Konusma olusturulamadi.';

        $id = (int)$konusma['WhatsappKonusmalar_id'];
        $this->aktifKonusmaId = $id;
        $this->konusmaSifirla($id);
        $this->konusmaGuncelle($id, [
            'WhatsappKonusmalar_SonMesajTarihi' => date('Y-m-d H:i:s'),
        ]);

        $konusma['WhatsappKonusmalar_Adim'] = self::ADIM_YENI;
        return $this->adimYeni($konusma);
    }

    /**
     * Telefon adiminda bekleyen bir konusmayi, numarasi zaten bilindigi
     * icin musteriye tekrar sormadan sonuclandirir.
     *
     * Telefon sorusu kaldirilmadan once takilmis konusmalari kurtarmak
     * ve panelden elle tamamlama yapabilmek icin kullanilir.
     */
    public function konusmayiSonuclandir(int $konusmaId): string
    {
        $konusma = $this->db->fetchOne("
            SELECT * FROM WhatsappKonusmalar WHERE WhatsappKonusmalar_id = ?
        ", [$konusmaId]);

        if (!$konusma) return 'Konusma bulunamadi.';

        $this->aktifKonusmaId = $konusmaId;

        $telefon = (string)$konusma['WhatsappKonusmalar_Telefon'];
        if ($telefon === '') {
            return 'Numara bilinmiyor, musteriden istenmeli.';
        }

        $adim = (string)$konusma['WhatsappKonusmalar_Adim'];
        if (!in_array($adim, [self::ADIM_TELEFON, self::ADIM_BIREYSEL_TELEFON], true)) {
            return 'Bu adim sonuclandirmaya uygun degil: ' . $adim;
        }

        $this->konusmaGuncelle($konusmaId, [
            'WhatsappKonusmalar_IletisimTelefon' => $telefon,
            'WhatsappKonusmalar_Adim'            => self::ADIM_TAMAM,
            'WhatsappKonusmalar_HataSayaci'      => 0,
        ]);

        return $adim === self::ADIM_BIREYSEL_TELEFON
            ? $this->bireyselTamamla($konusma, $telefon)
            : $this->isletmeTamamla($konusma, $telefon);
    }

    /** Karsilama + talep tipi sorusu */
    private function adimYeni(array $konusma): string
    {
        $jid = (string)$konusma['WhatsappKonusmalar_Jid'];

        $this->gonder($jid, $this->metin('KARSILAMA'));
        $this->soruGonder($jid, 'TALEP_TIPI_SOR');

        $this->konusmaGuncelle((int)$konusma['WhatsappKonusmalar_id'], [
            'WhatsappKonusmalar_Adim' => self::ADIM_TALEP_TIPI,
        ]);
        return 'Karsilama gonderildi.';
    }

    /**
     * Isletme mi bireysel mi.
     * Anket kullaniliyorsa secenek sirasi belirleyicidir (1. isletme,
     * 2. bireysel); anket kapaliysa serbest metin sozcukleri denenir.
     */
    private function adimTalepTipi(array $konusma, string $metin): string
    {
        $jid = (string)$konusma['WhatsappKonusmalar_Jid'];
        $id  = (int)$konusma['WhatsappKonusmalar_id'];

        $tip = null;

        $secenekler = $this->secenekler('TALEP_TIPI_SOR');
        $sira = self::secenekEslestir($metin, $secenekler);
        if ($sira !== null) {
            $tip = ($sira === 0) ? self::TIP_ISLETME : self::TIP_BIREYSEL;
        } else {
            $n = self::normalize($metin);
            $isletme  = ['1', 'isletme', 'isyeri', 'ticari', 'kafe', 'restoran', 'otel', 'dukkan', 'is', 'isletmemicin'];
            $bireysel = ['2', 'bireysel', 'ev', 'evim', 'konut', 'kisisel', 'sahsi', 'evimicin'];
            if (in_array($n, $isletme, true))  $tip = self::TIP_ISLETME;
            if (in_array($n, $bireysel, true)) $tip = self::TIP_BIREYSEL;
        }

        if ($tip === null) {
            return $this->anlasilmadi($konusma, 'TALEP_TIPI_ANLASILMADI');
        }

        if ($tip === self::TIP_ISLETME) {
            $this->konusmaGuncelle($id, [
                'WhatsappKonusmalar_TalepTipi'  => self::TIP_ISLETME,
                'WhatsappKonusmalar_Adim'       => self::ADIM_SEHIR,
                'WhatsappKonusmalar_HataSayaci' => 0,
            ]);
            $this->soruGonder($jid, 'SEHIR_SOR');
            return 'Isletme talebi, sehir soruldu.';
        }

        // Numara elimizdeyse telefon sormaya gerek yok
        $mevcut = (string)$konusma['WhatsappKonusmalar_Telefon'];
        if ($mevcut !== '') {
            $this->konusmaGuncelle($id, [
                'WhatsappKonusmalar_TalepTipi'       => self::TIP_BIREYSEL,
                'WhatsappKonusmalar_IletisimTelefon' => $mevcut,
                'WhatsappKonusmalar_Adim'            => self::ADIM_TAMAM,
                'WhatsappKonusmalar_HataSayaci'      => 0,
            ]);
            $konusma['WhatsappKonusmalar_TalepTipi'] = self::TIP_BIREYSEL;
            return $this->bireyselTamamla($konusma, $mevcut);
        }

        $this->konusmaGuncelle($id, [
            'WhatsappKonusmalar_TalepTipi'  => self::TIP_BIREYSEL,
            'WhatsappKonusmalar_Adim'       => self::ADIM_BIREYSEL_TELEFON,
            'WhatsappKonusmalar_HataSayaci' => 0,
        ]);
        $this->gonder($jid, $this->metin('BIREYSEL_TELEFON_SOR'));
        return 'Bireysel talep, numara bilinmiyor, telefon soruldu.';
    }

    /** Sehir cozumleme */
    private function adimSehir(array $konusma, string $metin): string
    {
        $jid = (string)$konusma['WhatsappKonusmalar_Jid'];
        $id  = (int)$konusma['WhatsappKonusmalar_id'];

        // Anketteki "diger sehir" secenegi: serbest metin istenir
        $diger = $this->metin('SEHIR_DIGER_ETIKET');
        if ($diger !== '' && self::normalize($metin) === self::normalize($diger)) {
            $this->konusmaGuncelle($id, ['WhatsappKonusmalar_HataSayaci' => 0]);
            $this->gonder($jid, $this->metin('SEHIR_SOR_SERBEST'));
            return 'Diger sehir secildi, serbest metin soruldu.';
        }

        $sehir = $this->sehirCoz($metin);
        if ($sehir === null) {
            return $this->anlasilmadi($konusma, 'SEHIR_ANLASILMADI');
        }

        // Hic yetkimiz olmayan bir ilde ilce ve telefon sormanin anlami
        // yok; musteri daha fazla oyalanmadan bilgilendirilir.
        if (!$this->sehirdeYetkiVarMi($sehir['plaka'])) {
            $this->konusmaGuncelle($id, [
                'WhatsappKonusmalar_SehirMetin' => $sehir['ad'],
                'WhatsappKonusmalar_Adim'       => self::ADIM_TAMAM,
                'WhatsappKonusmalar_HataSayaci' => 0,
            ]);
            $konusma['WhatsappKonusmalar_SehirMetin'] = $sehir['ad'];
            $konusma['WhatsappKonusmalar_SehirId']    = null;
            $konusma['WhatsappKonusmalar_IlceId']     = null;
            $konusma['WhatsappKonusmalar_IlceMetin']  = null;

            // Musterinin numarasi zaten elimizde; talep kaydi tutulur ki
            // hangi illerden talep geldigi raporlanabilsin.
            $talepId = $this->talepOlustur(
                $konusma,
                (string)$konusma['WhatsappKonusmalar_Telefon'],
                self::TIP_ISLETME,
                false
            );

            $this->gonder($jid, $this->metin('BOLGE_DISI_SEHIR', ['sehir' => $sehir['ad']]));
            return 'Yetki alani disi il: ' . $sehir['ad'] . ' (#' . $talepId . ').';
        }

        // Sehir plakasi saklanir; kesin SehirId ilce cozulunce belli olur
        // (ayni il birden fazla bayi bolgesine bolunmus olabilir).
        $this->konusmaGuncelle($id, [
            'WhatsappKonusmalar_SehirId'    => $sehir['plaka'],
            'WhatsappKonusmalar_SehirMetin' => $sehir['ad'],
            'WhatsappKonusmalar_Adim'       => self::ADIM_ILCE,
            'WhatsappKonusmalar_HataSayaci' => 0,
        ]);
        $this->gonder($jid, $this->metin('ILCE_SOR', ['sehir' => $sehir['ad']]));
        return 'Sehir cozuldu: ' . $sehir['ad'];
    }

    /** Ilce cozumleme */
    private function adimIlce(array $konusma, string $metin): string
    {
        $jid   = (string)$konusma['WhatsappKonusmalar_Jid'];
        $id    = (int)$konusma['WhatsappKonusmalar_id'];
        $plaka = (int)$konusma['WhatsappKonusmalar_SehirId'];
        $sehirAdi = (string)$konusma['WhatsappKonusmalar_SehirMetin'];

        $ilce = $this->ilceCoz($plaka, $metin);
        if ($ilce === null) {
            return $this->anlasilmadi($konusma, 'ILCE_ANLASILMADI', ['sehir' => $sehirAdi]);
        }

        // Ilce hangi sehir kaydina bagliysa yetki kontrolu onun uzerinden yapilir
        $this->konusmaGuncelle($id, [
            'WhatsappKonusmalar_SehirId'    => $ilce['sehirId'],
            'WhatsappKonusmalar_IlceId'     => $ilce['ilceId'],
            'WhatsappKonusmalar_IlceMetin'  => $ilce['ilceAdi'],
            'WhatsappKonusmalar_HataSayaci' => 0,
        ]);

        $konusma['WhatsappKonusmalar_SehirId']   = $ilce['sehirId'];
        $konusma['WhatsappKonusmalar_IlceId']    = $ilce['ilceId'];
        $konusma['WhatsappKonusmalar_IlceMetin'] = $ilce['ilceAdi'];

        // Numara elimizdeyse telefon sormadan talebi sonuclandir
        $mevcut = (string)$konusma['WhatsappKonusmalar_Telefon'];
        if ($mevcut !== '') {
            $this->konusmaGuncelle($id, [
                'WhatsappKonusmalar_IletisimTelefon' => $mevcut,
                'WhatsappKonusmalar_Adim'            => self::ADIM_TAMAM,
            ]);
            return $this->isletmeTamamla($konusma, $mevcut);
        }

        $this->konusmaGuncelle($id, ['WhatsappKonusmalar_Adim' => self::ADIM_TELEFON]);
        $this->gonder($jid, $this->metin('TELEFON_SOR'));
        return 'Ilce cozuldu: ' . $ilce['ilceAdi'] . ', numara bilinmiyor, telefon soruldu.';
    }

    /** Telefon toplama ve talebi sonuclandirma */
    private function adimTelefon(array $konusma, string $metin, string $tip): string
    {
        $jid = (string)$konusma['WhatsappKonusmalar_Jid'];
        $id  = (int)$konusma['WhatsappKonusmalar_id'];

        $onay = ['evet', 'e', 'tamam', 'olur', 'buradan', 'bunumara', 'bunumaradan', 'ayni', 'ayninumara'];
        $n    = self::normalize($metin);

        if (in_array($n, $onay, true)) {
            $telefon = (string)$konusma['WhatsappKonusmalar_Telefon'];
        } else {
            $telefon = self::telefonNormalize($metin);
        }

        if ($telefon === '') {
            $kod = ($tip === self::TIP_BIREYSEL) ? 'TELEFON_ANLASILMADI' : 'TELEFON_ANLASILMADI';
            return $this->anlasilmadi($konusma, $kod);
        }

        $this->konusmaGuncelle($id, [
            'WhatsappKonusmalar_IletisimTelefon' => $telefon,
            'WhatsappKonusmalar_Adim'            => self::ADIM_TAMAM,
            'WhatsappKonusmalar_HataSayaci'      => 0,
        ]);

        return ($tip === self::TIP_BIREYSEL)
            ? $this->bireyselTamamla($konusma, $telefon)
            : $this->isletmeTamamla($konusma, $telefon);
    }

    /** Isletme talebi: bolge kontrolu + satis grubuna bildirim */
    private function isletmeTamamla(array $konusma, string $telefon): string
    {
        $jid      = (string)$konusma['WhatsappKonusmalar_Jid'];
        $sehirId  = (int)$konusma['WhatsappKonusmalar_SehirId'];
        $ilceId   = $konusma['WhatsappKonusmalar_IlceId'] !== null
                        ? (int)$konusma['WhatsappKonusmalar_IlceId'] : null;
        $sehirAdi = (string)$konusma['WhatsappKonusmalar_SehirMetin'];
        $ilceAdi  = (string)$konusma['WhatsappKonusmalar_IlceMetin'];

        $bolgeIci = $this->bolgeIcindeMi($sehirId, $ilceId);

        $talepId = $this->talepOlustur($konusma, $telefon, self::TIP_ISLETME, $bolgeIci);

        if (!$bolgeIci) {
            $this->gonder($jid, $this->metin('BOLGE_DISI', [
                'sehir' => $sehirAdi, 'ilce' => $ilceAdi,
            ]));
            return 'Bolge disi talep kaydedildi (#' . $talepId . ').';
        }

        // Gruba dusen satir, temsilcilerin alisik oldugu formattadir
        $grupMesaji = $this->metin('GRUP_LEAD', [
            'bolge'   => $sehirAdi . ' / ' . $ilceAdi,
            'telefon' => $this->telefonGoster($telefon),
        ]);

        $this->yonlendir($talepId, $grupMesaji, 'GRUP');

        $this->gonder($jid, $this->metin('BOLGE_ICI_TAMAM', [
            'sehir' => $sehirAdi, 'ilce' => $ilceAdi,
        ]));
        return 'Isletme talebi gruba iletildi (#' . $talepId . ').';
    }

    /** Bireysel talep: ozel yetkiliye bildirim (gruba dusmez) */
    private function bireyselTamamla(array $konusma, string $telefon): string
    {
        $jid     = (string)$konusma['WhatsappKonusmalar_Jid'];
        $talepId = $this->talepOlustur($konusma, $telefon, self::TIP_BIREYSEL, false);

        $mesaj = $this->metin('BIREYSEL_BILDIRIM', [
            'telefon' => $this->telefonGoster($telefon),
        ]);

        $this->yonlendir($talepId, $mesaj, 'BIREYSEL_YETKILI');

        $this->gonder($jid, $this->metin('BIREYSEL_TAMAM'));
        return 'Bireysel talep yetkiliye iletildi (#' . $talepId . ').';
    }

    /** 5xxxxxxxxx -> 05xx xxx xx xx */
    private function telefonGoster(string $telefon): string
    {
        // Turkiye: 5xxxxxxxxx -> 05xx xxx xx xx
        if (strlen($telefon) === 10) {
            return '0' . substr($telefon, 0, 3) . ' ' . substr($telefon, 3, 3)
                 . ' ' . substr($telefon, 6, 2) . ' ' . substr($telefon, 8, 2);
        }

        // Yurt disi: ham numara ulke koduyla saklanir, arayabilmek icin
        // basina + konur (ornek: 99450xxxxxxx -> +99450xxxxxxx)
        if ($telefon !== '' && ctype_digit($telefon)) {
            return '+' . $telefon;
        }

        return $telefon;
    }

    /**
     * Anlasilmayan yanit: tekrar sorar. Ust uste anlasilamazsa konusma
     * sessizce olmez; insana devredilir ve yetkili haberdar edilir.
     */
    private function anlasilmadi(array $konusma, string $mesajKodu, array $degiskenler = []): string
    {
        $jid  = (string)$konusma['WhatsappKonusmalar_Jid'];
        $id   = (int)$konusma['WhatsappKonusmalar_id'];
        $hata = (int)$konusma['WhatsappKonusmalar_HataSayaci'] + 1;

        $esik = (int)$this->ayar('devir_hata_esigi', '3');
        if ($esik < 1) $esik = 3;

        if ($hata >= $esik) {
            return $this->insanaDevret($konusma, $hata);
        }

        $this->konusmaGuncelle($id, ['WhatsappKonusmalar_HataSayaci' => $hata]);
        $this->gonder($jid, $this->metin($mesajKodu, $degiskenler));
        return 'Anlasilmadi (' . $hata . '): ' . $mesajKodu;
    }

    /**
     * Konusmayi insana devreder: bot susar, musteri bilgilendirilir ve
     * yetkiliye WhatsApp mesaji + panel bildirimi gonderilir.
     *
     * Ayni konusma icin bildirim yalnizca bir kez gonderilir.
     */
    private function insanaDevret(array $konusma, int $hataSayisi): string
    {
        $jid = (string)$konusma['WhatsappKonusmalar_Jid'];
        $id  = (int)$konusma['WhatsappKonusmalar_id'];

        $this->konusmaGuncelle($id, [
            'WhatsappKonusmalar_HataSayaci' => $hataSayisi,
            'WhatsappKonusmalar_BotAktif'   => 0,
        ]);

        // Musteri bot degil insan bekledigini bilmelidir
        $this->gonder($jid, $this->metin('DEVIR_MUSTERI'));

        if (!empty($konusma['WhatsappKonusmalar_DevredildiTarihi'])) {
            return 'Konusma zaten devredilmisti.';
        }
        $this->konusmaGuncelle($id, [
            'WhatsappKonusmalar_DevredildiTarihi' => date('Y-m-d H:i:s'),
        ]);

        if ($this->ayar('devir_bildirim_aktif', '1') !== '1') {
            return 'Insana devredildi (bildirim kapali).';
        }

        $telefon = (string)$konusma['WhatsappKonusmalar_Telefon'];
        $ad      = trim((string)$konusma['WhatsappKonusmalar_GorunenAd']);
        $adim    = (string)$konusma['WhatsappKonusmalar_Adim'];

        $degiskenler = [
            'telefon' => $this->telefonGoster($telefon),
            'ad'      => $ad !== '' ? $ad : '-',
            'mesaj'   => $this->sonGelenMesaj($id),
            'adim'    => $this->adimEtiket($adim),
        ];

        // 1) WhatsApp bildirimi
        $hedef = $this->ayar('devir_bildirim_jid');
        if ($hedef === '') $hedef = $this->ayar('bireysel_yonlendirme_jid');

        if ($hedef !== '') {
            $this->gonder($hedef, $this->metin('DEVIR_BILDIRIM', $degiskenler), 'YETKILI');
        }

        // 2) Panel bildirimi + PWA push
        $this->panelBildirimi($telefon, $degiskenler);

        return 'Insana devredildi, yetkili bilgilendirildi.';
    }

    /**
     * Konusmanin son talebi yetki alanimizda miydi?
     *
     * Bireysel talepler bolgeden bagimsiz yonlendirildigi icin bolge ici
     * sayilir. Talep kaydi yoksa (akis tamamlanmadan kapanmissa) yanlis
     * umut vermemek adina bolge disi kabul edilir.
     */
    private function sonTalepBolgeIciMi(int $konusmaId): bool
    {
        $row = $this->db->fetchOne("
            SELECT TOP 1 WhatsappTalepler_BolgeIci AS bolge_ici,
                         WhatsappTalepler_TalepTipi AS tip
            FROM WhatsappTalepler
            WHERE WhatsappTalepler_KonusmaId = ? AND Durum = 1
            ORDER BY WhatsappTalepler_id DESC
        ", [$konusmaId]);

        if (!$row) return false;
        if ((string)$row['tip'] === self::TIP_BIREYSEL) return true;

        return !empty($row['bolge_ici']);
    }

    /** Konusmadaki son musteri mesaji (bildirimde baglam icin) */
    private function sonGelenMesaj(int $konusmaId): string
    {
        $row = $this->db->fetchOne("
            SELECT TOP 1 WhatsappMesajlar_Icerik AS icerik
            FROM WhatsappMesajlar
            WHERE WhatsappMesajlar_KonusmaId = ? AND WhatsappMesajlar_Yon = 'GELEN'
              AND WhatsappMesajlar_Icerik IS NOT NULL
            ORDER BY WhatsappMesajlar_id DESC
        ", [$konusmaId]);

        $metin = trim((string)($row['icerik'] ?? ''));
        return $metin !== '' ? '"' . mb_substr($metin, 0, 200) . '"' : '-';
    }

    /** Adim kodunun okunabilir karsiligi */
    private function adimEtiket(string $adim): string
    {
        $etiketler = [
            self::ADIM_YENI             => 'karşılama',
            self::ADIM_TALEP_TIPI       => 'talep tipi sorusu',
            self::ADIM_SEHIR            => 'şehir sorusu',
            self::ADIM_ILCE             => 'ilçe sorusu',
            self::ADIM_TELEFON          => 'telefon sorusu',
            self::ADIM_BIREYSEL_TELEFON => 'telefon sorusu (bireysel)',
        ];
        return $etiketler[$adim] ?? $adim;
    }

    /**
     * Panele bildirim dusurur ve kayitli cihazlara push gonderir.
     * Bildirim altyapisi yoksa sessizce atlanir.
     */
    private function panelBildirimi(string $telefon, array $degiskenler): void
    {
        $kullaniciId = (int)$this->ayar('devir_bildirim_kullanici_id', '0');
        if ($kullaniciId < 1) return;

        $bildirimDosya = __DIR__ . '/Bildirim.php';
        if (!is_file($bildirimDosya)) return;

        try {
            require_once $bildirimDosya;

            Bildirim::olustur($this->db, [
                'kullanici_id' => $kullaniciId,
                'baslik'       => 'WhatsApp: bot anlaşamadı',
                'govde'        => $degiskenler['telefon'] . ' — ' . $degiskenler['mesaj'],
                'url'          => '/admin/pages/whatsapp-talepler.php',
                'tip'          => 'uyari',
                'push'         => true,
                'olusturan'    => 0,
            ]);
        } catch (Throwable $e) {
            error_log('WhatsappBot panel bildirimi hatasi: ' . $e->getMessage());
        }
    }

    // ==========================================================
    // Talep kaydi ve yonlendirme
    // ==========================================================

    private function talepOlustur(array $konusma, string $telefon, string $tip, bool $bolgeIci): int
    {
        return (int)$this->db->insert('WhatsappTalepler', [
            'WhatsappTalepler_KonusmaId'  => (int)$konusma['WhatsappKonusmalar_id'],
            'WhatsappTalepler_Telefon'    => $telefon,
            'WhatsappTalepler_GorunenAd'  => (string)$konusma['WhatsappKonusmalar_GorunenAd'],
            'WhatsappTalepler_TalepTipi'  => $tip,
            'WhatsappTalepler_SehirId'    => $konusma['WhatsappKonusmalar_SehirId'],
            'WhatsappTalepler_SehirAdi'   => $konusma['WhatsappKonusmalar_SehirMetin'],
            'WhatsappTalepler_IlceId'     => $konusma['WhatsappKonusmalar_IlceId'],
            'WhatsappTalepler_IlceAdi'    => $konusma['WhatsappKonusmalar_IlceMetin'],
            'WhatsappTalepler_BolgeIci'   => $bolgeIci ? 1 : 0,
            'OlusturanKullanici'          => 0,
        ]);
    }

    /**
     * Talebi hedefe iletir ve sonucu talep kaydina yazar.
     *
     * @param string $hedefTipi GRUP | BIREYSEL_YETKILI
     */
    private function yonlendir(int $talepId, string $mesaj, string $hedefTipi): void
    {
        if ($mesaj === '') return;

        if ($hedefTipi === 'GRUP') {
            $aktif = $this->ayar('grup_gonderim_aktif', '1') === '1';
            $hedef = $this->ayar('grup_jid');
        } else {
            $aktif = $this->ayar('bireysel_yonlendirme_aktif', '1') === '1';
            $hedef = $this->ayar('bireysel_yonlendirme_jid');
        }

        $veri = [
            'WhatsappTalepler_GrupMesaji'         => $mesaj,
            'WhatsappTalepler_YonlendirmeHedefi'  => $hedefTipi,
            'GuncellemeTarihi'                    => date('Y-m-d H:i:s'),
        ];

        if (!$aktif || $hedef === '') {
            $veri['WhatsappTalepler_GrupHata'] = 'Yonlendirme kapali veya hedef tanimsiz.';
            $this->db->update('WhatsappTalepler', $veri, ['WhatsappTalepler_id' => $talepId]);
            return;
        }

        $sonuc = $this->gonder($hedef, $mesaj, $hedefTipi === 'GRUP' ? 'GRUP' : 'YETKILI');

        $veri['WhatsappTalepler_GrupGonderildi'] = $sonuc['success'] ? 1 : 0;
        $veri['WhatsappTalepler_GrupTarihi']     = date('Y-m-d H:i:s');
        if (!$sonuc['success']) {
            $veri['WhatsappTalepler_GrupHata'] = mb_substr($sonuc['message'], 0, 500);
        }

        $this->db->update('WhatsappTalepler', $veri, ['WhatsappTalepler_id' => $talepId]);
    }

    // ==========================================================
    // Evolution API
    // ==========================================================

    /** Evolution baglanti bilgileri (URL / API anahtari Entegrasyonlar tablosunda) */
    private function baglanti(): ?array
    {
        $row = $this->db->fetchOne("
            SELECT TOP 1 e.entegrasyon_url, e.entegrasyon_api_key
            FROM Entegrasyonlar e
            WHERE e.entegrasyon_kod = 'whatsapp_evolution' AND e.entegrasyon_durum = 1
        ");
        if (!$row) return null;

        $instance = $this->ayar('instance_kod');
        if ($instance === '') return null;

        return [
            'url'      => (string)$row['entegrasyon_url'],
            'key'      => (string)$row['entegrasyon_api_key'],
            'instance' => $instance,
        ];
    }

    /**
     * Bir soruyu gonderir. Mesajin secenegi tanimliysa anket, degilse
     * duz metin olarak gider. Anket gonderimi basarisiz olursa soru
     * numarali liste halinde metin olarak tekrar denenir; boylece anket
     * calismasa da akis tikanmaz.
     */
    private function soruGonder(string $jid, string $kod, array $degiskenler = []): array
    {
        $soru       = $this->metin($kod, $degiskenler);
        $secenekler = $this->secenekler($kod);

        if ($soru === '') return ['success' => false, 'message' => 'Metin bos.'];
        if (!$secenekler) return $this->gonder($jid, $soru);

        $sonuc = $this->anketGonder($jid, $soru, $secenekler);
        if ($sonuc['success']) return $sonuc;

        return $this->gonder($jid, $this->numaraliListe($soru, $secenekler));
    }

    /** Anket gonderilemedigi durumda kullanilan metin karsiligi */
    private function numaraliListe(string $soru, array $secenekler): string
    {
        $satirlar = [$soru, ''];
        foreach ($secenekler as $i => $s) {
            $satirlar[] = ($i + 1) . ' - ' . $s;
        }
        return implode("\n", $satirlar);
    }

    /** Evolution uzerinden tek secimli anket gonderir */
    public function anketGonder(string $jid, string $soru, array $secenekler): array
    {
        $secenekler = array_slice(array_values($secenekler), 0, 12);
        if (count($secenekler) < 2) {
            return ['success' => false, 'message' => 'Anket icin en az iki secenek gerekir.'];
        }

        $b = $this->baglanti();
        if ($b === null) {
            return ['success' => false, 'message' => 'Evolution baglantisi tanimli degil.'];
        }

        $url = rtrim($b['url'], '/') . '/message/sendPoll/' . rawurlencode($b['instance']);
        $ch  = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_HTTPHEADER     => ['Content-Type: application/json', 'apikey: ' . $b['key']],
            CURLOPT_POSTFIELDS     => json_encode([
                'number'          => $jid,
                'name'            => $soru,
                'selectableCount' => 1,
                'values'          => $secenekler,
            ], JSON_UNESCAPED_UNICODE),
            CURLOPT_TIMEOUT        => 20,
            CURLOPT_SSL_VERIFYPEER => false,
        ]);
        $cevap = curl_exec($ch);
        $kod   = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $hata  = curl_error($ch);
        curl_close($ch);

        if ($cevap === false) {
            $sonuc = ['success' => false, 'message' => 'cURL hatasi: ' . $hata];
        } elseif ($kod < 200 || $kod >= 300) {
            $sonuc = ['success' => false, 'message' => 'HTTP ' . $kod . ': ' . mb_substr((string)$cevap, 0, 200)];
        } else {
            $sonuc = ['success' => true, 'message' => 'Anket gonderildi.'];
        }

        $this->mesajKaydet(
            $this->aktifKonusmaId, $jid, 'GIDEN',
            $soru . "\n[anket: " . implode(' | ', $secenekler) . ']',
            '', $sonuc['success'], $sonuc['success'] ? '' : $sonuc['message']
        );

        return $sonuc;
    }

    /**
     * Mesaj gonderir ve log'a yazar.
     *
     * Not: hedef bireysel yetkili ise numara log'a dusurulmez, yalnizca
     * hedef tipi yazilir.
     */
    public function gonder(string $hedefJid, string $mesaj, string $hedefTipi = 'MUSTERI'): array
    {
        if (trim($mesaj) === '') {
            return ['success' => false, 'message' => 'Bos mesaj gonderilmedi.'];
        }

        $b = $this->baglanti();
        if ($b === null) {
            $sonuc = ['success' => false, 'message' => 'Evolution baglantisi tanimli degil.'];
            $this->mesajKaydet($this->logKonusmaId($hedefTipi), $this->logJid($hedefJid, $hedefTipi), 'GIDEN', $mesaj, '', false, $sonuc['message']);
            return $sonuc;
        }

        $url = rtrim($b['url'], '/') . '/message/sendText/' . rawurlencode($b['instance']);
        $ch  = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_HTTPHEADER     => ['Content-Type: application/json', 'apikey: ' . $b['key']],
            CURLOPT_POSTFIELDS     => json_encode([
                'number' => $hedefJid,
                'text'   => $mesaj,
            ], JSON_UNESCAPED_UNICODE),
            CURLOPT_TIMEOUT        => 20,
            CURLOPT_SSL_VERIFYPEER => false,
        ]);
        $cevap = curl_exec($ch);
        $hata  = curl_error($ch);
        $kod   = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($cevap === false) {
            $sonuc = ['success' => false, 'message' => 'cURL hatasi: ' . $hata];
        } elseif ($kod < 200 || $kod >= 300) {
            $sonuc = ['success' => false, 'message' => 'HTTP ' . $kod . ': ' . mb_substr((string)$cevap, 0, 200)];
        } else {
            $sonuc = ['success' => true, 'message' => 'Gonderildi.'];
        }

        $this->mesajKaydet(
            $this->logKonusmaId($hedefTipi),
            $this->logJid($hedefJid, $hedefTipi),
            'GIDEN',
            $mesaj,
            '',
            $sonuc['success'],
            $sonuc['success'] ? '' : $sonuc['message']
        );

        return $sonuc;
    }

    /**
     * Log'a yazilacak hedef adresi.
     * Bireysel yetkilinin numarasi gizlidir, log'a takma bir etiket yazilir.
     */
    private function logJid(string $jid, string $hedefTipi): string
    {
        return $hedefTipi === 'YETKILI' ? 'BIREYSEL_YETKILI' : $jid;
    }

    /**
     * Giden mesajin baglanacagi konusma.
     * Yalnizca musteriye giden mesajlar konusmaya baglanir; gruba ve
     * yetkiliye giden bildirimler konusma gecmisine karismaz.
     */
    private function logKonusmaId(string $hedefTipi): ?int
    {
        return $hedefTipi === 'MUSTERI' ? $this->aktifKonusmaId : null;
    }

    private function mesajKaydet(
        ?int $konusmaId, string $jid, string $yon, string $icerik,
        string $waMesajId = '', bool $basarili = true, string $hata = '', string $ham = ''
    ): void {
        try {
            $this->db->insert('WhatsappMesajlar', [
                'WhatsappMesajlar_KonusmaId' => $konusmaId,
                'WhatsappMesajlar_Jid'       => mb_substr($jid, 0, 60),
                'WhatsappMesajlar_Yon'       => $yon,
                'WhatsappMesajlar_WaMesajId' => mb_substr($waMesajId, 0, 80),
                'WhatsappMesajlar_Icerik'    => $icerik,
                'WhatsappMesajlar_Ham'       => $ham !== '' ? $ham : null,
                'WhatsappMesajlar_Basarili'  => $basarili ? 1 : 0,
                'WhatsappMesajlar_Hata'      => $hata !== '' ? mb_substr($hata, 0, 500) : null,
                'OlusturanKullanici'         => 0,
            ]);
        } catch (Exception $e) {
            error_log('WhatsappBot mesaj log hatasi: ' . $e->getMessage());
        }
    }
}
