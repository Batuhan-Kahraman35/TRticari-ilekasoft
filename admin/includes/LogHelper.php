<?php
/**
 * Değişiklik Log Helper
 * Tüm sayfalardaki CRUD işlemlerini otomatik loglar
 * 
 * Kullanım:
 * 1. UPDATE öncesi: $eskiKayit = $db->fetchOne("SELECT * FROM Tablo WHERE id = ?", [$id]);
 * 2. UPDATE yap
 * 3. UPDATE sonrası: $yeniKayit = $db->fetchOne("SELECT * FROM Tablo WHERE id = ?", [$id]);
 * 4. Log: logKayitDegisiklikleri($db, 'sayfa-adi', 'Tablo', $id, $eskiKayit, $yeniKayit, $userId);
 */

/**
 * Kayıt değişikliklerini otomatik karşılaştırıp loglar
 * 
 * @param object $db Database bağlantısı
 * @param string $sayfa Sayfa adı (örn: 'sozlesme-form', 'cari-yonetimi')
 * @param string $tablo Tablo adı (örn: 'Sozlesmeler', 'Cariler')
 * @param int $kayitId Kaydın ID'si
 * @param array|null $eskiKayit UPDATE öncesi kayıt (INSERT için null)
 * @param array|null $yeniKayit UPDATE sonrası kayıt (DELETE için null)
 * @param int $kullaniciId İşlemi yapan kullanıcı ID
 * @param string|null $aciklama Opsiyonel açıklama
 * @return bool Başarılı mı
 */
function logKayitDegisiklikleri($db, $sayfa, $tablo, $kayitId, $eskiKayit, $yeniKayit, $kullaniciId, $aciklama = null) {
    try {
        // Sayfa adına query string ekle (ör: sozlesme-form?id=3924&cari_tipi_id=1)
        if (!empty($_SERVER['QUERY_STRING'])) {
            $sayfa .= '?' . $_SERVER['QUERY_STRING'];
        }
        
        // İşlem tipini belirle
        if ($eskiKayit === null && $yeniKayit !== null) {
            $islemTipi = 'INSERT';
        } elseif ($eskiKayit !== null && $yeniKayit === null) {
            $islemTipi = 'DELETE';
        } elseif ($eskiKayit !== null && $yeniKayit !== null) {
            $islemTipi = 'UPDATE';
        } else {
            return false; // Her ikisi de null ise log atma
        }
        
        // Değişiklikleri hesapla
        $degisiklikler = [];
        
        if ($islemTipi === 'INSERT') {
            // Yeni kayıt - tüm alanları logla
            foreach ($yeniKayit as $alan => $deger) {
                // Datetime nesnelerini string'e çevir
                if ($deger instanceof DateTime) {
                    $deger = $deger->format('Y-m-d H:i:s');
                }
                $degisiklikler[$alan] = [
                    'eski' => null,
                    'yeni' => $deger
                ];
            }
        } elseif ($islemTipi === 'DELETE') {
            // Silinen kayıt - tüm alanları logla
            foreach ($eskiKayit as $alan => $deger) {
                if ($deger instanceof DateTime) {
                    $deger = $deger->format('Y-m-d H:i:s');
                }
                $degisiklikler[$alan] = [
                    'eski' => $deger,
                    'yeni' => null
                ];
            }
        } else {
            // UPDATE - sadece değişen alanları logla
            foreach ($yeniKayit as $alan => $yeniDeger) {
                $eskiDeger = $eskiKayit[$alan] ?? null;
                
                // Datetime nesnelerini string'e çevir
                if ($eskiDeger instanceof DateTime) {
                    $eskiDeger = $eskiDeger->format('Y-m-d H:i:s');
                }
                if ($yeniDeger instanceof DateTime) {
                    $yeniDeger = $yeniDeger->format('Y-m-d H:i:s');
                }
                
                // Değer karşılaştırması (tip dönüşümü ile)
                if (normalizeValue($eskiDeger) != normalizeValue($yeniDeger)) {
                    $degisiklikler[$alan] = [
                        'eski' => $eskiDeger,
                        'yeni' => $yeniDeger
                    ];
                }
            }
            
            // Değişiklik yoksa log atma
            if (empty($degisiklikler)) {
                return true;
            }
        }
        
        // JSON'a çevir
        $degisikliklerJson = json_encode($degisiklikler, JSON_UNESCAPED_UNICODE);
        
        // Log kaydı oluştur
        $db->execute("
            INSERT INTO Sistem_DegisiklikLog (
                log_sayfa, log_tablo, log_kayit_id, log_islem_tipi,
                log_degisiklikler, log_aciklama, log_kullanici_id, log_tarih
            ) VALUES (?, ?, ?, ?, ?, ?, ?, GETDATE())
        ", [
            $sayfa, $tablo, $kayitId, $islemTipi,
            $degisikliklerJson, $aciklama, $kullaniciId
        ]);
        
        // Hukuk (cari_tipi_id=3) ve Suç Duyurusu (cari_tipi_id=4) değişikliklerini WhatsApp grubuna gönder
        // Sadece ana tablo (HukukTakip) için gönder - Cari, Sozlesmeler vb. için ayrı mesaj gitmesin
        if (preg_match('/cari_tipi_id=(3|4)/', $sayfa) && $tablo === 'HukukTakip') {
            $anlamliDegisiklikler = array_filter($degisiklikler, function($alan) {
                // Tarih/sistem/ID alanlarını filtrele
                $haricAlanlar = ['guncelleme', 'olusturma', 'tarihi', '_tarih', 'GuncellemeTarihi', 'OlusturmaTarihi', 'GuncelleyenKullanici', 'OlusturanKullanici', 'takip_id'];
                foreach ($haricAlanlar as $pattern) {
                    if (strcasecmp($alan, $pattern) === 0 || stripos($alan, $pattern) !== false) return false;
                }
                return true;
            }, ARRAY_FILTER_USE_KEY);
            
            if (!empty($anlamliDegisiklikler)) {
                $anlamliJson = json_encode($anlamliDegisiklikler, JSON_UNESCAPED_UNICODE);
                sendWhatsAppLogBildirimi($db, $sayfa, $tablo, $kayitId, $islemTipi, $anlamliJson, $aciklama, $kullaniciId);
            }
        }
        
        return true;
    } catch (Exception $e) {
        // Log hatası ana işlemi engellemez, sadece error log'a yaz
        error_log("LogHelper Error: " . $e->getMessage());
        return false;
    }
}

