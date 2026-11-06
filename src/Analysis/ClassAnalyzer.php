<?php

declare(strict_types=1);

namespace Js199069\Mutagent\Analysis;

use PhpParser\Node;
use PhpParser\Node\IntersectionType;
use PhpParser\Node\NullableType;
use PhpParser\Node\Stmt\Class_;
use PhpParser\Node\Stmt\ClassMethod;
use PhpParser\Node\Stmt\Namespace_;
use PhpParser\Node\UnionType;
use PhpParser\NodeFinder;
use PhpParser\ParserFactory;
use PhpParser\PrettyPrinter\Standard;
use RuntimeException;

/** ساختار یک کلاس PHP برای پرامپت: نام کامل، متدهای public و امضای آن‌ها. */
final class ClassAnalyzer
{
    public function analyze(string $filePath): ClassInfo
    {
        if (! is_file($filePath)) {
            throw new RuntimeException("Class file not found: {$filePath}");
        }

        $source = (string) file_get_contents($filePath);
        $ast = (new ParserFactory())->createForHostVersion()->parse($source) ?? [];
        $finder = new NodeFinder();

        $class = $finder->findFirstInstanceOf($ast, Class_::class);
        if (! $class instanceof Class_ || $class->name === null) {
            throw new RuntimeException("No named class found in {$filePath}");
        }

        $namespace = $finder->findFirstInstanceOf($ast, Namespace_::class);
        $prefix = $namespace instanceof Namespace_ && $namespace->name !== null ? $namespace->name->toString() . '\\' : '';
        $shortName = $class->name->toString();

        $methods = [];
        foreach ($class->getMethods() as $method) {
            if ($method->isPublic()) {
                $methods[] = new MethodInfo($method->name->toString(), $this->signature($method));
            }
        }

        return new ClassInfo($prefix . $shortName, $shortName, $filePath, $source, $methods);
    }

    private function signature(ClassMethod $method): string
    {
        $printer = new Standard();
        $params = [];

        foreach ($method->params as $param) {
            $type = $param->type !== null ? $this->type($param->type) . ' ' : '';
            $name = $param->var instanceof Node\Expr\Variable && is_string($param->var->name) ? $param->var->name : 'arg';
            $default = $param->default !== null ? ' = ' . $printer->prettyPrintExpr($param->default) : '';
            $params[] = $type . ($param->variadic ? '...' : '') . '$' . $name . $default;
        }

        $return = $method->returnType !== null ? ': ' . $this->type($method->returnType) : '';
        $static = $method->isStatic() ? 'static ' : '';

        return $static . $method->name->toString() . '(' . implode(', ', $params) . ')' . $return;
    }

    private function type(Node $type): string
    {
        return match (true) {
            $type instanceof NullableType => '?' . $this->type($type->type),
            $type instanceof UnionType => implode('|', array_map($this->type(...), $type->types)),
            $type instanceof IntersectionType => implode('&', array_map($this->type(...), $type->types)),
            $type instanceof Node\Identifier, $type instanceof Node\Name => $type->toString(),
            default => 'mixed',
        };
    }
}
