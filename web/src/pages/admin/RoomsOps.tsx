import clsx from 'clsx'
import { Plus, Printer } from 'lucide-react'
import { useState } from 'react'
import { useTranslation } from 'react-i18next'
import { Badge, Button, Card, Empty, Field, Modal, PageHeader, Spinner, Tabs } from '@/components/ui'
import { useGet } from '@/hooks/useApi'
import { api, errorMessage } from '@/lib/api'
import { useAuth } from '@/lib/auth'
import { fmt } from '@/lib/format'
import { toast } from '@/lib/toast'

type Tab = 'calendar' | 'places' | 'seating'
type Room = { id: string; name_ar: string; name_en: string; capacity: number; effective_capacity?: number }

/** Rooms in use: a booking calendar with sessions and bookings together, places → buildings → rooms, and the seating designer. */
export default function RoomsOps() {
  const { t } = useTranslation()
  const { can } = useAuth()
  const tabs: { id: Tab; show: boolean }[] = [{ id: 'calendar', show: true }, { id: 'places', show: can('places.manage') || can('programs.view') }, { id: 'seating', show: can('seating.manage') }]
  const visible = tabs.filter((x) => x.show)
  const [tab, setTab] = useState<Tab>('calendar')
  return (
    <div className="space-y-6 pb-6">
      <PageHeader title={t('ops.rooms.title')} />
      <Tabs<Tab> value={tab} onChange={setTab} tabs={visible.map((x) => ({ id: x.id, label: t(`ops.rooms.tabs.${x.id}`) }))} />
      {tab === 'calendar' && <CalendarBooking />}
      {tab === 'places' && <Places />}
      {tab === 'seating' && <Seating />}
    </div>
  )
}

const range = (view: string) => {
  const d = new Date(); const from = new Date(d); const to = new Date(d)
  if (view === 'week') { from.setDate(d.getDate() - d.getDay()); to.setDate(from.getDate() + 6) } else if (view === 'month') { from.setDate(1); to.setMonth(d.getMonth() + 1, 0) }
  const f = (x: Date) => `${x.getFullYear()}-${String(x.getMonth() + 1).padStart(2, '0')}-${String(x.getDate()).padStart(2, '0')}`
  return { from: f(from), to: f(to) }
}

