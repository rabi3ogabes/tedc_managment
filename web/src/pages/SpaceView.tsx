/* eslint-disable @typescript-eslint/no-explicit-any */
import { ArrowLeft, Lock } from 'lucide-react'
import { useTranslation } from 'react-i18next'
import { Link, useLocation, useParams } from 'react-router-dom'
import { Badge, Button, ErrorState, PageHeader, Spinner } from '@/components/ui'
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
          {s.can_manage && s.type === 'community' && !s.archived && <Button variant="outline" onClick={() => call(() => api.post(`/social/spaces/${s.id}/archive`))}>{t('soc.space.archive')}</Button>}
        </div>} />
      {s.archived && <p className="mb-4 rounded-xl bg-amber-50 px-4 py-3 text-sm font-semibold text-amber-800">{t('soc.space.archived')}</p>}
      {s.can_read ? <SpacePanel space={s} onChanged={() => res.refetch()} /> : <p className="rounded-xl bg-navy-50 px-4 py-6 text-center text-slate-500">{t(s.my_status === 'pending' ? 'soc.hub.pending' : 'soc.space.notAllowed')}</p>}
    </>
  )
}
