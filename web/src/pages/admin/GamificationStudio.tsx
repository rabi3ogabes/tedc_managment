/* eslint-disable @typescript-eslint/no-explicit-any */
import { Plus, Trash2 } from 'lucide-react'
import { useEffect, useState } from 'react'
import { useTranslation } from 'react-i18next'
import { Badge, Button, Card, Empty, Field, Modal, PageHeader, Spinner, StatCard, Table, Tabs, Td } from '@/components/ui'
import { useGet } from '@/hooks/useApi'
import { useAuth } from '@/lib/auth'
import { api, errorMessage } from '@/lib/api'
import { fmt } from '@/lib/format'
import { toast } from '@/lib/toast'
import { dialogs } from '@/lib/dialogs'

type Tab = 'overview' | 'rules' | 'levels' | 'badges' | 'challenges' | 'rewards' | 'redemptions' | 'settings' | 'adjust'
const csv = (v: string) => v.split(',').map((x) => x.trim()).filter(Boolean)

/** The studio: what earns points, the levels, badges, challenges and rewards, and manual adjustments. */
export default function GamificationStudio() {
  const { t } = useTranslation()
  const { can } = useAuth()
  const manage = can('gamification.manage')
  const res = useGet<{ data: any }>('/gamification/admin/overview', undefined, { staleTime: 0, retry: false, enabled: manage } as any)
  const all: Tab[] = manage ? ['overview', 'rules', 'levels', 'badges', 'challenges', 'rewards', 'redemptions', 'settings', 'adjust'] : ['rewards', 'redemptions']
  const [tab, setTab] = useState<Tab>(all[0])
  const rewardsRes = useGet<{ data: any[] }>('/gamification/rewards', undefined, { staleTime: 0, enabled: !manage } as any)
  const d = res.data?.data
  const reload = () => res.refetch()

  if (manage && (res.isLoading || !d)) return <Spinner />
  return (
    <>
      <PageHeader title={t('soc.studio.title')} subtitle={t('soc.studio.subtitle')} />
      <Tabs<Tab> value={tab} onChange={setTab} tabs={all.map((x) => ({ id: x, label: t(`soc.studio.tabs.${x}`) }))} />
      {tab === 'overview' && d && <Overview d={d} />}
      {tab === 'rules' && d && <Rules rules={d.rules} onSaved={reload} />}
      {tab === 'levels' && d && <Levels levels={d.levels} onSaved={reload} />}
      {tab === 'badges' && d && <Badges badges={d.badges} rules={d.rules} onSaved={reload} />}
      {tab === 'challenges' && d && <Challenges rows={d.challenges} rules={d.rules} onSaved={reload} />}
      {tab === 'rewards' && <RewardsAdmin rows={d?.rewards ?? rewardsRes.data?.data ?? []} onSaved={() => { reload(); rewardsRes.refetch() }} />}
      {tab === 'redemptions' && <Redemptions />}
      {tab === 'settings' && d && <SettingsForm s={d.settings} onSaved={reload} />}
      {tab === 'adjust' && <Adjust />}
    </>
  )
}

function Overview({ d }: { d: any }) {
  const { t } = useTranslation()
  const s = d.stats
  return (
    <div className="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
      <StatCard label={t('soc.studio.active30')} value={fmt.number(s.active_30d)} />
      <StatCard label={t('soc.studio.points30')} value={fmt.number(s.points_30d)} />
      <StatCard label={t('soc.studio.badgesAwarded')} value={fmt.number(s.badges_awarded)} />
      <StatCard label={t('soc.studio.redemptionsN')} value={fmt.number(s.redemptions)} />
    </div>
  )
}

function useSave(onSaved: () => void) {
  const { t } = useTranslation()
  return async (fn: () => Promise<unknown>, ok = String(t('soc.studio.saved'))) => {
    try { await fn(); toast(ok); onSaved(); return true } catch (e) { toast(errorMessage(e), 'error'); return false }
  }
}

