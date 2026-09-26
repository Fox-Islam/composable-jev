import { strict as assert } from 'node:assert';
import { mkdtempSync, readFileSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { join } from 'node:path';
import { describe, it } from 'node:test';
import { Gates } from '../src/gates.js';
import { Graph } from '../src/graph.js';
import { batches } from '../src/oracle/batches.js';
import { Simulator } from '../src/oracle/simulator.js';
import { Presets } from '../src/presets.js';
import { FakeTypeSafe } from './helpers/fakeTypeSafe.js';

const presets = JSON.parse(readFileSync(new URL('../../../data/presets.json', import.meta.url), 'utf8')) as Record<string, { graph: unknown }>;

describe('a graph', () => {
    it('builds xor fluently, which one node cannot compute', async () => {
        const xor = Graph.make().input('a', 'b').gate('or', 'or', 'a', 'b').gate('nand', 'nand', 'a', 'b').gate('xor', 'and', 'or', 'nand');
        for (const [a, b, want] of [[0, 0, false], [0, 1, true], [1, 0, true], [1, 1, false]] as const) {
            assert.equal((await xor.run(new Simulator(), { a, b })).yes('xor'), want);
        }
    });

    it('adds', async () => {
        const row = await Presets.adder(8).run(new Simulator(), Presets.sums(8, [[100, 55]])[0]);
        assert.equal(row.number('s'), 155);
    });

    it('builds the adders in data/presets.json', () => {
        assert.deepEqual(Presets.adder(4).spec(), presets['4-bit adder']!.graph);
        assert.deepEqual(Presets.adder(8).spec(), presets['8-bit adder']!.graph);
    });

    it('holds every preset to its spec', () => {
        for (const name of Presets.names()) {
            assert.deepEqual(Presets.get(name).spec(), presets[name]!.graph, name);
        }
    });

    it('refuses what it cannot build', () => {
        assert.throws(() => Graph.make().input('a').gate('b', 'nor', 'a'), /two or more signals/);
        assert.throws(() => Graph.make().input('a', 'a'), /used twice/);
        assert.throws(() => Graph.make().input('A1'), /lowercase letter/);
        assert.throws(() => Graph.make().input('a').gate('b', 'buffer', 'c').plan(), /which is not an input or a node/);
        assert.throws(() => Presets.get('nope'), /is not a preset/);
        assert.rejects(Presets.get('decay').run(new Simulator()), /only Jev can answer it/);
    });

    it('keeps apart states that call different signals by one name', () => {
        const node = Gates.gate('buffer', ['a']);
        const items = [['x', node, { a: 1, b: 0 }], ['y', node, { a: 0, b: 1 }], ['z', node, { a: 1, c: 1 }]] as const;
        assert.deepEqual(batches(items.map(i => [i[0], i[1], { ...i[2] }])).map(b => b.map(i => i[0])), [['x', 'z'], ['y']]);
    });
});

describe('the context', () => {
    it('is text or a JSON object, from make() or context()', async () => {
        const fake = FakeTypeSafe.answering(0.9);
        const ticket = { ticket: 'Refund me', customer: { plan: 'enterprise', seats: 400 } };
        await Graph.make(ticket).ask('refund', 'Is the customer asking for money back?', Graph.CONTEXT).run(fake.jev());

        assert.deepEqual(fake.requests[0]!.body.state, { context: ticket });
        assert.deepEqual(Graph.make('one').context('two').contexts(), ['one', 'two']);
        assert.deepEqual(Graph.make().context({}).contexts(), []);
    });

    it('is also called text', () => {
        const graph = Graph.make().text('one').ask('q', 'Is it?', 'text');
        const old = Graph.fromSpec({ inputs: [], texts: ['one'], layers: [[{ name: 'q', preset: 'custom', reads: ['text'], instructions: 'Is it?' }]] });

        assert.equal(Graph.TEXT, Graph.CONTEXT);
        assert.deepEqual(graph.nodes()['q']!.reads, ['context']);
        assert.deepEqual(graph.texts(), ['one']);
        assert.deepEqual(old.spec(), graph.spec());
        assert.throws(() => Graph.make().input('text'), /used twice/);
    });
});

describe('asking Jev', () => {
    it('sends on/off inputs as 1.0 and whole seconds as whole numbers', async () => {
        const fake = FakeTypeSafe.answering(0.1);
        const graph = Presets.get('delay timer').input('a').gate('b', 'buffer', 'a');
        await graph.run(fake.jev({ batch: false }), { a: 1 }, 0, 32);
        const raw = fake.requests.map(r => r.raw).join('\n');

        assert.match(raw, /"a":1\.0/);
        assert.match(raw, /"time":32[,}]/);
        assert.doesNotMatch(raw, /32\.0/);
    });

    it('asks a batched adder one request a level, for the same sum', async () => {
        const adder = Presets.adder(4);
        const inputs = Presets.sums(4, [[7, 5]])[0];
        const apart = FakeTypeSafe.forFixture(adder);
        const together = FakeTypeSafe.forFixture(adder);
        const one = await adder.run(apart.jev({ batch: false }), inputs);
        const many = await adder.run(together.jev(), inputs);

        assert.deepEqual(many.signals, one.signals);
        assert.equal(many.number('s'), 12);
        assert.deepEqual([apart.requests.length, together.requests.length], [8, 4]);
    });

    it('caches an answer, and counts what it spent', async () => {
        const fake = FakeTypeSafe.answering(0.9);
        const jev = fake.jev({ cacheDir: mkdtempSync(join(tmpdir(), 'cjev-')) });
        const node = Gates.gate('buffer', ['a']);

        assert.equal(await jev.fire(node, { a: 0.7, unused: 1 }), 0.9);
        assert.equal(await jev.fire(node, { a: 0.7 }), 0.9);
        assert.deepEqual([jev.calls, jev.cached, jev.tokens], [1, 1, 380]);
        assert.deepEqual(fake.requests[0]!.body.state, { a: 0.7 });
    });
});
