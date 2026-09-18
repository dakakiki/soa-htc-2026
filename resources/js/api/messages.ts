import { http } from '@/api/http';
import type { Paginated } from '@/types/models';

/**
 * Messages to coordinators (2026-09-15).
 *
 * The administration writes one text and decides who gets it, on which
 * channels, and when. `recipients` is what the audience picker asks before
 * anything is sent — and it is answered by the same query that will pick the
 * people, so the number on the screen cannot drift from the number that goes.
 */
export type MessageStatus = 'draft' | 'scheduled' | 'sent';
export type MessageChannel = 'app' | 'mail' | 'push';

/**
 * Four lists that multiply: roles AND countries AND venues AND people. An
 * empty list narrows nothing, so an empty audience is every coordinator of the
 * season, and `{roles: [countryCoordinator], countries: [rs, hr]}` is the
 * country coordinators of two countries.
 */
export interface MessageAudience {
    roles: number[];
    countries: number[];
    venues: number[];
    users: number[];
}

export interface Message {
    id: number;
    subject: string;
    /** The plain, short text a notification and the in-app notice carry. */
    body: string | null;
    /** What the editor wrote, and what the mail carries. */
    body_html: string | null;
    audience: MessageAudience;
    /** The audience in words, built by the server for the list screen. */
    audience_label?: string;
    channels: MessageChannel[];
    status: MessageStatus;
    send_at: string | null;
    sent_at: string | null;
    recipients_count: number | null;
    /** Only on the list: what actually left, as opposed to what was addressed. */
    delivered_count?: number;
    failed_count?: number;
    author?: { id: number; name: string } | null;
}

export interface MessagePayload {
    subject: string;
    body: string | null;
    body_html: string | null;
    audience: MessageAudience;
    channels: MessageChannel[];
    status: Exclude<MessageStatus, 'sent'>;
    send_at?: string | null;
}

export interface MessageListParams {
    page?: number;
    per_page?: number;
    search?: string;
    status?: MessageStatus | '';
    channel?: MessageChannel | '';
}

export function listMessages(params: MessageListParams = {}) {
    return http.get<Paginated<Message>>('/api/messages', { params });
}

export function getMessage(id: number) {
    return http.get<{ data: Message }>(`/api/messages/${id}`);
}

export function createMessage(payload: MessagePayload) {
    return http.post<{ data: Message }>('/api/messages', payload);
}

export function updateMessage(id: number, payload: MessagePayload) {
    return http.put<{ data: Message }>(`/api/messages/${id}`, payload);
}

export function deleteMessage(id: number) {
    return http.delete(`/api/messages/${id}`);
}

/** How many coordinators an audience comes to, asked before sending. */
export function countRecipients(audience: MessageAudience) {
    return http.post<{ data: { count: number } }>('/api/messages/recipients', { audience });
}

/**
 * The people a filter currently matches — the list the coordinator picker is
 * ticked out of. The same resolver answers it, so a country already chosen
 * cannot offer somebody it has excluded.
 */
export function listRecipients(audience: MessageAudience, search?: string) {
    return http.post<{ data: { id: number; name: string; email: string }[]; meta: { total: number } }>(
        '/api/messages/recipients/list',
        { audience: { ...audience, users: [] }, search },
    );
}

/** Send it now: the in-app notices go at once, the mails are left owed. */
/**
 * The coordinator's own end of it: what is waiting for the person signed in.
 *
 * 🔴 No audience, no filter and no id — the server answers only about the
 * deliveries addressed to them, which is why these two carry no permission
 * (see `MessageController::inbox`).
 */
export interface InboxMessage {
    /** The DELIVERY's id, which is what putting it away names. */
    id: number;
    subject: string;
    body: string;
    sent_at: string | null;
}

/**
 * One person's own notices: what is waiting for them, newest first, ten at a
 * time.
 *
 * 🔴 Putting one away takes it off this list (owner, 2026-09-18). The delivery
 * row keeps `dismissed_at` so the administration's record of what was sent to
 * whom stays whole — it leaves their view, not the database.
 *
 * 🪤 `meta.waiting` is the TRUE count and not the size of the page, because the
 * bell in both shells is drawn from it.
 */
export function messageInbox(before?: number) {
    return http.get<{ data: InboxMessage[]; meta: { waiting: number; has_more: boolean } }>(
        '/api/messages/inbox',
        // 🪤 A cursor rather than a page number: notices arrive while somebody
        // reads, and a page number would show them a row twice and hide another.
        { params: before ? { before } : {} },
    );
}

export function dismissMessage(deliveryId: number) {
    return http.post(`/api/messages/deliveries/${deliveryId}/dismiss`);
}

export function sendMessage(id: number) {
    return http.post<{ data: Message }>(`/api/messages/${id}/send`);
}