function Rules({ rules, onSaved }: { rules: any[]; onSaved: () => void }) {
  const { t } = useTranslation()
  const save = useSave(onSaved)
  const [rows, setRows] = useState<any[]>(rules)
  useEffect(() => setRows(rules), [rules])
  const set = (i: number, k: string, v: any) => setRows(rows.map((r, j) => (j === i ? { ...r, [k]: v } : r)))
  const cap = (i: number, k: string, v: string) => set(i, 'caps', { ...(rows[i].caps ?? {}), [k]: v === '' ? undefined : Number(v) })
  return (
    <Card padded={false}>
      <Table head={[t('soc.studio.event'), t('soc.studio.points'), t('soc.studio.capDay'), t('soc.studio.capWeek'), t('soc.studio.threshold'), t('soc.studio.active'), '']}>
        {rows.map((r, i) => (
          <tr key={r.event}>
            <Td><span className="font-semibold text-navy-900">{t(`soc.gam.events.${r.event}`, { defaultValue: r.event })}</span></Td>
            <Td><input type="number" min={0} max={1000} className="input w-24" value={r.points} onChange={(e) => set(i, 'points', Number(e.target.value))} /></Td>
            <Td><input type="number" min={1} className="input w-24" value={r.caps?.day ?? ''} onChange={(e) => cap(i, 'day', e.target.value)} /></Td>
            <Td><input type="number" min={1} className="input w-24" value={r.caps?.week ?? ''} onChange={(e) => cap(i, 'week', e.target.value)} /></Td>
            <Td>{r.event === 'assessment_passed' ? <input type="number" min={0} max={100} className="input w-24" value={r.conditions?.threshold ?? ''} onChange={(e) => set(i, 'conditions', { threshold: e.target.value === '' ? undefined : Number(e.target.value) })} /> : '—'}</Td>
            <Td><input type="checkbox" className="size-4 accent-gold-600" checked={r.is_active} onChange={(e) => set(i, 'is_active', e.target.checked)} aria-label={r.event} /></Td>
            <Td><Button size="sm" variant="outline" onClick={() => save(() => api.put(`/gamification/admin/rules/${r.event}`, { points: r.points, is_active: r.is_active, caps: r.caps ?? {}, conditions: r.conditions ?? {} }))}>{t('soc.studio.save')}</Button></Td>
          </tr>
        ))}
      </Table>
    </Card>
  )
}

function Levels({ levels, onSaved }: { levels: any[]; onSaved: () => void }) {
  const { t } = useTranslation()
  const save = useSave(onSaved)
  const [rows, setRows] = useState<any[]>(levels)
  useEffect(() => setRows(levels), [levels])
  const set = (i: number, k: string, v: any) => setRows(rows.map((r, j) => (j === i ? { ...r, [k]: v } : r)))
  return (
    <Card className="space-y-3">
      <p className="text-sm text-slate-500">{t('soc.studio.levelsHint')}</p>
      {rows.map((r, i) => (
        <div key={i} className="grid items-end gap-3 sm:grid-cols-[5rem_1fr_1fr_9rem_auto]">
          <Field label={t('soc.studio.levelNo')}><input type="number" className="input" value={r.level_no} onChange={(e) => set(i, 'level_no', Number(e.target.value))} /></Field>
          <Field label={t('soc.studio.nameAr')}><input className="input" dir="rtl" value={r.name_ar} onChange={(e) => set(i, 'name_ar', e.target.value)} /></Field>
          <Field label={t('soc.studio.nameEn')}><input className="input" dir="ltr" value={r.name_en} onChange={(e) => set(i, 'name_en', e.target.value)} /></Field>
          <Field label={t('soc.studio.minPoints')}><input type="number" min={0} className="input" value={r.min_points} onChange={(e) => set(i, 'min_points', Number(e.target.value))} /></Field>
          <Button variant="ghost" aria-label={String(t('soc.common.delete'))} onClick={() => setRows(rows.filter((_, j) => j !== i))}><Trash2 className="size-4" /></Button>
        </div>
      ))}
      <div className="flex justify-between">
        <Button variant="outline" icon={<Plus className="size-4" />} onClick={() => setRows([...rows, { level_no: (rows.at(-1)?.level_no ?? 0) + 1, name_ar: '', name_en: '', min_points: (rows.at(-1)?.min_points ?? 0) + 500 }])}>{t('soc.studio.addLevel')}</Button>
        <Button variant="gold" onClick={() => save(() => api.put('/gamification/admin/levels', { levels: rows.map(({ level_no, name_ar, name_en, min_points }) => ({ level_no, name_ar, name_en, min_points })) }))}>{t('soc.studio.save')}</Button>
      </div>
    </Card>
  )
}

