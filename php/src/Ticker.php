<?php

declare(strict_types=1);

namespace Phox\ComposableJev;

use Closure;
use Phox\ComposableJev\Oracle\Oracle;

/**
 * Steps a graph one tick at a time, each settling from where the last ended. This is where a
 * latch shows it remembers, and a node reading itself runs down.
 *
 *     $latch = Presets::get('sr latch')->ticker($jev);
 *     $latch->tick(['s' => 1]);
 *     $latch->tick(['s' => 1]);    // a loop goes round once a tick, so setting takes two
 *     $latch->tick()->yes('q');    // true: it holds
 */
final class Ticker
{
    /** @var list<Row> */
    public array $rows = [];

    public function __construct(
        private readonly Graph $graph,
        private readonly Oracle $oracle,
        private readonly float $every = 1.0,
    ) {}

    /**
     * @param  array<string, mixed>  $inputs  on/off input => 0 or 1
     * @param  int  $context  which of the contexts the tick uses
     * @param  (Closure(string, string, mixed): void)|null  $onFire
     */
    public function tick(array $inputs = [], int $context = 0, ?Closure $onFire = null): Row
    {
        $last = $this->rows === [] ? null : $this->rows[count($this->rows) - 1];
        $values = $this->graph->values($inputs, $context, count($this->rows) * $this->every);
        $row = $this->graph->tick($this->oracle, $values, $last?->state(), $last?->idealState(), $onFire);
        $this->rows[] = $row;

        return $row;
    }
}
