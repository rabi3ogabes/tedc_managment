import clsx from 'clsx'
import { Plus, Trash2 } from 'lucide-react'
import { useState } from 'react'
import { useTranslation } from 'react-i18next'
import SchoolPicker from '@/components/admin/SchoolPicker'
import { Badge, Button, Field, Modal, Spinner } from '@/components/ui'
import { useGet } from '@/hooks/useApi'
import { api, errorMessage } from '@/lib/api'
import { fmt } from '@/lib/format'
import { toast } from '@/lib/toast'
import { dialogs } from '@/lib/dialogs'

type Grant = { id: string; role_id: string; slug: string; name_ar: string; name_en: string; scope_type: string; scope_label_ar: string; scope_label_en: string; expires_at: string | null; expired: boolean }
type Role = { id: string; slug: string; name_ar: string; name_en: string; scope_levels: string[] | null }
const SCOPES = ['ministry', 'school_group', 'school', 'department']

/** The roles of one person: each granted at a scope (Ministry / school group / school / department), optionally until a date. */
export default function UserRolesDialog({ user, onClose, onChanged }: { user: { id: string; name: string } | null; onClose: () => void; onChanged: () => void }) {
  const { t, i18n } = useTranslation()
  const ar = i18n.language === 'ar'
  const grants = useGet<{ data: Grant[] }>(user ? `/admin/users/${user.id}/roles` : null, undefined, { staleTime: 0 })
  const roles = useGet<{ data: Role[] }>(user ? '/admin/roles' : null, undefined, { staleTime: 60_000 })
  const groups = useGet<{ data: { id: string; name_ar: string; name_en: string }[] }>(user ? '/admin/school-groups' : null, { per_page: 100 }, { retry: false })
  const lookups = useGet<{ data: { departments: { id: string; name_ar: string; name_en: string }[] } }>(user ? '/admin/lookups' : null, undefined, { staleTime: 60_000 })
  const [form, setForm] = useState({ role_id: '', scope_type: 'ministry', scope_id: '', expires_at: '' })
  const [busy, setBusy] = useState(false)
  const [error, setError] = useState<string | null>(null)

  const role = roles.data?.data.find((r) => r.id === form.role_id)
  const allowed = role?.scope_levels ?? SCOPES
  const nm = (o: { name_ar: string; name_en: string }) => (ar ? o.name_ar : o.name_en)

  const pickRole = (id: string) => {
    const r = roles.data?.data.find((x) => x.id === id)
    const first = (r?.scope_levels ?? SCOPES)[0] ?? 'ministry'
    setForm({ ...form, role_id: id, scope_type: first, scope_id: '' })
  }
  const add = async () => {
    setBusy(true); setError(null)
    try {
      await api.post(`/admin/users/${user!.id}/roles`, { role_id: form.role_id, scope_type: form.scope_type, scope_id: form.scope_id || undefined, expires_at: form.expires_at || undefined })
      toast(t('userRoles.granted')); setForm({ role_id: '', scope_type: 'ministry', scope_id: '', expires_at: '' }); await grants.refetch(); onChanged()
    } catch (e) { setError(errorMessage(e)) } finally { setBusy(false) }
  }
  const revoke = async (g: Grant) => {
    if (!await dialogs.confirm(t('userRoles.confirmRevoke', { role: nm(g) }))) return
    try { await api.delete(`/admin/users/${user!.id}/roles/${g.id}`); toast(t('userRoles.revoked')); await grants.refetch(); onChanged() } catch (e) { toast(errorMessage(e), 'error') }
  }
  const needsScope = form.scope_type !== 'ministry'
  const ready = form.role_id && (!needsScope || form.scope_id)

  return (
    <Modal open={!!user} onClose={onClose} title={user ? t('userRoles.title', { name: user.name }) : ''} wide>
      <div className="space-y-5">
        {grants.isLoading ? <Spinner /> : (
          <ul className="space-y-2">
            {grants.data?.data.map((g) => (
              <li key={g.id} className={clsx('flex flex-wrap items-center gap-3 rounded-2xl border p-3', g.expired ? 'border-red-100 bg-red-50/50' : 'border-navy-100 bg-white')}>
                <div className="min-w-0 flex-1"><div className="font-bold text-navy-900">{nm(g)}</div><div className="text-xs text-slate-500">{t(`rolesAdmin.scope.${g.scope_type}`)} — {ar ? g.scope_label_ar : g.scope_label_en}</div></div>
                {g.expires_at && <Badge color={g.expired ? 'red' : 'gold'}>{g.expired ? t('userRoles.expired') : t('userRoles.until', { date: fmt.date(g.expires_at) })}</Badge>}
                <button type="button" aria-label={t('userRoles.revoke')} onClick={() => void revoke(g)} className="grid size-9 place-items-center rounded-xl text-slate-400 hover:bg-red-50 hover:text-danger"><Trash2 className="size-4" /></button>
              </li>
            ))}
          </ul>
        )}

        <div className="space-y-4 rounded-2xl bg-ivory p-4">
          <h4 className="text-sm font-bold text-navy-900">{t('userRoles.add')}</h4>
          <div className="grid gap-4 sm:grid-cols-2">
            <Field label={t('userRoles.role')}><select className="input" value={form.role_id} onChange={(e) => pickRole(e.target.value)}><option value="">—</option>{roles.data?.data.map((r) => <option key={r.id} value={r.id}>{nm(r)}</option>)}</select></Field>
            <Field label={t('userRoles.scope')}><select className="input" value={form.scope_type} disabled={!form.role_id} onChange={(e) => setForm({ ...form, scope_type: e.target.value, scope_id: '' })}>{allowed.map((s) => <option key={s} value={s}>{t(`rolesAdmin.scope.${s}`)}</option>)}</select></Field>
          </div>
          {form.scope_type === 'school' && <Field label={t('rolesAdmin.scope.school')}><SchoolPicker value={form.scope_id ? [form.scope_id] : []} onChange={(ids) => setForm({ ...form, scope_id: ids[0] ?? '' })} /></Field>}
          {form.scope_type === 'school_group' && <Field label={t('rolesAdmin.scope.school_group')}><select className="input" value={form.scope_id} onChange={(e) => setForm({ ...form, scope_id: e.target.value })}><option value="">—</option>{groups.data?.data.map((g) => <option key={g.id} value={g.id}>{nm(g)}</option>)}</select></Field>}
          {form.scope_type === 'department' && <Field label={t('rolesAdmin.scope.department')}><select className="input" value={form.scope_id} onChange={(e) => setForm({ ...form, scope_id: e.target.value })}><option value="">—</option>{lookups.data?.data.departments.map((d) => <option key={d.id} value={d.id}>{nm(d)}</option>)}</select></Field>}
          <Field label={t('userRoles.expires')} hint={t('userRoles.expiresHint')}><input type="date" className="input max-w-52" min={new Date(Date.now() + 86_400_000).toISOString().slice(0, 10)} value={form.expires_at} onChange={(e) => setForm({ ...form, expires_at: e.target.value })} /></Field>
          {error && <p className="rounded-xl bg-red-50 p-3 text-sm text-danger">{error}</p>}
          <Button variant="gold" icon={<Plus className="size-4" />} loading={busy} disabled={!ready} onClick={() => void add()}>{t('userRoles.grant')}</Button>
        </div>
      </div>
    </Modal>
  )
}
