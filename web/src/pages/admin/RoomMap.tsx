import clsx from 'clsx'
import { Building2, CircleCheck, CircleDashed, DoorOpen, Download, EyeOff, ExternalLink, Map as MapIcon, MapPin, Move, Plus, Printer, RotateCcw, Search, Signpost, Sparkles, Trash2, UserPlus, Users, Wand2 } from 'lucide-react'
import { QRCodeSVG } from 'qrcode.react'
import { useRef, useState } from 'react'
import { renderToStaticMarkup } from 'react-dom/server'
import { useTranslation } from 'react-i18next'
import { Button, Card, PageHeader } from '@/components/ui'
import { api, errorMessage } from '@/lib/api'
import { dialogs } from '@/lib/dialogs'
import { toast } from '@/lib/toast'
import { CENTRE_MAP, useWayfinding, type Person, type WayData, type WayRoom } from '@/features/wayfinding/data'
import type { Entrance, FloorId, Pt } from '@/features/wayfinding/engine'
import FloorPlan, { type Marker } from '@/features/wayfinding/FloorPlan'

type Tab = 'rooms' | 'places' | 'signs' | 'export'
type Mode = { kind: 'place' | 'door'; room: string } | { kind: 'placedoor'; place: string } | { kind: 'sign'; index: number } | null

/**
 * «خريطة المركز» (docs/wayfinding.md): the administrator's side of the indoor guide. Which training room is which place on the plans
 * (matched by name until someone decides otherwise), doors that sit elsewhere, rooms hidden from visitors, the main door and the people of
 * every place (the employees whose office it is), the entrances and the
 * "you are here" signs with their printable QR posters, and the redrawn plans as files for print. Right: rooms.manage.
 */
