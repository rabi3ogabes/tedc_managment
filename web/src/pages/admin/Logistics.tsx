import { Plus } from 'lucide-react'
import { useState } from 'react'
import { useTranslation } from 'react-i18next'
import { Badge, Button, Card, Field, Modal, PageHeader, Spinner } from '@/components/ui'
import { useGet } from '@/hooks/useApi'
import { api, errorMessage } from '@/lib/api'
import { useAuth } from '@/lib/auth'
import { fmt } from '@/lib/format'
import { toast } from '@/lib/toast'

type Req = { id: string; session: string | null; items: { type: string; qty?: number; note?: string }[]; notes: string | null; needed_by: string | null; status: string; requester: string | null; assignee: string | null; comment: string | null; overdue: boolean }
const COLS = ['new', 'in_progress', 'done']

/** The logistics team's board: requests from supervisors flow New → In progress → Done, with overdue badges. */
export default function Logistics() {
  const { t } = useTranslation()
  const { can } = useAuth()
  const list = useGet<{ data: Req[] }>('/admin/logistics-requests', undefined, { staleTime: 0 })
  const [open, setOpen] = useState(false)
  const [f, setF] = useState({ type: 'catering', qty: 10, needed_by: '', notes: '' })
  const [items, setItems] = useState<{ type: string; qty: number }[]>([])
  const manage = can('logistics.manage')
  const send = async () => {
    try { await api.post('/admin/logistics-requests', { items: items.length ? items : [{ type: f.type, qty: f.qty }], needed_by: f.needed_by || undefined, notes: f.notes || undefined }); toast(t('ops.logistics.sent')); setOpen(false); setItems([]); await list.refetch() } catch (e) { toast(errorMessage(e), 'error') }
  }
  const move = async (r: Req, status: string) => { try { await api.put(`/admin/logistics-requests/${r.id}`, { status }); await list.refetch() } catch (e) { toast(errorMessage(e), 'error') } }
  if (list.isLoading || !list.data) return <Spinner />
  return (
    <div className="space-y-6 pb-6">
      <PageHeader title={t('ops.logistics.title')} actions={<Button variant="gold" icon={<Plus className="size-4" />} onClick={() => setOpen(true)}>{t('ops.logistics.new')}</Button>} />
      <div className="grid gap-4 lg:grid-cols-3">{COLS.map((c) => (
        <section key={c} className="space-y-2 rounded-2xl bg-ivory p-3" aria-label={t(`ops.logistics.columns.${c}`)}>
          <header className="flex items-center justify-between"><Badge color={c === 'done' ? 'green' : c === 'in_progress' ? 'gold' : 'navy'}>{t(`ops.logistics.columns.${c}`)}</Badge><span className="text-xs font-bold text-slate-400">{list.data.data.filter((r) => r.status === c).length}</span></header>
          {list.data.data.filter((r) => r.status === c).map((r) => (
            <Card key={r.id} className="space-y-2 !p-3">
              <div className="flex flex-wrap gap-1">{r.items.map((i, k) => <Badge key={k} color="slate">{t(`ops.logistics.items.${i.type}`)}{i.qty ? ` ×${i.qty}` : ''}</Badge>)}{r.overdue && <Badge color="red">{t('ops.logistics.overdue')}</Badge>}</div>
              <p className="text-xs text-slate-600">{r.requester}{r.session && ` · ${r.session}`}</p>
              {r.needed_by && <p className="text-xs text-slate-500">{t('ops.logistics.neededBy')}: {fmt.dateTime(r.needed_by)}</p>}
              {r.notes && <p className="text-xs text-slate-500">{r.notes}</p>}
              {manage && c !== 'done' && <div className="flex gap-1.5">{c === 'new' && <Button size="sm" variant="outline" onClick={() => void move(r, 'in_progress')}>{t('ops.logistics.take')}</Button>}<Button size="sm" variant="gold" onClick={() => void move(r, 'done')}>{t('ops.logistics.finish')}</Button><Button size="sm" variant="ghost" onClick={() => void move(r, 'rejected')}>{t('ops.logistics.reject')}</Button></div>}
            </Card>))}
        </section>))}</div>
      <Modal open={open} onClose={() => setOpen(false)} title={t('ops.logistics.new')}>
        <div className="space-y-4">
          <div className="flex flex-wrap items-end gap-2"><Field label={t('ops.logistics.items.other')}><select className="input" value={f.type} onChange={(e) => setF({ ...f, type: e.target.value })}>{['equipment', 'catering', 'printing', 'it', 'arrangement', 'other'].map((x) => <option key={x} value={x}>{t(`ops.logistics.items.${x}`)}</option>)}</select></Field><Field label={t('ops.logistics.qty')}><input type="number" min={1} className="input w-24" value={f.qty} onChange={(e) => setF({ ...f, qty: Number(e.target.value) })} /></Field><Button variant="outline" onClick={() => setItems([...items, { type: f.type, qty: f.qty }])}>{t('ops.logistics.add')}</Button></div>
          <div className="flex flex-wrap gap-1.5">{items.map((i, k) => <button key={k} type="button" onClick={() => setItems(items.filter((_, j) => j !== k))} className="rounded-full bg-navy-900 px-3 py-1 text-xs font-bold text-white">{t(`ops.logistics.items.${i.type}`)} ×{i.qty} ×</button>)}</div>
          <Field label={t('ops.logistics.neededBy')}><input type="datetime-local" className="input" value={f.needed_by} onChange={(e) => setF({ ...f, needed_by: e.target.value })} /></Field>
          <Field label={t('ops.logistics.notes')}><textarea className="input min-h-20" value={f.notes} onChange={(e) => setF({ ...f, notes: e.target.value })} /></Field>
          <Button variant="gold" onClick={() => void send()}>{t('ops.logistics.send')}</Button>
        </div>
      </Modal>
    </div>
  )
}
