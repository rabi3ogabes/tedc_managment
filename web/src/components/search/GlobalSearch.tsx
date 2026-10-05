import clsx from 'clsx'
import { BookOpen, Compass, CornerDownLeft, Newspaper, PackageOpen, Search, ShieldCheck, UserRound, Users, History, X, Award } from 'lucide-react'
import { useEffect, useMemo, useRef, useState, type ComponentType, type ReactNode } from 'react'
import { useTranslation } from 'react-i18next'
import { useLocation, useNavigate } from 'react-router-dom'
import { useGet } from '@/hooks/useApi'

export type FunctionTarget = { label: string; to: string; keywords?: string[]; icon?: ComponentType<{ className?: string }> }
type Hit = { id: string; title: string; subtitle?: string | null; url: string }
type Group = { type: string; items: Hit[] }
type Row = { key: string; group: string; title: string; subtitle?: string | null; url: string; icon: ComponentType<{ className?: string }> }

const ICON: Record<string, ComponentType<{ className?: string }>> = { programs: BookOpen, people: Users, trainers: UserRound, kits: PackageOpen, certificates: Award, news: Newspaper, functions: Compass }
const RECENT = 'tedc.search.recent'
const readRecent = (): string[] => { try { return JSON.parse(localStorage.getItem(RECENT) ?? '[]') as string[] } catch { return [] } }

/** Highlights the part of a text that matches the query. */
function Mark({ text, q }: { text: string; q: string }): ReactNode {
  const i = q ? text.toLowerCase().indexOf(q.toLowerCase()) : -1
  if (i < 0) return text
  return <>{text.slice(0, i)}<mark className="rounded bg-gold-200/70 px-0.5 text-inherit">{text.slice(i, i + q.length)}</mark>{text.slice(i + q.length)}</>
}

/**
 * Ctrl/⌘ + K on every page: search programs, people, trainers, kits, certificates, news and the functions of the
 * platform. The server only returns what the active role may see; functions are the menu entries the user can open.
 */
