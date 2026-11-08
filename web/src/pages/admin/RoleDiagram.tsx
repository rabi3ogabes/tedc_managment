import clsx from 'clsx'
import { ShieldCheck } from 'lucide-react'
import { useMemo, useState } from 'react'
import { useTranslation } from 'react-i18next'

type Perm = { slug: string; name_ar: string; name_en: string; group: string }

/**
 * Roles & permissions → Diagram: the role at the top, the areas of the system under it. A lit area is one the role
 * has rights in; its card lists the open rights and the meter shows how much of the area is open. It reads the same
 * set the matrix edits, so a tick in the matrix shows here at once, before it is saved.
 */
export default function RoleDiagram({ roleName, isSuper, groups, granted, nameOf, groupName }: {
  roleName: string
  isSuper: boolean
  groups: Record<string, Perm[]>
  granted: Set<string>
  nameOf: (p: Perm) => string
  groupName: (group: string) => string
}) {
  const { t } = useTranslation()
  const [onlyOpen, setOnlyOpen] = useState(true)

  const areas = useMemo(() => Object.entries(groups)
    .map(([group, list]) => {
      const open = isSuper ? list : list.filter((p) => granted.has(p.slug))
      return { group, total: list.length, open }
    })
    .sort((a, b) => (b.open.length > 0 ? 1 : 0) - (a.open.length > 0 ? 1 : 0) || b.open.length / b.total - a.open.length / a.total), [groups, granted, isSuper])

  const lit = areas.filter((a) => a.open.length > 0)
  const shown = onlyOpen ? lit : areas

  return (
    <section aria-label={t('rolesAdmin.diagramTitle')} className="space-y-5">
      <div className="flex flex-wrap items-center justify-between gap-3">
        <p className="max-w-prose text-sm text-slate-600">{t('rolesAdmin.diagramHint')}</p>
        {!isSuper && (
          <label className="flex items-center gap-2 text-sm font-semibold text-navy-900">
            <input type="checkbox" className="accent-gold-600" checked={onlyOpen} onChange={(e) => setOnlyOpen(e.target.checked)} />{t('rolesAdmin.diagramOnly')}
          </label>
        )}
      </div>

      <div className="flex flex-col items-center">
        <div className="flex items-center gap-3 rounded-2xl bg-navy-900 px-5 py-3 text-white shadow-lg ring-1 ring-gold-400/40">
          <span className="grid size-10 place-items-center rounded-xl bg-gold-400/15 text-gold-300"><ShieldCheck className="size-5" /></span>
          <span>
            <span className="block text-base font-extrabold">{roleName}</span>
            <span className="block text-xs text-gold-200">{isSuper ? t('rolesAdmin.diagramAll') : `${lit.length} ${t('rolesAdmin.diagramOf')} ${areas.length} ${t('rolesAdmin.diagramAreas')} · ${granted.size} ${t('rolesAdmin.permissions')}`}</span>
          </span>
        </div>
        <span aria-hidden className="h-6 w-px bg-gold-300" />
      </div>

      {shown.length === 0 ? (
        <p className="rounded-2xl bg-ivory p-6 text-center text-sm text-slate-500">{t('rolesAdmin.diagramNothing')}</p>
      ) : (
        <ul className="relative grid gap-4 border-t border-gold-300 pt-6 sm:grid-cols-2 xl:grid-cols-3">
          {shown.map(({ group, total, open }) => {
            const on = open.length > 0
            const pct = Math.round((open.length / total) * 100)
            return (
              <li key={group} className={clsx('relative rounded-2xl border p-4 transition', on ? 'border-gold-300 bg-white shadow-sm' : 'border-navy-100 bg-ivory/60 opacity-70')}>
                <span aria-hidden className={clsx('absolute -top-6 start-1/2 h-6 w-px', on ? 'bg-gold-300' : 'bg-navy-100')} />
                <div className="flex items-baseline justify-between gap-2">
                  <h3 className="truncate text-sm font-extrabold text-navy-900">{groupName(group)}</h3>
                  <span className={clsx('shrink-0 text-xs font-bold tabular-nums', on ? 'text-gold-700' : 'text-slate-400')} dir="ltr">{open.length}/{total}</span>
                </div>
                <div className="mt-2 h-1.5 overflow-hidden rounded-full bg-navy-50" role="img" aria-label={`${pct}%`}>
                  <div className={clsx('h-full rounded-full', on ? 'bg-gold-500' : 'bg-transparent')} style={{ width: `${pct}%` }} />
                </div>
                {on && (
                  <ul className="mt-3 flex flex-wrap gap-1.5">
                    {open.map((p) => <li key={p.slug} title={p.slug} className="rounded-lg bg-navy-50 px-2 py-1 text-xs font-medium text-navy-900">{nameOf(p)}</li>)}
                  </ul>
                )}
              </li>
            )
          })}
        </ul>
      )}
    </section>
  )
}
