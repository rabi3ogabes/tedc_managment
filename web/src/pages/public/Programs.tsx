import clsx from 'clsx'
import { Search } from 'lucide-react'
import { useState } from 'react'
import { useTranslation } from 'react-i18next'
import ProgramCard from '@/components/public/ProgramCard'
import { PageHero } from '@/components/public/Section'
import { Button, Empty, Spinner } from '@/components/ui'
import { useGet } from '@/hooks/useApi'
import type { Paginated, Program } from '@/lib/types'

export default function Programs() {
  const { t } = useTranslation()
  const [q, setQ] = useState('')
  const [category, setCategory] = useState('')
  const [mode, setMode] = useState('')
  const [open, setOpen] = useState(false)
  const [page, setPage] = useState(1)

  const categories = useGet<{ data: { id: string; slug: string; name: string }[] }>('/public/categories')
  const { data, isLoading } = useGet<Paginated<Program>>('/public/programs', { q: q || undefined, category: category || undefined, mode: mode || undefined, open: open ? 1 : undefined, page })

  return (
    <>
      <PageHero title={t('programs.title')} subtitle={t('programs.subtitle')}>
        <div className="glass mt-8 flex max-w-2xl items-center gap-3 rounded-2xl p-2">
          <Search className="ms-3 size-5 text-slate-400" />
          <input value={q} onChange={(e) => { setQ(e.target.value); setPage(1) }} placeholder={t('programs.searchPlaceholder')} className="flex-1 bg-transparent py-2 text-ink outline-none placeholder:text-slate-400" />
        </div>
      </PageHero>
      <section className="py-12">
        <div className="container-x">
          <div className="mb-8 flex flex-wrap items-center gap-2">
            <Chip active={!category} onClick={() => setCategory('')}>{t('common.all')}</Chip>
            {categories.data?.data.map((c) => <Chip key={c.id} active={category === c.slug} onClick={() => { setCategory(c.slug); setPage(1) }}>{c.name}</Chip>)}
            <span className="mx-2 h-6 w-px bg-navy-100" />
            {(['in_person', 'online', 'hybrid'] as const).map((m) => <Chip key={m} active={mode === m} onClick={() => setMode(mode === m ? '' : m)}>{t(`modes.${m}`)}</Chip>)}
            <Chip active={open} onClick={() => setOpen(!open)}>{t('programs.openOnly')}</Chip>
          </div>
          {isLoading ? <Spinner /> : !data?.data.length ? <Empty /> : (
            <>
              <div className="grid gap-6 sm:grid-cols-2 lg:grid-cols-3">{data.data.map((p) => <ProgramCard key={p.id} program={p} />)}</div>
              {data.meta && data.meta.last_page > 1 && (
                <div className="mt-10 flex justify-center gap-2">
                  <Button variant="outline" size="sm" disabled={page <= 1} onClick={() => setPage(page - 1)}>{t('common.previous')}</Button>
                  <span className="px-3 py-1.5 text-sm text-slate-500">{page} / {data.meta.last_page}</span>
                  <Button variant="outline" size="sm" disabled={page >= data.meta.last_page} onClick={() => setPage(page + 1)}>{t('common.next')}</Button>
                </div>
              )}
            </>
          )}
        </div>
      </section>
    </>
  )
}

function Chip({ active, onClick, children }: { active: boolean; onClick: () => void; children: React.ReactNode }) {
  return (
    <button onClick={onClick} className={clsx('rounded-full px-4 py-2 text-sm font-semibold transition', active ? 'bg-navy-900 text-white shadow' : 'border border-navy-100 bg-white text-navy-800 hover:border-gold-400')}>
      {children}
    </button>
  )
}
