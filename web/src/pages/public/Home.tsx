import { Award, BrainCircuit, ChartNoAxesCombined, Quote, ShieldCheck, Star } from 'lucide-react'
import { useTranslation } from 'react-i18next'
import { Link } from 'react-router-dom'
import HeroSlider from '@/components/public/HeroSlider'
import ProgramCard from '@/components/public/ProgramCard'
import { SectionTitle } from '@/components/public/Section'
import { Avatar, Button, Spinner } from '@/components/ui'
import { useGet } from '@/hooks/useApi'
import { fmt } from '@/lib/format'
import { useTheme } from '@/lib/ThemeProvider'
import type { Program } from '@/lib/types'

type HomeData = {
  stats: Record<string, number>
  featured_programs: Program[]
  upcoming_programs: Program[]
  partners: { id: string; name: string; logo_url?: string | null; stage: string }[]
  testimonials: { quote: string; name: string; role?: string; school?: string; program: string; rating: number }[]
  news: { id: string; title: string; excerpt: string; cover_url?: string | null; published_at: string; type: string }[]
}

const pillarIcons = [ShieldCheck, BrainCircuit, ChartNoAxesCombined, Award]

export default function Home() {
  const { t } = useTranslation()
  const { data, isLoading } = useGet<{ data: HomeData }>('/public/home')
  const home = data?.data
  const pillars = t('home.pillars', { returnObjects: true }) as { title: string; text: string }[]
  const { active: theme } = useTheme()
  const ctaStyle = theme.banners.cta_style === 'image' && !theme.banners.page_banner_image ? 'gradient' : theme.banners.cta_style

  return (
    <>
      <HeroSlider />

      {/* Introduction */}
      <section className="relative py-24">
        <div className="container-x grid items-center gap-14 lg:grid-cols-2">
          <div>
            <SectionTitle eyebrow={t('home.introEyebrow')} title={t('home.introTitle')} text={t('home.introText')} />
            <Button to="/about" variant="outline">{t('common.readMore')}</Button>
          </div>
          <div className="grid gap-4 sm:grid-cols-2">
            {pillars.map((p, i) => {
              const Icon = pillarIcons[i]
              return (
                <div key={p.title} className="card group p-6 transition hover:-translate-y-1 hover:shadow-glass" style={{ animationDelay: `${i * 80}ms` }}>
                  <div className="grid size-12 place-items-center rounded-2xl bg-navy-900 text-gold-300 transition group-hover:bg-gold-500 group-hover:text-navy-950"><Icon className="size-6" /></div>
                  <h3 className="mt-4 text-lg font-bold text-navy-900">{p.title}</h3>
                  <p className="mt-2 text-sm leading-relaxed text-slate-500">{p.text}</p>
                </div>
              )
            })}
          </div>
        </div>
      </section>

      {/* Stats */}
      <section className="relative overflow-hidden bg-navy-900 py-20">
        <div className="pattern-bg absolute inset-0 opacity-25" />
        <div className="absolute -top-20 start-1/3 size-96 rounded-full bg-gold-500/10 blur-3xl" />
        <div className="container-x relative">
          <SectionTitle title={t('home.statsTitle')} center light />
          <div className="grid grid-cols-2 gap-4 md:grid-cols-3 lg:grid-cols-6">
            {(['programs', 'participants', 'certificates', 'schools', 'training_hours', 'satisfaction'] as const).map((key) => (
              <div key={key} className="glass-dark rounded-2xl p-5 text-center">
                <div className="font-display text-3xl font-bold text-gold-300 sm:text-4xl">
                  {home ? (key === 'satisfaction' ? fmt.percent(home.stats[key]) : fmt.number(home.stats[key])) : '—'}
                </div>
                <div className="mt-2 text-sm text-white/70">{t(`home.stats.${key}`)}</div>
              </div>
            ))}
          </div>
        </div>
      </section>

      {/* Featured */}
      <section className="py-24">
        <div className="container-x">
          <div className="flex flex-wrap items-end justify-between gap-4">
            <SectionTitle title={t('home.featured')} text={t('home.featuredText')} />
            <Link to="/programs" className="mb-10 text-sm font-bold text-link hover:opacity-80">{t('common.viewAll')}</Link>
          </div>
          {isLoading ? <Spinner /> : (
            <div className="grid gap-6 sm:grid-cols-2 lg:grid-cols-3">
              {home?.featured_programs.map((p) => <ProgramCard key={p.id} program={p} />)}
            </div>
          )}
        </div>
      </section>

      {/* Upcoming */}
      {!!home?.upcoming_programs.length && (
        <section className="bg-ivory-dark/60 py-24">
          <div className="container-x">
            <SectionTitle title={t('home.upcoming')} text={t('home.upcomingText')} />
            <div className="grid gap-4 md:grid-cols-2">
              {home.upcoming_programs.map((p) => (
                <Link key={p.id} to={`/programs/${p.code}`} className="card group flex items-center gap-5 p-4 transition hover:shadow-glass">
                  <div className="grid size-20 shrink-0 place-items-center rounded-2xl bg-navy-900 text-center text-white">
                    <div>
                      <div className="font-display text-2xl font-bold text-gold-300">{p.start_date ? new Date(p.start_date).getDate() : '—'}</div>
                      <div className="text-xs text-white/70">{fmt.date(p.start_date, { month: 'short' })}</div>
                    </div>
                  </div>
                  <div className="min-w-0 flex-1">
                    <h3 className="truncate font-bold text-navy-900 group-hover:text-link">{p.title}</h3>
                    <p className="mt-1 text-sm text-slate-500">{t(`modes.${p.delivery_mode}`)} · {fmt.number(p.total_hours)} {t('common.hours')} · {p.category?.name}</p>
                  </div>
                  <div className="hidden text-center sm:block">
                    <div className="font-display text-xl font-bold text-navy-900">{fmt.number(p.seats_available ?? 0)}</div>
                    <div className="text-xs text-slate-400">{t('common.seatsAvailable')}</div>
                  </div>
                </Link>
              ))}
            </div>
          </div>
        </section>
      )}

      {/* Partners */}
      {!!home?.partners.length && (
        <section className="py-24">
          <div className="container-x">
            <SectionTitle title={t('home.partners')} text={t('home.partnersText')} center />
            <div className="grid grid-cols-2 gap-4 sm:grid-cols-3 lg:grid-cols-4">
              {home.partners.map((s) => (
                <div key={s.id} className="card flex items-center gap-3 p-4 grayscale-[30%] transition hover:grayscale-0">
                  {s.logo_url ? <img src={s.logo_url} alt="" className="size-12 rounded-xl object-contain" /> : (
                    <div className="grid size-12 shrink-0 place-items-center rounded-xl bg-gradient-to-br from-gold-100 to-gold-300/60 font-display font-bold text-gold-700">{s.name.split(' ')[1]?.[0] ?? s.name[0]}</div>
                  )}
                  <div className="min-w-0">
                    <div className="truncate text-sm font-bold text-navy-900">{s.name}</div>
                    <div className="text-xs text-slate-400">{t(`stages.${s.stage}`)}</div>
                  </div>
                </div>
              ))}
            </div>
          </div>
        </section>
      )}

      {/* Testimonials */}
      {!!home?.testimonials.length && (
        <section className="relative overflow-hidden bg-navy-950 py-24">
          <div className="pattern-bg absolute inset-0 opacity-20" />
          <div className="container-x relative">
            <SectionTitle title={t('home.testimonials')} center light />
            <div className="grid gap-6 md:grid-cols-2 lg:grid-cols-3">
              {home.testimonials.slice(0, 3).map((tm, i) => (
                <figure key={i} className="glass-dark flex flex-col rounded-3xl p-7">
                  <Quote className="size-8 text-gold-400" />
                  <blockquote className="mt-4 flex-1 leading-relaxed text-white/85">{tm.quote}</blockquote>
                  <div className="mt-4 flex gap-0.5 text-gold-400">{Array.from({ length: Math.round(tm.rating) }, (_, s) => <Star key={s} className="size-4 fill-current" />)}</div>
                  <figcaption className="mt-5 flex items-center gap-3 border-t border-white/10 pt-5">
                    <Avatar name={tm.name} size={44} />
                    <div>
                      <div className="font-bold">{tm.name}</div>
                      <div className="text-xs text-white/60">{[tm.role, tm.school].filter(Boolean).join(' — ')}</div>
                      <div className="text-xs text-gold-300">{tm.program}</div>
                    </div>
                  </figcaption>
                </figure>
              ))}
            </div>
          </div>
        </section>
      )}

      {/* News */}
      {!!home?.news.length && (
        <section className="py-24">
          <div className="container-x">
            <SectionTitle title={t('home.news')} />
            <div className="grid gap-6 md:grid-cols-3">
              {home.news.map((n) => <NewsCard key={n.id} item={n} />)}
            </div>
          </div>
        </section>
      )}

      {/* CTA */}
      <section className="pb-24">
        <div className="container-x">
          <div className={`relative overflow-hidden rounded-[2rem] p-10 shadow-glass sm:p-14 ${ctaStyle === 'accent' ? 'btn-accent !shadow-gold' : 'bg-gradient-to-l from-navy-900 via-navy-800 to-navy-700 text-white'}`}>
            {ctaStyle === 'image' && <><img src={theme.banners.page_banner_image!} alt="" className="absolute inset-0 h-full w-full object-cover" /><div className="hero-overlay absolute inset-0" /></>}
            <div className="pattern-bg absolute inset-0 opacity-25" />
            <div className="absolute -end-16 -top-16 size-72 rounded-full bg-gold-500/25 blur-3xl" />
            <div className="relative flex flex-col items-start justify-between gap-6 lg:flex-row lg:items-center">
              <div className="max-w-2xl">
                <h2 className="text-3xl font-bold sm:text-4xl">{t('home.ctaTitle')}</h2>
                <p className={`mt-3 ${ctaStyle === 'accent' ? 'opacity-80' : 'text-white/75'}`}>{t('home.ctaText')}</p>
              </div>
              <Button to="/login" variant={ctaStyle === 'accent' ? 'primary' : 'gold'} size="lg">{t('home.ctaButton')}</Button>
            </div>
          </div>
        </div>
      </section>
    </>
  )
}

