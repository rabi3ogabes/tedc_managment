import clsx from 'clsx'
import { ArrowLeft, ArrowRight, Download, FileQuestion, Loader2, MessageSquareDashed, MousePointer2, Presentation } from 'lucide-react'
import { useCallback, useEffect, useMemo, useState } from 'react'
import { useTranslation } from 'react-i18next'
import { Link, useNavigate, useParams, useSearchParams } from 'react-router-dom'
import { Button, ErrorState, Spinner } from '@/components/ui'
import { useGet } from '@/hooks/useApi'
import { api, errorMessage } from '@/lib/api'
import { useCenterName } from '@/lib/ThemeProvider'
import CommentsPanel from './comments/CommentsPanel'
import { useKitComments } from './comments/api'
import { KitStatusBadge, kindIcon, kindTint } from './common'
import DeckEditor from './deck/DeckEditor'
import { importPptx } from './deck/pptx'
import KitActions from './KitActions'
import type { Anchor, KitComment, KitDetail, KitFile } from './types'
import DocxViewer from './viewers/DocxViewer'
import MediaViewer from './viewers/MediaViewer'
import PdfViewer from './viewers/PdfViewer'

/** Full-screen studio for one file: the slide editor, or a viewer with a review panel for PDF / Word / media. */
export default function FileStudio() {
  const { kitId = '', fileId = '' } = useParams()
  const { t, i18n } = useTranslation()
  const navigate = useNavigate()
  const centerName = useCenterName()
  const kitQuery = useGet<{ data: KitDetail }>(`/admin/kits/${kitId}`)
  const kit = kitQuery.data?.data
  const [fileOverride, setFileOverride] = useState<KitFile | null>(null)
  const file = fileOverride?.id === fileId ? fileOverride : kit?.files.find((f) => f.id === fileId) ?? null
  const Back = i18n.dir() === 'rtl' ? ArrowRight : ArrowLeft

  useEffect(() => { document.title = file ? `${file.name} · ${centerName}` : centerName }, [file, centerName])

  if (kitQuery.isLoading) return <Spinner className="min-h-screen" />
  if (!kit || !file) return <ErrorState message={kitQuery.error ? errorMessage(kitQuery.error) : t('kits.file.notFound')} onRetry={() => kitQuery.refetch()} />

  const Icon = kindIcon[file.kind]
  return (
    <div className="flex h-screen flex-col bg-ivory">
      <header className="flex flex-wrap items-center gap-3 border-b border-navy-100 bg-white px-4 py-2.5">
        <Link to={`/admin/kits/${kit.id}`} className="grid size-9 place-items-center rounded-lg text-navy-800 hover:bg-navy-100/70" aria-label={t('kits.file.back')}><Back className="size-5" /></Link>
        <span className={clsx('grid size-9 place-items-center rounded-lg', kindTint[file.kind])}><Icon className="size-5" /></span>
        <div className="min-w-0">
          <div className="truncate text-sm font-bold text-navy-900" dir="auto">{file.name}</div>
          <div className="truncate text-xs text-slate-500" dir="auto">{kit.title} · <span dir="ltr">{kit.code}</span></div>
        </div>
        <KitStatusBadge status={kit.status} />
        {kit.status === 'in_review' && <span className="rounded-full bg-gold-100 px-2.5 py-0.5 text-xs font-bold text-gold-700">{t('kits.file.round', { n: kit.review_round })}</span>}
        <FileSwitcher kit={kit} current={file.id} onOpen={(id) => navigate(`/admin/kits/${kit.id}/files/${id}`)} />
        <div className="ms-auto"><KitActions kit={kit} size="sm" onDone={() => kitQuery.refetch()} /></div>
      </header>
      <div className="min-h-0 flex-1">
        {file.kind === 'presentation' ? (
          <PresentationGate key={file.id} kit={kit} file={file} onFile={(f) => { setFileOverride(f); void kitQuery.refetch() }} />
        ) : (
          <ViewerStudio key={file.id} kit={kit} file={file} />
        )}
      </div>
    </div>
  )
}

function FileSwitcher({ kit, current, onOpen }: { kit: KitDetail; current: string; onOpen: (id: string) => void }) {
  const { t } = useTranslation()
  if (kit.files.length < 2) return null
  return (
    <select className="input !w-auto max-w-[14rem] !py-1.5 text-xs" value={current} onChange={(e) => onOpen(e.target.value)} aria-label={t('kits.file.switch')}>
      {kit.files.map((f) => <option key={f.id} value={f.id}>{f.name}{f.open_comments ? ` (${f.open_comments})` : ''}</option>)}
    </select>
  )
}

