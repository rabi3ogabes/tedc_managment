/* eslint-disable @typescript-eslint/no-explicit-any */
import { Archive, ArchiveRestore, CalendarClock, Globe, Megaphone, Pencil, Pin, PinOff, Plus, Repeat2, Search, Send, Upload, Users } from 'lucide-react'
import { useState } from 'react'
import { useTranslation } from 'react-i18next'
import { Badge, Button, Card, Empty, Field, Modal, Spinner } from '@/components/ui'
import { useGet } from '@/hooks/useApi'
import { api, errorMessage } from '@/lib/api'
import { useAuth } from '@/lib/auth'
import { fmt } from '@/lib/format'
import { toast } from '@/lib/toast'
import AnnouncementEditor from './AnnouncementEditor'
import MinistryDialog from './MinistryDialog'
import { fromLocalInput, input, statusColor } from './shared'

const dt = { dateStyle: 'medium', timeStyle: 'short' } as Intl.DateTimeFormatOptions

/** The announcement board: cards with status, window and pin; drag the pinned ones to reorder; archive with search; republish; Ministry export. */
export default function AnnouncementsBoard({ kind }: { kind: 'announcements' | 'events' }) {
  const { t, i18n } = useTranslation()
  const { can } = useAuth()
  const [q, setQ] = useState('')
  const [status, setStatus] = useState('')
  const [archive, setArchive] = useState(false)
  const types = kind === 'events' ? ['event', 'activity'] : ['news', 'announcement', 'circular']
  const [type, setType] = useState('')
  const url = archive ? '/admin/announcements/archive' : '/admin/announcements'
  const { data, isLoading, refetch } = useGet<{ data: any[] }>(url, { q: q || undefined, status: archive ? undefined : status || undefined, per_page: 60 }, { staleTime: 0 })
  const rows = (data?.data ?? []).filter((a) => (type ? a.type === type : types.includes(a.type)))
  const pinned = rows.filter((a) => a.is_pinned)
  const [edit, setEdit] = useState<{ row: any | null; type: string } | null>(null)
  const [rep, setRep] = useState<any | null>(null)
  const [repForm, setRepForm] = useState({ starts_at: '', ends_at: '' })
  const [rsvps, setRsvps] = useState<{ row: any; data: any } | null>(null)
  const [ministry, setMinistry] = useState(false)
  const [drag, setDrag] = useState<string | null>(null)

  const act = async (fn: () => Promise<any>, ok?: string) => { try { await fn(); if (ok) toast(ok); refetch() } catch (e) { toast(errorMessage(e), 'error') } }
  const reorder = async (overId: string) => {
    if (!drag || drag === overId) return
    const ids = pinned.map((a) => a.id)
    ids.splice(ids.indexOf(overId), 0, ids.splice(ids.indexOf(drag), 1)[0])
    setDrag(null)
    await act(() => api.put('/admin/announcements/pins/order', { ids }))
  }
  const openRsvps = async (a: any) => { const { data: d } = await api.get(`/admin/announcements/${a.id}/rsvps`); setRsvps({ row: a, data: d }) }
  const doRepublish = async () => {
    await act(async () => { await api.post(`/admin/announcements/${rep.id}/republish`, { starts_at: fromLocalInput(repForm.starts_at), ends_at: fromLocalInput(repForm.ends_at) }); setRep(null) }, String(t('comm.ann.republished')))
  }
  const title = (a: any) => (i18n.language === 'ar' ? a.title_ar : a.title_en)

  const card = (a: any, draggable = false) => (
    <Card key={a.id} className={drag === a.id ? 'opacity-50' : ''}>
      <div draggable={draggable} onDragStart={() => setDrag(a.id)} onDragEnd={() => setDrag(null)} onDragOver={(e) => draggable && e.preventDefault()} onDrop={() => draggable && reorder(a.id)} className={draggable ? 'cursor-grab' : ''}>
        <div className="flex flex-wrap items-center gap-2">
          <div className="grid size-10 place-items-center rounded-xl bg-navy-900 text-gold-300">{['event', 'activity'].includes(a.type) ? <CalendarClock className="size-5" /> : <Megaphone className="size-5" />}</div>
          <Badge color="navy">{t(`comm.ann.types.${a.type}`)}</Badge>
          <Badge color={statusColor(a.status)}>{t(`comm.status.${a.status}`)}</Badge>
          {a.is_public && <Badge color="gold"><Globe className="size-3" /></Badge>}
          {a.is_pinned && <Pin className="size-4 text-gold-600" />}
        </div>
        <h3 className="mt-3 font-bold text-navy-900">{title(a)}</h3>
        <p className="mt-1 line-clamp-2 text-sm text-slate-500">{i18n.language === 'ar' ? a.body_ar : a.body_en}</p>
        <div className="mt-3 flex flex-wrap gap-1.5 text-xs text-slate-500">
          {a.starts_at && <span className="rounded-full bg-ivory px-2 py-0.5">{t('comm.ann.from')}: {fmt.date(a.starts_at, dt)}</span>}
          {a.ends_at && <span className="rounded-full bg-ivory px-2 py-0.5">{t('comm.ann.to')}: {fmt.date(a.ends_at, dt)}</span>}
          {a.event?.starts_at && <span className="rounded-full bg-gold-100 px-2 py-0.5">{fmt.date(a.event.starts_at, dt)}</span>}
          {a.export_to_ministry && <span className="rounded-full bg-sky-50 px-2 py-0.5 text-sky-700">{a.exported_at ? t('comm.ann.exported') : t('comm.ann.exportMinistry')}</span>}
        </div>
        <div className="mt-4 flex flex-wrap gap-1.5">
          {a.status !== 'archived' && a.status !== 'published' && <Button size="sm" variant="gold" icon={<Send className="size-3" />} onClick={() => act(async () => { const { data: d } = await api.post(`/admin/announcements/${a.id}/publish`); toast(String(d.data.status === 'scheduled' ? t('comm.ann.scheduledOk') : t('comm.ann.published', { n: d.meta.recipients }))) })}>{t('comm.ann.publish')}</Button>}
          <Button size="sm" variant="outline" icon={<Pencil className="size-3" />} onClick={() => setEdit({ row: a, type: a.type })}>{t('comm.ann.edit')}</Button>
          {a.status === 'published' && <Button size="sm" variant="outline" icon={a.is_pinned ? <PinOff className="size-3" /> : <Pin className="size-3" />} onClick={() => act(() => api.post(`/admin/announcements/${a.id}/pin`, { pinned: !a.is_pinned }))}>{a.is_pinned ? t('comm.ann.unpin') : t('comm.ann.pin')}</Button>}
          {a.status === 'archived' ? <Button size="sm" variant="outline" icon={<ArchiveRestore className="size-3" />} onClick={() => act(() => api.post(`/admin/announcements/${a.id}/unarchive`))}>{t('comm.ann.unarchive')}</Button>
            : <Button size="sm" variant="outline" icon={<Archive className="size-3" />} onClick={() => act(() => api.post(`/admin/announcements/${a.id}/archive`))}>{t('comm.ann.archive')}</Button>}
          {['published', 'expired', 'archived'].includes(a.status) && <Button size="sm" variant="outline" icon={<Repeat2 className="size-3" />} onClick={() => { setRep(a); setRepForm({ starts_at: '', ends_at: '' }) }}>{t('comm.ann.republish')}</Button>}
          {a.status === 'published' && a.export_to_ministry && can('ministry_feed.manage') && <Button size="sm" variant="outline" icon={<Upload className="size-3" />} onClick={() => act(() => api.post(`/admin/announcements/${a.id}/export-ministry`), String(t('comm.ann.exportQueued')))}>{t('comm.ann.exportNow')}</Button>}
          {a.event?.rsvp && <Button size="sm" variant="outline" icon={<Users className="size-3" />} onClick={() => openRsvps(a)}>{t('comm.ann.event.rsvps')}{typeof a.going_count === 'number' ? ` (${a.going_count})` : ''}</Button>}
        </div>
      </div>
    </Card>
  )

  return (
    <>
      <div className="mb-4 flex flex-wrap items-center gap-2">
        <div className="relative"><Search className="pointer-events-none absolute start-3 top-2.5 size-4 text-slate-400" /><input className={`${input} ps-9`} placeholder={String(t('comm.ann.search'))} value={q} onChange={(e) => setQ(e.target.value)} /></div>
        <select className={`${input} w-auto`} value={type} onChange={(e) => setType(e.target.value)}><option value="">{t('admin.communication.type')}</option>{types.map((x) => <option key={x} value={x}>{t(`comm.ann.types.${x}`)}</option>)}</select>
        {!archive && <select className={`${input} w-auto`} value={status} onChange={(e) => setStatus(e.target.value)}><option value="">{t('comm.ann.states.all')}</option>{['draft', 'scheduled', 'published', 'expired'].map((s) => <option key={s} value={s}>{t(`comm.status.${s}`)}</option>)}</select>}
        <label className="flex items-center gap-2 text-sm"><input type="checkbox" className="accent-gold-600" checked={archive} onChange={(e) => setArchive(e.target.checked)} />{t('comm.ann.archiveToggle')}</label>
        <div className="ms-auto flex gap-2">
          {can('ministry_feed.manage') && <Button variant="outline" icon={<Upload className="size-4" />} onClick={() => setMinistry(true)}>{t('comm.ministry.title')}</Button>}
          <Button variant="gold" icon={<Plus className="size-4" />} onClick={() => setEdit({ row: null, type: kind === 'events' ? 'event' : 'announcement' })}>{kind === 'events' ? t('comm.ann.newEvent') : t('comm.ann.new')}</Button>
        </div>
      </div>

      {isLoading ? <Spinner /> : !rows.length ? <Card><Empty text={String(archive ? t('comm.ann.archiveEmpty') : t('comm.ann.empty'))} /></Card> : (
        <div className="space-y-6">
          {!archive && !!pinned.length && (
            <section>
              <h3 className="mb-1 flex items-center gap-2 text-sm font-bold text-navy-900"><Pin className="size-4 text-gold-600" />{t('comm.ann.pinned')}</h3>
              <p className="mb-3 text-xs text-slate-400">{t('comm.ann.reorderHint')}</p>
              <div className="grid gap-4 md:grid-cols-2 xl:grid-cols-3">{pinned.map((a) => card(a, true))}</div>
            </section>
          )}
          <div className="grid gap-4 md:grid-cols-2 xl:grid-cols-3">{rows.filter((a) => archive || !a.is_pinned).map((a) => card(a))}</div>
        </div>
      )}

      {edit && <AnnouncementEditor row={edit.row} type={edit.type} onClose={() => setEdit(null)} onSaved={() => refetch()} />}
      {ministry && <MinistryDialog onClose={() => setMinistry(false)} />}

      <Modal open={!!rep} onClose={() => setRep(null)} title={t('comm.ann.republishTitle')}>
        <p className="mb-3 text-sm text-slate-500">{t('comm.ann.republishHint')}</p>
        <div className="grid gap-3 sm:grid-cols-2">
          <Field label={t('comm.ann.from')}><input type="datetime-local" className={input} value={repForm.starts_at} onChange={(e) => setRepForm({ ...repForm, starts_at: e.target.value })} /></Field>
          <Field label={t('comm.ann.to')}><input type="datetime-local" className={input} value={repForm.ends_at} onChange={(e) => setRepForm({ ...repForm, ends_at: e.target.value })} /></Field>
        </div>
        <div className="mt-5 flex justify-end gap-2"><Button variant="outline" onClick={() => setRep(null)}>{t('common.cancel')}</Button><Button variant="gold" onClick={doRepublish}>{t('comm.ann.republish')}</Button></div>
      </Modal>

      <Modal open={!!rsvps} onClose={() => setRsvps(null)} title={rsvps ? title(rsvps.row) : ''}>
        {rsvps && (
          <>
            <p className="mb-3 text-sm text-slate-500">{t('comm.ann.event.going')}: {rsvps.data.meta.going}{rsvps.data.meta.capacity ? ` / ${rsvps.data.meta.capacity}` : ''} · {t('comm.ann.event.waiting')}: {rsvps.data.meta.waitlisted}</p>
            <ul className="max-h-80 divide-y divide-navy-50 overflow-y-auto text-sm">{rsvps.data.data.map((r: any) => <li key={r.id} className="flex items-center justify-between py-2"><span>{r.name}<span className="ms-2 text-xs text-slate-400">{r.school}</span></span><Badge color={r.status === 'going' ? 'green' : 'gold'}>{r.status === 'going' ? t('comm.ann.event.going') : t('comm.ann.event.waiting')}</Badge></li>)}</ul>
          </>
        )}
      </Modal>
    </>
  )
}
