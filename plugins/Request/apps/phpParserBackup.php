<?php


namespace plugins\Request;

use PhpParser\Node;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitorAbstract;
use PhpParser\ParserFactory;
use Swoole\Http\Request;
use Swoole\Http\Response;

require 'vendor/autoload.php';


class phpParserBackup
{
    public const CONTENT_TYPE_JSON = 'Content-Type';
    public const MIME_TYPE_JSON = 'application/json';
    public const INVALID_TOKEN_RESPONSE = ['error' => 'Invalid Token'];
    public const ERROR_IN_CODE_RESPONSE = ['error' => 'Error in code'];


    public static function api(Request $request, Response $response): ?bool
    {
        $response->header(self::CONTENT_TYPE_JSON, self::MIME_TYPE_JSON);
        $data = json_decode($request->rawContent(), true);
        if (empty($data['nameFile'])) {
            return $response->end(json_encode(self::ERROR_IN_CODE_RESPONSE));
        }

        $filePath =  $data['nameFile'];
        $code = file_get_contents($filePath);

        if (self::runBugfixScript($code, $data['nameFile'])) {
            return $response->end(json_encode(self::ERROR_IN_CODE_RESPONSE));
        }


        $parser = (new ParserFactory)->create(ParserFactory::PREFER_PHP7);
        $ast = $parser->parse($code);

        $traverser = new NodeTraverser();
        $visitor = new findClassesAndMethodsVisitorParser();
        $traverser->addVisitor($visitor);
        $traverser->traverse($ast);

        $collectedClasses = $visitor->getCollectedClasses();

        $response->header('Content-Type', 'application/json');
        $formattedClasses = [];
        foreach ($collectedClasses as $variable => $class) {
            $formattedClasses[$variable] = ['variable' => $variable, 'class' => $class];
        }
        $response->end(json_encode($formattedClasses));
        return true;
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
}


// obter todas as variáveis que instanciam uma classe ou são argumentos de funções ou métodos e tem o tipo da classe
// deve capturar qualquer método chamado em uma variável que é uma instância de uma classe
// pegar também por exemplo em $server->on("request", static function (\Swoole\Http\Request $request, \Swoole\Http\Response $response)

/**
 * {"server": "Swoole\\Http\\Server", "request": "Swoole\\Http\\Request", "response": "Swoole\\Http\\Response"}
 *
 */


use PhpParser\Node\Expr\FuncCall;
use PhpParser\Node\Expr\Variable;

class findClassesAndMethodsVisitorParserbackup extends NodeVisitorAbstract
{
    private array $collectedClasses = [];

    public function enterNode(Node $node)
    {
        // Variáveis atribuídas como novas instâncias de classes ou resultado de chamadas de função
        if ($node instanceof Node\Expr\Assign) {
            $variableName = $node->var instanceof Variable ? $node->var->name : null;
            // Verifica se o valor da variável é uma nova instância ou uma chamada de função
            if ($node->expr instanceof Node\Expr\New_) {
                $className = $node->expr->class->toString();
                if ($variableName && is_string($variableName) && is_string($className)) {
                    $this->collectedClasses[$variableName] = $className;
                }
            } elseif ($node->expr instanceof FuncCall) {
                // Captura o nome da função chamada
                $this->collectFuncCall($node->expr, $variableName);
            }
        }

        // Retorno de uma função
        if ($node instanceof Node\Stmt\Return_) {
            if ($node->expr instanceof Node\Expr\New_) {
                $variableName = $node->expr->class->toString();
                $className = $node->expr->class->toString();
                if ($variableName) {
                    $this->collectedClasses[$variableName] = $className;
                }
            }
        }

        // Verifica se a variável é uma instância de uma classe
        if ($node instanceof Node\Expr\MethodCall) {
            if ($node->var instanceof Variable) {
                $variableName = $node->var->name;
                if (!isset($this->collectedClasses[$variableName])) {
                    $this->collectedClasses[$variableName] = null; // Placeholder caso a classe não tenha sido identificada
                }
            }
        }

        // Verifica argumentos de chamadas de função e captura variáveis e classes
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

        // Chamada estática
        if ($node instanceof Node\Expr\StaticCall) {
            $className = $node->class->toString();
            if ($node->name instanceof Node\Identifier) {
                $methodName = $node->name->name;
                if ($className === 'static') {
                    $className = $this->getFunctionCallClass($node);
                }
                if ($className) {
                    $this->collectedClasses[$methodName] = $className;
                }
            }
        }

        // Verifica se a variável é um argumento de uma função ou método
        if ($node instanceof Node\Param) {
            if ($node->type instanceof Node\Name && $node->var instanceof Variable) {
                $this->collectedClasses[$node->var->name] = $node->type->toString();
            }
        }

        // Verifica se a variável é uma função que retorna uma instância de uma classe
        if ($node instanceof Node\Stmt\Function_) {
            $functionName = $node->name->name;
            $returnType = $node->getReturnType();
            if ($returnType instanceof Node\Name) {
                $this->collectedClasses[$functionName] = $returnType->toString();
            }
        }
    }

    // Função utilitária para obter tipo de retorno de função
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

    // Coleta chamadas de funções, capturando a assinatura de tipo se possível
    private function collectFuncCall(FuncCall $funcCall, ?string $variableName = null): void
    {
        // method exist to string
        if (method_exists($funcCall->name, 'toString')) {
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
                // Ignorar exceção
            }
        }
    }

    public function getCollectedClasses(): array
    {
        return $this->collectedClasses;
    }

    public function leaveNode(Node $node)
    {
        // Código opcional a ser adicionado ao sair de um nó
    }
}