import clsx from 'clsx'
import { Armchair, Building2, CalendarClock, Copy, Monitor, RefreshCw, ExternalLink, Layers, LocateFixed, MapPin, Pencil, Plus, Search, Trash2, Users2, Wrench } from 'lucide-react'
import { useEffect, useMemo, useState } from 'react'
import { useTranslation } from 'react-i18next'
import { Badge, Button, Card, Empty, Field, Modal, PageHeader, Spinner, Tabs } from '@/components/ui'
import { DataView } from '@/components/ui/DataView'
import { useGet } from '@/hooks/useApi'
import { api, errorMessage } from '@/lib/api'
import { useAuth } from '@/lib/auth'
import { fmt } from '@/lib/format'
import type { Paginated, Room } from '@/lib/types'
import { Pager } from './shared'

type Options = { layouts: { key: string; ar: string; en: string }[]; equipment: { key: string; ar: string; en: string; group: string }[]; offices: string[]; floors: string[] }
type Booking = { id: string; title: string; program?: string | null; program_id: string; starts_at: string; ends_at: string }
type Ranked = {
  room: Room; available: boolean; fits_capacity: boolean; capacity_for_layout: number; supports_layout: boolean; missing_equipment: string[]; score: number
  conflicts: { id: string; title: string; program?: string | null; starts_at: string; ends_at: string }[]
}

const statusTone = { active: 'green', maintenance: 'amber', inactive: 'gray' } as const

function useLabel() {
  const { i18n } = useTranslation()
  return (row: { ar: string; en: string }) => (i18n.language === 'en' ? row.en : row.ar)
}

