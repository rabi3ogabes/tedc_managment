import { Copy, Plus, Trash2 } from 'lucide-react'
import { useState } from 'react'
import { useTranslation } from 'react-i18next'
import { Badge, Button, Card, Empty, Field, Modal, PageHeader, Spinner, Tabs } from '@/components/ui'
import { useGet } from '@/hooks/useApi'
import { api, errorMessage } from '@/lib/api'
import { useAuth } from '@/lib/auth'
import { toast } from '@/lib/toast'

type Tab = 'priority' | 'withdrawal' | 'external'

/** Admission rules in one place: priority ranking, the withdrawal policy and reasons, and the external registration forms. */
export default function AdmissionSettings() {
  const { t } = useTranslation()
  const { can } = useAuth()
  const tabs: { id: Tab; show: boolean; label: string }[] = [
    { id: 'priority', show: can('priority.manage'), label: t('admission.rules.title') },
    { id: 'withdrawal', show: can('withdrawals.policy'), label: t('admission.withdraw.policy') },
    { id: 'external', show: can('external_forms.manage'), label: t('admission.ext.title') },
  ]
  const visible = tabs.filter((x) => x.show)
  const [tab, setTab] = useState<Tab>(visible[0]?.id ?? 'priority')
  return (
    <div className="space-y-6 pb-6">
      <PageHeader title={t('admission.rulesNav')} subtitle={t('admission.rules.hint')} />
      <Tabs<Tab> value={tab} onChange={setTab} tabs={visible.map((x) => ({ id: x.id, label: x.label }))} />
      {tab === 'priority' && <PriorityRules />}
      {tab === 'withdrawal' && <WithdrawalSettings />}
      {tab === 'external' && <ExternalForms />}
    </div>
  )
}

const CRITERIA = ['plan_targeted', 'approved_individual_need', 'no_training_in_months', 'appraisal_below', 'entity_priority', 'registration_date']

