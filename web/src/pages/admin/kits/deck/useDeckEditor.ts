import { useCallback, useEffect, useMemo, useRef, useState } from 'react'
import { api } from '@/lib/api'
import type { KitFile } from '../types'
import { slideSignature, type Deck, type Slide } from './model'

export type SaveStatus = 'loading' | 'saved' | 'dirty' | 'saving' | 'conflict' | 'error'
export type Presence = { user_id: string; name: string; slide_id: string | null; at: number }
export type Conflict = { slide_id: string; server: Slide }

type DeckResponse = { file: KitFile; deck: Deck | null; revision: number; presence: Presence[]; can_edit: boolean }

const hasDataUri = (s: Slide) => s.elements.some((e) => e.type === 'image' && (e.src ?? '').startsWith('data:'))

/** Mutators must be immutable (return new objects), never edit the deck in place. */
/**
 * State of the slide editor: history, slide-level autosave, merging of the other team's changes
 * (developer and QA edit the same deck) and conflict resolution.
 */
export function useDeckEditor(kitId: string, fileId: string, canEditProp: boolean, userId: string | undefined) {
  const base = `/admin/kits/${kitId}/files/${fileId}`
  const [deck, setDeckState] = useState<Deck | null>(null)
  const [file, setFile] = useState<KitFile | null>(null)
  const [status, setStatus] = useState<SaveStatus>('loading')
  const [conflicts, setConflicts] = useState<Conflict[]>([])
  const [presence, setPresence] = useState<Presence[]>([])
  const [canEdit, setCanEdit] = useState(canEditProp)
  const [error, setError] = useState<string | null>(null)
  const [, setTick] = useState(0)

  const deckRef = useRef<Deck | null>(null)
  const saved = useRef(new Map<string, string>())
  const revs = useRef(new Map<string, number>())
  const revision = useRef(0)
  const past = useRef<Deck[]>([])
  const future = useRef<Deck[]>([])
  const lastGroup = useRef<{ key: string; at: number } | null>(null)
  const saving = useRef(false)
  const timer = useRef<ReturnType<typeof setTimeout> | null>(null)
  const currentSlide = useRef<string | null>(null)
  const conflictRef = useRef<Conflict[]>([])
  conflictRef.current = conflicts

  const commit = useCallback((next: Deck) => {
    deckRef.current = next
    setDeckState(next)
  }, [])

  const learn = useCallback((d: Deck) => {
    saved.current = new Map(d.slides.map((s) => [s.id, slideSignature(s)]))
    revs.current = new Map(d.slides.map((s) => [s.id, s.rev]))
  }, [])

  /** Applies remote changes without touching slides the user has edited but not saved yet. */
  const mergeRemote = useCallback((local: Deck, remote: Deck, sent: Set<string> = new Set(), conflicting: Set<string> = new Set()): Deck => {
    const localById = new Map(local.slides.map((s) => [s.id, s]))
    const remoteById = new Map(remote.slides.map((s) => [s.id, s]))
    const isDirty = (s: Slide) => saved.current.get(s.id) !== slideSignature(s)
    const slides: Slide[] = []

    for (const l of local.slides) {
      const r = remoteById.get(l.id)
      if (!r) {
        if (saved.current.has(l.id) && !isDirty(l)) { saved.current.delete(l.id); revs.current.delete(l.id); continue } // deleted by the other team
        if (sent.has(l.id)) continue
        slides.push(l)
        continue
      }
      if (conflicting.has(l.id)) { slides.push(l); continue }
      if (sent.has(l.id)) {
        const merged = hasDataUri(l) ? r : { ...l, rev: r.rev, by: r.by, by_name: r.by_name, at: r.at }
        saved.current.set(l.id, slideSignature(merged))
        revs.current.set(l.id, r.rev)
        slides.push(merged)
      } else if (r.rev > l.rev && !isDirty(l)) {
        saved.current.set(l.id, slideSignature(r))
        revs.current.set(l.id, r.rev)
        slides.push(r)
      } else slides.push(l)
    }
    remote.slides.forEach((r, i) => {
      if (!localById.has(r.id)) {
        saved.current.set(r.id, slideSignature(r))
        revs.current.set(r.id, r.rev)
        slides.splice(Math.min(i, slides.length), 0, r)
      }
    })

    const anyDirty = slides.some(isDirty)
    if (!anyDirty) {
      const rank = new Map(remote.slides.map((s, i) => [s.id, i]))
      slides.sort((a, b) => (rank.get(a.id) ?? 999) - (rank.get(b.id) ?? 999))
    }
    return { ...local, slides, theme: anyDirty ? local.theme : remote.theme }
  }, [])

  const load = useCallback(async () => {
    try {
      const res = (await api.get<{ data: DeckResponse }>(`${base}/deck`)).data.data
      setFile(res.file)
      setCanEdit(res.can_edit)
      setPresence(res.presence)
      if (res.deck) {
        revision.current = res.revision
        learn(res.deck)
        past.current = []
        future.current = []
        commit(res.deck)
        setStatus('saved')
      } else setStatus('error')
    } catch (e) {
      setError(e instanceof Error ? e.message : 'error')
      setStatus('error')
    }
  }, [base, commit, learn])

  useEffect(() => { void load() }, [load])

  // Changes to the deck: history + dirty tracking.
  const apply = useCallback((mutator: (d: Deck) => Deck, opts: { history?: boolean; group?: string } = {}) => {
    const cur = deckRef.current
    if (!cur) return
    const next = mutator(cur)
    if (opts.history !== false) {
      const now = Date.now()
      const grouped = opts.group && lastGroup.current?.key === opts.group && now - lastGroup.current.at < 900
      if (!grouped) { past.current.push(cur); if (past.current.length > 80) past.current.shift() }
      lastGroup.current = opts.group ? { key: opts.group, at: now } : null
      future.current = []
    }
    commit(next)
    setTick((t) => t + 1)
  }, [commit])

  /** Remembers the current state as an undo step (called once at the start of a drag or resize). */
  const checkpoint = useCallback(() => {
    const cur = deckRef.current
    if (!cur) return
    past.current.push(cur)
    if (past.current.length > 80) past.current.shift()
    future.current = []
    lastGroup.current = null
    setTick((t) => t + 1)
  }, [])

  const undo = useCallback(() => {
    const prev = past.current.pop()
    if (!prev || !deckRef.current) return
    future.current.push(deckRef.current)
    lastGroup.current = null
    commit(prev)
    setTick((t) => t + 1)
  }, [commit])

  const redo = useCallback(() => {
    const next = future.current.pop()
    if (!next || !deckRef.current) return
    past.current.push(deckRef.current)
    lastGroup.current = null
    commit(next)
    setTick((t) => t + 1)
  }, [commit])

  const pending = useMemo(() => {
    if (!deck) return { changed: [] as string[], deleted: [] as string[], order: false }
    const changed = deck.slides.filter((s) => saved.current.get(s.id) !== slideSignature(s)).map((s) => s.id)
    const deleted = [...saved.current.keys()].filter((id) => !deck.slides.some((s) => s.id === id))
    return { changed, deleted, order: false }
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [deck, status])

  const save = useCallback(async (opts: { force?: boolean; only?: string[] } = {}) => {
    const d = deckRef.current
    if (!d || !canEdit || saving.current) return
    const onlyOk = (id: string) => !opts.only || opts.only.includes(id)
    const changed = d.slides.filter((s) => saved.current.get(s.id) !== slideSignature(s) && onlyOk(s.id))
    const deleted = [...saved.current.keys()].filter((id) => !d.slides.some((s) => s.id === id) && onlyOk(id)).map((id) => ({ id, rev: revs.current.get(id) ?? 0 }))
    const serverOrder = [...saved.current.keys()]
    const orderChanged = !opts.only && d.slides.map((s) => s.id).join() !== serverOrder.filter((id) => d.slides.some((s) => s.id === id)).join()
    if (!changed.length && !deleted.length && !orderChanged) { if (status !== 'conflict') setStatus('saved'); return }

    saving.current = true
    setStatus('saving')
    const sent = new Set(changed.map((s) => s.id))
    try {
      const res = await api.put<{ data: { deck: Deck; revision: number; conflicts: Conflict[]; presence: Presence[] } }>(`${base}/deck`, { slides: changed, deleted, order: d.slides.map((s) => s.id), force: opts.force ?? false }, { validateStatus: (s) => s === 200 || s === 409 })
      const data = res.data.data
      const conflicting = new Set(data.conflicts.map((c) => c.slide_id))
      deleted.forEach((g) => { if (!conflicting.has(g.id)) { saved.current.delete(g.id); revs.current.delete(g.id) } })
      const merged = mergeRemote(deckRef.current ?? d, data.deck, sent, conflicting)
      revision.current = data.revision
      commit(merged)
      setPresence(data.presence ?? [])
      setConflicts((cur) => [...cur.filter((c) => !sent.has(c.slide_id)), ...data.conflicts])
      setStatus(data.conflicts.length ? 'conflict' : 'saved')
    } catch (e) {
      setError(e instanceof Error ? e.message : 'error')
      setStatus('error')
    } finally {
      saving.current = false
    }
  }, [base, canEdit, commit, mergeRemote, status])

  // Autosave 1.5 s after the last change.
  useEffect(() => {
    if (!deck || !canEdit || status === 'loading' || status === 'saving' || status === 'conflict') return
    const dirty = pending.changed.length > 0 || pending.deleted.length > 0 || deck.slides.map((s) => s.id).join() !== [...saved.current.keys()].filter((id) => deck.slides.some((s) => s.id === id)).join()
    if (!dirty) { if (status !== 'saved') setStatus('saved'); return }
    if (status !== 'dirty') setStatus('dirty')
    if (timer.current) clearTimeout(timer.current)
    timer.current = setTimeout(() => void save(), 1500)
    return () => { if (timer.current) clearTimeout(timer.current) }
  }, [deck, pending, canEdit, status, save])

  // Presence + picking up the other team's saves.
  useEffect(() => {
    if (!deck) return
    const tick = async () => {
      try {
        const res = (await api.post<{ data: { presence: Presence[]; revision: number } }>(`${base}/heartbeat`, { slide_id: currentSlide.current })).data.data
        setPresence(res.presence)
        if (res.revision > revision.current && !saving.current) {
          const fresh = (await api.get<{ data: DeckResponse }>(`${base}/deck`)).data.data
          if (fresh.deck && deckRef.current) {
            revision.current = fresh.revision
            commit(mergeRemote(deckRef.current, fresh.deck))
          }
        }
      } catch { /* offline: try again next tick */ }
    }
    void tick()
    const id = setInterval(tick, 8000)
    return () => clearInterval(id)
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [base, !!deck])

  useEffect(() => {
    const before = (e: BeforeUnloadEvent) => { if (status === 'dirty' || status === 'saving' || status === 'conflict') e.preventDefault() }
    window.addEventListener('beforeunload', before)
    return () => window.removeEventListener('beforeunload', before)
  }, [status])

  useEffect(() => () => {
    if (timer.current) clearTimeout(timer.current)
    void save().finally(() => api.delete(`${base}/presence`).catch(() => undefined))
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [])

  const resolveConflict = useCallback(async (slideId: string, choice: 'mine' | 'theirs') => {
    const c = conflictRef.current.find((x) => x.slide_id === slideId)
    if (!c) return
    if (choice === 'theirs') {
      const cur = deckRef.current
      if (cur) {
        saved.current.set(slideId, slideSignature(c.server))
        revs.current.set(slideId, c.server.rev)
        commit({ ...cur, slides: cur.slides.map((s) => (s.id === slideId ? c.server : s)) })
      }
      setConflicts((cur) => cur.filter((x) => x.slide_id !== slideId))
      setStatus('dirty')
    } else {
      setConflicts((cur) => cur.filter((x) => x.slide_id !== slideId))
      await save({ force: true, only: [slideId] })
    }
  }, [commit, save])

  const replaceDeck = useCallback((d: Deck, rev: number) => {
    revision.current = rev
    learn(d)
    past.current = []
    future.current = []
    setConflicts([])
    commit(d)
    setStatus('saved')
  }, [commit, learn])

  const setCurrentSlide = useCallback((id: string | null) => { currentSlide.current = id }, [])
  const others = useMemo(() => presence.filter((p) => p.user_id !== userId), [presence, userId])

  return {
    deck, file, status, conflicts, presence, others, canEdit, error, apply, checkpoint, undo, redo, save, resolveConflict, replaceDeck, reload: load, setCurrentSlide, setFile,
    canUndo: past.current.length > 0, canRedo: future.current.length > 0, pendingCount: pending.changed.length + pending.deleted.length, dirtyIds: new Set(pending.changed),
  }
}
