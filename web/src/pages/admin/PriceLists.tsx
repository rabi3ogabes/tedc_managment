/* eslint-disable @typescript-eslint/no-explicit-any */
import { Plus, Trash2 } from 'lucide-react'
import { useEffect, useState } from 'react'
import { useTranslation } from 'react-i18next'
import { Link, useParams } from 'react-router-dom'
import { Badge, Button, Card, Empty, Field, PageHeader, Spinner, Tabs } from '@/components/ui'
import { useGet } from '@/hooks/useApi'
import { api, errorMessage } from '@/lib/api'
import { fmt } from '@/lib/format'
import { toast } from '@/lib/toast'

/** Prices of a programme: one list for the programme and, where needed, a list of its own for a group. */
export default function PriceLists() {
  const { t } = useTranslation()
  const { id } = useParams()
  const groups = useGet<{ data: any[] }>(`/admin/programs/${id}/groups`, undefined, { staleTime: 0 })
  const [tab, setTab] = useState<string>('program')
  if (groups.isLoading) return <Spinner />
  const tabs = [{ id: 'program', label: t('pay.pricing.program') }, ...(groups.data?.data ?? []).map((g: any) => ({ id: g.id, label: `${t('pay.pricing.group')} ${g.code ?? g.sequence}` }))]
  return (
    <>
      <PageHeader title={t('pay.pricing.title')} actions={<Link to={`/admin/programs/${id}`} className="text-sm font-semibold text-link">‹</Link>} />
      <Tabs<string> value={tab} onChange={setTab} tabs={tabs} />
      <Editor key={tab} url={tab === 'program' ? `/admin/programs/${id}/price-list` : `/admin/groups/${tab}/price-list`} inheritNote={tab !== 'program'} />
    </>
  )
}

