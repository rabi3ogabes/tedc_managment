import clsx from 'clsx'
import { ArrowUp, Globe, LayoutDashboard, LogIn, Mail, MapPin, Menu, Phone, Smartphone, X } from 'lucide-react'
import { useEffect, useState } from 'react'
import { useTranslation } from 'react-i18next'
import { Link, NavLink, Outlet, useLocation } from 'react-router-dom'
import { Button } from '@/components/ui'
import { homeFor, useAuth } from '@/lib/auth'
import ChatWidget from './ChatWidget'
import Logo from './Logo'
import { useCenterName } from '@/lib/ThemeProvider'

const links = [
  { to: '/', key: 'home' },
  { to: '/about', key: 'about' },
  { to: '/programs', key: 'programs' },
  { to: '/trainers', key: 'trainers' },
  { to: '/calendar', key: 'calendar' },
  { to: '/news', key: 'news' },
  { to: '/verify', key: 'verify' },
  { to: '/contact', key: 'contact' },
]

export function LanguageToggle({ light }: { light?: boolean }) {
  const { t, i18n } = useTranslation()
  return (
    <button
      onClick={() => i18n.changeLanguage(i18n.language === 'ar' ? 'en' : 'ar')}
      className={clsx('inline-flex items-center gap-1.5 rounded-xl px-3 py-2 text-sm font-semibold transition', light ? 'text-white/90 hover:bg-white/10' : 'text-navy-800 hover:bg-navy-100/60')}
    >
      <Globe className="size-4" />
      {t('nav.language')}
    </button>
  )
}

