import { CalendarClock, KeyRound, Play, ShieldCheck, Timer, Trash2 } from 'lucide-react'
import { useState } from 'react'
import { useTranslation } from 'react-i18next'
import { Link } from 'react-router-dom'
import { Badge, Button, Card, CardTitle, Modal, Spinner } from '@/components/ui'
import { useGet, useSend } from '@/hooks/useApi'
import { errorMessage } from '@/lib/api'
import { fmt } from '@/lib/format'
import { toast } from '@/lib/toast'

type Report = {
  policy: { retention_days: number; invitation_retention_days: number; draft_ttl_days: number; key_grace_days: number }
  pending: { drafts: number; orphan_files: number; invitations: number; keys: number; answers: number }
  due: { id: string; title: string; closed_at?: string | null; submissions: number }[]
  upcoming: { id: string; title: string; retire_at: string }[]
  held: { id: string; title: string }[]
  purged: number; keys_deleted: number
}
type Run = { dry_run: boolean; drafts: number; orphan_files: number; invitations: number; keys: number; answers: number; retired: { id: string; title: string }[] }

/** The retention panel (Gate 7): the policy, what the next daily run will remove, the surveys falling due, legal holds, and a dry or real run by hand. */
export default function RetentionPanel({ open, onClose }: { open: boolean; onClose: () => void }) {
  const { t } = useTranslation()
  const rep = useGet<{ data: Report }>(open ? '/admin/surveys/retention' : null, undefined, { staleTime: 0 })
  const run = useSend<{ dry_run: boolean }, { data: Run }>('post', '/admin/surveys/retention', ['/admin/surveys'])
  const [last, setLast] = useState<Run | null>(null)
  const d = rep.data?.data
  const go = async (dry: boolean) => {
    if (!dry && !window.confirm(String(t('ss.retention.confirm')))) return
    try { const { data } = await run.mutateAsync({ dry_run: dry }); setLast(data); if (!dry) await rep.refetch() } catch (e) { toast(errorMessage(e), 'error') }
  }
  const months = (days: number) => Math.round(days / 30.44)
  return (
    <Modal open={open} onClose={onClose} title={t('ss.retention.title')} wide>
      {!d ? <Spinner /> : (
        <div className="space-y-5">
          <p className="rounded-2xl border border-navy-100 bg-ivory px-4 py-3 text-sm leading-7 text-slate-700"><ShieldCheck className="me-2 inline size-4 text-gold-700" />{t('ss.retention.intro')}</p>
          <div className="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
            {[
              { icon: <Timer className="size-4" />, label: t('ss.retention.policy.answers'), value: t('ss.retention.months', { n: months(d.policy.retention_days) }), pending: d.pending.answers },
              { icon: <KeyRound className="size-4" />, label: t('ss.retention.policy.keys'), value: t('ss.retention.days', { n: d.policy.key_grace_days }), pending: d.pending.keys },
              { icon: <Trash2 className="size-4" />, label: t('ss.retention.policy.invitations'), value: t('ss.retention.days', { n: d.policy.invitation_retention_days }), pending: d.pending.invitations },
              { icon: <CalendarClock className="size-4" />, label: t('ss.retention.policy.drafts'), value: t('ss.retention.days', { n: d.policy.draft_ttl_days }), pending: d.pending.drafts + d.pending.orphan_files },
            ].map((c) => (
              <Card key={c.label}><div className="flex items-center gap-2 text-xs text-slate-500">{c.icon}{c.label}</div><div className="mt-1 font-display text-xl font-bold text-navy-900">{c.value}</div><div className="mt-1 text-xs">{c.pending ? <Badge color="amber">{t('ss.retention.pendingN', { n: c.pending })}</Badge> : <span className="text-slate-400">{t('ss.retention.nothingPending')}</span>}</div></Card>
            ))}
          </div>
          <div className="grid gap-4 md:grid-cols-2">
            <Card>
              <CardTitle subtitle={t('ss.retention.dueHint')}>{t('ss.retention.due')}</CardTitle>
              {!d.due.length ? <p className="text-sm text-slate-400">{t('ss.retention.noneDue')}</p> : <ul className="space-y-1 text-sm">{d.due.map((s) => <li key={s.id} className="flex items-center justify-between gap-2"><Link to={`/admin/surveys/${s.id}`} className="truncate font-semibold text-navy-900 hover:text-gold-700">{s.title}</Link><span className="shrink-0 text-xs text-slate-500">{s.submissions} · {fmt.date(s.closed_at ?? undefined)}</span></li>)}</ul>}
              {!!d.upcoming.length && <><div className="mb-1 mt-4 text-xs font-semibold text-slate-500">{t('ss.retention.upcoming')}</div><ul className="space-y-1 text-sm">{d.upcoming.map((s) => <li key={s.id} className="flex items-center justify-between gap-2"><Link to={`/admin/surveys/${s.id}`} className="truncate text-navy-900 hover:text-gold-700">{s.title}</Link><span className="shrink-0 text-xs text-slate-500">{fmt.date(s.retire_at)}</span></li>)}</ul></>}
            </Card>
            <Card>
              <CardTitle subtitle={t('ss.retention.heldHint')}>{t('ss.retention.held')}</CardTitle>
              {!d.held.length ? <p className="text-sm text-slate-400">{t('ss.retention.noneHeld')}</p> : <ul className="space-y-1 text-sm">{d.held.map((s) => <li key={s.id}><Link to={`/admin/surveys/${s.id}`} className="font-semibold text-navy-900 hover:text-gold-700">{s.title}</Link></li>)}</ul>}
              <div className="mt-4 flex flex-wrap gap-1.5 text-xs"><Badge color="gray">{t('ss.retention.purgedN', { n: d.purged })}</Badge><Badge color="gray">{t('ss.retention.keysDeletedN', { n: d.keys_deleted })}</Badge></div>
            </Card>
          </div>
          <div className="flex flex-wrap items-center gap-2">
            <Button variant="outline" loading={run.isPending} onClick={() => go(true)}>{t('ss.retention.dryRun')}</Button>
            <Button variant="gold" icon={<Play className="size-4" />} loading={run.isPending} onClick={() => go(false)}>{t('ss.retention.runNow')}</Button>
            <span className="text-xs text-slate-500">{t('ss.retention.scheduleNote')}</span>
          </div>
          {last && (
            <div className={`rounded-2xl border p-4 text-sm ${last.dry_run ? 'border-slate-200 bg-slate-50 text-slate-700' : 'border-emerald-200 bg-emerald-50 text-emerald-900'}`}>
              <div className="font-semibold">{t(last.dry_run ? 'ss.retention.dryResult' : 'ss.retention.runResult', { drafts: last.drafts + last.orphan_files, invitations: last.invitations, keys: last.keys, answers: last.answers })}</div>
              {!!last.retired.length && <ul className="mt-1 list-disc ps-5 text-xs">{last.retired.map((s) => <li key={s.id}>{s.title}</li>)}</ul>}
            </div>
          )}
        </div>
      )}
    </Modal>
  )
}
