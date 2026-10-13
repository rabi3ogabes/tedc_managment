import clsx from 'clsx'
import { AlertTriangle, CheckCircle2, CircleAlert, ShieldCheck } from 'lucide-react'
import { useQueryClient } from '@tanstack/react-query'
import { useState } from 'react'
import { useTranslation } from 'react-i18next'
import { Badge, Button, Card, Field, Modal, Progress } from '@/components/ui'
import { useGet } from '@/hooks/useApi'
import { api, errorMessage } from '@/lib/api'
import { toast } from '@/lib/toast'
import { fmt } from '@/lib/format'
import { type Compliance, type Issue, type ProgramType } from './programTypes'

/** A small label with the programme type; "type missing" when the item has none. */
export function TypeBadge({ type, types }: { type: string | null | undefined; types: ProgramType[] }) {
  const { t, i18n } = useTranslation()
  const ar = i18n.language !== 'en'
  const found = types.find((x) => x.id === type)
  if (!found) return <Badge color="amber">{t('plans.types.missing')}</Badge>
  return <Badge color="navy">{ar ? found.name_ar : found.name_en}</Badge>
}

/** Toggle chips for the year's programme types: Arabic names with the English one as a hint. No chip on means "every type". */
export function TypeChips({ value, onChange, disabled, types }: { value: string[]; onChange: (v: string[]) => void; disabled?: boolean; types: ProgramType[] }) {
  const { i18n } = useTranslation()
  const ar = i18n.language !== 'en'
  return (
    <div className="flex flex-wrap gap-2" role="group">
      {types.map((ty) => {
        const on = value.includes(ty.id)
        return (
          <button key={ty.id} type="button" disabled={disabled} aria-pressed={on} aria-label={ar ? ty.name_ar : ty.name_en} title={ar ? ty.hint_ar : ty.hint_en}
            onClick={() => onChange(on ? value.filter((x) => x !== ty.id) : [...value, ty.id])}
            className={clsx('rounded-2xl border px-4 py-2 text-start transition-colors disabled:cursor-not-allowed disabled:opacity-60', on ? 'border-navy-900 bg-navy-900 text-white' : 'border-navy-100 bg-surface text-navy-900 hover:border-gold-400')}>
            <span className="block text-sm font-bold">{ar ? ty.name_ar : ty.name_en}</span>
            <span className={clsx('block text-[11px]', on ? 'text-white/70' : 'text-slate-500')}>{ar ? ty.name_en : ty.name_ar}</span>
          </button>
        )
      })}
    </div>
  )
}

