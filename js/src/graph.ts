import { Answer } from './answer.js';
import { Gates } from './gates.js';
import { Node, type Compute, type NodeSpec } from './node.js';
import { round, seconds, type Context, type State, type Value } from './numbers.js';
import type { Oracle } from './oracle/oracle.js';
import { Plan } from './plan.js';
import { Row } from './row.js';
import { Rules } from './rules.js';
import { Simulation, type OnFire, type Settled } from './simulation.js';
import { Ticker } from './ticker.js';

/** A graph as the page edits it, and as `data/presets.json` holds one */
export interface GraphSpec {
    inputs: string[];
    contexts?: Context[];
    /** read as `contexts`, its other name */
    texts?: Context[];
    time?: boolean;
    layers: NodeSpec[][];
}

export type OnRowFire = (row: number, kind: 'firing' | 'fired', node: string, value: Value) => void;

const NAME = /^[a-z][a-z0-9_]{0,11}$/;

/** The context's other name */
const ALIAS = 'text';

/**
 * Nodes wired together, built fluently. Each node's value - a number, rounded to three places, or
 * the option a choice picked - is passed in the state of every node reading it: one question per
 * node, since questions in one request cannot see each other's answers. A node can read any signal, one after it or itself
 * included, so a graph can loop.
 *
 *     const xor = Graph.make()
 *         .input('a', 'b')
 *         .gate('or', 'or', 'a', 'b')
 *         .gate('nand', 'nand', 'a', 'b')
 *         .gate('xor', 'and', 'or', 'nand');
 *     (await xor.run(Jev.make(key), { a: 1, b: 0 })).yes('xor');
 */
export class Graph {
    /** The signal holding the context: what the questions are about, as System One's `state` is */
    static readonly CONTEXT = 'context';

    /** The same signal as CONTEXT, by its other name; a node reading `text` reads the context */
    static readonly TEXT = Graph.CONTEXT;

    static readonly TIME = 'time';

    private readonly onOff: string[] = [];

    private readonly given: Context[] = [];

    private timed = false;

    private readonly byName = new Map<string, Node>();

    private readonly starts: Record<string, number> = {};

    private columns: string[][] | null = null;

    /** @param context what the questions are about: text, or a JSON object or array */
    static make(context: Context | null = null): Graph {
        const graph = new Graph();

        return context === null ? graph : graph.context(context);
    }

    /**
     * A graph as the page edits it. The layers are where the page draws the nodes, and only
     * that: the order nodes are asked in comes from what each reads.
     */
    static fromSpec(spec: GraphSpec): Graph {
        const contexts = spec.contexts ?? spec.texts ?? [];
        if (!Array.isArray(contexts) || contexts.some(c => !given(c))) {
            throw new RangeError('contexts: a list of texts, JSON objects or arrays, none empty');
        }
        if (!Array.isArray(spec.inputs) || (spec.inputs.length === 0 && contexts.length === 0 && !spec.time)) {
            throw new RangeError('inputs: at least one, a context, or time');
        }
        if (!Array.isArray(spec.layers) || spec.layers.length === 0 || spec.layers.some(l => !Array.isArray(l) || l.length === 0)) {
            throw new RangeError('layers: at least one, and no empty layer');
        }
        const graph = Graph.make().input(...spec.inputs.map(String)).context(...contexts);
        if (spec.time) {
            graph.time();
        }
        for (const node of spec.layers.flat()) {
            graph.node(nodeFrom(node));
            if (node.start !== undefined) {
                if (typeof node.start !== 'number') {
                    throw new RangeError(`${node.name}: start is a number`);
                }
                graph.startsAt(node.name, node.start);
            }
        }
        graph.layout(...spec.layers.map(l => l.map(n => String(n.name ?? ''))));
        graph.plan();

        return graph;
    }

    input(...names: string[]): this {
        for (const name of names) {
            this.claim(name);
            this.onOff.push(name);
        }

        return this;
    }

    /**
     * The `context` input, with the contexts a truth table runs a row each and a tick picks from.
     * Each is text, or a JSON object or array.
     */
    context(...contexts: Context[]): this {
        this.given.push(...contexts);

        return this;
    }

    text(...contexts: Context[]): this {
        return this.context(...contexts);
    }

    /** The `time` input: the tick number times the tick interval, and 0 in a truth table */
    time(): this {
        this.timed = true;

        return this;
    }

