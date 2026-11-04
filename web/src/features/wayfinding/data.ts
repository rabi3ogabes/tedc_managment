import { useMemo } from 'react'
import { useGet } from '@/hooks/useApi'
import bundled from './centre-map.json'
import { createEngine, spotOf, type CentreMap, type Entrance, type FloorId, type Place, type Pt, type Step, type Target } from './engine'

export type WayRoom = {
  id: string; name_ar: string; name_en: string; code: string | null; capacity: number | null; type: string | null; active: boolean
  place: string | null; floor: FloorId | null; door: Pt | null; auto: boolean; latitude: number | null; longitude: number | null
  hidden?: boolean; custom_door?: boolean
}
/** Someone whose office a place is: the name and job title as the centre shows them, and the desk («مكتب 12»). */
export type Person = { id: string; name_ar: string; name_en: string; title_ar: string; title_en: string; desk: string; user_id?: string | null }
/** What the administrator set for a place of the plans: its main door (where the walk ends) and its people. */
export type PlaceInfo = { door: Pt | null; people: Person[] }
export type WayData = { entrances: Entrance[]; signs: Entrance[]; rooms: WayRoom[]; centre: { lat: number; lng: number } | null; places?: Record<string, PlaceInfo>; show_people?: boolean }
export type Destination = Target & { key: string; title: { ar: string; en: string }; place: Place | null; room: WayRoom | null; kind: Place['kind'] | 'room'; people: Person[] }

export const CENTRE_MAP = bundled as unknown as CentreMap
let engine: ReturnType<typeof createEngine> | null = null
/** One engine per page load: decoding the corridors takes a few milliseconds, routes after that are instant. */
export const getEngine = () => (engine ??= createEngine(CENTRE_MAP))

/** The map as administrators set it (rooms, entrances, signs, the centre's position); the plans themselves ship with the site. */
export function useWayfinding(admin = false) {
  const q = useGet<{ data: WayData & { map?: unknown } }>(admin ? '/admin/rooms/map' : '/public/wayfinding', undefined, { staleTime: admin ? 0 : 60_000 })
  const data: WayData = q.data?.data ?? { entrances: CENTRE_MAP.entrances, signs: [], rooms: [], centre: null }
  return { ...q, data, loaded: !!q.data }
}

/** Everything a visitor can go to: the centre's rooms by their own names, then the other places of the plans. */
export function useDestinations(data: WayData): Destination[] {
  return useMemo(() => {
    const out: Destination[] = []
    const used = new Set<string>()
    for (const r of data.rooms) {
      const place = r.place ? CENTRE_MAP.places.find((p) => p.id === r.place) ?? null : null
      if (place && r.door && r.floor) {
        used.add(place.id)
        out.push({ key: `room:${r.id}`, floor: r.floor, at: r.door, rect: place.rect, id: place.id, title: { ar: r.name_ar, en: r.name_en }, place, room: r, kind: place.kind, people: data.places?.[place.id]?.people ?? [] })
      } else if (r.latitude && r.longitude) {
        out.push({ key: `room:${r.id}`, floor: 'ground', at: [0, 0], title: { ar: r.name_ar, en: r.name_en }, place: null, room: r, kind: 'room', people: [] })
      }
    }
    for (const p of CENTRE_MAP.places) {
      if (used.has(p.id)) continue
      const info = data.places?.[p.id]
      out.push({ key: p.id, floor: p.floor, at: info?.door ?? p.door, rect: p.rect, id: p.id, title: { ar: p.ar, en: p.en }, place: p, room: null, kind: p.kind, people: info?.people ?? [] })
    }
    return out
  }, [data.rooms, data.places])
}

/** A person as one line: name, job title and desk, in the visitor's language. */
export const personLine = (p: Person, en: boolean) => [en ? p.name_en || p.name_ar : p.name_ar || p.name_en, en ? p.title_en || p.title_ar : p.title_ar || p.title_en, p.desk].filter(Boolean).join(' · ')

export type Origin = { key: string; floor: FloorId; at: Pt; title: { ar: string; en: string } }

/** A starting point from the address bar: an entrance, a sign, a place, stairs ("stairs-west@first"), or a tapped point ("ground:420,610"). */
export function originOf(key: string | null, data: WayData): Origin | null {
  if (!key) return null
  const tapped = key.match(/^(ground|first):(\d+),(\d+)$/)
  if (tapped) return { key, floor: tapped[1] as FloorId, at: getEngine().snap(tapped[1] as FloorId, [Number(tapped[2]), Number(tapped[3])]), title: { ar: 'موقعك على الخريطة', en: 'Your spot on the map' } }
  const entrance = [...data.entrances, ...data.signs].find((e) => e.id === key)
  if (entrance) return { key, floor: entrance.floor, at: entrance.at, title: { ar: entrance.ar, en: entrance.en || entrance.ar } }
  const s = spotOf(CENTRE_MAP, key)
  return s ? { key, floor: s.floor, at: s.at, title: s.label } : null
}

/** One step as a sentence, in the visitor's language. */
export function stepText(s: Step, t: (k: string, o?: Record<string, unknown>) => string, en: boolean, dest: string, from: string): string {
  const metres = Math.max(1, Math.round(s.metres))
  const near = s.near ? CENTRE_MAP.places.find((p) => p.id === s.near) : null
  const nearText = near ? t('wayfinding.step.near', { place: en ? near.en : near.ar }) : ''
  const floor = (id?: FloorId) => { const f = CENTRE_MAP.floors.find((x) => x.id === id); return f ? (en ? f.en : f.ar) : '' }
  const conn = s.connector ? CENTRE_MAP.connectors.find((c) => c.id === s.connector) : null
  const walk = s.metres >= 0.5 ? t('wayfinding.step.walk', { m: metres }) : ''
  switch (s.kind) {
    case 'start': return t('wayfinding.step.start', { from, walk })
    case 'arrive': return t(`wayfinding.step.arrive_${s.side ?? 'ahead'}`, { dest })
    case 'stairs': case 'lift': return t(`wayfinding.step.${s.kind}_${s.up ? 'up' : 'down'}`, { name: conn ? (en ? conn.en : conn.ar) : '', floor: floor(s.to), walk })
    default: return t(`wayfinding.step.${s.kind}`, { near: nearText, walk })
  }
}

/** Metres to the centre from a GPS position (haversine). */
export function distanceTo(a: { lat: number; lng: number }, b: { lat: number; lng: number }) {
  const R = 6371000, rad = Math.PI / 180
  const dLat = (b.lat - a.lat) * rad, dLng = (b.lng - a.lng) * rad
  const x = Math.sin(dLat / 2) ** 2 + Math.cos(a.lat * rad) * Math.cos(b.lat * rad) * Math.sin(dLng / 2) ** 2
  return 2 * R * Math.asin(Math.sqrt(x))
}

/** Directions in the phone's maps app, to a point (only the destination is in the address). */
export const directionsUrl = (lat: number, lng: number) => `https://www.google.com/maps/dir/?api=1&destination=${lat},${lng}&travelmode=driving`

/** The starting point a "you are here" sign's QR code carries: …/map?from=<id> (or the bare id). */
export function signKey(raw: string): string | null {
  try {
    const u = new URL(raw)
    return u.searchParams.get('from')
  } catch {
    return /^[a-z0-9@:,-]{2,60}$/i.test(raw) ? raw : null
  }
}

