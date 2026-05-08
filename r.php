<?php

require 'vendor/autoload.php';
include 'libspech/plugins/autoloader.php';
use PhpParser\Node;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitorAbstract;
use PhpParser\ParserFactory;
use Symfony\Component\Finder\Finder;

// ---- Paths ----

$stp = file_exists('stubs-paths.json')
    ? json_decode(file_get_contents('stubs-paths.json'), true)
    : ['files'];

$stp = array_values(array_filter($stp, 'is_dir'));


// ---- AST visitor: extracts classes, methods, properties, functions ----

class SignatureExtractor extends NodeVisitorAbstract
{
    public array $classes   = [];
    public array $functions = [];

    private array $namespaceStack = [];
    private array $classStack     = [];

    private function currentNamespace(): string
    {
        return implode('\\', $this->namespaceStack);
    }

    private function currentClass(): string
    {
        return end($this->classStack) ?: '';
    }

    private function resolveType(?Node $typeNode): string
    {
        if ($typeNode === null) {
            return 'mixed';
        }
        if ($typeNode instanceof Node\NullableType) {
            return '?' . $this->resolveType($typeNode->type);
        }
        if ($typeNode instanceof Node\UnionType) {
            return implode('|', array_map([$this, 'resolveType'], $typeNode->types));
        }
        if ($typeNode instanceof Node\IntersectionType) {
            return implode('&', array_map([$this, 'resolveType'], $typeNode->types));
        }
        if ($typeNode instanceof Node\Identifier) {
            return $typeNode->name;
        }
        if ($typeNode instanceof Node\Name) {
            return $typeNode->toString();
        }
        return 'mixed';
    }

    private function extractParams(array $params, bool $skipOptional = false): array
    {
        $result = [];
        foreach ($params as $p) {
            $hasDefault = $p->default !== null;
            if ($skipOptional && $hasDefault) {
                continue;
            }
            $result[] = [
                'isOptional'  => $hasDefault,
                'name'        => $p->var->name,
                'type'        => $this->resolveType($p->type),
                'default'     => null,
                'byReference' => $p->byRef,
            ];
        }
        return $result;
    }

    public function enterNode(Node $node)
    {
        if ($node instanceof Node\Stmt\Namespace_) {
            $this->namespaceStack[] = $node->name ? $node->name->toString() : '';
        }

        if ($node instanceof Node\Stmt\Class_ || $node instanceof Node\Stmt\Interface_ || $node instanceof Node\Stmt\Trait_) {
            $this->classStack[] = $node->name?->name ?? '';
        }

        if ($node instanceof Node\Stmt\ClassMethod) {
            $className  = $this->currentClass();
            $ns         = $this->currentNamespace();
            $returnType = $this->resolveType($node->returnType);
            $skipOpt    = $node->name->name !== '__construct';

            $this->classes[] = [
                'name'           => $node->name->name,
                'parameters'     => $this->extractParams($node->params, $skipOpt),
                'returnType'     => $returnType === 'void' ? 'mixed' : $returnType,
                'docComment'     => $node->getDocComment()?->getText(),
                'type'           => 'method',
                'class'          => ($ns ? $ns . '\\' : '') . $className,
                'static'         => $node->isStatic(),
                'classNamespace' => $ns,
            ];
        }

        if ($node instanceof Node\Stmt\Property) {
            if ($node->isPublic()) {
                $className = $this->currentClass();
                $ns        = $this->currentNamespace();
                $type      = $this->resolveType($node->type);
                foreach ($node->props as $prop) {
                    $this->classes[] = [
                        'name'           => $prop->name->name,
                        'parameters'     => [],
                        'returnType'     => $type,
                        'docComment'     => $node->getDocComment()?->getText(),
                        'type'           => 'property',
                        'class'          => ($ns ? $ns . '\\' : '') . $className,
                        'classNamespace' => $ns,
                    ];
                }
            }
        }

        if ($node instanceof Node\Stmt\Function_) {
            $ns         = $this->currentNamespace();
            $returnType = $this->resolveType($node->returnType);
            $this->functions[] = [
                'name'           => $node->name->name,
                'parameters'     => $this->extractParams($node->params, skipOptional: true),
                'returnType'     => $returnType === 'void' ? 'mixed' : $returnType,
                'docComment'     => $node->getDocComment()?->getText(),
                'type'           => 'function',
                'class'          => null,
                'classNamespace' => $ns ?: null,
            ];
        }
    }

    public function leaveNode(Node $node)
    {
        if ($node instanceof Node\Stmt\Namespace_) {
            array_pop($this->namespaceStack);
        }
        if ($node instanceof Node\Stmt\Class_ || $node instanceof Node\Stmt\Interface_ || $node instanceof Node\Stmt\Trait_) {
            array_pop($this->classStack);
        }
    }
}

