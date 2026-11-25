import clsx from 'clsx'
import { Check, ChevronDown, Eye, EyeOff, Home, ListChecks, Lock, Pencil, Plus, Power, RotateCcw, Save, Search, ShieldCheck, Trash2 } from 'lucide-react'
import { useEffect, useMemo, useState, type ComponentType } from 'react'
import { useTranslation } from 'react-i18next'
import { pageRights, pagesOfRole, type Item } from '@/components/admin/menuCatalog'
import { Button, Empty, ErrorState, Spinner } from '@/components/ui'
import { useGet } from '@/hooks/useApi'
import { useFeatures } from '@/hooks/useFeature'
import { api, errorMessage } from '@/lib/api'
import { useAuth } from '@/lib/auth'
import { dialogs } from '@/lib/dialogs'
import { alsoOpens, areaOf, customised, doorToGive, doorsOf, draftOf, featuresOf, hasLevels, hide, keepsOpen, kindOf, levelOn, LEVELS, linked, replaced, sameSaved, savedOf, setAllLevels, setFeature, setFeatures, setLevel, show, shown, type Draft, type Ground, type Kind, type Level, type Saved } from '@/lib/pageAccess'
import { toast } from '@/lib/toast'
import { featureGroups } from './featureGroups'

type Grant = { id: string; role_id: string; slug: string; name_ar: string; name_en: string; scope_label_ar: string; scope_label_en: string; expired: boolean; granted_permissions: string[]; revoked_permissions: string[]; hidden_pages: string[]; denied_actions: Record<string, Level[]> }
type Role = { id: string; slug: string; permissions: { slug: string }[] }
type Perm = { slug: string; name_ar: string; name_en: string; group: string }
type AccessRole = { id: string; landing_route: string; slug: string }
type Filter = 'all' | 'shown' | 'off'

const LEVEL_ICON: Record<Level, ComponentType<{ className?: string }>> = { add: Plus, edit: Pencil, delete: Trash2 }
/** What a feature does, at a glance: it adds, edits, deletes, only shows, or is a step of its own (approve, publish…). */
const KIND: Record<Kind, { icon: ComponentType<{ className?: string }>; tone: string }> = {
  view: { icon: Eye, tone: 'bg-navy-900 text-gold-300' },
  add: { icon: Plus, tone: 'bg-emerald-100 text-emerald-800' },
  edit: { icon: Pencil, tone: 'bg-gold-100 text-gold-800' },
  delete: { icon: Trash2, tone: 'bg-red-100 text-red-700' },
  other: { icon: Check, tone: 'bg-navy-100 text-navy-900' },
}

/**
 * «الصفحات» of one person: every page of the menu of the role they hold, with a switch (shown or not) and, where the
 * page has something to change, whether the person may add, edit and delete on it.
 *
 * A page with several features (سابر+: add a programme, edit it, cancel it, add a group, edit it, delete it…) opens on
 * «المزايا»: every feature of the page with its own switch, so each is given or kept back by itself.
 *
 * The administrator decides by page; the rights follow (lib/pageAccess): a page switched on gives the right that opens
 * it, a page switched off takes it when no other shown page needs it, and the pages that share a right are told apart
 * by hiding. What will be given and taken is always in view at the foot before anything is saved, and it is this
 * person's alone: the role is not changed.
 */
