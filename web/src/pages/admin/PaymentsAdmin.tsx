/* eslint-disable @typescript-eslint/no-explicit-any */
import { Download, Plus } from 'lucide-react'
import { useEffect, useState } from 'react'
import { useTranslation } from 'react-i18next'
import { Badge, Button, Card, Empty, Field, Modal, PageHeader, Spinner, StatCard, Table, Tabs, Td } from '@/components/ui'
import { useGet } from '@/hooks/useApi'
import { useAuth } from '@/lib/auth'
import { api, downloadFile, errorMessage } from '@/lib/api'
import { fmt } from '@/lib/format'
import { toast } from '@/lib/toast'

type Tab = 'orders' | 'payments' | 'refunds' | 'codes' | 'entities' | 'recon' | 'report' | 'settings'
const TONE: Record<string, any> = { paid: 'green', captured: 'green', pending_payment: 'amber', initiated: 'amber', failed: 'red', cancelled: 'gray', refunded: 'blue', partially_refunded: 'blue', requested: 'amber', approved: 'green', rejected: 'red' }

/** Finance workspace: orders, payments, the refunds queue, discount codes, entity accounts, reconciliation, reports and settings. */
export default function PaymentsAdmin() {
  const { t } = useTranslation()
  const { can } = useAuth()
  const all: { id: Tab; ok: boolean }[] = [
    { id: 'orders', ok: can('orders.view') }, { id: 'payments', ok: can('orders.view') }, { id: 'refunds', ok: can('orders.view') }, { id: 'codes', ok: can('pricing.manage') }, { id: 'entities', ok: can('entity_accounts.manage') },
    { id: 'recon', ok: can('orders.view') }, { id: 'report', ok: can('finance.reports') }, { id: 'settings', ok: can('pricing.manage') },
  ]
  const tabs = all.filter((x) => x.ok).map((x) => ({ id: x.id, label: t(`pay.admin.tabs.${x.id}`) }))
  const [tab, setTab] = useState<Tab>(tabs[0]?.id ?? 'orders')
  return (
    <>
      <PageHeader title={t('pay.admin.title')} />
      <Tabs<Tab> value={tab} onChange={setTab} tabs={tabs} />
      {tab === 'orders' && <Orders />}{tab === 'payments' && <Payments />}{tab === 'refunds' && <Refunds />}{tab === 'codes' && <Codes />}
      {tab === 'entities' && <Entities />}{tab === 'recon' && <Recon />}{tab === 'report' && <Report />}{tab === 'settings' && <Settings />}
    </>
  )
}

function Orders() {
  const { t } = useTranslation()
  const [status, setStatus] = useState('')
  const [q, setQ] = useState('')
  const res = useGet<{ data: any[] }>('/admin/orders', { status: status || undefined, q: q || undefined }, { staleTime: 0 })
  return (
    <div className="space-y-4">
      <div className="flex flex-wrap gap-2"><input className="input max-w-xs" value={q} onChange={(e) => setQ(e.target.value)} placeholder={String(t('pay.admin.search'))} />
        <select className="input w-auto" value={status} onChange={(e) => setStatus(e.target.value)}><option value="">{t('pay.admin.all')}</option>{Object.keys(TONE).filter((k) => ['pending_payment', 'paid', 'failed', 'cancelled', 'refunded', 'partially_refunded'].includes(k)).map((s) => <option key={s} value={s}>{t(`pay.orders.st.${s}`)}</option>)}</select></div>
      {res.isLoading ? <Spinner /> : <Card padded={false}><Table head={[t('pay.orders.number'), t('pay.admin.buyer'), t('pay.orders.total'), t('pay.orders.status'), t('pay.orders.invoice'), '']}>
        {(res.data?.data ?? []).map((o) => (
          <tr key={o.id}><Td><span className="font-mono text-xs" dir="ltr">{o.number}</span><div className="text-xs text-slate-400">{fmt.dateTime(o.created_at)}</div></Td><Td>{o.buyer}<div className="text-xs text-slate-400">{o.buyer_type}</div></Td><Td><b>{fmt.number(o.total, 2)}</b> {o.currency}</Td>
            <Td><Badge color={TONE[o.status] ?? 'gray'}>{t(`pay.orders.st.${o.status}`)}</Badge></Td><Td>{o.invoice_no ? <button type="button" className="text-xs text-link underline" onClick={() => downloadFile(`/admin/orders/${o.id}/invoice`, `${o.invoice_no}.pdf`)}>{o.invoice_no}</button> : '—'}</Td><Td>{null}</Td></tr>
        ))}
      </Table></Card>}
    </div>
  )
}

