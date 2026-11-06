<?php

declare(strict_types=1);

namespace Js199069\Mutagent\Tests;

use Js199069\Mutagent\Infection\InfectionRunner;
use PHPUnit\Framework\TestCase;

final class InfectionRunnerTest extends TestCase
{
    public function test_isolated_config_points_to_one_test_and_one_class(): void
    {
        $project = sys_get_temp_dir() . '/mutagent-project-' . bin2hex(random_bytes(4));
        $runner = new InfectionRunner($project, $project . '/src/ShippingCalculator.php');

        $dir = $runner->writeConfig($project . '/tests/Generated/ShippingCalculatorGeneratedTest.php');

        $phpunit = (string) file_get_contents($dir . '/phpunit.xml');
        $this->assertStringContainsString('<file>../tests/Generated/ShippingCalculatorGeneratedTest.php</file>', $phpunit);
        $this->assertStringContainsString('<file>../src/ShippingCalculator.php</file>', $phpunit);

        $config = json_decode((string) file_get_contents($dir . '/infection.json5'), true);
        $this->assertSame(['../src'], $config['source']['directories']);
        $this->assertSame('.', $config['phpUnit']['configDir']);

        array_map('unlink', glob($dir . '/*') ?: []);
        @rmdir($dir);
        @rmdir($project);
    }
}
