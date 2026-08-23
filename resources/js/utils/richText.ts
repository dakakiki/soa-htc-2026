/**
 * Admin-authored rich text rendered where only plain text fits — table cells and
 * pickers, which need one predictable line per row.
 *
 * Parsing through the browser rather than a tag-stripping regex keeps entities
 * readable (`&amp;` → `&`) and, more importantly, never leaves half-formed markup
 * behind: whatever comes back is text, so it is safe to interpolate.
 */
export function toPlainText(html: string | null | undefined): string {
    if (!html) {
        return '';
    }

    const parsed = new DOMParser().parseFromString(html, 'text/html');

    // Block-level content arrives as separate elements; a space keeps words apart.
    return (parsed.body.textContent ?? '').replace(/\s+/g, ' ').trim();
}
