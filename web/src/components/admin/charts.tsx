import type { ReactNode } from 'react'
import { useTranslation } from 'react-i18next'
import { Area, AreaChart, Bar, BarChart, CartesianGrid, Cell, Legend, Pie, PieChart, ResponsiveContainer, Tooltip, XAxis, YAxis } from 'recharts'
import { fmt } from '@/lib/format'

/**
 * Categorical palette validated for colour-vision deficiency (adjacent ΔE ≥ 8).
 * Some slots are below 3:1 contrast on white, so every multi-series chart ships a
 * legend and hover tooltips. Colour follows the entity, assigned in this fixed order.
 */
export const SERIES = ['#2a78d6', '#d4912a', '#1baf7a', '#7d5fd9', '#e87ba4']
export const SINGLE = '#264775'

const axis = { stroke: '#94a3b8', fontSize: 12, tickLine: false, axisLine: false }

export function ChartBox({ height = 280, children }: { height?: number; children: ReactNode }) {
  return <div style={{ height }} dir="ltr">{children as never}</div>
}

function TooltipBox({ active, payload, label, formatter }: { active?: boolean; payload?: { name: string; value: number; color: string }[]; label?: string; formatter?: (v: number) => string }) {
  if (!active || !payload?.length) return null
  return (
    <div className="rounded-xl border border-navy-100 bg-white px-3 py-2 text-xs shadow-glass">
      {label && <div className="mb-1 font-bold text-navy-900">{label}</div>}
      {payload.map((p) => (
        <div key={p.name} className="flex items-center gap-2 text-slate-600">
          <span className="size-2.5 rounded-sm" style={{ background: p.color }} />
          <span>{p.name}</span>
          <span className="ms-auto font-bold text-navy-900">{formatter ? formatter(p.value) : fmt.number(p.value)}</span>
        </div>
      ))}
    </div>
  )
}

export function TrendChart({ data, series }: { data: Record<string, number | string>[]; series: { key: string; label: string }[] }) {
  return (
    <ChartBox>
      <ResponsiveContainer>
        <AreaChart data={data} margin={{ top: 10, right: 12, left: -12, bottom: 0 }}>
          <defs>
            {series.map((s, i) => (
              <linearGradient key={s.key} id={`g-${s.key}`} x1="0" y1="0" x2="0" y2="1">
                <stop offset="0" stopColor={SERIES[i]} stopOpacity={0.25} />
                <stop offset="1" stopColor={SERIES[i]} stopOpacity={0} />
              </linearGradient>
            ))}
          </defs>
          <CartesianGrid stroke="#e2e8f0" strokeDasharray="3 3" vertical={false} />
          <XAxis dataKey="label" {...axis} />
          <YAxis {...axis} allowDecimals={false} />
          <Tooltip content={<TooltipBox />} cursor={{ stroke: '#a29475', strokeWidth: 1 }} />
          <Legend iconType="circle" wrapperStyle={{ fontSize: 12 }} />
          {series.map((s, i) => (
            <Area key={s.key} type="monotone" dataKey={s.key} name={s.label} stroke={SERIES[i]} strokeWidth={2} fill={`url(#g-${s.key})`} activeDot={{ r: 5, strokeWidth: 2, stroke: '#fff' }} />
          ))}
        </AreaChart>
      </ResponsiveContainer>
    </ChartBox>
  )
}

export function BarsChart({ data, dataKey, nameKey = 'name', label, height = 280, horizontal = false, formatter }: {
  data: Record<string, unknown>[]; dataKey: string; nameKey?: string; label: string; height?: number; horizontal?: boolean; formatter?: (v: number) => string
}) {
  return (
    <ChartBox height={height}>
      <ResponsiveContainer>
        <BarChart data={data} layout={horizontal ? 'vertical' : 'horizontal'} margin={{ top: 8, right: 16, left: horizontal ? 8 : -12, bottom: 0 }} barCategoryGap={horizontal ? 6 : '25%'}>
          <CartesianGrid stroke="#e2e8f0" strokeDasharray="3 3" horizontal={!horizontal} vertical={horizontal} />
          {horizontal ? (
            <>
              <XAxis type="number" {...axis} />
              <YAxis type="category" dataKey={nameKey} {...axis} width={150} tick={{ fontSize: 11, fill: '#475569' }} />
            </>
          ) : (
            <>
              <XAxis dataKey={nameKey} {...axis} tick={{ fontSize: 11, fill: '#475569' }} interval={0} />
              <YAxis {...axis} />
            </>
          )}
          <Tooltip content={<TooltipBox formatter={formatter} />} cursor={{ fill: 'rgb(200 162 74 / 0.08)' }} />
          <Bar dataKey={dataKey} name={label} fill={SINGLE} radius={horizontal ? [0, 4, 4, 0] : [4, 4, 0, 0]} maxBarSize={32} />
        </BarChart>
      </ResponsiveContainer>
    </ChartBox>
  )
}

export function DonutChart({ data, height = 260 }: { data: { name: string; value: number }[]; height?: number }) {
  const total = data.reduce((s, d) => s + d.value, 0)
  return (
    <div className="grid items-center gap-4 sm:grid-cols-2">
      <ChartBox height={height}>
        <ResponsiveContainer>
          <PieChart>
            <Pie data={data} dataKey="value" nameKey="name" innerRadius="62%" outerRadius="90%" paddingAngle={2} stroke="#fff" strokeWidth={2}>
              {data.map((_, i) => <Cell key={i} fill={SERIES[i % SERIES.length]} />)}
            </Pie>
            <Tooltip content={<TooltipBox />} />
          </PieChart>
        </ResponsiveContainer>
      </ChartBox>
      <ul className="space-y-2">
        {data.map((d, i) => (
          <li key={d.name} className="flex items-center gap-2 text-sm">
            <span className="size-3 rounded-sm" style={{ background: SERIES[i % SERIES.length] }} />
            <span className="text-slate-600">{d.name}</span>
            <span className="ms-auto font-bold text-navy-900">{fmt.number(d.value)}</span>
            <span className="w-12 text-end text-xs text-slate-400">{total ? fmt.percent((d.value / total) * 100) : ''}</span>
          </li>
        ))}
      </ul>
    </div>
  )
}

/** Radial gauge used for the headline Training Impact Score. */
export function ScoreRing({ value, size = 150, label }: { value: number | null | undefined; size?: number; label?: string }) {
  const { t } = useTranslation()
  const v = Math.max(0, Math.min(100, value ?? 0))
  const r = size / 2 - 10
  const c = 2 * Math.PI * r
  return (
    <div className="relative inline-grid place-items-center" style={{ width: size, height: size }}>
      <svg width={size} height={size} className="-rotate-90">
        <circle cx={size / 2} cy={size / 2} r={r} stroke="rgb(255 255 255 / 0.12)" strokeWidth="10" fill="none" />
        <circle cx={size / 2} cy={size / 2} r={r} stroke="url(#ring)" strokeWidth="10" fill="none" strokeLinecap="round" strokeDasharray={c} strokeDashoffset={c - (v / 100) * c} style={{ transition: 'stroke-dashoffset 1s ease' }} />
        <defs><linearGradient id="ring"><stop offset="0" stopColor="#e5cd8a" /><stop offset="1" stopColor="#a8852f" /></linearGradient></defs>
      </svg>
      <div className="absolute text-center">
        <div className="font-display text-4xl font-bold text-gold-300">{value === null || value === undefined ? '—' : fmt.number(value, 1)}</div>
        <div className="text-xs text-white/60">{label ?? t('admin.kpis.impact_score')}</div>
      </div>
    </div>
  )
}