function Payments() {
  const { t } = useTranslation()
  const res = useGet<{ data: any[] }>('/admin/payments', undefined, { staleTime: 0 })
  if (res.isLoading) return <Spinner />
  return <Card padded={false}><Table head={[t('pay.orders.number'), t('pay.admin.gateway'), t('pay.admin.ref'), t('pay.admin.amount'), t('pay.orders.status'), t('pay.admin.sig')]}>
    {(res.data?.data ?? []).map((p) => <tr key={p.id}><Td><span className="font-mono text-xs" dir="ltr">{p.order}</span></Td><Td>{p.gateway}</Td><Td><span className="font-mono text-xs" dir="ltr">{p.gateway_ref}</span></Td><Td>{fmt.number(p.amount, 2)}</Td><Td><Badge color={TONE[p.status] ?? 'gray'}>{p.status}</Badge></Td><Td>{p.signature_valid === null ? '—' : p.signature_valid ? '✓' : '✗'}</Td></tr>)}
  </Table></Card>
}

function Refunds() {
  const { t } = useTranslation()
  const { can } = useAuth()
  const [status, setStatus] = useState('requested')
  const res = useGet<{ data: any[] }>('/admin/refunds', { status }, { staleTime: 0 })
  const [note, setNote] = useState<Record<string, string>>({})
  const decide = async (id: string, decision: 'approve' | 'reject') => {
    if (decision === 'reject' && !(note[id] ?? '').trim()) { toast(String(t('pay.admin.needReason')), 'error'); return }
    try { await api.post(`/admin/refunds/${id}/decision`, { decision, note: note[id] || undefined }); toast(String(t('pay.admin.decided'))); res.refetch() } catch (e) { toast(errorMessage(e), 'error') }
  }
  return (
    <div className="space-y-4">
      <select className="input w-auto" value={status} onChange={(e) => setStatus(e.target.value)}>{['requested', 'refunded', 'rejected', 'failed', 'all'].map((s) => <option key={s} value={s}>{s === 'all' ? t('pay.admin.all') : t(`pay.orders.rf.${s}`)}</option>)}</select>
      {res.isLoading ? <Spinner /> : (res.data?.data.length ?? 0) === 0 ? <Empty /> : (
        <div className="space-y-3">{res.data!.data.map((r) => (
          <Card key={r.id}>
            <div className="flex flex-wrap items-start justify-between gap-3"><div><div className="font-mono text-xs" dir="ltr">{r.order}</div><div className="font-semibold text-navy-900">{r.buyer} · {fmt.number(r.amount, 2)} QAR</div>{r.reason && <p className="text-sm text-slate-500">{r.reason}</p>}</div><Badge color={TONE[r.status] ?? 'gray'}>{t(`pay.orders.rf.${r.status}`)}</Badge></div>
            {r.status === 'requested' && can('refunds.approve') && <div className="mt-3 flex flex-wrap items-center gap-2"><input className="input min-w-52 flex-1" placeholder={String(t('pay.admin.note'))} value={note[r.id] ?? ''} onChange={(e) => setNote({ ...note, [r.id]: e.target.value })} /><Button variant="gold" onClick={() => decide(r.id, 'approve')}>{t('pay.admin.approve')}</Button><Button variant="outline" onClick={() => decide(r.id, 'reject')}>{t('pay.admin.reject')}</Button></div>}
            {r.credit_note_no && <button type="button" className="mt-2 text-xs text-link underline" onClick={() => downloadFile(`/admin/refunds/${r.id}/credit-note`, `${r.credit_note_no}.pdf`)}>{t('pay.orders.creditNote')} {r.credit_note_no}</button>}
          </Card>))}</div>
      )}
    </div>
  )
}

