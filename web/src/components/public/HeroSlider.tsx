import clsx from 'clsx'
import { ChevronLeft, ChevronRight, ShieldCheck, Sparkles } from 'lucide-react'
import { useCallback, useEffect, useState, type ReactNode } from 'react'
import { useTranslation } from 'react-i18next'
import { Button } from '@/components/ui'
import { DohaSkyline, HeritageDesert, IslamicArtMuseum, MinistryOfEducation } from './QatarArt'

/**
 * Premium hero slider of Qatar.
 * Each slide shows an optional photograph from /public/images/hero/ over an illustrated
 * fallback scene; drop the official photos in that folder with the file names below
 * to replace the artwork (see web/public/images/hero/README.md).
 */
const SLIDES: { photo: string; art: ReactNode; credit?: string }[] = [
  { photo: '/images/hero/doha-skyline.jpg', art: <DohaSkyline /> },
  { photo: '/images/hero/ministry-of-education.jpg', art: <MinistryOfEducation /> },
  { photo: '/images/hero/museum-of-islamic-art.jpg', art: <IslamicArtMuseum /> },
  { photo: '/images/hero/qatar-heritage.jpg', art: <HeritageDesert /> },
]

function SlidePhoto({ src }: { src: string }) {
  const [ok, setOk] = useState(true)
  if (!ok) return null
  return <img src={src} alt="" onError={() => setOk(false)} className="absolute inset-0 h-full w-full object-cover" />
}

export default function HeroSlider() {
  const { t, i18n } = useTranslation()
  const slides = t('home.slides', { returnObjects: true }) as { eyebrow: string; title: string; text: string }[]
  const [index, setIndex] = useState(0)
  const [paused, setPaused] = useState(false)
  const rtl = i18n.language === 'ar'

  const go = useCallback((dir: 1 | -1) => setIndex((i) => (i + dir + SLIDES.length) % SLIDES.length), [])

  useEffect(() => {
    if (paused) return
    const id = window.setInterval(() => go(1), 7000)
    return () => window.clearInterval(id)
  }, [go, paused, index])

  const Prev = rtl ? ChevronRight : ChevronLeft
  const Next = rtl ? ChevronLeft : ChevronRight

  return (
    <section className="relative h-[92vh] min-h-[620px] overflow-hidden bg-navy-950" onMouseEnter={() => setPaused(true)} onMouseLeave={() => setPaused(false)} aria-roledescription="carousel">
      {SLIDES.map((slide, i) => (
        <div key={slide.photo} className={clsx('absolute inset-0 transition-opacity duration-[1400ms]', i === index ? 'opacity-100' : 'opacity-0')} aria-hidden={i !== index}>
          <div className={clsx('absolute inset-0', i === index && 'animate-ken-burns')}>
            {slide.art}
            <SlidePhoto src={slide.photo} />
          </div>
        </div>
      ))}

      {/* Legibility overlays */}
      <div className="absolute inset-0 bg-gradient-to-t from-navy-950 via-navy-950/40 to-navy-950/10" />
      <div className={clsx('absolute inset-0 bg-gradient-to-l from-transparent to-navy-950/70', rtl && 'bg-gradient-to-r')} />
      <div className="pattern-bg absolute inset-0 opacity-30" />

      <div className="container-x relative flex h-full flex-col justify-end pb-28 sm:pb-32">
        <div key={index} className="max-w-3xl animate-fade-up">
          <span className="glass-dark inline-flex items-center gap-2 rounded-full px-4 py-1.5 text-xs font-semibold text-gold-300 sm:text-sm">
            <Sparkles className="size-4" />
            {slides[index]?.eyebrow}
          </span>
          <h1 className="mt-5 text-4xl font-bold leading-tight text-white sm:text-5xl lg:text-6xl">{slides[index]?.title}</h1>
          <div className="gold-line mt-6" />
          <p className="mt-6 max-w-2xl text-base leading-relaxed text-white/80 sm:text-lg">{slides[index]?.text}</p>
          <div className="mt-8 flex flex-wrap gap-3">
            <Button to="/programs" variant="gold" size="lg">{t('home.exploreCta')}</Button>
            <Button to="/verify" variant="light" size="lg" icon={<ShieldCheck className="size-5" />}>{t('home.verifyCta')}</Button>
          </div>
        </div>
      </div>

      {/* Controls */}
      <div className="absolute inset-x-0 bottom-8">
        <div className="container-x flex items-center justify-between">
          <div className="flex items-center gap-2">
            {SLIDES.map((_, i) => (
              <button key={i} onClick={() => setIndex(i)} aria-label={`slide ${i + 1}`} className={clsx('h-1.5 rounded-full transition-all duration-500', i === index ? 'w-10 bg-gold-400' : 'w-4 bg-white/40 hover:bg-white/70')} />
            ))}
          </div>
          <div className="flex gap-2">
            <button onClick={() => go(-1)} className="glass-dark grid size-11 place-items-center rounded-full hover:bg-white/20" aria-label="previous"><Prev className="size-5" /></button>
            <button onClick={() => go(1)} className="glass-dark grid size-11 place-items-center rounded-full hover:bg-white/20" aria-label="next"><Next className="size-5" /></button>
          </div>
        </div>
      </div>
    </section>
  )
}