// ---- Reflection helpers for built-in / extension classes ----

function getTypeName(?ReflectionType $type): string
{
    if ($type === null) return 'mixed';
    if ($type instanceof ReflectionNamedType) return $type->getName();
    if ($type instanceof ReflectionUnionType) {
        return implode('|', array_map(fn($t) => $t->getName(), $type->getTypes()));
    }
    if ($type instanceof ReflectionIntersectionType) {
        return implode('&', array_map(fn($t) => $t->getName(), $type->getTypes()));
    }
    return 'mixed';
}

function reflectParameters(ReflectionFunctionAbstract $rfm, bool $skipOptional = false): array
{
    $params = [];
    foreach ($rfm->getParameters() as $p) {
        if ($skipOptional && $p->isOptional()) continue;
        $params[] = [
            'isOptional'  => $p->isOptional(),
            'name'        => $p->getName(),
            'type'        => getTypeName($p->getType()),
            'default'     => $p->isDefaultValueAvailable() ? $p->getDefaultValue() : null,
            'byReference' => $p->isPassedByReference(),
        ];
    }
    return $params;
}

function reflectReturnType(ReflectionFunctionAbstract $rfm): string
{
    $name = getTypeName($rfm->getReturnType());
    return $name === 'void' ? 'mixed' : $name;
}

function extractNamedConstants(): array
{
    return array_map(fn($name) => ['name' => $name], array_keys(get_defined_constants()));
}

// ---- Parse project files ----

$parser     = (new ParserFactory)->createForNewestSupportedVersion();
$traverser  = new NodeTraverser;
$visitor    = new SignatureExtractor;
$traverser->addVisitor($visitor);

if (!empty($stp)) {
    $finder = new Finder;
    $finder->files()->name('*.php')->in($stp)->exclude('vendor');

    foreach ($finder as $file) {
        //\libspech\Cli\cli::pcl("Parsing {$file->getRealPath()}");
        try {
            $ast = $parser->parse($file->getContents());
            if ($ast !== null) {
                $traverser->traverse($ast);
            }
        } catch (\PhpParser\Error $e) {
            fwrite(STDERR, "Parse error in {$file->getRealPath()}: {$e->getMessage()}\n");
        }
    }
}

// ---- Reflect built-in / extension classes ----

$builtinClasses = [];
foreach (get_declared_classes() as $classe) {
    $ref = new ReflectionClass($classe);
    if (true) {
        foreach ($ref->getMethods() as $rfm) {
            $skipOpt = !str_contains($rfm->getName(), '__construct');
            $builtinClasses[] = [
                'name'           => $rfm->getName(),
                'parameters'     => reflectParameters($rfm, $skipOpt),
                'returnType'     => reflectReturnType($rfm),
                'docComment'     => $rfm->getDocComment(),
                'type'           => 'method',
                'class'          => $classe,
                'static'         => $rfm->isStatic(),
                'classNamespace' => $ref->getNamespaceName(),
            ];
        }
        foreach ($ref->getProperties() as $property) {
            if (!$property->isPublic()) continue;
            $builtinClasses[] = [
                'name'           => $property->getName(),
                'parameters'     => [],
                'returnType'     => getTypeName($property->getType()),
                'docComment'     => $property->getDocComment(),
                'type'           => 'property',
                'class'          => $classe,
                'classNamespace' => $ref->getNamespaceName(),
            ];
        }
    }
}

// ---- Reflect built-in functions ----

$builtinFunctions = [];
foreach (get_defined_functions()['internal'] as $function) {
    $rfm = new ReflectionFunction($function);
    $builtinFunctions[] = [
        'name'           => $function,
        'parameters'     => reflectParameters($rfm, skipOptional: true),
        'returnType'     => reflectReturnType($rfm),
        'docComment'     => $rfm->getDocComment(),
        'type'           => 'function',
        'class'          => null,
        'classNamespace' => null,
    ];
}

// ---- Merge & deduplicate ----

$allClasses   = array_merge($visitor->classes, $builtinClasses);
$allFunctions = array_merge($visitor->functions, $builtinFunctions);

$allClasses   = array_values(array_map('unserialize', array_unique(array_map('serialize', $allClasses))));
$allFunctions = array_values(array_map('unserialize', array_unique(array_map('serialize', $allFunctions))));

file_put_contents('stubs-generated.json', json_encode([
    'functions' => $allFunctions,
    'classes'   => $allClasses,
    'constants' => extractNamedConstants(),
], JSON_PRETTY_PRINT));

echo 'stubs-generated.json atualizado (' . count($allClasses) . ' membros de classe, ' . count($allFunctions) . ' funções).' . PHP_EOL;
