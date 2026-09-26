import type { Graph } from './graph.js';
import type { Oracle } from './oracle/oracle.js';
import type { Row } from './row.js';
import type { OnFire } from './simulation.js';

/**
 * Steps a graph one tick at a time, each settling from where the last ended. This is where a
 * latch shows it remembers, and a node reading itself runs down.
 *
 *     const latch = Presets.get('sr latch').ticker(jev);
 *     await latch.tick({ s: 1 });
 *     await latch.tick({ s: 1 });      // a loop goes round once a tick, so setting takes two
 *     (await latch.tick()).yes('q');   // true: it holds
 */
export class Ticker {
    readonly rows: Row[] = [];

    constructor(
        private readonly graph: Graph,
        private readonly oracle: Oracle,
        private readonly every = 1,
    ) {}

    /**
     * @param inputs on/off input => 0 or 1
     * @param context which of the contexts the tick uses
     */
    async tick(inputs: Record<string, number> = {}, context = 0, onFire: OnFire | null = null): Promise<Row> {
        const last = this.rows[this.rows.length - 1];
        const values = this.graph.values(inputs, context, this.rows.length * this.every);
        const row = await this.graph.tick(this.oracle, values, last?.state() ?? null, last?.idealState() ?? null, onFire);
        this.rows.push(row);

        return row;
    }
}
