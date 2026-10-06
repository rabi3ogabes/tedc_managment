/* eslint-disable @typescript-eslint/no-explicit-any */
import { Plus, Sparkles, Trash2 } from 'lucide-react'
import { useEffect, useState } from 'react'
import { useTranslation } from 'react-i18next'
import { Link, useParams } from 'react-router-dom'
import { Button, Card, Empty, Field, PageHeader, Spinner } from '@/components/ui'
import { useGet } from '@/hooks/useApi'
import { api, errorMessage } from '@/lib/api'
import { toast } from '@/lib/toast'

/** The trainer's rules for adaptive learning: skip a module when a competency is mastered, add a remedial lesson when it is weak. */
export default function AdaptiveRules() {
  const { t, i18n } = useTranslation()
  const { id } = useParams()
  const en = i18n.language === 'en'
  const rules = useGet<{ data: any[] }>(`/admin/programs/${id}/adaptive-rules`, undefined, { staleTime: 0 })
  const course = useGet<{ data: any }>(`/admin/programs/${id}/course`, undefined, { staleTime: 0 })
  const skills = useGet<{ data: any[] }>('/admin/adaptive/skills', undefined, { staleTime: 60_000 })
  const [rows, setRows] = useState<any[]>([])
  const [busy, setBusy] = useState(false)
  const [gen, setGen] = useState('')
  useEffect(() => { if (rules.data) setRows(rules.data.data.map((r) => ({ skill_id: r.skill_id, action: r.action, module_id: r.module_id ?? '', lesson_id: r.lesson_id ?? '', skip_at: r.skip_at, remedial_below: r.remedial_below, is_active: r.is_active }))) }, [rules.data])
  if (rules.isLoading || course.isLoading) return <Spinner />
  const modules: any[] = course.data?.data.modules ?? []
  const lessons = modules.flatMap((m) => m.lessons.map((l: any) => ({ ...l, module: m })))
  const name = (x: any) => (en ? x.title_en || x.title_ar : x.title_ar)
  const set = (i: number, k: string, v: any) => setRows(rows.map((r, j) => (j === i ? { ...r, [k]: v } : r)))

  const save = async () => {
    setBusy(true)
    try {
      await api.put(`/admin/programs/${id}/adaptive-rules`, { rules: rows.map((r) => ({ skill_id: r.skill_id, action: r.action, module_id: r.action === 'skip_module' ? r.module_id : null, lesson_id: r.action === 'add_lesson' ? r.lesson_id : null, skip_at: Number(r.skip_at), remedial_below: Number(r.remedial_below), is_active: r.is_active })) })
      toast(String(t('aix.adaptive.saved'))); rules.refetch()
    } catch (e) { toast(errorMessage(e), 'error') } finally { setBusy(false) }
  }
  const generate = async () => {
    if (!gen) return
    try { await api.post(`/admin/programs/${id}/adaptive/remedial`, { skill_id: gen }); toast(String(t('aix.adaptive.generated'))); course.refetch() } catch (e) { toast(errorMessage(e), 'error') }
  }

  return (
    <>
      <PageHeader title={t('aix.adaptive.title')} subtitle={t('aix.adaptive.hint')} actions={<Link to={`/admin/programs/${id}`} className="text-sm font-semibold text-link">‹</Link>} />
      <Card className="space-y-4">
        {rows.length === 0 && <Empty text={String(t('aix.adaptive.none'))} />}
        {rows.map((r, i) => (
          <div key={i} className="grid items-end gap-3 rounded-xl bg-ivory p-3 md:grid-cols-[1.4fr_1.2fr_1.4fr_7rem_auto]">
            <Field label={t('aix.adaptive.skill')}><select className="input" value={r.skill_id} onChange={(e) => set(i, 'skill_id', e.target.value)}><option value="" />{skills.data?.data.map((s) => <option key={s.id} value={s.id}>{en ? s.name_en : s.name_ar}</option>)}</select></Field>
            <Field label={t('aix.adaptive.action')}><select className="input" value={r.action} onChange={(e) => set(i, 'action', e.target.value)}><option value="skip_module">{t('aix.adaptive.skip')}</option><option value="add_lesson">{t('aix.adaptive.addLesson')}</option></select></Field>
            {r.action === 'skip_module'
              ? <Field label={t('aix.adaptive.module')}><select className="input" value={r.module_id} onChange={(e) => set(i, 'module_id', e.target.value)}><option value="" />{modules.map((m) => <option key={m.id} value={m.id}>{name(m)}</option>)}</select></Field>
              : <Field label={t('aix.adaptive.lesson')}><select className="input" value={r.lesson_id} onChange={(e) => set(i, 'lesson_id', e.target.value)}><option value="" />{lessons.map((l) => <option key={l.id} value={l.id}>{name(l)}{l.status !== 'published' ? ' (draft)' : ''}</option>)}</select></Field>}
            {r.action === 'skip_module'
              ? <Field label={t('aix.adaptive.skipAt')}><input type="number" step="0.05" min={0.5} max={1} className="input" value={r.skip_at} onChange={(e) => set(i, 'skip_at', e.target.value)} /></Field>
              : <Field label={t('aix.adaptive.below')}><input type="number" step="0.05" min={0} max={0.9} className="input" value={r.remedial_below} onChange={(e) => set(i, 'remedial_below', e.target.value)} /></Field>}
            <Button variant="ghost" aria-label="delete" onClick={() => setRows(rows.filter((_, j) => j !== i))}><Trash2 className="size-4" /></Button>
          </div>
        ))}
        <div className="flex justify-between">
          <Button variant="outline" icon={<Plus className="size-4" />} onClick={() => setRows([...rows, { skill_id: '', action: 'skip_module', module_id: '', lesson_id: '', skip_at: 0.85, remedial_below: 0.5, is_active: true }])}>{t('aix.adaptive.add')}</Button>
          <Button variant="gold" loading={busy} disabled={rows.some((r) => !r.skill_id || (r.action === 'skip_module' ? !r.module_id : !r.lesson_id))} onClick={save}>{t('aix.adaptive.save')}</Button>
        </div>
      </Card>
      <Card className="mt-5 space-y-3">
        <h3 className="flex items-center gap-2 font-bold text-navy-900"><Sparkles className="size-5 text-gold-600" />{t('aix.adaptive.generate')}</h3>
        <p className="text-xs text-slate-500">{t('aix.adaptive.draftNote')}</p>
        <div className="flex flex-wrap gap-2"><select className="input max-w-sm" value={gen} onChange={(e) => setGen(e.target.value)}><option value="" />{skills.data?.data.map((s) => <option key={s.id} value={s.id}>{en ? s.name_en : s.name_ar}</option>)}</select><Button variant="outline" disabled={!gen} onClick={generate}>{t('aix.adaptive.generate')}</Button></div>
      </Card>
    </>
  )
}
