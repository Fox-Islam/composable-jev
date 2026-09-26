import { data } from './data.js';
import { Node } from './node.js';
import { text, type State } from './numbers.js';

export interface Template {
    arity: 1 | 2;
    instructions: string;
    yes: string;
    no: string;
}

/**
 * The questions a node asks: logic gates, a weighted sum, and questions written by hand - a noul,
 * a choice or a score. Every gate thresholds at 0.5 and states which side 0.5 itself falls on,
 * so the boundary is defined in the question. The wording is a template over the names of the
 * signals the gate reads: {x} is the one signal, {list} is them all ("a and b", "a, b and c"),
 * {both} is "both" or "all".
 */
export class Gates {
    /** `data/gates.json`. Arity 1 reads exactly one signal; arity 2 reads two or more. */
    static templates(): Record<string, Template> {
        return data<Record<string, Template>>('gates.json');
    }

    static gate(gate: string, reads: readonly string[], name: string | null = null): Node {
        const template = Gates.templates()[gate];
        if (template === undefined) {
            throw new RangeError(`${gate} is not a gate`);
        }
        if (reads.length === 0 || (template.arity === 1) !== (reads.length === 1)) {
            throw new RangeError(`${gate} reads ${template.arity === 1 ? 'one signal' : 'two or more signals'}`);
        }

        return new Node(
            name ?? gate,
            gate,
            [...reads],
            Gates.render(template.instructions, reads),
            Gates.render(template.yes, reads),
            Gates.render(template.no, reads),
            (state: State) => margin(gate, reads.map(r => Number(state[r]))),
        );
    }

    /** Each gate over `a`, or `a` and `b`: the Gates tab's single node */
    static builtIn(): Record<string, Node> {
        return Object.fromEntries(Object.entries(Gates.templates())
            .map(([name, t]) => [name, Gates.gate(name, t.arity === 1 ? ['a'] : ['a', 'b'])]));
    }

    /**
     * A classic perceptron: yes where the weighted sum of the inputs exceeds the bias. It asks Jev
     * to do the sum, which is the arithmetic its documentation names as a weakness.
     *
     * @param inputs the signals read, `a`, `b`, `c`… when not given
     */
    static weighted(weights: readonly number[], bias: number, name = 'weighted', inputs: readonly string[] | null = null): Node {
        const reads = inputs ?? weights.map((_w, i) => String.fromCharCode(97 + i));
        if (weights.length === 0 || reads.length !== weights.length) {
            throw new RangeError(`${name}: one weight per signal read`);
        }
        const total = weights.map((w, i) => `${text(w)} × ${reads[i]}`).join(' + ');
        const b = text(bias);

        return new Node(
            name,
            'weighted',
            [...reads],
            `Is ${total} greater than ${b}?`,
            `${total} is greater than ${b}.`,
            `${total} is ${b} or less.`,
            (state: State) => weights.reduce((sum, w, i) => sum + w * Number(state[reads[i]!]), 0) - bias,
            [...weights],
            bias,
        );
    }

    /** A question written by hand, over the signals it names. It has no rule. */
    static custom(name: string, question: string, reads: readonly string[], yes = '', no = ''): Node {
        if (question.trim() === '') {
            throw new RangeError(`${name}: write a question`);
        }

        return new Node(name, 'custom', [...reads], question.trim(), yes.trim(), no.trim());
    }

    /** A question Jev answers by picking one of the options, option => what it means */
    static choice(name: string, question: string, reads: readonly string[], options: Record<string, string>): Node {
        if (question.trim() === '') {
            throw new RangeError(`${name}: write a question`);
        }
        if (Object.keys(options).length < 2 || Object.keys(options).some(o => o.trim() === '' || o.includes('.'))) {
            throw new RangeError(`${name}: two or more options, each named, with no . in the name`);
        }

        return new Node(name, 'choice', [...reads], question.trim(), '', '', null, [], 0, null, 0, 'choice',
            Object.fromEntries(Object.entries(options).map(([o, d]) => [o, String(d)])));
    }

    /** A question Jev answers with a score, from 0 for the first level up; the levels lowest first */
    static score(name: string, question: string, reads: readonly string[], levels: readonly string[]): Node {
        if (question.trim() === '') {
            throw new RangeError(`${name}: write a question`);
        }
        if (!Array.isArray(levels) || levels.length < 2) {
            throw new RangeError(`${name}: two or more levels, lowest first`);
        }

        return new Node(name, 'score', [...reads], question.trim(), '', '', null, [], 0, null, 0, 'score', levels.map(String));
    }

    static render(template: string, reads: readonly string[]): string {
        const list = reads.length === 1 ? reads[0]! : `${reads.slice(0, -1).join(', ')} and ${reads[reads.length - 1]}`;
        const both = reads.length === 2 ? 'both' : 'all';

        return template.replace(/\{(x|list|both)\}/g, (_m, key: string) => key === 'x' ? reads[0]! : key === 'list' ? list : both);
    }
}

function margin(gate: string, v: number[]): number {
    const high = v.filter(x => x > 0.5).length;
    switch (gate) {
        case 'buffer': return v[0]! - 0.5;
        case 'not': return 0.5 - v[0]!;
        case 'and': return Math.min(...v) - 0.5;
        case 'or': return Math.max(...v) - 0.5;
        case 'nand': return 0.5 - Math.min(...v);
        case 'nor': return 0.5 - Math.max(...v);
        case 'xor': return high % 2 === 1 ? 0.5 : -0.5;
        default: return high - v.length / 2 - 0.25;
    }
}
