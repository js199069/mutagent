<?php

declare(strict_types=1);

namespace Js199069\Mutagent\Generation;

use RuntimeException;

/** بیرون کشیدن فایل PHP از جواب مدل (معمولاً داخل یک بلوک ```php). */
final class CodeExtractor
{
    public function extract(string $response): string
    {
        if (preg_match_all('/```(?:php)?[ \t]*\R(.*?)```/s', $response, $matches) && $matches[1] !== []) {
            // طولانی‌ترین بلوک: مدل گاهی قبل از فایل اصلی، تکه‌ی کوچکی هم می‌آورد
            usort($matches[1], fn ($a, $b) => strlen($b) <=> strlen($a));
            $code = trim($matches[1][0]);
        } else {
            $code = trim($response);
        }

        if (! str_contains($code, 'class ')) {
            throw new RuntimeException('LLM response does not contain a PHP class');
        }

        return str_starts_with($code, '<?php') ? $code . "\n" : "<?php\n\n" . $code . "\n";
    }
}
