/* eslint-disable @typescript-eslint/no-explicit-any */
import { Check } from 'lucide-react'
import { useTranslation } from 'react-i18next'
import { useNavigate } from 'react-router-dom'
import { Badge, Button, Modal, Progress, Spinner } from '@/components/ui'
import { useGet } from '@/hooks/useApi'
import { api, errorMessage } from '@/lib/api'
import { fmt } from '@/lib/format'
import { toast } from '@/lib/toast'

/** A ring per criterion with a plain next step, the weighted score and the way to pass by the comprehensive test. */
export default function MyProgress({ registrationId, onClose }: { registrationId: string; onClose: () => void }) {
  const { t } = useTranslation()
  const nav = useNavigate()
  const res = useGet<{ data: any }>(`/me/registrations/${registrationId}/progress`, undefined, { staleTime: 0 })
  const d = res.data?.data

  const step = (c: any): string | null => {
    if (!c.applicable || c.met) return null
    if (c.key === 'attendance') return t('passing.my.attendNext', { n: Math.max(1, Math.ceil(c.min - c.value)) })
    if (c.key === 'tasks') return t('passing.my.submitTask', { done: c.evidence.done, total: c.evidence.total })
    if (c.key === 'course') return t('passing.my.finishCourse')
    if (c.key === 'evaluation') return t('passing.my.fillSurvey')
    return null
  }
  const testOut = async () => {
    try { const { data } = await api.post(`/me/programs/${d.program_id ?? ''}/test-out/start`); nav(`/portal/assessments/${data.data.assessment_id}/take`) } catch (e) { toast(errorMessage(e), 'error') }
  }
  return (
    <Modal open onClose={onClose} title={t('passing.my.title')}>
      {!d ? <Spinner /> : (
        <div className="space-y-4">
          <div className="flex flex-wrap items-center gap-2"><Badge color={d.pass_status === 'passed' || d.pass_status === 'exempted' ? 'green' : d.pass_status === 'failed' ? 'red' : 'gray'}>{t(`passing.status.${d.pass_status}`)}</Badge>
            {d.mode === 'weighted' && d.weighted_score !== null && <span className="ms-auto text-sm font-bold">{t('passing.score')}: {fmt.number(d.weighted_score, 1)}% / {Math.round(d.pass_threshold)}%</span>}</div>
          {d.criteria.map((c: any) => (
            <div key={c.key} className="space-y-1.5">
              <div className="flex items-center justify-between text-sm"><b className="text-navy-900">{t(`passing.keys.${c.key}`)}</b>
                {!c.applicable ? <span className="text-xs text-slate-400">{t('passing.na')}</span> : c.met ? <span className="flex items-center gap-1 text-xs font-bold text-emerald-700"><Check className="size-4" />{c.exempted ? t('passing.exempt') : t('passing.met')}</span> : <span className="text-xs tabular-nums text-slate-500">{Math.round(c.value)}% / {Math.round(c.min)}%</span>}</div>
              {c.applicable && <Progress value={c.exempted ? 100 : c.value} tone={c.met ? 'green' : 'gold'} />}
              {step(c) && <p className="text-xs font-semibold text-gold-700">{t('passing.my.next')}: {step(c)}</p>}
            </div>))}
          {d.pass_status === 'passed' && <p className="rounded-xl bg-emerald-50 p-3 text-sm text-emerald-800">{t('passing.my.allDone')}</p>}
          {d.can_test_out && <div className="rounded-xl border border-dashed border-navy-200 p-3 text-sm"><p className="mb-2">{t('passing.my.testOut')}</p><Button variant="gold" size="sm" onClick={() => void testOut()}>{t('passing.my.startTestOut')}</Button></div>}
        </div>
      )}
    </Modal>
  )
}
