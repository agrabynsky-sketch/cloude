<?php
/**
 * Exely `update` → Unit.Travel ARI message parts.
 *
 * Exely omits a field it has already delivered, and Unit.Travel leaves a field it does
 * not receive unchanged, so only the fields present are passed on.
 */
class Exely_Ari
{
    /**
     * @param  Exely_Update[] $updates
     * @param  object         $currencies anything with currencyFor($ratePlanId), used when Exely omits currencyCode
     * @return Exely_AriParts
     * @throws Exely_Failure
     */
    public static function build(array $updates, $currencies)
    {
        $parts = new Exely_AriParts();
        foreach (array_values($updates) as $index => $update) {
            self::_translate($update, 'updates[' . $index . ']', $parts, $currencies);
        }
        return $parts;
    }

    protected static function _translate(Exely_Update $update, $where, Exely_AriParts $parts, $currencies)
    {
        $roomId = self::_id($update->roomTypeId, Exely_Code::INVALID_ROOM_TYPE, "$where: invalid roomTypeId");
        $ratePlanId = $update->ratePlanId === null
            ? null
            : self::_id($update->ratePlanId, Exely_Code::INVALID_RATE_PLAN, "$where: invalid ratePlanId");
        $period = array('date_from' => $update->startDate, 'date_to' => $update->endDate);
        $restriction = self::_restrictionFields($update, $where, $parts);

        if ($update->quota !== null) {
            if ($update->quota < 0) {
                throw Exely_Failure::of(Exely_Code::INVALID_REQUEST, "$where: quota must not be negative");
            }
            $availability = array_merge(array('room_id' => $roomId), $period, array('units' => $update->quota));
            if ($ratePlanId === null && array_key_exists('stop_sell', $restriction)) {
                $availability['closed'] = $restriction['stop_sell'];
                unset($restriction['stop_sell']);
            }
            $parts->availability[] = $availability;
        }

        if ($update->prices) {
            if ($ratePlanId === null) {
                throw Exely_Failure::of(Exely_Code::INVALID_RATE_PLAN, "$where: ratePlanId is required for prices");
            }
            $parts->rates[] = self::_rateItem($update, $roomId, $ratePlanId, $period, $where, $currencies);
        }

        if ($restriction) {
            if ($ratePlanId === null) {
                if (array_keys($restriction) === array('stop_sell')) {
                    $parts->warnings[] = new Exely_Issue(
                        Exely_Code::UNSUPPORTED_VALUE,
                        "$where: closing room type $roomId needs a quota or a ratePlanId; ignored"
                    );
                    return;
                }
                throw Exely_Failure::of(Exely_Code::INVALID_RATE_PLAN, "$where: ratePlanId is required for restrictions");
            }
            // A Unit.Travel rate plan belongs to exactly one room, so room + rate maps to the rate plan alone.
            $parts->restrictions[] = array_merge(array('rate_plan_id' => $ratePlanId), $period, $restriction);
        }
    }

    protected static function _restrictionFields(Exely_Update $update, $where, Exely_AriParts $parts)
    {
        $fields = array();
        if ($update->closed !== null) {
            $fields['stop_sell'] = $update->closed;
        }
        if ($update->closedToArrival !== null) {
            $fields['closed_to_arrival'] = $update->closedToArrival;
        }
        if ($update->closedToDeparture !== null) {
            $fields['closed_to_departure'] = $update->closedToDeparture;
        }
        if ($update->minLos !== null) {
            // Both sides check MinLOS against every night of the stay. Exely removes the
            // restriction with 0, while Unit.Travel's min_stay starts at 1.
            $fields['min_stay'] = max($update->minLos, 1);
        }
        if ($update->maxLos !== null) {
            $fields['max_stay'] = max($update->maxLos, 0); // 0 means "no limit" on both sides
        }
        if ($update->minimumAdvanceBooking !== null) {
            $fields['min_advance_days'] = max($update->minimumAdvanceBooking, 0);
        }
        if ($update->maximumAdvanceBooking !== null) {
            if ($update->maximumAdvanceBooking < 0) {
                $fields['max_advance_days'] = 0; // Exely removes the limit with -1, Unit.Travel with 0
            } elseif ($update->maximumAdvanceBooking === 0) {
                $parts->warnings[] = new Exely_Issue(
                    Exely_Code::UNSUPPORTED_VALUE,
                    "$where: maximumAdvanceBooking=0 (same-day bookings only) is not supported; ignored"
                );
            } else {
                $fields['max_advance_days'] = $update->maximumAdvanceBooking;
            }
        }
        $arrivalBased = array(
            'minLosArrivalBased' => $update->minLosArrivalBased,
            'maxLosArrivalBased' => $update->maxLosArrivalBased,
        );
        foreach ($arrivalBased as $name => $value) {
            if ($value) { // 0 only removes a restriction Unit.Travel never had
                $parts->warnings[] = new Exely_Issue(
                    Exely_Code::UNSUPPORTED_VALUE, "$where: $name is not supported by Unit.Travel; ignored"
                );
            }
        }
        return $fields;
    }

    protected static function _rateItem(Exely_Update $update, $roomId, $ratePlanId, array $period, $where, $currencies)
    {
        $roomPrice = null;
        $occupancyPrices = array();
        foreach ($update->prices as $entry) {
            if ($entry['cents'] < 0) {
                throw Exely_Failure::of(Exely_Code::INVALID_REQUEST, "$where: negative price for {$entry['code']}");
            }
            try {
                $adults = Exely_Catalog::parseOccupancyCode($entry['code'], $roomId);
            } catch (InvalidArgumentException $e) {
                throw Exely_Failure::of(Exely_Code::INVALID_ACCOMMODATION, "$where: " . $e->getMessage());
            }
            if ($adults === null) {
                $roomPrice = $entry['cents'];
            } else {
                $occupancyPrices[$adults] = $entry['cents'];
            }
        }
        if ($roomPrice !== null && $occupancyPrices) {
            throw Exely_Failure::of(
                Exely_Code::INVALID_ACCOMMODATION, "$where: per-room and per-guest prices mixed for room type $roomId"
            );
        }

        $currency = $update->currencyCode !== null
            ? strtoupper($update->currencyCode)
            : $currencies->currencyFor($ratePlanId);
        $item = array_merge(
            array('room_id' => $roomId, 'rate_plan_id' => $ratePlanId), $period, array('currency' => $currency)
        );
        if ($roomPrice !== null) {
            $item['pricing_model'] = 'per_room';
            $item['price'] = Exely_Values::centsToNumber($roomPrice);
        } else {
            ksort($occupancyPrices);
            $item['pricing_model'] = 'per_guest';
            $item['occupancy_prices'] = array();
            foreach ($occupancyPrices as $adults => $cents) {
                $item['occupancy_prices'][] = array('occupancy' => $adults, 'price' => Exely_Values::centsToNumber($cents));
            }
        }
        return $item;
    }

    protected static function _id($value, $code, $message)
    {
        $value = trim($value);
        if (!ctype_digit($value) || (int) $value <= 0) {
            throw Exely_Failure::of($code, "$message: '$value'");
        }
        return (int) $value;
    }
}