function Editor({ url, inheritNote }: { url: string; inheritNote: boolean }) {
  const { t } = useTranslation()
  const res = useGet<{ data: any | null; categories: string[]; defaults: any }>(url, undefined, { staleTime: 0 })
  const [f, setF] = useState<any | null>(null)
  const [busy, setBusy] = useState(false)
  useEffect(() => { if (res.data) setF(res.data.data ? { ...res.data.data, rules: res.data.data.rules.map((r: any) => ({ ...r })) } : null) }, [res.data])
  if (res.isLoading) return <Spinner />
  const d = res.data!
  const create = () => setF({ rules: [{ category: 'ministry_staff', price: 0 }, { category: 'any', price: 0 }], default_price: 0, vat_rate: d.defaults.vat_rate, refund_policy: d.defaults.refund_policy, is_active: true, preview: [] })
  if (!f) return <Card className="space-y-3 text-center"><Empty text={String(t('pay.pricing.none'))} />{inheritNote && <p className="text-xs text-slate-500">{t('pay.pricing.inherit')}</p>}<div><Button variant="gold" onClick={create}>{t('pay.pricing.create')}</Button></div></Card>

  const set = (k: string, v: any) => setF({ ...f, [k]: v })
  const rule = (i: number, k: string, v: any) => set('rules', f.rules.map((r: any, j: number) => (j === i ? { ...r, [k]: v } : r)))
  const policy = (k: string, v: number) => set('refund_policy', { ...f.refund_policy, [k]: v })
  const save = async () => {
    setBusy(true)
    try {
      const body = { rules: f.rules.map((r: any) => ({ category: r.category, price: Number(r.price), label_ar: r.label_ar || null, label_en: r.label_en || null })), default_price: Number(f.default_price), vat_rate: Number(f.vat_rate), is_active: f.is_active, refund_policy: f.refund_policy }
      const { data } = await api.put(url, body); setF(data.data); toast(String(t('pay.admin.saved'))); res.refetch()
    } catch (e) { toast(errorMessage(e), 'error') } finally { setBusy(false) }
  }
  const remove = async () => { try { await api.delete(url); toast(String(t('pay.pricing.removed'))); setF(null); res.refetch() } catch (e) { toast(errorMessage(e), 'error') } }

  return (
    <div className="grid gap-5 lg:grid-cols-[1fr_20rem]">
      <Card className="space-y-5">
        <div><h3 className="font-bold text-navy-900">{t('pay.pricing.rules')}</h3><p className="text-xs text-slate-500">{t('pay.pricing.rulesHint')}</p></div>
        {f.rules.map((r: any, i: number) => (
          <div key={i} className="grid items-end gap-3 rounded-xl bg-ivory p-3 sm:grid-cols-[1.6fr_8rem_1fr_auto]">
            <Field label={t('pay.pricing.cat')}><select className="input" value={r.category} onChange={(e) => rule(i, 'category', e.target.value)}>{d.categories.map((c) => <option key={c} value={c}>{t(`pay.pricing.categories.${c}`)}</option>)}</select></Field>
            <Field label={t('pay.pricing.price')}><input type="number" min={0} step="0.5" className="input" value={r.price} onChange={(e) => rule(i, 'price', e.target.value)} /></Field>
            <Field label={t('pay.pricing.label')}><input className="input" value={r.label_en ?? ''} onChange={(e) => rule(i, 'label_en', e.target.value)} /></Field>
            <Button variant="ghost" aria-label="delete" onClick={() => set('rules', f.rules.filter((_: any, j: number) => j !== i))}><Trash2 className="size-4" /></Button>
          </div>
        ))}
        <Button variant="outline" icon={<Plus className="size-4" />} onClick={() => set('rules', [...f.rules, { category: 'any', price: 0 }])}>{t('pay.pricing.add')}</Button>
        <div className="grid gap-4 sm:grid-cols-2"><Field label={t('pay.pricing.defaultPrice')}><input type="number" min={0} step="0.5" className="input" value={f.default_price} onChange={(e) => set('default_price', e.target.value)} /></Field><Field label={t('pay.pricing.vat')}><input type="number" min={0} max={100} step="0.5" className="input" value={f.vat_rate} onChange={(e) => set('vat_rate', e.target.value)} /></Field></div>
        <div><h3 className="mb-2 font-bold text-navy-900">{t('pay.pricing.refundPolicy')}</h3>
          <div className="grid gap-4 sm:grid-cols-3"><Field label={t('pay.pricing.fullDays')}><input type="number" min={0} className="input" value={f.refund_policy.full_days} onChange={(e) => policy('full_days', Number(e.target.value))} /></Field><Field label={t('pay.pricing.partialDays')}><input type="number" min={0} className="input" value={f.refund_policy.partial_days} onChange={(e) => policy('partial_days', Number(e.target.value))} /></Field><Field label={t('pay.pricing.partialPct')}><input type="number" min={0} max={100} className="input" value={f.refund_policy.partial_percent} onChange={(e) => policy('partial_percent', Number(e.target.value))} /></Field></div></div>
        <div className="flex justify-between">{f.id ? <Button variant="ghost" onClick={remove}>{t('pay.pricing.remove')}</Button> : <span />}<Button variant="gold" loading={busy} onClick={save}>{t('pay.common.save')}</Button></div>
      </Card>
      <Card className="h-fit space-y-2">
        <h3 className="font-bold text-navy-900">{t('pay.pricing.preview')}</h3>
        {(f.preview ?? []).length === 0 && <p className="text-xs text-slate-400">{t('pay.pricing.preview')}…</p>}
        {(f.preview ?? []).map((p: any) => <div key={p.category} className="flex items-center justify-between rounded-lg bg-ivory px-3 py-2 text-sm"><span>{t(`pay.pricing.categories.${p.category}`)}</span>{p.free ? <Badge color="green">{t('pay.pricing.free')}</Badge> : <b>{fmt.number(p.price_with_vat, 2)} {t('pay.price.currency')}</b>}</div>)}
      </Card>
    </div>
  )
}