export function NewsCard({ item }: { item: { id: string; title: string; excerpt: string; cover_url?: string | null; published_at: string; type: string } }) {
  const { t } = useTranslation()
  return (
    <Link to={`/news/${item.id}`} className="card group overflow-hidden transition hover:-translate-y-1 hover:shadow-glass">
      <div className="relative h-48 overflow-hidden bg-gradient-to-br from-navy-800 to-navy-600">
        <div className="pattern-bg absolute inset-0 opacity-40" />
        {item.cover_url && <img src={item.cover_url} alt="" loading="lazy" onError={(e) => (e.currentTarget.style.display = 'none')} className="absolute inset-0 h-full w-full object-cover transition duration-700 group-hover:scale-105" />}
        <span className="absolute start-4 top-4 rounded-full bg-gold-500 px-3 py-1 text-xs font-bold text-navy-950">{t(`admin.communication.types.${item.type}`)}</span>
      </div>
      <div className="p-5">
        <time className="text-xs text-slate-400">{fmt.date(item.published_at)}</time>
        <h3 className="mt-2 font-bold leading-snug text-navy-900 group-hover:text-link">{item.title}</h3>
        <p className="mt-2 line-clamp-2 text-sm text-slate-500">{item.excerpt}</p>
      </div>
    </Link>
  )
}
