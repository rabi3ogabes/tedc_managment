import { useQueryClient } from '@tanstack/react-query'
import clsx from 'clsx'
import { ArrowDown, ArrowUp, Check, GripVertical, RotateCcw } from 'lucide-react'
import { useEffect, useState } from 'react'
import { useTranslation } from 'react-i18next'
import { Button, Card, PageHeader, Spinner } from '@/components/ui'
import { useGet } from '@/hooks/useApi'
import { api, errorMessage } from '@/lib/api'
import { dialogs } from '@/lib/dialogs'
import { toast } from '@/lib/toast'
import { Switch } from './notifications/shared'

type Section = { key: string; enabled: boolean }

/** Settings → App home: the order of the cards on the mobile app's home screen, and which of them are shown. */
export default function AppHomeSettings() {
  const { t } = useTranslation()
  const qc = useQueryClient()
  const saved = useGet<{ data: Section[] }>('/admin/app-home', undefined, { staleTime: 0 })
  const [draft, setDraft] = useState<Section[] | null>(null)
  const [base, setBase] = useState('')
  const [busy, setBusy] = useState(false)

  useEffect(() => {
    if (saved.data && !draft) { setDraft(saved.data.data); setBase(JSON.stringify(saved.data.data)) }
  }, [saved.data, draft])

  if (!draft) return saved.isError ? <Card><p className="py-8 text-center text-sm font-semibold text-danger">{errorMessage(saved.error)}</p></Card> : <Spinner />
  const dirty = JSON.stringify(draft) !== base
  const move = (i: number, dir: -1 | 1) => setDraft((d) => {
    if (!d) return d
    const to = i + dir
    if (to < 0 || to >= d.length) return d
    const next = [...d]
    ;[next[i], next[to]] = [next[to], next[i]]
    return next
  })
  const toggle = (i: number, enabled: boolean) => setDraft((d) => d && d.map((s, n) => (n === i ? { ...s, enabled } : s)))
  const apply = async (rows: Section[]) => {
    setDraft(rows); setBase(JSON.stringify(rows))
    await qc.invalidateQueries({ queryKey: ['/admin/app-home'] })
  }
  const save = async () => {
    setBusy(true)
    try { const { data } = await api.put('/admin/app-home', { sections: draft }); await apply(data.data); toast(t('appHome.saved')) } catch (e) { toast(errorMessage(e), 'error') } finally { setBusy(false) }
  }
  const reset = async () => {
    if (!(await dialogs.confirm(t('appHome.confirmReset')))) return
    setBusy(true)
    try { const { data } = await api.delete('/admin/app-home'); await apply(data.data); toast(t('appHome.resetDone')) } catch (e) { toast(errorMessage(e), 'error') } finally { setBusy(false) }
  }

  return (
    <div className="space-y-5 pb-24">
      <PageHeader title={t('appHome.title')} subtitle={t('appHome.subtitle')}
        actions={<Button variant="outline" icon={<RotateCcw className="size-4" />} onClick={reset} disabled={busy}>{t('appHome.reset')}</Button>} />
      <ol className="space-y-2">
        {draft.map((s, i) => (
          <li key={s.key}>
            <Card className={clsx('flex flex-wrap items-center gap-3 !p-3 transition', !s.enabled && 'opacity-60')}>
              <span className="grid size-10 place-items-center rounded-xl bg-navy-900 text-sm font-bold text-gold-300" aria-hidden>{i + 1}</span>
              <GripVertical className="size-4 text-slate-300" aria-hidden />
              <div className="min-w-0 flex-1">
                <div className="font-bold text-navy-900">{t(`appHome.cards.${s.key}.title`)}</div>
                <div className="text-xs text-slate-500">{t(`appHome.cards.${s.key}.hint`)}</div>
              </div>
              <Switch checked={s.enabled} label={t(`appHome.cards.${s.key}.title`)} onChange={(v) => toggle(i, v)} />
              <button type="button" aria-label={`${t('appHome.up')}: ${t(`appHome.cards.${s.key}.title`)}`} disabled={i === 0} onClick={() => move(i, -1)} className="grid size-10 place-items-center rounded-xl text-navy-800 ring-1 ring-navy-100 hover:bg-ivory disabled:opacity-30"><ArrowUp className="size-4" /></button>
              <button type="button" aria-label={`${t('appHome.down')}: ${t(`appHome.cards.${s.key}.title`)}`} disabled={i === draft.length - 1} onClick={() => move(i, 1)} className="grid size-10 place-items-center rounded-xl text-navy-800 ring-1 ring-navy-100 hover:bg-ivory disabled:opacity-30"><ArrowDown className="size-4" /></button>
            </Card>
          </li>
        ))}
      </ol>
      <p className="text-xs text-slate-400">{t('appHome.note')}</p>
      <div className="sticky bottom-4 z-10 flex items-center justify-between gap-3 rounded-2xl border border-navy-100 bg-white/90 p-3 shadow-glass backdrop-blur">
        <span className={clsx('text-sm font-semibold', dirty ? 'text-amber-700' : 'text-slate-400')}>{dirty ? t('appHome.unsaved') : t('appHome.upToDate')}</span>
        <div className="flex gap-2">
          <Button variant="outline" disabled={!dirty || busy} onClick={() => setDraft(JSON.parse(base) as Section[])}>{t('appHome.discard')}</Button>
          <Button variant="gold" icon={<Check className="size-4" />} loading={busy} disabled={!dirty} onClick={save}>{t('appHome.save')}</Button>
        </div>
      </div>
    </div>
  )
}
