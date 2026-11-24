/**
 * A place inside this site to send the browser to, or `fallback` when the value is anything else. A remembered address is
 * read back from the browser's storage before it is used, so it is checked here: only a path of this site (`/admin/…`),
 * never another site (`https://…`, `//host`, `/\host`) and never a script (`javascript:`).
 */
export function insidePath(value: unknown, fallback: string): string {
  if (typeof value !== 'string' || value.length > 2000) return fallback
  if (!value.startsWith('/') || value.startsWith('//') || value.startsWith('/\\')) return fallback
  // No control characters or backslashes anywhere: browsers treat some of them as a slash.
  for (const ch of value) { if (ch === '\\' || ch.charCodeAt(0) < 0x20) return fallback }
  return value
}
