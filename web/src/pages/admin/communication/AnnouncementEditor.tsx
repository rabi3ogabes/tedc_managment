/* eslint-disable @typescript-eslint/no-explicit-any */
import { Headphones, Image as ImageIcon, Link2, Paperclip, Trash2, Video } from 'lucide-react'
import { useState } from 'react'
import { useTranslation } from 'react-i18next'
import { Button, Field, Modal } from '@/components/ui'
import { api, errorMessage } from '@/lib/api'
import { toast } from '@/lib/toast'
import { AudienceBuilder, fromLocalInput, input, toLocalInput } from './shared'

const KINDS = [['images', ImageIcon], ['video', Video], ['audio', Headphones], ['files', Paperclip], ['links', Link2]] as const
const ACCEPT: Record<string, string> = { images: 'image/*', video: 'video/*', audio: 'audio/*', files: '.pdf,.doc,.docx,.ppt,.pptx,.xls,.xlsx', links: '' }

const blank = (type: string) => ({
  type, title_ar: '', title_en: '', body_ar: '', body_en: '', audience: 'all', target_ids: [] as string[], audience_filter: {} as any, is_public: false, notify_push: true, notify_email: false, export_to_ministry: false,
  starts_at: '', ends_at: '', event: { starts_at: '', ends_at: '', venue_ar: '', venue_en: '', online_url: '', registration_url: '', capacity: '', rsvp: false, reminder_hours: 24 } as any,
})

function fromRow(a: any) {
  const e = a.event ?? {}
  return { ...blank(a.type), ...a, starts_at: toLocalInput(a.starts_at), ends_at: toLocalInput(a.ends_at), audience_filter: a.audience_filter ?? {}, target_ids: a.target_ids ?? [],
    event: { ...blank('event').event, ...e, starts_at: toLocalInput(e.starts_at), ends_at: toLocalInput(e.ends_at), capacity: e.capacity ?? '', reminder_hours: e.reminder_hours ?? 24 } }
}

