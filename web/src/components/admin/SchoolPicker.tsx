import clsx from 'clsx'
import { Check, Search } from 'lucide-react'
import { useEffect, useState } from 'react'
import { useTranslation } from 'react-i18next'
import { useGet } from '@/hooks/useApi'

export type SchoolRow = { id: string; code: string; name_ar: string; name_en: string; region?: string | null }

/** Searchable list of schools: pick one (`multiple` off) or several. The search runs on the server, so all ~775 schools are reachable. */
export default function SchoolPicker({ value, onChange, multiple = false, selectedRows = [] }: { value: string[]; onChange: (ids: string[], rows: SchoolRow[]) => void; multiple?: boolean; selectedRows?: SchoolRow[] }) {
  const { t, i18n } = useTranslation()
  const ar = i18n.language === 'ar'
  const [q, setQ] = useState('')
  const [debounced, setDebounced] = useState('')
  const [known, setKnown] = useState<Record<string, SchoolRow>>(() => Object.fromEntries(selectedRows.map((r) => [r.id, r])))
  useEffect(() => { const id = window.setTimeout(() => setDebounced(q.trim()), 250); return () => window.clearTimeout(id) }, [q])
  const { data, isFetching } = useGet<{ data: SchoolRow[] }>('/admin/schools', { q: debounced || undefined, per_page: 30 }, { staleTime: 60_000 })
  const rows = data?.data ?? []
  const name = (s: SchoolRow) => (ar ? s.name_ar : s.name_en || s.name_ar)

  const toggle = (s: SchoolRow) => {
    const next = { ...known, [s.id]: s }
    setKnown(next)
    const ids = multiple ? (value.includes(s.id) ? value.filter((x) => x !== s.id) : [...value, s.id]) : [s.id]
    onChange(ids, ids.map((id) => next[id]).filter(Boolean))
  }

  return (
    <div className="space-y-2">
      {multiple && value.length > 0 && (
        <div className="flex flex-wrap gap-1.5">{value.map((id) => known[id] && <button key={id} type="button" onClick={() => toggle(known[id])} className="rounded-full bg-navy-900 px-2.5 py-1 text-xs font-semibold text-white hover:bg-danger" title={t('common.remove', { defaultValue: 'Remove' })}>{name(known[id])} ×</button>)}</div>
      )}
      <div className="relative"><Search className="pointer-events-none absolute start-3 top-1/2 size-4 -translate-y-1/2 text-slate-400" /><input className="input ps-9" value={q} onChange={(e) => setQ(e.target.value)} placeholder={t('schoolPicker.search')} /></div>
      <ul className="max-h-56 divide-y divide-navy-50 overflow-auto rounded-xl border border-navy-100 bg-white" role="listbox" aria-multiselectable={multiple}>
        {rows.map((s) => {
          const on = value.includes(s.id)
          return (
            <li key={s.id} role="option" aria-selected={on}>
              <button type="button" onClick={() => toggle(s)} className={clsx('flex w-full items-center gap-3 px-3 py-2 text-start text-sm transition hover:bg-ivory', on && 'bg-gold-50')}>
                <span className="min-w-0 flex-1"><span className="block truncate font-semibold text-navy-900">{name(s)}</span><span className="block truncate text-xs text-slate-400" dir="ltr">{s.code}{s.region ? ` · ${s.region}` : ''}</span></span>
                {on && <Check className="size-4 text-gold-600" />}
              </button>
            </li>
          )
        })}
        {rows.length === 0 && <li className="px-3 py-6 text-center text-xs text-slate-400">{isFetching ? t('schoolPicker.loading') : t('schoolPicker.none')}</li>}
      </ul>
    </div>
  )
}
