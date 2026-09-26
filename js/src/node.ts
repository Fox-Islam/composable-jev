import type { State } from './numbers.js';

/** The rule as code, positive where the right answer is yes */
export type Margin = (state: State) => number;

/** A node's value worked out in code, from the signals it reads */
export type Compute = (state: State) => number;

/** A question, as System One is sent it */
export type Question =
    | { type: 'noul'; instructions: string; criteria: { true: string; false: string } }
    | { type: 'choice'; instructions: string; criteria: Record<string, string> }
    | { type: 'score'; instructions: string; criteria: [string, string, ...string[]] };

export type QuestionType = Question['type'];

/** A node as the page edits it, and as `data/presets.json` holds one */
export interface NodeSpec {
    name: string;
    preset: string;
    reads: string[];
    instructions?: string;
    yes?: string;
    no?: string;
    weights?: number[];
    bias?: number;
    n?: number;
    options?: Record<string, string>;
    levels?: string[];
    start?: number;
}

/**
 * One question about some signals. A noul's value is the probability Jev gives for yes; a
 * choice's is the option Jev picks and a score's the score it gives, with the probability of each
 * option or level as the node's parts, read as `name.option` or `name.2`. Its state is keyed by
 * the names of the signals it reads.
 *
 * The margin is the rule as code. Jev never sees it: it is what a run is checked against and
 * what the simulator answers from. A question written by hand - custom, choice or score - has
 * none.
 *
 * A node with `compute` is worked out in code and never asked: its value is what `compute`
 * returns, and its instructions describe what that is.
 */
export class Node {
    constructor(
        readonly name: string,
        /** a gate from `data/gates.json`, a rule from `data/rules.json`, `rule`, `weighted`, `custom`, `choice` or `score` */
        readonly preset: string,
        readonly reads: readonly string[],
        readonly instructions: string,
        readonly yes = '',
        readonly no = '',
        readonly margin: Margin | null = null,
        /** one per signal read, for a weighted sum or average */
        readonly weights: readonly number[] = [],
        readonly bias = 0,
        readonly compute: Compute | null = null,
        /** how many must be over 0.5, for `atLeast` */
        readonly n = 0,
        readonly type: QuestionType = 'noul',
        /** a choice's options, option => what it means, or a score's levels, lowest first */
        readonly options: Readonly<Record<string, string>> | readonly string[] = [],
    ) {}

    /** The same node reading other signals, as when a signal is known by another name */
    reading(reads: readonly string[]): Node {
        return new Node(this.name, this.preset, [...reads], this.instructions, this.yes, this.no, this.margin, this.weights, this.bias, this.compute, this.n, this.type, this.options);
    }

    named(name: string): Node {
        return new Node(name, this.preset, this.reads, this.instructions, this.yes, this.no, this.margin, this.weights, this.bias, this.compute, this.n, this.type, this.options);
    }

    question(): Question {
        switch (this.type) {
            case 'choice': return { type: 'choice', instructions: this.instructions, criteria: { ...this.options as Record<string, string> } };
            case 'score': return { type: 'score', instructions: this.instructions, criteria: [...this.options as string[]] as [string, string, ...string[]] };
            default: return { type: 'noul', instructions: this.instructions, criteria: { true: this.yes, false: this.no } };
        }
    }

    /** The names of a choice's or a score's parts, `name.option` or `name.0`, in order */
    parts(): string[] {
        return this.type === 'noul' ? [] : Object.keys(this.options).map(o => `${this.name}.${o}`);
    }

    /** The right answer, or null where the input sits on the boundary or there is no rule */
    expected(state: State): boolean | null {
        if (this.margin === null) {
            return null;
        }
        const margin = this.margin(state);

        return margin === 0 ? null : margin > 0;
    }

    spec(): NodeSpec {
        const base = { name: this.name, preset: this.preset, reads: [...this.reads] };
        if (this.preset === 'custom') {
            return { ...base, instructions: this.instructions, yes: this.yes, no: this.no };
        }

        switch (this.preset) {
            case 'weighted': return { ...base, weights: [...this.weights], bias: this.bias };
            case 'average': return { ...base, weights: [...this.weights] };
            case 'choice': return { ...base, instructions: this.instructions, options: { ...this.options as Record<string, string> } };
            case 'score': return { ...base, instructions: this.instructions, levels: [...this.options as string[]] };
            case 'atLeast': return { ...base, n: this.n };
            case 'rule': throw new Error(`${this.name} is worked out by a function, which a spec cannot hold`);
            default: return base;
        }
    }

    describe(): { name: string; inputs: string[]; instructions: string; yes: string; no: string; rule: boolean; code: boolean } {
        return {
            name: this.name, inputs: [...this.reads], instructions: this.instructions,
            yes: this.yes, no: this.no, rule: this.margin !== null, code: this.compute !== null,
        };
    }
}
