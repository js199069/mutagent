<?php

declare(strict_types=1);

namespace Js199069\Mutagent\Tests;

use Js199069\Mutagent\Analysis\ClassAnalyzer;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class ClassAnalyzerTest extends TestCase
{
    public function test_reads_class_name_and_public_method_signatures(): void
    {
        $class = (new ClassAnalyzer())->analyze(__DIR__ . '/Fixtures/ShippingCalculator.php');

        $this->assertSame('App\ShippingCalculator', $class->fqcn);
        $this->assertSame('ShippingCalculator', $class->shortName);
        $this->assertCount(1, $class->publicMethods, 'private methods are not included');
        $this->assertSame(
            'cost(float $weightKg, string $zone, bool $express = false): int',
            $class->publicMethods[0]->signature,
        );
    }

    public function test_missing_file_fails_clearly(): void
    {
        $this->expectException(RuntimeException::class);

        (new ClassAnalyzer())->analyze('nope.php');
    }
}
