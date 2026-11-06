<?php

declare(strict_types=1);

namespace Js199069\Mutagent\Generation;

/**
 * علت خطاهای شناخته‌شده را صریح به مدل می‌گوید. خروجی خام PHPUnit گاهی علت را پنهان می‌کند،
 * و مدل همان اشتباه را تکرار می‌کند.
 */
final class RepairHints
{
    /** @return list<string> */
    public function for(string $phpunitOutput): array
    {
        $hints = [];

        if (preg_match('/Too few arguments to function [^\s]*Test::/', $phpunitOutput)) {
            $hints[] = 'A test method with parameters received no data. Docblock annotations such as @dataProvider are ignored by PHPUnit 10+. '
                . "Use the attribute #[\\PHPUnit\\Framework\\Attributes\\DataProvider('providerName')] on the test method, "
                . 'and make the provider a public static method returning an array or iterable.';
        }

        if (str_contains($phpunitOutput, 'No tests found') || preg_match('/No tests executed/', $phpunitOutput)) {
            $hints[] = 'No tests were found. Test method names must start with "test", or use the #[\\PHPUnit\\Framework\\Attributes\\Test] attribute. '
                . 'The @test annotation is ignored by PHPUnit 10+.';
        }

        if (preg_match('/Class "([^"]+)" not found/', $phpunitOutput, $m)) {
            $hints[] = "Class \"{$m[1]}\" was not found. Check the use statements and the namespace of the class under test.";
        }

        // assertSame نوع را هم مقایسه می‌کند: '-1000' (رشته) با -1000 (عدد) یکی نیست
        if (preg_match("/Failed asserting that '(-?[\d.]+)' is identical to (-?[\d.]+)\./", $phpunitOutput, $m)) {
            $hints[] = "The actual value is the string '{$m[1]}', but the test expects the number {$m[2]}. "
                . "assertSame compares types: the code returns a string here, so expect the string literal '{$m[1]}'.";
        } elseif (preg_match("/Failed asserting that (-?[\d.]+) is identical to '(-?[\d.]+)'\./", $phpunitOutput, $m)) {
            $hints[] = "The actual value is the number {$m[1]}, but the test expects the string '{$m[2]}'. "
                . "assertSame compares types: the code returns a number here, so expect {$m[1]} without quotes.";
        }

        if (preg_match('/Failed asserting that exception of type "([^"]+)" is thrown/', $phpunitOutput, $m)) {
            $hints[] = "The code does not throw {$m[1]} for that input. Trace the source: the input is probably valid "
                . '(assert the returned value instead), or a different input is needed to trigger the exception.';
        }

        // راهنمای عمومی فقط وقتی هیچ راهنمای دقیق‌تری پیدا نشده؛ وگرنه مدل را گمراه می‌کند
        if ($hints === [] && (str_contains($phpunitOutput, 'is identical to') || str_contains($phpunitOutput, 'Failed asserting that'))) {
            $hints[] = 'An expected value is wrong. Recompute it by tracing the source code step by step, including rounding and integer casts. '
                . 'Do not change the expectation to match a guess.';
        }

        return $hints;
    }
}
