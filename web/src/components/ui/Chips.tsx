import clsx from 'clsx'
import { Check, Search, X } from 'lucide-react'
import { useMemo, useState, type KeyboardEvent } from 'react'

export type ChipOption = { value: string; label: string; hint?: string }

/** Pill toggles for a short list of options; several can be active at once. */
export function ChipSelect({ options, value, onChange, className }: { options: ChipOption[]; value: string[]; onChange: (next: string[]) => void; className?: string }) {
  const toggle = (v: string) => onChange(value.includes(v) ? value.filter((x) => x !== v) : [...value, v])
  return (
    <div className={clsx('flex flex-wrap gap-1.5', className)}>
      {options.map((o) => {
        const on = value.includes(o.value)
        return (
          <button
            key={o.value}
            type="button"
            title={o.hint}
            aria-pressed={on}
            onClick={() => toggle(o.value)}
            className={clsx('inline-flex items-center gap-1 rounded-full border px-3 py-1 text-xs font-semibold transition', on ? 'border-navy-900 bg-navy-900 text-white shadow' : 'border-navy-100 bg-white text-slate-600 hover:border-gold-400 hover:text-navy-900')}
          >
            {on && <Check className="size-3" />}
            {o.label}
          </button>
        )
      })}
    </div>
  )
}

/** Searchable checklist for long lists (schools, specializations). */
export function SearchMulti({ options, value, onChange, placeholder, max = 8 }: { options: ChipOption[]; value: string[]; onChange: (next: string[]) => void; placeholder?: string; max?: number }) {
  const [q, setQ] = useState('')
  const [focused, setFocused] = useState(false)
  const selected = useMemo(() => options.filter((o) => value.includes(o.value)), [options, value])
  const shown = useMemo(() => {
    const term = q.trim().toLowerCase()
    return options.filter((o) => !value.includes(o.value) && (!term || o.label.toLowerCase().includes(term))).slice(0, max)
  }, [options, value, q, max])

  return (
    <div className="space-y-2">
      {selected.length > 0 && (
        <div className="flex flex-wrap gap-1.5">
          {selected.map((o) => (
            <span key={o.value} className="inline-flex items-center gap-1 rounded-full bg-navy-900 px-2.5 py-1 text-xs font-semibold text-white">
              {o.label}
              <button type="button" aria-label="remove" onClick={() => onChange(value.filter((x) => x !== o.value))} className="rounded-full hover:bg-white/20"><X className="size-3" /></button>
            </span>
          ))}
        </div>
      )}
      <div className="relative">
        <Search className="absolute start-3 top-3 size-4 text-slate-400" />
        <input className="input ps-9" value={q} placeholder={placeholder} onChange={(e) => setQ(e.target.value)} onFocus={() => setFocused(true)} onBlur={() => setTimeout(() => setFocused(false), 150)} />
      </div>
      {(focused || q) && (
        <ul className="max-h-44 overflow-y-auto rounded-xl border border-navy-100 bg-white text-sm">
          {shown.length === 0 ? <li className="px-3 py-2 text-slate-400">—</li> : shown.map((o) => (
            <li key={o.value}>
              <button type="button" onClick={() => { onChange([...value, o.value]); setQ('') }} className="flex w-full items-center justify-between gap-2 px-3 py-2 text-start hover:bg-ivory">
                <span className="truncate">{o.label}</span>
                {o.hint && <span className="shrink-0 text-xs text-slate-400">{o.hint}</span>}
              </button>
            </li>
          ))}
        </ul>
      )}
    </div>
  )
}

/** Free-text tags: type and press Enter (or comma) to add. */
export function TagInput({ value, onChange, placeholder }: { value: string[]; onChange: (next: string[]) => void; placeholder?: string }) {
  const [draft, setDraft] = useState('')
  const add = () => {
    const v = draft.trim().replace(/,$/, '')
    if (v && !value.includes(v)) onChange([...value, v])
    setDraft('')
  }
  const onKey = (e: KeyboardEvent<HTMLInputElement>) => {
    if (e.key === 'Enter' || e.key === ',') { e.preventDefault(); add() }
    else if (e.key === 'Backspace' && !draft && value.length) onChange(value.slice(0, -1))
  }
  return (
    <div className="flex min-h-[2.75rem] flex-wrap items-center gap-1.5 rounded-xl border border-navy-100 bg-white px-2.5 py-1.5 focus-within:border-gold-500 focus-within:ring-4 focus-within:ring-gold-100">
      {value.map((t) => (
        <span key={t} className="inline-flex items-center gap-1 rounded-full bg-navy-100/70 px-2.5 py-0.5 text-xs font-semibold text-navy-800" dir="auto">
          {t}
          <button type="button" aria-label="remove" onClick={() => onChange(value.filter((x) => x !== t))} className="rounded-full hover:bg-white"><X className="size-3" /></button>
        </span>
      ))}
      <input className="min-w-24 flex-1 bg-transparent py-1 text-sm outline-none placeholder:text-slate-400" value={draft} placeholder={value.length ? '' : placeholder} onChange={(e) => setDraft(e.target.value)} onKeyDown={onKey} onBlur={add} />
    </div>
  )
}
