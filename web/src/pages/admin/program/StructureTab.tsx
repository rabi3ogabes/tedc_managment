import { Plus, Trash2 } from 'lucide-react'
import { useState } from 'react'
import { useTranslation } from 'react-i18next'
import { Badge, Button, Card, Empty, Field, Modal, Spinner } from '@/components/ui'
import { useGet } from '@/hooks/useApi'
import { api, errorMessage } from '@/lib/api'
import { useAuth } from '@/lib/auth'
import { fmt } from '@/lib/format'
import { toast } from '@/lib/toast'
import type { Program } from '@/lib/types'

type Node = { id: string; code: string; kind: string; title: string; status: string; total_hours: number; rollup: { groups: number; completed: number; seats_taken: number; capacity: number; hours: number; completion_percent: number }; children?: Node[] }
type Unit = { title_ar: string; title_en: string; hours: number; objectives: string }

/** Program → Structure: the main program with its sub-programs and roll-ups, and its units with objectives and hours. */
export default function StructureTab({ program }: { program: Program }) {
  const { t } = useTranslation()
  const { can } = useAuth()
  const manage = can('programs.manage')
  const tree = useGet<{ data: Node }>(`/admin/programs/${program.id}/tree`, undefined, { staleTime: 0 })
  const [units, setUnits] = useState<Unit[]>((program.units ?? []).map((u) => ({ title_ar: u.title_ar, title_en: u.title_en, hours: Number(u.hours ?? 0), objectives: (u.objectives ?? []).join('\n') })))
  const [adding, setAdding] = useState(false)
  const [sub, setSub] = useState({ title_ar: '', title_en: '', total_hours: 4 })
  const [busy, setBusy] = useState(false)

  const createSub = async () => {
    setBusy(true)
    try { await api.post(`/admin/programs/${program.id}/sub-programs`, sub); toast(t('structure.saved')); setAdding(false); setSub({ title_ar: '', title_en: '', total_hours: 4 }); await tree.refetch() } catch (e) { toast(errorMessage(e), 'error') } finally { setBusy(false) }
  }
  const saveUnits = async () => {
    setBusy(true)
    try { await api.put(`/admin/programs/${program.id}/units`, { units: units.map((u) => ({ ...u, hours: u.hours || null, objectives: u.objectives.split('\n').map((x) => x.trim()).filter(Boolean) })) }); toast(t('structure.saved')) } catch (e) { toast(errorMessage(e), 'error') } finally { setBusy(false) }
  }
  const patch = (i: number, p: Partial<Unit>) => setUnits(units.map((u, idx) => (idx === i ? { ...u, ...p } : u)))

  if (tree.isLoading || !tree.data) return <Spinner />
  const root = tree.data.data
  return (
    <div className="space-y-6">
      <Card className="space-y-3">
        <div className="flex items-center justify-between"><div><h3 className="font-bold text-navy-900">{t('structure.subs')}</h3><p className="text-sm text-slate-500">{t('structure.subHint')}</p></div>{manage && program.kind !== 'sub' && <Button variant="gold" icon={<Plus className="size-4" />} onClick={() => setAdding(true)}>{t('structure.newSub')}</Button>}</div>
        <p className="rounded-xl bg-ivory p-3 text-sm text-slate-600">{t('structure.rollup', { groups: root.rollup.groups, completed: root.rollup.completed, seats: root.rollup.seats_taken, hours: fmt.number(root.rollup.hours, 1) })}</p>
        {(root.children ?? []).length === 0 ? <Empty text={t('structure.noSubs')} /> : (
          <ul className="space-y-2">{root.children!.map((c) => <li key={c.id} className="flex flex-wrap items-center gap-3 rounded-xl border border-navy-100 p-3"><Badge color="navy">{t('structure.sub')}</Badge><span className="font-mono text-xs text-slate-400" dir="ltr">{c.code}</span><span className="min-w-0 flex-1 truncate font-semibold text-navy-900">{c.title}</span><span className="text-xs text-slate-500">{c.rollup.groups} · {fmt.number(c.total_hours, 1)}h</span></li>)}</ul>
        )}
      </Card>

      <Card className="space-y-4">
        <div><h3 className="font-bold text-navy-900">{t('structure.units')}</h3><p className="text-sm text-slate-500">{t('structure.unitsHint')}</p></div>
        {units.map((u, i) => (
          <div key={i} className="space-y-3 rounded-xl border border-navy-100 p-4">
            <div className="grid gap-3 sm:grid-cols-[1fr_1fr_7rem_auto] sm:items-end">
              <Field label={t('structure.titleAr')}><input className="input" value={u.title_ar} disabled={!manage} onChange={(e) => patch(i, { title_ar: e.target.value })} /></Field>
              <Field label={t('structure.titleEn')}><input className="input" dir="ltr" value={u.title_en} disabled={!manage} onChange={(e) => patch(i, { title_en: e.target.value })} /></Field>
              <Field label={t('structure.hours')}><input type="number" min={0} className="input" value={u.hours} disabled={!manage} onChange={(e) => patch(i, { hours: Number(e.target.value) })} /></Field>
              {manage && <button type="button" aria-label={t('structure.remove')} onClick={() => setUnits(units.filter((_, idx) => idx !== i))} className="grid size-10 place-items-center rounded-xl text-slate-400 hover:bg-red-50 hover:text-danger"><Trash2 className="size-4" /></button>}
            </div>
            <Field label={t('structure.objectives')}><textarea className="input min-h-16" value={u.objectives} disabled={!manage} onChange={(e) => patch(i, { objectives: e.target.value })} /></Field>
          </div>
        ))}
        {manage && <div className="flex gap-2"><Button variant="outline" icon={<Plus className="size-4" />} onClick={() => setUnits([...units, { title_ar: '', title_en: '', hours: 0, objectives: '' }])}>{t('structure.addUnit')}</Button><Button variant="gold" loading={busy} disabled={units.some((u) => !u.title_ar.trim() || !u.title_en.trim())} onClick={() => void saveUnits()}>{t('structure.save')}</Button></div>}
      </Card>

      <Modal open={adding} onClose={() => setAdding(false)} title={t('structure.newSub')}>
        <div className="space-y-4">
          <Field label={t('structure.titleAr')}><input className="input" value={sub.title_ar} onChange={(e) => setSub({ ...sub, title_ar: e.target.value })} /></Field>
          <Field label={t('structure.titleEn')}><input className="input" dir="ltr" value={sub.title_en} onChange={(e) => setSub({ ...sub, title_en: e.target.value })} /></Field>
          <Field label={t('structure.hours')}><input type="number" min={0} className="input" value={sub.total_hours} onChange={(e) => setSub({ ...sub, total_hours: Number(e.target.value) })} /></Field>
          <Button variant="gold" loading={busy} disabled={!sub.title_ar.trim() || !sub.title_en.trim()} onClick={() => void createSub()}>{t('structure.create')}</Button>
        </div>
      </Modal>
    </div>
  )
}
