import clsx from 'clsx'
import { BookOpen, GraduationCap, Home, Medal, User, type LucideIcon } from 'lucide-react'
import { useTranslation } from 'react-i18next'
import type { NavStyle, Theme } from '@/lib/theme'

const STYLES: NavStyle[] = ['classic', 'floating', 'center_fab', 'neumorphism', 'glass', 'outline']
const TABS: LucideIcon[] = [Home, BookOpen, GraduationCap, Medal, User]

/** A small phone footer drawn in each style, using the draft's own colours so the choice is judged in the brand. */
function Mini({ style, theme }: { style: NavStyle; theme: Theme }) {
  const { primary, accent, background } = theme.colors
  const active = 0
  const wrap = 'relative flex h-full items-end'
  const bar = { classic: 'h-9 w-full border-t bg-white', floating: 'mx-2 mb-2 h-9 w-full rounded-2xl bg-white shadow-lg', center_fab: 'h-9 w-full border-t bg-white', neumorphism: 'mb-1 h-9 w-full', glass: 'mx-2 mb-2 h-9 w-full rounded-2xl border border-white/80 bg-white/50 backdrop-blur', outline: 'h-9 w-full border-t bg-white' }[style]
  const soft = '4px 4px 8px rgba(0,0,0,.13), -4px -4px 8px rgba(255,255,255,.9)'
  return (
    <div className="h-24 overflow-hidden rounded-xl border border-navy-100 px-0" style={{ background: style === 'neumorphism' ? background : `linear-gradient(${background}, ${primary}22)` }} aria-hidden>
      <div className={wrap}>
        <div className={clsx(bar, 'flex items-center justify-around')} style={{ borderColor: `${primary}55` }}>
          {TABS.map((Icon, i) => {
            const on = i === active
            const mid = style === 'center_fab' && i === 2
            if (mid) return <span key={i} className="-mt-6 grid size-9 place-items-center rounded-full border-2 border-white text-white shadow-md" style={{ background: primary }}><Icon className="size-4" /></span>
            return (
              <span key={i} className={clsx('grid size-7 place-items-center', style === 'floating' && on && 'rounded-xl', style === 'glass' && on && 'rounded-xl', style === 'outline' && 'rounded-lg', style === 'neumorphism' && 'rounded-lg')}
                style={{
                  color: on ? (style === 'floating' ? accent : primary) : '#94a3b8',
                  background: style === 'floating' && on ? primary : style === 'glass' && on ? `${primary}22` : undefined,
                  boxShadow: style === 'neumorphism' && !on ? soft : undefined,
                  outline: style === 'outline' && on ? `1.5px solid ${primary}` : undefined,
                }}>
                <Icon className="size-4" strokeWidth={style === 'outline' ? 1.4 : 2} />
              </span>
            )
          })}
        </div>
      </div>
    </div>
  )
}

/** Brand Studio → Navigation: the look of the mobile app's bottom bar, which appears on every screen of the app. */
export default function NavigationPanel({ draft, onChange }: { draft: Theme; onChange: (style: NavStyle) => void }) {
  const { t } = useTranslation()
  const current = draft.navigation.style
  return (
    <div className="space-y-5">
      <p className="text-sm text-slate-500">{t('apa.navHint')}</p>
      <div role="radiogroup" aria-label={t('apa.navigation')} className="grid gap-3 sm:grid-cols-2">
        {STYLES.map((s) => (
          <button key={s} type="button" role="radio" aria-checked={current === s} onClick={() => onChange(s)}
            className={clsx('rounded-2xl border p-3 text-start transition', current === s ? 'border-gold-400 bg-gold-100/40 shadow-glass ring-2 ring-gold-300' : 'border-navy-100 bg-white hover:border-gold-300')}>
            <Mini style={s} theme={draft} />
            <div className="mt-2 flex items-center justify-between gap-2">
              <span className="font-bold text-navy-900">{t(`apa.navStyles.${s}`)}</span>
              {s === 'classic' && <span className="rounded-full bg-navy-100 px-2 py-0.5 text-[11px] font-bold text-navy-800">{t('apa.navDefault')}</span>}
            </div>
            <p className="mt-0.5 text-xs text-slate-500">{t(`apa.navHints.${s}`)}</p>
          </button>
        ))}
      </div>
      <p className="text-xs text-slate-400">{t('apa.navNote')}</p>
    </div>
  )
}
