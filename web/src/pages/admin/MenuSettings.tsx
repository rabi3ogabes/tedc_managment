import { useQueryClient } from '@tanstack/react-query'
import clsx from 'clsx'
import { ArrowDown, ArrowUp, Check, ChevronDown, Plus, RotateCcw, Trash2 } from 'lucide-react'
import { useEffect, useMemo, useRef, useState } from 'react'
import { useTranslation } from 'react-i18next'
import { adminCatalog, type Group } from '@/components/admin/menuCatalog'
import { Button, Card, PageHeader, Spinner } from '@/components/ui'
import { useGet } from '@/hooks/useApi'
import { api, errorMessage } from '@/lib/api'
import { dialogs } from '@/lib/dialogs'
import { applyLayout, EMPTY_LAYOUT, isCustomSection, MENU_ICONS, toLayout, type MenuLayout } from '@/lib/menuLayout'
import { toast } from '@/lib/toast'

type Draft = MenuLayout

/** A button that opens a grid of icons; the choice is kept per menu button. */
function IconPicker({ value, fallback: Fallback, label, onPick }: { value: string | null; fallback: React.ComponentType<{ className?: string }>; label: string; onPick: (name: string | null) => void }) {
  const { t } = useTranslation()
  const [open, setOpen] = useState(false)
  const root = useRef<HTMLDivElement>(null)
  useEffect(() => {
    if (!open) return
    const down = (e: MouseEvent) => { if (!root.current?.contains(e.target as Node)) setOpen(false) }
    const key = (e: KeyboardEvent) => { if (e.key === 'Escape') setOpen(false) }
    document.addEventListener('mousedown', down)
    window.addEventListener('keydown', key)
    return () => { document.removeEventListener('mousedown', down); window.removeEventListener('keydown', key) }
  }, [open])
  const Current = (value && MENU_ICONS[value]) || Fallback
  return (
    <div ref={root} className="relative">
      <button type="button" aria-haspopup="dialog" aria-expanded={open} aria-label={`${t('menuEd.icon')}: ${label}`} onClick={() => setOpen((o) => !o)}
        className="grid size-10 place-items-center rounded-xl bg-navy-900 text-gold-300 ring-1 ring-navy-100 transition hover:ring-gold-400 focus-visible:outline-2 focus-visible:outline-gold-400">
        <Current className="size-5" />
      </button>
      {open && (
        <div role="dialog" aria-label={t('menuEd.pickIcon')} className="absolute start-0 top-12 z-30 w-72 rounded-2xl border border-navy-100 bg-white p-3 shadow-[0_24px_60px_-18px_rgba(90,14,36,.45)]">
          <div className="grid max-h-56 grid-cols-6 gap-1.5 overflow-y-auto pe-1">
            {Object.entries(MENU_ICONS).map(([name, Icon]) => (
              <button key={name} type="button" title={name} aria-label={name} aria-pressed={value === name} onClick={() => { onPick(name); setOpen(false) }}
                className={clsx('grid size-9 place-items-center rounded-lg transition', value === name ? 'bg-navy-900 text-gold-300' : 'text-navy-800 hover:bg-gold-100')}>
                <Icon className="size-[18px]" />
              </button>
            ))}
          </div>
          <button type="button" onClick={() => { onPick(null); setOpen(false) }} className="mt-2 w-full rounded-lg px-3 py-1.5 text-xs font-bold text-navy-700 hover:bg-ivory">{t('menuEd.defaultIcon')}</button>
        </div>
      )}
    </div>
  )
}