function PriorityRules() {
  const { t } = useTranslation()
  const rules = useGet<{ data: { id: string; scope: string; criteria: string[]; weights: Record<string, number> | null }[]; defaults: { criteria: string[]; weights: Record<string, number> } }>('/admin/priority-rules', undefined, { staleTime: 0 })
  const groups = useGet<{ data: { columns: Record<string, { groups: { id: string; code: string; title: string }[] }> } }>('/admin/groups/board', undefined, { staleTime: 60_000 })
  const [order, setOrder] = useState<string[] | null>(null)
  const [weights, setWeights] = useState<Record<string, number> | null>(null)
  const [groupId, setGroupId] = useState('')
  const [preview, setPreview] = useState<{ employee: string; score: number }[] | null>(null)
  if (rules.isLoading || !rules.data) return <Spinner />
  const global = rules.data.data.find((r) => r.scope === 'global')
  const crit = order ?? global?.criteria ?? rules.data.defaults.criteria
  const w = weights ?? { ...rules.data.defaults.weights, ...(global?.weights ?? {}) }
  const move = (i: number, d: number) => { const n = [...crit]; const j = i + d; if (j < 0 || j >= n.length) return; [n[i], n[j]] = [n[j], n[i]]; setOrder(n) }
  const toggle = (c: string) => setOrder(crit.includes(c) ? crit.filter((x) => x !== c) : [...crit, c])
  const save = async () => { try { await api[global ? 'put' : 'post'](`/admin/priority-rules${global ? `/${global.id}` : ''}`, { scope: 'global', criteria: crit, weights: w }); toast(t('admission.rules.saved')); await rules.refetch() } catch (e) { toast(errorMessage(e), 'error') } }
  const run = async () => { try { const { data } = await api.post('/admin/priority-rules/preview', { group_id: groupId, criteria: crit, weights: w }); setPreview(data.data) } catch (e) { toast(errorMessage(e), 'error') } }
  const allGroups = Object.values(groups.data?.data.columns ?? {}).flatMap((c) => c.groups)

  return (
    <div className="grid gap-5 lg:grid-cols-2">
      <Card className="space-y-4">
        <h3 className="font-bold text-navy-900">{t('admission.rules.title')}</h3>
        <ol className="space-y-2">{crit.map((c, i) => (
          <li key={c} className="flex items-center gap-2 rounded-xl border border-navy-100 bg-white p-2">
            <span className="grid size-6 place-items-center rounded-full bg-navy-900 text-xs font-bold text-white">{i + 1}</span>
            <span className="flex-1 text-sm font-semibold text-navy-900">{t(`admission.rules.criteria.${c}`)}</span>
            <input type="number" min={0} max={100} aria-label={t('admission.rules.weight')} className="input w-20" value={w[c] ?? 0} onChange={(e) => setWeights({ ...w, [c]: Number(e.target.value) })} />
            <button type="button" aria-label="up" className="px-1 text-slate-400 hover:text-navy-900" onClick={() => move(i, -1)}>▲</button>
            <button type="button" aria-label="down" className="px-1 text-slate-400 hover:text-navy-900" onClick={() => move(i, 1)}>▼</button>
            <button type="button" aria-label="remove" className="px-1 text-slate-400 hover:text-danger" onClick={() => toggle(c)}>×</button>
          </li>))}</ol>
        <div className="flex flex-wrap gap-2">{CRITERIA.filter((c) => !crit.includes(c)).map((c) => <button key={c} type="button" onClick={() => toggle(c)} className="rounded-full border border-dashed border-navy-200 px-3 py-1 text-xs text-slate-600 hover:border-gold-400">+ {t(`admission.rules.criteria.${c}`)}</button>)}</div>
        <Button variant="gold" onClick={() => void save()}>{t('admission.rules.save')}</Button>
      </Card>
      <Card className="space-y-4">
        <h3 className="font-bold text-navy-900">{t('admission.rules.preview')}</h3>
        <Field label={t('admission.rules.group')}><select className="input" value={groupId} onChange={(e) => { setGroupId(e.target.value); setPreview(null) }}><option value="" />{allGroups.map((g) => <option key={g.id} value={g.id}>{g.code} — {g.title}</option>)}</select></Field>
        <Button variant="outline" disabled={!groupId} onClick={() => void run()}>{t('admission.rules.preview')}</Button>
        {preview && (preview.length === 0 ? <p className="text-sm text-slate-500">{t('admission.rules.previewEmpty')}</p> : <ol className="space-y-1.5">{preview.map((p, i) => <li key={i} className="flex justify-between rounded-lg bg-ivory px-3 py-2 text-sm"><span>{i + 1}. {p.employee}</span><b className="tabular-nums">{p.score}</b></li>)}</ol>)}
      </Card>
    </div>
  )
}

