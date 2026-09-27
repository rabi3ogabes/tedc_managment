import clsx from 'clsx'
import { LayoutGrid, Rows3 } from 'lucide-react'
import { useState, type ReactNode } from 'react'
import { useTranslation } from 'react-i18next'
import { Empty, Spinner } from './index'

export type ViewMode = 'table' | 'cards'

/**
 * A column rendered in both views.
 *  - title / subtitle: headline of each card
 *  - badge: shown in the card's top corner
 *  - media: leading visual (avatar, icon, code chip)
 *  - actions: card footer
 *  - select: selection checkbox
 *  - (default) detail: label / value pair in the card body
 */
export type Column<T> = {
  key: string
  header: ReactNode
  cell: (row: T) => ReactNode
  role?: 'title' | 'subtitle' | 'badge' | 'media' | 'actions' | 'select' | 'detail'
  className?: string
  hideInCards?: boolean
  hideInTable?: boolean
}

const storageKey = (id: string) => `tedc.view.${id}`

/** Per-list persisted view preference (table or cards). */
export function useViewMode(id: string, initial: ViewMode = 'table'): [ViewMode, (m: ViewMode) => void] {
  const [mode, setMode] = useState<ViewMode>(() => {
    try {
      const v = localStorage.getItem(storageKey(id))
      return v === 'cards' || v === 'table' ? v : initial
    } catch {
      return initial
    }
  })
  const update = (m: ViewMode) => {
    setMode(m)
    try {
      localStorage.setItem(storageKey(id), m)
    } catch {
      /* storage unavailable */
    }
  }
  return [mode, update]
}

export function ViewToggle({ value, onChange }: { value: ViewMode; onChange: (m: ViewMode) => void }) {
  const { t } = useTranslation()
  const options = [
    { id: 'table' as const, icon: Rows3, label: t('common.viewTable') },
    { id: 'cards' as const, icon: LayoutGrid, label: t('common.viewCards') },
  ]
  return (
    <div className="inline-flex rounded-xl border border-navy-100 bg-white p-1 shadow-sm" role="radiogroup" aria-label={t('common.viewMode')}>
      {options.map(({ id, icon: Icon, label }) => (
        <button
          key={id}
          type="button"
          role="radio"
          aria-checked={value === id}
          title={label}
          onClick={() => onChange(id)}
          className={clsx('inline-flex items-center gap-1.5 rounded-lg px-2.5 py-1.5 text-xs font-bold transition', value === id ? 'bg-navy-900 text-white shadow' : 'text-slate-500 hover:text-navy-900')}
        >
          <Icon className="size-4" />
          <span className="hidden sm:inline">{label}</span>
        </button>
      ))}
    </div>
  )
}

type Props<T> = {
  id: string
  rows: T[] | undefined
  columns: Column<T>[]
  rowKey: (row: T) => string
  loading?: boolean
  emptyText?: string
  total?: number
  toolbar?: ReactNode
  defaultMode?: ViewMode
  cardsClassName?: string
  isSelected?: (row: T) => boolean
}

/** Data list with a luxury card grid and a classic table, switchable by the user. */
export function DataView<T>({ id, rows, columns, rowKey, loading, emptyText, total, toolbar, defaultMode = 'table', cardsClassName, isSelected }: Props<T>) {
  const { t } = useTranslation()
  const [mode, setMode] = useViewMode(id, defaultMode)
  const count = total ?? rows?.length ?? 0

  return (
    <div>
      <div className="flex flex-wrap items-center justify-between gap-3 border-b border-navy-100 px-4 py-3">
        <div className="flex flex-wrap items-center gap-2">
          <span className="rounded-full bg-navy-100/70 px-2.5 py-1 text-xs font-bold text-navy-800">{t('common.results', { count })}</span>
          {toolbar}
        </div>
        <ViewToggle value={mode} onChange={setMode} />
      </div>

      {loading ? <Spinner /> : !rows?.length ? <Empty text={emptyText} /> : mode === 'table' ? (
        <TableView rows={rows} columns={columns} rowKey={rowKey} isSelected={isSelected} />
      ) : (
        <CardsView rows={rows} columns={columns} rowKey={rowKey} className={cardsClassName} isSelected={isSelected} />
      )}
    </div>
  )
}

