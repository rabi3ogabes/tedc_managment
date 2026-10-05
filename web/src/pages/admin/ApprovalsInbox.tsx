import { useState } from 'react'
import { useTranslation } from 'react-i18next'
import { Badge, Button, Card, Empty, Field, Modal, PageHeader, Spinner, Tabs } from '@/components/ui'
import { useGet } from '@/hooks/useApi'
import { api, errorMessage } from '@/lib/api'
import { useAuth } from '@/lib/auth'
import { fmt } from '@/lib/format'
import { toast } from '@/lib/toast'

type Tab = 'manager' | 'center' | 'withdrawals' | 'external'
type Reg = { id: string; status: string; program: { title: string; code: string; closes_at: string | null }; group: string | null; employee: string; school: string | null; priority_score: number | null; priority_explanation: { ar: string; en: string; points: number }[] | null; manager_note: string | null }
type Wd = { id: string; program: string; employee: string; reason_code: string | null; reason_text: string | null; timing: string; stage: string; is_late: boolean; attachments: { name: string }[]; manager_note: string | null }
type Ext = { id: string; number: string; email: string; status: string; form: { title_ar: string; title_en: string; audience: string } | null; created_at: string; has_snapshot: boolean; data?: Record<string, unknown>; fields?: { key: string; label_ar: string; label_en: string }[] }

/** One inbox for everything waiting on the signed-in person: registrations (as manager or centre), withdrawals and external requests. */
export default function ApprovalsInbox() {
  const { t } = useTranslation()
  const { can } = useAuth()
  const tabs: { id: Tab; show: boolean }[] = [
    { id: 'manager', show: can('registrations.approve_manager') || can('registrations.manage') },
    { id: 'center', show: can('registrations.manage') || can('registrations.approve_center') },
    { id: 'withdrawals', show: can('registrations.approve_manager') || can('withdrawals.decide') },
    { id: 'external', show: can('external_requests.review') },
  ]
  const visible = tabs.filter((x) => x.show)
  const [tab, setTab] = useState<Tab>(visible[0]?.id ?? 'manager')
  return (
    <div className="space-y-6 pb-6">
      <PageHeader title={t('admission.title')} subtitle={t('admission.subtitle')} />
      <Tabs<Tab> value={tab} onChange={setTab} tabs={visible.map((x) => ({ id: x.id, label: t(`admission.tabs.${x.id}`) }))} />
      {(tab === 'manager' || tab === 'center') && <RegistrationQueue stage={tab} />}
      {tab === 'withdrawals' && <WithdrawalQueue />}
      {tab === 'external' && <ExternalQueue />}
    </div>
  )
}

function Explain({ items }: { items: Reg['priority_explanation'] }) {
  const { i18n } = useTranslation()
  if (!items?.length) return null
  return <ul className="mt-1 space-y-0.5 text-xs text-slate-500">{items.map((x, i) => <li key={i}>+{x.points} · {i18n.language === 'ar' ? x.ar : x.en}</li>)}</ul>
}

