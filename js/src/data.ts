import { readFileSync } from 'node:fs';

const read = new Map<string, unknown>();

/**
 * The files in `data/` at the package root. They are not JavaScript: the PHP and Python packages
 * read the same files, so a gate's wording and a preset exist once.
 */
export function data<T>(file: string): T {
    if (!read.has(file)) {
        read.set(file, JSON.parse(readFileSync(new URL(`../../../data/${file}`, import.meta.url), 'utf8')));
    }

    return read.get(file) as T;
}