function Badges({ badges, rules, onSaved }: { badges: any[]; rules: any[]; onSaved: () => void }) {
  const { t, i18n } = useTranslation()
  const save = useSave(onSaved)
  const [edit, setEdit] = useState<any | null>(null)
  const blank = { code: '', name_ar: '', name_en: '', description_ar: '', description_en: '', tier: 'bronze', icon_svg: '', criteria: { event: rules[0]?.event ?? 'lesson_completed', count: 1, within_days: '' }, is_active: true }
  const submit = async () => {
    const body = { ...edit, icon_svg: edit.icon_svg || null, criteria: { event: edit.criteria.event, count: Number(edit.criteria.count), within_days: edit.criteria.within_days ? Number(edit.criteria.within_days) : null } }
    if (await save(() => (edit.id ? api.put(`/gamification/admin/badges/${edit.id}`, body) : api.post('/gamification/admin/badges', body)))) setEdit(null)
  }
  return (
    <>
      <div className="mb-4 flex justify-end"><Button variant="gold" icon={<Plus className="size-4" />} onClick={() => setEdit(blank)}>{t('soc.studio.badge.new')}</Button></div>
      <div className="grid gap-4 sm:grid-cols-2 xl:grid-cols-3">
        {badges.map((b) => (
          <Card key={b.id}>
            <div className="flex items-center gap-3"><div className="size-14 shrink-0" dangerouslySetInnerHTML={{ __html: b.icon_svg ?? '' }} /><div className="min-w-0"><p className="font-semibold text-navy-900">{i18n.language === 'en' ? b.name_en : b.name_ar}</p><p className="font-mono text-xs text-slate-400">{b.code}</p></div></div>
            <p className="mt-2 text-xs text-slate-500">{b.criteria.count} × {t(`soc.gam.events.${b.criteria.event}`, { defaultValue: b.criteria.event })}{b.criteria.within_days ? ` / ${b.criteria.within_days}d` : ''} · {t('soc.studio.badge.awarded')}: {b.awarded}</p>
            <div className="mt-3 flex gap-2"><Button size="sm" variant="outline" onClick={() => setEdit({ ...b, criteria: { ...b.criteria, within_days: b.criteria.within_days ?? '' } })}>{t('soc.common.edit')}</Button>
              <Button size="sm" variant="ghost" onClick={async () => await dialogs.confirm(String(t('soc.studio.badge.delete'))) && save(() => api.delete(`/gamification/admin/badges/${b.id}`))}>{t('soc.common.delete')}</Button></div>
          </Card>
        ))}
      </div>
      {edit && (
        <Modal open onClose={() => setEdit(null)} title={edit.id ? edit.name_en : t('soc.studio.badge.new')} wide>
          <div className="grid gap-4 sm:grid-cols-2">
            <Field label={t('soc.studio.badge.code')}><input className="input" dir="ltr" value={edit.code} disabled={!!edit.id} onChange={(e) => setEdit({ ...edit, code: e.target.value.toLowerCase().replace(/[^a-z0-9_]/g, '') })} /></Field>
            <Field label={t('soc.studio.badge.tier')}><select className="input" value={edit.tier} onChange={(e) => setEdit({ ...edit, tier: e.target.value })}>{['bronze', 'silver', 'gold'].map((x) => <option key={x} value={x}>{t(`soc.gam.tier.${x}`)}</option>)}</select></Field>
            <Field label={t('soc.studio.nameAr')}><input className="input" dir="rtl" value={edit.name_ar} onChange={(e) => setEdit({ ...edit, name_ar: e.target.value })} /></Field>
            <Field label={t('soc.studio.nameEn')}><input className="input" dir="ltr" value={edit.name_en} onChange={(e) => setEdit({ ...edit, name_en: e.target.value })} /></Field>
            <Field label={t('soc.studio.badge.event')}><select className="input" value={edit.criteria.event} onChange={(e) => setEdit({ ...edit, criteria: { ...edit.criteria, event: e.target.value } })}>{rules.map((r) => <option key={r.event} value={r.event}>{t(`soc.gam.events.${r.event}`, { defaultValue: r.event })}</option>)}</select></Field>
            <div className="grid grid-cols-2 gap-3">
              <Field label={t('soc.studio.badge.count')}><input type="number" min={1} className="input" value={edit.criteria.count} onChange={(e) => setEdit({ ...edit, criteria: { ...edit.criteria, count: e.target.value } })} /></Field>
              <Field label={t('soc.studio.badge.within')}><input type="number" min={1} className="input" value={edit.criteria.within_days} onChange={(e) => setEdit({ ...edit, criteria: { ...edit.criteria, within_days: e.target.value } })} /></Field>
            </div>
            <Field label={t('soc.studio.badge.icon')} hint={t('soc.studio.badge.iconHint')} className="sm:col-span-2"><textarea className="input min-h-20 font-mono text-xs" dir="ltr" value={edit.icon_svg ?? ''} onChange={(e) => setEdit({ ...edit, icon_svg: e.target.value })} /></Field>
          </div>
          <div className="mt-6 flex justify-end gap-2"><Button variant="ghost" onClick={() => setEdit(null)}>{t('soc.common.cancel')}</Button><Button variant="gold" onClick={submit} disabled={!edit.code || !edit.name_ar || !edit.name_en}>{t('soc.studio.save')}</Button></div>
        </Modal>
      )}
    </>
  )
}

