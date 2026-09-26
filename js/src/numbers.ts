/**
 * JSON.rawJSON and the source text a reviver is given, in Node 22 and newer. TypeScript's ES2023
 * library has neither.
 */
declare global {
    interface RawJSON {
        readonly rawJSON: string;
    }

    interface JSON {
        rawJSON(text: string): RawJSON;
        isRawJSON(value: unknown): value is RawJSON;
        parse(text: string, reviver: (this: unknown, key: string, value: unknown, context: { source?: string }) => unknown): unknown;
    }
}

export type JsonValue = string | number | boolean | null | JsonValue[] | { [key: string]: JsonValue };

/** What the questions are about, as System One's `state` is: text, or a JSON object or array */
export type Context = string | { [key: string]: JsonValue } | JsonValue[];

/** A value a signal holds: an answer, an input, the context, or null where a rule has no answer. */
export type Value = number | Context | null;

export type State = Record<string, Value>;

/**
 * Half rounds up. Written out, since PHP's round() pre-rounds, Python's rounds half to even
 * and JavaScript has no decimal rounding: 32.25 to one place is 32.3 in all three this way.
 */
export function round(value: number, places: number): number {
    const scale = 10 ** places;

    return Math.floor(value * scale + 0.5) / scale;
}

/**
 * Seconds to a tenth. Whole seconds go to Jev as whole numbers: sent as 32.0, Jev says it ends
 * in the digit 0 and misses multiples of 10 it gets right as 32.
 */
export function seconds(value: number): number {
    return round(value, 1);
}

/** A number as a question shows it: 1 and not 1.0, which Jev reads literally. */
export function text(n: number): string {
    return String(n);
}

/**
 * A number as a float on the wire. JavaScript has one number type, so `JSON.stringify(1.0)` is
 * `1`; asked whether an odd number of (1, 1, 0.96) is above 0.5, Jev answered 0.50 to 0.58 with
 * the ones sent as `1` and 0.55 to 0.66 as `1.0`.
 */
export function float(n: number): number | RawJSON {
    return Number.isInteger(n) ? JSON.rawJSON(n.toFixed(1)) : n;
}

/**
 * A body as its cache key hashes it and as tests compare it across implementations: keys
 * sorted, no whitespace, a float keeping its decimal, slashes and Unicode unescaped.
 */
export function canonical(value: unknown): string {
    return JSON.stringify(sorted(value));
}

/** JSON text in canonical form, each number written as it was: `1.0` stays a float. */
export function canonicalText(json: string): string {
    return canonical(JSON.parse(json, (_key, value, context) =>
        typeof value === 'number' && context.source !== undefined ? JSON.rawJSON(context.source) : value));
}

function sorted(value: unknown): unknown {
    if (Array.isArray(value)) {
        return value.map(sorted);
    }
    if (value === null || typeof value !== 'object' || JSON.isRawJSON(value)) {
        return value;
    }

    return Object.fromEntries(Object.keys(value).sort().map(k => [k, sorted((value as Record<string, unknown>)[k])]));
}
