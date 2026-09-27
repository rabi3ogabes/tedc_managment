import clsx from 'clsx'
import { Clock, Monitor, Smartphone } from 'lucide-react'
import { useState } from 'react'
import { useTranslation } from 'react-i18next'
import { LogoMark } from '@/components/public/Logo'
import { DohaSkyline } from '@/components/public/QatarArt'
import { patternImage, type Theme } from '@/lib/theme'

/**
 * Miniature of the website rendered with the draft theme. Uses the same CSS variables as the
 * real pages, so what you see here is exactly what visitors will see.
 */
export default function BrandPreview({ theme }: { theme: Theme }) {
  const { t } = useTranslation()
  const [device, setDevice] = useState<'desktop' | 'mobile'>('desktop')
  const hero = theme.banners.hero_images[0]

  return (
    <div className="overflow-hidden rounded-3xl border border-navy-100 bg-white shadow-glass">
      <div className="flex items-center justify-between border-b border-navy-100 px-4 py-3">
        <div className="flex gap-1.5"><span className="size-3 rounded-full bg-red-400/80" /><span className="size-3 rounded-full bg-amber-400/80" /><span className="size-3 rounded-full bg-emerald-400/80" /></div>
        <span className="text-xs font-bold text-navy-900">{t('admin.brand.preview.title')}</span>
        <div className="flex gap-1">
          {([['desktop', Monitor], ['mobile', Smartphone]] as const).map(([id, Icon]) => (
            <button key={id} onClick={() => setDevice(id)} title={t(`admin.brand.preview.${id}`)} className={clsx('rounded-lg p-1.5', device === id ? 'bg-navy-900 text-gold-300' : 'text-slate-400 hover:text-navy-900')}><Icon className="size-4" /></button>
          ))}
        </div>
      </div>

      <div className="bg-slate-100/70 p-4">
        <div className={clsx('mx-auto overflow-hidden bg-ivory shadow-lg transition-all duration-500', device === 'mobile' ? 'max-w-[300px] rounded-[2rem] ring-8 ring-navy-950' : 'max-w-full rounded-xl')}>
          {/* Hero banner */}
          <div className="relative h-52 overflow-hidden bg-navy-950 text-white">
            <div className="absolute inset-0">{hero ? <img src={hero} alt="" className="size-full object-cover" /> : <DohaSkyline />}</div>
            <div className="hero-overlay absolute inset-0" />
            <div className="absolute inset-0" style={{ backgroundImage: patternImage(theme.pattern), backgroundSize: `${theme.pattern.size}px`, opacity: 0.6 }} />
            <div className="relative flex items-center justify-between px-4 py-3">
              <div className="flex items-center gap-2"><LogoMark className="size-7" /><span className="text-[11px] font-bold">{t('brand.short')}</span></div>
              {device === 'desktop' && <div className="flex gap-3 text-[10px] text-white/80"><span>{t('nav.home')}</span><span>{t('nav.programs')}</span><span>{t('nav.calendar')}</span></div>}
            </div>
            <div className="relative px-4 pt-4">
              <div className="h-0.5 w-10 rounded-full bg-gold-500" />
              <h4 className="mt-2 text-lg font-bold leading-snug">{t('admin.brand.preview.hero')}</h4>
              <div className="mt-3 flex gap-2">
                <span className="btn btn-accent px-3 py-1.5 text-[11px] font-bold">{t('admin.brand.buttons.sampleAccent')}</span>
                <span className="btn border border-white/30 bg-white/10 px-3 py-1.5 text-[11px] font-bold">{t('home.verifyCta')}</span>
              </div>
            </div>
          </div>

          {/* Body */}
          <div className="pattern-bg space-y-3 p-4">
            <div className={clsx('grid gap-3', device === 'desktop' ? 'grid-cols-2' : 'grid-cols-1')}>
              <div className="card overflow-hidden">
                <div className="h-14 bg-gradient-to-l from-navy-900 to-navy-600" />
                <div className="p-3">
                  <div className="text-sm font-bold text-navy-900">{t('admin.brand.preview.card')}</div>
                  <p className="mt-1 text-[11px] text-ink/70">{t('admin.brand.preview.cardText')}</p>
                  <span className="mt-2 inline-block text-[11px] font-bold text-link underline-offset-2 hover:underline">{t('admin.brand.buttons.sampleLink')}</span>
                </div>
              </div>
              <div className="card flex flex-col justify-between p-3">
                <div className="flex items-center justify-between"><span className="grid size-8 place-items-center rounded-lg bg-gold-100 text-gold-700"><Clock className="size-4" /></span><span className="text-[10px] text-slate-400">KPI</span></div>
                <div className="mt-2 font-display text-2xl font-bold text-navy-900">1,280</div>
                <div className="text-[11px] text-slate-500">{t('admin.brand.preview.stat')}</div>
                <div className="mt-2 h-1.5 overflow-hidden rounded-full bg-navy-100"><div className="h-full w-3/4 rounded-full bg-gold-500" /></div>
              </div>
            </div>
            <div className="flex flex-wrap gap-2">
              <span className="btn bg-navy-900 px-3 py-1.5 text-[11px] font-bold text-white">{t('admin.brand.buttons.sample')}</span>
              <span className="btn btn-accent px-3 py-1.5 text-[11px] font-bold">{t('admin.brand.buttons.sampleAccent')}</span>
              <span className="btn border border-navy-100 bg-white px-3 py-1.5 text-[11px] font-bold text-navy-900">{t('common.cancel')}</span>
            </div>
          </div>
        </div>
      </div>
    </div>
  )
}
