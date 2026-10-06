/* eslint-disable @typescript-eslint/no-explicit-any */
import { CalendarPlus, Search } from 'lucide-react'
import { useState } from 'react'
import { useTranslation } from 'react-i18next'
import { Badge, Button, Card, Empty, Field, Modal, Spinner, Tabs } from '@/components/ui'
import { useGet } from '@/hooks/useApi'
import { api, errorMessage } from '@/lib/api'
import { fmt } from '@/lib/format'
import { toast } from '@/lib/toast'
import Composer from './Composer'
import PostCard from './PostCard'

type Tab = 'feed' | 'events' | 'members' | 'moderation'

/** The inside of a space: feed with composer, events, members and the moderators' queue. Used for communities, forums and lesson threads. */
export default function SpacePanel({ space, onChanged, compact = false }: { space: any; onChanged: () => void; compact?: boolean }) {
  const { t } = useTranslation()
  const [tab, setTab] = useState<Tab>('feed')
  const tabs: { id: Tab; label: string }[] = [{ id: 'feed', label: t('soc.space.feed') }, ...(compact ? [] : [{ id: 'events' as Tab, label: t('soc.space.events') }, { id: 'members' as Tab, label: t('soc.space.members') }]), ...(space.can_moderate ? [{ id: 'moderation' as Tab, label: t('soc.space.moderation') }] : [])]
  return (
    <>
      {tabs.length > 1 && <Tabs<Tab> value={tab} onChange={setTab} tabs={tabs} />}
      {tab === 'feed' && <Feed space={space} />}
      {tab === 'events' && <Events space={space} />}
      {tab === 'members' && <Members space={space} onChanged={onChanged} />}
      {tab === 'moderation' && <Moderation space={space} />}
    </>
  )
}

function Feed({ space }: { space: any }) {
  const { t } = useTranslation()
  const [q, setQ] = useState('')
  const [page, setPage] = useState(1)
  const res = useGet<{ data: any[]; meta: { last_page: number } }>(`/social/spaces/${space.id}/posts`, { q: q || undefined, page }, { staleTime: 0 })
  const focus = new URLSearchParams(window.location.search).get('post')
  return (
    <div>
      {space.can_post && !space.archived && <Composer spaceId={space.id} canModerate={space.can_moderate} allowPolls={space.settings?.allow_polls !== false} onPosted={() => { setPage(1); res.refetch() }} />}
      <div className="relative mb-4">
        <Search className="pointer-events-none absolute start-3 top-1/2 size-4 -translate-y-1/2 text-slate-400" />
        <input className="input ps-9" value={q} onChange={(e) => { setQ(e.target.value); setPage(1) }} placeholder={String(t('soc.space.search'))} />
      </div>
      {res.isLoading ? <Spinner /> : (res.data?.data.length ?? 0) === 0 ? <Empty text={String(t('soc.space.noPosts'))} /> : (
        <div className="space-y-4">
          {res.data!.data.map((p) => <PostCard key={p.id} post={p} canModerate={space.can_moderate} onChanged={() => res.refetch()} startOpen={focus === p.id} />)}
          {(res.data?.meta.last_page ?? 1) > 1 && (
            <div className="flex justify-center gap-2">
              <Button size="sm" variant="outline" disabled={page <= 1} onClick={() => setPage(page - 1)}>‹</Button>
              <span className="self-center text-sm text-slate-500">{page} / {res.data!.meta.last_page}</span>
              <Button size="sm" variant="outline" disabled={page >= res.data!.meta.last_page} onClick={() => setPage(page + 1)}>›</Button>
            </div>
          )}
        </div>
      )}
    </div>
  )
}

