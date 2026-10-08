<?php
/**
 * Satılmayan İllegaller Raporu
 * Statü adında "SATILDI" geçmeyen suç duyurusu kayıtları (cari_tipi_id = 4)
 * Statüsü atanmamış kayıtlar da bu rapora dahildir.
 * "Arandı - Satın almak istemiyor" kayıtları Almayan İllegaller sayfasında listelendiği için hariçtir.
 * Ortak gövde: admin/includes/illegal-rapor.php
 */

$illegalRaporSatildi         = false;
$illegalRaporHaricStatuIdler = [30]; // HukukStatu: Arandı - Satın almak istemiyor (bkz. almayan-illegaller.php)
require __DIR__ . '/../includes/illegal-rapor.php';
