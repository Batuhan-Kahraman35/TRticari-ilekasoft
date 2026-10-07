<?php
/**
 * Satılmayan İllegaller Raporu
 * Statü adında "SATILDI" geçmeyen suç duyurusu kayıtları (cari_tipi_id = 4)
 * Statüsü atanmamış kayıtlar da bu rapora dahildir.
 * Ortak gövde: admin/includes/illegal-rapor.php
 */

$illegalRaporSatildi = false;
require __DIR__ . '/../includes/illegal-rapor.php';
