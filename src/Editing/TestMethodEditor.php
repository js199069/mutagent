<?php

declare(strict_types=1);

namespace Js199069\Mutagent\Editing;

use PhpParser\Node\Stmt\Class_;
use PhpParser\Node\Stmt\ClassMethod;
use PhpParser\NodeFinder;
use PhpParser\ParserFactory;

/** پیدا کردن و حذف متدهای تست، بر اساس شماره‌ی خط تا قالب‌بندی بقیه‌ی فایل دست نخورد. */
final class TestMethodEditor
{
    /** @return array<string, array{int, int}> نام متد تست ← [خط شروع، خط پایان]، با attributeها و توضیح بالایش */
    public function testMethods(string $code): array
    {
        $ast = (new ParserFactory())->createForHostVersion()->parse($code) ?? [];
        $class = (new NodeFinder())->findFirstInstanceOf($ast, Class_::class);
        if (! $class instanceof Class_) {
            return [];
        }

        $methods = [];
        foreach ($class->getMethods() as $method) {
            $name = $method->name->toString();
            if (! $method->isPublic() || ! (str_starts_with($name, 'test') || $this->hasTestAttribute($method))) {
                continue;
            }

            $start = $method->getStartLine();
            foreach ($method->attrGroups as $group) {
                $start = min($start, $group->getStartLine());
            }
            if ($doc = $method->getDocComment()) {
                $start = min($start, $doc->getStartLine());
            }

            $methods[$name] = [$start, $method->getEndLine()];
        }

        return $methods;
    }

    /** @param list<string> $names */
    public function remove(string $code, array $names): string
    {
        $ranges = array_intersect_key($this->testMethods($code), array_flip($names));
        $lines = preg_split('/\R/', $code);
        uasort($ranges, fn ($a, $b) => $b[0] <=> $a[0]);

        foreach ($ranges as [$start, $end]) {
            array_splice($lines, $start - 1, $end - $start + 1);
        }

        return (string) preg_replace("/\n{3,}/", "\n\n", implode("\n", $lines));
    }

    /** «1) Tests\Generated\FooTest::testBar with data set #2» ← testBar */
    public static function failingTests(string $phpunitOutput): array
    {
        preg_match_all('/^\d+\) \S+::(\w+)/m', str_replace("\r\n", "\n", $phpunitOutput), $m);

        return array_values(array_unique($m[1]));
    }

    private function hasTestAttribute(ClassMethod $method): bool
    {
        foreach ($method->attrGroups as $group) {
            foreach ($group->attrs as $attr) {
                if ($attr->name->getLast() === 'Test') {
                    return true;
                }
            }
        }

        return false;
    }
}
