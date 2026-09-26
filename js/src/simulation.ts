import { Answer } from './answer.js';
import type { Plan } from './plan.js';
import { canonical, type State, type Value } from './numbers.js';

/** Asks one node, given the signals it reads */
export type Fire = (node: string, state: State) => Promise<Value | Answer>;

/** Asks several nodes at once, keyed by node */
export type FireMany = (items: [string, State][]) => Promise<Record<string, Value | Answer>>;

/** `firing` as a node is asked and `fired` with its answer */
export type OnFire = (kind: 'firing' | 'fired', node: string, value: Value) => void;

/** Every signal once a step has settled, the loops that had not, and how many answers it took */
export interface Settled {
    signals: State;
    /** loops with a node that crossed 0.5 in the step */
    unsettled: string[][];
    fires: number;
}

/**
 * An answer moving less than this is treated as unchanged, so Jev answering 0.98 then 0.99 does
 * not re-ask everything that reads it.
 */
export const CHANGE = 0.02;

/**
 * Runs a graph for one step. No node is asked more than once a step, so a step is one trip
 * round a loop, the way a gate delay is in a circuit: a latch takes a step or two to settle, and
 * a node that reads itself moves once a step, which is what lets it run down over time.
 *
 * A node with no loop is asked only when something it reads has changed, so a step that flips
 * one input re-asks only what that input reaches; a level's such nodes go to `fireMany` in one
 * call. A loop is asked every step, a node at a time in design order, each reading the latest
 * values: asking a loop all at once would set a latch oscillating.
 */
export class Simulation {
    constructor(
        private readonly plan: Plan,
        private readonly fire: Fire,
        private readonly onFire: OnFire | null = null,
        private readonly fireMany: FireMany | null = null,
    ) {}

    static changed(old: Value | undefined, value: Value | undefined): boolean {
        return typeof old === 'number' && typeof value === 'number' ? Math.abs(old - value) > CHANGE : !Simulation.same(old, value);
    }

    /** Whether an answer crossed from one side of 0.5 to the other, or a choice picked another option */
    static flipped(old: Value | undefined, value: Value | undefined): boolean {
        if (typeof old === 'string' && typeof value === 'string') {
            return old !== value;
        }

        return typeof old === 'number' && typeof value === 'number' && (old > 0.5) !== (value > 0.5);
    }

    /** A context that is an object or an array is the same where its JSON is */
    static same(a: Value | undefined, b: Value | undefined): boolean {
        return typeof a === 'object' && a !== null && typeof b === 'object' && b !== null ? canonical(a) === canonical(b) : a === b;
    }

    /** Every node from its starting value, then settled against `values` */
    fresh(values: State, start: Record<string, number>): Promise<Settled> {
        const signals: State = {};
        for (const node of Object.keys(this.plan.reads)) {
            signals[node] = start[node] ?? 0;
        }

        return this.settle({ ...signals, ...values }, new Set(Object.keys(this.plan.reads)));
    }

    /**
     * The last step's signals with `values` changed, settled again. A node that reads itself, or
     * sits in a loop, is asked every step.
     */
    step(previous: State, values: State): Promise<Settled> {
        const signals = { ...previous };
        const dirty = new Set(this.plan.looping);
        for (const [key, value] of Object.entries(values)) {
            if (!(key in signals) || !Simulation.same(signals[key], value)) {
                (this.plan.readers[key] ?? []).forEach(n => dirty.add(n));
            }
            signals[key] = value;
        }

        return this.settle(signals, dirty);
    }

    private async settle(signals: State, dirty: Set<string>): Promise<Settled> {
        const unsettled: string[][] = [];
        let fires = 0;
        for (const level of this.plan.levels) {
            const single = level.filter(c => !this.plan.looped(c)).map(c => c[0]!).filter(n => dirty.has(n));
            for (const [node, value] of Object.entries(await this.askAll(single, signals))) {
                this.record(node, value, signals, dirty);
            }
            fires += single.length;
            for (const component of level) {
                if (this.plan.looped(component)) {
                    fires += component.length;
                    if (await this.roundLoop(component, signals, dirty)) {
                        unsettled.push(component);
                    }
                }
            }
        }

        return { signals, unsettled, fires };
    }

    /**
     * Asks a loop once round, in order. True where a node with others in its loop crossed 0.5,
     * as a latch's nodes do for a step after it is set: the loop has not settled. A loop that only
     * drifts, as a leak does, and one node reading only itself are not reported.
     */
    private async roundLoop(component: string[], signals: State, dirty: Set<string>): Promise<boolean> {
        let moved = false;
        for (const node of component) {
            const old = signals[node];
            this.record(node, await this.ask(node, signals), signals, dirty);
            moved = moved || Simulation.flipped(old, signals[node]);
        }

        return moved && component.length > 1;
    }

    private async askAll(nodes: string[], signals: State): Promise<Record<string, Value | Answer>> {
        const answers: Record<string, Value | Answer> = {};
        if (this.fireMany === null || nodes.length < 2) {
            for (const node of nodes) {
                answers[node] = await this.ask(node, signals);
            }

            return answers;
        }
        nodes.forEach(n => this.notify('firing', n, null));
        const given = await this.fireMany(nodes.map(n => [n, this.state(n, signals)]));
        for (const node of nodes) {
            answers[node] = given[node]!;
            this.notify('fired', node, shownOf(answers[node]));
        }

        return answers;
    }

    private async ask(node: string, signals: State): Promise<Value | Answer> {
        this.notify('firing', node, null);
        const value = await this.fire(node, this.state(node, signals));
        this.notify('fired', node, shownOf(value));

        return value;
    }

    /**
     * Stores an answer, a choice's or a score's parts with it, and marks what reads each to be
     * asked, if it moved.
     */
    private record(node: string, value: Value | Answer, signals: State, dirty: Set<string>): void {
        dirty.delete(node);
        const given: State = value instanceof Answer
            ? { [node]: value.value, ...Object.fromEntries(Object.entries(value.parts).map(([p, v]) => [`${node}.${p}`, v])) }
            : { [node]: value };
        for (const [signal, next] of Object.entries(given)) {
            const old = signal in signals ? signals[signal] : null;
            signals[signal] = next;
            if (Simulation.changed(old, next)) {
                for (const reader of this.plan.readers[signal] ?? []) {
                    if (reader !== node) {
                        dirty.add(reader);
                    }
                }
            }
        }
    }

    private notify(kind: 'firing' | 'fired', node: string, value: Value): void {
        this.onFire?.(kind, node, value);
    }

    private state(node: string, signals: State): State {
        return Object.fromEntries(this.plan.reads[node]!.map(s => [s, signals[s] ?? null]));
    }
}

function shownOf(value: Value | Answer): Value {
    return value instanceof Answer ? value.value : value;
}