function TableView<T>({ rows, columns, rowKey, isSelected }: { rows: T[]; columns: Column<T>[]; rowKey: (r: T) => string; isSelected?: (r: T) => boolean }) {
  const visible = columns.filter((c) => !c.hideInTable)
  return (
    <div className="overflow-x-auto">
      <table className="w-full text-sm">
        <thead>
          <tr className="border-b border-navy-100 bg-ivory/60 text-xs font-semibold uppercase tracking-wide text-slate-500">
            {visible.map((c) => <th key={c.key} className="whitespace-nowrap px-3 py-3 text-start">{c.header}</th>)}
          </tr>
        </thead>
        <tbody className="divide-y divide-navy-100/70">
          {rows.map((row) => (
            <tr key={rowKey(row)} className={clsx('transition hover:bg-ivory/70', isSelected?.(row) && 'bg-gold-100/40')}>
              {visible.map((c) => <td key={c.key} className={clsx('px-3 py-3 align-middle', c.className)}>{c.cell(row)}</td>)}
            </tr>
          ))}
        </tbody>
      </table>
    </div>
  )
}

function CardsView<T>({ rows, columns, rowKey, className, isSelected }: { rows: T[]; columns: Column<T>[]; rowKey: (r: T) => string; className?: string; isSelected?: (r: T) => boolean }) {
  const visible = columns.filter((c) => !c.hideInCards)
  const by = (role: Column<T>['role']) => visible.filter((c) => (c.role ?? 'detail') === role)
  const [select, media, title, subtitle, badges, details, actions] = [by('select'), by('media'), by('title'), by('subtitle'), by('badge'), by('detail'), by('actions')]

  return (
    <div className={clsx('grid gap-4 p-4 sm:grid-cols-2 2xl:grid-cols-3', className)}>
      {rows.map((row) => (
        <article
          key={rowKey(row)}
          className={clsx(
            'group relative flex flex-col overflow-hidden rounded-[var(--radius-card,1rem)] border bg-[var(--color-surface)] transition duration-300 hover:-translate-y-0.5 hover:shadow-glass',
            isSelected?.(row) ? 'border-gold-500 ring-2 ring-gold-100' : 'border-navy-100',
          )}
        >
          <span className="absolute inset-x-0 top-0 h-1 bg-gradient-to-l from-navy-900 via-navy-700 to-gold-500 opacity-80" />
          <div className="flex items-start gap-3 p-4 pb-3">
            {select.map((c) => <div key={c.key} className="pt-1">{c.cell(row)}</div>)}
            {media.map((c) => <div key={c.key} className="shrink-0">{c.cell(row)}</div>)}
            <div className="min-w-0 flex-1">
              {title.map((c) => <div key={c.key} className="font-bold leading-snug text-navy-900">{c.cell(row)}</div>)}
              {subtitle.map((c) => <div key={c.key} className="mt-0.5 text-xs text-slate-500">{c.cell(row)}</div>)}
            </div>
            {!!badges.length && <div className="flex shrink-0 flex-col items-end gap-1">{badges.map((c) => <div key={c.key}>{c.cell(row)}</div>)}</div>}
          </div>
          {!!details.length && (
            <dl className="grid flex-1 grid-cols-2 gap-x-4 gap-y-3 border-t border-dashed border-navy-100 px-4 py-3">
              {details.map((c) => (
                <div key={c.key} className="min-w-0">
                  <dt className="text-[11px] font-semibold uppercase tracking-wide text-slate-400">{c.header}</dt>
                  <dd className="mt-0.5 truncate text-sm text-navy-900">{c.cell(row)}</dd>
                </div>
              ))}
            </dl>
          )}
          {!!actions.length && <div className="flex flex-wrap items-center gap-2 border-t border-navy-100 bg-ivory/50 px-4 py-2.5">{actions.map((c) => <div key={c.key}>{c.cell(row)}</div>)}</div>}
        </article>
      ))}
    </div>
  )
}