export default function RoomMap() {
  const { t, i18n } = useTranslation()
  const en = i18n.language === 'en'
  const { data, refetch, loaded } = useWayfinding(true)
  const [tab, setTab] = useState<Tab>('rooms')
  const [floor, setFloor] = useState<FloorId>('ground')
  const [selected, setSelected] = useState<string | null>(null)
  const [placeId, setPlaceId] = useState<string | null>(null)
  const [mode, setMode] = useState<Mode>(null)
  const [signs, setSigns] = useState<Entrance[] | null>(null)
  const [entrances, setEntrances] = useState<Entrance[] | null>(null)
  const svg = useRef<SVGSVGElement>(null)
  const room = data.rooms.find((r) => r.id === selected) ?? null
  const place = room?.place ? CENTRE_MAP.places.find((p) => p.id === room.place) ?? null : null
  const editSigns = signs ?? data.signs
  const editEntrances = entrances ?? data.entrances

  const saveRoom = async (id: string, body: Record<string, unknown>) => {
    try {
      await api.put(`/admin/rooms/${id}/map`, body)
      await refetch()
      toast(t('roommap.saved'))
    } catch (e) { toast(errorMessage(e), 'error') }
  }
  const savePlace = async (id: string, body: Record<string, unknown>) => {
    try {
      await api.put(`/admin/rooms/map/places/${id}`, body)
      await refetch()
      toast(t('roommap.saved'))
    } catch (e) { toast(errorMessage(e), 'error') }
  }
  const saveSetting = async (body: Record<string, unknown>) => {
    try {
      await api.put('/admin/rooms/map', body)
      await refetch()
      toast(t('roommap.saved'))
    } catch (e) { toast(errorMessage(e), 'error') }
  }
  const saveSigns = async (patch: { signs?: Entrance[]; entrances?: Entrance[] }) => {
    try {
      await api.put('/admin/rooms/map', patch)
      await refetch()
      setSigns(null); setEntrances(null)
      toast(t('roommap.saved'))
    } catch (e) { toast(errorMessage(e), 'error') }
  }

  const pick = ({ place: hit, at }: { place: (typeof CENTRE_MAP.places)[number] | null; at: Pt }) => {
    if (mode?.kind === 'place' && hit) { void saveRoom(mode.room, { place: hit.id }); setMode(null); return }
    if (mode?.kind === 'door') { void saveRoom(mode.room, { door: [Math.round(at[0]), Math.round(at[1])] }); setMode(null); return }
    if (mode?.kind === 'placedoor') { void savePlace(mode.place, { door: [Math.round(at[0]), Math.round(at[1])] }); setMode(null); return }
    if (mode?.kind === 'sign') {
      const list = [...editSigns]
      list[mode.index] = { ...list[mode.index], floor, at: [Math.round(at[0]), Math.round(at[1])] }
      setSigns(list); setMode(null); return
    }
    if (tab === 'rooms' && hit) { const r = data.rooms.find((x) => x.place === hit.id); setSelected(r?.id ?? null) }
    if (tab === 'places' && hit) setPlaceId(hit.id)
  }

  const markers: Marker[] = tab === 'rooms'
    ? data.rooms.filter((r) => r.floor === floor && r.door && (r.custom_door || r.id === selected)).map((r) => ({ at: r.door!, kind: 'door', active: r.id === selected }))
    : tab === 'places'
      ? CENTRE_MAP.places.filter((p) => p.floor === floor && (data.places?.[p.id]?.door || p.id === placeId)).map((p) => ({ at: data.places?.[p.id]?.door ?? p.door, kind: 'door' as const, active: p.id === placeId }))
    : tab === 'signs'
      ? [...editEntrances.filter((e) => e.floor === floor).map((e) => ({ at: e.at, kind: 'entrance' as const, label: en ? e.en || e.ar : e.ar })), ...editSigns.filter((s) => s.floor === floor).map((s) => ({ at: s.at, kind: 'sign' as const, label: en ? s.en || s.ar : s.ar }))]
      : []
  const counts = { placed: data.rooms.filter((r) => r.place && !r.auto).length, auto: data.rooms.filter((r) => r.auto).length, none: data.rooms.filter((r) => !r.place).length }

  return (
    <>
      <PageHeader
        title={<span className="flex items-center gap-3"><span className="grid size-11 place-items-center rounded-2xl bg-navy-900 text-gold-300 shadow-glass"><MapIcon className="size-5" /></span>{t('roommap.title')}</span>}
        subtitle={t('roommap.subtitle')}
        actions={<Button to="/map" variant="outline" icon={<ExternalLink className="size-4" />}>{t('roommap.openGuide')}</Button>}
      />

      <div className="mb-4 flex flex-wrap items-center gap-2">
        {(['rooms', 'places', 'signs', 'export'] as Tab[]).map((x) => (
          <button key={x} type="button" onClick={() => { setTab(x); setMode(null) }} className={clsx('rounded-xl px-4 py-2 text-sm font-bold transition', tab === x ? 'bg-navy-900 text-white shadow' : 'bg-white text-navy-800 ring-1 ring-navy-100 hover:ring-gold-400')}>{t(`roommap.tab.${x}`)}</button>
        ))}
        <span className="mx-2 h-6 w-px bg-navy-100" />
        {CENTRE_MAP.floors.map((f) => (
          <button key={f.id} type="button" onClick={() => setFloor(f.id)} className={clsx('rounded-xl px-3 py-2 text-xs font-bold transition', floor === f.id ? 'bg-gold-500 text-navy-950' : 'bg-white text-navy-800 ring-1 ring-navy-100')}>{en ? f.en : f.ar}</button>
        ))}
      </div>

      <div className="grid gap-4 xl:grid-cols-[1fr_400px]">
        <Card padded={false} className="relative h-[68vh] min-h-[460px] overflow-hidden bg-[radial-gradient(circle_at_1px_1px,rgba(26,26,26,.07)_1px,transparent_0)] bg-[length:22px_22px] !bg-[#f3f1ec]">
          <FloorPlan svgRef={svg} map={CENTRE_MAP} floor={floor} markers={markers} highlight={tab === 'places' ? (CENTRE_MAP.places.find((p) => p.id === placeId)?.floor === floor ? placeId : null) : place?.floor === floor ? place.id : null} onPick={pick} picking={!!mode} className="absolute inset-0">
            {mode && (
              <div className="pointer-events-auto absolute inset-x-4 top-4 mx-auto flex max-w-lg items-center gap-3 rounded-2xl bg-navy-900 px-4 py-3 text-sm font-semibold text-white shadow-glass">
                <Wand2 className="size-5 shrink-0 text-gold-300" /><span className="flex-1">{t(`roommap.mode.${mode.kind}`)}</span>
                <button type="button" onClick={() => setMode(null)} className="rounded-lg bg-white/10 px-2 py-1 text-xs hover:bg-white/20">{t('common.cancel')}</button>
              </div>
            )}
          </FloorPlan>
        </Card>

        <Card className="flex max-h-[68vh] min-h-[460px] flex-col">
          {tab === 'rooms' && (
            <>
              <div className="mb-3 grid grid-cols-3 gap-2 text-center text-xs">
                <Stat n={counts.placed} label={t('roommap.placed')} tone="emerald" />
                <Stat n={counts.auto} label={t('roommap.auto')} tone="gold" />
                <Stat n={counts.none} label={t('roommap.none')} tone="slate" />
              </div>
              {room && (
                <div className="mb-3 space-y-3 rounded-2xl bg-ivory p-3 ring-1 ring-navy-100">
                  <div className="flex items-center justify-between gap-2">
                    <div className="min-w-0"><div className="truncate font-bold text-navy-900">{en ? room.name_en : room.name_ar}</div><div className="text-xs text-slate-500">{place ? `${en ? place.en : place.ar} · ${floorName(place.floor, en)}` : t('roommap.notOnMap')}</div></div>
                    {room.auto && <span className="shrink-0 rounded-full bg-gold-50 px-2 py-0.5 text-[10px] font-bold text-gold-800 ring-1 ring-gold-200">{t('roommap.autoBadge')}</span>}
                  </div>
                  <div className="flex flex-wrap gap-2">
                    <Button size="sm" variant="gold" icon={<MapPin className="size-4" />} onClick={() => setMode({ kind: 'place', room: room.id })}>{t('roommap.choosePlace')}</Button>
                    {room.auto && <Button size="sm" variant="outline" icon={<CircleCheck className="size-4" />} onClick={() => saveRoom(room.id, { place: room.place })}>{t('roommap.confirm')}</Button>}
                    {room.place && <Button size="sm" variant="outline" icon={<Move className="size-4" />} onClick={() => { if (place) setFloor(place.floor); setMode({ kind: 'door', room: room.id }) }}>{t('roommap.moveDoor')}</Button>}
                    {room.place && <Button size="sm" variant="outline" onClick={() => saveRoom(room.id, { place: null })}>{t('roommap.notHere')}</Button>}
                    <Button size="sm" variant="outline" icon={<EyeOff className="size-4" />} onClick={() => saveRoom(room.id, { hidden: !room.hidden })}>{room.hidden ? t('roommap.show') : t('roommap.hide')}</Button>
                    <Button size="sm" variant="ghost" icon={<RotateCcw className="size-4" />} onClick={() => saveRoom(room.id, { reset: true })}>{t('roommap.reset')}</Button>
                    {room.place && <Button size="sm" variant="ghost" icon={<Printer className="size-4" />} onClick={() => printPoster({ id: room.place!, ar: room.name_ar, en: room.name_en, floor: room.floor! }, t, en, true)}>{t('roommap.doorSign')}</Button>}
                  </div>
                </div>
              )}
              <ul className="min-h-0 flex-1 space-y-1 overflow-y-auto">
                {!loaded && Array.from({ length: 6 }).map((_, i) => <li key={i} className="h-12 animate-pulse rounded-xl bg-ivory" />)}
                {data.rooms.map((r) => <RoomRow key={r.id} r={r} en={en} active={r.id === selected} onClick={() => { setSelected(r.id); setMode(null); if (r.floor) setFloor(r.floor) }} />)}
              </ul>
            </>
          )}

          {tab === 'places' && <PlacesPanel data={data} en={en} selected={placeId} onSelect={(id) => { setPlaceId(id); setMode(null); const p = CENTRE_MAP.places.find((x) => x.id === id); if (p) setFloor(p.floor) }} onDoor={(id) => setMode({ kind: 'placedoor', place: id })} onSave={savePlace} onSetting={saveSetting} />}

          {tab === 'signs' && (
            <div className="min-h-0 flex-1 space-y-5 overflow-y-auto">
              <p className="text-xs leading-5 text-slate-500">{t('roommap.signsHint')}</p>
              <SignList title={t('roommap.entrances')} list={editEntrances} en={en} onChange={setEntrances} onPlace={null} onPrint={(e) => printPoster({ id: e.id, ar: e.ar, en: e.en, floor: e.floor }, t, en)} floor={floor} />
              <SignList title={t('roommap.signs')} list={editSigns} en={en} onChange={setSigns} onPlace={(i) => setMode({ kind: 'sign', index: i })} onPrint={(e) => printPoster({ id: e.id, ar: e.ar, en: e.en, floor: e.floor }, t, en)} floor={floor} />
              {(signs || entrances) && (
                <div className="sticky bottom-0 flex gap-2 bg-white pt-2">
                  <Button variant="gold" className="flex-1" onClick={() => saveSigns({ ...(signs ? { signs } : {}), ...(entrances ? { entrances } : {}) })}>{t('common.save')}</Button>
                  <Button variant="outline" onClick={() => { setSigns(null); setEntrances(null) }}>{t('common.cancel')}</Button>
                </div>
              )}
            </div>
          )}

          {tab === 'export' && (
            <div className="space-y-4">
              <div className="rounded-2xl bg-gradient-to-br from-navy-900 to-navy-800 p-4 text-white">
                <div className="flex items-center gap-2 text-sm font-bold"><Sparkles className="size-4 text-gold-300" />{t('roommap.exportTitle')}</div>
                <p className="mt-1 text-xs leading-5 text-white/75">{t('roommap.exportHint')}</p>
              </div>
              {CENTRE_MAP.floors.map((f) => (
                <div key={f.id} className="flex items-center justify-between gap-2 rounded-2xl p-3 ring-1 ring-navy-100">
                  <span className="text-sm font-bold text-navy-900">{en ? f.en : f.ar}</span>
                  <span className="flex gap-2">
                    <Button size="sm" variant="outline" icon={<Download className="size-4" />} onClick={() => exportPlan(f.id, en, 'svg')}>SVG</Button>
                    <Button size="sm" variant="gold" icon={<Download className="size-4" />} onClick={() => exportPlan(f.id, en, 'png')}>PNG</Button>
                  </span>
                </div>
              ))}
            </div>
          )}
        </Card>
      </div>
    </>
  )
}

