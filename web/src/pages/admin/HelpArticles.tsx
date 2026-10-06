/* eslint-disable @typescript-eslint/no-explicit-any */
import { Plus, Trash2, Upload } from 'lucide-react'
import { useState } from 'react'
import { useTranslation } from 'react-i18next'
import { useLang } from '@/components/help/helpApi'
import { Badge, Button, Card, Empty, Field, Modal, PageHeader, Spinner } from '@/components/ui'
import { useGet } from '@/hooks/useApi'
import { api, errorMessage } from '@/lib/api'
import { toast } from '@/lib/toast'

const MODULES = ['general', 'trainee', 'trainer', 'manager', 'programs', 'planning', 'logistics', 'insight', 'kits', 'admin', 'finance']
const blank = { slug: '', title_ar: '', title_en: '', body_ar: '', body_en: '', roles: [] as string[], related: '', module: 'general', status: 'draft', sort_order: 0, video_url: '' }

/** The article editor for the people who write the manuals, plus reader feedback so weak articles get rewritten. */
export default function HelpArticles() {
  const { t } = useTranslation()
  const { lang } = useLang()
  const [tab, setTab] = useState<'articles' | 'feedback'>('articles')
  const [editing, setEditing] = useState<any | null>(null)
  const list = useGet<{ data: any[]; roles: any[] }>('/admin/help/articles', undefined, { staleTime: 0 })
  const stats = useGet<{ data: any[] }>(tab === 'feedback' ? '/admin/help/analytics' : null, undefined, { staleTime: 0 })

  const open = async (id?: string) => {
    if (!id) { setEditing({ ...blank }); return }
    try {
      const { data } = await api.get(`/admin/help/articles/${id}`)
      const a = data.data
      setEditing({ ...a, related: (a.related_routes ?? []).join('\n'), roles: a.roles ?? [] })
    } catch (e) { toast(errorMessage(e), 'error') }
  }

  return (
    <>
      <PageHeader title={t('hlp.admin.title')} subtitle={t('hlp.admin.subtitle')} actions={<Button variant="gold" icon={<Plus className="size-4" />} onClick={() => open()}>{t('hlp.admin.new')}</Button>} />
      <div className="mb-4 flex gap-2" role="tablist">
        {(['articles', 'feedback'] as const).map((k) => <button key={k} role="tab" aria-selected={tab === k} type="button" onClick={() => setTab(k)} className={`rounded-full px-4 py-1.5 text-sm font-semibold ${tab === k ? 'bg-navy-900 text-white' : 'bg-white text-navy-800 border border-navy-100'}`}>{t(k === 'articles' ? 'hlp.admin.tabArticles' : 'hlp.admin.tabFeedback')}</button>)}
      </div>
      {tab === 'articles' ? (
        list.isLoading ? <Spinner /> : (list.data?.data.length ?? 0) === 0 ? <Empty text={String(t('hlp.admin.empty'))} /> : (
          <div className="grid gap-3 md:grid-cols-2">
            {list.data!.data.map((a) => (
              <button key={a.id} type="button" onClick={() => open(a.id)} className="text-start">
                <Card className="h-full transition hover:border-gold-400">
                  <div className="flex items-start justify-between gap-2"><b className="text-navy-900">{lang === 'en' ? a.title_en : a.title_ar}</b><Badge color={a.status === 'published' ? 'green' : 'gold'}>{t(`hlp.admin.${a.status}`)}</Badge></div>
                  <p className="mt-1 text-xs text-slate-500" dir="ltr">{a.slug} · v{a.version}</p>
                  <p className="mt-2 text-xs text-slate-500">{(a.roles?.length ?? 0) === 0 ? t('hlp.admin.all') : a.roles.join(', ')}</p>
                </Card>
              </button>
            ))}
          </div>
        )
      ) : stats.isLoading ? <Spinner /> : (stats.data?.data.length ?? 0) === 0 ? <Empty text={String(t('hlp.admin.noFeedback'))} /> : (
        <div className="space-y-3">
          {stats.data!.data.map((r) => (
            <Card key={r.article_id}>
              <div className="flex flex-wrap items-center justify-between gap-2"><b className="text-navy-900">{lang === 'en' ? r.title_en : r.title_ar}</b>
                <div className="flex gap-2"><Badge color="green">{t('hlp.admin.helpfulCount')}: {r.helpful}</Badge><Badge color="red">{t('hlp.admin.notHelpfulCount')}: {r.not_helpful}</Badge>{r.score !== null && <Badge color="navy">{t('hlp.admin.score')}: {r.score}%</Badge>}</div></div>
              {r.comments.length > 0 && <ul className="mt-2 list-disc space-y-1 ps-5 text-sm text-slate-700">{r.comments.map((c: string, i: number) => <li key={i}>{c}</li>)}</ul>}
            </Card>
          ))}
        </div>
      )}
      {editing && <Editor article={editing} roles={list.data?.roles ?? []} onClose={() => setEditing(null)} onSaved={() => { list.refetch(); setEditing(null) }} onReload={(id: string) => open(id)} />}
    </>
  )
}

