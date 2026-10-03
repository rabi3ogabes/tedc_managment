import clsx from 'clsx'
import { Plus, Trash2 } from 'lucide-react'
import { useMemo, useState } from 'react'
import { useTranslation } from 'react-i18next'
import { Button, Field, Modal } from '@/components/ui'
import { useGet } from '@/hooks/useApi'
import { api, errorMessage } from '@/lib/api'
import { useAuth } from '@/lib/auth'
import type { KitDelivery, KitDetail, KitMember, KitRole, Person } from './types'
import type { Paginated, Program } from '@/lib/types'

type Row = { user_id: string; role: KitRole }
const ROLES: KitRole[] = ['developer', 'qa', 'reviewer', 'viewer']

/** Create or edit a kit: titles, objectives, due date, program link and the team (developers and QA). */
export default function KitForm({ kit, defaultDelivery = 'standard', onClose, onSaved }: { kit?: KitDetail; defaultDelivery?: KitDelivery; onClose: () => void; onSaved: (id: string) => void }) {
  const { t, i18n } = useTranslation()
  const { user } = useAuth()
  const people = useGet<{ data: Person[] }>('/admin/kits/people')
  const programs = useGet<Paginated<Program>>('/admin/programs', { per_page: 100 })
  const categories = useGet<{ data: { categories: { id: string; name_ar: string; name_en: string }[] } }>('/admin/lookups')
  const [form, setForm] = useState(() => ({
    title_ar: kit?.title_ar ?? '', title_en: kit?.title_en ?? '', audience: kit?.audience ?? '', duration_hours: kit ? String(kit.duration_hours) : '', objectives: (kit?.objectives ?? []).join('\n'),
    program_id: kit?.program_id ?? '', category_id: kit?.category_id ?? '', due_at: kit?.due_at ? kit.due_at.slice(0, 10) : '', description_ar: kit?.description_ar ?? '',
  }))
  const [delivery, setDelivery] = useState<KitDelivery>(kit?.delivery ?? defaultDelivery)
  const [owner, setOwner] = useState(kit?.owner_id ?? user?.id ?? '')
  const [team, setTeam] = useState<Row[]>((kit?.members ?? []).map((m: KitMember) => ({ user_id: m.user_id, role: m.role })))
  const [busy, setBusy] = useState(false)
  const [error, setError] = useState<string | null>(null)
  const set = <K extends keyof typeof form>(k: K, v: (typeof form)[K]) => setForm((f) => ({ ...f, [k]: v }))
  const list = useMemo(() => people.data?.data ?? [], [people.data])
  const nameOf = (id: string) => list.find((p) => p.id === id)?.name ?? ''
  const en = i18n.language === 'en'

  const pickProgram = (id: string) => {
    set('program_id', id)
    const p = programs.data?.data.find((x) => x.id === id)
    if (p) setDelivery(p.delivery_mode === 'online' ? 'online' : 'standard')
    if (p) setForm((f) => ({ ...f, program_id: id, title_ar: f.title_ar || p.title_ar, title_en: f.title_en || p.title_en, duration_hours: f.duration_hours || String(p.total_hours), objectives: f.objectives || (p.objectives ?? []).join('\n'), category_id: f.category_id || p.category_id || '' }))
  }

  const save = async () => {
    setBusy(true)
    setError(null)
    try {
      const body = {
        ...form, delivery, audience: form.audience || null, duration_hours: form.duration_hours ? Number(form.duration_hours) : 0, program_id: form.program_id || null, category_id: form.category_id || null, due_at: form.due_at || null,
        description_ar: form.description_ar || null, objectives: form.objectives.split('\n').map((s) => s.trim()).filter(Boolean), owner_id: owner || undefined,
        members: team.filter((m) => m.user_id && m.user_id !== owner),
      }
      if (kit) {
        await api.put(`/admin/kits/${kit.id}`, { ...body, members: undefined, owner_id: undefined })
        await api.put(`/admin/kits/${kit.id}/members`, { owner_id: owner, members: body.members })
        onSaved(kit.id)
      } else {
        const res = await api.post<{ data: { id: string } }>('/admin/kits', body)
        onSaved(res.data.data.id)
      }
    } catch (e) {
      setError(errorMessage(e))
      setBusy(false)
    }
  }

  const valid = (form.title_ar.trim() && form.title_en.trim()) || form.program_id

  return (
    <Modal open onClose={busy ? () => undefined : onClose} wide title={kit ? t('kits.form.editTitle') : t('kits.form.newTitle')}>
      <div className="space-y-6">
        <div role="radiogroup" aria-label={t('kits.delivery.label')} className="grid grid-cols-2 gap-2 rounded-2xl bg-ivory p-1.5">
          {(['standard', 'online'] as const).map((d) => <button key={d} type="button" role="radio" aria-checked={delivery === d} onClick={() => setDelivery(d)} className={clsx('rounded-xl px-4 py-2.5 text-sm font-bold transition', delivery === d ? 'bg-navy-900 text-white shadow' : 'text-slate-600 hover:text-navy-900')}>{t(`kits.delivery.${d}`)}</button>)}
        </div>
        {!kit && (
          <Field label={t('kits.form.program')} hint={t('kits.form.programHint')}>
            <select className="input" value={form.program_id} onChange={(e) => pickProgram(e.target.value)}>
              <option value="">{t('kits.form.noProgram')}</option>
              {programs.data?.data.map((p) => <option key={p.id} value={p.id}>{p.code} · {p.title}</option>)}
            </select>
          </Field>
        )}
        <div className="grid gap-4 sm:grid-cols-2">
          <Field label={t('kits.form.titleAr')}><input dir="rtl" className="input" value={form.title_ar} onChange={(e) => set('title_ar', e.target.value)} /></Field>
          <Field label={t('kits.form.titleEn')}><input dir="ltr" className="input" value={form.title_en} onChange={(e) => set('title_en', e.target.value)} /></Field>
          <Field label={t('kits.form.audience')}><input className="input" dir="auto" value={form.audience} onChange={(e) => set('audience', e.target.value)} /></Field>
          <div className="grid grid-cols-2 gap-3">
            <Field label={t('kits.form.hours')}><input type="number" min={0} step={0.5} className="input" value={form.duration_hours} onChange={(e) => set('duration_hours', e.target.value)} /></Field>
            <Field label={t('kits.form.due')}><input type="date" className="input" value={form.due_at} onChange={(e) => set('due_at', e.target.value)} /></Field>
          </div>
          <Field label={t('kits.form.category')}>
            <select className="input" value={form.category_id} onChange={(e) => set('category_id', e.target.value)}><option value="">—</option>{categories.data?.data.categories.map((c) => <option key={c.id} value={c.id}>{en ? c.name_en : c.name_ar}</option>)}</select>
          </Field>
          {kit && kit.program && <Field label={t('kits.form.program')}><input className="input" readOnly value={`${kit.program.code} · ${kit.program.title}`} /></Field>}
        </div>
        <Field label={t('kits.form.objectives')} hint={t('kits.form.objectivesHint')}><textarea rows={4} className="input" dir="auto" value={form.objectives} onChange={(e) => set('objectives', e.target.value)} /></Field>

        <section>
          <div className="mb-2 flex items-center justify-between"><h4 className="text-sm font-bold text-navy-900">{t('kits.form.team')}</h4><Button size="sm" variant="outline" icon={<Plus className="size-4" />} onClick={() => setTeam((c) => [...c, { user_id: '', role: 'qa' }])}>{t('kits.form.addMember')}</Button></div>
          <div className="space-y-2">
            <div className="grid grid-cols-[1fr_9rem_2rem] items-center gap-2 rounded-xl bg-ivory p-2">
              <select className="input !py-1.5 text-sm" value={owner} onChange={(e) => setOwner(e.target.value)} aria-label={t('kits.form.owner')}>
                {owner && !list.some((p) => p.id === owner) && <option value={owner}>{kit?.owner?.name ?? user?.name}</option>}
                {list.map((p) => <option key={p.id} value={p.id}>{p.name}</option>)}
              </select>
              <span className="rounded-lg bg-gold-100 px-2 py-1.5 text-center text-xs font-bold text-gold-700">{t('kits.form.ownerRole')}</span><span />
            </div>
            {team.map((m, i) => (
              <div key={i} className="grid grid-cols-[1fr_9rem_2rem] items-center gap-2">
                <select className="input !py-1.5 text-sm" value={m.user_id} onChange={(e) => { const p = list.find((x) => x.id === e.target.value); setTeam((c) => c.map((r, n) => (n === i ? { user_id: e.target.value, role: p?.suggested_role ?? r.role } : r))) }} aria-label={t('kits.form.member')}>
                  <option value="">{t('kits.form.pickPerson')}</option>
                  {list.filter((p) => p.id !== owner && !team.some((r, n) => n !== i && r.user_id === p.id)).map((p) => <option key={p.id} value={p.id}>{p.name}</option>)}
                  {m.user_id && !list.some((p) => p.id === m.user_id) && <option value={m.user_id}>{kit?.members?.find((x) => x.user_id === m.user_id)?.name ?? nameOf(m.user_id)}</option>}
                </select>
                <select className={clsx('input !py-1.5 text-sm font-semibold', m.role === 'qa' && 'text-gold-700')} value={m.role} onChange={(e) => setTeam((c) => c.map((r, n) => (n === i ? { ...r, role: e.target.value as KitRole } : r)))} aria-label={t('kits.form.role')}>
                  {ROLES.map((r) => <option key={r} value={r}>{t(`kits.roles.${r}`)}</option>)}
                </select>
                <button type="button" onClick={() => setTeam((c) => c.filter((_, n) => n !== i))} className="grid size-8 place-items-center rounded-lg text-slate-400 hover:bg-red-50 hover:text-danger" aria-label={t('kits.common.delete')}><Trash2 className="size-4" /></button>
              </div>
            ))}
            {team.length === 0 && <p className="rounded-xl border border-dashed border-navy-200 p-3 text-center text-xs text-slate-400">{t('kits.form.noTeam')}</p>}
          </div>
        </section>

        {error && <div className="rounded-xl bg-red-50 p-3 text-sm text-danger">{error}</div>}
        <div className="flex justify-end gap-2"><Button variant="ghost" disabled={busy} onClick={onClose}>{t('kits.common.cancel')}</Button><Button variant="gold" loading={busy} disabled={!valid} onClick={save}>{kit ? t('kits.common.save') : t('kits.form.create')}</Button></div>
      </div>
    </Modal>
  )
}