const floorName = (id: FloorId, en: boolean) => { const f = CENTRE_MAP.floors.find((x) => x.id === id); return f ? (en ? f.en : f.ar) : '' }

/**
 * Every place of the plans (offices, labs, the library, rooms, services): its main door, which is where the guide's walk ends, and the
 * employees whose office it is, so a visitor can ask for a person and be walked to their door. People are picked from the platform's
 * accounts (name and job title come with them) or typed; the centre may keep the names from visitors with one switch.
 */
const KIND_ORDER = ['office', 'train', 'lab', 'meet', 'public', 'pray', 'wc']

function PlacesPanel({ data, en, selected, onSelect, onDoor, onSave, onSetting }: {
  data: WayData; en: boolean; selected: string | null; onSelect: (id: string) => void; onDoor: (id: string) => void
  onSave: (id: string, body: Record<string, unknown>) => Promise<void>; onSetting: (body: Record<string, unknown>) => Promise<void>
}) {
  const { t } = useTranslation()
  const [q, setQ] = useState('')
  const info = (id: string) => data.places?.[id]
  const place = CENTRE_MAP.places.find((p) => p.id === selected) ?? null
  const needle = q.trim().toLowerCase()
  const list = CENTRE_MAP.places
    .filter((p) => p.kind !== 'wc' || info(p.id))
    .filter((p) => !needle || `${p.ar} ${p.en} ${p.number ?? ''} ${(info(p.id)?.people ?? []).map((x) => `${x.name_ar} ${x.name_en}`).join(' ')}`.toLowerCase().includes(needle))
    .sort((a, b) => KIND_ORDER.indexOf(a.kind) - KIND_ORDER.indexOf(b.kind) || Number(a.number ?? 99) - Number(b.number ?? 99) || a.ar.localeCompare(b.ar, 'ar'))
  const door = place ? info(place.id)?.door : null
  return (
    <>
      <p className="mb-3 text-xs leading-5 text-slate-500">{t('roommap.places.hint')}</p>
      <label className="mb-3 flex cursor-pointer items-center justify-between gap-3 rounded-2xl bg-ivory px-3 py-2.5 ring-1 ring-navy-100">
        <span className="min-w-0"><span className="block text-sm font-bold text-navy-900">{t('roommap.places.showPeople')}</span><span className="block text-[11px] leading-4 text-slate-500">{t('roommap.places.showPeopleHint')}</span></span>
        <button type="button" role="switch" aria-checked={data.show_people !== false} onClick={() => void onSetting({ show_people: data.show_people === false })} className={clsx('relative inline-flex h-6 w-11 shrink-0 items-center rounded-full p-0.5 transition-colors', data.show_people !== false ? 'justify-end bg-gold-500' : 'justify-start bg-slate-300')}><span className="inline-block size-5 rounded-full bg-white shadow" /></button>
      </label>
      {place && (
        <div className="mb-3 space-y-3 rounded-2xl bg-ivory p-3 ring-1 ring-navy-100">
          <div className="min-w-0"><div className="truncate font-bold text-navy-900">{en ? place.en : place.ar}</div><div className="text-xs text-slate-500">{floorName(place.floor, en)}</div></div>
          <div className="flex flex-wrap items-center gap-2">
            <span className="inline-flex items-center gap-1.5 text-xs font-bold text-navy-800"><DoorOpen className="size-4 text-gold-600" />{t('roommap.places.mainDoor')}: <span className={door ? 'text-emerald-700' : 'text-slate-500'}>{door ? t('roommap.places.customDoor') : t('roommap.places.planDoor')}</span></span>
            <Button size="sm" variant="gold" icon={<Move className="size-4" />} onClick={() => onDoor(place.id)}>{t('roommap.places.setDoor')}</Button>
            {door && <Button size="sm" variant="ghost" icon={<RotateCcw className="size-4" />} onClick={() => void onSave(place.id, { door: null })}>{t('roommap.places.resetDoor')}</Button>}
          </div>
          <PeopleEditor key={`${place.id}:${JSON.stringify(info(place.id)?.people ?? [])}`} initial={info(place.id)?.people ?? []} en={en} onSave={(people) => onSave(place.id, { people })} />
        </div>
      )}
      <label className="relative mb-2 block">
        <Search className="pointer-events-none absolute start-3 top-1/2 size-4 -translate-y-1/2 text-slate-400" />
        <input className="input !py-2 !ps-9 text-sm" placeholder={t('roommap.places.search')} value={q} onChange={(e) => setQ(e.target.value)} />
      </label>
      <ul className="min-h-0 flex-1 space-y-1 overflow-y-auto">
        {list.map((p) => {
          const n = info(p.id)?.people.length ?? 0
          const active = p.id === selected
          return (
            <li key={p.id}>
              <button type="button" onClick={() => onSelect(p.id)} className={clsx('flex w-full items-center gap-3 rounded-xl px-2 py-2 text-start transition', active ? 'bg-navy-900 text-white' : 'hover:bg-gold-50')}>
                <span className={clsx('grid size-9 shrink-0 place-items-center rounded-xl', active ? 'bg-white/10 text-gold-300' : p.kind === 'office' ? 'bg-gold-50 text-gold-700' : 'bg-slate-100 text-slate-500')}>{p.kind === 'office' ? <Building2 className="size-4" /> : <MapPin className="size-4" />}</span>
                <span className="min-w-0 flex-1">
                  <span className="block truncate text-sm font-bold">{en ? p.en : p.ar}</span>
                  <span className={clsx('block truncate text-[11px]', active ? 'text-white/70' : 'text-slate-500')}>{floorName(p.floor, en)}{info(p.id)?.door ? ` · ${t('roommap.places.customDoor')}` : ''}</span>
                </span>
                {n > 0 && <span className={clsx('inline-flex shrink-0 items-center gap-1 rounded-full px-2 py-0.5 text-[11px] font-bold', active ? 'bg-white/15 text-white' : 'bg-gold-50 text-gold-800 ring-1 ring-gold-200')}><Users className="size-3" />{n}</span>}
              </button>
            </li>
          )
        })}
        {list.length === 0 && <li className="px-4 py-8 text-center text-sm text-slate-500">{t('wayfinding.none')}</li>}
      </ul>
    </>
  )
}

