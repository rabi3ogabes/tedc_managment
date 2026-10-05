import { ClipboardCheck } from 'lucide-react'
import { useState } from 'react'
import { useTranslation } from 'react-i18next'
import { useNavigate } from 'react-router-dom'
import { Badge, Button, Card, Empty, Field, Modal, PageHeader, Spinner } from '@/components/ui'
import { useGet } from '@/hooks/useApi'
import { api, errorMessage } from '@/lib/api'
import { fmt } from '@/lib/format'
import { toast } from '@/lib/toast'

type Row = { id: string; kind: string; title_ar: string; title_en: string; program: string | null; delivery: string; time_limit_minutes: number | null; max_attempts: number; attempts_used: number; window_opens_at: string | null; window_closes_at: string | null; open: boolean; requires_code: boolean; open_attempt_id: string | null; best_score: number | null; last_attempt_id: string | null }

/** The trainee's assessments: what is open, what is in progress, and the best results. */
export default function Assessments() {
  const { t, i18n } = useTranslation()
  const ar = i18n.language === 'ar'
  const nav = useNavigate()
  const list = useGet<{ data: Row[] }>('/me/assessments', undefined, { staleTime: 0 })
  const [codeFor, setCodeFor] = useState<Row | null>(null)
  const [code, setCode] = useState('')
  const [busy, setBusy] = useState(false)

  const start = async (r: Row, accessCode?: string) => {
    setBusy(true)
    try { await api.post(`/me/assessments/${r.id}/start`, { access_code: accessCode || undefined }); nav(`/portal/assessments/${r.id}/take`) } catch (e) { toast(errorMessage(e), 'error') } finally { setBusy(false) }
  }
  if (list.isLoading || !list.data) return <Spinner />
  return (
    <div className="space-y-6 pb-6">
      <PageHeader title={t('assess.runner.title')} />
      {list.data.data.length === 0 ? <Card><Empty text={t('assess.runner.empty')} /></Card> : (
        <div className="grid gap-4 md:grid-cols-2">{list.data.data.map((r) => {
          const left = r.max_attempts - r.attempts_used
          return (
            <Card key={r.id} className="space-y-3">
              <div className="flex items-start justify-between gap-2"><div className="min-w-0"><h3 className="truncate font-bold text-navy-900">{ar ? r.title_ar : r.title_en}</h3><p className="text-xs text-slate-500">{r.program}</p></div><Badge color="navy">{t(`assess.kinds.${r.kind}`)}</Badge></div>
              <div className="flex flex-wrap gap-x-4 gap-y-1 text-sm text-slate-600"><span>{r.time_limit_minutes ? t('assess.runner.minutes', { n: r.time_limit_minutes }) : t('assess.runner.openEnded')}</span><span>{t('assess.runner.attempts', { used: r.attempts_used, max: r.max_attempts })}</span>{r.best_score !== null && <span>{t('assess.runner.best')}: {fmt.number(r.best_score, 1)}%</span>}{r.window_closes_at && <span>→ {fmt.dateTime(r.window_closes_at)}</span>}</div>
              <div className="flex flex-wrap gap-2">
                {r.open_attempt_id ? <Button variant="gold" icon={<ClipboardCheck className="size-4" />} onClick={() => nav(`/portal/assessments/${r.id}/take`)}>{t('assess.runner.resume')}</Button>
                  : left > 0 && r.open ? <Button variant="gold" loading={busy} onClick={() => (r.requires_code ? setCodeFor(r) : void start(r))}>{r.attempts_used ? t('assess.runner.retry') : t('assess.runner.start')}</Button>
                    : !r.open ? <Badge color="slate">{t('assess.runner.closed')}</Badge> : null}
                {r.last_attempt_id && !r.open_attempt_id && <Button variant="outline" onClick={() => nav(`/portal/attempts/${r.last_attempt_id}`)}>{t('assess.runner.result')}</Button>}
                {r.requires_code && <Badge color="gold">{t('assess.runner.inCenter')}</Badge>}
              </div>
            </Card>
          )
        })}</div>
      )}
      <Modal open={!!codeFor} onClose={() => setCodeFor(null)} title={t('assess.runner.code')}>
        <div className="space-y-4"><Field label={t('assess.runner.enterCode')}><input inputMode="numeric" maxLength={6} dir="ltr" className="input text-center text-2xl tracking-[.5em]" value={code} onChange={(e) => setCode(e.target.value.replace(/\D/g, ''))} /></Field><Button variant="gold" loading={busy} disabled={code.length < 4} onClick={() => codeFor && void start(codeFor, code)}>{t('assess.runner.start')}</Button></div>
      </Modal>
    </div>
  )
}
