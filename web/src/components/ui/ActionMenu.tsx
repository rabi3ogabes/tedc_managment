import clsx from 'clsx'
import { useCallback, useEffect, useId, useRef, useState, useSyncExternalStore, type ReactNode } from 'react'

export type MenuAction = { id: string; label: ReactNode; icon?: ReactNode; badge?: ReactNode; destructive?: boolean; disabled?: boolean; onSelect: () => void }

/** True below 768 px: the same menu is a popover on a desktop and a bottom sheet under the thumb on a phone. */
function useIsPhone() {
  return useSyncExternalStore(
    (cb) => { const mq = window.matchMedia('(max-width: 767px)'); mq.addEventListener('change', cb); return () => mq.removeEventListener('change', cb) },
    () => window.matchMedia('(max-width: 767px)').matches,
    () => false,
  )
}

/** The phone's Back button closes the sheet instead of leaving the page (one history entry while it is open). */
function useCloseOnBack(open: boolean, close: () => void) {
  const closeRef = useRef(close)
  useEffect(() => { closeRef.current = close })
  useEffect(() => {
    if (!open) return
    let pushed = false
    let popped = false
    const depth = (((window.history.state as { __menu?: number } | null)?.__menu) ?? 0) + 1
    const timer = window.setTimeout(() => { window.history.pushState({ ...(window.history.state ?? {}), __menu: depth }, ''); pushed = true }, 0)
    const onPop = () => { if (pushed && (((window.history.state as { __menu?: number } | null)?.__menu) ?? 0) < depth) { popped = true; closeRef.current() } }
    window.addEventListener('popstate', onPop)
    return () => {
      window.clearTimeout(timer)
      window.removeEventListener('popstate', onPop)
      if (pushed && !popped && (window.history.state as { __menu?: number } | null)?.__menu === depth) window.history.back()
    }
  }, [open])
}

/**
 * A button that opens a short list of actions: a popover next to it on a desktop, a bottom sheet on a phone
 * (overlays-and-controls skill, §1). Outside click, Esc and Back close it; focus returns to the button; the arrow
 * keys move through the items; an action runs after the menu has closed so a window it opens keeps the focus.
 */
export default function ActionMenu({ title, label, icon, badge, actions, className }: {
  title: string
  label: ReactNode
  icon?: ReactNode
  badge?: ReactNode
  actions: MenuAction[]
  className?: string
}) {
  const phone = useIsPhone()
  const [open, setOpen] = useState(false)
  const button = useRef<HTMLButtonElement>(null)
  const panel = useRef<HTMLDivElement>(null)
  const id = useId()
  const close = useCallback(() => setOpen(false), [])
  useCloseOnBack(open && phone, close)

  const move = (step: number) => {
    const items = Array.from(panel.current?.querySelectorAll<HTMLElement>('[role="menuitem"]:not([disabled])') ?? [])
    if (!items.length) return
    const at = items.indexOf(document.activeElement as HTMLElement)
    items[(at + step + items.length) % items.length].focus()
  }

  useEffect(() => {
    if (!open) return
    panel.current?.querySelector<HTMLElement>('[role="menuitem"]:not([disabled])')?.focus()
    const onKey = (e: KeyboardEvent) => {
      if (e.key === 'Escape') { e.stopPropagation(); close(); button.current?.focus() }
      else if (e.key === 'ArrowDown') { e.preventDefault(); move(1) }
      else if (e.key === 'ArrowUp') { e.preventDefault(); move(-1) }
      else if (e.key === 'Tab') close()
    }
    const onDown = (e: MouseEvent) => { if (!phone && !panel.current?.contains(e.target as Node) && !button.current?.contains(e.target as Node)) close() }
    window.addEventListener('keydown', onKey, true)
    window.addEventListener('mousedown', onDown)
    if (phone) { const was = document.body.style.overflow; document.body.style.overflow = 'hidden'; return () => { document.body.style.overflow = was; window.removeEventListener('keydown', onKey, true); window.removeEventListener('mousedown', onDown) } }
    return () => { window.removeEventListener('keydown', onKey, true); window.removeEventListener('mousedown', onDown) }
  }, [open, phone, close])

  const run = (a: MenuAction) => { close(); button.current?.focus(); window.setTimeout(a.onSelect, 0) }

  const items = actions.map((a, i) => (
    <button key={a.id} type="button" role="menuitem" disabled={a.disabled} onClick={() => run(a)}
      className={clsx('flex w-full items-center gap-3 text-start text-sm font-semibold outline-none transition disabled:opacity-40', phone ? 'min-h-[3.25rem] border-b border-navy-50 px-1 last:border-b-0 active:bg-ivory' : 'min-h-10 rounded-lg px-3 hover:bg-ivory focus-visible:bg-ivory',
        a.destructive ? 'text-rose-600' : 'text-navy-900', !phone && a.destructive && i > 0 && 'mt-1 border-t border-navy-50 pt-1')}>
      {a.icon && <span className="grid size-5 shrink-0 place-items-center text-gold-700" aria-hidden>{a.icon}</span>}
      <span className="min-w-0 flex-1 truncate">{a.label}</span>
      {a.badge}
    </button>
  ))

  return (
    <div className={clsx('relative', className)}>
      <button ref={button} type="button" aria-haspopup="menu" aria-expanded={open} aria-controls={open ? id : undefined} onClick={() => setOpen((o) => !o)}
        className="inline-flex min-h-10 items-center gap-2 rounded-xl border border-navy-100 bg-white px-3.5 py-2 text-sm font-bold text-navy-900 shadow-sm transition hover:border-gold-300 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-gold-500">
        {icon}{label}{badge}
      </button>
      {open && !phone && (
        <div ref={panel} id={id} role="menu" aria-label={title} className="absolute end-0 top-full z-40 mt-2 min-w-56 origin-top-end animate-fade-up rounded-2xl border border-navy-100 bg-white p-1.5 shadow-xl">{items}</div>
      )}
      {open && phone && (
        <>
          <div className="fixed inset-0 z-40 bg-navy-950/45" onClick={close} aria-hidden />
          <div ref={panel} id={id} role="menu" aria-label={title} className="fixed inset-x-0 bottom-0 z-50 max-h-[88dvh] animate-fade-up overflow-y-auto overscroll-contain rounded-t-3xl bg-white px-4 pb-[calc(1rem+env(safe-area-inset-bottom))] pt-2 shadow-2xl">
            <div className="mx-auto mb-3 mt-1 h-1.5 w-10 rounded-full bg-navy-100" aria-hidden />
            <div className="px-1 pb-2 text-base font-extrabold text-navy-900">{title}</div>
            {items}
          </div>
        </>
      )}
    </div>
  )
}
