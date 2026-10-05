import { useState } from 'react'
import { useTranslation } from 'react-i18next'
import { Button, Card, Field, Spinner } from '@/components/ui'
import { useGet } from '@/hooks/useApi'
import { api, errorMessage } from '@/lib/api'
import { toast } from '@/lib/toast'

type Report = { imported: number; updated?: number; errors: { line: number; reason: string }[] }
type Weak = { employee_id: string; name: string | null; employee_no: string; school: string | null; ratings: { year: number; rating: string }[] }
const RATINGS = ['weak', 'acceptable', 'good', 'very_good', 'excellent']

function Importer({ title, url, onDone }: { title: string; url: string; onDone: (r: Report) => void }) {
  const { t } = useTranslation()
  const upload = async (f: File) => {
    const body = new FormData(); body.append('file', f)
    try { const { data } = await api.post(url, body); onDone(data.data) } catch (e) { toast(errorMessage(e), 'error') }
  }
  return (
    <label className="flex cursor-pointer flex-col items-center gap-2 rounded-2xl border-2 border-dashed border-navy-100 bg-white p-6 text-center hover:border-gold-400">
      <span className="font-bold text-navy-900">{title}</span><span className="text-xs text-slate-500">{t('needsHub.perf.choose')}</span>
      <input type="file" accept=".csv,.xlsx" className="hidden" onChange={(e) => { const f = e.target.files?.[0]; if (f) void upload(f); e.target.value = '' }} />
    </label>
  )
}

/** Imports of appraisals and classroom observations with their validation report, and the weak-performer report. */
export default function PerformanceTab() {
  const { t } = useTranslation()
  const [report, setReport] = useState<Report | null>(null)
  const [years, setYears] = useState(String(new Date().getFullYear() - 1))
  const [ratings, setRatings] = useState(['weak', 'acceptable'])
  const [skillId, setSkillId] = useState('')
  const weak = useGet<{ data: Weak[] }>('/admin/performance/weak', { years, ratings: ratings.join(',') }, { staleTime: 0 })
  const skills = useGet<{ data: { id: string; name_ar: string; name_en: string }[] }>('/admin/competencies')
  const { i18n } = useTranslation()
  const ar = i18n.language === 'ar'

  const target = async () => {
    try { const { data } = await api.post(`/admin/performance/weak/target?years=${years}&ratings=${ratings.join(',')}`, { skill_ids: [skillId] }); toast(t('needsHub.perf.targeted', { n: data.data.created })) } catch (e) { toast(errorMessage(e), 'error') }
  }
  return (
    <div className="space-y-5">
      <div className="grid gap-4 md:grid-cols-2"><Importer title={t('needsHub.perf.appraisals')} url="/admin/performance/appraisals/import" onDone={(r) => { setReport(r); void weak.refetch() }} /><Importer title={t('needsHub.perf.observations')} url="/admin/performance/observations/import" onDone={setReport} /></div>
      <p className="text-xs text-slate-500" dir="ltr">{t('needsHub.perf.template')}</p>
      {report && (
        <Card className="space-y-2"><h3 className="font-bold text-navy-900">{t('needsHub.perf.report')}</h3>
          <p className="text-sm text-slate-600">{t('needsHub.perf.imported', { n: report.imported })}{report.updated !== undefined && ` · ${t('needsHub.perf.updated', { n: report.updated })}`} · {t('needsHub.perf.errors', { n: report.errors.length })}</p>
          {report.errors.length > 0 && <ul className="max-h-48 overflow-auto rounded-xl bg-amber-50 p-3 text-xs text-amber-900">{report.errors.map((e, i) => <li key={i}>{t('needsHub.perf.line', { n: e.line })}: {e.reason}</li>)}</ul>}</Card>
      )}
      <Card className="space-y-4">
        <h3 className="font-bold text-navy-900">{t('needsHub.perf.weak')}</h3>
        <div className="flex flex-wrap items-end gap-4">
          <Field label={t('needsHub.perf.years')}><input className="input w-40" dir="ltr" value={years} onChange={(e) => setYears(e.target.value)} /></Field>
          <div className="flex flex-wrap gap-2">{RATINGS.map((r) => { const on = ratings.includes(r); return <button key={r} type="button" aria-pressed={on} onClick={() => setRatings(on ? ratings.filter((x) => x !== r) : [...ratings, r])} className={on ? 'rounded-full bg-navy-900 px-3 py-1.5 text-sm font-bold text-white' : 'rounded-full border border-navy-100 px-3 py-1.5 text-sm text-slate-600'}>{r}</button> })}</div>
        </div>
        {weak.isLoading || !weak.data ? <Spinner /> : weak.data.data.length === 0 ? <p className="text-sm text-slate-500">{t('needsHub.perf.none')}</p> : (
          <>
            <ul className="divide-y divide-navy-50">{weak.data.data.map((w) => <li key={w.employee_id} className="flex flex-wrap gap-3 py-2 text-sm"><span className="font-semibold text-navy-900">{w.name}</span><span className="text-slate-500">{w.school}</span><span className="ms-auto text-slate-600">{w.ratings.map((r) => `${r.year}: ${r.rating}`).join(' · ')}</span></li>)}</ul>
            <div className="flex flex-wrap items-end gap-3"><Field label={t('needsHub.perf.targetSkill')}><select className="input w-64" value={skillId} onChange={(e) => setSkillId(e.target.value)}><option value="" />{skills.data?.data.map((s) => <option key={s.id} value={s.id}>{ar ? s.name_ar : s.name_en}</option>)}</select></Field><Button variant="gold" disabled={!skillId} onClick={() => void target()}>{t('needsHub.perf.target')}</Button></div>
          </>
        )}
      </Card>
    </div>
  )
}
