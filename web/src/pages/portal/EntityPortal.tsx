/* eslint-disable @typescript-eslint/no-explicit-any */
import { useState } from 'react'
import { useTranslation } from 'react-i18next'
import CartPanel from '@/components/shop/CartPanel'
import { Badge, Button, Card, Empty, Field, PageHeader, Spinner, StatCard, Table, Tabs, Td } from '@/components/ui'
import { useGet } from '@/hooks/useApi'
import { api, errorMessage } from '@/lib/api'
import { fmt } from '@/lib/format'
import { toast } from '@/lib/toast'

type Tab = 'buy' | 'vouchers' | 'orders'

/** An entity's account: buy seats for staff, hand the vouchers out, and see how many were used. */
export default function EntityPortal() {
  const { t, i18n } = useTranslation()
  const en = i18n.language === 'en'
  const accounts = useGet<{ data: any[] }>('/entity/accounts', undefined, { staleTime: 0, retry: false })
  const [pick, setPick] = useState<string>('')
  const [tab, setTab] = useState<Tab>('buy')
  if (accounts.isLoading) return <Spinner />
  const list = accounts.data?.data ?? []
  if (!list.length) return <><PageHeader title={t('pay.entity.title')} /><Empty text={String(t('pay.entity.none'))} /></>
  const entity = list.find((e) => e.id === pick) ?? list[0]
  const u = entity.usage
  return (
    <>
      <PageHeader title={t('pay.entity.title')} subtitle={en ? entity.name_en : entity.name_ar} actions={list.length > 1 && <select className="input w-auto" value={entity.id} onChange={(e) => setPick(e.target.value)}>{list.map((e) => <option key={e.id} value={e.id}>{en ? e.name_en : e.name_ar}</option>)}</select>} />
      <div className="mb-6 grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
        <StatCard label={t('pay.entity.total')} value={fmt.number(u.total)} /><StatCard label={t('pay.entity.redeemedN')} value={fmt.number(u.redeemed)} />
        <StatCard label={t('pay.entity.unused')} value={fmt.number(u.unused)} hint={u.expired ? `${t('pay.entity.expired')}: ${u.expired}` : undefined} /><StatCard label={t('pay.entity.spent')} value={`${fmt.number(u.spent, 2)} ${t('pay.price.currency')}`} />
      </div>
      <Tabs<Tab> value={tab} onChange={setTab} tabs={[{ id: 'buy', label: t('pay.entity.buy') }, { id: 'vouchers', label: t('pay.entity.vouchers') }, { id: 'orders', label: t('pay.entity.orders') }]} />
      {tab === 'buy' && <Buy entityId={entity.id} />}
      {tab === 'vouchers' && <Vouchers entityId={entity.id} onChanged={() => accounts.refetch()} />}
      {tab === 'orders' && <Orders entityId={entity.id} />}
    </>
  )
}

function Buy({ entityId }: { entityId: string }) {
  const { t } = useTranslation()
  const [q, setQ] = useState('')
  const [program, setProgram] = useState<any | null>(null)
  const [gid, setGid] = useState('')
  const [qty, setQty] = useState(5)
  const programs = useGet<{ data: any[] }>(q.length >= 2 ? '/public/programs' : null, { q, open: 1, per_page: 8 }, { staleTime: 0 })
  const detail = useGet<{ data: any }>(program ? `/public/programs/${program.code}` : null, undefined, { staleTime: 0 })
  const groups: any[] = detail.data?.data.groups?.data ?? detail.data?.data.groups ?? []
  const add = async () => {
    try { await api.post('/entity/cart', { entity_id: entityId, group_id: gid || groups[0]?.id, quantity: qty }); toast(String(t('pay.add.added'))); setProgram(null); setQ(''); cart.refetch() } catch (e) { toast(errorMessage(e), 'error') }
  }
  const cart = useGet<{ data: any }>('/entity/cart', { entity_id: entityId }, { staleTime: 0 })
  return (
    <div className="grid gap-5 lg:grid-cols-[1fr_24rem]">
      <Card className="space-y-4">
        <Field label={t('pay.entity.program')}><input className="input" value={q} onChange={(e) => { setQ(e.target.value); setProgram(null) }} /></Field>
        {!program && (programs.data?.data.length ?? 0) > 0 && <ul className="divide-y divide-navy-50 rounded-xl border border-navy-100">{programs.data!.data.map((p) => <li key={p.id}><button type="button" className="block w-full px-4 py-2.5 text-start text-sm hover:bg-ivory" onClick={() => setProgram(p)}><b>{p.title}</b> <span className="text-xs text-slate-400" dir="ltr">{p.code}</span></button></li>)}</ul>}
        {program && (
          <div className="space-y-3 rounded-xl bg-ivory p-4">
            <div className="font-bold text-navy-900">{program.title}</div>
            {groups.length > 1 && <Field label={t('pay.entity.group')}><select className="input" value={gid || groups[0].id} onChange={(e) => setGid(e.target.value)}>{groups.map((g) => <option key={g.id} value={g.id}>{g.title ?? g.code} · {fmt.date(g.start_date)}</option>)}</select></Field>}
            <Field label={t('pay.entity.qty')}><input type="number" min={1} max={500} className="input w-32" value={qty} onChange={(e) => setQty(Math.max(1, Number(e.target.value)))} /></Field>
            <Button variant="gold" disabled={!groups.length} onClick={add}>{t('pay.entity.add')}</Button>
          </div>
        )}
      </Card>
      <Card><h3 className="mb-3 font-bold text-navy-900">{t('pay.entity.cart')}</h3><CartPanel key={cart.dataUpdatedAt} entityId={entityId} /></Card>
    </div>
  )
}

