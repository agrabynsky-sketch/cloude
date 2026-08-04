<?php
/**
 * Тест groupHotelRooms() на реальном списке из 106 названий номеров
 * одного отеля, собранных от разных поставщиков.
 *
 * Запуск: php room_grouper_test.php
 */

require dirname(__FILE__) . '/room_grouper.php';

$roomNames = array(
    'SUPERIOR ROOM GARDEN VIEW',
    'SUPERIOR ROOM',
    'SUPERIOR ROOM WITH GARDEN VIEW',
    'ROOM SUPERIOR',
    'SUPERIOR ROOM POOL VIEW',
    'SUPERIOR POOL VIEW ROOM',
    'FAMILY DELUXE DOUBLE POOL VIEW ROOM',
    'FAMILY DELUXE POOL VIEW',
    'DELUXE FAMILY ROOM(POOL VIEW)',
    'DOUBLE/TWIN DUPLEX WITH POOL VIEW',
    'DELUXE FAMILY ROOM',
    'SUPERIOR ROOM - SEA VIEW',
    'ROOM, DELUXE',
    'FAMILY ROOM - DOUBLE - QUEEN - DE LUXE - GARDEN VIEW',
    'DELUXE FAMILY ROOM WITH GARDEN VIEW (2 SOFA BEDS AND 1 LARGE BED) - FREE WIFI',
    'FAMILY ROOM - DE LUXE - POOL VIEW',
    'DOUBLE ROOM QUEEN BED - DE LUXE - JAZ BLUEMARINE',
    'DELUXE FAMILY QUEEN POOL VIEW WITH KIDS BED (1 SOFA BED AND 1 LARGE BED) - FREE WIFI',
    'DELUXE FAMILY ROOM POOL VIEW (2 SOFA BEDS AND 1 LARGE BED) - FREE WIFI',
    'DELUXE ROOM POOL VIEW - JAZ BLUEMARINE (1 LARGE BED) - FREE WIFI',
    'FAMILY DELUXE ROOM - GARDEN VIEW',
    'FAMILY ROOM DELUXE GARDEN VIEW',
    'FAMILY ROOM, SEA VIEW',
    'DELUXE FAMILY ROOM',
    'DELUXE FAMILY, QUEEN BED, GARDEN VIEW',
    'SUPERIOR ROOM GARDEN VIEW DOUBLE',
    'DELUXE ROOM',
    'FAMILY ROOM - POOL VIEW',
    'TWIN ROOM - CASAL - SUPERIOR - SEA VIEW',
    'DELUXE FAMILY ROOM WITH SEA VIEW (1 LARGE BED) - FREE WIFI',
    'SUPERIOR TWIN ROOM WITH SEA VIEW (2 SINGLE BEDS) - FREE WIFI',
    'FAMILY DELUXE ROOM - SEA VIEW',
    'DELUXE FAMILY ROOM SEA VIEW',
    'SUPERIOR ROOM POOL VIEW DOUBLE',
    'FAMILY ROOM - SEA VIEW',
    'SUPERIOR ROOM TWIN BED SEA VIEW',
    'FAMILY DOUBLE ROOM, GARDEN VIEW (DELUXE)',
    'FAMILY DOUBLE ROOM, POOL VIEW (DELUXE WITH KIDS BED)',
    'FAMILY DOUBLE ROOM, POOL VIEW (DELUXE, JAZ BLUEMARINE)',
    'FAMILY ROOM, POOL VIEW (DELUXE)',
    'DELUXE DOUBLE ROOM, POOL VIEW (JAZ BLUEMARINE)',
    'FAMILY DELUXE POOL VIEW ROOM DOUBLE',
    'TRIPLE FAMILY ROOM DELUXE WITH POOL VIEW',
    'SUPERIOR ROOM GARDEN VIEW DOUBLE SUPERIOR GARDEN VIEW',
    'FAMILY ROOM - DOUBLE - QUEEN - DE LUXE - SWIM UP',
    'DELUXE GARDEN VIEW FAMILY ROOM WITH QUEEN BED',
    'DELUXE FAMILY ROOM – SWIM UP (2 SOFA BEDS AND 1 LARGE BED) - FREE WIFI',
    'SUPERIOR TWIN ROOM, SEA VIEW',
    'FAMILY DOUBLE ROOM, SEA VIEW (DELUXE)',
    'FAMILY DELUXE ROOM DOUBLE FAMILY DELUXE ROOM',
    'SUPERIOR ROOM POOL VIEW DOUBLE SUPERIOR POOL VIEW',
    'DELUXE POOL VIEW FAMILY WITH QUEEN BED',
    'FAMILY DOUBLE ROOM, POOL VIEW (DELUXE WITH KIDS BED)',
    'FAMILY ROOM DELUXE WITH SIDE SEA VIEW',
    'FAMILY ROOM',
    'FAMILY ROOM WITH SIDE SEA VIEW',
    'DELUXE FAMILY, QUEEN BED, SWIM UP',
    'DELUXE SEA VIEW FAMILY ROOM WITH QUEEN BED',
    'JAZ BLUEMARINE - DELUXE FAMILY ROOM',
    'DELUXE POOL VIEW FAMILY ROOM WITH QUEEN AND KIDS BED',
    'JAZ BLUEMARINE - DELUXE, QUEEN BED',
    'FAMILY DOUBLE ROOM (DELUXE SWIM UP)',
    'SUPERIOR ROOM SEA VIEW DOUBLE SUPERIOR SEA VIEW',
    'JUNIOR SUITE',
    'FAMILY DELUXE ROOM SEA VIEW DOUBLE FAMILY DELUXE ROOM SV',
    'STANDARD ROOM',
    'JUNIOR SUITE - TWIN/QUEEN - POOL VIEW',
    'JUNIOR SUITE, QUEEN OR TWIN BED, POOL VIEW (1 DOUBLE BED) - FREE WIFI',
    'JUNIOR SUITE - POOL VIEW',
    'JUNIOR SUITE',
    'JAZ BLUEMARINE - DELUXE',
    'DELUXE FAMILY SWIM UP ROOM WITH QUEEN BED',
    'FAMILY DOUBLE ROOM, GARDEN VIEW (DELUXE) (1 QUEEN BED)',
    'DELUXE DOUBLE ROOM, POOL VIEW (JAZ BLUEMARINE) (1 QUEEN BED)',
    'FAMILY ROOM, POOL VIEW (DELUXE) (1 QUEEN BED)',
    'FAMILY DOUBLE ROOM, POOL VIEW (DELUXE, JAZ BLUEMARINE) (1 QUEEN BED)',
    'FAMILY DOUBLE ROOM, GARDEN VIEW (DELUXE) (1 QUEEN BED)',
    'FAMILY ROOM GARDEN VIEW',
    'JUNIOR SUITE, POOL VIEW (QUEEN OR TWIN BED)',
    'SUPERIOR, TWIN BED, SEA VIEW DOUBLE',
    'SUITE - QUEEN BED - EXECUTIVE',
    'DOUBLE/TWIN ROOM DELUXE',
    'FAMILY ROOM SUPERIOR QUEEN BED',
    'FAMILY ROOM QUEEN SIZE BED',
    'DOUBLE QUEEN SIZE BED',
    'EXECUTIVE DOUBLE SUITE (1 LARGE BED) - FREE WIFI',
    'JUNIOR SUITE WITH POOL VIEW AND BALCONY',
    'EXECUTIVE SUITE',
    'SUPERIOR TWIN ROOM, SEA VIEW (2 TWIN BEDS)',
    'FAMILY DOUBLE ROOM, SEA VIEW (DELUXE) (1 QUEEN BED)',
    'DOUBLE/TWIN ROOM DELUXE WITH POOL VIEW',
    'DOUBLE/TWIN ROOM SUPERIOR',
    'FAMILY ROOM SEA VIEW CAPACITY 4',
    'EXECUTIVE SUITE QUEEN',
    'TRIPLE FAMILY ROOM DELUXE WITH GARDEN VIEW',
    'EXECUTIVE SUITE',
    'FAMILY DOUBLE ROOM (DELUXE SWIM UP) (1 QUEEN BED)',
    'TRIPLE FAMILY ROOM DELUXE WITH SEA VIEW',
    'DELUXE FAMILY ROOM - POOL VIEW',
    'TRIPLE FAMILY ROOM DELUXE WITH ACCESS TO OUTDOOR POOL',
    'JUNIOR SUITE, POOL VIEW (QUEEN OR TWIN BED) (2 TWIN BEDS OR 1 QUEEN BED)',
    'DELUXE FAMILY ROOM - POOL ACCESS',
    'DOUBLE/TWIN JUNIOR SUITE',
    'EXECUTIVE SUITE QUEEN BED WITH POOL OR SEA VIEW',
    'JUNIOR SUITE, QUEEN OR TWIN BED, POOL VIEW',
    'EXECUTIVE SUITE (1 QUEEN BED)',
);