function Challenges({ rows, rules, onSaved }: { rows: any[]; rules: any[]; onSaved: () => void }) {
  const { t, i18n } = useTranslation()
  const save = useSave(onSaved)
  const [edit, setEdit] = useState<any | null>(null)
  const local = (d: string) => (d ? new Date(d).toISOString().slice(0, 16) : '')
  const blank = { title_ar: '', title_en: '', description_ar: '', description_en: '', starts_at: local(new Date().toISOString()), ends_at: local(new Date(Date.now() + 14 * 864e5).toISOString()), goal: { event: rules[0]?.event ?? 'lesson_completed', count: 5 }, reward: { points: 50, badge_code: '' }, schools: '', roles: '', is_active: true }
  const submit = async () => {
    const body = { title_ar: edit.title_ar, title_en: edit.title_en, description_ar: edit.description_ar || null, description_en: edit.description_en || null, starts_at: new Date(edit.starts_at).toISOString(), ends_at: new Date(edit.ends_at).toISOString(),
      goal: { event: edit.goal.event, count: Number(edit.goal.count) }, reward: { points: Number(edit.reward.points) || 0, badge_code: edit.reward.badge_code || null }, audience: { schools: csv(edit.schools), roles: csv(edit.roles) }, is_active: edit.is_active }
    if (await save(() => (edit.id ? api.put(`/gamification/admin/challenges/${edit.id}`, body) : api.post('/gamification/admin/challenges', body)))) setEdit(null)
  }
  return (
    <>
      <div className="mb-4 flex justify-end"><Button variant="gold" icon={<Plus className="size-4" />} onClick={() => setEdit(blank)}>{t('soc.studio.challenge.new')}</Button></div>
      {rows.length === 0 ? <Empty text={String(t('soc.gam.noChallenges'))} /> : (
        <Card padded={false}>
          <Table head={[t('soc.studio.nameEn'), t('soc.studio.challenge.goalEvent'), t('soc.studio.challenge.starts'), t('soc.studio.challenge.ends'), t('soc.studio.challenge.participants'), t('soc.studio.challenge.completed'), '']}>
            {rows.map((c) => (
              <tr key={c.id}>
                <Td><span className="font-semibold">{i18n.language === 'en' ? c.title_en : c.title_ar}</span> {c.closed_at && <Badge color="gray">{t('soc.gam.closedC')}</Badge>}</Td>
                <Td>{c.goal.count} × {t(`soc.gam.events.${c.goal.event}`, { defaultValue: c.goal.event })}</Td><Td>{fmt.date(c.starts_at)}</Td><Td>{fmt.date(c.ends_at)}</Td><Td>{c.participants}</Td><Td>{c.completed}</Td>
                <Td><div className="flex gap-1.5"><Button size="sm" variant="outline" onClick={() => setEdit({ ...c, starts_at: local(c.starts_at), ends_at: local(c.ends_at), reward: { points: c.reward?.points ?? 0, badge_code: c.reward?.badge_code ?? '' }, schools: (c.audience?.schools ?? []).join(', '), roles: (c.audience?.roles ?? []).join(', ') })}>{t('soc.common.edit')}</Button>
                  <Button size="sm" variant="ghost" onClick={async () => await dialogs.confirm(String(t('soc.studio.challenge.delete'))) && save(() => api.delete(`/gamification/admin/challenges/${c.id}`))}>{t('soc.common.delete')}</Button></div></Td>
              </tr>
            ))}
          </Table>
        </Card>
      )}
      {edit && (
        <Modal open onClose={() => setEdit(null)} title={t('soc.studio.challenge.new')} wide>
          <div className="grid gap-4 sm:grid-cols-2">
            <Field label={t('soc.studio.nameAr')}><input className="input" dir="rtl" value={edit.title_ar} onChange={(e) => setEdit({ ...edit, title_ar: e.target.value })} /></Field>
            <Field label={t('soc.studio.nameEn')}><input className="input" dir="ltr" value={edit.title_en} onChange={(e) => setEdit({ ...edit, title_en: e.target.value })} /></Field>
            <Field label={t('soc.studio.challenge.starts')}><input type="datetime-local" className="input" value={edit.starts_at} onChange={(e) => setEdit({ ...edit, starts_at: e.target.value })} /></Field>
            <Field label={t('soc.studio.challenge.ends')}><input type="datetime-local" className="input" value={edit.ends_at} onChange={(e) => setEdit({ ...edit, ends_at: e.target.value })} /></Field>
            <Field label={t('soc.studio.challenge.goalEvent')}><select className="input" value={edit.goal.event} onChange={(e) => setEdit({ ...edit, goal: { ...edit.goal, event: e.target.value } })}>{rules.map((r) => <option key={r.event} value={r.event}>{t(`soc.gam.events.${r.event}`, { defaultValue: r.event })}</option>)}</select></Field>
            <Field label={t('soc.studio.challenge.goalCount')}><input type="number" min={1} className="input" value={edit.goal.count} onChange={(e) => setEdit({ ...edit, goal: { ...edit.goal, count: e.target.value } })} /></Field>
            <Field label={t('soc.studio.challenge.rewardPoints')}><input type="number" min={0} className="input" value={edit.reward.points} onChange={(e) => setEdit({ ...edit, reward: { ...edit.reward, points: e.target.value } })} /></Field>
            <Field label={t('soc.studio.challenge.rewardBadge')}><input className="input" dir="ltr" value={edit.reward.badge_code} onChange={(e) => setEdit({ ...edit, reward: { ...edit.reward, badge_code: e.target.value } })} /></Field>
            <Field label={t('soc.studio.challenge.schools')} className="sm:col-span-2"><input className="input" dir="ltr" value={edit.schools} onChange={(e) => setEdit({ ...edit, schools: e.target.value })} /></Field>
            <Field label={t('soc.studio.challenge.roles')} className="sm:col-span-2"><input className="input" dir="ltr" value={edit.roles} onChange={(e) => setEdit({ ...edit, roles: e.target.value })} /></Field>
          </div>
          <div className="mt-6 flex justify-end gap-2"><Button variant="ghost" onClick={() => setEdit(null)}>{t('soc.common.cancel')}</Button><Button variant="gold" onClick={submit} disabled={!edit.title_ar || !edit.title_en}>{t('soc.studio.save')}</Button></div>
        </Modal>
      )}
    </>
  )
}

