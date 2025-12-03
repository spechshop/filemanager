<?php

namespace plugins\Request;

use PhpParser\Node\Expr;
use PhpParser\PrettyPrinter\Standard;

/**
 * Classe para formatação de código PHP conforme PSR-12
 */
class CustomPrettyPrinter extends Standard
{
    private $lastLine;
    public $nameFile = '';
    public $dataFileLines = '';

    /**
     * Formata arrays estilo PSR-12
     */
    protected function pExpr_Array(Expr\Array_ $node): string
    {
        if (empty($node->items)) {
            return '[]';
        }
        if (count($node->items) === 1) {
            return '[' . $this->p($node->items[0]) . ']';
        }

        $indent = $this->indent();
        $result = "[\n";



        var_dump($node->getLine());
        $line = $this->dataFileLines[$node->getLine()-1];
        $spaces = 0;
        for ($i = 0; $i < strlen($line); $i++) {
            if ($line[$i] === ' ') {
                $spaces++;
            } else {
                break;
            }
        }
         
        foreach ($node->items as $item) {
            //var_dump($item);;
            $rs = str_repeat(' ', $spaces+4);
            $result .= $rs . $this->p($item) . ",\n";
        }

        $rs = str_repeat(' ', $spaces);
        $result .= $this->outdent() .$rs. "]";
        if (str_ends_with($result, ",\n]")) {
            $result = str_replace(",\n]", "\n]", $result);
        }

        return $result;
    }











    /**
     * Ajusta atribuição com espaço conforme PSR-12
     */
    protected function pExpr_Assign(Expr\Assign $node, int $precedence, int $lhsPrecedence): string
    {
        return $this->p($node->var) . ' = ' . $this->p($node->expr);
    }

    /**
     * Ajusta operadores binários com espaço conforme PSR-12
     */
    protected function pExpr_BinaryOp(Expr\BinaryOp $node, int $precedence, int $lhsPrecedence): string
    {
        return $this->p($node->left) . ' ' . $node->getOperatorSigil() . ' ' . $this->p($node->right);
    }

    /**
     * Força chaves na mesma linha para métodos conforme PSR-12
     */
    protected function pStmt_ClassMethod(\PhpParser\Node\Stmt\ClassMethod $node): string
    {
        $result = parent::pStmt_ClassMethod($node);
        return preg_replace('/\)\s*\n\s*\{/', ') {', $result);
    }

    /**
     * Força chaves na mesma linha para funções conforme PSR-12
     */
    protected function pStmt_Function(\PhpParser\Node\Stmt\Function_ $node): string
    {
        $result = parent::pStmt_Function($node);
        return preg_replace('/\)\s*\n\s*\{/', ') {', $result);
    }

    /**
     * Adiciona linha em branco entre blocos principais conforme PSR-12
     */
    protected function pStmts(array $nodes, bool $indent = true): string
    {
        $code = parent::pStmts($nodes, $indent);

        // Adiciona linha em branco entre blocos principais (if, foreach, return)
        $code = preg_replace('/(\})\n(\$|if|foreach|return)/', "\$1\n\n\$2", $code);

        return $code;
    }
}
