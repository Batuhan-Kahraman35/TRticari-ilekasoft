<?php
/**
 * Admin Panel - Menü Verisi
 * Portal Örnek Yazılım
 *
 * Menü ağacını bir kez kurar; header.php ve sidebar.php ortak kullanır.
 * Çıktı değişkenleri:
 *   $menuTreeHeader  -> menuler_header_goster = 1 olan ana menüler (Header'da gösterilir)
 *   $menuTreeSidebar -> diğer ana menüler (Sidebar'da gösterilir)
 *   $isAdmin, $userDepartmanId
 */

if (!isset($user) || !isset($db)) {
    die('menu-veri.php requires $user and $db variables to be set.');
}

// Aynı istekte iki kez çalışmasın
if (!isset($menuVeriYuklendi)) {
    $menuVeriYuklendi = true;

    $userDepartmanId = $user['departman_id'] ?? null;
    $isAdmin = ($userDepartmanId == 1); // Administrator departmanı

    // Kullanıcının yetkili olduğu menüleri çek
    if ($isAdmin) {
        $allMenus = $db->fetchAll("
            SELECT
                m.menuler_id,
                m.menuler_menu_adi,
                m.menuler_ikon,
                m.menuler_parent_id,
                m.menuler_sira_no,
                m.menuler_header_goster
            FROM Menuler m
            WHERE m.menuler_durum = 1
            ORDER BY ISNULL(m.menuler_parent_id, 0), m.menuler_sira_no
        ");
    } else {
        try {
            // Önce direkt yetkili menüleri al
            $directMenus = $db->fetchAll("
                SELECT DISTINCT
                    m.menuler_id,
                    m.menuler_menu_adi,
                    m.menuler_ikon,
                    m.menuler_parent_id,
                    m.menuler_sira_no,
                    m.menuler_header_goster
                FROM Menuler m
                INNER JOIN Menu_SayfaYetkileri msy ON m.menuler_id = msy.menu_id
                WHERE m.menuler_durum = 1
                  AND msy.departman_id = ?
                  AND msy.durum = 1
                  AND msy.gor = 1
                ORDER BY m.menuler_sira_no
            ", [$userDepartmanId]);

            // Parent menü ID'lerini topla
            $parentIds = [];
            foreach ($directMenus as $menu) {
                if ($menu['menuler_parent_id']) {
                    $parentIds[] = $menu['menuler_parent_id'];
                }
            }

            // Parent menüleri de ekle (yetki kontrolü olmadan)
            $allMenus = $directMenus;
            if (!empty($parentIds)) {
                $parentIds = array_unique($parentIds);
                $placeholders = implode(',', array_fill(0, count($parentIds), '?'));

                $parentMenus = $db->fetchAll("
                    SELECT
                        menuler_id,
                        menuler_menu_adi,
                        menuler_ikon,
                        menuler_parent_id,
                        menuler_sira_no,
                        menuler_header_goster
                    FROM Menuler
                    WHERE menuler_durum = 1
                      AND menuler_id IN ($placeholders)
                    ORDER BY menuler_sira_no
                ", array_values($parentIds));

                $existingIds = array_column($allMenus, 'menuler_id');
                foreach ($parentMenus as $parent) {
                    if (!in_array($parent['menuler_id'], $existingIds)) {
                        $allMenus[] = $parent;
                    }
                }

                usort($allMenus, function ($a, $b) {
                    return $a['menuler_sira_no'] - $b['menuler_sira_no'];
                });
            }
        } catch (Exception $e) {
            error_log("Menu Veri Query Error: " . $e->getMessage());
            $allMenus = [];
        }
    }

    // Sayfa listesini çek
    if ($isAdmin) {
        $allPages = $db->fetchAll("
            SELECT
                s.sayfalar_id,
                s.sayfalar_menu_id,
                s.sayfalar_sayfa_adi,
                s.sayfalar_sayfa_url,
                s.sayfalar_ikon,
                s.sayfalar_sira_no
            FROM Menu_Sayfalar s
            WHERE s.sayfalar_durum = 1
            ORDER BY s.sayfalar_sira_no
        ");
    } else {
        $allPages = $db->fetchAll("
            SELECT DISTINCT
                s.sayfalar_id,
                s.sayfalar_menu_id,
                s.sayfalar_sayfa_adi,
                s.sayfalar_sayfa_url,
                s.sayfalar_ikon,
                s.sayfalar_sira_no
            FROM Menu_Sayfalar s
            INNER JOIN Menu_SayfaYetkileri msy ON s.sayfalar_id = msy.sayfa_id
            WHERE s.sayfalar_durum = 1
              AND msy.departman_id = ?
              AND msy.durum = 1
              AND msy.gor = 1
            ORDER BY s.sayfalar_sira_no
        ", [$userDepartmanId]);
    }

    // Ana menüleri ve alt menüleri grupla
    $menuTree = [];
    $subMenus = [];

    foreach ($allMenus as $menu) {
        if ($menu['menuler_parent_id'] === null) {
            $menuTree[$menu['menuler_id']] = $menu;
            $menuTree[$menu['menuler_id']]['children'] = [];
            $menuTree[$menu['menuler_id']]['pages'] = [];
        } else {
            $subMenus[$menu['menuler_parent_id']][] = $menu;
        }
    }

    // Alt menüleri ana menülere ekle
    foreach ($subMenus as $parentId => $children) {
        if (isset($menuTree[$parentId])) {
            $menuTree[$parentId]['children'] = [];
            foreach ($children as $child) {
                $child['pages'] = [];
                $menuTree[$parentId]['children'][] = $child;
            }
        }
    }

    // Sayfaları ilgili menülere ekle
    foreach ($allPages as $page) {
        $menuId = $page['sayfalar_menu_id'];

        if (isset($menuTree[$menuId])) {
            $menuTree[$menuId]['pages'][] = $page;
        } else {
            foreach ($menuTree as $parentId => &$parentMenu) {
                if (isset($parentMenu['children'])) {
                    foreach ($parentMenu['children'] as $childIdx => &$child) {
                        if ($child['menuler_id'] == $menuId) {
                            $menuTree[$parentId]['children'][$childIdx]['pages'][] = $page;
                            break 2;
                        }
                    }
                }
            }
            unset($parentMenu, $child);
        }
    }

    // Header / Sidebar ayrımı
    $menuTreeHeader = [];
    $menuTreeSidebar = [];
    foreach ($menuTree as $menuId => $menu) {
        if (!empty($menu['menuler_header_goster'])) {
            $menuTreeHeader[$menuId] = $menu;
        } else {
            $menuTreeSidebar[$menuId] = $menu;
        }
    }
}