    gate(name: string, gate: string, ...reads: string[]): this {
        return this.node(Gates.gate(gate, reads, name));
    }

    /** @param weights signal => weight */
    weighted(name: string, weights: Record<string, number>, bias: number): this {
        return this.node(Gates.weighted(Object.values(weights), bias, name, Object.keys(weights)));
    }

    ask(name: string, question: string, reads: string | string[], yes = '', no = ''): this {
        return this.node(Gates.custom(name, question, typeof reads === 'string' ? [reads] : reads, yes, no));
    }

    all(name: string, ...reads: string[]): this {
        return this.node(Rules.all(name, reads));
    }

    any(name: string, ...reads: string[]): this {
        return this.node(Rules.any(name, reads));
    }

    none(name: string, ...reads: string[]): this {
        return this.node(Rules.none(name, reads));
    }

    atLeast(name: string, n: number, ...reads: string[]): this {
        return this.node(Rules.atLeast(name, n, reads));
    }

    /** @param weights signal => weight */
    average(name: string, weights: Record<string, number>): this {
        return this.node(Rules.average(name, weights));
    }

    /** @param compute given the signals it reads by name */
    rule(name: string, compute: Compute, ...reads: string[]): this {
        return this.node(Rules.rule(name, compute, reads));
    }

    /** See Node for what a later question and code read of a choice or a score */
    choose(name: string, question: string, reads: string | string[], options: Record<string, string>): this {
        return this.node(Gates.choice(name, question, typeof reads === 'string' ? [reads] : reads, options));
    }

    score(name: string, question: string, reads: string | string[], levels: string[]): this {
        return this.node(Gates.score(name, question, typeof reads === 'string' ? [reads] : reads, levels));
    }

    sum(name: string, ...reads: string[]): this {
        return this.node(Rules.sum(name, reads));
    }

    node(node: Node): this {
        const read = node.reads.includes(ALIAS) ? node.reading(node.reads.map(r => r === ALIAS ? Graph.CONTEXT : r)) : node;
        this.claim(read.name);
        this.byName.set(read.name, read);

        return this;
    }

    /** What a node in a loop holds before it is first asked */
    startsAt(name: string, value: number): this {
        this.starts[name] = value;

        return this;
    }

    /** The columns a page draws the nodes in, if not by the level each is asked at */
    layout(...columns: string[][]): this {
        this.columns = columns;

        return this;
    }

    nodes(): Record<string, Node> {
        return Object.fromEntries(this.byName);
    }

    /** The on/off inputs */
    inputs(): string[] {
        return [...this.onOff];
    }

    /** The contexts with something in them */
    contexts(): Context[] {
        return this.given.filter(given);
    }

    texts(): Context[] {
        return this.contexts();
    }

    hasTime(): boolean {
        return this.timed;
    }

    /** Every input signal: the on/off inputs, then `context` and `time` where the graph has them */
    signals(): string[] {
        return [...this.onOff, ...(this.contexts().length === 0 ? [] : [Graph.CONTEXT]), ...(this.timed ? [Graph.TIME] : [])];
    }

    start(): Record<string, number> {
        return { ...this.starts };
    }

    output(): string {
        return [...this.byName.keys()].pop() ?? '';
    }

    plan(): Plan {
        const known = new Set(this.known());
        const reads: Record<string, readonly string[]> = {};
        for (const [name, node] of this.byName) {
            const missing = node.reads.filter(s => !known.has(s));
            if (missing.length > 0) {
                throw new RangeError(`${name}: reads ${missing.join(', ')}, which is not an input or a node`);
            }
            const picked = node.reads.find(s => this.byName.get(s)?.type === 'choice');
            if (picked !== undefined && !['custom', 'choice', 'score'].includes(node.preset)) {
                throw new RangeError(`${name}: reads the option ${picked} picked, which is not a number; read one option's probability, such as ${this.byName.get(picked)!.parts()[0]}`);
            }
            reads[name] = node.reads;
        }

        return new Plan(reads);
    }

    /** Every signal a node can read: the inputs, the nodes, and the parts of a choice or a score */
    known(): string[] {
        return [...this.signals(), ...this.byName.keys(), ...this.parts()];
    }

