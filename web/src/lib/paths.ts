/** A path inside the app only ("/portal/…"), never another site ("//evil.example", "/\\evil.example", "https://…"). */
export function safePath(p: string | null | undefined): string | undefined {
  return p && p.startsWith('/') && !p.startsWith('//') && !p.startsWith('/\\') ? p : undefined
}
