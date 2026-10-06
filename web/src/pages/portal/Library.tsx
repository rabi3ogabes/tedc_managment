/* eslint-disable @typescript-eslint/no-explicit-any */
import { BookMarked, BookOpen, Download, Star } from 'lucide-react'
import { useState } from 'react'
import { useTranslation } from 'react-i18next'
import { Badge, Button, Card, Empty, Modal, PageHeader, Spinner, Tabs } from '@/components/ui'
import { useGet } from '@/hooks/useApi'
import { api, errorMessage } from '@/lib/api'
import { toast } from '@/lib/toast'

const TYPES = ['book', 'journal', 'periodical', 'audio', 'video', 'elearning', 'kit', 'link']
type Tab = 'catalogue' | 'shelf' | 'shared'

/** The digital library: search with Arabic folding, shelves, a reader that respects rights (watermark, no download), shelf and ratings. */
export default function Library() {
  const { t } = useTranslation()
  const [tab, setTab] = useState<Tab>('catalogue')
  return (
    <>
      <PageHeader title={t('content.library.title')} />
      <Tabs<Tab> value={tab} onChange={setTab} tabs={[{ id: 'catalogue', label: t('content.library.all') }, { id: 'shelf', label: t('content.library.myShelf') }, { id: 'shared', label: t('content.library.shared') }]} />
      <div className="mt-4">{tab === 'catalogue' ? <Catalogue /> : tab === 'shelf' ? <Shelf /> : <Shared />}</div>
    </>
  )
}

function Catalogue() {
  const { t, i18n } = useTranslation()
  const [q, setQ] = useState('')
  const [type, setType] = useState('')
  const [sort, setSort] = useState('recent')
  const [collection, setCollection] = useState('')
  const [open, setOpen] = useState<string | null>(null)
  const res = useGet<{ data: any[]; collections: any[] }>('/me/library', { q: q || undefined, type: type || undefined, sort, collection: collection || undefined }, { staleTime: 0 })
  return (
    <div className="space-y-4">
      <div className="flex flex-wrap gap-2"><input className="input min-w-52 flex-1" placeholder={t('content.library.search')} value={q} onChange={(e) => setQ(e.target.value)} />
        <select aria-label="type" className="rounded-xl border border-navy-100 px-3 py-2 text-sm" value={type} onChange={(e) => setType(e.target.value)}><option value="">{t('content.library.all')}</option>{TYPES.map((x) => <option key={x} value={x}>{t(`content.library.types.${x}`)}</option>)}</select>
        <select aria-label="sort" className="rounded-xl border border-navy-100 px-3 py-2 text-sm" value={sort} onChange={(e) => setSort(e.target.value)}>{['recent', 'popular', 'title'].map((x) => <option key={x} value={x}>{t(`content.library.sort.${x}`)}</option>)}</select></div>
      {(res.data?.collections.length ?? 0) > 0 && <div className="flex flex-wrap gap-2"><span className="self-center text-xs text-slate-500">{t('content.library.collections')}</span>{res.data!.collections.map((c) => <button key={c.id} type="button" onClick={() => setCollection(collection === c.id ? '' : c.id)} className={`rounded-full px-3 py-1 text-xs font-bold ${collection === c.id ? 'bg-navy-900 text-white' : 'bg-white text-navy-900'}`}>{i18n.language === 'ar' ? c.name_ar : c.name_en}</button>)}</div>}
      {res.isLoading ? <Spinner /> : !res.data?.data.length ? <Card><Empty text={t('content.library.empty')} /></Card> : <ItemGrid items={res.data.data} onOpen={setOpen} />}
      {open && <ItemModal id={open} onClose={() => { setOpen(null); void res.refetch() }} />}
    </div>
  )
}

function ItemGrid({ items, onOpen }: { items: any[]; onOpen: (id: string) => void }) {
  const { t, i18n } = useTranslation()
  const ar = i18n.language === 'ar'
  return (
    <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">{items.map((i) => (
      <button key={i.id} type="button" onClick={() => onOpen(i.id)} className="group overflow-hidden rounded-2xl border border-navy-100 bg-white text-start shadow-sm transition hover:shadow-md">
        <div className="grid aspect-[4/3] place-items-center bg-gradient-to-br from-navy-900 to-navy-700 text-white">{i.cover_url ? <img src={i.cover_url} alt="" className="size-full object-cover" /> : <BookMarked className="size-10 opacity-60" />}</div>
        <div className="space-y-1 p-3"><div className="line-clamp-2 font-bold text-navy-900">{ar ? i.title_ar : i.title_en || i.title_ar}</div><div className="flex flex-wrap items-center gap-1.5 text-xs"><Badge>{t(`content.library.types.${i.type}`)}</Badge>{i.rating > 0 && <span className="flex items-center gap-0.5 text-gold-700"><Star className="size-3 fill-current" />{i.rating}</span>}{!i.rights.download && <Badge color="gray">{t('content.library.viewOnly')}</Badge>}</div></div>
      </button>))}</div>
  )
}

