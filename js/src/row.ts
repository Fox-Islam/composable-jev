import type { State, Value } from './numbers.js';

/**
 * What one step gave: the values it ran with, each node's answer, and each node's right answer
 * where it has a rule.
 */
export class Row {
    constructor(
        readonly inputs: State,
        /** node => Jev's answer, and a choice's or a score's parts */
        readonly signals: State,
        /** node => 1, 0, or null with no rule */
        readonly ideal: Record<string, number | null>,
        readonly output: string,
        /** nodes in a loop that had not settled */
        readonly unsettled: string[],
        readonly fires: number,
    ) {}

    value(node: string): number {
        return Number(this.signals[node]);
    }

    yes(node: string): boolean {
        return this.value(node) > 0.5;
    }

    /** The option a choice picked */
    choice(node: string): string {
        return String(this.signals[node]);
    }

    /** Each option's or level's probability, from a choice's or a score's parts */
    probabilities(node: string): Record<string, number> {
        return Object.fromEntries(Object.entries(this.signals)
            .filter(([s]) => s.startsWith(`${node}.`))
            .map(([s, v]) => [s.slice(node.length + 1), Number(v)]));
    }

    /** The number held in bits named `${prefix}0`, `${prefix}1`…, least significant first */
    number(prefix: string): number {
        let total = 0;
        for (let i = 0; `${prefix}${i}` in this.signals; i++) {
            total += this.yes(`${prefix}${i}`) ? 2 ** i : 0;
        }

        return total;
    }

    /** Inputs and nodes, as the next step starts from them */
    state(): State {
        return { ...this.signals, ...this.inputs };
    }

    idealState(): State {
        return { ...this.ideal, ...this.inputs };
    }

    toJSON(): Record<string, unknown> {
        const want: Value | undefined = this.ideal[this.output];

        return {
            inputs: this.inputs, signals: this.signals, ideal: this.ideal,
            p: this.signals[this.output] ?? null, want: want === null || want === undefined ? null : want > 0.5,
            unsettled: this.unsettled, fires: this.fires,
        };
    }
}
