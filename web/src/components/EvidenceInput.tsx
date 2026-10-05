import { Link2, Paperclip, X } from 'lucide-react'
import { useRef, useState } from 'react'
import { useTranslation } from 'react-i18next'

export type Evidence = { files: File[]; links: string[] }
export const noEvidence = (): Evidence => ({ files: [], links: [] })

/** Files and links that back an answer (impact forms, trainer reflection …). The server decides what it accepts; this only collects. */
export default function EvidenceInput({ value, onChange, max = 3 }: { value: Evidence; onChange: (v: Evidence) => void; max?: number }) {
  const { t } = useTranslation()
  const file = useRef<HTMLInputElement>(null)
  const [link, setLink] = useState('')
  const count = value.files.length + value.links.length
  return (
    <div className="space-y-2 rounded-xl border border-dashed border-navy-200 p-3 text-sm">
      <div className="text-xs text-slate-500">{t('evalc.my.evidenceHint')}</div>
      <div className="flex flex-wrap gap-2">
        {value.files.map((f, i) => <span key={`f${i}`} className="inline-flex items-center gap-1 rounded-full bg-ivory px-3 py-1"><Paperclip className="size-3.5" />{f.name}<button type="button" aria-label="remove" onClick={() => onChange({ ...value, files: value.files.filter((_, j) => j !== i) })}><X className="size-3.5" /></button></span>)}
        {value.links.map((l, i) => <span key={`l${i}`} className="inline-flex items-center gap-1 rounded-full bg-ivory px-3 py-1" dir="ltr"><Link2 className="size-3.5" />{l.slice(0, 40)}<button type="button" aria-label="remove" onClick={() => onChange({ ...value, links: value.links.filter((_, j) => j !== i) })}><X className="size-3.5" /></button></span>)}
      </div>
      {count < max && (
        <div className="flex flex-wrap items-center gap-2">
          <button type="button" className="rounded-xl border border-navy-100 px-3 py-1.5 font-semibold text-navy-900" onClick={() => file.current?.click()}>{t('evalc.my.addFile')}</button>
          <input ref={file} type="file" hidden accept=".pdf,.jpg,.jpeg,.png,.doc,.docx,.xls,.xlsx,.mp4" onChange={(e) => { const f = e.target.files?.[0]; if (f) onChange({ ...value, files: [...value.files, f] }); e.target.value = '' }} />
          <input className="input min-w-0 flex-1 text-xs" dir="ltr" placeholder={t('evalc.my.link')} value={link} onChange={(e) => setLink(e.target.value)} />
          <button type="button" className="rounded-xl border border-navy-100 px-3 py-1.5 font-semibold text-navy-900" disabled={!/^https?:\/\//i.test(link)} onClick={() => { onChange({ ...value, links: [...value.links, link] }); setLink('') }}>{t('evalc.my.addLink')}</button>
        </div>
      )}
    </div>
  )
}
