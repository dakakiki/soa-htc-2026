import { createI18n } from 'vue-i18n';
import en from '@/i18n/en';

/**
 * English is the only initially enabled locale. The structure supports adding
 * more locales later by registering additional message catalogs.
 */
export const i18n = createI18n({
    legacy: false,
    globalInjection: true,
    locale: 'en',
    fallbackLocale: 'en',
    messages: { en },
});
