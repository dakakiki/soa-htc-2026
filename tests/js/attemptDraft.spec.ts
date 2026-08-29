import { afterEach, describe, expect, it, vi } from 'vitest';
import {
    clearDraft,
    dropAllDrafts,
    dropStaleDrafts,
    loadDraft,
    saveDraft,
    type AttemptAnswers,
} from '@/utils/attemptDraft';

const HALF_A_DAY = 12 * 60 * 60 * 1000;

function sheet(over: Partial<AttemptAnswers> = {}): AttemptAnswers {
    return { mc: {}, gaps: {}, essay: {}, ...over };
}

/** Backdate a stored draft — the only way to make one look abandoned. */
function age(attemptId: number, by: number): void {
    const key = `soahtc.attempt.${attemptId}`;
    const raw = JSON.parse(localStorage.getItem(key) ?? '{}');
    raw.saved_at = Date.now() - by;
    localStorage.setItem(key, JSON.stringify(raw));
}

afterEach(() => {
    localStorage.clear();
});

describe('keeping a sheet', () => {
    it('gives back exactly what was put in', () => {
        const written = sheet({ mc: { 311: [962] }, gaps: { 400: ['dumps', ''] }, essay: { 500: 'a sentence' } });

        saveDraft(7933, written);

        expect(loadDraft(7933)).toEqual(written);
    });

    it('has nothing for an attempt it has not seen', () => {
        expect(loadDraft(7933)).toBeNull();
    });

    it('is dropped when the exam is handed in', () => {
        saveDraft(7933, sheet({ essay: { 1: 'kept' } }));

        clearDraft(7933);

        expect(loadDraft(7933)).toBeNull();
    });
});

describe('what it refuses to give back', () => {
    it('refuses a draft older than half a day, and forgets it', () => {
        saveDraft(7933, sheet({ essay: { 1: 'yesterday' } }));
        age(7933, HALF_A_DAY + 1000);

        expect(loadDraft(7933)).toBeNull();
        expect(localStorage.getItem('soahtc.attempt.7933')).toBeNull();
    });

    it('keeps one that is merely old', () => {
        saveDraft(7933, sheet({ essay: { 1: 'this morning' } }));
        age(7933, HALF_A_DAY - 60_000);

        expect(loadDraft(7933)).not.toBeNull();
    });

    it('refuses text that is not a draft at all', () => {
        localStorage.setItem('soahtc.attempt.7933', 'not json');

        expect(loadDraft(7933)).toBeNull();
    });

    /**
     * What comes out of storage is text somebody could have edited by hand, so
     * every entry is measured before it is handed back to the screen.
     */
    it('drops entries of the wrong shape and keeps the rest', () => {
        localStorage.setItem(
            'soahtc.attempt.7933',
            JSON.stringify({
                mc: { 1: ['not a number'], 2: [962] },
                gaps: 'not a map at all',
                essay: { 3: 7, 4: 'kept' },
                saved_at: Date.now(),
            }),
        );

        expect(loadDraft(7933)).toEqual({ mc: { 2: [962] }, gaps: {}, essay: { 4: 'kept' } });
    });
});

describe('giving drafts up', () => {
    it('drops the abandoned and keeps the live', () => {
        saveDraft(1, sheet());
        saveDraft(2, sheet());
        age(1, HALF_A_DAY + 1000);

        dropStaleDrafts();

        expect(localStorage.getItem('soahtc.attempt.1')).toBeNull();
        expect(localStorage.getItem('soahtc.attempt.2')).not.toBeNull();
    });

    /**
     * 🔴 The regression this file was written for. One `try` around the whole
     * sweep meant the first unreadable key ended it, and every draft after that
     * one was never looked at — on exactly the shared venue machine the sweep
     * exists for, and with nothing else in the application that removes them.
     */
    it('keeps sweeping past a draft it cannot read', () => {
        localStorage.setItem('soahtc.attempt.1', 'not json');
        saveDraft(2, sheet());
        saveDraft(3, sheet());
        age(2, HALF_A_DAY + 1000);
        age(3, HALF_A_DAY + 1000);

        dropStaleDrafts();

        expect(localStorage.getItem('soahtc.attempt.1')).toBeNull();
        expect(localStorage.getItem('soahtc.attempt.2')).toBeNull();
        expect(localStorage.getItem('soahtc.attempt.3')).toBeNull();
    });

    it('takes every draft when a competitor signs out, and nothing else', () => {
        saveDraft(1, sheet());
        saveDraft(2, sheet());
        localStorage.setItem('student-token', 'not ours to touch');

        dropAllDrafts();

        expect(localStorage.getItem('soahtc.attempt.1')).toBeNull();
        expect(localStorage.getItem('soahtc.attempt.2')).toBeNull();
        expect(localStorage.getItem('student-token')).toBe('not ours to touch');
    });
});

/**
 * A private window, site data switched off, a full quota. None of it may
 * interrupt an exam.
 */
describe('storage that will not co-operate', () => {
    it('does not throw when writing is refused', () => {
        const refuse = vi.spyOn(window.localStorage, 'setItem').mockImplementation(() => {
            throw new DOMException('QuotaExceededError');
        });

        expect(() => saveDraft(7933, sheet({ essay: { 1: 'lost, but quietly' } }))).not.toThrow();

        refuse.mockRestore();
    });

    it('does not throw when reading is refused', () => {
        const refuse = vi.spyOn(window.localStorage, 'getItem').mockImplementation(() => {
            throw new DOMException('SecurityError');
        });

        expect(loadDraft(7933)).toBeNull();

        refuse.mockRestore();
    });
});
