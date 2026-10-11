import { useQueryClient } from '@tanstack/react-query'
import clsx from 'clsx'
import { ArrowDown, ArrowUp, Check, RotateCcw } from 'lucide-react'
import { useEffect, useState } from 'react'
import { useTranslation } from 'react-i18next'
import { Button, Card, PageHeader, Spinner } from '@/components/ui'
import { useGet } from '@/hooks/useApi'
import { api, errorMessage } from '@/lib/api'
import { dialogs } from '@/lib/dialogs'
import { toast } from '@/lib/toast'
import { Switch } from './notifications/shared'

type Section = { key: string; enabled: boolean }
type RoleView = { role: string; name: { ar: string; en: string }; sections: Section[]; customised: boolean }
type Payload = { data: RoleView[]; meta: { cards: string[]; actions: string[] } }

/** Settings → App home screen: for each kind of person, which cards and action tiles the app shows, and in what order. */
export default function AppHomeSettings({ embedded = false }: { embedded?: boolean }) {
  const { t, i18n } = useTranslation()
  const lang = i18n.language === 'ar' ? 'ar' : 'en'
  const qc = useQueryClient()
  const saved = useGet<Payload>('/admin/app-home', undefined, { staleTime: 0 })
  const [drafts, setDrafts] = useState<Record<string, Section[]> | null>(null)
  const [base, setBase] = useState<Record<string, string>>({})
  const [role, setRole] = useState('employee')
  const [busy, setBusy] = useState(false)

  const take = (p: Payload) => {
    setDrafts(Object.fromEntries(p.data.map((r) => [r.role, r.sections])))
    setBase(Object.fromEntries(p.data.map((r) => [r.role, JSON.stringify(r.sections)])))
  }
  useEffect(() => { if (saved.data && !drafts) take(saved.data) }, [saved.data, drafts])

  if (!drafts || !saved.data) return saved.isError ? <Card><p className="py-8 text-center text-sm font-semibold text-danger">{errorMessage(saved.error)}</p></Card> : <Spinner />
  const { data: roles, meta } = saved.data
  const list = drafts[role] ?? []
  const dirty = JSON.stringify(list) !== base[role]
  const kind = (key: string) => (meta.actions.includes(key) ? 'actions' : 'cards')
  const edit = (fn: (l: Section[]) => Section[]) => setDrafts((d) => (d ? { ...d, [role]: fn([...(d[role] ?? [])]) } : d))
  const toggle = (key: string, enabled: boolean) => edit((l) => l.map((s) => (s.key === key ? { ...s, enabled } : s)))
  // Moves a row past the next row of the same kind (cards among cards, tiles among tiles).
  const move = (key: string, dir: -1 | 1) => edit((l) => {
    const i = l.findIndex((s) => s.key === key)
    let j = i + dir
    while (j >= 0 && j < l.length && kind(l[j].key) !== kind(key)) j += dir
    if (j < 0 || j >= l.length) return l
    ;[l[i], l[j]] = [l[j], l[i]]
    return l
  })
  const afterSave = async (p: Payload) => { take(p); await qc.invalidateQueries({ queryKey: ['/admin/app-home'] }) }
  const save = async () => {
    setBusy(true)
    try { const { data } = await api.put('/admin/app-home', { role, sections: list }); await afterSave(data); toast(t('appHome.saved')) } catch (e) { toast(errorMessage(e), 'error') } finally { setBusy(false) }
  }
  const reset = async () => {
    if (!(await dialogs.confirm(t('appHome.confirmReset')))) return
    setBusy(true)
    try { const { data } = await api.delete('/admin/app-home', { params: { role } }); await afterSave(data); toast(t('appHome.resetDone')) } catch (e) { toast(errorMessage(e), 'error') } finally { setBusy(false) }
  }

  const resetBtn = <Button variant="outline" icon={<RotateCcw className="size-4" />} onClick={reset} disabled={busy}>{t('appHome.reset')}</Button>
  const group = (type: 'cards' | 'actions') => {
    const rows = list.filter((s) => kind(s.key) === type)
    return (
      <section className="space-y-2">
        <h3 className="text-sm font-bold text-slate-500">{t(`appHome.groups.${type}`)}</h3>
        <ol className="space-y-2">
          {rows.map((s, i) => (
            <li key={s.key}>
              <Card className={clsx('flex flex-wrap items-center gap-3 !p-3 transition', !s.enabled && 'opacity-60')}>
                <span className="grid size-9 place-items-center rounded-xl bg-navy-900 text-xs font-bold text-gold-300" aria-hidden>{i + 1}</span>
                <div className="min-w-0 flex-1">
                  <div className="font-bold text-navy-900">{t(`appHome.cards.${s.key}.title`)}</div>
                  <div className="text-xs text-slate-500">{t(`appHome.cards.${s.key}.hint`)}</div>
                </div>
                <Switch checked={s.enabled} label={t(`appHome.cards.${s.key}.title`)} onChange={(v) => toggle(s.key, v)} />
                <button type="button" aria-label={`${t('appHome.up')}: ${t(`appHome.cards.${s.key}.title`)}`} disabled={i === 0} onClick={() => move(s.key, -1)} className="grid size-10 place-items-center rounded-xl text-navy-800 ring-1 ring-navy-100 hover:bg-ivory disabled:opacity-30"><ArrowUp className="size-4" /></button>
                <button type="button" aria-label={`${t('appHome.down')}: ${t(`appHome.cards.${s.key}.title`)}`} disabled={i === rows.length - 1} onClick={() => move(s.key, 1)} className="grid size-10 place-items-center rounded-xl text-navy-800 ring-1 ring-navy-100 hover:bg-ivory disabled:opacity-30"><ArrowDown className="size-4" /></button>
              </Card>
            </li>
          ))}
        </ol>
      </section>
    )
  }

  return (
    <div className="space-y-5 pb-24">
      {embedded ? (
        <div className="flex flex-wrap items-start justify-between gap-3">
          <div className="max-w-2xl"><h2 className="text-lg font-bold text-navy-900">{t('appHome.title')}</h2><p className="mt-1 text-sm text-slate-500">{t('appHome.subtitle')}</p></div>
          {resetBtn}
        </div>
      ) : <PageHeader title={t('appHome.title')} subtitle={t('appHome.subtitle')} actions={resetBtn} />}

      <div role="tablist" aria-label={t('appHome.roles')} className="flex flex-wrap gap-2">
        {roles.map((r) => (
          <button key={r.role} type="button" role="tab" aria-selected={role === r.role} onClick={() => setRole(r.role)}
            className={clsx('inline-flex items-center gap-1.5 rounded-xl px-3.5 py-2 text-sm font-bold transition', role === r.role ? 'bg-navy-900 text-gold-300 shadow-glass' : 'bg-white text-navy-900 ring-1 ring-navy-100 hover:ring-gold-300')}>
            {r.name[lang]}
            {JSON.stringify(drafts[r.role]) !== base[r.role] ? <span className="size-1.5 rounded-full bg-amber-500" aria-label={t('appHome.unsaved')} /> : r.customised ? <span className="size-1.5 rounded-full bg-gold-500" aria-label={t('appHome.customised')} /> : null}
          </button>
        ))}
      </div>

      {group('cards')}
      {group('actions')}
      <p className="text-xs text-slate-400">{t('appHome.note')}</p>

      <div className="sticky bottom-4 z-10 flex items-center justify-between gap-3 rounded-2xl border border-navy-100 bg-white/90 p-3 shadow-glass backdrop-blur">
        <span className={clsx('text-sm font-semibold', dirty ? 'text-amber-700' : 'text-slate-400')}>{dirty ? t('appHome.unsaved') : t('appHome.upToDate')}</span>
        <div className="flex gap-2">
          <Button variant="outline" disabled={!dirty || busy} onClick={() => setDrafts((d) => (d ? { ...d, [role]: JSON.parse(base[role]) as Section[] } : d))}>{t('appHome.discard')}</Button>
          <Button variant="gold" icon={<Check className="size-4" />} loading={busy} disabled={!dirty} onClick={save}>{t('appHome.save')}</Button>
        </div>
      </div>
    </div>
  )
}
