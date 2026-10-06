/* eslint-disable @typescript-eslint/no-explicit-any */
import { Quote } from 'lucide-react'
import { safeHtml } from '@/lib/safeHtml'

const pick = (v: any, lang: string) => (v && typeof v === 'object' ? v[lang] || v.ar || v.en || '' : v ?? '')

/** A faithful-enough rendering of a block for the editor's live preview (both languages, desktop or phone width). */
export default function BlockPreview({ block, lang, data }: { block: any; lang: 'ar' | 'en'; data?: any }) {
  const c = block.config ?? {}
  const title = pick(c.title, lang)
  const head = title ? <h3 className="mb-3 text-lg font-bold text-navy-900">{title}</h3> : null
  const wrap = (children: React.ReactNode, dark = false) => <section className={`px-5 py-6 ${dark ? 'bg-navy-900 text-white' : ''} ${block.is_visible ? '' : 'opacity-40'}`}>{children}</section>

  switch (block.type) {
    case 'hero_slider': {
      const s = (c.slides ?? [])[0]
      return wrap(<div className="relative grid min-h-36 place-items-center overflow-hidden rounded-2xl bg-gradient-to-l from-navy-900 to-navy-700 p-6 text-center text-white">{s?.image && <img src={s.image} alt="" className="absolute inset-0 size-full object-cover opacity-50" />}<div className="relative"><div className="text-xl font-bold">{pick(s?.title, lang) || '—'}</div><div className="mt-1 text-sm text-white/80">{pick(s?.subtitle, lang)}</div>{s && pick(s.label, lang) && <span className="mt-3 inline-block rounded-full bg-gold-500 px-4 py-1 text-xs font-bold text-navy-950">{pick(s.label, lang)}</span>}</div></div>)
    }
    case 'stats':
      return wrap(<>{head && <h3 className="mb-3 text-lg font-bold text-white">{title}</h3>}<div className="grid grid-cols-2 gap-2 sm:grid-cols-3">{(data ?? []).map((s: any) => <div key={s.key} className="rounded-xl bg-white/10 p-3 text-center"><div className="font-display text-2xl font-bold text-gold-300">{s.value}</div><div className="text-xs text-white/70">{pick(s.label, lang)}</div></div>)}</div></>, true)
    case 'news':
    case 'events':
      return wrap(<>{head}<div className="grid gap-2 sm:grid-cols-3">{(data ?? []).slice(0, c.limit ?? 3).map((n: any) => <div key={n.id} className="rounded-xl border border-navy-100 p-3 text-sm font-semibold text-navy-900">{pick(n.title, lang)}</div>)}{!(data ?? []).length && <div className="text-xs text-slate-400">—</div>}</div></>)
    case 'featured_programs':
      return wrap(<>{head}<div className="grid gap-2 sm:grid-cols-3">{Array.from({ length: Math.min(c.limit ?? 3, 3) }, (_, i) => <div key={i} className="h-16 rounded-xl bg-navy-50" />)}</div></>)
    case 'rich_text':
      return wrap(<>{head}<div className="prose prose-sm max-w-none text-slate-700" dangerouslySetInnerHTML={{ __html: safeHtml(pick(c.body, lang)) }} /></>)
    case 'custom_html_safe':
      return wrap(<div className="text-slate-700" dangerouslySetInnerHTML={{ __html: safeHtml(pick(c.html, lang)) }} />)
    case 'cta':
      return wrap(<div className="rounded-2xl bg-gradient-to-l from-navy-900 to-navy-700 p-6 text-white"><div className="text-lg font-bold">{title}</div><p className="mt-1 text-sm text-white/75">{pick(c.text, lang)}</p>{pick(c.label, lang) && <span className="mt-3 inline-block rounded-full bg-gold-500 px-4 py-1 text-xs font-bold text-navy-950">{pick(c.label, lang)}</span>}</div>)
    case 'logos':
      return wrap(<>{head}<div className="flex flex-wrap gap-3">{(c.items ?? []).map((i: any, k: number) => <div key={k} className="grid h-12 min-w-20 place-items-center rounded-xl border border-navy-100 px-3 text-xs">{i.image ? <img src={i.image} alt={pick(i.name, lang)} className="max-h-8" /> : pick(i.name, lang)}</div>)}</div></>)
    case 'faq':
      return wrap(<>{head}<div className="space-y-2">{(c.items ?? []).map((i: any, k: number) => <div key={k} className="rounded-xl border border-navy-100 p-3"><div className="flex items-center gap-2 text-sm font-bold text-navy-900"><Quote className="size-3 text-gold-600" />{pick(i.question, lang)}</div><div className="mt-1 text-xs text-slate-500" dangerouslySetInnerHTML={{ __html: safeHtml(pick(i.answer, lang)) }} /></div>)}</div></>)
    case 'video':
      return wrap(<>{head}<div className="grid aspect-video place-items-center rounded-2xl bg-navy-900 text-xs text-white/70">{c.video_url || '—'}</div></>)
    default:
      return null
  }
}
