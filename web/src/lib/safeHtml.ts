import DOMPurify from 'dompurify'

/**
 * HTML that came out of an uploaded file (a Word document, for example) is data, never code: scripts, event handlers,
 * javascript: links and embedded frames are removed before it is put on the page. Links open in a new tab safely.
 */
export function safeHtml(dirty: string): string {
  const clean = DOMPurify.sanitize(dirty, { USE_PROFILES: { html: true }, FORBID_TAGS: ['style', 'form', 'iframe', 'object', 'embed'], FORBID_ATTR: ['style'] })
  const doc = new DOMParser().parseFromString(clean, 'text/html')
  doc.querySelectorAll('a[href]').forEach((a) => { a.setAttribute('target', '_blank'); a.setAttribute('rel', 'noopener noreferrer nofollow') })
  return doc.body.innerHTML
}