// 'jaz' и 'bluemarine' — название отеля в выгрузке одного из поставщиков,
// передаём его как шумовые слова
$groups = groupHotelRooms(array('Mixed' => $roomNames), 0.6, array('jaz', 'bluemarine'));

echo "Всего названий: " . count($roomNames) . ", категорий: " . count($groups) . "\n\n";
foreach ($groups as $key => $group) {
    echo "=== {$group['category']}  [{$key}]  (" . count($group['rooms']) . ")\n";
    foreach ($group['rooms'] as $room) {
        echo "    - {$room['name']}\n";
    }
}

/*
 * Семантика бассейнов — три разные категории:
 *   - pool view    — вид на бассейн;
 *   - swim up / pool access — выход в общий бассейн, проходящий вдоль номеров;
 *   - with pool / pool villa / private pool — индивидуальный бассейн
 *     в номере или на вилле.
 */
$poolNames = array(
    'VILLA WITH PRIVATE POOL',
    'POOL VILLA',
    'VILLA WITH POOL',
    'VILLA WITH OWN POOL',
    'VILLA WITH PLUNGE POOL',
    'DELUXE SWIM UP ROOM',
    'DELUXE ROOM POOL ACCESS',
    'DELUXE ROOM POOL VIEW',
    'DELUXE ROOM WITH POOL',
);

