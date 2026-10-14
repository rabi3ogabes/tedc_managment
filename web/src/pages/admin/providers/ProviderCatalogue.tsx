import clsx from 'clsx'
import { BookOpen, ExternalLink, Search } from 'lucide-react'
import { useEffect, useState } from 'react'
import { useTranslation } from 'react-i18next'
import { Link } from 'react-router-dom'
import { useLang } from '@/components/dashboard/Widget'
import { Badge, Button, Card, CardTitle, Empty, Spinner } from '@/components/ui'
import { useGet } from '@/hooks/useApi'
import { api, errorMessage } from '@/lib/api'
import { toast } from '@/lib/toast'
import type { CatalogueCourse, Paged, Provider } from './types'

const control = 'rounded-xl border border-navy-100 bg-white px-3 py-2 text-sm'

/** The courses TEDC has read from the providers: search them, and make a programme from any so employees can register in it. */
export default function ProviderCatalogue({ providers, refreshKey, onChanged }: { providers: Provider[]; refreshKey: number; onChanged: () => void }) {
  const { t } = useTranslation()
  const L = useLang()
  const [text, setText] = useState('')
  const [q, setQ] = useState('')
  const [provider, setProvider] = useState('')
  const [status, setStatus] = useState('')
  const [imported, setImported] = useState('')
  const [page, setPage] = useState(1)
  const [making, setMaking] = useState<string | null>(null)

  useEffect(() => {   // search as the person pauses, not at every key
    const timer = setTimeout(() => { setQ(text.trim()); setPage(1) }, 300)
    return () => clearTimeout(timer)
  }, [text])

  const res = useGet<Paged<CatalogueCourse>>('/admin/learning-providers-courses', { q: q || undefined, provider: provider || undefined, status: status || undefined, imported: imported || undefined, page, per_page: 12 }, { staleTime: 0 })
  const rows = res.data?.data ?? []
  const names = Object.fromEntries(providers.map((p) => [p.key, p.name]))
  const name = (key: string) => (names[key] ? L(names[key]) : key)
  const { refetch } = res
  useEffect(() => { if (refreshKey > 0) void refetch() }, [refreshKey, refetch])

  const make = async (course: CatalogueCourse) => {
    setMaking(course.id)
    try {
      await api.post(`/admin/external-courses/${course.id}/program`)
      toast(String(t('content.providers.programMade')))
      await res.refetch()
      onChanged()
    } catch (e) {
      toast(errorMessage(e), 'error')
    } finally {
      setMaking(null)
    }
  }

  return (
    <Card>
      <CardTitle subtitle={t('min.providers.catalogue.hint')} action={res.data ? <span className="text-sm text-slate-400">{t('min.providers.catalogue.total', { n: res.data.total })}</span> : undefined}>{t('min.providers.catalogue.title')}</CardTitle>

      <div className="mb-5 flex flex-wrap gap-2">
        <div className="relative min-w-56 flex-1">
          <Search className="pointer-events-none absolute start-3 top-1/2 size-4 -translate-y-1/2 text-slate-400" aria-hidden="true" />
          <input type="search" className={clsx(control, 'w-full ps-9')} placeholder={t('min.providers.catalogue.search')} aria-label={t('min.providers.catalogue.search')} value={text} onChange={(e) => setText(e.target.value)} />
        </div>
        <select className={control} aria-label={t('min.providers.issues.provider')} value={provider} onChange={(e) => { setProvider(e.target.value); setPage(1) }}>
          <option value="">{t('min.providers.catalogue.all')}</option>
          {providers.map((p) => <option key={p.key} value={p.key}>{name(p.key)}</option>)}
        </select>
        <select className={control} aria-label={t('min.providers.catalogue.anyStatus')} value={status} onChange={(e) => { setStatus(e.target.value); setPage(1) }}>
          <option value="">{t('min.providers.catalogue.anyStatus')}</option>
          {['available', 'retired', 'removed'].map((s) => <option key={s} value={s}>{t(`min.providers.catalogue.${s}`)}</option>)}
        </select>
        <select className={control} aria-label={t('min.providers.catalogue.anyProgramme')} value={imported} onChange={(e) => { setImported(e.target.value); setPage(1) }}>
          <option value="">{t('min.providers.catalogue.anyProgramme')}</option>
          <option value="1">{t('min.providers.catalogue.made')}</option>
          <option value="0">{t('min.providers.catalogue.notYet')}</option>
        </select>
      </div>

      {!res.data ? <Spinner /> : rows.length === 0 ? <Empty text={t('min.providers.catalogue.empty')} icon={<BookOpen className="size-6" />} /> : (
        <ul className="divide-y divide-navy-100/70">
          {rows.map((c) => (
            <li key={c.id} className="flex items-start gap-4 py-4 first:pt-0 last:pb-0">
              {c.image_url
                ? <img src={c.image_url} alt="" loading="lazy" className="size-14 shrink-0 rounded-xl bg-navy-50 object-cover" onError={(e) => { e.currentTarget.style.visibility = 'hidden' }} />
                : <div className="grid size-14 shrink-0 place-items-center rounded-xl bg-navy-50 text-navy-300" aria-hidden="true"><BookOpen className="size-6" /></div>}
              <div className="min-w-0 flex-1">
                <div className="flex flex-wrap items-center gap-x-2 gap-y-1">
                  <h4 className="font-semibold text-navy-900" dir="auto">{c.title}</h4>
                  {c.status === 'retired' && <Badge color="amber">{t('min.providers.catalogue.retiredBadge')}</Badge>}
                  {c.status === 'removed' && <Badge color="gray">{t('min.providers.catalogue.removedBadge')}</Badge>}
                </div>
                <p className="mt-0.5 text-xs text-slate-400">
                  {name(c.provider)}{c.hours > 0 && ` · ${t('min.providers.catalogue.hours', { n: c.hours })}`}{c.level && ` · ${c.level}`}{c.language && ` · ${c.language.toUpperCase()}`}
                </p>
                {c.summary && <p className="mt-1.5 line-clamp-2 text-sm text-slate-500" dir="auto">{c.summary}</p>}
              </div>
              <div className="flex shrink-0 flex-col items-end gap-2">
                {c.program_id
                  ? <Link to={`/admin/programs/${c.program_id}`} className="inline-flex items-center gap-1 text-xs font-semibold text-emerald-700 hover:underline">{t('min.providers.catalogue.programmeMade')}</Link>
                  : <Button size="sm" variant="outline" loading={making === c.id} onClick={() => void make(c)}>{t('min.providers.catalogue.makeProgramme')}</Button>}
                {c.url && <a href={c.url} target="_blank" rel="noopener noreferrer" className="inline-flex items-center gap-1 text-xs text-slate-400 hover:text-navy-800"><ExternalLink className="size-3.5" aria-hidden="true" />{t('min.providers.catalogue.open')}</a>}
              </div>
            </li>
          ))}
        </ul>
      )}

      {res.data && res.data.last_page > 1 && (
        <nav className="mt-5 flex items-center justify-between border-t border-navy-100/70 pt-4" aria-label={t('min.providers.catalogue.title')}>
          <Button size="sm" variant="outline" disabled={page <= 1} onClick={() => setPage(page - 1)}>{t('min.providers.catalogue.prev')}</Button>
          <span className="text-sm text-slate-500">{t('min.providers.catalogue.page', { p: res.data.current_page, n: res.data.last_page })}</span>
          <Button size="sm" variant="outline" disabled={page >= res.data.last_page} onClick={() => setPage(page + 1)}>{t('min.providers.catalogue.next')}</Button>
        </nav>
      )}
    </Card>
  )
}
