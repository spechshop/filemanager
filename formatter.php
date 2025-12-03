<?php

include 'vendor/autoload.php';

$code = file_get_contents('/root/voipmaster/plugins/Start/events/events.php');


$parser = new PhpParser\ParserFactory();


/** @var PhpParser\Parser $p */
$p = $parser->create(PhpParser\ParserFactory::PREFER_PHP7);

$stmts = $p->parse($code);
$prettierPr = new PhpParser\PrettyPrinter\Standard();
print $c = $prettierPr->prettyPrintFile($stmts, 
    [
        'shortArraySyntax' => true,
        'scalarTypeHints' => true,
        'scalarTypeHintsStrict' => true,
        'nullableTypeHints' => true,
        'classConstantVisibility' => true,
        'constantVisibility' => true,
        'phpVersion' => 80000, // PHP 8.0
    ]
);

