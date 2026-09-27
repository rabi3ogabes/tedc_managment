import { Plus, Sparkles, Trash2 } from 'lucide-react'
import { useEffect, useState } from 'react'
import { useTranslation } from 'react-i18next'
import { Button, Card, CardTitle, EligibilityPanel, Spinner } from '@/components/ui'
import { useGet } from '@/hooks/useApi'
import { api, errorMessage } from '@/lib/api'
import { useAuth } from '@/lib/auth'
import type { Eligibility, EligibilityRule, Employee, Program } from '@/lib/types'

const FIELD_OPERATORS: Record<string, string[]> = {
  job_title: ['eq', 'neq', 'in', 'not_in'], job_category: ['eq', 'neq', 'in', 'not_in'], department: ['eq', 'neq', 'in', 'not_in'],
  school_type: ['eq', 'neq', 'in', 'not_in'], school_stage: ['eq', 'neq', 'in', 'not_in'], education_stage: ['eq', 'neq', 'in', 'not_in'],
  region: ['eq', 'neq', 'in', 'not_in'], qualification: ['eq', 'neq', 'in', 'not_in'],
  experience_years: ['gt', 'gte', 'lt', 'lte', 'eq'], completed_program: ['completed', 'not_completed'], skill_level: ['has_skill', 'lacks_skill'],
}

type Lookups = { job_titles: { code: string; name_ar: string; name_en: string }[]; skills: { code: string; name_ar: string; name_en: string }[]; school_types: string[]; stages: string[]; regions: string[] }