function Editor({ article, roles, onClose, onSaved, onReload }: { article: any; roles: any[]; onClose: () => void; onSaved: () => void; onReload: (id: string) => void }) {
  const { t } = useTranslation()
  const { lang } = useLang()
  const [f, setF] = useState(article)
  const [busy, setBusy] = useState(false)
  const [shot, setShot] = useState({ ar: '', en: '' })
  const set = (k: string, v: any) => setF((p: any) => ({ ...p, [k]: v }))
  const toggleRole = (slug: string) => set('roles', f.roles.includes(slug) ? f.roles.filter((r: string) => r !== slug) : [...f.roles, slug])

  const save = async () => {
    setBusy(true)
    try {
      const body = {
        slug: f.slug, title_ar: f.title_ar, title_en: f.title_en, body_ar: f.body_ar ?? '', body_en: f.body_en ?? '', roles: f.roles, module: f.module, status: f.status, sort_order: Number(f.sort_order) || 0,
        related_routes: String(f.related ?? '').split('\n').map((x) => x.trim()).filter(Boolean), video_url: f.video_url || null,
      }
      if (f.id) await api.put(`/admin/help/articles/${f.id}`, body); else await api.post('/admin/help/articles', body)
      toast(String(t('hlp.admin.saved'))); onSaved()
    } catch (e) { toast(errorMessage(e), 'error') } finally { setBusy(false) }
  }
  const remove = async () => {
    if (!window.confirm(String(t('hlp.admin.confirmDelete')))) return
    try { await api.delete(`/admin/help/articles/${f.id}`); toast(String(t('hlp.admin.deleted'))); onSaved() } catch (e) { toast(errorMessage(e), 'error') }
  }
  const upload = async (kind: 'screenshots' | 'video', file: File | undefined) => {
    if (!file) return
    const body = new FormData()
    body.append('file', file)
    if (kind === 'screenshots') { body.append('caption_ar', shot.ar); body.append('caption_en', shot.en) }
    try { await api.post(`/admin/help/articles/${f.id}/${kind}`, body); setShot({ ar: '', en: '' }); onReload(f.id) } catch (e) { toast(errorMessage(e), 'error') }
  }
  const dropShot = async (i: number) => { try { await api.delete(`/admin/help/articles/${f.id}/screenshots/${i}`); onReload(f.id) } catch (e) { toast(errorMessage(e), 'error') } }
  const rollback = async (v: number) => { try { await api.post(`/admin/help/articles/${f.id}/rollback/${v}`); toast(String(t('hlp.admin.rolledBack'))); onReload(f.id) } catch (e) { toast(errorMessage(e), 'error') } }

  return (
    <Modal open onClose={onClose} title={f.id ? t('hlp.admin.edit') : t('hlp.admin.new')} wide>
      <div className="space-y-4">
        <div className="grid gap-3 sm:grid-cols-2">
          <Field label={t('hlp.admin.slug')}><input className="input" dir="ltr" value={f.slug} onChange={(e) => set('slug', e.target.value)} /></Field>
          <div className="grid grid-cols-3 gap-3">
            <Field label={t('hlp.admin.module')}><select className="input" value={f.module} onChange={(e) => set('module', e.target.value)}>{MODULES.map((m) => <option key={m} value={m}>{t(`hlp.modules.${m}`)}</option>)}</select></Field>
            <Field label={t('hlp.admin.status')}><select className="input" value={f.status} onChange={(e) => set('status', e.target.value)}><option value="draft">{t('hlp.admin.draft')}</option><option value="published">{t('hlp.admin.published')}</option></select></Field>
            <Field label={t('hlp.admin.sortOrder')}><input className="input" type="number" min={0} value={f.sort_order} onChange={(e) => set('sort_order', e.target.value)} /></Field>
          </div>
          <Field label={t('hlp.admin.titleAr')}><input className="input" dir="rtl" value={f.title_ar} onChange={(e) => set('title_ar', e.target.value)} /></Field>
          <Field label={t('hlp.admin.titleEn')}><input className="input" dir="ltr" value={f.title_en} onChange={(e) => set('title_en', e.target.value)} /></Field>
          <Field label={t('hlp.admin.bodyAr')}><textarea className="input min-h-48 font-mono text-xs" dir="rtl" value={f.body_ar ?? ''} onChange={(e) => set('body_ar', e.target.value)} /></Field>
          <Field label={t('hlp.admin.bodyEn')}><textarea className="input min-h-48 font-mono text-xs" dir="ltr" value={f.body_en ?? ''} onChange={(e) => set('body_en', e.target.value)} /></Field>
          <Field label={t('hlp.admin.routes')}><textarea className="input min-h-24 font-mono text-xs" dir="ltr" value={f.related ?? ''} onChange={(e) => set('related', e.target.value)} placeholder="/programs&#10;/learn/*" /></Field>
          <Field label={t('hlp.admin.videoUrl')}><input className="input" dir="ltr" value={f.video_url ?? ''} onChange={(e) => set('video_url', e.target.value)} placeholder="https://" /></Field>
        </div>
        <fieldset className="space-y-2"><legend className="text-sm font-semibold text-navy-900">{t('hlp.admin.roles')}</legend>
          <div className="flex flex-wrap gap-2">{roles.map((r) => <label key={r.slug} className={`cursor-pointer rounded-full border px-3 py-1 text-xs ${f.roles.includes(r.slug) ? 'border-navy-900 bg-navy-900 text-white' : 'border-navy-100 bg-white'}`}><input type="checkbox" className="sr-only" checked={f.roles.includes(r.slug)} onChange={() => toggleRole(r.slug)} />{lang === 'en' ? r.name_en : r.name_ar}</label>)}</div>
        </fieldset>
        {f.id ? (
          <div className="space-y-3 rounded-xl bg-ivory p-4">
            <h3 className="font-bold text-navy-900">{t('hlp.admin.screenshots')}</h3>
            <div className="grid gap-2 sm:grid-cols-3">{(f.screenshots ?? []).map((s: any, i: number) => (
              <figure key={i} className="relative overflow-hidden rounded-lg border border-navy-100 bg-white"><img src={s.url} alt={s.caption_en || s.caption_ar || ''} className="h-28 w-full object-cover" />
                <button type="button" onClick={() => dropShot(i)} aria-label="remove" className="absolute end-1 top-1 rounded bg-white/90 p-1 text-danger"><Trash2 className="size-4" /></button></figure>
            ))}</div>
            <div className="grid gap-2 sm:grid-cols-3">
              <input className="input" dir="rtl" placeholder={String(t('hlp.admin.captionAr'))} value={shot.ar} onChange={(e) => setShot({ ...shot, ar: e.target.value })} />
              <input className="input" dir="ltr" placeholder={String(t('hlp.admin.captionEn'))} value={shot.en} onChange={(e) => setShot({ ...shot, en: e.target.value })} />
              <label className="btn inline-flex cursor-pointer items-center justify-center gap-2 border border-navy-100 bg-white px-3 py-2 text-sm font-semibold"><Upload className="size-4" />{t('hlp.admin.addShot')}<input type="file" accept="image/png,image/jpeg,image/webp" className="sr-only" onChange={(e) => { upload('screenshots', e.target.files?.[0]); e.target.value = '' }} /></label>
            </div>
            <label className="btn inline-flex cursor-pointer items-center gap-2 border border-navy-100 bg-white px-3 py-2 text-sm font-semibold"><Upload className="size-4" />{t('hlp.admin.videoFile')}{f.video_asset ? ' ✓' : ''}<input type="file" accept="video/mp4,video/webm" className="sr-only" onChange={(e) => { upload('video', e.target.files?.[0]); e.target.value = '' }} /></label>
            <h3 className="pt-1 font-bold text-navy-900">{t('hlp.admin.versions')}</h3>
            <div className="flex flex-wrap gap-2">{(f.versions ?? []).map((v: any) => <Button key={v.version} size="sm" variant="outline" disabled={v.version === f.version} onClick={() => rollback(v.version)}>{t('hlp.admin.rollback')} v{v.version}</Button>)}</div>
          </div>
        ) : <p className="text-sm text-slate-500">{t('hlp.admin.saveFirst')}</p>}
        <div className="flex justify-between gap-2">
          <div>{f.id && <Button variant="danger" icon={<Trash2 className="size-4" />} onClick={remove}>{t('common.delete', { defaultValue: 'Delete' })}</Button>}</div>
          <Button variant="gold" loading={busy} disabled={!f.slug || !f.title_ar || !f.title_en} onClick={save}>{t('common.save', { defaultValue: 'Save' })}</Button>
        </div>
      </div>
    </Modal>
  )
}