export default function PublicLayout() {
  const { t } = useTranslation()
  const centerName = useCenterName()
  const { user } = useAuth()
  const { pathname } = useLocation()
  const [scrolled, setScrolled] = useState(false)
  const [progress, setProgress] = useState(0)
  const [top, setTop] = useState(false)
  const [open, setOpen] = useState(false)
  const overHero = pathname === '/' && !scrolled

  useEffect(() => {
    const onScroll = () => {
      setScrolled(window.scrollY > 40)
      const max = document.documentElement.scrollHeight - window.innerHeight
      setProgress(max > 0 ? Math.min(1, window.scrollY / max) : 0)
      setTop(window.scrollY > 700)
    }
    onScroll()
    window.addEventListener('scroll', onScroll, { passive: true })
    return () => window.removeEventListener('scroll', onScroll)
  }, [])

  useEffect(() => {
    setOpen(false)
    window.scrollTo({ top: 0 })
  }, [pathname])

  return (
    <div className="flex min-h-screen flex-col">
      <header className={clsx('fixed inset-x-0 top-0 z-40 transition-all duration-300', overHero ? 'bg-transparent' : 'glass border-x-0 border-t-0')}>
        <div className="container-x flex h-20 items-center justify-between gap-4">
          <Logo light={overHero} compact />
          <nav className="hidden items-center gap-0.5 xl:flex">
            {links.map((l) => (
              <NavLink
                key={l.to}
                to={l.to}
                end={l.to === '/'}
                className={({ isActive }) =>
                  clsx(
                    'relative whitespace-nowrap rounded-lg px-2.5 py-2 text-[13px] font-semibold transition 2xl:px-3 2xl:text-sm',
                    overHero ? 'text-white/85 hover:text-white' : 'text-navy-800 hover:text-navy-950',
                    isActive && 'after:absolute after:inset-x-3 after:-bottom-0.5 after:h-0.5 after:rounded-full after:bg-gold-500',
                  )
                }
              >
                {t(`nav.${l.key}`)}
              </NavLink>
            ))}
          </nav>
          <div className="flex items-center gap-2">
            <div className="hidden sm:block"><LanguageToggle light={overHero} /></div>
            {user ? (
              <Button to={homeFor(user)} variant={overHero ? 'gold' : 'primary'} size="sm" icon={<LayoutDashboard className="size-4" />}>
                {homeFor(user) === '/admin' ? t('nav.dashboard') : t('nav.portal')}
              </Button>
            ) : (
              <Button to="/login" variant={overHero ? 'gold' : 'primary'} size="sm" icon={<LogIn className="size-4" />}>{t('nav.login')}</Button>
            )}
            <button className={clsx('rounded-lg p-2 xl:hidden', overHero ? 'text-white' : 'text-navy-900')} onClick={() => setOpen((o) => !o)} aria-label="menu">
              {open ? <X className="size-6" /> : <Menu className="size-6" />}
            </button>
          </div>
        </div>
        <div aria-hidden className={clsx('absolute inset-x-0 bottom-0 h-[2px] origin-left bg-gradient-to-l from-gold-300 via-gold-500 to-gold-600 transition-opacity rtl:origin-right', scrolled ? 'opacity-100' : 'opacity-0')} style={{ transform: `scaleX(${progress})` }} />
        {open && (
          <div className="glass mx-4 mb-4 rounded-2xl p-3 xl:hidden">
            {links.map((l) => (
              <NavLink key={l.to} to={l.to} end={l.to === '/'} className={({ isActive }) => clsx('block rounded-xl px-4 py-3 text-sm font-semibold', isActive ? 'bg-navy-900 text-white' : 'text-navy-800')}>
                {t(`nav.${l.key}`)}
              </NavLink>
            ))}
            <div className="mt-2 border-t border-navy-100 pt-2"><LanguageToggle /></div>
          </div>
        )}
      </header>

      <main className="flex-1">
        <Outlet />
      </main>

      <ChatWidget />
      <button type="button" onClick={() => window.scrollTo({ top: 0, behavior: 'smooth' })} aria-label={t('common.backToTop')} className={clsx('fixed bottom-5 start-5 z-40 grid size-11 place-items-center rounded-full border border-gold-300 bg-white/90 text-navy-900 shadow-glass backdrop-blur transition duration-300 hover:bg-gold-500 hover:text-navy-950', top ? 'translate-y-0 opacity-100' : 'pointer-events-none translate-y-4 opacity-0')}><ArrowUp className="size-5" /></button>

      <footer className="relative overflow-hidden bg-navy-950 text-white">
        <div className="absolute inset-x-0 top-0 h-px bg-gradient-to-l from-transparent via-gold-400 to-transparent" aria-hidden />
        <div className="pattern-bg absolute inset-0 opacity-20" />
        <div className="container-x relative grid gap-10 py-16 md:grid-cols-2 lg:grid-cols-4">
          <div className="lg:col-span-1">
            <Logo light />
            <p className="mt-5 text-sm leading-relaxed text-white/65">{t('footer.about')}</p>
          </div>
          <div>
            <h4 className="font-display text-lg font-bold text-gold-300">{t('footer.quickLinks')}</h4>
            <ul className="mt-4 grid grid-cols-2 gap-2 text-sm text-white/70">
              {links.slice(1).map((l) => <li key={l.to}><Link to={l.to} className="hover:text-gold-300">{t(`nav.${l.key}`)}</Link></li>)}
            </ul>
          </div>
          <div>
            <h4 className="font-display text-lg font-bold text-gold-300">{t('footer.contact')}</h4>
            <ul className="mt-4 space-y-3 text-sm text-white/70">
              <li className="flex items-center gap-2"><MapPin className="size-4 text-gold-400" />{t('contact.address')}</li>
              <li className="flex items-center gap-2" dir="ltr"><Phone className="size-4 text-gold-400" />+974 4000 0000</li>
              <li className="flex items-center gap-2"><Mail className="size-4 text-gold-400" />info@tedc.edu.qa</li>
            </ul>
          </div>
          <div>
            <h4 className="font-display text-lg font-bold text-gold-300">{t('footer.apps')}</h4>
            <p className="mt-4 text-sm text-white/70">{t('footer.appsText')}</p>
            <div className="mt-4 flex gap-2">
              <span className="glass-dark inline-flex items-center gap-2 rounded-xl px-3 py-2 text-xs"><Smartphone className="size-4 text-gold-300" />App Store</span>
              <span className="glass-dark inline-flex items-center gap-2 rounded-xl px-3 py-2 text-xs"><Smartphone className="size-4 text-gold-300" />Google Play</span>
            </div>
          </div>
        </div>
        <div className="relative border-t border-white/10">
          <div className="container-x flex flex-col items-center justify-between gap-2 py-5 text-xs text-white/50 sm:flex-row">
            <span>© {new Date().getFullYear()} {centerName} — {t('footer.rights')}</span>
            <span>{t('contact.hours')}</span>
          </div>
        </div>
      </footer>
    </div>
  )
}
