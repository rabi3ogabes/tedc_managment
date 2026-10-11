import { useQueryClient } from '@tanstack/react-query'
import clsx from 'clsx'
import { Check, RotateCcw } from 'lucide-react'
import { useEffect, useState } from 'react'
import { useTranslation } from 'react-i18next'
import { Button, Card, PageHeader, Spinner } from '@/components/ui'
import { useGet } from '@/hooks/useApi'
import { api, errorMessage } from '@/lib/api'
import { dialogs } from '@/lib/dialogs'
import { toast } from '@/lib/toast'
import { ColorField } from './brand/controls'

type Colors = { primary: string; accent: string; background: string }
type Preset = {
  id: string; name: { ar: string; en: string }; description: { ar: string; en: string }
  colors: Colors | null; default_colors: Colors | null
  shape: { card_radius: number; button_radius: number; elevation: number }; header: 'curved' | 'flat' | 'glass'; nav: string | null; home: string[] | null
}
type Group = { key: string; roles: string[]; preset: string }
type Payload = { presets: Preset[]; groups: Group[] }

const COLOR_KEYS: (keyof Colors)[] = ['primary', 'accent', 'background']

/** A small phone drawn in the look's own colours, header, card shape and bottom bar. */
function Phone({ p, brand }: { p: Preset; brand: Colors }) {
  const c = p.colors ?? brand
  const r = Math.round(p.shape.card_radius / 2.2)
  const shadow = p.shape.elevation ? `0 ${p.shape.elevation * 3}px ${p.shape.elevation * 8}px ${c.primary}33` : 'none'
  const flat = p.header === 'flat'
  return (
    <div className="mx-auto w-28 overflow-hidden rounded-[18px] border border-navy-100 shadow-sm" style={{ background: c.background }} aria-hidden>
      <div className="h-14 px-2 pt-2" style={{ background: flat ? c.background : `linear-gradient(135deg, ${c.primary}, ${c.primary}cc)`, borderRadius: p.header === 'glass' ? '0 0 18px 18px' : flat ? 0 : '0 0 14px 14px' }}>
        <div className="h-1.5 w-10 rounded-full" style={{ background: flat ? c.primary : '#fff' }} />
        <div className="mt-1.5 h-2 w-16 rounded-full" style={{ background: flat ? `${c.primary}66` : '#ffffffaa' }} />
      </div>
      <div className="space-y-1.5 p-2">
        <div className="h-9" style={{ background: c.primary, borderRadius: r, boxShadow: shadow }} />
        <div className="grid grid-cols-3 gap-1">{[0, 1, 2].map((i) => <div key={i} className="h-6 bg-white" style={{ borderRadius: r, boxShadow: shadow, borderTop: `3px solid ${i === 1 ? c.accent : `${c.primary}22`}` }} />)}</div>
        <div className="h-7 bg-white" style={{ borderRadius: r, boxShadow: shadow }} />
      </div>
      <div className="mx-2 mb-2 flex h-5 items-center justify-around bg-white" style={{ borderRadius: 99, boxShadow: shadow }}>
        {[0, 1, 2, 3].map((i) => <span key={i} className="size-1.5 rounded-full" style={{ background: i === 0 ? c.primary : '#cbd5e1' }} />)}
      </div>
    </div>
  )
}