function RegistrationQueue({ stage }: { stage: 'manager' | 'center' }) {
  const { t } = useTranslation()
  const list = useGet<{ data: Reg[] }>(`/admin/approvals/${stage}`, undefined, { staleTime: 0 })
  const [sel, setSel] = useState<string[]>([])
  const [note, setNote] = useState('')
  const [early, setEarly] = useState<{ ids: string[]; message: string } | null>(null)
  const [reason, setReason] = useState('')

  const decide = async (ids: string[], decision: 'approved' | 'rejected', override?: string) => {
    let failed = 0
    for (const id of ids) {
      try {
        if (stage === 'manager') await api.post(`/admin/registrations/${id}/manager-decision`, { decision, note: note || undefined })
        else await api.patch(`/admin/registrations/${id}/status`, { status: decision, notes: note || undefined, override_reason: override })
      } catch (e) {
        const code = (e as { response?: { data?: { code?: string } } }).response?.data?.code
        if (code === 'window_open' && !override) { setEarly({ ids: [id], message: errorMessage(e) }); return }
        failed++; toast(errorMessage(e), 'error')
      }
    }
    if (failed < ids.length) toast(t('admission.decided'))
    setSel([]); setNote(''); setEarly(null); setReason(''); await list.refetch()
  }

  if (list.isLoading || !list.data) return <Spinner />
  return (
    <div className="space-y-3">
      <Card padded={false}>{list.data.data.length === 0 ? <Empty text={t('admission.empty')} /> : (
        <ul className="divide-y divide-navy-50">{list.data.data.map((r) => (
          <li key={r.id} className="flex items-start gap-3 px-5 py-4">
            <input type="checkbox" className="mt-1" aria-label={r.employee} checked={sel.includes(r.id)} onChange={(e) => setSel(e.target.checked ? [...sel, r.id] : sel.filter((x) => x !== r.id))} />
            <div className="min-w-0 flex-1">
              <div className="font-bold text-navy-900">{r.employee} <span className="font-normal text-slate-500">· {r.school}</span></div>
              <div className="text-sm text-slate-700">{r.program.title} <span className="font-mono text-xs text-slate-400" dir="ltr">{r.group ?? r.program.code}</span></div>
              {r.program.closes_at && <div className="text-xs text-amber-700">{t('admission.closesIn', { date: fmt.date(r.program.closes_at) })}</div>}
              {r.manager_note && <p className="text-xs text-slate-500">{r.manager_note}</p>}
              <Explain items={r.priority_explanation} />
            </div>
            {r.priority_score !== null && <Badge color="gold">{t('admission.priority')} {fmt.number(r.priority_score, 1)}</Badge>}
          </li>))}</ul>
      )}</Card>
      {sel.length > 0 && (
        <div className="sticky bottom-4 flex flex-wrap items-center gap-3 rounded-2xl bg-navy-900 p-4 text-white shadow-lg">
          <span className="text-sm font-bold">{sel.length}</span>
          <input className="input min-w-0 flex-1 text-navy-900" placeholder={t('admission.note')} value={note} onChange={(e) => setNote(e.target.value)} />
          <Button variant="gold" onClick={() => void decide(sel, 'approved')}>{t('admission.approve')}</Button>
          <Button variant="outline" disabled={!note.trim()} onClick={() => void decide(sel, 'rejected')}>{t('admission.reject')}</Button>
        </div>
      )}
      <Modal open={!!early} onClose={() => setEarly(null)} title={t('admission.overrideReason')}>
        <div className="space-y-4"><p className="text-sm text-slate-600">{early?.message || t('admission.overrideHint')}</p><textarea className="input min-h-20" value={reason} onChange={(e) => setReason(e.target.value)} /><Button variant="gold" disabled={!reason.trim()} onClick={() => early && void decide(early.ids, 'approved', reason)}>{t('admission.approve')}</Button></div>
      </Modal>
    </div>
  )
}

function WithdrawalQueue() {
  const { t } = useTranslation()
  const list = useGet<{ data: Wd[] }>('/admin/withdrawals', { status: 'pending' }, { staleTime: 0 })
  const [target, setTarget] = useState<Wd | null>(null)
  const [note, setNote] = useState('')
  const decide = async (decision: 'approved' | 'rejected') => {
    if (!target) return
    try { await api.post(`/admin/withdrawals/${target.id}/decision`, { decision, note: note || undefined }); toast(t('admission.decided')); setTarget(null); setNote(''); await list.refetch() } catch (e) { toast(errorMessage(e), 'error') }
  }
  if (list.isLoading || !list.data) return <Spinner />
  return (
    <>
      <Card padded={false}>{list.data.data.length === 0 ? <Empty text={t('admission.empty')} /> : (
        <ul className="divide-y divide-navy-50">{list.data.data.map((w) => (
          <li key={w.id} className="flex flex-wrap items-start gap-3 px-5 py-4">
            <div className="min-w-0 flex-1"><div className="font-bold text-navy-900">{w.employee}</div><div className="text-sm text-slate-700">{w.program}</div><p className="text-xs text-slate-500">{w.reason_code} {w.reason_text && `— ${w.reason_text}`}</p>{w.attachments.length > 0 && <p className="text-xs text-slate-400">{w.attachments.map((a) => a.name).join(', ')}</p>}</div>
            <Badge color="navy">{t(`admission.stage.${w.stage}`)}</Badge>{w.is_late && <Badge color="red">{t('admission.withdraw.late')}</Badge>}
            <Button size="sm" variant="outline" onClick={() => setTarget(w)}>{t('admission.approve')} / {t('admission.reject')}</Button>
          </li>))}</ul>
      )}</Card>
      <Modal open={!!target} onClose={() => setTarget(null)} title={target?.employee ?? ''}>
        <div className="space-y-4"><Field label={t('admission.note')}><textarea className="input min-h-20" value={note} onChange={(e) => setNote(e.target.value)} /></Field>
          <div className="flex gap-2"><Button variant="gold" onClick={() => void decide('approved')}>{t('admission.approve')}</Button><Button variant="outline" disabled={!note.trim()} onClick={() => void decide('rejected')}>{t('admission.reject')}</Button></div></div>
      </Modal>
    </>
  )
}