function RewardsAdmin({ rows, onSaved }: { rows: any[]; onSaved: () => void }) {
  const { t, i18n } = useTranslation()
  const { can } = useAuth()
  const save = useSave(onSaved)
  const [edit, setEdit] = useState<any | null>(null)
  const blank = { title_ar: '', title_en: '', description_ar: '', description_en: '', kind: 'voucher', cost_points: 100, min_level: 1, stock: '', is_active: true }
  const submit = async () => {
    const body = { ...edit, stock: edit.stock === '' || edit.stock === null ? null : Number(edit.stock), cost_points: Number(edit.cost_points), min_level: Number(edit.min_level) }
    if (await save(() => (edit.id ? api.put(`/gamification/admin/rewards/${edit.id}`, body) : api.post('/gamification/admin/rewards', body)))) setEdit(null)
  }
  const mayEdit = can('rewards.manage')
  return (
    <>
      {mayEdit && <div className="mb-4 flex justify-end"><Button variant="gold" icon={<Plus className="size-4" />} onClick={() => setEdit(blank)}>{t('soc.studio.reward.new')}</Button></div>}
      {rows.length === 0 ? <Empty text={String(t('soc.gam.noRewards'))} /> : (
        <Card padded={false}>
          <Table head={[t('soc.studio.nameEn'), t('soc.studio.reward.kind'), t('soc.studio.reward.cost'), t('soc.studio.reward.minLevel'), t('soc.studio.reward.stock'), t('soc.studio.reward.redeemed'), '']}>
            {rows.map((r) => (
              <tr key={r.id}>
                <Td><span className="font-semibold">{i18n.language === 'en' ? r.title_en : r.title_ar}</span> {!r.is_active && <Badge color="gray">off</Badge>}</Td><Td>{t(`soc.studio.reward.kinds.${r.kind}`)}</Td><Td>{fmt.number(r.cost_points)}</Td><Td>{r.min_level}</Td><Td>{r.stock ?? '∞'}</Td><Td>{r.redeemed ?? '—'}</Td>
                <Td>{mayEdit && <Button size="sm" variant="outline" onClick={() => setEdit({ ...r, stock: r.stock ?? '' })}>{t('soc.common.edit')}</Button>}</Td>
              </tr>
            ))}
          </Table>
        </Card>
      )}
      {edit && (
        <Modal open onClose={() => setEdit(null)} title={t('soc.studio.reward.new')} wide>
          <div className="grid gap-4 sm:grid-cols-2">
            <Field label={t('soc.studio.nameAr')}><input className="input" dir="rtl" value={edit.title_ar} onChange={(e) => setEdit({ ...edit, title_ar: e.target.value })} /></Field>
            <Field label={t('soc.studio.nameEn')}><input className="input" dir="ltr" value={edit.title_en} onChange={(e) => setEdit({ ...edit, title_en: e.target.value })} /></Field>
            <Field label={t('soc.studio.reward.kind')}><select className="input" value={edit.kind} onChange={(e) => setEdit({ ...edit, kind: e.target.value })}>{['certificate', 'content', 'voucher'].map((k) => <option key={k} value={k}>{t(`soc.studio.reward.kinds.${k}`)}</option>)}</select></Field>
            <Field label={t('soc.studio.reward.cost')}><input type="number" min={1} className="input" value={edit.cost_points} onChange={(e) => setEdit({ ...edit, cost_points: e.target.value })} /></Field>
            <Field label={t('soc.studio.reward.minLevel')}><input type="number" min={1} className="input" value={edit.min_level} onChange={(e) => setEdit({ ...edit, min_level: e.target.value })} /></Field>
            <Field label={t('soc.studio.reward.stock')}><input type="number" min={0} className="input" value={edit.stock} onChange={(e) => setEdit({ ...edit, stock: e.target.value })} /></Field>
            <label className="flex items-center gap-2 text-sm"><input type="checkbox" className="accent-gold-600" checked={edit.is_active} onChange={(e) => setEdit({ ...edit, is_active: e.target.checked })} />{t('soc.studio.active')}</label>
          </div>
          <div className="mt-6 flex justify-end gap-2"><Button variant="ghost" onClick={() => setEdit(null)}>{t('soc.common.cancel')}</Button><Button variant="gold" onClick={submit} disabled={!edit.title_ar || !edit.title_en}>{t('soc.studio.save')}</Button></div>
        </Modal>
      )}
    </>
  )
}

