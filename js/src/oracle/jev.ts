import { createHash, randomBytes } from 'node:crypto';
import { existsSync, mkdirSync, readFileSync, renameSync, writeFileSync } from 'node:fs';
import { dirname, join } from 'node:path';
import { TypeSafeClient, type Fetch } from '@typesafe-ai/sdk';
import { Answer } from '../answer.js';
import type { Node, Question } from '../node.js';
import { canonical, float, seconds, type State } from '../numbers.js';
import { batches } from './batches.js';
import type { Item, Oracle } from './oracle.js';

export interface JevOptions {
    /** falls back to `TYPESAFE_API_KEY` */
    apiKey?: string;
    /** a level's nodes in one request; on unless false */
    batch?: boolean;
    /** where answers are kept, by request body; nothing is kept without one */
    cacheDir?: string | null;
    model?: string;
    baseURL?: string;
    /** swaps the transport, for a test that calls nothing */
    fetch?: Fetch;
}

type Wire = Record<string, unknown>;

/**
 * Asks Jev through TypeSafe's System One. Answers are cached on disk by request body, so a rerun
 * costs only what changed; the key is the same in every implementation, so they share a cache.
 *
 * Batching asks a level's nodes in one request, their inputs merged into one state: the fixed
 * part of a call is paid once, not once a node. Over the adders, xor, half-adder and ticket
 * triage, with every node asked of Jev, batched and separate answers fell on the same side of 0.5
 * for 2,722 of 2,731 nodes, batched was right as often or more, and it used 26 to 39% fewer
 * tokens in about half the requests.
 */
export class Jev implements Oracle {
    calls = 0;

    cached = 0;

    tokens = 0;

    private readonly client: TypeSafeClient;

    private readonly batch: boolean;

    private readonly cacheDir: string | null;

    private readonly model: string;

    constructor(options: JevOptions = {}) {
        this.client = new TypeSafeClient({
            ...(options.apiKey === undefined ? {} : { apiKey: options.apiKey }),
            ...(options.baseURL === undefined ? {} : { baseURL: options.baseURL }),
            ...(options.fetch === undefined ? {} : { fetch: options.fetch }),
        });
        this.batch = options.batch ?? true;
        this.cacheDir = options.cacheDir ?? null;
        this.model = options.model ?? 'jev-latest';
    }

    static make(apiKey?: string, options: Omit<JevOptions, 'apiKey'> = {}): Jev {
        return new Jev({ ...options, ...(apiKey === undefined ? {} : { apiKey }) });
    }

    async fire(node: Node, state: State): Promise<number | Answer> {
        return (await this.ask(only(node, state), { out: node.question() }, { out: node }))['out']!;
    }

    async fireMany(items: Item[]): Promise<Record<string, number | Answer>> {
        const answers: Record<string, number | Answer> = {};
        for (const batch of batches(items)) {
            if (batch.length === 1) {
                const [key, node, state] = batch[0]!;
                answers[key] = await this.fire(node, state);
                continue;
            }
            const state: State = {};
            const questions: Record<string, Question> = {};
            const nodes: Record<string, Node> = {};
            for (const [key, node, given] of batch) {
                nodes[key] = node;
                for (const [signal, value] of Object.entries(only(node, given))) {
                    state[signal] = signal in state ? state[signal]! : value;
                }
                questions[key] = node.question();
            }
            const sorted = Object.fromEntries(Object.keys(state).sort().map(k => [k, state[k]!]));
            Object.assign(answers, await this.ask(sorted, questions, nodes));
        }

        return answers;
    }

    batching(): boolean {
        return this.batch;
    }

    /** One request, or its cached answers */
    private async ask(state: State, questions: Record<string, Question>, nodes: Record<string, Node>): Promise<Record<string, number | Answer>> {
        const wire = shown(state);
        const path = this.cacheDir === null ? null : join(this.cacheDir, `${createHash('sha256').update(canonical({ model: this.model, state: wire, questions })).digest('hex')}.json`);
        let raw: Record<string, unknown>;
        if (path !== null && existsSync(path)) {
            this.cached++;
            raw = (JSON.parse(readFileSync(path, 'utf8')) as { answers: Record<string, unknown> }).answers;
        } else {
            const result = await this.client.systemOne({ state: wire as never, questions, model: this.model });
            raw = {};
            for (const key of Object.keys(questions)) {
                const answer = (result.answers as Record<string, unknown>)[key];
                if (answer === undefined) {
                    throw new Error(`TypeSafe gave no answer to ${key}`);
                }
                raw[key] = answer;
            }
            this.calls++;
            this.tokens += (result.usage?.input_tokens ?? 0) + (result.usage?.output_tokens ?? 0);
            if (path !== null) {
                save(path, { request: { model: this.model, state: wire, questions }, model: result.model ?? null, answers: raw });
            }
        }

        return Object.fromEntries(Object.keys(questions).map(key => [key, read(nodes[key]!, raw[key], key)]));
    }
}

/**
 * An answer as the API sends it: a noul's probability, or a choice's pick or a score with a
 * probability for each option or level, which come in no set order and are put in the node's.
 */
function read(node: Node, answer: unknown, key: string): number | Answer {
    // A cache written before choices and scores holds a noul's probability alone.
    if (node.type === 'noul' && typeof answer === 'number') {
        return answer;
    }
    const given = answer as Record<string, unknown> | null;
    if (given === null || typeof given !== 'object' || given[node.type] === undefined) {
        throw new Error(`TypeSafe gave no ${node.type} answer to ${key}`);
    }
    if (node.type === 'noul') {
        return Number(given['noul']);
    }
    const probabilities = (given['probabilities'] ?? {}) as Record<string, number>;
    const parts = Object.fromEntries(Object.keys(node.options).map(p => [p, Number(probabilities[p] ?? 0)]));

    return new Answer(node.type === 'choice' ? String(given['choice']) : Number(given['score']), parts);
}

function only(node: Node, state: State): State {
    return Object.fromEntries(node.reads.map(s => [s, state[s]!]));
}

/**
 * The state as it goes over the wire: time in seconds, sent whole where whole, and every other
 * number as a float.
 */
function shown(state: State): Wire {
    return Object.fromEntries(Object.entries(state).map(([k, v]) =>
        [k, typeof v !== 'number' ? v : k === 'time' ? seconds(v) : float(v)]));
}

/** Written aside and moved into place, so a reader never meets half a file */
function save(path: string, entry: unknown): void {
    mkdirSync(dirname(path), { recursive: true });
    const aside = `${path}.${randomBytes(8).toString('hex')}.tmp`;
    writeFileSync(aside, JSON.stringify(entry));
    renameSync(aside, path);
}