function Codes() {
  const { t } = useTranslation()
  const res = useGet<{ data: any[] }>('/admin/discount-codes', undefined, { staleTime: 0 })
  const [edit, setEdit] = useState<any | null>(null)
  const blank = { code: '', type: 'percent', value: 10, scope: 'all', scope_id: '', usage_limit: '', per_user_limit: 1, valid_to: '', is_active: true }
  const save = async () => {
    try {
      const body = { ...edit, value: Number(edit.value), usage_limit: edit.usage_limit === '' ? null : Number(edit.usage_limit), per_user_limit: Number(edit.per_user_limit), scope_id: edit.scope === 'all' ? null : edit.scope_id, valid_to: edit.valid_to || null }
      await (edit.id ? api.put(`/admin/discount-codes/${edit.id}`, body) : api.post('/admin/discount-codes', body)); toast(String(t('pay.admin.saved'))); setEdit(null); res.refetch()
    } catch (e) { toast(errorMessage(e), 'error') }
  }
  return (
    <>
      <div className="mb-4 flex justify-end"><Button variant="gold" icon={<Plus className="size-4" />} onClick={() => setEdit(blank)}>{t('pay.admin.newCode')}</Button></div>
      <Card padded={false}><Table head={[t('pay.admin.code'), t('pay.admin.type'), t('pay.admin.value'), t('pay.admin.scope'), t('pay.admin.used'), t('pay.admin.validTo'), '']}>
        {(res.data?.data ?? []).map((c) => <tr key={c.id}><Td><b className="font-mono">{c.code}</b></Td><Td>{t(`pay.admin.types.${c.type}`)}</Td><Td>{c.value}</Td><Td>{t(`pay.admin.scopes.${c.scope}`)}</Td><Td>{c.used}{c.usage_limit ? ` / ${c.usage_limit}` : ''}</Td><Td>{c.valid_to ? fmt.date(c.valid_to) : '—'}</Td><Td><Button size="sm" variant="outline" onClick={() => setEdit({ ...c, usage_limit: c.usage_limit ?? '', valid_to: c.valid_to?.slice(0, 10) ?? '', scope_id: c.scope_id ?? '' })}>✎</Button></Td></tr>)}
      </Table></Card>
      {edit && <Modal open onClose={() => setEdit(null)} title={t('pay.admin.newCode')} wide>
        <div className="grid gap-4 sm:grid-cols-2">
          <Field label={t('pay.admin.code')}><input className="input font-mono uppercase" dir="ltr" value={edit.code} onChange={(e) => setEdit({ ...edit, code: e.target.value.replace(/[^A-Za-z0-9_-]/g, '') })} /></Field>
          <Field label={t('pay.admin.type')}><select className="input" value={edit.type} onChange={(e) => setEdit({ ...edit, type: e.target.value })}><option value="percent">{t('pay.admin.types.percent')}</option><option value="amount">{t('pay.admin.types.amount')}</option></select></Field>
          <Field label={t('pay.admin.value')}><input type="number" min={0} className="input" value={edit.value} onChange={(e) => setEdit({ ...edit, value: e.target.value })} /></Field>
          <Field label={t('pay.admin.scope')}><select className="input" value={edit.scope} onChange={(e) => setEdit({ ...edit, scope: e.target.value })}>{['all', 'program', 'group'].map((s) => <option key={s} value={s}>{t(`pay.admin.scopes.${s}`)}</option>)}</select></Field>
          {edit.scope !== 'all' && <Field label={t('pay.admin.scopeId')} className="sm:col-span-2"><input className="input" dir="ltr" value={edit.scope_id} onChange={(e) => setEdit({ ...edit, scope_id: e.target.value })} /></Field>}
          <Field label={t('pay.admin.limit')}><input type="number" min={1} className="input" value={edit.usage_limit} onChange={(e) => setEdit({ ...edit, usage_limit: e.target.value })} /></Field>
          <Field label={t('pay.admin.perUser')}><input type="number" min={1} className="input" value={edit.per_user_limit} onChange={(e) => setEdit({ ...edit, per_user_limit: e.target.value })} /></Field>
          <Field label={t('pay.admin.validTo')}><input type="date" className="input" value={edit.valid_to} onChange={(e) => setEdit({ ...edit, valid_to: e.target.value })} /></Field>
        </div>
        <div className="mt-6 flex justify-end gap-2"><Button variant="ghost" onClick={() => setEdit(null)}>{t('pay.common.cancel')}</Button><Button variant="gold" disabled={!edit.code} onClick={save}>{t('pay.common.save')}</Button></div>
      </Modal>}
    </>
  )
}

