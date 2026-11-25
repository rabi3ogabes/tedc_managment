import { Eye, KeyRound, ShieldCheck, UserCheck } from 'lucide-react'
import { useState, type MouseEvent } from 'react'
import { useTranslation } from 'react-i18next'
import { useLocation, useNavigate } from 'react-router-dom'
import { Avatar, Badge, Button, Card, PageHeader, StatusBadge } from '@/components/ui'
import { DataView } from '@/components/ui/DataView'
import { useGet } from '@/hooks/useApi'
import { useFeature } from '@/hooks/useFeature'
import { api, errorMessage } from '@/lib/api'
import { useAuth } from '@/lib/auth'
import { impersonation } from '@/lib/impersonation'
import { fmt } from '@/lib/format'
import { toast } from '@/lib/toast'
import type { LaravelPage } from '@/lib/types'
import { dialogs } from '@/lib/dialogs'
import UserPanel from './users/UserPanel'
import { readUserPanel, writeUserPanel, type UserView } from './users/panelAddress'

type User = { id: string; name: string; name_ar?: string; email: string; status: string; last_login_at?: string; roles: { slug: string; name_ar: string; name_en: string }[] }

/**
 * Users. Pressing a user opens them in a side panel over the list: «الصلاحيات» (the rights of this person in each role:
 * what the role gives, what is added for them, what is taken from them), «الأدوار» (the roles they hold) and «ما يراه»
 * (what they end up seeing, and the way into the account). Which user is open is part of the address of the page
 * (users/panelAddress). The rights of a role itself are edited in Settings → Roles & permissions.
 */
export default function Users() {
  const { t, i18n } = useTranslation()
  const { hasRole } = useAuth()
  const [q, setQ] = useState('')
  const signInOn = useFeature('impersonation')
  const users = useGet<LaravelPage<User>>('/admin/users', { q: q || undefined })
  const nm = (o: { name_ar: string; name_en: string }) => (i18n.language === 'ar' ? o.name_ar : o.name_en)
  const navigate = useNavigate()
  const location = useLocation()
  const panel = readUserPanel(location.search)
  // The row that was pressed: the panel shows its name at once, before anything is loaded.
  const [pressed, setPressed] = useState<User | null>(null)

  const show = (u: User, view: UserView = 'rights') => {
    setPressed(u)
    // A new step in the history: the browser's Back button closes the panel.
    navigate({ search: writeUserPanel(location.search, { id: u.id, view }) }, { state: { panel: true } })
  }
  const setView = (view: UserView) => { if (panel) navigate({ search: writeUserPanel(location.search, { id: panel.id, view }) }, { replace: true, state: location.state }) }
  // Closing undoes the step that opened the panel; a panel reached by its address (a link, a reload) has no such step.
  const close = () => ((location.state as { panel?: boolean } | null)?.panel ? navigate(-1) : navigate({ search: writeUserPanel(location.search, null) }, { replace: true }))
  const act = (ev: MouseEvent, run: () => void) => { ev.stopPropagation(); run() }

  const signInAs = async (u: User) => {
    // Switched off (its state on a live system until it is switched on with a reason): «ما يراه» says so and leads to the switch.
    if (!signInOn) return show(u, 'access')
    if (!await dialogs.confirm(t('impersonation.confirm', { name: u.name, email: u.email }))) return
    try { window.location.href = (await impersonation.start(u.id)).landing } catch (e) { toast(errorMessage(e), 'error') }
  }
  const toggleStatus = async (u: User) => {
    try { await api.put(`/admin/users/${u.id}`, { status: u.status === 'active' ? 'suspended' : 'active' }); void users.refetch() } catch (e) { toast(errorMessage(e), 'error') }
  }

  return (
    <>
      <PageHeader title={t('admin.users.title')} subtitle={t('userPanel.rowHint')} />
      <Card padded={false}>
        <div className="border-b border-navy-100 p-4"><input className="input max-w-md" placeholder={t('common.search')} value={q} onChange={(e) => setQ(e.target.value)} /></div>
        <DataView id="admin.users" rows={users.data?.data} total={users.data?.total} loading={users.isLoading} rowKey={(u) => u.id} isSelected={(u) => panel?.id === u.id} onRowClick={(u) => show(u)} columns={[
          { key: 'avatar', header: '', role: 'media', hideInTable: true, cell: (u) => <Avatar name={u.name} size={44} /> },
          // The name is the row's real button: the keyboard and screen readers open the user from it, the pointer from anywhere on the row.
          { key: 'name', header: t('common.name'), role: 'title', cell: (u) => <button type="button" onClick={(ev) => act(ev, () => show(u))} aria-haspopup="dialog" className="text-start font-semibold text-navy-900 underline-offset-4 hover:underline focus-visible:underline">{i18n.language === 'ar' ? u.name_ar ?? u.name : u.name}</button> },
          { key: 'email', header: t('common.email'), role: 'subtitle', cell: (u) => <span className="text-xs" dir="ltr">{u.email}</span> },
          { key: 'roles', header: t('admin.users.role'), cell: (u) => <div className="flex flex-wrap gap-1">{u.roles.map((r) => <Badge key={r.slug} color="navy">{nm(r)}</Badge>)}</div> },
          { key: 'login', header: t('admin.users.lastLogin'), cell: (u) => <span className="text-xs">{fmt.dateTime(u.last_login_at)}</span> },
          { key: 'status', header: t('common.status'), role: 'badge', cell: (u) => <StatusBadge status={u.status === 'active' ? 'approved' : 'blocked'} label={t(`hlp.userStatus.${u.status}`, { defaultValue: u.status })} /> },
          { key: 'toggle', header: '', role: 'actions', cell: (u) => (
            <div className="flex flex-wrap gap-1">
              <Button size="sm" variant="outline" icon={<KeyRound className="size-4" />} onClick={(ev) => act(ev, () => show(u, 'rights'))}>{t('userRights.tab')}</Button>
              <Button size="sm" variant="outline" icon={<ShieldCheck className="size-4" />} onClick={(ev) => act(ev, () => show(u, 'roles'))}>{t('userRoles.manage')}</Button>
              <Button size="sm" variant="outline" icon={<Eye className="size-4" />} onClick={(ev) => act(ev, () => show(u, 'access'))}>{t('userAccess.open')}</Button>
              {hasRole('super_admin') && !u.roles.some((r) => r.slug === 'super_admin') && u.status === 'active' && <Button size="sm" variant="outline" icon={<UserCheck className="size-4" />} onClick={(ev) => act(ev, () => void signInAs(u))}>{t('impersonation.signInAs')}</Button>}
              <Button size="sm" variant="ghost" aria-label={t(u.status === 'active' ? 'userPanel.suspend' : 'userPanel.activate')} title={t(u.status === 'active' ? 'userPanel.suspend' : 'userPanel.activate')} onClick={(ev) => act(ev, () => void toggleStatus(u))}>{u.status === 'active' ? '⏸' : '▶'}</Button>
            </div>) },
        ]} />
      </Card>
      {panel && <UserPanel key={panel.id} id={panel.id} initial={pressed} view={panel.view} onView={setView} onClose={close} onChanged={() => void users.refetch()} />}
    </>
  )
}
