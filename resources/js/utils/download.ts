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
