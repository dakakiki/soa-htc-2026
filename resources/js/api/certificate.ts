import { http } from '@/api/http';

export interface CertPlaceholder {
    tag: string;
    description: string;
}

/** Admin-editable certificate content + assets (Settings → Certificate). */
export interface CertSettings {
    header_title: string | null;
    body: string;
    signature_text: string | null;
    logo_url: string | null;
    signature_url: string | null;
    qr_url: string | null;
    placeholders: CertPlaceholder[];
}

export interface CertFiles {
    cert_logo?: File | null;
    cert_signature?: File | null;
    cert_qr?: File | null;
}

export function getCertificate() {
    return http.get<CertSettings>('/api/settings/certificate');
}

/** Save the body + signature caption; images via multipart with method spoofing. */
export function updateCertificate(fields: { cert_header_title: string; cert_body: string; cert_signature_text: string }, files?: CertFiles) {
    const fd = new FormData();
    fd.append('cert_header_title', fields.cert_header_title);
    fd.append('cert_body', fields.cert_body);
    fd.append('cert_signature_text', fields.cert_signature_text);
    if (files?.cert_logo) {
        fd.append('cert_logo', files.cert_logo);
    }
    if (files?.cert_signature) {
        fd.append('cert_signature', files.cert_signature);
    }
    if (files?.cert_qr) {
        fd.append('cert_qr', files.cert_qr);
    }
    fd.append('_method', 'PUT');
    return http.post<CertSettings>('/api/settings/certificate', fd);
}

export type CertAsset = 'logo' | 'signature' | 'qr';

/** Delete one uploaded asset (logo / signature / QR) from the server. */
export function deleteCertificateAsset(asset: CertAsset) {
    return http.delete<CertSettings>(`/api/settings/certificate/assets/${asset}`);
}
