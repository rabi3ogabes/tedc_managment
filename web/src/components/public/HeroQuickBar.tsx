import { BadgeCheck, CalendarDays, MessageCircle, Search } from 'lucide-react'
import { useTranslation } from 'react-i18next'
import { Link } from 'react-router-dom'

/** Four smart shortcuts that overlap the hero: the things visitors most often come for. */
export default function HeroQuickBar() {
  const { t } = useTranslation()
  const items = [
    { icon: Search, to: '/programs', key: 'programs' },
    { icon: CalendarDays, to: '/calendar', key: 'calendar' },
    { icon: BadgeCheck, to: '/verify', key: 'verify' },
    { icon: MessageCircle, to: null, key: 'chat' },
  ] as const
  const cls = 'group glass flex items-center gap-4 rounded-2xl p-4 text-start transition duration-300 hover:-translate-y-1 hover:border-gold-400 hover:bg-white/90'
  const body = (Icon: (typeof items)[number]['icon'], key: string) => (
    <>
      <span className="grid size-12 shrink-0 place-items-center rounded-xl bg-navy-900 text-gold-300 transition group-hover:bg-gold-500 group-hover:text-navy-950"><Icon className="size-6" /></span>
      <span className="min-w-0"><span className="block font-bold text-navy-900">{t(`home.quick.${key}.title`)}</span><span className="block truncate text-xs text-slate-500">{t(`home.quick.${key}.text`)}</span></span>
    </>
  )
  return (
    <div className="container-x relative z-20 -mt-14">
      <div className="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
        {items.map(({ icon, to, key }) => to
          ? <Link key={key} to={to} className={cls}>{body(icon, key)}</Link>
          : <button key={key} type="button" onClick={() => window.dispatchEvent(new Event('tedc:chat-open'))} className={cls}>{body(icon, key)}</button>)}
      </div>
    </div>
  )
}
