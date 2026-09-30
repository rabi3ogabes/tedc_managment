import clsx from 'clsx'
import { ChevronLeft, ChevronRight, Download, Languages, MousePointerClick, PanelLeft, PencilLine, RotateCcw, Save, Search, Trash2, Undo2, Upload } from 'lucide-react'
import { useDeferredValue, useMemo, useRef, useState } from 'react'
import { useTranslation } from 'react-i18next'
import { Badge, Button, Card, PageHeader, Spinner } from '@/components/ui'
import { useGet } from '@/hooks/useApi'
import { api, errorMessage } from '@/lib/api'
import { fmt } from '@/lib/format'
import { applyLabels, baseLabels, cacheLabels, type LabelSet, type Lng } from '@/lib/labels'

type Payload = { data: LabelSet; version: string }
type Draft = Record<string, Partial<Record<Lng, string>>>
type Preset = 'all' | 'menus' | 'buttons' | 'modified'

const PAGE = 30
const BUTTON_WORD = /^(save|cancel|send|add|edit|delete|remove|close|back|next|previous|submit|confirm|create|apply|export|import|upload|download|search|retry|reset|new|open|approve|reject|publish|share|start|finish|continue|login|logout|register|view|details|filter|clear|copy|print|select|choose|generate|verify|test)/i
const KEYS = [...new Set([...Object.keys(baseLabels.ar), ...Object.keys(baseLabels.en)])].sort()

const sectionOf = (key: string) => {
  const parts = key.split('.')
  return parts.length > 2 && ['mgmt', 'admin', 'kits'].includes(parts[0]) ? `${parts[0]}.${parts[1]}` : parts[0]
}
const isMenu = (key: string) => /(^|\.)(menu|nav|menus|groups|tabs)(\.|$)/.test(key)
const isButton = (key: string) => {
  const last = key.split('.').pop() ?? ''
  return BUTTON_WORD.test(last) || /(^|\.)(btn|button|buttons|actions?|cta)(\.|$)/i.test(key)
}
const placeholders = (text: string) => [...text.matchAll(/\{\{\s*(\w+)\s*\}\}/g)].map((m) => m[1])