function Redemptions() {
  const { t } = useTranslation()
  const res = useGet<{ data: any[] }>('/gamification/admin/redemptions', undefined, { staleTime: 0 })
  const set = async (id: string, status: string) => { try { await api.post(`/gamification/admin/redemptions/${id}/status`, { status }); res.refetch() } catch (e) { toast(errorMessage(e), 'error') } }
  if (res.isLoading) return <Spinner />
  if (!res.data?.data.length) return <Empty text={String(t('soc.studio.red.empty'))} />
  return (
    <Card padded={false}>
      <Table head={[t('soc.studio.red.user'), t('soc.studio.red.reward'), t('soc.studio.points'), t('soc.gam.code'), '', '']}>
        {res.data.data.map((x) => (
          <tr key={x.id}><Td>{x.user}</Td><Td>{x.reward}</Td><Td>{x.points}</Td><Td><span className="font-mono text-xs">{x.code}</span></Td><Td><Badge color={x.status === 'cancelled' ? 'gray' : x.status === 'fulfilled' ? 'green' : 'amber'}>{x.status}</Badge></Td>
            <Td>{x.status === 'granted' && <div className="flex gap-1.5"><Button size="sm" variant="outline" onClick={() => set(x.id, 'fulfilled')}>{t('soc.studio.red.fulfil')}</Button><Button size="sm" variant="ghost" onClick={() => set(x.id, 'cancelled')}>{t('soc.studio.red.cancel')}</Button></div>}</Td></tr>
        ))}
      </Table>
    </Card>
  )
}

