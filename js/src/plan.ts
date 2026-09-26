/**
 * The order a graph's nodes are asked in: levels of strongly connected components. A component
 * with no loop is one node, asked once what it reads is final; a component with a loop is asked a
 * node at a time. Kosaraju's passes put the components in the order they depend on each other.
 *
 * A node reading another's part, `team.billing`, depends on that node.
 */
export class Plan {
    /** signal => the nodes that read it */
    readonly readers: Record<string, string[]> = {};

    readonly levels: string[][][];

    /** the nodes that can reach themselves */
    readonly looping: string[];

    /** @param reads node => the signals it reads */
    constructor(readonly reads: Record<string, readonly string[]>) {
        for (const [node, signals] of Object.entries(reads)) {
            for (const signal of new Set(signals)) {
                (this.readers[signal] ??= []).push(node);
            }
        }
        this.levels = this.ordered();
        this.looping = this.levels.flat().filter(c => this.looped(c)).flat();
    }

    looped(component: readonly string[]): boolean {
        return component.length > 1 || this.sources(component[0]!).includes(component[0]!);
    }

    /** The nodes a node reads, or reads a part of, in the order it reads them */
    sources(node: string): string[] {
        return [...new Set(this.reads[node]!.map(s => s.split('.', 1)[0]!).filter(b => b in this.reads))];
    }

    private ordered(): string[][][] {
        const order = Object.keys(this.reads);
        const position = new Map(order.map((n, i) => [n, i]));
        const sort = (nodes: string[]) => nodes.sort((a, b) => position.get(a)! - position.get(b)!);
        const forward: Record<string, string[]> = Object.fromEntries(order.map(n => [n, []]));
        const backward: Record<string, string[]> = {};
        for (const node of order) {
            backward[node] = this.sources(node);
            backward[node].forEach(source => forward[source]!.push(node));
        }
        Object.values(forward).forEach(sort);
        const components = this.components(finished(order, forward), backward, sort);

        return grouped(components, backward);
    }

    /** The components, walked back along what each node reads, in dependency order */
    private components(done: string[], backward: Record<string, string[]>, sort: (n: string[]) => string[]): string[][] {
        const components: string[][] = [];
        const assigned = new Set<string>();
        for (const root of [...done].reverse()) {
            if (assigned.has(root)) {
                continue;
            }
            const members: string[] = [];
            const stack = [root];
            assigned.add(root);
            while (stack.length > 0) {
                const node = stack.pop()!;
                members.push(node);
                for (const source of backward[node]!) {
                    if (!assigned.has(source)) {
                        assigned.add(source);
                        stack.push(source);
                    }
                }
            }
            components.push(sort(members));
        }

        return components;
    }
}

/** Nodes in the order a depth-first pass over readers finishes them */
function finished(order: string[], forward: Record<string, string[]>): string[] {
    const done: string[] = [];
    const seen = new Set<string>();
    for (const root of order) {
        if (seen.has(root)) {
            continue;
        }
        seen.add(root);
        const stack: [string, number][] = [[root, 0]];
        while (stack.length > 0) {
            const top = stack[stack.length - 1]!;
            const child = forward[top[0]]![top[1]];
            if (child === undefined) {
                stack.pop();
                done.push(top[0]);
                continue;
            }
            top[1]++;
            if (!seen.has(child)) {
                seen.add(child);
                stack.push([child, 0]);
            }
        }
    }

    return done;
}

function grouped(components: string[][], backward: Record<string, string[]>): string[][][] {
    const of = new Map<string, number>();
    components.forEach((members, i) => members.forEach(n => of.set(n, i)));
    const level: number[] = [];
    const groups = new Map<number, string[][]>();
    components.forEach((members, i) => {
        level[i] = 0;
        for (const node of members) {
            for (const source of backward[node]!) {
                const j = of.get(source)!;
                level[i] = j === i ? level[i]! : Math.max(level[i]!, level[j]! + 1);
            }
        }
        groups.set(level[i]!, [...(groups.get(level[i]!) ?? []), members]);
    });

    return [...groups.keys()].sort((a, b) => a - b).map(k => groups.get(k)!);
}
