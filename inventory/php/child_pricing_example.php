<?php
declare(strict_types=1);

/**
 * Unit.Travel — финализация цен с учётом ВОЗРАСТА детей.
 * Модель как в Booking.com Extranet: Child policy + Child rates на уровне ОТЕЛЯ,
 * произвольное число возрастных диапазонов 0-17.
 *
 * SQL (запросы B/C/E из database/03_rebuild_and_search.sql) уже:
 *   - отфильтровал ВМЕСТИМОСТЬ (max_children/max_occupancy/max_infants),
 *     поэтому номера/отели без детского размещения сюда не приходят;
 *   - вернул кандидатов: по одной строке на (hotel_id, rate_plan_id) с
 *     БАЗОВОЙ ценой по взрослым за весь период (adult_total).
 *
 * Здесь: считаем детскую доплату под ТОЧНЫЕ возрасты по child_rates отеля и
 * берём минимальную ПОЛНУЮ цену на отель.
 */

// ─────────────────────────── входные структуры ───────────────────────────

/** Запрос гостей. Инфанты входят в childrenAges как обычный возраст (0-1). */
final class GuestRequest
{
    /** @param int[] $childrenAges напр. [3, 8] */
    public function __construct(
        public readonly int $adults,
        public readonly array $childrenAges,
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

/** Детская политика отеля (hotels.allow_children / children_min_age). */
final class HotelChildPolicy
{
    public function __construct(
        public readonly bool $allowChildren,
        public readonly int $minAge = 0,   // 0 = Any
    ) {}
}

/** Одна строка child_rates: возрастной диапазон + его цена (как у Booking). */
final class ChildRate
{
    public function __construct(
        public readonly int $ageFrom,      // включительно
        public readonly int $ageTo,        // включительно
        public readonly string $chargeType, // 'free' | 'percent' | 'fixed'
        public readonly float $amount,      // percent: 50=50%; fixed: сумма
        public readonly string $unit = 'per_child_night', // | 'per_child_stay'
    ) {}
}

/** Что делать, если на возраст нет строки child_rates. */
enum NoChildRatePolicy: string
{
    case Reject  = 'reject';    // безопасно: тариф не продаём этим детям (дефолт)
    case AsAdult = 'as_adult';  // считать ребёнка как доп. взрослого
}

// ───────────────────────────── провайдер конфига ─────────────────────────

/**
 * Отдаёт детский конфиг отеля. В бою — batch-загрузка ОДНИМ запросом на все
 * hotelId кандидатов (child_rates + hotels), затем кэш в памяти (без N+1).
 */
interface ChildConfig
{
    public function policy(int $hotelId): HotelChildPolicy;

    /**
     * @return ChildRate[] ставки отеля (при наличии override на тариф —
     *         уже отобранные под этот rate_plan/даты).
     */
    public function rates(int $hotelId, int $ratePlanId): array;
}

// ──────────────────────────────── логика ─────────────────────────────────

/** Первый диапазон child_rates, покрывающий возраст. null = нет ставки. */
function rateForAge(int $age, array $rates): ?ChildRate
{
    foreach ($rates as $r) {
        if ($age >= $r->ageFrom && $age <= $r->ageTo) {
            return $r;
        }
    }
    return null;
}

/**
 * Детская доплата за весь стей для одного тарифа.
 * @return float|null сумма доплаты, или NULL если тариф НЕ продаётся этим детям.
 */
function childSurcharge(
    RateCandidate $c,
    GuestRequest $req,
    HotelChildPolicy $policy,
    array $rates,                 // ChildRate[]
    NoChildRatePolicy $fallback = NoChildRatePolicy::Reject,
): ?float {
    if ($req->childrenAges === []) {
        return 0.0;
    }
    if (!$policy->allowChildren) {
        return null; // отель детей не принимает
    }

    $adultNightly = $c->adultTotal / max(1, $c->nights); // ночь размещения (для percent)
    $sum = 0.0;

    foreach ($req->childrenAges as $age) {
        if ($age < $policy->minAge) {
            return null; // возраст ниже допустимого политикой -> тариф не продаём
        }

        $rate = rateForAge($age, $rates);
        if ($rate === null) {
            // НЕТ детской ставки на этот возраст:
            if ($fallback === NoChildRatePolicy::AsAdult) {
                $sum += $adultNightly * $c->nights;
                continue;
            }
            return null; // Reject (дефолт): исключаем тариф
        }

        $sum += match ($rate->chargeType) {
            'free'    => 0.0,
            'percent' => $adultNightly * ($rate->amount / 100.0) * $c->nights,
            'fixed'   => $rate->unit === 'per_child_stay'
                            ? $rate->amount
                            : $rate->amount * $c->nights,
            default   => throw new \RuntimeException("bad charge_type: {$rate->chargeType}"),
        };
    }
    return $sum;
}

/**
 * Из кандидатов SQL — по одному минимальному варианту на отель, с учётом
 * возраста детей.
 * @param RateCandidate[] $candidates
 * @return array<int,array{price:float,ratePlanId:int,roomId:int,currency:string}>
 */
function minPricePerHotel(
    array $candidates,
    GuestRequest $req,
    ChildConfig $cfg,
    NoChildRatePolicy $fallback = NoChildRatePolicy::Reject,
): array {
    $best = [];
    foreach ($candidates as $c) {
        $policy = $cfg->policy($c->hotelId);
        $rates  = $cfg->rates($c->hotelId, $c->ratePlanId);

        $child = childSurcharge($c, $req, $policy, $rates, $fallback);
        if ($child === null) {
            continue; // тариф не продаётся этим детям
        }
        $full = $c->adultTotal + $child;

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

// Конфиг как на скринах Booking: 0-5 бесплатно, 6-10 = 100/ночь.
$cfg = new class implements ChildConfig {
    public function policy(int $hotelId): HotelChildPolicy {
        return new HotelChildPolicy(allowChildren: true, minAge: 0); // "Any"
    }
    public function rates(int $hotelId, int $ratePlanId): array {
        return [
            new ChildRate(0, 5,  'free',  0,   'per_child_night'),   // 0-5 бесплатно
            new ChildRate(6, 10, 'fixed', 100, 'per_child_night'),   // 6-10 = 100/ночь
            // 11-17 не заданы -> дети этого возраста => тариф исключается (Reject)
        ];
    }
};

// Запрос: 2 взрослых + дети 3 и 8, 3 ночи.
$req = new GuestRequest(adults: 2, childrenAges: [3, 8], nights: 3);

$candidates = [
    new RateCandidate(500, 4500, 111, 'INR', adultTotal: 3000.0, nights: 3),
    new RateCandidate(700, 6500, 111, 'INR', adultTotal: 2800.0, nights: 3),
];

print_r(minPricePerHotel($candidates, $req, $cfg));
/*
Ребёнок 3 -> 0-5 free -> 0
Ребёнок 8 -> 6-10 fixed 100/ночь -> 100*3 = 300
Отель 500: 3000 + 300 = 3300
Отель 700: 2800 + 300 = 3100
=> [500 => 3300, 700 => 3100]

Если бы был ребёнок 12 (нет ставки, Reject): оба тарифа исключены,
отели без других тарифов исчезают из выдачи.
*/