type Candidate = { user_id: string; name_ar: string; name_en: string; email: string; title_ar: string | null; title_en: string | null }

const newPerson = (): Person => ({ id: `p-${Date.now().toString(36)}`, name_ar: '', name_en: '', title_ar: '', title_en: '', desk: '' })

/** The people of one place: add from the platform's accounts or by hand, edit the name, job title and desk, remove, save. */
function PeopleEditor({ initial, en, onSave }: { initial: Person[]; en: boolean; onSave: (people: Person[]) => Promise<void> }) {
  const { t } = useTranslation()
  const [list, setList] = useState<Person[]>(initial)
  const [q, setQ] = useState('')
  const [found, setFound] = useState<Candidate[]>([])
  const [saving, setSaving] = useState(false)
  const timer = useRef<number | undefined>(undefined)
  const dirty = JSON.stringify(list) !== JSON.stringify(initial)
  const search = (v: string) => {
    setQ(v)
    window.clearTimeout(timer.current)
    if (v.trim().length < 2) { setFound([]); return }
    timer.current = window.setTimeout(async () => {
      try { const { data } = await api.get('/admin/rooms/map/people', { params: { q: v } }); setFound(data.data as Candidate[]) } catch { setFound([]) }
    }, 250)
  }
  const add = (p: Person) => { setList((l) => [...l, p]); setQ(''); setFound([]) }
  const update = (i: number, patch: Partial<Person>) => setList((l) => l.map((p, k) => (k === i ? { ...p, ...patch } : p)))
  const save = async () => { setSaving(true); try { await onSave(list) } finally { setSaving(false) } }
  return (
    <div className="space-y-2">
      <div className="flex items-center gap-2 text-xs font-bold text-navy-800"><Users className="size-4 text-gold-600" />{t('roommap.places.people')}</div>
      {list.length === 0 && <p className="rounded-xl bg-white px-3 py-2 text-xs text-slate-500 ring-1 ring-navy-100">{t('roommap.places.noPeople')}</p>}
      {list.map((p, i) => (
        <div key={p.id} className="space-y-1.5 rounded-xl bg-white p-2.5 ring-1 ring-navy-100">
          <div className="flex items-center gap-2">
            <input className="input !py-1.5 text-sm" placeholder={t('roommap.places.nameAr')} value={p.name_ar} onChange={(e) => update(i, { name_ar: e.target.value })} />
            <button type="button" onClick={() => setList((l) => l.filter((_, k) => k !== i))} className="rounded-lg p-1.5 text-slate-400 hover:bg-red-50 hover:text-danger" aria-label={t('roommap.places.remove')}><Trash2 className="size-4" /></button>
          </div>
          <input className="input !py-1.5 text-sm" dir="ltr" placeholder={t('roommap.places.nameEn')} value={p.name_en} onChange={(e) => update(i, { name_en: e.target.value })} />
          <div className="grid grid-cols-2 gap-1.5">
            <input className="input !py-1.5 text-sm" placeholder={t('roommap.places.titleAr')} value={p.title_ar} onChange={(e) => update(i, { title_ar: e.target.value })} />
            <input className="input !py-1.5 text-sm" dir="ltr" placeholder={t('roommap.places.titleEn')} value={p.title_en} onChange={(e) => update(i, { title_en: e.target.value })} />
          </div>
          <input className="input !py-1.5 text-sm" placeholder={t('roommap.places.desk')} value={p.desk} onChange={(e) => update(i, { desk: e.target.value })} />
        </div>
      ))}
      <div className="relative">
        <UserPlus className="pointer-events-none absolute start-3 top-1/2 size-4 -translate-y-1/2 text-slate-400" />
        <input className="input !py-2 !ps-9 text-sm" placeholder={t('roommap.places.searchPerson')} value={q} onChange={(e) => search(e.target.value)} />
        {found.length > 0 && (
          <ul className="absolute inset-x-0 top-full z-10 mt-1 max-h-56 overflow-y-auto rounded-2xl bg-white p-1 shadow-glass ring-1 ring-navy-100">
            {found.map((c) => (
              <li key={c.user_id}>
                <button type="button" onClick={() => add({ ...newPerson(), name_ar: c.name_ar, name_en: c.name_en, title_ar: c.title_ar ?? '', title_en: c.title_en ?? '', user_id: c.user_id })} className="block w-full rounded-xl px-3 py-2 text-start hover:bg-gold-50">
                  <span className="block truncate text-sm font-bold text-navy-900">{en ? c.name_en : c.name_ar}</span>
                  <span className="block truncate text-[11px] text-slate-500">{(en ? c.title_en : c.title_ar) || c.email}</span>
                </button>
              </li>
            ))}
          </ul>
        )}
      </div>
      <div className="flex flex-wrap gap-2">
        <Button size="sm" variant="outline" icon={<Plus className="size-4" />} onClick={() => add(newPerson())}>{t('roommap.places.addManual')}</Button>
        <Button size="sm" variant="gold" loading={saving} disabled={!dirty} onClick={save}>{t('roommap.places.save')}</Button>
      </div>
    </div>
  )
}