function SettingsForm({ s, onSaved }: { s: any; onSaved: () => void }) {
  const { t } = useTranslation()
  const save = useSave(onSaved)
  const [f, setF] = useState({ roles: (s.disabled_roles ?? []).join(', '), programs: (s.disabled_programs ?? []).join(', '), show_names: s.show_names, size: s.leaderboard_size })
  return (
    <Card className="max-w-2xl space-y-4">
      <Field label={t('soc.studio.set.disabledRoles')}><input className="input" dir="ltr" value={f.roles} onChange={(e) => setF({ ...f, roles: e.target.value })} /></Field>
      <Field label={t('soc.studio.set.disabledPrograms')}><input className="input" dir="ltr" value={f.programs} onChange={(e) => setF({ ...f, programs: e.target.value })} /></Field>
      <label className="flex items-center gap-2 text-sm"><input type="checkbox" className="accent-gold-600" checked={f.show_names} onChange={(e) => setF({ ...f, show_names: e.target.checked })} />{t('soc.studio.set.showNames')}</label>
      <Field label={t('soc.studio.set.boardSize')}><input type="number" min={5} max={100} className="input w-32" value={f.size} onChange={(e) => setF({ ...f, size: Number(e.target.value) })} /></Field>
      <div className="flex justify-end"><Button variant="gold" onClick={() => save(() => api.put('/gamification/admin/settings', { disabled_roles: csv(f.roles), disabled_programs: csv(f.programs), show_names: f.show_names, leaderboard_size: f.size }))}>{t('soc.studio.save')}</Button></div>
    </Card>
  )
}

