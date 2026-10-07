<?php
/**
 * Gorev: Suc Duyurusu Ozet Maili
 *
 * suc-duyurusu-takip sayfasindaki ozet kutulari (toplam, telefonu bos, odeme adet/tutar,
 * statu dagilimi) ve takip listesini HTML mail olarak secilen personellere gonderir.
 *
 * Alicilar dbo.kullanicilar tablosundan gelir; kodda sabit e-posta tutulmaz.
 *
 * Parametreler:
 *   personeller   multiselect  kullanici_id listesi (zorunlu)
 *   sezon_id      select       Sozlesme_Sezonlar.sezon_id (bos = tum sezonlar)
 *   limit         number       Tabloda listelenecek en fazla satir (varsayilan 200)
 *   ek_alicilar   text         Virgulle ayrilmis ek e-posta adresleri (opsiyonel)
 *
 * Onerilen zamanlama: 0 11 * * *   ·   TelafiEt = 1
 */

if (!defined('CRON_SISTEMI')) exit('Dogrudan calistirilamaz.');

require_once __DIR__ . '/../../includes/MailHelper.php';

function gorev_suc_duyurusu_ozet_mail(array $params, $db): array
{
    ob_start();

    $cariTipiId = 4;                                   // suc duyurusu carileri
    $sezonId    = (int)($params['sezon_id'] ?? 0);
    $limit      = (int)($params['limit'] ?? 200);
    if ($limit < 1)    $limit = 1;
    if ($limit > 2000) $limit = 2000;

    // ── Alicilar ────────────────────────────────────────────────────────────
    $personelIdler = $params['personeller'] ?? [];
    if (is_string($personelIdler)) {
        $personelIdler = array_filter(array_map('trim', explode(',', $personelIdler)), fn($v) => $v !== '');
    }
    $personelIdler = array_values(array_filter(array_map('intval', (array)$personelIdler)));

    $alicilar   = [];
    $aliciAdlar = [];

    if ($personelIdler) {
        $yerTutucu = implode(',', array_fill(0, count($personelIdler), '?'));
        $personeller = $db->fetchAll("
            SELECT kullanici_id, kullanici_ad, kullanici_soyad, kullanici_email
            FROM dbo.kullanicilar
            WHERE kullanici_id IN ({$yerTutucu})
              AND kullanici_durum = 1
              AND kullanici_email IS NOT NULL
              AND LTRIM(RTRIM(kullanici_email)) <> ''
            ORDER BY kullanici_ad, kullanici_soyad
        ", $personelIdler);

        foreach ($personeller as $p) {
            $alicilar[]   = trim($p['kullanici_email']);
            $aliciAdlar[] = trim(($p['kullanici_ad'] ?? '') . ' ' . ($p['kullanici_soyad'] ?? ''));
        }
    }

    foreach (explode(',', (string)($params['ek_alicilar'] ?? '')) as $ek) {
        $ek = trim($ek);
        if ($ek !== '') { $alicilar[] = $ek; $aliciAdlar[] = $ek; }
    }

    $alicilar = array_values(array_unique($alicilar));

    if (!$alicilar) {
        echo "[HATA] Gecerli alici bulunamadi. Personel secimini ve kullanici e-postalarini kontrol edin.\n";
        return ['durum' => 2, 'sonuc' => 'Gecerli alici yok', 'cikti' => ob_get_clean()];
    }

    echo "Alicilar (" . count($alicilar) . "): " . implode(', ', $alicilar) . "\n";

    // ── Ortak sorgu govdesi ────────────────────────────────────────────────
    // Sezon, carinin son sozlesmesinden turetilir (HukukTakip'te sezon kolonu yok).
    $sezonApply = "OUTER APPLY (
                       SELECT TOP 1 sn.sezon_id, sn.sezon_ad,
                              ISNULL((SELECT SUM(h.hareket_fiyat)
                                        FROM dbo.Sozlesme_StokHareketleri h
                                       WHERE h.hareket_sozlesme_id = sz.sozlesme_id), 0) AS satis_fiyati
                         FROM dbo.Sozlesmeler sz
                         INNER JOIN dbo.Sozlesme_Sezonlar sn ON sz.sozlesme_sezon_id = sn.sezon_id
                        WHERE sz.sozlesme_cari_id = c.cari_id
                        ORDER BY sz.sozlesme_id DESC
                   ) sez";

    $where  = "WHERE c.cari_tipi_id = ?";
    $pars   = [$cariTipiId];
    $sezonAd = 'Tüm sezonlar';

    if ($sezonId > 0) {
        $where .= " AND sez.sezon_id = ?";
        $pars[] = $sezonId;
        $sz = $db->fetchOne("SELECT sezon_ad FROM dbo.Sozlesme_Sezonlar WHERE sezon_id = ?", [$sezonId]);
        $sezonAd = $sz['sezon_ad'] ?? ('Sezon #' . $sezonId);
    }

    $temelSorgu = "FROM dbo.HukukTakip t
                   INNER JOIN dbo.Cari c ON t.takip_cari_id = c.cari_id
                   LEFT JOIN dbo.HukukTaraflar tr ON t.takip_taraf_id = tr.taraf_id
                   LEFT JOIN dbo.HukukStatu st ON t.takip_statu_id = st.statu_id
                   LEFT JOIN dbo.Adres_Sehirler s ON c.cari_sehirler = s.SehirId
                   LEFT JOIN dbo.Adres_Ilceler il ON c.cari_ilceler = il.ilceId
                   {$sezonApply}
                   {$where}";

    // ── Ozet rakamlar ──────────────────────────────────────────────────────
    $toplam = (int)($db->fetchOne("SELECT COUNT(*) AS sayi {$temelSorgu}", $pars)['sayi'] ?? 0);
    $telBos = (int)($db->fetchOne(
        "SELECT COUNT(*) AS sayi {$temelSorgu} AND (c.cari_telefon IS NULL OR c.cari_telefon = '')",
        $pars
    )['sayi'] ?? 0);

    $odemeWhere  = "WHERE od.odeme_yapildi = 1 AND c2.cari_tipi_id = ?";
    $odemePars   = [$cariTipiId];
    if ($sezonId > 0) { $odemeWhere .= " AND sz.sozlesme_sezon_id = ?"; $odemePars[] = $sezonId; }

    $odeme = $db->fetchOne("
        SELECT COUNT(od.odeme_id) AS adet, ISNULL(SUM(od.odeme_tutar), 0) AS tutar
        FROM dbo.Sozlesme_Odemeler od
        INNER JOIN dbo.Sozlesmeler sz ON od.odeme_sozlesme_id = sz.sozlesme_id
        INNER JOIN dbo.Cari c2 ON sz.sozlesme_cari_id = c2.cari_id
        {$odemeWhere}
    ", $odemePars);

    $odemeAdet  = (int)($odeme['adet'] ?? 0);
    $odemeTutar = (float)($odeme['tutar'] ?? 0);

    $statuAdetleri = $db->fetchAll("
        SELECT hs.statu_id, hs.statu_ad, COUNT(t.takip_id) AS adet
        FROM dbo.HukukStatu hs
        LEFT JOIN (SELECT t.takip_statu_id, t.takip_id {$temelSorgu}) t
               ON hs.statu_id = t.takip_statu_id
        WHERE hs.Durum = 1 AND hs.statu_cari_tipi_id = ?
        GROUP BY hs.statu_id, hs.statu_ad
        ORDER BY hs.statu_ad
    ", array_merge($pars, [$cariTipiId]));

    echo "Ozet: toplam={$toplam}, telefonu bos={$telBos}, odeme={$odemeAdet} adet / "
       . number_format($odemeTutar, 2, ',', '.') . " TL\n";

    // ── Detay liste ────────────────────────────────────────────────────────
    $liste = $db->fetchAll("
        SELECT TOP {$limit}
            t.takip_tespit_adet,
            CONVERT(VARCHAR(10), t.takip_tespit_tarihi, 104) AS tespit_tarihi,
            t.takip_tespit_turu,
            c.cari_adi   AS isletmeci,
            c.cari_unvan AS isyeri_adi,
            c.cari_telefon,
            s.SehirAdi   AS sehir_adi,
            il.IlceAdi   AS ilce_adi,
            tr.taraf_ad,
            st.statu_ad,
            ISNULL(sez.sezon_ad, '') AS sezon_ad,
            ISNULL(sez.satis_fiyati, 0) AS satis_fiyati
        {$temelSorgu}
        ORDER BY t.OlusturmaTarihi DESC
    ", $pars);

    echo "Listelenen satir: " . count($liste) . " (limit {$limit})\n";

    // ── Mail govdesi ───────────────────────────────────────────────────────
    $siteAyar  = $db->fetchOne("SELECT TOP 1 site_ayarlari_site_title FROM dbo.tanim_site_ayarlari ORDER BY site_ayarlari_id DESC");
    $siteTitle = $siteAyar['site_ayarlari_site_title'] ?? 'Örnek Yazılım Portal';

    $govde = sucDuyurusuMailGovdesi(
        $siteTitle, $sezonAd, $toplam, $telBos, $odemeAdet, $odemeTutar,
        $statuAdetleri, $liste, $limit
    );

    $konu = 'Suç Duyurusu Takip Özeti - ' . date('d.m.Y') . ' (' . $sezonAd . ')';

    // ── Gonderim ───────────────────────────────────────────────────────────
    $sonuc = MailHelper::gonder($db, $alicilar, $konu, $govde, true, $siteTitle);

    echo ($sonuc['success'] ? '[OK] ' : '[HATA] ') . $sonuc['message'] . "\n";
    if (!empty($sonuc['reddedilen'])) {
        echo "Reddedilen alicilar: " . implode(', ', $sonuc['reddedilen']) . "\n";
    }

    return [
        'durum' => $sonuc['success'] ? 1 : 2,
        'sonuc' => $sonuc['success']
            ? count($sonuc['gonderilen']) . " aliciya gonderildi ({$toplam} kayit, {$sezonAd})"
            : 'Gonderim basarisiz: ' . $sonuc['message'],
        'cikti' => ob_get_clean(),
    ];
}

/**
 * Ozet kutulari + detay tablosundan HTML mail govdesi uretir.
 * Mail istemcileri harici CSS yuklemez; tum stiller satir ici yazilir.
 */
function sucDuyurusuMailGovdesi(
    string $siteTitle, string $sezonAd, int $toplam, int $telBos,
    int $odemeAdet, float $odemeTutar, array $statuAdetleri, array $liste, int $limit
): string {
    $ka = fn($v) => htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
    $tl = fn($v) => number_format((float)$v, 2, ',', '.') . ' ₺';

    $kutu = function (string $baslik, string $deger, string $renk) use ($ka): string {
        return '<td style="padding:6px;" width="25%">'
             . '<div style="background:' . $renk . ';border-radius:6px;padding:14px 12px;color:#fff;font-family:Arial,sans-serif;">'
             . '<div style="font-size:12px;opacity:.85;">' . $ka($baslik) . '</div>'
             . '<div style="font-size:22px;font-weight:bold;margin-top:4px;">' . $ka($deger) . '</div>'
             . '</div></td>';
    };

    $h = '<!doctype html><html lang="tr"><head><meta charset="utf-8"></head>'
       . '<body style="margin:0;padding:20px;background:#f4f6f9;font-family:Arial,Helvetica,sans-serif;color:#212529;">'
       . '<div style="max-width:1000px;margin:0 auto;background:#fff;border-radius:8px;padding:24px;">'
       . '<h2 style="margin:0 0 4px;font-size:20px;">Suç Duyurusu Takip Özeti</h2>'
       . '<p style="margin:0 0 20px;color:#6c757d;font-size:13px;">'
       . $ka($sezonAd) . ' · ' . date('d.m.Y H:i') . '</p>';

    // Ozet kutulari
    $h .= '<table cellpadding="0" cellspacing="0" width="100%" style="border-collapse:separate;"><tr>'
        . $kutu('Toplam Kayıt',    number_format($toplam, 0, ',', '.'), '#0d6efd')
        . $kutu('Telefonu Boş',    number_format($telBos, 0, ',', '.'), '#dc3545')
        . $kutu('Ödeme Adedi',     number_format($odemeAdet, 0, ',', '.'), '#198754')
        . $kutu('Ödeme Tutarı',    $tl($odemeTutar), '#fd7e14')
        . '</tr></table>';

    // Statu dagilimi
    if ($statuAdetleri) {
        $h .= '<h3 style="font-size:15px;margin:24px 0 8px;">Statü Dağılımı</h3>'
            . '<table cellpadding="8" cellspacing="0" width="100%" style="border-collapse:collapse;font-size:13px;">'
            . '<tr style="background:#e9ecef;"><th align="left" style="border:1px solid #dee2e6;">Statü</th>'
            . '<th align="right" style="border:1px solid #dee2e6;width:120px;">Adet</th></tr>';
        foreach ($statuAdetleri as $s) {
            $h .= '<tr><td style="border:1px solid #dee2e6;">' . $ka($s['statu_ad']) . '</td>'
                . '<td align="right" style="border:1px solid #dee2e6;">' . number_format((int)$s['adet'], 0, ',', '.') . '</td></tr>';
        }
        $h .= '</table>';
    }

    // Detay tablo
    $h .= '<h3 style="font-size:15px;margin:24px 0 8px;">Takip Listesi</h3>';

    if (!$liste) {
        $h .= '<p style="font-size:13px;color:#6c757d;">Seçilen kriterlere uyan kayıt bulunamadı.</p>';
    } else {
        if ($toplam > count($liste)) {
            $h .= '<p style="font-size:12px;color:#6c757d;margin:0 0 8px;">'
                . 'Toplam ' . number_format($toplam, 0, ',', '.') . ' kayıttan en yeni '
                . number_format(count($liste), 0, ',', '.') . ' tanesi listelenmiştir.</p>';
        }

        $basliklar = ['Tespit Tarihi', 'Adet', 'Tür', 'İşletmeci', 'İşyeri Adı', 'Telefon',
                      'İl', 'İlçe', 'Taraf', 'Statü', 'Sezon', 'Satış Fiyatı'];

        $h .= '<table cellpadding="6" cellspacing="0" width="100%" style="border-collapse:collapse;font-size:12px;">'
            . '<tr style="background:#e9ecef;">';
        foreach ($basliklar as $b) {
            $h .= '<th align="left" style="border:1px solid #dee2e6;white-space:nowrap;">' . $ka($b) . '</th>';
        }
        $h .= '</tr>';

        foreach ($liste as $i => $r) {
            $arka = $i % 2 ? '#f8f9fa' : '#ffffff';
            $h .= '<tr style="background:' . $arka . ';">'
                . '<td style="border:1px solid #dee2e6;white-space:nowrap;">' . $ka($r['tespit_tarihi']) . '</td>'
                . '<td style="border:1px solid #dee2e6;" align="right">' . $ka($r['takip_tespit_adet']) . '</td>'
                . '<td style="border:1px solid #dee2e6;">' . $ka($r['takip_tespit_turu']) . '</td>'
                . '<td style="border:1px solid #dee2e6;">' . $ka($r['isletmeci']) . '</td>'
                . '<td style="border:1px solid #dee2e6;">' . $ka($r['isyeri_adi']) . '</td>'
                . '<td style="border:1px solid #dee2e6;white-space:nowrap;">' . $ka($r['cari_telefon']) . '</td>'
                . '<td style="border:1px solid #dee2e6;">' . $ka($r['sehir_adi']) . '</td>'
                . '<td style="border:1px solid #dee2e6;">' . $ka($r['ilce_adi']) . '</td>'
                . '<td style="border:1px solid #dee2e6;">' . $ka($r['taraf_ad']) . '</td>'
                . '<td style="border:1px solid #dee2e6;">' . $ka($r['statu_ad']) . '</td>'
                . '<td style="border:1px solid #dee2e6;">' . $ka($r['sezon_ad']) . '</td>'
                . '<td style="border:1px solid #dee2e6;white-space:nowrap;" align="right">' . $tl($r['satis_fiyati']) . '</td>'
                . '</tr>';
        }
        $h .= '</table>';
    }

    $h .= '<p style="margin:24px 0 0;font-size:11px;color:#adb5bd;border-top:1px solid #dee2e6;padding-top:12px;">'
        . 'Bu e-posta ' . $ka($siteTitle) . ' otomatik görev sistemi tarafından gönderilmiştir.</p>'
        . '</div></body></html>';

    return $h;
}
