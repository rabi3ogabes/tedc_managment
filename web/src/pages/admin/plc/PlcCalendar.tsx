import { ChevronLeft, ChevronRight, MapPin, Video } from 'lucide-react'
import { useMemo, useState } from 'react'
import { useTranslation } from 'react-i18next'
import { Link } from 'react-router-dom'
import { Badge, Card, Spinner } from '@/components/ui'
import { useGet } from '@/hooks/useApi'
import { STATE_TONE, type PlcState } from './plcApi'

type Session = { id: string; workshop_id: string; code: string; title: string; title_en?: string | null; session_title?: string | null; starts_at: string; ends_at: string; mode: 'in_person' | 'online' | 'hybrid'; state: PlcState; scenario: string; school?: string | null; region?: string | null; room?: string | null; trainers: string[] }
type Payload = { days: { date: string; training_allowed: boolean; kind?: string | null }[]; sessions: Session[] }
const p2 = (n: number) => String(n).padStart(2, '0')
const ymd = (d: Date) => `${d.getFullYear()}-${p2(d.getMonth() + 1)}-${p2(d.getDate())}`

/** The PLC calendar: a month of workshop sessions inside the person's scope, closed days shaded, filters by school, region, trainer and delivery mode. */
export default function PlcCalendar() {
  const { t, i18n } = useTranslation()
  const en = i18n.language === 'en'
  const [month, setMonth] = useState(() => { const d = new Date(); return new Date(d.getFullYear(), d.getMonth(), 1) })
  const [filters, setFilters] = useState<{ school: string; region: string; trainer: string; mode: string }>({ school: '', region: '', trainer: '', mode: '' })
  const [picked, setPicked] = useState<string | null>(null)
  const from = ymd(month)
  const to = ymd(new Date(month.getFullYear(), month.getMonth() + 1, 0))
  const res = useGet<{ data: Payload }>('/admin/plc/calendar', { from, to, mode: filters.mode || undefined }, { staleTime: 30_000 })
  const d = res.data?.data
  const sessions = useMemo(() => (d?.sessions ?? []).filter((s) => (!filters.school || s.school === filters.school) && (!filters.region || s.region === filters.region) && (!filters.trainer || s.trainers.includes(filters.trainer))), [d, filters])
  const options = useMemo(() => ({
    school: [...new Set((d?.sessions ?? []).map((s) => s.school).filter(Boolean))] as string[],
    region: [...new Set((d?.sessions ?? []).map((s) => s.region).filter(Boolean))] as string[],
    trainer: [...new Set((d?.sessions ?? []).flatMap((s) => s.trainers))],
  }), [d])
  const byDay = useMemo(() => { const m: Record<string, Session[]> = {}; for (const s of sessions) { const k = ymd(new Date(s.starts_at)); (m[k] ??= []).push(s) } return m }, [sessions])
  const dayInfo = useMemo(() => Object.fromEntries((d?.days ?? []).map((x) => [x.date, x])), [d])
  const first = new Date(month.getFullYear(), month.getMonth(), 1)
  const lead = first.getDay() // Sunday first, as the Qatari week
  const daysInMonth = new Date(month.getFullYear(), month.getMonth() + 1, 0).getDate()
  const cells: (string | null)[] = [...Array(lead).fill(null), ...Array.from({ length: daysInMonth }, (_, i) => ymd(new Date(month.getFullYear(), month.getMonth(), i + 1)))]
  while (cells.length % 7) cells.push(null)
  const weekdays = t('plc.calendar.weekdays', { returnObjects: true }) as string[]
  const time = (iso: string) => new Date(iso).toLocaleTimeString(en ? 'en-GB' : 'ar-QA', { hour: '2-digit', minute: '2-digit' })
  const pickedList = picked ? byDay[picked] ?? [] : []

  return (
    <div className="space-y-4">
      <div className="flex flex-wrap items-center gap-2">
        <div className="flex items-center gap-1 rounded-2xl border border-slate-200 bg-white px-2 py-1">
          <button type="button" className="rounded-lg p-1 hover:bg-slate-100" onClick={() => setMonth(new Date(month.getFullYear(), month.getMonth() - 1, 1))} aria-label="prev"><ChevronRight className="size-4" /></button>
          <span className="min-w-36 text-center font-display text-sm font-bold text-navy-900">{month.toLocaleDateString(en ? 'en-GB' : 'ar-QA', { month: 'long', year: 'numeric' })}</span>
          <button type="button" className="rounded-lg p-1 hover:bg-slate-100" onClick={() => setMonth(new Date(month.getFullYear(), month.getMonth() + 1, 1))} aria-label="next"><ChevronLeft className="size-4" /></button>
        </div>
        <select className="input w-auto text-xs" value={filters.school} onChange={(e) => setFilters({ ...filters, school: e.target.value })}><option value="">{t('plc.calendar.allSchools')}</option>{options.school.map((x) => <option key={x} value={x}>{x}</option>)}</select>
        <select className="input w-auto text-xs" value={filters.region} onChange={(e) => setFilters({ ...filters, region: e.target.value })}><option value="">{t('plc.calendar.allRegions')}</option>{options.region.map((x) => <option key={x} value={x}>{x}</option>)}</select>
        <select className="input w-auto text-xs" value={filters.trainer} onChange={(e) => setFilters({ ...filters, trainer: e.target.value })}><option value="">{t('plc.calendar.allTrainers')}</option>{options.trainer.map((x) => <option key={x} value={x}>{x}</option>)}</select>
        <select className="input w-auto text-xs" value={filters.mode} onChange={(e) => setFilters({ ...filters, mode: e.target.value })}><option value="">{t('plc.calendar.allModes')}</option>{['in_person', 'online', 'hybrid'].map((m) => <option key={m} value={m}>{t(`plc.mode.${m}`)}</option>)}</select>
        <span className="ms-auto text-xs text-slate-500">{t('plc.calendar.sessionsN', { n: sessions.length })}</span>
      </div>
      {res.isLoading && !d ? <Spinner /> : (
        <div className="grid gap-4 lg:grid-cols-[1fr_300px]">
          <Card padded={false}>
            <div className="grid grid-cols-7 border-b border-slate-100 bg-slate-50 text-center text-[11px] font-semibold text-slate-500">{weekdays.map((w) => <div key={w} className="py-2">{w}</div>)}</div>
            <div className="grid grid-cols-7">
              {cells.map((day, i) => {
                if (!day) return <div key={`e${i}`} className="min-h-24 border-b border-e border-slate-100 bg-slate-50/50" />
                const info = dayInfo[day]
                const closed = info && !info.training_allowed
                const list = byDay[day] ?? []
                return (
                  <button key={day} type="button" onClick={() => setPicked(day)} className={`min-h-24 border-b border-e border-slate-100 p-1.5 text-start align-top transition hover:bg-gold-50 ${closed ? 'bg-slate-100/70' : ''} ${picked === day ? 'ring-2 ring-inset ring-gold-400' : ''}`}>
                    <div className="flex items-center justify-between"><span className={`text-xs font-semibold ${closed ? 'text-slate-400' : 'text-navy-900'}`}>{Number(day.slice(-2))}</span>{closed && <span className="text-[9px] text-slate-400">{t(`plc.calendar.kind.${info.kind ?? 'closed'}`, { defaultValue: t('plc.calendar.closed') })}</span>}</div>
                    <div className="mt-1 space-y-0.5">{list.slice(0, 3).map((s) => <div key={s.id} className={`truncate rounded px-1 py-0.5 text-[10px] text-white ${s.mode === 'online' ? 'bg-navy-700' : s.mode === 'hybrid' ? 'bg-gold-700' : 'bg-navy-900'}`}>{time(s.starts_at)} {en && s.title_en ? s.title_en : s.title}</div>)}{list.length > 3 && <div className="text-[10px] text-slate-500">+{list.length - 3}</div>}</div>
                  </button>
                )
              })}
            </div>
            <div className="flex flex-wrap gap-3 px-3 py-2 text-[11px] text-slate-500"><span><i className="me-1 inline-block size-2.5 rounded-sm bg-navy-900" />{t('plc.mode.in_person')}</span><span><i className="me-1 inline-block size-2.5 rounded-sm bg-navy-700" />{t('plc.mode.online')}</span><span><i className="me-1 inline-block size-2.5 rounded-sm bg-gold-700" />{t('plc.mode.hybrid')}</span><span><i className="me-1 inline-block size-2.5 rounded-sm bg-slate-200" />{t('plc.calendar.closed')}</span></div>
          </Card>
          <Card>
            <div className="mb-2 font-display text-sm font-bold text-navy-900">{picked ? new Date(picked).toLocaleDateString(en ? 'en-GB' : 'ar-QA', { weekday: 'long', day: 'numeric', month: 'long' }) : t('plc.calendar.pickDay')}</div>
            {picked && !pickedList.length && <p className="text-sm text-slate-400">{t('plc.calendar.nothing')}</p>}
            <ul className="space-y-2">{pickedList.map((s) => (
              <li key={s.id} className="rounded-2xl border border-slate-200 p-3 text-sm">
                <Link to={`/admin/communities/workshops/${s.workshop_id}/schedule`} className="font-semibold text-navy-900 hover:text-gold-700">{en && s.title_en ? s.title_en : s.title}</Link>
                <div className="mt-1 flex flex-wrap items-center gap-1"><Badge color={STATE_TONE[s.state] ?? 'gray'}>{t(`plc.state.${s.state}`)}</Badge><Badge color="gray">{s.mode === 'online' ? <Video className="size-3" /> : <MapPin className="size-3" />} {t(`plc.mode.${s.mode}`)}</Badge></div>
                <div className="mt-1 text-xs text-slate-500">{time(s.starts_at)} – {time(s.ends_at)}{s.room ? ` · ${s.room}` : ''}{s.school ? ` · ${s.school}` : ''}</div>
                {!!s.trainers.length && <div className="text-xs text-slate-500">{s.trainers.join('، ')}</div>}
              </li>))}</ul>
          </Card>
        </div>
      )}
    </div>
  )
}
