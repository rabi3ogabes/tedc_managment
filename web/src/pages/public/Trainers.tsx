import { BadgeCheck, Star } from 'lucide-react'
import { useTranslation } from 'react-i18next'
import { PageHero } from '@/components/public/Section'
import { Avatar, Spinner } from '@/components/ui'
import { useGet } from '@/hooks/useApi'
import { fmt } from '@/lib/format'
import type { Trainer } from '@/lib/types'

export default function Trainers() {
  const { t } = useTranslation()
  const { data, isLoading } = useGet<{ data: Trainer[] }>('/public/trainers')

  return (
    <>
      <PageHero title={t('trainers.title')} subtitle={t('trainers.subtitle')} />
      <section className="py-16">
        <div className="container-x">
          {isLoading ? <Spinner /> : (
            <div className="grid gap-6 sm:grid-cols-2 lg:grid-cols-4">
              {data?.data.map((tr) => (
                <div key={tr.id} className="card group overflow-hidden text-center transition hover:-translate-y-1 hover:shadow-glass">
                  <div className="relative h-28 bg-gradient-to-br from-navy-900 to-navy-700"><div className="pattern-bg absolute inset-0 opacity-40" /></div>
                  <div className="-mt-14 flex justify-center"><Avatar name={tr.name} src={tr.photo_url} size={104} /></div>
                  <div className="p-5 pt-3">
                    <h3 className="text-lg font-bold text-navy-900">{tr.name}</h3>
                    <p className="text-sm text-gold-700">{tr.title}</p>
                    <p className="mt-3 line-clamp-3 text-sm text-slate-500">{tr.bio}</p>
                    <div className="mt-4 flex items-center justify-center gap-4 text-sm">
                      <span className="flex items-center gap-1 font-bold text-navy-900"><Star className="size-4 fill-gold-500 text-gold-500" />{fmt.number(tr.rating, 1)}</span>
                      <span className="text-slate-400">{fmt.number(tr.programs_count ?? 0)} {t('trainers.programs')}</span>
                    </div>
                    {tr.is_external && <p className="mt-3 inline-flex items-center gap-1 text-xs text-slate-500"><BadgeCheck className="size-4 text-emerald-600" />{tr.organization ?? t('trainers.external')}</p>}
                  </div>
                </div>
              ))}
            </div>
          )}
        </div>
      </section>
    </>
  )
}
