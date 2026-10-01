import clsx from 'clsx'
import { BellRing, Radio, Save, Users, Video } from 'lucide-react'
import { useState } from 'react'
import { useTranslation } from 'react-i18next'
import { Badge, Button, Card, CardTitle, Progress, Spinner, StatCard, Table, Td } from '@/components/ui'
import { useGet } from '@/hooks/useApi'
import { api, errorMessage } from '@/lib/api'
import { useAuth } from '@/lib/auth'
import { fmt } from '@/lib/format'
import type { Program } from '@/lib/types'
import { DeliveryStep, emptyRemote, type RemoteSettings } from '../smart/RemoteParts'

type Tracking = {
  sessions: { id: string; sequence: number; title: string; mode: string; starts_at: string; ends_at: string; state: 'upcoming' | 'live' | 'ended'; expected: number; joined: number; late: number; not_joined: number; avg_minutes: number; has_link: boolean; has_recording: boolean }[]
  participants: { registration_id: string; name: string; school: string | null; joined: number; late: number; missed: number; minutes: number; last_join_at: string | null; attendance_percent: number }[]
  summary: { participants: number; sessions: number; ended: number; live: number; join_rate: number | null; at_risk: number }
}

const stateTone = { upcoming: 'blue', live: 'green', ended: 'gray' } as const

