<?php

namespace plugins\Utils\cache;
use plugins\Request\template;

class bufferPages
{
    public static function get($namePage, $dir): ?string
    {
        $addressPage = "$dir/Request/pages/$namePage.html";
        if (!file_exists($addressPage)) return 'what?';
        return template::prepare(file_get_contents($addressPage));
    }
}