import { CircleHelp } from 'lucide-react'
import { useTranslation } from 'react-i18next'
import { useHelp } from './HelpProvider'

/** The «?» in the top bar: opens the help window on the articles written for the page the person is on, with search and the whole help centre one step away. */
export default function HelpButton({ portal: _portal }: { portal: boolean }) {
  const { t } = useTranslation()
  const help = useHelp()
  if (!help) return null
  return (
    <button type="button" data-tour="help-button" onClick={() => help.open({})} aria-label={t('hlp.button')} title={String(t('hlp.button'))} className="rounded-xl p-2 text-navy-800 hover:bg-navy-100/60">
      <CircleHelp className="size-5" />
    </button>
  )
}
