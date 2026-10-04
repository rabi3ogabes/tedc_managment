import { Globe, Link2, Megaphone, Plus, Send, Trash2 } from 'lucide-react'
import { useState } from 'react'
import { useTranslation } from 'react-i18next'
import { Badge, Button, Card, Empty, Field, Modal, PageHeader, Spinner, Tabs } from '@/components/ui'
import CampaignTracking from './notifications/CampaignTracking'
import ChannelPicker, { type Channel } from './notifications/ChannelPicker'
import UpcomingTimeline from './notifications/UpcomingTimeline'
import { useGet } from '@/hooks/useApi'
import { api, errorMessage } from '@/lib/api'
import { fmt } from '@/lib/format'
import type { LaravelPage, Paginated, Program } from '@/lib/types'

type Announcement = { id: string; type: string; title_ar: string; title_en: string; body_ar?: string; audience: string; is_public: boolean; published_at?: string | null; attachments?: { type: string; title: string; url?: string }[] }

const blank = { type: 'announcement', title_ar: '', title_en: '', body_ar: '', body_en: '', audience: 'all', target_ids: [] as string[], attachments: [] as { type: string; title: string; url: string }[], is_public: false }

export default function Communication() {
  const { t, i18n } = useTranslation()
  const { data, isLoading, refetch } = useGet<LaravelPage<Announcement>>('/admin/announcements')
  const lookups = useGet<{ data: { schools: { id: string; name_ar: string; name_en: string }[] } }>('/admin/lookups')
  const programs = useGet<Paginated<Program>>('/admin/programs', { per_page: 100 })
  const [tab, setTab] = useState<'announcements' | 'tracking' | 'upcoming'>('announcements')
  const [channels, setChannels] = useState<Channel[] | null>(null)
  const [open, setOpen] = useState(false)
  const [form, setForm] = useState(blank)
  const [file, setFile] = useState<File | null>(null)
  const [notice, setNotice] = useState<string | null>(null)
  const [error, setError] = useState<string | null>(null)
  const [loading, setLoading] = useState(false)
  const nm = (o: { name_ar: string; name_en: string }) => (i18n.language === 'ar' ? o.name_ar : o.name_en)

  const targets = form.audience === 'schools' ? lookups.data?.data.schools.map((s) => ({ id: s.id, label: nm(s) }))
    : form.audience === 'programs' ? programs.data?.data.map((p) => ({ id: p.id, label: p.title }))
    : form.audience === 'roles' ? ['employee', 'school_admin', 'trainer', 'supervisor', 'executive'].map((r) => ({ id: r, label: r })) : null

  const send = async (publish: boolean) => {
    setLoading(true)
    setError(null)
    try {
      const { data: res } = await api.post('/admin/announcements', { ...form, attachments: form.attachments.filter((a) => a.url), publish, notify_channels: publish ? channels ?? undefined : undefined })
      if (file) {
        const body = new FormData()
        body.append('file', file)
        await api.post(`/admin/announcements/${res.data.id}/attachments`, body)
      }
      setNotice(publish ? t('admin.communication.recipients', { n: res.meta.recipients }) : t('common.saved'))
      setOpen(false)
      setForm(blank)
      setFile(null)
      refetch()
    } catch (e) {
      setError(errorMessage(e))
    } finally {
      setLoading(false)
    }
  }

  const publish = async (id: string) => {
    const { data: res } = await api.post(`/admin/announcements/${id}/publish`)
    setNotice(t('admin.communication.recipients', { n: res.meta.recipients }))
    refetch()
  }

  return (
    <>
      <PageHeader title={t('admin.communication.title')} actions={<Button variant="gold" icon={<Plus className="size-4" />} onClick={() => setOpen(true)}>{t('admin.communication.new')}</Button>} />
      <Tabs value={tab} onChange={setTab} tabs={[{ id: 'announcements', label: t('mgmt.notif.tabs.announcements') }, { id: 'tracking', label: t('mgmt.notif.tabs.tracking') }, { id: 'upcoming', label: t('upcoming.tab') }]} />
      {notice && <div className="mb-4 rounded-xl bg-emerald-50 p-3 text-sm text-emerald-700">{notice}</div>}
      {tab === 'upcoming' ? <UpcomingTimeline /> : tab === 'tracking' ? <CampaignTracking /> : isLoading ? <Spinner /> : !data?.data.length ? <Card><Empty /></Card> : (
        <div className="grid gap-4 md:grid-cols-2 xl:grid-cols-3">
          {data.data.map((a) => (
            <Card key={a.id}>
              <div className="flex items-center gap-2">
                <div className="grid size-10 place-items-center rounded-xl bg-navy-900 text-gold-300"><Megaphone className="size-5" /></div>
                <Badge color="navy">{t(`admin.communication.types.${a.type}`)}</Badge>
                {a.is_public && <Badge color="gold"><Globe className="size-3" /></Badge>}
              </div>
              <h3 className="mt-3 font-bold text-navy-900">{i18n.language === 'ar' ? a.title_ar : a.title_en}</h3>
              <p className="mt-1 line-clamp-2 text-sm text-slate-500">{a.body_ar}</p>
              <div className="mt-4 flex items-center justify-between text-xs text-slate-400">
                <span>{t(`admin.communication.audiences.${a.audience}`)}</span>
                {a.published_at ? <span>{fmt.date(a.published_at)}</span> : <Button size="sm" variant="outline" icon={<Send className="size-3" />} onClick={() => publish(a.id)}>{t('common.publish')}</Button>}
              </div>
            </Card>
          ))}
        </div>
      )}

      <Modal open={open} onClose={() => setOpen(false)} title={t('admin.communication.new')} wide>
        <div className="grid gap-3 sm:grid-cols-2">
          <Field label={t('admin.communication.type')}><select className="input" value={form.type} onChange={(e) => setForm({ ...form, type: e.target.value })}>{['announcement', 'news', 'circular'].map((x) => <option key={x} value={x}>{t(`admin.communication.types.${x}`)}</option>)}</select></Field>
          <Field label={t('admin.communication.audience')}><select className="input" value={form.audience} onChange={(e) => setForm({ ...form, audience: e.target.value, target_ids: [] })}>{['all', 'schools', 'programs', 'roles'].map((x) => <option key={x} value={x}>{t(`admin.communication.audiences.${x}`)}</option>)}</select></Field>
          {targets && (
            <Field label={t('admin.communication.audience')} className="sm:col-span-2">
              <select multiple className="input h-32" value={form.target_ids} onChange={(e) => setForm({ ...form, target_ids: Array.from(e.target.selectedOptions).map((o) => o.value) })}>{targets.map((x) => <option key={x.id} value={x.id}>{x.label}</option>)}</select>
            </Field>
          )}
          <Field label={t('admin.programs.titleAr')}><input className="input" value={form.title_ar} onChange={(e) => setForm({ ...form, title_ar: e.target.value })} /></Field>
          <Field label={t('admin.programs.titleEn')}><input className="input" dir="ltr" value={form.title_en} onChange={(e) => setForm({ ...form, title_en: e.target.value })} /></Field>
          <Field label={t('admin.communication.bodyAr')}><textarea className="input min-h-28" value={form.body_ar} onChange={(e) => setForm({ ...form, body_ar: e.target.value })} /></Field>
          <Field label={t('admin.communication.bodyEn')}><textarea className="input min-h-28" dir="ltr" value={form.body_en} onChange={(e) => setForm({ ...form, body_en: e.target.value })} /></Field>
          <div className="sm:col-span-2">
            <span className="label">{t('admin.communication.attachments')}</span>
            <div className="space-y-2">
              {form.attachments.map((a, i) => (
                <div key={i} className="flex gap-2">
                  <select className="input w-28" value={a.type} onChange={(e) => setForm({ ...form, attachments: form.attachments.map((x, j) => (j === i ? { ...x, type: e.target.value } : x)) })}><option value="link">link</option><option value="video">video</option></select>
                  <input className="input" placeholder={t('common.name')} value={a.title} onChange={(e) => setForm({ ...form, attachments: form.attachments.map((x, j) => (j === i ? { ...x, title: e.target.value } : x)) })} />
                  <input className="input" dir="ltr" placeholder="https://" value={a.url} onChange={(e) => setForm({ ...form, attachments: form.attachments.map((x, j) => (j === i ? { ...x, url: e.target.value } : x)) })} />
                  <button className="text-slate-400 hover:text-danger" onClick={() => setForm({ ...form, attachments: form.attachments.filter((_, j) => j !== i) })}><Trash2 className="size-4" /></button>
                </div>
              ))}
              <div className="flex flex-wrap gap-2">
                <Button size="sm" variant="outline" icon={<Link2 className="size-4" />} onClick={() => setForm({ ...form, attachments: [...form.attachments, { type: 'link', title: '', url: '' }] })}>{t('admin.communication.addLink')}</Button>
                <input type="file" className="text-sm" onChange={(e) => setFile(e.target.files?.[0] ?? null)} />
              </div>
            </div>
          </div>
          <div className="sm:col-span-2"><ChannelPicker value={channels} onChange={setChannels} /></div>
          <label className="flex items-center gap-2 text-sm sm:col-span-2"><input type="checkbox" className="accent-gold-600" checked={form.is_public} onChange={(e) => setForm({ ...form, is_public: e.target.checked })} />{t('admin.communication.isPublic')}</label>
        </div>
        {error && <p className="mt-3 text-sm text-danger">{error}</p>}
        <div className="mt-5 flex justify-end gap-2">
          <Button variant="outline" loading={loading} onClick={() => send(false)}>{t('common.save')}</Button>
          <Button variant="gold" loading={loading} icon={<Send className="size-4" />} onClick={() => send(true)}>{t('common.publish')}</Button>
        </div>
      </Modal>
    </>
  )
}
