<?php
/**
 * Сквозной тест синхронизации: изменение в MySQL -> очередь -> воркер -> ClickHouse == эталон.
 *   php tests/queue_flow.php [id_hotel]
 * Все изменения в MySQL в конце откатываются, отель пересобирается обратно.
 */
require __DIR__ . '/bootstrap.php';
require __DIR__ . '/reference.php';

$today = date('Y-m-d');
$checkin = date('Y-m-d', strtotime('+40 day'));
$nights = 5;
$stay = array('checkin' => $checkin, 'nights' => $nights, 'guests' => 2);
if(isset($argv[1])) {
    $hotelId = (int)$argv[1];
} else {
    // демо-отель, у которого на эти даты доступно побольше рум-рейтов (чтобы тест что-то проверял)
    $first = (int)$mysql->fetchOne("SELECT id_from FROM hotels_search_demo_registry WHERE entity = 'hotels'");
    for($hotelId = $first; $hotelId < $first + 100; $hotelId++) {
        if(count(referenceRates($mysql, $hotelId, $checkin, $nights, 2, $today)) >= 6) {
            break;
        }
    }
}
$model = new Search_Model_Stay($chHttp);
$queue = new Search_Sync_Queue($mysql);
$worker = new Search_Sync_Worker($chWriter, new Search_Sync_Builder($mysql), $queue);
$failures = 0;

function current_rates(Search_Model_Stay $model, $hotelId, array $stay) {
    $got = array();
    foreach($model->hotelRates($hotelId, $stay) as $row) {
        $got[$row['id_rate_room']] = $row['price_minor'];
    }
    ksort($got);
    return $got;
}
function expected_rates($db, $hotelId, array $stay, $today) {
    $exp = array();
    foreach(referenceRates($db, $hotelId, $stay['checkin'], $stay['nights'], $stay['guests'], $today) as $rrId => $v) {
        $exp[$rrId] = $v['total'];
    }
    ksort($exp);
    return $exp;
}
// один номер с детьми: id_rate_room => цена
function current_rooms(Search_Model_Stay $model, $hotelId, array $stay, array $room) {
    $offer = $model->hotelRooms($hotelId, array('checkin' => $stay['checkin'], 'nights' => $stay['nights'], 'rooms' => array($room)));
    $got = array();
    foreach($offer['rooms'][0]['items'] as $row) {
        $got[$row['id_rate_room']] = $row['price_minor'];
    }
    ksort($got);
    return $got;
}
function expected_rooms($db, $hotelId, array $stay, array $room, $today) {
    $exp = array();
    foreach(referenceRooms($db, $hotelId, $stay['checkin'], $stay['nights'], $room['adults'], $room['children'], $today) as $rrId => $v) {
        $exp[$rrId] = $v['total'];
    }
    ksort($exp);
    return $exp;
}
function check($title, $expected, $got, &$failures) {
    $ok = $expected == $got;
    $failures += $ok ? 0 : 1;
    printf("%-62s %s (%s)\n", $title, $ok ? 'OK' : 'FAIL', is_array($got) ? count($got) . ' room-rates' : 'value ' . $got);
    if(!$ok) {
        echo "  expected: " . json_encode($expected) . "\n  got:      " . json_encode($got) . "\n";
    }
}
function sync($queue, $worker, $hotelId, $reason) {
    $queue->push($hotelId, $reason);
    $worker->runOnce();
}

$rates = $mysql->fetchPairs('SELECT title, id FROM hotels_rates WHERE id_hotel = ?', array($hotelId));
$bar = $rates['Best Available Rate'];
$bb = $rates['Bed & Breakfast'];
$lastNight = date('Y-m-d', strtotime("$checkin +" . ($nights - 1) . ' day'));
printf("hotel %d, stay %s + %d nights, BAR=%d BB=%d\n\n", $hotelId, $checkin, $nights, $bar, $bb);

// все изменения MySQL регистрируют откат; он выполняется в конце и при любой ошибке
$undo = array();
$rollback = function() use (&$undo) {
    while($f = array_pop($undo)) {
        call_user_func($f);
    }
};
$touched = array($hotelId);