function CalendarBooking() {
  const { t, i18n } = useTranslation()
  const ar = i18n.language === 'ar'
  const { can } = useAuth()
  const [view, setView] = useState<'day' | 'week' | 'month'>('week')
  const r = range(view)
  const cal = useGet<{ data: { events: { type: string; id: string; room_id: string; title: string | null; purpose?: string; starts_at: string; ends_at: string }[]; occupancy: { booked_hours: number; available_hours: number } } }>('/admin/rooms/calendar', r, { staleTime: 0 })
  const rooms = useGet<{ data: { rooms: Room[] } }>('/admin/lookups', undefined, { staleTime: 60_000 })
  const [open, setOpen] = useState(false)
  const [f, setF] = useState({ room_id: '', purpose: 'meeting', title: '', starts_at: '', ends_at: '', attendees: '' })
  const [conflict, setConflict] = useState<{ type: string; title: string; supervisor: string | null; starts_at: string; ends_at: string }[] | null>(null)
  const name = (id: string) => { const x = rooms.data?.data.rooms.find((y) => y.id === id); return x ? (ar ? x.name_ar : x.name_en) : '' }
  const book = async () => {
    setConflict(null)
    try { await api.post('/admin/room-bookings', { ...f, attendees: f.attendees ? Number(f.attendees) : undefined }); toast(t('ops.rooms.booked')); setOpen(false); await cal.refetch() } catch (e) {
      const d = (e as { response?: { data?: { details?: { occupants?: typeof conflict } } } }).response?.data?.details?.occupants
      if (d) setConflict(d); else toast(errorMessage(e), 'error')
    }
  }
  const cancel = async (id: string) => { try { await api.delete(`/admin/room-bookings/${id}`); await cal.refetch() } catch (e) { toast(errorMessage(e), 'error') } }
  if (cal.isLoading || !cal.data) return <Spinner />
  const o = cal.data.data.occupancy
  return (
    <div className="space-y-4">
      <div className="flex flex-wrap items-center justify-between gap-3">
        <div className="flex rounded-xl border border-navy-100 bg-white p-0.5">{(['day', 'week', 'month'] as const).map((v) => <button key={v} type="button" aria-pressed={view === v} onClick={() => setView(v)} className={clsx('rounded-lg px-3 py-1.5 text-sm font-bold', view === v ? 'bg-navy-900 text-white' : 'text-slate-500')}>{t(`ops.rooms.${v}`)}</button>)}</div>
        <span className="text-sm text-slate-500">{t('ops.rooms.occupancy', { booked: o.booked_hours, available: o.available_hours })}</span>
        {can('rooms.book') && <Button variant="gold" icon={<Plus className="size-4" />} onClick={() => setOpen(true)}>{t('ops.rooms.book')}</Button>}
      </div>
      <Card padded={false}>{cal.data.data.events.length === 0 ? <Empty text={t('ops.rooms.empty')} /> : (
        <ul className="divide-y divide-navy-50">{cal.data.data.events.map((e) => (
          <li key={`${e.type}-${e.id}`} className="flex flex-wrap items-center gap-3 px-5 py-3 text-sm">
            <Badge color={e.type === 'session' ? 'navy' : 'gold'}>{t(`ops.rooms.${e.type}`)}</Badge><span className="min-w-0 flex-1 font-semibold text-navy-900">{e.title}</span><span className="text-slate-500">{name(e.room_id)}</span><span className="tabular-nums text-xs text-slate-500">{fmt.dateTime(e.starts_at)} – {fmt.time(e.ends_at)}</span>
            {e.type === 'booking' && can('rooms.book') && <button type="button" className="text-xs font-bold text-danger" onClick={() => void cancel(e.id)}>{t('ops.rooms.cancel')}</button>}
          </li>))}</ul>
      )}</Card>
      <Modal open={open} onClose={() => setOpen(false)} title={t('ops.rooms.book')}>
        <div className="space-y-4">
          <Field label={t('ops.devices.room')}><select className="input" value={f.room_id} onChange={(e) => setF({ ...f, room_id: e.target.value })}><option value="" />{rooms.data?.data.rooms.map((x) => <option key={x.id} value={x.id}>{ar ? x.name_ar : x.name_en}</option>)}</select></Field>
          <div className="grid gap-4 sm:grid-cols-2"><Field label={t('ops.rooms.purpose')}><select className="input" value={f.purpose} onChange={(e) => setF({ ...f, purpose: e.target.value })}>{['meeting', 'exam', 'event', 'maintenance', 'other'].map((p) => <option key={p} value={p}>{t(`ops.rooms.purposes.${p}`)}</option>)}</select></Field><Field label={t('ops.rooms.attendees')}><input type="number" min={1} className="input" value={f.attendees} onChange={(e) => setF({ ...f, attendees: e.target.value })} /></Field></div>
          <Field label={t('ops.rooms.titleField')}><input className="input" value={f.title} onChange={(e) => setF({ ...f, title: e.target.value })} /></Field>
          <div className="grid gap-4 sm:grid-cols-2"><Field label={t('ops.rooms.from')}><input type="datetime-local" className="input" value={f.starts_at} onChange={(e) => setF({ ...f, starts_at: e.target.value })} /></Field><Field label={t('ops.rooms.to')}><input type="datetime-local" className="input" value={f.ends_at} onChange={(e) => setF({ ...f, ends_at: e.target.value })} /></Field></div>
          {conflict && <div className="rounded-xl bg-red-50 p-3 text-sm text-danger"><b>{t('ops.rooms.conflict')}</b>{conflict.map((c, i) => <div key={i} className="mt-1">{c.title} — {t('ops.rooms.heldBy')}: {c.supervisor ?? '—'} · {fmt.dateTime(c.starts_at)} – {fmt.time(c.ends_at)}</div>)}</div>}
          <Button variant="gold" disabled={!f.room_id || !f.title.trim() || !f.starts_at || !f.ends_at} onClick={() => void book()}>{t('ops.rooms.book')}</Button>
        </div>
      </Modal>
    </div>
  )
}

type Place = { id: string; name_ar: string; name_en: string; capacity_limit: number | null; buildings: { id: string; name_ar: string; name_en: string; capacity_limit: number | null }[] }

