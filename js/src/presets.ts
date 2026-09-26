import { data } from './data.js';
import { Graph, type GraphSpec } from './graph.js';

interface Entry {
    about: string;
    graph: GraphSpec;
    starts?: Record<string, number>;
    truthTable?: boolean;
}

/**
 * The graphs in `data/presets.json`, each with what it shows and the inputs its first tick
 * starts from.
 */
export class Presets {
    static names(): string[] {
        return Object.keys(presets());
    }

    static get(name: string): Graph {
        return Graph.fromSpec(entry(name).graph);
    }

    static about(name: string): string {
        return entry(name).about;
    }

    static starts(name: string): Record<string, number> {
        return { ...(entry(name).starts ?? {}) };
    }

    /**
     * A ripple-carry adder of a and b into s, least significant bit first, of any width. Each
     * bit is two nodes: the sum is xor of a, b and the carry in, and the carry out is their
     * majority, the last carry being the top bit of the sum. The sum row is drawn the other way
     * from the carries, so it reads most significant bit first.
     */
    static adder(bits: number): Graph {
        const graph = Graph.make();
        for (let i = 0; i < bits; i++) {
            graph.input(`a${i}`);
        }
        for (let i = 0; i < bits; i++) {
            graph.input(`b${i}`);
        }
        const carries: string[] = [];
        for (let i = 0; i < bits; i++) {
            const carry = i < bits - 1 ? `c${i + 1}` : `s${bits}`;
            const reads = i === 0 ? ['a0', 'b0'] : [`a${i}`, `b${i}`, `c${i}`];
            graph.gate(`s${i}`, 'xor', ...reads).gate(carry, i === 0 ? 'and' : 'majority', ...reads);
            carries.push(carry);
        }

        return graph.layout(...carries.map((carry, i) => [carry, `s${bits - 1 - i}`]));
    }

    /** Each adder input set to a pair of numbers */
    static sums(bits: number, pairs: [number, number][]): Record<string, number>[] {
        return pairs.map(([a, b]) => {
            const inputs: Record<string, number> = {};
            for (let i = 0; i < bits; i++) {
                inputs[`a${i}`] = (a >> i) & 1;
                inputs[`b${i}`] = (b >> i) & 1;
            }

            return inputs;
        });
    }
}

function presets(): Record<string, Entry> {
    return data<Record<string, Entry>>('presets.json');
}

function entry(name: string): Entry {
    const found = presets()[name];
    if (found === undefined) {
        throw new RangeError(`${name} is not a preset; the presets are ${Presets.names().join(', ')}`);
    }

    return found;
}
