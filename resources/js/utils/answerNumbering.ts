/**
 * Option markers for a multiple-choice question — "a)", "B)", "3)" — derived from
 * each option's position rather than typed into its text, so reordering the
 * options relabels them instead of leaving the old letters behind.
 *
 * Mirrors `App\Domain\Assessment\Enums\AnswerNumbering`; null means a plain list.
 */
export type AnswerNumbering = 'lower_alpha' | 'upper_alpha' | 'numeric';

/** Marker for the option at `index` (zero-based); '' when the question has none. */
export function answerMarker(style: string | null | undefined, index: number): string {
    // Past the 26th option the letters run off the alphabet, so those fall back to
    // a number rather than printing whatever character comes next.
    const beyondAlphabet = index < 0 || index > 25;

    if (!style || style === 'numeric' || beyondAlphabet) {
        return style ? `${index + 1})` : '';
    }

    const base = style === 'upper_alpha' ? 'A' : 'a';

    return `${String.fromCharCode(base.charCodeAt(0) + index)})`;
}