function Events({ space }: { space: any }) {
  const { t } = useTranslation()
  const res = useGet<{ data: any[] }>(`/social/spaces/${space.id}/events`, undefined, { staleTime: 0 })
  const [open, setOpen] = useState(false)
  const rsvp = async (id: string, status: string) => {
    try { await api.post(`/social/space-events/${id}/rsvp`, { status }); res.refetch() } catch (e) { toast(errorMessage(e), 'error') }
  }
  return (
    <div className="space-y-4">
      {space.can_moderate && <div className="flex justify-end"><Button variant="gold" icon={<CalendarPlus className="size-4" />} onClick={() => setOpen(true)}>{t('soc.event.new')}</Button></div>}
      {res.isLoading ? <Spinner /> : (res.data?.data.length ?? 0) === 0 ? <Empty text={String(t('soc.space.noEvents'))} /> : res.data!.data.map((e) => (
        <Card key={e.id}>
          <div className="flex flex-wrap items-start justify-between gap-3">
            <div>
              <h3 className="font-display text-lg font-bold text-navy-900">{e.title}</h3>
              <p className="text-sm text-slate-500">{fmt.dateTime(e.starts_at)}{e.ends_at && ` — ${fmt.dateTime(e.ends_at)}`}{e.location && ` · ${e.location}`}</p>
              {e.agenda && <p className="mt-2 whitespace-pre-line text-sm text-ink">{e.agenda}</p>}
              {e.online_url && <a href={e.online_url} target="_blank" rel="noopener noreferrer" className="mt-2 inline-block text-sm font-semibold text-gold-700 underline">{t('soc.event.online')}</a>}
            </div>
            <div className="text-end">
              <div className="mb-2 text-xs text-slate-500">{t('soc.event.goingCount', { n: e.going })}{e.rsvp_required && <Badge color="amber" className="ms-2">{t('soc.event.required')}</Badge>}</div>
              <div className="flex gap-1.5">{(['going', 'maybe', 'no'] as const).map((s) => <Button key={s} size="sm" variant={e.my_rsvp === s ? 'primary' : 'outline'} onClick={() => rsvp(e.id, s)}>{t(`soc.event.${s}`)}</Button>)}</div>
            </div>
          </div>
        </Card>
      ))}
      <EventDialog open={open} spaceId={space.id} onClose={() => setOpen(false)} onDone={() => { setOpen(false); res.refetch() }} />
    </div>
  )
}

function EventDialog({ open, spaceId, onClose, onDone }: { open: boolean; spaceId: string; onClose: () => void; onDone: () => void }) {
  const { t } = useTranslation()
  const [f, setF] = useState<any>({ title: '', starts_at: '', ends_at: '', location: '', online_url: '', agenda: '', rsvp_required: false })
  const [busy, setBusy] = useState(false)
  const set = (k: string, v: any) => setF((x: any) => ({ ...x, [k]: v }))
  const submit = async () => {
    setBusy(true)
    try {
      await api.post(`/social/spaces/${spaceId}/events`, { ...f, starts_at: new Date(f.starts_at).toISOString(), ends_at: f.ends_at ? new Date(f.ends_at).toISOString() : null, location: f.location || null, online_url: f.online_url || null, agenda: f.agenda || null })
      toast(String(t('soc.event.created'))); onDone()
    } catch (e) { toast(errorMessage(e), 'error') } finally { setBusy(false) }
  }
  return (
    <Modal open={open} onClose={onClose} title={t('soc.event.new')} wide>
      <div className="grid gap-4 sm:grid-cols-2">
        <Field label={t('soc.event.title')} className="sm:col-span-2"><input className="input" value={f.title} onChange={(e) => set('title', e.target.value)} maxLength={250} /></Field>
        <Field label={t('soc.event.starts')}><input type="datetime-local" className="input" value={f.starts_at} onChange={(e) => set('starts_at', e.target.value)} /></Field>
        <Field label={t('soc.event.ends')}><input type="datetime-local" className="input" value={f.ends_at} onChange={(e) => set('ends_at', e.target.value)} /></Field>
        <Field label={t('soc.event.location')}><input className="input" value={f.location} onChange={(e) => set('location', e.target.value)} /></Field>
        <Field label={t('soc.event.online')}><input className="input" dir="ltr" placeholder="https://" value={f.online_url} onChange={(e) => set('online_url', e.target.value)} /></Field>
        <Field label={t('soc.event.agenda')} className="sm:col-span-2"><textarea className="input min-h-20" value={f.agenda} onChange={(e) => set('agenda', e.target.value)} /></Field>
        <label className="flex items-center gap-2 text-sm"><input type="checkbox" className="accent-gold-600" checked={f.rsvp_required} onChange={(e) => set('rsvp_required', e.target.checked)} />{t('soc.event.required')}</label>
      </div>
      <div className="mt-6 flex justify-end gap-2"><Button variant="ghost" onClick={onClose}>{t('soc.common.cancel')}</Button><Button variant="gold" loading={busy} disabled={!f.title.trim() || !f.starts_at} onClick={submit}>{t('soc.event.create')}</Button></div>
    </Modal>
  )
}

