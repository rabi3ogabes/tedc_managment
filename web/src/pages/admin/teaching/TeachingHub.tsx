import clsx from 'clsx'
import { CalendarClock, ChevronDown, DoorOpen, Library, MonitorPlay, Play, Radio, UserCog, Users } from 'lucide-react'
import { useEffect, useMemo, useState } from 'react'
import { useTranslation } from 'react-i18next'
import { Link, useNavigate, useSearchParams } from 'react-router-dom'
import { Badge, Button, Card, Empty, ErrorState, PageHeader, Spinner, StatusBadge } from '@/components/ui'
import { useGet } from '@/hooks/useApi'
import { api } from '@/lib/api'
import { toast } from '@/lib/toast'
import { fmtClock, fmtDateTime, pickBi, type ProgramDetail, type TeachingHome, type TeachingProgram } from './api'
import { OwnershipNotice } from './Ownership'

/** The trainer's teaching desk: the programmes they teach with when, where and under whom, the next session to start, and the centre's kits to read or present. */
export default function TeachingHub() {
  const { t, i18n } = useTranslation()
  const en = i18n.language === 'en'
  const [params] = useSearchParams()
  const { data, isLoading, error, refetch } = useGet<{ data: TeachingHome }>('/me/teaching', undefined, { staleTime: 0, refetchInterval: 60000 })
  if (isLoading) return <Spinner />
  if (error || !data) return <ErrorState error={error} onRetry={refetch} />
  const programs = data.data.programs
  return (
    <div className="mx-auto max-w-6xl">
      <PageHeader title={t('teach.title')} subtitle={t('teach.subtitle')} />
      <OwnershipNotice className="mb-6" />
      {!programs.length ? <Card><Empty text={t('teach.none')} icon={<Library className="size-6" />} /></Card> : (
        <div className="space-y-5">
          {programs.map((p) => <ProgramCard key={p.id} p={p} en={en} open={params.get('program') === p.id} onChange={() => void refetch()} />)}
        </div>
      )}
    </div>
  )
}