export default function UserPages({ userId, onChanged }: { userId: string; onChanged?: () => void }) {
  const { t, i18n } = useTranslation()
  const ar = i18n.language === 'ar'
  const { can, refresh, user: me } = useAuth()
  const grants = useGet<{ data: Grant[] }>(`/admin/users/${userId}/roles`, undefined, { staleTime: 0 })
  const catalogue = useGet<{ data: Role[]; permissions: Record<string, Perm[]> }>('/admin/roles', undefined, { staleTime: 60_000 })
  const access = useGet<{ data: { roles: AccessRole[] } }>(`/admin/users/${userId}/access`, undefined, { staleTime: 0 })
  const flags = useFeatures().data?.data.flags
  const featureOn = (f?: string) => !f || !flags || f.split('|').some((x) => flags[x])
  const [grantId, setGrantId] = useState<string | null>(null)
  const [draft, setDraft] = useState<Draft | null>(null)
  const [term, setTerm] = useState('')
  const [filter, setFilter] = useState<Filter>('all')
  const [busy, setBusy] = useState(false)
  // The pages whose features are open, one by one.
  const [opened, setOpened] = useState<ReadonlySet<string>>(new Set())
  const toggleOpened = (to: string) => setOpened((was) => { const next = new Set(was); if (!next.delete(to)) next.add(to); return next })
  const nm = (o: { name_ar: string; name_en: string }) => (ar ? o.name_ar : o.name_en)

  const live = useMemo(() => (grants.data?.data ?? []).filter((g) => !g.expired), [grants.data])
  const grant = live.find((g) => g.id === grantId) ?? live[0] ?? null
  const perms = useMemo(() => Object.values(catalogue.data?.permissions ?? {}).flat(), [catalogue.data])
  const roleRights = useMemo(() => catalogue.data?.data.find((r) => r.id === grant?.role_id)?.permissions.map((p) => p.slug) ?? [], [catalogue.data, grant?.role_id])
  const saved: Saved = useMemo(() => ({ granted: grant?.granted_permissions ?? [], revoked: grant?.revoked_permissions ?? [], hidden_pages: grant?.hidden_pages ?? [], denied_actions: grant?.denied_actions ?? {} }), [grant])
  const landing = access.data?.data.roles.find((r) => r.id === grant?.id)?.landing_route
  const heldSlugs = useMemo(() => (access.data?.data.roles ?? []).map((r) => r.slug), [access.data])
  const sections = useMemo(() => (grant && landing ? pagesOfRole(t, { slug: grant.slug, landing }, heldSlugs) : []), [grant, landing, heldSlugs, t])
  const ground: Ground = useMemo(() => {
    const known = perms.map((p) => p.slug)
    return { pages: sections.flatMap((s) => s.items), known, onlyDoors: new Set([...pageRights(t)].filter((p) => known.includes(p))) }
  }, [sections, perms, t])
  const start = useMemo(() => draftOf(roleRights, saved), [roleRights, saved])
  // The switches start from what is saved for the role being looked at, and start again after a save or another role.
  useEffect(() => { setDraft(start) }, [start, grant?.id])

  if (grants.isError) return <ErrorState error={grants.error} onRetry={() => void grants.refetch()} />
  if (access.isError) return <ErrorState error={access.error} onRetry={() => void access.refetch()} />
  if (grants.isLoading || catalogue.isLoading || !catalogue.data || access.isLoading) return <Spinner />
  if (!grant) return <Empty text={t('userRights.noRoles')} icon={<ShieldCheck className="size-6" />} />
  if (!draft || !landing) return <Spinner />

  const fixed = grant.slug === 'super_admin'
  const editable = can('roles.manage') && !fixed
  const now = savedOf(roleRights, draft, ground)
  const dirty = !sameSaved(now, saved)
  const rightName = (slug: string | null | undefined) => { const p = perms.find((x) => x.slug === slug); return p ? nm(p) : slug ?? '' }
  const gained = [...draft.rights].filter((r) => !start.rights.has(r)).sort()
  const lost = [...start.rights].filter((r) => !draft.rights.has(r)).sort()
  const total = ground.pages.length
  const seen = ground.pages.filter((p) => shown(p, draft) && featureOn(p.feature)).length
  const q = term.trim().toLowerCase()

  const reason = (page: Item, on: boolean): string => {
    if (page.to === landing) return t('userPages.why.home')
    const doors = doorsOf(page)
    if (replaced(page, draft)) return t('userPages.why.replaced', { page: (ground.pages as readonly Item[]).find((p) => p.permission && p.permission === page.without?.permission)?.label ?? '' })
    if (!doors.length) return on ? t('userPages.why.open') : t('userPages.why.hidden')
    const held = doors.find((d) => draft.rights.has(d))
    if (on) return t('userPages.why.right', { right: rightName(held) })
    return held ? t('userPages.why.hiddenKept', { right: rightName(held) }) : t('userPages.why.needs', { right: rightName(doorToGive(page)) })
  }

  const save = async () => {
    const changes = [gained.length && t('userPages.confirmGain', { rights: gained.map(rightName).join('، ') }), lost.length && t('userPages.confirmLose', { rights: lost.map(rightName).join('، ') })].filter(Boolean).join('\n')
    if (!await dialogs.confirm(`${t('userPages.confirm', { role: nm(grant), n: seen })}${changes ? `\n\n${changes}` : ''}`)) return
    setBusy(true)
    try {
      await api.put(`/admin/users/${userId}/roles/${grant.id}/permissions`, now)
      toast(t('userPages.saved'))
      await grants.refetch()
      onChanged?.()
      // The administrator's own account reads its pages again when it was the one changed.
      if (me?.id === userId) void refresh()
    } catch (e) { toast(errorMessage(e), 'error') } finally { setBusy(false) }
  }

  return (
    <div className="space-y-5">
      {live.length > 1 && (
        <div role="tablist" aria-label={t('userAccess.roles')} className="flex flex-wrap gap-2">
          {live.map((g) => {
            const own = customised({ granted: g.granted_permissions, revoked: g.revoked_permissions, hidden_pages: g.hidden_pages, denied_actions: g.denied_actions })
            return (
              <button key={g.id} type="button" role="tab" aria-selected={g.id === grant.id} onClick={() => { setGrantId(g.id); setTerm(''); setFilter('all') }}
                className={clsx('rounded-2xl border px-4 py-2.5 text-start transition', g.id === grant.id ? 'border-navy-900 bg-navy-900 text-white shadow-md' : 'border-navy-100 bg-white text-navy-900 hover:border-gold-400')}>
                <span className="flex items-center gap-2 font-bold"><ShieldCheck className={clsx('size-4', g.id === grant.id ? 'text-gold-300' : 'text-gold-700')} aria-hidden />{nm(g)}{own && <span className={clsx('rounded-full px-2 py-0.5 text-[10px] font-bold', g.id === grant.id ? 'bg-gold-500 text-navy-950' : 'bg-gold-100 text-gold-800')}>{t('userPages.own')}</span>}</span>
                <span className={clsx('mt-0.5 block text-xs', g.id === grant.id ? 'text-white/70' : 'text-slate-500')}>{ar ? g.scope_label_ar : g.scope_label_en}</span>
              </button>
            )
          })}
        </div>
      )}

      <p className="rounded-2xl border border-gold-200 bg-gold-50/60 px-4 py-3 text-xs leading-6 text-navy-900">{fixed ? t('rolesAdmin.superNote') : t('userPages.intro', { role: nm(grant) })}</p>

      {!fixed && (
        <>
          <div className="flex flex-wrap items-center gap-3">
            <div className="relative min-w-52 flex-1"><Search className="pointer-events-none absolute start-3 top-1/2 size-4 -translate-y-1/2 text-slate-400" /><input className="input ps-9" placeholder={t('userPages.search')} value={term} onChange={(e) => setTerm(e.target.value)} /></div>
            <div role="group" aria-label={t('userPages.filterLabel')} className="inline-flex rounded-xl border border-navy-100 bg-white p-1">
              {(['all', 'shown', 'off'] as const).map((f) => (
                <button key={f} type="button" aria-pressed={filter === f} onClick={() => setFilter(f)} className={clsx('rounded-lg px-3 py-1.5 text-xs font-bold transition-colors', filter === f ? 'bg-navy-900 text-white' : 'text-navy-900 hover:bg-ivory')}>
                  {t(`userPages.filter.${f}`)} <span className={clsx('tabular-nums', filter === f ? 'text-gold-300' : 'text-slate-400')}>{f === 'all' ? total : f === 'shown' ? seen : total - seen}</span>
                </button>
              ))}
            </div>
            {editable && (
              <div className="flex gap-2">
                <button type="button" onClick={() => setDraft(setAllLevels(false, draft, ground))} className="inline-flex items-center gap-1.5 rounded-xl border border-navy-100 bg-white px-3 py-2 text-xs font-bold text-navy-900 transition-colors hover:border-gold-400"><Eye className="size-3.5 text-gold-700" aria-hidden />{t('userPages.allViewOnly')}</button>
                <button type="button" onClick={() => setDraft(setAllLevels(true, draft, ground))} className="inline-flex items-center gap-1.5 rounded-xl border border-navy-100 bg-white px-3 py-2 text-xs font-bold text-navy-900 transition-colors hover:border-gold-400"><Pencil className="size-3.5 text-gold-700" aria-hidden />{t('userPages.allLevels')}</button>
              </div>
            )}
          </div>

          <div className="space-y-4">
            {sections.map((section) => {
              const rows = section.items.filter((p) => {
                const on = shown(p, draft) && featureOn(p.feature)
                return (!q || `${p.label} ${p.to}`.toLowerCase().includes(q)) && (filter === 'all' || (filter === 'shown') === on)
              })
              if (!rows.length) return null
              const onCount = section.items.filter((p) => shown(p, draft) && featureOn(p.feature)).length
              return (
                <section key={section.id ?? section.title} aria-label={section.title} className="overflow-hidden rounded-2xl border border-navy-100 bg-white">
                  <h3 className="flex items-baseline justify-between gap-3 border-b border-navy-100 bg-ivory/70 px-4 py-2.5 text-xs font-bold text-gold-700">{section.title}<span className="font-semibold tabular-nums text-slate-500">{t('userPages.sectionCount', { n: onCount, total: section.items.length })}</span></h3>
                  <ul className="divide-y divide-navy-100/70">
                    {rows.map((page) => {
                      const on = shown(page, draft)
                      const home = page.to === landing
                      const off = !featureOn(page.feature)
                      const stands = replaced(page, draft)
                      const features = on ? featuresOf(page, ground) : []
                      const heldFeatures = features.filter((f) => draft.rights.has(f)).length
                      const expanded = features.length > 1 && opened.has(page.to)
                      const changed = on !== shown(page, start) || LEVELS.some((l) => levelOn(page, l, draft) !== levelOn(page, l, start)) || featuresOf(page, ground).some((f) => draft.rights.has(f) !== start.rights.has(f))
                      const limited = [...new Set(features.map(areaOf))].flatMap((a) => draft.denied[a] ?? [])
                      const levels = on && hasLevels(page, draft, ground)
                      const moves = levels ? linked(page, ground).filter((p) => shown(p, draft)) : []
                      return (
                        <li key={page.to} data-page={page.to} data-on={on} className={clsx('flex flex-wrap items-center gap-x-4 gap-y-2.5 px-4 py-3 transition-colors', !on && 'bg-slate-50/70')}>
                          <span className={clsx('grid size-9 shrink-0 place-items-center rounded-xl transition-colors', on ? 'bg-navy-900 text-gold-300' : 'bg-slate-100 text-slate-400')} aria-hidden><page.icon className="size-4" /></span>
                          <div className="min-w-0 flex-1 basis-56">
                            <div className="flex flex-wrap items-center gap-2">
                              <span className={clsx('font-bold', on ? 'text-navy-900' : 'text-slate-500')}>{page.label}</span>
                              {home && <span className="inline-flex items-center gap-1 rounded-full bg-gold-100 px-2 py-0.5 text-[10px] font-bold text-gold-800"><Home className="size-3" aria-hidden />{t('userPages.home')}</span>}
                              {off && <span className="inline-flex items-center gap-1 rounded-full bg-slate-100 px-2 py-0.5 text-[10px] font-bold text-slate-600"><Power className="size-3" aria-hidden />{t('userPages.featureOff')}</span>}
                              {changed && <span className="rounded-full bg-gold-500 px-2 py-0.5 text-[10px] font-bold text-navy-950">{t('userPages.changed')}</span>}
                            </div>
                            <p className="mt-0.5 text-xs leading-5 text-slate-500">{reason(page, on)}{moves.length > 0 && <span title={moves.map((p) => (p as Item).label).join('، ')}> · {t('userPages.movesWith', { pages: moves.slice(0, 2).map((p) => (p as Item).label).join('، ') })}{moves.length > 2 && t('userPages.andMore', { n: moves.length - 2 })}</span>}</p>
                          </div>
                          {features.length > 1 && (
                            <button type="button" aria-expanded={expanded} aria-controls={`features-${page.to}`} data-features onClick={() => toggleOpened(page.to)}
                              className={clsx('inline-flex items-center gap-1.5 rounded-full border px-3 py-1 text-xs font-bold transition-colors', expanded ? 'border-navy-900 bg-navy-900 text-white' : 'border-navy-100 bg-white text-navy-900 hover:border-gold-400')}>
                              <ListChecks className={clsx('size-3.5', expanded ? 'text-gold-300' : 'text-gold-700')} aria-hidden />{t('userPages.features')}
                              <span className={clsx('tabular-nums', expanded ? 'text-gold-300' : 'text-slate-500')}>{heldFeatures}/{features.length}</span>
                              <ChevronDown className={clsx('size-3.5 transition-transform', expanded && 'rotate-180')} aria-hidden />
                            </button>
                          )}
                          <div className="flex items-center gap-1.5" role="group" aria-label={t('userPages.levelsOf', { page: page.label })}>
                            {levels ? LEVELS.map((level) => {
                              const allowedNow = levelOn(page, level, draft)
                              const Icon = LEVEL_ICON[level]
                              return (
                                <button key={level} type="button" aria-pressed={allowedNow} disabled={!editable} data-level={level} onClick={() => setDraft(setLevel(page, level, !allowedNow, draft, ground))}
                                  className={clsx('inline-flex items-center gap-1 rounded-full border px-2.5 py-1 text-xs font-bold transition-colors disabled:cursor-not-allowed', allowedNow ? 'border-emerald-600/25 bg-emerald-50 text-emerald-800 hover:border-emerald-600/60' : 'border-slate-200 bg-white text-slate-400 line-through decoration-slate-300 hover:border-slate-400')}>
                                  <Icon className="size-3.5" aria-hidden />{t(`pageAccess.level.${level}`)}
                                </button>
                              )
                            }) : on && doorsOf(page).length > 0 ? <span className="inline-flex items-center gap-1 rounded-full bg-ivory px-2.5 py-1 text-xs font-semibold text-slate-500 ring-1 ring-navy-100"><Eye className="size-3.5" aria-hidden />{t('userPages.readOnlyPage')}</span> : null}
                          </div>
                          <button type="button" role="switch" aria-checked={on} aria-label={t(on ? 'userPages.hide' : 'userPages.show', { page: page.label })} title={home ? t('userPages.why.home') : stands ? reason(page, on) : undefined}
                            disabled={!editable || home || (stands && !on)} onClick={() => setDraft(on ? hide(page, draft, ground) : show(page, draft, ground))}
                            className={clsx('relative inline-flex h-6 w-11 shrink-0 items-center rounded-full p-0.5 transition-colors disabled:cursor-not-allowed disabled:opacity-50', on ? 'justify-end bg-gold-500' : 'justify-start bg-slate-300')}>
                            <span className="grid size-5 place-items-center rounded-full bg-white shadow">{on ? <Eye className="size-3 text-gold-700" aria-hidden /> : <EyeOff className="size-3 text-slate-400" aria-hidden />}</span>
                          </button>
                          {expanded && (
                            <div id={`features-${page.to}`} className="basis-full rounded-2xl border border-navy-100 bg-ivory/60 p-4">
                              <div className="flex flex-wrap items-center gap-3">
                                <div className="min-w-0 flex-1 basis-64">
                                  <h4 className="font-bold text-navy-900">{t('userPages.featuresOf', { page: page.label })} <span className="text-xs font-semibold tabular-nums text-slate-500">{t('userPages.sectionCount', { n: heldFeatures, total: features.length })}</span></h4>
                                  <p className="mt-0.5 text-xs leading-5 text-slate-600">{t('userPages.featuresHint')}</p>
                                </div>
                                {editable && (
                                  <div className="flex gap-2">
                                    <button type="button" data-all="off" onClick={() => setDraft(setFeatures(page, false, draft, ground))} className="inline-flex items-center gap-1.5 rounded-xl border border-navy-100 bg-white px-3 py-1.5 text-xs font-bold text-navy-900 transition-colors hover:border-gold-400"><Eye className="size-3.5 text-gold-700" aria-hidden />{t('userPages.viewOnly')}</button>
                                    <button type="button" data-all="on" onClick={() => setDraft(setFeatures(page, true, draft, ground))} className="inline-flex items-center gap-1.5 rounded-xl border border-navy-100 bg-white px-3 py-1.5 text-xs font-bold text-navy-900 transition-colors hover:border-gold-400"><Check className="size-3.5 text-gold-700" aria-hidden />{t('userPages.allOn')}</button>
                                  </div>
                                )}
                              </div>
                              {limited.length > 0 && <p role="note" className="mt-3 rounded-xl border border-gold-200 bg-gold-50 px-3 py-2 text-xs leading-5 text-navy-900">{t('userPages.generalLimit', { actions: LEVELS.filter((l) => limited.includes(l)).map((l) => t(`pageAccess.level.${l}`)).join('، ') })}</p>}
                              <div className="mt-3 space-y-3">
                                {featureGroups(features).map((group) => (
                                  <fieldset key={group.id || 'all'}>
                                    {group.id && <legend className="mb-1.5 text-[11px] font-bold text-gold-700">{t(`userPages.featureGroups.${group.id}`)}</legend>}
                                    <div className="grid gap-2 sm:grid-cols-2">
                                      {group.features.map((slug) => {
                                        const has = draft.rights.has(slug)
                                        const locked = has && keepsOpen(page, slug, draft)
                                        const kind = KIND[kindOf(slug)]
                                        const others = alsoOpens(page, slug, ground) as Item[]
                                        const note = locked ? t('userPages.opensPage') : (t(`userPages.featureNote.${slug.replaceAll('.', '_')}`, { defaultValue: '' }) as string) || (others.length ? t('userPages.alsoPage', { pages: others.map((o) => o.label).join('، ') }) : '')
                                        return (
                                          <button key={slug} type="button" role="switch" aria-checked={has} data-feature={slug} title={slug} disabled={!editable || locked} onClick={() => setDraft(setFeature(page, slug, !has, draft, ground))}
                                            className={clsx('flex items-center gap-2.5 rounded-xl border px-3 py-2 text-start transition-colors disabled:cursor-not-allowed', has ? 'border-navy-100 bg-white text-navy-900 shadow-sm' : 'border-dashed border-slate-300 text-slate-500 hover:border-slate-400', has !== start.rights.has(slug) && 'ring-2 ring-gold-400/60')}>
                                            <span className={clsx('grid size-7 shrink-0 place-items-center rounded-lg', has ? kind.tone : 'bg-slate-100 text-slate-400')} aria-hidden><kind.icon className="size-3.5" /></span>
                                            <span className="min-w-0 flex-1"><span className="block text-sm font-semibold leading-5">{rightName(slug)}</span>{note && <span className="block text-[11px] leading-4 text-slate-500">{note}</span>}</span>
                                            {locked ? <Lock className="size-4 shrink-0 text-gold-700" aria-hidden />
                                              : <span className={clsx('relative inline-flex h-5 w-9 shrink-0 items-center rounded-full p-0.5 transition-colors', has ? 'justify-end bg-gold-500' : 'justify-start bg-slate-300')} aria-hidden><span className="size-4 rounded-full bg-white shadow" /></span>}
                                          </button>
                                        )
                                      })}
                                    </div>
                                  </fieldset>
                                ))}
                              </div>
                            </div>
                          )}
                        </li>
                      )
                    })}
                  </ul>
                </section>
              )
            })}
            {q && !sections.some((s) => s.items.some((p) => `${p.label} ${p.to}`.toLowerCase().includes(q))) && <p className="rounded-2xl bg-ivory p-4 text-center text-sm text-slate-600">{t('userPages.none')}</p>}
          </div>

          <div className="sticky bottom-0 -mx-1 rounded-2xl bg-navy-950 px-4 py-3 text-white shadow-lg">
            <div className="flex flex-wrap items-center gap-3">
              <p className="min-w-0 flex-1 text-sm" aria-live="polite">
                <span className="font-bold text-gold-300 tabular-nums">{seen}</span> {t('userPages.seenOf', { total })}
                {Object.keys(now.denied_actions).length > 0 && <> · {t('userPages.limitedAreas', { n: Object.keys(now.denied_actions).length })}</>}
              </p>
              {editable ? (
                <>
                  <button type="button" disabled={!customised(now)} onClick={() => setDraft(draftOf(roleRights, { granted: [], revoked: [], hidden_pages: [], denied_actions: {} }))} className="inline-flex items-center gap-1.5 rounded-xl bg-white/10 px-3 py-2 text-xs font-bold transition-colors hover:bg-white/20 disabled:cursor-not-allowed disabled:opacity-40"><RotateCcw className="size-3.5" />{t('userPages.reset')}</button>
                  <Button variant="gold" icon={<Save className="size-4" />} loading={busy} disabled={!dirty} onClick={() => void save()}>{t('userPages.save')}</Button>
                </>
              ) : <span className="text-xs text-white/70">{t('userRights.readOnly')}</span>}
            </div>
            {(gained.length > 0 || lost.length > 0) && (
              <p className="mt-2 border-t border-white/10 pt-2 text-xs leading-6 text-white/80">
                {gained.length > 0 && <><span className="font-bold text-emerald-300">{t('userPages.gains')}</span> {gained.map(rightName).join('، ')}</>}
                {gained.length > 0 && lost.length > 0 && ' · '}
                {lost.length > 0 && <><span className="font-bold text-red-300">{t('userPages.loses')}</span> {lost.map(rightName).join('، ')}</>}
              </p>
            )}
          </div>
        </>
      )}
    </div>
  )
}