function ItemModal({ id, onClose }: { id: string; onClose: () => void }) {
  const { t, i18n } = useTranslation()
  const ar = i18n.language === 'ar'
  const res = useGet<{ data: any }>(`/me/library/${id}`, undefined, { staleTime: 0 })
  const [reader, setReader] = useState<any | null>(null)
  const [stars, setStars] = useState(0)
  const [text, setText] = useState('')
  const d = res.data?.data
  const read = async () => { try { const { data } = await api.get(`/me/library/${id}/read`); data.data.external ? window.open(data.data.url, '_blank', 'noopener') : setReader(data.data) } catch (e) { toast(errorMessage(e), 'error') } }
  const dl = async () => { try { const { data } = await api.get(`/me/library/${id}/download`); window.open(data.data.url, '_blank', 'noopener') } catch (e) { toast(errorMessage(e), 'error') } }
  const shelf = async () => { try { await api.put(`/me/library/${id}/shelf`, { on: !d.on_shelf }); void res.refetch() } catch (e) { toast(errorMessage(e), 'error') } }
  const rate = async () => { try { await api.post(`/me/library/${id}/review`, { stars, review: text || null }); toast('✓'); void res.refetch() } catch (e) { toast(errorMessage(e), 'error') } }
  return (
    <Modal wide open onClose={onClose} title={d ? (ar ? d.title_ar : d.title_en || d.title_ar) : '…'}>
      {!d ? <Spinner /> : reader ? (
        <div className="relative">
          {reader.mime?.startsWith('audio') ? <audio controls controlsList={reader.allow_download ? undefined : 'nodownload'} src={reader.url} className="w-full" /> : reader.mime?.startsWith('video') ? <video controls controlsList={reader.allow_download ? undefined : 'nodownload'} src={reader.url} className="max-h-[70vh] w-full" onContextMenu={(e) => !reader.allow_download && e.preventDefault()} /> : <iframe title="reader" src={`${reader.url}${reader.allow_download ? '' : '#toolbar=0&navpanes=0'}`} className="h-[70vh] w-full rounded-xl border border-navy-100" />}
          {reader.watermark && <div aria-hidden className="pointer-events-none absolute inset-0 grid place-items-center overflow-hidden"><span className="-rotate-30 select-none text-2xl font-extrabold text-navy-900/10">{reader.watermark}</span></div>}
          <div className="mt-2 text-xs text-slate-500">{!reader.allow_download && t('content.library.noDownload')} {!reader.allow_print && `· ${t('content.library.noPrint')}`}</div>
        </div>
      ) : (
        <div className="space-y-3">
          <div className="flex flex-wrap gap-2 text-xs"><Badge>{t(`content.library.types.${d.type}`)}</Badge>{d.authors.map((a: string) => <Badge key={a} color="gray">{a}</Badge>)}{d.year && <Badge color="gray">{d.year}</Badge>}{d.rating > 0 && <Badge color="gold">★ {d.rating} · {t('content.library.reviews', { n: d.reviews })}</Badge>}</div>
          <p className="text-sm text-slate-700">{ar ? d.description_ar : d.description_en || d.description_ar}</p>
          <div className="flex flex-wrap gap-2"><Button variant="gold" icon={<BookOpen className="size-4" />} onClick={() => void read()}>{t('content.library.read')}</Button>{d.has_file && d.rights.download && <Button variant="outline" icon={<Download className="size-4" />} onClick={() => void dl()}>{t('content.library.download')}</Button>}<Button variant="outline" onClick={() => void shelf()}>{d.on_shelf ? t('content.library.onShelf') : t('content.library.add')}</Button></div>
          {(d.rights.owner || !d.rights.download) && <div className="rounded-xl bg-ivory p-3 text-xs text-slate-600"><b>{t('content.library.rights')}:</b> {d.rights.owner} {d.rights.licence && `· ${d.rights.licence}`} {!d.rights.download && `· ${t('content.library.noDownload')}`}</div>}
          <div className="space-y-2 border-t border-navy-50 pt-3"><div className="flex gap-1">{[1, 2, 3, 4, 5].map((n) => <button key={n} type="button" aria-label={`${n}`} onClick={() => setStars(n)}><Star className={`size-6 ${n <= stars ? 'fill-gold-500 text-gold-500' : 'text-slate-300'}`} /></button>)}</div>
            <div className="flex gap-2"><input className="input" placeholder={t('content.library.review')} value={text} onChange={(e) => setText(e.target.value)} /><Button size="sm" variant="outline" disabled={!stars} onClick={() => void rate()}>{t('content.library.send')}</Button></div>
            {(d.review_list ?? []).map((r: any, i: number) => <p key={i} className="text-sm text-slate-600">{'★'.repeat(r.stars)} {r.review}</p>)}</div>
        </div>)}
    </Modal>
  )
}

function Shelf() {
  const { t } = useTranslation()
  const res = useGet<{ data: any[] }>('/me/library/shelf', undefined, { staleTime: 0 })
  const [open, setOpen] = useState<string | null>(null)
  return res.isLoading ? <Spinner /> : !res.data?.data.length ? <Card><Empty text={t('content.library.empty')} /></Card> : <><ItemGrid items={res.data.data} onOpen={setOpen} />{open && <ItemModal id={open} onClose={() => { setOpen(null); void res.refetch() }} />}</>
}

function Shared() {
  const { t, i18n } = useTranslation()
  const ar = i18n.language === 'ar'
  const res = useGet<{ data: any[] }>('/me/shared', undefined, { staleTime: 0 })
  const [open, setOpen] = useState<string | null>(null)
  return res.isLoading ? <Spinner /> : !res.data?.data.length ? <Card><Empty text={t('content.sharing.sharedEmpty')} /></Card> : (
    <div className="space-y-2">{res.data.data.map((s) => <Card key={s.id} className="flex items-center gap-3"><Badge>{s.resource_type}</Badge><span className="flex-1 font-semibold">{ar ? s.title_ar : s.title_en || s.title_ar}</span>{s.view_only && <Badge color="gray">{t('content.library.viewOnly')}</Badge>}{s.resource_type === 'library_item' && <Button size="sm" variant="outline" onClick={() => setOpen(s.resource_id)}>{t('content.library.open')}</Button>}</Card>)}{open && <ItemModal id={open} onClose={() => setOpen(null)} />}</div>
  )
}
