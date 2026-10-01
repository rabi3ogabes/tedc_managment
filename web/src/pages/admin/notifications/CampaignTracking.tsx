import clsx from 'clsx'
import { CheckCheck, Download, Eye, EyeOff, Search, Send } from 'lucide-react'
import { useState } from 'react'
import { useTranslation } from 'react-i18next'
import { Badge, Button, Card, Empty, Modal, Spinner } from '@/components/ui'
import { useGet } from '@/hooks/useApi'
import { downloadFile } from '@/lib/api'
import { fmt } from '@/lib/format'
import type { Paginated, Program } from '@/lib/types'
import SendNotificationDialog from './SendNotificationDialog'

type Campaign = {
  id: string; event: string | null; kind: string; title: string; body: string | null; program: { id: string; code: string; title: string } | null; by: string | null
  created_at: string; recipients: number; sent: number; seen: number; read: number; read_rate: number
}
type Recipient = { id: string; name: string; email: string | null; school: string | null; sent_at: string; seen_at: string | null; read_at: string | null; type?: string; title?: string }
type Page<T> = { data: T[]; meta?: { current_page: number; last_page: number; total: number } }

/** Who received a notification, who saw it and who read it. */
export default function CampaignTracking({ programId }: { programId?: string }) {
  const { t } = useTranslation()
  const [program, setProgram] = useState(programId ?? '')
  const [mode, setMode] = useState<'campaigns' | 'all'>('campaigns')
  const [page, setPage] = useState(1)
  const [open, setOpen] = useState<string | null>(null)
  const [sending, setSending] = useState(false)
  const programs = useGet<Paginated<Program>>(programId ? null : '/admin/programs', { per_page: 100 })
  const campaigns = useGet<Page<Campaign>>(mode === 'campaigns' ? '/admin/notifications/campaigns' : null, { page, ...(program ? { program_id: program } : {}) })

  return (
    <div className="space-y-5">
      <div className="flex flex-wrap items-center gap-3">
        {!programId && (
          <select className="input !w-auto min-w-56" value={program} onChange={(e) => { setProgram(e.target.value); setPage(1) }} aria-label={t('mgmt.notif.send.program')}>
            <option value="">{t('mgmt.notif.tracking.allPrograms')}</option>
            {programs.data?.data.map((p) => <option key={p.id} value={p.id}>{p.title}</option>)}
          </select>
        )}
        {!programId && (
          <div role="tablist" className="inline-flex rounded-xl border border-navy-100 bg-white p-1 text-sm font-bold">
            {(['campaigns', 'all'] as const).map((m) => <button key={m} type="button" role="tab" aria-selected={mode === m} onClick={() => setMode(m)} className={clsx('rounded-lg px-4 py-1.5', mode === m ? 'bg-navy-900 text-white' : 'text-slate-500')}>{t(`mgmt.notif.tracking.modes.${m}`)}</button>)}
          </div>
        )}
        <Button className="ms-auto" variant="gold" icon={<Send className="size-4" />} onClick={() => setSending(true)}>{t('mgmt.notif.send.title')}</Button>
      </div>

      {mode === 'all' ? <AllNotifications programId={program} /> : campaigns.isLoading ? <Spinner /> : !campaigns.data?.data.length ? (
        <Card><Empty text={t('mgmt.notif.tracking.empty')} /></Card>
      ) : (
        <ul className="space-y-3">
          {campaigns.data.data.map((c) => {
            const unseen = Math.max(0, c.sent - c.seen), seenOnly = Math.max(0, c.seen - c.read)
            const pct = (n: number) => (c.sent ? (n / c.sent) * 100 : 0)
            return (
              <li key={c.id}>
                <button type="button" onClick={() => setOpen(c.id)} className="block w-full rounded-2xl border border-navy-100 bg-white p-4 text-start shadow-sm transition hover:-translate-y-0.5 hover:border-gold-400 hover:shadow-glass">
                  <div className="flex flex-wrap items-start gap-3">
                    <div className="min-w-0 flex-1 basis-60">
                      <div className="flex flex-wrap items-center gap-2"><span className="font-bold text-navy-900">{c.title}</span>{c.kind === 'survey' && <Badge color="gold">{t('mgmt.notif.tracking.survey')}</Badge>}{c.program && <Badge color="navy">{c.program.title}</Badge>}</div>
                      <div className="mt-0.5 line-clamp-1 text-sm text-slate-500">{c.body}</div>
                      <div className="mt-1 text-xs text-slate-400">{c.by} · {fmt.dateTime(c.created_at)}</div>
                    </div>
                    <div className="text-end"><div className="text-3xl font-extrabold text-navy-900">{c.read_rate}%</div><div className="text-[11px] text-slate-500">{t('mgmt.notif.tracking.readRate')}</div></div>
                  </div>
                  <div className="mt-3 flex h-2.5 overflow-hidden rounded-full bg-navy-100/60" role="img" aria-label={`${c.read}/${c.sent}`}>
                    <div className="bg-emerald-500 transition-all" style={{ width: `${pct(c.read)}%` }} /><div className="bg-sky-400 transition-all" style={{ width: `${pct(seenOnly)}%` }} /><div className="bg-slate-300 transition-all" style={{ width: `${pct(unseen)}%` }} />
                  </div>
                  <div className="mt-2 flex flex-wrap gap-4 text-xs text-slate-500">
                    <span className="inline-flex items-center gap-1.5"><span className="size-2 rounded-full bg-emerald-500" />{t('mgmt.notif.tracking.read')} {c.read}</span>
                    <span className="inline-flex items-center gap-1.5"><span className="size-2 rounded-full bg-sky-400" />{t('mgmt.notif.tracking.seenOnly')} {seenOnly}</span>
                    <span className="inline-flex items-center gap-1.5"><span className="size-2 rounded-full bg-slate-300" />{t('mgmt.notif.tracking.unseen')} {unseen}</span>
                    <span className="ms-auto font-semibold text-navy-900">{t('mgmt.notif.tracking.sentTo', { count: c.sent })}</span>
                  </div>
                </button>
              </li>
            )
          })}
        </ul>
      )}
      {mode === 'campaigns' && (campaigns.data?.meta?.last_page ?? 1) > 1 && (
        <div className="flex justify-center gap-2"><Button size="sm" variant="outline" disabled={page <= 1} onClick={() => setPage(page - 1)}>{t('common.previous')}</Button><Button size="sm" variant="outline" disabled={page >= (campaigns.data?.meta?.last_page ?? 1)} onClick={() => setPage(page + 1)}>{t('common.next')}</Button></div>
      )}

      {open && <CampaignDetail id={open} onClose={() => setOpen(null)} />}
      {sending && <SendNotificationDialog programId={programId ?? (program || undefined)} onClose={() => setSending(false)} onSent={() => void campaigns.refetch()} />}
    </div>
  )
}

