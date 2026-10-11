/** A one-time address for sending a file straight to storage, as the API hands it out. */
export type SignedUpload = { path?: string; url: string; method: 'PUT' | 'POST'; mode: 'form' | 'raw'; headers: Record<string, string> }

/** Sends a big file straight to storage with a progress callback (the API cannot take large bodies on serverless hosting). */
export function putFile(signed: SignedUpload, file: File, onProgress: (percent: number) => void, signal?: AbortSignal): Promise<void> {
  return new Promise((resolve, reject) => {
    const xhr = new XMLHttpRequest()
    if (signal?.aborted) { reject(new DOMException('Aborted', 'AbortError')); return }
    signal?.addEventListener('abort', () => xhr.abort(), { once: true })
    xhr.onabort = () => reject(new DOMException('Aborted', 'AbortError'))
    xhr.open(signed.method, signed.url)
    Object.entries(signed.headers ?? {}).forEach(([k, v]) => xhr.setRequestHeader(k, v))
    xhr.upload.onprogress = (e) => e.lengthComputable && onProgress(Math.round((e.loaded / e.total) * 100))
    xhr.onload = () => (xhr.status >= 200 && xhr.status < 300 ? resolve() : reject(new Error(`Upload failed (${xhr.status})`)))
    xhr.onerror = () => reject(new Error('Upload failed'))
    if (signed.mode === 'form') {
      const form = new FormData()
      form.append('cacheControl', '3600')
      form.append('', file)
      xhr.send(form)
    } else {
      xhr.send(file)
    }
  })
}
