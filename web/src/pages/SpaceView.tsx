/* eslint-disable @typescript-eslint/no-explicit-any */
import { ArrowLeft, Lock, Settings2 } from 'lucide-react'
import { useState } from 'react'
import { useTranslation } from 'react-i18next'
import { Link, useLocation, useParams } from 'react-router-dom'
import { Badge, Button, ErrorState, Field, Modal, PageHeader, Spinner } from '@/components/ui'
import SpacePanel from '@/components/social/SpacePanel'
import { useGet } from '@/hooks/useApi'
import { api, errorMessage } from '@/lib/api'
import { toast } from '@/lib/toast'

/** One community, forum or channel. */
export default function SpaceView() {
  const { t, i18n } = useTranslation()
  const { id } = useParams()
  const base = useLocation().pathname.startsWith('/admin') ? '/admin' : '/portal'
  const res = useGet<{ data: any }>(`/social/spaces/${id}`, undefined, { staleTime: 0, retry: false })
  const [editing, setEditing] = useState(false)
  if (res.isLoading) return <Spinner />
  if (res.isError || !res.data) return <ErrorState message={errorMessage(res.error) || String(t('soc.space.notAllowed'))} onRetry={() => res.refetch()} />
  const s = res.data.data
  const en = i18n.language === 'en'
  const call = async (fn: () => Promise<unknown>, ok?: string) => { try { await fn(); if (ok) toast(ok); res.refetch() } catch (e) { toast(errorMessage(e), 'error') } }
  const notify = async (v: string) => call(() => api.patch(`/social/spaces/${s.id}/me`, { notify: v }))

  return (
    <>
      <Link to={`${base}/communities`} className="mb-3 inline-flex items-center gap-1 text-sm font-semibold text-slate-500 hover:text-navy-900"><ArrowLeft className="size-4 rtl:rotate-180" />{t('soc.space.back')}</Link>
      <PageHeader title={<span className="inline-flex items-center gap-2">{en ? s.title_en : s.title_ar}{s.visibility === 'private' && <Lock className="size-5 text-slate-400" />}</span>} subtitle={en ? s.description_en : s.description_ar}
        actions={<div className="flex flex-wrap items-center gap-2">
          <Badge color={s.type === 'community' ? 'gold' : 'navy'}>{t(`soc.hub.types.${s.type}`)}</Badge>
          {s.my_role && <select aria-label={String(t('soc.space.notify'))} className="input w-auto py-1.5 text-xs" value={s.my_notify ?? 'all'} onChange={(e) => notify(e.target.value)}>{['all', 'mentions', 'none'].map((v) => <option key={v} value={v}>{t('soc.space.notify')}: {t(`soc.space.notifies.${v}`)}</option>)}</select>}
          {!s.my_role && s.type === 'community' && s.my_status !== 'pending' && s.join_policy !== 'invite' && <Button variant="gold" onClick={() => call(() => api.post(`/social/spaces/${s.id}/join`), String(t(s.join_policy === 'request' ? 'soc.hub.requested' : 'soc.hub.joined')))}>{t(s.join_policy === 'request' ? 'soc.hub.request' : 'soc.hub.join')}</Button>}
          {s.my_role && s.type === 'community' && <Button variant="ghost" onClick={() => call(() => api.delete(`/social/spaces/${s.id}/leave`), String(t('soc.hub.left')))}>{t('soc.hub.leave')}</Button>}
          {s.can_manage && !s.archived && <Button variant="outline" icon={<Settings2 className="size-4" />} onClick={() => setEditing(true)}>{t('soc.space.settings')}</Button>}
          {s.can_manage && s.type === 'community' && !s.archived && <Button variant="outline" onClick={() => call(() => api.post(`/social/spaces/${s.id}/archive`))}>{t('soc.space.archive')}</Button>}
        </div>} />
      {s.archived && <p className="mb-4 rounded-xl bg-amber-50 px-4 py-3 text-sm font-semibold text-amber-800">{t('soc.space.archived')}</p>}
      {editing && <SpaceSettings space={s} onClose={() => setEditing(false)} onSaved={() => { setEditing(false); res.refetch() }} />}
      {s.can_read ? <SpacePanel space={s} onChanged={() => res.refetch()} /> : <p className="rounded-xl bg-navy-50 px-4 py-6 text-center text-slate-500">{t(s.my_status === 'pending' ? 'soc.hub.pending' : 'soc.space.notAllowed')}</p>}
    </>
  )
}

/** What a space allows: polls, files (and how many per post), review before posting, anonymous questions. */
function SpaceSettings({ space, onClose, onSaved }: { space: any; onClose: () => void; onSaved: () => void }) {
  const { t } = useTranslation()
  const [f, setF] = useState<any>({ allow_polls: true, allow_files: true, moderation: false, anonymous_qa: false, max_files: null, ...space.settings })
  const [busy, setBusy] = useState(false)
  const platformMax = space.file_limits?.files_per_post ?? 10
  const save = async () => {
    setBusy(true)
    try { await api.patch(`/social/spaces/${space.id}`, { settings: { ...f, max_files: f.max_files ? Number(f.max_files) : null } }); toast(String(t('soc.form.saved'))); onSaved() } catch (e) { toast(errorMessage(e), 'error') } finally { setBusy(false) }
  }
  return (
    <Modal open onClose={onClose} title={t('soc.space.settings')}>
      <div className="space-y-3">
        {(['allow_polls', 'allow_files', 'moderation', 'anonymous_qa'] as const).map((k) => (
          <label key={k} className="flex items-center gap-2 text-sm text-navy-900"><input type="checkbox" className="accent-gold-600" checked={!!f[k]} onChange={(e) => setF({ ...f, [k]: e.target.checked })} />{t(`soc.form.${{ allow_polls: 'polls', allow_files: 'files', moderation: 'moderation', anonymous_qa: 'anonymous' }[k]}`)}</label>
        ))}
        {f.allow_files && (
          <Field label={t('soc.files.spaceMax')} hint={t('soc.files.spaceMaxHint', { n: platformMax })}>
            <input type="number" min={1} max={20} className="input w-32" value={f.max_files ?? ''} onChange={(e) => setF({ ...f, max_files: e.target.value === '' ? null : Math.max(1, Math.min(20, Number(e.target.value))) })} />
          </Field>
        )}
      </div>
      <div className="mt-6 flex justify-end gap-2"><Button variant="ghost" onClick={onClose}>{t('soc.common.cancel')}</Button><Button variant="gold" loading={busy} onClick={save}>{t('soc.common.save')}</Button></div>
    </Modal>
  )
}
