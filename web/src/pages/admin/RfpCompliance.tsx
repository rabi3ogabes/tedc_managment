import clsx from 'clsx'
import { ChevronDown, Search, Star } from 'lucide-react'
import { useMemo, useState } from 'react'
import { useTranslation } from 'react-i18next'
import { Card, Empty, PageHeader, Spinner } from '@/components/ui'
import { useGet } from '@/hooks/useApi'
import { fmt } from '@/lib/format'

type Status = 'available' | 'partial' | 'missing'
type Item = { id: string; mandatory: boolean; requirement: string; status: Status; evidence: string; phase: number | null }
type Mod = { code: string; title_en: string; title_ar: string; items: Item[] }
type Payload = {
  totals: { total: number; available: number; partial: number; missing: number; mandatory: number; open: number }
  phases: { phase: number; title: string; open: number; items: number; done: number }[]
  modules: Mod[]
  mandatory31: { no: number; requirement: string; status: Status | 'vendor'; notes: string }[]
  generated_at: string
}

const TONE: Record<Status | 'vendor', string> = { available: 'bg-emerald-50 text-emerald-700', partial: 'bg-amber-50 text-amber-700', missing: 'bg-red-50 text-red-700', vendor: 'bg-slate-100 text-slate-500' }
const BAR: Record<Status, string> = { available: 'bg-emerald-500', partial: 'bg-amber-400', missing: 'bg-red-400' }
const STATUSES: Status[] = ['available', 'partial', 'missing']
const score = (a: number, p: number, total: number) => (total ? Math.round(((a + p / 2) / total) * 100) : 0)

function Bar({ a, p, m }: { a: number; p: number; m: number }) {
  const total = a + p + m || 1
  return (
    <div className="flex h-2.5 w-full overflow-hidden rounded-full bg-slate-100" role="img" aria-label={`${a} / ${p} / ${m}`}>
      <span className="bg-emerald-500" style={{ width: `${(a / total) * 100}%` }} /><span className="bg-amber-400" style={{ width: `${(p / total) * 100}%` }} /><span className="bg-red-400" style={{ width: `${(m / total) * 100}%` }} />
    </div>
  )
}

