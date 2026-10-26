import clsx from 'clsx'
import { ArrowLeft, Minus, Plus, Sparkles } from 'lucide-react'
import { useTranslation } from 'react-i18next'
import { fmt } from '@/lib/format'

type DayDesc = { date: string; start_time: string | null; end_time: string | null; room: string | null; trainer: string | null } | null
export type Diff = {
  program: { field: string; old: unknown; new: unknown }[]
  groups: { key: string; title: string | null; number: number; color: string | null; kind: 'added' | 'removed' | 'changed'; meta: { field: string; old: unknown; new: unknown }[]; days: { idx: number; old: DayDesc; new: DayDesc }[] }[]
  counts: { groups: number; days: number; added: number; removed: number }
  new_program: boolean
}

const KIND = { added: 'bg-sky-50 text-sky-700 ring-sky-600/20', removed: 'bg-rose-50 text-rose-700 ring-rose-600/20', changed: 'bg-gold-50 text-gold-800 ring-gold-600/20' }

/** Old (published) next to new (planned): programme fields, groups added / removed / changed, and every day that differs. */
export default function DiffView({ diff }: { diff: Diff }) {
  const { t } = useTranslation()
  const value = (field: string, v: unknown) => {
    if (v === null || v === undefined || v === '') return t('saber.review.none')
    if (field === 'delivery_mode') return t(`modes.${v}`, { defaultValue: String(v) })
    if (field === 'kit_ready') return v ? t('saber.program.kitReady') : t('saber.program.kitNotReady')
    if (field === 'term') return t(`saber.group.terms.${v}`, { defaultValue: String(v) })
    if (field === 'start_date') return fmt.date(String(v))
    return String(v)
  }
  const day = (d: DayDesc) => d ? (
    <span className="block">
      <span className="font-semibold text-navy-900">{fmt.date(d.date, { weekday: 'short', day: 'numeric', month: 'short' })}</span>
      <span className="ms-1 tabular-nums text-slate-500" dir="ltr">{d.start_time}–{d.end_time}</span>
      <span className="block text-[11px] text-slate-500">{[d.room, d.trainer].filter(Boolean).join(' · ') || t('saber.review.none')}</span>
    </span>
  ) : <span className="text-slate-300">{t('saber.review.none')}</span>

  if (!diff.new_program && diff.program.length === 0 && diff.groups.length === 0) return <p className="py-8 text-center text-sm text-slate-400">{t('saber.review.noChanges')}</p>

  return (
    <div className="space-y-4">
      {diff.new_program && <p className="flex items-center gap-2 rounded-xl bg-sky-50 px-3 py-2 text-sm font-semibold text-sky-800"><Sparkles className="size-4" />{t('saber.review.newProgram')}</p>}
      {diff.program.length > 0 && (
        <section className="rounded-xl border border-navy-100">
          <h5 className="border-b border-navy-50 bg-ivory/60 px-3 py-2 text-xs font-bold text-slate-500">{t('saber.review.program')}</h5>
          <ul className="divide-y divide-navy-50 text-sm">
            {diff.program.map((f) => (
              <li key={f.field} className="grid grid-cols-[8rem_1fr_auto_1fr] items-center gap-2 px-3 py-2">
                <span className="text-xs font-bold text-slate-500">{t(`saber.review.fields.${f.field}`, { defaultValue: f.field })}</span>
                <span className="text-slate-500 line-through decoration-rose-300">{value(f.field, f.old)}</span>
                <ArrowLeft className="size-3.5 text-gold-600 ltr:rotate-180" />
                <span className="font-semibold text-navy-900">{value(f.field, f.new)}</span>
              </li>
            ))}
          </ul>
        </section>
      )}
      {diff.groups.map((g) => (
        <section key={g.key} className="rounded-xl border border-navy-100" style={{ borderInlineStartWidth: 4, borderInlineStartColor: g.color ?? undefined }}>
          <header className="flex flex-wrap items-center gap-2 border-b border-navy-50 px-3 py-2">
            <span className="text-sm font-bold text-navy-900">{g.title || t('saber.group.n', { n: g.number })}</span>
            <span className={clsx('inline-flex items-center gap-1 rounded-full px-2 py-0.5 text-[10px] font-bold ring-1 ring-inset', KIND[g.kind])}>
              {g.kind === 'added' ? <Plus className="size-3" /> : g.kind === 'removed' ? <Minus className="size-3" /> : null}{t(`saber.review.kind.${g.kind}`)}
            </span>
          </header>
          {g.meta.length > 0 && (
            <ul className="divide-y divide-navy-50 border-b border-navy-50 text-xs">
              {g.meta.map((m) => (
                <li key={m.field} className="grid grid-cols-[7rem_1fr_auto_1fr] items-center gap-2 px-3 py-1.5">
                  <span className="font-bold text-slate-500">{t(`saber.review.fields.${m.field}`, { defaultValue: m.field })}</span>
                  <span className="text-slate-500 line-through decoration-rose-300">{value(m.field, m.old)}</span>
                  <ArrowLeft className="size-3 text-gold-600 ltr:rotate-180" />
                  <span className="font-semibold text-navy-900">{value(m.field, m.new)}</span>
                </li>
              ))}
            </ul>
          )}
          {g.days.length > 0 && (
            <table className="w-full text-xs">
              <thead className="text-[10px] font-bold text-slate-400"><tr><th className="w-16 px-3 py-1.5 text-start" /><th className="px-2 text-start">{t('saber.review.old')}</th><th /><th className="px-2 text-start">{t('saber.review.new')}</th></tr></thead>
              <tbody className="divide-y divide-navy-50">
                {g.days.map((d) => (
                  <tr key={d.idx} className="align-top">
                    <td className="px-3 py-2 font-bold text-navy-900">{t('saber.review.day', { n: d.idx })}</td>
                    <td className={clsx('px-2 py-2', d.old && 'opacity-70')}>{day(d.old)}</td>
                    <td className="py-2"><ArrowLeft className="mt-0.5 size-3 text-gold-600 ltr:rotate-180" /></td>
                    <td className="px-2 py-2">{day(d.new)}</td>
                  </tr>
                ))}
              </tbody>
            </table>
          )}
        </section>
      ))}
    </div>
  )
}
