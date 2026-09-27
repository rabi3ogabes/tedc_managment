import { Compass, Eye, Gem } from 'lucide-react'
import { useTranslation } from 'react-i18next'
import { PageHero, SectionTitle } from '@/components/public/Section'

export default function About() {
  const { t } = useTranslation()
  const values = t('about.values', { returnObjects: true }) as string[]
  const lifecycle = t('about.lifecycle', { returnObjects: true }) as string[]

  return (
    <>
      <PageHero title={t('about.title')} subtitle={t('about.subtitle')} />
      <section className="py-20">
        <div className="container-x grid gap-6 md:grid-cols-3">
          {[
            { icon: Eye, title: t('about.visionTitle'), text: t('about.vision') },
            { icon: Compass, title: t('about.missionTitle'), text: t('about.mission') },
            { icon: Gem, title: t('about.valuesTitle'), text: values.join(' · ') },
          ].map(({ icon: Icon, title, text }) => (
            <div key={title} className="card p-8">
              <div className="grid size-14 place-items-center rounded-2xl bg-navy-900 text-gold-300"><Icon className="size-7" /></div>
              <h3 className="mt-5 text-xl font-bold text-navy-900">{title}</h3>
              <p className="mt-3 leading-relaxed text-slate-600">{text}</p>
            </div>
          ))}
        </div>
      </section>
      <section className="bg-navy-950 py-20 text-white">
        <div className="container-x">
          <SectionTitle title={t('about.lifecycleTitle')} center light />
          <ol className="grid grid-cols-2 gap-4 sm:grid-cols-5">
            {lifecycle.map((step, i) => (
              <li key={step} className="glass-dark relative rounded-2xl p-5 text-center">
                <div className="mx-auto grid size-10 place-items-center rounded-full bg-gold-500 font-display font-bold text-navy-950">{i + 1}</div>
                <div className="mt-3 text-sm font-semibold">{step}</div>
              </li>
            ))}
          </ol>
        </div>
      </section>
    </>
  )
}
