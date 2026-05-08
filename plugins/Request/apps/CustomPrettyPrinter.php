<?php

namespace plugins\Request;

use PhpParser\Node\Expr;
use PhpParser\Node\Stmt;
use PhpParser\PrettyPrinter\Standard;

class CustomPrettyPrinter extends Standard
{
    public string $nameFile = '';
    public array $dataFileLines = [];

    /**
     * Formata arrays multi-item com indentação alinhada à coluna de origem (PSR-12).
     * Arrays vazios → [] | um item → inline | múltiplos → multi-linha.
     */
    protected function pExpr_Array(Expr\Array_ $node): string
    {
        if (empty($node->items)) {
            return '[]';
        }

        if (count($node->items) === 1) {
            return '[' . $this->p($node->items[0]) . ']';
        }

        $startLine = $node->getStartLine();
        $sourceLine = $this->dataFileLines[$startLine - 1] ?? '';

        $leadingSpaces = 0;
        for ($i = 0; $i < strlen($sourceLine); $i++) {
            if ($sourceLine[$i] !== ' ') {
                break;
            }
            $leadingSpaces++;
        }

        $innerPad = str_repeat(' ', $leadingSpaces + 4);
        $outerPad = str_repeat(' ', $leadingSpaces);

        $result = "[\n";
        foreach ($node->items as $item) {
            $result .= $innerPad . $this->p($item) . ",\n";
        }

        // Remove trailing comma on last item before closing bracket
        $result = substr($result, 0, -2) . "\n";
        $result .= $outerPad . ']';

        return $result;
    }

    /**
     * Força abertura de chave na mesma linha do método (PSR-12).
     */
    protected function pStmt_ClassMethod(Stmt\ClassMethod $node): string
    {
        $result = parent::pStmt_ClassMethod($node);
        return preg_replace('/\)\s*\n\s*\{/', ') {', $result);
    }

    /**
     * Força abertura de chave na mesma linha da função (PSR-12).
     */
    protected function pStmt_Function(Stmt\Function_ $node): string
    {
        $result = parent::pStmt_Function($node);
        return preg_replace('/\)\s*\n\s*\{/', ') {', $result);
    }

    /**
     * Adiciona linha em branco após blocos de controle (PSR-12).
     */
    protected function pStmts(array $nodes, bool $indent = true): string
    {
        $code = parent::pStmts($nodes, $indent);
        return preg_replace('/(\})\n(\$|if|foreach|return)/', "$1\n\n$2", $code);
    }
}
