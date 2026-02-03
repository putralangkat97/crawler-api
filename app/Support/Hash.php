<?php

namespace App\Support;

class Hash
{
    public static function sha256(string $value): string
    {
        return hash('sha256', $value);
    }
}
