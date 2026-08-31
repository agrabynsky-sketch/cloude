<?php
declare(strict_types=1);

/**
 * Unit.Travel — финализация цен с учётом ВОЗРАСТА детей.
 *
 * SQL (запросы B/C/E из database/03_rebuild_and_search.sql) уже:
 *   - отфильтровал вместимость (max_children/max_occupancy/max_infants),
 *     поэтому отели, НЕ размещающие детей, сюда не приходят;
 *   - вернул кандидатов: по одной строке на (hotel_id, rate_plan_id) с
 *     БАЗОВОЙ ценой по взрослым за весь период (adult_total).
 *
 * Здесь мы:
 *   1) для каждого кандидата считаем детскую доплату под ТОЧНЫЕ возрасты
 *      (пер-отельные бэнды + child_prices тарифа);
 *   2) если у тарифа нет цены на ребёнка этого возраста — исключаем тариф
 *      (или считаем ребёнка взрослым — по политике);
 *   3) берём минимальную ПОЛНУЮ цену по каждому отелю.
 */

// ─────────────────────────── входные структуры ───────────────────────────

/** Запрос гостей. Инфанты (0-1) — отдельно, бесплатны, в childrenAges НЕ входят. */
final class GuestRequest
{
    /** @param int[] $childrenAges напр. [6, 8] */
    public function __construct(
        public readonly int $adults,
        public readonly array $childrenAges,
        public readonly int $infants = 0,
        public readonly int $nights = 1,
    ) {}
}

/** Кандидат из SQL: одна строка на (hotel_id, rate_plan_id). */
final class RateCandidate
{
    public function __construct(
        public readonly int $hotelId,
        public readonly int $roomId,
        public readonly int $ratePlanId,
        public readonly string $currency,
        public readonly float $adultTotal,  // SUM базовой цены по взрослым за все ночи
        public readonly int $nights,
    ) {}
}

/** Возрастной бэнд отеля (child_age_bands). */
final class AgeBand
{
    public function __construct(
        public readonly int $bandNo,
        public readonly int $ageFrom,   // включительно
        public readonly int $ageTo,     // включительно
        public readonly bool $inPricing, // false = инфант/бесплатно
    ) {}
}

/** Детская цена по бэнду для тарифа (child_prices, уже под даты стея). */
final class ChildPrice
{
    public function __construct(
        public readonly string $chargeType, // 'free' | 'percent' | 'fixed'
        public readonly float $value,       // percent: 50 = 50% от взрослой ночи; fixed: сумма/ночь
    ) {}
}

/** Что делать, если у тарифа нет цены на ребёнка этого возраста. */
enum NoChildPricePolicy: string
{
    case Reject  = 'reject';    // безопасно: тариф не продаём этим детям (дефолт)
    case AsAdult = 'as_adult';  // считать ребёнка как доп. взрослого (по контракту)
}

// ───────────────────────────── провайдер конфига ─────────────────────────

/**
 * Отдаёт пер-отельные бэнды и детские цены. В бою — batch-загрузка ОДНИМ
 * запросом на все hotelId/ratePlanId кандидатов (без N+1), затем кэш в память.
 */
interface ChildPricingConfig
{
    /** @return AgeBand[] бэнды отеля; если своих нет — платформенный дефолт (hotel_id IS NULL). */
    public function ageBands(int $hotelId): array;

    /** @return array<int,ChildPrice> карта bandNo => ChildPrice для тарифа. */
    public function childPrices(int $ratePlanId): array;
}

// ──────────────────────────────── логика ─────────────────────────────────

/** Находит бэнд по возрасту (первый подходящий). null = возраст вне бэндов. */
function bandForAge(int $age, array $bands): ?AgeBand
{
    foreach ($bands as $b) {
        if ($age >= $b->ageFrom && $age <= $b->ageTo) {
            return $b;
        }
    }
    return null;
}

/**
 * Детская доплата за весь стей для одного тарифа.
 *
 * @return float|null  сумма доплаты, или NULL если тариф НЕ может продать
 *                      этим детям (нет правила и политика = Reject).
 */
function childSurcharge(
    RateCandidate $c,
    GuestRequest $req,
    array $bands,              // AgeBand[]
    array $priceByBand,        // array<int,ChildPrice>
    NoChildPricePolicy $policy = NoChildPricePolicy::Reject,
): ?float {
    if ($req->childrenAges === []) {
        return 0.0; // детей нет — доплаты нет
    }

    // Цена «взрослой ночи» = ночная цена размещения (для percent-детей).
    $adultNightly = $c->adultTotal / max(1, $c->nights);

    $sum = 0.0;
    foreach ($req->childrenAges as $age) {
        $band = bandForAge($age, $bands);

        // Возраст вне детских бэндов (или инфант-бэнд) — по политике.
        if ($band === null || !$band->inPricing) {
            if ($band !== null && !$band->inPricing) {
                continue; // инфант в childrenAges по ошибке — бесплатно
            }
            // Возраст не покрыт бэндами отеля:
            if ($policy === NoChildPricePolicy::AsAdult) {
                $sum += $adultNightly; // считаем как доп. взрослого
                continue;
            }
            return null; // Reject: тариф не продаём этим гостям
        }

        $price = $priceByBand[$band->bandNo] ?? null;

        // ОТЕЛЬ НЕ ВЕРНУЛ ЦЕНУ НА РЕБЁНКА ЭТОГО ВОЗРАСТА:
        if ($price === null) {
            if ($policy === NoChildPricePolicy::AsAdult) {
                $sum += $adultNightly;
                continue;
            }
            return null; // Reject (дефолт): исключаем тариф целиком
        }

        $sum += match ($price->chargeType) {
            'free'    => 0.0,
            'fixed'   => $price->value * $c->nights,                         // сумма за ребёнка/ночь
            'percent' => $adultNightly * ($price->value / 100.0) * $c->nights, // % от взрослой ночи
            default   => throw new \RuntimeException("bad charge_type: {$price->chargeType}"),
        };
    }
    return $sum;
}

