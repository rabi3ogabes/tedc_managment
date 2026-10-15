import { Building2, Check, ChevronDown, Lock, MapPin, Plus, Search, Sparkles, Trash2, X } from 'lucide-react'
import { useState } from 'react'
import { useTranslation } from 'react-i18next'
import { useParams } from 'react-router-dom'
import { Badge, Button, Card, CardTitle, Empty, ErrorState, Field, Modal, PageHeader, Spinner } from '@/components/ui'
import { useGet, useSend } from '@/hooks/useApi'
import { errorMessage } from '@/lib/api'
import { toast } from '@/lib/toast'
import { STATE_TONE, type Catalog, type WorkshopDetail } from './plcApi'

type Row = {
  id: string; code?: string | null; name: string; name_en?: string | null; type?: string | null; gender?: string | null; stage?: string | null; region?: string | null; district?: string | null
  has_position: boolean; role?: 'host' | 'participant'; status?: string | null; seats?: number | null; reasons?: { key: string; value?: number }[] | { from_suggestion?: boolean } | null
  distance_km?: number | null; excluded_reason?: string | null; gender_ok?: boolean | null; staff?: number; clashes?: number
}
type Suggest = { suggested: Row[]; excluded: Row[]; facts: { host: Row; host_has_position: boolean; gender_policy: string; max_distance_km: number; require_same_region: boolean; candidates: number } }