/** Settings → Menu: move buttons between sections, reorder them, rename sections and change icons of the dashboard menu. */
export default function MenuSettings() {
  const { t, i18n } = useTranslation()
  const qc = useQueryClient()
  const saved = useGet<{ data: MenuLayout }>('/me/menu-layout', undefined, { staleTime: 0 })
  const [draft, setDraft] = useState<Draft | null>(null)
  const [base, setBase] = useState('')
  const [busy, setBusy] = useState(false)
  const lang = i18n.language === 'ar' ? 'ar' : 'en'
  const badges = useMemo(() => ({}), [])
  const catalog = useMemo(() => adminCatalog(t, badges), [t, badges])
  const byPath = useMemo(() => new Map(catalog.flatMap((g) => g.items.map((i) => [i.to, i] as const))), [catalog])
  const defaultTitle = (id: string) => catalog.find((g) => g.id === id)?.title ?? ''

  // The editor starts from the arrangement in force (every button listed, in its section).
  useEffect(() => {
    if (!saved.data || draft) return
    const stored = saved.data.data ?? EMPTY_LAYOUT
    const next = toLayout(applyLayout(catalog, stored, lang) as Group[], stored)
    setDraft(next); setBase(JSON.stringify(next))
  }, [saved.data, catalog, lang, draft])

  if (saved.isLoading || !draft) return saved.isError ? <Card><p className="py-8 text-center text-sm font-semibold text-danger">{errorMessage(saved.error)}</p></Card> : <Spinner />
  const dirty = JSON.stringify(draft) !== base

  const edit = (fn: (d: Draft) => Draft) => setDraft((d) => (d ? fn(structuredClone(d)) : d))
  const move = (si: number, ii: number, dir: -1 | 1) => edit((d) => {
    const items = d.sections[si].items
    const to = ii + dir
    if (to < 0 || to >= items.length) return d
    ;[items[ii], items[to]] = [items[to], items[ii]]
    return d
  })
  const moveSection = (si: number, dir: -1 | 1) => edit((d) => {
    const to = si + dir
    if (to < 0 || to >= d.sections.length) return d
    ;[d.sections[si], d.sections[to]] = [d.sections[to], d.sections[si]]
    return d
  })
  const sendTo = (si: number, ii: number, target: number) => edit((d) => {
    const [path] = d.sections[si].items.splice(ii, 1)
    d.sections[target].items.push(path)
    return d
  })
  const addSection = () => edit((d) => {
    d.sections.push({ id: `c_${Math.random().toString(36).slice(2, 8)}`, title_ar: t('menuEd.newSection', { lng: 'ar' }), title_en: t('menuEd.newSection', { lng: 'en' }), items: [] })
    return d
  })
  const removeSection = async (si: number) => {
    if (!(await dialogs.confirm(t('menuEd.confirmDelete')))) return
    edit((d) => {
      const [gone] = d.sections.splice(si, 1)
      // Its buttons return to the section before it (or the first one) so none is lost.
      const target = d.sections[Math.max(0, si - 1)]
      if (target) target.items.push(...gone.items)
      return d
    })
  }
  const setIcon = (path: string, name: string | null) => edit((d) => {
    if (name) d.icons[path] = name
    else delete d.icons[path]
    return d
  })
  const save = async () => {
    setBusy(true)
    try {
      await api.put('/admin/menu-layout', draft)
      setBase(JSON.stringify(draft))
      await qc.invalidateQueries({ queryKey: ['/me/menu-layout'] })
      toast(t('menuEd.saved'))
    } catch (e) { toast(errorMessage(e), 'error') } finally { setBusy(false) }
  }
  const reset = async () => {
    if (!(await dialogs.confirm(t('menuEd.confirmReset')))) return
    setBusy(true)
    try {
      await api.delete('/admin/menu-layout')
      await qc.invalidateQueries({ queryKey: ['/me/menu-layout'] })
      const next = toLayout(applyLayout(catalog, EMPTY_LAYOUT, lang) as Group[], EMPTY_LAYOUT)
      setDraft(next); setBase(JSON.stringify(next))
      toast(t('menuEd.resetDone'))
    } catch (e) { toast(errorMessage(e), 'error') } finally { setBusy(false) }
  }

  return (
    <div className="space-y-5 pb-24">
      <PageHeader title={t('menuEd.title')} subtitle={t('menuEd.subtitle')}
        actions={<Button variant="outline" icon={<RotateCcw className="size-4" />} onClick={reset} disabled={busy}>{t('menuEd.reset')}</Button>} />

      {draft.sections.map((section, si) => (
        <Card key={section.id} className="space-y-4">
          <div className="flex flex-wrap items-end gap-3">
            <label className="min-w-40 flex-1"><span className="mb-1 block text-xs font-semibold text-slate-500">{t('menuEd.nameAr')}</span>
              <input className="input" dir="rtl" maxLength={60} value={section.title_ar ?? ''} placeholder={lang === 'ar' ? defaultTitle(section.id) : ''} onChange={(e) => edit((d) => { d.sections[si].title_ar = e.target.value || null; return d })} /></label>
            <label className="min-w-40 flex-1"><span className="mb-1 block text-xs font-semibold text-slate-500">{t('menuEd.nameEn')}</span>
              <input className="input" dir="ltr" maxLength={60} value={section.title_en ?? ''} placeholder={lang === 'en' ? defaultTitle(section.id) : ''} onChange={(e) => edit((d) => { d.sections[si].title_en = e.target.value || null; return d })} /></label>
            <div className="flex items-center gap-1">
              <button type="button" aria-label={t('menuEd.sectionUp')} disabled={si === 0} onClick={() => moveSection(si, -1)} className="grid size-10 place-items-center rounded-xl text-navy-800 ring-1 ring-navy-100 hover:bg-ivory disabled:opacity-30"><ArrowUp className="size-4" /></button>
              <button type="button" aria-label={t('menuEd.sectionDown')} disabled={si === draft.sections.length - 1} onClick={() => moveSection(si, 1)} className="grid size-10 place-items-center rounded-xl text-navy-800 ring-1 ring-navy-100 hover:bg-ivory disabled:opacity-30"><ArrowDown className="size-4" /></button>
              {isCustomSection(section.id) && <button type="button" aria-label={t('menuEd.deleteSection')} onClick={() => void removeSection(si)} className="grid size-10 place-items-center rounded-xl text-danger ring-1 ring-red-100 hover:bg-red-50"><Trash2 className="size-4" /></button>}
            </div>
          </div>

          <ul className="space-y-2">
            {section.items.length === 0 && <li className="rounded-xl border border-dashed border-navy-100 p-4 text-center text-sm text-slate-400">{t('menuEd.emptySection')}</li>}
            {section.items.map((path, ii) => {
              const item = byPath.get(path)
              if (!item) return null
              return (
                <li key={path} className="flex flex-wrap items-center gap-2 rounded-xl border border-navy-100 bg-white p-2">
                  <IconPicker value={draft.icons[path] ?? null} fallback={item.icon} label={item.label} onPick={(name) => setIcon(path, name)} />
                  <span className="min-w-0 flex-1 truncate font-semibold text-navy-900">{item.label}</span>
                  <div className="relative">
                    <select aria-label={`${t('menuEd.moveTo')}: ${item.label}`} value={si} onChange={(e) => sendTo(si, ii, Number(e.target.value))}
                      className="input h-10 w-44 appearance-none py-0 pe-8 text-sm">
                      {draft.sections.map((s, i) => <option key={s.id} value={i}>{(lang === 'ar' ? s.title_ar : s.title_en) || defaultTitle(s.id) || t('menuEd.untitled')}</option>)}
                    </select>
                    <ChevronDown className="pointer-events-none absolute end-3 top-3 size-4 text-slate-400" />
                  </div>
                  <button type="button" aria-label={`${t('menuEd.up')}: ${item.label}`} disabled={ii === 0} onClick={() => move(si, ii, -1)} className="grid size-10 place-items-center rounded-xl text-navy-800 ring-1 ring-navy-100 hover:bg-ivory disabled:opacity-30"><ArrowUp className="size-4" /></button>
                  <button type="button" aria-label={`${t('menuEd.down')}: ${item.label}`} disabled={ii === section.items.length - 1} onClick={() => move(si, ii, 1)} className="grid size-10 place-items-center rounded-xl text-navy-800 ring-1 ring-navy-100 hover:bg-ivory disabled:opacity-30"><ArrowDown className="size-4" /></button>
                </li>
              )
            })}
          </ul>
        </Card>
      ))}

      <button type="button" onClick={addSection} className="flex w-full items-center justify-center gap-2 rounded-2xl border border-dashed border-gold-300 bg-gold-100/30 py-4 text-sm font-bold text-navy-900 transition hover:bg-gold-100/60"><Plus className="size-4" />{t('menuEd.addSection')}</button>

      <div className="sticky bottom-4 z-10 flex items-center justify-between gap-3 rounded-2xl border border-navy-100 bg-white/90 p-3 shadow-glass backdrop-blur">
        <span className={clsx('text-sm font-semibold', dirty ? 'text-amber-700' : 'text-slate-400')}>{dirty ? t('menuEd.unsaved') : t('menuEd.upToDate')}</span>
        <div className="flex gap-2">
          <Button variant="outline" disabled={!dirty || busy} onClick={() => { setDraft(JSON.parse(base) as Draft) }}>{t('menuEd.discard')}</Button>
          <Button variant="gold" icon={<Check className="size-4" />} loading={busy} disabled={!dirty} onClick={save}>{t('menuEd.save')}</Button>
        </div>
      </div>
    </div>
  )
}
