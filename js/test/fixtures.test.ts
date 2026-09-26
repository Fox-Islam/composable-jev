import { strict as assert } from 'node:assert';
import { readFileSync } from 'node:fs';
import { describe, it } from 'node:test';
import { Gates } from '../src/gates.js';
import { Graph, type GraphSpec } from '../src/graph.js';
import type { NodeSpec } from '../src/node.js';
import { canonicalText, round, seconds, text, type State, type Value } from '../src/numbers.js';
import type { Oracle } from '../src/oracle/oracle.js';
import { Simulator } from '../src/oracle/simulator.js';
import { Plan } from '../src/plan.js';
import { Presets } from '../src/presets.js';
import type { Row } from '../src/row.js';
import { Ticker } from '../src/ticker.js';
import { FakeTypeSafe } from './helpers/fakeTypeSafe.js';

interface FixtureRow {
    inputs: State;
    signals: State;
    ideal: Record<string, number | null>;
    unsettled: string[];
    fires: number;
    asked: string[];
}

interface Run {
    name: string;
    preset?: string;
    graph?: GraphSpec;
    oracle: 'simulator' | 'jev';
    batch?: boolean;
    mode: 'truth' | 'ticks';
    every?: number;
    ticks?: { inputs: Record<string, number>; context: number }[];
    rows: FixtureRow[];
    requests?: string[];
}

interface Fixtures {
    numbers: { round: [number, number, number][]; seconds: [number, number][]; text: [number, string][] };
    questions: Record<string, unknown>;
    plans: { reads: Record<string, string[]>; levels: string[][][]; looping: string[] }[];
    rules: { spec: NodeSpec; describe: string; values: number[]; states: State[] }[];
    runs: Run[];
}

/** Written by the PHP implementation; `composer fixtures` writes it again */
const fixtures = JSON.parse(readFileSync(new URL('../../../spec/fixtures.json', import.meta.url), 'utf8')) as Fixtures;

function close(actual: Value | undefined, expected: Value | undefined, where: string): void {
    if (typeof actual === 'number' && typeof expected === 'number') {
        assert.ok(Math.abs(actual - expected) < 1e-12, `${where}: ${actual} is not ${expected}`);
    } else {
        assert.deepEqual(actual, expected, where);
    }
}

function compare(rows: Row[], asked: string[][], run: Run): void {
    assert.equal(rows.length, run.rows.length, run.name);
    run.rows.forEach((want, i) => {
        const row = rows[i]!;
        const where = `${run.name}, row ${i}`;
        assert.deepEqual(Object.keys(row.signals).sort(), Object.keys(want.signals).sort(), where);
        for (const key of Object.keys(want.signals)) {
            close(row.signals[key], want.signals[key], `${where}, ${key}`);
        }
        for (const key of Object.keys(want.inputs)) {
            close(row.inputs[key], want.inputs[key], `${where}, input ${key}`);
        }
        assert.deepEqual(row.ideal, want.ideal, where);
        assert.deepEqual(row.unsettled, want.unsettled, where);
        assert.equal(row.fires, want.fires, where);
        assert.deepEqual(asked[i] ?? [], want.asked, where);
    });
}

describe('the fixtures the PHP implementation wrote', () => {
    it('rounds, and shows numbers, the same way', () => {
        for (const [value, places, want] of fixtures.numbers.round) {
            assert.equal(round(value, places), want, `${value} to ${places}`);
        }
        for (const [value, want] of fixtures.numbers.seconds) {
            assert.equal(seconds(value), want, String(value));
        }
        for (const [value, want] of fixtures.numbers.text) {
            assert.equal(text(value), want, String(value));
        }
    });

    it('words every gate the same way', () => {
        for (const [key, want] of Object.entries(fixtures.questions)) {
            const [gate, reads] = key.split(' ');
            const node = gate === 'weighted' ? Gates.weighted([0.6, 1.0, -2.5], 1.0)
                : gate === 'choice' ? Gates.choice('x', 'Which?', ['context'], { one: 'The first', two: 'The second' })
                : gate === 'score' ? Gates.score('x', 'How much?', ['context'], ['None', 'Some', 'All'])
                : Gates.gate(gate!, reads!.split(','));
            assert.deepEqual(node.question(), want, key);
        }
    });

    it('works out and describes every rule the same way', () => {
        for (const want of fixtures.rules) {
            const graph = Graph.fromSpec({ inputs: ['a', 'b', 'c'], layers: [[want.spec]] });
            const node = graph.nodes()[want.spec.name]!;
            assert.equal(node.instructions, want.describe);
            assert.deepEqual(node.spec(), want.spec);
            want.states.forEach((state, i) => close(node.compute!(state), want.values[i], `${want.describe}, state ${i}`));
        }
    });

    it('orders the nodes of a graph the same way', () => {
        for (const want of fixtures.plans) {
            const plan = new Plan(want.reads);
            assert.deepEqual([plan.levels, plan.looping], [want.levels, want.looping], JSON.stringify(want.reads));
        }
    });

    for (const run of fixtures.runs) {
        it(`gives the same rows and requests for ${run.name}`, async () => {
            const graph = run.graph === undefined ? Presets.get(run.preset!) : Graph.fromSpec(run.graph);
            const fake = run.oracle === 'jev' ? FakeTypeSafe.forFixture(graph) : null;
            const oracle: Oracle = fake === null ? new Simulator() : fake.jev({ batch: run.batch ?? true });
            const asked: string[][] = [];
            let rows: Row[];
            if (run.mode === 'truth') {
                rows = await graph.truthTable(oracle, null, (i, kind, node) => {
                    if (kind === 'fired') {
                        (asked[i] ??= []).push(node);
                    }
                });
            } else {
                const ticker = new Ticker(graph, oracle, run.every ?? 1);
                for (const [i, tick] of (run.ticks ?? []).entries()) {
                    await ticker.tick(tick.inputs, tick.context, (kind, node) => {
                        if (kind === 'fired') {
                            (asked[i] ??= []).push(node);
                        }
                    });
                }
                rows = ticker.rows;
            }

            compare(rows, asked, run);
            if (fake !== null) {
                assert.deepEqual(fake.requests.map(r => canonicalText(r.raw)), run.requests, run.name);
            }
        });
    }
});
