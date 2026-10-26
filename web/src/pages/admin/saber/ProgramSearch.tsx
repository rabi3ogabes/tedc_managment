import clsx from 'clsx'
import { Search } from 'lucide-react'
import { useEffect, useState } from 'react'
import { useTranslation } from 'react-i18next'
import { useGet } from '@/hooks/useApi'
import type { Program } from './types'

/** Find a programme by name or code (the workbench list, searched on the server); picking one hands back the whole row with its groups. */
export default function ProgramSearch({ value, onPick, placeholder, filter }: { value: Program | null; onPick: (p: Program) => void; placeholder: string; filter?: (p: Program) => boolean }) {
  const { t } = useTranslation()
  const [q, setQ] = useState('')
  const [term, setTerm] = useState('')
  useEffect(() => { const id = setTimeout(() => setTerm(q.trim()), 250); return () => clearTimeout(id) }, [q])
  const { data, isFetching } = useGet<{ data: Program[] }>('/admin/saber/programs', { q: term || undefined }, { staleTime: 10_000 })
  const rows = (data?.data ?? []).filter((p) => !filter || filter(p))

  return (
    <div className="overflow-hidden rounded-xl border border-navy-100 bg-white">
      <label className="flex items-center gap-2 border-b border-navy-50 px-3 py-2">
        <Search className="size-4 text-slate-400" />
        <input value={q} onChange={(e) => setQ(e.target.value)} placeholder={placeholder} className="w-full bg-transparent text-sm outline-none" />
        {isFetching && <span className="text-[11px] font-bold text-gold-600">•••</span>}
      </label>
      <ul className="max-h-52 overflow-y-auto p-1">
        {rows.length === 0 && !isFetching && <li className="px-3 py-3 text-center text-sm text-slate-400">{t('saber.day.noMatches')}</li>}
        {rows.map((p) => (
          <li key={p.id}>
            <button type="button" onClick={() => onPick(p)} aria-pressed={value?.id === p.id}
              className={clsx('flex w-full items-center justify-between gap-3 rounded-lg px-3 py-2 text-start text-sm transition', value?.id === p.id ? 'bg-gold-50 font-bold text-navy-900 ring-1 ring-inset ring-gold-300' : 'hover:bg-ivory')}>
              <span className="truncate">{p.title}</span>
              <span className="shrink-0 font-mono text-[11px] text-slate-400" dir="ltr">{p.code}</span>
            </button>
          </li>
        ))}
      </ul>
    </div>
  )
}
