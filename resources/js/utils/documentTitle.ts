import { useThemeStore } from '@/stores/theme';

/**
 * Keep the browser tab in step with client-side navigation.
 *
 * The server renders the head for the address that was actually requested
 * (SpaController), which is what a crawler and a shared link need. Moving
 * around inside the SPA never reloads the document, so without this the tab
 * keeps whatever title the first page had.
 */
export function setDocumentTitle(title: string | null): void {
    const theme = useThemeStore().theme;
    // `site_title` is rich text from the theme editor; the tab wants words.
    const site = (theme?.site_title ?? '').replace(/<[^>]*>/g, '').trim() || 'SOA HTC';

    document.title = title ? `${title} · ${site}` : site;
}