function Entities() {
  const { t } = useTranslation()
  const res = useGet<{ data: any[] }>('/admin/entity-accounts', undefined, { staleTime: 0 })
  const [edit, setEdit] = useState<any | null>(null)
  const blank = { name_ar: '', name_en: '', type: 'private_school', cr_number: '', billing_address: '', admin_emails: '' }
  const save = async () => {
    try {
      const body = { ...edit, admin_emails: String(edit.admin_emails).split('\n').map((x: string) => x.trim()).filter(Boolean), cr_number: edit.cr_number || null, billing_address: edit.billing_address || null }
      await (edit.id ? api.put(`/admin/entity-accounts/${edit.id}`, body) : api.post('/admin/entity-accounts', body)); toast(String(t('pay.admin.saved'))); setEdit(null); res.refetch()
    } catch (e) { toast(errorMessage(e), 'error') }
  }
  return (
    <>
      <div className="mb-4 flex justify-end"><Button variant="gold" icon={<Plus className="size-4" />} onClick={() => setEdit(blank)}>{t('pay.admin.newEntity')}</Button></div>
      <Card padded={false}><Table head={[t('pay.admin.entityName'), t('pay.admin.type'), t('pay.admin.cr'), t('pay.admin.tabs.entities'), '']}>
        {(res.data?.data ?? []).map((e) => <tr key={e.id}><Td><b>{e.name_en}</b><div className="text-xs text-slate-400">{e.name_ar}</div></Td><Td>{t(`pay.admin.etype.${e.type}`)}</Td><Td>{e.cr_number ?? '—'}</Td><Td className="text-xs">{e.admins.map((a: any) => a.email).join(', ')}</Td><Td><Button size="sm" variant="outline" onClick={() => setEdit({ ...e, admin_emails: '', cr_number: e.cr_number ?? '', billing_address: e.billing_address ?? '' })}>✎</Button></Td></tr>)}
      </Table></Card>
      {edit && <Modal open onClose={() => setEdit(null)} title={t('pay.admin.newEntity')} wide>
        <div className="grid gap-4 sm:grid-cols-2">
          <Field label={`${t('pay.admin.entityName')} (AR)`}><input className="input" dir="rtl" value={edit.name_ar} onChange={(e) => setEdit({ ...edit, name_ar: e.target.value })} /></Field>
          <Field label={`${t('pay.admin.entityName')} (EN)`}><input className="input" dir="ltr" value={edit.name_en} onChange={(e) => setEdit({ ...edit, name_en: e.target.value })} /></Field>
          <Field label={t('pay.admin.type')}><select className="input" value={edit.type} onChange={(e) => setEdit({ ...edit, type: e.target.value })}>{['private_school', 'company', 'other'].map((x) => <option key={x} value={x}>{t(`pay.admin.etype.${x}`)}</option>)}</select></Field>
          <Field label={t('pay.admin.cr')}><input className="input" dir="ltr" value={edit.cr_number} onChange={(e) => setEdit({ ...edit, cr_number: e.target.value })} /></Field>
          <Field label={t('pay.admin.adminsEmail')} className="sm:col-span-2"><textarea className="input min-h-20" dir="ltr" value={edit.admin_emails} onChange={(e) => setEdit({ ...edit, admin_emails: e.target.value })} /></Field>
        </div>
        <div className="mt-6 flex justify-end gap-2"><Button variant="ghost" onClick={() => setEdit(null)}>{t('pay.common.cancel')}</Button><Button variant="gold" disabled={!edit.name_ar || !edit.name_en} onClick={save}>{t('pay.common.save')}</Button></div>
      </Modal>}
    </>
  )
}

