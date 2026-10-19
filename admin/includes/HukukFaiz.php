<?php
/**
 * Hukuk Takip - Isleyen Faiz Hesaplama (odeme tarihli, Ticket #389/#393)
 *
 * Faiz donem donem, o tarihteki kalan ana para uzerinden islenir:
 *   donem_faizi = kalan_ana_para x (yillik_oran / 100 / 365) x donem_gun
 * Donemler: takip_dosya_acilis_tarihi -> her odeme tarihi -> bugun
 *
 * Odeme mahsup sirasi (TBK md.100 / UYAP):
 *   masraf -> vekalet ucreti -> tahsil harci -> islemis faiz -> birikmis isleyen faiz -> ana para
 *
 * takip_Isleyen_Faiz'e toplam (odenmis kismi dahil) isleyen faiz yazilir; UYAP'taki
 * "Toplam Faiz Miktari" ile ayni anlamdadir. Kalan Alacak = Toplam Alacak - Toplam Odenen.
 *
 * Odemeler: carinin son sozlesmesindeki odeme_yapildi = 1 satirlari (form.php ile ayni baglanti).
 *
 * Cron (admin/cron/gorevler/hukuk_faiz_hesapla.php) toplu hesaplamada bu fonksiyonlari kullanir.
 */

if (!function_exists('hukukIsleyenFaizHesapla')) {
    /**
     * Tek takip kaydinin isleyen faizini hesaplar, kayit yapmaz.
     *
     * @return array|null ['faiz', 'kalan_ana', 'odeme_adet', 'odeme_toplam', 'gun']; kayit uygun degilse null.
     */
    function hukukIsleyenFaizHesapla($db, int $takipId): ?array
    {
        if ($takipId <= 0) return null;

        $k = $db->fetchOne("
            SELECT takip_cari_id, takip_ana_tutar, takip_faiz_orani,
                   ISNULL(takip_Masraf, 0)          AS masraf,
                   ISNULL(takip_Vekalet_Ucreti, 0)  AS vekalet,
                   ISNULL(takip_Tahsil_Harci, 0)    AS harc,
                   ISNULL(takip_Islemis_Faiz, 0)    AS islemis,
                   CONVERT(VARCHAR(10), takip_dosya_acilis_tarihi, 120) AS acilis,
                   CONVERT(VARCHAR(10), GETDATE(), 120) AS bugun
            FROM dbo.HukukTakip
            WHERE takip_id = ?
              AND Durum = 1
              AND takip_dosya_acilis_tarihi IS NOT NULL
              AND takip_ana_tutar > 0
              AND takip_faiz_orani > 0
        ", [$takipId]);

        if (!$k) return null;

        $gun = function (string $a, string $b): int {
            return max(0, (int)round((strtotime($b) - strtotime($a)) / 86400));
        };

        $toplamGun = $gun($k['acilis'], $k['bugun']);
        if ($toplamGun <= 0) return null; // Acilis tarihi bugun veya gelecekte

        $odemeler = $db->fetchAll("
            SELECT CONVERT(VARCHAR(10), o.odeme_tarih, 120) AS tarih, o.odeme_tutar AS tutar
            FROM Sozlesme_Odemeler o
            WHERE o.odeme_sozlesme_id = (
                    SELECT TOP 1 s.sozlesme_id FROM Sozlesmeler s
                    WHERE s.sozlesme_cari_id = ? ORDER BY s.sozlesme_id DESC)
              AND o.odeme_yapildi = 1
              AND o.odeme_tutar > 0
              AND o.odeme_tarih IS NOT NULL
              AND CONVERT(DATE, o.odeme_tarih) <= CONVERT(DATE, GETDATE())
            ORDER BY o.odeme_tarih, o.odeme_id
        ", [(int)$k['takip_cari_id']]);

        $gunlukOran = (float)$k['takip_faiz_orani'] / 100 / 365;
        $anaPara    = (float)$k['takip_ana_tutar'];
        $kalemler   = [
            'masraf'  => (float)$k['masraf'],
            'vekalet' => (float)$k['vekalet'],
            'harc'    => (float)$k['harc'],
            'islemis' => (float)$k['islemis'],
            'faiz'    => 0.0, // birikmis, odenmemis isleyen faiz
        ];
        $toplamFaiz  = 0.0;
        $odemeToplam = 0.0;
        $sonTarih    = $k['acilis'];

        foreach ($odemeler as $o) {
            // Acilistan once yapilan odeme acilis gunune mahsup edilir
            $tarih = max($o['tarih'], $k['acilis']);

            $donemFaizi = $anaPara * $gunlukOran * $gun($sonTarih, $tarih);
            $kalemler['faiz'] += $donemFaizi;
            $toplamFaiz       += $donemFaizi;
            $sonTarih          = $tarih;

            $kalan = (float)$o['tutar'];
            $odemeToplam += $kalan;
            foreach ($kalemler as $ad => $bakiye) {
                $dus = min($bakiye, $kalan);
                $kalemler[$ad] -= $dus;
                $kalan         -= $dus;
            }
            $anaPara = max(0.0, $anaPara - $kalan);
        }

        $toplamFaiz += $anaPara * $gunlukOran * $gun($sonTarih, $k['bugun']);

        return [
            'faiz'         => round($toplamFaiz, 2),
            'kalan_ana'    => round($anaPara, 2),
            'odeme_adet'   => count($odemeler),
            'odeme_toplam' => round($odemeToplam, 2),
            'gun'          => $toplamGun,
        ];
    }
}

if (!function_exists('hukukIsleyenFaizGuncelle')) {
    /**
     * Tek takip kaydinin isleyen faizini hesaplar ve gunceller.
     * Kayit/guncelleme aninda cagirilir; gun icinde girilen tutar ve odemeler gece cronunu beklemez.
     *
     * @return float|null Hesaplanan faiz; kayit uygun degilse null.
     */
    function hukukIsleyenFaizGuncelle($db, int $takipId): ?float
    {
        $h = hukukIsleyenFaizHesapla($db, $takipId);
        if (!$h) return null;

        $db->execute("
            UPDATE dbo.HukukTakip
            SET takip_Isleyen_Faiz    = ?,
                takip_son_faiz_tarihi = GETDATE(),
                GuncellemeTarihi      = GETDATE()
            WHERE takip_id = ?
        ", [$h['faiz'], $takipId]);

        return $h['faiz'];
    }
}

if (!function_exists('hukukIsleyenFaizCariIcinGuncelle')) {
    /**
     * Bir sozlesmenin carisine bagli tum aktif takiplerin faizini yeniler.
     * Odeme silme gibi takip id'si bilinmeyen islemlerden sonra cagirilir.
     */
    function hukukIsleyenFaizCariIcinGuncelle($db, int $sozlesmeId): void
    {
        if ($sozlesmeId <= 0) return;

        $takipler = $db->fetchAll("
            SELECT t.takip_id
            FROM dbo.HukukTakip t
            INNER JOIN Sozlesmeler s ON s.sozlesme_cari_id = t.takip_cari_id
            WHERE s.sozlesme_id = ? AND t.Durum = 1
        ", [$sozlesmeId]);

        foreach ($takipler as $t) {
            hukukIsleyenFaizGuncelle($db, (int)$t['takip_id']);
        }
    }
}
