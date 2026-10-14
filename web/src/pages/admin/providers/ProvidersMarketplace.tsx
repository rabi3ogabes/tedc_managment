import { History } from 'lucide-react'
import { useState } from 'react'
import { useTranslation } from 'react-i18next'
import { useLang } from '@/components/dashboard/Widget'
import { Badge, Button, Card, CardTitle, Empty, Spinner } from '@/components/ui'
import { useGet } from '@/hooks/useApi'
import { api, errorMessage } from '@/lib/api'
import { fmt } from '@/lib/format'
import { dialogs } from '@/lib/dialogs'
import { toast } from '@/lib/toast'
import { useStepUp } from '../SecurityAdmin'
import ConnectProviderDialog from './ConnectProviderDialog'
import ProviderCard from './ProviderCard'
import ProviderCatalogue from './ProviderCatalogue'
import ProviderIssues from './ProviderIssues'
import type { Provider, ProviderRun } from './types'

interface PendingReview { id: string; employee_name: string | null; program: string | null; note: string | null }
interface ReadResult { status: string; courses: number; completed: number; error: string | null }

/** Settings → Standards and integrations → Providers: connect each learning provider, read it, search what it offers, and see what could not be counted. */
export default function ProvidersMarketplace() {
  const { t } = useTranslation()
  const L = useLang()
  const step = useStepUp()
  const list = useGet<{ data: Provider[] }>('/admin/learning-providers', undefined, { staleTime: 0 })
  const runs = useGet<{ data: (ProviderRun & { id: string; provider: string })[] }>('/admin/learning-providers-runs', undefined, { staleTime: 0 })
  const reviews = useGet<{ data: PendingReview[] }>('/admin/external-completions', { status: 'pending' }, { staleTime: 0 })
  const [busy, setBusy] = useState<string | null>(null)
  const [editing, setEditing] = useState<Provider | null>(null)
  const [refresh, setRefresh] = useState(0)

  const providers = list.data?.data
  if (!providers) return <Spinner />
  const names = Object.fromEntries(providers.map((p) => [p.key, L(p.name)]))
  const anyReadable = providers.some((p) => p.enabled && p.connection !== 'not_set_up')

  const reload = async () => { await Promise.all([list.refetch(), runs.refetch()]); setRefresh((n) => n + 1) }
  const work = async (key: string, action: string, fn: () => Promise<void>) => {
    setBusy(`${key}:${action}`)
    try { await fn() } catch (e) { toast(errorMessage(e), 'error') } finally { setBusy(null) }
  }

  const toggle = (p: Provider, on: boolean) => step.guard(async () => {
    setBusy(`${p.key}:toggle`)
    try {
      await api.put(`/admin/learning-providers/${p.key}`, { enabled: on })
      await list.refetch()
    } finally {
      setBusy(null)
    }
  })
  const check = (p: Provider) => work(p.key, 'check', async () => {
    const { data } = await api.post<{ data: Provider }>(`/admin/learning-providers/${p.key}/check`)
    toast(String(t('min.providers.checked', { health: t(`min.providers.health.${data.data.health}`) })), data.data.health === 'ok' ? undefined : 'error')
    await list.refetch()
  })
  const read = (p: Provider, full: boolean) => work(p.key, full ? 'full' : 'sync', async () => {
    const { data } = await api.post<{ data: ReadResult }>(`/admin/learning-providers/${p.key}/sync`, { full })
    if (data.data.error) toast(String(t('min.providers.readFailed', { error: data.data.error })), 'error')
    else toast(String(t('min.providers.read', { courses: data.data.courses, completed: data.data.completed })))
    await reload()
  })
  const readAll = () => work('all', 'sync', async () => {
    const { data } = await api.post<{ data: { courses: number; completed: number; errors: string[] } }>('/admin/content-providers/sync')
    if (data.data.errors.length) toast(String(t('min.providers.readFailed', { error: data.data.errors.join(' · ') })), 'error')
    else toast(String(t('min.providers.read', { courses: data.data.courses, completed: data.data.completed })))
    await reload()
  })
  const decide = async (id: string, decision: 'approve' | 'reject') => {
    const note = decision === 'approve' ? undefined : (await dialogs.prompt(t('content.providers.note'))) ?? ''
    if (decision === 'reject' && !note) return
    try {
      await api.post(`/admin/external-completions/${id}/decision`, { decision, note })
      await reviews.refetch()
    } catch (e) {
      toast(errorMessage(e), 'error')
    }
  }

  return (
    <div className="space-y-8">
      <header className="flex flex-col gap-3 sm:flex-row sm:items-end sm:justify-between">
        <div>
          <h2 className="text-xl font-bold text-navy-900">{t('min.providers.title')}</h2>
          <p className="mt-1 text-sm text-slate-500">{t('min.providers.subtitle')}</p>
        </div>
        <Button variant="gold" loading={busy === 'all:sync'} disabled={!anyReadable} onClick={() => void readAll()}>{busy === 'all:sync' ? t('min.providers.reading') : t('min.providers.readAll')}</Button>
      </header>

      <section aria-label={t('min.providers.title')} className="grid gap-5 lg:grid-cols-2">
        {providers.map((p) => (
          <ProviderCard key={p.key} p={p} busy={busy} onToggle={(on) => void toggle(p, on)} onConnect={() => setEditing(p)} onCheck={() => void check(p)} onSync={(full) => void read(p, full)} />
        ))}
      </section>

      <ProviderCatalogue providers={providers} refreshKey={refresh} onChanged={() => void list.refetch()} />
      <ProviderIssues names={names} refreshKey={refresh} onChanged={() => void list.refetch()} />

      <Card>
        <CardTitle subtitle={t('min.providers.reviews.hint')}>{t('min.providers.reviews.title')}</CardTitle>
        {!reviews.data?.data.length ? <Empty text={t('min.providers.reviews.none')} /> : (
          <ul className="divide-y divide-navy-100/70">
            {reviews.data.data.map((c) => (
              <li key={c.id} className="flex flex-wrap items-center gap-3 py-3 first:pt-0 last:pb-0">
                <div className="min-w-0 flex-1"><p className="font-semibold text-navy-900">{c.employee_name}</p><p className="text-sm text-slate-500">{c.program}</p>{c.note && <p className="text-xs text-slate-400" dir="auto">{c.note}</p>}</div>
                <Button size="sm" variant="gold" onClick={() => void decide(c.id, 'approve')}>{t('content.providers.approve')}</Button>
                <Button size="sm" variant="ghost" onClick={() => void decide(c.id, 'reject')}>{t('content.providers.reject')}</Button>
              </li>
            ))}
          </ul>
        )}
      </Card>

      <Card>
        <CardTitle>{t('min.providers.runs.title')}</CardTitle>
        {!runs.data?.data.length ? <Empty text={t('min.providers.never')} icon={<History className="size-6" />} /> : (
          <ul className="divide-y divide-navy-100/70 text-sm">
            {runs.data.data.slice(0, 10).map((r) => (
              <li key={r.id} className="flex flex-wrap items-center gap-x-4 gap-y-1 py-2.5 first:pt-0 last:pb-0">
                <span className="w-44 font-semibold text-navy-900">{names[r.provider] ?? r.provider}</span>
                <Badge color={r.status === 'ok' ? 'green' : r.status === 'failed' ? 'red' : 'amber'}>{t(`min.providers.run.${r.status}`)}</Badge>
                <span className="text-slate-500">{t('min.providers.runs.summary', { courses: r.courses_seen, done: r.completions_applied, issues: r.issues_found })}</span>
                <span className="ms-auto text-xs text-slate-400">{t(`min.providers.runs.trigger.${r.trigger}`)} · {fmt.dateTime(r.started_at)}</span>
                {r.error && <span className="basis-full text-xs text-red-700" dir="auto">{r.error}</span>}
              </li>
            ))}
          </ul>
        )}
      </Card>

      {editing && <ConnectProviderDialog p={editing} onClose={() => setEditing(null)} onSaved={() => void list.refetch()} />}
      {step.dialog}
    </div>
  )
}