function Stat({ n, label, tone }: { n: number; label: string; tone: 'emerald' | 'gold' | 'slate' }) {
  return <div className={clsx('rounded-xl p-2 ring-1', tone === 'emerald' ? 'bg-emerald-50 text-emerald-800 ring-emerald-100' : tone === 'gold' ? 'bg-gold-50 text-gold-800 ring-gold-200' : 'bg-slate-50 text-slate-600 ring-slate-200')}><div className="text-xl font-extrabold">{n}</div><div className="font-semibold">{label}</div></div>
}

function RoomRow({ r, en, active, onClick }: { r: WayRoom; en: boolean; active: boolean; onClick: () => void }) {
  const { t } = useTranslation()
  const place = r.place ? CENTRE_MAP.places.find((p) => p.id === r.place) : null
  return (
    <li>
      <button type="button" onClick={onClick} className={clsx('flex w-full items-center gap-3 rounded-xl px-2 py-2 text-start transition', active ? 'bg-navy-900 text-white' : 'hover:bg-gold-50')}>
        <span className={clsx('grid size-9 shrink-0 place-items-center rounded-xl', active ? 'bg-white/10 text-gold-300' : r.place ? (r.auto ? 'bg-gold-50 text-gold-700' : 'bg-emerald-50 text-emerald-700') : 'bg-slate-100 text-slate-400')}>
          {r.place ? (r.auto ? <Wand2 className="size-4" /> : <CircleCheck className="size-4" />) : <CircleDashed className="size-4" />}
        </span>
        <span className="min-w-0 flex-1">
          <span className="block truncate text-sm font-bold">{en ? r.name_en : r.name_ar}</span>
          <span className={clsx('block truncate text-[11px]', active ? 'text-white/70' : 'text-slate-500')}>{place ? `${en ? place.en : place.ar} · ${floorName(place.floor, en)}` : t('roommap.notOnMap')}{r.hidden ? ` · ${t('roommap.hidden')}` : ''}</span>
        </span>
      </button>
    </li>
  )
}