function Recon() {
  const { t } = useTranslation()
  const res = useGet<{ data: any[] }>('/admin/payment-reconciliations', undefined, { staleTime: 0 })
  const run = async () => { try { await api.post('/admin/payment-reconciliations/run'); res.refetch() } catch (e) { toast(errorMessage(e), 'error') } }
  return (
    <div className="space-y-4">
      <div className="flex justify-end"><Button variant="outline" onClick={run}>{t('pay.admin.reconRun')}</Button></div>
      <Card padded={false}><Table head={[t('pay.admin.day'), t('pay.admin.matched'), t('pay.admin.fixed'), t('pay.admin.diffs')]}>
        {(res.data?.data ?? []).map((r) => <tr key={r.id}><Td>{fmt.date(r.day)}</Td><Td>{r.matched}</Td><Td>{r.fixed}</Td><Td>{(r.mismatches ?? []).length ? <span className="text-xs text-danger">{(r.mismatches as any[]).map((m) => `${m.type} ${m.ref ?? ''}`).join(' · ')}</span> : '—'}</Td></tr>)}
      </Table></Card>
    </div>
  )
}

function Report() {
  const { t, i18n } = useTranslation()
  const [from, setFrom] = useState('')
  const [to, setTo] = useState('')
  const res = useGet<{ data: any }>('/admin/finance/report', { from: from || undefined, to: to || undefined }, { staleTime: 0 })
  const d = res.data?.data
  const ex = (f: string) => downloadFile(`/admin/finance/report/${f}?lang=${i18n.language}${from ? `&from=${from}` : ''}${to ? `&to=${to}` : ''}`, `finance-report.${f}`)
  return (
    <div className="space-y-5">
      <div className="flex flex-wrap items-end gap-3"><Field label={t('pay.admin.from')}><input type="date" className="input" value={from} onChange={(e) => setFrom(e.target.value)} /></Field><Field label={t('pay.admin.to')}><input type="date" className="input" value={to} onChange={(e) => setTo(e.target.value)} /></Field>
        <div className="ms-auto flex gap-2">{['xlsx', 'pdf', 'csv'].map((f) => <Button key={f} size="sm" variant="outline" icon={<Download className="size-4" />} onClick={() => ex(f)}>{f.toUpperCase()}</Button>)}</div></div>
      {!d ? <Spinner /> : <>
        <div className="grid gap-4 sm:grid-cols-2 xl:grid-cols-5"><StatCard label={t('pay.admin.gross')} value={fmt.number(d.totals.gross, 2)} /><StatCard label={t('pay.admin.refunded')} value={fmt.number(d.totals.refunded, 2)} /><StatCard label={t('pay.admin.netRev')} value={fmt.number(d.totals.net, 2)} accent /><StatCard label={t('pay.admin.discounts')} value={fmt.number(d.totals.discounts, 2)} /><StatCard label={t('pay.admin.outstanding')} value={fmt.number(d.totals.outstanding, 2)} hint={`${d.totals.outstanding_orders}`} /></div>
        <div className="grid gap-5 lg:grid-cols-2">
          <Card padded={false}><h3 className="px-5 pt-4 font-bold text-navy-900">{t('pay.admin.revByProgram')}</h3><Table head={['', t('pay.admin.seats'), t('pay.admin.revenue')]}>{d.by_program.map((r: any) => <tr key={r.program_id}><Td>{i18n.language === 'en' ? r.title_en : r.title_ar}</Td><Td>{r.seats}</Td><Td>{fmt.number(r.revenue, 2)}</Td></tr>)}</Table></Card>
          <div className="space-y-5">
            <Card padded={false}><h3 className="px-5 pt-4 font-bold text-navy-900">{t('pay.admin.revByCategory')}</h3><Table head={['', t('pay.admin.seats'), t('pay.admin.revenue')]}>{d.by_category.map((r: any) => <tr key={r.category}><Td>{t(`pay.pricing.categories.${r.category}`, { defaultValue: r.category })}</Td><Td>{r.seats}</Td><Td>{fmt.number(r.revenue, 2)}</Td></tr>)}</Table></Card>
            <Card padded={false}><h3 className="px-5 pt-4 font-bold text-navy-900">{t('pay.admin.revByEntity')}</h3><Table head={['', t('pay.orders.title'), t('pay.admin.revenue')]}>{d.by_entity.map((r: any) => <tr key={r.entity_id}><Td>{r.name}</Td><Td>{r.orders}</Td><Td>{fmt.number(r.revenue, 2)}</Td></tr>)}</Table></Card>
          </div>
        </div>
      </>}
    </div>
  )
}