/**
 * Главная функция: из кандидатов SQL оставляет по одному минимальному
 * варианту на отель — уже с учётом ВОЗРАСТА детей.
 *
 * @param RateCandidate[] $candidates
 * @return array<int,array{price:float,ratePlanId:int,roomId:int,currency:string}>
 *         keyed by hotelId; отели без валидного тарифа для этих детей отсутствуют.
 */
function minPricePerHotel(
    array $candidates,
    GuestRequest $req,
    ChildPricingConfig $cfg,
    NoChildPricePolicy $policy = NoChildPricePolicy::Reject,
): array {
    $best = [];

    foreach ($candidates as $c) {
        $bands       = $cfg->ageBands($c->hotelId);       // пер-отельные (+ дефолт)
        $priceByBand = $cfg->childPrices($c->ratePlanId); // bandNo => ChildPrice

        $child = childSurcharge($c, $req, $bands, $priceByBand, $policy);
        if ($child === null) {
            continue; // тариф не продаёт этим детям — пропускаем
        }

        $full = $c->adultTotal + $child;

        // минимум по отелю (детская доплата могла поменять, какой тариф дешевле)
        if (!isset($best[$c->hotelId]) || $full < $best[$c->hotelId]['price']) {
            $best[$c->hotelId] = [
                'price'      => round($full, 2),
                'ratePlanId' => $c->ratePlanId,
                'roomId'     => $c->roomId,
                'currency'   => $c->currency,
            ];
        }
    }

    return $best;
}

// ──────────────────────────────── пример ─────────────────────────────────

// Конфиг-заглушка (в бою — batch из child_age_bands / child_prices).
$cfg = new class implements ChildPricingConfig {
    public function ageBands(int $hotelId): array {
        // Пер-отельные бэнды; здесь один набор для примера.
        return [
            new AgeBand(1, 0,  1,  false), // инфант — бесплатно
            new AgeBand(2, 2,  6,  true),
            new AgeBand(3, 7,  12, true),
            new AgeBand(4, 13, 17, true),
        ];
    }
    public function childPrices(int $ratePlanId): array {
        // Тариф 111: есть цены на 2-6 и 7-12, но НЕ на 13-17 (подростков не тарифицирует).
        // Тариф 222: только фикс за любого ребёнка 2-12; тоже без подростков.
        return match ($ratePlanId) {
            111 => [2 => new ChildPrice('percent', 50), 3 => new ChildPrice('percent', 75)],
            222 => [2 => new ChildPrice('fixed', 20),   3 => new ChildPrice('fixed', 20)],
            default => [],
        };
    }
};

// Запрос: 2 взрослых + дети 6 и 8, 3 ночи.
$req = new GuestRequest(adults: 2, childrenAges: [6, 8], infants: 0, nights: 3);

// Кандидаты «из SQL» (adult_total = база по взрослым за 3 ночи):
$candidates = [
    new RateCandidate(hotelId: 500, roomId: 4500, ratePlanId: 111, currency: 'EUR', adultTotal: 300.0, nights: 3),
    new RateCandidate(hotelId: 500, roomId: 4500, ratePlanId: 222, currency: 'EUR', adultTotal: 330.0, nights: 3),
    new RateCandidate(hotelId: 700, roomId: 6500, ratePlanId: 111, currency: 'EUR', adultTotal: 280.0, nights: 3),
];

$result = minPricePerHotel($candidates, $req, $cfg);
print_r($result);
/*
Ожидаемо:
  Отель 500:
    тариф 111: adultNightly=100; ребёнок 6 -> 50% =50/ночь, ребёнок 8 -> 75% =75/ночь
               доплата=(50+75)*3=375; полная=300+375=675
    тариф 222: fixed 20/реб/ночь ×2 ×3 =120; полная=330+120=450  <-- дешевле
    => min 450 (rate 222)
  Отель 700:
    тариф 111: adultNightly=280/3≈93.33; дети 6,8 -> (46.67+70.0)*3≈350
               полная=280+350=630
    => min 630 (rate 111)

Если бы запрос был с ребёнком 15 (подросток) и политика Reject:
  ни у 111, ни у 222 нет цены на бэнд 13-17 -> оба тарифа исключены ->
  отели без других тарифов НЕ попадают в результат.
*/