function Places() {
  const { t, i18n } = useTranslation()
  const ar = i18n.language === 'ar'
  const { can } = useAuth()
  const list = useGet<{ data: Place[] }>('/admin/places', undefined, { staleTime: 0 })
  const [dlg, setDlg] = useState<{ kind: 'place' | 'building'; placeId?: string } | null>(null)
  const [f, setF] = useState({ name_ar: '', name_en: '', capacity_limit: '' })
  const save = async () => {
    if (!dlg) return
    const body = { name_ar: f.name_ar, name_en: f.name_en, capacity_limit: f.capacity_limit ? Number(f.capacity_limit) : null, ...(dlg.kind === 'building' ? { place_id: dlg.placeId } : {}) }
    try { await api.post(dlg.kind === 'place' ? '/admin/places' : '/admin/buildings', body); toast(t('ops.rooms.saved')); setDlg(null); setF({ name_ar: '', name_en: '', capacity_limit: '' }); await list.refetch() } catch (e) { toast(errorMessage(e), 'error') }
  }
  if (list.isLoading || !list.data) return <Spinner />
  return (
    <div className="space-y-4">
      {can('places.manage') && <div className="flex justify-end"><Button variant="gold" icon={<Plus className="size-4" />} onClick={() => setDlg({ kind: 'place' })}>{t('ops.rooms.newPlace')}</Button></div>}
      {list.data.data.length === 0 ? <Card><Empty text={t('ops.rooms.empty')} /></Card> : list.data.data.map((p) => (
        <Card key={p.id} className="space-y-3">
          <div className="flex items-center justify-between"><h3 className="font-bold text-navy-900">{ar ? p.name_ar : p.name_en}</h3>{p.capacity_limit && <Badge color="gold">{t('ops.rooms.limit')}: {p.capacity_limit}</Badge>}</div>
          <ul className="space-y-1.5">{p.buildings.map((b) => <li key={b.id} className="flex items-center justify-between rounded-xl bg-ivory px-3 py-2 text-sm"><span className="font-semibold">{ar ? b.name_ar : b.name_en}</span>{b.capacity_limit && <span className="text-xs text-slate-500">{t('ops.rooms.limit')}: {b.capacity_limit}</span>}</li>)}</ul>
          {can('places.manage') && <Button size="sm" variant="outline" icon={<Plus className="size-4" />} onClick={() => setDlg({ kind: 'building', placeId: p.id })}>{t('ops.rooms.newBuilding')}</Button>}
        </Card>
      ))}
      <Modal open={!!dlg} onClose={() => setDlg(null)} title={dlg?.kind === 'place' ? t('ops.rooms.newPlace') : t('ops.rooms.newBuilding')}>
        <div className="space-y-4"><Field label={t('ops.rooms.nameAr')}><input className="input" value={f.name_ar} onChange={(e) => setF({ ...f, name_ar: e.target.value })} /></Field><Field label={t('ops.rooms.nameEn')}><input className="input" dir="ltr" value={f.name_en} onChange={(e) => setF({ ...f, name_en: e.target.value })} /></Field><Field label={t('ops.rooms.limit')}><input type="number" min={1} className="input" value={f.capacity_limit} onChange={(e) => setF({ ...f, capacity_limit: e.target.value })} /></Field><Button variant="gold" disabled={!f.name_ar.trim() || !f.name_en.trim()} onClick={() => void save()}>{t('ops.rooms.save')}</Button></div>
      </Modal>
    </div>
  )
}

