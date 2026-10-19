import { useQueryClient } from '@tanstack/react-query'
import { Eye, MonitorPlay, ShieldAlert, ShieldCheck, Users } from 'lucide-react'
import { useState } from 'react'
import { useTranslation } from 'react-i18next'
import { Badge, Card, CardTitle, Empty, ErrorState, PageHeader, Spinner, StatCard } from '@/components/ui'
import { useGet } from '@/hooks/useApi'
import { api } from '@/lib/api'
import { toast } from '@/lib/toast'
import { Switch } from '../notifications/shared'
import type { ShieldSettings } from './api'
import { fmtDateTime } from './api'
import { OwnershipNotice } from './Ownership'

type Row = { id: string; kind: string; at: string; trainer: { id: string; name: string } | null; kit: { id: string; code: string; title: string } | null; file: string | null; meta: Record<string, unknown> | null; ip: string | null; agent: string | null }
type Payload = { settings: ShieldSettings; stats: { opened: number; presented: number; alerts: number; trainers: number }; data: Row[]; meta: { current_page: number; last_page: number; total: number } }

const SWITCHES: (keyof ShieldSettings)[] = ['watermark', 'blank_on_capture_keys', 'blank_on_blur_reader', 'blank_on_blur_presenter', 'block_print_copy', 'require_acknowledgement', 'log_events', 'notify_admin']
const ALERT = ['capture_attempt', 'print_attempt', 'tamper']