function StatusCell({ at, icon: Icon, tone }: { at: string | null; icon: typeof Eye; tone: string }) {
  return at ? <span className={clsx('inline-flex items-center gap-1.5 text-xs font-semibold', tone)}><Icon className="size-3.5" />{at.slice(5)}</span> : <span className="text-xs text-slate-300">—</span>
}

function CampaignDetail({ id, onClose }: { id: string; onClose: () => void }) {
  const { t } = useTranslation()
  const [status, setStatus] = useState('')
  const [q, setQ] = useState('')
  const [page, setPage] = useState(1)
  const { data, isLoading } = useGet<{ data: Campaign; recipients: Page<Recipient> }>(`/admin/notifications/campaigns/${id}`, { status: status || undefined, q: q || undefined, page })
  const c = data?.data

  return (
    <Modal open onClose={onClose} wide title={c?.title ?? '…'}>
      {isLoading || !c ? <Spinner /> : (
        <div className="space-y-4">
          <div className="grid gap-3 sm:grid-cols-4">
            {[{ l: t('mgmt.notif.tracking.sent'), v: c.sent, tone: 'text-navy-900' }, { l: t('mgmt.notif.tracking.seenCount'), v: c.seen, tone: 'text-sky-600' }, { l: t('mgmt.notif.tracking.read'), v: c.read, tone: 'text-emerald-600' }, { l: t('mgmt.notif.tracking.readRate'), v: `${c.read_rate}%`, tone: 'text-gold-700' }].map((s) => (
              <div key={s.l} className="rounded-2xl border border-navy-100 bg-ivory/60 p-3 text-center"><div className={clsx('text-3xl font-extrabold', s.tone)}>{s.v}</div><div className="text-xs text-slate-500">{s.l}</div></div>
            ))}
          </div>
          <div className="flex flex-wrap items-center gap-3">
            <div role="tablist" className="inline-flex rounded-xl border border-navy-100 p-1 text-xs font-bold">
              {([['', 'all'], ['read', 'read'], ['unread', 'unread'], ['unseen', 'unseen']] as const).map(([v, k]) => <button key={k} type="button" role="tab" aria-selected={status === v} onClick={() => { setStatus(v); setPage(1) }} className={clsx('rounded-lg px-3 py-1.5', status === v ? 'bg-navy-900 text-white' : 'text-slate-500')}>{t(`mgmt.notif.tracking.filters.${k}`)}</button>)}
            </div>
            <div className="relative min-w-48 flex-1"><Search className="pointer-events-none absolute start-3 top-1/2 size-4 -translate-y-1/2 text-slate-400" /><input className="input !ps-9" value={q} onChange={(e) => { setQ(e.target.value); setPage(1) }} placeholder={t('mgmt.live.search')} /></div>
            <Button variant="outline" size="sm" icon={<Download className="size-4" />} onClick={() => downloadFile(`/admin/notifications/campaigns/${id}/export`, `notification-${id}.csv`)}>{t('mgmt.live.exportCsv')}</Button>
          </div>
          <div className="max-h-[22rem] overflow-auto rounded-2xl border border-navy-100">
            <table className="w-full text-sm">
              <thead className="sticky top-0 bg-ivory text-xs text-slate-500"><tr>{['name', 'school', 'sent', 'seen', 'read'].map((k) => <th key={k} className="px-4 py-2.5 text-start font-semibold">{t(`mgmt.notif.tracking.cols.${k}`)}</th>)}</tr></thead>
              <tbody className="divide-y divide-navy-100">
                {data?.recipients.data.map((r) => (
                  <tr key={r.id} className="hover:bg-ivory/50">
                    <td className="px-4 py-2.5"><div className="font-semibold text-navy-900">{r.name}</div><div className="text-xs text-slate-400" dir="ltr">{r.email}</div></td>
                    <td className="px-4 py-2.5 text-slate-600">{r.school ?? '—'}</td>
                    <td className="px-4 py-2.5 text-xs text-slate-500" dir="ltr">{r.sent_at.slice(5)}</td>
                    <td className="px-4 py-2.5"><StatusCell at={r.seen_at} icon={r.seen_at ? Eye : EyeOff} tone="text-sky-600" /></td>
                    <td className="px-4 py-2.5"><StatusCell at={r.read_at} icon={CheckCheck} tone="text-emerald-600" /></td>
                  </tr>
                ))}
              </tbody>
            </table>
            {data?.recipients.data.length === 0 && <div className="p-6"><Empty text={t('mgmt.live.nobody')} /></div>}
          </div>
          {(data?.recipients.meta?.last_page ?? 1) > 1 && (
            <div className="flex justify-center gap-2"><Button size="sm" variant="outline" disabled={page <= 1} onClick={() => setPage(page - 1)}>{t('common.previous')}</Button><Button size="sm" variant="outline" disabled={page >= (data?.recipients.meta?.last_page ?? 1)} onClick={() => setPage(page + 1)}>{t('common.next')}</Button></div>
          )}
        </div>
      )}
    </Modal>
  )
}