/** Live tracking of an online program (who joined each session, who is at risk) and its delivery settings. */
export default function RemoteTab({ program }: { program: Program }) {
  const { t } = useTranslation()
  const { can } = useAuth()
  const { data, isLoading, refetch } = useGet<{ data: Tracking }>(`/admin/programs/${program.id}/remote-tracking`, undefined, { refetchInterval: 30_000, staleTime: 0 })
  const r = program.remote
  const [settings, setSettings] = useState<RemoteSettings>({
    ...emptyRemote, platform: r?.platform ?? 'zoom', join_url: r?.join_url ?? '', passcode: r?.passcode ?? '', join_opens_minutes: String(r?.join_opens_minutes ?? 15),
    instructions_ar: r?.instructions_ar ?? '', instructions_en: r?.instructions_en ?? '',
  })
  const [busy, setBusy] = useState<string | null>(null)
  const [message, setMessage] = useState<{ ok: boolean; text: string } | null>(null)
  const manage = can('programs.manage')

  const saveSettings = async () => {
    setBusy('save')
    setMessage(null)
    try {
      await api.put(`/admin/programs/${program.id}`, { remote: { platform: settings.platform, join_url: settings.join_url || null, passcode: settings.passcode || null, join_opens_minutes: Number(settings.join_opens_minutes) || 0, instructions_ar: settings.instructions_ar || null, instructions_en: settings.instructions_en || null } })
      // Sessions without their own link follow the program's link.
      await Promise.all((program.sessions ?? []).filter((s) => s.mode === 'online' && (!s.online_url || s.online_url === r?.join_url)).map((s) => api.put(`/admin/sessions/${s.id}`, { online_url: settings.join_url || null, online_platform: settings.platform, online_passcode: settings.passcode || null }).catch(() => undefined)))
      setMessage({ ok: true, text: t('studio.tracking.saved') })
    } catch (e) {
      setMessage({ ok: false, text: errorMessage(e) })
    } finally {
      setBusy(null)
    }
  }

  const remind = async (id: string) => {
    setBusy(id)
    setMessage(null)
    try {
      const res = await api.post<{ data: { reminded: number } }>(`/admin/sessions/${id}/remind`)
      setMessage({ ok: true, text: t('studio.tracking.reminded', { count: res.data.data.reminded }) })
    } catch (e) {
      setMessage({ ok: false, text: errorMessage(e) })
    } finally {
      setBusy(null)
    }
  }

  if (isLoading || !data) return <Spinner />
  const d = data.data

  return (
    <div className="space-y-6">
      {message && <div className={clsx('rounded-xl p-3 text-sm', message.ok ? 'bg-emerald-50 text-emerald-800' : 'bg-red-50 text-danger')}>{message.text}</div>}

      <div className="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
        <StatCard label={t('studio.tracking.participants')} value={fmt.number(d.summary.participants)} icon={<Users className="size-5" />} />
        <StatCard label={t('studio.tracking.live')} value={fmt.number(d.summary.live)} icon={<Radio className="size-5" />} accent />
        <StatCard label={t('studio.tracking.joinRate')} value={d.summary.join_rate == null ? '—' : `${fmt.number(d.summary.join_rate, 1)}%`} icon={<Video className="size-5" />} hint={t('studio.tracking.joinRateHint')} />
        <StatCard label={t('studio.tracking.atRisk')} value={fmt.number(d.summary.at_risk)} hint={t('studio.tracking.atRiskHint', { percent: program.min_attendance_percent })} />
      </div>

      <Card padded={false}>
        <div className="p-5 pb-0"><CardTitle subtitle={t('studio.tracking.sessionsHint')}>{t('studio.tracking.sessions')}</CardTitle></div>
        <Table head={['#', t('studio.tracking.session'), t('studio.tracking.state'), t('studio.tracking.joined'), t('studio.tracking.avg'), '']}>
          {d.sessions.map((s) => (
            <tr key={s.id}>
              <Td>{fmt.number(s.sequence)}</Td>
              <Td><div className="font-semibold text-navy-900">{s.title}</div><div className="text-xs text-slate-500">{fmt.dateTime(s.starts_at)}{!s.has_link && s.mode === 'online' && <span className="ms-2 font-semibold text-danger">{t('studio.tracking.noLink')}</span>}</div></Td>
              <Td><Badge color={stateTone[s.state]}>{t(`studio.tracking.states.${s.state}`)}</Badge></Td>
              <Td><div className="min-w-[10rem]"><div className="mb-1 flex justify-between text-xs"><span className="font-semibold text-navy-900">{fmt.number(s.joined)} / {fmt.number(s.expected)}</span>{s.late > 0 && <span className="text-amber-700">{t('studio.tracking.late', { count: s.late })}</span>}</div><Progress value={s.expected ? (s.joined / s.expected) * 100 : 0} tone={s.state === 'ended' && s.joined < s.expected * 0.7 ? 'red' : 'green'} /></div></Td>
              <Td>{s.joined ? `${fmt.number(s.avg_minutes)} ${t('common.minutes', { defaultValue: 'min' })}` : '—'}</Td>
              <Td>{manage && s.state !== 'ended' && s.mode === 'online' && <Button size="sm" variant="outline" loading={busy === s.id} icon={<BellRing className="size-4" />} onClick={() => remind(s.id)}>{t('studio.tracking.remind')}</Button>}</Td>
            </tr>
          ))}
        </Table>
      </Card>

      <Card padded={false}>
        <div className="flex items-start justify-between gap-3 p-5 pb-0"><CardTitle subtitle={t('studio.tracking.participantsHint')}>{t('studio.tracking.people')}</CardTitle><Button variant="ghost" size="sm" onClick={() => refetch()}>{t('studio.tracking.refresh')}</Button></div>
        <Table head={[t('studio.tracking.name'), t('studio.tracking.attendance'), t('studio.tracking.joinedCount'), t('studio.tracking.missed'), t('studio.tracking.lastJoin')]}>
          {d.participants.map((p) => (
            <tr key={p.registration_id}>
              <Td><div className="font-semibold text-navy-900">{p.name}</div><div className="text-xs text-slate-500">{p.school}</div></Td>
              <Td><div className="min-w-[8rem]"><div className="mb-1 text-xs font-semibold">{fmt.number(p.attendance_percent, 0)}%</div><Progress value={p.attendance_percent} tone={d.summary.ended > 0 && p.attendance_percent < program.min_attendance_percent ? 'red' : 'green'} /></div></Td>
              <Td>{fmt.number(p.joined)}{p.late > 0 && <span className="ms-1 text-xs text-amber-700">({t('studio.tracking.late', { count: p.late })})</span>}</Td>
              <Td className={p.missed > 0 ? 'font-semibold text-danger' : ''}>{fmt.number(p.missed)}</Td>
              <Td>{p.last_join_at ? fmt.dateTime(p.last_join_at) : '—'}</Td>
            </tr>
          ))}
        </Table>
      </Card>

      {manage && (
        <div className="space-y-4">
          <div className="flex items-center justify-between gap-3"><h3 className="text-lg font-bold text-navy-900">{t('studio.tracking.settings')}</h3><Button variant="gold" loading={busy === 'save'} icon={<Save className="size-4" />} onClick={saveSettings}>{t('studio.tracking.saveSettings')}</Button></div>
          <DeliveryStep value={settings} onChange={setSettings} />
        </div>
      )}
    </div>
  )
}