/**
 * Değer normalizasyonu (karşılaştırma için)
 */
function normalizeValue($value) {
    if ($value === null || $value === '') {
        return null;
    }
    if (is_numeric($value)) {
        return (string)$value;
    }
    return $value;
}

/**
 * Belirli bir kaydın log geçmişini getir
 * 
 * @param object $db Database bağlantısı
 * @param string $tablo Tablo adı
 * @param int $kayitId Kayıt ID
 * @param int $limit Maksimum kayıt sayısı
 * @return array Log kayıtları
 */
function getKayitLogGecmisi($db, $tablo, $kayitId, $limit = 50) {
    return $db->fetchAll("
        SELECT 
            l.log_id,
            l.log_sayfa,
            l.log_tablo,
            l.log_kayit_id,
            l.log_islem_tipi,
            l.log_degisiklikler,
            l.log_aciklama,
            CONVERT(VARCHAR(19), l.log_tarih, 120) as log_tarih,
            k.kullanici_ad + ' ' + k.kullanici_soyad as kullanici_adi
        FROM Sistem_DegisiklikLog l
        LEFT JOIN kullanicilar k ON l.log_kullanici_id = k.kullanici_id
        WHERE l.log_tablo = ? AND l.log_kayit_id = ?
        ORDER BY l.log_tarih DESC
        OFFSET 0 ROWS FETCH NEXT ? ROWS ONLY
    ", [$tablo, $kayitId, $limit]);
}

/**
 * Hukuk/Suç Duyurusu değişikliklerini WhatsApp grubuna gönder
 */
function sendWhatsAppLogBildirimi($db, $sayfa, $tablo, $kayitId, $islemTipi, $degisikliklerJson, $aciklama, $kullaniciId) {
    try {
        $configPath = __DIR__ . '/../../config/whatsapp.php';
        if (!file_exists($configPath)) return;
        
        $config = require $configPath;
        if (empty($config['enabled'])) return;
        
        // Kullanıcı adını al
        $kullanici = $db->fetchOne("SELECT kullanici_ad + ' ' + kullanici_soyad as ad_soyad FROM kullanicilar WHERE kullanici_id = ?", [$kullaniciId]);
        $kullaniciAdi = $kullanici['ad_soyad'] ?? 'Bilinmeyen';
        
        // Cari bilgilerini ve toplam alacağı al (sayfa URL'sindeki id'den)
        $cariAdi = '';
        $cariUnvan = '';
        $dosyaNo = '';
        $sehirAdi = '';
        $toplamAlacak = 0;
        preg_match('/id=(\d+)/', $sayfa, $idMatches);
        $takipIdParam = $idMatches[1] ?? 0;
        if ($takipIdParam > 0) {
            $cariBilgi = $db->fetchOne("
                SELECT c.cari_adi, c.cari_unvan, t.takip_dosya_no, s.SehirAdi,
                       ISNULL(t.takip_ana_tutar, 0) + ISNULL(t.takip_Isleyen_Faiz, 0) + ISNULL(t.takip_Islemis_Faiz, 0) + ISNULL(t.takip_Vekalet_Ucreti, 0) + ISNULL(t.takip_Masraf, 0) as toplam_alacak
                FROM HukukTakip t 
                LEFT JOIN Cari c ON t.takip_cari_id = c.cari_id 
                LEFT JOIN Adres_Sehirler s ON c.cari_sehirler = s.SehirId
                WHERE t.takip_id = ?
            ", [$takipIdParam]);
            $cariAdi = $cariBilgi['cari_adi'] ?? '';
            $cariUnvan = $cariBilgi['cari_unvan'] ?? '';
            $dosyaNo = $cariBilgi['takip_dosya_no'] ?? '';
            $sehirAdi = $cariBilgi['SehirAdi'] ?? '';
            $toplamAlacak = floatval($cariBilgi['toplam_alacak'] ?? 0);
        }
        
        // cari_tipi_id'ye göre tip belirle
        preg_match('/cari_tipi_id=(\d+)/', $sayfa, $matches);
        $cariTipiId = $matches[1] ?? '';
        $tipAdi = $cariTipiId == '3' ? 'Hukuk' : 'Suç Duyurusu';
        
        // İşlem tipi Türkçe
        $islemTipleri = [
            'INSERT' => 'Yeni Kayıt',
            'UPDATE' => 'Güncelleme',
            'DELETE' => 'Silme'
        ];
        $islemAdi = $islemTipleri[$islemTipi] ?? $islemTipi;
        
        // Değişiklikleri özetle
        $degisiklikler = json_decode($degisikliklerJson, true);
        $ozet = '';
        if ($degisiklikler && is_array($degisiklikler)) {
            // Alan adı → Form'daki Türkçe etiket
            $alanEtiketleri = [
                // HukukTakip alanları
                'takip_statu_id'            => 'Statü',
                'takip_taraf_id'            => 'Taraf',
                'takip_icra_dairesi_id'     => 'İcra Dairesi',
                'takip_savcilik_id'         => 'Savcılık',
                'takip_cari_id'             => 'Müşteri',
                'takip_dosya_no'            => 'Dosya No',
                'takip_aciklama'            => 'Açıklama',
                'takip_dosya_acilis_tarihi' => 'Dosya Açılış Tarihi',
                'takip_ana_tutar'           => 'Ana Tutar',
                'takip_Isleyen_Faiz'        => 'İşleyen Faiz',
                'takip_Islemis_Faiz'        => 'İşlemiş Faiz',
                'takip_Vekalet_Ucreti'      => 'Vekalet Ücreti',
                'takip_Masraf'              => 'Masraf',
                'takip_Tahsil_Harci'        => 'Tahsil Harcı',
                'takip_faiz_orani'          => 'Faiz Oranı',
                'takip_son_faiz_tarihi'     => 'Son Faiz Tarihi',
                'takip_tespit_turu'         => 'Tespit Türü',
                'takip_tespit_tarihi'       => 'Tespit Tarihi',
                'takip_tespit_adet'         => 'Tespit Adet',
                'Durum'                     => 'Durum',
                // Cari alanları
                'cari_adi'                  => 'Müşteri Adı',
                'cari_unvan'                => 'Ünvan',
                'cari_telefon'              => 'Telefon',
                'cari_vergi_no'             => 'Vergi No',
                'cari_vergi_dairesi'        => 'Vergi Dairesi',
                'cari_yetkili_adi'          => 'Yetkili Adı Soyadı',
                'cari_yetkili_telefon'      => 'Yetkili Telefon',
                'cari_adres'                => 'Adres',
                'cari_sehirler'             => 'Şehir',
                'cari_ilceler'              => 'İlçe',
                'cari_tipi_id'              => 'Cari Tipi',
                'cari_aktif'                => 'Aktif',
                // Sözleşme alanları
                'sozlesme_no'               => 'Sözleşme No',
                'sozlesme_aciklama'         => 'Sözleşme Açıklama',
                'sozlesme_durum'            => 'Sözleşme Durum',
                'sozlesme_tarih'            => 'Sözleşme Tarihi',
                'sozlesme_fatura_no'        => 'Fatura No',
                'sozlesme_sezon_id'         => 'Sezon',
                'sozlesme_personel_id'      => 'Personel',
                'sozlesme_fatura_dosya'     => 'Fatura Dosyası',
                'sozlesme_dosyalar'         => 'Sözleşme Evrağı',
            ];
            
            // ID → İsim çözümleme haritası
            $idCozumle = [
                'takip_statu_id'       => ['tablo' => 'HukukStatu', 'id' => 'statu_id', 'ad' => 'statu_ad'],
                'takip_taraf_id'       => ['tablo' => 'HukukTaraflar', 'id' => 'taraf_id', 'ad' => 'taraf_ad'],
                'takip_icra_dairesi_id'=> ['tablo' => 'HukukIcraDairesi', 'id' => 'icra_dairesi_id', 'ad' => 'icra_dairesi_ad'],
                'takip_savcilik_id'    => ['tablo' => 'HukukSavciliklar', 'id' => 'savcilik_id', 'ad' => 'savcilik_ad'],
                'takip_cari_id'        => ['tablo' => 'Cari', 'id' => 'cari_id', 'ad' => 'cari_adi'],
            ];
            
            // BIT/Durum alanları: 1 → Aktif, 0 → Pasif
            $bitAlanlar = ['Durum', 'cari_aktif', 'sozlesme_durum', 'takip_durum'];
            
            $satirlar = [];
            foreach ($degisiklikler as $alan => $degerler) {
                $etiket = $alanEtiketleri[$alan] ?? $alan;
                
                // BIT alanıysa Aktif/Pasif göster
                if (in_array($alan, $bitAlanlar)) {
                    $eskiDurum = ($degerler['eski'] !== null) ? ($degerler['eski'] == 1 ? 'Aktif' : 'Pasif') : '';
                    $yeniDurum = ($degerler['yeni'] !== null) ? ($degerler['yeni'] == 1 ? 'Aktif' : 'Pasif') : '';
                    if ($islemTipi === 'UPDATE') {
                        $satirlar[] = "• *{$etiket}*: {$eskiDurum} → {$yeniDurum}";
                    } elseif ($islemTipi === 'INSERT' && $yeniDurum !== '') {
                        $satirlar[] = "• *{$etiket}*: {$yeniDurum}";
                    }
                    continue;
                }
                
                // ID alanıysa isim çözümle
                if (isset($idCozumle[$alan])) {
                    $map = $idCozumle[$alan];
                    $eskiAd = '';
                    $yeniAd = '';
                    if (!empty($degerler['eski'])) {
                        $r = $db->fetchOne("SELECT {$map['ad']} as ad FROM {$map['tablo']} WHERE {$map['id']} = ?", [$degerler['eski']]);
                        $eskiAd = $r['ad'] ?? $degerler['eski'];
                    }
                    if (!empty($degerler['yeni'])) {
                        $r = $db->fetchOne("SELECT {$map['ad']} as ad FROM {$map['tablo']} WHERE {$map['id']} = ?", [$degerler['yeni']]);
                        $yeniAd = $r['ad'] ?? $degerler['yeni'];
                    }
                    if ($islemTipi === 'UPDATE') {
                        $satirlar[] = "• *{$etiket}*: {$eskiAd} → {$yeniAd}";
                    } elseif ($islemTipi === 'INSERT' && $yeniAd !== '') {
                        $satirlar[] = "• *{$etiket}*: {$yeniAd}";
                    }
                } else {
                    if ($islemTipi === 'UPDATE') {
                        $satirlar[] = "• *{$etiket}*: {$degerler['eski']} → {$degerler['yeni']}";
                    } elseif ($islemTipi === 'INSERT') {
                        if ($degerler['yeni'] !== null && $degerler['yeni'] !== '') {
                            $satirlar[] = "• *{$etiket}*: {$degerler['yeni']}";
                        }
                    }
                }
            }
            // Çok uzun mesajları kısalt
            if (count($satirlar) > 15) {
                $satirlar = array_slice($satirlar, 0, 15);
                $satirlar[] = "... ve " . (count($degisiklikler) - 15) . " alan daha";
            }
            $ozet = implode("\n", $satirlar);
        }
        
        // Sayfa linkini oluştur - id parametresi yoksa kayıt id'sini ekle
        $sayfaLink = 'https://ticari.ornekproje.com/admin/' . $sayfa;
        if ($kayitId && !preg_match('/[?&]id=/', $sayfaLink)) {
            $separator = strpos($sayfaLink, '?') !== false ? '&' : '?';
            $sayfaLink .= $separator . 'id=' . $kayitId;
        }
        
        // Mesaj oluştur
        $mesaj = "📋 *{$tipAdi} - {$islemAdi}*\n";
        $mesaj .= "━━━━━━━━━━━━━━━\n";
        if ($cariAdi) {
            $mesaj .= "🏢 *Müşteri:* {$cariAdi}\n";
        }
        if ($cariUnvan) {
            $mesaj .= "📌 *Ünvan:* {$cariUnvan}\n";
        }
        if ($sehirAdi) {
            $mesaj .= "📍 *Şehir:* {$sehirAdi}\n";
        }
        if ($dosyaNo) {
            $mesaj .= "📁 *Dosya No:* {$dosyaNo}\n";
        }        $mesaj .= "� *Toplam Alacak:* " . number_format($toplamAlacak, 2, ',', '.') . " ₺\n";
        $mesaj .= "�📄 *Tablo:* {$tablo}\n";

        $mesaj .= "👤 *Kullanıcı:* {$kullaniciAdi}\n";
        $mesaj .= "🕐 *Tarih:* " . date('d.m.Y H:i:s') . "\n";
        if ($aciklama) {
            $mesaj .= "💬 *Açıklama:* {$aciklama}\n";
        }
        if ($ozet) {
            $mesaj .= "━━━━━━━━━━━━━━━\n";
            $mesaj .= $ozet . "\n";
        }
        $mesaj .= "━━━━━━━━━━━━━━━\n";
        $mesaj .= "🔗 {$sayfaLink}";
        
        // Evolution API ile gönder
        $apiUrl = rtrim($config['api_url'], '/');
        $payload = json_encode([
            'number' => '120363408017326279@g.us',
            'text' => $mesaj
        ]);
        
        $ch = curl_init("{$apiUrl}/message/sendText/{$config['instance']}");
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $payload,
            CURLOPT_HTTPHEADER => [
                "apikey: {$config['api_key']}",
                "Content-Type: application/json"
            ],
            CURLOPT_TIMEOUT => 10
        ]);
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        
        // Log_WhatsApp tablosuna kaydet (tablo varsa)
        try {
            $db->execute("
                INSERT INTO Log_WhatsApp (wa_log_telefon, wa_log_mesaj, wa_log_tip, wa_log_kullanici_id, wa_log_durum, wa_log_api_yanit, wa_log_kaynak)
                VALUES (?, ?, ?, ?, ?, ?, ?)
            ", [
                '120363408017326279@g.us',
                $mesaj,
                'LOG_BILDIRIM',
                $kullaniciId,
                ($httpCode == 200 || $httpCode == 201) ? 1 : 0,
                $response,
                'LogHelper'
            ]);
        } catch (Exception $e) {
            // Log tablosu yoksa devam et
        }
        
    } catch (Exception $e) {
        error_log("WhatsApp Bildirim Hatası: " . $e->getMessage());
    }
}

