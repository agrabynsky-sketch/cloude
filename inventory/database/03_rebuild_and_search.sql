-- =====================================================================
--  Unit.Travel — Инвенторная система
--  Файл 03: ЗАПРОСЫ — пересборка кэша и горячий поиск
--  СУБД: MySQL 5.6 / 5.7 (без CTE и оконных функций!)
-- =====================================================================

-- ---------------------------------------------------------------------
--  A. ПЕРЕСБОРКА ПОСУТОЧНОГО КЭША (запускает PHP/cron при изменениях)
-- ---------------------------------------------------------------------
--  Логика (реализуется в PHP, здесь — суть SQL-шагов):
--   1) Для затронутого тарифа и диапазона дат удалить строки кэша.
--   2) Развернуть периоды цен/ограничений/аллотмента в посуточные строки,
--      применяя dow_mask (день недели) и приоритеты периодов.
--   3) Слить с room_availability (наличие) и обязательными per-night extras.
--   4) INSERT ... в search_daily одной пачкой (batch).
--
--  Пример «плоского» разворота одного тарифа за диапазон дат через
--  вспомогательный календарь дат (таблица calendar(d DATE PRIMARY KEY)):

-- Удаляем старое
DELETE FROM search_daily
 WHERE rate_plan_id = :rate_plan_id
   AND stay_date BETWEEN :from AND :to;

-- Разворачиваем и вставляем (наличие берём из room_availability,
-- обязательные per-night extras прибавляем к цене в PHP или подзапросом)
INSERT INTO search_daily
  (hotel_id, city_id, country_id, room_id, rate_plan_id, board_type_id,
   occupancy_id, adults, children, max_infants, is_refundable, stay_date,
   price, currency, available,
   min_stay, max_stay, cta, ctd, closed, min_advance, max_advance,
   channel_mask, visibility, access_group_id)
SELECT
   rp.hotel_id, h.city_id, h.country_id, rp.room_id, rp.id, rp.board_type_id,
   o.id, o.adults, o.children, rt.max_infants, rp.is_refundable, cal.d,
   pr.price + IFNULL(mext.per_night_sum,0)      AS price,
   rp.currency,
   GREATEST(LEAST(CAST(av.allotment AS SIGNED), rt.total_rooms)
            - av.booked - av.blocked, 0) AS available,
   IFNULL(rs.min_stay,1), IFNULL(rs.max_stay,0),
   IFNULL(rs.closed_to_arrival,0), IFNULL(rs.closed_to_departure,0),
   IFNULL(rs.stop_sell,0), IFNULL(rs.min_advance_days,0), IFNULL(rs.max_advance_days,0),
   rp.channel_mask,
   IF(rp.visibility='private',1,0), rp.access_group_id
FROM calendar cal
JOIN rate_plans rp        ON rp.id = :rate_plan_id AND rp.active = 1
JOIN hotels h             ON h.id = rp.hotel_id
JOIN room rt              ON rt.id = rp.room_id
JOIN occupancy_options o  ON o.room_id = rp.room_id
JOIN rate_prices pr       ON pr.rate_plan_id = rp.id
                        AND pr.occupancy_id = o.id
                        AND cal.d BETWEEN pr.date_from AND pr.date_to
                        AND (pr.dow_mask & (1 << WEEKDAY(cal.d)))
LEFT JOIN rate_restrictions rs ON rs.rate_plan_id = rp.id
                        AND cal.d BETWEEN rs.date_from AND rs.date_to
                        AND (rs.dow_mask & (1 << WEEKDAY(cal.d)))
-- наличие на КАТЕГОРИЮ -> общий счётчик для всех тарифов room
LEFT JOIN room_availability av ON av.room_id = rp.room_id
                        AND av.stay_date = cal.d
LEFT JOIN (
     -- сумма обязательных per-night extras на тариф
     SELECT rpe.rate_plan_id, SUM(e.price) AS per_night_sum
       FROM rate_plan_extras rpe
       JOIN extras e ON e.id = rpe.extra_id
      WHERE e.is_mandatory = 1 AND e.charge_type = 'per_night'
      GROUP BY rpe.rate_plan_id
) mext ON mext.rate_plan_id = rp.id
WHERE cal.d BETWEEN :from AND :to;