function SignList({ title, list, en, onChange, onPlace, onPrint, floor }: { title: string; list: Entrance[]; en: boolean; onChange: (l: Entrance[]) => void; onPlace: ((i: number) => void) | null; onPrint: (e: Entrance) => void; floor: FloorId }) {
  const { t } = useTranslation()
  const update = (i: number, patch: Partial<Entrance>) => onChange(list.map((e, k) => (k === i ? { ...e, ...patch } : e)))
  return (
    <div>
      <div className="mb-2 flex items-center justify-between"><span className="text-sm font-bold text-navy-900">{title}</span>
        {onPlace && <button type="button" onClick={() => { onChange([...list, { id: `sign-${Date.now().toString(36)}`, floor, ar: '', en: '', at: CENTRE_MAP.entrances[0].at }]); onPlace(list.length) }} className="inline-flex items-center gap-1 text-xs font-bold text-link hover:underline"><Plus className="size-3.5" />{t('roommap.addSign')}</button>}
      </div>
      <div className="space-y-2">
        {list.map((e, i) => (
          <div key={e.id} className="space-y-2 rounded-2xl p-3 ring-1 ring-navy-100">
            <div className="flex items-center gap-2"><Signpost className="size-4 shrink-0 text-gold-600" /><span className="text-[11px] text-slate-500">{floorName(e.floor, en)}</span>
              <span className="ms-auto flex gap-1">
                {onPlace && <button type="button" onClick={() => onPlace(i)} className="rounded-lg p-1.5 text-slate-500 hover:bg-gold-50" title={t('roommap.placeOnMap')} aria-label={t('roommap.placeOnMap')}><MapPin className="size-4" /></button>}
                <button type="button" onClick={() => onPrint(e)} disabled={!e.ar} className="rounded-lg p-1.5 text-slate-500 hover:bg-gold-50 disabled:opacity-40" title={t('roommap.print')} aria-label={t('roommap.print')}><Printer className="size-4" /></button>
                <button type="button" onClick={async () => { if (await dialogs.confirm(t('roommap.confirmRemove'))) onChange(list.filter((_, k) => k !== i)) }} className="rounded-lg p-1.5 text-slate-400 hover:bg-red-50 hover:text-danger" aria-label={t('admin.brand.remove')}><Trash2 className="size-4" /></button>
              </span>
            </div>
            <input className="input !py-1.5 text-sm" placeholder={t('roommap.nameAr')} value={e.ar} onChange={(x) => update(i, { ar: x.target.value })} />
            <input className="input !py-1.5 text-sm" dir="ltr" placeholder={t('roommap.nameEn')} value={e.en} onChange={(x) => update(i, { en: x.target.value })} />
          </div>
        ))}
      </div>
    </div>
  )
}

