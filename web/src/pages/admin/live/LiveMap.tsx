import 'leaflet/dist/leaflet.css'
import L from 'leaflet'
import { useEffect, useMemo, useRef } from 'react'
import { useTranslation } from 'react-i18next'
import { MapContainer, Marker, Popup, TileLayer, useMap } from 'react-leaflet'

export type Place = { lat: number; lng: number; country: string | null; city: string | null; count: number; staff: number; sources: Record<string, number>; users: { name: string; team: string; source: string }[] }

/** Country code → flag emoji. */
export const flag = (code: string | null) => (code && code.length === 2 ? String.fromCodePoint(...[...code.toUpperCase()].map((c) => 127397 + c.charCodeAt(0))) : '🌐')

function Fit({ places }: { places: Place[] }) {
  const map = useMap()
  const key = useRef('')
  useEffect(() => {
    // Re-fit only when the set of places changes, so the map does not jump on every refresh.
    const next = places.map((p) => `${p.lat},${p.lng}`).sort().join('|')
    if (next === key.current || places.length === 0) return
    key.current = next
    if (places.length === 1) map.setView([places[0].lat, places[0].lng], 8, { animate: true })
    else map.fitBounds(L.latLngBounds(places.map((p) => [p.lat, p.lng] as [number, number])), { padding: [60, 60], maxZoom: 9, animate: true })
  }, [places, map])
  return null
}

const bubble = (p: Place) => {
  const size = Math.min(64, 30 + Math.sqrt(p.count) * 12)
  const staffShare = p.count ? Math.round((p.staff / p.count) * 100) : 0
  return L.divIcon({
    className: '',
    iconSize: [size, size],
    html: `<div class="live-bubble" style="width:${size}px;height:${size}px;--share:${staffShare}%"><span class="live-bubble-ring"></span><b>${p.count}</b></div>`,
  })
}

/** Live map of who is online and where; bubbles grow with the number of people and breathe while they are connected. */
export default function LiveMap({ places }: { places: Place[] }) {
  const { t, i18n } = useTranslation()
  const names = useMemo(() => new Intl.DisplayNames([i18n.language], { type: 'region' }), [i18n.language])
  return (
    <MapContainer center={[25.35, 51.3]} zoom={6} minZoom={2} worldCopyJump scrollWheelZoom={false} className="h-full w-full !rounded-2xl" attributionControl={false}>
      <TileLayer url="https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png" attribution="&copy; OpenStreetMap contributors" />
      <Fit places={places} />
      {places.map((p) => (
        <Marker key={`${p.lat},${p.lng}`} position={[p.lat, p.lng]} icon={bubble(p)}>
          <Popup>
            <div className="min-w-44 text-sm" dir={i18n.dir()}>
              <div className="font-bold text-navy-900">{flag(p.country)} {p.city ?? (p.country ? names.of(p.country) : '—')}</div>
              {p.country && p.city && <div className="text-xs text-slate-500">{names.of(p.country)}</div>}
              <div className="mt-1 font-semibold">{t('liveGeo.people', { count: p.count })}</div>
              <ul className="mt-1 space-y-0.5 text-xs text-slate-600">{p.users.map((u, i) => <li key={i}>• {u.name} <span className="text-slate-400">· {t(`liveGeo.sources.${u.source}`)}</span></li>)}</ul>
              {p.count > p.users.length && <div className="text-[11px] text-slate-400">+{p.count - p.users.length}</div>}
            </div>
          </Popup>
        </Marker>
      ))}
    </MapContainer>
  )
}