/**
 * Belirli bir sayfanın tüm log geçmişini getir
 * 
 * @param object $db Database bağlantısı
 * @param string $sayfa Sayfa adı
 * @param int $limit Maksimum kayıt sayısı
 * @return array Log kayıtları
 */
function getSayfaLogGecmisi($db, $sayfa, $limit = 100) {
    return $db->fetchAll("
        SELECT 
            l.log_id,
            l.log_sayfa,
            l.log_tablo,
            l.log_kayit_id,
            l.log_islem_tipi,
            l.log_degisiklikler,
            l.log_aciklama,
            CONVERT(VARCHAR(19), l.log_tarih, 120) as log_tarih,
            k.kullanici_ad + ' ' + k.kullanici_soyad as kullanici_adi
        FROM Sistem_DegisiklikLog l
        LEFT JOIN kullanicilar k ON l.log_kullanici_id = k.kullanici_id
        WHERE l.log_sayfa = ?
        ORDER BY l.log_tarih DESC
        OFFSET 0 ROWS FETCH NEXT ? ROWS ONLY
    ", [$sayfa, $limit]);
}

/**
 * İşlem tipi için Türkçe etiket
 */
function getIslemTipiLabel($islemTipi) {
    $labels = [
        'INSERT' => ['text' => 'Eklendi', 'badge' => 'success', 'icon' => 'bi-plus-circle'],
        'UPDATE' => ['text' => 'Güncellendi', 'badge' => 'warning', 'icon' => 'bi-pencil'],
        'DELETE' => ['text' => 'Silindi', 'badge' => 'danger', 'icon' => 'bi-trash']
    ];
    return $labels[$islemTipi] ?? ['text' => $islemTipi, 'badge' => 'secondary', 'icon' => 'bi-question'];
}

/**
 * Alan adını Türkçeye çevir (opsiyonel mapping)
 */
function getAlanAdiLabel($tablo, $alanAdi) {
    // Genel alan adı çevirileri
    $genelCeviriler = [
        'olusturma_tarihi' => 'Oluşturma Tarihi',
        'guncelleme_tarihi' => 'Güncelleme Tarihi',
        'olusturan_id' => 'Oluşturan',
        'guncelleyen_id' => 'Güncelleyen',
        'durum' => 'Durum',
        'aciklama' => 'Açıklama',
        'tarih' => 'Tarih'
    ];
    
    // Tablo bazlı özel çeviriler
    $tabloCevirileri = [
        'Cariler' => [
            'cari_id' => 'Cari ID',
            'cari_adi' => 'Cari Adı',
            'cari_unvan' => 'Ünvan',
            'cari_tip' => 'Cari Tipi',
            'cari_telefon' => 'Telefon',
            'cari_email' => 'E-posta',
            'cari_vergi_no' => 'Vergi No',
            'cari_vergi_dairesi' => 'Vergi Dairesi',
            'cari_adres' => 'Adres',
            'cari_durum' => 'Durum'
        ],
        'Sozlesmeler' => [
            'sozlesme_id' => 'Sözleşme ID',
            'sozlesme_no' => 'Sözleşme No',
            'sozlesme_tarih' => 'Tarih',
            'sozlesme_cari_id' => 'Cari',
            'sozlesme_sezon_id' => 'Sezon',
            'sozlesme_personel_id' => 'Personel',
            'sozlesme_aciklama' => 'Açıklama',
            'sozlesme_durum' => 'Durum'
        ],
        'Sozlesme_Odemeler' => [
            'odeme_id' => 'Ödeme ID',
            'odeme_tipi_id' => 'Ödeme Tipi',
            'odeme_tutar' => 'Tutar',
            'odeme_vade_tarihi' => 'Vade Tarihi',
            'odeme_durum_id' => 'Durum',
            'odeme_belge_no' => 'Belge No',
            'odeme_aciklama' => 'Açıklama',
            'odeme_yapildi' => 'Ödeme Yapıldı',
            'odeme_tarih' => 'Ödeme Tarihi'
        ]
    ];
    
    // Önce tablo bazlı, sonra genel çevirilere bak
    if (isset($tabloCevirileri[$tablo][$alanAdi])) {
        return $tabloCevirileri[$tablo][$alanAdi];
    }
    if (isset($genelCeviriler[$alanAdi])) {
        return $genelCeviriler[$alanAdi];
    }
    
    // Bulunamazsa alan adını formatla (snake_case -> Title Case)
    return ucwords(str_replace('_', ' ', $alanAdi));
}
