<?php
namespace plugins\Request;
use Swoole\Http\Request;
use Swoole\Http\Response;
class toggleStubsPath
{
    public static function api(Request $request, Response $response): ?bool
    {
        if (!security::authorize($request, $response, __CLASS__)) return true;
        $owner = securityPolicy::requestToken($request);
        $path = securityPolicy::path($request->get['path'] ?? '', $owner);
        $paths = is_file('stubs-paths.json') ? json_decode(file_get_contents('stubs-paths.json'), true) : [];
        if (!is_array($paths)) return security::deny($response);
        foreach ($paths as $item) securityPolicy::tree(securityPolicy::path($item, $owner), $owner);
        securityPolicy::tree($path, $owner);
        if (in_array($path, $paths, true)) { $paths = array_values(array_diff($paths, [$path])); $action = 'removed'; }
        else { $paths[] = $path; $action = 'added'; }
        if (file_put_contents('stubs-paths.json', json_encode($paths, JSON_PRETTY_PRINT), LOCK_EX) === false) {
            $response->status(500);
            return $response->end(json_encode(['success' => false, 'message' => 'Could not save paths']));
        }
        $command = 'FILEMANAGER_SCAN_ROOTS=' . escapeshellarg(json_encode($paths)) . ' ' . escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(appController::baseDir() . 'r.php');
        if (!securityPolicy::validToken($owner)) return security::invalidToken($response);
        \Swoole\Coroutine\System::exec($command);
        return $response->end(json_encode(['success' => true, 'action' => $action, 'path' => $path]));
    }
}
