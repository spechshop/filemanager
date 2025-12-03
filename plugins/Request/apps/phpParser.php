<?php

namespace plugins\Request;

use PhpParser\Node;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitorAbstract;
use PhpParser\ParserFactory;
use Swoole\Http\Request;
use Swoole\Http\Response;
require "vendor/autoload.php";
class phpParser
{
    private const CONTENT_TYPE_JSON = "Content-Type";
    private const MIME_TYPE_JSON = "application/json";
    private const INVALID_TOKEN_RESPONSE = ["error" => "Invalid Token"];
    private const ERROR_IN_CODE_RESPONSE = ["error" => "Error in code"];
    public static function api(Request $request, Response $response): ?bool
    {
        $response->header(self::CONTENT_TYPE_JSON, self::MIME_TYPE_JSON);
        $data = json_decode($request->rawContent(), true);
        if (empty($data["nameFile"])) {
            return $response->end(json_encode(["error" => "nameFile is required"]));
        }
        $filePath = $data["nameFile"];
        $code = file_get_contents($filePath);
        if (!str_starts_with($code, "<?php")) {
            return $response->end(json_encode(["error" => "File does not start with <?php"]));
        }
        $bug = self::runBugfixScript($code, $data["nameFile"]);
        if ($bug) {
            $baseDir = \plugins\Request\appController::baseDir();
            $script = <<<'PHP'
            $includeVendorWithBaseDir = '__BASE_DIR__';
            include $includeVendorWithBaseDir;
            $parser = (new \PhpParser\ParserFactory)->createForNewestSupportedVersion();
            $prettyPrinter = new \PhpParser\PrettyPrinter\Standard;
            $ast = $parser->parse(
            PHP;
            $script = str_replace('__BASE_DIR__', $baseDir . 'vendor/autoload.php', $script);
            $escapedScript = escapeshellarg($script . 'file_get_contents(\'' . $filename . '\'));');
            return $response->end(json_encode([
                "error" => "Bugfix script executed successfully, but no classes found.",
                "res" => $bug,
                "baseDir" => \plugins\Request\appController::baseDir(),
                "script" => $script,
            ]));
        }
        $parser = (new ParserFactory())->createForNewestSupportedVersion();
        $ast = $parser->parse($code);
        $traverser = new NodeTraverser();
        $visitor = new findClassesAndMethodsVisitorParser();
        $traverser->addVisitor($visitor);
        $traverser->traverse($ast);
        $collectedClasses = $visitor->getCollectedClasses();
        $response->header("Content-Type", "application/json");
        $formattedClasses = [];
        foreach ($collectedClasses as $variable => $class) {
            $formattedClasses[$variable] = [
                "variable" => $variable,
                "class" => $class,
            ];
            // change case existe /** @var classename $varname */
        }
        $response->end(json_encode($formattedClasses));
        return true;
    }
    private static function runBugfixScript(string $fileContent, string $filename) {
        $baseDir = \plugins\Request\appController::baseDir();
        $script = <<<'PHP'
        $includeVendorWithBaseDir = '__BASE_DIR__';
        include $includeVendorWithBaseDir;
        $parser = (new \PhpParser\ParserFactory)->createForNewestSupportedVersion();
        $prettyPrinter = new \PhpParser\PrettyPrinter\Standard;
        $ast = $parser->parse(
        PHP;
        $script = str_replace('__BASE_DIR__', $baseDir . 'vendor/autoload.php', $script);
        $escapedScript = escapeshellarg($script . 'file_get_contents(\'' . $filename . '\'));');
        $output = shell_exec("php -r " . $escapedScript);
        return $output;
    }
}
/**
 * {"server": "Swoole\\Http\\Server", "request": "Swoole\\Http\\Request", "response": "Swoole\\Http\\Response"}
 *
 */
use PhpParser\Node\Expr\FuncCall;
use PhpParser\Node\Expr\Variable;
class findClassesAndMethodsVisitorParser extends NodeVisitorAbstract
{
    private array $collectedClasses = [];
    public function enterNode(Node $node) {
        if ($node instanceof Node\Expr\Assign) {
            $variableName = $node->var instanceof Variable ? $node->var->name : null;
            if ($node->expr instanceof Node\Expr\New_) {
                $className = $node->expr->class->toString();
                if ($variableName && is_string($variableName) && is_string($className)) {
                    $this->collectedClasses[$variableName] = $className;
                }
            } elseif ($node->expr instanceof FuncCall) {
                $this->collectFuncCall($node->expr, $variableName);
            }
        }
        // Verifica se o nó tem comentários e extrai @var
        if ($comments = $node->getComments()) {
            foreach ($comments as $comment) {
                if (preg_match_all('/@var\s+([\w\\\\]+)\s+\$(\w+)/', $comment->getText(), $matches, PREG_SET_ORDER)) {
                    foreach ($matches as $match) {
                        $className = $match[1];
                        $varName = $match[2];
                        if (!isset($this->collectedClasses[$varName])) {
                            $this->collectedClasses[$varName] = $className;
                        }
                    }
                }
            }
        }
        if ($node instanceof Node\Stmt\Return_) {
            if ($node->expr instanceof Node\Expr\New_) {
                $variableName = $node->expr->class->toString();
                $className = $node->expr->class->toString();
                if ($variableName) {
                    $this->collectedClasses[$variableName] = $className;
                }
            }
        }
        if ($node instanceof Node\Expr\MethodCall) {
            if ($node->var instanceof Variable) {
                $variableName = $node->var->name;
                if (!isset($this->collectedClasses[$variableName])) {
                    $this->collectedClasses[$variableName] = null;
                }
            }
        }
        if ($node instanceof FuncCall) {
            foreach ($node->args as $arg) {
                if ($arg->value instanceof Node\Expr\New_) {
                    $variableName = $this->getVariableNameFromArg($arg);
                    $className = $arg->value->class->toString();
                    if ($variableName) {
                        $this->collectedClasses[$variableName] = $className;
                    }
                } elseif ($arg->value instanceof FuncCall) {
                    $this->collectFuncCall($arg->value);
                }
            }
        }
        if ($node instanceof Node\Expr\StaticCall) {
            $className = $node->class->toString();
            if ($node->name instanceof Node\Identifier) {
                $methodName = $node->name->name;
                if ($className === "static") {
                    $className = $this->getFunctionCallClass($node);
                }
                if ($className) {
                    $this->collectedClasses[$methodName] = $className;
                }
            }
        }
        if ($node instanceof Node\Param) {
            if ($node->type instanceof Node\Name && $node->var instanceof Variable) {
                $this->collectedClasses[$node->var->name] = $node->type->toString();
            }
        }
        if ($node instanceof Node\Stmt\Function_) {
            $functionName = $node->name->name;
            $returnType = $node->getReturnType();
            if ($returnType instanceof Node\Name) {
                $this->collectedClasses[$functionName] = $returnType->toString();
            }
        }
    }
    private function getFunctionCallClass(Node\Expr\StaticCall $node): ?string
    {
        $functionName = $node->name->name;
        $function = new \ReflectionFunction($functionName);
        $returnType = $function->getReturnType();
        if ($returnType instanceof \ReflectionNamedType) {
            return $returnType->getName();
        }
        return null;
    }
    private function getVariableNameFromArg(Node\Arg $arg): ?string
    {
        if ($arg->value instanceof Variable) {
            return $arg->value->name;
        }
        return null;
    }
    private function collectFuncCall(FuncCall $funcCall, ?string $variableName = null): void
    {
        if (method_exists($funcCall->name, "toString")) {
            $functionName = $funcCall->name->toString();
        } else {
            $functionName = null;
        }
        if ($variableName && is_string($variableName)) {
            try {
                $reflectionFunc = new \ReflectionFunction($functionName);
                $returnType = $reflectionFunc->getReturnType();
                if ($returnType instanceof \ReflectionNamedType) {
                    $this->collectedClasses[$variableName] = $returnType->getName();
                }
            } catch (\ReflectionException $e) {
            }
        }
    }
    public function getCollectedClasses(): array
    {
        return $this->collectedClasses;
    }
    public function leaveNode(Node $node) {
    }
}