/** Every notification of the platform with its read state — to follow one person or one program. */
function AllNotifications({ programId }: { programId: string }) {
  const { t } = useTranslation()
  const [status, setStatus] = useState('')
  const [q, setQ] = useState('')
  const [page, setPage] = useState(1)
  const { data, isLoading } = useGet<{ summary: { sent: number; seen: number; read: number }; data: Recipient[]; meta?: { last_page: number } }>('/admin/notifications/tracking', { page, status: status || undefined, q: q || undefined, program_id: programId || undefined })
  return (
    <div className="space-y-4">
      <div className="flex flex-wrap items-center gap-3">
        <div role="tablist" className="inline-flex rounded-xl border border-navy-100 bg-white p-1 text-xs font-bold">
          {([['', 'all'], ['read', 'read'], ['unread', 'unread']] as const).map(([v, k]) => <button key={k} type="button" role="tab" aria-selected={status === v} onClick={() => { setStatus(v); setPage(1) }} className={clsx('rounded-lg px-3 py-1.5', status === v ? 'bg-navy-900 text-white' : 'text-slate-500')}>{t(`mgmt.notif.tracking.filters.${k}`)}</button>)}
        </div>
        <div className="relative min-w-56 flex-1"><Search className="pointer-events-none absolute start-3 top-1/2 size-4 -translate-y-1/2 text-slate-400" /><input className="input !ps-9" value={q} onChange={(e) => { setQ(e.target.value); setPage(1) }} placeholder={t('mgmt.live.search')} /></div>
        {data && <span className="text-xs text-slate-500">{t('mgmt.notif.tracking.summary', data.summary)}</span>}
      </div>
      {isLoading ? <Spinner /> : (
        <Card padded={false}>
          <table className="w-full text-sm">
            <thead className="bg-ivory text-xs text-slate-500"><tr>{['name', 'notification', 'sent', 'seen', 'read'].map((k) => <th key={k} className="px-4 py-2.5 text-start font-semibold">{t(`mgmt.notif.tracking.cols.${k}`)}</th>)}</tr></thead>
            <tbody className="divide-y divide-navy-100">
              {data?.data.map((r) => (
                <tr key={r.id} className="hover:bg-ivory/50">
                  <td className="px-4 py-2.5"><div className="font-semibold text-navy-900">{r.name}</div><div className="text-xs text-slate-400" dir="ltr">{r.email}</div></td>
                  <td className="px-4 py-2.5"><div className="text-slate-700">{r.title}</div><code className="text-[10px] text-slate-400" dir="ltr">{r.type}</code></td>
                  <td className="px-4 py-2.5 text-xs text-slate-500" dir="ltr">{r.sent_at.slice(5)}</td>
                  <td className="px-4 py-2.5"><StatusCell at={r.seen_at} icon={Eye} tone="text-sky-600" /></td>
                  <td className="px-4 py-2.5"><StatusCell at={r.read_at} icon={CheckCheck} tone="text-emerald-600" /></td>
                </tr>
              ))}
            </tbody>
          </table>
          {data?.data.length === 0 && <div className="p-6"><Empty text={t('mgmt.notif.tracking.empty')} /></div>}
        </Card>
      )}
      {(data?.meta?.last_page ?? 1) > 1 && <div className="flex justify-center gap-2"><Button size="sm" variant="outline" disabled={page <= 1} onClick={() => setPage(page - 1)}>{t('common.previous')}</Button><Button size="sm" variant="outline" disabled={page >= (data?.meta?.last_page ?? 1)} onClick={() => setPage(page + 1)}>{t('common.next')}</Button></div>}
    </div>
  )
}
