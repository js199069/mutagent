<?php

declare(strict_types=1);

namespace Js199069\Mutagent\Editing;

use Js199069\Mutagent\Runner\TestRunner;

/**
 * اگر فقط چند تست با کد مخالف‌اند، همان‌ها حذف می‌شوند و بقیه می‌مانند (کد مرجع است).
 * راستی‌آزمایی: بعد از حذف، هیچ‌کدام از نام‌ها نباید در فایل مانده باشد، و فایل باید سبز باشد؛ وگرنه فایل دست نمی‌خورد.
 */
final class Salvager
{
    public function __construct(
        private TestRunner $runner,
        private TestMethodEditor $editor = new TestMethodEditor(),
        private float $maxShare = 0.3,
    ) {}

    /** @return list<string> نام تست‌های حذف‌شده؛ خالی یعنی نجات ممکن نبود و فایل دست نخورد */
    public function salvage(string $testFile, string $failureOutput): array
    {
        $code = TestMethodEditor::lf((string) file_get_contents($testFile));
        $methods = $this->editor->testMethods($code);
        $failing = array_values(array_intersect(TestMethodEditor::failingTests($failureOutput), array_keys($methods)));

        $limit = max(1, (int) floor($this->maxShare * count($methods)));
        if ($failing === [] || count($failing) > $limit || count($failing) >= count($methods)) {
            return [];
        }

        $new = $this->editor->remove($code, $failing);
        if (array_intersect($failing, array_keys($this->editor->testMethods($new))) !== []) {
            return [];
        }

        file_put_contents($testFile, $new);
        if (! $this->runner->run($testFile)->passed) {
            file_put_contents($testFile, $code);

            return [];
        }

        return $failing;
    }
}