/** Settings → App themes: ready-made looks for the mobile app, assigned to each group of people, with editable colours. */
export default function AppThemesSettings() {
  const { t, i18n } = useTranslation()
  const lang = i18n.language === 'ar' ? 'ar' : 'en'
  const qc = useQueryClient()
  const loaded = useGet<{ data: Payload }>('/admin/app-themes', undefined, { staleTime: 0 })
  const brand = useGet<{ data: { colors: Colors } }>('/public/theme', undefined, { staleTime: 60_000 }).data?.data.colors
  const [assign, setAssign] = useState<Record<string, string> | null>(null)
  const [colors, setColors] = useState<Record<string, Colors>>({})
  const [base, setBase] = useState('')
  const [open, setOpen] = useState<string | null>(null)
  const [busy, setBusy] = useState(false)

  const take = (d: Payload) => {
    const a = Object.fromEntries(d.groups.map((g) => [g.key, g.preset]))
    const c = Object.fromEntries(d.presets.filter((p) => p.colors).map((p) => [p.id, p.colors as Colors]))
    setAssign(a); setColors(c); setBase(JSON.stringify({ a, c }))
  }
  useEffect(() => { if (loaded.data && !assign) take(loaded.data.data) }, [loaded.data, assign])

  if (!assign || !loaded.data) return loaded.isError ? <Card><p className="py-8 text-center text-sm font-semibold text-danger">{errorMessage(loaded.error)}</p></Card> : <Spinner />
  const { presets, groups } = loaded.data.data
  const fallback: Colors = brand ?? { primary: '#8A1538', accent: '#A29475', background: '#F8F6F2' }
  const dirty = JSON.stringify({ a: assign, c: colors }) !== base
  const label = (id: string) => presets.find((p) => p.id === id)?.name[lang] ?? id

  const save = async () => {
    setBusy(true)
    try {
      const { data } = await api.put('/admin/app-themes', { assignments: assign, colors })
      take(data.data)
      loaded.refetch().catch(() => null)
      await qc.invalidateQueries({ queryKey: ['/admin/app-themes'] })
      toast(t('appThemes.saved'))
    } catch (e) { toast(errorMessage(e), 'error') } finally { setBusy(false) }
  }
  const reset = async () => {
    if (!(await dialogs.confirm(t('appThemes.confirmReset')))) return
    setBusy(true)
    try { const { data } = await api.delete('/admin/app-themes'); take(data.data); toast(t('appThemes.resetDone')) } catch (e) { toast(errorMessage(e), 'error') } finally { setBusy(false) }
  }

  return (
    <div className="space-y-6 pb-24">
      <PageHeader title={t('appThemes.title')} subtitle={t('appThemes.subtitle')} actions={<Button variant="outline" icon={<RotateCcw className="size-4" />} onClick={reset} disabled={busy}>{t('appThemes.reset')}</Button>} />

      <section className="space-y-3">
        <h2 className="text-sm font-bold text-slate-500">{t('appThemes.assign')}</h2>
        <Card className="divide-y divide-navy-100 !p-0">
          {groups.map((g) => (
            <div key={g.key} className="flex flex-wrap items-center gap-3 p-4">
              <div className="min-w-0 flex-1"><div className="font-bold text-navy-900">{t(`appThemes.groups.${g.key}`)}</div><div className="text-xs text-slate-500">{t(`appThemes.groupHint.${g.key}`)}</div></div>
              <select aria-label={t(`appThemes.groups.${g.key}`)} className="input h-11 w-56" value={assign[g.key] ?? 'classic'} onChange={(e) => setAssign((a) => ({ ...(a as Record<string, string>), [g.key]: e.target.value }))}>
                {presets.map((p) => <option key={p.id} value={p.id}>{p.name[lang]}</option>)}
              </select>
            </div>
          ))}
        </Card>
      </section>

      <section className="space-y-3">
        <h2 className="text-sm font-bold text-slate-500">{t('appThemes.gallery')}</h2>
        <div className="grid gap-4 sm:grid-cols-2 xl:grid-cols-3">
          {presets.map((p) => {
            const using = groups.filter((g) => (assign[g.key] ?? 'classic') === p.id).map((g) => t(`appThemes.groups.${g.key}`))
            const live: Preset = { ...p, colors: p.colors ? colors[p.id] ?? p.colors : null }
            return (
              <Card key={p.id} className={clsx('space-y-3', using.length > 0 && p.id !== 'classic' && 'ring-2 ring-gold-300')}>
                <Phone p={live} brand={fallback} />
                <div><div className="font-bold text-navy-900">{p.name[lang]}</div><p className="mt-0.5 text-xs leading-5 text-slate-500">{p.description[lang]}</p></div>
                <div className="flex flex-wrap gap-1.5 text-[11px] font-bold">
                  <span className="rounded-full bg-navy-100 px-2 py-0.5 text-navy-800">{t(`appThemes.header.${p.header}`)}</span>
                  {p.nav && <span className="rounded-full bg-navy-100 px-2 py-0.5 text-navy-800">{t(`apa.navStyles.${p.nav}`)}</span>}
                  {using.map((u) => <span key={u} className="rounded-full bg-gold-100 px-2 py-0.5 text-gold-800">{u}</span>)}
                </div>
                {p.colors && (
                  <>
                    <button type="button" aria-expanded={open === p.id} onClick={() => setOpen(open === p.id ? null : p.id)} className="text-xs font-bold text-navy-700 underline-offset-2 hover:underline">{t('appThemes.colors')}</button>
                    {open === p.id && (
                      <div className="space-y-2.5 rounded-xl bg-ivory p-3">
                        {COLOR_KEYS.map((k) => <ColorField key={k} label={t(`appThemes.color.${k}`)} value={(colors[p.id] ?? p.colors!)[k]} onChange={(v) => setColors((c) => ({ ...c, [p.id]: { ...(c[p.id] ?? p.colors!), [k]: v } }))} />)}
                        <button type="button" onClick={() => setColors((c) => ({ ...c, [p.id]: p.default_colors! }))} className="text-xs font-bold text-slate-500 hover:text-navy-900">{t('appThemes.restoreColors')}</button>
                      </div>
                    )}
                  </>
                )}
              </Card>
            )
          })}
        </div>
      </section>
      <p className="text-xs text-slate-400">{t('appThemes.note')}</p>

      <div className="sticky bottom-4 z-10 flex items-center justify-between gap-3 rounded-2xl border border-navy-100 bg-white/90 p-3 shadow-glass backdrop-blur">
        <span className={clsx('text-sm font-semibold', dirty ? 'text-amber-700' : 'text-slate-400')}>{dirty ? t('appThemes.unsaved') : t('appThemes.upToDate')}</span>
        <div className="flex gap-2">
          <Button variant="outline" disabled={!dirty || busy} onClick={() => { const b = JSON.parse(base) as { a: Record<string, string>; c: Record<string, Colors> }; setAssign(b.a); setColors(b.c) }}>{t('appThemes.discard')}</Button>
          <Button variant="gold" icon={<Check className="size-4" />} loading={busy} disabled={!dirty} onClick={save}>{t('appThemes.save')}</Button>
        </div>
      </div>
      <span className="sr-only">{groups.map((g) => label(assign[g.key] ?? 'classic')).join(', ')}</span>
    </div>
  )
}