/** A "You are here" poster: the centre's name, where the sign stands, and a QR code that opens the guide from that very spot. */
function printPoster(sign: { id: string; ar: string; en: string; floor: FloorId }, t: (k: string, o?: Record<string, unknown>) => string, en: boolean, door = false) {
  const url = `${window.location.origin}/map?from=${encodeURIComponent(sign.id)}`
  const qr = renderToStaticMarkup(<QRCodeSVG value={url} size={420} marginSize={2} fgColor="#1a1a1a" level="M" />)
  const css = getComputedStyle(document.documentElement)
  const primary = css.getPropertyValue('--color-navy-900').trim() || '#8A1538'
  const gold = css.getPropertyValue('--color-gold-500').trim() || '#A29475'
  const w = window.open('', '_blank')
  if (!w) return
  w.document.write(`<!doctype html><html dir="${en ? 'ltr' : 'rtl'}" lang="${en ? 'en' : 'ar'}"><head><meta charset="utf-8"><title>${t('roommap.posterTitle')}</title>
<style>@page{size:A4;margin:0}*{box-sizing:border-box}body{margin:0;font-family:Lusail,"Qatar Sans",Tajawal,system-ui,sans-serif;color:#1a1a1a}
.p{width:210mm;height:297mm;display:flex;flex-direction:column;align-items:center;justify-content:space-between;padding:22mm 18mm;background:linear-gradient(180deg,#fff 0%,#faf7f2 100%)}
.top{width:100%;display:flex;align-items:center;justify-content:space-between;border-bottom:2px solid ${gold};padding-bottom:8mm}
.brand{font-weight:800;color:${primary};font-size:20pt}.sub{font-size:10pt;color:#666}
.here{display:inline-flex;align-items:center;gap:4mm;background:${primary};color:#fff;border-radius:999px;padding:4mm 9mm;font-weight:800;font-size:22pt}
.dot{width:7mm;height:7mm;border-radius:50%;background:#fff;box-shadow:0 0 0 3mm rgba(255,255,255,.25)}
h1{margin:9mm 0 2mm;font-size:34pt;text-align:center;color:#1a1a1a}.en{font-size:15pt;color:#555;text-align:center}
.qr{padding:7mm;border-radius:10mm;background:#fff;box-shadow:0 8mm 22mm -10mm rgba(0,0,0,.25);border:1.5mm solid ${gold}}
.how{font-size:15pt;text-align:center;max-width:150mm;line-height:1.7}.foot{font-size:9pt;color:#888;direction:ltr}</style></head>
<body><div class="p"><div class="top"><div><div class="brand">${t('wayfinding.title')}</div><div class="sub">${t('wayfinding.subtitle')}</div></div><div class="sub">${floorName(sign.floor, en)}</div></div>
<div style="text-align:center"><span class="here"><span class="dot"></span>${door ? t('roommap.posterDoor') : t('roommap.posterHere')}</span><h1>${escape(sign.ar)}</h1><div class="en">${escape(sign.en || '')}</div></div>
<div class="qr">${qr}</div><div class="how">${t('roommap.posterHow')}</div><div class="foot">${escape(url)}</div></div>
<script>setTimeout(()=>print(),300)</script></body></html>`)
  w.document.close()
}