/** Settings → RFP Compliance: how much of the Ministry's RFP the platform covers, per module and phase, with the evidence. */
export default function RfpCompliance() {
  const { t, i18n } = useTranslation()
  const lang = i18n.language === 'ar' ? 'ar' : 'en'
  const { data, isLoading } = useGet<{ data: Payload }>('/admin/rfp-status')
  const [q, setQ] = useState('')
  const [status, setStatus] = useState<Status | ''>('')
  const [phase, setPhase] = useState<number | ''>('')
  const [mandatory, setMandatory] = useState(false)
  const [open, setOpen] = useState<Set<string>>(new Set())

  const filtered = useMemo(() => {
    const d = data?.data
    if (!d) return []
    const needle = q.trim().toLowerCase()
    return d.modules.map((m) => ({
      ...m,
      shown: m.items.filter((i) => (!status || i.status === status) && (phase === '' || i.phase === phase) && (!mandatory || i.mandatory) && (!needle || `${i.id} ${i.requirement} ${i.evidence}`.toLowerCase().includes(needle))),
    })).filter((m) => m.shown.length)
  }, [data, q, status, phase, mandatory])

  if (isLoading || !data) return <Spinner />
  const d = data.data
  const { totals } = d
  const toggle = (code: string) => setOpen((s) => { const n = new Set(s); if (n.has(code)) n.delete(code); else n.add(code); return n })
  const chip = (on: boolean) => clsx('rounded-full px-3.5 py-1.5 text-xs font-bold transition', on ? 'bg-navy-900 text-white' : 'bg-white text-slate-600 ring-1 ring-navy-100 hover:ring-gold-300')

  return (
    <div className="space-y-6 pb-6">
      <PageHeader title={t('rfp.title')} subtitle={t('rfp.subtitle')} />

      <Card className="grid gap-6 lg:grid-cols-[auto_1fr] lg:items-center">
        <div className="text-center lg:text-start">
          <div className="text-5xl font-extrabold tabular-nums text-navy-900">{score(totals.available, totals.partial, totals.total)}<span className="text-2xl text-gold-500">%</span></div>
          <div className="mt-1 text-xs text-slate-500">{t('rfp.coverage')}</div>
        </div>
        <div className="space-y-3">
          <Bar a={totals.available} p={totals.partial} m={totals.missing} />
          <div className="flex flex-wrap gap-x-5 gap-y-1 text-sm">
            {STATUSES.map((s) => <span key={s} className="inline-flex items-center gap-2"><span className={clsx('size-2.5 rounded-full', BAR[s])} /><b className="tabular-nums">{fmt.number(totals[s])}</b><span className="text-slate-500">{t(`rfp.status.${s}`)}</span></span>)}
            <span className="inline-flex items-center gap-2 text-slate-500"><Star className="size-3.5 text-gold-500" /><b className="tabular-nums text-navy-900">{fmt.number(totals.mandatory)}</b>{t('rfp.mandatory')}</span>
          </div>
        </div>
      </Card>

      <Card className="space-y-3">
        <h2 className="text-sm font-bold text-slate-500">{t('rfp.phases')}</h2>
        <div className="grid gap-2 sm:grid-cols-2 xl:grid-cols-3">
          {d.phases.map((p) => (
            <button key={p.phase} type="button" onClick={() => setPhase(phase === p.phase ? '' : p.phase)} className={clsx('rounded-2xl border p-3 text-start transition', phase === p.phase ? 'border-gold-400 bg-gold-50' : 'border-navy-100 bg-white hover:border-gold-300')}>
              <div className="flex items-center justify-between gap-2 text-sm"><span className="truncate font-bold text-navy-900">{String(p.phase).padStart(2, '0')} · {p.title}</span><span className="shrink-0 text-xs tabular-nums text-slate-500">{p.done}/{p.items}</span></div>
              <div className="mt-2 h-1.5 overflow-hidden rounded-full bg-slate-100"><span className="block h-full rounded-full bg-emerald-500" style={{ width: `${p.items ? (p.done / p.items) * 100 : 100}%` }} /></div>
            </button>
          ))}
        </div>
      </Card>

      <div className="flex flex-wrap items-center gap-2">
        <div className="relative min-w-56 flex-1"><Search className="pointer-events-none absolute start-3 top-1/2 size-4 -translate-y-1/2 text-slate-400" /><input className="input ps-9" placeholder={t('rfp.search')} value={q} onChange={(e) => setQ(e.target.value)} /></div>
        <button type="button" className={chip(status === '')} onClick={() => setStatus('')}>{t('common.all')}</button>
        {STATUSES.map((s) => <button key={s} type="button" className={chip(status === s)} onClick={() => setStatus(status === s ? '' : s)}>{t(`rfp.status.${s}`)}</button>)}
        <button type="button" aria-pressed={mandatory} className={chip(mandatory)} onClick={() => setMandatory(!mandatory)}><Star className="me-1 inline size-3" />{t('rfp.mandatoryOnly')}</button>
      </div>

      {filtered.length === 0 ? <Card><Empty text={t('rfp.empty')} /></Card> : filtered.map((m) => {
        const a = m.items.filter((i) => i.status === 'available').length, p = m.items.filter((i) => i.status === 'partial').length, ms = m.items.length - a - p
        const expanded = open.has(m.code) || !!q || !!status || phase !== '' || mandatory
        return (
          <Card key={m.code} padded={false}>
            <button type="button" onClick={() => toggle(m.code)} aria-expanded={expanded} className="flex w-full items-center gap-4 p-4 text-start">
              <span className="grid size-11 shrink-0 place-items-center rounded-xl bg-navy-900 font-mono text-xs font-bold text-gold-300" dir="ltr">{m.code}</span>
              <div className="min-w-0 flex-1"><div className="truncate font-bold text-navy-900">{lang === 'ar' ? m.title_ar : m.title_en}</div><div className="mt-2"><Bar a={a} p={p} m={ms} /></div></div>
              <span className="shrink-0 text-sm font-bold tabular-nums text-navy-900">{score(a, p, m.items.length)}%</span>
              <ChevronDown className={clsx('size-4 shrink-0 text-slate-400 transition', expanded && 'rotate-180')} />
            </button>
            {expanded && (
              <ul className="divide-y divide-navy-50 border-t border-navy-100">
                {m.shown.map((i) => (
                  <li key={i.id} className="flex flex-wrap items-start gap-3 px-4 py-3">
                    <span className="mt-0.5 w-20 shrink-0 font-mono text-xs font-bold text-navy-700" dir="ltr">{i.id}{i.mandatory && <Star className="ms-1 inline size-3 text-gold-500" aria-label={t('rfp.mandatory')} />}</span>
                    <div className="min-w-0 flex-1"><div className="text-sm font-semibold text-navy-900">{i.requirement}</div><div className="mt-0.5 text-xs text-slate-500">{i.evidence}</div></div>
                    <div className="flex shrink-0 items-center gap-2"><span className={clsx('rounded-full px-2.5 py-0.5 text-xs font-bold', TONE[i.status])}>{t(`rfp.status.${i.status}`)}</span>{i.phase !== null && <span className="rounded-full bg-navy-50 px-2 py-0.5 text-xs font-bold text-navy-700">{t('rfp.phase', { n: i.phase })}</span>}</div>
                  </li>
                ))}
              </ul>
            )}
          </Card>
        )
      })}

      <Card padded={false}>
        <div className="border-b border-navy-100 p-4"><h2 className="font-bold text-navy-900">{t('rfp.main31')}</h2></div>
        <ul className="divide-y divide-navy-50">
          {d.mandatory31.map((m) => (
            <li key={m.no} className="flex flex-wrap items-start gap-3 px-4 py-3">
              <span className="w-8 shrink-0 text-sm font-bold tabular-nums text-slate-400">{m.no}</span>
              <div className="min-w-0 flex-1"><div className="text-sm font-semibold text-navy-900">{m.requirement}</div><div className="mt-0.5 text-xs text-slate-500">{m.notes}</div></div>
              <span className={clsx('shrink-0 rounded-full px-2.5 py-0.5 text-xs font-bold', TONE[m.status])}>{t(`rfp.status.${m.status}`)}</span>
            </li>
          ))}
        </ul>
      </Card>
      <p className="text-xs text-slate-400">{t('rfp.generated', { date: fmt.dateTime(d.generated_at) })}</p>
    </div>
  )
}