function WithdrawalSettings() {
  const { t, i18n } = useTranslation()
  const ar = i18n.language === 'ar'
  const policy = useGet<{ data: { min_days_before_start: number; allow_after_start: boolean } }>('/admin/settings/withdrawal-policy', undefined, { staleTime: 0 })
  const reasons = useGet<{ data: { id: string; code: string; label_ar: string; label_en: string; requires_attachment: boolean; is_active: boolean }[] }>('/admin/withdrawal-reasons', undefined, { staleTime: 0 })
  const [p, setP] = useState<{ min_days_before_start: number; allow_after_start: boolean } | null>(null)
  const [open, setOpen] = useState(false)
  const [f, setF] = useState({ code: '', label_ar: '', label_en: '', requires_attachment: false })
  if (policy.isLoading || reasons.isLoading || !policy.data || !reasons.data) return <Spinner />
  const cur = p ?? policy.data.data
  const savePolicy = async () => { try { await api.put('/admin/settings/withdrawal-policy', cur); toast(t('admission.withdraw.saved')); await policy.refetch() } catch (e) { toast(errorMessage(e), 'error') } }
  const patch = async (id: string, body: object) => { try { await api.put(`/admin/withdrawal-reasons/${id}`, body); await reasons.refetch() } catch (e) { toast(errorMessage(e), 'error') } }
  return (
    <div className="grid gap-5 lg:grid-cols-2">
      <Card className="space-y-4">
        <h3 className="font-bold text-navy-900">{t('admission.withdraw.policy')}</h3>
        <Field label={t('admission.withdraw.minDays')}><input type="number" min={0} className="input w-28" value={cur.min_days_before_start} onChange={(e) => setP({ ...cur, min_days_before_start: Number(e.target.value) })} /></Field>
        <label className="flex items-center gap-2 text-sm font-semibold text-navy-900"><input type="checkbox" checked={cur.allow_after_start} onChange={(e) => setP({ ...cur, allow_after_start: e.target.checked })} />{t('admission.withdraw.afterStart')}</label>
        <Button variant="gold" onClick={() => void savePolicy()}>{t('admission.equiv.save')}</Button>
      </Card>
      <Card className="space-y-3">
        <div className="flex items-center justify-between"><h3 className="font-bold text-navy-900">{t('admission.withdraw.reasons')}</h3><Button size="sm" variant="outline" icon={<Plus className="size-4" />} onClick={() => setOpen(true)}>{t('admission.withdraw.newReason')}</Button></div>
        <ul className="divide-y divide-navy-50">{reasons.data.data.map((r) => (
          <li key={r.id} className="flex flex-wrap items-center gap-3 py-2 text-sm"><span className="min-w-0 flex-1 font-semibold text-navy-900">{ar ? r.label_ar : r.label_en}</span>
            <label className="flex items-center gap-1 text-xs text-slate-600"><input type="checkbox" checked={r.requires_attachment} onChange={(e) => void patch(r.id, { requires_attachment: e.target.checked })} />{t('admission.withdraw.needsAttachment')}</label>
            <label className="flex items-center gap-1 text-xs text-slate-600"><input type="checkbox" checked={r.is_active} onChange={(e) => void patch(r.id, { is_active: e.target.checked })} />{t('admission.withdraw.active')}</label></li>))}</ul>
      </Card>
      <Modal open={open} onClose={() => setOpen(false)} title={t('admission.withdraw.newReason')}>
        <div className="space-y-4">
          <Field label={t('admission.withdraw.code')}><input className="input" dir="ltr" value={f.code} onChange={(e) => setF({ ...f, code: e.target.value })} /></Field>
          <Field label={t('admission.withdraw.labelAr')}><input className="input" value={f.label_ar} onChange={(e) => setF({ ...f, label_ar: e.target.value })} /></Field>
          <Field label={t('admission.withdraw.labelEn')}><input className="input" dir="ltr" value={f.label_en} onChange={(e) => setF({ ...f, label_en: e.target.value })} /></Field>
          <label className="flex items-center gap-2 text-sm"><input type="checkbox" checked={f.requires_attachment} onChange={(e) => setF({ ...f, requires_attachment: e.target.checked })} />{t('admission.withdraw.needsAttachment')}</label>
          <Button variant="gold" disabled={!f.code.trim() || !f.label_ar.trim() || !f.label_en.trim()} onClick={() => void api.post('/admin/withdrawal-reasons', f).then(() => { setOpen(false); setF({ code: '', label_ar: '', label_en: '', requires_attachment: false }); return reasons.refetch() }).catch((e) => toast(errorMessage(e), 'error'))}>{t('admission.equiv.save')}</Button>
        </div>
      </Modal>
    </div>
  )
}

type Field = { key: string; type: string; label_ar: string; label_en: string; required: boolean; options?: string[] }
type FormRow = { id: string; slug: string; title_ar: string; title_en: string; intro_ar: string | null; intro_en: string | null; audience: string; fields: Field[]; conditions: { allowed_email_domains?: string[] } | null; is_active: boolean; share_url: string; requests_count?: number; closes_at: string | null }
const FIELD_TYPES = ['text', 'textarea', 'email', 'phone', 'number', 'date', 'select', 'multiselect', 'national_id']
const blankForm = { title_ar: '', title_en: '', intro_ar: '', intro_en: '', audience: 'trainee', domains: '', closes_at: '', fields: [{ key: 'name_ar', type: 'text', label_ar: 'الاسم بالعربية', label_en: 'Name (Arabic)', required: true, options: '' }, { key: 'name_en', type: 'text', label_ar: 'الاسم بالإنجليزية', label_en: 'Name (English)', required: true, options: '' }] as (Omit<Field, 'options'> & { options: string })[] }

