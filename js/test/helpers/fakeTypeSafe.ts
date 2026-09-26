import type { Fetch } from '@typesafe-ai/sdk';
import { Jev, type JevOptions } from '../../src/oracle/jev.js';
import type { Graph } from '../../src/graph.js';
import { round, type State } from '../../src/numbers.js';

type Answer = (state: State, question: Asked) => number;

interface Asked {
    type: 'noul' | 'choice' | 'score';
    instructions: string;
    criteria: Record<string, string> | string[];
}

/**
 * TypeSafe's System One, answered by a function over each question's state, and every request
 * kept with the body as it went over the wire.
 */
export class FakeTypeSafe {
    readonly requests: { raw: string; body: { state: State; questions: Record<string, Asked> } }[] = [];

    constructor(private readonly answer: Answer) {}

    static answering(p: number): FakeTypeSafe {
        return new FakeTypeSafe(() => p);
    }

    /**
     * As `spec/fixtures.json` is answered in every implementation: 0.99 or 0.01 by the node's
     * rule, and for a question with none an answer that follows its numbers, so a loop drifts and
     * settles as a leak does.
     */
    static forFixture(graph: Graph): FakeTypeSafe {
        const nodes = new Map(Object.values(graph.nodes()).map(n => [n.instructions, n]));

        return new FakeTypeSafe((state, question) => {
            const node = nodes.get(question.instructions)!;
            const expected = node.expected(Object.fromEntries(node.reads.map(r => [r, state[r]!])));

            return expected === null ? drifting(Object.fromEntries(node.reads.map(r => [r, state[r]!]))) : expected ? 0.99 : 0.01;
        });
    }

    readonly fetch: Fetch = async (_input, init) => {
        const raw = String(init?.body);
        const body = JSON.parse(raw) as FakeTypeSafe['requests'][number]['body'];
        this.requests.push({ raw, body });
        const answers = Object.fromEntries(Object.entries(body.questions)
            .map(([key, q]) => [key, answer(q, this.answer(body.state, q))]));
        const count = Object.keys(answers).length;

        return new Response(JSON.stringify({
            model: 'jev-test', answers,
            usage: { input_tokens: 300 + 60 * count, output_tokens: 20 * count },
        }), { status: 200, headers: { 'content-type': 'application/json' } });
    };

    jev(options: Omit<JevOptions, 'fetch' | 'apiKey'> = {}): Jev {
        return new Jev({ ...options, apiKey: 'ts-key', fetch: this.fetch });
    }
}

/** 0.2 plus 0.6 times the mean of the state's numbers, each held to 0 to 1, or 0.7 for a state with none */
export function drifting(state: State): number {
    const numbers = Object.values(state).filter((v): v is number => typeof v === 'number').map(v => Math.max(0, Math.min(1, v)));

    return numbers.length === 0 ? 0.7 : round(0.2 + 0.6 * numbers.reduce((a, b) => a + b, 0) / numbers.length, 3);
}

/**
 * A noul answered `p`; a choice or a score with `p` on its first option or its top level and the
 * rest shared among the others, picking the likeliest option, first where two tie, and scoring
 * the expected level to two places.
 */
export function answer(question: Asked, p: number): Record<string, unknown> {
    if (question.type === 'noul') {
        return { type: 'noul', noul: p };
    }
    const options = Object.keys(question.criteria);
    const favoured = question.type === 'choice' ? 0 : options.length - 1;
    const rest = round((1 - p) / (options.length - 1), 3);
    const probabilities = Object.fromEntries(options.map((o, i) => [o, i === favoured ? p : rest]));
    if (question.type === 'choice') {
        const top = Math.max(...Object.values(probabilities));

        return { type: 'choice', choice: options.find(o => probabilities[o] === top), probabilities };
    }

    return { type: 'score', score: round(Object.values(probabilities).reduce((sum, chance, level) => sum + level * chance, 0), 2), probabilities };
}
