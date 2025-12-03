<?php


if (!function_exists('extractNamedConstants')) {
    function extractNamedConstants()
    {
        $constants = [];
        foreach (get_defined_constants() as $name => $value) {
            $constants[] = ['name' => $name];
        }

        return $constants;
    }
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
    $rfm = new ReflectionFunction($function);
    $detailsP = [];
    foreach ($rfm->getParameters() as $parameter) {
        /** @var ReflectionParameter $parameter */
        



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

print json_encode([
    'callsFunction' => $callsFunction,
    'callsClass' => $callsClass,
    'constants' => extractNamedConstants(),
]);