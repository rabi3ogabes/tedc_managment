/* eslint-disable @typescript-eslint/no-explicit-any */
import { MessageCircleQuestion } from 'lucide-react'
import { useTranslation } from 'react-i18next'
import { Badge, Button, Card, Empty, ErrorState, PageHeader, Spinner } from '@/components/ui'
import { useGet } from '@/hooks/useApi'
import { api, errorMessage } from '@/lib/api'
import { fmt } from '@/lib/format'
import { safeHtml } from '@/lib/safeHtml'
import { toast } from '@/lib/toast'

/** The questions this person asked their trainers, and the answers. */
export default function MyQuestions() {
  const { t, i18n } = useTranslation()
  const res = useGet<{ data: any[] }>('/social/questions', undefined, { staleTime: 0, retry: false })
  const close = async (id: string) => { try { await api.post(`/social/questions/${id}/close`); res.refetch() } catch (e) { toast(errorMessage(e), 'error') } }
  if (res.isLoading) return <Spinner />
  if (res.isError) return <ErrorState message={errorMessage(res.error)} onRetry={() => res.refetch()} />
  return (
    <>
      <PageHeader title={t('soc.questions.title')} />
      {res.data!.data.length === 0 ? <Empty text={String(t('soc.questions.empty'))} icon={<MessageCircleQuestion className="size-8" />} /> : (
        <div className="space-y-4">
          {res.data!.data.map((q) => (
            <Card key={q.id}>
              <div className="flex flex-wrap items-start justify-between gap-2">
                <div><h3 className="font-display text-lg font-bold text-navy-900">{q.subject}</h3><p className="text-xs text-slate-500">{i18n.language === 'en' ? q.program?.title_en : q.program?.title_ar} · {fmt.dateTime(q.created_at)}</p></div>
                <Badge color={q.status === 'answered' ? 'green' : q.status === 'closed' ? 'gray' : 'amber'}>{t(`soc.questions.status.${q.status}`)}</Badge>
              </div>
              {q.body && <div className="prose prose-sm mt-3 max-w-none" dangerouslySetInnerHTML={{ __html: safeHtml(q.body) }} />}
              {q.answer ? <div className="mt-4 rounded-xl border border-emerald-200 bg-emerald-50/60 p-4"><p className="mb-1 text-xs font-bold text-emerald-700">{t('soc.questions.answer')} · {fmt.dateTime(q.answered_at)}</p><div className="prose prose-sm max-w-none" dangerouslySetInnerHTML={{ __html: safeHtml(q.answer) }} /></div>
                : <p className="mt-3 text-xs text-slate-500">{t('soc.questions.waiting')} · {t('soc.questions.due')}: {fmt.dateTime(q.due_at)}</p>}
              {q.status !== 'closed' && <div className="mt-3 flex justify-end"><Button size="sm" variant="ghost" onClick={() => close(q.id)}>{t('soc.questions.close')}</Button></div>}
            </Card>
          ))}
        </div>
      )}
    </>
  )
}
