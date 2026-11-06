<?php

declare(strict_types=1);

namespace Js199069\Mutagent\Generation;

use RuntimeException;

/** بیرون کشیدن کد PHP از جواب مدل. بلوک‌های ```php ترجیح دارند (جواب ممکن است بلوک json هم داشته باشد). */
final class CodeExtractor
{
    /** فایل کامل یک کلاس */
    public function extract(string $response): string
    {
        $code = $this->block($response) ?? trim($response);

        if (! str_contains($code, 'class ')) {
            throw new RuntimeException('LLM response does not contain a PHP class');
        }

        return str_starts_with($code, '<?php') ? $code . "\n" : "<?php\n\n" . $code . "\n";
    }

    /** محتوای طولانی‌ترین بلوک کد (php ترجیح دارد؛ json هرگز)، یا null */
    public function block(string $response): ?string
    {
        return $this->longestBlock($response, '/```php[ \t]*\R(.*?)```/s')
            ?? $this->longestBlock($response, '/```(?!json)[a-zA-Z0-9]*[ \t]*\R(.*?)```/s');
    }

    private function longestBlock(string $text, string $pattern): ?string
    {
        if (! preg_match_all($pattern, $text, $matches) || $matches[1] === []) {
            return null;
        }
        usort($matches[1], fn ($a, $b) => strlen($b) <=> strlen($a));

        return trim($matches[1][0]);
    }
}