function ExternalForms() {
  const { t, i18n } = useTranslation()
  const ar = i18n.language === 'ar'
  const forms = useGet<{ data: FormRow[] }>('/admin/registration-forms', undefined, { staleTime: 0 })
  const [editing, setEditing] = useState<FormRow | 'new' | null>(null)
  const [f, setF] = useState(blankForm)
  const open = (row: FormRow | 'new') => {
    setEditing(row)
    setF(row === 'new' ? blankForm : { title_ar: row.title_ar, title_en: row.title_en, intro_ar: row.intro_ar ?? '', intro_en: row.intro_en ?? '', audience: row.audience, domains: (row.conditions?.allowed_email_domains ?? []).join(','), closes_at: row.closes_at?.slice(0, 10) ?? '', fields: row.fields.map((x) => ({ ...x, options: (x.options ?? []).join(',') })) })
  }
  const save = async () => {
    const body = { title_ar: f.title_ar, title_en: f.title_en, intro_ar: f.intro_ar || null, intro_en: f.intro_en || null, audience: f.audience, conditions: { allowed_email_domains: f.domains.split(',').map((x) => x.trim()).filter(Boolean) }, closes_at: f.closes_at ? `${f.closes_at} 23:59:00` : null,
      fields: f.fields.map((x) => ({ key: x.key, type: x.type, label_ar: x.label_ar, label_en: x.label_en, required: x.required, ...(['select', 'multiselect'].includes(x.type) ? { options: x.options.split(',').map((o) => o.trim()).filter(Boolean) } : {}) })) }
    try { if (editing === 'new') await api.post('/admin/registration-forms', body); else await api.put(`/admin/registration-forms/${(editing as FormRow).id}`, body); toast(t('admission.ext.saved')); setEditing(null); await forms.refetch() } catch (e) { toast(errorMessage(e), 'error') }
  }
  const patchField = (i: number, p: Partial<(typeof f.fields)[number]>) => setF({ ...f, fields: f.fields.map((x, j) => (j === i ? { ...x, ...p } : x)) })
  if (forms.isLoading || !forms.data) return <Spinner />
  return (
    <div className="space-y-4">
      <div className="flex justify-end"><Button variant="gold" icon={<Plus className="size-4" />} onClick={() => open('new')}>{t('admission.ext.new')}</Button></div>
      {forms.data.data.length === 0 ? <Card><Empty text={t('admission.empty')} /></Card> : (
        <div className="grid gap-4 md:grid-cols-2">{forms.data.data.map((r) => (
          <Card key={r.id} className="space-y-3"><div className="flex items-start justify-between gap-2"><div className="min-w-0"><h3 className="truncate font-bold text-navy-900">{ar ? r.title_ar : r.title_en}</h3><p className="text-xs text-slate-500">{t(`admission.ext.audiences.${r.audience}`)} · {r.requests_count ?? 0}</p></div><Badge color={r.is_active ? 'green' : 'slate'}>{t('admission.ext.active')}</Badge></div>
            <div className="flex items-center gap-2 rounded-xl bg-ivory p-2 text-xs"><span className="min-w-0 flex-1 truncate font-mono" dir="ltr">{r.share_url}</span><button type="button" aria-label={t('admission.ext.copy')} onClick={() => void navigator.clipboard?.writeText(r.share_url).then(() => toast(t('admission.ext.copied')))} className="text-gold-700"><Copy className="size-4" /></button></div>
            <Button size="sm" variant="outline" onClick={() => open(r)}>{t('admission.ext.edit')}</Button></Card>))}</div>
      )}
      <Modal open={editing !== null} onClose={() => setEditing(null)} title={t('admission.ext.new')} wide>
        <div className="space-y-4">
          <div className="grid gap-4 sm:grid-cols-2"><Field label={t('admission.ext.titleAr')}><input className="input" value={f.title_ar} onChange={(e) => setF({ ...f, title_ar: e.target.value })} /></Field><Field label={t('admission.ext.titleEn')}><input className="input" dir="ltr" value={f.title_en} onChange={(e) => setF({ ...f, title_en: e.target.value })} /></Field>
            <Field label={t('admission.ext.intro')}><textarea className="input min-h-16" value={f.intro_ar} onChange={(e) => setF({ ...f, intro_ar: e.target.value })} /></Field><Field label={t('admission.ext.introEn')}><textarea className="input min-h-16" dir="ltr" value={f.intro_en} onChange={(e) => setF({ ...f, intro_en: e.target.value })} /></Field>
            <Field label={t('admission.ext.audience')}><select className="input" value={f.audience} onChange={(e) => setF({ ...f, audience: e.target.value })}>{['trainee', 'trainer', 'other'].map((a) => <option key={a} value={a}>{t(`admission.ext.audiences.${a}`)}</option>)}</select></Field><Field label={t('admission.ext.closes')}><input type="date" className="input" value={f.closes_at} onChange={(e) => setF({ ...f, closes_at: e.target.value })} /></Field></div>
          <Field label={t('admission.ext.domains')}><input className="input" dir="ltr" value={f.domains} onChange={(e) => setF({ ...f, domains: e.target.value })} /></Field>
          <div className="space-y-2"><h4 className="font-bold text-navy-900">{t('admission.ext.fields')}</h4>
            {f.fields.map((x, i) => (
              <div key={i} className="grid gap-2 rounded-xl border border-navy-100 p-3 sm:grid-cols-[8rem_9rem_1fr_1fr_auto_auto] sm:items-center">
                <input className="input font-mono text-xs" dir="ltr" aria-label={t('admission.ext.key')} value={x.key} onChange={(e) => patchField(i, { key: e.target.value })} />
                <select className="input" aria-label={t('admission.ext.type')} value={x.type} onChange={(e) => patchField(i, { type: e.target.value })}>{FIELD_TYPES.map((ty) => <option key={ty} value={ty}>{t(`admission.ext.types.${ty}`)}</option>)}</select>
                <input className="input" aria-label={t('admission.ext.labelAr')} placeholder={t('admission.ext.labelAr')} value={x.label_ar} onChange={(e) => patchField(i, { label_ar: e.target.value })} />
                <input className="input" dir="ltr" aria-label={t('admission.ext.labelEn')} placeholder={t('admission.ext.labelEn')} value={x.label_en} onChange={(e) => patchField(i, { label_en: e.target.value })} />
                <label className="flex items-center gap-1 text-xs"><input type="checkbox" checked={x.required} onChange={(e) => patchField(i, { required: e.target.checked })} />{t('admission.ext.required')}</label>
                <button type="button" aria-label="remove" className="text-slate-400 hover:text-danger" onClick={() => setF({ ...f, fields: f.fields.filter((_, j) => j !== i) })}><Trash2 className="size-4" /></button>
                {['select', 'multiselect'].includes(x.type) && <input className="input sm:col-span-6" placeholder={t('admission.ext.options')} value={x.options} onChange={(e) => patchField(i, { options: e.target.value })} />}
              </div>))}
            <Button variant="outline" size="sm" icon={<Plus className="size-4" />} onClick={() => setF({ ...f, fields: [...f.fields, { key: `field_${f.fields.length + 1}`, type: 'text', label_ar: '', label_en: '', required: false, options: '' }] })}>{t('admission.ext.addField')}</Button></div>
          <Button variant="gold" disabled={!f.title_ar.trim() || !f.title_en.trim() || f.fields.length === 0 || f.fields.some((x) => !x.key.trim() || !x.label_ar.trim() || !x.label_en.trim())} onClick={() => void save()}>{t('admission.ext.save')}</Button>
        </div>
      </Modal>
    </div>
  )
}