/** Add / edit form: location, seating layouts with capacities and the equipment inventory. */
function RoomForm({ room, options, onClose, onSaved }: { room: Room | null; options: Options; onClose: () => void; onSaved: (deactivated?: boolean) => void }) {
  const { t } = useTranslation()
  const label = useLabel()
  const [form, setForm] = useState(() => ({
    name_ar: room?.name_ar ?? '', name_en: room?.name_en ?? '', code: room?.code ?? '', office: room?.office ?? '', building: room?.building ?? '', floor: room?.floor ?? '',
    location: room?.location ?? '', area_m2: room?.area_m2 ? String(room.area_m2) : '', layout: room?.layout ?? 'classroom', status: room?.status ?? 'active',
    is_accessible: room?.is_accessible ?? true, notes: room?.notes ?? '',
    latitude: room?.latitude != null ? String(room.latitude) : '', longitude: room?.longitude != null ? String(room.longitude) : '',
  }))
  const [locating, setLocating] = useState(false)
  const [layouts, setLayouts] = useState<Record<string, string>>(() => Object.fromEntries((room?.layouts ?? [{ key: 'classroom', capacity: 30 }]).map((l) => [l.key, String(l.capacity)])))
  const [equipment, setEquipment] = useState<Record<string, string>>(() => Object.fromEntries((room?.equipment ?? []).map((e) => [e.key, String(e.qty)])))
  const [saving, setSaving] = useState(false)
  const [error, setError] = useState<string | null>(null)
  const set = <K extends keyof typeof form>(key: K, value: (typeof form)[K]) => setForm((f) => ({ ...f, [key]: value }))

  const groups = useMemo(() => {
    const map = new Map<string, Options['equipment']>()
    options.equipment.forEach((e) => map.set(e.group, [...(map.get(e.group) ?? []), e]))
    return [...map.entries()]
  }, [options.equipment])

  const coordsValid = (form.latitude === '' && form.longitude === '') || (Number.isFinite(Number(form.latitude)) && Math.abs(Number(form.latitude)) <= 90 && Number.isFinite(Number(form.longitude)) && Math.abs(Number(form.longitude)) <= 180 && form.latitude !== '' && form.longitude !== '')
  const useMyLocation = () => {
    if (!navigator.geolocation) return setError(t('mgmt.rooms.geoUnsupported'))
    setLocating(true)
    navigator.geolocation.getCurrentPosition(
      (pos) => { setForm((f) => ({ ...f, latitude: pos.coords.latitude.toFixed(6), longitude: pos.coords.longitude.toFixed(6) })); setLocating(false) },
      () => { setError(t('mgmt.rooms.geoDenied')); setLocating(false) },
      { enableHighAccuracy: true, timeout: 12000 },
    )
  }
  const layoutKeys = Object.keys(layouts)
  const capacity = Math.max(0, ...layoutKeys.map((k) => Number(layouts[k]) || 0))

  const save = async () => {
    if (!coordsValid) return setError(t('mgmt.rooms.gpsInvalid'))
    setSaving(true)
    setError(null)
    try {
      const body = {
        ...form,
        code: form.code || null, office: form.office || null, building: form.building || null, floor: form.floor || null, location: form.location || null, notes: form.notes || null,
        area_m2: form.area_m2 ? Number(form.area_m2) : null,
        latitude: form.latitude.trim() === '' ? null : Number(form.latitude), longitude: form.longitude.trim() === '' ? null : Number(form.longitude),
        layout: layoutKeys.includes(form.layout) ? form.layout : layoutKeys[0],
        capacity: capacity || 1,
        layouts: Object.fromEntries(layoutKeys.map((k) => [k, Math.max(1, Number(layouts[k]) || 1)])),
        facilities: Object.entries(equipment).map(([key, qty]) => ({ key, qty: Math.max(1, Number(qty) || 1) })),
      }
      if (room) await api.put(`/admin/rooms/${room.id}`, body)
      else await api.post('/admin/rooms', body)
      onSaved()
    } catch (e) {
      setError(errorMessage(e))
    } finally {
      setSaving(false)
    }
  }

  return (
    <Modal open onClose={onClose} wide title={room ? t('mgmt.rooms.edit') : t('mgmt.rooms.new')}>
      <div className="space-y-6">
        <div className="grid gap-4 sm:grid-cols-2">
          <Field label={t('mgmt.rooms.nameAr')}><input dir="rtl" className="input" value={form.name_ar} onChange={(e) => set('name_ar', e.target.value)} /></Field>
          <Field label={t('mgmt.rooms.nameEn')}><input dir="ltr" className="input" value={form.name_en} onChange={(e) => set('name_en', e.target.value)} /></Field>
          <Field label={t('mgmt.rooms.office')}><input className="input" list="rooms-offices" value={form.office} onChange={(e) => set('office', e.target.value)} /><datalist id="rooms-offices">{options.offices.map((o) => <option key={o} value={o} />)}</datalist></Field>
          <Field label={t('mgmt.rooms.building')}><input className="input" value={form.building} onChange={(e) => set('building', e.target.value)} /></Field>
          <Field label={t('mgmt.rooms.floor')}><input className="input" list="rooms-floors" value={form.floor} onChange={(e) => set('floor', e.target.value)} /><datalist id="rooms-floors">{options.floors.map((o) => <option key={o} value={o} />)}</datalist></Field>
          <Field label={t('mgmt.rooms.code')}><input dir="ltr" className="input" value={form.code} onChange={(e) => set('code', e.target.value)} /></Field>
          <Field label={t('mgmt.rooms.location')} hint={t('mgmt.rooms.locationHint')} className="sm:col-span-2"><input className="input" value={form.location} onChange={(e) => set('location', e.target.value)} /></Field>
          <div className="rounded-2xl border border-navy-100 bg-ivory/60 p-4 sm:col-span-2">
            <div className="mb-3 flex flex-wrap items-center justify-between gap-2">
              <div className="flex items-center gap-2 text-sm font-bold text-navy-900"><MapPin className="size-4 text-gold-600" />{t('mgmt.rooms.gps')}</div>
              <div className="flex gap-2">
                <Button size="sm" variant="outline" type="button" icon={<LocateFixed className="size-4" />} disabled={locating} onClick={useMyLocation}>{t('mgmt.rooms.useMyLocation')}</Button>
                {coordsValid && form.latitude !== '' && <a className="inline-flex items-center gap-1.5 rounded-lg px-3 py-1.5 text-xs font-semibold text-link hover:bg-white" target="_blank" rel="noreferrer" href={`https://www.google.com/maps?q=${form.latitude},${form.longitude}`}><ExternalLink className="size-3.5" />{t('mgmt.rooms.openMap')}</a>}
              </div>
            </div>
            <div className="grid gap-3 sm:grid-cols-2">
              <Field label={t('mgmt.rooms.latitude')}><input dir="ltr" inputMode="decimal" className="input" placeholder="25.285400" value={form.latitude} onChange={(e) => set('latitude', e.target.value)} /></Field>
              <Field label={t('mgmt.rooms.longitude')}><input dir="ltr" inputMode="decimal" className="input" placeholder="51.531000" value={form.longitude} onChange={(e) => set('longitude', e.target.value)} /></Field>
            </div>
            <p className={clsx('mt-2 text-xs', coordsValid ? 'text-slate-500' : 'text-danger')}>{coordsValid ? t('mgmt.rooms.gpsHint') : t('mgmt.rooms.gpsInvalid')}</p>
          </div>
          <Field label={t('mgmt.rooms.area')}><input type="number" min={1} className="input" value={form.area_m2} onChange={(e) => set('area_m2', e.target.value)} /></Field>
          <Field label={t('mgmt.rooms.statusLabel')}>
            <select className="input" value={form.status} onChange={(e) => set('status', e.target.value as Room['status'])}>
              {(['active', 'maintenance', 'inactive'] as const).map((s) => <option key={s} value={s}>{t(`mgmt.rooms.statuses.${s}`)}</option>)}
            </select>
          </Field>
        </div>

        <section>
          <h4 className="text-sm font-bold text-navy-900">{t('mgmt.rooms.layouts')}</h4>
          <p className="mb-3 text-xs text-slate-500">{t('mgmt.rooms.layoutsHint')}</p>
          <div className="grid gap-2 sm:grid-cols-2">
            {options.layouts.map((l) => {
              const on = l.key in layouts
              return (
                <div key={l.key} className={clsx('flex items-center gap-3 rounded-xl border px-3 py-2 transition', on ? 'border-gold-400 bg-gold-100/30' : 'border-navy-100')}>
                  <label className="flex flex-1 cursor-pointer items-center gap-2 text-sm">
                    <input type="checkbox" className="accent-gold-600" checked={on} onChange={(e) => setLayouts((cur) => {
                      const next = { ...cur }
                      if (e.target.checked) next[l.key] = next[l.key] ?? '20'
                      else if (Object.keys(next).length > 1) delete next[l.key]
                      return next
                    })} />
                    {label(l)}
                  </label>
                  {on && (
                    <>
                      <input type="number" min={1} className="input !w-20 !py-1.5 text-center" aria-label={label(l)} value={layouts[l.key]} onChange={(e) => setLayouts((cur) => ({ ...cur, [l.key]: e.target.value }))} />
                      <button type="button" title={t('mgmt.rooms.layout')} onClick={() => set('layout', l.key)} className={clsx('rounded-full px-2 py-0.5 text-[11px] font-bold', form.layout === l.key ? 'bg-navy-900 text-white' : 'bg-slate-100 text-slate-500 hover:bg-navy-100')}>★</button>
                    </>
                  )}
                </div>
              )
            })}
          </div>
          <p className="mt-2 text-xs text-slate-500">{t('mgmt.rooms.capacity')}: <b className="text-navy-900">{fmt.number(capacity)}</b></p>
        </section>

        <section>
          <h4 className="text-sm font-bold text-navy-900">{t('mgmt.rooms.equipment')}</h4>
          <p className="mb-3 text-xs text-slate-500">{t('mgmt.rooms.equipmentHint')}</p>
          <div className="space-y-3">
            {groups.map(([group, items]) => (
              <div key={group}>
                <div className="mb-1.5 text-[11px] font-bold uppercase tracking-wide text-slate-400">{t(`mgmt.rooms.groups.${group}`, { defaultValue: group })}</div>
                <div className="grid gap-2 sm:grid-cols-2 lg:grid-cols-3">
                  {items.map((e) => {
                    const on = e.key in equipment
                    return (
                      <div key={e.key} className={clsx('flex items-center gap-2 rounded-xl border px-3 py-1.5 text-sm transition', on ? 'border-gold-400 bg-gold-100/30' : 'border-navy-100')}>
                        <label className="flex flex-1 cursor-pointer items-center gap-2">
                          <input type="checkbox" className="accent-gold-600" checked={on} onChange={(ev) => setEquipment((cur) => {
                            const next = { ...cur }
                            if (ev.target.checked) next[e.key] = '1'
                            else delete next[e.key]
                            return next
                          })} />
                          <span className="truncate">{label(e)}</span>
                        </label>
                        {on && <input type="number" min={1} className="input !w-16 !py-1 text-center" aria-label={label(e)} value={equipment[e.key]} onChange={(ev) => setEquipment((cur) => ({ ...cur, [e.key]: ev.target.value }))} />}
                      </div>
                    )
                  })}
                </div>
              </div>
            ))}
          </div>
        </section>

        <label className="flex items-center gap-2 text-sm text-navy-800"><input type="checkbox" className="size-4 accent-gold-600" checked={form.is_accessible} onChange={(e) => set('is_accessible', e.target.checked)} />{t('mgmt.rooms.accessible')}</label>
        <Field label={t('mgmt.rooms.notes')}><textarea rows={2} className="input" value={form.notes} onChange={(e) => set('notes', e.target.value)} /></Field>

        {error && <div className="rounded-xl bg-red-50 p-3 text-sm text-danger">{error}</div>}
        <div className="flex justify-end gap-2">
          <Button variant="ghost" onClick={onClose}>{t('mgmt.common.cancel')}</Button>
          <Button loading={saving} disabled={!form.name_ar.trim() || !form.name_en.trim()} onClick={save}>{saving ? t('mgmt.common.saving') : t('mgmt.common.save')}</Button>
        </div>
      </div>
    </Modal>
  )
}

