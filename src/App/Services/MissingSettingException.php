<?php

namespace Keel\App\Services;

class MissingSettingException extends \RuntimeException
{
    public static function forKey(string $key): self
    {
        return new self("Required setting \"{$key}\" is not configured.");
    }

    public static function forType(string $key, string $expected, string $actual): self
    {
        return new self("Setting \"{$key}\" is declared as {$actual}, not {$expected}.");
    }
}
