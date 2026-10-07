<?php
/**
 * Ortak Toplu Import Sayfası
 * Cari, Suc Duyurusu ve Sozlesme icin tek noktadan Excel import
 */

require_once __DIR__ . '/../auth.php';
require_once __DIR__ . '/../includes/PageAuth.php';
requireAuth();

$user = Auth::user();
$db = Database::getInstance();

$currentPageFile = basename($_SERVER['PHP_SELF']);
$pagePermissions = PageAuth::checkPagePermissions(
    $user['kullanici_id'],
    $user['departman_id'],
    $currentPageFile
);

if (!$pagePermissions['has_access']) {
    PageAuth::accessDenied($pagePermissions['error'] ?? 'Bu sayfaya erisim yetkiniz bulunmamaktadır.');
}

$pageInfo = $db->fetchOne("
    SELECT s.sayfalar_sayfa_adi, s.sayfalar_aciklama, m.menuler_menu_adi as menu_adi
    FROM Menu_Sayfalar s
    LEFT JOIN Menuler m ON s.sayfalar_menu_id = m.menuler_id
    WHERE s.sayfalar_sayfa_url LIKE ? AND s.sayfalar_durum = 1
", ['%' . $currentPageFile]);

$pageTitle = $pageInfo['sayfalar_sayfa_adi'] ?? 'Toplu Import';
$pageDescription = $pageInfo['sayfalar_aciklama'] ?? 'Excel dosyasından toplu veri yukleme';
$menuAdi = $pageInfo['menu_adi'] ?? null;

$siteAyarlari = $db->fetchOne("SELECT TOP 1 site_ayarlari_site_title FROM dbo.tanim_site_ayarlari ORDER BY site_ayarlari_id DESC");
$siteTitle = $siteAyarlari['site_ayarlari_site_title'] ?? 'Portal';
// Referans veriler
$sehirler = $db->fetchAll("SELECT SehirId, SehirAdi FROM Adres_Sehirler WHERE UlkeId = 1 ORDER BY SehirAdi");
$ilceler = $db->fetchAll("SELECT ilceId, IlceAdi, SehirId FROM Adres_Ilceler ORDER BY IlceAdi");
$taraflar = $db->fetchAll("SELECT taraf_id, taraf_ad FROM HukukTaraflar WHERE Durum = 1");
$icraDaireleri = $db->fetchAll("SELECT icra_dairesi_id, icra_dairesi_ad FROM HukukIcraDairesi WHERE Durum = 1");
$hukukMusteriler = $db->fetchAll("SELECT cari_id, cari_adi FROM Cari WHERE cari_tipi_id IN (3, 4) AND cari_aktif = 1");
$sezonlar = $db->fetchAll("SELECT sezon_id, sezon_ad FROM Sozlesme_Sezonlar WHERE sezon_durum = 1 ORDER BY sezon_ad DESC");
$uyeTipleri = $db->fetchAll("SELECT uye_tipi_id, uye_tipi_ad FROM Sozlesme_UyeTipleri WHERE uye_tipi_durum = 1 ORDER BY uye_tipi_id");
$ticariGruplar = $db->fetchAll("SELECT ticari_grup_id, ticari_grup_kod, ticari_grup_ad FROM Sozlesme_TicariGruplar WHERE ticari_grup_durum = 1 ORDER BY ticari_grup_id");
$urunler = $db->fetchAll("SELECT urun_hizmet_id, urun_hizmet_adi FROM Urun_Hizmet WHERE urun_hizmet_durum = 1 ORDER BY urun_hizmet_adi");
$tumCariler = $db->fetchAll("SELECT cari_id, cari_adi, cari_email FROM Cari ORDER BY cari_adi");
// Pasif personel de eslestirilebilir; pasiflik yalnizca panele girisi engeller (bkz. admin/auth.php)
$personeller = $db->fetchAll("SELECT kullanici_id, kullanici_ad, kullanici_soyad FROM kullanicilar ORDER BY kullanici_ad");
$cariTipleri = $db->fetchAll("SELECT cari_tipi_id, cari_tipi_ad FROM Cari_CariTipleri WHERE cari_tipi_durum = 1 ORDER BY cari_tipi_id");

// Turkce İ/I karakter sorununu cozen lowercase fonksiyonu
function turkishLower($str) {
    $str = str_replace(['İ', 'I'], ['i', 'ı'], $str);
    return mb_strtolower($str);
}

// AJAX
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json');
    $action = $_POST['action'] ?? '';
    $modul = $_POST['modul'] ?? '';

    try {
        switch ($action) {

            // ============================================
            // ANALIZ (tum moduller)
            // ============================================
            case 'analyze':
                $rawData = $_POST['data'] ?? '';
                $mapping = json_decode($_POST['mapping'] ?? '{}', true);
                $duplicateField = $_POST['duplicate_field'] ?? '';

                if (empty($rawData)) {
                    echo json_encode(['success' => false, 'message' => 'Veri alınamadı']);
                    break;
                }

                $data = json_decode($rawData, true);
                if (empty($data) || !is_array($data)) {
                    echo json_encode(['success' => false, 'message' => 'Dosyada gecerli veri bulunamadı']);
                    break;
                }

                $validatedData = [];
                $excelDuplicates = [];

                // ---- HUKUK MODUL ----
                if ($modul === 'hukuk') {
                    if (empty($mapping)) {
                        echo json_encode(['success' => false, 'message' => 'En az bir sutun eslestirilmelidir']);
                        break;
                    }

                    foreach ($data as $i => $row) {
                        $rowNum = $i + 2;
                        $validated = [
                            'row_num' => $rowNum, 'mapped_data' => [], 'status' => 'new',
                            'has_error' => false, 'error_message' => '',
                            'taraf_id' => null, 'taraf_status' => 'new',
                            'icra_dairesi_id' => null, 'icra_dairesi_status' => 'new',
                            'musteri_id' => null, 'musteri_status' => 'new',
                            'sehir_id' => null, 'ilce_id' => null,
                            'sehir_status' => 'not_mapped', 'ilce_status' => 'not_mapped'
                        ];

                        foreach ($mapping as $dbField => $excelCol) {
                            if ($excelCol === '' || $excelCol === null) continue;
                            $validated['mapped_data'][$dbField] = isset($row[$dbField]) ? trim($row[$dbField]) : '';
                        }

                        // Dosya No bos olabilir, hata verme

                        // Taraf eslestir
                        if (!empty($validated['mapped_data']['taraf'])) {
                            foreach ($taraflar as $t) {
                                if (mb_strtolower(trim($t['taraf_ad'])) === mb_strtolower(trim($validated['mapped_data']['taraf']))) {
                                    $validated['taraf_id'] = $t['taraf_id'];
                                    $validated['taraf_status'] = 'found';
                                    break;
                                }
                            }
                        }

                        // Icra Dairesi eslestir
                        if (!empty($validated['mapped_data']['icra_dairesi'])) {
                            foreach ($icraDaireleri as $ic) {
                                if (mb_strtolower(trim($ic['icra_dairesi_ad'])) === mb_strtolower(trim($validated['mapped_data']['icra_dairesi']))) {
                                    $validated['icra_dairesi_id'] = $ic['icra_dairesi_id'];
                                    $validated['icra_dairesi_status'] = 'found';
                                    break;
                                }
                            }
                        }

                        // Musteri eslestir
                        if (!empty($validated['mapped_data']['musteri_adi'])) {
                            foreach ($hukukMusteriler as $m) {
                                if (mb_strtolower(trim($m['cari_adi'])) === mb_strtolower(trim($validated['mapped_data']['musteri_adi']))) {
                                    $validated['musteri_id'] = $m['cari_id'];
                                    $validated['musteri_status'] = 'found';
                                    break;
                                }
                            }
                        }

                        // Sehir eslestir
                        if (!empty($validated['mapped_data']['sehir'])) {
                            foreach ($sehirler as $s) {
                                if (turkishLower(trim($s['SehirAdi'])) === turkishLower(trim($validated['mapped_data']['sehir']))) {
                                    $validated['sehir_id'] = $s['SehirId'];
                                    $validated['sehir_status'] = 'found';
                                    break;
                                }
                            }
                            if (!$validated['sehir_id']) $validated['sehir_status'] = 'not_found';
                        }

                        // Ilce eslestir
                        if (!empty($validated['mapped_data']['ilce']) && $validated['sehir_id']) {
                            foreach ($ilceler as $il) {
                                if ($il['SehirId'] == $validated['sehir_id'] &&
                                    turkishLower(trim($il['IlceAdi'])) === turkishLower(trim($validated['mapped_data']['ilce']))) {
                                    $validated['ilce_id'] = $il['ilceId'];
                                    $validated['ilce_status'] = 'found';
                                    break;
                                }
                            }
                            if (!$validated['ilce_id']) $validated['ilce_status'] = 'not_found';
                        }

                        // Mukerrer: DB kontrolu
                        if (!$validated['has_error'] && !empty($duplicateField) && !empty($validated['mapped_data'][$duplicateField] ?? '')) {
                            $hukukDbMap = [
                                'dosya_no' => 'takip_dosya_no',
                                'aciklama' => 'takip_aciklama',
                                'ana_tutar' => 'takip_ana_tutar'
                            ];
                            if (isset($hukukDbMap[$duplicateField])) {
                                $dbCol = $hukukDbMap[$duplicateField];
                                $existing = $db->fetchOne("SELECT takip_id FROM HukukTakip WHERE $dbCol = ?", [$validated['mapped_data'][$duplicateField]]);
                                if ($existing) {
                                    $validated['status'] = 'duplicate';
                                    $validated['duplicate_id'] = $existing['takip_id'];
                                }
                            } elseif ($duplicateField === 'musteri_adi') {
                                $existing = $db->fetchOne("SELECT TOP 1 t.takip_id FROM HukukTakip t INNER JOIN Cari c ON t.takip_cari_id = c.cari_id WHERE c.cari_adi = ?", [$validated['mapped_data']['musteri_adi']]);
                                if ($existing) {
                                    $validated['status'] = 'duplicate';
                                    $validated['duplicate_id'] = $existing['takip_id'];
                                }
                            } elseif ($duplicateField === 'cari_unvan') {
                                $existing = $db->fetchOne("SELECT TOP 1 t.takip_id FROM HukukTakip t INNER JOIN Cari c ON t.takip_cari_id = c.cari_id WHERE c.cari_unvan = ?", [$validated['mapped_data']['cari_unvan']]);
                                if ($existing) {
                                    $validated['status'] = 'duplicate';
                                    $validated['duplicate_id'] = $existing['takip_id'];
                                }
                            }
                        }

                        // Excel ici mukerrer
                        if (!$validated['has_error'] && $validated['status'] === 'new' && !empty($duplicateField)) {
                            $fieldVal = $validated['mapped_data'][$duplicateField] ?? '';
                            if (!empty($fieldVal)) {
                                $checkVal = mb_strtolower(trim($fieldVal));
                                if (isset($excelDuplicates[$checkVal])) {
                                    $validated['status'] = 'excel_duplicate';
                                    $validated['error_message'] = 'Excel icinde mukerrer (Satır ' . $excelDuplicates[$checkVal] . ')';
                                } else {
                                    $excelDuplicates[$checkVal] = $rowNum;
                                }
                            }
                        }

                        $validatedData[] = $validated;
                    }

                    $cariTipiId = intval($_POST['cari_tipi_id'] ?? 4);
                    $sezonId = intval($_POST['sezon_id'] ?? 0);
                    $defaultUrunId = intval($_POST['default_urun_id'] ?? 0);
                    $hareketDurum = intval($_POST['hareket_durum'] ?? 1);

                    $_SESSION['import_settings'] = [
                        'modul' => 'hukuk', 'mapping' => $mapping, 'duplicate_field' => $duplicateField,
                        'cari_tipi_id' => $cariTipiId,
                        'sezon_id' => $sezonId,
                        'default_urun_id' => $defaultUrunId,
                        'hareket_durum' => $hareketDurum
                    ];
                }
                // ---- SOZLESME MODUL ----
                if ($modul === 'sozlesme') {
                    $sezonId = intval($_POST['sezon_id'] ?? 0);
                    $defaultUrunId = intval($_POST['default_urun_id'] ?? 0);
                    $cariTipiId = intval($_POST['cari_tipi_id'] ?? 1);
                    $hareketDurum = intval($_POST['hareket_durum'] ?? 1);

                    if (empty($mapping)) {
                        echo json_encode(['success' => false, 'message' => 'En az bir sutun eslestirilmelidir']);
                        break;
                    }

                    $sozExcelDuplicates = [];
                    foreach ($data as $i => $row) {
                        $rowNum = $i + 2;
                        $validated = [
                            'row_num' => $rowNum, 'mapped_data' => [], 'status' => 'new',
                            'has_error' => false, 'error_message' => '',
                            'cari_id' => null, 'cari_match_status' => 'none', 'cari_adi_match' => null,
                            'cari_status_label' => 'none',
                            'sehir_id' => null, 'ilce_id' => null
                        ];

                        foreach ($mapping as $dbField => $excelCol) {
                            if ($excelCol === '' || $excelCol === null) continue;
                            $validated['mapped_data'][$dbField] = isset($row[$dbField]) ? trim($row[$dbField]) : '';
                        }





                        // Sehir eslestir
                        if (!empty($validated['mapped_data']['sehir'])) {
                            foreach ($sehirler as $s) {
                                if (turkishLower(trim($s['SehirAdi'])) === turkishLower(trim($validated['mapped_data']['sehir']))) {
                                    $validated['sehir_id'] = $s['SehirId'];
                                    break;
                                }
                            }
                        }

                        // Ilce eslestir
                        if (!empty($validated['mapped_data']['ilce']) && $validated['sehir_id']) {
                            foreach ($ilceler as $il) {
                                if ($il['SehirId'] == $validated['sehir_id'] &&
                                    turkishLower(trim($il['IlceAdi'])) === turkishLower(trim($validated['mapped_data']['ilce']))) {
                                    $validated['ilce_id'] = $il['ilceId'];
                                    break;
                                }
                            }
                        }



                        // Mukerrer: DB kontrolu (secilen alana gore)
                        if (!$validated['has_error'] && !empty($duplicateField) && !empty($validated['mapped_data'][$duplicateField] ?? '')) {
                            $sozDbMap = [
                                'uye_no_1' => 'hareket_uye_no_1',
                                'uye_no_2' => 'hareket_uye_no_2',
                                'cari_adi' => null,
                                'hareket_fiyat' => 'hareket_fiyat',
                                'paket_tanim' => null
                            ];
                            $dupVal = $validated['mapped_data'][$duplicateField];
                            if (isset($sozDbMap[$duplicateField]) && $sozDbMap[$duplicateField]) {
                                $dbCol = $sozDbMap[$duplicateField];
                                $existing = $db->fetchOne("SELECT h.hareket_id FROM Sozlesme_StokHareketleri h WHERE h.$dbCol = ?", [$dupVal]);
                                if ($existing) {
                                    $validated['status'] = 'duplicate';
                                    $validated['duplicate_id'] = $existing['hareket_id'];
                                }
                            } elseif ($duplicateField === 'cari_adi') {
                                $existing = $db->fetchOne("SELECT TOP 1 s.sozlesme_id FROM Sozlesmeler s INNER JOIN Cari c ON s.sozlesme_cari_id = c.cari_id WHERE c.cari_adi = ?", [$dupVal]);
                                if ($existing) {
                                    $validated['status'] = 'duplicate';
                                    $validated['duplicate_id'] = $existing['sozlesme_id'];
                                }
                            }
                        }

                        // Excel ici mukerrer
                        if (!$validated['has_error'] && $validated['status'] === 'new' && !empty($duplicateField)) {
                            $fieldVal = $validated['mapped_data'][$duplicateField] ?? '';
                            if (!empty($fieldVal)) {
                                $checkVal = mb_strtolower(trim($fieldVal));
                                if (isset($sozExcelDuplicates[$checkVal])) {
                                    $validated['status'] = 'excel_duplicate';
                                    $validated['error_message'] = 'Excel icinde mukerrer (Satır ' . $sozExcelDuplicates[$checkVal] . ')';
                                } else {
                                    $sozExcelDuplicates[$checkVal] = $rowNum;
                                }
                            }
                        }

                        $validatedData[] = $validated;
                    }

                    $_SESSION['import_settings'] = [
                        'modul' => 'sozlesme', 'sezon_id' => $sezonId,
                        'default_urun_id' => $defaultUrunId, 'mapping' => $mapping,
                        'duplicate_field' => $duplicateField,
                        'cari_tipi_id' => $cariTipiId,
                        'hareket_durum' => $hareketDurum
                    ];
                }

                // Istatistikler
                $summary = [
                    'total' => count($validatedData),
                    'new' => count(array_filter($validatedData, fn($r) => $r['status'] === 'new')),
                    'duplicate' => count(array_filter($validatedData, fn($r) => $r['status'] === 'duplicate')),
                    'excel_duplicate' => count(array_filter($validatedData, fn($r) => $r['status'] === 'excel_duplicate')),
                    'errors' => count(array_filter($validatedData, fn($r) => $r['has_error']))
                ];


                // Hukuk icin ek istatistik
                if ($modul === 'hukuk') {
                    $summary['new_taraf'] = count(array_filter($validatedData, fn($r) => ($r['taraf_status'] ?? '') === 'new' && !empty($r['mapped_data']['taraf'] ?? '')));
                    $summary['new_icra'] = count(array_filter($validatedData, fn($r) => ($r['icra_dairesi_status'] ?? '') === 'new' && !empty($r['mapped_data']['icra_dairesi'] ?? '')));
                    $summary['new_musteri'] = count(array_filter($validatedData, fn($r) => ($r['musteri_status'] ?? '') === 'new' && !empty($r['mapped_data']['musteri_adi'] ?? '')));
                }

                $_SESSION['import_data'] = $validatedData;

                $responseData = [
                    'success' => true,
                    'data' => $validatedData,
                    'summary' => $summary
                ];

                // Sozlesme: statik alan bilgilerini ekle
                if ($modul === 'sozlesme') {
                    $sezonAdi = '';
                    foreach ($sezonlar as $sz) {
                        if ($sz['sezon_id'] == $sezonId) { $sezonAdi = $sz['sezon_ad']; break; }
                    }
                    $urunAdi = '';
                    foreach ($urunler as $ur) {
                        if ($ur['urun_hizmet_id'] == $defaultUrunId) { $urunAdi = $ur['urun_hizmet_adi']; break; }
                    }
                    $cariTipiAdi = '';
                    foreach ($cariTipleri as $ct) {
                        if ($ct['cari_tipi_id'] == $cariTipiId) { $cariTipiAdi = $ct['cari_tipi_ad']; break; }
                    }
                    $responseData['static_fields'] = [
                        ['label' => 'Sezon', 'value' => $sezonAdi ?: '-'],
                        ['label' => 'Urun/Hizmet', 'value' => $urunAdi ?: '-'],
                        ['label' => 'Cari Tipi', 'value' => $cariTipiAdi ?: '-'],
                        ['label' => 'Sozlesme Durum', 'value' => $hareketDurum == 1 ? 'Aktif' : 'Pasif']
                    ];
                }

                echo json_encode($responseData);
                break;
            // ============================================
            // IMPORT (tum moduller)
            // ============================================
            case 'import':
                if (!isset($_SESSION['import_data']) || empty($_SESSION['import_data'])) {
                    echo json_encode(['success' => false, 'message' => 'Import verisi bulunamadı. Lutfen once dosyayi analiz edin.']);
                    break;
                }

                $importData = $_SESSION['import_data'];
                $settings = $_SESSION['import_settings'];
                $duplicateAction = $_POST['duplicate_action'] ?? 'skip';
                $imported = 0;
                $updated = 0;
                $skipped = 0;

                // ---- HUKUK IMPORT ----
                if ($settings['modul'] === 'hukuk') {
                    $newTaraflar = [];
                    $newIcraDaireleri = [];
                    $newMusteriler = [];

                    foreach ($importData as $row) {
                        if ($row['has_error'] || $row['status'] === 'excel_duplicate') { $skipped++; continue; }
                        $d = $row['mapped_data'];

                        // Sadece guncelle modunda yeni kayitlari atla
                        if ($duplicateAction === 'only_update' && $row['status'] !== 'duplicate') { $skipped++; continue; }

                        // Mukerrer kayit
                        if ($row['status'] === 'duplicate') {
                            if ($duplicateAction === 'skip') { $skipped++; continue; }
                            // Guncelle
                            $updateFields = [];
                            $updateParams = [];
                            if (!empty($d['aciklama'])) { $updateFields[] = "takip_aciklama = ?"; $updateParams[] = $d['aciklama']; }
                            if (!empty($d['ana_tutar'])) { $updateFields[] = "takip_ana_tutar = ?"; $updateParams[] = floatval($d['ana_tutar']); }
                            if (!empty($updateFields)) {
                                $updateFields[] = "GuncelleyenKullanici = ?"; $updateParams[] = $user['kullanici_id'];
                                $updateFields[] = "GuncellemeTarihi = GETDATE()";
                                $updateParams[] = $row['duplicate_id'];
                                $db->execute("UPDATE HukukTakip SET " . implode(", ", $updateFields) . " WHERE takip_id = ?", $updateParams);
                                $updated++;
                            } else { $skipped++; }
                            continue;
                        }

                        // Taraf olustur (yoksa)
                        $tarafId = $row['taraf_id'];
                        if (!$tarafId && !empty($d['taraf'])) {
                            $tarafKey = mb_strtolower($d['taraf']);
                            if (isset($newTaraflar[$tarafKey])) { $tarafId = $newTaraflar[$tarafKey]; }
                            else {
                                $db->execute("INSERT INTO HukukTaraflar (taraf_ad, Durum, OlusturanKullanici, OlusturmaTarihi) VALUES (?, 1, ?, GETDATE())", [$d['taraf'], $user['kullanici_id']]);
                                $result = $db->fetchOne("SELECT MAX(taraf_id) as id FROM HukukTaraflar");
                                $tarafId = $result['id'];
                                $newTaraflar[$tarafKey] = $tarafId;
                            }
                        }

                        // Icra Dairesi olustur (yoksa)
                        $icraDairesiId = $row['icra_dairesi_id'];
                        if (!$icraDairesiId && !empty($d['icra_dairesi'])) {
                            $icraKey = mb_strtolower($d['icra_dairesi']);
                            if (isset($newIcraDaireleri[$icraKey])) { $icraDairesiId = $newIcraDaireleri[$icraKey]; }
                            else {
                                $db->execute("INSERT INTO HukukIcraDairesi (icra_dairesi_ad, Durum, OlusturanKullanici, OlusturmaTarihi) VALUES (?, 1, ?, GETDATE())", [$d['icra_dairesi'], $user['kullanici_id']]);
                                $result = $db->fetchOne("SELECT MAX(icra_dairesi_id) as id FROM HukukIcraDairesi");
                                $icraDairesiId = $result['id'];
                                $newIcraDaireleri[$icraKey] = $icraDairesiId;
                            }
                        }

                        // Musteri olustur (yoksa)
                        $musteriId = $row['musteri_id'];
                        if (!$musteriId && !empty($d['musteri_adi'])) {
                            $musteriKey = mb_strtolower($d['musteri_adi']);
                            if (isset($newMusteriler[$musteriKey])) { $musteriId = $newMusteriler[$musteriKey]; }
                            else {
                                $cariInsFields = ['cari_adi','cari_tipi_id','cari_aktif','cari_musteri','cari_tedarikci','cari_ulke','cari_sehirler','cari_ilceler','cari_olusturan_kullanici','cari_olusturma_tarihi'];
                                $cariInsPlc = ['?','?','1','1','0','1','?','?','?','GETDATE()'];
                                $cariInsParams = [$d['musteri_adi'], $settings['cari_tipi_id'] ?? 3, $row['sehir_id'], $row['ilce_id'], $user['kullanici_id']];
                                foreach (['cari_unvan','cari_vergi_dairesi','cari_vergi_no','cari_telefon','cari_yetkili_adi','cari_yetkili_telefon','cari_adres','cari_web_sitesi'] as $cf) {
                                    if (!empty($d[$cf])) { $cariInsFields[] = $cf; $cariInsPlc[] = '?'; $cariInsParams[] = $d[$cf]; }
                                }
                                $db->execute("INSERT INTO Cari (" . implode(', ', $cariInsFields) . ") VALUES (" . implode(', ', $cariInsPlc) . ")", $cariInsParams);
                                $result = $db->fetchOne("SELECT MAX(cari_id) as id FROM Cari");
                                $musteriId = $result['id'];
                                $newMusteriler[$musteriKey] = $musteriId;
                            }
                        }

                        $dosyaAcilisTarihi = !empty($d['dosya_acilis_tarihi']) ? convertDateFormat($d['dosya_acilis_tarihi']) : null;
                        $anaTutar = !empty($d['ana_tutar']) ? floatval($d['ana_tutar']) : 0;
                        $tespitTuru = !empty($d['tespit_turu']) ? $d['tespit_turu'] : null;
                        $tespitAdet = !empty($d['tespit_adet']) ? intval($d['tespit_adet']) : null;
                        $tespitTarihi = !empty($d['tespit_tarihi']) ? convertDateFormat($d['tespit_tarihi']) : null;

                        $result = $db->execute("INSERT INTO HukukTakip (takip_cari_id, takip_taraf_id, takip_icra_dairesi_id, takip_dosya_no, takip_aciklama, takip_ana_tutar, takip_dosya_acilis_tarihi, takip_tespit_turu, takip_tespit_adet, takip_tespit_tarihi, Durum, OlusturanKullanici, OlusturmaTarihi) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 1, ?, GETDATE())",
                            [$musteriId, $tarafId, $icraDairesiId, $d['dosya_no'] ?? '', $d['aciklama'] ?? '', $anaTutar, $dosyaAcilisTarihi, $tespitTuru, $tespitAdet, $tespitTarihi, $user['kullanici_id']]);
                        if ($result === false) {
                            error_log("HukukTakip INSERT HATA - cari_id: $musteriId, taraf: $tarafId, icra: $icraDairesiId, dosya_no: " . ($d['dosya_no'] ?? '') . " - SQL Hata: " . print_r(sqlsrv_errors(), true));
                            $skipped++;
                        } else {
                            $imported++;
                        }
                    }
                }
                // ---- SOZLESME IMPORT ----
                if ($settings['modul'] === 'sozlesme') {
                    $newSozCariler = [];
                    foreach ($importData as $row) {
                        if ($row['has_error'] || $row['status'] === 'excel_duplicate') { $skipped++; continue; }
                        $d = $row['mapped_data'];

                        // Sadece guncelle modunda yeni kayitlari atla
                        if ($duplicateAction === 'only_update' && $row['status'] !== 'duplicate') { $skipped++; continue; }

                        // ---- Mukerrer: Guncelle veya Atla ----
                        if ($row['status'] === 'duplicate') {
                            if ($duplicateAction === 'skip') { $skipped++; continue; }

                            $dupId = $row['duplicate_id'];
                            $dupField = $settings['duplicate_field'] ?? '';

                            // sozlesme_id ve hareket_id belirle
                            $sozlesmeId = null;
                            $hareketId = null;
                            $cariId = null;

                            if ($dupField === 'cari_adi') {
                                $sozlesmeId = $dupId;
                                $sozRec = $db->fetchOne("SELECT sozlesme_cari_id FROM Sozlesmeler WHERE sozlesme_id = ?", [$sozlesmeId]);
                                if ($sozRec) $cariId = $sozRec['sozlesme_cari_id'];
                                $harRec = $db->fetchOne("SELECT TOP 1 hareket_id FROM Sozlesme_StokHareketleri WHERE hareket_sozlesme_id = ?", [$sozlesmeId]);
                                if ($harRec) $hareketId = $harRec['hareket_id'];
                            } else {
                                $hareketId = $dupId;
                                $harRec = $db->fetchOne("SELECT hareket_sozlesme_id FROM Sozlesme_StokHareketleri WHERE hareket_id = ?", [$hareketId]);
                                if ($harRec) {
                                    $sozlesmeId = $harRec['hareket_sozlesme_id'];
                                    $sozRec = $db->fetchOne("SELECT sozlesme_cari_id FROM Sozlesmeler WHERE sozlesme_id = ?", [$sozlesmeId]);
                                    if ($sozRec) $cariId = $sozRec['sozlesme_cari_id'];
                                }
                            }

                            // Cari guncelle
                            if ($cariId) {
                                $cariUpdateFields = [];
                                $cariUpdateParams = [];
                                foreach (['cari_unvan','cari_vergi_dairesi','cari_vergi_no','cari_telefon','cari_yetkili_adi','cari_yetkili_telefon','cari_adres','cari_web_sitesi'] as $cf) {
                                    if (!empty($d[$cf])) { $cariUpdateFields[] = "$cf = ?"; $cariUpdateParams[] = $d[$cf]; }
                                }
                                if ($row['sehir_id']) { $cariUpdateFields[] = "cari_sehirler = ?"; $cariUpdateParams[] = $row['sehir_id']; }
                                if ($row['ilce_id']) { $cariUpdateFields[] = "cari_ilceler = ?"; $cariUpdateParams[] = $row['ilce_id']; }
                                if (!empty($cariUpdateFields)) {
                                    $cariUpdateFields[] = "GuncelleyenKullanici = ?"; $cariUpdateParams[] = $user['kullanici_id'];
                                    $cariUpdateFields[] = "GuncellemeTarihi = GETDATE()";
                                    $cariUpdateParams[] = $cariId;
                                    $db->execute("UPDATE Cari SET " . implode(", ", $cariUpdateFields) . " WHERE cari_id = ?", $cariUpdateParams);
                                }
                            }

                            // Sozlesme guncelle
                            if ($sozlesmeId) {
                                $sozUpdateFields = [];
                                $sozUpdateParams = [];
                                if (!empty($d['paket_tanim'])) { $sozUpdateFields[] = "sozlesme_aciklama = ?"; $sozUpdateParams[] = $d['paket_tanim']; }
                                if (!empty($d['personel_adi'])) {
                                    $pAdi = mb_strtolower(trim($d['personel_adi']));
                                    foreach ($personeller as $p) {
                                        $pFullName = mb_strtolower(trim($p['kullanici_ad'] . ' ' . $p['kullanici_soyad']));
                                        if ($pFullName === $pAdi || mb_strtolower(trim($p['kullanici_ad'])) === $pAdi) {
                                            $sozUpdateFields[] = "sozlesme_personel_id = ?"; $sozUpdateParams[] = $p['kullanici_id'];
                                            break;
                                        }
                                    }
                                }
                                if (!empty($sozUpdateFields)) {
                                    $sozUpdateFields[] = "sozlesme_kullanici_id = ?"; $sozUpdateParams[] = $user['kullanici_id'];
                                    $sozUpdateParams[] = $sozlesmeId;
                                    $db->execute("UPDATE Sozlesmeler SET " . implode(", ", $sozUpdateFields) . " WHERE sozlesme_id = ?", $sozUpdateParams);
                                }
                            }

                            // Hareket guncelle
                            if ($hareketId) {
                                $harUpdateFields = [];
                                $harUpdateParams = [];
                                if (!empty($d['uye_no_1'])) { $harUpdateFields[] = "hareket_uye_no_1 = ?"; $harUpdateParams[] = $d['uye_no_1']; }
                                if (!empty($d['uye_no_2'])) { $harUpdateFields[] = "hareket_uye_no_2 = ?"; $harUpdateParams[] = $d['uye_no_2']; }
                                if (!empty($d['hareket_fiyat'])) { $harUpdateFields[] = "hareket_fiyat = ?"; $harUpdateParams[] = floatval($d['hareket_fiyat']); }
                                if (!empty($d['uye_tipi_id'])) { $harUpdateFields[] = "hareket_uye_tipi_id = ?"; $harUpdateParams[] = intval($d['uye_tipi_id']); }
                                if (!empty($d['ticari_grup_id'])) { $harUpdateFields[] = "hareket_ticari_grup_id = ?"; $harUpdateParams[] = intval($d['ticari_grup_id']); }
                                $aktivasyonTarihi = !empty($d['aktivasyon_tarihi']) ? convertDateFormat($d['aktivasyon_tarihi']) : null;
                                $taahutBitis = !empty($d['taahut_bitis']) ? convertDateFormat($d['taahut_bitis']) : null;
                                if ($aktivasyonTarihi) { $harUpdateFields[] = "hareket_aktivasyon_tarihi = ?"; $harUpdateParams[] = $aktivasyonTarihi; }
                                if ($taahutBitis) { $harUpdateFields[] = "hareket_taahut_bitis = ?"; $harUpdateParams[] = $taahutBitis; }
                                if (!empty($harUpdateFields)) {
                                    $harUpdateParams[] = $hareketId;
                                    $db->execute("UPDATE Sozlesme_StokHareketleri SET " . implode(", ", $harUpdateFields) . " WHERE hareket_id = ?", $harUpdateParams);
                                }
                            }

                            $updated++;
                            continue;
                        }

                        $cariId = null;
                        // Her satır icin yeni cari olustur
                        if (!empty($d['cari_adi'])) {
                            $cariKey = mb_strtolower($d['cari_adi']);
                            if (isset($newSozCariler[$cariKey])) { $cariId = $newSozCariler[$cariKey]; }
                            else {
                                $cariInsFields = ['cari_adi','cari_tipi_id','cari_aktif','cari_musteri','cari_tedarikci','cari_ulke','cari_sehirler','cari_ilceler','cari_olusturan_kullanici','cari_olusturma_tarihi'];
                                $cariInsPlc = ['?','?','1','1','0','1','?','?','?','GETDATE()'];
                                $cariInsParams = [$d['cari_adi'], $settings['cari_tipi_id'] ?? 1, $row['sehir_id'], $row['ilce_id'], $user['kullanici_id']];
                                foreach (['cari_unvan','cari_vergi_dairesi','cari_vergi_no','cari_telefon','cari_yetkili_adi','cari_yetkili_telefon','cari_adres','cari_web_sitesi'] as $cf) {
                                    if (!empty($d[$cf])) { $cariInsFields[] = $cf; $cariInsPlc[] = '?'; $cariInsParams[] = $d[$cf]; }
                                }
                                $db->execute("INSERT INTO Cari (" . implode(', ', $cariInsFields) . ") VALUES (" . implode(', ', $cariInsPlc) . ")", $cariInsParams);
                                $result = $db->fetchOne("SELECT MAX(cari_id) as id FROM Cari");
                                $cariId = $result['id'];
                                $newSozCariler[$cariKey] = $cariId;
                            }
                        }
                        if (!$cariId) { $skipped++; continue; }

                        // Tarihleri donustur
                        $aktivasyonTarihi = null;
                        $taahutBitis = null;
                        if (!empty($d['aktivasyon_tarihi'])) $aktivasyonTarihi = convertDateFormat($d['aktivasyon_tarihi']);
                        if (!empty($d['taahut_bitis'])) $taahutBitis = convertDateFormat($d['taahut_bitis']);

                        $sozlesmeTarihi = $taahutBitis ?: date('Y-m-d');

                        // Personel eslestir
                        $personelId = null;
                        if (!empty($d['personel_adi'])) {
                            $pAdi = mb_strtolower(trim($d['personel_adi']));
                            foreach ($personeller as $p) {
                                $pFullName = mb_strtolower(trim($p['kullanici_ad'] . ' ' . $p['kullanici_soyad']));
                                if ($pFullName === $pAdi || mb_strtolower(trim($p['kullanici_ad'])) === $pAdi) {
                                    $personelId = $p['kullanici_id'];
                                    break;
                                }
                            }
                        }

                        $insertResult = $db->fetchOne("
                            INSERT INTO Sozlesmeler (sozlesme_sezon_id, sozlesme_tarih, sozlesme_cari_id, sozlesme_no, sozlesme_aciklama, sozlesme_personel_id, sozlesme_fatura_no, sozlesme_dosyalar, sozlesme_kullanici_id, sozlesme_olusturma_tarihi, sozlesme_durum)
                            OUTPUT INSERTED.sozlesme_id
                            VALUES (?, ?, ?, '', ?, ?, '', '[]', ?, GETDATE(), 1)
                        ", [$settings['sezon_id'], $sozlesmeTarihi, $cariId, $d['paket_tanim'] ?? '', $personelId, $user['kullanici_id']]);

                        $sozlesmeId = $insertResult['sozlesme_id'] ?? null;
                        if (!$sozlesmeId) { $skipped++; continue; }

                        $db->execute("
                            INSERT INTO Sozlesme_StokHareketleri (hareket_sozlesme_id, hareket_uye_tipi_id, hareket_ticari_grup_id, hareket_uye_no_1, hareket_uye_no_2, hareket_urun_hizmet_id, hareket_fiyat, hareket_aktivasyon_tarihi, hareket_taahut_bitis, hareket_durum, hareket_olusturma_tarihi)
                            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, GETDATE())
                        ", [
                            $sozlesmeId,
                            !empty($d['uye_tipi_id']) ? intval($d['uye_tipi_id']) : null,
                            !empty($d['ticari_grup_id']) ? intval($d['ticari_grup_id']) : null,
                            $d['uye_no_1'] ?? null,
                            $d['uye_no_2'] ?? null,
                            $settings['default_urun_id'] ?: null,
                            !empty($d['hareket_fiyat']) ? floatval($d['hareket_fiyat']) : 0,
                            $aktivasyonTarihi,
                            $taahutBitis,
                            $settings['hareket_durum'] ?? 1
                        ]);

                        $imported++;
                    }
                }

                unset($_SESSION['import_data']);
                unset($_SESSION['import_settings']);

                $msg = "Import tamamlandı. $imported yeni kayit eklendi";
                if ($updated > 0) $msg .= ", $updated kayit guncellendi";
                if ($skipped > 0) $msg .= ", $skipped kayit atlandı";
                $msg .= ".";

                echo json_encode(['success' => true, 'message' => $msg, 'imported' => $imported, 'updated' => $updated, 'skipped' => $skipped]);
                break;

            case 'clear':
                unset($_SESSION['import_data']);
                unset($_SESSION['import_settings']);
                echo json_encode(['success' => true, 'message' => 'Veriler temizlendi']);
                break;

            default:
                echo json_encode(['success' => false, 'message' => 'Gecersiz islem']);
        }
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'message' => 'Hata: ' . $e->getMessage()]);
    }
    exit;
}