-- Примечание: при пересечении периодов цен выбор нужной строки (по
-- приоритету/самому узкому периоду) делает PHP перед вставкой, либо
-- периоды хранятся непересекающимися по контракту загрузки.
--
-- PRICING_MODEL и ПРОИЗВОДНЫЕ ТАРИФЫ разрешаются PHP на этапе пересборки,
-- поэтому в search_daily всегда лежит УЖЕ финальная цена за ночь:
--   * pricing_model=1 (per room)      -> одна цена на все occupancy_id
--     (можно продублировать в строки размещений или искать по базовому);
--   * pricing_model=2 (occupancy)     -> цена берётся из rate_prices по occupancy_id.
--   * derive_type!=0                  -> цена = f(цена родителя, derive_value):
--       1 parent+fixed, 2 parent-fixed (derive_value в minor units),
--       3 parent+percent, 4 parent-percent (basis points: 1000 = 10.00%),
--       5 same as parent.
--     Пересборка ИДЁТ ОТ КОРНЯ: сначала родитель, затем производные.
--     Изменение родителя ставит в cache_rebuild_queue и всех потомков
--     (обход по rate_plans.parent_rate_plan_id, индекс idx_rp_parent).
--     Циклы наследования запрещены на уровне валидации в PHP.


-- ---------------------------------------------------------------------
--  B. ГОРЯЧИЙ ПОИСК — минимальный тариф по сотням отелей за один запрос
-- ---------------------------------------------------------------------
--  Вход из PHP:
--    :checkin  — дата заезда
--    :checkout — дата выезда  (ночей = DATEDIFF(:checkout,:checkin) = :nights)
--    :occ      — occupancy_id, разрешённый из (adults, children)
--    :rooms    — сколько номеров нужно (обычно 1)
--    :channel  — бит канала (напр. 1 = web B2C, 2 = b2b, ...)
--    :hotels   — список hotel_id (или используйте city_id вариант)
--    :today    — текущая дата (для окон бронирования)
--    :groups   — набор access_group_id, доступных этому зрителю
--                (из логина аккаунта + введённого negotiated/promo-кода);
--                для анонима/публичного поиска = пустой -> только public
--    :board    — board_type_id (или NULL = любой тип питания)
--    :refundable_only — 1 = только тарифы с бесплатной отменой, иначе 0
--    :infants  — число младенцев (проверяется против room.max_infants)
--
--  Идея: берём ВСЕ ночи диапазона [checkin, checkout) одним range-scan,
--  группируем по тарифу; тариф проходит только если:
--    * покрыты ВСЕ ночи         -> COUNT(*) = :nights
--    * наличие на каждую ночь    -> MIN(available) >= :rooms
--    * нет stop-sell             -> MAX(closed) = 0
--    * min/max stay на заезде    -> проверка строки заезда
--    * CTA на дату заезда        -> нет
--    * окна бронирования         -> min/max advance
--  Затем берём минимальный total_price на отель.

SELECT hotel_id, MIN(total_price) AS min_total_price
FROM (
    SELECT
        sd.hotel_id,
        sd.rate_plan_id,
        SUM(sd.price)                                  AS total_price,
        COUNT(*)                                       AS nights,
        MIN(sd.available)                              AS min_avail,
        MAX(sd.closed)                                 AS any_closed,
        -- правила, действующие на ночь ЗАЕЗДА:
        MAX(CASE WHEN sd.stay_date = :checkin AND sd.cta = 1 THEN 1 ELSE 0 END)                    AS cta_block,
        MAX(CASE WHEN sd.stay_date = :checkin AND sd.min_stay > :nights THEN 1 ELSE 0 END)         AS minstay_block,
        MAX(CASE WHEN sd.stay_date = :checkin AND sd.max_stay > 0
                  AND sd.max_stay < :nights THEN 1 ELSE 0 END)                                     AS maxstay_block,
        MAX(CASE WHEN sd.stay_date = :checkin
                  AND sd.min_advance > DATEDIFF(:checkin, :today) THEN 1 ELSE 0 END)               AS minadv_block,
        MAX(CASE WHEN sd.stay_date = :checkin AND sd.max_advance > 0
                  AND sd.max_advance < DATEDIFF(:checkin, :today) THEN 1 ELSE 0 END)               AS maxadv_block
    FROM search_daily sd
    WHERE sd.occupancy_id = :occ
      AND sd.stay_date >= :checkin
      AND sd.stay_date <  :checkout
      AND sd.hotel_id IN ( /* :hotels */ )
      AND sd.closed = 0
      AND sd.available >= :rooms
      AND (sd.channel_mask & :channel)                       -- ось 1: канал
      AND (sd.access_group_id = 0                            -- ось 2: публичный
           OR sd.access_group_id IN ( /* :groups */ ))       --   или доступный зрителю
      AND (:board IS NULL OR sd.board_type_id = :board)      -- тип питания
      AND (:refundable_only = 0 OR sd.is_refundable = 1)     -- только free cancellation
      AND sd.max_infants >= :infants                         -- вместимость по младенцам
    GROUP BY sd.hotel_id, sd.rate_plan_id
    HAVING nights = :nights
       AND cta_block = 0
       AND minstay_block = 0
       AND maxstay_block = 0
       AND minadv_block = 0
       AND maxadv_block = 0
) AS ok_rates
GROUP BY hotel_id;

