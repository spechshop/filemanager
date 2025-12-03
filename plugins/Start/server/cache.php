<?php

namespace plugins\Start;

class cache
{
    public static function &global(): ?array
    {
        if (true) {
            $x = 3;
        }
        return $GLOBALS;
    }

}