function Members({ space, onChanged }: { space: any; onChanged: () => void }) {
  const { t } = useTranslation()
  const res = useGet<{ data: any[] }>(`/social/spaces/${space.id}/members`, undefined, { staleTime: 0 })
  const update = async (uid: string, body: any) => {
    try { await api.put(`/social/spaces/${space.id}/members/${uid}`, body); res.refetch(); onChanged() } catch (e) { toast(errorMessage(e), 'error') }
  }
  const remove = async (uid: string) => {
    try { await api.delete(`/social/spaces/${space.id}/members/${uid}`); res.refetch(); onChanged() } catch (e) { toast(errorMessage(e), 'error') }
  }
  if (res.isLoading) return <Spinner />
  return (
    <Card padded={false}>
      {space.type !== 'community' && <p className="border-b border-navy-50 px-5 py-3 text-sm text-slate-500">{t('soc.space.autoNote')}</p>}
      {(res.data?.data.length ?? 0) === 0 ? <Empty text={String(t('soc.space.noMembers'))} /> : (
        <ul className="divide-y divide-navy-50">
          {res.data!.data.map((m) => (
            <li key={m.user_id} className="flex flex-wrap items-center justify-between gap-2 px-5 py-3 text-sm">
              <div><span className="font-semibold text-navy-900">{m.name}</span><span className="ms-2 text-xs text-slate-400">{t(`soc.space.role.${m.role}`)}</span>{m.status !== 'active' && <Badge color={m.status === 'banned' ? 'red' : 'amber'} className="ms-2">{t(`soc.space.status.${m.status}`)}</Badge>}</div>
              {space.can_manage && m.role !== 'owner' && (
                <div className="flex flex-wrap gap-1.5">
                  {m.status === 'pending' && <Button size="sm" variant="gold" onClick={() => update(m.user_id, { status: 'active' })}>{t('soc.space.approve')}</Button>}
                  {m.status === 'active' && m.role === 'member' && <Button size="sm" variant="outline" onClick={() => update(m.user_id, { role: 'moderator' })}>{t('soc.space.makeMod')}</Button>}
                  {m.status === 'active' && m.role === 'moderator' && <Button size="sm" variant="outline" onClick={() => update(m.user_id, { role: 'member' })}>{t('soc.space.makeMember')}</Button>}
                  {m.status !== 'banned' ? <Button size="sm" variant="ghost" onClick={() => update(m.user_id, { status: 'banned' })}>{t('soc.space.ban')}</Button> : <Button size="sm" variant="ghost" onClick={() => update(m.user_id, { status: 'active' })}>{t('soc.space.unban')}</Button>}
                  {space.type === 'community' && <Button size="sm" variant="ghost" onClick={() => remove(m.user_id)}>{t('soc.space.remove')}</Button>}
                </div>
              )}
            </li>
          ))}
        </ul>
      )}
    </Card>
  )
}

function Moderation({ space }: { space: any }) {
  const { t } = useTranslation()
  const res = useGet<{ data: any[] }>(`/social/spaces/${space.id}/reports`, undefined, { staleTime: 0 })
  const act = async (id: string, action: string) => {
    try { await api.post(`/social/reports/${id}/resolve`, { action }); toast(String(t('soc.mod.done'))); res.refetch() } catch (e) { toast(errorMessage(e), 'error') }
  }
  if (res.isLoading) return <Spinner />
  return (
    <div className="space-y-3">
      <h3 className="font-display text-lg font-bold text-navy-900">{t('soc.mod.queue')}</h3>
      {(res.data?.data.length ?? 0) === 0 ? <Empty text={String(t('soc.mod.empty'))} /> : res.data!.data.map((r) => (
        <Card key={r.id}>
          <div className="flex flex-wrap items-center gap-2 text-xs"><Badge color="red">{t(`soc.post.reportReasons.${r.reason}`, { defaultValue: r.reason })}</Badge><span className="text-slate-500">{t('soc.mod.reporter')}: {r.reporter}</span><span className="text-slate-400">{fmt.dateTime(r.created_at)}</span></div>
          <p className="mt-2 rounded-lg bg-navy-50/60 p-3 text-sm text-ink">{r.excerpt || '—'}</p>
          {r.note && <p className="mt-2 text-sm text-slate-500">{r.note}</p>}
          <div className="mt-3 flex flex-wrap gap-2">
            <Button size="sm" variant="outline" onClick={() => act(r.id, 'hide')}>{t('soc.mod.hide')}</Button>
            <Button size="sm" variant="outline" onClick={() => act(r.id, 'warn')}>{t('soc.mod.warn')}</Button>
            <Button size="sm" variant="danger" onClick={() => act(r.id, 'ban')}>{t('soc.mod.ban')}</Button>
            <Button size="sm" variant="ghost" onClick={() => act(r.id, 'dismiss')}>{t('soc.mod.dismiss')}</Button>
          </div>
        </Card>
      ))}
    </div>
  )
}
