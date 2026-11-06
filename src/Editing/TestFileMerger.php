<?php

declare(strict_types=1);

namespace Js199069\Mutagent\Editing;

use Js199069\Mutagent\Generation\CodeExtractor;
use PhpParser\Node\Stmt\ClassMethod;
use PhpParser\NodeFinder;
use PhpParser\ParserFactory;
use RuntimeException;

/**
 * جواب مدل را روی فایل تست فعلی اعمال می‌کند. اگر کل کلاس آمده، جایگزین می‌شود؛
 * اگر فقط متدها آمده‌اند (صرفه‌جویی در توکن خروجی)، ادغام می‌شوند: هم‌نام‌ها جایگزین، تازه‌ها به انتهای کلاس.
 */
final class TestFileMerger
{
    public function __construct(
        private CodeExtractor $extractor = new CodeExtractor(),
    ) {}

    public function apply(string $current, string $response): string
    {
        $block = $this->extractor->block($response);
        if ($block === null) {
            throw new RuntimeException('LLM response has no PHP code block');
        }

        if (preg_match('/^\s*(final\s+|abstract\s+|readonly\s+)*class\s+\w+/m', $block)) {
            return $this->extractor->extract($response);
        }

        return $this->merge($current, $block);
    }

    public function merge(string $current, string $snippet): string
    {
        $snippet = trim((string) preg_replace('/^<\?php\s*/', '', trim($snippet)));

        preg_match_all('/^[ \t]*use\s+[^;(]+;[ \t]*$/m', $snippet, $m);
        $uses = array_map('trim', $m[0]);
        $methods = trim((string) preg_replace('/^[ \t]*use\s+[^;(]+;[ \t]*\R?/m', '', $snippet));
        if ($methods === '') {
            throw new RuntimeException('LLM snippet contains no methods');
        }

        $code = $this->removeMethods($current, $this->methodNames($methods));

        foreach ($uses as $use) {
            if (str_contains($code, $use)) {
                continue;
            }
            $code = $this->addUse($code, $use);
        }

        $close = strrpos($code, '}');
        if ($close === false) {
            throw new RuntimeException('Current test file has no class body');
        }
        $code = rtrim(substr($code, 0, $close)) . "\n\n" . $this->indent($methods) . "\n}\n";

        // فایل خراب نباید نوشته شود؛ PhpParser\Error خودش RuntimeException است
        (new ParserFactory())->createForHostVersion()->parse($code);

        return $code;
    }

    /** @return list<string> */
    private function methodNames(string $methods): array
    {
        $ast = (new ParserFactory())->createForHostVersion()->parse("<?php\nclass MutagentSnippet\n{\n{$methods}\n}\n") ?? [];

        return array_map(
            fn (ClassMethod $m) => $m->name->toString(),
            (new NodeFinder())->findInstanceOf($ast, ClassMethod::class),
        );
    }

    /** @param list<string> $names */
    private function removeMethods(string $code, array $names): string
    {
        if ($names === []) {
            return $code;
        }

        $ast = (new ParserFactory())->createForHostVersion()->parse($code) ?? [];
        $ranges = [];
        foreach ((new NodeFinder())->findInstanceOf($ast, ClassMethod::class) as $method) {
            if (! in_array($method->name->toString(), $names, true)) {
                continue;
            }
            $start = $method->getStartLine();
            foreach ($method->attrGroups as $group) {
                $start = min($start, $group->getStartLine());
            }
            if ($doc = $method->getDocComment()) {
                $start = min($start, $doc->getStartLine());
            }
            $ranges[] = [$start, $method->getEndLine()];
        }

        usort($ranges, fn ($a, $b) => $b[0] <=> $a[0]);
        $lines = preg_split('/\R/', $code);
        foreach ($ranges as [$start, $end]) {
            array_splice($lines, $start - 1, $end - $start + 1);
        }

        return (string) preg_replace("/\n{3,}/", "\n\n", implode("\n", $lines));
    }

    private function addUse(string $code, string $use): string
    {
        if (preg_match_all('/^use\s+[^;]+;[ \t]*$/m', $code, $um, PREG_OFFSET_CAPTURE) && $um[0] !== []) {
            $last = end($um[0]);
            $pos = $last[1] + strlen($last[0]);

            return substr($code, 0, $pos) . "\n" . $use . substr($code, $pos);
        }

        if (preg_match('/^namespace\s+[^;]+;[ \t]*$/m', $code, $nm, PREG_OFFSET_CAPTURE)) {
            $pos = $nm[0][1] + strlen($nm[0][0]);

            return substr($code, 0, $pos) . "\n\n" . $use . substr($code, $pos);
        }

        return (string) preg_replace('/^<\?php\s*/', "<?php\n\n{$use}\n\n", $code, 1);
    }

    private function indent(string $methods): string
    {
        $lines = preg_split('/\R/', $methods);
        $first = current(array_filter($lines, fn ($l) => trim($l) !== '')) ?: '';
        if (str_starts_with($first, '    ') || str_starts_with($first, "\t")) {
            return implode("\n", $lines);
        }

        return implode("\n", array_map(fn ($l) => trim($l) === '' ? '' : '    ' . $l, $lines));
    }
}
