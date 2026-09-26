<?php

declare(strict_types=1);

namespace Phox\ComposableJev\Oracle;

use Phox\ComposableJev\Answer;
use Phox\ComposableJev\Node;

/**
 * What answers a node's question: Jev, or the simulator.
 */
interface Oracle
{
    /**
     * A noul's probability, or a choice's or a score's answer.
     *
     * @param  array<string, mixed>  $state  the signals the node reads
     */
    public function fire(Node $node, array $state): float|Answer;

    /**
     * Several nodes at once, keyed as given.
     *
     * @param  list<array{string, Node, array<string, mixed>}>  $items
     * @return array<string, float|Answer>
     */
    public function fireMany(array $items): array;

    /**
     * Whether a level's nodes should go to fireMany in one call instead of fire each.
     */
    public function batching(): bool;
}
