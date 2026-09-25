<?php
/**
 * Сквозной тест синхронизации: изменение в MySQL -> очередь -> воркер -> ClickHouse == эталон.
 *   php tests/queue_flow.php [hotel_id]
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
    $first = (int)$mysql->fetchOne("SELECT id_from FROM search_demo_registry WHERE entity = 'hotels'");
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
        $got[$row['rate_room_id']] = $row['price_minor'];
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

// 0. исходное состояние
sync($queue, $worker, $hotelId, 'test');
$initial = current_rates($model, $hotelId, $stay);
check('0. initial state == reference', expected_rates($mysql, $hotelId, $stay, $today), $initial, $failures);

// 1. BAR +100.00 на все ночи проживания (производный Non-refundable должен подорожать тоже)
$mysql->beginTransaction();
$mysql->query('UPDATE hotels_rates_prices SET price = price + 100 WHERE id_rate = ? AND date BETWEEN ? AND ?', array($bar, $checkin, $lastNight));
$queue->push($hotelId, 'bulkedit');   // в той же транзакции, что и изменение цен
$mysql->commit();
$worker->runOnce();
$after = current_rates($model, $hotelId, $stay);
check('1. BAR price +100 -> cache updated', expected_rates($mysql, $hotelId, $stay, $today), $after, $failures);
printf("   changed room-rates: %d\n", count(array_diff_assoc($after, $initial)));

// 2. тариф BB выключен -> его рум-рейты исчезают из кэша (tombstone-строки)
$mysql->query('UPDATE hotels_rates SET active = 0 WHERE id = ?', array($bb));
sync($queue, $worker, $hotelId, 'rate');
check('2. BB rate deactivated -> removed from cache', expected_rates($mysql, $hotelId, $stay, $today), current_rates($model, $hotelId, $stay), $failures);
printf("   rows of BB left in cache (FINAL): %d\n", $chHttp->fetchOne('SELECT count() FROM search_stay FINAL WHERE rate_id = ' . (int)$bb));

// 3. BAR стал private -> пропадает из публичного поиска, хотя старые версии строк ещё лежат в частях таблицы
$mysql->query("UPDATE hotels_rates SET visibility = 'private' WHERE id = ?", array($bar));
sync($queue, $worker, $hotelId, 'rate');
check('3. BAR private -> hidden from public search (FINAL + WHERE)', expected_rates($mysql, $hotelId, $stay, $today), current_rates($model, $hotelId, $stay), $failures);

// 4. стоп-продажа номера на одну ночь -> все тарифы этого номера недоступны
$roomId = (int)$mysql->fetchOne('SELECT id FROM hotels_rooms WHERE id_hotel = ? ORDER BY id LIMIT 1', array($hotelId));
$mysql->query('INSERT INTO hotels_rooms_availability (id_room, date, allotment, net_booked, active) VALUES (?, ?, 5, 0, 0)', array($roomId, $checkin));
$stopId = (int)$mysql->lastInsertId();
sync($queue, $worker, $hotelId, 'availability');
check('4. stop-sale of one room on arrival night', expected_rates($mysql, $hotelId, $stay, $today), current_rates($model, $hotelId, $stay), $failures);

// 5. отель выключен -> исчезает из поиска
$mysql->query('UPDATE hotels SET active = 0 WHERE id = ?', array($hotelId));
sync($queue, $worker, $hotelId, 'hotel');
$res = $model->search(array('checkin' => $checkin, 'nights' => $nights, 'guests' => 2, 'hotel_ids' => array($hotelId)));
check('5. hotel deactivated -> not found by search()', 0, $res['total'], $failures);

// 6. защита очереди: изменение во время сборки не теряется
$queue->push($hotelId, 'test');
$claimed = $queue->claim('test-token', 10);
$queue->push($hotelId, 'changed-during-build');
$queue->done('test-token', $claimed);
$left = $mysql->fetchOne('SELECT COUNT(*) FROM search_sync_queue WHERE hotel_id = ?', array($hotelId));
check('6. change during build keeps hotel in queue', 1, (int)$left, $failures);

// откат
$mysql->query('UPDATE hotels SET active = 1 WHERE id = ?', array($hotelId));
$mysql->query('DELETE FROM hotels_rooms_availability WHERE id = ?', array($stopId));
$mysql->query("UPDATE hotels_rates SET visibility = 'public' WHERE id = ?", array($bar));
$mysql->query('UPDATE hotels_rates SET active = 1 WHERE id = ?', array($bb));
$mysql->query('UPDATE hotels_rates_prices SET price = price - 100 WHERE id_rate = ? AND date BETWEEN ? AND ?', array($bar, $checkin, $lastNight));
sync($queue, $worker, $hotelId, 'restore');
check('7. everything restored -> same as initial', $initial, current_rates($model, $hotelId, $stay), $failures);

echo $failures ? "\nFAILED: $failures\n" : "\nALL OK\n";
exit($failures ? 1 : 0);
