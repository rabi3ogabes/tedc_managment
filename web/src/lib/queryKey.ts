/**
 * The key a GET is remembered under: the address, its parameters, the language (content is localised on the server) and, for
 * everything a signed-in person reads, the role they are working in.
 *
 * A person with several roles (a supervisor who is also a trainee) gets different answers from the same address in each role: the
 * role is sent as `X-Active-Role`, not in the address. Without the role in the key, a page that stayed on screen across a switch kept
 * showing what it had loaded for the role before. With it, a switch changes every key, so every page loads its own copy for the new
 * role, and the two roles can never show each other's data.
 *
 * Public data (`/public/…`) is the same for everyone. It keeps its three-part key: it is fetched ahead and remembered between visits
 * under that key (see main.tsx and ProgramCard).
 */
export function queryKeyFor(url: string | null, params: Record<string, unknown> | undefined, language: string, roleId: string | null): readonly unknown[] {
  const personal = !!url && !url.startsWith('/public/')
  return personal && roleId ? [url, params, language, roleId] : [url, params, language]
}
