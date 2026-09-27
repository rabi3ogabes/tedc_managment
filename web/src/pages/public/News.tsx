import { Download, ExternalLink, PlayCircle } from 'lucide-react'
import { useTranslation } from 'react-i18next'
import { useParams } from 'react-router-dom'
import { PageHero } from '@/components/public/Section'
import { Card, Empty, Spinner } from '@/components/ui'
import { useGet } from '@/hooks/useApi'
import { fmt } from '@/lib/format'
import { NewsCard } from './Home'

type NewsItem = { id: string; title: string; excerpt: string; cover_url?: string | null; published_at: string; type: string; body?: string; attachments?: { type: string; title: string; url?: string }[] }

export default function News() {
  const { t } = useTranslation()
  const { data, isLoading } = useGet<{ data: NewsItem[] }>('/public/news')
  return (
    <>
      <PageHero title={t('news.title')} subtitle={t('news.subtitle')} />
      <section className="py-16">
        <div className="container-x">
          {isLoading ? <Spinner /> : !data?.data.length ? <Empty /> : <div className="grid gap-6 md:grid-cols-3">{data.data.map((n) => <NewsCard key={n.id} item={n} />)}</div>}
        </div>
      </section>
    </>
  )
}

export function NewsDetail() {
  const { id } = useParams()
  const { data, isLoading } = useGet<{ data: NewsItem }>(`/public/news/${id}`)
  const item = data?.data
  if (isLoading || !item) return <div className="pt-32"><Spinner /></div>
  return (
    <>
      <PageHero title={item.title} subtitle={fmt.date(item.published_at)} />
      <section className="py-12">
        <div className="container-x max-w-3xl">
          <Card>
            <p className="whitespace-pre-line text-lg leading-loose text-slate-700">{item.body}</p>
            {!!item.attachments?.length && (
              <div className="mt-8 space-y-2">
                {item.attachments.filter((a) => a.url).map((a) => (
                  <a key={a.title} href={a.url} target="_blank" rel="noreferrer" className="flex items-center gap-3 rounded-xl bg-ivory p-3 text-sm font-semibold text-navy-800 hover:bg-gold-100">
                    {a.type === 'video' ? <PlayCircle className="size-5 text-gold-600" /> : a.type === 'file' ? <Download className="size-5 text-gold-600" /> : <ExternalLink className="size-5 text-gold-600" />}
                    {a.title}
                  </a>
                ))}
              </div>
            )}
          </Card>
        </div>
      </section>
    </>
  )
}