echo "\n--- Разделение pool view / swim-up / private pool ---\n\n";
$groups = groupHotelRooms(array('Mixed' => $poolNames));
foreach ($groups as $key => $group) {
    echo "=== {$group['category']}  [{$key}]  (" . count($group['rooms']) . ")\n";
    foreach ($group['rooms'] as $room) {
        echo "    - {$room['name']}\n";
    }
}

/*
 * Опции кроватей не участвуют в группировке. Номера с "King or Twin",
 * "Double/Twin" и т.п. не дробятся; номер, названный только по кровати,
 * попадает в самую низшую категорию (Standard).
 */
$bedNames = array(
    'DOUBLE ROOM',
    'TWIN ROOM',
    'DOUBLE/TWIN ROOM',
    'DOUBLE OR TWIN BED',
    'KING OR TWIN',
    '1 KING OR 2 TWIN',
    'KING BED OR TWO SINGLE BEDS',
    'SUPERIOR ROOM KING OR TWIN',
    'SUPERIOR TWIN ROOM SEA VIEW',
    'SUPERIOR ROOM SEA VIEW',
    'DELUXE ROOM KING BED',
    'DELUXE ROOM TWIN BEDS',
);

echo "\n--- Опции кроватей исключены из группировки ---\n\n";
$groups = groupHotelRooms(array('Mixed' => $bedNames));
foreach ($groups as $key => $group) {
    echo "=== {$group['category']}  [{$key}]  (" . count($group['rooms']) . ")\n";
    foreach ($group['rooms'] as $room) {
        echo "    - {$room['name']}\n";
    }
}

/*
 * Дополнительные правки:
 *  - ограниченный вид на море (Partial / Side / Limited Sea View) —
 *    отдельная категория "Partial Sea View", не полный Sea View;
 *  - Pool Access отделён от Swim-Up;
 *  - рекламный текст (Getaway offer 15%, Summer 26...) отбрасывается;
 *  - Room Assigned On Arrival / Run Of House / ROH — одна категория ROH;
 *  - название "Room" (или свернувшееся в пустоту) -> низшая категория.
 */