/** The secret address of the screen at the room's door: copy it, open it, or replace it. */
function ScreenLinkModal({ room, onClose }: { room: Room; onClose: () => void }) {
  const { t } = useTranslation()
  const [link, setLink] = useState<{ token: string; url: string } | null>(null)
  const [copied, setCopied] = useState(false)
  const [busy, setBusy] = useState(false)
  const [error, setError] = useState<string | null>(null)

  const load = async (regenerate = false) => {
    setBusy(true)
    setError(null)
    try { setLink((await api.post<{ data: { token: string; url: string } }>(`/admin/rooms/${room.id}/screen-link`, null, { params: regenerate ? { regenerate: 1 } : undefined })).data.data) } catch (e) { setError(errorMessage(e)) } finally { setBusy(false) }
  }
  useEffect(() => { void load() }, [])  // eslint-disable-line react-hooks/exhaustive-deps

  return (
    <Modal open onClose={onClose} title={`${t('studio.screen.link')} · ${room.name}`}>
      <p className="mb-4 text-sm text-slate-600">{t('studio.screen.linkHint')}</p>
      {link && (
        <div className="space-y-3">
          <div className="flex items-center gap-2 rounded-xl border border-navy-100 bg-ivory p-2"><input readOnly dir="ltr" className="min-w-0 flex-1 bg-transparent px-2 font-mono text-xs text-navy-900 outline-none" value={link.url} onFocus={(e) => e.currentTarget.select()} />
            <Button size="sm" variant="gold" icon={<Copy className="size-4" />} onClick={() => { void navigator.clipboard.writeText(link.url); setCopied(true); setTimeout(() => setCopied(false), 2000) }}>{copied ? t('studio.screen.copied') : t('studio.screen.copy')}</Button></div>
          <div className="flex flex-wrap items-center justify-between gap-2">
            <Button variant="primary" icon={<Monitor className="size-4" />} onClick={() => window.open(link.url, '_blank', 'noopener')}>{t('studio.screen.open')}</Button>
            <Button variant="ghost" loading={busy} icon={<RefreshCw className="size-4" />} onClick={() => window.confirm(t('studio.screen.regenerateHint')) && load(true)}>{t('studio.screen.regenerate')}</Button>
          </div>
        </div>
      )}
      {error && <p className="mt-3 text-sm text-danger">{error}</p>}
    </Modal>
  )
}