/** How the plan stands against the year's rules, with a way to each fix. */
export function ComplianceCard({ c, types, onFix }: { c: Compliance; types: ProgramType[]; onFix: (where: 'items' | 'rules') => void }) {
  const { t, i18n } = useTranslation()
  const ar = i18n.language !== 'en'
  const [showExcluded, setShowExcluded] = useState(false)
  const bar = (label: string, used: number, max: number | null) => {
    const pct = max ? (used / max) * 100 : 0
    return (
      <div className="space-y-1.5">
        <div className="flex items-baseline justify-between gap-3 text-sm"><span className="font-semibold text-navy-900">{label}</span><span className="tabular-nums text-slate-500">{fmt.number(used)}{max !== null ? ` / ${fmt.number(max)}` : ` · ${t('plans.compliance.noCap')}`}</span></div>
        {max !== null && <Progress value={pct} tone={pct > 100 ? 'red' : pct > 90 ? 'gold' : 'green'} />}
      </div>
    )
  }
  const ok = c.violations.length === 0
  return (
    <Card className="space-y-5">
      <div className="flex flex-wrap items-center gap-3">
        <span className={clsx('grid size-10 place-items-center rounded-2xl', ok ? 'bg-emerald-50 text-emerald-700' : 'bg-danger/10 text-danger')}>{ok ? <ShieldCheck className="size-5" /> : <CircleAlert className="size-5" />}</span>
        <div className="min-w-0 flex-1"><h3 className="font-bold text-navy-900">{t('plans.compliance.title')}</h3><p className="text-sm text-slate-500">{ok ? t('plans.compliance.ok') : t('plans.compliance.broken', { n: c.violations.length })}</p></div>
        {c.deferred > 0 && <Badge color="amber">{t('plans.compliance.deferred', { n: c.deferred })}</Badge>}
      </div>

      <div className="grid gap-5 lg:grid-cols-2">
        <div className="space-y-4">{bar(t('plans.seats'), c.caps.seats.used, c.caps.seats.max)}{bar(t('plans.hours'), c.caps.hours.used, c.caps.hours.max)}</div>
        <div>
          <p className="mb-2 text-sm font-semibold text-navy-900">{t('plans.compliance.byType')}</p>
          <ul className="flex flex-wrap gap-2">
            {types.map((ty) => {
              const allowed = c.allowed_types.length === 0 || c.allowed_types.includes(ty.id)
              return <li key={ty.id} className={clsx('rounded-xl border px-3 py-1.5 text-sm', allowed ? 'border-navy-100' : 'border-dashed border-navy-200 text-slate-400')}><span className="font-semibold">{ar ? ty.name_ar : ty.name_en}</span> <span className="tabular-nums text-slate-500">{fmt.number(c.by_type[ty.id] ?? 0)}</span></li>
            })}
            {(c.by_type.missing ?? 0) > 0 && <li className="rounded-xl border border-amber-200 bg-amber-50 px-3 py-1.5 text-sm text-amber-800">{t('plans.types.missing')} <span className="tabular-nums">{fmt.number(c.by_type.missing)}</span></li>}
          </ul>
        </div>
      </div>

      {c.mandatory.length > 0 && (
        <div>
          <p className="mb-2 text-sm font-semibold text-navy-900">{t('plans.compliance.mandatory')}</p>
          <ul className="flex flex-wrap gap-2">{c.mandatory.map((m) => <li key={m.id} className={clsx('inline-flex items-center gap-1.5 rounded-xl px-3 py-1.5 text-sm', m.covered ? 'bg-emerald-50 text-emerald-800' : 'bg-danger/10 text-danger')}>{m.covered ? <CheckCircle2 className="size-4" /> : <CircleAlert className="size-4" />}{ar ? m.name_ar : m.name_en}</li>)}</ul>
        </div>
      )}

      {(c.violations.length > 0 || c.warnings.length > 0) && (
        <ul className="space-y-2">
          {c.violations.map((v) => (
            <li key={v.code} className="flex flex-wrap items-center gap-3 rounded-xl bg-danger/5 px-3 py-2 text-sm text-danger">
              <CircleAlert className="size-4 shrink-0" /><span className="min-w-0 flex-1">{ar ? v.message.ar : v.message.en}</span>
              <button type="button" className="font-semibold underline underline-offset-2" onClick={() => onFix(v.code === 'mandatory_missing' ? 'rules' : 'items')}>{t('plans.compliance.fix')}</button>
            </li>
          ))}
          {c.warnings.map((w) => (
            <li key={w.code} className="flex flex-wrap items-center gap-3 rounded-xl bg-amber-50 px-3 py-2 text-sm text-amber-800">
              <AlertTriangle className="size-4 shrink-0" /><span className="min-w-0 flex-1">{ar ? w.message.ar : w.message.en}</span>
              <button type="button" className="font-semibold underline underline-offset-2" onClick={() => onFix('items')}>{t('plans.compliance.fix')}</button>
            </li>
          ))}
        </ul>
      )}

      {c.excluded_needs.length > 0 && (
        <div>
          <button type="button" className="text-sm font-semibold text-navy-800 underline underline-offset-2" aria-expanded={showExcluded} onClick={() => setShowExcluded(!showExcluded)}>{t('plans.compliance.excluded', { n: c.excluded_needs.length })}</button>
          {showExcluded && <ul className="mt-2 space-y-1 text-sm text-slate-600">{c.excluded_needs.map((n) => <li key={n.need_ids.join()} className="flex flex-wrap gap-2"><span className="font-semibold text-navy-900">{n.title}</span><TypeBadge type={n.program_type} types={types} /><span>{t('plans.compliance.typeNotAllowed')}</span><span className="tabular-nums text-slate-400">{fmt.number(n.seats)} {t('plans.seats')}</span></li>)}</ul>}
        </div>
      )}

      {c.exception && <p className="rounded-xl bg-gold-50 px-3 py-2 text-sm text-gold-800"><strong>{t('plans.compliance.exception')}:</strong> {c.exception.reason}{c.exception.by_name ? ` — ${c.exception.by_name}` : ''} · {fmt.dateTime(c.exception.at)}</p>}
    </Card>
  )
}

