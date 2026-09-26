<?php

declare(strict_types=1);

namespace Phox\ComposableJev\Oracle;

use InvalidArgumentException;
use Phox\ComposableJev\Node;

/**
 * A sigmoid of each node's own rule, for tests and for trying a graph before paying for it. A
 * node with no rule is left to Jev.
 */
final class Simulator implements Oracle
{
    public int $calls = 0;

    public function __construct(private readonly float $sharpness = 20.0) {}

    public function fire(Node $node, array $state): float
    {
        if ($node->margin === null) {
            throw new InvalidArgumentException("{$node->name} has no rule, so only Jev can answer it");
        }
        $this->calls++;

        return 1 / (1 + exp(-$this->sharpness * ($node->margin)($state)));
    }

    public function fireMany(array $items): array
    {
        $answers = [];
        foreach ($items as [$key, $node, $state]) {
            $answers[$key] = $this->fire($node, $state);
        }

        return $answers;
    }

    public function batching(): bool
    {
        return false;
    }
}