function Adjust() {
  const { t } = useTranslation()
  const [q, setQ] = useState('')
  const [people, setPeople] = useState<any[]>([])
  const [user, setUser] = useState<any | null>(null)
  const [points, setPoints] = useState('')
  const [note, setNote] = useState('')
  const [busy, setBusy] = useState(false)
  const [ledger, setLedger] = useState<any | null>(null)
  useEffect(() => {
    if (q.trim().length < 2) { setPeople([]); return }
    const h = window.setTimeout(async () => { try { setPeople((await api.get('/gamification/admin/people', { params: { q } })).data.data) } catch { setPeople([]) } }, 300)
    return () => window.clearTimeout(h)
  }, [q])
  const loadLedger = async (u: any) => { try { setLedger((await api.get(`/gamification/admin/users/${u.id}/ledger`)).data) } catch { setLedger(null) } }
  const apply = async () => {
    setBusy(true)
    try { const { data } = await api.post('/gamification/admin/adjust', { user_id: user.id, points: Number(points), note }); toast(String(t('soc.studio.adjust.done', { n: data.data.balance }))); setPoints(''); setNote(''); loadLedger(user) } catch (e) { toast(errorMessage(e), 'error') } finally { setBusy(false) }
  }
  return (
    <div className="grid gap-5 lg:grid-cols-2">
      <Card className="space-y-4">
        <p className="text-sm text-slate-500">{t('soc.studio.adjust.hint')}</p>
        {user ? <div className="flex items-center justify-between rounded-xl bg-navy-50 px-4 py-2 text-sm"><span className="font-semibold">{user.name} <span className="text-xs text-slate-400">{user.email}</span></span><Button size="sm" variant="ghost" onClick={() => { setUser(null); setLedger(null) }}>×</Button></div> : (
          <Field label={t('soc.studio.adjust.user')}>
            <input className="input" value={q} onChange={(e) => setQ(e.target.value)} placeholder={String(t('soc.studio.adjust.userPh'))} />
            {people.length > 0 && <ul className="mt-1 rounded-xl border border-navy-100 bg-white shadow">{people.map((p) => <li key={p.id}><button type="button" className="block w-full px-4 py-2 text-start text-sm hover:bg-navy-50" onClick={() => { setUser(p); setPeople([]); setQ(''); loadLedger(p) }}>{p.name} <span className="text-xs text-slate-400">{p.email}</span></button></li>)}</ul>}
          </Field>
        )}
        <Field label={t('soc.studio.adjust.points')}><input type="number" className="input w-40" value={points} onChange={(e) => setPoints(e.target.value)} /></Field>
        <Field label={t('soc.studio.adjust.note')}><input className="input" value={note} onChange={(e) => setNote(e.target.value)} maxLength={250} /></Field>
        <div className="flex justify-end"><Button variant="gold" loading={busy} disabled={!user || !Number(points) || !note.trim()} onClick={apply}>{t('soc.studio.adjust.apply')}</Button></div>
      </Card>
      {ledger && (
        <Card padded={false}>
          <h3 className="px-5 pt-4 font-display text-lg font-bold text-navy-900">{t('soc.studio.adjust.ledger')} · {fmt.number(ledger.balance)}</h3>
          <ul className="max-h-96 divide-y divide-navy-50 overflow-y-auto">{ledger.data.map((r: any) => <li key={r.id} className="flex justify-between px-5 py-2.5 text-sm"><span>{t(`soc.gam.events.${r.event}`, { defaultValue: r.event })}{r.note && <span className="text-xs text-slate-400"> · {r.note}</span>}</span><span className="flex gap-3"><span className="text-xs text-slate-400">{fmt.dateTime(r.created_at)}</span><b className={r.points >= 0 ? 'text-emerald-600' : 'text-danger'}>{r.points > 0 ? '+' : ''}{r.points}</b></span></li>)}</ul>
        </Card>
      )}
    </div>
  )
}