type Seat = { rows: number; cols: number; blocked: string[] }
function Seating() {
  const { t, i18n } = useTranslation()
  const ar = i18n.language === 'ar'
  const rooms = useGet<{ data: { rooms: Room[] } }>('/admin/lookups', undefined, { staleTime: 60_000 })
  const [roomId, setRoomId] = useState('')
  const [sessionId, setSessionId] = useState('')
  const win = { from: new Date(Date.now() - 30 * 864e5).toISOString().slice(0, 10), to: new Date(Date.now() + 90 * 864e5).toISOString().slice(0, 10) }
  const sessions = useGet<{ data: { id: string; title: string; program: string | null; starts_at: string }[] }>(roomId ? `/admin/rooms/${roomId}/schedule` : null, win, { staleTime: 30_000 })
  const plan = useGet<{ data: { room: { capacity: number }; layout: Seat; seats: Record<string, { name: string | null }> } }>(roomId && sessionId ? `/admin/rooms/${roomId}/seating` : null, { session: sessionId }, { staleTime: 0 })
  const [layout, setLayout] = useState<Seat | null>(null)
  const [mode, setMode] = useState('alphabetical')
  const cur = layout ?? plan.data?.data.layout ?? { rows: 4, cols: 6, blocked: [] }
  const seats = plan.data?.data.seats ?? {}
  const toggle = (k: string) => setLayout({ ...cur, blocked: cur.blocked.includes(k) ? cur.blocked.filter((x) => x !== k) : [...cur.blocked, k] })
  const save = async () => { try { await api.put(`/admin/rooms/${roomId}/seating`, { session_id: sessionId, layout: cur }); toast(t('ops.rooms.saved')); setLayout(null); await plan.refetch() } catch (e) { toast(errorMessage(e), 'error') } }
  const auto = async () => { try { await api.put(`/admin/rooms/${roomId}/seating`, { session_id: sessionId, layout: cur }); await api.post(`/admin/rooms/${roomId}/seating/auto`, { session_id: sessionId, mode }); setLayout(null); await plan.refetch() } catch (e) { toast(errorMessage(e), 'error') } }
  return (
    <div className="space-y-4">
      <div className="flex flex-wrap items-end gap-3">
        <Field label={t('ops.devices.room')}><select className="input w-56" value={roomId} onChange={(e) => { setRoomId(e.target.value); setSessionId(''); setLayout(null) }}><option value="" />{rooms.data?.data.rooms.map((x) => <option key={x.id} value={x.id}>{ar ? x.name_ar : x.name_en}</option>)}</select></Field>
        <Field label={t('ops.rooms.pickSession')}><select className="input w-72" value={sessionId} onChange={(e) => { setSessionId(e.target.value); setLayout(null) }}><option value="" />{sessions.data?.data.map((s) => <option key={s.id} value={s.id}>{s.program ?? s.title} · {fmt.dateTime(s.starts_at)}</option>)}</select></Field>
      </div>
      {roomId && sessionId && (
        <Card className="space-y-4">
          <div className="flex flex-wrap items-end gap-3">
            <Field label={t('ops.rooms.seatRows')}><input type="number" min={1} max={40} className="input w-24" value={cur.rows} onChange={(e) => setLayout({ ...cur, rows: Number(e.target.value) })} /></Field>
            <Field label={t('ops.rooms.seatCols')}><input type="number" min={1} max={40} className="input w-24" value={cur.cols} onChange={(e) => setLayout({ ...cur, cols: Number(e.target.value) })} /></Field>
            <Button variant="outline" onClick={() => void save()}>{t('ops.rooms.saveLayout')}</Button>
            <select className="input w-40" value={mode} onChange={(e) => setMode(e.target.value)}>{['alphabetical', 'school', 'random'].map((m) => <option key={m} value={m}>{t(`ops.rooms.modes.${m}`)}</option>)}</select>
            <Button variant="gold" onClick={() => void auto()}>{t('ops.rooms.autoSeat')}</Button>
            <Button variant="ghost" icon={<Printer className="size-4" />} onClick={() => window.print()}>{t('ops.rooms.print')}</Button>
          </div>
          <p className="text-xs text-slate-500">{t('ops.rooms.blockedHint')} · {t('ops.rooms.capacity')}: {plan.data?.data.room.capacity}</p>
          <div className="overflow-x-auto"><div className="inline-grid gap-1.5" style={{ gridTemplateColumns: `repeat(${cur.cols}, minmax(5.5rem, 1fr))` }}>
            {Array.from({ length: cur.rows * cur.cols }, (_, i) => { const k = `${Math.floor(i / cur.cols)},${i % cur.cols}`; const blocked = cur.blocked.includes(k); const who = seats[k]?.name
              return <button key={k} type="button" onClick={() => toggle(k)} className={clsx('min-h-14 rounded-lg border px-1 py-2 text-xs font-semibold', blocked ? 'border-dashed border-slate-300 bg-slate-100 text-slate-300' : who ? 'border-gold-400 bg-gold-50 text-navy-900' : 'border-navy-100 bg-white text-slate-400')}>{blocked ? '×' : who ?? '·'}</button> })}
          </div></div>
        </Card>
      )}
    </div>
  )
}