function ScheduleModal({ room, onClose }: { room: Room; onClose: () => void }) {
  const { t } = useTranslation()
  const [{ from, to }] = useState(() => ({ from: new Date().toISOString().slice(0, 10), to: new Date(Date.now() + 60 * 86_400_000).toISOString().slice(0, 10) }))
  const { data, isLoading } = useGet<{ data: Booking[] }>(`/admin/rooms/${room.id}/schedule`, { from, to })
  return (
    <Modal open onClose={onClose} title={`${t('mgmt.rooms.scheduleTitle')} · ${room.name}`}>
      {isLoading ? <Spinner /> : !data?.data.length ? <Empty text={t('mgmt.rooms.noBookings')} /> : (
        <ul className="space-y-2">
          {data.data.map((b) => (
            <li key={b.id} className="flex items-center justify-between gap-3 rounded-xl border border-navy-100 px-3 py-2 text-sm">
              <div className="min-w-0"><div className="truncate font-semibold text-navy-900">{b.program ?? b.title}</div><div className="truncate text-xs text-slate-500">{b.title}</div></div>
              <div className="shrink-0 text-end text-xs text-slate-500"><div>{fmt.date(b.starts_at, { weekday: 'short', day: 'numeric', month: 'short' })}</div><div dir="ltr">{fmt.time(b.starts_at)} – {fmt.time(b.ends_at)}</div></div>
            </li>
          ))}
        </ul>
      )}
    </Modal>
  )
}