const escape = (s: string) => s.replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' })[c]!)

/** The redrawn floor plan as a file for print and signage: the whole floor, the theme's colours written in, at twice the plan's size. */
function exportPlan(floor: FloorId, en: boolean, format: 'svg' | 'png') {
  const host = document.createElement('div')
  host.style.cssText = `position:fixed;left:-99999px;top:0;width:${CENTRE_MAP.size.w}px;height:${CENTRE_MAP.size.h}px`
  document.body.appendChild(host)
  void import('react-dom/client').then(({ createRoot }) => {
    const root = createRoot(host)
    root.render(<FloorPlan map={CENTRE_MAP} floor={floor} className="size-full" />)
    setTimeout(() => {
      const el = host.querySelector('svg')
      if (!el) { root.unmount(); host.remove(); return }
      const clone = el.cloneNode(true) as SVGSVGElement
      clone.setAttribute('viewBox', `0 0 ${CENTRE_MAP.size.w} ${CENTRE_MAP.size.h}`)
      clone.setAttribute('width', String(CENTRE_MAP.size.w * 2))
      clone.setAttribute('height', String(CENTRE_MAP.size.h * 2))
      const css = getComputedStyle(document.documentElement)
      let text = new XMLSerializer().serializeToString(clone).replace(/var\((--[a-z0-9-]+)\)/gi, (_, v) => css.getPropertyValue(v).trim() || '#888')
      text = text.replace('<svg', `<svg style="background:#f3f1ec;font-family:Lusail,'Qatar Sans',Tajawal,sans-serif"`)
      root.unmount(); host.remove()
      const name = `centre-plan-${floor}-${en ? 'en' : 'ar'}`
      if (format === 'svg') return download(new Blob([text], { type: 'image/svg+xml' }), `${name}.svg`)
      const img = new Image()
      img.onload = () => {
        const canvas = document.createElement('canvas')
        canvas.width = CENTRE_MAP.size.w * 2
        canvas.height = CENTRE_MAP.size.h * 2
        const ctx = canvas.getContext('2d')!
        ctx.fillStyle = '#f3f1ec'
        ctx.fillRect(0, 0, canvas.width, canvas.height)
        ctx.drawImage(img, 0, 0, canvas.width, canvas.height)
        canvas.toBlob((b) => b && download(b, `${name}.png`), 'image/png')
      }
      img.src = `data:image/svg+xml;charset=utf-8,${encodeURIComponent(text)}`
    }, 400)
  })
}

function download(blob: Blob, name: string) {
  const a = document.createElement('a')
  a.href = URL.createObjectURL(blob)
  a.download = name
  a.click()
  setTimeout(() => URL.revokeObjectURL(a.href), 2000)
}
