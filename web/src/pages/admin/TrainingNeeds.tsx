import { Plus } from 'lucide-react'
import { useState } from 'react'
import { useSearchParams } from 'react-router-dom'
import { useTranslation } from 'react-i18next'
import { BarsChart, DonutChart } from '@/components/admin/charts'
import { Badge, Button, Card, CardTitle, Field, Modal, PageHeader, Spinner, StatusBadge, Tabs } from '@/components/ui'
import { DataView } from '@/components/ui/DataView'
import { useGet } from '@/hooks/useApi'
import { api, errorMessage } from '@/lib/api'
import { useAuth } from '@/lib/auth'
import { fmt } from '@/lib/format'
import type { LaravelPage } from '@/lib/types'
import SurveysTab from './needs/SurveysTab'

type Need = { id: string; skill_name: string; employees_count: number; priority: string; reason: string; status: string; target_group?: string; created_at: string; school?: { name_ar: string; name_en: string; region: string } }
type Analytics = {
  totals: { requests: number; open: number; employees: number; fulfilled: number }
  by_priority: Record<string, number>
  most_requested_skills: { skill: string; requests: number; employees: number; schools: number; priority_score: number }[]
  most_needed_programs: { program_id: string; program: string; demand: number; capacity: number; seats_gap: number }[]
  uncovered_skills: { skill: string; employees: number }[]
  school_gaps: { school: string; region: string; requests: number; employees: number; critical: number; skills: string[] }[]
}

