/* eslint-disable @typescript-eslint/no-explicit-any */
import { Plus, Trash2 } from 'lucide-react'
import { useState } from 'react'
import { useTranslation } from 'react-i18next'
import { Badge, Button, Card, Empty, Field, Modal, PageHeader, Spinner, Tabs } from '@/components/ui'
import { useGet } from '@/hooks/useApi'
import { api, errorMessage } from '@/lib/api'
import { useAuth } from '@/lib/auth'
import { toast } from '@/lib/toast'

const input = 'w-full rounded-xl border border-navy-100 px-3 py-2 text-sm'
type Tab = 'policies' | 'groups'

/** Who may share what with whom (per role), and the job groups used as sharing targets. */
export default function SharingAdmin() {
  const { t } = useTranslation()
  const { can } = useAuth()
  const [tab, setTab] = useState<Tab>(can('sharing.manage') ? 'policies' : 'groups')
  return (<><PageHeader title={t('content.navSharing')} /><Tabs<Tab> value={tab} onChange={setTab} tabs={[...(can('sharing.manage') ? [{ id: 'policies' as Tab, label: t('content.sharing.policies') }] : []), { id: 'groups', label: t('content.sharing.jobGroups') }]} /><div className="mt-4">{tab === 'policies' ? <Policies /> : <Groups />}</div></>)
}

function Policies() {
  const { t } = useTranslation()
  const res = useGet<{ data: any[] }>('/admin/sharing-policies', undefined, { staleTime: 0 })
  const roles = useGet<{ data: any[] }>('/admin/roles')
  const [cur, setCur] = useState<any | null>(null)
  const save = async () => { try { await api.post('/admin/sharing-policies', cur); toast('✓'); setCur(null); void res.refetch() } catch (e) { toast(errorMessage(e), 'error') } }
  const del = async (id: string) => { try { await api.delete(`/admin/sharing-policies/${id}`); void res.refetch() } catch (e) { toast(errorMessage(e), 'error') } }
  const toggle = (k: 'resource_types' | 'target_types', v: string) => setCur({ ...cur, [k]: cur[k].includes(v) ? cur[k].filter((x: string) => x !== v) : [...cur[k], v] })
  return (
    <div className="space-y-3"><div className="flex justify-end"><Button size="sm" icon={<Plus className="size-4" />} onClick={() => setCur({ role: '', resource_types: ['material'], target_types: ['program'], allow_reshare: false, allow_download: true, watermark: false })}>{t('content.sharing.policies')}</Button></div>
      {res.isLoading ? <Spinner /> : !res.data?.data.length ? <Card><Empty /></Card> : res.data.data.map((p) => <Card key={p.id} className="flex flex-wrap items-center gap-2"><b>{p.role}</b>{p.resource_types.map((x: string) => <Badge key={x}>{x}</Badge>)}→{p.target_types.map((x: string) => <Badge key={x} color="gold">{t(`content.sharing.targets.${x}`)}</Badge>)}{p.allow_reshare && <Badge color="green">{t('content.sharing.allowReshare')}</Badge>}{!p.allow_download && <Badge color="gray">view</Badge>}<Button className="ms-auto" size="sm" variant="outline" onClick={() => setCur(p)}>{t('common.edit')}</Button><button type="button" aria-label="delete" className="text-danger" onClick={() => void del(p.id)}><Trash2 className="size-4" /></button></Card>)}
      <Modal open={!!cur} onClose={() => setCur(null)} title={t('content.sharing.policies')}>{cur && <div className="space-y-3">
        <Field label={t('content.sharing.role')}><select className={input} value={cur.role} onChange={(e) => setCur({ ...cur, role: e.target.value })}><option value="">—</option>{(roles.data?.data ?? []).map((r: any) => <option key={r.slug} value={r.slug}>{r.slug}</option>)}</select></Field>
        <div className="text-sm font-semibold">{t('content.sharing.resources')}</div><div className="flex flex-wrap gap-3 text-sm">{['material', 'kit_file', 'library_item', 'lesson'].map((x) => <label key={x} className="flex items-center gap-2"><input type="checkbox" checked={cur.resource_types.includes(x)} onChange={() => toggle('resource_types', x)} />{x}</label>)}</div>
        <div className="text-sm font-semibold">{t('content.sharing.with')}</div><div className="flex flex-wrap gap-3 text-sm">{['program', 'group', 'job_group', 'role', 'user'].map((x) => <label key={x} className="flex items-center gap-2"><input type="checkbox" checked={cur.target_types.includes(x)} onChange={() => toggle('target_types', x)} />{t(`content.sharing.targets.${x}`)}</label>)}</div>
        <div className="flex flex-wrap gap-4 text-sm"><label className="flex items-center gap-2"><input type="checkbox" checked={cur.allow_reshare} onChange={(e) => setCur({ ...cur, allow_reshare: e.target.checked })} />{t('content.sharing.allowReshare')}</label><label className="flex items-center gap-2"><input type="checkbox" checked={cur.allow_download} onChange={(e) => setCur({ ...cur, allow_download: e.target.checked })} />{t('content.sharing.allowDownload')}</label><label className="flex items-center gap-2"><input type="checkbox" checked={cur.watermark} onChange={(e) => setCur({ ...cur, watermark: e.target.checked })} />{t('content.admin.watermark')}</label></div>
        <Button variant="gold" disabled={!cur.role} onClick={() => void save()}>{t('common.save')}</Button></div>}</Modal></div>
  )
}