/** When the API refuses a step because a rule is broken: the list, and — for the head of planning — going ahead with a documented reason. */
export function RulesBrokenDialog({ open, violations, canOverride, busy, onClose, onConfirm }: { open: boolean; violations: Issue[]; canOverride: boolean; busy: boolean; onClose: () => void; onConfirm: (reason: string) => void }) {
  const { t, i18n } = useTranslation()
  const ar = i18n.language !== 'en'
  const [reason, setReason] = useState('')
  return (
    <Modal open={open} onClose={onClose} title={t('plans.compliance.blockedTitle')}>
      <div className="space-y-4">
        <ul className="space-y-2">{violations.map((v) => <li key={v.code} className="flex gap-2 rounded-xl bg-danger/5 px-3 py-2 text-sm text-danger"><CircleAlert className="mt-0.5 size-4 shrink-0" />{ar ? v.message.ar : v.message.en}</li>)}</ul>
        {canOverride ? (
          <>
            <Field label={t('plans.compliance.exceptionReason')} hint={t('plans.compliance.exceptionHint')}><textarea className="input min-h-24" value={reason} maxLength={1000} onChange={(e) => setReason(e.target.value)} /></Field>
            <div className="flex justify-end gap-2"><Button variant="ghost" onClick={onClose}>{t('plans.compliance.fixFirst')}</Button><Button variant="gold" loading={busy} disabled={!reason.trim()} onClick={() => onConfirm(reason.trim())}>{t('plans.compliance.goAhead')}</Button></div>
          </>
        ) : (
          <>
            <p className="text-sm text-slate-600">{t('plans.compliance.askHead')}</p>
            <div className="flex justify-end"><Button variant="gold" onClick={onClose}>{t('plans.compliance.fixFirst')}</Button></div>
          </>
        )}
      </div>
    </Modal>
  )
}

/** The type each programme category carries; programmes follow it unless they set their own (needs the programme-management right to change). */
export function CategoryTypes({ types, editable }: { types: ProgramType[]; editable: boolean }) {
  const { t, i18n } = useTranslation()
  const ar = i18n.language !== 'en'
  const qc = useQueryClient()
  const res = useGet<{ data: { id: string; name: string; program_type: string | null }[] }>('/public/categories', undefined, { staleTime: 0 })
  const save = async (id: string, type: string) => {
    try {
      await api.put(`/admin/categories/${id}`, { program_type: type || null })
      toast(t('plans.types.saved'))
      // Every cached list of categories (catalogue, programme editor lookups, plans) now shows the new type.
      void qc.invalidateQueries({ predicate: (q) => ['/public/categories', '/admin/lookups', '/admin/plans'].some((p) => String(q.queryKey[0] ?? '').startsWith(p)) })
    } catch (e) { toast(errorMessage(e), 'error') }
  }
  return (
    <section className="space-y-3">
      <div><h3 className="font-bold text-navy-900">{t('plans.types.byCategory')}</h3><p className="text-sm text-slate-500">{t('plans.types.byCategoryHint')}</p></div>
      <ul className="grid gap-2 sm:grid-cols-2">
        {(res.data?.data ?? []).map((c) => (
          <li key={c.id} className="flex items-center gap-3 rounded-xl border border-navy-100 px-3 py-2">
            <span className="min-w-0 flex-1 truncate text-sm font-semibold text-navy-900">{c.name}</span>
            <select className="input w-44 py-1.5 text-sm" aria-label={`${t('plans.types.label')}: ${c.name}`} disabled={!editable} value={c.program_type ?? ''} onChange={(e) => void save(c.id, e.target.value)}>
              <option value="">{t('plans.types.missing')}</option>
              {types.map((ty) => <option key={ty.id} value={ty.id}>{ar ? ty.name_ar : ty.name_en}</option>)}
            </select>
          </li>
        ))}
      </ul>
    </section>
  )
}
