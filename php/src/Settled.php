<?php

declare(strict_types=1);

namespace Phox\ComposableJev;

/**
 * Every signal once a step has settled, the loops that had not, and how many answers it took.
 */
final readonly class Settled
{
    /**
     * @param  array<string, mixed>  $signals  inputs, nodes, and the parts of a choice or a score
     * @param  list<list<string>>  $unsettled  loops with a node that crossed 0.5 in the step
     */
    public function __construct(
        public array $signals,
        public array $unsettled,
        public int $fires,
    ) {}
}
