/* eslint-disable @typescript-eslint/no-explicit-any */
import { FileSpreadsheet, FileText } from 'lucide-react'
import { useState } from 'react'
import { useTranslation } from 'react-i18next'
import { Badge, Button, Card, Empty, Spinner, Table, Td } from '@/components/ui'
import { useGet } from '@/hooks/useApi'
import { downloadFile } from '@/lib/api'
import { fmt } from '@/lib/format'
import { input, statusColor } from './shared'

const STATUSES = ['queued', 'sent', 'delivered', 'read', 'failed', 'skipped']
const COLORS: Record<string, string> = { queued: '#d4a017', sent: '#2563eb', delivered: '#059669', read: '#0f766e', failed: '#dc2626', skipped: '#94a3b8' }

/** A donut of the statuses, drawn with a conic gradient. */
function Donut({ counts, total }: { counts: Record<string, number>; total: number }) {
  let acc = 0
  const stops = STATUSES.filter((s) => counts[s]).map((s) => { const from = (acc / Math.max(total, 1)) * 100; acc += counts[s]; return `${COLORS[s]} ${from}% ${(acc / Math.max(total, 1)) * 100}%` })
  return (
    <div className="relative size-32 shrink-0 rounded-full" style={{ background: total ? `conic-gradient(${stops.join(', ')})` : '#e2e8f0' }} role="img" aria-label={STATUSES.map((s) => `${s}: ${counts[s] ?? 0}`).join(', ')}>
      <div className="absolute inset-4 grid place-items-center rounded-full bg-white"><span className="font-display text-xl font-bold text-navy-900">{fmt.number(total)}</span></div>
    </div>
  )
}

/** Who got what, by which channel, and what happened — filter, drill down by campaign, export to Excel or PDF. */
export default function DeliveriesTab({ initial }: { initial?: { channel?: string; status?: string; campaign_id?: string } }) {
  const { t, i18n } = useTranslation()
  const [f, setF] = useState({ channel: initial?.channel ?? '', status: initial?.status ?? '', from: '', to: '', q: '', campaign_id: initial?.campaign_id ?? '' })
  const [page, setPage] = useState(1)
  const params = { ...Object.fromEntries(Object.entries(f).filter(([, v]) => v)), page, per_page: 25 }
  const { data, isLoading } = useGet<{ data: { data: any[]; last_page: number }; summary: { total: number; by_status: Record<string, number> } }>('/admin/notifications/deliveries', params, { staleTime: 0 })
  const qs = new URLSearchParams(Object.entries(f).filter(([, v]) => v) as [string, string][]).toString()
  const set = (patch: Partial<typeof f>) => { setF({ ...f, ...patch }); setPage(1) }
  const sum = data?.summary

  return (
    <div className="space-y-4">
      <Card>
        <div className="flex flex-wrap items-center gap-6">
          <Donut counts={sum?.by_status ?? {}} total={sum?.total ?? 0} />
          <div className="grid flex-1 grid-cols-2 gap-3 sm:grid-cols-3 lg:grid-cols-6">
            {STATUSES.map((s) => (
              <button key={s} type="button" onClick={() => set({ status: f.status === s ? '' : s })} className={`rounded-xl p-3 text-start transition ${f.status === s ? 'bg-navy-900 text-white' : 'bg-ivory hover:bg-gold-100'}`}>
                <div className="flex items-center gap-1.5 text-xs"><span className="size-2 rounded-full" style={{ background: COLORS[s] }} />{t(`comm.status.${s}`)}</div>
                <div className="mt-1 font-display text-xl font-bold">{fmt.number(sum?.by_status[s] ?? 0)}</div>
              </button>
            ))}
          </div>
        </div>
        <p className="mt-3 text-xs text-slate-400">{t('comm.deliveries.note')}</p>
      </Card>
      <Card>
        <div className="flex flex-wrap items-center gap-2">
          <select className={`${input} w-auto`} value={f.channel} onChange={(e) => set({ channel: e.target.value })}><option value="">{t('comm.deliveries.allChannels')}</option>{['in_app', 'push', 'email', 'sms'].map((c) => <option key={c} value={c}>{t(`comm.channels.${c}`)}</option>)}</select>
          <select className={`${input} w-auto`} value={f.status} onChange={(e) => set({ status: e.target.value })}><option value="">{t('comm.deliveries.allStatuses')}</option>{STATUSES.map((s) => <option key={s} value={s}>{t(`comm.status.${s}`)}</option>)}</select>
          <input type="date" className={`${input} w-auto`} value={f.from} onChange={(e) => set({ from: e.target.value })} />
          <input type="date" className={`${input} w-auto`} value={f.to} onChange={(e) => set({ to: e.target.value })} />
          <input className={`${input} w-56`} placeholder={String(t('comm.deliveries.search'))} value={f.q} onChange={(e) => set({ q: e.target.value })} />
          <div className="ms-auto flex gap-2">
            <Button size="sm" variant="outline" icon={<FileSpreadsheet className="size-4" />} onClick={() => downloadFile(`/admin/notifications/deliveries/export?format=xlsx&lang=${i18n.language}&${qs}`, 'notification-deliveries.xlsx')}>{t('comm.deliveries.excel')}</Button>
            <Button size="sm" variant="outline" icon={<FileText className="size-4" />} onClick={() => downloadFile(`/admin/notifications/deliveries/export?format=pdf&lang=${i18n.language}&${qs}`, 'notification-deliveries.pdf')}>{t('comm.deliveries.pdf')}</Button>
          </div>
        </div>
      </Card>
      {isLoading ? <Spinner /> : !data?.data.data.length ? <Card><Empty text={String(t('comm.deliveries.empty'))} /></Card> : (
        <Card padded={false}>
          <Table head={[t('comm.deliveries.recipient'), t('comm.deliveries.channel'), t('comm.deliveries.type'), t('comm.status.sent'), t('comm.deliveries.reason'), t('comm.deliveries.date')]}>
            {data.data.data.map((r) => (
              <tr key={`${r.channel}-${r.id}`}>
                <Td>{r.user_name ?? '—'}</Td>
                <Td><Badge>{t(`comm.channels.${r.channel}`)}</Badge></Td>
                <Td className="text-xs text-slate-500">{r.type}</Td>
                <Td><Badge color={statusColor(r.status)}>{t(`comm.status.${r.status}`)}</Badge></Td>
                <Td className="max-w-56 truncate text-xs text-slate-500" >{r.reason ?? ''}</Td>
                <Td className="text-xs">{fmt.date(r.created_at, { dateStyle: 'medium', timeStyle: 'short' } as any)}</Td>
              </tr>
            ))}
          </Table>
          <div className="flex items-center justify-between p-3 text-sm">
            <Button size="sm" variant="outline" disabled={page <= 1} onClick={() => setPage(page - 1)}>‹</Button>
            <span>{page} / {data.data.last_page}</span>
            <Button size="sm" variant="outline" disabled={page >= data.data.last_page} onClick={() => setPage(page + 1)}>›</Button>
          </div>
        </Card>
      )}
    </div>
  )
}
