<?php

declare(strict_types=1);

namespace Phox\ComposableJev;

/**
 * Numbers as Jev is shown them, the same in every implementation.
 */
final class Numbers
{
    /**
     * Half rounds up. Written out, since PHP's round() pre-rounds, Python's rounds half to even
     * and JavaScript has no decimal rounding: 32.25 to one place is 32.3 in all three this way.
     */
    public static function round(float $value, int $places): float
    {
        $scale = 10 ** $places;

        return floor($value * $scale + 0.5) / $scale;
    }

    /**
     * Whole seconds as an int, as Jev is shown them: sent as 32.0, Jev says it ends in the digit 0
     * and misses multiples of 10 it gets right as 32.
     */
    public static function seconds(float|int $value): float|int
    {
        $tenths = self::round((float) $value, 1);

        return floor($tenths) === $tenths ? (int) $tenths : $tenths;
    }

    /**
     * A number as a question shows it: 1 and not 1.0, which Jev reads literally.
     */
    public static function text(float $n): string
    {
        return (string) $n;
    }

    /**
     * A body as its cache key hashes it and as tests compare it across implementations: keys
     * sorted, no whitespace, a float keeping its decimal, slashes and Unicode unescaped.
     */
    public static function canonical(mixed $value): string
    {
        return json_encode(self::sorted($value), JSON_PRESERVE_ZERO_FRACTION | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    }

    private static function sorted(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }
        if (! array_is_list($value)) {
            ksort($value, SORT_STRING);
        }

        return array_map(self::sorted(...), $value);
    }
}
