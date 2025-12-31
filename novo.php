<?php

 $autoLoads = listFiles("stubs");
 $autoLoads = array_unique($autoLoads);

 function extractNamedConstants()
{
    $constants = [];
    foreach (get_defined_constants() as $name => $value) {
        $constants[] = ["name" => $name];
    }

    return $constants;
}

$class = get_declared_classes();
$functions = get_defined_functions();
$calls = [];
$callsClass = [];
$callsFunction = [];

foreach ($class as $classe) {
    $reflection = new ReflectionClass($classe);
    $extractedC = get_class_methods($classe);
    foreach ($extractedC as $method) {
        $rfm = new ReflectionMethod($classe, $method);
        $detailsP = [];

        foreach ($rfm->getParameters() as $parameter) {
            if ($parameter->isOptional() and !str_contains($method, "__construct")) {
                continue;
            }
            $detailsP[] = [
                "isOptional" => $parameter->isOptional(),
                "name" => $parameter->getName(),
                "type" => $parameter->getType() ? (method_exists($parameter->getType(), "getName") ? $parameter->getType()->getName() : "mixed") : "mixed",
                //'default' => $parameter->isDefaultValueAvailable() ? $parameter->getDefaultValue() : null,
                "default" => $parameter->isDefaultValueAvailable() ? ($parameter->isDefaultValueConstant() ? $parameter->getDefaultValueConstantName() : $parameter->getDefaultValue()) : null,
                "byReference" => $parameter->isPassedByReference(),
            ];
        }
        $calls[] = [
            "name" => $method,
            "parameters" => $detailsP,
            "returnType" =>
                (!empty($rfm->getReturnType()) and $rfm->getReturnType() !== null and method_exists($rfm->getReturnType(), "getName") and $rfm->getReturnType()->getName() !== "void")
                    ? $rfm->getReturnType()->getName()
                    : "mixed",
            "docComment" => $rfm->getDocComment(),
            "type" => "method",
            "class" => $classe,
            "classNamespace" => $reflection->getNamespaceName(),
        ];
    }
}
foreach ($functions["internal"] as $function) {
    $rfm = new ReflectionFunction($function);
    $detailsP = [];
    foreach ($rfm->getParameters() as $parameter) {
        if ($parameter->isOptional()) {
            continue;
        }
        $detailsP[] = [
            "isOptional" => $parameter->isOptional(),
            "name" => $parameter->getName(),
            "type" => $parameter->getType() ? (method_exists($parameter->getType(), "getName") ? $parameter->getType()->getName() : "mixed") : "mixed",
            "default" => $parameter->isDefaultValueAvailable() ? $parameter->getDefaultValue() : null,
            "byReference" => $parameter->isPassedByReference(),
        ];
    }
    $calls[] = [
        "name" => $function,
        "parameters" => $detailsP,
        "returnType" =>
            (!empty($rfm->getReturnType()) and $rfm->getReturnType() !== null and method_exists($rfm->getReturnType(), "getName") and $rfm->getReturnType()->getName() !== "void")
                ? $rfm->getReturnType()->getName()
                : "mixed",
        "docComment" => $rfm->getDocComment(),
        "type" => "function",
        "class" => null,
        "classNamespace" => null,
    ];
}
foreach ($functions["user"] as $function) {
    $rfm = new ReflectionFunction($function);
    $detailsP = [];
    foreach ($rfm->getParameters() as $parameter) {
        if ($parameter->isOptional()) {
            continue;
        }
        $detailsP[] = [
            "isOptional" => $parameter->isOptional(),
            "name" => $parameter->getName(),
            "type" => $parameter->getType() ? (method_exists($parameter->getType(), "getName") ? $parameter->getType()->getName() : "mixed") : "mixed",
            "default" => $parameter->isDefaultValueAvailable() ? $parameter->getDefaultValue() : null,
            "byReference" => $parameter->isPassedByReference(),
        ];
    }
    $calls[] = [
        "name" => $function,
        "parameters" => $detailsP,
        "returnType" =>
            (!empty($rfm->getReturnType()) and $rfm->getReturnType() !== null and method_exists($rfm->getReturnType(), "getName") and $rfm->getReturnType()->getName() !== "void")
                ? $rfm->getReturnType()->getName()
                : "mixed",
        "docComment" => $rfm->getDocComment(),
        "type" => "function",
        "class" => null,
        "classNamespace" => null,
    ];
}
foreach ($calls as $call) {
    if ($call["type"] === "function") {
        $callsFunction[] = $call;
    } else {
        $callsClass[] = $call;
    }
}
// remove duplicates
$callsFunction = array_map("unserialize", array_unique(array_map("serialize", $callsFunction)));
$callsClass = array_map("unserialize", array_unique(array_map("serialize", $callsClass)));
$calls = [
    "functions" => $callsFunction,
    "classes" => $callsClass,
    "constants" => extractNamedConstants(),
];
file_put_contents("stubs-generated.json", json_encode($calls, JSON_PRETTY_PRINT));

