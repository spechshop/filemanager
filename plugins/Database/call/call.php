<?php
namespace plugins\Database;
class call
{
    public static function data(): ?array
    {
        return \plugins\Request\securityPolicy::tokens();
    }
}
