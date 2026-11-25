import clsx from 'clsx'
import { RotateCcw, Save, Search, ShieldCheck } from 'lucide-react'
import { useEffect, useMemo, useState } from 'react'
import { useTranslation } from 'react-i18next'
import { pageRightOf, pageRights } from '@/components/admin/menuCatalog'
import { Button, Empty, ErrorState, Spinner } from '@/components/ui'
import { useGet } from '@/hooks/useApi'
import { api, errorMessage } from '@/lib/api'
import { useAuth } from '@/lib/auth'
import { dialogs } from '@/lib/dialogs'
import { toast } from '@/lib/toast'
import { effectiveRights, overridesFor, rightState, sameOverrides, type Overrides } from '@/lib/userRights'

type Grant = { id: string; role_id: string; slug: string; name_ar: string; name_en: string; scope_type: string; scope_label_ar: string; scope_label_en: string; expired: boolean; granted_permissions: string[]; revoked_permissions: string[] }
type Role = { id: string; slug: string; permissions: { slug: string }[] }
type Perm = { slug: string; name_ar: string; name_en: string; group: string }

/**
 * «الصلاحيات» of one person: for each role they hold, the rights of the role with a tick on every right the person has.
 * Ticking a right the role does not give adds it for this person; unticking one the role gives takes it from this person.
 * The role itself is not changed, so nobody else who holds it is affected, and the difference from the role is always
 * in view: how many were added, how many taken, and the way back to the role as it is.
 */
