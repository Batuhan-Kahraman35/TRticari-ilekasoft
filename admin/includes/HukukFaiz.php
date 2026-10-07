<?php
/**
 * Hukuk Takip - Isleyen Faiz Hesaplama
 *
 * Formul: isleyen_faiz = ana_tutar x (yillik_oran / 100 / 365) x gun_sayisi
 * Gun sayisi: takip_dosya_acilis_tarihi -> bugun
 *
 * Cron (admin/cron/gorevler/hukuk_faiz_hesapla.php) toplu hesaplama yapar.
 * Bu fonksiyon tek dosya icindir: kayit/guncelleme aninda cagirilir, boylece
 * gun icinde tutari girilen dosya ertesi gece 01:00'i beklemeden faizini gorur.
 */

if (!function_exists('hukukIsleyenFaizGuncelle')) {
    /**
     * Tek takip kaydinin isleyen faizini hesaplar ve gunceller.
     *
     * @return float|null Hesaplanan faiz; kayit uygun degilse null.
     */
    function hukukIsleyenFaizGuncelle($db, int $takipId): ?float
    {
        if ($takipId <= 0) return null;

        $k = $db->fetchOne("
            SELECT takip_ana_tutar, takip_faiz_orani,
                   DATEDIFF(DAY, takip_dosya_acilis_tarihi, GETDATE()) AS gecen_gun
            FROM dbo.HukukTakip
            WHERE takip_id = ?
              AND Durum = 1
              AND takip_dosya_acilis_tarihi IS NOT NULL
              AND takip_ana_tutar > 0
              AND takip_faiz_orani > 0
        ", [$takipId]);

        if (!$k) return null;

        $gecenGun = (int)$k['gecen_gun'];
        if ($gecenGun <= 0) return null; // Acilis tarihi bugun veya gelecekte

        $faiz = round((float)$k['takip_ana_tutar'] * ((float)$k['takip_faiz_orani'] / 100 / 365) * $gecenGun, 2);

        $db->execute("
            UPDATE dbo.HukukTakip
            SET takip_Isleyen_Faiz    = ?,
                takip_son_faiz_tarihi = GETDATE(),
                GuncellemeTarihi      = GETDATE()
            WHERE takip_id = ?
        ", [$faiz, $takipId]);

        return $faiz;
    }
}
