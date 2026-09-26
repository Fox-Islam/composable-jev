import type { Answer } from '../answer.js';
import type { Node } from '../node.js';
import type { State } from '../numbers.js';

/** A node to ask, keyed as its answer comes back, with the signals it reads */
export type Item = [key: string, node: Node, state: State];

/** What answers a node's question: Jev, or the simulator */
export interface Oracle {
    /** A noul's probability, or a choice's or a score's answer */
    fire(node: Node, state: State): Promise<number | Answer>;

    /** Several nodes at once, keyed as given */
    fireMany(items: Item[]): Promise<Record<string, number | Answer>>;

    /** Whether a level's nodes should go to fireMany in one call instead of fire each */
    batching(): boolean;
}
