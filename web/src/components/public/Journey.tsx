import { Award, ClipboardCheck, QrCode, Search } from 'lucide-react'
import { useTranslation } from 'react-i18next'
import { SectionTitle } from './Section'
import Reveal from './Reveal'

const ICONS = [Search, ClipboardCheck, QrCode, Award]

/** The trainee's path in four steps: find, register, attend, get certified. */
export default function Journey() {
  const { t } = useTranslation()
  const steps = t('home.journey.steps', { returnObjects: true }) as { title: string; text: string }[]
  return (
    <section className="relative overflow-hidden bg-ivory-dark/50 py-24">
      <div className="pattern-bg absolute inset-0 opacity-10" />
      <div className="container-x relative">
        <SectionTitle title={t('home.journey.title')} text={t('home.journey.text')} center />
        <ol className="relative grid gap-10 md:grid-cols-4">
          <div className="absolute inset-x-[12%] top-8 hidden h-px bg-gradient-to-l from-transparent via-gold-400 to-transparent md:block" aria-hidden />
          {steps.map((s, i) => {
            const Icon = ICONS[i]
            return (
              <Reveal as="li" key={s.title} delay={i * 110} className="relative text-center">
                <div className="relative mx-auto grid size-16 place-items-center rounded-2xl bg-navy-900 text-gold-300 shadow-gold ring-4 ring-ivory transition hover:-translate-y-1 hover:bg-gold-500 hover:text-navy-950">
                  <Icon className="size-7" />
                  <span className="absolute -end-2 -top-2 grid size-7 place-items-center rounded-full bg-gold-500 font-display text-sm font-bold text-navy-950 ring-2 ring-ivory">{i + 1}</span>
                </div>
                <h3 className="mt-5 text-lg font-bold text-navy-900">{s.title}</h3>
                <p className="mx-auto mt-2 max-w-[16rem] text-sm leading-relaxed text-slate-500">{s.text}</p>
              </Reveal>
            )
          })}
        </ol>
      </div>
    </section>
  )
}
