/* eslint-disable @typescript-eslint/no-explicit-any */
import { Plus, Share2, Trash2 } from 'lucide-react'
import { useRef, useState } from 'react'
import { useTranslation } from 'react-i18next'
import { Badge, Button, Card, Empty, Field, Modal, PageHeader, Spinner, Tabs } from '@/components/ui'
import { useGet } from '@/hooks/useApi'
import { api, errorMessage } from '@/lib/api'
import { toast } from '@/lib/toast'
import ShareDialog from './ShareDialog'

const input = 'w-full rounded-xl border border-navy-100 px-3 py-2 text-sm'
const TYPES = ['book', 'journal', 'periodical', 'audio', 'video', 'elearning', 'kit', 'link']
const FIELDS = ['job_title', 'job_category', 'school_type', 'school_stage', 'region', 'experience_years', 'qualification', 'subject', 'grade_taught', 'licence_level', 'has_licence']
const OPS = ['eq', 'neq', 'in', 'gte', 'lte', 'includes']
type Tab = 'items' | 'shelves'

/** Library management: items with rights, embargo and audience rules, uploads, shelves, sharing. */
export default function LibraryAdmin() {
  const { t } = useTranslation()
  const [tab, setTab] = useState<Tab>('items')
  return (<><PageHeader title={t('content.navLibraryAdmin')} /><Tabs<Tab> value={tab} onChange={setTab} tabs={[{ id: 'items', label: t('content.admin.items') }, { id: 'shelves', label: t('content.admin.collectionsTab') }]} /><div className="mt-4">{tab === 'items' ? <Items /> : <Shelves />}</div></>)
}

