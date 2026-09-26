import { data } from './data.js';
import { Gates } from './gates.js';
import { Node, type Compute } from './node.js';
import { text, type State } from './numbers.js';

export interface RuleTemplate {
    label: string;
    glyph: string;
    describe: string;
}

/**
 * Nodes worked out in code: no call, no tokens, no wait, and exact. Asking Jev an and between two
 * answers takes a request a level; for ticket triage that is 3 requests against 1, 746 ms
 * against 268 ms and 1,223 tokens against 502, with the same decision on every ticket.
 *
 * Each returns a probability, so a later question sees how sure the answers were, and each
 * is over 0.5 exactly where its logic is true: the lowest of some answers is over 0.5 only where
 * all of them are.
 */
export class Rules {
    /** `data/rules.json`: each rule's name on the page and what it works out */
    static templates(): Record<string, RuleTemplate> {
        return data<Record<string, RuleTemplate>>('rules.json');
    }

    static isRule(preset: string): boolean {
        return preset in Rules.templates() || preset === 'rule';
    }

    static all(name: string, reads: readonly string[]): Node {
        return node(name, 'all', reads, v => Math.min(...v));
    }

    static any(name: string, reads: readonly string[]): Node {
        return node(name, 'any', reads, v => Math.max(...v));
    }

    /** Not, over one signal; nor, over more */
    static none(name: string, reads: readonly string[]): Node {
        return node(name, 'none', reads, v => 1 - Math.max(...v));
    }

    /**
     * The chance one of them is true, where no two can be, as a choice's options or a score's
     * levels cannot: `sum('serious', ['severity.3', 'severity.4'])`. Held to 1 at most.
     */
    static sum(name: string, reads: readonly string[]): Node {
        return node(name, 'sum', reads, v => Math.min(1, v.reduce((a, b) => a + b, 0)));
    }

    /** The value `n` down from the highest: over 0.5 where at least `n` of them are */
    static atLeast(name: string, n: number, reads: readonly string[]): Node {
        if (!Number.isInteger(n) || n < 1 || n > reads.length) {
            throw new RangeError(`${name}: at least 1, and no more than the ${reads.length} signals it reads`);
        }

        return node(name, 'atLeast', reads, v => [...v].sort((a, b) => b - a)[n - 1]!, [], n);
    }

    /** @param weights signal => weight, none below 0 and not all 0 */
    static average(name: string, weights: Record<string, number>): Node {
        const w = Object.values(weights).map(Number);
        if (w.length === 0 || Math.min(...w) < 0 || w.reduce((a, b) => a + b, 0) <= 0) {
            throw new RangeError(`${name}: a weight for each signal, none below 0 and not all 0`);
        }
        const total = w.reduce((a, b) => a + b, 0);

        return node(name, 'average', Object.keys(weights), v => v.reduce((sum, x, i) => sum + x * w[i]!, 0) / total, w);
    }

    /**
     * A function of your own over the signals read, returning 0 to 1. It has no spec, so the page
     * and `data/presets.json` cannot hold it.
     */
    static rule(name: string, compute: Compute, reads: readonly string[]): Node {
        return built(name, 'rule', reads, `Worked out by a function of ${name}'s own`, s => Number(compute(s)), [], 0);
    }

    static describe(preset: string, reads: readonly string[], weights: readonly number[] = [], n = 0): string {
        return Gates.render(Rules.templates()[preset]!.describe, reads)
            .replace(/\{(n|weights)\}/g, (_m, key: string) => key === 'n' ? String(n) : weights.map(text).join(', '));
    }
}

function node(name: string, preset: string, reads: readonly string[], of: (values: number[]) => number, weights: number[] = [], n = 0): Node {
    reading(name, reads);

    return built(name, preset, reads, Rules.describe(preset, reads, weights, n), (state: State) => of(reads.map(r => Number(state[r]))), weights, n);
}

function built(name: string, preset: string, reads: readonly string[], describe: string, compute: Compute, weights: number[], n: number): Node {
    reading(name, reads);

    return new Node(name, preset, [...reads], describe, '', '', s => compute(s) - 0.5, weights, 0, compute, n);
}

function reading(name: string, reads: readonly string[]): void {
    if (reads.length === 0) {
        throw new RangeError(`${name}: read at least one signal`);
    }
}
