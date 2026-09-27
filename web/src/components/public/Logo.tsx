import clsx from 'clsx'
import { useState } from 'react'
import { useTranslation } from 'react-i18next'
import { Link } from 'react-router-dom'
import { useTheme } from '@/lib/ThemeProvider'
import { logoFor } from '@/lib/theme'

/** Built-in mark, drawn in the active theme colours (fallback when no official logo is configured). */
export function LogoMark({ className }: { className?: string }) {
  return (
    <svg viewBox="0 0 64 64" className={clsx('shrink-0', className)} aria-hidden>
      <rect width="64" height="64" rx="16" fill="var(--color-navy-900)" />
      <path d="M32 11l19 8.5v4H13v-4z" fill="var(--color-gold-500)" />
      <rect x="17.5" y="27" width="5" height="17" rx="1" fill="var(--color-gold-300)" />
      <rect x="29.5" y="27" width="5" height="17" rx="1" fill="var(--color-gold-300)" />
      <rect x="41.5" y="27" width="5" height="17" rx="1" fill="var(--color-gold-300)" />
      <rect x="13" y="46.5" width="38" height="5.5" rx="1.5" fill="var(--color-gold-500)" />
    </svg>
  )
}

/**
 * Official logo (e.g. Ministry of Education and Higher Education) for the current language,
 * with an automatic light variant on dark backgrounds. Returns null when no logo file exists.
 */
export function OfficialLogo({ onDark = false, className, onMissing }: { onDark?: boolean; className?: string; onMissing?: () => void }) {
  const { i18n } = useTranslation()
  const { active } = useTheme()
  const { src, invert } = logoFor(active, i18n.language, onDark)
  const [failed, setFailed] = useState<string | null>(null)
  const [fallbackFailed, setFallbackFailed] = useState<string | null>(null)

  // A missing light variant falls back to the regular logo rendered in white.
  const regular = logoFor(active, i18n.language, false).src
  const useFallback = onDark && failed === src
  const current = useFallback ? regular : src
  if ((useFallback && fallbackFailed === regular) || (!onDark && failed === src)) return null

  return (
    <img
      src={current}
      alt={i18n.language === 'ar' ? 'وزارة التربية والتعليم والتعليم العالي' : 'Ministry of Education and Higher Education'}
      onError={() => {
        if (useFallback) {
          setFallbackFailed(current)
          onMissing?.()
        } else {
          setFailed(src)
          if (!onDark) onMissing?.()
        }
      }}
      className={clsx('w-auto object-contain', (invert || useFallback) && 'brightness-0 invert', className)}
    />
  )
}

export default function Logo({ light = false, compact = false }: { light?: boolean; compact?: boolean }) {
  const { t } = useTranslation()
  const { active } = useTheme()
  const [hasOfficial, setHasOfficial] = useState(true)

  const name = (
    <div className={clsx('leading-tight', compact && 'hidden sm:block xl:hidden 2xl:block')}>
      <div className={clsx('whitespace-nowrap font-display text-[15px] font-bold sm:text-base', light ? 'text-white' : 'text-navy-900')}>{t('brand.name')}</div>
      <div className={clsx('whitespace-nowrap text-[11px] tracking-wide', light ? 'text-gold-300' : 'text-gold-700')}>{t('brand.tagline')}</div>
    </div>
  )

  return (
    <Link to="/" className="flex items-center gap-3">
      {hasOfficial ? (
        <OfficialLogo onDark={light} className="h-11 max-w-[180px]" onMissing={() => setHasOfficial(false)} />
      ) : (
        <LogoMark className="size-11 drop-shadow" />
      )}
      {active.identity.show_center_name && (
        <>
          {hasOfficial && <span className={clsx('hidden h-9 w-px sm:block', light ? 'bg-white/25' : 'bg-navy-100', compact && 'xl:hidden 2xl:block')} />}
          {name}
        </>
      )}
    </Link>
  )
}

/** Official logo when available, otherwise the built-in mark. */
export function BrandMark({ onDark = false, className = 'h-11 max-w-[170px]', markClassName = 'size-11' }: { onDark?: boolean; className?: string; markClassName?: string }) {
  const { active } = useTheme()
  const [missing, setMissing] = useState<string | null>(null)
  const key = JSON.stringify(active.identity)
  return missing === key ? <LogoMark className={markClassName} /> : <OfficialLogo key={key} onDark={onDark} className={className} onMissing={() => setMissing(key)} />
}
