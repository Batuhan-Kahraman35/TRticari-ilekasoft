<?php
/**
 * Sayfa Yetki Kontrolü
 * 
 * Kullanıcının mevcut sayfaya erişim yetkisini kontrol eder.
 * departman_id=1 (Administrator) için tüm yetkilere izin verir.
 * Diğer departmanlar için menu_sayfa_yetkiler tablosunu kontrol eder.
 */

class PageAuth {
    
    /**
     * Sayfa yetkilerini kontrol et
     * 
     * @param int $userId Kullanıcı ID
     * @param int $departmanId Departman ID
     * @param string $pageFile Sayfa dosya adı (örn: stok-yonetimi.php)
     * @return array Yetki bilgileri
     */
    public static function checkPagePermissions($userId, $departmanId, $pageFile) {
        // Administrator departmanı için tüm yetkiler açık
        if ($departmanId == 1) {
            return [
                'has_access' => true,
                'can_view' => true,
                'can_add' => true,
                'can_edit' => true,
                'can_delete' => true,
                'can_view_firma' => true,
                'can_view_sube' => true,
                'can_view_own_records' => false, // Admin tüm kayıtları görür
                'is_admin' => true
            ];
        }
        
        $db = Database::getInstance();

        // Dosya adını query string'den ayır (örn. aylık-satis-raporu.php?sezon_id=2 -> aylık-satis-raporu.php)
        // Aynı fiziksel sayfa birden fazla menüde farklı ?sezon_id ile tanımlı olabilir.
        $baseFile = strtok(trim($pageFile), '?');

        // Bu dosyaya ait sayfa tanımı var mı? (URL'deki query string yok sayılır)
        $page = $db->fetchOne("
            SELECT TOP 1 sayfalar_id
            FROM Menu_Sayfalar
            WHERE sayfalar_durum = 1
              AND RTRIM(LTRIM(
                    CASE WHEN CHARINDEX('?', sayfalar_sayfa_url) > 0
                         THEN LEFT(sayfalar_sayfa_url, CHARINDEX('?', sayfalar_sayfa_url) - 1)
                         ELSE sayfalar_sayfa_url END
                  )) = RTRIM(LTRIM(?))
        ", [$baseFile]);

        if (!$page) {
            // Sayfa tanımı yoksa erişim yok
            return [
                'has_access' => false,
                'can_view' => false,
                'can_add' => false,
                'can_edit' => false,
                'can_delete' => false,
                'can_view_firma' => false,
                'can_view_sube' => false,
                'is_admin' => false,
                'error' => 'Sayfa tanımı bulunamadı'
            ];
        }

        // Departman yetkilerini kontrol et.
        // Aynı dosyaya ait TÜM sayfa kayıtlarının (2026/2027 vb.) yetkilerini birleştir (MAX).
        $permissions = $db->fetchOne("
            SELECT
                MAX(CAST(y.gor AS int)) AS gor,
                MAX(CAST(y.ekle AS int)) AS ekle,
                MAX(CAST(y.duzenle AS int)) AS duzenle,
                MAX(CAST(y.sil AS int)) AS sil,
                MAX(CAST(y.firma_gor AS int)) AS firma_gor,
                MAX(CAST(y.sube_gor AS int)) AS sube_gor,
                MAX(CAST(y.kendi_kullanicini_gor AS int)) AS kendi_kullanicini_gor
            FROM Menu_Sayfalar s
            INNER JOIN Menu_SayfaYetkileri y ON y.sayfa_id = s.sayfalar_id
            WHERE s.sayfalar_durum = 1
              AND y.durum = 1
              AND y.departman_id = ?
              AND RTRIM(LTRIM(
                    CASE WHEN CHARINDEX('?', s.sayfalar_sayfa_url) > 0
                         THEN LEFT(s.sayfalar_sayfa_url, CHARINDEX('?', s.sayfalar_sayfa_url) - 1)
                         ELSE s.sayfalar_sayfa_url END
                  )) = RTRIM(LTRIM(?))
        ", [$departmanId, $baseFile]);

        if (!$permissions || $permissions['gor'] === null) {
            // Yetki kaydı yoksa erişim yok
            return [
                'has_access' => false,
                'can_view' => false,
                'can_add' => false,
                'can_edit' => false,
                'can_delete' => false,
                'can_view_firma' => false,
                'can_view_sube' => false,
                'can_view_own_records' => false,
                'is_admin' => false,
                'error' => 'Bu sayfaya erişim yetkiniz yok'
            ];
        }
        
        return [
            'has_access' => (bool)$permissions['gor'],
            'can_view' => (bool)$permissions['gor'],
            'can_add' => (bool)$permissions['ekle'],
            'can_edit' => (bool)$permissions['duzenle'],
            'can_delete' => (bool)$permissions['sil'],
            'can_view_firma' => (bool)$permissions['firma_gor'],
            'can_view_sube' => (bool)$permissions['sube_gor'],
            'can_view_own_records' => (bool)$permissions['kendi_kullanicini_gor'],
            'is_admin' => false
        ];
    }
    
    /**
     * Yetkisiz erişim durumunda hata sayfası göster
     */
    public static function accessDenied($message = 'Bu sayfaya erişim yetkiniz yok!') {
        http_response_code(403);
        ?>
        <!DOCTYPE html>
        <html lang="tr">
        <head>
            <meta charset="UTF-8">
            <meta name="viewport" content="width=device-width, initial-scale=1.0">
            <title>Erişim Engellendi</title>
            <link rel="stylesheet" href="/admin/assets/css/adminlte.min.css">
            <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.13.1/font/bootstrap-icons.min.css">
        </head>
        <body class="d-flex align-items-center justify-content-center" style="min-height: 100vh; background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);">
            <div class="text-center text-white">
                <i class="bi bi-shield-x" style="font-size: 120px; opacity: 0.8;"></i>
                <h1 class="display-4 mt-4">403 - Erişim Engellendi</h1>
                <p class="lead mb-4"><?= htmlspecialchars($message) ?></p>
                <a href="/admin/anasayfa" class="btn btn-light btn-lg">
                    <i class="bi bi-house-door"></i> Ana Sayfaya Dön
                </a>
            </div>
        </body>
        </html>
        <?php
        exit;
    }
}
