import { ShieldCheck } from 'lucide-react'
import { useEffect } from 'react'
import { useTranslation } from 'react-i18next'
import { Badge, Button, Card, CardTitle, Empty, Spinner } from '@/components/ui'
import { useGet } from '@/hooks/useApi'
import { api, errorMessage } from '@/lib/api'
import { fmt } from '@/lib/format'
import { toast } from '@/lib/toast'
import type { CompletionIssue, Paged } from './types'

/** Completions a provider reported that could not be counted, each with its reason and what to do about it. They clear themselves when the cause is fixed. */
export default function ProviderIssues({ names, refreshKey, onChanged }: { names: Record<string, string>; refreshKey: number; onChanged: () => void }) {
  const { t } = useTranslation()
  const res = useGet<Paged<CompletionIssue>>('/admin/learning-providers-issues', { state: 'open', per_page: 25 }, { staleTime: 0 })
  const rows = res.data?.data ?? []
  const { refetch } = res
  useEffect(() => { if (refreshKey > 0) void refetch() }, [refreshKey, refetch])

  const dismiss = async (issue: CompletionIssue) => {
    try {
      await api.post(`/admin/learning-providers-issues/${issue.id}/dismiss`)
      toast(String(t('min.providers.issues.dismissed')))
      await res.refetch()
      onChanged()
    } catch (e) {
      toast(errorMessage(e), 'error')
    }
  }

  return (
    <Card>
      <CardTitle subtitle={t('min.providers.issues.hint')}>{t('min.providers.issues.title')}</CardTitle>
      {!res.data ? <Spinner /> : rows.length === 0 ? <Empty text={t('min.providers.issues.none')} icon={<ShieldCheck className="size-6" />} /> : (
        <ul className="divide-y divide-navy-100/70">
          {rows.map((i) => (
            <li key={i.id} className="flex flex-wrap items-start gap-x-6 gap-y-2 py-4 first:pt-0 last:pb-0">
              <div className="min-w-0 flex-1 basis-64">
                <p className="font-semibold text-navy-900" dir="auto">{i.course_title ?? i.external_course_id}</p>
                <p className="mt-0.5 text-xs text-slate-400">{names[i.provider] ?? i.provider} · <span dir="ltr">{i.learner_ref}</span></p>
              </div>
              <div className="min-w-0 flex-1 basis-64">
                <Badge color={i.reason === 'unknown_employee' ? 'red' : 'amber'}>{t(`min.providers.issues.reasons.${i.reason}`)}</Badge>
                <p className="mt-1.5 text-xs text-slate-500">{t(`min.providers.issues.fixHint.${i.reason}`)}</p>
              </div>
              <div className="text-xs text-slate-400">{t('min.providers.issues.seen')}: {fmt.number(i.occurrences)}<br />{t('min.providers.issues.last')}: {fmt.dateTime(i.last_seen_at)}</div>
              <Button size="sm" variant="ghost" onClick={() => void dismiss(i)}>{t('min.providers.issues.dismiss')}</Button>
            </li>
          ))}
        </ul>
      )}
    </Card>
  )
}