/** Compose or edit an announcement, news item, circular, activity or event — with window, audience, media and Ministry export. */
export default function AnnouncementEditor({ row, type, onClose, onSaved }: { row: any | null; type: string; onClose: () => void; onSaved: () => void }) {
  const { t } = useTranslation()
  const [f, setF] = useState<any>(() => (row ? fromRow(row) : blank(type)))
  const [saved, setSaved] = useState<any | null>(row)
  const [busy, setBusy] = useState(false)
  const [error, setError] = useState<string | null>(null)
  const [media, setMedia] = useState({ kind: 'images', url: '', title: '' })
  const [file, setFile] = useState<File | null>(null)
  const isEvent = ['event', 'activity'].includes(f.type)

  const payload = () => {
    const body: any = { ...f, starts_at: fromLocalInput(f.starts_at), ends_at: fromLocalInput(f.ends_at), audience_filter: f.audience === 'filter' ? f.audience_filter : null }
    delete body.media; delete body.author; delete body.id; delete body.status; delete body.going_count
    body.event = isEvent ? { ...f.event, starts_at: fromLocalInput(f.event.starts_at), ends_at: fromLocalInput(f.event.ends_at), capacity: f.event.capacity === '' ? null : Number(f.event.capacity), reminder_hours: Number(f.event.reminder_hours) || 0 } : null
    return body
  }

  const save = async (publish: boolean) => {
    setBusy(true); setError(null)
    try {
      const body = payload()
      const res = saved ? await api.put(`/admin/announcements/${saved.id}`, body) : await api.post('/admin/announcements', { ...body, publish })
      const id = res.data.data.id
      if (saved && publish) {
        const p = await api.post(`/admin/announcements/${id}/publish`)
        toast(String(p.data.data.status === 'scheduled' ? t('comm.ann.scheduledOk') : t('comm.ann.published', { n: p.data.meta.recipients })))
      } else if (!saved && publish) {
        toast(String(res.data.data.status === 'scheduled' ? t('comm.ann.scheduledOk') : t('comm.ann.published', { n: res.data.meta.recipients })))
      } else toast(String(t('comm.ann.saved')))
      setSaved(res.data.data)
      onSaved()
      if (publish) onClose()
    } catch (e) { setError(errorMessage(e)) } finally { setBusy(false) }
  }

  const addMedia = async () => {
    if (!saved) return
    setBusy(true); setError(null)
    try {
      const body = new FormData()
      body.append('kind', media.kind)
      if (media.title) body.append('title', media.title)
      if (file) body.append('file', file); else body.append('url', media.url)
      const { data } = await api.post(`/admin/announcements/${saved.id}/media`, body)
      setSaved(data.data); setMedia({ ...media, url: '', title: '' }); setFile(null); onSaved()
    } catch (e) { setError(errorMessage(e)) } finally { setBusy(false) }
  }
  const removeMedia = async (kind: string, index: number) => {
    const { data } = await api.delete(`/admin/announcements/${saved.id}/media`, { data: { kind, index } })
    setSaved(data.data); onSaved()
  }
  const ev = (patch: any) => setF({ ...f, event: { ...f.event, ...patch } })

  return (
    <Modal open onClose={onClose} title={saved ? t('comm.ann.edit') : isEvent ? t('comm.ann.newEvent') : t('comm.ann.new')} wide>
      <div className="grid gap-3 md:grid-cols-2">
        <Field label={t('admin.communication.type')}><select className={input} value={f.type} onChange={(e) => setF({ ...f, type: e.target.value })}>{['announcement', 'news', 'circular', 'event', 'activity'].map((x) => <option key={x} value={x}>{t(`comm.ann.types.${x}`)}</option>)}</select></Field>
        <Field label={t('admin.communication.audience')}><select className={input} value={f.audience} onChange={(e) => setF({ ...f, audience: e.target.value })}>{['all', 'filter'].map((x) => <option key={x} value={x}>{t(`comm.ann.audiences.${x}`)}</option>)}{!['all', 'filter'].includes(f.audience) && <option value={f.audience}>{t(`comm.ann.audiences.${f.audience}`)}</option>}</select></Field>
        {f.audience === 'filter' && <div className="md:col-span-2"><AudienceBuilder value={f.audience_filter} onChange={(v) => setF({ ...f, audience_filter: v })} /></div>}
        <Field label={t('admin.programs.titleAr')}><input className={input} value={f.title_ar} onChange={(e) => setF({ ...f, title_ar: e.target.value })} /></Field>
        <Field label={t('admin.programs.titleEn')}><input className={input} dir="ltr" value={f.title_en} onChange={(e) => setF({ ...f, title_en: e.target.value })} /></Field>
        <Field label={t('comm.ann.bodyAr')}><textarea className={`${input} min-h-28`} value={f.body_ar ?? ''} onChange={(e) => setF({ ...f, body_ar: e.target.value })} /></Field>
        <Field label={t('comm.ann.bodyEn')}><textarea className={`${input} min-h-28`} dir="ltr" value={f.body_en ?? ''} onChange={(e) => setF({ ...f, body_en: e.target.value })} /></Field>
        <Field label={t('comm.ann.from')}><input type="datetime-local" className={input} value={f.starts_at} onChange={(e) => setF({ ...f, starts_at: e.target.value })} /></Field>
        <Field label={t('comm.ann.to')}><input type="datetime-local" className={input} value={f.ends_at} onChange={(e) => setF({ ...f, ends_at: e.target.value })} /></Field>

        {isEvent && (
          <fieldset className="grid gap-3 rounded-2xl border border-navy-100 p-4 md:col-span-2 md:grid-cols-2">
            <legend className="px-2 text-sm font-bold text-navy-900">{t('comm.ann.event.title')}</legend>
            <Field label={t('comm.ann.event.starts')}><input type="datetime-local" className={input} value={f.event.starts_at} onChange={(e) => ev({ starts_at: e.target.value })} /></Field>
            <Field label={t('comm.ann.event.ends')}><input type="datetime-local" className={input} value={f.event.ends_at} onChange={(e) => ev({ ends_at: e.target.value })} /></Field>
            <Field label={t('comm.ann.event.venueAr')}><input className={input} value={f.event.venue_ar ?? ''} onChange={(e) => ev({ venue_ar: e.target.value })} /></Field>
            <Field label={t('comm.ann.event.venueEn')}><input className={input} dir="ltr" value={f.event.venue_en ?? ''} onChange={(e) => ev({ venue_en: e.target.value })} /></Field>
            <Field label={t('comm.ann.event.online')}><input className={input} dir="ltr" placeholder="https://" value={f.event.online_url ?? ''} onChange={(e) => ev({ online_url: e.target.value })} /></Field>
            <Field label={t('comm.ann.event.registration')}><input className={input} dir="ltr" placeholder="https://" value={f.event.registration_url ?? ''} onChange={(e) => ev({ registration_url: e.target.value })} /></Field>
            <Field label={t('comm.ann.event.capacity')}><input type="number" min={1} className={input} value={f.event.capacity} onChange={(e) => ev({ capacity: e.target.value })} /></Field>
            <Field label={t('comm.ann.event.reminder')}><input type="number" min={0} max={336} className={input} value={f.event.reminder_hours} onChange={(e) => ev({ reminder_hours: e.target.value })} /></Field>
            <label className="flex items-center gap-2 text-sm md:col-span-2"><input type="checkbox" className="accent-gold-600" checked={!!f.event.rsvp} onChange={(e) => ev({ rsvp: e.target.checked })} />{t('comm.ann.event.rsvp')}</label>
          </fieldset>
        )}

        <fieldset className="rounded-2xl border border-navy-100 p-4 md:col-span-2">
          <legend className="px-2 text-sm font-bold text-navy-900">{t('comm.ann.media.title')}</legend>
          {!saved ? <p className="text-sm text-slate-500">{t('comm.ann.media.saveFirst')}</p> : (
            <div className="space-y-3">
              {KINDS.map(([k, Icon]) => !!saved.media?.[k]?.length && (
                <div key={k}>
                  <div className="mb-1 flex items-center gap-1.5 text-xs font-bold text-slate-500"><Icon className="size-3.5" />{t(`comm.ann.media.${k}`)}</div>
                  <ul className="space-y-1">{saved.media[k].map((m: any, i: number) => (
                    <li key={i} className="flex items-center justify-between rounded-lg bg-ivory px-3 py-1.5 text-sm"><span className="truncate">{m.title || m.url || m.path}</span><button className="text-slate-400 hover:text-danger" onClick={() => removeMedia(k, i)} aria-label={String(t('comm.ann.media.remove'))}><Trash2 className="size-4" /></button></li>
                  ))}</ul>
                </div>
              ))}
              <div className="flex flex-wrap items-end gap-2">
                <select className={`${input} w-auto`} value={media.kind} onChange={(e) => { setMedia({ ...media, kind: e.target.value }); setFile(null) }}>{KINDS.map(([k]) => <option key={k} value={k}>{t(`comm.ann.media.${k}`)}</option>)}</select>
                <input className={`${input} w-44`} placeholder={String(t('common.name'))} value={media.title} onChange={(e) => setMedia({ ...media, title: e.target.value })} />
                <input className={`${input} w-56`} dir="ltr" placeholder="https://" value={media.url} onChange={(e) => setMedia({ ...media, url: e.target.value })} disabled={!!file} />
                {media.kind !== 'links' && <input type="file" accept={ACCEPT[media.kind]} className="text-sm" onChange={(e) => setFile(e.target.files?.[0] ?? null)} />}
                <Button size="sm" variant="outline" loading={busy} disabled={!file && !media.url} onClick={addMedia}>{t('comm.ann.media.add')}</Button>
              </div>
              <p className="text-xs text-slate-400">{t('comm.ann.media.hintSize')}</p>
            </div>
          )}
        </fieldset>

        <div className="flex flex-wrap gap-x-6 gap-y-2 text-sm md:col-span-2">
          <span className="font-semibold text-navy-900">{t('comm.ann.notify')}:</span>
          <label className="flex items-center gap-2"><input type="checkbox" className="accent-gold-600" checked={f.notify_push} onChange={(e) => setF({ ...f, notify_push: e.target.checked })} />{t('comm.ann.notifyPush')}</label>
          <label className="flex items-center gap-2"><input type="checkbox" className="accent-gold-600" checked={f.notify_email} onChange={(e) => setF({ ...f, notify_email: e.target.checked })} />{t('comm.ann.notifyEmail')}</label>
          <label className="flex items-center gap-2"><input type="checkbox" className="accent-gold-600" checked={f.is_public} onChange={(e) => setF({ ...f, is_public: e.target.checked })} />{t('comm.ann.public')}</label>
          <label className="flex items-center gap-2"><input type="checkbox" className="accent-gold-600" checked={f.export_to_ministry} onChange={(e) => setF({ ...f, export_to_ministry: e.target.checked })} />{t('comm.ann.exportMinistry')}</label>
        </div>
      </div>
      {error && <p className="mt-3 text-sm text-danger">{error}</p>}
      <div className="mt-5 flex justify-end gap-2">
        <Button variant="outline" loading={busy} onClick={() => save(false)}>{t('common.save')}</Button>
        <Button variant="gold" loading={busy} onClick={() => save(true)}>{t('comm.ann.publish')}</Button>
      </div>
    </Modal>
  )
}
