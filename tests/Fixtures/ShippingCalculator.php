<?php

namespace App;

final class ShippingCalculator
{
    public function cost(float $weightKg, string $zone, bool $express = false): int
    {
        if ($weightKg <= 0) {
            throw new \InvalidArgumentException('Weight must be positive');
        }

        $base = match ($zone) {
            'tehran' => 30000,
            'province' => 45000,
            default => throw new \InvalidArgumentException("Unknown zone: {$zone}"),
        };

        $extraKg = max(0, (int) ceil($weightKg) - 1);
        $cost = $base + $extraKg * 10000;

        if ($express) {
            $cost = (int) round($cost * 1.5);
        }

        return $cost >= 500000 ? 500000 : $cost;
    }

    private function hidden(): void {}
}
