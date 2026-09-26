<?php

declare(strict_types=1);

namespace Tests\Fakes;

use Closure;
use GuzzleHttp\Psr7\Response;
use Phox\ComposableJev\Graph;
use Phox\ComposableJev\Numbers;
use Phox\ComposableJev\Oracle\Jev;
use Phox\TypeSafe\Client;
use Phox\TypeSafe\Contracts\Transport;
use Phox\TypeSafe\Retry\RetryPolicy;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

/**
 * TypeSafe's System One, as the SDK's transport, answered by a closure over each question's state,
 * and every request kept with the body as it went over the wire.
 */
final class FakeTypeSafe implements Transport
{
    /** @var list<array{raw: string, body: array<string, mixed>}> */
    public array $requests = [];

    /**
     * @param  Closure(array<string, mixed>, array<string, mixed>): float  $answer  state, question
     */
    public function __construct(private Closure $answer) {}

    public static function answering(float $p): self
    {
        return new self(fn () => $p);
    }

    /**
     * Each question answered 0.99 or 0.01 by the rule of the node in `$graph` it came from.
     */
    public static function byRule(Graph $graph): self
    {
        $nodes = [];
        foreach ($graph->nodes() as $node) {
            $nodes[$node->instructions] = $node;
        }

        return new self(function (array $state, array $question) use ($nodes): float {
            $node = $nodes[$question['instructions']];

            return $node->expected(array_intersect_key($state, array_flip($node->reads))) ? 0.99 : 0.01;
        });
    }

    /**
     * As `spec/fixtures.json` is answered in every implementation: 0.99 or 0.01 by the node's
     * rule, and for a question with none an answer that follows its numbers, so a loop drifts and
     * settles as a leak does.
     */
    public static function forFixture(Graph $graph): self
    {
        $nodes = [];
        foreach ($graph->nodes() as $node) {
            $nodes[$node->instructions] = $node;
        }

        return new self(function (array $state, array $question) use ($nodes): float {
            $node = $nodes[$question['instructions']];

            return match ($node->expected(array_intersect_key($state, array_flip($node->reads)))) {
                true => 0.99,
                false => 0.01,
                null => self::drifting(array_intersect_key($state, array_flip($node->reads))),
            };
        });
    }

    /**
     * A noul answered `$p`; a choice or a score with `$p` on its first option or its top level and
     * the rest shared among the others, picking the likeliest option, first where two tie, and
     * scoring the expected level to two places.
     *
     * @param  array<string, mixed>  $question
     * @return array<string, mixed>
     */
    public static function answer(array $question, float $p): array
    {
        if ($question['type'] === 'noul') {
            return ['type' => 'noul', 'noul' => $p];
        }
        $options = $question['type'] === 'choice' ? array_keys($question['criteria']) : array_map('strval', array_keys($question['criteria']));
        $favoured = $question['type'] === 'choice' ? 0 : count($options) - 1;
        $rest = Numbers::round((1 - $p) / (count($options) - 1), 3);
        $probabilities = [];
        foreach ($options as $i => $option) {
            $probabilities[$option] = $i === $favoured ? $p : $rest;
        }
        if ($question['type'] === 'choice') {
            return ['type' => 'choice', 'choice' => array_search(max($probabilities), $probabilities, true), 'probabilities' => $probabilities];
        }
        $expected = 0.0;
        foreach (array_values($probabilities) as $level => $chance) {
            $expected += $level * $chance;
        }

        return ['type' => 'score', 'score' => Numbers::round($expected, 2), 'probabilities' => $probabilities];
    }

    /**
     * 0.2 plus 0.6 times the mean of the state's numbers, each held to 0 to 1, or 0.7 for a state
     * with none.
     *
     * @param  array<string, mixed>  $state
     */
    public static function drifting(array $state): float
    {
        $numbers = array_map(fn ($v) => max(0.0, min(1.0, (float) $v)), array_filter($state, fn ($v) => is_int($v) || is_float($v)));

        return $numbers === [] ? 0.7 : Numbers::round(0.2 + 0.6 * array_sum($numbers) / count($numbers), 3);
    }

    public function jev(bool $batch = true, ?string $cacheDir = null): Jev
    {
        return new Jev(Client::make('ts-key')->transport($this)->retry(RetryPolicy::none()), $batch, $cacheDir);
    }

    public function send(RequestInterface $request, float $timeout): ResponseInterface
    {
        $raw = (string) $request->getBody();
        $body = json_decode($raw, true);
        $this->requests[] = ['raw' => $raw, 'body' => $body];
        $answers = [];
        foreach ($body['questions'] as $key => $question) {
            $answers[$key] = self::answer($question, ($this->answer)($body['state'], $question));
        }

        return new Response(200, ['Content-Type' => 'application/json'], json_encode([
            'model' => 'jev-test', 'answers' => $answers,
            'usage' => ['input_tokens' => 300 + 60 * count($answers), 'output_tokens' => 20 * count($answers)],
        ]));
    }
}
