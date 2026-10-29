from decimal import Decimal

import pytest

from exely_adapter.values import allocate_amount, format_money, parse_bool, parse_decimal, parse_int, split_amount


@pytest.mark.parametrize(
    ("raw", "expected"),
    [
        ("15,500.00", "15500.00"),
        ("20000.00", "20000.00"),
        ("5.000.00", "5000.00"),  # sic, from the Exely specification
        ("1,000", "1000"),
        ("1,000,000.50", "1000000.50"),
        ("15,50", "15.50"),
        ("1.000,50", "1000.50"),
        (" 1 200.00 ", "1200.00"),
        (120, "120"),
        (99.9, "99.9"),
    ],
)
def test_parse_decimal(raw, expected):
    assert parse_decimal(raw) == Decimal(expected)


@pytest.mark.parametrize("raw", ["", "abc", True, None, "NaN", "Infinity"])
def test_parse_decimal_rejects(raw):
    with pytest.raises(ValueError):
        parse_decimal(raw)


def test_parse_int():
    assert parse_int("6") == 6
    assert parse_int(-1) == -1
    with pytest.raises(ValueError):
        parse_int("2.5")


@pytest.mark.parametrize(("raw", "expected"), [("false", False), ("TRUE", True), (True, True), (0, False), ("1", True)])
def test_parse_bool(raw, expected):
    assert parse_bool(raw) is expected


def test_parse_bool_rejects():
    with pytest.raises(ValueError):
        parse_bool("maybe")


def test_split_amount_keeps_the_total():
    parts = split_amount(Decimal("100"), 3)
    assert [format_money(p) for p in parts] == ["33.33", "33.33", "33.34"]
    assert sum(parts) == Decimal("100")


def test_allocate_amount_is_proportional_and_exact():
    parts = allocate_amount(Decimal("101.25"), [Decimal("400"), Decimal("275")])
    assert sum(parts) == Decimal("101.25")
    assert parts[0] == Decimal("60.00")
