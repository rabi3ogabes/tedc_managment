import { ShieldCheck } from 'lucide-react'
import { useEffect, useState } from 'react'
import { useTranslation } from 'react-i18next'
import { Avatar, Badge, Button, Card, CardTitle, PageHeader, Spinner, StatusBadge, Tabs } from '@/components/ui'
import { DataView } from '@/components/ui/DataView'
import { useGet } from '@/hooks/useApi'
import { api, errorMessage } from '@/lib/api'
import { useAuth } from '@/lib/auth'
import { fmt } from '@/lib/format'
import type { LaravelPage } from '@/lib/types'

type Role = { id: string; slug: string; name_ar: string; name_en: string; users_count: number; permissions: { slug: string }[] }
type Perm = { slug: string; name_ar: string; name_en: string; group: string }
type User = { id: string; name: string; name_ar?: string; email: string; status: string; last_login_at?: string; roles: { slug: string; name_ar: string; name_en: string }[] }

export default function Users() {
  const { t, i18n } = useTranslation()
  const { can } = useAuth()
  const [tab, setTab] = useState<'users' | 'roles'>('users')
  const [q, setQ] = useState('')
  const users = useGet<LaravelPage<User>>('/admin/users', { q: q || undefined })
  const roles = useGet<{ data: Role[]; permissions: Record<string, Perm[]> }>('/admin/roles')
  const [selected, setSelected] = useState<Role | null>(null)
  const [perms, setPerms] = useState<string[]>([])
  const [message, setMessage] = useState<string | null>(null)
  const nm = (o: { name_ar: string; name_en: string }) => (i18n.language === 'ar' ? o.name_ar : o.name_en)

  useEffect(() => { if (selected) setPerms(selected.permissions.map((p) => p.slug)) }, [selected])

  const save = async () => {
    try {
      await api.put(`/admin/roles/${selected!.id}/permissions`, { permissions: perms })
      setMessage(t('common.saved'))
      roles.refetch()
    } catch (e) { setMessage(errorMessage(e)) }
  }
  const toggleStatus = async (u: User) => { await api.put(`/admin/users/${u.id}`, { status: u.status === 'active' ? 'suspended' : 'active' }); users.refetch() }

  return (
    <>
      <PageHeader title={t('admin.users.title')} />
      <Tabs value={tab} onChange={setTab} tabs={[{ id: 'users', label: t('admin.users.users') }, { id: 'roles', label: t('admin.users.roles') }]} />
      {tab === 'users' && (
        <Card padded={false}>
          <div className="border-b border-navy-100 p-4"><input className="input max-w-md" placeholder={t('common.search')} value={q} onChange={(e) => setQ(e.target.value)} /></div>
          <DataView id="admin.users" rows={users.data?.data} total={users.data?.total} loading={users.isLoading} rowKey={(u) => u.id} columns={[
            { key: 'avatar', header: '', role: 'media', hideInTable: true, cell: (u) => <Avatar name={u.name} size={44} /> },
            { key: 'name', header: t('common.name'), role: 'title', cell: (u) => <span className="font-semibold">{i18n.language === 'ar' ? u.name_ar ?? u.name : u.name}</span> },
            { key: 'email', header: t('common.email'), role: 'subtitle', cell: (u) => <span className="text-xs" dir="ltr">{u.email}</span> },
            { key: 'roles', header: t('admin.users.role'), cell: (u) => <div className="flex flex-wrap gap-1">{u.roles.map((r) => <Badge key={r.slug} color="navy">{nm(r)}</Badge>)}</div> },
            { key: 'login', header: t('admin.users.lastLogin'), cell: (u) => <span className="text-xs">{fmt.dateTime(u.last_login_at)}</span> },
            { key: 'status', header: t('common.status'), role: 'badge', cell: (u) => <StatusBadge status={u.status === 'active' ? 'approved' : 'blocked'} label={u.status} /> },
            { key: 'toggle', header: '', role: 'actions', cell: (u) => <Button size="sm" variant="ghost" onClick={() => toggleStatus(u)}>{u.status === 'active' ? '⏸' : '▶'}</Button> },
          ]} />
        </Card>
      )}
      {tab === 'roles' && (roles.isLoading || !roles.data ? <Spinner /> : (
        <div className="grid gap-6 lg:grid-cols-3">
          <Card padded={false}>
            <ul className="divide-y divide-navy-100">
              {roles.data.data.map((r) => (
                <li key={r.id}>
                  <button onClick={() => { setSelected(r); setMessage(null) }} className={`flex w-full items-center justify-between p-4 text-start hover:bg-ivory ${selected?.id === r.id ? 'bg-gold-100/50' : ''}`}>
                    <span className="flex items-center gap-2 font-semibold text-navy-900"><ShieldCheck className="size-4 text-gold-600" />{nm(r)}</span>
                    <span className="text-xs text-slate-400">{r.users_count} · {r.slug === 'super_admin' ? '∞' : r.permissions.length}</span>
                  </button>
                </li>
              ))}
            </ul>
          </Card>
          <Card className="lg:col-span-2">
            {!selected ? <p className="text-sm text-slate-500">{t('admin.users.roles')}</p> : (
              <>
                <CardTitle action={can('roles.manage') && selected.slug !== 'super_admin' && <Button variant="gold" size="sm" onClick={save}>{t('common.save')}</Button>}>{nm(selected)} — {t('admin.users.permissions')}</CardTitle>
                {message && <p className="mb-3 text-sm text-slate-500">{message}</p>}
                <div className="grid gap-5 sm:grid-cols-2">
                  {Object.entries(roles.data.permissions).map(([group, list]) => (
                    <div key={group}>
                      <div className="mb-2 text-xs font-bold uppercase tracking-wide text-gold-700">{group}</div>
                      {list.map((p) => (
                        <label key={p.slug} className="flex items-center gap-2 py-1 text-sm">
                          <input type="checkbox" className="accent-gold-600" disabled={selected.slug === 'super_admin'} checked={selected.slug === 'super_admin' || perms.includes(p.slug)}
                            onChange={(e) => setPerms(e.target.checked ? [...perms, p.slug] : perms.filter((x) => x !== p.slug))} />
                          {nm(p)}
                        </label>
                      ))}
                    </div>
                  ))}
                </div>
              </>
            )}
          </Card>
        </div>
      ))}
    </>
  )
}
