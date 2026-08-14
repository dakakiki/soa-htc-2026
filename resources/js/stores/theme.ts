import { defineStore } from 'pinia';
import { ref } from 'vue';
import { getTheme } from '@/api/theme';
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
};

/** Push the brand colours onto :root as CSS variables Tailwind utilities read. */
function applyColors(colors: Record<ThemeColorKey, string>): void {
    const root = document.documentElement;
    (Object.entries(colors) as [ThemeColorKey, string][]).forEach(([key, value]) => {
        root.style.setProperty(`--color-brand-${key.replace(/_/g, '-')}`, value);
    });
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

export const useThemeStore = defineStore('theme', () => {
    const theme = ref<Theme | null>(null);

    function apply(next: Theme): void {
        theme.value = next;
        applyColors(next.colors);
        applyFavicon(next.logo_icon_url ?? next.logo_url);
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
