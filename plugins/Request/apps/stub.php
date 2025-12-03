<?php



namespace plugins\Request;

use PhpParser\Node;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitorAbstract;
use PhpParser\ParserFactory;
use ReflectionClass;
use ReflectionFunction;
use ReflectionMethod;
use Swoole\Http\Request;
use Swoole\Http\Response;

require 'vendor/autoload.php';

class stub
{
    private const CONTENT_TYPE_JSON = 'Content-Type';
    private const MIME_TYPE_JSON = 'application/json';
    private const FILES_DIR = 'files';
    private const INVALID_TOKEN_RESPONSE = ['error' => 'Invalid Token'];
    private const ERROR_IN_CODE_RESPONSE = ['error' => 'Error in code'];

    public static function api(Request $request, Response $response): ?bool
    {
        if (!Security::verifyToken($request)) {
            return self::sendJsonResponse($response, self::INVALID_TOKEN_RESPONSE);
        }

        $response->header(self::CONTENT_TYPE_JSON, self::MIME_TYPE_JSON);

        $data = json_decode($request->rawContent(), true);
        $word = $data['word'] ?? null;
        $fullPath = $data['fullPath'] ?? null;
        $x = \Soyhuce\ClassMapGenerator\ClassMapGenerator::createMap('files');
        $includes = [];
        foreach ($x as $xc => $file) {
            if (str_contains($xc, $word)) {
                $includes[] = $file;
            }
        }
        $class = get_declared_classes();
        $functions = get_defined_functions();
        $calls = [];
        $callsClass = [];
        $callsFunction = [];
        $class = get_declared_classes();
        $functions = get_defined_functions();
        $calls = [];
        $callsClass = [];
        $callsFunction = [];



        foreach ($class as $classe) {
            if (str_contains($classe, 'stubTmp_')) continue;
            if (!str_contains($classe, $word)) continue;
            $reflection = new ReflectionClass($classe);
            $extractedC = get_class_methods($classe);
            foreach ($extractedC as $method) {
                $rfm = new ReflectionMethod($classe, $method);
                $detailsP = [];


                foreach ($rfm->getParameters() as $parameter) {
                    if ($parameter->isOptional() and !str_contains($method, '__construct')) continue;
                    $detailsP[] = [
                        'isOptional' => $parameter->isOptional(),
                        'name' => $parameter->getName(),
                        'type' => $parameter->getType() ? (method_exists($parameter->getType(), 'getName') ? $parameter->getType()->getName() : 'mixed') : 'mixed',
                        'default' => $parameter->isDefaultValueAvailable() ? $parameter->getDefaultValue() : null,
                        'byReference' => $parameter->isPassedByReference(),
                    ];
                }
                $calls[] = [
                    'name' => $method,
                    'parameters' => $detailsP,
                    'returnType' => (!empty($rfm->getReturnType()) and $rfm->getReturnType() !== null and method_exists($rfm->getReturnType(), 'getName') and $rfm->getReturnType()->getName() !== 'void')
                        ? $rfm->getReturnType()->getName()
                        : 'mixed',
                    'docComment' => $rfm->getDocComment(),
                    'type' => 'method',
                    'class' => $classe,
                    'classNamespace' => $reflection->getNamespaceName(),
                ];
            }
        }
        foreach ($functions['internal'] as $function) {
            if (!str_contains($function, $word)) continue;
            $rfm = new ReflectionFunction($function);
            $detailsP = [];
            foreach ($rfm->getParameters() as $parameter) {
                if ($parameter->isOptional()) continue;
                $detailsP[] = [
                    'isOptional' => $parameter->isOptional(),
                    'name' => $parameter->getName(),
                    'type' => $parameter->getType() ? (method_exists($parameter->getType(), 'getName') ? $parameter->getType()->getName() : 'mixed') : 'mixed',
                    'default' => $parameter->isDefaultValueAvailable() ? $parameter->getDefaultValue() : null,
                    'byReference' => $parameter->isPassedByReference(),
                ];
            }
            $calls[] = [
                'name' => $function,
                'parameters' => $detailsP,
                'returnType' => (!empty($rfm->getReturnType()) and $rfm->getReturnType() !== null and method_exists($rfm->getReturnType(), 'getName') and $rfm->getReturnType()->getName() !== 'void')
                    ? $rfm->getReturnType()->getName()
                    : 'mixed',
                'docComment' => $rfm->getDocComment(),
                'type' => 'function',
                'class' => null,
                'classNamespace' => null,
            ];
        }
        foreach ($functions['user'] as $function) {
            if (!str_contains($function, $word)) continue;
            $rfm = new ReflectionFunction($function);
            $detailsP = [];
            foreach ($rfm->getParameters() as $parameter) {
                if ($parameter->isOptional()) continue;
                $detailsP[] = [
                    'isOptional' => $parameter->isOptional(),
                    'name' => $parameter->getName(),
                    'type' => $parameter->getType() ? (method_exists($parameter->getType(), 'getName') ? $parameter->getType()->getName() : 'mixed') : 'mixed',
                    'default' => $parameter->isDefaultValueAvailable() ? $parameter->getDefaultValue() : null,
                    'byReference' => $parameter->isPassedByReference(),
                ];
            }
            $calls[] = [
                'name' => $function,
                'parameters' => $detailsP,
                'returnType' => (!empty($rfm->getReturnType()) and $rfm->getReturnType() !== null and method_exists($rfm->getReturnType(), 'getName') and $rfm->getReturnType()->getName() !== 'void')
                    ? $rfm->getReturnType()->getName()
                    : 'mixed',
                'docComment' => $rfm->getDocComment(),
                'type' => 'function',
                'class' => null,
                'classNamespace' => null,
            ];
        }
        foreach ($calls as $call) {
            if ($call['type'] === 'function') {
                $callsFunction[] = $call;
            } else {
                $callsClass[] = $call;
            }
        }
// remove duplicates
        $callsFunction = array_map("unserialize", array_unique(array_map("serialize", $callsFunction)));
        $callsClass = array_map("unserialize", array_unique(array_map("serialize", $callsClass)));


        $calls = [
            'functions' => $callsFunction,
            'classes' => $callsClass,
            'constants' => self::extractNamedConstants()
        ];
        $calls = json_encode($calls, JSON_PRETTY_PRINT);



        return self::sendJsonResponse($response, $callsClass);


    }

