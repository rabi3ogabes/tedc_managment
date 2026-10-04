import clsx from 'clsx'
import { Mail, MessageSquareText, Smartphone } from 'lucide-react'
import { useTranslation } from 'react-i18next'
import { useGet } from '@/hooks/useApi'

export type Channel = 'push' | 'email' | 'sms'
export type ChannelStatus = Record<Channel, { on: boolean; ready: boolean }>
export const CHANNEL_ICON = { push: Smartphone, email: Mail, sms: MessageSquareText } as const

/**
 * The channels a task will use. Every channel that is on and set up is selected by default; the administrator can switch
 * one off for this task. `value` is null until a choice is made, so untouched pickers send nothing and the defaults apply.
 */
export default function ChannelPicker({ value, onChange, compact }: { value: Channel[] | null; onChange: (v: Channel[] | null) => void; compact?: boolean }) {
  const { t } = useTranslation()
  const { data } = useGet<{ data: ChannelStatus }>('/admin/notification-channels/status', undefined, { staleTime: 60_000 })
  const status = data?.data
  if (!status) return null
  const usable = (['push', 'email', 'sms'] as Channel[]).filter((c) => status[c].on && (status[c].ready || c === 'push'))
  const selected = value ?? usable
  const toggle = (c: Channel) => {
    const next = selected.includes(c) ? selected.filter((x) => x !== c) : [...selected, c]
    onChange(next.length === usable.length && usable.every((u) => next.includes(u)) ? null : next)
  }

  return (
    <div className={clsx('rounded-2xl border border-navy-100 bg-white', compact ? 'p-2.5' : 'p-3.5')} role="group" aria-label={t('channels.picker.title')}>
      {!compact && <div className="mb-2 flex items-baseline justify-between gap-2"><span className="text-xs font-bold text-navy-900">{t('channels.picker.title')}</span><span className="text-[11px] text-slate-400">{t('channels.picker.hint')}</span></div>}
      <div className="flex flex-wrap gap-2">
        {(['push', 'email', 'sms'] as Channel[]).map((c) => {
          const Icon = CHANNEL_ICON[c]
          const available = status[c].on && (status[c].ready || c === 'push')
          const active = available && selected.includes(c)
          return (
            <button key={c} type="button" disabled={!available} aria-pressed={active} onClick={() => toggle(c)} title={available ? undefined : t('channels.picker.unavailable')}
              className={clsx('inline-flex items-center gap-2 rounded-xl border px-3 py-2 text-xs font-bold transition', active ? 'border-navy-900 bg-navy-900 text-white shadow-sm' : 'border-navy-100 bg-white text-slate-500', available ? 'hover:border-gold-400' : 'cursor-not-allowed opacity-45')}>
              <Icon className="size-4" />{t(`channels.${c}`)}{!available && <span className="font-medium text-[10px]">· {t('channels.picker.unavailable')}</span>}
            </button>
          )
        })}
      </div>
    </div>
  )
}
