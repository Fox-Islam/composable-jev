import { strict as assert } from 'node:assert';
import { describe, it } from 'node:test';
import { Graph } from '../src/graph.js';
import { Simulator } from '../src/oracle/simulator.js';
import { Presets } from '../src/presets.js';
import { Rules } from '../src/rules.js';
import { FakeTypeSafe } from './helpers/fakeTypeSafe.js';

describe('rules in code', () => {
    it('works out each rule as a probability that is over 0.5 where its logic is true', () => {
        const state = { a: 0.9, b: 0.3, c: 0.6 };
        const value = (node: ReturnType<typeof Rules.all>) => Math.round(node.compute!(state) * 1e6) / 1e6;

        assert.equal(value(Rules.all('x', ['a', 'b', 'c'])), 0.3);
        assert.equal(value(Rules.any('x', ['a', 'b', 'c'])), 0.9);
        assert.equal(value(Rules.none('x', ['b'])), 0.7);
        assert.equal(value(Rules.atLeast('x', 2, ['a', 'b', 'c'])), 0.6);
        assert.equal(value(Rules.average('x', { a: 3, b: 1 })), 0.75);
    });

    it('never sends a rule to Jev', async () => {
        const graph = Presets.get('ticket triage');
        const fake = FakeTypeSafe.forFixture(graph);
        const row = await graph.run(fake.jev(), {}, 1);

        assert.equal(fake.requests.length, 1);
        assert.deepEqual(Object.keys(fake.requests[0]!.body.questions).sort(), ['angry', 'enterprise', 'refund']);
        assert.equal(row.value('escalate'), Math.min(row.value('angry'), Math.max(row.value('refund'), row.value('enterprise'))));
    });

    it('runs a function of your own, which a spec cannot hold', async () => {
        const graph = Graph.make().input('a', 'b').rule('both', s => Number(s['a']) * Number(s['b']), 'a', 'b').none('neither', 'a', 'b');

        assert.equal((await graph.run(new Simulator(), { a: 1, b: 1 })).value('both'), 1);
        assert.equal((await graph.run(new Simulator(), { a: 0, b: 0 })).value('neither'), 1);
        assert.throws(() => graph.spec(), /which a spec cannot hold/);
    });

    it('refuses a rule it cannot work out', () => {
        assert.throws(() => Rules.atLeast('x', 3, ['a', 'b']), /no more than the 2 signals/);
        assert.throws(() => Rules.average('x', { a: -1, b: 2 }), /none below 0/);
        assert.throws(() => Rules.all('x', []), /read at least one signal/);
        assert.throws(() => Graph.fromSpec({ inputs: [], texts: ['hi'], layers: [[{ name: 'x', preset: 'all', reads: ['text'] }]] }), /works on numbers/);
    });
});
