import clsx from 'clsx'
import { FileType2, ImagePlus, Loader2, Trash2 } from 'lucide-react'
import { useRef, useState, type ReactNode } from 'react'
import { useTranslation } from 'react-i18next'
import { api, errorMessage } from '@/lib/api'

const HEX = /^#[0-9a-f]{6}$/i

/** Colour swatch with native picker + editable hex value. */
export function ColorField({ label, hint, value, onChange }: { label: string; hint?: string; value: string; onChange: (hex: string) => void }) {
  const [text, setText] = useState(value)
  const [prev, setPrev] = useState(value)
  if (prev !== value) {
    setPrev(value)
    setText(value)
  }

  return (
    <div className="flex items-center gap-4 rounded-2xl border border-navy-100 bg-white p-3 transition hover:border-gold-300">
      <label className="relative size-12 shrink-0 cursor-pointer overflow-hidden rounded-xl ring-1 ring-black/10 shadow-inner" style={{ background: value }}>
        <input type="color" value={value} onChange={(e) => onChange(e.target.value.toUpperCase())} className="absolute inset-0 size-full cursor-pointer opacity-0" aria-label={label} />
      </label>
      <div className="min-w-0 flex-1">
        <div className="text-sm font-bold text-navy-900">{label}</div>
        {hint && <div className="truncate text-xs text-slate-400">{hint}</div>}
      </div>
      <input
        dir="ltr"
        value={text}
        maxLength={7}
        onChange={(e) => {
          const v = e.target.value.startsWith('#') ? e.target.value : `#${e.target.value}`
          setText(v)
          if (HEX.test(v)) onChange(v.toUpperCase())
        }}
        className={clsx('w-24 rounded-lg border px-2 py-1.5 text-center font-mono text-xs uppercase outline-none', HEX.test(text) ? 'border-navy-100 focus:border-gold-500' : 'border-danger text-danger')}
      />
    </div>
  )
}

export function SliderField({ label, value, min, max, unit = '', onChange }: { label: string; value: number; min: number; max: number; unit?: string; onChange: (n: number) => void }) {
  return (
    <div>
      <div className="mb-2 flex items-center justify-between text-sm">
        <span className="font-semibold text-navy-800">{label}</span>
        <span className="rounded-lg bg-navy-100/70 px-2 py-0.5 font-mono text-xs text-navy-900" dir="ltr">{value}{unit}</span>
      </div>
      <input type="range" min={min} max={max} value={value} onChange={(e) => onChange(Number(e.target.value))} className="w-full accent-[var(--color-gold-500)]" />
    </div>
  )
}

export function Segmented<T extends string>({ options, value, onChange }: { options: { id: T; label: ReactNode }[]; value: T; onChange: (v: T) => void }) {
  return (
    <div className="inline-flex rounded-xl border border-navy-100 bg-white p-1">
      {options.map((o) => (
        <button key={o.id} type="button" onClick={() => onChange(o.id)}
          className={clsx('rounded-lg px-3.5 py-1.5 text-sm font-semibold transition', value === o.id ? 'bg-navy-900 text-white shadow' : 'text-slate-500 hover:text-navy-900')}>
          {o.label}
        </button>
      ))}
    </div>
  )
}

