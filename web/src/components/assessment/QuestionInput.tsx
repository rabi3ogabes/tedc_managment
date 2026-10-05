import clsx from 'clsx'
import { ArrowDown, ArrowUp } from 'lucide-react'
import { useEffect, useRef } from 'react'
import { useTranslation } from 'react-i18next'

/* eslint-disable @typescript-eslint/no-explicit-any */
export type ExamQuestion = { id: string; type: string; stem_ar: string; stem_en: string | null; media?: { image?: string } | null; points: number; payload: any }
type Props = { q: ExamQuestion; value: any; onChange: (v: any) => void; disabled?: boolean }

const lang = (ar: boolean, a?: string | null, e?: string | null) => (ar ? a || e || '' : e || a || '')

/** One input component for every question type. Drag-style types (matching, ordering, categorization) use selects and up/down buttons, so they work with a keyboard and a screen reader. */
export default function QuestionInput({ q, value, onChange, disabled }: Props) {
  const { t, i18n } = useTranslation()
  const ar = i18n.language === 'ar'
  const p = q.payload ?? {}
  const text = (a?: string | null, e?: string | null) => lang(ar, a, e)
  const set = (patch: Record<string, unknown>) => onChange({ ...(value ?? {}), ...patch })

  // An ordering question starts from the order shown, so a trainee who agrees with it does not have to touch it.
  const initialised = useRef(false)
  useEffect(() => {
    if (q.type === 'ordering' && !initialised.current && value === undefined) { initialised.current = true; onChange((p.items ?? []).map((i: any) => i.id)) }
  }, [q.type, value, p.items, onChange])

  const body = (() => {
    switch (q.type) {
      case 'single_choice':
        return <div role="radiogroup" className="space-y-2">{p.options.map((o: any) => (
          <label key={o.id} className={clsx('flex cursor-pointer items-center gap-3 rounded-xl border p-3', value === o.id ? 'border-gold-500 bg-gold-50' : 'border-navy-100 bg-white')}><input type="radio" name={q.id} disabled={disabled} checked={value === o.id} onChange={() => onChange(o.id)} /><span>{text(o.text_ar, o.text_en)}</span></label>))}</div>
      case 'multiple_select': {
        const cur: string[] = value ?? []
        return <div className="space-y-2">{p.options.map((o: any) => (
          <label key={o.id} className={clsx('flex cursor-pointer items-center gap-3 rounded-xl border p-3', cur.includes(o.id) ? 'border-gold-500 bg-gold-50' : 'border-navy-100 bg-white')}><input type="checkbox" disabled={disabled} checked={cur.includes(o.id)} onChange={(e) => onChange(e.target.checked ? [...cur, o.id] : cur.filter((x) => x !== o.id))} /><span>{text(o.text_ar, o.text_en)}</span></label>))}</div>
      }
      case 'true_false':
        return <div className="flex gap-3">{[['true', ar ? 'صح' : 'True'], ['false', ar ? 'خطأ' : 'False']].map(([v, l]) => <button key={v} type="button" disabled={disabled} aria-pressed={String(value) === v} onClick={() => onChange(v === 'true')} className={clsx('min-h-12 flex-1 rounded-xl border text-lg font-bold', String(value) === v ? 'border-gold-500 bg-gold-50 text-navy-900' : 'border-navy-100 bg-white text-slate-600')}>{l}</button>)}</div>
      case 'dropdown': {
        const parts = text(p.template_ar, p.template_en).split(/(\{\{\d+\}\})/)
        return <p className="leading-[2.6rem]">{parts.map((part, i) => { const m = part.match(/\{\{(\d+)\}\}/); if (!m) return <span key={i}>{part}</span>; const b = p.blanks[Number(m[1]) - 1]; if (!b) return null
          return <select key={i} className="input mx-1 inline-block w-auto min-w-32" disabled={disabled} value={value?.[b.id] ?? ''} onChange={(e) => set({ [b.id]: e.target.value })} aria-label={`${m[1]}`}><option value="">{t('assess.runner.pick')}</option>{b.options.map((o: any) => <option key={o.id} value={o.id}>{o.text}</option>)}</select> })}</p>
      }
      case 'matrix': {
        const rows = p.rows as any[]; const cols = p.cols as any[]
        return <div className="overflow-x-auto"><table className="w-full text-sm"><thead><tr><th />{cols.map((c) => <th key={c.id} className="px-2 py-2 text-center font-semibold">{c.text}</th>)}</tr></thead>
          <tbody>{rows.map((r) => <tr key={r.id} className="border-t border-navy-50"><td className="py-2 pe-3 font-semibold">{r.text}</td>{cols.map((c) => { const cur = value?.[r.id]; const on = p.multiple ? (cur ?? []).includes(c.id) : cur === c.id
            return <td key={c.id} className="text-center"><input type={p.multiple ? 'checkbox' : 'radio'} name={`${q.id}-${r.id}`} disabled={disabled} checked={!!on} aria-label={`${r.text} — ${c.text}`} onChange={(e) => set({ [r.id]: p.multiple ? (e.target.checked ? [...(cur ?? []), c.id] : (cur ?? []).filter((x: string) => x !== c.id)) : c.id })} /></td> })}</tr>)}</tbody></table></div>
      }
      case 'essay': {
        const words = String(value?.text ?? '').trim().split(/\s+/).filter(Boolean).length
        return <div className="space-y-1"><textarea className="input min-h-48" disabled={disabled} value={value?.text ?? ''} onChange={(e) => set({ text: e.target.value })} /><div className="text-xs text-slate-500">{t('assess.runner.words', { n: words })}{p.max_words ? ` / ${p.max_words}` : ''}</div></div>
      }
      case 'short_answer':
        return <input className="input" disabled={disabled} value={value ?? ''} onChange={(e) => onChange(e.target.value)} />
      case 'numeric':
        return <div className="flex items-center gap-2"><input inputMode="decimal" dir="ltr" className="input max-w-xs" disabled={disabled} value={value ?? ''} onChange={(e) => onChange(e.target.value)} />{p.unit && <span className="text-slate-500">{p.unit}</span>}</div>
      case 'fill_blanks': {
        const parts = text(p.text_ar, p.text_en).split(/(\{\{\d+\}\})/)
        return <p className="leading-[2.6rem]">{parts.map((part, i) => { const m = part.match(/\{\{(\d+)\}\}/); if (!m) return <span key={i}>{part}</span>; const b = p.blanks[Number(m[1]) - 1]; if (!b) return null
          return <input key={i} className="input mx-1 inline-block w-40" disabled={disabled} aria-label={`${m[1]}`} value={value?.[b.id] ?? ''} onChange={(e) => set({ [b.id]: e.target.value })} /> })}</p>
      }
      case 'matching':
        return <div className="space-y-2">{p.lefts.map((l: any) => <div key={l.id} className="flex flex-wrap items-center gap-3 rounded-xl border border-navy-100 bg-white p-3"><span className="min-w-0 flex-1 font-semibold">{l.text}</span>
          <select className="input w-56" disabled={disabled} value={value?.[l.id] ?? ''} onChange={(e) => set({ [l.id]: e.target.value })} aria-label={l.text}><option value="">{t('assess.runner.pick')}</option>{p.rights.map((r: any) => <option key={r.id} value={r.id}>{r.text}</option>)}</select></div>)}</div>
      case 'ordering': {
        const order: string[] = value ?? (p.items ?? []).map((i: any) => i.id)
        const byId = Object.fromEntries((p.items ?? []).map((i: any) => [i.id, i]))
        const move = (i: number, d: number) => { const n = [...order]; const j = i + d; if (j < 0 || j >= n.length) return; [n[i], n[j]] = [n[j], n[i]]; onChange(n) }
        return <ol className="space-y-2">{order.map((id, i) => <li key={id} className="flex items-center gap-2 rounded-xl border border-navy-100 bg-white p-2"><span className="grid size-7 place-items-center rounded-full bg-navy-900 text-xs font-bold text-white">{i + 1}</span><span className="min-w-0 flex-1">{byId[id]?.text}</span>
          <button type="button" disabled={disabled || i === 0} aria-label={t('assess.runner.up')} onClick={() => move(i, -1)} className="grid size-9 place-items-center rounded-lg text-slate-500 hover:bg-ivory disabled:opacity-30"><ArrowUp className="size-4" /></button>
          <button type="button" disabled={disabled || i === order.length - 1} aria-label={t('assess.runner.down')} onClick={() => move(i, 1)} className="grid size-9 place-items-center rounded-lg text-slate-500 hover:bg-ivory disabled:opacity-30"><ArrowDown className="size-4" /></button></li>)}</ol>
      }
      case 'categorization':
        return <div className="space-y-2">{p.items.map((it: any) => <div key={it.id} className="flex flex-wrap items-center gap-3 rounded-xl border border-navy-100 bg-white p-3"><span className="min-w-0 flex-1 font-semibold">{it.text}</span>
          <select className="input w-48" disabled={disabled} value={value?.[it.id] ?? ''} onChange={(e) => set({ [it.id]: e.target.value })} aria-label={it.text}><option value="">{t('assess.runner.pick')}</option>{p.buckets.map((b: any) => <option key={b.id} value={b.id}>{b.name}</option>)}</select></div>)}</div>
      case 'hotspot':
        return <div className="space-y-2"><p className="text-sm text-slate-500">{t('assess.runner.clickImage')}</p>
          <div className="relative inline-block max-w-full cursor-crosshair" onClick={(e) => { if (disabled) return; const r = e.currentTarget.getBoundingClientRect(); onChange({ x: Math.round(((e.clientX - r.left) / r.width) * 1000) / 10, y: Math.round(((e.clientY - r.top) / r.height) * 1000) / 10 }) }}>
            <img src={p.image} alt="" className="max-h-96 max-w-full rounded-xl" draggable={false} />{value && <span className="absolute size-5 -translate-x-1/2 -translate-y-1/2 rounded-full border-2 border-white bg-gold-500 shadow" style={{ left: `${value.x}%`, top: `${value.y}%` }} />}</div></div>
      case 'h5p':
        return <div className="space-y-3"><iframe title="H5P" src={p.content_url ?? ''} className="h-96 w-full rounded-xl border border-navy-100" allowFullScreen /><button type="button" disabled={disabled} onClick={() => onChange({ score: 1, max: 1 })} className={clsx('rounded-xl border px-4 py-2 text-sm font-bold', value ? 'border-emerald-500 bg-emerald-50 text-emerald-800' : 'border-navy-100 bg-white')}>{t('assess.runner.h5pDone')}</button></div>
      default:
        return null
    }
  })()

  return (
    <div className="space-y-4">
      <div className="text-lg font-semibold leading-relaxed text-navy-900">{text(q.stem_ar, q.stem_en)}</div>
      {q.media?.image && q.type !== 'hotspot' && <img src={q.media.image} alt="" className="max-h-72 rounded-xl" />}
      {body}
    </div>
  )
}