/** A PPTX that was uploaded is converted into an editable deck the first time it is opened. */
function PresentationGate({ kit, file, onFile }: { kit: KitDetail; file: KitFile; onFile: (f: KitFile) => void }) {
  const { t } = useTranslation()
  const [state, setState] = useState<'idle' | 'working' | 'error'>('idle')
  const [message, setMessage] = useState<string | null>(null)
  const [warnings, setWarnings] = useState<string[]>([])

  const convert = useCallback(async () => {
    setState('working')
    setMessage(t('kits.file.importReading'))
    try {
      const link = (await api.get<{ data: { url: string } }>(`/admin/kits/${kit.id}/files/${file.id}/download`)).data.data.url
      const blob = await (await fetch(link)).blob()
      const { deck, warnings: w } = await importPptx(new File([blob], file.original_name ?? 'deck.pptx'))
      if (!deck.slides.length) throw new Error(t('kits.file.importEmpty'))
      setMessage(t('kits.file.importSaving', { count: deck.slides.length }))
      const res = await api.post<{ data: { file: KitFile } }>(`/admin/kits/${kit.id}/files/${file.id}/deck/import`, { deck })
      setWarnings(w)
      onFile(res.data.data.file)
    } catch (e) {
      setMessage(e instanceof Error && !('response' in e) ? e.message : errorMessage(e))
      setState('error')
    }
  }, [file.id, file.original_name, kit.id, onFile, t])

  useEffect(() => { if (!file.is_deck && file.has_binary && kit.can?.edit && state === 'idle') void convert() }, [file, kit.can?.edit, state, convert])

  if (file.is_deck) {
    return (
      <div className="h-full">
        {warnings.length > 0 && <ImportWarnings warnings={warnings} onClose={() => setWarnings([])} />}
        <DeckEditor kit={kit} file={file} onFileChange={onFile} />
      </div>
    )
  }
  return (
    <div className="grid h-full place-items-center p-8">
      <div className="max-w-md text-center">
        <span className="mx-auto grid size-16 place-items-center rounded-2xl bg-orange-50 text-orange-600">{state === 'working' ? <Loader2 className="size-8 animate-spin" /> : <Presentation className="size-8" />}</span>
        <h2 className="mt-4 text-xl font-bold text-navy-900">{state === 'error' ? t('kits.file.importFailed') : t('kits.file.preparing')}</h2>
        <p className="mt-2 text-sm text-slate-500" dir="auto">{message ?? (kit.can?.edit ? t('kits.file.preparingText') : t('kits.file.notEditableYet'))}</p>
        {state === 'error' && <div className="mt-5 flex justify-center gap-2"><Button variant="gold" onClick={convert}>{t('kits.common.retry')}</Button><Button variant="outline" icon={<Download className="size-4" />} onClick={async () => { const l = (await api.get<{ data: { url: string } }>(`/admin/kits/${kit.id}/files/${file.id}/download`)).data.data.url; window.open(l, '_blank') }}>{t('kits.file.download')}</Button></div>}
      </div>
    </div>
  )
}

function ImportWarnings({ warnings, onClose }: { warnings: string[]; onClose: () => void }) {
  const { t } = useTranslation()
  return (
    <div className="absolute inset-x-0 top-[57px] z-40 mx-auto max-w-2xl rounded-b-2xl border border-amber-200 bg-amber-50 p-4 text-sm text-amber-900 shadow-glass">
      <div className="flex items-start justify-between gap-3"><div><div className="font-bold">{t('kits.file.importNotes')}</div><ul className="mt-1 list-disc ps-5 text-xs">{warnings.slice(0, 8).map((w) => <li key={w}>{w}</li>)}</ul></div><button type="button" onClick={onClose} className="text-xs font-bold underline">{t('kits.common.close')}</button></div>
    </div>
  )
}

