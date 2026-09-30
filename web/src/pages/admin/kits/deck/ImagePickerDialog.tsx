import clsx from 'clsx'
import { ImagePlus, Loader2, Sparkles, Upload } from 'lucide-react'
import { useRef, useState } from 'react'
import { useTranslation } from 'react-i18next'
import { Button, Empty, Modal, Spinner, Tabs } from '@/components/ui'
import { useGet } from '@/hooks/useApi'
import { api, errorMessage } from '@/lib/api'
import type { Asset } from '../types'

type Mode = 'ai' | 'library' | 'upload'
type Props = { kitId: string; initial?: Mode; prompt?: string; canGenerate: boolean; onPick: (asset: Asset) => void; onClose: () => void }

const STYLES = ['flat', 'watercolor', 'realistic', 'infographic', 'minimal']

/** Choose a picture: describe it and let the system draw it, reuse one from the kit library, or upload your own. */
export default function ImagePickerDialog({ kitId, initial = 'library', prompt: seed = '', canGenerate, onPick, onClose }: Props) {
  const { t } = useTranslation()
  const [mode, setMode] = useState<Mode>(initial === 'ai' && !canGenerate ? 'library' : initial)
  const [prompt, setPrompt] = useState(seed)
  const [aspect, setAspect] = useState<'16:9' | '4:3' | '1:1'>('4:3')
  const [style, setStyle] = useState('flat')
  const [busy, setBusy] = useState(false)
  const [error, setError] = useState<string | null>(null)
  const [made, setMade] = useState<Asset[]>([])
  const file = useRef<HTMLInputElement>(null)
  const library = useGet<{ data: Asset[] }>(mode === 'library' ? `/admin/kits/${kitId}/assets` : null)
  const status = useGet<{ data: { ai: boolean; image_provider: string } }>('/admin/kits/ai/status')

  const generate = async () => {
    setBusy(true)
    setError(null)
    try {
      const res = await api.post<{ data: Asset }>(`/admin/kits/${kitId}/ai/image`, { prompt, aspect, style: t(`kits.image.styles.${style}`) })
      setMade((cur) => [res.data.data, ...cur])
    } catch (e) {
      setError(errorMessage(e))
    } finally {
      setBusy(false)
    }
  }

  const upload = async (f: File | undefined) => {
    if (!f) return
    setBusy(true)
    setError(null)
    try {
      const body = new FormData()
      body.append('image', f)
      const res = await api.post<{ data: Asset }>(`/admin/kits/${kitId}/assets`, body)
      onPick(res.data.data)
    } catch (e) {
      setError(errorMessage(e))
    } finally {
      setBusy(false)
    }
  }

  const grid = (items: Asset[]) => (
    <div className="grid max-h-[50vh] grid-cols-2 gap-3 overflow-y-auto pe-1 sm:grid-cols-3">
      {items.map((a) => (
        <button key={a.id} type="button" onClick={() => onPick(a)} className="group relative overflow-hidden rounded-xl border border-navy-100 bg-ivory text-start transition hover:-translate-y-0.5 hover:border-gold-400 hover:shadow-glass">
          <img src={a.url} alt={a.prompt ?? a.name ?? ''} className="aspect-[4/3] w-full object-cover" loading="lazy" />
          <span className="absolute inset-x-0 bottom-0 truncate bg-gradient-to-t from-black/60 to-transparent px-2 pb-1.5 pt-6 text-[11px] font-semibold text-white opacity-0 transition group-hover:opacity-100">{a.prompt ?? a.name}</span>
          {a.provider && <span className="absolute start-1.5 top-1.5 rounded-full bg-white/90 px-1.5 py-px text-[9px] font-bold text-navy-800">{t(`kits.image.providers.${a.provider}`, { defaultValue: a.provider })}</span>}
        </button>
      ))}
    </div>
  )

  return (
    <Modal open onClose={onClose} wide title={t('kits.image.title')}>
      <Tabs tabs={[...(canGenerate ? [{ id: 'ai' as const, label: t('kits.image.tabAi') }] : []), { id: 'library' as const, label: t('kits.image.tabLibrary') }, { id: 'upload' as const, label: t('kits.image.tabUpload') }]} value={mode} onChange={setMode} />
      {error && <div className="mb-3 rounded-xl bg-red-50 p-3 text-sm text-danger">{error}</div>}

      {mode === 'ai' && (
        <div className="space-y-4">
          <label className="block">
            <span className="label">{t('kits.image.describe')}</span>
            <textarea rows={3} className="input" dir="auto" value={prompt} placeholder={t('kits.image.placeholder')} onChange={(e) => setPrompt(e.target.value)} />
          </label>
          <div className="flex flex-wrap items-center gap-3">
            <div className="flex gap-1" role="radiogroup" aria-label={t('kits.image.aspect')}>
              {(['16:9', '4:3', '1:1'] as const).map((a) => <button key={a} type="button" role="radio" aria-checked={aspect === a} onClick={() => setAspect(a)} className={clsx('rounded-lg border px-3 py-1.5 text-xs font-bold transition', aspect === a ? 'border-navy-900 bg-navy-900 text-white' : 'border-navy-100 bg-white text-slate-600')} dir="ltr">{a}</button>)}
            </div>
            <div className="flex flex-wrap gap-1">
              {STYLES.map((s) => <button key={s} type="button" aria-pressed={style === s} onClick={() => setStyle(s)} className={clsx('rounded-full border px-3 py-1 text-xs font-semibold transition', style === s ? 'border-gold-500 bg-gold-100 text-gold-700' : 'border-navy-100 bg-white text-slate-600')}>{t(`kits.image.styles.${s}`)}</button>)}
            </div>
            <Button className="ms-auto" variant="gold" loading={busy} disabled={prompt.trim().length < 3} icon={<Sparkles className="size-4" />} onClick={generate}>{made.length ? t('kits.image.again') : t('kits.image.generate')}</Button>
          </div>
          {status.data && <p className="rounded-xl bg-ivory px-3 py-2 text-xs text-slate-500">{t(`kits.image.providerNote.${status.data.data.image_provider}`)}</p>}
          {busy && <div className="flex items-center gap-2 text-sm text-slate-500"><Loader2 className="size-4 animate-spin" />{t('kits.image.working')}</div>}
          {made.length > 0 && <div><h4 className="mb-2 text-sm font-bold text-navy-900">{t('kits.image.results')}</h4>{grid(made)}</div>}
        </div>
      )}

      {mode === 'library' && (library.isLoading ? <Spinner /> : !library.data?.data.length ? <Empty text={t('kits.image.libraryEmpty')} /> : grid(library.data.data))}

      {mode === 'upload' && (
        <button type="button" onClick={() => file.current?.click()} onDragOver={(e) => e.preventDefault()} onDrop={(e) => { e.preventDefault(); void upload(e.dataTransfer.files[0]) }}
          className="grid w-full place-items-center gap-2 rounded-2xl border-2 border-dashed border-navy-200 bg-ivory/60 px-6 py-14 text-slate-500 transition hover:border-gold-400 hover:text-navy-900">
          {busy ? <Loader2 className="size-8 animate-spin" /> : <ImagePlus className="size-9 text-gold-600" />}
          <span className="font-semibold">{t('kits.image.drop')}</span>
          <span className="text-xs">PNG · JPG · WEBP · SVG · 8 MB</span>
          <span className="mt-1 inline-flex items-center gap-1.5 rounded-lg bg-navy-900 px-3 py-1.5 text-xs font-semibold text-white"><Upload className="size-3.5" />{t('kits.image.choose')}</span>
          <input ref={file} type="file" accept="image/png,image/jpeg,image/webp,image/gif,image/svg+xml" className="hidden" onChange={(e) => void upload(e.target.files?.[0])} />
        </button>
      )}
    </Modal>
  )
}
