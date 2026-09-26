<?php

declare(strict_types=1);

namespace Phox\ComposableJev;

/**
 * The files in `data/` at the package root. They are not PHP: the JavaScript and Python
 * packages read the same files, so a gate's wording and a preset exist once.
 */
final class Data
{
    /** @var array<string, array<string, mixed>> */
    private static array $read = [];

    /**
     * @return array<string, mixed>
     */
    public static function read(string $file): array
    {
        return self::$read[$file] ??= json_decode(
            (string) file_get_contents(dirname(__DIR__, 2) . "/data/{$file}"),
            true,
            flags: JSON_THROW_ON_ERROR,
        );
    }
}
