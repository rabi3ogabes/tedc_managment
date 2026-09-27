import clsx from 'clsx'
import { useTranslation } from 'react-i18next'
import { Link } from 'react-router-dom'

export function LogoMark({ className }: { className?: string }) {
  return (
    <svg viewBox="0 0 64 64" className={clsx('shrink-0', className)} aria-hidden>
      <rect width="64" height="64" rx="16" fill="#0B1F3A" />
      <path d="M32 11l19 8.5v4H13v-4z" fill="#C8A24A" />
      <rect x="17.5" y="27" width="5" height="17" rx="1" fill="#E5CD8A" />
      <rect x="29.5" y="27" width="5" height="17" rx="1" fill="#E5CD8A" />
      <rect x="41.5" y="27" width="5" height="17" rx="1" fill="#E5CD8A" />
      <rect x="13" y="46.5" width="38" height="5.5" rx="1.5" fill="#C8A24A" />
    </svg>
  )
}

export default function Logo({ light = false, compact = false }: { light?: boolean; compact?: boolean }) {
  const { t } = useTranslation()
  return (
    <Link to="/" className="flex items-center gap-3">
      <LogoMark className="size-11 drop-shadow" />
      <div className={clsx('leading-tight', compact && 'hidden sm:block xl:hidden 2xl:block')}>
        <div className={clsx('whitespace-nowrap font-display text-[15px] font-bold sm:text-base', light ? 'text-white' : 'text-navy-900')}>{t('brand.name')}</div>
        <div className={clsx('whitespace-nowrap text-[11px] tracking-wide', light ? 'text-gold-300' : 'text-gold-700')}>{t('brand.tagline')}</div>
      </div>
    </Link>
  )
}
