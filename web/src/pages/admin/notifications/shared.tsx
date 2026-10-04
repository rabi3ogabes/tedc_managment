import clsx from 'clsx'
import { BellRing, Smartphone } from 'lucide-react'
import { useEffect, useState } from 'react'
import { api } from '@/lib/api'

export type Template = {
  id: string; event: string; is_system: boolean; name_ar: string; name_en: string; title_ar: string; title_en: string; body_ar: string | null; body_en: string | null
  enabled: boolean; push: boolean; email: boolean; sms: boolean; group: string; customised: boolean; default: { title_ar: string; title_en: string; body_ar: string; body_en: string } | null
}
export type TemplateData = { groups: Record<string, { ar: string; en: string }>; variables: string[]; templates: Template[] }
export type Rendered = { title_ar: string; title_en: string; body_ar: string; body_en: string }

export function useDebounced<T>(value: T, ms = 350): T {
  const [v, setV] = useState(value)
  useEffect(() => { const id = setTimeout(() => setV(value), ms); return () => clearTimeout(id) }, [value, ms])
  return v
}

/** Renders wording with the data of a real program on the server, so the preview is what trainees will read. */
export function usePreview(fields: { title_ar: string; title_en: string; body_ar: string; body_en: string }, programId?: string | null) {
  const debounced = useDebounced(fields)
  const [out, setOut] = useState<Rendered | null>(null)
  useEffect(() => {
    let live = true
    api.post('/admin/notifications/templates/preview', { ...debounced, ...(programId ? { program_id: programId } : {}) }).then((r) => live && setOut(r.data.data)).catch(() => undefined)
    return () => { live = false }
  }, [debounced, programId])
  return out
}

/** A phone notification as the trainee will see it. */
export function PhoneNotification({ title, body, lang, appName }: { title: string; body: string; lang: 'ar' | 'en'; appName: string }) {
  return (
    <div dir={lang === 'ar' ? 'rtl' : 'ltr'} className="rounded-2xl border border-navy-100 bg-white p-3.5 shadow-md">
      <div className="mb-1.5 flex items-center gap-2 text-[11px] text-slate-400">
        <span className="grid size-5 place-items-center rounded-md bg-navy-900 text-gold-300"><BellRing className="size-3" /></span>
        <span className="font-semibold">{appName}</span><span>•</span><span>{lang === 'ar' ? 'الآن' : 'now'}</span>
        <Smartphone className="ms-auto size-3.5" />
      </div>
      <div className={clsx('text-sm font-bold text-navy-900', !title && 'text-slate-300')}>{title || '—'}</div>
      {body && <div className="mt-0.5 text-[13px] leading-relaxed text-slate-600">{body}</div>}
    </div>
  )
}

export function Switch({ checked, onChange, label, small }: { checked: boolean; onChange: (v: boolean) => void; label: string; small?: boolean }) {
  return (
    <button type="button" role="switch" aria-checked={checked} aria-label={label} onClick={() => onChange(!checked)}
      className={clsx('relative inline-flex shrink-0 items-center rounded-full p-0.5 transition-colors', small ? 'h-6 w-11' : 'h-7 w-12', checked ? 'justify-end bg-emerald-500' : 'justify-start bg-slate-300')}>
      <span className={clsx('inline-block rounded-full bg-white shadow-md transition-all', small ? 'size-5' : 'size-6')} />
    </button>
  )
}

/** Inserts a placeholder at the cursor of the focused field. */
export function VariableChips({ variables, onInsert }: { variables: string[]; onInsert: (token: string) => void }) {
  return (
    <div className="flex flex-wrap gap-1.5">
      {variables.map((v) => (
        <button key={v} type="button" onMouseDown={(e) => e.preventDefault()} onClick={() => onInsert(`{{${v}}}`)}
          className="rounded-full bg-gold-100 px-2.5 py-1 font-mono text-[11px] font-bold text-gold-700 ring-1 ring-inset ring-gold-300/60 transition hover:bg-gold-200" dir="ltr">{`{{${v}}}`}</button>
      ))}
    </div>
  )
}
