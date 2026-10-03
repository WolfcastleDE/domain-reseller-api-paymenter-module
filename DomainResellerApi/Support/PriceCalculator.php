<?php

namespace Paymenter\Extensions\Servers\DomainResellerApi\Support;

use InvalidArgumentException;

/**
 * Turns the net EUR purchase prices of the API into Paymenter plan prices.
 *
 * Paymenter charges "price + setup fee" for the first period and "price" for
 * every renewal. The recurring price is derived from the renew price, the
 * setup fee covers the difference to the (possibly higher) first-period cost.
 */
final class PriceCalculator
{
    /**
     * @param  float  $marginPercent  Mark-up in percent applied to the purchase price
     * @param  float  $fixedMarkup  Fixed amount added per billing period (in the target currency)
     * @param  float  $rate  Conversion rate from EUR into the target currency
     * @param  float|null  $ending  Round prices up so they end with this fraction (e.g. 0.99), null = no rounding
     */
    public function __construct(
        private readonly float $marginPercent = 0.0,
        private readonly float $fixedMarkup = 0.0,
        private readonly float $rate = 1.0,
        private readonly ?float $ending = null,
    ) {
        if ($rate <= 0) {
            throw new InvalidArgumentException('The conversion rate must be greater than 0.');
        }
        if ($ending !== null && ($ending < 0 || $ending >= 1)) {
            throw new InvalidArgumentException('The price ending must be between 0 and 0.99.');
        }
    }

    /**
     * @param  array  $pricing  One entry of GET /api/v1/pricing
     * @param  string  $mode  "register" or "transfer"
     * @return array{years: int, price: float, setup_fee: float, first_period: float}
     */
    public function plan(array $pricing, string $mode = 'register'): array
    {
        $years = $mode === 'transfer' ? 1 : max(1, (int) ($pricing['minYears'] ?? 1));
        $setup = (float) ($pricing['setupPrice'] ?? 0);
        $renew = (float) ($pricing['renewPrice'] ?? 0);

        $firstNet = $mode === 'transfer'
            ? (float) ($pricing['transferPrice'] ?? 0) + $setup
            : (float) ($pricing['registerPrice'] ?? 0) * $years + $setup;
        $recurringNet = $renew * $years;

        $recurring = $this->sell($recurringNet);
        $first = max($recurring, $this->sell($firstNet));

        return [
            'years' => $years,
            'price' => $recurring,
            'setup_fee' => round($first - $recurring, 2),
            'first_period' => $first,
        ];
    }

    public function sell(float $net): float
    {
        $price = $net * $this->rate * (1 + $this->marginPercent / 100) + $this->fixedMarkup;
        $price = round($price, 2);

        if ($this->ending !== null && $price > 0) {
            $candidate = floor($price) + $this->ending;
            if ($candidate + 0.0001 < $price) {
                $candidate += 1;
            }
            $price = round($candidate, 2);
        }

        return $price;
    }
}
