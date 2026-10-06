/* eslint-disable @typescript-eslint/no-explicit-any */
import { ArrowDown, ArrowUp, Eye, EyeOff, SlidersHorizontal } from 'lucide-react'
import { useEffect, useState } from 'react'
import { useTranslation } from 'react-i18next'
import { Button } from '@/components/ui'
import { useGet } from '@/hooks/useApi'
import { api } from '@/lib/api'
import { toast } from '@/lib/toast'
import WidgetCard from './Widget'

const day = (d: Date) => d.toISOString().slice(0, 10)

/** The dashboard of the active role: its widgets for a chosen period, which the person can hide and reorder. */
export default function RoleDashboard() {
  const { t } = useTranslation()
  const layout = useGet<{ data: { role: string; widgets: any[] } }>('/dashboard', undefined, { staleTime: 0 })
  const [from, setFrom] = useState(() => day(new Date(new Date().getFullYear(), 0, 1)))
  const [to, setTo] = useState(() => day(new Date()))
  const [editing, setEditing] = useState(false)
  const [items, setItems] = useState<any[] | null>(null)
  useEffect(() => { if (layout.data) setItems(layout.data.data.widgets) }, [layout.data])
  if (!items) return null

  const persist = async (next: any[]) => {
    setItems(next)
    try { await api.put('/dashboard/layout', { order: next.map((w) => w.key), hidden: next.filter((w) => w.hidden).map((w) => w.key) }) } catch { toast(String(t('common.error')), 'error') }
  }
  const move = (i: number, d: number) => { const n = [...items]; const j = i + d; if (j < 0 || j >= n.length) return; [n[i], n[j]] = [n[j], n[i]]; void persist(n) }
  const toggle = (i: number) => void persist(items.map((w, k) => (k === i ? { ...w, hidden: !w.hidden } : w)))
  const visible = items.filter((w) => editing || !w.hidden)

  return (
    <section className="mb-8" aria-label={String(t('rep.dash.title'))}>
      <div className="mb-3 flex flex-wrap items-center gap-3">
        <h2 className="text-lg font-bold text-navy-900">{t('rep.dash.title')}</h2>
        <div className="ms-auto flex flex-wrap items-center gap-2 text-sm">
          <label className="flex items-center gap-1.5 text-slate-500">{t('rep.from')}<input type="date" className="rounded-xl border border-navy-100 px-2 py-1" value={from} max={to} onChange={(e) => setFrom(e.target.value)} /></label>
          <label className="flex items-center gap-1.5 text-slate-500">{t('rep.to')}<input type="date" className="rounded-xl border border-navy-100 px-2 py-1" value={to} min={from} onChange={(e) => setTo(e.target.value)} /></label>
          <Button size="sm" variant={editing ? 'primary' : 'outline'} icon={<SlidersHorizontal className="size-4" />} onClick={() => setEditing(!editing)}>{editing ? t('rep.dash.done') : t('rep.dash.customise')}</Button>
        </div>
      </div>
      <div className="grid gap-4 md:grid-cols-2 xl:grid-cols-3">
        {visible.map((w) => {
          const i = items.findIndex((x) => x.key === w.key)
          return (
            <div key={w.key} className={w.hidden ? 'opacity-50' : ''}>
              <WidgetCard meta={w} from={from} to={to} controls={editing ? (
                <span className="flex items-center gap-0.5">
                  <button type="button" className="rounded p-1 text-slate-400 hover:bg-navy-50 hover:text-navy-900" onClick={() => move(i, -1)} aria-label={String(t('rep.dash.up'))}><ArrowUp className="size-3.5" /></button>
                  <button type="button" className="rounded p-1 text-slate-400 hover:bg-navy-50 hover:text-navy-900" onClick={() => move(i, 1)} aria-label={String(t('rep.dash.down'))}><ArrowDown className="size-3.5" /></button>
                  <button type="button" className="rounded p-1 text-slate-400 hover:bg-navy-50 hover:text-navy-900" onClick={() => toggle(i)} aria-label={String(w.hidden ? t('rep.dash.show') : t('rep.dash.hide'))}>{w.hidden ? <EyeOff className="size-3.5" /> : <Eye className="size-3.5" />}</button>
                </span>
              ) : undefined} />
            </div>
          )
        })}
      </div>
    </section>
  )
}
