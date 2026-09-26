<?php

declare(strict_types=1);

use Phox\ComposableJev\Answer;
use Phox\ComposableJev\Graph;
use Phox\ComposableJev\Oracle\Simulator;
use Tests\Fakes\FakeTypeSafe;
use Tests\Fixtures;

it('asks a choice and a score with the questions of their level, and reads their parts', function (): void {
    $graph = Graph::fromSpec(Fixtures::routing());
    $fake = FakeTypeSafe::forFixture($graph);
    $row = $graph->run($fake->jev(), context: 1);
    $first = $fake->requests[0]['body']['questions'];

    expect(count($fake->requests))->toBe(2)
        ->and([$first['team']['type'], $first['severity']['type'], $first['angry']['type']])->toBe(['choice', 'score', 'noul'])
        ->and($first['severity']['criteria'][4])->toBe('It stops the customer working')
        ->and($row->choice('team'))->toBe('billing')
        ->and($row->probabilities('team'))->toBe(['billing' => 0.7, 'bugs' => 0.1, 'sales' => 0.1, 'other' => 0.1])
        ->and($row->value('serious'))->toBe(0.075 + 0.7)
        ->and($fake->requests[1]['body']['state'])->toEqual(['serious' => 0.775, 'team' => 'billing', 'context' => $graph->contexts()[1]]);
});

it('puts the probabilities TypeSafe sends in any order into the order of the options', function (): void {
    $fake = new FakeTypeSafe(fn () => 0.0);
    $graph = Graph::make('Refund me')->choose('team', 'Which team?', Graph::CONTEXT, ['billing' => 'Money', 'bugs' => 'Broken']);
    $jev = $fake->jev();
    $answer = (new ReflectionMethod($jev, 'read'))->invoke(null, $graph->nodes()['team'],
        ['type' => 'choice', 'choice' => 'billing', 'probabilities' => ['bugs' => 0.2, 'billing' => 0.8]], 'team');

    expect($answer)->toEqual(new Answer('billing', ['billing' => 0.8, 'bugs' => 0.2]));
});

it('asks a node reading a part after the node, and keeps its readers by part', function (): void {
    $graph = Graph::make()->input('a')
        ->choose('pick', 'Which?', 'a', ['one' => 'The first', 'two' => 'The second'])
        ->sum('first', 'pick.one')
        ->sum('second', 'pick.two');

    expect($graph->plan()->levels[0])->toBe([['pick']])
        ->and($graph->plan()->levels[1])->toEqualCanonicalizing([['first'], ['second']])
        ->and($graph->plan()->readers)->toHaveKeys(['a', 'pick.one', 'pick.two']);
});

it('refuses a rule reading the option a choice picked', function (): void {
    expect(fn () => Graph::make('hi')->choose('pick', 'Which?', Graph::CONTEXT, ['one' => 'A', 'two' => 'B'])->all('x', 'pick')->plan())
        ->toThrow(InvalidArgumentException::class, 'read one option\'s probability, such as pick.one');
});

it('refuses a choice the simulator cannot answer, or that it cannot build', function (): void {
    $graph = Graph::make()->input('a')->score('s', 'How much?', 'a', ['None', 'All']);

    expect(fn () => $graph->run(new Simulator))->toThrow(InvalidArgumentException::class, 'only Jev can answer it')
        ->and(fn () => Graph::make()->input('a')->choose('p', 'Which?', 'a', ['only' => 'One']))->toThrow(InvalidArgumentException::class, 'two or more options')
        ->and(fn () => Graph::make()->input('a')->score('p', 'How much?', 'a', ['One']))->toThrow(InvalidArgumentException::class, 'two or more levels');
});

it('reads a cached noul written before choices and scores', function (): void {
    $dir = sys_get_temp_dir() . '/cjev-' . bin2hex(random_bytes(4));
    $graph = Graph::make()->input('a')->gate('b', 'buffer', 'a');
    $jev = FakeTypeSafe::answering(0.9)->jev(cacheDir: $dir);
    $graph->run($jev, ['a' => 1]);
    $file = glob("{$dir}/*.json")[0];
    $entry = json_decode((string) file_get_contents($file), true);
    $entry['answers'] = ['out' => 0.9];
    file_put_contents($file, json_encode($entry));

    expect($graph->run($fresh = FakeTypeSafe::answering(0.1)->jev(cacheDir: $dir), ['a' => 1])->value('b'))->toBe(0.9)
        ->and($fresh->cached)->toBe(1);
});

it('caches a score\'s probabilities keyed by level, as the other implementations read them', function (): void {
    $dir = sys_get_temp_dir() . '/cjev-' . bin2hex(random_bytes(4));
    $graph = Graph::make()->text('Help')->score('s', 'How much?', 'text', ['None', 'Some', 'All']);
    $graph->run(FakeTypeSafe::answering(0.5)->jev(cacheDir: $dir));

    expect((string) file_get_contents(glob("{$dir}/*.json")[0]))->toContain('"probabilities":{"0":0.25,"1":0.25,"2":0.5}');
});
