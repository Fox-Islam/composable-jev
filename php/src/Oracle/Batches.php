<?php

declare(strict_types=1);

namespace Phox\ComposableJev\Oracle;

use Phox\ComposableJev\Node;
use Phox\ComposableJev\Simulation;

/**
 * Splits nodes into batches whose states agree wherever they share a key, so one state holds a
 * batch. Nodes that call different signals by the same name go in different batches.
 */
final class Batches
{
    /**
     * @param  list<array{string, Node, array<string, mixed>}>  $items
     * @return list<list<array{string, Node, array<string, mixed>}>>
     */
    public static function of(array $items): array
    {
        $batches = [];
        foreach ($items as $item) {
            $placed = false;
            foreach ($batches as $i => [$merged]) {
                if (self::agree($merged, $item[2])) {
                    $batches[$i] = [$merged + $item[2], [...$batches[$i][1], $item]];
                    $placed = true;
                    break;
                }
            }
            $batches = $placed ? $batches : [...$batches, [$item[2], [$item]]];
        }

        return array_map(fn (array $b) => $b[1], $batches);
    }

    /**
     * @param  array<string, mixed>  $merged
     * @param  array<string, mixed>  $state
     */
    private static function agree(array $merged, array $state): bool
    {
        foreach ($state as $key => $value) {
            if (array_key_exists($key, $merged) && ! Simulation::same($merged[$key], $value)) {
                return false;
            }
        }

        return true;
    }
}
