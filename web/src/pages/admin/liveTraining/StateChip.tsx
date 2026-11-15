import clsx from 'clsx'

const STATE_STYLE: Record<string, string> = {
  live: 'bg-emerald-50 text-emerald-700 ring-emerald-600/20', soon: 'bg-amber-50 text-amber-700 ring-amber-600/25', later: 'bg-navy-50 text-navy-800 ring-navy-600/15',
  upcoming: 'bg-navy-50 text-navy-800 ring-navy-600/15', ended: 'bg-slate-100 text-slate-600 ring-slate-500/15', idle: 'bg-slate-100 text-slate-500 ring-slate-500/15',
}

/** The state of a room or a session as a small coloured chip; a running one carries a pulsing dot. */
export function StateChip({ state, label }: { state: string; label: string }) {
  return (
    <span className={clsx('inline-flex items-center gap-1.5 whitespace-nowrap rounded-full px-2.5 py-0.5 text-xs font-bold ring-1 ring-inset', STATE_STYLE[state] ?? STATE_STYLE.idle)}>
      {state === 'live' && <span className="size-1.5 animate-pulse rounded-full bg-emerald-500" />}{label}
    </span>
  )
}