function Items() {
  const { t, i18n } = useTranslation()
  const ar = i18n.language === 'ar'
  const res = useGet<{ data: any[] }>('/me/library', { status: undefined }, { staleTime: 0 })
  const [cur, setCur] = useState<any | null>(null)
  const [share, setShare] = useState<string | null>(null)
  const blank = { type: 'book', title_ar: '', title_en: '', authors: '', publisher: '', isbn: '', year: '', language: 'ar', subjects: '', url: '', status: 'draft', rights: { owner: '', licence: '', download: true, print: true, watermark: false, embargo_from: '', embargo_until: '' }, audience: [] as any[] }
  const file = useRef<HTMLInputElement>(null)
  const cover = useRef<HTMLInputElement>(null)
  const save = async () => {
    const body: any = { ...cur, authors: String(cur.authors ?? '').split(',').map((s: string) => s.trim()).filter(Boolean), subjects: String(cur.subjects ?? '').split(',').map((s: string) => s.trim()).filter(Boolean), year: cur.year ? Number(cur.year) : null, url: cur.url || null,
      rights: { ...cur.rights, embargo_from: cur.rights.embargo_from || null, embargo_until: cur.rights.embargo_until || null }, audience: cur.audience.map((r: any) => ({ field: r.field, operator: r.operator, value: typeof r.value === 'string' && r.value.includes(',') ? r.value.split(',').map((x: string) => x.trim()) : /^\d+(\.\d+)?$/.test(String(r.value)) ? Number(r.value) : r.value, is_mandatory: true })) }
    ;['id', 'cover_url', 'has_file', 'views', 'downloads', 'rating', 'reviews', 'on_shelf', 'source'].forEach((k) => delete body[k])
    try { const { data } = cur.id ? await api.put(`/admin/library-items/${cur.id}`, body) : await api.post('/admin/library-items', body); toast(t('content.admin.saved')); setCur({ ...cur, id: data.data.id }); void res.refetch() } catch (e) { toast(errorMessage(e), 'error') }
  }
  const up = async (kind: 'file' | 'cover', f: File) => { const fd = new FormData(); fd.append(kind, f); try { await api.post(`/admin/library-items/${cur.id}/files`, fd); toast('✓'); void res.refetch() } catch (e) { toast(errorMessage(e), 'error') } }
  const del = async (id: string) => { if (!window.confirm('?')) return; try { await api.delete(`/admin/library-items/${id}`); void res.refetch() } catch (e) { toast(errorMessage(e), 'error') } }
  const edit = (i: any) => setCur({ ...blank, ...i, authors: (i.authors ?? []).join(', '), subjects: (i.subjects ?? []).join(', '), rights: { ...blank.rights, ...(i.rights ?? {}), owner: i.rights?.owner ?? '', licence: i.rights?.licence ?? '' }, audience: i.audience ?? [] })
  return (
    <div className="space-y-3">
      <div className="flex justify-end"><Button size="sm" icon={<Plus className="size-4" />} onClick={() => setCur(blank)}>{t('content.admin.newItem')}</Button></div>
      {res.isLoading ? <Spinner /> : !res.data?.data.length ? <Card><Empty /></Card> : res.data.data.map((i) => <Card key={i.id} className="flex flex-wrap items-center gap-3"><Badge>{t(`content.library.types.${i.type}`)}</Badge><span className="min-w-0 flex-1 truncate font-semibold">{ar ? i.title_ar : i.title_en || i.title_ar}</span><Badge color={i.status === 'published' ? 'green' : 'gray'}>{t(`content.admin.status.${i.status}`)}</Badge><span className="text-xs text-slate-400">{i.views} 👁 · {i.downloads} ⬇</span>
        <Button size="sm" variant="ghost" icon={<Share2 className="size-4" />} onClick={() => setShare(i.id)}>{t('content.sharing.share')}</Button><Button size="sm" variant="outline" onClick={() => edit(i)}>{t('common.edit')}</Button><button type="button" aria-label="delete" className="text-danger" onClick={() => void del(i.id)}><Trash2 className="size-4" /></button></Card>)}
      {share && <ShareDialog resourceType="library_item" resourceId={share} onClose={() => setShare(null)} />}
      <Modal wide open={!!cur} onClose={() => setCur(null)} title={t('content.admin.newItem')}>
        {cur && <div className="grid gap-3 sm:grid-cols-2">
          <Field label={t('content.library.types.book')}><select className={input} value={cur.type} onChange={(e) => setCur({ ...cur, type: e.target.value })}>{TYPES.map((x) => <option key={x} value={x}>{t(`content.library.types.${x}`)}</option>)}</select></Field>
          <Field label={t('common.status')}><select className={input} value={cur.status} onChange={(e) => setCur({ ...cur, status: e.target.value })}>{['draft', 'published', 'archived'].map((x) => <option key={x} value={x}>{t(`content.admin.status.${x}`)}</option>)}</select></Field>
          <Field label={t('content.admin.titleAr')}><input className={input} value={cur.title_ar} onChange={(e) => setCur({ ...cur, title_ar: e.target.value })} /></Field>
          <Field label={t('content.admin.titleEn')}><input dir="ltr" className={input} value={cur.title_en ?? ''} onChange={(e) => setCur({ ...cur, title_en: e.target.value })} /></Field>
          <Field label={t('content.admin.authors')}><input className={input} value={cur.authors} onChange={(e) => setCur({ ...cur, authors: e.target.value })} /></Field>
          <Field label={t('content.admin.subjects')}><input className={input} value={cur.subjects} onChange={(e) => setCur({ ...cur, subjects: e.target.value })} /></Field>
          <Field label={t('content.admin.publisher')}><input className={input} value={cur.publisher ?? ''} onChange={(e) => setCur({ ...cur, publisher: e.target.value })} /></Field>
          <Field label={t('content.admin.isbn')}><input dir="ltr" className={input} value={cur.isbn ?? ''} onChange={(e) => setCur({ ...cur, isbn: e.target.value })} /></Field>
          <Field label={t('content.admin.year')}><input className={input} type="number" value={cur.year ?? ''} onChange={(e) => setCur({ ...cur, year: e.target.value })} /></Field>
          <Field label={t('content.admin.url')}><input dir="ltr" className={input} value={cur.url ?? ''} onChange={(e) => setCur({ ...cur, url: e.target.value })} /></Field>
          <fieldset className="space-y-2 rounded-xl border border-navy-100 p-3 sm:col-span-2"><legend className="px-1 text-sm font-bold">{t('content.library.rights')}</legend>
            <div className="grid gap-3 sm:grid-cols-2"><Field label={t('content.admin.owner')}><input className={input} value={cur.rights.owner} onChange={(e) => setCur({ ...cur, rights: { ...cur.rights, owner: e.target.value } })} /></Field><Field label={t('content.admin.licence')}><input className={input} value={cur.rights.licence} onChange={(e) => setCur({ ...cur, rights: { ...cur.rights, licence: e.target.value } })} /></Field>
              <Field label={t('content.admin.embargoFrom')}><input className={input} type="date" value={cur.rights.embargo_from ?? ''} onChange={(e) => setCur({ ...cur, rights: { ...cur.rights, embargo_from: e.target.value } })} /></Field><Field label={t('content.admin.embargoUntil')}><input className={input} type="date" value={cur.rights.embargo_until ?? ''} onChange={(e) => setCur({ ...cur, rights: { ...cur.rights, embargo_until: e.target.value } })} /></Field></div>
            <div className="flex flex-wrap gap-4 text-sm">{(['download', 'print', 'watermark'] as const).map((k) => <label key={k} className="flex items-center gap-2"><input type="checkbox" checked={!!cur.rights[k]} onChange={(e) => setCur({ ...cur, rights: { ...cur.rights, [k]: e.target.checked } })} />{t(`content.admin.${k === 'download' ? 'allowDownload' : k === 'print' ? 'allowPrint' : 'watermark'}`)}</label>)}</div></fieldset>
          <fieldset className="space-y-2 rounded-xl border border-navy-100 p-3 sm:col-span-2"><legend className="px-1 text-sm font-bold">{t('content.admin.audience')}</legend>
            {cur.audience.map((r: any, i: number) => <div key={i} className="flex gap-2"><select className={input} value={r.field} onChange={(e) => setCur({ ...cur, audience: cur.audience.map((x: any, j: number) => (j === i ? { ...x, field: e.target.value } : x)) })}>{FIELDS.map((f) => <option key={f} value={f}>{f}</option>)}</select><select className={`${input} max-w-28`} value={r.operator} onChange={(e) => setCur({ ...cur, audience: cur.audience.map((x: any, j: number) => (j === i ? { ...x, operator: e.target.value } : x)) })}>{OPS.map((o) => <option key={o} value={o}>{o}</option>)}</select><input dir="ltr" className={input} value={Array.isArray(r.value) ? r.value.join(',') : r.value ?? ''} onChange={(e) => setCur({ ...cur, audience: cur.audience.map((x: any, j: number) => (j === i ? { ...x, value: e.target.value } : x)) })} /><button type="button" aria-label="remove" className="text-danger" onClick={() => setCur({ ...cur, audience: cur.audience.filter((_: any, j: number) => j !== i) })}><Trash2 className="size-4" /></button></div>)}
            <Button size="sm" variant="outline" onClick={() => setCur({ ...cur, audience: [...cur.audience, { field: 'job_title', operator: 'eq', value: '' }] })}>{t('content.admin.addRule')}</Button></fieldset>
          <div className="flex flex-wrap gap-2 sm:col-span-2"><Button variant="gold" disabled={!cur.title_ar} onClick={() => void save()}>{t('common.save')}</Button>
            {cur.id && <><Button variant="outline" onClick={() => file.current?.click()}>{t('content.admin.file')}</Button><input ref={file} type="file" hidden accept=".pdf,.epub,.mp3,.mp4,.m4a,.zip,.doc,.docx,.ppt,.pptx" onChange={(e) => { const f = e.target.files?.[0]; if (f) void up('file', f); e.target.value = '' }} /><Button variant="outline" onClick={() => cover.current?.click()}>{t('content.admin.cover')}</Button><input ref={cover} type="file" hidden accept="image/*" onChange={(e) => { const f = e.target.files?.[0]; if (f) void up('cover', f); e.target.value = '' }} /></>}</div>
        </div>}
      </Modal>
    </div>
  )
}

