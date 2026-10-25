<?php

use App\Support\Money;

it('converts amounts to whole cents', function (string|int|float $amount, int $cents) {
    expect(Money::toCents($amount))->toBe($cents);
})->with([
    ['12.50', 1250],
    [12.5, 1250],
    [12, 1200],
    ['0.10', 10],
    [0.1, 10],
    ['0.29', 29],
    [0.29, 29],
    ['-250.75', -25075],
    ['9999999999.99', 999999999999],
]);

it('formats cents as a two-decimal string', function (int $cents, string $amount) {
    expect(Money::fromCents($cents))->toBe($amount);
})->with([
    [0, '0.00'],
    [5, '0.05'],
    [1250, '12.50'],
    [-5, '-0.05'],
    [-25075, '-250.75'],
    [999999999999, '9999999999.99'],
]);

it('adds amounts without floating point drift', function () {
    $total = array_sum(array_map(Money::toCents(...), ['0.10', '0.10', '0.10']));

    expect(Money::fromCents($total))->toBe('0.30');
});
