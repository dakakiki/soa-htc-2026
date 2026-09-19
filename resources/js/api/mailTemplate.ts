import { http } from '@/api/http';

/**
 * The e-mail template behind Website → Notifications (ADR-0132).
 *
 * Every field is an OVERRIDE of something the mail already borrows — the logo
 * from the theme, the name beside it from the site title, the footer paragraph
 * from the website's own footer block. So each one arrives twice: the stored
 * value, which is what the box holds and is usually empty, and `effective`,
 * which is what a letter sent right now would actually carry.
 */
export interface MailTemplateSettings {
    header_text: string | null;
    greeting: string | null;
    signoff: string | null;
    footer_text: string | null;
    footer_web: string | null;
    footer_email: string | null;
    logo_url: string | null;
    effective: {
        header_text: string;
        greeting: string | null;
        signoff: string | null;
        footer_text: string | null;
        logo_url: string | null;
    };
    defaults: {
        greeting: string;
        signoff: string;
    };
}

export interface MailTemplateFields {
    mail_header_text: string;
    mail_greeting: string;
    mail_signoff: string;
    mail_footer_text: string;
    mail_footer_web: string;
    mail_footer_email: string;
}

export function getMailTemplate() {
    return http.get<MailTemplateSettings>('/api/settings/mail-template');
}

/** Save the wording; the logo rides along as multipart with method spoofing. */
export function updateMailTemplate(fields: MailTemplateFields, logo?: File | null) {
    const fd = new FormData();
    (Object.keys(fields) as (keyof MailTemplateFields)[]).forEach((key) => fd.append(key, fields[key]));
    if (logo) {
        fd.append('mail_logo', logo);
    }
    fd.append('_method', 'PUT');
    return http.post<MailTemplateSettings>('/api/settings/mail-template', fd);
}

/** Drop the mail's own logo, which puts the header back on the theme's. */
export function deleteMailTemplateLogo() {
    return http.delete<MailTemplateSettings>('/api/settings/mail-template/assets/logo');
}