var_dump("oi é o caralho");

function extractClassesAndFunctionsFromTokens($tokens, $originalCode = "")
{
    $code = "";

    $insideClassOrFunction = false;
    $braceCount = 0;
    $insideClass = false;
    $currentFunctionName = "";
    $shortCuts = [];
    $namespace = "";

    for ($i = 0; $i < count($tokens); $i++) {
        $token = $tokens[$i];
        $getTypes = function ($array) {
            $tokenType = $array[0];
            if ($tokenType === T_CLASS) {
                return "T_CLASS";
            }
            if ($tokenType === T_FUNCTION) {
                return "T_FUNCTION";
            }
            if ($tokenType === T_NAMESPACE) {
                return "T_NAMESPACE";
            }
            if ($tokenType === T_STRING) {
                return "T_STRING";
            }
            if ($tokenType === T_VARIABLE) {
                return "T_VARIABLE";
            }
            if ($tokenType === T_PUBLIC) {
                return "T_PUBLIC";
            }
            if ($tokenType === T_PROTECTED) {
                return "T_PROTECTED";
            }
            if ($tokenType === T_PRIVATE) {
                return "T_PRIVATE";
            }
            if ($tokenType === T_ABSTRACT) {
                return "T_ABSTRACT";
            }
            if ($tokenType === T_FINAL) {
                return "T_FINAL";
            }
            if ($tokenType === T_STATIC) {
                return "T_STATIC";
            }
            if ($tokenType === T_IMPLEMENTS) {
                return "T_IMPLEMENTS";
            }
            if ($tokenType === T_EXTENDS) {
                return "T_EXTENDS";
            }
            if ($tokenType === T_USE) {
                return "T_USE";
            }
            if ($tokenType === T_AS) {
                return "T_AS";
            }
            if ($tokenType === T_NEW) {
                return "T_NEW";
            }
            if ($tokenType === T_RETURN) {
                return "T_RETURN";
            }
            if ($tokenType === T_ECHO) {
                return "T_ECHO";
            }
            if ($tokenType === T_IF) {
                return "T_IF";
            }
            if ($tokenType === T_ELSE) {
                return "T_ELSE";
            }
            if ($tokenType === T_ELSEIF) {
                return "T_ELSEIF";
            }
            if ($tokenType === T_WHILE) {
                return "T_WHILE";
            }
            if ($tokenType === T_FOR) {
                return "T_FOR";
            }
            if ($tokenType === T_FOREACH) {
                return "T_FOREACH";
            }
            if ($tokenType === T_SWITCH) {
                return "T_SWITCH";
            }
            if ($tokenType === T_CASE) {
                return "T_CASE";
            }
            if ($tokenType === T_DEFAULT) {
                return "T_DEFAULT";
            }
            if ($tokenType === T_BREAK) {
                return "T_BREAK";
            }
            if ($tokenType === T_CONTINUE) {
                return "T_CONTINUE";
            }
            if ($tokenType === T_TRY) {
                return "T_TRY";
            }
            if ($tokenType === T_CATCH) {
                return "T_CATCH";
            }
            if ($tokenType === T_THROW) {
                return "T_THROW";
            }
            if ($tokenType === T_DECLARE) {
                return "T_DECLARE";
            }
            if ($tokenType === T_GLOBAL) {
                return "T_GLOBAL";
            }
            if ($tokenType === T_VAR) {
                return "T_VAR";
            }
            if ($tokenType === T_CONST) {
                return "T_CONST";
            }
            // trait
            if ($tokenType === T_TRAIT) {
                return "T_TRAIT";
            }
            return "T_UNKNOWN";
        };
        $gv = fn($i) => trim(@$tokens[$i][1]);
        if ($getTypes($token) === "T_NAMESPACE") {
            $namespace = "\\" . $gv($i + 2) . "\\";
        }
        if ($getTypes($token) === "T_CLASS") {
            $shortCuts[] = !$namespace ? "" : $namespace . $gv($i + 2);
        }

        if (is_array($token)) {
            $tokenType = $token[0];
            $tokenContent = $token[1];

            // Verifica se é uma declaração de classe
            if ($tokenType === T_CLASS || $tokenType === T_TRAIT) {
                $insideClass = true;
                $insideClassOrFunction = true;

                // Adiciona a classe ao espaço global se namespace estiver presente
                if (isset($tokens[$i - 2]) && $tokens[$i - 2][0] === T_NAMESPACE) {
                    $namespace = trim($tokens[$i - 1][1]);
                } else {
                    $namespace = "";
                }

                $class = @trim(explode("{", !explode("class", $tokenContent)[1] ? explode("trait", $tokenContent)[1] : explode("class", $tokenContent)[1])[0]);
                $GLOBALS["spaces"][$class] = [
                    "namespace" => $namespace,
                    "class" => $class,
                    "callable" => $namespace . "\\" . $class,
                ];
            }

            // Verifica se é uma declaração de função
            if ($tokenType === T_FUNCTION) {
                // Verifica se é uma função anônima (procura por "function(" sem um nome de função)
                if (isset($tokens[$i + 1]) && @$tokens[$i + 1][1] === " " && isset($tokens[$i + 2]) && @$tokens[$i + 2] === "(") {
                    if (!function_exists(@$tokens[$i - 2][1])) {
                        continue;
                    }
                } else {
                    $insideClassOrFunction = true;
                    // Salva o nome da função
                    $currentFunctionName = isset($tokens[$i - 2]) ? @$tokens[$i - 2][1] : "";
                }
            }

            // Adiciona o token ao código se estiver dentro de uma classe ou função nomeada
            if ($insideClassOrFunction) {
                $code .= $tokenContent;

                // Conta as chaves para acompanhar o início e o fim de blocos
                if ($tokenContent === "{") {
                    $braceCount++;
                } elseif ($tokenContent === "}") {
                    $braceCount--;
                    // Se todas as chaves foram fechadas, significa que saiu da classe ou função
                    if ($braceCount === 0) {
                        $insideClassOrFunction = false;
                        if (!$insideClass && $currentFunctionName) {
                            // Adiciona a verificação de existência de função fora de classes
                            $code = str_replace("function " . $currentFunctionName, 'if (!function_exists(\'' . $currentFunctionName . '\')) function ' . $currentFunctionName, $code);
                        }
                        $currentFunctionName = "";
                    }
                }
            }
        } else {
            // Adiciona caracteres simples (como '{' e '}') ao código
            if ($insideClassOrFunction) {
                $code .= $token;
                if ($token === "{") {
                    $braceCount++;
                } elseif ($token === "}") {
                    $braceCount--;
                    if ($braceCount === 0) {
                        $insideClassOrFunction = false;
                        if (!$insideClass && $currentFunctionName) {
                            // se a função não estiver dentro de uma classe, adiciona a verificação de existência
                            if (!function_exists($currentFunctionName)) {
                                $code = str_replace("function " . $currentFunctionName, 'if (!function_exists(\'' . $currentFunctionName . '\')) function ' . $currentFunctionName, $code);
                            } else {
                                $code = str_replace("function " . $currentFunctionName, 'if (!function_exists(\'' . $currentFunctionName . '\')) function ' . $currentFunctionName, $code);
                            }
                        }
                        $currentFunctionName = "";
                    }
                }
            }
        }
    }

    return [
        "code" => $code,
        "shortCuts" => $shortCuts,
    ];
}

function listFiles($dir, $filter = null)
{
    $result = [];
    $files = scandir($dir);
    foreach ($files as $file) {
        if ($file === "." || $file === "..") {
            continue;
        }

        $filePath = $dir . "/" . $file;
        if (is_dir($filePath)) {
            $result = array_merge($result, listFiles($filePath, $filter));
        } else {
            if (!empty($filter)) {
                if (str_ends_with(strtolower($filePath), $filter)) {
                    $result[] = $filePath;
                }
            } else {
                $result[] = $filePath;
            }
        }
    }

    return $result;
}