    /**
     * An oracle that batches is asked each level's loop-free nodes in one request. A node worked
     * out in code is never sent. A node is shown time as whole seconds where they are whole, and
     * every other number rounded to three places.
     */
    simulation(oracle: Oracle, onFire: OnFire | null = null): Simulation {
        const node = (n: string) => this.byName.get(n)!;
        const fire = async (n: string, s: State): Promise<number | Answer> => {
            const found = node(n);

            return found.compute === null ? oracle.fire(found, shown(s)) : found.compute(shown(s));
        };
        const fireMany = async (items: [string, State][]): Promise<Record<string, Value | Answer>> => {
            const asked = items.filter(([n]) => node(n).compute === null);
            const answers: Record<string, number | Answer> = asked.length === 0 ? {} : await oracle.fireMany(asked.map(([n, s]) => [n, node(n), shown(s)]));
            const all: Record<string, Value | Answer> = {};
            for (const [n, s] of items) {
                all[n] = answers[n] ?? await fire(n, s);
            }

            return all;
        };

        return new Simulation(this.plan(), fire, onFire, oracle.batching() ? fireMany : null);
    }

    /**
     * The same graph with every node answering exactly by its rule: null for a node with no
     * rule, on its boundary, or reading one that is null.
     */
    ideal(): Simulation {
        return new Simulation(this.plan(), async (name, state) => {
            const expected = Object.values(state).includes(null) ? null : this.byName.get(name)!.expected(state);

            return expected === null ? null : Number(expected);
        });
    }

    /** Every node from its starting value, settled once against the inputs */
    run(oracle: Oracle, inputs: Record<string, number> = {}, context = 0, time = 0): Promise<Row> {
        return this.tick(oracle, this.values(inputs, context, time));
    }

    /** One step: `values` settled from where `previous` ended, or from the starting values */
    async tick(oracle: Oracle, values: State, previous: State | null = null, previousIdeal: State | null = null, onFire: OnFire | null = null): Promise<Row> {
        const simulation = this.simulation(oracle, onFire);
        if (previous === null) {
            return this.row(values, await simulation.fresh(values, this.starts), await this.ideal().fresh(values, this.starts));
        }

        return this.row(
            values,
            await simulation.step(this.restore(previous), values),
            await this.ideal().step(this.restore(previousIdeal ?? previous), values),
        );
    }

    /**
     * One row per combination of the on/off inputs, and per text: each from the starting values,
     * so a latch forgets between rows.
     */
    async truthTable(oracle: Oracle, onRow: ((i: number, row: Row) => void) | null = null, onFire: OnRowFire | null = null): Promise<Row[]> {
        const rows: Row[] = [];
        for (const [i, values] of this.combinations().entries()) {
            const row = await this.tick(oracle, values, null, null, onFire === null ? null : (k, n, v) => onFire(i, k, n, v));
            rows.push(row);
            onRow?.(i, row);
        }

        return rows;
    }

    ticker(oracle: Oracle, every = 1): Ticker {
        return new Ticker(this, oracle, every);
    }

    /**
     * The values a step runs with: each on/off input as 0 or 1, the text picked from those
     * written, and time in seconds.
     */
    values(inputs: Record<string, number>, context = 0, time = 0): State {
        const values: State = {};
        for (const name of this.onOff) {
            values[name] = Number(inputs[name] ?? 0);
        }
        const contexts = this.contexts();
        if (contexts.length > 0) {
            values[Graph.CONTEXT] = contexts[context % contexts.length]!;
        }
        if (this.timed) {
            values[Graph.TIME] = seconds(time);
        }

        return values;
    }

    /** The columns the page draws: the layout, or else the level each node is asked at */
    layers(): string[][] {
        return this.columns ?? this.plan().levels.map(level => level.flat());
    }

    /** The graph as the page edits it */
    spec(): GraphSpec {
        return {
            inputs: [...this.onOff],
            ...(this.given.length === 0 ? {} : { contexts: [...this.given] }),
            ...(this.timed ? { time: true } : {}),
            layers: this.layers().map(column => column.map(n => ({
                ...this.byName.get(n)!.spec(),
                ...(n in this.starts ? { start: this.starts[n]! } : {}),
            }))),
        };
    }

