"""Lenient parsing of the loosely typed values Exely sends, and money helpers.

Exely serialises numbers and booleans inconsistently: quotas come as "6",
flags as "false", and prices with thousands separators ("15,500.00"; the
specification's own samples even contain "5.000.00").
"""

from decimal import ROUND_HALF_UP, Decimal, InvalidOperation

CENT = Decimal("0.01")

_TRUE = {"true", "1", "yes"}
_FALSE = {"false", "0", "no"}


def parse_decimal(value: object) -> Decimal:
    if isinstance(value, bool):
        raise ValueError("expected a number, got a boolean")
    if isinstance(value, Decimal):
        result = value
    elif isinstance(value, int):
        result = Decimal(value)
    elif isinstance(value, float):
        result = Decimal(repr(value))
    elif isinstance(value, str):
        try:
            result = Decimal(_normalize_number(value))
        except InvalidOperation:
            raise ValueError(f"not a number: {value!r}") from None
    else:
        raise ValueError(f"expected a number, got {type(value).__name__}")
    if not result.is_finite():
        raise ValueError(f"not a finite number: {value!r}")
    return result


def _normalize_number(text: str) -> str:
    """Turn "15,500.00", "5.000.00", "1.000,50" or "15,50" into "15500.00" style."""
    s = text.strip().replace(" ", "").replace(" ", "")
    if not s:
        raise ValueError("empty number")
    last_comma, last_dot = s.rfind(","), s.rfind(".")
    if last_comma >= 0 and last_dot >= 0:
        decimal_mark = "," if last_comma > last_dot else "."
    elif last_comma >= 0:
        # "1,000" and "1,000,000" group thousands; "15,50" uses a decimal comma.
        grouped = s.count(",") > 1 or len(s) - last_comma - 1 == 3
        decimal_mark = None if grouped else ","
    elif last_dot >= 0:
        # With several dots ("5.000.00") the last one is the decimal point.
        decimal_mark = "."
    else:
        decimal_mark = None
    if decimal_mark is None:
        return s.replace(",", "")
    head, _, tail = s.rpartition(decimal_mark)
    return head.replace(",", "").replace(".", "") + "." + tail


def parse_int(value: object) -> int:
    number = parse_decimal(value)
    if number != number.to_integral_value():
        raise ValueError(f"expected an integer, got {value!r}")
    return int(number)


def parse_bool(value: object) -> bool:
    if isinstance(value, bool):
        return value
    if isinstance(value, int) and value in (0, 1):
        return bool(value)
    if isinstance(value, str):
        text = value.strip().lower()
        if text in _TRUE:
            return True
        if text in _FALSE:
            return False
    raise ValueError(f"expected a boolean, got {value!r}")


def format_money(amount: Decimal) -> str:
    return str(amount.quantize(CENT, rounding=ROUND_HALF_UP))


def split_amount(total: Decimal, parts: int) -> list[Decimal]:
    """Split total into equal parts that add up exactly; the last part takes the remainder."""
    cents = int(total.quantize(CENT, rounding=ROUND_HALF_UP).scaleb(2))
    share, remainder = divmod(cents, parts)
    amounts = [share] * parts
    amounts[-1] += remainder
    return [Decimal(a).scaleb(-2) for a in amounts]


def allocate_amount(total: Decimal, weights: list[Decimal]) -> list[Decimal]:
    """Split total in proportion to weights; the last part takes the remainder."""
    weight_sum = sum(weights, Decimal(0))
    if weight_sum <= 0:
        return split_amount(total, len(weights))
    cents = int(total.quantize(CENT, rounding=ROUND_HALF_UP).scaleb(2))
    shares = [int(cents * weight / weight_sum) for weight in weights[:-1]]
    shares.append(cents - sum(shares))
    return [Decimal(s).scaleb(-2) for s in shares]