function Vouchers({ entityId, onChanged }: { entityId: string; onChanged: () => void }) {
  const { t, i18n } = useTranslation()
  const en = i18n.language === 'en'
  const res = useGet<{ data: any[] }>('/entity/vouchers', { entity_id: entityId }, { staleTime: 0 })
  const [text, setText] = useState('')
  const [busy, setBusy] = useState(false)
  const assign = async () => {
    const entries = text.split('\n').map((l) => l.trim()).filter(Boolean).map((l) => (l.includes('@') ? { email: l } : { employee_no: l }))
    setBusy(true)
    try {
      const { data } = await api.post('/entity/vouchers/assign', { entity_id: entityId, entries })
      toast(String(t('pay.entity.assigned', { n: data.data.assigned })))
      if (data.data.failed.length) toast(`${t('pay.entity.failedRows', { n: data.data.failed.length })}: ${data.data.failed[0].error}`, 'error')
      setText(''); res.refetch(); onChanged()
    } catch (e) { toast(errorMessage(e), 'error') } finally { setBusy(false) }
  }
  return (
    <div className="space-y-5">
      <Card className="space-y-3">
        <h3 className="font-bold text-navy-900">{t('pay.entity.assign')}</h3>
        <p className="text-xs text-slate-500">{t('pay.entity.assignHint')}</p>
        <textarea className="input min-h-28 font-mono text-sm" dir="ltr" value={text} onChange={(e) => setText(e.target.value)} />
        <div className="flex justify-end"><Button variant="gold" loading={busy} disabled={!text.trim()} onClick={assign}>{t('pay.entity.assignBtn')}</Button></div>
      </Card>
      {res.isLoading ? <Spinner /> : (
        <Card padded={false}><Table head={['', t('pay.entity.person'), t('pay.orders.status'), '']}>
          {(res.data?.data ?? []).map((v) => (
            <tr key={v.id}><Td><div className="font-semibold">{en ? v.program_en : v.program_ar}</div><div className="font-mono text-xs text-slate-400" dir="ltr">{v.code}</div></Td><Td>{v.assigned_to ?? '—'}</Td><Td><Badge color={v.status === 'redeemed' ? 'green' : v.status === 'expired' || v.status === 'refunded' ? 'gray' : 'gold'}>{t(`pay.vouchers.st.${v.status}`)}</Badge></Td><Td><span className="text-xs text-slate-400">{fmt.date(v.expires_at)}</span></Td></tr>
          ))}
        </Table></Card>
      )}
    </div>
  )
}

function Orders({ entityId }: { entityId: string }) {
  const { t } = useTranslation()
  const res = useGet<{ data: any[] }>('/entity/orders', { entity_id: entityId }, { staleTime: 0 })
  if (res.isLoading) return <Spinner />
  return (
    <Card padded={false}><Table head={[t('pay.orders.number'), t('pay.orders.date'), t('pay.orders.total'), t('pay.orders.status')]}>
      {(res.data?.data ?? []).map((o) => <tr key={o.id}><Td><span className="font-mono text-xs" dir="ltr">{o.number}</span></Td><Td>{fmt.date(o.created_at)}</Td><Td><b>{fmt.number(o.total, 2)}</b> {o.currency}</Td><Td><Badge color={o.status === 'paid' ? 'green' : 'gray'}>{t(`pay.orders.st.${o.status}`)}</Badge></Td></tr>)}
    </Table></Card>
  )
}
