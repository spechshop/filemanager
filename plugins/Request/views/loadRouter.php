<?php

namespace plugins\Request;
class loadRouter
{
    public static function disableBrowserCache($response): void
    {
        // Evita tanto o cache local do navegador quanto caches intermediários.
        // Não usamos Clear-Site-Data porque ele também apagaria dados da sessão.
        $response->header('Cache-Control', 'no-store, no-cache, must-revalidate, max-age=0, private');
        $response->header('CDN-Cache-Control', 'no-store');
        $response->header('Surrogate-Control', 'no-store');
        $response->header('Pragma', 'no-cache');
        $response->header('Expires', 'Thu, 01 Jan 1970 00:00:00 GMT');
    }

    public static function view($path, $response): ?array
    {
        self::disableBrowserCache($response);
        $checkRoute = checkRoute::check($path, $response);
        if ($checkRoute['break']) return ['break' => true];
        return ['break' => false];
    }
}