    private static function sendJsonResponse(Response $response, array $data): ?bool
    {
        return $response->end(json_encode($data, JSON_PRETTY_PRINT));
    }

    private static function extractNamedConstants(): array
    {
        $constants = get_defined_constants(true);
        $namedConstants = [];
        foreach ($constants as $constantType) {
            foreach ($constantType as $name => $value) {
                if (!is_int($name)) {
                    $namedConstants[$name] = $value;
                }
            }
        }

        return $namedConstants;
    }

    private static function runBugfixScript(string $fileContent, string $filename): bool
    {
        $script = <<<'PHP'
include 'vendor/autoload.php';
$parser = (new PhpParser\ParserFactory)->create(PhpParser\ParserFactory::PREFER_PHP7);
$prettyPrinter = new PhpParser\PrettyPrinter\Standard;
$ast = $parser->parse(
PHP;
        $escapedScript = escapeshellarg($script . 'file_get_contents(\'files\' . DIRECTORY_SEPARATOR . \'' . $filename . '\'));');
        $output = shell_exec('php -r ' . $escapedScript);

        return (bool)$output;
    }

    private static function parseAndTraverse(string $fileContent): array
    {
        $parser = (new ParserFactory)->create(ParserFactory::PREFER_PHP7);
        $ast = $parser->parse($fileContent);

        $traverser = new NodeTraverser();
        $classAndMethodsVisitor = new FindClassesAndMethodsVisitor();
        $traverser->addVisitor($classAndMethodsVisitor);
        $traverser->traverse($ast);


        return $classAndMethodsVisitor->getCollectedClasses();
    }
}