/** Step 2 of the wizard: the host, the suggested and manually chosen participating schools, seats and exclusions. */
export default function SchoolsStep() {
  const { t, i18n } = useTranslation()
  const { id } = useParams()
  const en = i18n.language === 'en'
  const detail = useGet<{ data: WorkshopDetail }>(`/admin/plc/workshops/${id}`, undefined, { staleTime: 0 })
  const catalog = useGet<{ data: Catalog }>('/admin/plc/workshops/catalog')
  const current = useGet<{ data: Row[] }>(`/admin/plc/workshops/${id}/schools`, undefined, { staleTime: 0 })
  const [maxKm, setMaxKm] = useState<number | ''>('')
  const suggest = useGet<{ data: Suggest }>(detail.data?.data.host_school ? `/admin/plc/workshops/${id}/schools/suggest` : null, { max_km: maxKm || undefined }, { staleTime: 0, retry: false })
  const [q, setQ] = useState('')
  const [gender, setGender] = useState('')
  const [region, setRegion] = useState('')
  const search = useGet<{ data: Row[] }>(q.length >= 2 || gender || region ? `/admin/plc/workshops/${id}/schools/search` : null, { q: q || undefined, gender: gender || undefined, region: region || undefined })
  const [showExcluded, setShowExcluded] = useState(false)
  const [exception, setException] = useState<{ rows: Row[] } | null>(null)
  const [reason, setReason] = useState('')
  const [hostPick, setHostPick] = useState(false)
  const inv = ['/admin/plc']
  const add = useSend<Record<string, unknown>, { data: Row[] }>('post', `/admin/plc/workshops/${id}/schools`, inv)
  const remove = useSend<{ school: string }, { data: Row[] }>('delete', (b) => `/admin/plc/workshops/${id}/schools/${b.school}`, inv)
  const seats = useSend<{ school: string; seats: number | null }, { data: Row[] }>('put', (b) => `/admin/plc/workshops/${id}/schools/${b.school}/seats`, inv)
  const exclude = useSend<{ school: string; reason: string; note?: string }, { data: Row[] }>('post', (b) => `/admin/plc/workshops/${id}/schools/${b.school}/exclude`, inv)
  const host = useSend<{ host_school_id: string }, { data: Row[] }>('put', `/admin/plc/workshops/${id}/schools/host`, inv)

  if (detail.isLoading || current.isLoading || catalog.isLoading) return <Spinner />
  if (detail.error || !detail.data) return <ErrorState error={detail.error} onRetry={() => detail.refetch()} home="/admin/communities?tab=workshops" />
  const w = detail.data.data
  const rows = current.data?.data ?? []
  const hostRow = rows.find((r) => r.role === 'host')
  const participants = rows.filter((r) => r.role !== 'host' && r.status !== 'excluded')
  const excludedRows = rows.filter((r) => r.status === 'excluded')
  const chosen = new Set(rows.filter((r) => r.status !== 'excluded').map((r) => r.id))
  const canEdit = w.can.edit || (['approved', 'scheduled'].includes(w.state) && catalog.data?.data.can.manage)
  const name = (r: Row) => (en && r.name_en ? r.name_en : r.name)
  const genderLabel = (g?: string | null) => (g ? t(`plc.schools.gender.${g}`, { defaultValue: g }) : t('plc.schools.unknown'))
  const facts = suggest.data?.data.facts

  const doAdd = async (list: Row[], fromSuggestion = false, exceptionReason?: string) => {
    try {
      await add.mutateAsync({ schools: list.map((r) => ({ id: r.id, from_suggestion: fromSuggestion })), exception_reason: exceptionReason })
      toast(String(t('plc.schools.added', { count: list.length })))
      await Promise.all([current.refetch(), suggest.refetch(), search.data ? search.refetch() : Promise.resolve()]); setException(null); setReason('')
    } catch (e: unknown) {
      const code = (e as { response?: { data?: { code?: string } } }).response?.data?.code
      if (code === 'plc_gender_exception' && catalog.data?.data.can.exceptions) setException({ rows: list })
      else toast(errorMessage(e), 'error')
    }
  }
  const act = async (fn: () => Promise<unknown>, msg?: string) => { try { await fn(); if (msg) toast(msg); await Promise.all([current.refetch(), suggest.refetch()]) } catch (e) { toast(errorMessage(e), 'error') } }
  const reasonLabel = (r: { key: string; value?: number }) => (r.key === 'distance_km' ? t('plc.schools.reasons.distance_km', { km: r.value }) : r.key === 'staff' ? t('plc.schools.reasons.staff', { count: r.value }) : r.key === 'clashes' ? t('plc.schools.reasons.clashes', { count: r.value }) : t(`plc.schools.reasons.${r.key}`, { defaultValue: r.key }))

  return (
    <>
      <PageHeader title={<span className="flex flex-wrap items-center gap-3">{en && w.title_en ? w.title_en : w.title_ar}<Badge color={STATE_TONE[w.state]}>{t(`plc.state.${w.state}`)}</Badge></span>} subtitle={t('plc.schools.subtitle')}
        actions={<Button variant="outline" to={`/admin/communities/workshops/${id}`}>{t('plc.schools.backToWorkshop')}</Button>} />
      <Stepper current={1} id={id!} />
      {!canEdit && <p className="mb-4 rounded-2xl border border-slate-200 bg-slate-50 px-4 py-3 text-sm text-slate-600">{t('plc.schools.readOnly', { state: t(`plc.state.${w.state}`) })}</p>}
      <div className="grid gap-5 lg:grid-cols-3">
        <div className="space-y-5 lg:col-span-2">
          <Card>
            <CardTitle action={canEdit && w.scenario === 'shared' ? <Button size="sm" variant="outline" onClick={() => setHostPick(true)}>{t('plc.schools.changeHost')}</Button> : undefined}>{t('plc.schools.host')}</CardTitle>
            {hostRow ? <SchoolLine r={hostRow} name={name} genderLabel={genderLabel} t={t} badge={<Badge color="gold">{t('plc.detail.host')}</Badge>} /> : <p className="text-sm text-slate-500">{t('plc.schools.noHost')}</p>}
            {w.scenario === 'internal' && <p className="mt-2 text-xs text-slate-500">{t('plc.wizard.hostFixed')}</p>}
          </Card>

          <Card>
            <CardTitle subtitle={facts ? t('plc.schools.policyLine', { policy: t(`plc.schools.policy.${facts.gender_policy}`), km: facts.max_distance_km }) : undefined}
              action={<label className="flex items-center gap-2 text-xs text-slate-500">{t('plc.schools.maxKm')}<input type="number" min={1} max={500} className="input w-20 py-1" value={maxKm} placeholder={String(facts?.max_distance_km ?? '')} onChange={(e) => setMaxKm(e.target.value ? Number(e.target.value) : '')} /></label>}>
              <span className="inline-flex items-center gap-2"><Sparkles className="size-4 text-gold-600" />{t('plc.schools.suggestions')}</span>
            </CardTitle>
            {!hostRow ? <p className="text-sm text-slate-500">{t('plc.schools.noHost')}</p> : suggest.isLoading ? <Spinner /> : suggest.error ? <ErrorState error={suggest.error} onRetry={() => suggest.refetch()} /> : (
              <>
                {facts && !facts.host_has_position && <p className="mb-3 rounded-xl bg-amber-50 px-3 py-2 text-xs text-amber-800">{t('plc.schools.noPosition')}</p>}
                {!suggest.data?.data.suggested.length ? <Empty text={String(t('plc.schools.noSuggestions'))} icon={<Building2 className="size-6" />} /> : (
                  <ul className="divide-y divide-slate-100">
                    {suggest.data.data.suggested.map((r) => (
                      <li key={r.id} className="flex flex-wrap items-center justify-between gap-3 py-2.5">
                        <div className="min-w-0 flex-1">
                          <div className="flex flex-wrap items-center gap-2"><span className="font-semibold text-navy-900">{name(r)}</span><Badge color={r.gender === 'boys' ? 'blue' : r.gender === 'girls' ? 'red' : 'gray'}>{genderLabel(r.gender)}</Badge>{r.district && <span className="text-xs text-slate-400">{r.district}</span>}</div>
                          <div className="mt-1 flex flex-wrap gap-1">{Array.isArray(r.reasons) && r.reasons.map((x, i) => <span key={i} className={`rounded-full px-2 py-0.5 text-[11px] font-semibold ${x.key === 'clashes' || x.key === 'gender_exception_needed' ? 'bg-amber-50 text-amber-800' : 'bg-slate-100 text-slate-600'}`}>{reasonLabel(x)}</span>)}</div>
                        </div>
                        {chosen.has(r.id) ? <Badge color="green"><Check className="size-3" /> {t('plc.schools.selected')}</Badge> : canEdit && <Button size="sm" variant="outline" icon={<Plus className="size-3.5" />} onClick={() => doAdd([r], true)}>{t('plc.schools.add')}</Button>}
                      </li>
                    ))}
                  </ul>
                )}
                {!!suggest.data?.data.excluded.length && (
                  <div className="mt-3 border-t border-slate-100 pt-3">
                    <button type="button" className="inline-flex items-center gap-1 text-xs font-semibold text-slate-500 hover:text-navy-900" onClick={() => setShowExcluded((v) => !v)}><ChevronDown className={`size-3.5 transition ${showExcluded ? 'rotate-180' : ''}`} />{t('plc.schools.excludedCount', { count: suggest.data.data.excluded.length })}</button>
                    {showExcluded && <ul className="mt-2 space-y-1 text-xs text-slate-600">{suggest.data.data.excluded.map((r) => <li key={r.id} className="flex items-center justify-between gap-2"><span>{name(r)}</span><span className="text-slate-400">{t(`plc.schools.exclusions.${r.excluded_reason}`)}{r.distance_km != null ? ` · ${r.distance_km} ${t('plc.schools.km')}` : ''}</span></li>)}</ul>}
                  </div>
                )}
              </>
            )}
          </Card>

          <Card>
            <CardTitle subtitle={t('plc.schools.searchHint')}>{t('plc.schools.manual')}</CardTitle>
            <div className="mb-3 flex flex-wrap gap-2">
              <div className="relative min-w-52 flex-1"><Search className="pointer-events-none absolute start-3 top-1/2 size-4 -translate-y-1/2 text-slate-400" /><input className="input ps-9" value={q} onChange={(e) => setQ(e.target.value)} placeholder={String(t('plc.wizard.hostSearch'))} /></div>
              <select className="input w-auto" value={gender} onChange={(e) => setGender(e.target.value)}><option value="">{t('plc.schools.anyGender')}</option>{['boys', 'girls', 'mixed'].map((g) => <option key={g} value={g}>{genderLabel(g)}</option>)}</select>
              <select className="input w-auto" value={region} onChange={(e) => setRegion(e.target.value)}><option value="">{t('plc.schools.anyRegion')}</option>{['doha', 'al_rayyan', 'al_wakrah', 'al_khor', 'al_shamal', 'umm_salal', 'al_daayen', 'al_shahaniya'].map((r) => <option key={r} value={r}>{t(`regions.${r}`, { defaultValue: r })}</option>)}</select>
            </div>
            {search.isLoading ? <Spinner /> : !search.data ? <p className="text-xs text-slate-400">{t('plc.schools.typeToSearch')}</p> : !search.data.data.length ? <Empty text={String(t('plc.schools.noResults'))} /> : (
              <ul className="divide-y divide-slate-100">
                {search.data.data.filter((r) => r.role !== 'host').map((r) => (
                  <li key={r.id} className="flex flex-wrap items-center justify-between gap-3 py-2">
                    <SchoolLine r={r} name={name} genderLabel={genderLabel} t={t} badge={r.gender_ok === false ? <Badge color="amber">{t('plc.schools.incompatible')}</Badge> : r.distance_km != null ? <Badge color="gray">{r.distance_km} {t('plc.schools.km')}</Badge> : undefined} />
                    {chosen.has(r.id) ? <Badge color="green"><Check className="size-3" /> {t('plc.schools.selected')}</Badge> : canEdit && <Button size="sm" variant="outline" icon={<Plus className="size-3.5" />} onClick={() => doAdd([r])}>{t('plc.schools.add')}</Button>}
                  </li>
                ))}
              </ul>
            )}
          </Card>
        </div>

        <aside className="space-y-4">
          <Card>
            <CardTitle subtitle={t('plc.schools.seatsHint')}>{t('plc.schools.participants')} <Badge color="navy">{participants.length}</Badge></CardTitle>
            {!participants.length ? <p className="text-sm text-slate-500">{t('plc.schools.noParticipants')}</p> : (
              <ul className="space-y-2">
                {participants.map((r) => (
                  <li key={r.id} className="rounded-xl border border-slate-200 px-3 py-2">
                    <div className="flex items-start justify-between gap-2">
                      <div className="min-w-0"><div className="truncate font-semibold text-navy-900">{name(r)}</div><div className="text-xs text-slate-500">{genderLabel(r.gender)}{r.distance_km != null ? ` · ${r.distance_km} ${t('plc.schools.km')}` : r.district ? ` · ${r.district}` : ''}</div></div>
                      {canEdit && <div className="flex gap-1"><button type="button" title={String(t('plc.schools.exclude'))} className="text-slate-400 hover:text-amber-600" onClick={() => act(() => exclude.mutateAsync({ school: r.id, reason: 'manual' }))}><X className="size-4" /></button><button type="button" title={String(t('plc.schools.remove'))} className="text-slate-400 hover:text-red-600" onClick={() => act(() => remove.mutateAsync({ school: r.id }))}><Trash2 className="size-4" /></button></div>}
                    </div>
                    <label className="mt-2 flex items-center gap-2 text-xs text-slate-500">{t('plc.schools.seats')}
                      <input type="number" min={1} className="input w-20 py-1" defaultValue={r.seats ?? ''} disabled={!canEdit} onBlur={(e) => { const v = e.target.value ? Number(e.target.value) : null; if (v !== (r.seats ?? null)) act(() => seats.mutateAsync({ school: r.id, seats: v })) }} />
                    </label>
                  </li>
                ))}
              </ul>
            )}
            {!!excludedRows.length && <div className="mt-3 border-t border-slate-100 pt-3 text-xs text-slate-500"><div className="mb-1 font-semibold">{t('plc.schools.excludedList')}</div><ul className="space-y-1">{excludedRows.map((r) => <li key={r.id} className="flex items-center justify-between gap-2"><span>{name(r)}</span><span className="text-slate-400">{t(`plc.schools.exclusions.${r.excluded_reason}`)}</span></li>)}</ul></div>}
          </Card>
          <div className="flex flex-col gap-2">
            <Button variant="gold" to={`/admin/communities/workshops/${id}`}>{t('plc.schools.done')}</Button>
          </div>
          <p className="text-xs text-slate-400">{t('plc.schools.advisory')}</p>
        </aside>
      </div>

      <Modal open={!!exception} onClose={() => setException(null)} title={t('plc.schools.exceptionTitle')}>
        <p className="mb-3 text-sm text-slate-600">{t('plc.schools.exceptionHint')}</p>
        <Field label={t('plc.detail.reason')}><textarea className="input min-h-20" value={reason} onChange={(e) => setReason(e.target.value)} maxLength={1000} /></Field>
        <div className="mt-4 flex justify-end gap-2"><Button variant="outline" onClick={() => setException(null)}>{t('common.cancel')}</Button><Button variant="gold" disabled={!reason.trim()} loading={add.isPending} onClick={() => exception && doAdd(exception.rows, false, reason)}>{t('plc.detail.confirm')}</Button></div>
      </Modal>
      <HostPicker open={hostPick} onClose={() => setHostPick(false)} id={id!} onPick={(school) => act(() => host.mutateAsync({ host_school_id: school }), String(t('plc.schools.hostChanged'))).then(() => { setHostPick(false); detail.refetch() })} />
    </>
  )
}