/** Visual builder for the Smart Eligibility Engine rules of a program. */
export default function RulesTab({ program }: { program: Program }) {
  const { t, i18n } = useTranslation()
  const { can } = useAuth()
  const { data, isLoading } = useGet<{ data: EligibilityRule[] }>(`/admin/programs/${program.id}/eligibility-rules`)
  const lookups = useGet<{ data: Lookups }>('/admin/lookups')
  const [rules, setRules] = useState<EligibilityRule[]>([])
  const [saving, setSaving] = useState(false)
  const [message, setMessage] = useState<string | null>(null)
  const [q, setQ] = useState('')
  const [preview, setPreview] = useState<{ employee: Employee; result: Eligibility } | null>(null)
  const employees = useGet<{ data: Employee[] }>(q.length > 1 ? '/admin/employees' : null, { q, per_page: 6 })
  const nm = (o: { name_ar: string; name_en: string }) => (i18n.language === 'ar' ? o.name_ar : o.name_en)

  useEffect(() => { if (data) setRules(data.data) }, [data])
  if (isLoading || lookups.isLoading) return <Spinner />
  const L = lookups.data!.data

  const options = (field: string): { value: string; label: string }[] | null => {
    switch (field) {
      case 'job_title': return L.job_titles.map((j) => ({ value: j.code, label: nm(j) }))
      case 'job_category': return ['teaching', 'leadership', 'administrative', 'support'].map((v) => ({ value: v, label: v }))
      case 'school_type': return L.school_types.map((v) => ({ value: v, label: t(`schoolTypes.${v}`) }))
      case 'school_stage': case 'education_stage': return L.stages.map((v) => ({ value: v, label: t(`stages.${v}`) }))
      case 'region': return L.regions.map((v) => ({ value: v, label: t(`regions.${v}`) }))
      case 'qualification': return ['diploma', 'bachelor', 'master', 'phd'].map((v) => ({ value: v, label: v }))
      default: return null
    }
  }

  const update = (i: number, patch: Partial<EligibilityRule>) => setRules((rs) => rs.map((r, j) => (j === i ? { ...r, ...patch } : r)))

  const save = async () => {
    setSaving(true)
    setMessage(null)
    try {
      await api.put(`/admin/programs/${program.id}/eligibility-rules`, { rules })
      setMessage(t('common.saved'))
      if (preview) runPreview(preview.employee)
    } catch (e) {
      setMessage(errorMessage(e))
    } finally {
      setSaving(false)
    }
  }

  const runPreview = async (employee: Employee) => {
    const { data: res } = await api.get(`/admin/programs/${program.id}/eligibility/${employee.id}`)
    setPreview({ employee, result: res.data })
    setQ('')
  }

  return (
    <div className="grid gap-6 xl:grid-cols-3">
      <Card className="xl:col-span-2">
        <CardTitle subtitle={t('admin.rules.help')} action={can('programs.manage') && <Button size="sm" variant="outline" icon={<Plus className="size-4" />} onClick={() => setRules([...rules, { field: 'job_title', operator: 'eq', value: '', is_mandatory: true }])}>{t('admin.programs.addRule')}</Button>}>
          <span className="flex items-center gap-2"><Sparkles className="size-5 text-gold-600" />{t('admin.rules.title')}</span>
        </CardTitle>
        <div className="space-y-3">
          {rules.map((rule, i) => {
            const ops = FIELD_OPERATORS[rule.field] ?? ['eq']
            const opts = options(rule.field)
            const multi = ['in', 'not_in', 'completed', 'not_completed'].includes(rule.operator)
            const skillValue = (rule.value ?? {}) as { skill?: string; min_level?: number }
            return (
              <div key={i} className="rounded-2xl border border-navy-100 bg-ivory/60 p-4">
                <div className="flex flex-wrap items-center gap-2">
                  {i > 0 && <span className="rounded-lg bg-navy-900 px-2 py-1 text-xs font-bold text-gold-300">{i18n.language === 'ar' ? 'و' : 'AND'}</span>}
                  <select className="input w-auto" value={rule.field} onChange={(e) => update(i, { field: e.target.value, operator: FIELD_OPERATORS[e.target.value][0], value: e.target.value === 'skill_level' ? { skill: L.skills[0]?.code, min_level: 3 } : '' })}>
                    {Object.keys(FIELD_OPERATORS).map((f) => <option key={f} value={f}>{t(`admin.rules.fields.${f}`)}</option>)}
                  </select>
                  <select className="input w-auto" value={rule.operator} onChange={(e) => update(i, { operator: e.target.value })}>
                    {ops.map((o) => <option key={o} value={o}>{t(`admin.rules.operators.${o}`)}</option>)}
                  </select>
                  <div className="min-w-48 flex-1">
                    {rule.field === 'skill_level' ? (
                      <div className="flex gap-2">
                        <select className="input" value={skillValue.skill} onChange={(e) => update(i, { value: { ...skillValue, skill: e.target.value } })}>{L.skills.map((s) => <option key={s.code} value={s.code}>{nm(s)}</option>)}</select>
                        <select className="input w-20" value={skillValue.min_level} onChange={(e) => update(i, { value: { ...skillValue, min_level: Number(e.target.value) } })}>{[1, 2, 3, 4, 5].map((n) => <option key={n}>{n}</option>)}</select>
                      </div>
                    ) : rule.field === 'experience_years' ? (
                      <input className="input" type="number" step="0.5" value={String(rule.value ?? '')} onChange={(e) => update(i, { value: Number(e.target.value) })} />
                    ) : opts ? (
                      <select className="input" multiple={multi} value={multi ? ((rule.value as string[]) ?? []) : String(rule.value ?? '')}
                        onChange={(e) => update(i, { value: multi ? Array.from(e.target.selectedOptions).map((o) => o.value) : e.target.value })}>
                        {!multi && <option value="">—</option>}
                        {opts.map((o) => <option key={o.value} value={o.value}>{o.label}</option>)}
                      </select>
                    ) : (
                      <input className="input" dir="ltr" placeholder="CODE-1, CODE-2" value={Array.isArray(rule.value) ? (rule.value as string[]).join(', ') : String(rule.value ?? '')}
                        onChange={(e) => update(i, { value: multi ? e.target.value.split(',').map((s) => s.trim()).filter(Boolean) : e.target.value })} />
                    )}
                  </div>
                  <label className="flex items-center gap-1.5 text-xs font-semibold text-navy-800"><input type="checkbox" className="accent-gold-600" checked={rule.is_mandatory} onChange={(e) => update(i, { is_mandatory: e.target.checked })} />{t('admin.rules.mandatory')}</label>
                  <button className="p-1 text-slate-400 hover:text-danger" onClick={() => setRules(rules.filter((_, j) => j !== i))}><Trash2 className="size-4" /></button>
                </div>
                <input className="input mt-2 text-xs" placeholder={t('admin.rules.messageAr')} value={rule.message_ar ?? ''} onChange={(e) => update(i, { message_ar: e.target.value })} />
              </div>
            )
          })}
        </div>
        {can('programs.manage') && <div className="mt-5 flex items-center gap-3"><Button variant="gold" loading={saving} onClick={save}>{t('common.save')}</Button>{message && <span className="text-sm text-slate-500">{message}</span>}</div>}
      </Card>

      <Card>
        <CardTitle>{t('admin.rules.preview')}</CardTitle>
        <input className="input" placeholder={t('admin.rules.previewPlaceholder')} value={q} onChange={(e) => setQ(e.target.value)} />
        {!!employees.data?.data.length && q.length > 1 && (
          <ul className="mt-2 divide-y divide-navy-100 rounded-xl border border-navy-100">
            {employees.data.data.map((e) => <li key={e.id}><button className="w-full p-2 text-start text-sm hover:bg-ivory" onClick={() => runPreview(e)}>{e.name} <span className="text-xs text-slate-400">· {e.job_title?.name}</span></button></li>)}
          </ul>
        )}
        {preview && <div className="mt-4"><p className="mb-2 text-sm font-bold text-navy-900">{preview.employee.name}</p><EligibilityPanel result={preview.result} /></div>}
      </Card>
    </div>
  )
}