-- CTD (closed-to-departure) проверяется по строке ДАТЫ ВЫЕЗДА отдельно
-- (она не входит в ночи проживания). PHP делает быстрый добор:
--   SELECT rate_plan_id FROM search_daily
--    WHERE occupancy_id=:occ AND stay_date=:checkout AND ctd=1
--      AND rate_plan_id IN (<кандидаты>);
-- и исключает такие тарифы. В большинстве продаж CTD включают редко,
-- поэтому добор дешевле, чем таскать выездную ночь в основном скане.


-- ---------------------------------------------------------------------
--  C. ПОИСК ПО ГОРОДУ/ЛОКАЦИИ — мин. цена отеля за ВЕСЬ период
--     Это тот же запрос B, но вместо списка hotel_id фильтруем по city_id
--     (денормализован в search_daily -> без JOIN). Возвращает точную
--     минимальную цену за период проживания по каждому отелю города —
--     ровно то, что показывается в выдаче Unit.Travel.
--     Никакого понедельного предагрегата: SUM идёт ВНУТРИ одного тарифа
--     (после GROUP BY rate_plan_id), поэтому цена реально бронируемая.
-- ---------------------------------------------------------------------

SELECT hotel_id, MIN(total_price) AS min_total_price
FROM (
    SELECT
        sd.hotel_id,
        sd.rate_plan_id,
        SUM(sd.price)                                  AS total_price,
        COUNT(*)                                       AS nights,
        MAX(CASE WHEN sd.stay_date = :checkin AND sd.cta = 1 THEN 1 ELSE 0 END)             AS cta_block,
        MAX(CASE WHEN sd.stay_date = :checkin AND sd.min_stay > :nights THEN 1 ELSE 0 END)  AS minstay_block
        -- + max_stay / min_advance / max_advance аналогично запросу B
    FROM search_daily sd
    WHERE sd.occupancy_id = :occ
      AND sd.stay_date >= :checkin
      AND sd.stay_date <  :checkout
      AND sd.city_id = :city                                  -- поиск по городу
      AND sd.closed = 0
      AND sd.available >= :rooms
      AND (sd.channel_mask & :channel)
      AND (sd.access_group_id = 0 OR sd.access_group_id IN ( /* :groups */ ))
      AND (:board IS NULL OR sd.board_type_id = :board)
      AND (:refundable_only = 0 OR sd.is_refundable = 1)
      AND sd.max_infants >= :infants
    GROUP BY sd.hotel_id, sd.rate_plan_id
    HAVING nights = :nights AND cta_block = 0 AND minstay_block = 0
) AS ok_rates
GROUP BY hotel_id                    -- ОДНА строка на отель = одна мин. цена за период
ORDER BY min_total_price ASC
LIMIT :page;   -- пагинация выдачи по городу

-- Индекс idx_search_city (occupancy_id, stay_date, city_id, closed,
-- access_group_id, price) держит это в одном range-scan. CTD добирается
-- по дате выезда так же, как в B.


-- ---------------------------------------------------------------------
--  E. СТРАНИЦА ОТЕЛЯ — ВСЕ доступные румрейты за период (сырая цена)
--     Тот же подзапрос, что в B/C, но БЕЗ внешнего MIN по отелю: отдаём
--     каждый прошедший тариф с просуммированной СЫРОЙ ценой (без налогов
--     и сборов — их добавит PHP на финальной цене по политикам отеля).
--     Это доказывает: «одна мин. цена» в B/C — лишь внешняя агрегация;
--     внутри уже посчитаны все румрейты.
-- ---------------------------------------------------------------------

