/* eslint-disable @typescript-eslint/no-explicit-any */
import { Inbox } from 'lucide-react'
import { useEffect, useState } from 'react'
import { useTranslation } from 'react-i18next'
import { Badge, Button, Card, Empty, Field, PageHeader, Spinner, Tabs } from '@/components/ui'
import { useGet } from '@/hooks/useApi'
import { useAuth } from '@/lib/auth'
import { api, errorMessage } from '@/lib/api'
import { fmt } from '@/lib/format'
import { safeHtml } from '@/lib/safeHtml'
import { toast } from '@/lib/toast'

type Tab = 'inbox' | 'reviews' | 'settings'

/** Questions from trainees to their trainers (with the reply deadline), reviews to moderate, and the collaboration settings. */
export default function TrainerInbox() {
  const { t } = useTranslation()
  const { can } = useAuth()
  const tabs: { id: Tab; label: string }[] = [
    { id: 'inbox', label: t('soc.questions.inbox') },
    ...(can('ratings.moderate') ? [{ id: 'reviews' as Tab, label: t('soc.nav.reviews') }] : []),
    ...(can('forums.moderate') || can('communities.moderate') ? [{ id: 'settings' as Tab, label: t('soc.questions.settings') }] : []),
  ]
  const [tab, setTab] = useState<Tab>('inbox')
  return (
    <>
      <PageHeader title={t('soc.questions.inbox')} subtitle={t('soc.questions.inboxHint')} />
      {tabs.length > 1 && <Tabs<Tab> value={tab} onChange={setTab} tabs={tabs} />}
      {tab === 'inbox' && <InboxList />}
      {tab === 'reviews' && <Reviews />}
      {tab === 'settings' && <SettingsCard />}
    </>
  )
}

function InboxList() {
  const { t, i18n } = useTranslation()
  const [status, setStatus] = useState('')
  const [overdue, setOverdue] = useState(false)
  const res = useGet<{ data: any[] }>('/social/trainer-inbox', { status: status || undefined, overdue: overdue ? 1 : undefined }, { staleTime: 0 })
  const [answers, setAnswers] = useState<Record<string, string>>({})
  const [busy, setBusy] = useState<string | null>(null)
  const send = async (id: string) => {
    setBusy(id)
    try {
      await api.post(`/social/questions/${id}/answer`, { answer: `<p>${answers[id].replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/\n/g, '<br>')}</p>` })
      toast(String(t('soc.questions.answered'))); setAnswers((a) => ({ ...a, [id]: '' })); res.refetch()
    } catch (e) { toast(errorMessage(e), 'error') } finally { setBusy(null) }
  }
  return (
    <>
      <div className="mb-4 flex flex-wrap items-center gap-2">
        <select className="input w-auto" value={status} onChange={(e) => setStatus(e.target.value)}><option value="">{t('soc.questions.all')}</option>{['open', 'answered', 'closed'].map((s) => <option key={s} value={s}>{t(`soc.questions.status.${s}`)}</option>)}</select>
        <label className="flex items-center gap-2 text-sm"><input type="checkbox" className="accent-gold-600" checked={overdue} onChange={(e) => setOverdue(e.target.checked)} />{t('soc.questions.onlyOverdue')}</label>
      </div>
      {res.isLoading ? <Spinner /> : (res.data?.data.length ?? 0) === 0 ? <Empty text={String(t('soc.questions.empty'))} icon={<Inbox className="size-8" />} /> : (
        <div className="space-y-4">
          {res.data!.data.map((q) => (
            <Card key={q.id}>
              <div className="flex flex-wrap items-start justify-between gap-2">
                <div>
                  <h3 className="font-display text-lg font-bold text-navy-900">{q.subject}</h3>
                  <p className="text-xs text-slate-500">{t('soc.questions.from')}: {q.asker} · {t('soc.questions.program')}: {i18n.language === 'en' ? q.program?.title_en : q.program?.title_ar} · {fmt.dateTime(q.created_at)}</p>
                </div>
                <div className="flex gap-1.5">{q.overdue && <Badge color="red">{t('soc.questions.overdue')}</Badge>}<Badge color={q.status === 'answered' ? 'green' : q.status === 'closed' ? 'gray' : 'amber'}>{t(`soc.questions.status.${q.status}`)}</Badge></div>
              </div>
              <div className="prose prose-sm mt-3 max-w-none" dangerouslySetInnerHTML={{ __html: safeHtml(q.body ?? '') }} />
              {q.answer ? <div className="mt-3 rounded-xl border border-emerald-200 bg-emerald-50/60 p-3 text-sm" dangerouslySetInnerHTML={{ __html: safeHtml(q.answer) }} /> : (
                <div className="mt-3 flex items-start gap-2">
                  <textarea className="input min-h-20 flex-1" value={answers[q.id] ?? ''} onChange={(e) => setAnswers({ ...answers, [q.id]: e.target.value })} placeholder={String(t('soc.questions.writeAnswer'))} />
                  <Button variant="gold" loading={busy === q.id} disabled={!(answers[q.id] ?? '').trim()} onClick={() => send(q.id)}>{t('soc.questions.sendAnswer')}</Button>
                </div>
              )}
              {!q.answer && <p className="mt-2 text-xs text-slate-400">{t('soc.questions.due')}: {fmt.dateTime(q.due_at)}</p>}
            </Card>
          ))}
        </div>
      )}
    </>
  )
}