export default function GlobalSearch({ functions, open, onClose }: { functions: FunctionTarget[]; open: boolean; onClose: () => void }) {
  const { t } = useTranslation()
  const navigate = useNavigate()
  const [q, setQ] = useState('')
  const [debounced, setDebounced] = useState('')
  const [index, setIndex] = useState(0)
  const [recent, setRecent] = useState<string[]>(readRecent)
  const list = useRef<HTMLUListElement>(null)

  useEffect(() => { const id = window.setTimeout(() => setDebounced(q.trim()), 220); return () => window.clearTimeout(id) }, [q])
  const remote = useGet<{ data: Group[] }>(open && debounced.length >= 2 ? '/search' : null, { q: debounced }, { staleTime: 30_000, retry: false })

  const rows = useMemo<Row[]>(() => {
    const term = q.trim().toLowerCase()
    const out: Row[] = []
    if (term.length >= 1) {
      functions.filter((f) => `${f.label} ${(f.keywords ?? []).join(' ')}`.toLowerCase().includes(term)).slice(0, 6)
        .forEach((f) => out.push({ key: `f:${f.to}`, group: 'functions', title: f.label, url: f.to, icon: f.icon ?? Compass }))
    }
    if (term.length >= 2 && remote.data?.data.length) {
      for (const g of remote.data.data) g.items.forEach((h) => out.push({ key: `${g.type}:${h.id}`, group: g.type, title: h.title, subtitle: h.subtitle, url: h.url, icon: ICON[g.type] ?? Search }))
    }
    return out
  }, [q, functions, remote.data])

  useEffect(() => { setIndex(0) }, [rows.length, q])
  useEffect(() => { list.current?.querySelector<HTMLElement>('[aria-selected="true"]')?.scrollIntoView({ block: 'nearest' }) }, [index])
  useEffect(() => { if (!open) { setQ(''); setDebounced('') } }, [open])

  if (!open) return null

  const go = (row: Row) => {
    const next = [q.trim(), ...recent.filter((r) => r !== q.trim())].filter(Boolean).slice(0, 6)
    try { localStorage.setItem(RECENT, JSON.stringify(next)) } catch { /* storage unavailable */ }
    setRecent(next)
    onClose()
    navigate(row.url)
  }
  const onKey = (e: React.KeyboardEvent) => {
    if (e.key === 'ArrowDown') { e.preventDefault(); setIndex((i) => Math.min(rows.length - 1, i + 1)) }
    else if (e.key === 'ArrowUp') { e.preventDefault(); setIndex((i) => Math.max(0, i - 1)) }
    else if (e.key === 'Enter' && rows[index]) { e.preventDefault(); go(rows[index]) }
    else if (e.key === 'Escape') { e.preventDefault(); onClose() }
  }
  const searching = debounced.length >= 2 && remote.isFetching
  let last = ''

  return (
    <div className="fixed inset-0 z-[65] flex items-start justify-center bg-navy-950/55 p-4 pt-[10vh] backdrop-blur-sm" onMouseDown={onClose} role="dialog" aria-modal="true" aria-label={t('search.title')}>
      <div className="card w-full max-w-2xl animate-fade-up overflow-hidden !p-0" onMouseDown={(e) => e.stopPropagation()}>
        <div className="relative border-b border-navy-100">
          <Search className="absolute start-4 top-4 size-5 text-slate-400" aria-hidden />
          <input autoFocus value={q} onChange={(e) => setQ(e.target.value)} onKeyDown={onKey} placeholder={t('search.placeholder')} aria-label={t('search.title')}
            className="w-full bg-transparent py-4 pe-12 ps-12 text-base text-navy-900 placeholder:text-slate-400 focus:outline-none" role="combobox" aria-expanded="true" aria-controls="global-search-list" />
          {q ? <button type="button" onClick={() => setQ('')} className="absolute end-3 top-3 grid size-8 place-items-center rounded-lg text-slate-400 hover:bg-slate-100" aria-label={t('search.clear')}><X className="size-4" /></button> : null}
        </div>
        <ul id="global-search-list" ref={list} role="listbox" className="max-h-[55vh] overflow-y-auto p-2">
          {!q.trim() && recent.length > 0 && (
            <>
              <li className="px-3 pb-1 pt-2 text-xs font-bold text-slate-400">{t('search.recent')}</li>
              {recent.map((r) => <li key={r}><button type="button" onClick={() => setQ(r)} className="flex w-full items-center gap-3 rounded-xl px-3 py-2 text-start text-sm text-navy-800 hover:bg-ivory"><History className="size-4 text-slate-400" />{r}</button></li>)}
            </>
          )}
          {!q.trim() && recent.length === 0 && <li className="px-4 py-10 text-center text-sm text-slate-400">{t('search.hint')}</li>}
          {q.trim() && rows.length === 0 && !searching && (
            <li className="px-4 py-10 text-center text-sm text-slate-500"><ShieldCheck className="mx-auto mb-2 size-6 text-gold-500" />{t('search.none', { q })}<div className="mt-1 text-xs text-slate-400">{t('search.suggest')}</div></li>
          )}
          {searching && rows.length === 0 && <li className="px-4 py-8 text-center text-sm text-slate-400">{t('search.searching')}</li>}
          {rows.map((r, i) => {
            const header = r.group !== last ? r.group : null
            last = r.group
            const Icon = r.icon
            return (
              <li key={r.key} role="presentation">
                {header && <div className="px-3 pb-1 pt-3 text-xs font-bold text-slate-400">{t(`search.groups.${header}`)}</div>}
                <button type="button" role="option" aria-selected={i === index} onMouseEnter={() => setIndex(i)} onClick={() => go(r)}
                  className={clsx('flex w-full items-center gap-3 rounded-xl px-3 py-2.5 text-start transition', i === index ? 'bg-navy-900 text-white' : 'text-navy-900 hover:bg-ivory')}>
                  <span className={clsx('grid size-9 shrink-0 place-items-center rounded-lg', i === index ? 'bg-gold-500 text-navy-950' : 'bg-navy-50 text-navy-700')}><Icon className="size-4" /></span>
                  <span className="min-w-0 flex-1"><span className="block truncate text-sm font-bold"><Mark text={r.title} q={q.trim()} /></span>{r.subtitle && <span className={clsx('block truncate text-xs', i === index ? 'text-white/70' : 'text-slate-500')}>{r.subtitle}</span>}</span>
                  {i === index && <CornerDownLeft className="size-4 shrink-0 opacity-70" />}
                </button>
              </li>
            )
          })}
        </ul>
        <div className="flex items-center justify-between border-t border-navy-100 bg-ivory/70 px-4 py-2 text-[11px] text-slate-500"><span>{t('search.keys')}</span><kbd className="rounded bg-white px-1.5 font-mono ring-1 ring-navy-100" dir="ltr">Esc</kbd></div>
      </div>
    </div>
  )
}

/** Opens the search with Ctrl/⌘ + K (except where a screen has its own palette). */
export function useSearchShortcut(toggle: () => void) {
  const { pathname } = useLocation()
  useEffect(() => {
    const onKey = (e: KeyboardEvent) => {
      if ((e.ctrlKey || e.metaKey) && !e.altKey && !e.shiftKey && e.key.toLowerCase() === 'k' && !pathname.startsWith('/admin/settings')) { e.preventDefault(); toggle() }
    }
    window.addEventListener('keydown', onKey)
    return () => window.removeEventListener('keydown', onKey)
  }, [pathname, toggle])
}