SELECT
    sd.room_id,
    sd.rate_plan_id,
    sd.board_type_id,
    sd.is_refundable,
    sd.currency,
    SUM(sd.price)  AS total_raw_price,   -- сырая сумма за период (нетто)
    MIN(sd.available) AS min_avail
FROM search_daily sd
WHERE sd.hotel_id = :hotel                    -- один отель
  AND sd.occupancy_id = :occ
  AND sd.stay_date >= :checkin
  AND sd.stay_date <  :checkout
  AND sd.closed = 0
  AND sd.available >= :rooms
  AND (sd.channel_mask & :channel)
  AND (sd.access_group_id = 0 OR sd.access_group_id IN ( /* :groups */ ))
  AND (:board IS NULL OR sd.board_type_id = :board)
  AND (:refundable_only = 0 OR sd.is_refundable = 1)
  AND sd.max_infants >= :infants
GROUP BY sd.room_id, sd.rate_plan_id, sd.board_type_id, sd.is_refundable, sd.currency
HAVING COUNT(*) = :nights
   AND MAX(CASE WHEN sd.stay_date = :checkin AND sd.cta = 1 THEN 1 ELSE 0 END) = 0
   AND MAX(CASE WHEN sd.stay_date = :checkin AND sd.min_stay > :nights THEN 1 ELSE 0 END) = 0
   -- + max_stay / advance аналогично B
ORDER BY total_raw_price ASC;
-- PHP далее: налоги/сборы (hotels.vat_policy_id/fee_policy_id), опциональные extras, наценка,
-- конвертация валют, штраф отмены (cancellation_rules) — на этих строках.


-- ---------------------------------------------------------------------
--  F. MULTIROOM-ПОИСК (несколько номеров, одинаковые/разные категории)
--     Каждый «номер» из запроса — отдельная НОГА поиска со своим
--     occupancy_id (2 взр; 2 взр + ребёнок; и т.п.). Схема поддерживает
--     multiroom БЕЗ изменений — оркестрация в PHP:
--       1) сгруппировать запрошенные номера по (occupancy_id) -> qty;
--       2) для одинаковых номеров одной категории: искать с :rooms = qty
--          (available >= qty на каждую ночь уже гарантирует общий фонд);
--       3) для РАЗНЫХ occupancy/категорий: выполнить запрос E по каждой
--          ноге, затем скомбинировать в PHP (сумма мин. цен ног);
--       4) ВАЖНО про общий фонд: если несколько ног тянут из ОДНОЙ
--          категории (room_id), суммарный спрос по ней = сумма qty ног;
--          финальную достаточность гарантирует атомарный декремент при
--          брони (запрос D: одна транзакция, все ночи, все номера).
--     Т.е. поиск — это N независимых прогонов B/E + сборка в PHP; общий
--     счётчик наличия на room делает результат корректным при брони.
-- ---------------------------------------------------------------------


-- ---------------------------------------------------------------------
--  D. БРОНИРОВАНИЕ — атомарный декремент наличия КАТЕГОРИИ
--     При подтверждении брони ЛЮБОГО тарифа категории списываем номера с
--     ОДНОГО счётчика room_availability. Это защищает от овербукинга,
--     когда 4 тарифа Deluxe (RO/BB × Flex/NRF) делят одни 3 номера.
--
--     Делать в ОДНОЙ транзакции по всем ночам проживания. Условие
--     available >= :rooms проверяется атомарно в самом UPDATE:
-- ---------------------------------------------------------------------

START TRANSACTION;

-- на каждую ночь [checkin, checkout): списать, только если хватает
UPDATE room_availability
   SET booked = booked + :rooms,
       updated_at = NOW()
 WHERE room_id = :room_id
   AND stay_date = :night
   AND (LEAST(allotment, (SELECT total_rooms FROM room WHERE id = :room_id))
        - booked - blocked) >= :rooms;
-- если ROW_COUNT() = 0 хотя бы на одну ночь -> ROLLBACK (номеров не хватило).

COMMIT;

-- После успешной брони:
--   * поставить cache_rebuild_queue scope='room' на категорию/даты
--     (пересоберёт available во ВСЕХ тарифах категории);
--   * при двусторонней интеграции — положить обновление наличия в ari_outbox
--     для отправки в PMS/Channel Manager (защита от овербукинга на их стороне).
