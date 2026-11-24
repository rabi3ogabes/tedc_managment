import clsx from 'clsx'
import { Briefcase, Building2, Home, KeyRound, LogIn, School, ShieldCheck, ToggleRight } from 'lucide-react'
import { useEffect, useMemo, useState } from 'react'
import { useTranslation } from 'react-i18next'
import { Link } from 'react-router-dom'
import { menuOfRole } from '@/components/admin/menuCatalog'
import { Avatar, Badge, Button, ErrorState, Modal, Spinner, StatusBadge } from '@/components/ui'
import { useGet } from '@/hooks/useApi'
import { useFeatures } from '@/hooks/useFeature'
import { errorMessage } from '@/lib/api'
import { useAuth } from '@/lib/auth'
import { impersonation } from '@/lib/impersonation'
import { toast } from '@/lib/toast'

type Named = { ar: string; en: string } | null
type AccessRole = {
  id: string; slug: string; name_ar: string; name_en: string; scope_type: string; scope_label_ar: string; scope_label_en: string
  landing_route: string; expires_at: string | null; starts_in: boolean; permissions: string[]
}
export type SignInReason = 'not_system_admin' | 'self' | 'admin' | 'inactive' | 'feature_off' | null
type Access = {
  user: { id: string; name: string; name_ar: string | null; name_en: string; email: string; status: string; last_login_at: string | null }
  position: { job_title: Named; school: Named; department: Named; has_trainee_profile: boolean; is_trainer: boolean }
  roles: AccessRole[]
  sign_in: { allowed: boolean; reason: SignInReason }
}
type Perm = { slug: string; name_ar: string; name_en: string; group: string }

/**
 * «ما يراه»: what one person sees and may do, role by role, and the way into their account.
 *
 * A person works in one role at a time, so each role is shown by itself: where it starts, the pages of its menu (worked
 * out by the same rule the side menu uses, menuOfRole) and its rights by area. From here the system administrator opens
 * the account in the role being looked at, to see it exactly as the person does.
 */
