import { Play, Plus, Trash2 } from 'lucide-react'
import { useState } from 'react'
import { useTranslation } from 'react-i18next'
import { Badge, Button, Card, Empty, Field, Modal, Spinner } from '@/components/ui'
import { useGet } from '@/hooks/useApi'
import { api, errorMessage } from '@/lib/api'
import { toast } from '@/lib/toast'

type Rule = { id: string; name_ar: string; name_en: string; trigger: string; conditions: Record<string, unknown> | null; action: { skill_ids?: string[]; required_level?: number } | null; is_active: boolean }
const TRIGGERS = ['new_hire', 'appraisal', 'observation', 'specialisation', 'stage']
const blank = { name_ar: '', name_en: '', trigger: 'new_hire', months: 6, years: String(new Date().getFullYear() - 1), ratings: 'weak,acceptable', below: 3, specs: '', skill_id: '', level: 3 }

/** Rules that turn new hires, appraisals, observations and specialisation into explained needs; they also run every night. */
export default function RulesTab() {
  const { t, i18n } = useTranslation()
  const ar = i18n.language === 'ar'
  const rules = useGet<{ data: Rule[] }>('/admin/needs-rules', undefined, { staleTime: 0 })
  const skills = useGet<{ data: { id: string; name_ar: string; name_en: string }[] }>('/admin/competencies')
  const [open, setOpen] = useState(false)
  const [f, setF] = useState(blank)
  const csv = (s: string) => s.split(',').map((x) => x.trim()).filter(Boolean)
  const save = async () => {
    const conditions = f.trigger === 'new_hire' ? { months: f.months } : f.trigger === 'appraisal' ? { years: csv(f.years).map(Number), ratings: csv(f.ratings) } : f.trigger === 'observation' ? { below_level: f.below } : { specializations: csv(f.specs) }
    try { await api.post('/admin/needs-rules', { name_ar: f.name_ar, name_en: f.name_en, trigger: f.trigger, conditions, action: { skill_ids: [f.skill_id], required_level: f.level } }); toast(t('needsHub.cycle.saved')); setOpen(false); setF(blank); await rules.refetch() } catch (e) { toast(errorMessage(e), 'error') }
  }
  const skillName = (id?: string) => { const s = skills.data?.data.find((x) => x.id === id); return s ? (ar ? s.name_ar : s.name_en) : '' }
  if (rules.isLoading || !rules.data) return <Spinner />
  return (
    <div className="space-y-4">
      <div className="flex justify-end gap-2">
        <Button variant="outline" icon={<Play className="size-4" />} onClick={() => void api.post('/admin/needs-rules/run').then(({ data }) => toast(t('needsHub.rules.ran', { n: data.data.created }))).catch((e) => toast(errorMessage(e), 'error'))}>{t('needsHub.rules.run')}</Button>
        <Button variant="gold" icon={<Plus className="size-4" />} onClick={() => setOpen(true)}>{t('needsHub.rules.new')}</Button>
      </div>
      <Card padded={false}>{rules.data.data.length === 0 ? <Empty text={t('needsHub.rules.empty')} /> : (
        <ul className="divide-y divide-navy-50">{rules.data.data.map((r) => (
          <li key={r.id} className="flex flex-wrap items-center gap-3 px-5 py-4"><div className="min-w-0 flex-1"><div className="font-bold text-navy-900">{ar ? r.name_ar : r.name_en}</div><div className="text-xs text-slate-500">{skillName(r.action?.skill_ids?.[0])} · {t('needsHub.rules.level')} {r.action?.required_level}</div></div>
            <Badge color="navy">{t(`needsHub.rules.triggers.${r.trigger}`)}</Badge>
            <label className="flex items-center gap-1.5 text-xs font-semibold text-slate-600"><input type="checkbox" checked={r.is_active} onChange={(e) => void api.put(`/admin/needs-rules/${r.id}`, { is_active: e.target.checked }).then(() => rules.refetch())} />{t('needsHub.rules.active')}</label>
            <button type="button" aria-label="delete" onClick={() => { if (window.confirm(ar ? r.name_ar : r.name_en)) void api.delete(`/admin/needs-rules/${r.id}`).then(() => rules.refetch()) }} className="grid size-9 place-items-center rounded-xl text-slate-400 hover:bg-red-50 hover:text-danger"><Trash2 className="size-4" /></button></li>))}</ul>
      )}</Card>
      <Modal open={open} onClose={() => setOpen(false)} title={t('needsHub.rules.new')} wide>
        <div className="space-y-4">
          <div className="grid gap-4 sm:grid-cols-2"><Field label={t('needsHub.rules.nameAr')}><input className="input" value={f.name_ar} onChange={(e) => setF({ ...f, name_ar: e.target.value })} /></Field><Field label={t('needsHub.rules.nameEn')}><input className="input" dir="ltr" value={f.name_en} onChange={(e) => setF({ ...f, name_en: e.target.value })} /></Field></div>
          <Field label={t('needsHub.rules.trigger')}><select className="input" value={f.trigger} onChange={(e) => setF({ ...f, trigger: e.target.value })}>{TRIGGERS.map((x) => <option key={x} value={x}>{t(`needsHub.rules.triggers.${x}`)}</option>)}</select></Field>
          {f.trigger === 'new_hire' && <Field label={t('needsHub.rules.months')}><input type="number" min={1} className="input" value={f.months} onChange={(e) => setF({ ...f, months: Number(e.target.value) })} /></Field>}
          {f.trigger === 'appraisal' && <div className="grid gap-4 sm:grid-cols-2"><Field label={t('needsHub.rules.years')}><input className="input" dir="ltr" value={f.years} onChange={(e) => setF({ ...f, years: e.target.value })} /></Field><Field label={t('needsHub.rules.ratings')}><input className="input" dir="ltr" value={f.ratings} onChange={(e) => setF({ ...f, ratings: e.target.value })} /></Field></div>}
          {f.trigger === 'observation' && <Field label={t('needsHub.rules.below')}><input type="number" min={1} max={5} className="input" value={f.below} onChange={(e) => setF({ ...f, below: Number(e.target.value) })} /></Field>}
          {(f.trigger === 'specialisation' || f.trigger === 'stage') && <Field label={t('needsHub.rules.specs')}><input className="input" value={f.specs} onChange={(e) => setF({ ...f, specs: e.target.value })} /></Field>}
          <div className="grid gap-4 sm:grid-cols-2"><Field label={t('needsHub.rules.skill')}><select className="input" value={f.skill_id} onChange={(e) => setF({ ...f, skill_id: e.target.value })}><option value="" />{skills.data?.data.map((s) => <option key={s.id} value={s.id}>{ar ? s.name_ar : s.name_en}</option>)}</select></Field><Field label={t('needsHub.rules.level')}><input type="number" min={1} max={5} className="input" value={f.level} onChange={(e) => setF({ ...f, level: Number(e.target.value) })} /></Field></div>
          <Button variant="gold" disabled={!f.name_ar.trim() || !f.name_en.trim() || !f.skill_id} onClick={() => void save()}>{t('needsHub.rules.save')}</Button>
        </div>
      </Modal>
    </div>
  )
}
