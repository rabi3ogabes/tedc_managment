import clsx from 'clsx'
import type { CSSProperties } from 'react'
import { useTranslation } from 'react-i18next'
import { useTheme } from '@/lib/ThemeProvider'
import type { Theme } from '@/lib/theme'

const lum = (hex: string) => {
  const n = parseInt(hex.replace('#', '').slice(0, 6), 16)
  return (0.299 * (n >> 16) + 0.587 * ((n >> 8) & 255) + 0.114 * (n & 255)) / 255
}

/** The loading page chosen in the Brand Studio (style, message, colours): full screen while the application opens, inline while a page loads. Also used as the live preview there. */
export default function LoadingScreen({ inline = false, theme, lang }: { inline?: boolean; theme?: Theme; lang?: 'ar' | 'en' }) {
  const ctx = useTheme()
  const { i18n } = useTranslation()
  const th = theme ?? ctx.active
  const l = th.loading
  const language = lang ?? (i18n.language === 'en' ? 'en' : 'ar')
  const bg = l.background ?? th.colors.background
  const accent = l.accent ?? th.colors.primary
  const message = (language === 'en' ? l.message_en : l.message_ar) || ''
  const style = { '--ls-bg': bg, '--ls-accent': accent, '--ls-ink': lum(bg) < 0.5 ? '#ffffff' : accent } as CSSProperties
  return (
    <div className={clsx('splash', inline && 'inline')} style={style} role="status" aria-label={message}>
      <div className="ls-box">
        {l.style === 'crescent' ? (
          <div className="ls-visual ls-crescent">
            <svg viewBox="0 0 120 120" aria-hidden="true"><path d="M82 18a44 44 0 1 0 0 84 36 36 0 1 1 0-84z" fill={accent} /><path className="star" d="M92 30l3.5 8 8.5.8-6.4 5.6 1.9 8.4-7.5-4.4-7.5 4.4 1.9-8.4-6.4-5.6 8.5-.8z" fill={accent} /></svg>
          </div>
        ) : (
          <div className={clsx('ls-visual', `ls-${l.style}`)}><div className="ring"><img src="/brand/emblem-splash.webp" alt="" width={72} height={67} /></div></div>
        )}
        {l.style === 'bar' && <div className="ls-bar-track" />}
        {l.style === 'dots' && <div className="ls-dots"><i /><i /><i /></div>}
        {message && <p className="ls-message">{message}</p>}
        {l.show_name && <div className="ls-name">{language === 'en' ? th.identity.name_en : th.identity.name_ar}</div>}
      </div>
    </div>
  )
}
