<?php
namespace plugins\Request;
class checkRoute
{
    public static function check($path, $response): ?array
    {
        if (!str_contains($path, '.')) return ['break' => false];
        $base = rtrim(appController::baseDir(), '/');
        $real = realpath($base . $path);
        $extension = strtolower(pathinfo($path, PATHINFO_EXTENSION));
        $allowed = false;
        foreach (['css', 'js', 'img'] as $directory) if ($real !== false && securityPolicy::within($real, $base . '/' . $directory)) $allowed = true;
        if (!$allowed || str_contains($path, '..') || !is_file($real)
            || !isset($GLOBALS['interface']['allowExtensions'][$extension])) {
            $response->status(404); $response->end(); return ['break' => true];
        }
        $response->header('Content-Type', $GLOBALS['interface']['allowExtensions'][$extension]);
        $response->end(file_get_contents($real));
        return ['break' => true];
    }
}