    private combinations(): State[] {
        const count = this.onOff.length;
        const bits: Record<string, number>[] = [];
        for (let i = 0; i < 2 ** count; i++) {
            bits.push(Object.fromEntries(this.onOff.map((name, j) => [name, (i >> (count - 1 - j)) & 1])));
        }
        const contexts = this.contexts().length === 0 ? [0] : this.contexts().map((_c, i) => i);

        return contexts.flatMap(context => bits.map(b => this.values(b, context)));
    }

    /** The signals a step starts from: what was given, where it names a signal of this graph */
    private restore(previous: State): State {
        const signals: State = {};
        for (const node of this.byName.keys()) {
            signals[node] = this.starts[node] ?? 0;
        }
        const known = new Set(this.known());
        for (const [key, value] of Object.entries(previous)) {
            if (known.has(key)) {
                signals[key] = value;
            }
        }

        return signals;
    }

    private row(values: State, settled: Settled, ideal: Settled): Row {
        const nodes = [...this.byName.keys()];

        return new Row(
            values,
            Object.fromEntries([...nodes, ...this.parts()].filter(n => n in settled.signals).map(n => [n, settled.signals[n]!])),
            Object.fromEntries(nodes.map(n => [n, ideal.signals[n] as number | null])),
            this.output(),
            settled.unsettled.flat(),
            settled.fires,
        );
    }

    private parts(): string[] {
        return [...this.byName.values()].flatMap(n => n.parts());
    }

    private claim(name: string): void {
        if (!NAME.test(name)) {
            throw new RangeError(`'${name}': a name is a lowercase letter, then up to 11 letters, digits or _`);
        }
        if ([Graph.CONTEXT, ALIAS, Graph.TIME, ...this.onOff, ...this.byName.keys()].includes(name)) {
            throw new RangeError(`${name}: used twice`);
        }
    }
}

function shown(state: State): State {
    return Object.fromEntries(Object.entries(state).map(([k, v]) =>
        [k, typeof v !== 'number' ? v : k === Graph.TIME ? seconds(v) : round(v, 3)]));
}

/** Whether a context has something in it: text that is not blank, or a non-empty object or array */
function given(context: unknown): boolean {
    if (typeof context === 'string') {
        return context.trim() !== '';
    }

    return typeof context === 'object' && context !== null && Object.keys(context).length > 0;
}

function nodeFrom(spec: NodeSpec): Node {
    const name = String(spec.name ?? '');
    if (!Array.isArray(spec.reads) || spec.reads.length === 0 || new Set(spec.reads).size !== spec.reads.length) {
        throw new RangeError(`${name}: read at least one signal, each once`);
    }
    const reads = spec.reads.map(r => String(r) === ALIAS ? Graph.CONTEXT : String(r));
    if (!['custom', 'choice', 'score'].includes(spec.preset) && reads.includes(Graph.CONTEXT)) {
        throw new RangeError(`${name}: ${spec.preset} works on numbers, so only a question can read the context`);
    }
    const weights = (spec.weights ?? []).map(Number);
    if (typeof spec.preset === 'string' && spec.preset in Gates.templates()) {
        return Gates.gate(spec.preset, reads.map(String), name);
    }
    switch (spec.preset) {
        case 'all': return Rules.all(name, reads.map(String));
        case 'any': return Rules.any(name, reads.map(String));
        case 'none': return Rules.none(name, reads.map(String));
        case 'sum': return Rules.sum(name, reads.map(String));
        case 'choice': return Gates.choice(name, String(spec.instructions ?? ''), reads.map(String), spec.options ?? {});
        case 'score': return Gates.score(name, String(spec.instructions ?? ''), reads.map(String), spec.levels ?? []);
        case 'atLeast': return Rules.atLeast(name, Number(spec.n ?? 1), reads.map(String));
        case 'average':
            if (weights.length !== reads.length) {
                throw new RangeError(`${name}: one weight per signal read`);
            }

            return Rules.average(name, Object.fromEntries(reads.map((r, i) => [String(r), weights[i]!])));
    }
    if (spec.preset === 'weighted') {
        return Gates.weighted(weights, Number(spec.bias ?? 0), name, reads.map(String));
    }
    if (spec.preset === 'custom') {
        return Gates.custom(name, String(spec.instructions ?? ''), reads.map(String), String(spec.yes ?? ''), String(spec.no ?? ''));
    }
    throw new RangeError(`${name}: preset is a gate, a rule, weighted or custom`);
}
