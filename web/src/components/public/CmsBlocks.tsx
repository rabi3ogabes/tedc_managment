/* eslint-disable @typescript-eslint/no-explicit-any */
import { useTranslation } from 'react-i18next'
import { Link } from 'react-router-dom'
import CountUp from '@/components/public/CountUp'
import HeroQuickBar from '@/components/public/HeroQuickBar'
import HeroSlider from '@/components/public/HeroSlider'
import ProgramCard from '@/components/public/ProgramCard'
import Reveal from '@/components/public/Reveal'
import { SectionTitle } from '@/components/public/Section'
import { Button } from '@/components/ui'
import { fmt } from '@/lib/format'
import { safeHtml } from '@/lib/safeHtml'
import type { Program } from '@/lib/types'

type Block = { type: string; config: any; data?: any }
const isUrl = (u?: string) => !!u && /^(https?:\/\/|\/|#|mailto:)/i.test(u)

/** The published blocks of a page as visitors see them. Live data (statistics, news, events) arrives with the block. */
export default function CmsBlocks({ blocks, programs = [], news: NewsCard }: { blocks: Block[]; programs?: Program[]; news: React.ComponentType<{ item: any }> }) {
  const { i18n, t } = useTranslation()
  const lang = i18n.language === 'en' ? 'en' : 'ar'
  const pick = (v: any): string => (v && typeof v === 'object' ? v[lang] || v.ar || v.en || '' : v ?? '')
  const link = (label: string, url?: string, variant: 'gold' | 'outline' | 'primary' = 'gold') => (label && isUrl(url) ? (/^https?:/i.test(url!) ? <a href={url} target="_blank" rel="noreferrer noopener" className="btn-gold inline-flex rounded-xl px-5 py-2.5 text-sm font-bold">{label}</a> : <Button to={url!} variant={variant}>{label}</Button>) : null)

  return (
    <>
      {blocks.map((b, i) => {
        const c = b.config ?? {}
        const title = pick(c.title)
        switch (b.type) {
          case 'hero_slider': {
            const slides = (c.slides ?? []) as any[]
            if (!slides.length) return <div key={i}><HeroSlider /><HeroQuickBar /></div>
            const s = slides[0]
            return (
              <section key={i} className="relative grid min-h-[28rem] place-items-center overflow-hidden bg-gradient-to-l from-navy-900 to-navy-700 text-center text-white">
                {isUrl(s.image) && <img src={s.image} alt="" className="absolute inset-0 size-full object-cover opacity-45" />}
                <div className="relative container-x py-24"><h1 className="text-4xl font-bold sm:text-5xl">{pick(s.title)}</h1><p className="mx-auto mt-4 max-w-2xl text-lg text-white/80">{pick(s.subtitle)}</p><div className="mt-8">{link(pick(s.label), s.url)}</div></div>
              </section>
            )
          }
          case 'stats':
            return (
              <section key={i} className="relative overflow-hidden bg-navy-900 py-20">
                <div className="pattern-bg absolute inset-0 opacity-25" />
                <div className="container-x relative">
                  {title && <SectionTitle title={title} center light />}
                  <div className="grid grid-cols-2 gap-4 md:grid-cols-3 lg:grid-cols-5">
                    {(b.data ?? []).map((s: any) => (
                      <div key={s.key} className="glass-dark rounded-2xl p-5 text-center">
                        <div className="font-display text-3xl font-bold text-gold-300 sm:text-4xl">{typeof s.value === 'number' ? <CountUp value={s.value} format={(n) => fmt.number(Math.round(n))} /> : s.value}</div>
                        <div className="mx-auto mt-2 h-px w-8 bg-gold-400/40" /><div className="mt-2 text-sm text-white/70">{pick(s.label)}</div>
                      </div>
                    ))}
                  </div>
                </div>
              </section>
            )
          case 'featured_programs':
            return (
              <section key={i} className="py-20"><div className="container-x">
                <div className="flex flex-wrap items-end justify-between gap-4"><SectionTitle title={title || t('home.featured')} /><Link to="/programs" className="mb-10 text-sm font-bold text-link">{t('common.viewAll')}</Link></div>
                <div className="grid gap-6 sm:grid-cols-2 lg:grid-cols-3">{programs.slice(0, c.limit ?? 6).map((p, k) => <Reveal key={p.id} delay={k * 80} className="flex"><ProgramCard program={p} /></Reveal>)}</div>
              </div></section>
            )
          case 'news':
            return !(b.data ?? []).length ? null : (
              <section key={i} className="py-20"><div className="container-x"><SectionTitle title={title || t('home.news')} /><div className="grid gap-6 md:grid-cols-3">{b.data.map((n: any) => <NewsCard key={n.id} item={{ id: n.id, title: pick(n.title), excerpt: pick(n.excerpt), cover_url: n.cover_url, published_at: n.published_at, type: n.type }} />)}</div></div></section>
            )
          case 'events':
            return !(b.data ?? []).length ? null : (
              <section key={i} className="bg-ivory-dark/60 py-20"><div className="container-x">
                <div className="flex items-end justify-between"><SectionTitle title={title || t('comm.events.title')} /><Link to="/events" className="mb-10 text-sm font-bold text-link">{t('common.viewAll')}</Link></div>
                <div className="grid gap-4 md:grid-cols-3">{b.data.map((e: any) => (
                  <Link key={e.id} to={`/events/${e.id}`} className="card p-5 transition hover:-translate-y-1 hover:shadow-glass"><time className="text-xs text-slate-400">{e.event?.starts_at ? fmt.date(e.event.starts_at, { dateStyle: 'medium', timeStyle: 'short' } as any) : ''}</time><h3 className="mt-1 font-bold text-navy-900">{pick(e.title)}</h3><p className="mt-1 line-clamp-2 text-sm text-slate-500">{pick(e.excerpt)}</p></Link>
                ))}</div>
              </div></section>
            )
          case 'rich_text':
            return <section key={i} className="py-16"><div className="container-x max-w-3xl">{title && <h2 className="mb-4 text-3xl font-bold text-navy-900">{title}</h2>}<div className="prose max-w-none leading-loose text-slate-700" dangerouslySetInnerHTML={{ __html: safeHtml(pick(c.body)) }} /></div></section>
          case 'custom_html_safe':
            return <section key={i} className="py-12"><div className="container-x" dangerouslySetInnerHTML={{ __html: safeHtml(pick(c.html)) }} /></section>
          case 'cta':
            return (
              <section key={i} className="pb-20"><div className="container-x"><div className="relative overflow-hidden rounded-[2rem] bg-gradient-to-l from-navy-900 via-navy-800 to-navy-700 p-10 text-white shadow-glass sm:p-14">
                <div className="pattern-bg absolute inset-0 opacity-25" /><div className="relative flex flex-col items-start justify-between gap-6 lg:flex-row lg:items-center"><div className="max-w-2xl"><h2 className="text-3xl font-bold">{title}</h2><p className="mt-3 text-white/75">{pick(c.text)}</p></div>{link(pick(c.label), c.url)}</div>
              </div></div></section>
            )
          case 'logos':
            return (
              <section key={i} className="py-16"><div className="container-x">{title && <SectionTitle title={title} center />}<div className="flex flex-wrap items-center justify-center gap-6">{(c.items ?? []).map((it: any, k: number) => { const img = isUrl(it.image) ? <img src={it.image} alt={pick(it.name)} className="max-h-14 object-contain grayscale transition hover:grayscale-0" /> : <span className="font-bold text-navy-800">{pick(it.name)}</span>; return isUrl(it.url) ? <a key={k} href={it.url} target="_blank" rel="noreferrer noopener">{img}</a> : <div key={k}>{img}</div> })}</div></div></section>
            )
          case 'faq':
            return (
              <section key={i} className="py-16"><div className="container-x max-w-3xl">{title && <SectionTitle title={title} />}<div className="space-y-3">{(c.items ?? []).map((it: any, k: number) => (
                <details key={k} className="card group p-4"><summary className="cursor-pointer font-bold text-navy-900">{pick(it.question)}</summary><div className="mt-3 text-slate-600" dangerouslySetInnerHTML={{ __html: safeHtml(pick(it.answer)) }} /></details>
              ))}</div></div></section>
            )
          case 'video': {
            const u: string = c.video_url ?? ''
            const yt = u.match(/(?:youtube\.com\/watch\?v=|youtu\.be\/)([\w-]{6,})/)
            return (
              <section key={i} className="py-16"><div className="container-x max-w-4xl">{title && <SectionTitle title={title} />}
                {yt ? <iframe title={title || 'video'} className="aspect-video w-full rounded-2xl" src={`https://www.youtube-nocookie.com/embed/${yt[1]}`} allowFullScreen loading="lazy" referrerPolicy="strict-origin-when-cross-origin" />
                  : /\.(mp4|webm)(\?|$)/i.test(u) ? <video controls preload="metadata" src={u} className="w-full rounded-2xl" /> : isUrl(u) ? <a href={u} target="_blank" rel="noreferrer noopener" className="text-link">{u}</a> : null}
                {pick(c.text) && <p className="mt-4 text-slate-600">{pick(c.text)}</p>}
              </div></section>
            )
          }
          default:
            return null
        }
      })}
    </>
  )
}