try {
    // 0. исходное состояние
    sync($queue, $worker, $hotelId, 'test');
    $initial = current_rates($model, $hotelId, $stay);
    check('0. initial state == reference', expected_rates($mysql, $hotelId, $stay, $today), $initial, $failures);

    // 1. BAR +100.00 на все ночи проживания (производный Non-refundable должен подорожать тоже)
    $mysql->beginTransaction();
    $mysql->query('UPDATE hotels_rates_prices SET price = price + 100 WHERE id_rate = ? AND date BETWEEN ? AND ?', array($bar, $checkin, $lastNight));
    $queue->push($hotelId, 'bulkedit');   // в той же транзакции, что и изменение цен
    $mysql->commit();
    $undo[] = function() use ($mysql, $bar, $checkin, $lastNight) {
        $mysql->query('UPDATE hotels_rates_prices SET price = price - 100 WHERE id_rate = ? AND date BETWEEN ? AND ?', array($bar, $checkin, $lastNight));
    };
    $worker->runOnce();
    $after = current_rates($model, $hotelId, $stay);
    check('1. BAR price +100 -> cache updated', expected_rates($mysql, $hotelId, $stay, $today), $after, $failures);
    printf("   changed room-rates: %d\n", count(array_diff_assoc($after, $initial)));

    // 2. тариф BB выключен -> его рум-рейты исчезают из кэша (tombstone-строки)
    $mysql->query('UPDATE hotels_rates SET active = 0 WHERE id = ?', array($bb));
    $undo[] = function() use ($mysql, $bb) {
        $mysql->query('UPDATE hotels_rates SET active = 1 WHERE id = ?', array($bb));
    };
    sync($queue, $worker, $hotelId, 'rate');
    check('2. BB rate deactivated -> removed from cache', expected_rates($mysql, $hotelId, $stay, $today), current_rates($model, $hotelId, $stay), $failures);
    printf("   rows of BB left in cache (FINAL): %d\n", $chHttp->fetchOne('SELECT count() FROM hotels_search_stay FINAL WHERE id_rate = ' . (int)$bb));

    // 3. BAR стал private -> пропадает из публичного поиска, хотя старые версии строк ещё лежат в частях таблицы
    $mysql->query("UPDATE hotels_rates SET visibility = 'private' WHERE id = ?", array($bar));
    $undo[] = function() use ($mysql, $bar) {
        $mysql->query("UPDATE hotels_rates SET visibility = 'public' WHERE id = ?", array($bar));
    };
    sync($queue, $worker, $hotelId, 'rate');
    check('3. BAR private -> hidden from public search (FINAL + WHERE)', expected_rates($mysql, $hotelId, $stay, $today), current_rates($model, $hotelId, $stay), $failures);

    // 4. стоп-продажа номера на ночь заезда -> все тарифы этого номера недоступны
    $roomId = (int)$mysql->fetchOne('SELECT id FROM hotels_rooms WHERE id_hotel = ? ORDER BY id LIMIT 1', array($hotelId));
    $av = $mysql->fetchRow('SELECT id, active FROM hotels_rooms_availability WHERE id_room = ? AND date = ? ORDER BY id DESC LIMIT 1', array($roomId, $checkin));
    if($av) {
        $mysql->query('UPDATE hotels_rooms_availability SET active = 0 WHERE id = ?', array($av['id']));
        $undo[] = function() use ($mysql, $av) {
            $mysql->query('UPDATE hotels_rooms_availability SET active = ? WHERE id = ?', array($av['active'], $av['id']));
        };
    } else {
        $mysql->query('INSERT INTO hotels_rooms_availability (id_room, date, allotment, net_booked, active) VALUES (?, ?, NULL, 0, 0)', array($roomId, $checkin));
        $stopId = (int)$mysql->lastInsertId();
        $undo[] = function() use ($mysql, $stopId) {
            $mysql->query('DELETE FROM hotels_rooms_availability WHERE id = ?', array($stopId));
        };
    }
    sync($queue, $worker, $hotelId, 'availability');
    check('4. stop-sale of one room on arrival night', expected_rates($mysql, $hotelId, $stay, $today), current_rates($model, $hotelId, $stay), $failures);

    // 5. отель выключен -> исчезает из поиска
    $mysql->query('UPDATE hotels SET active = 0 WHERE id = ?', array($hotelId));
    $undo[] = function() use ($mysql, $hotelId) {
        $mysql->query('UPDATE hotels SET active = 1 WHERE id = ?', array($hotelId));
    };
    sync($queue, $worker, $hotelId, 'hotel');
    $res = $model->search(array('checkin' => $checkin, 'nights' => $nights, 'guests' => 2, 'id_hotel' => array($hotelId)));
    check('5. hotel deactivated -> not found by search()', 0, $res['total'], $failures);

    // 6. защита очереди: изменение во время сборки не теряется
    $queue->push($hotelId, 'test');
    $claimed = $queue->claim('test-token', 10);
    $queue->push($hotelId, 'changed-during-build');
    $queue->done('test-token', $claimed);
    $left = $mysql->fetchOne('SELECT COUNT(*) FROM hotels_search_sync_queue WHERE id_hotel = ?', array($hotelId));
    check('6. change during build keeps hotel in queue', 1, (int)$left, $failures);

    // 7. откат шагов 1-5
    $rollback();
    sync($queue, $worker, $hotelId, 'restore');
    check('7. everything restored -> same as initial', $initial, current_rates($model, $hotelId, $stay), $failures);

    // 8. дневная цена на 3 гостей (hotels_rates_occupancy_daily) у рум-рейта с ценами по числу гостей
    $stay3 = array('checkin' => $checkin, 'nights' => $nights, 'guests' => 3);
    $first = (int)$mysql->fetchOne("SELECT id_from FROM hotels_search_demo_registry WHERE entity = 'hotels'");
    $target = null;
    for($h = $first + 1; $h < $first + 200 && !$target; $h += 2) {           // у чётных демо-отелей есть цены по числу гостей
        foreach(referenceRates($mysql, $h, $checkin, $nights, 3, $today) as $rrId => $v) {
            $occ = $mysql->fetchOne('SELECT COUNT(*) FROM hotels_rates_occupancy WHERE id_rate_room = ?', array($rrId));
            if($occ) {
                $target = array($h, $rrId, $v['total'], $v['nightly']);
                break;
            }
        }
    }
    list($h2, $rr2, $total2, $nightly2) = $target;
    $touched[] = $h2;
    $row = $mysql->fetchRow('SELECT id_room, id_rate FROM hotels_rates_rooms WHERE id = ?', array($rr2));
    $mysql->query('INSERT INTO hotels_rates_occupancy_daily (id_rate_room, id_room, id_rate, guests, date, price) VALUES (?, ?, ?, 3, ?, 1234.56)',
        array($rr2, $row['id_room'], $row['id_rate'], $checkin));
    $d1 = (int)$mysql->lastInsertId();
    $undo[] = function() use ($mysql, $d1) {
        $mysql->query('DELETE FROM hotels_rates_occupancy_daily WHERE id = ?', array($d1));
    };
    sync($queue, $worker, $h2, 'occupancy_daily');
    $after3 = current_rates($model, $h2, $stay3);
    check("8. daily price for 3 guests (hotel $h2, rr $rr2) == reference", expected_rates($mysql, $h2, $stay3, $today), $after3, $failures);
    check('   first night = 1234.56', $total2 - $nightly2[0] + 123456, isset($after3[$rr2]) ? $after3[$rr2] : null, $failures);

    // 8b. дневная цена 0 на другую ночь = «на 3 гостей в эту ночь не продаётся»: рум-рейт пропадает для 3 гостей на всё проживание,
    //     для 2 гостей остаётся
    $stay2 = array('checkin' => $checkin, 'nights' => $nights, 'guests' => 2);
    $before2 = current_rates($model, $h2, $stay2);
    $second = date('Y-m-d', strtotime("$checkin +" . ($nights - 1) . ' day'));
    $mysql->query('INSERT INTO hotels_rates_occupancy_daily (id_rate_room, id_room, id_rate, guests, date, price) VALUES (?, ?, ?, 3, ?, 0)',
        array($rr2, $row['id_room'], $row['id_rate'], $second));
    $d2 = (int)$mysql->lastInsertId();
    $undo[] = function() use ($mysql, $d2) {
        $mysql->query('DELETE FROM hotels_rates_occupancy_daily WHERE id = ?', array($d2));
    };
    sync($queue, $worker, $h2, 'occupancy_daily_closed');
    $closed3 = current_rates($model, $h2, $stay3);
    check('8b. daily price 0 on another night == reference', expected_rates($mysql, $h2, $stay3, $today), $closed3, $failures);
    check("   rr $rr2 not sold for 3 guests", false, isset($closed3[$rr2]), $failures);
    check('   2 guests unchanged', $before2, current_rates($model, $h2, $stay2), $failures);

    // 9. дубли строк (на проде нет UNIQUE-ключей): побеждает строка с максимальным id
    $hasUnique = $mysql->fetchOne("SELECT COUNT(*) FROM information_schema.statistics WHERE table_schema = DATABASE()
        AND table_name = 'hotels_rates_prices' AND index_name = 'uq_rr_date'");
    if($hasUnique) {
        echo "9. duplicates: skipped (UNIQUE index uq_rr_date exists, duplicates impossible)\n";
    } else {
        $barRr = (int)$mysql->fetchOne('SELECT id FROM hotels_rates_rooms WHERE id_rate = ? ORDER BY id LIMIT 1', array($bar));
        $orig = $mysql->fetchRow('SELECT * FROM hotels_rates_prices WHERE id_rate_room = ? AND date = ? ORDER BY id DESC LIMIT 1', array($barRr, $checkin));
        if($orig) {
            $mysql->query('INSERT INTO hotels_rates_prices (id_rate_room, id_room, id_rate, date, price, derive_type, derive_value, min_los, max_los,
                min_adv, max_adv, cta, ctd, active) VALUES (?, ?, ?, ?, ?, 0, 0, NULL, NULL, NULL, NULL, 0, 0, 1)',
                array($barRr, $orig['id_room'], $orig['id_rate'], $checkin, $orig['price'] + 500));
            $dupId = (int)$mysql->lastInsertId();
            $undo[] = function() use ($mysql, $dupId) {
                $mysql->query('DELETE FROM hotels_rates_prices WHERE id = ?', array($dupId));
            };
            sync($queue, $worker, $hotelId, 'duplicate');
            check('9. duplicate price row: the newest (max id) wins', expected_rates($mysql, $hotelId, $stay, $today), current_rates($model, $hotelId, $stay), $failures);
        }
    }

    // 11-17. дети, цены по числу гостей и вместимость: демо-отель с политикой «как на экране» (0-2 бесплатно, 3-11 фикс,
    //        12-17 50% цены взрослого), с ценами по числу гостей и доступными номерами на 2 взрослых + 2 детей
    $kids = array('adults' => 2, 'children' => array(8, 4));
    $three = array('adults' => 3, 'children' => array());
    $single = array('adults' => 1, 'children' => array(7));
    $h3 = null;
    $occRr = null;
    for($h = $first; $h < $first + 2000 && !$h3; $h++) {
        // фикс у группы 3-11, доступные номера для всех составов и рум-рейт BAR с ценами по числу гостей,
        // который продаётся и на 3 взрослых, и на 1 взрослого с ребёнком (у производного тарифа есть рум-рейт на том же номере)
        $fixed = $mysql->fetchOne('SELECT COUNT(*) FROM hotels_children_groups WHERE id_hotel = ? AND active = 1 AND age_from = 3 AND price_type = 2', array($h));
        if(!$fixed || count(expected_rooms($mysql, $h, $stay, $kids, $today)) < 2) {
            continue;
        }
        $both = array_intersect(array_keys(expected_rooms($mysql, $h, $stay, $three, $today)), array_keys(expected_rooms($mysql, $h, $stay, $single, $today)));
        foreach($both as $rrId) {
            $row = $mysql->fetchRow("SELECT rr.id, rr.id_room, rr.id_rate FROM hotels_rates_rooms rr JOIN hotels_rates t ON t.id = rr.id_rate
                WHERE rr.id = ? AND t.title = 'Best Available Rate' AND EXISTS (SELECT 1 FROM hotels_rates_occupancy o WHERE o.id_rate_room = rr.id)", array($rrId));
            if($row && !$occRr) {
                $occRr = $row;
                $h3 = $h;
            }
        }
    }
    if(!$h3) {
        throw new Exception('no demo hotel for steps 11-17');
    }
    $touched[] = $h3;
    sync($queue, $worker, $h3, 'test');
    $initialKids = current_rooms($model, $h3, $stay, $kids);
    $initialThree = current_rooms($model, $h3, $stay, $three);
    $initialSingle = current_rooms($model, $h3, $stay, $single);
    check("11. hotel $h3: 2 adults + children 8, 4 == reference", expected_rooms($mysql, $h3, $stay, $kids, $today), $initialKids, $failures);
    check('    3 adults == reference', expected_rooms($mysql, $h3, $stay, $three, $today), $initialThree, $failures);

    // 12. цена группы 3-11: фикс -> 20% тарифа на номер
    $g311 = $mysql->fetchRow('SELECT * FROM hotels_children_groups WHERE id_hotel = ? AND age_from = 3 AND active = 1', array($h3));
    $mysql->query('UPDATE hotels_children_groups SET price_type = 3, price_value = 20 WHERE id = ?', array($g311['id']));
    $undo[] = function() use ($mysql, $g311) {
        $mysql->query('UPDATE hotels_children_groups SET price_type = ?, price_value = ? WHERE id = ?',
            array($g311['price_type'], $g311['price_value'], $g311['id']));
    };
    sync($queue, $worker, $h3, 'children');
    $after = current_rooms($model, $h3, $stay, $kids);
    check('12. child group 3-11: fixed -> 20% of the room rate', expected_rooms($mysql, $h3, $stay, $kids, $today), $after, $failures);
    printf("   changed room-rates: %d\n", count(array_diff_assoc($after, $initialKids)));

    // 13. окно цен по числу гостей у BAR: «3 гостя» — сумма +123.45 за ночь; производный Non-refundable наследует
    $old3 = $mysql->fetchRow('SELECT * FROM hotels_rates_occupancy WHERE id_rate_room = ? AND guests = 3 ORDER BY id DESC LIMIT 1', array($occRr['id']));
    if($old3) {
        $mysql->query('UPDATE hotels_rates_occupancy SET amount_type = 1, amount = 123.45, active = 1 WHERE id = ?', array($old3['id']));
        $undo[] = function() use ($mysql, $old3) {
            $mysql->query('UPDATE hotels_rates_occupancy SET amount_type = ?, amount = ?, active = ? WHERE id = ?',
                array($old3['amount_type'], $old3['amount'], $old3['active'], $old3['id']));
        };
    } else {
        $mysql->query('INSERT INTO hotels_rates_occupancy (id_rate_room, id_room, id_rate, guests, amount_type, amount, active) VALUES (?, ?, ?, 3, 1, 123.45, 1)',
            array($occRr['id'], $occRr['id_room'], $occRr['id_rate']));
        $newId = (int)$mysql->lastInsertId();
        $undo[] = function() use ($mysql, $newId) {
            $mysql->query('DELETE FROM hotels_rates_occupancy WHERE id = ?', array($newId));
        };
    }
    sync($queue, $worker, $h3, 'occupancy');
    $after = current_rooms($model, $h3, $stay, $three);
    check("13. BAR rr {$occRr['id']}: 3 guests = +123.45 per night", expected_rooms($mysql, $h3, $stay, $three, $today), $after, $failures);
    printf("   changed room-rates (BAR and derived Non-refundable): %d of %d\n", count(array_diff_assoc($after, $initialThree)), count($after));

    // 14. своя строка производного тарифа: «3 гостя» +10% от его цены (вместо цены родителя)
    $nrRr = $mysql->fetchRow("SELECT rr.id, rr.id_room, rr.id_rate FROM hotels_rates_rooms rr JOIN hotels_rates t ON t.id = rr.id_rate
        WHERE t.id_hotel = ? AND t.id_parent > 0 AND rr.id_room = ?", array($h3, $occRr['id_room']));
    $mysql->query('INSERT INTO hotels_rates_occupancy (id_rate_room, id_room, id_rate, guests, amount_type, amount, active) VALUES (?, ?, ?, 3, 0, 10, 1)',
        array($nrRr['id'], $nrRr['id_room'], $nrRr['id_rate']));
    $ownId = (int)$mysql->lastInsertId();
    $undo[] = function() use ($mysql, $ownId) {
        $mysql->query('DELETE FROM hotels_rates_occupancy WHERE id = ?', array($ownId));
    };
    sync($queue, $worker, $h3, 'occupancy');
    check("14. derived rr {$nrRr['id']}: own row 3 guests +10%", expected_rooms($mysql, $h3, $stay, $three, $today), current_rooms($model, $h3, $stay, $three), $failures);

    // 15. «1 гость» выключен у BAR -> 1 взрослый + ребёнок 7 лет: цена за 2 гостей, ребёнок на втором месте бесплатно
    $old1 = $mysql->fetchRow('SELECT * FROM hotels_rates_occupancy WHERE id_rate_room = ? AND guests = 1 ORDER BY id DESC LIMIT 1', array($occRr['id']));
    if($old1) {
        $mysql->query('UPDATE hotels_rates_occupancy SET active = 0 WHERE id = ?', array($old1['id']));
        $undo[] = function() use ($mysql, $old1) {
            $mysql->query('UPDATE hotels_rates_occupancy SET active = ? WHERE id = ?', array($old1['active'], $old1['id']));
        };
    } else {
        $mysql->query('INSERT INTO hotels_rates_occupancy (id_rate_room, id_room, id_rate, guests, amount_type, amount, active) VALUES (?, ?, ?, 1, 0, 0, 0)',
            array($occRr['id'], $occRr['id_room'], $occRr['id_rate']));
        $offId = (int)$mysql->lastInsertId();
        $undo[] = function() use ($mysql, $offId) {
            $mysql->query('DELETE FROM hotels_rates_occupancy WHERE id = ?', array($offId));
        };
    }
    sync($queue, $worker, $h3, 'occupancy');
    $offer = $model->hotelRooms($h3, array('checkin' => $stay['checkin'], 'nights' => $stay['nights'], 'rooms' => array($single)));
    $guests = null;
    foreach($offer['rooms'][0]['items'] as $item) {
        if($item['id_rate_room'] == $occRr['id']) {
            $guests = $item['guests'];
        }
    }
    check('15. 1 guest off: 1 adult + 7 y.o. == reference', expected_rooms($mysql, $h3, $stay, $single, $today), current_rooms($model, $h3, $stay, $single), $failures);
    check('    BAR priced for 2 guests (the child takes the 2nd place)', 2, $guests, $failures);

    // 16. младенцы не принимаются (max_infants = 0) -> 2 взрослых + младенец 1 год: ничего; без младенца — находится
    $inf = $mysql->fetchPairs('SELECT id, max_infants FROM hotels_rooms WHERE id_hotel = ?', array($h3));
    $mysql->query('UPDATE hotels_rooms SET max_infants = 0 WHERE id_hotel = ?', array($h3));
    $undo[] = function() use ($mysql, $inf) {
        foreach($inf as $id => $v) {
            $mysql->query('UPDATE hotels_rooms SET max_infants = ? WHERE id = ?', array($v, $id));
        }
    };
    sync($queue, $worker, $h3, 'room');
    $baby = array('adults' => 2, 'children' => array(1));
    check('16. max_infants = 0: 2 adults + 1 y.o. == reference (nothing)', expected_rooms($mysql, $h3, $stay, $baby, $today), current_rooms($model, $h3, $stay, $baby), $failures);
    check('    ... 2 adults + 8, 4 y.o. still == reference', expected_rooms($mysql, $h3, $stay, $kids, $today), current_rooms($model, $h3, $stay, $kids), $failures);

    // 17. отель перестал принимать детей -> с детьми не находится, без детей — находится
    $mysql->query('UPDATE hotels SET allow_children = 0 WHERE id = ?', array($h3));
    $undo[] = function() use ($mysql, $h3) {
        $mysql->query('UPDATE hotels SET allow_children = 1 WHERE id = ?', array($h3));
    };
    sync($queue, $worker, $h3, 'hotel');
    $res = $model->search(array('checkin' => $checkin, 'nights' => $nights, 'rooms' => array($kids), 'id_hotel' => array($h3)));
    check('17. adults only hotel: not found with children', 0, $res['total'], $failures);
    $res = $model->search(array('checkin' => $checkin, 'nights' => $nights, 'adults' => 2, 'id_hotel' => array($h3)));
    check('    ... and found without children', 1, $res['total'], $failures);
} catch(Exception $e) {
    $rollback();
    foreach($touched as $h) {
        $worker->syncHotels(array($h));
    }
    throw $e;
}

// финальный откат и пересборка затронутых отелей
$rollback();
foreach($touched as $h) {
    sync($queue, $worker, $h, 'restore');
}
check('18. all test changes rolled back -> same as initial', $initial, current_rates($model, $hotelId, $stay), $failures);
check('    children, 3 adults, 1 adult + child -> same as initial', array($initialKids, $initialThree, $initialSingle),
    array(current_rooms($model, $h3, $stay, $kids), current_rooms($model, $h3, $stay, $three), current_rooms($model, $h3, $stay, $single)), $failures);

echo $failures ? "\nFAILED: $failures\n" : "\nALL OK\n";
exit($failures ? 1 : 0);
