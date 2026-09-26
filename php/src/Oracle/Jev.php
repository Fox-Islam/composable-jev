<?php

declare(strict_types=1);

namespace Phox\ComposableJev\Oracle;

use Phox\ComposableJev\Answer;
use Phox\ComposableJev\Node;
use Phox\ComposableJev\Numbers;
use Phox\TypeSafe\Client;
use Phox\TypeSafe\Questions\Choice;
use Phox\TypeSafe\Questions\Noul;
use Phox\TypeSafe\Questions\Question;
use Phox\TypeSafe\Questions\Score;
use RuntimeException;

/**
 * Asks Jev through TypeSafe's System One, with the PHP SDK, which sends a float with its `.0`
 * and retries what an overloaded provider refuses. Answers are cached on disk by request body, so
 * a rerun costs only what changed; the key is the same in every implementation, so they share a
 * cache.
 *
 * Batching asks a level's nodes in one request, their inputs merged into one state: the fixed
 * part of a call is paid once, not once a node. Over the adders, xor, half-adder and ticket
 * triage, with every node asked of Jev, batched and separate answers fell on the same side of 0.5
 * for 2,722 of 2,731 nodes, batched was right as often or more, and it used 26 to 39% fewer
 * tokens in about half the requests.
 */
final class Jev implements Oracle
{
    public int $calls = 0;

    public int $cached = 0;

    public int $tokens = 0;

    public function __construct(
        private readonly Client $client,
        private readonly bool $batch = true,
        private readonly ?string $cacheDir = null,
        private readonly string $model = 'jev-latest',
    ) {}

    /**
     * @param  string|null  $key  falls back to `TYPESAFE_API_KEY`
     */
    public static function make(?string $key = null, bool $batch = true, ?string $cacheDir = null, string $model = 'jev-latest'): self
    {
        return new self(Client::make($key), $batch, $cacheDir, $model);
    }

    public function fire(Node $node, array $state): float|Answer
    {
        return $this->ask(self::only($node, $state), ['out' => $node->question()], ['out' => $node])['out'];
    }

    public function fireMany(array $items): array
    {
        $answers = [];
        foreach (Batches::of($items) as $batch) {
            if (count($batch) === 1) {
                [$key, $node, $state] = $batch[0];
                $answers[$key] = $this->fire($node, $state);

                continue;
            }
            $state = [];
            $questions = [];
            $nodes = [];
            foreach ($batch as [$key, $node, $given]) {
                $state += self::only($node, $given);
                $questions[$key] = $node->question();
                $nodes[$key] = $node;
            }
            ksort($state);
            $answers += $this->ask($state, $questions, $nodes);
        }

        return $answers;
    }

    public function batching(): bool
    {
        return $this->batch;
    }

    /**
     * Written aside and moved into place, so a reader never meets half a file.
     *
     * @param  array<string, mixed>  $entry
     */
    private static function save(string $path, array $entry): void
    {
        if (! is_dir(dirname($path))) {
            mkdir(dirname($path), recursive: true);
        }
        $aside = $path . '.' . bin2hex(random_bytes(8)) . '.tmp';
        file_put_contents($aside, json_encode($entry, JSON_THROW_ON_ERROR));
        rename($aside, $path);
    }

    /**
     * @param  array<string, mixed>  $state
     * @return array<string, mixed>
     */
    private static function only(Node $node, array $state): array
    {
        $only = [];
        foreach ($node->reads as $signal) {
            $only[$signal] = $state[$signal];
        }

        return $only;
    }

    private static function question(Node $node): Question
    {
        return match ($node->type) {
            'choice' => Choice::between($node->options)->instructions($node->instructions),
            'score' => Score::rubric($node->options)->instructions($node->instructions),
            default => Noul::ask($node->instructions)->yes($node->yes)->no($node->no),
        };
    }

    /**
     * An answer as it is cached. A score's probabilities are keyed "0", "1"…, which PHP decodes to
     * a list and would write back as one; the other implementations read the same cache.
     */
    private static function kept(mixed $answer): mixed
    {
        foreach (['probabilities', 'legend'] as $field) {
            if (is_array($answer) && is_array($answer[$field] ?? null)) {
                $answer[$field] = (object) $answer[$field];
            }
        }

        return $answer;
    }

    /**
     * An answer as the API sends it: a noul's probability, or a choice's pick or a score with a
     * probability for each option or level, which come in no set order and are put in the node's.
     */
    private static function read(Node $node, mixed $answer, string $key): float|Answer
    {
        // A cache written before choices and scores holds a noul's probability alone.
        if ($node->type === 'noul' && (is_int($answer) || is_float($answer))) {
            return (float) $answer;
        }
        if (! is_array($answer) || ! isset($answer[$node->type])) {
            throw new RuntimeException("TypeSafe gave no {$node->type} answer to {$key}");
        }
        if ($node->type === 'noul') {
            return (float) $answer['noul'];
        }
        $parts = [];
        foreach (array_keys($node->options) as $part) {
            $parts[(string) $part] = (float) ($answer['probabilities'][(string) $part] ?? 0.0);
        }

        return new Answer($node->type === 'choice' ? (string) $answer['choice'] : (float) $answer['score'], $parts);
    }

    /**
     * One request, or its cached answers.
     *
     * @param  array<string, mixed>  $state
     * @param  array<string, array<string, mixed>>  $questions
     * @param  array<string, Node>  $nodes  the node each question is, by key
     * @return array<string, float|Answer>
     */
    private function ask(array $state, array $questions, array $nodes): array
    {
        $body = ['model' => $this->model, 'state' => $state, 'questions' => $questions];
        $path = $this->path($body);
        if ($path !== null && is_file($path)) {
            $this->cached++;
            $raw = json_decode((string) file_get_contents($path), true, flags: JSON_THROW_ON_ERROR)['answers'];
        } else {
            $request = $this->client->systemOne()->state($state)->model($this->model);
            foreach ($nodes as $key => $node) {
                $request->ask($key, self::question($node));
            }
            $result = $request->send()->toArray();
            $raw = [];
            foreach (array_keys($questions) as $key) {
                $raw[$key] = $result['answers'][$key] ?? throw new RuntimeException("TypeSafe gave no answer to {$key}");
            }
            $this->calls++;
            $this->tokens += (int) ($result['usage']['input_tokens'] ?? 0) + (int) ($result['usage']['output_tokens'] ?? 0);
            if ($path !== null) {
                self::save($path, ['request' => $body, 'model' => $result['model'] ?? null, 'answers' => array_map(self::kept(...), $raw)]);
            }
        }

        return array_map(fn (string $key) => self::read($nodes[$key], $raw[$key], $key), array_combine(array_keys($questions), array_keys($questions)));
    }

    /**
     * @param  array<string, mixed>  $body
     */
    private function path(array $body): ?string
    {
        return $this->cacheDir === null ? null : $this->cacheDir . '/' . hash('sha256', Numbers::canonical($body)) . '.json';
    }
}
