<?php

declare(strict_types=1);

namespace Js199069\Mutagent\Generation;

use RuntimeException;

/** بیرون کشیدن فایل PHP از جواب مدل. بلوک‌های ```php ترجیح دارند (جواب ممکن است بلوک json هم داشته باشد). */
final class CodeExtractor
{
    public function extract(string $response): string
    {
        $code = $this->longestBlock($response, '/```php[ \t]*\R(.*?)```/s')
            ?? $this->longestBlock($response, '/```[a-zA-Z0-9]*[ \t]*\R(.*?)```/s')
            ?? trim($response);

        if (! str_contains($code, 'class ')) {
            throw new RuntimeException('LLM response does not contain a PHP class');
        }

        return str_starts_with($code, '<?php') ? $code . "\n" : "<?php\n\n" . $code . "\n";
    }

    private function longestBlock(string $text, string $pattern): ?string
    {
        if (! preg_match_all($pattern, $text, $matches) || $matches[1] === []) {
            return null;
        }
        // طولانی‌ترین بلوک: مدل گاهی قبل از فایل اصلی، تکه‌ی کوچکی هم می‌آورد
        usort($matches[1], fn ($a, $b) => strlen($b) <=> strlen($a));

        return trim($matches[1][0]);
    }
}
