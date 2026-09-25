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

    // 8. дневная цена на 3 гостей (hotels_rates_occupancy_daily) для номера с pricing_model = 2
    $stay3 = array('checkin' => $checkin, 'nights' => $nights, 'guests' => 3);
    $first = (int)$mysql->fetchOne("SELECT id_from FROM hotels_search_demo_registry WHERE entity = 'hotels'");
    $target = null;
    for($h = $first + 1; $h < $first + 200 && !$target; $h += 2) {           // у чётных демо-отелей есть pricing_model = 2
        foreach(referenceRates($mysql, $h, $checkin, $nights, 3, $today) as $rrId => $v) {
            $pm = $mysql->fetchOne('SELECT r.pricing_model FROM hotels_rates_rooms rr JOIN hotels_rooms r ON r.id = rr.id_room WHERE rr.id = ?', array($rrId));
            if(2 == $pm) {
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
    // строка с price = 0 ("не задано") — на ночь, где своих дневных цен ещё нет
    $second = null;
    for($i = 1; $i < $nights && !$second; $i++) {
        $date = date('Y-m-d', strtotime("$checkin +$i day"));
        if(!$mysql->fetchOne('SELECT COUNT(*) FROM hotels_rates_occupancy_daily WHERE id_rate_room = ? AND guests = 3 AND date = ?', array($rr2, $date))) {
            $second = $date;
        }
    }
    $mysql->query('INSERT INTO hotels_rates_occupancy_daily (id_rate_room, id_room, id_rate, guests, date, price) VALUES (?, ?, ?, 3, ?, 0)',
        array($rr2, $row['id_room'], $row['id_rate'], $second));
    $d2 = (int)$mysql->lastInsertId();
    $undo[] = function() use ($mysql, $d2) {
        $mysql->query('DELETE FROM hotels_rates_occupancy_daily WHERE id = ?', array($d2));
    };
    $before3 = current_rates($model, $h2, $stay3);
    sync($queue, $worker, $h2, 'occupancy_daily');
    $after3 = current_rates($model, $h2, $stay3);
    check("8. daily price for 3 guests (hotel $h2, rr $rr2) == reference", expected_rates($mysql, $h2, $stay3, $today), $after3, $failures);
    check('   first night = 1234.56, price 0 on another night ignored', $total2 - $nightly2[0] + 123456, $after3[$rr2], $failures);

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
check('10. all test changes rolled back -> same as initial', $initial, current_rates($model, $hotelId, $stay), $failures);

echo $failures ? "\nFAILED: $failures\n" : "\nALL OK\n";
exit($failures ? 1 : 0);
