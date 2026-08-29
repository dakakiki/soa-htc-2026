/**
 * What the competitor has typed, kept on their own device until the attempt is
 * handed in.
 *
 * Nothing else keeps it. Answers reach the database exactly once, from
 * `AttemptController::submit`, and the resume payload an attempt comes back with
 * carries the questions and the server's remaining time but no given answer — so
 * a refreshed or crashed tab used to return with the clock still running and an
 * empty sheet.
 *
 * A draft is a convenience on one device and never a source of truth. It is
 * stored under its attempt's own id, and an attempt id is only ever handed to the
 * session that owns it, so one competitor's draft cannot be opened by another:
 * the next person at a venue machine starts their own attempt and gets their own
 * id. What is left is data at rest, and that is why a handed-in attempt drops its
 * draft, signing out drops all of them, and anything older than half a day is
 * treated as abandoned.
 */

const PREFIX = 'soahtc.attempt.';

/** Past this, a draft belongs to an exam nobody is sitting any more. */
const MAX_AGE_MS = 12 * 60 * 60 * 1000;

/** The answer state of one attempt, in the shapes the exam screen holds it. */
export interface AttemptAnswers {
    mc: Record<number, number[]>;
    gaps: Record<number, string[]>;
    essay: Record<number, string>;
}

interface StoredDraft extends AttemptAnswers {
    saved_at: number;
}

/**
 * Storage can be missing or refuse to answer — a private window, site data
 * switched off, a full quota. None of that may interrupt an exam, so every
 * access here fails quietly and the screen carries on exactly as it did before
 * any of this existed.
 */
function storage(): Storage | null {
    try {
        return window.localStorage;
    } catch {
        return null;
    }
}

function key(attemptId: number): string {
    return `${PREFIX}${attemptId}`;
}

const isNumberArray = (v: unknown): v is number[] => Array.isArray(v) && v.every((n) => typeof n === 'number');
const isStringArray = (v: unknown): v is string[] => Array.isArray(v) && v.every((s) => typeof s === 'string');
const isString = (v: unknown): v is string => typeof v === 'string';

/** Read back a map keyed by question id, dropping anything of the wrong shape. */
function toMap<T>(value: unknown, valid: (v: unknown) => v is T): Record<number, T> {
    const out: Record<number, T> = {};

    if (typeof value !== 'object' || value === null) {
        return out;
    }

    for (const [id, entry] of Object.entries(value)) {
        if (Number.isInteger(Number(id)) && valid(entry)) {
            out[Number(id)] = entry;
        }
    }

    return out;
}

/** Write this attempt's answers over whatever was there. */
export function saveDraft(attemptId: number, answers: AttemptAnswers): void {
    const store = storage();

    if (store === null) {
        return;
    }

    const draft: StoredDraft = { ...answers, saved_at: Date.now() };

    try {
        store.setItem(key(attemptId), JSON.stringify(draft));
    } catch {
        // Out of quota, or a browser that refuses to write. Nothing to do about
        // it here, and nothing that should stop the competitor from answering.
    }
}

/**
 * This attempt's answers, or null when there are none to put back. Parsed
 * defensively: whatever comes out of storage is text somebody could have edited,
 * and the caller applies it only to questions the attempt actually contains.
 */
export function loadDraft(attemptId: number): AttemptAnswers | null {
    const store = storage();

    if (store === null) {
        return null;
    }

    let raw: string | null = null;

    try {
        raw = store.getItem(key(attemptId));
    } catch {
        return null;
    }

    if (raw === null) {
        return null;
    }

    let parsed: unknown;

    try {
        parsed = JSON.parse(raw);
    } catch {
        return null;
    }

    if (typeof parsed !== 'object' || parsed === null) {
        return null;
    }

    const savedAt = (parsed as { saved_at?: unknown }).saved_at;

    if (typeof savedAt !== 'number' || Date.now() - savedAt > MAX_AGE_MS) {
        clearDraft(attemptId);

        return null;
    }

    const draft = parsed as Partial<StoredDraft>;

    return {
        mc: toMap(draft.mc, isNumberArray),
        gaps: toMap(draft.gaps, isStringArray),
        essay: toMap(draft.essay, isString),
    };
}

/** Forget one attempt — handed in, so there is nothing left to recover. */
export function clearDraft(attemptId: number): void {
    const store = storage();

    try {
        store?.removeItem(key(attemptId));
    } catch {
        // Same as above: never worth an error on an exam screen.
    }
}

/** Every draft this browser is holding, newest first is irrelevant — all of them. */
function draftKeys(store: Storage): string[] {
    const keys: string[] = [];

    for (let i = 0; i < store.length; i++) {
        const found = store.key(i);

        if (found !== null && found.startsWith(PREFIX)) {
            keys.push(found);
        }
    }

    return keys;
}

/**
 * Drop every draft. Called when a competitor signs out, because the machine in
 * front of them is very often a venue's rather than their own.
 */
export function dropAllDrafts(): void {
    const store = storage();

    if (store === null) {
        return;
    }

    try {
        for (const found of draftKeys(store)) {
            store.removeItem(found);
        }
    } catch {
        // Nothing to do.
    }
}

/**
 * Drop drafts nobody can still be sitting. A competitor may legitimately have
 * more than one test open at a time, so age is the only thing that says a draft
 * is finished with — not "some other attempt started".
 */
export function dropStaleDrafts(): void {
    const store = storage();

    if (store === null) {
        return;
    }

    /*
     * 🪤 Judged one at a time, and that is the whole point of the shape. Around
     * the loop instead, a single draft that will not parse would abort the sweep
     * before it reached the others — and since nothing else removes these keys,
     * "the rest age out on their own" would not be true: they would sit there for
     * as long as the browser did.
     */
    for (const found of draftKeys(store)) {
        try {
            const raw = store.getItem(found);
            const savedAt = raw === null ? null : (JSON.parse(raw) as { saved_at?: unknown }).saved_at;

            if (typeof savedAt !== 'number' || Date.now() - savedAt > MAX_AGE_MS) {
                store.removeItem(found);
            }
        } catch {
            // Ours by its prefix, and unreadable: nothing can be recovered from it.
            store.removeItem(found);
        }
    }
}