function SchoolLine({ r, name, genderLabel, t, badge }: { r: Row; name: (r: Row) => string; genderLabel: (g?: string | null) => string; t: (k: string, o?: Record<string, unknown>) => string; badge?: React.ReactNode }) {
  return (
    <div className="flex min-w-0 flex-1 items-center gap-3">
      <span className="grid size-9 shrink-0 place-items-center rounded-xl bg-navy-100 text-navy-900"><Building2 className="size-4" /></span>
      <div className="min-w-0">
        <div className="flex flex-wrap items-center gap-2"><span className="truncate font-semibold text-navy-900">{name(r)}</span>{badge}</div>
        <div className="text-xs text-slate-500">{[r.code, genderLabel(r.gender), r.type ? t(`schoolTypes.${r.type}`, { defaultValue: r.type }) : null, r.region ? t(`regions.${r.region}`, { defaultValue: r.region }) : null, r.district].filter(Boolean).join(' · ')}{r.has_position ? '' : ` · ${t('plc.schools.noCoords')}`}</div>
      </div>
    </div>
  )
}

function HostPicker({ open, onClose, id, onPick }: { open: boolean; onClose: () => void; id: string; onPick: (schoolId: string) => void }) {
  const { t, i18n } = useTranslation()
  const [q, setQ] = useState('')
  const res = useGet<{ data: Row[] }>(open && q.length >= 2 ? `/admin/plc/workshops/${id}/schools/search` : null, { q })
  return (
    <Modal open={open} onClose={onClose} title={t('plc.schools.changeHost')}>
      <div className="relative mb-3"><Search className="pointer-events-none absolute start-3 top-1/2 size-4 -translate-y-1/2 text-slate-400" /><input className="input ps-9" value={q} onChange={(e) => setQ(e.target.value)} placeholder={String(t('plc.wizard.hostSearch'))} autoFocus /></div>
      <ul className="max-h-72 divide-y divide-slate-100 overflow-auto">
        {(res.data?.data ?? []).map((r) => <li key={r.id}><button type="button" className="flex w-full items-center justify-between px-2 py-2 text-start text-sm hover:bg-slate-50" onClick={() => onPick(r.id)}><span className="font-semibold text-navy-900">{i18n.language === 'en' && r.name_en ? r.name_en : r.name}</span><span className="text-xs text-slate-400">{r.gender ?? ''} {r.region ?? ''}</span></button></li>)}
      </ul>
    </Modal>
  )
}

