/* eslint-disable @typescript-eslint/no-explicit-any */
import { Trash2 } from 'lucide-react'
import { useState } from 'react'
import { useTranslation } from 'react-i18next'
import { Badge, Button, Field, Modal } from '@/components/ui'
import { useGet } from '@/hooks/useApi'
import { api, errorMessage } from '@/lib/api'
import { toast } from '@/lib/toast'

const input = 'w-full rounded-xl border border-navy-100 px-3 py-2 text-sm'

/** Shares a resource with a program, group, job group, role or person, with a permission and an expiry; the policy of the sharer's role decides what is allowed. */
export default function ShareDialog({ resourceType, resourceId, onClose }: { resourceType: string; resourceId: string; onClose: () => void }) {
  const { t, i18n } = useTranslation()
  const ar = i18n.language === 'ar'
  const [target, setTarget] = useState('program')
  const [targetId, setTargetId] = useState('')
  const [permission, setPermission] = useState('view')
  const [expires, setExpires] = useState('')
  const shares = useGet<{ data: any[]; policy: any }>('/admin/shares', { resource_type: resourceType, resource_id: resourceId }, { staleTime: 0 })
  const programs = useGet<{ data: any[] }>(target === 'program' ? '/admin/programs' : null, { per_page: 200 })
  const groups = useGet<{ data: any[] }>(target === 'job_group' ? '/admin/job-groups' : null)
  const roles = useGet<{ data: any[] }>(target === 'role' ? '/admin/roles' : null)
  const policy = shares.data?.policy
  const options: { id: string; label: string }[] = target === 'program' ? (programs.data?.data ?? []).map((p: any) => ({ id: p.id, label: `${p.code} — ${p.title}` })) : target === 'job_group' ? (groups.data?.data ?? []).map((g: any) => ({ id: g.id, label: ar ? g.name_ar : g.name_en })) : target === 'role' ? (roles.data?.data ?? []).map((r: any) => ({ id: r.slug, label: ar ? r.name_ar ?? r.slug : r.name_en ?? r.slug })) : []
  const go = async () => { try { await api.post('/admin/shares', { resource_type: resourceType, resource_id: resourceId, target_type: target, target_id: targetId, permission, expires_at: expires || null }); toast(t('content.sharing.done')); setTargetId(''); void shares.refetch() } catch (e) { toast(errorMessage(e), 'error') } }
  const del = async (id: string) => { try { await api.delete(`/admin/shares/${id}`); void shares.refetch() } catch (e) { toast(errorMessage(e), 'error') } }
  return (
    <Modal open onClose={onClose} title={t('content.sharing.title')}>
      <div className="space-y-3">
        <div className="grid gap-3 sm:grid-cols-2">
          <Field label={t('content.sharing.with')}><select className={input} value={target} onChange={(e) => { setTarget(e.target.value); setTargetId('') }}>{(policy?.target_types ?? ['program']).map((x: string) => <option key={x} value={x}>{t(`content.sharing.targets.${x}`)}</option>)}</select></Field>
          {options.length > 0 || target === 'program' || target === 'job_group' || target === 'role' ? <Field label=" "><select className={input} value={targetId} onChange={(e) => setTargetId(e.target.value)}><option value="">—</option>{options.map((o) => <option key={o.id} value={o.id}>{o.label}</option>)}</select></Field> : <Field label="ID"><input dir="ltr" className={input} value={targetId} onChange={(e) => setTargetId(e.target.value)} /></Field>}
          <Field label={t('content.sharing.permission')}><select className={input} value={permission} onChange={(e) => setPermission(e.target.value)}>{['view', 'download', ...(policy?.allow_reshare ? ['reshare'] : [])].map((x) => <option key={x} value={x}>{t(`content.sharing.permissions.${x}`)}</option>)}</select></Field>
          <Field label={t('content.sharing.expires')}><input className={input} type="date" value={expires} onChange={(e) => setExpires(e.target.value)} /></Field>
        </div>
        <Button variant="gold" disabled={!targetId} onClick={() => void go()}>{t('content.sharing.share')}</Button>
        <div className="space-y-1 border-t border-navy-50 pt-2">{(shares.data?.data ?? []).map((s) => <div key={s.id} className="flex items-center gap-2 text-sm"><Badge>{t(`content.sharing.targets.${s.target_type}`)}</Badge><span className="flex-1 truncate" dir="ltr">{s.target_id}</span><Badge color="gray">{t(`content.sharing.permissions.${s.permission}`)}</Badge><button type="button" aria-label="remove" className="text-danger" onClick={() => void del(s.id)}><Trash2 className="size-4" /></button></div>)}</div>
      </div>
    </Modal>
  )
}
