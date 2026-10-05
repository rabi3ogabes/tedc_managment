import clsx from 'clsx'
import { Copy, Plus, Save, Search, ShieldCheck, Trash2 } from 'lucide-react'
import { useEffect, useMemo, useState } from 'react'
import { useTranslation } from 'react-i18next'
import { Badge, Button, Card, Empty, Field, Modal, PageHeader, Spinner } from '@/components/ui'
import { useGet } from '@/hooks/useApi'
import { api, errorMessage } from '@/lib/api'
import { useAuth } from '@/lib/auth'
import { toast } from '@/lib/toast'

type Role = { id: string; slug: string; name_ar: string; name_en: string; level: number; is_system: boolean; users_count: number; scope_levels: string[] | null; landing_route: string | null; permissions: { slug: string }[] }
type Perm = { slug: string; name_ar: string; name_en: string; group: string }
type Payload = { data: Role[]; permissions: Record<string, Perm[]> }
const SCOPES = ['ministry', 'school_group', 'school', 'department'] as const
const LANDINGS = ['/admin', '/admin/executive', '/admin/kits', '/admin/programs', '/admin/rooms', '/portal']

/** Settings → Roles & permissions: the roles of the RFP, custom roles (new or cloned), and the permission matrix with a diff before saving. */
export default function RolesPermissions() {
  const { t, i18n } = useTranslation()
  const { can } = useAuth()
  const ar = i18n.language === 'ar'
  const { data, isLoading, refetch } = useGet<Payload>('/admin/roles', undefined, { staleTime: 0 })
  const [selectedId, setSelectedId] = useState<string | null>(null)
  const [perms, setPerms] = useState<Set<string>>(new Set())
  const [filter, setFilter] = useState('')
  const [diff, setDiff] = useState(false)
  const [creating, setCreating] = useState(false)
  const [busy, setBusy] = useState(false)
  const nm = (o: { name_ar: string; name_en: string }) => (ar ? o.name_ar : o.name_en)

  const selected = data?.data.find((r) => r.id === selectedId) ?? null
  useEffect(() => { if (data && !selectedId) setSelectedId(data.data[0]?.id ?? null) }, [data, selectedId])
  useEffect(() => { if (selected) setPerms(new Set(selected.permissions.map((p) => p.slug))) }, [selected?.id, data]) // eslint-disable-line react-hooks/exhaustive-deps

  const original = useMemo(() => new Set(selected?.permissions.map((p) => p.slug) ?? []), [selected])
  const added = [...perms].filter((p) => !original.has(p))
  const removed = [...original].filter((p) => !perms.has(p))
  const dirty = added.length + removed.length > 0
  const all = useMemo(() => Object.values(data?.permissions ?? {}).flat(), [data])
  const label = (slug: string) => { const p = all.find((x) => x.slug === slug); return p ? nm(p) : slug }

  if (isLoading || !data) return <Spinner />
  const isSuper = selected?.slug === 'super_admin'
  const canEditMatrix = can('roles.manage') && !isSuper
  const term = filter.trim().toLowerCase()

  const toggleGroup = (list: Perm[], on: boolean) => setPerms((s) => { const n = new Set(s); list.forEach((p) => (on ? n.add(p.slug) : n.delete(p.slug))); return n })
  const save = async () => {
    setBusy(true)
    try { await api.put(`/admin/roles/${selected!.id}/permissions`, { permissions: [...perms] }); toast(t('rolesAdmin.saved')); setDiff(false); await refetch() }
    catch (e) { toast(errorMessage(e), 'error') } finally { setBusy(false) }
  }
  const remove = async () => {
    if (!selected || !window.confirm(t('rolesAdmin.confirmDelete', { name: nm(selected) }))) return
    try { await api.delete(`/admin/roles/${selected.id}`); setSelectedId(null); toast(t('rolesAdmin.deleted')); await refetch() } catch (e) { toast(errorMessage(e), 'error') }
  }

  return (
    <div className="space-y-6 pb-6">
      <PageHeader title={t('rolesAdmin.title')} subtitle={t('rolesAdmin.subtitle')} actions={can('roles.create') && <Button variant="gold" icon={<Plus className="size-4" />} onClick={() => setCreating(true)}>{t('rolesAdmin.newRole')}</Button>} />

      <div className="grid gap-6 lg:grid-cols-[22rem_1fr]">
        <div className="space-y-2">
          {data.data.map((r) => (
            <button key={r.id} type="button" onClick={() => { setSelectedId(r.id); setFilter('') }} aria-pressed={r.id === selectedId}
              className={clsx('flex w-full items-center gap-3 rounded-2xl border p-3.5 text-start transition', r.id === selectedId ? 'border-gold-400 bg-white shadow-md' : 'border-navy-100 bg-white hover:border-gold-300')}>
              <span className={clsx('grid size-10 shrink-0 place-items-center rounded-xl', r.is_system ? 'bg-navy-900 text-gold-300' : 'bg-gold-100 text-gold-700')}><ShieldCheck className="size-5" /></span>
              <span className="min-w-0 flex-1">
                <span className="block truncate font-bold text-navy-900">{nm(r)}</span>
                <span className="block truncate text-xs text-slate-500">{r.users_count} {t('rolesAdmin.users')} · {r.slug === 'super_admin' ? '∞' : r.permissions.length} {t('rolesAdmin.permissions')}</span>
              </span>
              {!r.is_system && <Badge color="gold">{t('rolesAdmin.custom')}</Badge>}
            </button>
          ))}
        </div>

        <Card className="space-y-5">
          {!selected ? <Empty text={t('rolesAdmin.pick')} /> : (
            <>
              <div className="flex flex-wrap items-center gap-3">
                <div className="min-w-0 flex-1">
                  <h2 className="truncate text-lg font-bold text-navy-900">{nm(selected)}</h2>
                  <p className="text-xs text-slate-500" dir="ltr">{selected.slug} · {t('rolesAdmin.scopes')}: {(selected.scope_levels ?? SCOPES).map((s) => t(`rolesAdmin.scope.${s}`)).join(' / ')}</p>
                </div>
                {can('roles.create') && !selected.is_system && selected.users_count === 0 && <Button variant="outline" icon={<Trash2 className="size-4" />} onClick={() => void remove()}>{t('rolesAdmin.delete')}</Button>}
                {canEditMatrix && <Button variant="gold" icon={<Save className="size-4" />} disabled={!dirty} onClick={() => setDiff(true)}>{t('rolesAdmin.review')}{dirty ? ` (${added.length + removed.length})` : ''}</Button>}
              </div>
              {isSuper && <p className="rounded-xl bg-ivory px-3 py-2 text-xs text-slate-600">{t('rolesAdmin.superNote')}</p>}
              {selected.is_system && !isSuper && <p className="rounded-xl bg-ivory px-3 py-2 text-xs text-slate-600">{t('rolesAdmin.systemNote')}</p>}

              <div className="relative"><Search className="pointer-events-none absolute start-3 top-1/2 size-4 -translate-y-1/2 text-slate-400" /><input className="input ps-9" placeholder={t('rolesAdmin.search')} value={filter} onChange={(e) => setFilter(e.target.value)} /></div>

              <div className="grid gap-4 sm:grid-cols-2">
                {Object.entries(data.permissions).map(([group, list]) => {
                  const shown = list.filter((p) => !term || `${p.slug} ${p.name_ar} ${p.name_en}`.toLowerCase().includes(term))
                  if (!shown.length) return null
                  const on = isSuper ? list.length : list.filter((p) => perms.has(p.slug)).length
                  return (
                    <fieldset key={group} className="rounded-2xl border border-navy-100 p-3.5">
                      <legend className="px-1 text-xs font-bold text-gold-700">{t(`rolesAdmin.groups.${group}`, { defaultValue: group })}</legend>
                      <label className="mb-1 flex items-center gap-2 border-b border-navy-50 pb-2 text-xs font-bold text-slate-500">
                        <input type="checkbox" className="accent-gold-600" disabled={!canEditMatrix} checked={on === list.length} ref={(el) => { if (el) el.indeterminate = on > 0 && on < list.length }}
                          onChange={(e) => toggleGroup(list, e.target.checked)} />{t('rolesAdmin.selectAll')} ({on}/{list.length})
                      </label>
                      {shown.map((p) => (
                        <label key={p.slug} className="flex items-center gap-2 py-1 text-sm">
                          <input type="checkbox" className="accent-gold-600" disabled={!canEditMatrix} checked={isSuper || perms.has(p.slug)}
                            onChange={(e) => setPerms((s) => { const n = new Set(s); if (e.target.checked) n.add(p.slug); else n.delete(p.slug); return n })} />
                          <span className="min-w-0 flex-1 truncate">{nm(p)}</span><span className="hidden font-mono text-[10px] text-slate-300 sm:inline" dir="ltr">{p.slug}</span>
                        </label>
                      ))}
                    </fieldset>
                  )
                })}
              </div>
            </>
          )}
        </Card>
      </div>

      <Modal open={diff} onClose={() => setDiff(false)} title={t('rolesAdmin.diffTitle')}>
        <p className="mb-4 text-sm text-slate-600">{selected && t('rolesAdmin.diffIntro', { name: nm(selected) })}</p>
        <div className="grid gap-4 sm:grid-cols-2">
          <div><div className="mb-1 text-xs font-bold text-emerald-700">+ {t('rolesAdmin.added')} ({added.length})</div><ul className="space-y-1 text-sm">{added.map((p) => <li key={p} className="rounded-lg bg-emerald-50 px-2 py-1">{label(p)}</li>)}{!added.length && <li className="text-xs text-slate-400">—</li>}</ul></div>
          <div><div className="mb-1 text-xs font-bold text-danger">− {t('rolesAdmin.removed')} ({removed.length})</div><ul className="space-y-1 text-sm">{removed.map((p) => <li key={p} className="rounded-lg bg-red-50 px-2 py-1">{label(p)}</li>)}{!removed.length && <li className="text-xs text-slate-400">—</li>}</ul></div>
        </div>
        <div className="mt-5 flex gap-2"><Button variant="gold" loading={busy} onClick={() => void save()}>{t('rolesAdmin.confirmSave')}</Button><Button variant="outline" onClick={() => setDiff(false)}>{t('rolesAdmin.cancel')}</Button></div>
      </Modal>

      <NewRoleDialog open={creating} roles={data.data} onClose={() => setCreating(false)} onCreated={async (id) => { setCreating(false); await refetch(); setSelectedId(id); toast(t('rolesAdmin.created')) }} />
    </div>
  )
}