function ExternalQueue() {
  const { t, i18n } = useTranslation()
  const ar = i18n.language === 'ar'
  const list = useGet<{ data: Ext[] }>('/admin/registration-requests', { status: 'submitted,needs_info' }, { staleTime: 0 })
  const [open, setOpen] = useState<Ext | null>(null)
  const [note, setNote] = useState('')
  const detail = useGet<{ data: Ext }>(open ? `/admin/registration-requests/${open.id}` : null)
  const act = async (what: 'approve' | 'reject' | 'request-info') => {
    if (!open) return
    try { await api.post(`/admin/registration-requests/${open.id}/${what}`, { note: note || undefined }); toast(t('admission.decided')); setOpen(null); setNote(''); await list.refetch() } catch (e) { toast(errorMessage(e), 'error') }
  }
  if (list.isLoading || !list.data) return <Spinner />
  return (
    <>
      <Card padded={false}>{list.data.data.length === 0 ? <Empty text={t('admission.empty')} /> : (
        <ul className="divide-y divide-navy-50">{list.data.data.map((r) => (
          <li key={r.id} className="flex flex-wrap items-center gap-3 px-5 py-4"><span className="font-mono text-xs text-slate-400" dir="ltr">{r.number}</span><div className="min-w-0 flex-1"><div className="font-bold text-navy-900" dir="ltr">{r.email}</div><div className="text-xs text-slate-500">{ar ? r.form?.title_ar : r.form?.title_en} · {fmt.dateTime(r.created_at)}</div></div><Badge color="gold">{t(`admission.ext.status.${r.status}`)}</Badge><Button size="sm" variant="outline" onClick={() => setOpen(r)}>{t('admission.ext.approve')} / {t('admission.ext.reject')}</Button></li>))}</ul>
      )}</Card>
      <Modal open={!!open} onClose={() => setOpen(null)} title={open?.number ?? ''} wide>
        <div className="space-y-4">
          {!detail.data ? <Spinner /> : <dl className="grid gap-2 sm:grid-cols-2">{(detail.data.data.fields ?? []).map((f) => <div key={f.key} className="rounded-xl bg-ivory p-3"><dt className="text-xs text-slate-500">{ar ? f.label_ar : f.label_en}</dt><dd className="font-semibold text-navy-900">{String(detail.data!.data.data?.[f.key] ?? '—')}</dd></div>)}</dl>}
          <Field label={t('admission.note')}><textarea className="input min-h-20" value={note} onChange={(e) => setNote(e.target.value)} /></Field>
          <div className="flex flex-wrap gap-2"><Button variant="gold" onClick={() => void act('approve')}>{t('admission.ext.approve')}</Button><Button variant="outline" disabled={!note.trim()} onClick={() => void act('request-info')}>{t('admission.ext.info')}</Button><Button variant="outline" disabled={!note.trim()} onClick={() => void act('reject')}>{t('admission.ext.reject')}</Button></div>
        </div>
      </Modal>
    </>
  )
}
