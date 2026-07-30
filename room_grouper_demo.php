<?php
/**
 * Демонстрация groupHotelRooms(): названия одних и тех же номеров
 * от разных поставщиков в разных форматах и с разными аббревиатурами.
 *
 * Запуск: php room_grouper_demo.php
 */

require dirname(__FILE__) . '/room_grouper.php';

$supplierRooms = array(
    'SupplierA' => array(
        'Standard Double Room',
        'Superior Room Sea View',
        'Junior Suite',
        'DBL Deluxe with Balcony',
        'Family Room',
    ),
    'SupplierB' => array(
        'STD DBL',
        'SUP Room, sea view',
        'Suite Junior',
        'Deluxe Double Balcony Room',
        'Single Standard',
    ),
    'SupplierC' => array(
        'Std Twin',
        'Improved Sea View Room',
        'Junior Suite Room',
        'DLX King Balcony',
        'FAM Room',
        'SGL Standard',
    ),
);

$groups = groupHotelRooms($supplierRooms);

foreach ($groups as $key => $group) {
    echo "Категория: {$group['category']}  [ключ: {$key}]\n";
    foreach ($group['rooms'] as $room) {
        echo "    - [{$room['supplier']}] {$room['name']}\n";
    }
    echo "\n";
}