function Shelves() {
  const { t, i18n } = useTranslation()
  const ar = i18n.language === 'ar'
  const res = useGet<{ data: any[] }>('/admin/library-collections', undefined, { staleTime: 0 })
  const items = useGet<{ data: any[] }>('/me/library')
  const [cur, setCur] = useState<any | null>(null)
  const save = async () => { try { const body = { name_ar: cur.name_ar, name_en: cur.name_en, is_featured: !!cur.is_featured, item_ids: cur.item_ids }; cur.id ? await api.put(`/admin/library-collections/${cur.id}`, body) : await api.post('/admin/library-collections', body); setCur(null); void res.refetch() } catch (e) { toast(errorMessage(e), 'error') } }
  return (
    <div className="space-y-3"><div className="flex justify-end"><Button size="sm" icon={<Plus className="size-4" />} onClick={() => setCur({ name_ar: '', name_en: '', is_featured: true, item_ids: [] })}>{t('content.admin.newCollection')}</Button></div>
      {(res.data?.data ?? []).map((c) => <Card key={c.id} className="flex items-center gap-3"><b>{ar ? c.name_ar : c.name_en}</b>{c.is_featured && <Badge color="gold">{t('content.admin.featured')}</Badge>}<span className="text-xs text-slate-400">{c.items.length}</span><Button className="ms-auto" size="sm" variant="outline" onClick={() => setCur({ ...c, item_ids: c.items.map((i: any) => i.id) })}>{t('common.edit')}</Button></Card>)}
      <Modal open={!!cur} onClose={() => setCur(null)} title={t('content.admin.collectionsTab')}>{cur && <div className="space-y-3"><Field label={t('content.admin.titleAr')}><input className={input} value={cur.name_ar} onChange={(e) => setCur({ ...cur, name_ar: e.target.value })} /></Field><Field label={t('content.admin.titleEn')}><input dir="ltr" className={input} value={cur.name_en} onChange={(e) => setCur({ ...cur, name_en: e.target.value })} /></Field>
        <label className="flex items-center gap-2 text-sm"><input type="checkbox" checked={!!cur.is_featured} onChange={(e) => setCur({ ...cur, is_featured: e.target.checked })} />{t('content.admin.featured')}</label>
        <div className="max-h-52 space-y-1 overflow-y-auto rounded-xl border border-navy-100 p-2">{(items.data?.data ?? []).map((i) => <label key={i.id} className="flex items-center gap-2 text-sm"><input type="checkbox" checked={cur.item_ids.includes(i.id)} onChange={(e) => setCur({ ...cur, item_ids: e.target.checked ? [...cur.item_ids, i.id] : cur.item_ids.filter((x: string) => x !== i.id) })} />{ar ? i.title_ar : i.title_en || i.title_ar}</label>)}</div><Button variant="gold" onClick={() => void save()}>{t('common.save')}</Button></div>}</Modal>
    </div>
  )
}
