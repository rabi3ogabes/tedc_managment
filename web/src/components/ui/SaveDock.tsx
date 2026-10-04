import clsx from 'clsx'
import { Loader2, Save } from 'lucide-react'

/**
 * The page's Save button: fixed at the middle of the physical left edge, so it stays under the thumb however far the page is scrolled.
 * `disabled` (nothing to save yet) dims it; `loading` shows the spinner.
 */
export function SaveDock({ label, onClick, loading, disabled }: { label: string; onClick: () => void; loading?: boolean; disabled?: boolean }) {
  const off = disabled || loading
  return (
    <button
      type="button"
      onClick={onClick}
      disabled={off}
      aria-label={label}
      title={label}
      className={clsx(
        'fixed left-2 top-1/2 z-30 flex size-12 -translate-y-1/2 flex-col items-center justify-center gap-1 rounded-full text-[11px] font-bold leading-tight shadow-lg transition-all sm:left-4 sm:h-auto sm:w-[4.5rem] sm:rounded-2xl sm:px-1.5 sm:py-3',
        'focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-navy-900',
        off ? 'cursor-not-allowed bg-slate-200 text-slate-400 shadow-none' : 'bg-gradient-to-b from-gold-300 to-gold-500 text-navy-950 shadow-gold hover:-translate-y-[52%] active:scale-95',
      )}
    >
      {loading ? <Loader2 className="size-5 animate-spin" /> : <Save className="size-5" />}
      <span className="hidden text-center sm:block">{label}</span>
    </button>
  )
}
