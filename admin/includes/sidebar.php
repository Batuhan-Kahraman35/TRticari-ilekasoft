<?php
/**
 * Admin Panel - Sidebar Component
 * Portal Örnek Yazılım
 * Dinamik Menü Sistemi
 */

// $user ve $db değişkenleri ana sayfada tanımlanmalı
if (!isset($user) || !isset($db)) {
    die('Sidebar component requires $user and $db variables to be set.');
}

// Menü ağacını yükle (header.php ile ortak)
require_once __DIR__ . '/menu-veri.php';

// Site ayarlarından logo ve başlığı çek
$siteAyarlari = $db->fetchOne("SELECT TOP 1 site_ayarlari_logo_url, site_ayarlari_site_title FROM dbo.tanim_site_ayarlari ORDER BY site_ayarlari_id DESC");
$logoUrl = $siteAyarlari['site_ayarlari_logo_url'] ?? null;
$siteTitle = $siteAyarlari['site_ayarlari_site_title'] ?? 'Örnek Yazılım Portal';

// Logo yolunu mutlak yap
if ($logoUrl && strpos($logoUrl, 'http') !== 0) {
    if (strpos($logoUrl, 'assets/') === 0) {
        $logoUrl = '/admin/' . $logoUrl;
    } elseif (strpos($logoUrl, '/') !== 0) {
        $logoUrl = '/admin/assets/' . $logoUrl;
    }
}

// Mevcut sayfa URL'sini belirle
$currentPage = basename($_SERVER['PHP_SELF']);
?>
<!--begin::Sidebar-->
<aside class="app-sidebar bg-body-secondary shadow" data-bs-theme="dark" style="min-height: 100vh;">
    <!--begin::Sidebar Brand-->
    <div class="sidebar-brand">
        <a href="/admin/anasayfa" class="brand-link">
            <?php if ($logoUrl): ?>
                <img src="<?= htmlspecialchars($logoUrl) ?>" alt="<?= htmlspecialchars($siteTitle) ?>" class="brand-image" style="opacity: .8; max-height: 33px;">
                <span class="brand-text fw-light"><?= htmlspecialchars($siteTitle) ?></span>
            <?php else: ?>
                <span class="brand-text fw-light"><?= htmlspecialchars($siteTitle) ?></span>
            <?php endif; ?>
        </a>
    </div>
    <!--end::Sidebar Brand-->
    
    <!--begin::Sidebar Wrapper-->
    <div class="sidebar-wrapper">

        
        <nav class="mt-2">
            <!--begin::Sidebar Menu-->
            <ul class="nav sidebar-menu flex-column" data-lte-toggle="treeview" role="menu" data-accordion="false">
                <?php foreach ($menuTreeSidebar as $menu): ?>
                    <?php 
                    $hasChildren = !empty($menu['children']);
                    $hasPages = !empty($menu['pages']);
                    $hasSubItems = $hasChildren || $hasPages;
                    $menuIcon = $menu['menuler_ikon'] ?: 'bi bi-folder';
                    
                    // Eğer menünün alt öğesi yoksa gösterme
                    if (!$hasSubItems) {
                        continue;
                    }
                    ?>
                    
                    <li class="nav-item <?= $hasSubItems ? 'has-treeview' : '' ?>">
                        <?php if ($hasSubItems): ?>
                            <!-- Ana menü (alt öğeleri var) - URL'yi görmezden gel -->
                            <a href="#" class="nav-link" onclick="return false;">
                                <i class="nav-icon <?= htmlspecialchars($menuIcon) ?>"></i>
                                <p>
                                    <?= htmlspecialchars($menu['menuler_menu_adi']) ?>
                                    <i class="nav-arrow bi bi-chevron-right"></i>
                                </p>
                            </a>
                            <ul class="nav nav-treeview">
                                <!-- Ana menünün doğrudan sayfaları -->
                                <?php foreach ($menu['pages'] as $page): ?>
                                    <?php 
                                    // SEO dostu URL (uzantıyı ve pages/ prefix'ini kaldır)
                                    $cleanUrl = str_replace('.php', '', $page['sayfalar_sayfa_url']);
                                    $cleanUrl = preg_replace('/^pages\//', '', $cleanUrl);
                                    $pageUrl = '/admin/' . $cleanUrl;
                                    $pageIcon = $page['sayfalar_ikon'] ?: 'bi bi-circle';
                                    $isActive = basename(strtok($page['sayfalar_sayfa_url'], '?')) === $currentPage;
                                    ?>
                                    <li class="nav-item">
                                        <a href="<?= htmlspecialchars($pageUrl) ?>" class="nav-link <?= $isActive ? 'active' : '' ?>">
                                            <i class="nav-icon <?= htmlspecialchars($pageIcon) ?>"></i>
                                            <p><?= htmlspecialchars($page['sayfalar_sayfa_adi']) ?></p>
                                        </a>
                                    </li>
                                <?php endforeach; ?>
                                
                                <!-- Alt menüler -->
                                <?php foreach ($menu['children'] as $subMenu): ?>
                                    <?php 
                                    $hasSubPages = !empty($subMenu['pages']);
                                    $subMenuIcon = $subMenu['menuler_ikon'] ?: 'bi bi-folder';
                                    
                                    // Eğer alt menünün sayfası yoksa gösterme
                                    if (!$hasSubPages) {
                                        continue;
                                    }
                                    ?>
                                    
                                    <?php if ($hasSubPages): ?>
                                        <!-- Alt menü (sayfaları var) - URL'yi görmezden gel -->
                                        <li class="nav-item has-treeview">
                                            <a href="#" class="nav-link" onclick="return false;">
                                                <i class="nav-icon <?= htmlspecialchars($subMenuIcon) ?>"></i>
                                                <p>
                                                    <?= htmlspecialchars($subMenu['menuler_menu_adi']) ?>
                                                    <i class="nav-arrow bi bi-chevron-right"></i>
                                                </p>
                                            </a>
                                            <ul class="nav nav-treeview">
                                                <?php foreach ($subMenu['pages'] as $page): ?>
                                                    <?php 
                                                    // SEO dostu URL (uzantıyı ve pages/ prefix'ini kaldır)
                                                    $cleanUrl = str_replace('.php', '', $page['sayfalar_sayfa_url']);
                                                    $cleanUrl = preg_replace('/^pages\//', '', $cleanUrl);
                                                    $pageUrl = '/admin/' . $cleanUrl;
                                                    $pageIcon = $page['sayfalar_ikon'] ?: 'bi bi-circle';
                                                    $isActive = basename(strtok($page['sayfalar_sayfa_url'], '?')) === $currentPage;
                                                    ?>
                                                    <li class="nav-item">
                                                        <a href="<?= htmlspecialchars($pageUrl) ?>" class="nav-link <?= $isActive ? 'active' : '' ?>">
                                                            <i class="nav-icon <?= htmlspecialchars($pageIcon) ?>"></i>
                                                            <p><?= htmlspecialchars($page['sayfalar_sayfa_adi']) ?></p>
                                                        </a>
                                                    </li>
                                                <?php endforeach; ?>
                                            </ul>
                                        </li>
                                    <?php endif; ?>
                                <?php endforeach; ?>
                            </ul>
                        <?php endif; ?>
                    </li>
                <?php endforeach; ?>
            </ul>
            <!--end::Sidebar Menu-->
        </nav>
    </div>
    <!--end::Sidebar Wrapper-->
</aside>
<!--end::Sidebar-->
