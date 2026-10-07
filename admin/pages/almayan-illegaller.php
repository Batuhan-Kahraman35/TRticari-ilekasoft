<?php
/**
 * Almayan İllegaller Raporu
 * Satılmayan İllegaller raporunun kopyası; yalnızca "Arandı - Satın almak istemiyor"
 * statüsündeki suç duyurusu kayıtları (cari_tipi_id = 4) listelenir.
 * Ortak gövde: admin/includes/illegal-rapor.php
 */

$illegalRaporSatildi   = false;
$illegalRaporStatuId   = 30; // HukukStatu: Arandı - Satın almak istemiyor
$illegalRaporBaslik    = 'Almayan İllegaller';
$illegalRaporDosyaOnek = 'almayan-illegaller';
require __DIR__ . '/../includes/illegal-rapor.php';
