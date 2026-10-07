import clsx from 'clsx'
import { useState } from 'react'
import { useTranslation } from 'react-i18next'
import LoadingScreen from '@/components/ui/LoadingScreen'
import type { LoadingStyle, Theme } from '@/lib/theme'
import { ColorField, Segmented } from './controls'

const STYLES: LoadingStyle[] = ['emblem', 'bar', 'dots', 'pulse', 'crescent']

/** The page shown while the application opens: its style, message and colours, with a live preview in both languages. */
export default function LoadingPanel({ draft, onChange }: { draft: Theme; onChange: (patch: Partial<Theme['loading']>) => void }) {
  const { t } = useTranslation()
  const [lang, setLang] = useState<'ar' | 'en'>('ar')
  const l = draft.loading
  return (
    <div className="space-y-5">
      <p className="text-sm text-slate-500">{t('apa.loadingHint')}</p>
      <div className="overflow-hidden rounded-2xl border border-navy-100" dir={lang === 'ar' ? 'rtl' : 'ltr'}>
        <div className="h-64 overflow-hidden"><LoadingScreen inline theme={draft} lang={lang} /></div>
      </div>
      <div>
        <div className="mb-2 text-sm font-semibold text-navy-800">{t('apa.previewLang')}</div>
        <Segmented value={lang} onChange={(v) => setLang(v as 'ar' | 'en')} options={[{ id: 'ar', label: 'العربية' }, { id: 'en', label: 'English' }]} />
      </div>
      <div>
        <div className="mb-2 text-sm font-semibold text-navy-800">{t('apa.style')}</div>
        <div className="grid grid-cols-2 gap-2 sm:grid-cols-3">
          {STYLES.map((s) => (
            <button key={s} type="button" aria-pressed={l.style === s} onClick={() => onChange({ style: s })}
              className={clsx('rounded-xl border px-3 py-2.5 text-start text-sm font-semibold transition', l.style === s ? 'border-transparent bg-navy-900 text-gold-300 shadow-glass' : 'border-navy-100 bg-white text-navy-900 hover:border-gold-300')}>
              {t(`apa.styles.${s}`)}
            </button>
          ))}
        </div>
      </div>
      <div className="grid gap-3 sm:grid-cols-2">
        <label className="block"><span className="mb-1 block text-xs font-semibold text-slate-500">{t('apa.messageAr')}</span><input className="input" dir="rtl" maxLength={120} value={l.message_ar} onChange={(e) => onChange({ message_ar: e.target.value })} /></label>
        <label className="block"><span className="mb-1 block text-xs font-semibold text-slate-500">{t('apa.messageEn')}</span><input className="input" dir="ltr" maxLength={120} value={l.message_en} onChange={(e) => onChange({ message_en: e.target.value })} /></label>
      </div>
      <div className="space-y-3">
        <label className="flex items-center gap-2 text-sm font-semibold text-navy-800"><input type="checkbox" checked={l.background === null} onChange={(e) => onChange({ background: e.target.checked ? null : draft.colors.background })} />{t('apa.followTheme')}</label>
        {l.background !== null && <ColorField label={t('apa.background')} value={l.background} onChange={(background) => onChange({ background })} />}
        <ColorField label={t('apa.accent')} value={l.accent ?? draft.colors.primary} onChange={(accent) => onChange({ accent })} />
        <label className="flex items-center gap-2 text-sm font-semibold text-navy-800"><input type="checkbox" checked={l.show_name} onChange={(e) => onChange({ show_name: e.target.checked })} />{t('apa.showName')}</label>
      </div>
    </div>
  )
}
