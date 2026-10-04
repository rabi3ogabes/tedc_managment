import clsx from 'clsx'
import { AudioLines, Clapperboard, ImagePlus, Loader2, Mic, Play, Sparkles, Upload, Video } from 'lucide-react'
import { useRef, useState } from 'react'
import { useTranslation } from 'react-i18next'
import { Button, Empty, Modal, Progress, Spinner, Tabs } from '@/components/ui'
import { useGet } from '@/hooks/useApi'
import { api, errorMessage } from '@/lib/api'
import { putFile, videoDuration } from '../../program/course/api'
import type { Asset, MediaKind } from '../types'

type Mode = 'ai' | 'library' | 'upload'
type Props = {
  kitId: string
  kind?: MediaKind
  initial?: Mode
  prompt?: string
  canGenerate: boolean
  onPick: (asset: Asset) => void
  onClose: () => void
  /** Opens the Video Studio (the place AI videos are made). */
  onVideoStudio?: () => void
}

const STYLES = ['flat', 'watercolor', 'realistic', 'infographic', 'minimal']
const ACCEPT: Record<MediaKind, string> = { image: 'image/png,image/jpeg,image/webp,image/gif,image/svg+xml', video: 'video/mp4,video/webm,video/quicktime', audio: 'audio/mpeg,audio/mp4,audio/wav,audio/webm,audio/ogg,audio/aac,.mp3,.m4a,.wav' }
const ICON = { image: ImagePlus, video: Video, audio: AudioLines } as const

const audioDuration = (file: File): Promise<number> => new Promise((resolve) => {
  const url = URL.createObjectURL(file)
  const el = document.createElement('audio')
  el.preload = 'metadata'
  el.onloadedmetadata = () => { resolve(Number.isFinite(el.duration) ? Math.round(el.duration) : 0); URL.revokeObjectURL(url) }
  el.onerror = () => { resolve(0); URL.revokeObjectURL(url) }
  el.src = url
})
const clock = (s?: number | null) => (s ? `${Math.floor(s / 60)}:${String(Math.floor(s % 60)).padStart(2, '0')}` : '')

/**
 * Choose a picture, a video or a sound for a slide: have it made by AI from a written prompt, take one from the kit's
 * library, or upload a file from the computer.
 */
