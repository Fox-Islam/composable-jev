import type { State } from '../numbers.js';
import { Simulation } from '../simulation.js';
import type { Item } from './oracle.js';

/**
 * Splits nodes into batches whose states agree wherever they share a key, so one state holds a
 * batch. Nodes that call different signals by the same name go in different batches.
 */
export function batches(items: Item[]): Item[][] {
    const found: [State, Item[]][] = [];
    for (const item of items) {
        const batch = found.find(([merged]) => Object.entries(item[2]).every(([k, v]) => !(k in merged) || Simulation.same(merged[k], v)));
        if (batch === undefined) {
            found.push([{ ...item[2] }, [item]]);
        } else {
            batch[0] = { ...item[2], ...batch[0] };
            batch[1].push(item);
        }
    }

    return found.map(([, batch]) => batch);
}
