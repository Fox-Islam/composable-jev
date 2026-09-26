import { strict as assert } from 'node:assert';
import { readFileSync } from 'node:fs';
import { describe, it } from 'node:test';
import { Graph, type GraphSpec } from '../src/graph.js';
import { Simulator } from '../src/oracle/simulator.js';
import { FakeTypeSafe } from './helpers/fakeTypeSafe.js';

const routing = JSON.parse(readFileSync(new URL('../../../spec/routing.json', import.meta.url), 'utf8')) as GraphSpec;

describe('a choice and a score', () => {
    it('are asked with the questions of their level, and their parts are read', async () => {
        const graph = Graph.fromSpec(routing);
        const fake = FakeTypeSafe.forFixture(graph);
        const row = await graph.run(fake.jev(), {}, 1);
        const first = fake.requests[0]!.body.questions;

        assert.equal(fake.requests.length, 2);
        assert.deepEqual([first['team']!.type, first['severity']!.type, first['angry']!.type], ['choice', 'score', 'noul']);
        assert.equal(row.choice('team'), 'billing');
        assert.deepEqual(row.probabilities('team'), { billing: 0.7, bugs: 0.1, sales: 0.1, other: 0.1 });
        assert.equal(row.value('serious'), 0.075 + 0.7);
        assert.deepEqual(fake.requests[1]!.body.state, { serious: 0.775, team: 'billing', context: graph.contexts()[1]! });
    });

    it('puts the probabilities TypeSafe sends in any order into the order of the options', async () => {
        const graph = Graph.make('Refund me').choose('team', 'Which team?', Graph.CONTEXT, { billing: 'Money', bugs: 'Broken' });
        const jev = new FakeTypeSafe(() => 0).jev();
        (jev as unknown as { client: { systemOne: () => Promise<unknown> } }).client.systemOne = async () => ({
            model: 'jev-test', usage: { input_tokens: 1, output_tokens: 1 },
            answers: { out: { type: 'choice', choice: 'billing', probabilities: { bugs: 0.2, billing: 0.8 } } },
        });
        const row = await graph.run(jev);

        assert.deepEqual(Object.entries(row.probabilities('team')), [['billing', 0.8], ['bugs', 0.2]]);
    });

    it('refuses a rule reading the option a choice picked', () => {
        assert.throws(() => Graph.make('hi').choose('pick', 'Which?', Graph.CONTEXT, { one: 'A', two: 'B' }).all('x', 'pick').plan(),
            /read one option's probability, such as pick\.one/);
    });

    it('refuses what the simulator cannot answer, or cannot be built', async () => {
        await assert.rejects(Graph.make().input('a').score('s', 'How much?', 'a', ['None', 'All']).run(new Simulator()), /only Jev can answer it/);
        assert.throws(() => Graph.make().input('a').choose('p', 'Which?', 'a', { only: 'One' }), /two or more options/);
        assert.throws(() => Graph.make().input('a').score('p', 'How much?', 'a', ['One']), /two or more levels/);
    });
});
