import clsx from 'clsx'
import { useLayoutEffect, useRef, useState, type KeyboardEvent, type ReactNode } from 'react'

export type SegmentedOption<T extends string> = { value: T; label: ReactNode; icon?: ReactNode; badge?: ReactNode }

/**
 * Switches the *view* (2–5 options), not the data: one choice is always selected, a thumb slides to it, the arrow keys
 * follow the reading direction, and a row too narrow for its options scrolls sideways. Used with a URL parameter so a
 * view can be shared and survives a refresh (overlays-and-controls skill, §2).
 */
export function Segmented<T extends string>({ label, options, value, onChange, className }: {
  label: string
  options: SegmentedOption<T>[]
  value: T
  onChange: (value: T) => void
  className?: string
}) {
  const box = useRef<HTMLDivElement>(null)
  const items = useRef<(HTMLButtonElement | null)[]>([])
  const [thumb, setThumb] = useState<{ left: number; width: number } | null>(null)
  const index = Math.max(0, options.findIndex((o) => o.value === value))

  useLayoutEffect(() => {
    const place = () => {
      const el = items.current[index]
      if (!el) return
      setThumb({ left: el.offsetLeft, width: el.offsetWidth })
    }
    place()
    const scroller = box.current
    if (!scroller) return
    // A selected view that sits off-screen in a scrolling row is brought into reach (horizontal only, never the page).
    const el = items.current[index]
    if (el && scroller.scrollWidth > scroller.clientWidth) scroller.scrollTo({ left: el.offsetLeft - (scroller.clientWidth - el.offsetWidth) / 2, behavior: 'smooth' })
    const observer = new ResizeObserver(place)
    observer.observe(scroller)
    return () => observer.disconnect()
  }, [index, options.length])

  const onKeyDown = (e: KeyboardEvent<HTMLDivElement>) => {
    const rtl = getComputedStyle(e.currentTarget).direction === 'rtl'
    const forward = rtl ? 'ArrowLeft' : 'ArrowRight'
    const back = rtl ? 'ArrowRight' : 'ArrowLeft'
    let next = index
    if (e.key === forward) next = (index + 1) % options.length
    else if (e.key === back) next = (index - 1 + options.length) % options.length
    else if (e.key === 'Home') next = 0
    else if (e.key === 'End') next = options.length - 1
    else return
    e.preventDefault()
    onChange(options[next].value)
    items.current[next]?.focus()
  }

  return (
    <div ref={box} className={clsx('overflow-x-auto rounded-2xl border border-navy-100 bg-white p-1 shadow-sm [scrollbar-width:none]', className)}>
      <div role="tablist" aria-label={label} onKeyDown={onKeyDown} className="relative inline-flex min-w-full gap-1">
        {thumb && <span aria-hidden className="absolute inset-y-0 rounded-xl bg-navy-900 shadow transition-[left,width] duration-300 ease-[cubic-bezier(.22,1,.36,1)] motion-reduce:transition-none" style={{ left: thumb.left, width: thumb.width }} />}
        {options.map((o, i) => (
          <button key={o.value} ref={(el) => { items.current[i] = el }} type="button" role="tab" aria-selected={o.value === value} tabIndex={o.value === value ? 0 : -1} onClick={() => onChange(o.value)}
            className={clsx('relative z-10 flex min-h-11 flex-1 items-center justify-center gap-2 whitespace-nowrap rounded-xl px-4 py-2 text-sm font-bold transition-colors duration-200 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-gold-500', o.value === value ? 'text-white' : 'text-slate-500 hover:text-navy-900')}>
            {o.icon}{o.label}{o.badge}
          </button>
        ))}
      </div>
    </div>
  )
}