export default function UserAccess({ userId, onClose }: { userId: string | null; onClose: () => void }) {
  const { t, i18n } = useTranslation()
  const ar = i18n.language === 'ar'
  const { can } = useAuth()
  const access = useGet<{ data: Access }>(userId ? `/admin/users/${userId}/access` : null, undefined, { staleTime: 0 })
  const catalogue = useGet<{ permissions: Record<string, Perm[]> }>(userId ? '/admin/roles' : null, undefined, { staleTime: 60_000 })
  const flags = useFeatures().data?.data.flags ?? {}
  const featureOn = (f?: string) => !f || f.split('|').some((x) => flags[x])
  const [roleId, setRoleId] = useState<string | null>(null)
  const [opening, setOpening] = useState(false)
  const data = access.data?.data
  useEffect(() => { setRoleId(null) }, [userId])

  const role = data?.roles.find((r) => r.id === roleId) ?? data?.roles.find((r) => r.starts_in) ?? data?.roles[0] ?? null
  const nm = (o: { name_ar: string; name_en: string }) => (ar ? o.name_ar : o.name_en)
  const named = (o: Named) => (o ? (ar ? o.ar : o.en) : null)
  const held = useMemo(() => (data?.roles ?? []).map((r) => r.slug), [data])
  const menu = role ? menuOfRole(t, { slug: role.slug, landing: role.landing_route, permissions: role.permissions }, held, featureOn) : []
  const pages = menu.reduce((n, g) => n + g.items.length, 0)
  const everything = !!role?.permissions.includes('*')
  const areas = role && !everything
    ? Object.entries(catalogue.data?.permissions ?? {}).map(([group, list]) => ({ group, list: list.filter((p) => role.permissions.includes(p.slug)) })).filter((a) => a.list.length)
    : []

  const signIn = async () => {
    if (!data || !role) return
    setOpening(true)
    try { window.location.href = (await impersonation.start(data.user.id, undefined, role.id)).landing } catch (e) { toast(errorMessage(e), 'error'); setOpening(false) }
  }

  return (
    <Modal open={!!userId} onClose={onClose} title={t('userAccess.title')} wide>
      {access.isError ? <ErrorState error={access.error} onRetry={() => void access.refetch()} /> : !data ? <Spinner /> : (
        <div className="space-y-6">
          <header className="flex flex-wrap items-center gap-4">
            <Avatar name={data.user.name} size={56} />
            <div className="min-w-0 flex-1">
              <div className="flex flex-wrap items-center gap-2">
                <h4 className="truncate font-display text-xl font-bold text-navy-900">{ar ? data.user.name_ar ?? data.user.name_en : data.user.name_en}</h4>
                <StatusBadge status={data.user.status === 'active' ? 'approved' : 'blocked'} label={t(`hlp.userStatus.${data.user.status}`, { defaultValue: data.user.status })} />
              </div>
              <div className="text-xs text-slate-500" dir="ltr">{data.user.email}</div>
              <ul className="mt-2 flex flex-wrap gap-x-4 gap-y-1 text-sm text-navy-900">
                {named(data.position.job_title) && <li className="inline-flex items-center gap-1.5"><Briefcase className="size-4 text-gold-700" aria-hidden />{named(data.position.job_title)}</li>}
                {named(data.position.school) && <li className="inline-flex items-center gap-1.5"><School className="size-4 text-gold-700" aria-hidden />{named(data.position.school)}</li>}
                {named(data.position.department) && <li className="inline-flex items-center gap-1.5"><Building2 className="size-4 text-gold-700" aria-hidden />{named(data.position.department)}</li>}
                {!data.position.has_trainee_profile && <li className="text-slate-500">{t('userAccess.noProfile')}</li>}
                {data.position.is_trainer && <li><Badge color="gold">{t('userAccess.trainer')}</Badge></li>}
              </ul>
            </div>
          </header>

          {!role ? <p className="rounded-2xl bg-ivory p-4 text-sm text-slate-600">{t('userAccess.noRoles')}</p> : (
            <>
              <div>
                <p className="mb-2 text-xs leading-6 text-slate-500">{data.roles.length > 1 ? t('userAccess.oneAtATime') : t('userAccess.oneRole')}</p>
                <div role="tablist" aria-label={t('userAccess.roles')} className="flex flex-wrap gap-2">
                  {data.roles.map((r) => (
                    <button key={r.id} type="button" role="tab" aria-selected={r.id === role.id} onClick={() => setRoleId(r.id)}
                      className={clsx('rounded-2xl border px-4 py-2.5 text-start transition', r.id === role.id ? 'border-navy-900 bg-navy-900 text-white shadow-md' : 'border-navy-100 bg-white text-navy-900 hover:border-gold-400')}>
                      <span className="flex items-center gap-2 font-bold"><ShieldCheck className={clsx('size-4', r.id === role.id ? 'text-gold-300' : 'text-gold-700')} aria-hidden />{nm(r)}{r.starts_in && <span className={clsx('rounded-full px-2 py-0.5 text-[10px] font-bold', r.id === role.id ? 'bg-gold-500 text-navy-950' : 'bg-gold-100 text-gold-800')}>{t('userAccess.startsIn')}</span>}</span>
                      <span className={clsx('mt-0.5 block text-xs', r.id === role.id ? 'text-white/70' : 'text-slate-500')}>{ar ? r.scope_label_ar : r.scope_label_en}</span>
                    </button>
                  ))}
                </div>
              </div>

              <div className="grid gap-5 lg:grid-cols-2">
                <section aria-labelledby="ua-pages" className="rounded-2xl border border-navy-100 p-4">
                  <h5 id="ua-pages" className="flex items-baseline justify-between gap-3 font-bold text-navy-900">{t('userAccess.pages')}<span className="rounded-full bg-gold-100 px-2.5 py-0.5 text-[11px] font-bold text-gold-800">{t('userAccess.pagesCount', { n: pages })}</span></h5>
                  <p className="mt-1 inline-flex items-center gap-1.5 text-xs text-slate-600"><Home className="size-3.5 text-gold-700" aria-hidden />{t('userAccess.landing')} <span dir="ltr" className="font-mono text-navy-900">{role.landing_route}</span></p>
                  <div className="mt-3 max-h-80 space-y-3 overflow-y-auto pe-1">
                    {menu.map((g) => (
                      <div key={g.id ?? g.title}>
                        <div className="mb-1.5 text-[11px] font-bold text-gold-700">{g.title}</div>
                        <ul className="flex flex-wrap gap-1.5">
                          {g.items.map((i) => <li key={i.to} className="inline-flex items-center gap-1.5 rounded-lg bg-ivory px-2.5 py-1 text-xs font-medium text-navy-900 ring-1 ring-navy-100"><i.icon className="size-3.5 text-navy-600" />{i.label}</li>)}
                        </ul>
                      </div>
                    ))}
                  </div>
                </section>

                <section aria-labelledby="ua-rights" className="rounded-2xl border border-navy-100 p-4">
                  <h5 id="ua-rights" className="flex items-baseline justify-between gap-3 font-bold text-navy-900">{t('userAccess.rights')}<span className="rounded-full bg-gold-100 px-2.5 py-0.5 text-[11px] font-bold text-gold-800">{everything ? t('userAccess.all') : t('userAccess.rightsCount', { n: role.permissions.length })}</span></h5>
                  <p className="mt-1 inline-flex items-center gap-1.5 text-xs text-slate-600"><KeyRound className="size-3.5 text-gold-700" aria-hidden />{t('userAccess.rightsHint')}</p>
                  <div className="mt-3 max-h-80 space-y-3 overflow-y-auto pe-1">
                    {everything ? <p className="rounded-xl bg-ivory p-3 text-sm text-slate-700">{t('rolesAdmin.superNote')}</p>
                      : !role.permissions.length ? <p className="rounded-xl bg-ivory p-3 text-sm text-slate-700">{t('userAccess.noRights')}</p>
                      : !areas.length ? <Spinner />
                      : areas.map((a) => (
                        <div key={a.group}>
                          <div className="mb-1.5 text-[11px] font-bold text-gold-700">{t(`rolesAdmin.groups.${a.group}`, { defaultValue: a.group })} <span className="font-semibold text-slate-400">({a.list.length})</span></div>
                          <ul className="flex flex-wrap gap-1.5">{a.list.map((p) => <li key={p.slug} title={p.slug} className="rounded-lg bg-white px-2.5 py-1 text-xs text-navy-900 ring-1 ring-navy-100">{nm(p)}</li>)}</ul>
                        </div>
                      ))}
                  </div>
                </section>
              </div>

              <footer className="rounded-2xl bg-navy-950 p-4 text-white">
                {data.sign_in.allowed ? (
                  <div className="flex flex-wrap items-center gap-4">
                    <p className="min-w-0 flex-1 text-sm leading-6 text-white/80">{t('userAccess.signInHint', { role: nm(role) })}</p>
                    <Button variant="gold" loading={opening} icon={<LogIn className="size-4 rtl:-scale-x-100" />} onClick={() => void signIn()}>{t('userAccess.signInAs', { role: nm(role) })}</Button>
                  </div>
                ) : (
                  <div className="flex flex-wrap items-center gap-4">
                    <p className="min-w-0 flex-1 text-sm leading-6 text-white/80">{t(`userAccess.cannot.${data.sign_in.reason}`)}</p>
                    {data.sign_in.reason === 'feature_off' && can('settings.manage') && <Link to="/admin/settings?tab=features" onClick={onClose} className="inline-flex shrink-0 items-center gap-2 rounded-xl bg-gold-500 px-4 py-2 text-sm font-bold text-navy-950 transition-colors hover:bg-gold-400"><ToggleRight className="size-4" />{t('userAccess.openFeatures')}</Link>}
                  </div>
                )}
              </footer>
            </>
          )}
        </div>
      )}
    </Modal>
  )
}