/** Settings → Labels: rename any menu, button or text of the platform in Arabic and English. */
export default function LabelManager() {
  const { t, i18n } = useTranslation()
  const { data, isLoading, refetch } = useGet<Payload>('/admin/settings/labels')
  const [draft, setDraft] = useState<Draft>({})
  const [q, setQ] = useState('')
  const [preset, setPreset] = useState<Preset>('all')
  const [section, setSection] = useState('')
  const [page, setPage] = useState(1)
  const [saving, setSaving] = useState(false)
  const [notice, setNotice] = useState<{ ok: boolean; text: string } | null>(null)
  const file = useRef<HTMLInputElement>(null)
  const search = useDeferredValue(q.trim().toLowerCase())
  const saved: LabelSet = data?.data ?? { ar: {}, en: {} }

  const sections = useMemo(() => {
    const counts = new Map<string, number>()
    KEYS.forEach((k) => counts.set(sectionOf(k), (counts.get(sectionOf(k)) ?? 0) + 1))
    return [...counts.entries()].sort((a, b) => b[1] - a[1])
  }, [])

  // What each field shows: the pending edit, else the saved name, else nothing (= the built-in text).
  const value = (key: string, lng: Lng) => (draft[key] && lng in draft[key]! ? draft[key]![lng]! : saved[lng][key] ?? '')
  const modified = (key: string) => (['ar', 'en'] as const).some((l) => value(key, l).trim() !== '' && value(key, l) !== baseLabels[l][key])
  const problem = (key: string, lng: Lng) => {
    const v = value(key, lng).trim()
    if (!v) return null
    const missing = placeholders(baseLabels[lng][key] ?? '').filter((p) => !placeholders(v).includes(p))
    return missing.length ? t('mgmt.labels.keepPlaceholder', { names: missing.map((p) => `{{${p}}}`).join(' ') }) : null
  }

  const rows = useMemo(() => {
    return KEYS.filter((k) => {
      if (section && sectionOf(k) !== section) return false
      if (preset === 'menus' && !isMenu(k)) return false
      if (preset === 'buttons' && !isButton(k)) return false
      if (preset === 'modified' && !modified(k)) return false
      if (!search) return true
      return `${k} ${baseLabels.ar[k] ?? ''} ${baseLabels.en[k] ?? ''} ${saved.ar[k] ?? ''} ${saved.en[k] ?? ''} ${draft[k]?.ar ?? ''} ${draft[k]?.en ?? ''}`.toLowerCase().includes(search)
    })
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [search, preset, section, draft, data])

  const pages = Math.max(1, Math.ceil(rows.length / PAGE))
  const visible = rows.slice((Math.min(page, pages) - 1) * PAGE, Math.min(page, pages) * PAGE)
  const changes = Object.entries(draft).flatMap(([key, langs]) => (Object.keys(langs) as Lng[]).map((lng) => ({ key, lng, value: (langs[lng] ?? '').trim() })))
    .filter((c) => c.value !== (saved[c.lng][c.key] ?? ''))
  const invalid = changes.some((c) => problem(c.key, c.lng))
  const customised = KEYS.filter(modified).length

  const set = (key: string, lng: Lng, v: string) => setDraft((d) => ({ ...d, [key]: { ...d[key], [lng]: v } }))
  const reset = (key: string, lng?: Lng) => setDraft((d) => ({ ...d, [key]: { ...d[key], ...(lng ? { [lng]: '' } : { ar: '', en: '' }) } }))

  const save = async () => {
    setSaving(true)
    setNotice(null)
    try {
      const body: LabelSet = { ar: {}, en: {} }
      changes.forEach((c) => { body[c.lng][c.key] = c.value === baseLabels[c.lng][c.key] ? '' : c.value })
      const res = await api.put<Payload>('/admin/settings/labels', body)
      applyLabels(res.data.data)
      cacheLabels(res.data.data, res.data.version)
      setDraft({})
      await refetch()
      setNotice({ ok: true, text: t('mgmt.labels.saved', { count: changes.length }) })
    } catch (e) {
      setNotice({ ok: false, text: errorMessage(e) })
    } finally {
      setSaving(false)
    }
  }

  const resetAll = async () => {
    if (!window.confirm(t('mgmt.labels.confirmResetAll'))) return
    try {
      const res = await api.put<Payload>('/admin/settings/labels', { replace: true })
      applyLabels(res.data.data)
      cacheLabels(res.data.data, res.data.version)
      setDraft({})
      await refetch()
      setNotice({ ok: true, text: t('mgmt.labels.resetDone') })
    } catch (e) { setNotice({ ok: false, text: errorMessage(e) }) }
  }

  const exportJson = () => {
    const out: LabelSet = { ar: {}, en: {} }
    KEYS.forEach((k) => (['ar', 'en'] as const).forEach((l) => { const v = value(k, l).trim(); if (v && v !== baseLabels[l][k]) out[l][k] = v }))
    const url = URL.createObjectURL(new Blob([JSON.stringify(out, null, 2)], { type: 'application/json' }))
    const a = Object.assign(document.createElement('a'), { href: url, download: 'labels.json' })
    a.click()
    URL.revokeObjectURL(url)
  }

  const importJson = async (f: File) => {
    try {
      const parsed = JSON.parse(await f.text()) as Partial<LabelSet>
      const next: Draft = {}
      let count = 0
      ;(['ar', 'en'] as const).forEach((l) => Object.entries(parsed[l] ?? {}).forEach(([k, v]) => {
        if (k in baseLabels[l] && typeof v === 'string') { next[k] = { ...next[k], [l]: v }; count++ }
      }))
      setDraft((d) => ({ ...d, ...next }))
      setNotice({ ok: true, text: t('mgmt.labels.imported', { count }) })
    } catch { setNotice({ ok: false, text: t('mgmt.labels.badFile') }) }
  }

  if (isLoading) return <Spinner />

  const presets: { id: Preset; label: string; icon: typeof PanelLeft }[] = [
    { id: 'all', label: t('mgmt.labels.presets.all'), icon: Languages },
    { id: 'menus', label: t('mgmt.labels.presets.menus'), icon: PanelLeft },
    { id: 'buttons', label: t('mgmt.labels.presets.buttons'), icon: MousePointerClick },
    { id: 'modified', label: t('mgmt.labels.presets.modified'), icon: PencilLine },
  ]
  const Prev = i18n.dir() === 'rtl' ? ChevronRight : ChevronLeft
  const Next = i18n.dir() === 'rtl' ? ChevronLeft : ChevronRight

  return (
    <div className="pb-24">
      <PageHeader
        title={<span className="flex items-center gap-3"><span className="grid size-11 place-items-center rounded-2xl bg-navy-900 text-gold-300"><Languages className="size-5" /></span>{t('mgmt.settings.sections.labels.title')}</span>}
        subtitle={t('mgmt.labels.subtitle')}
        actions={
          <div className="flex flex-wrap gap-2">
            <Button variant="outline" size="sm" icon={<Download className="size-4" />} onClick={exportJson}>{t('mgmt.labels.export')}</Button>
            <Button variant="outline" size="sm" icon={<Upload className="size-4" />} onClick={() => file.current?.click()}>{t('mgmt.labels.import')}</Button>
            <Button variant="ghost" size="sm" icon={<RotateCcw className="size-4" />} disabled={!customised} onClick={resetAll}>{t('mgmt.labels.resetAll')}</Button>
            <input ref={file} type="file" accept="application/json,.json" className="hidden" onChange={(e) => { const f = e.target.files?.[0]; if (f) void importJson(f); e.target.value = '' }} />
          </div>
        }
      />

      <div className="mb-5 grid gap-3 sm:grid-cols-3">
        {[
          { label: t('mgmt.labels.stats.total'), value: KEYS.length },
          { label: t('mgmt.labels.stats.customised'), value: customised, accent: true },
          { label: t('mgmt.labels.stats.pending'), value: changes.length },
        ].map((s) => (
          <div key={s.label} className={clsx('rounded-2xl border p-4', s.accent ? 'border-gold-300 bg-gold-100/40' : 'border-navy-100 bg-white')}>
            <div className="text-xs font-semibold text-slate-500">{s.label}</div>
            <div className="mt-1 text-2xl font-extrabold text-navy-900">{fmt.number(s.value)}</div>
          </div>
        ))}
      </div>

      {notice && <div className={clsx('mb-4 rounded-2xl p-3 text-sm font-semibold', notice.ok ? 'bg-emerald-50 text-emerald-700' : 'bg-red-50 text-danger')}>{notice.text}</div>}

      <Card className="mb-4">
        <div className="flex flex-wrap items-center gap-3">
          <div className="relative min-w-60 flex-1">
            <Search className="pointer-events-none absolute start-3 top-1/2 size-4 -translate-y-1/2 text-slate-400" />
            <input className="input !ps-9" value={q} onChange={(e) => { setQ(e.target.value); setPage(1) }} placeholder={t('mgmt.labels.search')} aria-label={t('mgmt.labels.search')} />
          </div>
          <select className="input !w-auto max-w-56" value={section} onChange={(e) => { setSection(e.target.value); setPage(1) }} aria-label={t('mgmt.labels.section')}>
            <option value="">{t('mgmt.labels.allSections')}</option>
            {sections.map(([s, n]) => <option key={s} value={s}>{s} ({n})</option>)}
          </select>
        </div>
        <div className="mt-3 flex flex-wrap gap-2" role="tablist">
          {presets.map((p) => (
            <button key={p.id} type="button" role="tab" aria-selected={preset === p.id} onClick={() => { setPreset(p.id); setPage(1) }}
              className={clsx('inline-flex items-center gap-1.5 rounded-full px-3.5 py-1.5 text-sm font-semibold ring-1 ring-inset transition', preset === p.id ? 'bg-navy-900 text-white ring-navy-900' : 'bg-white text-slate-600 ring-navy-100 hover:ring-gold-400')}>
              <p.icon className="size-4" />{p.label}
            </button>
          ))}
          <span className="ms-auto self-center text-xs text-slate-400">{t('mgmt.labels.results', { count: rows.length })}</span>
        </div>
      </Card>

      {visible.length === 0 ? (
        <Card><p className="py-10 text-center text-slate-500">{t('mgmt.labels.empty')}</p></Card>
      ) : (
        <ul className="space-y-3">
          {visible.map((key) => {
            const on = modified(key)
            const dirty = !!draft[key]
            return (
              <li key={key} className={clsx('rounded-2xl border bg-white p-4 shadow-sm transition', on ? 'border-gold-400 bg-gradient-to-l from-gold-100/30 to-white' : 'border-navy-100')}>
                <div className="mb-3 flex flex-wrap items-center gap-2">
                  <code className="rounded-md bg-navy-100/60 px-2 py-0.5 font-mono text-[11px] text-navy-800" dir="ltr">{key}</code>
                  <Badge color="gray">{sectionOf(key)}</Badge>
                  {on && <Badge color="gold">{t('mgmt.labels.customised')}</Badge>}
                  {dirty && <span className="size-2 animate-pulse rounded-full bg-amber-500" title={t('mgmt.labels.unsaved')} />}
                  {on && <button type="button" onClick={() => reset(key)} className="ms-auto inline-flex items-center gap-1 rounded-lg px-2 py-1 text-xs font-semibold text-slate-500 hover:bg-navy-100/60 hover:text-navy-900"><Undo2 className="size-3.5" />{t('mgmt.labels.restore')}</button>}
                </div>
                <div className="grid gap-3 md:grid-cols-2">
                  {(['ar', 'en'] as const).map((lng) => {
                    const err = problem(key, lng)
                    const base = baseLabels[lng][key]
                    if (base === undefined) return <div key={lng} />
                    return (
                      <label key={lng} className="block">
                        <span className="mb-1 flex items-center justify-between text-xs font-semibold text-slate-500">{lng === 'ar' ? 'العربية' : 'English'}
                          {value(key, lng) && value(key, lng) !== base && <button type="button" onClick={() => reset(key, lng)} className="text-[11px] font-medium text-link hover:underline">{t('mgmt.labels.useDefault')}</button>}
                        </span>
                        <input dir={lng === 'ar' ? 'rtl' : 'ltr'} maxLength={200} className={clsx('input', err && '!border-danger')} value={value(key, lng)} placeholder={base} onChange={(e) => set(key, lng, e.target.value)} />
                        <span className="mt-1 block truncate text-[11px] text-slate-400" dir={lng === 'ar' ? 'rtl' : 'ltr'}>{err ? <span className="text-danger">{err}</span> : `${t('mgmt.labels.default')}: ${base}`}</span>
                      </label>
                    )
                  })}
                </div>
              </li>
            )
          })}
        </ul>
      )}

      {pages > 1 && (
        <div className="mt-5 flex items-center justify-center gap-3">
          <Button size="sm" variant="outline" icon={<Prev className="size-4" />} disabled={page <= 1} onClick={() => setPage(page - 1)}>{t('common.previous')}</Button>
          <span className="text-sm text-slate-500">{Math.min(page, pages)} / {pages}</span>
          <Button size="sm" variant="outline" disabled={page >= pages} onClick={() => setPage(page + 1)}>{t('common.next')}<Next className="ms-1 inline size-4" /></Button>
        </div>
      )}

      {changes.length > 0 && (
        <div className="fixed inset-x-0 bottom-0 z-40 border-t border-gold-300 bg-white/95 px-4 py-3 shadow-[0_-8px_30px_rgba(0,0,0,.08)] backdrop-blur">
          <div className="mx-auto flex max-w-6xl flex-wrap items-center gap-3">
            <span className="grid size-9 place-items-center rounded-xl bg-gold-100 text-gold-700"><PencilLine className="size-4" /></span>
            <div className="min-w-0 flex-1">
              <div className="font-bold text-navy-900">{t('mgmt.labels.pending', { count: changes.length })}</div>
              <div className="text-xs text-slate-500">{invalid ? t('mgmt.labels.fixErrors') : t('mgmt.labels.appliesNow')}</div>
            </div>
            <Button variant="ghost" icon={<Trash2 className="size-4" />} onClick={() => setDraft({})}>{t('mgmt.labels.discard')}</Button>
            <Button variant="gold" icon={<Save className="size-4" />} loading={saving} disabled={invalid} onClick={save}>{t('mgmt.labels.save')}</Button>
          </div>
        </div>
      )}
    </div>
  )
}