/** Settings → training kits: how the kit is protected in the trainer's reader and presenter, and the trail of who opened, presented or tried to capture what. */
export default function KitProtectionAdmin() {
  const { t, i18n } = useTranslation()
  const qc = useQueryClient()
  const [kind, setKind] = useState('')
  const [page, setPage] = useState(1)
  const { data, isLoading, error, refetch } = useGet<Payload>('/admin/kits/protection', { kind: kind || undefined, page, per_page: 20 }, { staleTime: 0 })
  if (isLoading) return <Spinner />
  if (error || !data) return <ErrorState error={error} onRetry={refetch} />
  const s = data.settings
  const set = async (patch: Partial<ShieldSettings>) => {
    try { await api.put('/admin/kits/protection', patch); toast(t('common.saved')); void qc.invalidateQueries({ queryKey: ['/admin/kits/protection'] }) } catch { toast(t('common.error'), 'error') }
  }
  return (
    <div className="mx-auto max-w-6xl">
      <PageHeader title={t('teach.protection.title')} subtitle={t('teach.protection.subtitle')} />
      <OwnershipNotice className="mb-6" />
      <div className="mb-6 grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
        <StatCard label={t('teach.protection.opened')} value={data.stats.opened} icon={<Eye className="size-5" />} hint={t('teach.protection.last30')} />
        <StatCard label={t('teach.protection.presented')} value={data.stats.presented} icon={<MonitorPlay className="size-5" />} hint={t('teach.protection.last30')} />
        <StatCard label={t('teach.protection.alerts')} value={data.stats.alerts} icon={<ShieldAlert className="size-5" />} hint={t('teach.protection.last30')} accent={data.stats.alerts > 0} />
        <StatCard label={t('teach.protection.trainers')} value={data.stats.trainers} icon={<Users className="size-5" />} hint={t('teach.protection.last30')} />
      </div>

      <div className="grid gap-6 lg:grid-cols-[360px_1fr]">
        <Card>
          <CardTitle subtitle={t('teach.protection.limits')}><span className="inline-flex items-center gap-2"><ShieldCheck className="size-5 text-gold-600" />{t('teach.protection.settings')}</span></CardTitle>
          <ul className="divide-y divide-slate-100">
            {SWITCHES.map((k) => (
              <li key={k} className="flex items-start gap-3 py-3 first:pt-0">
                <div className="min-w-0 flex-1"><div className="text-sm font-bold text-navy-900">{t(`teach.protection.sw.${k}`)}</div><div className="mt-0.5 text-xs leading-5 text-slate-500">{t(`teach.protection.hint.${k}`)}</div></div>
                <Switch small checked={!!s[k]} label={t(`teach.protection.sw.${k}`)} onChange={(v) => void set({ [k]: v } as Partial<ShieldSettings>)} />
              </li>
            ))}
            <li className="flex items-center gap-3 pt-3">
              <div className="min-w-0 flex-1 text-sm font-bold text-navy-900">{t('teach.protection.window')}</div>
              <input type="number" min={1} max={1440} defaultValue={s.notify_window_minutes} className="input w-20 py-1" onBlur={(e) => { const v = Math.max(1, Math.min(1440, Number(e.target.value) || 10)); if (v !== s.notify_window_minutes) void set({ notify_window_minutes: v }) }} />
            </li>
          </ul>
        </Card>

        <Card>
          <CardTitle action={<select className="input py-1 text-sm" value={kind} onChange={(e) => { setKind(e.target.value); setPage(1) }} aria-label={t('teach.protection.filter')}><option value="">{t('teach.protection.allKinds')}</option>{['acknowledged', 'opened', 'presented', 'capture_attempt', 'print_attempt', 'blocked', 'tamper'].map((k) => <option key={k} value={k}>{t(`teach.protection.kind.${k}`)}</option>)}</select>}>{t('teach.protection.trail')}</CardTitle>
          {!data.data.length ? <Empty text={t('teach.protection.none')} icon={<ShieldCheck className="size-6" />} /> : (
            <div className="overflow-x-auto">
              <table className="w-full text-sm">
                <thead><tr className="border-b border-navy-100 text-start text-xs text-slate-500"><th className="py-2 pe-3 text-start font-semibold">{t('teach.protection.when')}</th><th className="pe-3 text-start font-semibold">{t('teach.protection.trainer')}</th><th className="pe-3 text-start font-semibold">{t('teach.protection.what')}</th><th className="text-start font-semibold">{t('teach.protection.kit')}</th></tr></thead>
                <tbody>
                  {data.data.map((r) => (
                    <tr key={r.id} className="border-b border-slate-100 align-top last:border-0">
                      <td className="whitespace-nowrap py-2.5 pe-3 text-xs text-slate-500">{fmtDateTime(r.at, i18n.language)}</td>
                      <td className="pe-3 font-semibold text-navy-900" dir="auto">{r.trainer?.name ?? '—'}</td>
                      <td className="pe-3"><Badge color={ALERT.includes(r.kind) ? 'red' : r.kind === 'presented' ? 'gold' : 'gray'}>{t(`teach.protection.kind.${r.kind}`)}</Badge>{r.meta?.key ? <span className="ms-1 font-mono text-[11px] text-slate-500" dir="ltr">{String(r.meta.key)}</span> : null}{r.meta?.rehearsal ? <span className="ms-1 text-[11px] text-slate-400">{t('teach.present.rehearsal')}</span> : null}</td>
                      <td className="text-xs text-slate-600" dir="auto">{r.kit ? `${r.kit.title}` : '—'}{r.file ? <div className="text-slate-400">{r.file}</div> : null}</td>
                    </tr>
                  ))}
                </tbody>
              </table>
            </div>
          )}
          {data.meta.last_page > 1 && (
            <div className="mt-3 flex items-center justify-between text-xs text-slate-500">
              <button type="button" disabled={page <= 1} onClick={() => setPage(page - 1)} className="rounded-lg px-3 py-1.5 ring-1 ring-navy-100 disabled:opacity-40">{t('common.previous')}</button>
              <span>{page} / {data.meta.last_page}</span>
              <button type="button" disabled={page >= data.meta.last_page} onClick={() => setPage(page + 1)} className="rounded-lg px-3 py-1.5 ring-1 ring-navy-100 disabled:opacity-40">{t('common.next')}</button>
            </div>
          )}
        </Card>
      </div>
    </div>
  )
}