function Reviews() {
  const { t } = useTranslation()
  const res = useGet<{ data: any[] }>('/social/reviews', undefined, { staleTime: 0 })
  const set = async (id: string, status: string) => { try { await api.post(`/social/ratings/${id}/moderate`, { status }); res.refetch() } catch (e) { toast(errorMessage(e), 'error') } }
  if (res.isLoading) return <Spinner />
  if (!res.data?.data.length) return <Empty text={String(t('soc.reviews.empty'))} />
  return (
    <Card padded={false}>
      <ul className="divide-y divide-navy-50">
        {res.data.data.map((r) => (
          <li key={r.id} className="flex flex-wrap items-start justify-between gap-3 px-5 py-4 text-sm">
            <div><p className="font-semibold text-navy-900">{'★'.repeat(r.stars)}<span className="text-navy-200">{'★'.repeat(5 - r.stars)}</span> <span className="ms-2 text-xs font-normal text-slate-500">{r.author} · {r.subject_type}</span></p><p className="mt-1 text-ink">{r.review}</p></div>
            <div className="flex items-center gap-2"><Badge color={r.status === 'hidden' ? 'gray' : 'green'}>{t(`soc.reviews.status.${r.status}`)}</Badge><Button size="sm" variant="outline" onClick={() => set(r.id, r.status === 'hidden' ? 'published' : 'hidden')}>{t(r.status === 'hidden' ? 'soc.reviews.show' : 'soc.reviews.hide')}</Button></div>
          </li>
        ))}
      </ul>
    </Card>
  )
}

function SettingsCard() {
  const { t } = useTranslation()
  const res = useGet<{ data: any }>('/social/settings', undefined, { staleTime: 0 })
  const [f, setF] = useState<any>(null)
  const [busy, setBusy] = useState(false)
  useEffect(() => { if (res.data) setF(res.data.data) }, [res.data])
  if (!f) return <Spinner />
  const save = async () => {
    setBusy(true)
    try { const { data } = await api.put('/social/settings', f); setF(data.data); toast(String(t('soc.studio.saved'))) } catch (e) { toast(errorMessage(e), 'error') } finally { setBusy(false) }
  }
  return (
    <Card className="max-w-xl space-y-4">
      <Field label={t('soc.questions.sla')}><input type="number" min={1} max={720} className="input" value={f.trainer_sla_hours} onChange={(e) => setF({ ...f, trainer_sla_hours: Number(e.target.value) })} /></Field>
      <label className="flex items-center gap-2 text-sm"><input type="checkbox" className="accent-gold-600" checked={f.daily_digest} onChange={(e) => setF({ ...f, daily_digest: e.target.checked })} />{t('soc.questions.digest')}</label>
      <Field label={t('soc.questions.digestHour')}><input type="number" min={0} max={23} className="input" value={f.digest_hour} onChange={(e) => setF({ ...f, digest_hour: Number(e.target.value) })} /></Field>
      <label className="flex items-center gap-2 text-sm"><input type="checkbox" className="accent-gold-600" checked={f.rating_reviews} onChange={(e) => setF({ ...f, rating_reviews: e.target.checked })} />{t('soc.questions.reviews')}</label>
      <div className="flex justify-end"><Button variant="gold" loading={busy} onClick={save}>{t('soc.common.save')}</Button></div>
    </Card>
  )
}
