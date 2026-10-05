import { Download, Plus, Upload } from 'lucide-react'
import { useState } from 'react'
import { useTranslation } from 'react-i18next'
import { Badge, Button, Card, Empty, Field, Modal, Spinner } from '@/components/ui'
import { useGet } from '@/hooks/useApi'
import { api, downloadFile, errorMessage } from '@/lib/api'
import { useAuth } from '@/lib/auth'
import { toast } from '@/lib/toast'

type Comp = { id: string; code: string; name_ar: string; name_en: string; category: string; licence_relevant: boolean; domain: { name_ar: string; name_en: string } | null; descriptors: Record<string, { ar: string; en: string }> | null }
type Req = { skill_id: string; required_level: number; education_stage?: string | null; subject?: string | null; weight?: number }

/** The competency framework: catalogue with level descriptors, required levels per job, evidence weights, import/export. */
export default function CompetenciesTab() {
  const { t, i18n } = useTranslation()
  const ar = i18n.language === 'ar'
  const { can } = useAuth()
  const manage = can('competencies.manage')
  const comps = useGet<{ data: Comp[] }>('/admin/competencies', undefined, { staleTime: 0 })
  const domains = useGet<{ data: { id: string; code: string; name_ar: string; name_en: string }[] }>('/admin/competency-domains')
  const jobs = useGet<{ data: { job_titles: { id: string; name_ar: string; name_en: string }[] } }>('/admin/lookups')
  const weights = useGet<{ data: Record<string, number> }>('/admin/competencies/weights', undefined, { staleTime: 0 })
  const [open, setOpen] = useState(false)
  const [f, setF] = useState({ code: '', name_ar: '', name_en: '', category: 'pedagogy', domain_id: '', licence_relevant: false, l1: '', l4: '' })
  const [job, setJob] = useState('')
  const reqs = useGet<{ data: Req[] }>(job ? `/admin/job-titles/${job}/requirements` : null, undefined, { staleTime: 0 })
  const [draft, setDraft] = useState<Req[] | null>(null)
  const rows = draft ?? reqs.data?.data ?? []
  const jobList = jobs.data?.data.job_titles ?? []

  const create = async () => {
    const descriptors: Record<number, { ar: string; en: string }> = {}
    if (f.l1) descriptors[1] = { ar: f.l1, en: f.l1 }
    if (f.l4) descriptors[4] = { ar: f.l4, en: f.l4 }
    try { await api.post('/admin/competencies', { code: f.code, name_ar: f.name_ar, name_en: f.name_en, category: f.category, domain_id: f.domain_id || undefined, licence_relevant: f.licence_relevant, descriptors }); toast(t('needsHub.comp.saved')); setOpen(false); await comps.refetch() } catch (e) { toast(errorMessage(e), 'error') }
  }
  const upload = async (file: File) => {
    const body = new FormData(); body.append('file', file)
    try { const { data } = await api.post('/admin/competencies/import', body); toast(`${data.data.created} + ${data.data.updated} (${data.data.errors.length})`); await comps.refetch() } catch (e) { toast(errorMessage(e), 'error') }
  }
  const saveReqs = async () => { try { await api.put(`/admin/job-titles/${job}/requirements`, { requirements: rows }); toast(t('needsHub.comp.saved')); setDraft(null); await reqs.refetch() } catch (e) { toast(errorMessage(e), 'error') } }
  const saveWeights = async (k: string, v: number) => { try { await api.put('/admin/competencies/weights', { [k]: v }); await weights.refetch() } catch (e) { toast(errorMessage(e), 'error') } }

  if (comps.isLoading || !comps.data) return <Spinner />
  return (
    <div className="space-y-5">
      <div className="flex flex-wrap justify-end gap-2">
        <Button variant="outline" icon={<Download className="size-4" />} onClick={() => downloadFile('/admin/competencies/export?format=xlsx', 'competency-framework.xlsx')}>{t('needsHub.comp.export')}</Button>
        {manage && <label className="cursor-pointer"><span className="inline-flex items-center gap-2 rounded-xl border border-navy-100 bg-white px-4 py-2 text-sm font-bold text-navy-900"><Upload className="size-4" />{t('needsHub.comp.import')}</span><input type="file" accept=".csv,.xlsx" className="hidden" onChange={(e) => { const x = e.target.files?.[0]; if (x) void upload(x); e.target.value = '' }} /></label>}
        {manage && <Button variant="gold" icon={<Plus className="size-4" />} onClick={() => setOpen(true)}>{t('needsHub.comp.new')}</Button>}
      </div>
      <Card padded={false}>{comps.data.data.length === 0 ? <Empty text={t('needsHub.comp.empty')} /> : (
        <ul className="divide-y divide-navy-50">{comps.data.data.map((c) => <li key={c.id} className="flex flex-wrap items-center gap-3 px-5 py-3"><span className="font-mono text-xs text-slate-400" dir="ltr">{c.code}</span><span className="min-w-0 flex-1 font-semibold text-navy-900">{ar ? c.name_ar : c.name_en}</span>{c.domain && <Badge color="navy">{ar ? c.domain.name_ar : c.domain.name_en}</Badge>}{c.licence_relevant && <Badge color="gold">{t('needsHub.comp.licence')}</Badge>}</li>)}</ul>
      )}</Card>

      <Card className="space-y-4">
        <h3 className="font-bold text-navy-900">{t('needsHub.comp.jobReq')}</h3>
        <Field label={t('needsHub.comp.job')}><select className="input max-w-sm" value={job} onChange={(e) => { setJob(e.target.value); setDraft(null) }}><option value="" />{jobList.map((j) => <option key={j.id} value={j.id}>{ar ? j.name_ar : j.name_en}</option>)}</select></Field>
        {job && (
          <>
            {rows.map((r, i) => (
              <div key={i} className="flex flex-wrap items-center gap-2">
                <select className="input w-64" disabled={!manage} value={r.skill_id} onChange={(e) => setDraft(rows.map((x, j) => (j === i ? { ...x, skill_id: e.target.value } : x)))}>{comps.data!.data.map((c) => <option key={c.id} value={c.id}>{ar ? c.name_ar : c.name_en}</option>)}</select>
                <input type="number" min={1} max={5} className="input w-20" disabled={!manage} value={r.required_level} onChange={(e) => setDraft(rows.map((x, j) => (j === i ? { ...x, required_level: Number(e.target.value) } : x)))} />
                {manage && <button type="button" className="text-xs font-bold text-danger" onClick={() => setDraft(rows.filter((_, j) => j !== i))}>×</button>}
              </div>
            ))}
            {manage && <div className="flex gap-2"><Button variant="outline" onClick={() => setDraft([...rows, { skill_id: comps.data!.data[0]?.id ?? '', required_level: 3 }])}>{t('needsHub.comp.add')}</Button>{draft && <Button variant="gold" onClick={() => void saveReqs()}>{t('groups.save')}</Button>}</div>}
          </>
        )}
      </Card>

      {weights.data && (
        <Card className="space-y-3"><h3 className="font-bold text-navy-900">{t('needsHub.comp.weights')}</h3>
          <div className="grid gap-4 sm:grid-cols-5">{(['verified', 'manager', 'observation', 'self', 'licence'] as const).map((k) => <Field key={k} label={k === 'licence' ? t('needsHub.comp.licenceW') : t(`needsHub.comp.${k}`)}><input type="number" step="0.5" min={0} max={10} className="input" disabled={!manage} defaultValue={weights.data!.data[k]} onBlur={(e) => { const v = Number(e.target.value); if (v !== weights.data!.data[k]) void saveWeights(k, v) }} /></Field>)}</div></Card>
      )}

      <Modal open={open} onClose={() => setOpen(false)} title={t('needsHub.comp.new')} wide>
        <div className="space-y-4">
          <div className="grid gap-4 sm:grid-cols-3"><Field label={t('needsHub.comp.code')}><input className="input" dir="ltr" value={f.code} onChange={(e) => setF({ ...f, code: e.target.value })} /></Field><Field label={t('needsHub.rules.nameAr')}><input className="input" value={f.name_ar} onChange={(e) => setF({ ...f, name_ar: e.target.value })} /></Field><Field label={t('needsHub.rules.nameEn')}><input className="input" dir="ltr" value={f.name_en} onChange={(e) => setF({ ...f, name_en: e.target.value })} /></Field></div>
          <div className="grid gap-4 sm:grid-cols-2"><Field label={t('needsHub.comp.domain')}><select className="input" value={f.domain_id} onChange={(e) => setF({ ...f, domain_id: e.target.value })}><option value="" />{domains.data?.data.map((d) => <option key={d.id} value={d.id}>{ar ? d.name_ar : d.name_en}</option>)}</select></Field><label className="flex items-center gap-2 pt-7 text-sm font-semibold text-navy-900"><input type="checkbox" checked={f.licence_relevant} onChange={(e) => setF({ ...f, licence_relevant: e.target.checked })} />{t('needsHub.comp.licence')}</label></div>
          <div className="grid gap-4 sm:grid-cols-2"><Field label={`${t('needsHub.comp.descriptors')} 1`}><input className="input" value={f.l1} onChange={(e) => setF({ ...f, l1: e.target.value })} /></Field><Field label={`${t('needsHub.comp.descriptors')} 4`}><input className="input" value={f.l4} onChange={(e) => setF({ ...f, l4: e.target.value })} /></Field></div>
          <Button variant="gold" disabled={!f.code.trim() || !f.name_ar.trim() || !f.name_en.trim()} onClick={() => void create()}>{t('needsHub.cycle.saved')}</Button>
        </div>
      </Modal>
    </div>
  )
}
