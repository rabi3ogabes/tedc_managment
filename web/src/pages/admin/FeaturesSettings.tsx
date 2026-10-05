import clsx from 'clsx'
import { History, ShieldAlert, ToggleRight, TriangleAlert } from 'lucide-react'
import { useState } from 'react'
import { useTranslation } from 'react-i18next'
import { Badge, Button, Card, Field, Modal, PageHeader, Spinner } from '@/components/ui'
import { useFeatures } from '@/hooks/useFeature'
import { useGet } from '@/hooks/useApi'
import { api, errorMessage } from '@/lib/api'
import { fmt } from '@/lib/format'
import { Switch } from './notifications/shared'

type Row = {
  key: string; phase: number; unsafe: boolean; title: { ar: string; en: string }; description: { ar: string; en: string }
  enabled: boolean; default: boolean; source: 'default' | 'override'; reason: string | null
  history: { enabled: boolean; reason: string | null; by: string | null; at: string }[]
}

/** Settings → Features: switch product features and risky tools on or off, with the reason on record. */
export default function FeaturesSettings() {
  const { t, i18n } = useTranslation()
  const lang = i18n.language === 'ar' ? 'ar' : 'en'
  const { data, isLoading, refetch } = useGet<{ data: Row[] }>('/admin/features', undefined, { staleTime: 0 })
  const env = useFeatures().data?.data.environment
  const production = env === 'production'
  const [asking, setAsking] = useState<Row | null>(null)
  const [reason, setReason] = useState('')
  const [open, setOpen] = useState<string | null>(null)
  const [busy, setBusy] = useState<string | null>(null)
  const [note, setNote] = useState<{ ok: boolean; text: string } | null>(null)
  const features = useFeatures()

  if (isLoading || !data) return <Spinner />

  const send = async (row: Row, enabled: boolean, why?: string) => {
    setBusy(row.key); setNote(null)
    try {
      await api.put(`/admin/features/${row.key}`, { enabled, reason: why || undefined })
      await Promise.all([refetch(), features.refetch()])
      setNote({ ok: true, text: t('features.saved') }); setAsking(null); setReason('')
    } catch (e) { setNote({ ok: false, text: errorMessage(e) }) } finally { setBusy(null) }
  }
  const toggle = (row: Row, enabled: boolean) => (enabled && row.unsafe && production ? setAsking(row) : void send(row, enabled))

  const risky = data.data.filter((r) => r.unsafe)
  const product = data.data.filter((r) => !r.unsafe)
  const card = (r: Row) => (
    <Card key={r.key} className={clsx('space-y-3', r.unsafe && r.enabled && production && 'border-amber-300 bg-amber-50/40')}>
      <div className="flex items-start gap-3">
        <span className={clsx('grid size-11 shrink-0 place-items-center rounded-xl', r.unsafe ? 'bg-amber-100 text-amber-800' : 'bg-navy-900 text-gold-300')}>{r.unsafe ? <ShieldAlert className="size-5" /> : <ToggleRight className="size-5" />}</span>
        <div className="min-w-0 flex-1">
          <div className="flex flex-wrap items-center gap-2"><h3 className="font-bold text-navy-900">{r.title[lang]}</h3>{r.unsafe && <Badge color="gold">{t('features.careful')}</Badge>}<span className="text-xs text-slate-400">{t('features.phase', { n: r.phase })}</span></div>
          <p className="mt-1 text-sm text-slate-500">{r.description[lang]}</p>
        </div>
        <Switch checked={r.enabled} label={r.title[lang]} onChange={(v) => toggle(r, v)} />
      </div>
      <div className="flex flex-wrap items-center gap-x-4 gap-y-1 text-xs text-slate-500">
        <span className={clsx('rounded-full px-2.5 py-0.5 font-bold', r.enabled ? 'bg-emerald-50 text-emerald-700' : 'bg-slate-100 text-slate-500')}>{r.enabled ? t('features.on') : t('features.off')}</span>
        <span>{r.source === 'override' ? t('features.changed') : t('features.byDefault', { state: r.default ? t('features.on') : t('features.off') })}</span>
        {r.reason && r.enabled && <span className="truncate" title={r.reason}>{t('features.because')} {r.reason}</span>}
        {r.history.length > 0 && <button type="button" onClick={() => setOpen(open === r.key ? null : r.key)} className="inline-flex items-center gap-1 font-bold text-navy-700"><History className="size-3.5" />{t('features.history')}</button>}
        {busy === r.key && <Spinner className="size-4" />}
      </div>
      {open === r.key && (
        <ul className="space-y-1.5 rounded-xl bg-ivory p-3 text-xs">
          {r.history.map((h, i) => <li key={i} className="flex flex-wrap gap-x-3"><span className={h.enabled ? 'font-bold text-emerald-700' : 'font-bold text-slate-500'}>{h.enabled ? t('features.on') : t('features.off')}</span><span>{h.by ?? '—'}</span><span className="text-slate-400">{fmt.dateTime(h.at)}</span>{h.reason && <span className="basis-full text-slate-600">{h.reason}</span>}</li>)}
        </ul>
      )}
    </Card>
  )

  return (
    <div className="space-y-6 pb-6">
      <PageHeader title={t('features.title')} subtitle={t('features.subtitle')} />
      {production && <div className="flex items-center gap-2 rounded-2xl bg-amber-50 p-3 text-sm font-semibold text-amber-900"><TriangleAlert className="size-4" />{t('features.productionNote')}</div>}
      {note && <div role="status" className={clsx('rounded-2xl p-3 text-sm font-semibold', note.ok ? 'bg-emerald-50 text-emerald-800' : 'bg-red-50 text-danger')}>{note.text}</div>}
      <section className="space-y-3"><h2 className="text-sm font-bold text-slate-500">{t('features.toolsTitle')}</h2><div className="grid gap-4 lg:grid-cols-2">{risky.map(card)}</div></section>
      <section className="space-y-3"><h2 className="text-sm font-bold text-slate-500">{t('features.productTitle')}</h2><div className="grid gap-4 lg:grid-cols-2">{product.map(card)}</div></section>

      <Modal open={!!asking} onClose={() => { setAsking(null); setReason('') }} title={asking ? t('features.askTitle', { name: asking.title[lang] }) : ''}>
        <p className="mb-4 text-sm text-slate-600">{t('features.askBody')}</p>
        <Field label={t('features.reason')}><textarea className="input min-h-24" value={reason} onChange={(e) => setReason(e.target.value)} placeholder={t('features.reasonPlaceholder')} /></Field>
        <div className="mt-5 flex gap-2">
          <Button variant="gold" loading={busy === asking?.key} disabled={reason.trim().length < 5} onClick={() => asking && void send(asking, true, reason.trim())}>{t('features.turnOn')}</Button>
          <Button variant="outline" onClick={() => { setAsking(null); setReason('') }}>{t('features.cancel')}</Button>
        </div>
      </Modal>
    </div>
  )
}