/** PDF, Word, image and video: a viewer plus the anchored review panel. */
function ViewerStudio({ kit, file }: { kit: KitDetail; file: KitFile }) {
  const { t } = useTranslation()
  const comments = useKitComments(kit.id, file.id)
  const all = useMemo(() => comments.data?.data ?? [], [comments.data])
  const [reviewing, setReviewing] = useState(false)
  const [draft, setDraft] = useState<Anchor | null>(null)
  const [active, setActive] = useState<string | null>(null)
  const [page, setPage] = useState(1)
  const [jump, setJump] = useState<{ page?: number; paragraph?: number; at?: number } | null>(null)
  const link = useGet<{ data: { url: string } }>(file.has_binary ? `/admin/kits/${kit.id}/files/${file.id}/download` : null)
  const url = link.data?.data.url ?? file.url ?? null

  const anchorLabel = useCallback((a: Anchor | null) => {
    if (!a || a.type === 'file') return null
    if (a.page) return t('kits.viewer.pageN', { n: a.page })
    if (a.type === 'text') return a.paragraph != null ? t('kits.viewer.paragraphN', { n: a.paragraph + 1 }) : t('kits.viewer.text')
    if (a.type === 'time') return `${Math.floor((a.at ?? 0) / 60)}:${String(Math.floor((a.at ?? 0) % 60)).padStart(2, '0')}`
    return t('kits.viewer.point')
  }, [t])

  const place = (a: Anchor) => { setDraft(a); setActive(null) }
  const onPin = (c: KitComment) => setActive(c.id)
  const onJump = (c: KitComment) => {
    setActive(c.id)
    const a = c.anchor
    if (a?.page) setJump({ page: a.page })
    else if (a?.paragraph != null) setJump({ paragraph: a.paragraph })
    else if (a?.at != null) setJump({ at: a.at })
  }
  const anchored = all.filter((c) => c.anchor && c.anchor.type !== 'file')
  const [params, setParams] = useSearchParams()
  const deepLink = params.get('comment')
  useEffect(() => {
    const c = deepLink ? all.find((x) => x.id === deepLink) : null
    if (!c) return
    setActive(c.id)
    setReviewing(true)
    if (c.anchor?.page) setJump({ page: c.anchor.page })
    else if (c.anchor?.paragraph != null) setJump({ paragraph: c.anchor.paragraph })
    else if (c.anchor?.at != null) setJump({ at: c.anchor.at })
    setParams({}, { replace: true })
  }, [deepLink, all, setParams])

  return (
    <div className="flex h-full min-h-0">
      <main className="relative flex min-w-0 flex-1 flex-col">
        <div className="flex items-center gap-1.5 border-b border-navy-100 bg-white px-3 py-1.5">
          <button type="button" aria-pressed={!reviewing} onClick={() => setReviewing(false)} className={clsx('inline-flex items-center gap-1.5 rounded-lg px-3 py-1.5 text-xs font-semibold', !reviewing ? 'bg-navy-900 text-white' : 'text-slate-600 hover:bg-navy-100/70')}><MousePointer2 className="size-4" />{t('kits.viewer.read')}</button>
          {kit.can?.comment && <button type="button" aria-pressed={reviewing} onClick={() => setReviewing(true)} className={clsx('inline-flex items-center gap-1.5 rounded-lg px-3 py-1.5 text-xs font-semibold', reviewing ? 'bg-gold-500 text-navy-950' : 'text-slate-600 hover:bg-navy-100/70')}><MessageSquareDashed className="size-4" />{t('kits.viewer.review')}</button>}
          {url && <a href={url} target="_blank" rel="noreferrer" className="ms-auto inline-flex items-center gap-1.5 rounded-lg px-3 py-1.5 text-xs font-semibold text-slate-600 hover:bg-navy-100/70"><Download className="size-4" />{t('kits.file.download')}</a>}
        </div>
        <div className="min-h-0 flex-1">
          {link.isLoading ? <Spinner className="h-full" /> : !url ? (
            <div className="grid h-full place-items-center p-8 text-center text-slate-500"><div><FileQuestion className="mx-auto mb-2 size-9 text-navy-200" />{t('kits.viewer.noPreview')}</div></div>
          ) : file.kind === 'pdf' ? (
            <PdfViewer url={url} reviewing={reviewing} comments={anchored} activeId={active} draft={draft} currentPage={page} onPage={setPage} onPlace={place} onPinClick={onPin} scrollTo={jump?.page ?? null} />
          ) : file.kind === 'document' && /\.docx$/i.test(file.original_name ?? file.name) ? (
            <DocxViewer url={url} reviewing={reviewing} comments={anchored} activeId={active} onPlace={place} onPinClick={onPin} scrollToParagraph={jump?.paragraph ?? null} />
          ) : file.kind === 'image' || file.kind === 'video' ? (
            <MediaViewer kind={file.kind} url={url} reviewing={reviewing} comments={anchored} activeId={active} draft={draft} onPlace={place} onPinClick={onPin} seekTo={jump?.at ?? null} />
          ) : (
            <div className="grid h-full place-items-center p-8 text-center text-slate-500"><div><FileQuestion className="mx-auto mb-2 size-9 text-navy-200" />{t('kits.viewer.noPreview')}<div className="mt-4"><a className="btn inline-flex items-center gap-2 rounded-xl bg-navy-900 px-4 py-2 text-sm font-semibold text-white" href={url} target="_blank" rel="noreferrer"><Download className="size-4" />{t('kits.file.download')}</a></div></div></div>
          )}
        </div>
      </main>
      <aside className="flex w-[380px] shrink-0 flex-col border-s border-navy-100 bg-white p-4">
        <h3 className="mb-3 text-sm font-bold text-navy-900">{t('kits.viewer.reviewPanel')}</h3>
        <div className="min-h-0 flex-1">
          <CommentsPanel kit={kit} fileId={file.id} fileVersion={file.version} comments={all} loading={comments.isLoading} draft={draft} onClearDraft={() => setDraft(null)} activeId={active} onActive={(c) => setActive(c?.id ?? null)}
            onJump={onJump} anchorLabel={anchorLabel} scopeLabel={file.kind === 'pdf' ? t('kits.viewer.thisPage') : undefined} scopeFilter={file.kind === 'pdf' ? (c) => c.anchor?.page === page : undefined} />
        </div>
      </aside>
    </div>
  )
}
