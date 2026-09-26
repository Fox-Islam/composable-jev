/**
 * A choice's or a score's answer: the value a later question reads, and the probabilities code
 * reads as the node's parts. A choice's value is the option Jev picked, a score's the score Jev
 * gave; the parts are keyed by option, or by level from "0", in the order the node lists them.
 */
export class Answer {
    constructor(
        readonly value: string | number,
        readonly parts: Record<string, number>,
    ) {}
}
