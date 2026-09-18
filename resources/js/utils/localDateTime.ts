/**
 * The two ends of a `<input type="datetime-local">`.
 *
 * 🔴 The application stores time in UTC and that is right: a coordinator in
 * Belgrade, one in Riyadh and one in Ulaanbaatar all read the same row, and the
 * only hour they can agree on is the one nobody is standing in. What was wrong
 * was the BOUNDARY. A `datetime-local` input holds a bare wall clock —
 * `2026-09-20T08:00`, with no zone on it at all — and that string was being
 * handed to the server as if it were already UTC. Measured on the staging
 * server: an administrator picking 08:00 stored `08:00 UTC`, which is
 * **10:00 in Belgrade**. A message written for the morning went out mid-morning,
 * and a page scheduled to appear at nine appeared at eleven.
 *
 * 🪤 Reading it back had the same fault mirrored: `send_at.slice(0, 16)` takes
 * the UTC wall clock and drops it into a picker that means local time, so
 * reopening a message showed an hour nobody had chosen.
 *
 * So both ends convert, and the browser is the only thing that has to know
 * which zone the reader is in — which it does, and the server never needs to.
 */

/**
 * The server's instant → what the picker should show: the same moment on the
 * reader's own clock, in the `YYYY-MM-DDTHH:mm` shape the input requires.
 *
 * 🪤 Via the offset rather than `toISOString()` directly, because that prints
 * UTC and UTC is exactly what must not reach the picker.
 */
export function toLocalInput(value: string | null | undefined): string {
    if (!value) {
        return '';
    }

    const at = new Date(value);

    if (Number.isNaN(at.getTime())) {
        return '';
    }

    const shifted = new Date(at.getTime() - at.getTimezoneOffset() * 60_000);

    return shifted.toISOString().slice(0, 16);
}

/**
 * What the picker holds → the instant to store, as ISO-8601 in UTC.
 *
 * 🪤 A bare `2026-09-20T08:00` is read by `Date` in the BROWSER'S OWN zone,
 * which is the whole trick here: the reader typed their local time, so that is
 * what it means, and `toISOString()` then says the same moment in UTC.
 * (A date with no time — `2026-09-20` — would be read as UTC instead, which is
 * why nothing here accepts one.)
 */
export function fromLocalInput(value: string | null | undefined): string | null {
    if (!value) {
        return null;
    }

    const at = new Date(value);

    return Number.isNaN(at.getTime()) ? null : at.toISOString();
}