/** Upload (or paste) an image into the public theme assets. */
export function ImageField({ label, value, kind, onChange, aspect = 'aspect-video', contain = false, onDark = false }: { label: string; value: string | null; kind: 'hero' | 'banner' | 'pattern' | 'logo'; onChange: (url: string | null) => void; aspect?: string; contain?: boolean; onDark?: boolean }) {
  const { t } = useTranslation()
  const input = useRef<HTMLInputElement>(null)
  const [busy, setBusy] = useState(false)
  const [error, setError] = useState<string | null>(null)

  const upload = async (file: File) => {
    setBusy(true)
    setError(null)
    const body = new FormData()
    body.append('file', file)
    body.append('kind', kind)
    try {
      const { data } = await api.post('/admin/theme/assets', body)
      onChange(data.data.url)
    } catch (e) {
      setError(errorMessage(e))
    } finally {
      setBusy(false)
    }
  }

  return (
    <div>
      <div className={clsx('mb-2 text-sm font-semibold', onDark ? 'text-white' : 'text-navy-800')}>{label}</div>
      <div
        onDragOver={(e) => e.preventDefault()}
        onDrop={(e) => { e.preventDefault(); const f = e.dataTransfer.files[0]; if (f) upload(f) }}
        className={clsx('group relative overflow-hidden rounded-2xl border-2 border-dashed transition', value ? 'border-transparent' : onDark ? 'border-white/30 hover:border-gold-300' : 'border-navy-100 hover:border-gold-400', aspect)}
      >
        {value ? (
          <>
            <img src={value} alt="" className={clsx('absolute inset-0 size-full', contain ? 'object-contain p-4' : 'object-cover')} />
            <div className="absolute inset-0 flex items-center justify-center gap-2 bg-navy-950/60 opacity-0 transition group-hover:opacity-100">
              <button type="button" onClick={() => input.current?.click()} className="rounded-lg bg-white px-3 py-1.5 text-xs font-bold text-navy-900">{t('admin.brand.replace')}</button>
              <button type="button" onClick={() => onChange(null)} className="rounded-lg bg-danger px-3 py-1.5 text-xs font-bold text-white"><Trash2 className="inline size-3.5" /> {t('admin.brand.remove')}</button>
            </div>
          </>
        ) : (
          <button type="button" onClick={() => input.current?.click()} className={clsx('absolute inset-0 flex flex-col items-center justify-center gap-2', onDark ? 'bg-white/5 text-white/80 hover:text-white' : 'bg-ivory/60 text-slate-500 hover:text-navy-900')}>
            {busy ? <Loader2 className="size-6 animate-spin text-gold-500" /> : <ImagePlus className={clsx('size-6', onDark ? 'text-gold-300' : 'text-gold-600')} />}
            <span className="text-xs font-semibold">{busy ? t('admin.brand.uploading') : t('admin.brand.upload')}</span>
          </button>
        )}
        <input ref={input} type="file" accept="image/*" className="hidden" onChange={(e) => { const f = e.target.files?.[0]; if (f) upload(f); e.target.value = '' }} />
      </div>
      <input dir="ltr" className="input mt-2 py-1.5 text-xs" placeholder={t('admin.brand.orUrl')} value={value ?? ''} onChange={(e) => onChange(e.target.value.trim() || null)} />
      {error && <p className="mt-1 text-xs text-danger">{error}</p>}
    </div>
  )
}

/** Uploads a web font file and reports its public URL. */
export function FontField({ label, value, onChange }: { label: string; value: string | null; onChange: (url: string | null) => void }) {
  const { t } = useTranslation()
  const input = useRef<HTMLInputElement>(null)
  const [busy, setBusy] = useState(false)
  const [error, setError] = useState<string | null>(null)

  const upload = async (file: File) => {
    setBusy(true)
    setError(null)
    const body = new FormData()
    body.append('file', file)
    body.append('kind', 'font')
    try {
      const { data } = await api.post('/admin/theme/assets', body)
      onChange(data.data.url)
    } catch (e) {
      setError(errorMessage(e))
    } finally {
      setBusy(false)
    }
  }

  return (
    <div>
      <div className="mb-2 text-sm font-semibold text-navy-800">{label}</div>
      <div className="flex items-center gap-3 rounded-2xl border border-dashed border-navy-100 bg-ivory/60 p-3">
        <span className="grid size-10 place-items-center rounded-xl bg-navy-900 text-gold-300">{busy ? <Loader2 className="size-5 animate-spin" /> : <FileType2 className="size-5" />}</span>
        <span className="min-w-0 flex-1 truncate font-mono text-xs text-slate-500" dir="ltr">{value ? decodeURIComponent(value.split('/').pop() ?? '') : '—'}</span>
        <button type="button" onClick={() => input.current?.click()} className="rounded-lg bg-white px-3 py-1.5 text-xs font-bold text-navy-900 ring-1 ring-navy-100 hover:ring-gold-400">{value ? t('admin.brand.replace') : t('admin.brand.typography.uploadFont')}</button>
        {value && <button type="button" onClick={() => onChange(null)} className="p-1 text-slate-400 hover:text-danger" aria-label={t('admin.brand.remove')}><Trash2 className="size-4" /></button>}
        <input ref={input} type="file" accept=".woff2,.woff,.ttf,.otf" className="hidden" onChange={(e) => { const f = e.target.files?.[0]; if (f) upload(f); e.target.value = '' }} />
      </div>
      {error && <p className="mt-1 text-xs text-danger">{error}</p>}
    </div>
  )
}