export default function UserRights({ userId, onChanged }: { userId: string; onChanged?: () => void }) {
  const { t, i18n } = useTranslation()
  const ar = i18n.language === 'ar'
  const { can, refresh, user: me } = useAuth()
  const grants = useGet<{ data: Grant[] }>(`/admin/users/${userId}/roles`, undefined, { staleTime: 0 })
  const catalogue = useGet<{ data: Role[]; permissions: Record<string, Perm[]> }>('/admin/roles', undefined, { staleTime: 60_000 })
  const [grantId, setGrantId] = useState<string | null>(null)
  const [wanted, setWanted] = useState<Set<string>>(new Set())
  const [filter, setFilter] = useState('')
  const [onlyChanged, setOnlyChanged] = useState(false)
  const [busy, setBusy] = useState(false)
  const nm = (o: { name_ar: string; name_en: string }) => (ar ? o.name_ar : o.name_en)

  const live = useMemo(() => (grants.data?.data ?? []).filter((g) => !g.expired), [grants.data])
  const grant = live.find((g) => g.id === grantId) ?? live[0] ?? null
  const roleRights = useMemo(() => catalogue.data?.data.find((r) => r.id === grant?.role_id)?.permissions.map((p) => p.slug) ?? [], [catalogue.data, grant?.role_id])
  const own = useMemo(() => new Set(roleRights), [roleRights])
  const saved: Overrides = useMemo(() => ({ granted: grant?.granted_permissions ?? [], revoked: grant?.revoked_permissions ?? [] }), [grant])
  // The ticks start from what is saved for the role being looked at, and start again when another role is chosen or after a save.
  useEffect(() => { setWanted(effectiveRights(roleRights, saved)) }, [grant?.id, roleRights, saved])
  const pages = useMemo(() => { const known = new Set(Object.values(catalogue.data?.permissions ?? {}).flat().map((p) => p.slug)); return new Set([...pageRights(t)].filter((p) => known.has(p))) }, [catalogue.data, t])

  if (grants.isError) return <ErrorState error={grants.error} onRetry={() => void grants.refetch()} />
  if (grants.isLoading || catalogue.isLoading || !catalogue.data) return <Spinner />
  if (!grant) return <Empty text={t('userRights.noRoles')} icon={<ShieldCheck className="size-6" />} />

  const fixed = grant.slug === 'super_admin'
  const editable = can('roles.manage') && !fixed
  const now = overridesFor(roleRights, wanted)
  const dirty = !sameOverrides(now, saved)
  const term = filter.trim().toLowerCase()

  const give = (slug: string) => setWanted((was) => { const next = new Set(was).add(slug); const page = pageRightOf(slug, pages); if (page) next.add(page); return next })
  const take = (slug: string) => setWanted((was) => { const next = new Set(was); next.delete(slug); return next })
  const save = async () => {
    if (!await dialogs.confirm(t('userRights.confirm', { role: nm(grant), added: now.granted.length, taken: now.revoked.length }))) return
    setBusy(true)
    try {
      await api.put(`/admin/users/${userId}/roles/${grant.id}/permissions`, now)
      toast(t('userRights.saved'))
      await grants.refetch()
      onChanged?.()
      // The administrator's own account reads its rights again when it was the one changed.
      if (me?.id === userId) void refresh()
    } catch (e) { toast(errorMessage(e), 'error') } finally { setBusy(false) }
  }

  return (
    <div className="space-y-5">
      {live.length > 1 && (
        <div role="tablist" aria-label={t('userAccess.roles')} className="flex flex-wrap gap-2">
          {live.map((g) => {
            const changed = g.granted_permissions.length + g.revoked_permissions.length
            return (
              <button key={g.id} type="button" role="tab" aria-selected={g.id === grant.id} onClick={() => { setGrantId(g.id); setFilter(''); setOnlyChanged(false) }}
                className={clsx('rounded-2xl border px-4 py-2.5 text-start transition', g.id === grant.id ? 'border-navy-900 bg-navy-900 text-white shadow-md' : 'border-navy-100 bg-white text-navy-900 hover:border-gold-400')}>
                <span className="flex items-center gap-2 font-bold"><ShieldCheck className={clsx('size-4', g.id === grant.id ? 'text-gold-300' : 'text-gold-700')} aria-hidden />{nm(g)}{changed > 0 && <span className={clsx('rounded-full px-2 py-0.5 text-[10px] font-bold', g.id === grant.id ? 'bg-gold-500 text-navy-950' : 'bg-gold-100 text-gold-800')}>{t('userRights.changedN', { n: changed })}</span>}</span>
                <span className={clsx('mt-0.5 block text-xs', g.id === grant.id ? 'text-white/70' : 'text-slate-500')}>{ar ? g.scope_label_ar : g.scope_label_en}</span>
              </button>
            )
          })}
        </div>
      )}

      <p className="rounded-2xl border border-gold-200 bg-gold-50/60 px-4 py-3 text-xs leading-6 text-navy-900">{fixed ? t('rolesAdmin.superNote') : t('userRights.intro', { role: nm(grant) })}</p>

      {!fixed && (
        <>
          <div className="flex flex-wrap items-center gap-3">
            <div className="relative min-w-52 flex-1"><Search className="pointer-events-none absolute start-3 top-1/2 size-4 -translate-y-1/2 text-slate-400" /><input className="input ps-9" placeholder={t('rolesAdmin.search')} value={filter} onChange={(e) => setFilter(e.target.value)} /></div>
            <label className="inline-flex cursor-pointer items-center gap-2 text-sm font-semibold text-navy-900"><input type="checkbox" className="accent-gold-600" checked={onlyChanged} onChange={(e) => setOnlyChanged(e.target.checked)} />{t('userRights.onlyChanged')}</label>
          </div>

          <div className="grid gap-4 sm:grid-cols-2">
            {Object.entries(catalogue.data.permissions).map(([group, list]) => {
              const shown = list.filter((p) => (!term || `${p.slug} ${p.name_ar} ${p.name_en}`.toLowerCase().includes(term)) && (!onlyChanged || ['added', 'taken'].includes(rightState(p.slug, own, wanted))))
              if (!shown.length) return null
              const held = list.filter((p) => wanted.has(p.slug)).length
              return (
                <fieldset key={group} className="rounded-2xl border border-navy-100 bg-white p-3.5">
                  <legend className="px-1 text-xs font-bold text-gold-700">{t(`rolesAdmin.groups.${group}`, { defaultValue: group })} <span className="font-semibold text-slate-400">({held}/{list.length})</span></legend>
                  {shown.map((p) => {
                    const state = rightState(p.slug, own, wanted)
                    return (
                      <label key={p.slug} className={clsx('flex items-center gap-2 rounded-lg px-1.5 py-1 text-sm', state === 'added' && 'bg-emerald-50', state === 'taken' && 'bg-red-50')}>
                        <input type="checkbox" className="accent-gold-600" disabled={!editable} checked={wanted.has(p.slug)} onChange={(e) => (e.target.checked ? give(p.slug) : take(p.slug))} />
                        <span className={clsx('min-w-0 flex-1 truncate', state === 'taken' && 'text-slate-500 line-through')} title={p.slug}>{nm(p)}</span>
                        {state === 'added' && <span className="shrink-0 rounded-full bg-emerald-600 px-2 py-0.5 text-[10px] font-bold text-white">{t('userRights.added')}</span>}
                        {state === 'taken' && <span className="shrink-0 rounded-full bg-red-600 px-2 py-0.5 text-[10px] font-bold text-white">{t('userRights.taken')}</span>}
                      </label>
                    )
                  })}
                </fieldset>
              )
            })}
          </div>
          {onlyChanged && !now.granted.length && !now.revoked.length && <p className="rounded-2xl bg-ivory p-4 text-center text-sm text-slate-600">{t('userRights.sameAsRole')}</p>}

          <div className="sticky bottom-0 -mx-1 flex flex-wrap items-center gap-3 rounded-2xl bg-navy-950 px-4 py-3 text-white shadow-lg">
            <p className="min-w-0 flex-1 text-sm">
              {now.granted.length + now.revoked.length === 0 ? t('userRights.sameAsRole')
                : <><span className="font-bold text-emerald-300">+{now.granted.length}</span> {t('userRights.addedMany')} · <span className="font-bold text-red-300">−{now.revoked.length}</span> {t('userRights.takenMany')}</>}
            </p>
            {editable ? (
              <>
                <button type="button" disabled={now.granted.length + now.revoked.length === 0} onClick={() => setWanted(new Set(roleRights))} className="inline-flex items-center gap-1.5 rounded-xl bg-white/10 px-3 py-2 text-xs font-bold transition-colors hover:bg-white/20 disabled:cursor-not-allowed disabled:opacity-40"><RotateCcw className="size-3.5" />{t('userRights.reset')}</button>
                <Button variant="gold" icon={<Save className="size-4" />} loading={busy} disabled={!dirty} onClick={() => void save()}>{t('userRights.save')}</Button>
              </>
            ) : <span className="text-xs text-white/70">{t('userRights.readOnly')}</span>}
          </div>
        </>
      )}
    </div>
  )
}