function Groups() {
  const { t, i18n } = useTranslation()
  const ar = i18n.language === 'ar'
  const res = useGet<{ data: any[] }>('/admin/job-groups', undefined, { staleTime: 0 })
  const lookups = useGet<{ data: { job_titles: any[] } }>('/admin/lookups')
  const jobs = { data: { data: lookups.data?.data.job_titles ?? [] } }
  const [cur, setCur] = useState<any | null>(null)
  const save = async () => { try { const body = { name_ar: cur.name_ar, name_en: cur.name_en, rule: { job_title_ids: cur.rule.job_title_ids ?? [], job_categories: split(cur.cats), subjects: split(cur.subjects) } }; cur.id ? await api.put(`/admin/job-groups/${cur.id}`, body) : await api.post('/admin/job-groups', body); setCur(null); void res.refetch() } catch (e) { toast(errorMessage(e), 'error') } }
  const split = (s: string) => String(s ?? '').split(',').map((x) => x.trim()).filter(Boolean)
  const del = async (id: string) => { try { await api.delete(`/admin/job-groups/${id}`); void res.refetch() } catch (e) { toast(errorMessage(e), 'error') } }
  return (
    <div className="space-y-3"><div className="flex justify-end"><Button size="sm" icon={<Plus className="size-4" />} onClick={() => setCur({ name_ar: '', name_en: '', rule: { job_title_ids: [] }, cats: '', subjects: '' })}>{t('content.sharing.newGroup')}</Button></div>
      {res.isLoading ? <Spinner /> : !res.data?.data.length ? <Card><Empty /></Card> : res.data.data.map((g) => <Card key={g.id} className="flex items-center gap-3"><b>{ar ? g.name_ar : g.name_en}</b><Badge color="gold">{t('content.sharing.members', { n: g.members })}</Badge><Button className="ms-auto" size="sm" variant="outline" onClick={() => setCur({ ...g, cats: (g.rule.job_categories ?? []).join(', '), subjects: (g.rule.subjects ?? []).join(', ') })}>{t('common.edit')}</Button><button type="button" aria-label="delete" className="text-danger" onClick={() => void del(g.id)}><Trash2 className="size-4" /></button></Card>)}
      <Modal open={!!cur} onClose={() => setCur(null)} title={t('content.sharing.jobGroups')}>{cur && <div className="space-y-3">
        <Field label={t('content.admin.titleAr')}><input className={input} value={cur.name_ar} onChange={(e) => setCur({ ...cur, name_ar: e.target.value })} /></Field><Field label={t('content.admin.titleEn')}><input dir="ltr" className={input} value={cur.name_en} onChange={(e) => setCur({ ...cur, name_en: e.target.value })} /></Field>
        <Field label={t('content.sharing.ruleTitles')}><select multiple className={`${input} h-28`} value={cur.rule.job_title_ids ?? []} onChange={(e) => setCur({ ...cur, rule: { ...cur.rule, job_title_ids: Array.from(e.target.selectedOptions).map((o) => o.value) } })}>{(jobs.data?.data ?? []).map((j: any) => <option key={j.id} value={j.id}>{ar ? j.name_ar : j.name_en}</option>)}</select></Field>
        <Field label={t('content.sharing.ruleCategories')}><input className={input} value={cur.cats} onChange={(e) => setCur({ ...cur, cats: e.target.value })} /></Field><Field label={t('content.sharing.ruleSubjects')}><input className={input} value={cur.subjects} onChange={(e) => setCur({ ...cur, subjects: e.target.value })} /></Field>
        <Button variant="gold" onClick={() => void save()}>{t('common.save')}</Button></div>}</Modal></div>
  )
}