function ProgramCard({ p, en, open: initial, onChange }: { p: TeachingProgram; en: boolean; open: boolean; onChange: () => void }) {
  const { t } = useTranslation()
  const nav = useNavigate()
  const [open, setOpen] = useState(initial)
  const [now, setNow] = useState(Date.now())
  const [busy, setBusy] = useState(false)
  useEffect(() => { const id = window.setInterval(() => setNow(Date.now()), 1000); return () => window.clearInterval(id) }, [])
  const detail = useGet<{ data: ProgramDetail }>(open ? `/me/teaching/programs/${p.id}` : null, undefined, { staleTime: 0 })
  const s = p.next_session
  const start = s ? new Date(s.starts_at).getTime() : 0
  const end = s ? new Date(s.ends_at).getTime() : 0
  const live = s?.status === 'live'
  const inWindow = !!s && now >= start - 3600000 && now <= end && s.status !== 'completed'
  const countdown = s && now < start ? fmtClock(start - now) : null
  const title = en ? p.title_en : p.title_ar
  const rooms = useMemo(() => [...new Map(p.groups.flatMap((g) => g.rooms).map((r) => [r.ar, r])).values()], [p.groups])

  const startSession = async () => {
    if (!s) return
    setBusy(true)
    try { await api.post(`/me/teaching/sessions/${s.id}/start`); toast(t('teach.sessionStarted')); onChange() } catch (e) { toast((e as { response?: { data?: { message?: string } } }).response?.data?.message ?? t('teach.cannotStart'), 'error') } finally { setBusy(false) }
  }

  return (
    <Card className="!p-0 overflow-hidden">
      <div className="flex flex-wrap items-start gap-4 p-5">
        <div className="min-w-0 flex-1">
          <div className="flex flex-wrap items-center gap-2"><Badge color="gold" className="font-mono">{p.code}</Badge><StatusBadge status={p.status} />{live && <Badge color="green"><Radio className="size-3 animate-pulse" />{t('teach.live')}</Badge>}</div>
          <h2 className="mt-2 text-xl font-bold text-navy-900" dir="auto">{title}</h2>
          <dl className="mt-3 grid gap-x-8 gap-y-2 text-sm sm:grid-cols-2">
            <Row icon={CalendarClock} label={t('teach.when')}>{p.groups[0]?.from ? `${fmtDateTime(p.groups[0].from, en ? 'en' : 'ar')} → ${fmtDateTime(p.groups.at(-1)?.to ?? p.groups[0].to, en ? 'en' : 'ar')}` : `${p.start_date ?? '—'} → ${p.end_date ?? '—'}`}</Row>
            <Row icon={Users} label={t('teach.group')}>{p.groups.map((g) => (en ? g.title_en : g.title_ar)).join('، ') || '—'} · {t('teach.trainees', { n: p.trainees })}</Row>
            <Row icon={DoorOpen} label={t('teach.room')}>{p.delivery_mode === 'online' ? t('teach.online') : rooms.map((r) => pickBi(r, en ? 'en' : 'ar')).join('، ') || t('teach.roomTbd')}</Row>
            <Row icon={UserCog} label={t('teach.supervisor')}>{pickBi(p.groups[0]?.supervisor, en ? 'en' : 'ar') || '—'}</Row>
          </dl>
        </div>
        {s && (
          <div className="w-full shrink-0 rounded-2xl bg-navy-900 p-4 text-white sm:w-72">
            <div className="text-xs font-semibold text-gold-300">{t('teach.nextSession')}</div>
            <div className="mt-1 text-sm font-bold" dir="auto">{(en ? s.title_en : s.title_ar) || `${t('teach.session')} ${s.sequence ?? ''}`}</div>
            <div className="mt-1 text-xs text-white/70">{fmtDateTime(s.starts_at, en ? 'en' : 'ar')}{s.room ? ` · ${pickBi(s.room, en ? 'en' : 'ar')}` : ''}</div>
            {countdown && <div className="mt-2 font-display text-2xl font-bold tabular-nums text-gold-200" dir="ltr">{countdown}</div>}
            <div className="mt-3 flex gap-2">
              {live ? <span className="inline-flex items-center gap-1.5 rounded-xl bg-emerald-500/20 px-3 py-2 text-xs font-bold text-emerald-300"><Radio className="size-4 animate-pulse" />{t('teach.live')}</span>
                : <Button size="sm" variant="gold" disabled={!inWindow} loading={busy} icon={<Play className="size-4" />} onClick={startSession}>{t('teach.startSession')}</Button>}
              {!inWindow && !live && <span className="self-center text-[11px] text-white/55">{t('teach.startHint')}</span>}
            </div>
          </div>
        )}
      </div>

      <button type="button" onClick={() => setOpen((v) => !v)} aria-expanded={open} className="flex w-full items-center justify-between border-t border-navy-100 bg-ivory px-5 py-3 text-sm font-bold text-navy-900 hover:bg-gold-50">
        <span className="inline-flex items-center gap-2"><Library className="size-4 text-gold-600" />{t('teach.kits')} ({p.kits.length})</span>
        <ChevronDown className={clsx('size-4 transition', open && 'rotate-180')} />
      </button>
      {open && (
        <div className="space-y-3 p-5">
          {!p.kits.length && <p className="text-sm text-slate-500">{t('teach.noKits')}</p>}
          {detail.isLoading ? <Spinner /> : (detail.data?.data.kits ?? []).map((k) => (
            <div key={k.id} className="rounded-2xl border border-navy-100 bg-white p-4">
              <div className="flex flex-wrap items-center gap-3">
                <div className="min-w-0 flex-1"><div className="font-bold text-navy-900" dir="auto">{en ? k.title_en : k.title_ar}</div><div className="text-xs text-slate-500"><span className="font-mono">{k.code}</span> · v{k.version} · {t('teach.filesN', { n: k.files.length })}</div></div>
                <Button size="sm" variant="outline" icon={<Library className="size-4" />} onClick={() => nav(`/admin/teaching/kits/${k.id}?program=${p.id}`)}>{t('teach.openKit')}</Button>
              </div>
              {k.files.some((f) => f.presentable) && (
                <ul className="mt-3 flex flex-wrap gap-2">
                  {k.files.filter((f) => f.presentable).map((f) => (
                    <li key={f.id}><Link to={`/admin/teaching/present/${k.id}/${f.id}?program=${p.id}${s && inWindow ? `&session=${s.id}` : ''}`} className="inline-flex items-center gap-2 rounded-xl bg-navy-900 px-3 py-2 text-xs font-bold text-white hover:bg-navy-800"><MonitorPlay className="size-4 text-gold-300" /><span className="max-w-56 truncate" dir="auto">{f.name}</span></Link></li>
                  ))}
                </ul>
              )}
            </div>
          ))}
        </div>
      )}
    </Card>
  )
}

function Row({ icon: Icon, label, children }: { icon: typeof Users; label: string; children: React.ReactNode }) {
  return <div className="flex items-start gap-2"><Icon className="mt-0.5 size-4 shrink-0 text-gold-600" /><div className="min-w-0"><dt className="text-[11px] font-semibold text-slate-500">{label}</dt><dd className="text-navy-900" dir="auto">{children}</dd></div></div>
}
