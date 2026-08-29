import { defineStore } from 'pinia';
import { ref } from 'vue';
import { getTheme } from '@/api/theme';
import { PAPER, readableOn } from '@/utils/readableColor';
import type { Theme, ThemeColorKey } from '@/types/models';

const DEFAULT_COLORS: Record<ThemeColorKey, string> = {
    primary: '#2563eb',
    primary_hover: '#1d4ed8',
    primary_soft: '#eff6ff',
    on_primary: '#ffffff',
    accent: '#0d9488',
    accent_hover: '#0f766e',
    link: '#2563eb',
    border: '#e5e7eb',
    palette_1: '#fbba00',
    palette_2: '#f39200',
    palette_3: '#97bddd',
    palette_4: '#003758',
};

/** Push the brand colours onto :root as CSS variables Tailwind utilities read. */
function applyColors(colors: Record<ThemeColorKey, string>): void {
    const root = document.documentElement;
    (Object.entries(colors) as [ThemeColorKey, string][]).forEach(([key, value]) => {
        root.style.setProperty(`--color-brand-${key.replace(/_/g, '-')}`, value);
    });

    /*
     * One derived colour: the accent, darkened until it can carry text on the
     * page ground ({@see readableOn}). The brand orange is 2.26:1 on paper and
     * fails AA at every size, so nothing written in it was readable - and it is
     * the palette slot that marks a category, the exam password and an open
     * round, so it could not simply be replaced with navy.
     *
     * Derived here rather than written into the palette because an
     * administrator chooses these four colours in Theme settings: a second
     * stored hex would go stale the first time they changed one, and silently,
     * since nothing on the page reports a contrast failure.
     */
    root.style.setProperty('--color-brand-ink-accent', readableOn(colors.palette_2, PAPER));
}

/** Point the browser favicon at the uploaded icon (or full logo) when present. */
function applyFavicon(url: string | null): void {
    if (!url) {
        return;
    }
    let link = document.querySelector<HTMLLinkElement>('link[rel="icon"]');
    if (!link) {
        link = document.createElement('link');
        link.rel = 'icon';
        document.head.appendChild(link);
    }
    link.href = url;
}

/**
 * The icon iOS reads when somebody adds the site to their home screen. It is not
 * covered by the manifest — Safari looks for this link and nothing else — and it
 * is read from the live page at the moment they add it, which is why setting it
 * here, after the theme has arrived, works at all.
 */
function applyTouchIcon(url: string | null): void {
    if (!url) {
        return;
    }
    let link = document.querySelector<HTMLLinkElement>('link[rel="apple-touch-icon"]');
    if (!link) {
        link = document.createElement('link');
        link.rel = 'apple-touch-icon';
        document.head.appendChild(link);
    }
    link.href = url;
}

/**
 * The colour a browser paints its own furniture with — the address bar on
 * Android, the status bar of an installed window. The manifest carries the same
 * value for the installed app; this is the tab.
 */
function applyThemeColor(color: string): void {
    let meta = document.querySelector<HTMLMetaElement>('meta[name="theme-color"]');
    if (!meta) {
        meta = document.createElement('meta');
        meta.name = 'theme-color';
        document.head.appendChild(meta);
    }
    meta.content = color;
}

export const useThemeStore = defineStore('theme', () => {
    const theme = ref<Theme | null>(null);

    function apply(next: Theme): void {
        theme.value = next;
        applyColors(next.colors);
        applyFavicon(next.logo_icon_url ?? next.logo_url);
        applyTouchIcon(next.logo_icon_url ?? next.logo_url);
        applyThemeColor(next.colors.primary);
    }

    /**
     * Fetch and apply the theme once, before the app mounts. Failures fall back
     * to the CSS defaults so the SPA still renders (and never blocks boot).
     */
    async function load(): Promise<void> {
        try {
            const { data } = await getTheme();
            apply(data.data);
        } catch {
            applyColors(DEFAULT_COLORS);
        }
    }

    return { theme, apply, load };
});
