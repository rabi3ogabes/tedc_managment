import clsx from 'clsx'
import { ShieldAlert } from 'lucide-react'
import { useState } from 'react'
import { useTranslation } from 'react-i18next'
import { Link } from 'react-router-dom'
import { Card, Empty, PageHeader, Spinner } from '@/components/ui'
import { useGet } from '@/hooks/useApi'
import { fmt } from '@/lib/format'
import { Pager } from './shared'

type Row = { id: string; outcome: 'rejected' | 'already_present'; code: string; message: string; at: string; device: string | null; person: string; program: { id: string; code: string; title: string } | null; session: { id: string; title: string; starts_at: string } | null }
type Payload = { data: Row[]; meta: { total: number; last_page: number }; summary: Record<string, number> }

const TONE: Record<string, string> = { already_present: 'bg-amber-50 text-amber-800', already_checked_out: 'bg-amber-50 text-amber-800', not_checked_in: 'bg-amber-50 text-amber-800' }

/** Every scan that did not record attendance, with the reason, so the administrator can see what the participants run into. */
export default function AttendanceAttempts() {
  const { t } = useTranslation()
  const [code, setCode] = useState('')
  const [page, setPage] = useState(1)
  const { data, isLoading } = useGet<Payload>('/admin/attendance-attempts', { code: code || undefined, page }, { refetchInterval: 30_000 })
  const label = (c: string) => t(`attempts.codes.${c}`, { defaultValue: c })

  return (
    <div className="space-y-5 pb-6">
      <PageHeader title={<span className="flex items-center gap-3"><span className="grid size-11 place-items-center rounded-2xl bg-navy-900 text-gold-300"><ShieldAlert className="size-5" /></span>{t('attempts.title')}</span>} subtitle={t('attempts.subtitle')} />

      <div className="flex flex-wrap gap-2">
        <button type="button" onClick={() => { setCode(''); setPage(1) }} className={clsx('rounded-full px-4 py-2 text-sm font-bold', code === '' ? 'bg-navy-900 text-white' : 'bg-white text-slate-600 ring-1 ring-navy-100')}>{t('common.all')}</button>
        {Object.entries(data?.summary ?? {}).map(([c, n]) => (
          <button key={c} type="button" onClick={() => { setCode(c); setPage(1) }} className={clsx('rounded-full px-4 py-2 text-sm font-bold', code === c ? 'bg-navy-900 text-white' : 'bg-white text-slate-600 ring-1 ring-navy-100')}>{label(c)} <span className="ms-1 tabular-nums text-gold-500">{fmt.number(n)}</span></button>
        ))}
        <span className="self-center text-xs text-slate-400">{t('attempts.last7')}</span>
      </div>

      <Card padded={false}>
        {isLoading ? <Spinner /> : !data?.data.length ? <Empty text={t('attempts.empty')} /> : (
          <ul className="divide-y divide-navy-50">
            {data.data.map((r) => (
              <li key={r.id} className="flex flex-wrap items-start gap-3 p-4">
                <span className={clsx('mt-0.5 rounded-full px-3 py-1 text-xs font-bold', TONE[r.code] ?? 'bg-red-50 text-danger')}>{label(r.code)}</span>
                <div className="min-w-0 flex-1">
                  <div className="font-bold text-navy-900">{r.person}</div>
                  <div className="text-sm text-slate-600">{r.message}</div>
                  <div className="mt-1 flex flex-wrap gap-x-4 gap-y-1 text-xs text-slate-400">
                    {r.program && <Link className="hover:text-link" to={`/admin/programs/${r.program.id}`}>{r.program.code} · {r.program.title}</Link>}
                    {r.session && <span>{r.session.title} · {fmt.dateTime(r.session.starts_at)}</span>}
                    {r.device && <span dir="ltr" className="max-w-64 truncate">{r.device}</span>}
                  </div>
                </div>
                <time className="text-xs tabular-nums text-slate-400">{fmt.dateTime(r.at)}</time>
              </li>
            ))}
          </ul>
        )}
        <Pager page={page} last={data?.meta?.last_page} onChange={setPage} />
      </Card>
    </div>
  )
}
