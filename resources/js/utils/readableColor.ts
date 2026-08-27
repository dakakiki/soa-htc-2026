/**
 * A brand colour, darkened just enough to carry text.
 *
 * The contest's orange (`#f39200`) is 2.26:1 on the site's paper ground — it
 * fails WCAG AA at every size, large text included, so no eyebrow, category
 * label or link written in it was readable to anyone who needs contrast. Navy
 * would pass, but the orange is doing work: it separates a category from a
 * date, marks the exam password as the one thing read out loud, and says a
 * round is open. Painting all of that navy would cost the meaning, so the
 * colour is kept and its lightness is spent instead.
 *
 * 🪤 This is DERIVED, not a second hex in the palette. The four palette slots
 * are set by an administrator in Theme settings and pushed onto `:root` at
 * runtime, so a hard-coded darker orange would go stale the first time anybody
 * re-skinned the site — and go stale silently, because nothing on the page
 * announces a contrast failure. Deriving it means the guarantee survives a
 * palette nobody has chosen yet.
 *
 * Lightness is lowered in OKLCh rather than mixed toward black: mixing drags
 * the hue toward brown (#f39200 mixed 35% black is #9e5f00), while holding
 * chroma and hue and dropping L keeps it recognisably the brand's orange
 * (#af5400).
 */

/** The page colour the public and competitor shells paint (`bg-[#fbfaf8]`). */
export const PAPER = '#fbfaf8';

/**
 * A little over AA's 4.5:1 for normal text. The margin is deliberate: the
 * search walks in steps, and a colour that lands exactly on 4.5 is one
 * rounding away from failing.
 */
const TARGET = 4.8;

function toLinear(channel: number): number {
    const c = channel / 255;

    return c <= 0.04045 ? c / 12.92 : ((c + 0.055) / 1.055) ** 2.4;
}

function toChannel(linear: number): number {
    const c = linear <= 0.0031308 ? 12.92 * linear : 1.055 * linear ** (1 / 2.4) - 0.055;

    return Math.max(0, Math.min(255, Math.round(c * 255)));
}

/** `#rgb` and `#rrggbb`, with or without the hash. Null when it is neither. */
function parse(hex: string): [number, number, number] | null {
    const value = hex.trim().replace(/^#/, '');
    const full = value.length === 3 ? value.replace(/./g, (c) => c + c) : value;

    if (!/^[0-9a-f]{6}$/i.test(full)) {
        return null;
    }

    return [0, 2, 4].map((i) => parseInt(full.slice(i, i + 2), 16)) as [number, number, number];
}

function relativeLuminance([r, g, b]: [number, number, number]): number {
    return 0.2126 * toLinear(r) + 0.7152 * toLinear(g) + 0.0722 * toLinear(b);
}

/** WCAG 2.x contrast ratio, 1 to 21. */
export function contrast(a: string, b: string): number {
    const first = parse(a);
    const second = parse(b);

    if (first === null || second === null) {
        return 1;
    }

    const [light, dark] = [relativeLuminance(first), relativeLuminance(second)].sort((x, y) => y - x);

    return (light + 0.05) / (dark + 0.05);
}

function toOklch([r, g, b]: [number, number, number]): [number, number, number] {
    const lr = toLinear(r);
    const lg = toLinear(g);
    const lb = toLinear(b);

    const l = Math.cbrt(0.4122214708 * lr + 0.5363325363 * lg + 0.0514459929 * lb);
    const m = Math.cbrt(0.2119034982 * lr + 0.6806995451 * lg + 0.1073969566 * lb);
    const s = Math.cbrt(0.0883024619 * lr + 0.2817188376 * lg + 0.6299787005 * lb);

    const lightness = 0.2104542553 * l + 0.793617785 * m - 0.0040720468 * s;
    const a = 1.9779984951 * l - 2.428592205 * m + 0.4505937099 * s;
    const bAxis = 0.0259040371 * l + 0.7827717662 * m - 0.808675766 * s;

    return [lightness, Math.hypot(a, bAxis), Math.atan2(bAxis, a)];
}

function fromOklch([lightness, chroma, hue]: [number, number, number]): string {
    const a = chroma * Math.cos(hue);
    const b = chroma * Math.sin(hue);

    const l = (lightness + 0.3963377774 * a + 0.2158037573 * b) ** 3;
    const m = (lightness - 0.1055613458 * a - 0.0638541728 * b) ** 3;
    const s = (lightness - 0.0894841775 * a - 1.291485548 * b) ** 3;

    const rgb: [number, number, number] = [
        toChannel(4.0767416621 * l - 3.3077115913 * m + 0.2309699292 * s),
        toChannel(-1.2684380046 * l + 2.6097574011 * m - 0.3413193965 * s),
        toChannel(-0.0041960863 * l - 0.7034186147 * m + 1.707614701 * s),
    ];

    return '#' + rgb.map((c) => c.toString(16).padStart(2, '0')).join('');
}

/**
 * `colour`, darkened until it reads on `ground`.
 *
 * Returns it untouched when it already passes — a palette somebody has already
 * chosen dark enough is not this function's business — and when it cannot be
 * parsed, because a colour we do not understand is not one to rewrite.
 */
export function readableOn(colour: string, ground: string = PAPER, target: number = TARGET): string {
    const parsed = parse(colour);

    if (parsed === null || contrast(colour, ground) >= target) {
        return colour;
    }

    const [start, chroma, hue] = toOklch(parsed);

    // Down in hundredths of OKLCh lightness. Black is the floor and always
    // clears the target against any light ground, so this terminates.
    for (let lightness = start - 0.01; lightness > 0; lightness -= 0.01) {
        const candidate = fromOklch([lightness, chroma, hue]);

        if (contrast(candidate, ground) >= target) {
            return candidate;
        }
    }

    return '#000000';
}