// Tarih format donusturme
function convertDateFormat($dateStr) {
    $dateStr = trim($dateStr);
    if (empty($dateStr)) return null;
    // Excel seri numarasi kontrolu (ornegin 45678)
    if (is_numeric($dateStr) && $dateStr > 25000 && $dateStr < 100000) {
        $unixTs = ($dateStr - 25569) * 86400;
        return date('Y-m-d', intval($unixTs));
    }
    $formats = ['d.m.y', 'd.m.Y', 'd/m/y', 'd/m/Y'];
    foreach ($formats as $format) {
        $date = DateTime::createFromFormat($format, $dateStr);
        if ($date) return $date->format('Y-m-d');
    }
    // ISO formatında ise direkt dondur
    if (preg_match('/^\d{4}-\d{2}-\d{2}/', $dateStr)) return substr($dateStr, 0, 10);
    return null;
}
?><!DOCTYPE html>
<html lang="tr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars($pageTitle) ?> - <?= htmlspecialchars($siteTitle) ?></title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/@fontsource/source-sans-3@5.0.12/index.css" crossorigin="anonymous">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.13.1/font/bootstrap-icons.min.css" crossorigin="anonymous">
    <link rel="stylesheet" href="/admin/assets/css/adminlte.min.css">
    <link href="https://cdn.jsdelivr.net/npm/sweetalert2@11/dist/sweetalert2.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/select2-bootstrap-5-theme@1.3.0/dist/select2-bootstrap-5-theme.min.css" rel="stylesheet">
    <link rel="stylesheet" href="/admin/assets/css/custom.css">
    <script src="https://cdn.sheetjs.com/xlsx-0.20.1/package/dist/xlsx.full.min.js"></script>
    <style>
        .step-card { transition: all 0.3s ease; }
        .step-card.disabled-step { opacity: 0.5; pointer-events: none; }
        .step-number { display: inline-flex; align-items: center; justify-content: center; width: 28px; height: 28px; border-radius: 50%; background: #0d6efd; color: #fff; font-weight: bold; font-size: 14px; margin-right: 8px; }
        .step-number.completed { background: #198754; }
        .modul-card { cursor: pointer; transition: all 0.2s; border: 2px solid transparent; }
        .modul-card:hover { border-color: #0d6efd; transform: translateY(-2px); }
        .modul-card.selected { border-color: #0d6efd; background: rgba(13,110,253,0.05); }
        .modul-settings { display: none; }
        .modul-settings.active { display: block; }
        .preview-badge { font-size: 0.8em; }
    </style>
</head>
<body class="layout-fixed sidebar-expand-lg sidebar-open bg-body-tertiary">
    <div class="app-wrapper">
<?php
include __DIR__ . '/../includes/header.php';
include __DIR__ . '/../includes/sidebar.php';
?>

        <main class="app-main">
            <div class="app-content-header">
                <div class="container-fluid">
                    <div class="row">
                        <div class="col-sm-6"><h3 class="mb-0"><?= htmlspecialchars($pageTitle) ?></h3></div>
                        <div class="col-sm-6">
                            <ol class="breadcrumb float-sm-end">
                                <li class="breadcrumb-item"><a href="anasayfa.php">Ana Sayfa</a></li>
                                <li class="breadcrumb-item active">Toplu Import</li>
                            </ol>
                        </div>
                    </div>
                </div>
            </div>

            <div class="app-content">
                <div class="container-fluid">
                    <!-- ADIM 1: Modul Secimi & Dosya Yukleme -->
                    <div class="card card-primary card-outline mb-3 step-card" id="step1Card">
                        <div class="card-header">
                            <h3 class="card-title">
                                <span class="step-number" id="step1Badge">1</span>
                                <i class="bi bi-gear"></i> Import Ayarlari
                            </h3>
                            <div class="card-tools">
                                <button type="button" class="btn btn-success btn-sm" id="downloadTemplateBtn">
                                    <i class="bi bi-download"></i> Sablon Indir
                                </button>
                            </div>
                        </div>
                        <div class="card-body">
                            <!-- Modul Secimi -->
                            <label class="form-label fw-bold mb-2">Import Modulu Secin</label>
                            <div class="row mb-3 g-3">
                                <div class="col-md-6">
                                    <div class="card modul-card text-center p-3" data-modul="hukuk">
                                        <i class="bi bi-briefcase-fill text-danger" style="font-size: 2em;"></i>
                                        <h6 class="mt-2 mb-0">Suc Duyurusu Import</h6>
                                        <small class="text-muted">Suc duyurusu dosyasi yukleme + Cari otomatik olusturma</small>
                                    </div>
                                </div>
                                <div class="col-md-6">
                                    <div class="card modul-card text-center p-3" data-modul="sozlesme">
                                        <i class="bi bi-file-earmark-text-fill text-success" style="font-size: 2em;"></i>
                                        <h6 class="mt-2 mb-0">Sozlesme Import</h6>
                                        <small class="text-muted">Sozlesme ve hareket yukleme</small>
                                    </div>
                                </div>
                            </div>

                            <hr>

                            <div class="row">
                                <!-- Dosya Secimi -->
                                <div class="col-md-6">
                                    <div class="mb-3">
                                        <label class="form-label fw-bold">Excel / CSV Dosyasi</label>
                                        <input type="file" class="form-control" id="excelFile" accept=".csv,.xlsx,.xls">
                                        <div class="form-text">Desteklenen: .xlsx, .xls, .csv</div>
                                    </div>
                                </div>

                                <!-- Modul Ozel Ayarlar -->
                                <div class="col-md-6">
                                    <!-- Sozlesme Ayarlar -->
                                    <div class="modul-settings" id="sozlesmeSettings">
                                        <div class="alert alert-info mb-0 small">
                                            <i class="bi bi-info-circle"></i> Sezon, Urun/Hizmet, Cari Tip ve Sozlesme Durum secimi <strong>Sutun Eslestirme</strong> adiminda yapılacaktır.
                                        </div>
                                    </div>

                                    <!-- Hukuk Ayarlar (ozel ayar yok) -->
                                    <div class="modul-settings" id="hukukSettings">
                                        <div class="alert alert-light mb-0 small">
                                            <i class="bi bi-info-circle"></i> Taraf, Icra Dairesi ve Musteri kayitlari yoksa otomatik olusturulur.
                                        </div>
                                    </div>
                                </div>
                            </div>

                            <div class="mt-3">
                                <button type="button" class="btn btn-primary" id="readFileBtn" disabled>
                                    <i class="bi bi-file-earmark-spreadsheet"></i> Dosyayı Oku
                                </button>
                                <button type="button" class="btn btn-secondary" id="resetAllBtn" style="display:none;">
                                    <i class="bi bi-arrow-counterclockwise"></i> Sıfırla
                                </button>
                            </div>
                        </div>
                    </div>
                    <!-- ADIM 2: Sutun Eslestirme -->
                    <div class="card card-warning card-outline mb-3 step-card disabled-step" id="step2Card">
                        <div class="card-header">
                            <h3 class="card-title">
                                <span class="step-number" id="step2Badge">2</span>
                                <i class="bi bi-arrows-angle-expand"></i> Sutun Eslestirme
                            </h3>
                            <div class="card-tools">
                                <span class="badge bg-info" id="excelColCount">0 sutun</span>
                                <span class="badge bg-primary" id="excelRowCount">0 satır</span>
                            </div>
                        </div>
                        <div class="card-body">
                            <p class="text-muted small mb-3">
                                <i class="bi bi-lightbulb"></i>
                                Excel sutunlarini veritabani alanlarina eslestirin. <strong class="text-danger">Zorunlu alanlar</strong> isaretlidir.
                            </p>
                            <div class="row">
                                <div class="col-md-6">
                                    <div class="table-responsive" style="max-height: 500px; overflow-y: auto;">
                                        <table class="table table-sm table-bordered mb-0" id="mappingTable">
                                            <thead class="table-light sticky-top">
                                                <tr>
                                                    <th width="5%">#</th>
                                                    <th width="25%">Veritabani Alani</th>
                                                    <th width="30%">Excel Sutunu</th>
                                                    <th width="25%">On Izleme</th>
                                                    <th width="15%" class="text-center">Mukerrer</th>
                                                </tr>
                                            </thead>
                                            <tbody id="mappingBody"></tbody>
                                        </table>
                                    </div>
                                </div>
                                <div class="col-md-6">
                                    <div class="card bg-light">
                                        <div class="card-header py-2">
                                            <h6 class="card-title mb-0"><i class="bi bi-eye"></i> Excel On Izleme (Ilk 5 Satır)</h6>
                                        </div>
                                        <div class="card-body p-0" style="max-height: 400px; overflow: auto;">
                                            <table class="table table-sm table-bordered table-striped mb-0">
                                                <thead class="table-dark sticky-top" id="excelPreviewHead"></thead>
                                                <tbody id="excelPreviewBody"></tbody>
                                            </table>
                                        </div>
                                    </div>
                                </div>
                            </div>
                            <!-- Statik Alanlar (Sozlesme) -->
                            <div class="mt-3" id="staticFieldsSection" style="display:none;">
                                <hr>
                                <h6 class="fw-bold"><i class="bi bi-sliders"></i> Tum Satirlara Uygulanacak Sabit Alanlar</h6>
                                <div class="row g-3">
                                    <div class="col-md-3">
                                        <label class="form-label fw-bold">Sezon</label>
                                        <select class="form-select form-select-sm" id="sezonSelect">
                                            <option value="">Seciniz...</option>
                                            <?php foreach ($sezonlar as $s): ?>
                                                <option value="<?= $s['sezon_id'] ?>"><?= htmlspecialchars($s['sezon_ad']) ?></option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                    <div class="col-md-3">
                                        <label class="form-label fw-bold">Urun / Hizmet</label>
                                        <select class="form-select form-select-sm" id="defaultUrunSelect">
                                            <option value="">Seciniz...</option>
                                            <?php foreach ($urunler as $u): ?>
                                                <option value="<?= $u['urun_hizmet_id'] ?>"><?= htmlspecialchars($u['urun_hizmet_adi']) ?></option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                    <div class="col-md-3">
                                        <label class="form-label fw-bold">Cari Tipi</label>
                                        <select class="form-select form-select-sm" id="cariTipiSelect">
                                            <?php foreach ($cariTipleri as $ct): ?>
                                                <option value="<?= $ct['cari_tipi_id'] ?>"<?= $ct['cari_tipi_id'] == 1 ? ' selected' : '' ?>><?= htmlspecialchars($ct['cari_tipi_ad']) ?></option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                    <div class="col-md-3">
                                        <label class="form-label fw-bold">Sozlesme Durum</label>
                                        <select class="form-select form-select-sm" id="hareketDurumSelect">
                                            <option value="1" selected>Aktif</option>
                                            <option value="0">Pasif</option>
                                        </select>
                                    </div>
                                </div>
                            </div>

                            <div class="mt-3">
                                <button type="button" class="btn btn-outline-secondary me-2" onclick="backToStep1()">
                                    <i class="bi bi-arrow-left"></i> Geri
                                </button>
                                <button type="button" class="btn btn-warning" id="analyzeBtn">
                                    <i class="bi bi-search"></i> Analiz Et
                                </button>
                                <button type="button" class="btn btn-outline-secondary btn-sm" id="autoMatchBtn">
                                    <i class="bi bi-magic"></i> Otomatik Eslestir
                                </button>
                            </div>
                        </div>
                    </div>

                    <!-- ADIM 3: Analiz Sonucu -->
                    <div class="card card-info card-outline mb-3" id="summaryCard" style="display:none;">
                        <div class="card-header">
                            <h3 class="card-title">
                                <span class="step-number" id="step3Badge">3</span>
                                <i class="bi bi-bar-chart"></i> Analiz Sonucu
                            </h3>
                        </div>
                        <div class="card-body">
                            <div class="row text-center g-3" id="summaryBoxes">
                                <!-- JS ile doldurulacak -->
                            </div>
                            <div class="row mt-3">
                                <div class="col-md-6" id="duplicateActionDiv">
                                    <label class="form-label fw-bold">Mukerrer Kayitlar Icin:</label>
                                    <div class="form-check">
                                        <input class="form-check-input" type="radio" name="duplicateAction" id="dupSkip" value="skip" checked>
                                        <label class="form-check-label" for="dupSkip">Atla (ekleme)</label>
                                    </div>
                                    <div class="form-check">
                                        <input class="form-check-input" type="radio" name="duplicateAction" id="dupUpdate" value="update">
                                        <label class="form-check-label" for="dupUpdate">Guncelle (mevcut kayitlari guncelle, yeni kayitlari da ekle)</label>
                                    </div>
                                    <div class="form-check">
                                        <input class="form-check-input" type="radio" name="duplicateAction" id="dupOnlyUpdate" value="only_update">
                                        <label class="form-check-label" for="dupOnlyUpdate">Sadece Guncelle <span class="badge bg-warning text-dark">Yeni kayit eklenmez</span></label>
                                    </div>
                                </div>
                                <div class="col-md-6 text-end d-flex align-items-end justify-content-end gap-2">
                                    <button type="button" class="btn btn-outline-secondary" onclick="backToStep2()">
                                        <i class="bi bi-arrow-left"></i> Geri
                                    </button>
                                    <button type="button" class="btn btn-success btn-lg" id="importBtn">
                                        <i class="bi bi-database-add"></i> Import Et
                                    </button>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Onizleme Tablosu -->
                    <div class="card card-primary card-outline" id="previewCard" style="display:none;">
                        <div class="card-header">
                            <h3 class="card-title"><i class="bi bi-table"></i> Veri Onizleme</h3>
                            <div class="card-tools">
                                <span class="badge bg-success me-1"><i class="bi bi-plus"></i> Yeni</span>
                                <span class="badge bg-warning me-1"><i class="bi bi-exclamation"></i> Mukerrer</span>
                                <span class="badge bg-danger"><i class="bi bi-x"></i> Hata</span>
                            </div>
                        </div>
                        <div class="card-body p-0">
                            <div class="table-responsive" style="max-height: 500px; overflow-y: auto;">
                                <table class="table table-bordered table-sm table-hover mb-0">
                                    <thead class="table-dark sticky-top" id="previewHead"></thead>
                                    <tbody id="previewBody"></tbody>
                                </table>
                            </div>
                        </div>
                    </div>

                </div>
            </div>
        </main>
        <?php include __DIR__ . '/../includes/footer.php'; ?>
    </div>

    <script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/@popperjs/core@2.11.8/dist/umd/popper.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.7/dist/js/bootstrap.min.js"></script>
    <script src="/admin/assets/js/adminlte.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11/dist/sweetalert2.all.min.js"></script>
    <script src="/admin/assets/js/custom.js"></script>
    <script>
    // ==========================================
    // MODUL TANIMLARI
    // ==========================================
    const modulConfigs = {
        hukuk: {
            label: 'Suc Duyurusu Import',
            requiredField: '',
            requiredLabel: '',
            fields: [
                { key: 'taraf',                 label: 'Taraf',                 required: false },
                { key: 'icra_dairesi',          label: 'Icra Dairesi',          required: false },
                { key: 'dosya_no',              label: 'Dosya No',              required: false },
                { key: 'musteri_adi',           label: 'Musteri Adi',           required: false },
                { key: 'cari_unvan',            label: 'Unvan',                 required: false },
                { key: 'cari_vergi_dairesi',    label: 'Vergi Dairesi',         required: false },
                { key: 'cari_vergi_no',         label: 'Vergi No',              required: false },
                { key: 'cari_telefon',          label: 'Telefon',               required: false },
                { key: 'cari_yetkili_adi',      label: 'Yetkili Adi Soyadi',    required: false },
                { key: 'cari_yetkili_telefon',  label: 'Yetkili Telefon',       required: false },
                { key: 'aciklama',              label: 'Aciklama',              required: false },
                { key: 'ana_tutar',             label: 'Ana Tutar',             required: false },
                { key: 'dosya_acilis_tarihi',   label: 'Dosya Acilis Tarihi',   required: false },
                { key: 'sehir',                 label: 'Il',                    required: false },
                { key: 'ilce',                  label: 'Ilce',                  required: false },
                { key: 'cari_adres',            label: 'Adres',                 required: false },
                { key: 'cari_web_sitesi',       label: 'Web Sitesi',            required: false },
                { key: 'tespit_turu',           label: 'Tespit Turu',           required: false },
                { key: 'tespit_adet',           label: 'Tespit Adet',           required: false },
                { key: 'tespit_tarihi',         label: 'Tespit Tarihi',         required: false }
            ],
            autoMatch: {
                'taraf': ['taraf', 'taraflar'],
                'icra_dairesi': ['icra dairesi', 'icra', 'daire'],
                'dosya_no': ['dosya no', 'dosya numarasi', 'esas no', 'account number'],
                'musteri_adi': ['musteri', 'musteri adi', 'cari', 'cari adi', 'firma', 'organisation', 'organization'],
                'cari_unvan': ['unvan'],
                'cari_vergi_dairesi': ['vergi dairesi'],
                'cari_vergi_no': ['vergi no', 'vergi numarasi'],
                'cari_telefon': ['telefon', 'tel'],
                'cari_yetkili_adi': ['yetkili', 'yetkili adi', 'yetkili ad soyad'],
                'cari_yetkili_telefon': ['yetkili telefon', 'yetkili tel'],
                'aciklama': ['aciklama', 'not', 'detay'],
                'ana_tutar': ['tutar', 'ana tutar', 'miktar', 'borc', 'fiyat'],
                'dosya_acilis_tarihi': ['tarih', 'dosya acilis', 'acilis tarihi', 'kayit tarihi', 'ekleme tarihi'],
                'sehir': ['sehir', 'il', 'city'],
                'ilce': ['ilce'],
                'cari_adres': ['adres'],
                'cari_web_sitesi': ['web', 'web sitesi', 'website', 'internet', 'site'],
                'tespit_turu': ['tespit turu', 'tur', 'tespit tip', 'tip'],
                'tespit_adet': ['tespit adet', 'tespit', 'adet'],
                'tespit_tarihi': ['tespit tarihi', 'tespit tarih']
            },
            templateHeaders: ['Taraf', 'Icra Dairesi', 'Dosya No', 'Musteri Adi', 'Unvan', 'Vergi Dairesi', 'Vergi No', 'Telefon', 'Yetkili Adi', 'Yetkili Telefon', 'Aciklama', 'Ana Tutar', 'Dosya Acilis Tarihi', 'Il', 'Ilce', 'Adres', 'Web Sitesi', 'Tespit Turu', 'Tespit Adet', 'Tespit Tarihi'],
            templateSample: [
                ['Alacaklı', 'Izmir 2. Icra Dairesi', '2025/6650', 'Mehmet Yılmaz', 'Yılmaz Ticaret', 'Konak VD', '1234567890', '0532 111 2233', 'Ahmet Yılmaz', '0533 222 3344', 'Ornek aciklama', '15000.00', '05.08.2025', 'Izmir', 'Konak', 'Ataturk Cad. No:5', 'www.yilmazticaret.com', 'Fiziksel', '5', '15.03.2025']
            ]
        },
        sozlesme: {
            label: 'Sozlesme Import',
            requiredField: '',
            requiredLabel: '',
            fields: [
                { key: 'cari_adi',              label: 'Musteri Adi',           required: false },
                { key: 'cari_unvan',            label: 'Unvan',                 required: false },
                { key: 'cari_vergi_dairesi',    label: 'Vergi Dairesi',         required: false },
                { key: 'cari_vergi_no',         label: 'Vergi No',              required: false },
                { key: 'cari_telefon',          label: 'Telefon',               required: true },
                { key: 'cari_yetkili_adi',      label: 'Yetkili Adi Soyadi',    required: false },
                { key: 'cari_yetkili_telefon',  label: 'Yetkili Telefon',       required: false },
                { key: 'sehir',                 label: 'Il',                    required: false },
                { key: 'ilce',                  label: 'Ilce',                  required: false },
                { key: 'cari_adres',            label: 'Adres',                 required: false },
                { key: 'cari_web_sitesi',       label: 'Web Sitesi',            required: false },
                { key: 'uye_tipi_id',           label: 'Uye Tipi ID',          required: false },
                { key: 'uye_no_1',              label: 'Uye No 1',             required: false },
                { key: 'uye_no_2',              label: 'Uye No 2',             required: false },
                { key: 'ticari_grup_id',        label: 'Ticari Grup ID',        required: false },
                { key: 'paket_tanim',           label: 'Paket / Aciklama',      required: false },
                { key: 'hareket_fiyat',         label: 'Sozlesme Tutar',        required: false },
                { key: 'personel_adi',          label: 'Personel Adi',          required: false },
                { key: 'aktivasyon_tarihi',     label: 'Aktivasyon Tarihi',     required: false },
                { key: 'taahut_bitis',          label: 'Taahut Bitis',          required: false }
            ],
            autoMatch: {
                'cari_adi': ['cari', 'cari adi', 'firma', 'musteri', 'musteri adi', 'isim', 'ad', 'organisation', 'organization'],
                'cari_unvan': ['unvan'],
                'cari_vergi_dairesi': ['vergi dairesi'],
                'cari_vergi_no': ['vergi no', 'vergi numarasi'],
                'cari_telefon': ['telefon', 'tel'],
                'cari_yetkili_adi': ['yetkili', 'yetkili adi', 'yetkili ad soyad'],
                'cari_yetkili_telefon': ['yetkili telefon', 'yetkili tel'],
                'sehir': ['sehir', 'il', 'city'],
                'ilce': ['ilce'],
                'cari_adres': ['adres'],
                'cari_web_sitesi': ['web', 'web sitesi', 'website', 'internet', 'site'],
                'uye_tipi_id': ['uye tipi', 'uye tip', 'tip id'],
                'uye_no_1': ['uye no 1', 'uye no', 'abone no'],
                'uye_no_2': ['uye no 2', 'eski no'],
                'ticari_grup_id': ['ticari grup', 'grup id', 'grup'],
                'paket_tanim': ['paket', 'paket tanim', 'aciklama', 'tanim'],
                'hareket_fiyat': ['fiyat', 'tutar', 'sozlesme tutar', 'ucret', 'bedel'],
                'personel_adi': ['personel', 'personel adi', 'temsilci', 'satis temsilcisi'],
                'aktivasyon_tarihi': ['aktivasyon', 'aktivasyon tarihi', 'baslangic'],
                'taahut_bitis': ['taahut', 'taahut bitis', 'bitis', 'bitis tarihi']
            },
            templateHeaders: ['Cari Adi', 'Unvan', 'Vergi Dairesi', 'Vergi No', 'Telefon', 'Yetkili Adi', 'Yetkili Telefon', 'Il', 'Ilce', 'Adres', 'Web Sitesi', 'Uye Tipi ID', 'Uye No 1', 'Uye No 2', 'Hareket Durum', 'Ticari Grup ID', 'Paket Tanim', 'Sozlesme Tutar', 'Personel Adi', 'Aktivasyon Tarihi', 'Taahut Bitis'],
            templateSample: [
                ['ABC Teknoloji Ltd Sti', 'ABC Teknoloji', 'Kadikoy VD', '9876543210', '0212 111 2233', 'Ali Veli', '0533 444 5566', 'Istanbul', 'Kadikoy', 'Bagdat Cad. No:10', 'www.abcteknoloji.com', '1', '100001', '200001', '1', '1', 'Gold Paket', '5000.00', 'Ahmet Yılmaz', '01.01.2026', '01.01.2027']
            ]
        }
    };

    let selectedModul = null;
    let excelHeaders = [];
    let excelData = [];
    let currentMapping = {};
    let analysisData = null;
    // ==========================================
    // MODUL SECIM
    // ==========================================
    $(document).on('click', '.modul-card', function() {
        const modul = $(this).data('modul');
        if (selectedModul === modul) return;
        selectedModul = modul;

        $('.modul-card').removeClass('border-primary border-3').addClass('border');
        $(this).removeClass('border').addClass('border-primary border-3');

        // Modul ayarlarini goster/gizle
        $('#sozlesmeSettings, #hukukSettings').addClass('d-none');
        if (modul === 'sozlesme') $('#sozlesmeSettings').removeClass('d-none');
        else if (modul === 'hukuk') $('#hukukSettings').removeClass('d-none');

        // Suc Duyurusu (hukuk) secildiginde Cari Tipi varsayilani 4 yap
        if (modul === 'hukuk') {
            $('#cariTipiSelect').val('4').trigger('change.select2');
        } else if (modul === 'sozlesme') {
            $('#cariTipiSelect').val('1').trigger('change.select2');
        }

        // Mapping tablosu varsa yeniden olustur
        if (excelHeaders.length > 0) {
            buildMappingTable();
        }

        // Buton kontrolu
        const hasFile = $('#excelFile').val() !== '';
        $('#readFileBtn').prop('disabled', !hasFile);
        $('#resetAllBtn').show();
    });

    // ==========================================
    // DOSYA OKUMA
    // ==========================================
    function readExcelFile() {
        if (!selectedModul) {
            showToast('Lutfen once bir modul secin!', 'warning');
            return;
        }
        const fileInput = document.getElementById('excelFile');
        if (!fileInput.files.length) {
            showToast('Lutfen bir Excel dosyasi secin!', 'warning');
            return;
        }
        const file = fileInput.files[0];
        const ext = file.name.split('.').pop().toLowerCase();
        if (!['xlsx', 'xls', 'csv'].includes(ext)) {
            showToast('Desteklenen formatlar: .xlsx, .xls, .csv', 'error');
            return;
        }

        showLoading();
        const reader = new FileReader();
        reader.onload = function(e) {
            try {
                const data = new Uint8Array(e.target.result);
                const workbook = XLSX.read(data, { type: 'array', cellDates: false, cellText: true, raw: true });
                const firstSheet = workbook.Sheets[workbook.SheetNames[0]];
                const jsonData = XLSX.utils.sheet_to_json(firstSheet, { header: 1, raw: true, defval: '' });

                if (jsonData.length < 2) {
                    showToast('Dosyada yeterli veri yok! En az 1 baslik + 1 veri satiri olmalı.', 'error');
                    hideLoading();
                    return;
                }

                excelHeaders = jsonData[0].map(h => (h || '').toString().trim());
                excelData = jsonData.slice(1).filter(row => row.some(cell => cell !== '' && cell !== null && cell !== undefined));

                if (excelData.length === 0) {
                    showToast('Dosyada veri satiri bulunamadı!', 'error');
                    hideLoading();
                    return;
                }

                buildMappingTable();
                buildExcelPreview();
                autoMatch();

                $('#excelColCount').text(excelHeaders.length + ' sutun');
                $('#excelRowCount').text(excelData.length + ' satır');
                $('#step1Card').addClass('d-none');
                $('#step2Card').removeClass('d-none disabled-step');

                // Sozlesme ve Hukuk (Suc Duyurusu) ise statik alanlar bolumunu goster
                if (selectedModul === 'sozlesme' || selectedModul === 'hukuk') {
                    $('#staticFieldsSection').show();
                } else {
                    $('#staticFieldsSection').hide();
                }

                showToast(`${excelHeaders.length} sutun ve ${excelData.length} satır okundu.`, 'success');
            } catch(err) {
                console.error('Excel okuma hatası:', err);
                showToast('Dosya okunamadı: ' + err.message, 'error');
            }
            hideLoading();
        };
        reader.readAsArrayBuffer(file);
    }
    // ==========================================
    // MAPPING TABLOSU
    // ==========================================
    function buildMappingTable() {
        const config = modulConfigs[selectedModul];
        let html = '';
        config.fields.forEach((field, idx) => {
            let options = '<option value="">-- Secim Yapılmadı --</option>';
            excelHeaders.forEach((h, i) => {
                options += `<option value="${i}">${escapeHtml(h)}</option>`;
            });
            const reqBadge = field.required ? ' <span class="badge bg-danger">Zorunlu</span>' : '';
            html += `<tr>
                <td>${idx + 1}</td>
                <td><strong>${escapeHtml(field.label)}</strong>${reqBadge}</td>
                <td>
                    <select class="form-select form-select-sm mapping-select" data-field="${field.key}" id="map_${field.key}">
                        ${options}
                    </select>
                </td>
                <td class="preview-cell" id="prev_${field.key}"><span class="text-muted">-</span></td>
                <td class="text-center">
                    <input type="radio" class="form-check-input dup-radio" name="duplicateField" value="${field.key}" id="dup_${field.key}" disabled>
                </td>
            </tr>`;
        });
        $('#mappingBody').html(html);

        // Mapping degistiginde preview guncelle + radio aktiflik guncelle
        $('.mapping-select').off('change').on('change', function() {
            const field = $(this).data('field');
            const colIndex = $(this).val();
            if (colIndex !== '') {
                let sample = excelData[0] ? (excelData[0][parseInt(colIndex)] || '-') : '-';
                if (dateFields.includes(field)) sample = excelDateToStr(sample);
                $(`#prev_${field}`).html(`<span class="text-success">${escapeHtml(truncate(String(sample), 50))}</span>`);
                $(`#dup_${field}`).prop('disabled', false);
            } else {
                $(`#prev_${field}`).html('<span class="text-muted">-</span>');
                if ($(`#dup_${field}`).is(':checked')) {
                    $(`#dup_${field}`).prop('checked', false);
                }
                $(`#dup_${field}`).prop('disabled', true);
            }
        });
    }

    // ==========================================
    // EXCEL ON IZLEME (ilk 5 satır)
    // ==========================================
    function buildExcelPreview() {
        let headHtml = '<tr><th>#</th>';
        excelHeaders.forEach(h => {
            headHtml += `<th>${escapeHtml(h)}</th>`;
        });
        headHtml += '</tr>';
        $('#excelPreviewHead').html(headHtml);

        let bodyHtml = '';
        const previewRows = excelData.slice(0, 5);
        previewRows.forEach((row, idx) => {
            bodyHtml += `<tr><td>${idx + 1}</td>`;
            excelHeaders.forEach((h, ci) => {
                bodyHtml += `<td>${escapeHtml(truncate(String(row[ci] || ''), 40))}</td>`;
            });
            bodyHtml += '</tr>';
        });
        if (excelData.length > 5) {
            bodyHtml += `<tr><td colspan="${excelHeaders.length + 1}" class="text-center text-muted">... toplam ${excelData.length} satır (ilk 5 gosteriliyor)</td></tr>`;
        }
        $('#excelPreviewBody').html(bodyHtml);
    }

    // ==========================================
    // OTOMATIK ESLESTIRME
    // ==========================================
    function autoMatch() {
        const config = modulConfigs[selectedModul];
        let matchCount = 0;
        config.fields.forEach(field => {
            const keywords = config.autoMatch[field.key] || [];
            let bestMatch = -1;
            for (let i = 0; i < excelHeaders.length; i++) {
                const hNorm = normalizeStr(excelHeaders[i]);
                for (const kw of keywords) {
                    if (hNorm === normalizeStr(kw) || hNorm.includes(normalizeStr(kw))) {
                        bestMatch = i;
                        break;
                    }
                }
                if (bestMatch >= 0) break;
            }
            if (bestMatch >= 0) {
                $(`#map_${field.key}`).val(bestMatch).trigger('change');
                matchCount++;
            }
        });
        if (matchCount > 0) {
            showToast(`${matchCount} sutun otomatik eslestirildi`, 'info');
        }
    }

    // Excel tarih seri numarasini YYYY-MM-DD string'e cevir
    const dateFields = ['aktivasyon_tarihi', 'taahut_bitis', 'dosya_acilis_tarihi', 'tespit_tarihi'];
    function excelDateToStr(val) {
        if (val === '' || val === null || val === undefined) return '';
        // Sayısal seri numarasi kontrolu
        const num = Number(val);
        if (!isNaN(num) && num > 25000 && num < 100000) {
            // Excel epoch: 1900-01-01 (1 tabanli, 29 Subat 1900 bug'i)
            const utcDays = Math.floor(num) - 25569;
            const d = new Date(utcDays * 86400 * 1000);
            if (!isNaN(d.getTime())) {
                const yy = d.getUTCFullYear();
                const mm = String(d.getUTCMonth() + 1).padStart(2, '0');
                const dd = String(d.getUTCDate()).padStart(2, '0');
                return `${yy}-${mm}-${dd}`;
            }
        }
        return String(val).trim();
    }

    function normalizeStr(str) {
        return str.toLowerCase()
            .replace(/\u00e7/g, 'c').replace(/\u011f/g, 'g').replace(/\u0131/g, 'i')
            .replace(/\u00f6/g, 'o').replace(/\u015f/g, 's').replace(/\u00fc/g, 'u')
            .replace(/\u00c7/g, 'c').replace(/\u011e/g, 'g').replace(/\u0130/g, 'i')
            .replace(/\u00d6/g, 'o').replace(/\u015e/g, 's').replace(/\u00dc/g, 'u')
            .replace(/[^a-z0-9]/g, ' ').trim();
    }
    // ==========================================
    // ANALIZ - SUNUCUYA GONDER
    // ==========================================
    function analyzeData() {
        const config = modulConfigs[selectedModul];

        // Mapping topla
        currentMapping = {};
        let requiredMissing = false;
        config.fields.forEach(field => {
            const val = $(`#map_${field.key}`).val();
            if (val !== '' && val !== null) {
                currentMapping[field.key] = parseInt(val);
            }
            if (field.required && (val === '' || val === null)) {
                requiredMissing = true;
                showToast(`"${field.label}" alani zorunlu, lutfen bir sutun eslestiriniz!`, 'error');
            }
        });
        if (requiredMissing) return;

        // Eslestirilen verileri duz objelere cevir
        const mappedData = excelData.map(row => {
            const obj = {};
            for (const [fieldKey, colIdx] of Object.entries(currentMapping)) {
                let val = row[colIdx];
                if (val === undefined || val === null) val = '';
                // Tarih alanlari icin Excel seri numarasi donusumu
                if (dateFields.includes(fieldKey)) {
                    obj[fieldKey] = excelDateToStr(val);
                } else {
                    obj[fieldKey] = String(val).trim();
                }
            }
            return obj;
        });

        // Ek parametreler
        const params = {
            action: 'analyze',
            modul: selectedModul,
            duplicate_field: $('input[name="duplicateField"]:checked').val() || '',
            data: JSON.stringify(mappedData),
            mapping: JSON.stringify(currentMapping)
        };

        if (selectedModul === 'sozlesme' || selectedModul === 'hukuk') {
            params.sezon_id = $('#sezonSelect').val() || '';
            params.default_urun_id = $('#defaultUrunSelect').val() || '';
            params.cari_tipi_id = $('#cariTipiSelect').val() || '1';
            params.hareket_durum = $('#hareketDurumSelect').val() || '1';
        }

        showLoading();
        $.ajax({
            url: '',
            method: 'POST',
            data: params,
            dataType: 'json',
            success: function(response) {
                hideLoading();
                if (response.success) {
                    analysisData = response;
                    updateSummary(response);
                    renderPreview(response);
                    $('#step2Card').addClass('d-none');
                    $('#summaryCard, #previewCard').removeClass('d-none').show();
                    showToast('Analiz tamamlandı!', 'success');
                } else {
                    showToast(response.message || 'Analiz hatası', 'error');
                }
            },
            error: function(xhr) {
                hideLoading();
                console.error('Analiz hatası:', xhr.responseText);
                showToast('Sunucu hatası olustu!', 'error');
            }
        });
    }

    // ==========================================
    // OZET GUNCELLE
    // ==========================================
    function updateSummary(response) {
        const s = response.summary || {};
        let html = '';
        if (selectedModul === 'hukuk') {
            html = `
                <div class="col-md-3"><div class="info-box"><span class="info-box-icon text-bg-primary"><i class="bi bi-file-earmark-spreadsheet"></i></span><div class="info-box-content"><span class="info-box-text">Toplam Satır</span><span class="info-box-number">${s.total || 0}</span></div></div></div>
                <div class="col-md-3"><div class="info-box"><span class="info-box-icon text-bg-success"><i class="bi bi-plus-circle"></i></span><div class="info-box-content"><span class="info-box-text">Yeni Kayıt</span><span class="info-box-number">${s.new || 0}</span></div></div></div>
                <div class="col-md-3"><div class="info-box"><span class="info-box-icon text-bg-warning"><i class="bi bi-arrow-repeat"></i></span><div class="info-box-content"><span class="info-box-text">Mukerrer Dosya</span><span class="info-box-number">${s.duplicate || 0}</span></div></div></div>
                <div class="col-md-3"><div class="info-box"><span class="info-box-icon text-bg-info"><i class="bi bi-currency-exchange"></i></span><div class="info-box-content"><span class="info-box-text">Toplam Tutar</span><span class="info-box-number">${formatCurrency(s.total_amount || 0)}</span></div></div></div>`;
        } else if (selectedModul === 'sozlesme') {
            html = `
                <div class="col-md-3"><div class="info-box"><span class="info-box-icon text-bg-primary"><i class="bi bi-file-earmark-spreadsheet"></i></span><div class="info-box-content"><span class="info-box-text">Toplam Satır</span><span class="info-box-number">${s.total || 0}</span></div></div></div>
                <div class="col-md-3"><div class="info-box"><span class="info-box-icon text-bg-success"><i class="bi bi-plus-circle"></i></span><div class="info-box-content"><span class="info-box-text">Yeni Kayıt</span><span class="info-box-number">${s.new || 0}</span></div></div></div>
                <div class="col-md-3"><div class="info-box"><span class="info-box-icon text-bg-warning"><i class="bi bi-arrow-repeat"></i></span><div class="info-box-content"><span class="info-box-text">Mukerrer</span><span class="info-box-number">${s.duplicate || 0}</span></div></div></div>
                <div class="col-md-3"><div class="info-box"><span class="info-box-icon text-bg-danger"><i class="bi bi-x-circle"></i></span><div class="info-box-content"><span class="info-box-text">Hata</span><span class="info-box-number">${s.errors || 0}</span></div></div></div>`;
        }
        $('#summaryBoxes').html(html);
    }
    // ==========================================
    // ON IZLEME TABLOSU RENDER
    // ==========================================
    function renderPreview(response) {
        const rows = response.data || [];
        const config = modulConfigs[selectedModul];

        // Statik alanlar (sozlesme icin)
        const staticFields = response.static_fields || [];

        // Tablo baslik
        let headHtml = '<tr><th>#</th><th>Durum</th>';
        config.fields.forEach(f => {
            if (currentMapping.hasOwnProperty(f.key)) {
                headHtml += `<th>${escapeHtml(f.label)}</th>`;
            }
        });
        staticFields.forEach(sf => {
            headHtml += `<th class="text-center"><span class="text-primary">${escapeHtml(sf.label)}</span></th>`;
        });
        headHtml += '</tr>';
        $('#previewHead').html(headHtml);

        // Tablo body
        let bodyHtml = '';
        rows.forEach((row, idx) => {
            const md = row.mapped_data || {};
            let rowClass = '';
            let statusBadge = '';
            if (row.status === 'error' || row.has_error) {
                rowClass = 'table-danger';
                statusBadge = `<span class="badge bg-danger" title="${escapeHtml(row.error_message || '')}">${escapeHtml(row.error_message || 'Hata')}</span>`;
            } else if (row.status === 'duplicate') {
                rowClass = 'table-warning';
                statusBadge = '<span class="badge bg-warning">Mukerrer (DB)</span>';
            } else if (row.status === 'excel_duplicate') {
                rowClass = 'table-warning';
                statusBadge = `<span class="badge bg-warning" title="${escapeHtml(row.error_message || '')}">Mukerrer (Excel)</span>`;
            } else if (row.status === 'new') {
                rowClass = 'table-success';
                statusBadge = '<span class="badge bg-success">Yeni</span>';
            } else if (row.status === 'update') {
                rowClass = 'table-info';
                statusBadge = '<span class="badge bg-info">Guncellenecek</span>';
            }


            bodyHtml += `<tr class="${rowClass}"><td>${idx + 1}</td><td>${statusBadge}</td>`;
            config.fields.forEach(f => {
                if (currentMapping.hasOwnProperty(f.key)) {
                    let cellVal = md[f.key] || '-';
                    bodyHtml += `<td>${escapeHtml(truncate(String(cellVal), 50))}</td>`;
                }
            });
            staticFields.forEach(sf => {
                bodyHtml += `<td class="text-center"><small class="text-muted">${escapeHtml(sf.value)}</small></td>`;
            });
            bodyHtml += '</tr>';
        });
        $('#previewBody').html(bodyHtml);
    }

    // ==========================================
    // IMPORT ISLEMLERI
    // ==========================================
    function importData() {
        if (!analysisData) {
            showToast('Lutfen once analiz yapın!', 'warning');
            return;
        }

        const duplicateAction = $('input[name="duplicateAction"]:checked').val() || 'skip';
        const summary = analysisData.summary || {};
        let importCount = 0;

        if (selectedModul === 'hukuk') {
            importCount = (summary.new || 0);
            if (duplicateAction === 'update') importCount += (summary.duplicate || 0);
            if (duplicateAction === 'only_update') importCount = (summary.duplicate || 0);
        } else if (selectedModul === 'sozlesme') {
            importCount = (summary.total || 0) - (summary.errors || 0) - (summary.excel_duplicate || 0);
            if (duplicateAction === 'skip') importCount -= (summary.duplicate || 0);
            if (duplicateAction === 'only_update') importCount = (summary.duplicate || 0);
        }

        if (importCount === 0) {
            showToast('Aktarilacak kayit bulunamadı!', 'warning');
            return;
        }

        confirmAction(
            `${importCount} kayit aktarılacak. Devam etmek istiyor musunuz?`,
            'Bu islem geri alınamaz!',
            function() {
                showLoading();
                const params = {
                    action: 'import',
                    modul: selectedModul,
                    duplicate_action: duplicateAction
                };

                if (selectedModul === 'sozlesme' || selectedModul === 'hukuk') {
                    params.sezon_id = $('#sezonSelect').val() || '';
                    params.default_urun_id = $('#defaultUrunSelect').val() || '';
                    params.cari_tipi_id = $('#cariTipiSelect').val() || '1';
                    params.hareket_durum = $('#hareketDurumSelect').val() || '1';
                }

                $.ajax({
                    url: '',
                    method: 'POST',
                    data: params,
                    dataType: 'json',
                    success: function(response) {
                        hideLoading();
                        if (response.success) {
                            showSuccess('Import Tamamlandı!', response.message);
                            // Temizle
                            $.post('', { action: 'clear' });
                        } else {
                            showError('Import Hatası!', response.message || 'Bilinmeyen hata');
                        }
                    },
                    error: function(xhr) {
                        hideLoading();
                        console.error('Import hatası:', xhr.responseText);
                        showError('Sunucu Hatası!', 'İşlem sırasında bir hata olustu.');
                    }
                });
            }
        );
    }
    // ==========================================
    // SIFIRLA
    // ==========================================
    function resetAll() {
        confirmAction('Tum verileri sifirlamak istiyor musunuz?', null, function() {
            selectedModul = null;
            excelHeaders = [];
            excelData = [];
            currentMapping = {};
            analysisData = null;

            $('.modul-card').removeClass('border-primary border-3').addClass('border');
            $('#excelFile').val('');
            $('#duplicateField').empty().append('<option value="">Modul secin</option>');
            $('#cariSettings, #sozlesmeSettings, #hukukSettings').addClass('d-none');
            $('#step2Card, #summaryCard, #previewCard').addClass('d-none').hide();
            $('#step1Card').removeClass('d-none');
            $('#mappingBody, #excelPreviewHead, #excelPreviewBody, #previewTableHead, #previewTableBody, #summaryBoxes').empty();

            $.post('', { action: 'clear' });
            showToast('Sifirlandiyor...', 'info');
        });
    }

    // ==========================================
    // ADIM ARASI GEC
    // ==========================================
    function backToStep1() {
        $('#step2Card').addClass('d-none');
        $('#step1Card').removeClass('d-none');
    }

    function backToStep2() {
        $('#summaryCard, #previewCard').addClass('d-none').hide();
        $('#step2Card').removeClass('d-none');
    }

    // ==========================================
    // SABLON INDIR
    // ==========================================
    function downloadTemplate() {
        if (!selectedModul) {
            showToast('Lutfen once bir modul secin!', 'warning');
            return;
        }
        const config = modulConfigs[selectedModul];
        const wsData = [config.templateHeaders, ...config.templateSample];
        const ws = XLSX.utils.aoa_to_sheet(wsData);
        const wb = XLSX.utils.book_new();
        XLSX.utils.book_append_sheet(wb, ws, 'Sablon');

        // Sutun genislikleri
        ws['!cols'] = config.templateHeaders.map(h => ({ wch: Math.max(h.length + 5, 15) }));

        XLSX.writeFile(wb, `${selectedModul}_import_sablonu.xlsx`);
        showToast('Sablon dosyasi indirildi', 'success');
    }

    // ==========================================
    // YARDIMCI FONKSIYONLAR
    // ==========================================
    function escapeHtml(str) {
        if (!str) return '';
        const div = document.createElement('div');
        div.appendChild(document.createTextNode(str));
        return div.innerHTML;
    }

    function truncate(str, len) {
        if (!str) return '';
        return str.length > len ? str.substring(0, len) + '...' : str;
    }

    // ==========================================
    // SAYFA HAZIR
    // ==========================================
    $(document).ready(function() {
        // Select2 baslat
        if (typeof $.fn.select2 !== 'undefined') {
            $('#sezonSelect, #defaultUrunSelect').select2({
                theme: 'bootstrap-5',
                width: '100%',
                placeholder: 'Seciniz...',
                allowClear: true,
                language: {
                    noResults: function() { return "Sonuc bulunamadı"; },
                    searching: function() { return "Aranıyor..."; }
                }
            });
        }

        // Dosya secildiginde ismi goster ve butonu aktif et
        $('#excelFile').on('change', function() {
            const f = this.files[0];
            if (f) {
                showToast(`Dosya secildi: ${f.name}`, 'info');
                $('#readFileBtn').prop('disabled', !selectedModul);
            }
        });

        // Modul secildiginde buton kontrolu (modul-card handler zaten yukarda)
        // readFileBtn aktif/pasif durumu modul-card handler icinde yonetiliyor

        // Buton click handler'lari
        $('#readFileBtn').on('click', readExcelFile);
        $('#analyzeBtn').on('click', analyzeData);
        $('#importBtn').on('click', importData);
        $('#resetAllBtn').on('click', resetAll);
        $('#autoMatchBtn').on('click', autoMatch);
        $('#downloadTemplateBtn').on('click', downloadTemplate);
    });
    </script>
</body>
</html>