function NewRoleDialog({ open, roles, onClose, onCreated }: { open: boolean; roles: Role[]; onClose: () => void; onCreated: (id: string) => void }) {
  const { t, i18n } = useTranslation()
  const ar = i18n.language === 'ar'
  const [form, setForm] = useState({ name_ar: '', name_en: '', clone_from: '', scope_levels: [...SCOPES] as string[], landing_route: '/portal' })
  const [busy, setBusy] = useState(false)
  const [error, setError] = useState<string | null>(null)
  const submit = async () => {
    setBusy(true); setError(null)
    try {
      const { data } = await api.post('/admin/roles', { name_ar: form.name_ar, name_en: form.name_en, clone_from: form.clone_from || undefined, scope_levels: form.scope_levels, landing_route: form.landing_route })
      onCreated(data.data.id)
    } catch (e) { setError(errorMessage(e)) } finally { setBusy(false) }
  }
  return (
    <Modal open={open} onClose={onClose} title={t('rolesAdmin.newRole')}>
      <div className="space-y-4">
        <div className="grid gap-4 sm:grid-cols-2">
          <Field label={t('rolesAdmin.nameAr')}><input className="input" value={form.name_ar} onChange={(e) => setForm({ ...form, name_ar: e.target.value })} /></Field>
          <Field label={t('rolesAdmin.nameEn')}><input className="input" dir="ltr" value={form.name_en} onChange={(e) => setForm({ ...form, name_en: e.target.value })} /></Field>
        </div>
        <Field label={t('rolesAdmin.cloneFrom')} hint={t('rolesAdmin.cloneHint')}>
          <select className="input" value={form.clone_from} onChange={(e) => setForm({ ...form, clone_from: e.target.value })}>
            <option value="">{t('rolesAdmin.fromScratch')}</option>
            {roles.filter((r) => r.slug !== 'super_admin').map((r) => <option key={r.id} value={r.id}>{ar ? r.name_ar : r.name_en}</option>)}
          </select>
        </Field>
        <Field label={t('rolesAdmin.scopes')}>
          <div className="flex flex-wrap gap-3">{SCOPES.map((s) => <label key={s} className="flex items-center gap-1.5 text-sm"><input type="checkbox" className="accent-gold-600" checked={form.scope_levels.includes(s)} onChange={(e) => setForm({ ...form, scope_levels: e.target.checked ? [...form.scope_levels, s] : form.scope_levels.filter((x) => x !== s) })} />{t(`rolesAdmin.scope.${s}`)}</label>)}</div>
        </Field>
        <Field label={t('rolesAdmin.landing')}><select className="input" value={form.landing_route} onChange={(e) => setForm({ ...form, landing_route: e.target.value })}>{LANDINGS.map((l) => <option key={l} value={l}>{l}</option>)}</select></Field>
        {error && <p className="rounded-xl bg-red-50 p-3 text-sm text-danger">{error}</p>}
        <div className="flex gap-2"><Button variant="gold" icon={form.clone_from ? <Copy className="size-4" /> : <Plus className="size-4" />} loading={busy} disabled={!form.name_ar.trim() || !form.name_en.trim() || !form.scope_levels.length} onClick={() => void submit()}>{form.clone_from ? t('rolesAdmin.clone') : t('rolesAdmin.create')}</Button><Button variant="outline" onClick={onClose}>{t('rolesAdmin.cancel')}</Button></div>
      </div>
    </Modal>
  )
}
