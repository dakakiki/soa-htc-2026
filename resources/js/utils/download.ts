/** Pull the filename out of a Content-Disposition header, if present. */
export function filenameFromDisposition(header?: string | null): string | null {
    if (!header) {
        return null;
    }
    const match = /filename\*?=(?:UTF-8'')?"?([^";]+)"?/i.exec(header);
    return match ? decodeURIComponent(match[1]) : null;
}

/** Trigger a browser download of a Blob under the given filename. */
export function saveBlob(data: Blob, name: string): void {
    const url = URL.createObjectURL(data);
    const a = document.createElement('a');
    a.href = url;
    a.download = name;
    document.body.appendChild(a);
    a.click();
    a.remove();
    URL.revokeObjectURL(url);
}
