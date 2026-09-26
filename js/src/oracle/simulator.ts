import type { Node } from '../node.js';
import type { State } from '../numbers.js';
import type { Item, Oracle } from './oracle.js';

/**
 * A sigmoid of each node's own rule, for tests and for trying a graph before paying for it. A
 * node with no rule is left to Jev.
 */
export class Simulator implements Oracle {
    calls = 0;

    constructor(private readonly sharpness = 20) {}

    async fire(node: Node, state: State): Promise<number> {
        if (node.margin === null) {
            throw new RangeError(`${node.name} has no rule, so only Jev can answer it`);
        }
        this.calls++;

        return 1 / (1 + Math.exp(-this.sharpness * node.margin(state)));
    }

    async fireMany(items: Item[]): Promise<Record<string, number>> {
        const answers: Record<string, number> = {};
        for (const [key, node, state] of items) {
            answers[key] = await this.fire(node, state);
        }

        return answers;
    }

    batching(): boolean {
        return false;
    }
}
