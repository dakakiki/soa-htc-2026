import { vi } from 'vitest';

/**
 * jsdom has neither `IntersectionObserver` nor `ResizeObserver`, and the exam
 * screen uses both — one to know which question is being read, the other to
 * measure the height of the clock band. Neither is what any test here is about,
 * so both are answered with something inert rather than polyfilled.
 */
class InertObserver {
    observe(): void {}

    unobserve(): void {}

    disconnect(): void {}

    takeRecords(): [] {
        return [];
    }
}

vi.stubGlobal('IntersectionObserver', InertObserver);
vi.stubGlobal('ResizeObserver', InertObserver);

// Nothing in jsdom scrolls, and the question rail asks to be brought into view.
Element.prototype.scrollIntoView = vi.fn();
