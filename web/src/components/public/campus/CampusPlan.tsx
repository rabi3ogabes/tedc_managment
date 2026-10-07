import { useId } from 'react'
import { FLOOR_ROUTES, PLAN, type Floor } from './campusData'
import { PLANS, type Kind, type Room } from './planData'

type Look = { top: string; side: string; text: string; stroke: string }

/** The brand's own colours, read from the site's theme variables, so a change in the Brand Studio repaints the plans. */
const LOOKS: Record<Floor, { plate: string; plateStroke: string; corridor: string; kinds: Record<Kind, Look>; note: string }> = {
  ground: {
    plate: '#ffffff', plateStroke: 'var(--color-navy-100)', corridor: 'var(--color-ivory-dark)', note: 'var(--color-navy-900)',
    kinds: {
      train: { top: 'var(--color-navy-900)', side: 'var(--color-navy-950)', text: '#fff', stroke: 'var(--color-navy-800)' },
      lab: { top: 'var(--color-navy-700)', side: 'var(--color-navy-900)', text: '#fff', stroke: 'var(--color-navy-600)' },
      public: { top: 'var(--color-gold-500)', side: 'var(--color-gold-700)', text: 'var(--color-navy-950)', stroke: 'var(--color-gold-400)' },
      wc: { top: 'var(--color-gold-100)', side: 'var(--color-gold-300)', text: 'var(--color-navy-800)', stroke: 'var(--color-gold-300)' },
      pray: { top: 'var(--color-gold-300)', side: 'var(--color-gold-500)', text: 'var(--color-navy-950)', stroke: 'var(--color-gold-400)' },
      office: { top: 'var(--color-navy-100)', side: 'var(--color-navy-500)', text: 'var(--color-navy-800)', stroke: 'var(--color-navy-100)' },
      dark: { top: 'var(--color-navy-950)', side: '#2b0a14', text: 'var(--color-gold-300)', stroke: 'var(--color-navy-900)' },
      stair: { top: 'var(--color-ivory)', side: 'var(--color-gold-300)', text: 'var(--color-navy-800)', stroke: 'var(--color-gold-300)' },
    },
  },
  first: {
    plate: 'var(--color-navy-950)', plateStroke: 'var(--color-gold-500)', corridor: 'var(--color-navy-900)', note: 'var(--color-gold-300)',
    kinds: {
      train: { top: 'var(--color-navy-800)', side: '#2b0a14', text: 'var(--color-gold-100)', stroke: 'var(--color-gold-500)' },
      lab: { top: 'var(--color-gold-500)', side: 'var(--color-gold-700)', text: 'var(--color-navy-950)', stroke: 'var(--color-gold-300)' },
      public: { top: 'var(--color-gold-300)', side: 'var(--color-gold-500)', text: 'var(--color-navy-950)', stroke: 'var(--color-gold-100)' },
      wc: { top: 'var(--color-navy-900)', side: '#2b0a14', text: 'var(--color-gold-300)', stroke: 'var(--color-navy-700)' },
      pray: { top: 'var(--color-gold-600)', side: 'var(--color-gold-700)', text: 'var(--color-ivory)', stroke: 'var(--color-gold-400)' },
      office: { top: 'var(--color-navy-700)', side: 'var(--color-navy-900)', text: 'var(--color-ivory)', stroke: 'var(--color-navy-600)' },
      dark: { top: '#2b0a14', side: '#1a060d', text: 'var(--color-gold-300)', stroke: 'var(--color-gold-600)' },
      stair: { top: 'var(--color-gold-100)', side: 'var(--color-gold-400)', text: 'var(--color-navy-800)', stroke: 'var(--color-gold-300)' },
    },
  },
}

const DEPTH = 9

function shape(r: Room): string {
  const { x, y, w, h } = r
  const c = Math.min(r.chamfer ?? 0, w / 3, h / 3)
  if (!c) return `M${x + 7} ${y}H${x + w - 7}Q${x + w} ${y} ${x + w} ${y + 7}V${y + h - 7}Q${x + w} ${y + h} ${x + w - 7} ${y + h}H${x + 7}Q${x} ${y + h} ${x} ${y + h - 7}V${y + 7}Q${x} ${y} ${x + 7} ${y}Z`
  return `M${x + c} ${y}H${x + w - c}L${x + w} ${y + c}V${y + h - c}L${x + w - c} ${y + h}H${x + c}L${x} ${y + h - c}V${y + c}Z`
}

function Glyph({ x, y, color }: { x: number; y: number; color: string }) {
  return <g transform={`translate(${x} ${y})`} fill={color} opacity=".85"><circle cy="-13" r="6" /><path d="M-8 -4h16l4 22h-6l-1 14h-10l-1-14h-6z" /></g>
}

function Steps({ r, color }: { r: Pick<Room, 'x' | 'y' | 'w' | 'h'>; color: string }) {
  const n = 5
  return (
    <g stroke={color} strokeWidth="2.5" opacity=".7" strokeLinecap="round">
      {Array.from({ length: n }, (_, i) => <line key={i} x1={r.x + 12} x2={r.x + r.w - 12} y1={r.y + 12 + ((r.h - 24) / (n - 1)) * i} y2={r.y + 12 + ((r.h - 24) / (n - 1)) * i} />)}
    </g>
  )
}