/** The wizard's step bar, shared by the steps. */
export function Stepper({ current, id }: { current: number; id: string }) {
  const { t } = useTranslation()
  const steps = t('plc.wizard.steps', { returnObjects: true }) as string[]
  const links = [`/admin/communities/workshops/${id}/edit`, `/admin/communities/workshops/${id}/schools`]
  return (
    <ol className="mb-6 flex flex-wrap gap-2">
      {steps.map((s, i) => {
        const unlocked = i < links.length
        const inner = <><span className={`grid size-5 place-items-center rounded-full text-[11px] ${i === current ? 'bg-gold-400 text-navy-950' : i < current ? 'bg-emerald-100 text-emerald-700' : 'bg-white text-slate-500'}`}>{i < current ? <Check className="size-3" /> : i + 1}</span>{s}{!unlocked && <span className="inline-flex items-center gap-1 text-[10px] font-normal opacity-80"><Lock className="size-3" />{t('plc.wizard.locked')}</span>}</>
        const cls = `flex items-center gap-2 rounded-full px-3 py-1.5 text-xs font-semibold ${i === current ? 'bg-navy-900 text-white' : 'bg-slate-100 text-slate-500'}`
        return <li key={s}>{unlocked && i !== current ? <a href={links[i]} className={`${cls} hover:bg-slate-200`}>{inner}</a> : <span className={cls}>{inner}</span>}</li>
      })}
    </ol>
  )
}

export { MapPin }
