/**
 * A new deployment replaces the hashed files of the site; a tab opened before it then fails to load a page it has not visited yet.
 * These are the messages browsers give for that — including React's own when a lazy page's module came back empty
 * («can't access property "default", e._result is undefined» in Firefox, «Cannot read properties of undefined (reading 'default')» in Chrome).
 */
const STALE = /Failed to fetch dynamically imported module|Importing a module script failed|error loading dynamically imported module|ChunkLoadError|Loading chunk .* failed|Unable to preload CSS|e\._result is undefined|can't access property "default"|Cannot read properties of undefined \(reading 'default'\)/i

export function isStaleCodeError(error: unknown): boolean {
  return STALE.test(String((error as Error | undefined)?.message ?? error ?? ''))
}
