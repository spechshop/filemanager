<?php
declare(strict_types=1);
namespace plugins\Extension {
    function proc_open($argv, $spec, &$pipes) {
        $GLOBALS['calls'][] = $argv;
        return \proc_open(['/usr/bin/printf', '%s', str_contains(implode(' ', $argv), '-ls') ? " 1234.exact-session (Detached)\n 1235.exact-session-other (Detached)\n" : ''], $spec, $pipes);
    }
}
namespace {
    require dirname(__DIR__, 2) . '/plugins/Extension/plugins/utilsFunction.php';
    use plugins\Extension\utilsFunction;
    $GLOBALS['calls'] = [];
    foreach (['-option', 'bad name', 'other/session', "line\nname", ''] as $name) {
        if (utilsFunction::toggleServer($name, 'echo fixture')['success'] !== false) throw new RuntimeException('Invalid screen identifier accepted');
    }
    if ($GLOBALS['calls']) throw new RuntimeException('Denied session launched process');
    // This command is observed only as an argv element; the fake peer never executes it.
    $code = 'printf "%s\\n" "legitimate quotes"';
    $result = utilsFunction::toggleServer('exact-session', $code);
    if (!$result['success'] || $result['message'] !== 'restart') throw new RuntimeException('Legitimate session restart failed');
    if ($GLOBALS['calls'] !== [['screen', '-ls'], ['screen', '-S', '1234.exact-session', '-X', 'quit'], ['screen', '-dmS', 'exact-session', 'bash', '-c', $code]]) throw new RuntimeException('Screen argv or exact session selection changed');
    echo "PASS screen identifiers, exact matches and quoted administrative code use separate argv\n";
}
