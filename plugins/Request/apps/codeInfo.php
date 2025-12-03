<?php



namespace plugins\Request;

use PhpParser\Node;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitorAbstract;
use PhpParser\ParserFactory;
use Swoole\Http\Request;
use Swoole\Http\Response;

require 'vendor/autoload.php';

class CodeInfo
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

        if (empty($data['nameFile'])) {
            return self::sendJsonResponse($response, self::INVALID_TOKEN_RESPONSE);
        }

        $filePath = self::FILES_DIR . DIRECTORY_SEPARATOR . $data['nameFile'];
        $fileContent = file_get_contents($filePath);

        if (self::runBugfixScript($fileContent, $data['nameFile'])) {
            return self::sendJsonResponse($response, self::ERROR_IN_CODE_RESPONSE);
        }

        return self::sendJsonResponse($response, self::parseAndTraverse($fileContent));
    }

    private static function sendJsonResponse(Response $response, array $data): ?bool
    {
        return $response->end(json_encode($data, JSON_PRETTY_PRINT));
    }

    private static function runBugfixScript(string $fileContent, string $filename): bool
    {
        $script = <<<'PHP'
include 'vendor/autoload.php';
$parser = (new PhpParser\ParserFactory)->create(PhpParser\ParserFactory::PREFER_PHP7);
$prettyPrinter = new PhpParser\PrettyPrinter\Standard;
$ast = $parser->parse(
PHP;
      $escapedScript = escapeshellarg($script . 'file_get_contents(\'' . $filename . '\'));');

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





class FindClassesAndMethodsVisitor extends NodeVisitorAbstract
{
    private $collectedClasses = [];

    public function enterNode(Node $node)
    {
        $this->captureClassInstantiations($node);
        $this->captureFuncCallAssignments($node);
        $this->captureMethodCalls($node);
        $this->captureMethodParameters($node);
        $this->captureFunctionReturnTypes($node);
        $this->captureFunctionArguments($node);
    }

    private function captureFunctionArguments(Node $node): void
    {
        if ($node instanceof Node\Expr\FuncCall && $node->name instanceof Node\Name\FullyQualified) {
            $functionName = $node->name->toString();
            $arguments = [];

            foreach ($node->args as $arg) {
                $arguments[] = $arg->value->getType();
            }

            if (function_exists($functionName)) {
                $reflection = new \ReflectionFunction($functionName);
                $parameters = $reflection->getParameters();

                foreach ($parameters as $index => $parameter) {
                    $paramName = $parameter->getName();
                    $paramType = $parameter->getType();

                    if ($paramType && method_exists($paramType, 'getName')) {
                        $arguments[$paramName] = $paramType->getName();
                    }
                }
            }

            $this->collectedClasses[$functionName] = [
                'class' => 'unknown', // ou outra lógica para determinar a classe
                'methods' => [],
                'arguments' => $arguments,
            ];
        }
    }

    private function captureClassInstantiations(Node $node): void
    {
        if ($node instanceof Node\Expr\Assign && $node->expr instanceof Node\Expr\New_) {
            $variableName = $node->var->name ?? 'unknown';
            $className = $node->expr->class->toString() ?? 'unknown';

            if (is_string($variableName) && is_string($className)) {
                $this->collectedClasses[$variableName] = [
                    'class' => $className,
                    'methods' => $this->getClassMethods($className),
                    'arguments' => [],
                ];
            }
        }
    }

    private function captureFuncCallAssignments(Node $node): void
    {
        if ($node instanceof Node\Expr\Assign && $node->expr instanceof Node\Expr\FuncCall && $node->expr->name instanceof Node\Name\FullyQualified) {
            $variableName = $node->var->name;
            $functionName = $node->expr->name->toString();

            $this->processFunctionCall($variableName, $functionName);
        }
    }

    private function captureMethodCalls(Node $node): void
    {
        if ($node instanceof Node\Expr\MethodCall && $node->var instanceof Node\Expr\Variable) {
            $variableName = $node->var->name;
            $methodName = $node->name->name;

            if (isset($this->collectedClasses[$variableName])) {
                $this->processMethodCall($variableName, $methodName);
            }
        }
    }

    private function captureMethodParameters(Node $node): void
    {
        if ($node instanceof Node\Stmt\ClassMethod) {
            $methodName = $node->name->toString();
            $parameters = [];

            foreach ($node->params as $param) {
                $paramName = $param->var->name;
                $paramType = !empty($param->type) && method_exists($param->type, 'toString')
                    ? $param->type->toString()
                    : 'mixed';

                // Captura métodos da classe do parâmetro
                if (class_exists($paramType)) {
                    $this->collectedClasses[$paramName] = [
                        'class' => $paramType,
                        'methods' => $this->getClassMethods($paramType),
                        'arguments' => [],
                    ];
                }

                $parameters[$paramName] = $paramType;
            }

            // Adiciona os argumentos ao método na classe correspondente
            if (isset($this->collectedClasses[$methodName])) {
                $this->collectedClasses[$methodName]['arguments'] = $parameters;
            } else {
                $this->collectedClasses[$methodName] = [
                    'class' => 'unknown', // ou outra lógica para determinar a classe
                    'methods' => [],
                    'arguments' => $parameters,
                ];
            }
        }
    }


    private function captureFunctionReturnTypes(Node $node): void
    {
        if ($node instanceof Node\Stmt\Function_ || $node instanceof Node\Stmt\ClassMethod) {
            if (!empty($node->stmts)) {
                foreach ($node->stmts as $stmt) {
                    if ($stmt instanceof Node\Stmt\Return_ && $stmt->expr) {
                        $this->processReturnType($stmt->expr->getType(), $node);
                    }
                }
            }
        }
    }


    private function processFunctionCall(string $variableName, string $functionName): void
    {
        if (function_exists($functionName)) {
            $reflection = new \ReflectionFunction($functionName);
            $returnType = $reflection->getReturnType();

            if ($returnType && method_exists($returnType, 'getName') && $returnType->getName() !== 'void') {
                $this->collectedClasses[$variableName] = [
                    'class' => $returnType->getName(),
                    'methods' => $this->getClassMethods($returnType->getName()),
                    'arguments' => [],
                ];
            }
        }
    }

    private function processMethodCall(string $variableName, string $methodName): void
    {
        if (!in_array($methodName, $this->collectedClasses[$variableName]['methods'])) {
            $this->collectedClasses[$variableName]['methods'][] = $methodName;
            $this->addReturnMethods($variableName, $methodName);
        }
    }

    private function processReturnType($returnType, Node $node): void
    {
        if ($returnType && method_exists($returnType, 'getName') && $returnType->getName() !== 'void') {
            $className = $returnType->getName();
            $methodName = $node->name instanceof Node\Identifier ? $node->name->name : null;

            if ($methodName && class_exists($className)) {
                $this->collectedClasses[$methodName] = [
                    'class' => $className,
                    'methods' => $this->getClassMethods($className),
                    'arguments' => [],
                ];
            }
        }
    }

    private function getClassMethods(string $className): array
    {
        return class_exists($className)
            ? array_map(fn($method) => $method->getName(), (new \ReflectionClass($className))->getMethods())
            : [];
    }

    private function addReturnMethods(string $variableName, string $methodName): void
    {
        $className = $this->collectedClasses[$variableName]['class'];

        if (class_exists($className) && ($method = (new \ReflectionClass($className))->getMethod($methodName))) {
            $returnType = $method->getReturnType();

            if ($returnType && method_exists($returnType, 'getName') && $returnType->getName() !== 'void') {
                $this->collectedClasses[$variableName]['methods'] = array_merge(
                    $this->collectedClasses[$variableName]['methods'],
                    $this->getClassMethods($returnType->getName())
                );
            }
        }
    }

    public function getCollectedClasses(): array
    {
        return $this->collectedClasses;
    }
}