export default function TrainingNeeds() {
  const { t, i18n } = useTranslation()
  const { can } = useAuth()
  const isCenter = can('needs.manage')
  const [params, setParams] = useSearchParams()
  type Tab = 'surveys' | 'analytics' | 'requests'
  const requested = params.get('tab') as Tab | null
  const tab: Tab = isCenter ? (requested && ['surveys', 'analytics', 'requests'].includes(requested) ? requested : 'surveys') : 'requests'
  const setTab = (next: Tab) => setParams(next === 'surveys' ? {} : { tab: next }, { replace: true })
  const [creating, setCreating] = useState(false)
  const list = useGet<LaravelPage<Need>>('/admin/training-needs', { per_page: 50 })
  const analytics = useGet<{ data: Analytics }>(isCenter ? '/admin/training-needs/analytics' : null)
  const lookups = useGet<{ data: { skills: { id: string; name_ar: string; name_en: string }[]; job_titles: { id: string; name_ar: string; name_en: string }[] } }>('/admin/lookups')
  const [form, setForm] = useState({ skill_id: '', skill_name: '', employees_count: 5, priority: 'high', reason: '', target_job_title_id: '', target_group: '' })
  const [error, setError] = useState<string | null>(null)
  const nm = (o: { name_ar: string; name_en: string }) => (i18n.language === 'ar' ? o.name_ar : o.name_en)

  const submit = async () => {
    setError(null)
    try {
      await api.post('/admin/training-needs', { ...form, skill_id: form.skill_id || null, target_job_title_id: form.target_job_title_id || null })
      setCreating(false)
      list.refetch()
      analytics.refetch()
    } catch (e) { setError(errorMessage(e)) }
  }
  const review = async (id: string, status: string) => { await api.patch(`/admin/training-needs/${id}`, { status }); list.refetch(); analytics.refetch() }

  const a = analytics.data?.data
  return (
    <>
      <PageHeader title={t('admin.needs.title')} actions={can('needs.submit') && <Button variant="gold" icon={<Plus className="size-4" />} onClick={() => setCreating(true)}>{t('admin.needs.submit')}</Button>} />
      {isCenter && <Tabs value={tab} onChange={setTab} tabs={[{ id: 'surveys', label: t('surveys.tab') }, { id: 'analytics', label: t('admin.menu.analytics') }, { id: 'requests', label: t('admin.needs.title') }]} />}

      {tab === 'surveys' && <SurveysTab />}

      {tab === 'analytics' && (analytics.isLoading || !a ? <Spinner /> : (
        <div className="space-y-6">
          <div className="grid gap-4 sm:grid-cols-4">
            {(['requests', 'open', 'employees', 'fulfilled'] as const).map((k, i) => (
              <div key={k} className={i === 2 ? 'rounded-2xl bg-navy-900 p-5 text-white shadow-glass' : 'card p-5'}>
                <div className={i === 2 ? 'text-sm text-white/70' : 'text-sm text-slate-500'}>{k === 'employees' ? t('admin.needs.employees') : t(`status.${k === 'requests' ? 'submitted' : k === 'open' ? 'under_review' : 'fulfilled'}`)}</div>
                <div className={`mt-2 font-display text-3xl font-bold ${i === 2 ? 'text-gold-300' : 'text-navy-900'}`}>{fmt.number(a.totals[k])}</div>
              </div>
            ))}
          </div>
          <div className="grid gap-6 xl:grid-cols-3">
            <Card className="xl:col-span-2">
              <CardTitle>{t('admin.needs.mostRequested')}</CardTitle>
              <BarsChart data={a.most_requested_skills.map((s) => ({ name: s.skill, value: s.employees }))} dataKey="value" label={t('admin.needs.employees')} horizontal height={320} />
            </Card>
            <Card>
              <CardTitle>{t('admin.needs.priority')}</CardTitle>
              <DonutChart data={Object.entries(a.by_priority).map(([k, v]) => ({ name: t(`priority.${k}`), value: v }))} height={180} />
            </Card>
          </div>
          <div className="grid gap-6 xl:grid-cols-2">
            <Card>
              <CardTitle>{t('admin.needs.mostNeeded')}</CardTitle>
              <div className="-mx-5 sm:-mx-6"><DataView id="admin.needs.programs" rows={a.most_needed_programs} rowKey={(p) => p.program_id} columns={[
                { key: 'program', header: t('admin.registrations.program'), role: 'title', cell: (p) => p.program },
                { key: 'gap', header: t('admin.needs.seatsGap'), role: 'badge', cell: (p) => p.seats_gap > 0 ? <Badge color="red">{fmt.number(p.seats_gap)}</Badge> : <Badge color="green">0</Badge> },
                { key: 'demand', header: t('admin.needs.demand'), cell: (p) => fmt.number(p.demand) },
                { key: 'capacity', header: t('programs.capacity'), cell: (p) => fmt.number(p.capacity) },
              ]} cardsClassName="sm:grid-cols-1 2xl:grid-cols-2" /></div>
              {!!a.uncovered_skills.length && (
                <div className="mt-5 rounded-xl bg-red-50 p-4">
                  <div className="mb-2 text-sm font-bold text-red-700">{t('admin.needs.uncovered')}</div>
                  <div className="flex flex-wrap gap-2">{a.uncovered_skills.map((s) => <Badge key={s.skill} color="red">{s.skill} · {s.employees}</Badge>)}</div>
                </div>
              )}
            </Card>
            <Card>
              <CardTitle>{t('admin.needs.gaps')}</CardTitle>
              <div className="max-h-[420px] space-y-3 overflow-y-auto">
                {a.school_gaps.map((g) => (
                  <div key={g.school} className="rounded-xl border border-navy-100 p-3">
                    <div className="flex items-center justify-between"><span className="font-semibold text-navy-900">{g.school}</span><span className="text-xs text-slate-400">{t(`regions.${g.region}`)}</span></div>
                    <div className="mt-1 text-xs text-slate-500">{fmt.number(g.requests)} · {fmt.number(g.employees)} {t('admin.needs.employees')} {g.critical > 0 && <Badge color="red" className="ms-1">{t('priority.critical')} {g.critical}</Badge>}</div>
                    <div className="mt-2 flex flex-wrap gap-1">{g.skills.map((s) => <Badge key={s} color="gray">{s}</Badge>)}</div>
                  </div>
                ))}
              </div>
            </Card>
          </div>
        </div>
      ))}

      {tab === 'requests' && (
        <Card padded={false}>
          <DataView id="admin.needs" rows={list.data?.data} total={list.data?.total} loading={list.isLoading} rowKey={(n) => n.id} defaultMode="cards" columns={[
            { key: 'skill', header: t('admin.needs.skill'), role: 'title', cell: (n) => n.skill_name },
            { key: 'school', header: t('admin.needs.school'), role: 'subtitle', cell: (n) => n.school ? nm(n.school) : '' },
            { key: 'priority', header: t('admin.needs.priority'), role: 'badge', cell: (n) => <StatusBadge status={n.priority} /> },
            { key: 'status', header: t('common.status'), role: 'badge', cell: (n) => <StatusBadge status={n.status} /> },
            { key: 'count', header: t('admin.needs.employees'), cell: (n) => <span className="font-bold">{fmt.number(n.employees_count)}</span> },
            { key: 'date', header: t('common.date'), cell: (n) => fmt.date(n.created_at) },
            { key: 'reason', header: t('admin.needs.reason'), className: 'max-w-xs truncate text-xs text-slate-500', cell: (n) => <span title={n.reason}>{n.reason}</span> },
            { key: 'review', header: '', role: 'actions', cell: (n) => isCenter ? (
              <select className="input py-1 text-xs" value="" onChange={(e) => e.target.value && review(n.id, e.target.value)}>
                <option value="">{t('admin.needs.review')}</option>
                {['under_review', 'approved', 'planned', 'fulfilled', 'rejected'].map((s) => <option key={s} value={s}>{t(`status.${s}`)}</option>)}
              </select>
            ) : null },
          ]} />
        </Card>
      )}

      <Modal open={creating} onClose={() => setCreating(false)} title={t('admin.needs.submit')}>
        <div className="space-y-3">
          <Field label={t('admin.needs.skill')}>
            <select className="input" value={form.skill_id} onChange={(e) => setForm({ ...form, skill_id: e.target.value })}><option value="">—</option>{lookups.data?.data.skills.map((s) => <option key={s.id} value={s.id}>{nm(s)}</option>)}</select>
          </Field>
          {!form.skill_id && <input className="input" placeholder={t('admin.needs.skill')} value={form.skill_name} onChange={(e) => setForm({ ...form, skill_name: e.target.value })} />}
          <div className="grid grid-cols-2 gap-3">
            <Field label={t('admin.needs.employees')}><input className="input" type="number" min={1} value={form.employees_count} onChange={(e) => setForm({ ...form, employees_count: Number(e.target.value) })} /></Field>
            <Field label={t('admin.needs.priority')}><select className="input" value={form.priority} onChange={(e) => setForm({ ...form, priority: e.target.value })}>{['low', 'medium', 'high', 'critical'].map((p) => <option key={p} value={p}>{t(`priority.${p}`)}</option>)}</select></Field>
          </div>
          <Field label={t('admin.needs.targetGroup')}><select className="input" value={form.target_job_title_id} onChange={(e) => setForm({ ...form, target_job_title_id: e.target.value })}><option value="">—</option>{lookups.data?.data.job_titles.map((j) => <option key={j.id} value={j.id}>{nm(j)}</option>)}</select></Field>
          <Field label={t('admin.needs.reason')}><textarea className="input" value={form.reason} onChange={(e) => setForm({ ...form, reason: e.target.value })} /></Field>
          {error && <p className="text-sm text-danger">{error}</p>}
          <div className="flex justify-end"><Button variant="gold" onClick={submit}>{t('common.submit')}</Button></div>
        </div>
      </Modal>
    </>
  )
}