$edgeNames = array(
    // Ограниченный вид на море -> Partial Sea View (отдельно от полного)
    'DELUXE ROOM PARTIAL SEA VIEW',
    'DELUXE ROOM SIDE SEA VIEW',
    'DELUXE ROOM SEA VIEW LIMITED',
    'DELUXE ROOM SEA VIEW',
    'DELUXE ROOM FULL SEA VIEW',
    // Pool access vs swim-up
    'DELUXE ROOM POOL ACCESS',
    'DELUXE ROOM WITH ACCESS TO OUTDOOR POOL',
    'DELUXE ROOM SWIM UP',
    // Рекламный текст
    'STANDARD ROOM - SUMMER 26 GETAWAY OFFER 15%',
    'STANDARD ROOM EARLY BIRD DEAL',
    'STANDARD ROOM',
    // ROH
    'ROOM ASSIGNED ON ARRIVAL',
    'RUN OF HOUSE',
    'ROH',
    'DELUXE RUN OF THE HOUSE',
    // Просто "Room"
    'ROOM',
    'Getaway Offer 15 %',
);

echo "\n--- Виды-ограничения / pool access / промо / ROH / пустое имя ---\n\n";
$groups = groupHotelRooms(array('Mixed' => $edgeNames));
foreach ($groups as $key => $group) {
    echo "=== {$group['category']}  [{$key}]  (" . count($group['rooms']) . ")\n";
    foreach ($group['rooms'] as $room) {
        echo "    - {$room['name']}\n";
    }
}

/*
 * Правки 6-14:
 *  6  Non refundable / Non-Ref / Nonref     -> убрать из названия
 *  7  Eiffel View и др. значимые виды        -> сохранить в названии
 *  8  bare Triple / Quad                     -> низшая категория (Standard X)
 *  9  All Inclusive / Breakfast Included ...  -> убрать (нет отдельных категорий)
 * 10  2ad / 3pax                             -> игнорировать (это размещение)
 * 11  Double with Balcony (нет Room/класса)   -> Standard Balcony (+ вид)
 * 12  Landview == Land View, Parkview ...     -> не дробить
 * 13  Lateral Sea View                        -> Partial Sea View
 * 14  ведущее Exclusive                       -> сохранить в названии
 */
$moreNames = array(
    'DELUXE ROOM NON REFUNDABLE', 'DELUXE ROOM NON-REF', 'DELUXE ROOM NONREF',
    'SUPERIOR ROOM EIFFEL VIEW', 'SUPERIOR EIFFEL VIEW',
    'TRIPLE', 'QUAD', 'TRIPLE ROOM',
    'STANDARD ROOM ALL INCLUSIVE', 'STANDARD ROOM ULTRA ALL INCLUSIVE',
    'STANDARD ROOM ALL INCLUSIVE ULTRA', 'STANDARD ROOM BREAKFAST INCLUDED',
    'STANDARD ROOM DINNER INCLUDED',
    'DELUXE ROOM 2AD', 'DELUXE ROOM 3PAX', 'DELUXE ROOM 2 ADULTS',
    'DOUBLE WITH BALCONY', 'TWIN WITH SEA VIEW',
    'DELUXE LANDVIEW', 'DELUXE LAND VIEW', 'DELUXE PARKVIEW', 'DELUXE PARK VIEW',
    'DELUXE ROOM LATERAL SEA VIEW',
    'EXCLUSIVE SEA VIEW', 'EXCLUSIVE ROOM', 'DELUXE ROOM EXCLUSIVE OFFER',
);

echo "\n--- non-ref / лендмарк-вид / triple-quad / meal / 2ad / exclusive ---\n\n";
$groups = groupHotelRooms(array('Mixed' => $moreNames));
foreach ($groups as $key => $group) {
    echo "=== {$group['category']}  [{$key}]  (" . count($group['rooms']) . ")\n";
    foreach ($group['rooms'] as $room) {
        echo "    - {$room['name']}\n";
    }
}