export default function MediaPickerDialog({ kitId, kind = 'image', initial = 'library', prompt: seed = '', canGenerate, onPick, onClose, onVideoStudio }: Props) {
  const { t } = useTranslation()
  const status = useGet<{ data: { ai: boolean; image_provider: string; audio_provider: string | null; voices: string[] } }>('/admin/kits/ai/status')
  const aiPossible = canGenerate && (kind !== 'video' || !!onVideoStudio)
  const [mode, setMode] = useState<Mode>(initial === 'ai' && !aiPossible ? 'library' : initial)
  const [prompt, setPrompt] = useState(seed)
  const [aspect, setAspect] = useState<'16:9' | '4:3' | '1:1'>('4:3')
  const [style, setStyle] = useState('flat')
  const [voice, setVoice] = useState('nova')
  const [busy, setBusy] = useState(false)
  const [progress, setProgress] = useState<number | null>(null)
  const [error, setError] = useState<string | null>(null)
  const [made, setMade] = useState<Asset[]>([])
  const file = useRef<HTMLInputElement>(null)
  const library = useGet<{ data: Asset[] }>(mode === 'library' ? `/admin/kits/${kitId}/assets` : null, { kind })
  const Icon = ICON[kind]

  const generate = async () => {
    setBusy(true); setError(null)
    try {
      const res = kind === 'audio'
        ? await api.post<{ data: Asset }>(`/admin/kits/${kitId}/ai/audio`, { text: prompt, voice })
        : await api.post<{ data: Asset }>(`/admin/kits/${kitId}/ai/image`, { prompt, aspect, style: t(`kits.image.styles.${style}`) })
      setMade((cur) => [res.data.data, ...cur])
    } catch (e) { setError(errorMessage(e)) } finally { setBusy(false) }
  }

  const upload = async (f: File | undefined) => {
    if (!f) return
    setBusy(true); setError(null); setProgress(null)
    try {
      if (kind === 'image') {
        const body = new FormData()
        body.append('image', f)
        onPick((await api.post<{ data: Asset }>(`/admin/kits/${kitId}/assets`, body)).data.data)
        return
      }
      // Videos and sounds go straight to storage (too big for the API), with a progress bar.
      const mime = f.type || (kind === 'audio' ? 'audio/mpeg' : 'video/mp4')
      const signed = (await api.post<{ data: Parameters<typeof putFile>[0] & { path: string } }>(`/admin/kits/${kitId}/assets/upload-url`, { filename: f.name, mime, size: f.size })).data.data
      setProgress(0)
      await putFile(signed, f, setProgress)
      const duration = kind === 'video' ? await videoDuration(f) : await audioDuration(f)
      onPick((await api.post<{ data: Asset }>(`/admin/kits/${kitId}/assets/complete`, { path: signed.path, name: f.name, mime, size: f.size, duration })).data.data)
    } catch (e) { setError(errorMessage(e)) } finally { setBusy(false); setProgress(null) }
  }

  /** One result / library item, shaped for what it is. */
  const card = (a: Asset) => {
    if (kind === 'audio') {
      return (
        <div key={a.id} className="flex items-center gap-3 rounded-xl border border-navy-100 bg-white p-2.5">
          <span className="grid size-10 shrink-0 place-items-center rounded-lg bg-navy-900 text-gold-300"><AudioLines className="size-5" /></span>
          <div className="min-w-0 flex-1"><div className="truncate text-sm font-bold text-navy-900" dir="auto">{a.prompt || a.name}</div><audio src={a.url} controls preload="none" className="mt-1 h-8 w-full" /></div>
          <Button size="sm" variant="gold" onClick={() => onPick(a)}>{t('kits.media.use')}</Button>
        </div>
      )
    }
    return (
      <button key={a.id} type="button" onClick={() => onPick(a)} className="group relative overflow-hidden rounded-xl border border-navy-100 bg-ivory text-start transition hover:-translate-y-0.5 hover:border-gold-400 hover:shadow-glass">
        {kind === 'video'
          ? <span className="relative block aspect-video bg-black"><video src={`${a.url}#t=0.1`} preload="metadata" muted playsInline className="size-full object-cover" /><span className="absolute inset-0 grid place-items-center bg-black/20"><span className="grid size-10 place-items-center rounded-full bg-white/90 text-navy-900"><Play className="size-5" fill="currentColor" /></span></span>{a.duration ? <span className="absolute bottom-1.5 end-1.5 rounded bg-black/70 px-1.5 py-px text-[10px] font-bold text-white" dir="ltr">{clock(a.duration)}</span> : null}</span>
          : <img src={a.url} alt={a.prompt ?? a.name ?? ''} className="aspect-[4/3] w-full object-cover" loading="lazy" />}
        <span className="absolute inset-x-0 bottom-0 truncate bg-gradient-to-t from-black/60 to-transparent px-2 pb-1.5 pt-6 text-[11px] font-semibold text-white opacity-0 transition group-hover:opacity-100">{a.prompt ?? a.name}</span>
        {a.provider && <span className="absolute start-1.5 top-1.5 rounded-full bg-white/90 px-1.5 py-px text-[9px] font-bold text-navy-800">{t(`kits.image.providers.${a.provider}`, { defaultValue: a.provider })}</span>}
      </button>
    )
  }
  const list = (items: Asset[]) => <div className={clsx('max-h-[50vh] overflow-y-auto pe-1', kind === 'audio' ? 'space-y-2' : 'grid grid-cols-2 gap-3 sm:grid-cols-3')}>{items.map(card)}</div>

  const aiAudio = status.data?.data.audio_provider
  return (
    <Modal open onClose={onClose} wide title={t(`kits.media.title.${kind}`)}>
      <Tabs tabs={[...(aiPossible ? [{ id: 'ai' as const, label: t('kits.image.tabAi') }] : []), { id: 'library' as const, label: t('kits.image.tabLibrary') }, { id: 'upload' as const, label: t(`kits.media.tabUpload.${kind}`) }]} value={mode} onChange={setMode} />
      {error && <div className="mb-3 rounded-xl bg-red-50 p-3 text-sm text-danger">{error}</div>}

      {mode === 'ai' && kind === 'video' && (
        <div className="grid place-items-center gap-3 rounded-2xl bg-ivory px-6 py-10 text-center">
          <span className="grid size-14 place-items-center rounded-2xl bg-gradient-to-br from-gold-400 to-gold-600 text-navy-950"><Clapperboard className="size-7" /></span>
          <p className="max-w-md text-sm text-slate-600">{t('kits.media.videoAi')}</p>
          <Button variant="gold" icon={<Sparkles className="size-4" />} onClick={onVideoStudio}>{t('kits.media.openVideoStudio')}</Button>
        </div>
      )}

      {mode === 'ai' && kind !== 'video' && (
        <div className="space-y-4">
          <label className="block">
            <span className="label">{t(kind === 'audio' ? 'kits.media.describeAudio' : 'kits.image.describe')}</span>
            <textarea rows={kind === 'audio' ? 5 : 3} className="input" dir="auto" value={prompt} placeholder={t(kind === 'audio' ? 'kits.media.audioPlaceholder' : 'kits.image.placeholder')} onChange={(e) => setPrompt(e.target.value)} />
          </label>
          <div className="flex flex-wrap items-center gap-3">
            {kind === 'image' ? (
              <>
                <div className="flex gap-1" role="radiogroup" aria-label={t('kits.image.aspect')}>{(['16:9', '4:3', '1:1'] as const).map((a) => <button key={a} type="button" role="radio" aria-checked={aspect === a} onClick={() => setAspect(a)} className={clsx('rounded-lg border px-3 py-1.5 text-xs font-bold transition', aspect === a ? 'border-navy-900 bg-navy-900 text-white' : 'border-navy-100 bg-white text-slate-600')} dir="ltr">{a}</button>)}</div>
                <div className="flex flex-wrap gap-1">{STYLES.map((s) => <button key={s} type="button" aria-pressed={style === s} onClick={() => setStyle(s)} className={clsx('rounded-full border px-3 py-1 text-xs font-semibold transition', style === s ? 'border-gold-500 bg-gold-100 text-gold-700' : 'border-navy-100 bg-white text-slate-600')}>{t(`kits.image.styles.${s}`)}</button>)}</div>
              </>
            ) : (
              <div className="flex flex-wrap items-center gap-1.5"><Mic className="size-4 text-gold-600" />{(status.data?.data.voices ?? ['nova']).map((v) => <button key={v} type="button" aria-pressed={voice === v} onClick={() => setVoice(v)} className={clsx('rounded-full border px-3 py-1 text-xs font-semibold capitalize transition', voice === v ? 'border-gold-500 bg-gold-100 text-gold-700' : 'border-navy-100 bg-white text-slate-600')}>{v}</button>)}</div>
            )}
            <Button className="ms-auto" variant="gold" loading={busy} disabled={prompt.trim().length < 3 || (kind === 'audio' && !aiAudio)} icon={<Sparkles className="size-4" />} onClick={generate}>{made.length ? t('kits.image.again') : t(kind === 'audio' ? 'kits.media.generateAudio' : 'kits.image.generate')}</Button>
          </div>
          {kind === 'audio' && status.data && !aiAudio && <p className="rounded-xl bg-amber-50 px-3 py-2 text-xs font-semibold text-amber-800">{t('kits.media.audioUnavailable')}</p>}
          {kind === 'image' && status.data && <p className="rounded-xl bg-ivory px-3 py-2 text-xs text-slate-500">{t(`kits.image.providerNote.${status.data.data.image_provider}`)}</p>}
          {busy && <div className="flex items-center gap-2 text-sm text-slate-500"><Loader2 className="size-4 animate-spin" />{t('kits.image.working')}</div>}
          {made.length > 0 && <div><h4 className="mb-2 text-sm font-bold text-navy-900">{t('kits.image.results')}</h4>{list(made)}</div>}
        </div>
      )}

      {mode === 'library' && (library.isLoading ? <Spinner /> : !library.data?.data.length ? <Empty text={t(`kits.media.libraryEmpty.${kind}`)} /> : list(library.data.data))}

      {mode === 'upload' && (
        <div>
          <button type="button" disabled={busy} onClick={() => file.current?.click()} onDragOver={(e) => e.preventDefault()} onDrop={(e) => { e.preventDefault(); void upload(e.dataTransfer.files[0]) }}
            className="grid w-full place-items-center gap-2 rounded-2xl border-2 border-dashed border-navy-200 bg-ivory/60 px-6 py-14 text-slate-500 transition hover:border-gold-400 hover:text-navy-900 disabled:opacity-70">
            {busy ? <Loader2 className="size-8 animate-spin" /> : <Icon className="size-9 text-gold-600" />}
            <span className="font-semibold">{t(`kits.media.drop.${kind}`)}</span>
            <span className="text-xs">{t(`kits.media.formats.${kind}`)}</span>
            <span className="mt-1 inline-flex items-center gap-1.5 rounded-lg bg-navy-900 px-3 py-1.5 text-xs font-semibold text-white"><Upload className="size-3.5" />{t('kits.image.choose')}</span>
            <input ref={file} type="file" accept={ACCEPT[kind]} className="hidden" onChange={(e) => void upload(e.target.files?.[0])} />
          </button>
          {progress !== null && <div className="mt-3"><Progress value={progress} /><p className="mt-1 text-center text-xs text-slate-500">{progress}%</p></div>}
        </div>
      )}
    </Modal>
  )
}
