import { ThumbsDown, ThumbsUp } from 'lucide-react'
import { useState } from 'react'
import { useTranslation } from 'react-i18next'
import { Button, Spinner } from '@/components/ui'
import { useGet } from '@/hooks/useApi'
import { api, errorMessage } from '@/lib/api'
import { fmt } from '@/lib/format'
import { safeHtml } from '@/lib/safeHtml'
import { toast } from '@/lib/toast'
import { useLang, type HelpArticle } from './helpApi'

/** One article: the text, screenshots, the video and the «was this helpful?» question. */
export default function ArticleView({ slug }: { slug: string }) {
  const { t } = useTranslation()
  const { pick } = useLang()
  const res = useGet<{ data: HelpArticle }>(`/me/help/articles/${slug}`)
  const [voted, setVoted] = useState<boolean | null>(null)
  const [comment, setComment] = useState('')
  const [sent, setSent] = useState(false)

  const vote = async (helpful: boolean, text?: string) => {
    try {
      await api.post(`/me/help/articles/${slug}/feedback`, { helpful, comment: text || undefined })
      setVoted(helpful)
      if (text !== undefined || helpful) setSent(true)
    } catch (e) { toast(errorMessage(e), 'error') }
  }

  if (res.isLoading) return <Spinner />
  const a = res.data?.data
  if (!a) return null
  const body = safeHtml(pick(a, 'body') || '')
  return (
    <article className="space-y-4">
      <h2 className="text-xl font-bold text-navy-900">{pick(a, 'title')}</h2>
      <p className="text-xs text-slate-500">{t('hlp.version')} {a.version}{a.updated_at ? ` · ${t('hlp.updated')}: ${fmt.date(a.updated_at)}` : ''}</p>
      <div className="help-body space-y-3 leading-8 text-slate-800 [&_blockquote]:border-s-4 [&_blockquote]:border-gold-500 [&_blockquote]:bg-ivory [&_blockquote]:px-3 [&_blockquote]:py-1 [&_h3]:pt-2 [&_h3]:text-lg [&_h3]:font-bold [&_h3]:text-navy-900 [&_li]:ms-1 [&_ol]:list-decimal [&_ol]:ps-6 [&_ul]:list-disc [&_ul]:ps-6" dangerouslySetInnerHTML={{ __html: body }} />
      {(a.screenshots?.length ?? 0) > 0 && (
        <div className="space-y-3">
          <h3 className="font-bold text-navy-900">{t('hlp.shots')}</h3>
          {a.screenshots!.map((s, i) => (
            <figure key={i} className="overflow-hidden rounded-xl border border-navy-100">
              <img src={s.url} alt={pick(s, 'caption') || pick(a, 'title')} loading="lazy" className="w-full" />
              {pick(s, 'caption') && <figcaption className="bg-ivory px-3 py-2 text-xs text-slate-600">{pick(s, 'caption')}</figcaption>}
            </figure>
          ))}
        </div>
      )}
      {(a.video_asset_url || a.video_url) && (
        <div className="space-y-2">
          <h3 className="font-bold text-navy-900">{t('hlp.video')}</h3>
          {a.video_asset_url
            ? <video controls preload="metadata" className="w-full rounded-xl bg-black" src={a.video_asset_url} />
            : <a className="inline-flex text-navy-700 underline" href={a.video_url!} target="_blank" rel="noopener noreferrer">{t('hlp.watch')}</a>}
        </div>
      )}
      <div className="rounded-xl bg-ivory p-4">
        {sent ? <p className="text-sm font-semibold text-navy-900" role="status">{t('hlp.thanks')}</p> : (
          <div className="space-y-2">
            <p className="text-sm font-semibold text-navy-900">{t('hlp.helpful')}</p>
            <div className="flex gap-2">
              <Button size="sm" variant={voted === true ? 'gold' : 'ghost'} icon={<ThumbsUp className="size-4" />} onClick={() => vote(true)}>{t('hlp.yes')}</Button>
              <Button size="sm" variant={voted === false ? 'gold' : 'ghost'} icon={<ThumbsDown className="size-4" />} onClick={() => vote(false)}>{t('hlp.no')}</Button>
            </div>
            {voted === false && (
              <div className="space-y-2">
                <textarea className="input min-h-16" maxLength={1000} placeholder={String(t('hlp.comment'))} value={comment} onChange={(e) => setComment(e.target.value)} />
                <Button size="sm" variant="gold" onClick={() => vote(false, comment)}>{t('hlp.sendComment')}</Button>
              </div>
            )}
          </div>
        )}
      </div>
    </article>
  )
}