/*
 * Правки 15-19:
 * 15  "Room, Mountain View" не должен наследовать "One Bedroom" чужой группы
 * 16  "N Bedrooms" сохраняет количество (не превращается в "Bedrooms")
 * 17  Anex / Annexe -> Annex
 * 18  Sea Side -> Partial Sea View
 * 19  "(bed type is subject to availability)" и любые "...subject to
 *     availability" убираются
 */
$bedroomNames = array(
    'ROOM, MOUNTAIN VIEW', 'STANDARD ONE BEDROOM MOUNTAIN VIEW',
    'FAMILY ROOM 2 BEDROOMS', '2 BEDROOMS, FAMILY SUITE',
    'FAMILY ROOM LAND VIEW 2 BEDROOMS', 'FAMILY ROOM 1 BEDROOM',
    'FAMILY ROOM 3 BEDROOMS',
    'DELUXE ROOM ANEX', 'DELUXE ROOM ANNEXE', 'DELUXE ROOM ANNEX',
    'DELUXE ROOM SEA SIDE', 'DELUXE ROOM SEASIDE',
    'DELUXE ROOM (BED TYPE IS SUBJECT TO AVAILABILITY)',
    'DELUXE ROOM BED TYPE IS SUBJECT TO AVAILABILITY',
);

echo "\n--- bedrooms / annex / sea side / subject to availability ---\n\n";
$groups = groupHotelRooms(array('Mixed' => $bedroomNames));
foreach ($groups as $key => $group) {
    echo "=== {$group['category']}  [{$key}]  (" . count($group['rooms']) . ")\n";
    foreach ($group['rooms'] as $room) {
        echo "    - {$room['name']}\n";
    }
}

/*
 * Правка 20: опечатка STANDART (T на конце) == STANDARD.
 */
$typoNames = array(
    'STANDART ROOM', 'STANDART DOUBLE ROOM', 'STANDARD ROOM', 'STD ROOM',
    'STANDART SEA VIEW',
);

echo "\n--- опечатка STANDART -> STANDARD ---\n\n";
$groups = groupHotelRooms(array('Mixed' => $typoNames));
foreach ($groups as $key => $group) {
    echo "=== {$group['category']}  [{$key}]  (" . count($group['rooms']) . ")\n";
    foreach ($group['rooms'] as $room) {
        echo "    - {$room['name']}\n";
    }
}

/*
 * Правки 20-29:
 * 20 Stadard -> Standard;               21 DWB/TWB -> тип кровати (отбрасывается)
 * 22 PERKS -> реклама;                  23 sqm/sqft/m² -> площадь (убрать)
 * 24 upon request -> убрать;            25 (2AD+1CH) -> размещение (убрать)
 * 26 (extra bed not included) -> убрать;27 Luxury/Suite/Delux/... -> свой грейд
 * 28 &amp; не превращать в "Amp";       29 Partial Seaview (слитно) -> Partial Sea View
 */
$batchNames = array(
    'STADARD ROOM', 'DELUXE DWB', 'DELUXE TWB', 'STANDARD ROOM PERKS',
    'DELUXE ROOM 25 SQM', 'DELUXE ROOM 25SQM', 'DELUXE ROOM 300 SQ FT', 'DELUXE ROOM 25M²',
    'DELUXE ROOM UPON REQUEST', 'DELUXE ROOM (2AD+1CH)',
    'DELUXE ROOM (EXTRA BED NOT INCLUDED)',
    'LUXURY ROOM', 'SUITE ROOM', 'DELUX ROOM', 'SPECTACULAR ROOM',
    'PREMIER ROOM', 'ELITE ROOM', 'PAVILION', 'VILLA',
    'DELUXE & SUITE', 'DELUXE &amp; SUITE',
    'DELUXE ROOM WITH PARTIAL SEAVIEW', 'DELUXE ROOM SEA VIEW',
);

echo "\n--- Stadard/DWB/PERKS/sqm/request/(2AD+1CH)/grades/&/partial seaview ---\n\n";
$groups = groupHotelRooms(array('Mixed' => $batchNames));
foreach ($groups as $key => $group) {
    echo "=== {$group['category']}  [{$key}]  (" . count($group['rooms']) . ")\n";
    foreach ($group['rooms'] as $room) {
        echo "    - {$room['name']}\n";
    }
}
