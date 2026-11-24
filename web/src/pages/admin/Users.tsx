import { Eye, ShieldCheck, UserCheck } from 'lucide-react'
import { useState } from 'react'
import { useTranslation } from 'react-i18next'
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
import UserAccess from './UserAccess'
import UserRolesDialog from './UserRolesDialog'
import { dialogs } from '@/lib/dialogs'

type User = { id: string; name: string; name_ar?: string; email: string; status: string; last_login_at?: string; roles: { slug: string; name_ar: string; name_en: string }[] }

/**
 * Users and their roles. The permissions of each role are edited in Settings → Roles & permissions; «ما يراه» shows what
 * one person sees with them, and the system administrator opens the account from there or straight from the row.
 */
export default function Users() {
  const { t, i18n } = useTranslation()
  const { hasRole } = useAuth()
  const [q, setQ] = useState('')
  const [rolesOf, setRolesOf] = useState<User | null>(null)
  const [accessOf, setAccessOf] = useState<string | null>(null)
  const signInOn = useFeature('impersonation')
  const users = useGet<LaravelPage<User>>('/admin/users', { q: q || undefined })
  const nm = (o: { name_ar: string; name_en: string }) => (i18n.language === 'ar' ? o.name_ar : o.name_en)

  const signInAs = async (u: User) => {
    // Switched off (its state on a live system until it is switched on with a reason): the window says so and leads to the switch.
    if (!signInOn) return setAccessOf(u.id)
    if (!await dialogs.confirm(t('impersonation.confirm', { name: u.name, email: u.email }))) return
    try { window.location.href = (await impersonation.start(u.id)).landing } catch (e) { toast(errorMessage(e), 'error') }
  }
  const toggleStatus = async (u: User) => { await api.put(`/admin/users/${u.id}`, { status: u.status === 'active' ? 'suspended' : 'active' }); void users.refetch() }

  return (
    <>
      <PageHeader title={t('admin.users.title')} />
      <Card padded={false}>
        <div className="border-b border-navy-100 p-4"><input className="input max-w-md" placeholder={t('common.search')} value={q} onChange={(e) => setQ(e.target.value)} /></div>
        <DataView id="admin.users" rows={users.data?.data} total={users.data?.total} loading={users.isLoading} rowKey={(u) => u.id} columns={[
          { key: 'avatar', header: '', role: 'media', hideInTable: true, cell: (u) => <Avatar name={u.name} size={44} /> },
          { key: 'name', header: t('common.name'), role: 'title', cell: (u) => <span className="font-semibold">{i18n.language === 'ar' ? u.name_ar ?? u.name : u.name}</span> },
          { key: 'email', header: t('common.email'), role: 'subtitle', cell: (u) => <span className="text-xs" dir="ltr">{u.email}</span> },
          { key: 'roles', header: t('admin.users.role'), cell: (u) => <div className="flex flex-wrap gap-1">{u.roles.map((r) => <Badge key={r.slug} color="navy">{nm(r)}</Badge>)}</div> },
          { key: 'login', header: t('admin.users.lastLogin'), cell: (u) => <span className="text-xs">{fmt.dateTime(u.last_login_at)}</span> },
          { key: 'status', header: t('common.status'), role: 'badge', cell: (u) => <StatusBadge status={u.status === 'active' ? 'approved' : 'blocked'} label={t(`hlp.userStatus.${u.status}`, { defaultValue: u.status })} /> },
          { key: 'toggle', header: '', role: 'actions', cell: (u) => (
            <div className="flex flex-wrap gap-1">
              <Button size="sm" variant="outline" icon={<ShieldCheck className="size-4" />} onClick={() => setRolesOf(u)}>{t('userRoles.manage')}</Button>
              <Button size="sm" variant="outline" icon={<Eye className="size-4" />} onClick={() => setAccessOf(u.id)}>{t('userAccess.open')}</Button>
              {hasRole('super_admin') && !u.roles.some((r) => r.slug === 'super_admin') && u.status === 'active' && <Button size="sm" variant="outline" icon={<UserCheck className="size-4" />} onClick={() => signInAs(u)}>{t('impersonation.signInAs')}</Button>}
              <Button size="sm" variant="ghost" onClick={() => toggleStatus(u)}>{u.status === 'active' ? '⏸' : '▶'}</Button>
            </div>) },
        ]} />
      </Card>
      <UserRolesDialog user={rolesOf ? { id: rolesOf.id, name: rolesOf.name } : null} onClose={() => setRolesOf(null)} onChanged={() => void users.refetch()} />
      <UserAccess userId={accessOf} onClose={() => setAccessOf(null)} />
    </>
  )
}