/** Smart finder: free rooms for a slot ranked by capacity, layout and equipment fit. */
function Finder({ options }: { options: Options }) {
  const { t } = useTranslation()
  const label = useLabel()
  const [q, setQ] = useState({ starts_at: '', ends_at: '', capacity: '', layout: '', accessible: false })
  const [equipment, setEquipment] = useState<string[]>([])
  const [results, setResults] = useState<Ranked[] | null>(null)
  const [loading, setLoading] = useState(false)
  const [error, setError] = useState<string | null>(null)
  const names = Object.fromEntries(options.equipment.map((e) => [e.key, label(e)]))

  const search = async () => {
    if (!q.starts_at || !q.ends_at) { setError(t('mgmt.rooms.pickTime')); return }
    setLoading(true)
    setError(null)
    try {
      const res = await api.get<{ data: Ranked[] }>('/admin/rooms/availability', { params: {
        starts_at: q.starts_at.replace('T', ' '), ends_at: q.ends_at.replace('T', ' '), capacity: q.capacity || undefined, layout: q.layout || undefined,
        equipment: equipment.length ? equipment : undefined, accessible: q.accessible ? 1 : undefined,
      } })
      setResults(res.data.data)
    } catch (e) {
      setError(errorMessage(e))
    } finally {
      setLoading(false)
    }
  }

  return (
    <Card>
      <h3 className="text-lg font-bold text-navy-900">{t('mgmt.rooms.findTitle')}</h3>
      <p className="mt-1 text-sm text-slate-500">{t('mgmt.rooms.findHint')}</p>
      <div className="mt-5 grid gap-4 md:grid-cols-4">
        <Field label={t('mgmt.rooms.startsAt')}><input type="datetime-local" className="input" value={q.starts_at} onChange={(e) => setQ({ ...q, starts_at: e.target.value })} /></Field>
        <Field label={t('mgmt.rooms.endsAt')}><input type="datetime-local" className="input" value={q.ends_at} onChange={(e) => setQ({ ...q, ends_at: e.target.value })} /></Field>
        <Field label={t('mgmt.rooms.needCapacity')}><input type="number" min={1} className="input" value={q.capacity} onChange={(e) => setQ({ ...q, capacity: e.target.value })} /></Field>
        <Field label={t('mgmt.rooms.needLayout')}>
          <select className="input" value={q.layout} onChange={(e) => setQ({ ...q, layout: e.target.value })}>
            <option value="">—</option>
            {options.layouts.map((l) => <option key={l.key} value={l.key}>{label(l)}</option>)}
          </select>
        </Field>
      </div>
      <div className="mt-4">
        <div className="label">{t('mgmt.rooms.needEquipment')}</div>
        <div className="flex flex-wrap gap-1.5">
          {options.equipment.map((e) => {
            const on = equipment.includes(e.key)
            return <button key={e.key} type="button" aria-pressed={on} onClick={() => setEquipment(on ? equipment.filter((x) => x !== e.key) : [...equipment, e.key])} className={clsx('rounded-full border px-3 py-1 text-xs font-semibold transition', on ? 'border-navy-900 bg-navy-900 text-white' : 'border-navy-100 bg-white text-slate-600 hover:border-gold-400')}>{label(e)}</button>
          })}
        </div>
      </div>
      <div className="mt-4 flex flex-wrap items-center justify-between gap-3">
        <label className="flex items-center gap-2 text-sm text-navy-800"><input type="checkbox" className="size-4 accent-gold-600" checked={q.accessible} onChange={(e) => setQ({ ...q, accessible: e.target.checked })} />{t('mgmt.rooms.accessibleOnly')}</label>
        <Button variant="gold" loading={loading} icon={<Search className="size-4" />} onClick={search}>{t('mgmt.rooms.searchButton')}</Button>
      </div>
      {error && <div className="mt-4 rounded-xl bg-red-50 p-3 text-sm text-danger">{error}</div>}

      {results && (
        <div className="mt-6 grid gap-3 lg:grid-cols-2">
          {results.length === 0 && <div className="lg:col-span-2"><Empty text={t('mgmt.rooms.empty')} /></div>}
          {results.map((r, i) => (
            <article key={r.room.id} className={clsx('rounded-2xl border p-4', r.available && r.fits_capacity ? 'border-emerald-200 bg-emerald-50/40' : 'border-navy-100 bg-white', !r.available && 'opacity-80')}>
              <div className="flex items-start justify-between gap-3">
                <div className="min-w-0">
                  <div className="flex items-center gap-2 font-bold text-navy-900">{r.room.name}{i === 0 && r.available && r.fits_capacity && <Badge color="gold">{t('mgmt.rooms.bestMatch')}</Badge>}</div>
                  <div className="text-xs text-slate-500">{[r.room.office, r.room.building, r.room.floor && `${t('mgmt.rooms.floor')} ${r.room.floor}`].filter(Boolean).join(' · ')}</div>
                </div>
                <div className="text-end"><div className="text-2xl font-bold text-navy-900">{r.score}</div><div className="text-[11px] text-slate-400">{t('mgmt.rooms.match')}</div></div>
              </div>
              <div className="mt-3 flex flex-wrap gap-1.5">
                <Badge color={r.available ? 'green' : 'red'}>{r.available ? t('mgmt.rooms.available') : t('mgmt.rooms.busy')}</Badge>
                <Badge color={r.fits_capacity ? 'green' : 'amber'}>{r.fits_capacity ? t('mgmt.rooms.fits') : t('mgmt.rooms.tooSmall')} · {fmt.number(r.capacity_for_layout)} {t('mgmt.rooms.seats')}</Badge>
                {r.missing_equipment.length > 0 && <Badge color="amber">{t('mgmt.rooms.missing')}: {r.missing_equipment.map((k) => names[k] ?? k).join('، ')}</Badge>}
              </div>
              {r.conflicts.length > 0 && (
                <ul className="mt-3 space-y-1 border-t border-dashed border-navy-100 pt-2 text-xs text-slate-500">
                  <li className="font-semibold">{t('mgmt.rooms.conflicts')}</li>
                  {r.conflicts.map((c) => <li key={c.id}>{c.program ?? c.title} · <span dir="ltr">{fmt.time(c.starts_at)}–{fmt.time(c.ends_at)}</span></li>)}
                </ul>
              )}
            </article>
          ))}
        </div>
      )}
    </Card>
  )
}

