import clsx from 'clsx'
import type { ReactNode } from 'react'
import { useTheme } from '@/lib/ThemeProvider'

export function SectionTitle({ eyebrow, title, text, center, light }: { eyebrow?: string; title: string; text?: string; center?: boolean; light?: boolean }) {
  return (
    <div className={clsx('mb-10 max-w-2xl', center && 'mx-auto text-center')}>
      {eyebrow && <span className={clsx('inline-flex items-center gap-2 text-sm font-bold tracking-wide', light ? 'text-gold-300' : 'text-gold-700')}><span aria-hidden className="size-1.5 rotate-45 bg-current" />{eyebrow}</span>}
      <h2 className={clsx('mt-2 text-3xl font-bold sm:text-4xl', light ? 'text-white' : 'text-navy-900')}>{title}</h2>
      <div className={clsx('mt-4 flex items-center gap-2', center && 'justify-center')} aria-hidden><div className="gold-line" /><span className="size-1.5 rotate-45 bg-gold-500" /></div>
      {text && <p className={clsx('mt-4 leading-relaxed', light ? 'text-white/70' : 'text-slate-500')}>{text}</p>}
    </div>
  )
}

export function PageHero({ title, subtitle, children }: { title: string; subtitle?: string; children?: ReactNode }) {
  const { active } = useTheme()
  const banner = active.banners.page_banner_image
  return (
    <section className="relative overflow-hidden bg-navy-950 pb-16 pt-36 text-white">
      {banner && <img src={banner} alt="" className="absolute inset-0 h-full w-full object-cover" />}
      {banner && <div className="hero-overlay absolute inset-0" />}
      <div className="pattern-bg absolute inset-0 opacity-25" />
      <div className="absolute -top-24 end-0 size-96 rounded-full bg-gold-500/15 blur-3xl" />
      <div className="absolute -bottom-32 start-10 size-80 rounded-full bg-navy-600/40 blur-3xl" />
      <div className="container-x relative animate-fade-up">
        <h1 className="text-4xl font-bold sm:text-5xl">{title}</h1>
        <div className="gold-line mt-5" />
        {subtitle && <p className="mt-5 max-w-2xl text-lg text-white/70">{subtitle}</p>}
        {children}
      </div>
    </section>
  )
}