/** A floor of the centre drawn in the site's brand colours: a light, ivory day plan for the ground floor and a dark, gold night plan for the first. */
export default function CampusPlan({ floor, lang }: { floor: Floor; lang: 'ar' | 'en' }) {
  const uid = useId().replace(/:/g, '')
  const look = LOOKS[floor]
  const { rooms, polys } = PLANS[floor]
  const routes = Object.values(FLOOR_ROUTES[floor].paths)
  const label = (o: { ar?: string; en?: string }) => (lang === 'en' ? o.en : o.ar)

  return (
    <svg viewBox={`0 0 ${PLAN.w} ${PLAN.h}`} className="size-full" role="img" aria-label={floor === 'ground' ? (lang === 'ar' ? 'مخطط الطابق الأرضي' : 'Ground floor plan') : (lang === 'ar' ? 'مخطط الطابق الأول' : 'First floor plan')}>
      <defs>
        <filter id={`${uid}-shadow`} x="-10%" y="-10%" width="120%" height="130%"><feDropShadow dx="0" dy="14" stdDeviation="14" floodColor="#000" floodOpacity={floor === 'ground' ? 0.16 : 0.45} /></filter>
        <linearGradient id={`${uid}-sheen`} x1="0" y1="0" x2="0" y2="1"><stop offset="0" stopColor="#fff" stopOpacity=".2" /><stop offset="1" stopColor="#fff" stopOpacity="0" /></linearGradient>
      </defs>

      {/* the floor: every room grown a little, plus the walking corridors */}
      <g filter={`url(#${uid}-shadow)`}>
        <g fill={look.plate} stroke={look.plate} strokeLinejoin="round" strokeLinecap="round" strokeWidth="26">
          {rooms.map((r, i) => <rect key={i} x={r.x} y={r.y} width={r.w} height={r.h} rx="10" />)}
          {polys.map((p, i) => <polygon key={i} points={p.points.map((q) => q.join(',')).join(' ')} />)}
          {routes.map((pts, i) => <polyline key={i} points={pts.map((q) => q.join(',')).join(' ')} fill="none" strokeWidth="46" />)}
        </g>
      </g>
      <g fill="none" stroke={look.corridor} strokeWidth="30" strokeLinecap="round" strokeLinejoin="round" opacity={floor === 'ground' ? 0.9 : 0.75}>
        {routes.map((pts, i) => <polyline key={i} points={pts.map((q) => q.join(',')).join(' ')} />)}
      </g>

      {/* rooms: a darker slab underneath gives each one its depth, a light sheen its top */}
      {rooms.map((r, i) => {
        const k = look.kinds[r.kind]
        const cx = r.x + r.w / 2
        const cy = r.y + r.h / 2
        const text = label(r)
        const fs = r.fs ?? (r.kind === 'public' ? 26 : 22)
        return (
          <g key={i}>
            <path d={shape(r)} transform={`translate(0 ${DEPTH})`} fill={k.side} />
            <path d={shape(r)} fill={k.top} stroke={k.stroke} strokeOpacity=".7" strokeWidth="2" />
            <path d={shape(r)} fill={`url(#${uid}-sheen)`} />
            {r.kind === 'wc' && <Glyph x={cx} y={cy + 2} color={k.text} />}
            {r.kind === 'stair' && <Steps r={r} color={k.text} />}
            {text && (
              <text x={cx} y={cy} transform={r.vertical ? `rotate(-90 ${cx} ${cy})` : undefined} textAnchor="middle" dominantBaseline="central" fill={k.text} fontSize={fs} fontWeight="700" style={{ fontFamily: 'inherit', letterSpacing: lang === 'en' ? '0.01em' : 0 }}>
                {text}
              </text>
            )}
          </g>
        )
      })}
      {polys.map((p, i) => {
        const k = look.kinds[p.kind]
        const text = label(p)
        const d = `M${p.points.map((q) => q.join(' ')).join('L')}Z`
        return (
          <g key={i}>
            <path d={d} transform={`translate(0 ${DEPTH})`} fill={k.side} />
            <path d={d} fill={k.top} stroke={k.stroke} strokeOpacity=".7" strokeWidth="2" strokeLinejoin="round" />
            <path d={d} fill={`url(#${uid}-sheen)`} />
            {p.kind === 'stair' && <Steps r={{ x: p.points[0][0] + 14, y: p.points[0][1] + 14, w: 38, h: 38 }} color={k.text} />}
            {text && p.at && (p.fs ?? 22) > 1 && <text x={p.at[0]} y={p.at[1]} textAnchor="middle" dominantBaseline="central" fill={k.text} fontSize={p.fs ?? 22} fontWeight="700" style={{ fontFamily: 'inherit' }}>{text}</text>}
          </g>
        )
      })}
    </svg>
  )
}