export default function Rooms() {
  const { t } = useTranslation()
  const { can } = useAuth()
  const canManage = can('rooms.manage')
  const [tab, setTab] = useState<'list' | 'find'>('list')
  const [filters, setFilters] = useState({ q: '', office: '', floor: '', status: '', layout: '', min_capacity: '' })
  const [page, setPage] = useState(1)
  const [editing, setEditing] = useState<Room | null | 'new'>(null)
  const [schedule, setSchedule] = useState<Room | null>(null)
  const [screenLink, setScreenLink] = useState<Room | null>(null)
  const [notice, setNotice] = useState<string | null>(null)
  const options = useGet<{ data: Options }>('/admin/rooms/options')
  const params = Object.fromEntries(Object.entries(filters).filter(([, v]) => v))
  const rooms = useGet<Paginated<Room>>('/admin/rooms', { ...params, page })
  const opts = options.data?.data
  const label = useLabel()
  const setFilter = (k: keyof typeof filters, v: string) => { setFilters((f) => ({ ...f, [k]: v })); setPage(1) }

  const remove = async (room: Room) => {
    if (!window.confirm(t('mgmt.common.confirmDelete'))) return
    try {
      const res = await api.delete(`/admin/rooms/${room.id}`)
      setNotice(res.data?.meta?.deactivated ? t('mgmt.rooms.deactivatedMsg') : null)
      rooms.refetch()
      options.refetch()
    } catch (e) {
      setNotice(errorMessage(e))
    }
  }

  return (
    <>
      <PageHeader title={t('mgmt.rooms.title')} subtitle={t('mgmt.rooms.subtitle')} actions={canManage && opts && <Button variant="gold" icon={<Plus className="size-4" />} onClick={() => setEditing('new')}>{t('mgmt.rooms.new')}</Button>} />
      <Tabs tabs={[{ id: 'list', label: t('mgmt.rooms.tabs.list') }, { id: 'find', label: t('mgmt.rooms.tabs.find') }]} value={tab} onChange={setTab} />
      {notice && <div className="mb-4 rounded-xl bg-amber-50 p-3 text-sm text-amber-800">{notice}</div>}

      {tab === 'find' ? (opts ? <Finder options={opts} /> : <Spinner />) : (
        <Card padded={false}>
          <div className="grid gap-3 border-b border-navy-100 p-4 sm:grid-cols-2 xl:grid-cols-6">
            <div className="relative xl:col-span-2"><Search className="absolute start-3 top-3 size-4 text-slate-400" /><input className="input ps-9" placeholder={t('mgmt.common.search')} value={filters.q} onChange={(e) => setFilter('q', e.target.value)} /></div>
            <select className="input" aria-label={t('mgmt.rooms.office')} value={filters.office} onChange={(e) => setFilter('office', e.target.value)}><option value="">{t('mgmt.rooms.officeFilter')}</option>{opts?.offices.map((o) => <option key={o} value={o}>{o}</option>)}</select>
            <select className="input" aria-label={t('mgmt.rooms.floor')} value={filters.floor} onChange={(e) => setFilter('floor', e.target.value)}><option value="">{t('mgmt.rooms.floorFilter')}</option>{opts?.floors.map((o) => <option key={o} value={o}>{o}</option>)}</select>
            <select className="input" aria-label={t('mgmt.rooms.layout')} value={filters.layout} onChange={(e) => setFilter('layout', e.target.value)}><option value="">{t('mgmt.rooms.layoutFilter')}</option>{opts?.layouts.map((l) => <option key={l.key} value={l.key}>{label(l)}</option>)}</select>
            <select className="input" aria-label={t('mgmt.rooms.statusLabel')} value={filters.status} onChange={(e) => setFilter('status', e.target.value)}><option value="">{t('mgmt.rooms.statusFilter')}</option>{(['active', 'maintenance', 'inactive'] as const).map((s) => <option key={s} value={s}>{t(`mgmt.rooms.statuses.${s}`)}</option>)}</select>
          </div>
          <DataView id="admin.rooms" rows={rooms.data?.data} total={rooms.data?.meta?.total} loading={rooms.isLoading} rowKey={(r) => r.id} defaultMode="cards" emptyText={t('mgmt.rooms.empty')} columns={[
            { key: 'media', header: '', role: 'media', hideInTable: true, cell: (r) => <span className="grid size-10 place-items-center rounded-xl bg-navy-900 text-gold-300">{r.status === 'maintenance' ? <Wrench className="size-5" /> : <Building2 className="size-5" />}</span> },
            { key: 'name', header: t('mgmt.common.name'), role: 'title', cell: (r) => <div><div>{r.name}</div>{r.code && <div className="font-mono text-[11px] font-normal text-slate-400" dir="ltr">{r.code}</div>}</div> },
            { key: 'where', header: t('mgmt.rooms.office'), role: 'subtitle', cell: (r) => [r.office, r.building, r.floor && `${t('mgmt.rooms.floor')} ${r.floor}`].filter(Boolean).join(' · ') || '—' },
            { key: 'capacity', header: t('mgmt.rooms.capacity'), cell: (r) => <span className="inline-flex items-center gap-1.5"><Users2 className="size-4 text-slate-400" />{fmt.number(r.capacity)}</span> },
            { key: 'layouts', header: t('mgmt.rooms.layouts'), cell: (r) => <div className="flex max-w-xs flex-wrap gap-1">{r.layouts.map((l) => <span key={l.key} className="inline-flex items-center gap-1 rounded-full bg-navy-100/70 px-2 py-0.5 text-[11px] font-semibold text-navy-800"><Armchair className="size-3" />{l.label} {fmt.number(l.capacity)}</span>)}</div> },
            { key: 'equipment', header: t('mgmt.rooms.equipment'), hideInCards: false, cell: (r) => <div className="flex max-w-sm flex-wrap gap-1">{r.equipment.slice(0, 6).map((e) => <span key={e.key} className="rounded-full bg-gold-100/60 px-2 py-0.5 text-[11px] font-semibold text-gold-700">{e.label}{e.qty > 1 && ` ×${fmt.number(e.qty)}`}</span>)}{r.equipment.length > 6 && <span className="text-[11px] text-slate-400">+{r.equipment.length - 6}</span>}</div> },
            { key: 'usage', header: t('mgmt.rooms.sessions'), cell: (r) => <span className="inline-flex items-center gap-1.5 text-slate-600"><Layers className="size-4 text-slate-400" />{fmt.number(r.sessions_count ?? 0)} <span className="text-xs text-slate-400">({fmt.number(r.upcoming_sessions_count ?? 0)} {t('mgmt.rooms.upcoming')})</span></span> },
            { key: 'status', header: t('mgmt.common.status'), role: 'badge', cell: (r) => <Badge color={statusTone[r.status]}>{t(`mgmt.rooms.statuses.${r.status}`)}</Badge> },
            { key: 'actions', header: '', role: 'actions', cell: (r) => (
              <div className="flex flex-wrap gap-1">
                <Button size="sm" variant="outline" icon={<CalendarClock className="size-4" />} onClick={() => setSchedule(r)}>{t('mgmt.rooms.schedule')}</Button>
                <Button size="sm" variant="gold" icon={<Monitor className="size-4" />} onClick={() => window.open(`/admin/rooms/${r.id}/screen`, '_blank', 'noopener')}>{t('studio.screen.open')}</Button>
                {canManage && <Button size="sm" variant="ghost" aria-label={t('studio.screen.link')} title={t('studio.screen.link')} icon={<Copy className="size-4" />} onClick={() => setScreenLink(r)} />}
                {canManage && <Button size="sm" variant="ghost" icon={<Pencil className="size-4" />} onClick={() => setEditing(r)}>{t('mgmt.common.edit')}</Button>}
                {canManage && <Button size="sm" variant="ghost" icon={<Trash2 className="size-4" />} onClick={() => remove(r)} aria-label={t('mgmt.common.delete')} />}
              </div>
            ) },
          ]} />
          <Pager page={page} last={rooms.data?.meta?.last_page} onChange={setPage} />
        </Card>
      )}

      {editing && opts && <RoomForm room={editing === 'new' ? null : editing} options={opts} onClose={() => setEditing(null)} onSaved={() => { setEditing(null); rooms.refetch(); options.refetch() }} />}
      {schedule && <ScheduleModal room={schedule} onClose={() => setSchedule(null)} />}
      {screenLink && <ScreenLinkModal room={screenLink} onClose={() => setScreenLink(null)} />}
    </>
  )
}