function Settings() {
  const { t } = useTranslation()
  const res = useGet<{ data: any }>('/admin/payment-settings', undefined, { staleTime: 0 })
  const [f, setF] = useState<any>(null)
  useEffect(() => { if (res.data) setF(res.data.data) }, [res.data])
  if (!f) return <Spinner />
  const save = async () => { try { setF((await api.put('/admin/payment-settings', f)).data.data); toast(String(t('pay.admin.saved'))) } catch (e) { toast(errorMessage(e), 'error') } }
  const num = (k: string, label: string) => <Field label={label}><input type="number" className="input" value={f[k]} onChange={(e) => setF({ ...f, [k]: Number(e.target.value) })} /></Field>
  const txt = (k: string, label: string, dir?: string) => <Field label={label}><input className="input" dir={dir} value={f[k] ?? ''} onChange={(e) => setF({ ...f, [k]: e.target.value })} /></Field>
  return (
    <Card className="max-w-3xl space-y-4">
      <div className="grid gap-4 sm:grid-cols-2">{num('hold_minutes', t('pay.admin.set.hold'))}{num('order_minutes', t('pay.admin.set.order'))}{num('voucher_valid_days', t('pay.admin.set.voucherDays'))}{num('voucher_reminder_days', t('pay.admin.set.reminder'))}{num('default_vat_rate', t('pay.admin.set.vat'))}
        {txt('tax_id', t('pay.admin.set.tax'), 'ltr')}{txt('seller_name_ar', t('pay.admin.set.sellerAr'), 'rtl')}{txt('seller_name_en', t('pay.admin.set.sellerEn'), 'ltr')}{txt('finance_email', t('pay.admin.set.email'), 'ltr')}</div>
      <label className="flex items-center gap-2 text-sm"><input type="checkbox" className="accent-gold-600" checked={f.skip_manager_approval} onChange={(e) => setF({ ...f, skip_manager_approval: e.target.checked })} />{t('pay.admin.set.skip')}</label>
      <div className="flex justify-end"><Button variant="gold" onClick={save}>{t('pay.common.save')}</Button></div>
    </Card>
  )
}
