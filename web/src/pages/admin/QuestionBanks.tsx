/* eslint-disable @typescript-eslint/no-explicit-any */
import { Download, Plus, Trash2, Upload } from 'lucide-react'
import { useRef, useState } from 'react'
import { useTranslation } from 'react-i18next'
import { Badge, Button, Card, Empty, Field, Modal, PageHeader, Spinner } from '@/components/ui'
import { useGet } from '@/hooks/useApi'
import { api, downloadFile, errorMessage } from '@/lib/api'
import { toast } from '@/lib/toast'

export const QUESTION_TYPES = ['single_choice', 'multiple_select', 'true_false', 'dropdown', 'matrix', 'essay', 'short_answer', 'fill_blanks', 'matching', 'ordering', 'categorization', 'hotspot', 'numeric', 'h5p'] as const
type Bank = { id: string; title_ar: string; title_en: string; visibility: string; questions_count: number }
type Cat = { id: string; name_ar: string; name_en: string }
type Q = { id: string; type: string; stem_ar: string; stem_en: string | null; difficulty: string; points: number; payload: any; tags: string[] | null; category_id: string | null; explanation_ar?: string | null; explanation_en?: string | null; version?: number }

const starter = (type: string): any => ({
  single_choice: { options: [{ id: 'o1', text_ar: '', correct: true }, { id: 'o2', text_ar: '', correct: false }] },
  multiple_select: { options: [{ id: 'o1', text_ar: '', correct: true }, { id: 'o2', text_ar: '', correct: true }, { id: 'o3', text_ar: '', correct: false }], partial: true },
  true_false: { correct: true }, short_answer: { accepted: [''], case_sensitive: false }, essay: { rubric: [], max_words: 300 }, numeric: { value: 0, tolerance: 0 },
  ordering: { items: [{ text: '' }, { text: '' }, { text: '' }] }, matching: { pairs: [{ left: '', right: '' }, { left: '', right: '' }] },
  fill_blanks: { text_ar: '… {{1}} …', blanks: [{ accepted: [''] }] },
  dropdown: { template_ar: '… {{1}} …', blanks: [{ options: ['', ''], correct: '' }] },
  matrix: { rows: [{ text: '' }], cols: [{ text: '' }, { text: '' }], multiple: false }, categorization: { buckets: [{ name: '' }, { name: '' }], items: [{ text: '', bucket: 'b1' }] },
  hotspot: { image: '', areas: [{ x: 0, y: 0, w: 100, h: 100, correct: true }] }, h5p: { content_url: '' },
}[type] ?? {})

/** Type-aware payload editor for the common types; the rest are edited as JSON so no type is out of reach. */
function PayloadEditor({ type, value, onChange }: { type: string; value: any; onChange: (v: any) => void }) {
  const { t } = useTranslation()
  const [raw, setRaw] = useState(JSON.stringify(value, null, 2))
  const [rawErr, setRawErr] = useState(false)
  const set = (patch: any) => onChange({ ...value, ...patch })
  const input = 'w-full rounded-xl border border-navy-100 px-3 py-2 text-sm'
  if (type === 'single_choice' || type === 'multiple_select') {
    const opts: any[] = value.options ?? []
    return <div className="space-y-2">{opts.map((o, i) => (
      <div key={i} className="flex items-center gap-2">
        <input aria-label={t('assess.studio.correct')} type={type === 'single_choice' ? 'radio' : 'checkbox'} checked={!!o.correct} onChange={(e) => set({ options: opts.map((x, j) => (type === 'single_choice' ? { ...x, correct: j === i } : j === i ? { ...x, correct: e.target.checked } : x)) })} />
        <input className={input} placeholder={`${t('assess.studio.option')} ${i + 1} (ع)`} value={o.text_ar ?? ''} onChange={(e) => set({ options: opts.map((x, j) => (j === i ? { ...x, text_ar: e.target.value } : x)) })} />
        <input className={input} dir="ltr" placeholder="EN" value={o.text_en ?? ''} onChange={(e) => set({ options: opts.map((x, j) => (j === i ? { ...x, text_en: e.target.value } : x)) })} />
        <button type="button" aria-label="remove" className="text-danger" onClick={() => set({ options: opts.filter((_, j) => j !== i) })}><Trash2 className="size-4" /></button>
      </div>))}
      <Button size="sm" variant="outline" icon={<Plus className="size-4" />} onClick={() => set({ options: [...opts, { id: `o${opts.length + 1}`, text_ar: '', correct: false }] })}>{t('assess.studio.addOption')}</Button>
      {type === 'multiple_select' && <label className="flex items-center gap-2 text-sm"><input type="checkbox" checked={value.partial ?? true} onChange={(e) => set({ partial: e.target.checked })} />{t('assess.studio.partial')}</label>}
    </div>
  }
  if (type === 'true_false') return <div className="flex gap-3">{[true, false].map((v) => <label key={String(v)} className="flex items-center gap-2"><input type="radio" checked={value.correct === v} onChange={() => set({ correct: v })} />{v ? 'صح / True' : 'خطأ / False'}</label>)}</div>
  if (type === 'short_answer') return <Field label={t('assess.studio.accepted')}><input className={input} value={(value.accepted ?? []).join(' | ')} onChange={(e) => set({ accepted: e.target.value.split('|').map((s) => s.trim()) })} /></Field>
  if (type === 'numeric') return <div className="grid grid-cols-2 gap-3"><Field label={t('assess.studio.value')}><input className={input} type="number" step="any" value={value.value ?? ''} onChange={(e) => set({ value: e.target.value })} /></Field><Field label={t('assess.studio.tolerance')}><input className={input} type="number" step="any" value={value.tolerance ?? 0} onChange={(e) => set({ tolerance: e.target.value })} /></Field></div>
  if (type === 'essay') return <Field label={t('assess.studio.maxWords')}><input className={input} type="number" value={value.max_words ?? ''} onChange={(e) => set({ max_words: e.target.value ? Number(e.target.value) : null })} /></Field>
  if (type === 'ordering') { const items: any[] = value.items ?? []; return <div className="space-y-2"><p className="text-xs text-slate-500">{t('assess.studio.items')}</p>{items.map((it, i) => <div key={i} className="flex gap-2"><input className={input} value={it.text ?? ''} onChange={(e) => set({ items: items.map((x, j) => (j === i ? { ...x, text: e.target.value } : x)) })} /><button type="button" aria-label="remove" className="text-danger" onClick={() => set({ items: items.filter((_, j) => j !== i) })}><Trash2 className="size-4" /></button></div>)}<Button size="sm" variant="outline" icon={<Plus className="size-4" />} onClick={() => set({ items: [...items, { text: '' }] })}>{t('assess.studio.addOption')}</Button></div> }
  if (type === 'matching') { const pairs: any[] = value.pairs ?? []; return <div className="space-y-2">{pairs.map((p, i) => <div key={i} className="flex gap-2"><input className={input} placeholder={t('assess.studio.left')} value={p.left ?? ''} onChange={(e) => set({ pairs: pairs.map((x, j) => (j === i ? { ...x, left: e.target.value } : x)) })} /><input className={input} placeholder={t('assess.studio.right')} value={p.right ?? ''} onChange={(e) => set({ pairs: pairs.map((x, j) => (j === i ? { ...x, right: e.target.value } : x)) })} /><button type="button" aria-label="remove" className="text-danger" onClick={() => set({ pairs: pairs.filter((_, j) => j !== i) })}><Trash2 className="size-4" /></button></div>)}<Button size="sm" variant="outline" icon={<Plus className="size-4" />} onClick={() => set({ pairs: [...pairs, { left: '', right: '' }] })}>{t('assess.studio.addOption')}</Button></div> }
  if (type === 'fill_blanks') return <div className="space-y-2"><Field label={t('assess.studio.template')}><textarea className={input} rows={3} value={value.text_ar ?? ''} onChange={(e) => set({ text_ar: e.target.value })} /></Field>{(value.blanks ?? []).map((b: any, i: number) => <Field key={i} label={`{{${i + 1}}} — ${t('assess.studio.accepted')}`}><input className={input} value={(b.accepted ?? []).join(' | ')} onChange={(e) => set({ blanks: value.blanks.map((x: any, j: number) => (j === i ? { ...x, accepted: e.target.value.split('|').map((s) => s.trim()) } : x)) })} /></Field>)}<Button size="sm" variant="outline" icon={<Plus className="size-4" />} onClick={() => set({ blanks: [...(value.blanks ?? []), { accepted: [''] }] })}>{t('assess.studio.addOption')}</Button></div>
  // dropdown, matrix, categorization, hotspot, h5p: JSON with a starter template
  return <Field label="JSON" hint={rawErr ? 'JSON?' : undefined}><textarea dir="ltr" className={`${input} font-mono`} rows={10} value={raw} onChange={(e) => { setRaw(e.target.value); try { onChange(JSON.parse(e.target.value)); setRawErr(false) } catch { setRawErr(true) } }} /></Field>
}

function QuestionForm({ bank, cats, q, onDone }: { bank: Bank; cats: Cat[]; q: Q | null; onDone: () => void }) {
  const { t, i18n } = useTranslation()
  const ar = i18n.language === 'ar'
  const [f, setF] = useState<any>(q ? { ...q, tags: (q.tags ?? []).join(', ') } : { type: 'single_choice', stem_ar: '', stem_en: '', difficulty: 'medium', points: 1, payload: starter('single_choice'), tags: '', category_id: '' })
  const [busy, setBusy] = useState(false)
  const input = 'w-full rounded-xl border border-navy-100 px-3 py-2 text-sm'
  const save = async () => {
    setBusy(true)
    try {
      const body = { type: f.type, stem_ar: f.stem_ar, stem_en: f.stem_en || null, difficulty: f.difficulty, points: Number(f.points), payload: f.payload, category_id: f.category_id || null, explanation_ar: f.explanation_ar || null, explanation_en: f.explanation_en || null, tags: String(f.tags || '').split(',').map((s) => s.trim()).filter(Boolean) }
      const { data } = q ? await api.put(`/admin/questions/${q.id}`, body) : await api.post(`/admin/question-banks/${bank.id}/questions`, body)
      if (data.data.duplicate_of) toast(t('assess.studio.duplicate'), 'error')
      toast(t('assess.studio.saved')); onDone()
    } catch (e) { toast(errorMessage(e), 'error') } finally { setBusy(false) }
  }
  return (
    <div className="space-y-4">
      <div className="grid gap-3 sm:grid-cols-4">
        <Field label={t('assess.studio.type')} className="sm:col-span-2"><select className={input} disabled={!!q} value={f.type} onChange={(e) => setF({ ...f, type: e.target.value, payload: starter(e.target.value) })}>{QUESTION_TYPES.map((k) => <option key={k} value={k}>{t(`assess.types.${k}`)}</option>)}</select></Field>
        <Field label={t('assess.studio.difficulty')}><select className={input} value={f.difficulty} onChange={(e) => setF({ ...f, difficulty: e.target.value })}>{['easy', 'medium', 'hard'].map((d) => <option key={d} value={d}>{t(`assess.difficulty.${d}`)}</option>)}</select></Field>
        <Field label={t('assess.studio.points')}><input className={input} type="number" min="0" step="0.5" value={f.points} onChange={(e) => setF({ ...f, points: e.target.value })} /></Field>
      </div>
      <Field label={t('assess.studio.stemAr')}><textarea className={input} rows={3} value={f.stem_ar} onChange={(e) => setF({ ...f, stem_ar: e.target.value })} /></Field>
      <Field label={t('assess.studio.stemEn')}><textarea dir="ltr" className={input} rows={2} value={f.stem_en ?? ''} onChange={(e) => setF({ ...f, stem_en: e.target.value })} /></Field>
      <PayloadEditor key={f.type + (q?.id ?? '')} type={f.type} value={f.payload} onChange={(payload) => setF({ ...f, payload })} />
      <div className="grid gap-3 sm:grid-cols-2">
        <Field label={t('assess.studio.categories')}><select className={input} value={f.category_id ?? ''} onChange={(e) => setF({ ...f, category_id: e.target.value })}><option value="">—</option>{cats.map((c) => <option key={c.id} value={c.id}>{ar ? c.name_ar : c.name_en}</option>)}</select></Field>
        <Field label={t('assess.studio.tags')}><input className={input} value={f.tags} onChange={(e) => setF({ ...f, tags: e.target.value })} /></Field>
        <Field label={t('assess.studio.explanationAr')}><textarea className={input} rows={2} value={f.explanation_ar ?? ''} onChange={(e) => setF({ ...f, explanation_ar: e.target.value })} /></Field>
        <Field label={t('assess.studio.explanationEn')}><textarea dir="ltr" className={input} rows={2} value={f.explanation_en ?? ''} onChange={(e) => setF({ ...f, explanation_en: e.target.value })} /></Field>
      </div>
      <Button variant="gold" loading={busy} onClick={() => void save()}>{t('assess.studio.save')}</Button>
    </div>
  )
}

/** Question banks: banks, categories, filterable questions, versioned editing, bulk actions, import and export. */
export default function QuestionBanks() {
  const { t, i18n } = useTranslation()
  const ar = i18n.language === 'ar'
  const banks = useGet<{ data: Bank[] }>('/admin/question-banks', undefined, { staleTime: 0 })
  const [bankId, setBankId] = useState<string | null>(null)
  const bank = banks.data?.data.find((b) => b.id === bankId) ?? banks.data?.data[0] ?? null
  const cats = useGet<{ data: Cat[] }>(bank ? `/admin/question-banks/${bank.id}/categories` : '', undefined, { enabled: !!bank, staleTime: 0 })
  const [filters, setFilters] = useState({ type: '', difficulty: '', category_id: '', q: '' })
  const qs = useGet<{ data: Q[] }>(bank ? `/admin/question-banks/${bank.id}/questions` : '', { ...Object.fromEntries(Object.entries(filters).filter(([, v]) => v)) }, { enabled: !!bank, staleTime: 0 })
  const [edit, setEdit] = useState<Q | 'new' | null>(null)
  const [newBank, setNewBank] = useState(false)
  const [bankForm, setBankForm] = useState({ title_ar: '', title_en: '', visibility: 'center' })
  const [picked, setPicked] = useState<string[]>([])
  const file = useRef<HTMLInputElement>(null)
  const refresh = () => { void banks.refetch(); void qs.refetch(); void cats.refetch() }
  const input = 'rounded-xl border border-navy-100 px-3 py-2 text-sm'

  const addBank = async () => { try { const { data } = await api.post('/admin/question-banks', bankForm); setNewBank(false); setBankId(data.data.id); refresh() } catch (e) { toast(errorMessage(e), 'error') } }
  const addCat = async () => { const name = window.prompt(t('assess.studio.newCategory')); if (!name || !bank) return; try { await api.post(`/admin/question-banks/${bank.id}/categories`, { name_ar: name, name_en: name }); void cats.refetch() } catch (e) { toast(errorMessage(e), 'error') } }
  const doImport = async (f: File) => {
    if (!bank) return
    const fd = new FormData(); fd.append('file', f)
    try { const { data } = await api.post(`/admin/question-banks/${bank.id}/import`, fd); toast(t('assess.studio.importReport', { created: data.data.created, errors: data.data.errors?.length ?? 0 })); refresh() } catch (e) { toast(errorMessage(e), 'error') }
  }
  const bulk = async (patch: any) => { if (!bank || !picked.length) return; try { await api.post(`/admin/question-banks/${bank.id}/questions/bulk`, { ids: picked, ...patch }); setPicked([]); refresh() } catch (e) { toast(errorMessage(e), 'error') } }

  if (banks.isLoading) return <Spinner />
  return (
    <>
      <PageHeader title={t('assess.studio.title')} actions={<Button icon={<Plus className="size-4" />} onClick={() => setNewBank(true)}>{t('assess.studio.newBank')}</Button>} />
      <div className="grid gap-5 lg:grid-cols-[16rem_1fr]">
        <Card className="h-fit space-y-1">{(banks.data?.data ?? []).map((b) => (
          <button key={b.id} type="button" onClick={() => { setBankId(b.id); setPicked([]) }} className={`flex w-full items-center justify-between rounded-xl px-3 py-2 text-start text-sm ${bank?.id === b.id ? 'bg-navy-900 font-bold text-white' : 'hover:bg-ivory'}`}><span className="truncate">{ar ? b.title_ar : b.title_en}</span><Badge color="gold">{b.questions_count}</Badge></button>))}
          {!banks.data?.data.length && <Empty text={t('assess.studio.empty')} />}</Card>
        {bank && (
          <div className="space-y-4">
            <Card className="flex flex-wrap items-center gap-2">
              <input className={`${input} min-w-40 flex-1`} placeholder={t('assess.studio.search')} value={filters.q} onChange={(e) => setFilters({ ...filters, q: e.target.value })} />
              <select aria-label={t('assess.studio.type')} className={input} value={filters.type} onChange={(e) => setFilters({ ...filters, type: e.target.value })}><option value="">{t('assess.studio.type')}</option>{QUESTION_TYPES.map((k) => <option key={k} value={k}>{t(`assess.types.${k}`)}</option>)}</select>
              <select aria-label={t('assess.studio.difficulty')} className={input} value={filters.difficulty} onChange={(e) => setFilters({ ...filters, difficulty: e.target.value })}><option value="">{t('assess.studio.difficulty')}</option>{['easy', 'medium', 'hard'].map((d) => <option key={d} value={d}>{t(`assess.difficulty.${d}`)}</option>)}</select>
              <select aria-label={t('assess.studio.categories')} className={input} value={filters.category_id} onChange={(e) => setFilters({ ...filters, category_id: e.target.value })}><option value="">{t('assess.studio.categories')}</option>{(cats.data?.data ?? []).map((c) => <option key={c.id} value={c.id}>{ar ? c.name_ar : c.name_en}</option>)}</select>
              <Button size="sm" variant="outline" onClick={() => void addCat()}>{t('assess.studio.newCategory')}</Button>
              <Button size="sm" variant="outline" icon={<Upload className="size-4" />} onClick={() => file.current?.click()}>{t('assess.studio.import')}</Button>
              <input ref={file} type="file" accept=".csv,.xlsx,.txt" hidden onChange={(e) => { const f = e.target.files?.[0]; if (f) void doImport(f); e.target.value = '' }} />
              <Button size="sm" variant="outline" icon={<Download className="size-4" />} onClick={() => void downloadFile(`/admin/question-banks/${bank.id}/export`, 'questions.xlsx')}>{t('assess.studio.export')}</Button>
              <Button size="sm" variant="gold" icon={<Plus className="size-4" />} onClick={() => setEdit('new')}>{t('assess.studio.newQuestion')}</Button>
            </Card>
            {picked.length > 0 && <Card className="flex flex-wrap items-center gap-2 text-sm"><b>{t('assess.studio.selected', { n: picked.length })}</b>{['easy', 'medium', 'hard'].map((d) => <Button key={d} size="sm" variant="outline" onClick={() => void bulk({ difficulty: d })}>{t(`assess.difficulty.${d}`)}</Button>)}<Button size="sm" variant="outline" onClick={() => void bulk({ status: 'retired' })}>{t('assess.studio.retire')}</Button></Card>}
            <Card className="divide-y divide-navy-50" padded={false}>
              {qs.isLoading ? <Spinner /> : !qs.data?.data.length ? <Empty text={t('assess.studio.empty')} /> : qs.data.data.map((q) => (
                <div key={q.id} className="flex items-start gap-3 p-3">
                  <input type="checkbox" aria-label="select" className="mt-1" checked={picked.includes(q.id)} onChange={(e) => setPicked(e.target.checked ? [...picked, q.id] : picked.filter((x) => x !== q.id))} />
                  <button type="button" className="flex-1 text-start" onClick={() => setEdit(q)}>
                    <div className="line-clamp-2 font-semibold text-navy-900" dir="auto">{ar ? q.stem_ar : q.stem_en || q.stem_ar}</div>
                    <div className="mt-1 flex flex-wrap gap-1.5 text-xs"><Badge>{t(`assess.types.${q.type}`)}</Badge><Badge color={q.difficulty === 'hard' ? 'red' : q.difficulty === 'easy' ? 'green' : 'gold'}>{t(`assess.difficulty.${q.difficulty}`)}</Badge><span className="text-slate-400">{q.points} {t('assess.studio.points')}</span>{q.version && q.version > 1 && <span className="text-slate-400">{t('assess.studio.version', { n: q.version })}</span>}</div>
                  </button>
                </div>))}
            </Card>
          </div>
        )}
      </div>
      <Modal open={newBank} onClose={() => setNewBank(false)} title={t('assess.studio.newBank')}>
        <div className="space-y-3">
          <Field label="العنوان بالعربية"><input className={`${input} w-full`} value={bankForm.title_ar} onChange={(e) => setBankForm({ ...bankForm, title_ar: e.target.value })} /></Field>
          <Field label="Title (English)"><input dir="ltr" className={`${input} w-full`} value={bankForm.title_en} onChange={(e) => setBankForm({ ...bankForm, title_en: e.target.value })} /></Field>
          <Field label={t('assess.studio.visibility')}><select className={`${input} w-full`} value={bankForm.visibility} onChange={(e) => setBankForm({ ...bankForm, visibility: e.target.value })}>{['private', 'program', 'center'].map((v) => <option key={v} value={v}>{t(`assess.studio.visibilities.${v}`)}</option>)}</select></Field>
          <Button variant="gold" onClick={() => void addBank()}>{t('assess.studio.save')}</Button>
        </div>
      </Modal>
      <Modal wide open={!!edit && !!bank} onClose={() => setEdit(null)} title={edit === 'new' ? t('assess.studio.newQuestion') : t('assess.studio.newQuestion')}>
        {bank && edit && <QuestionForm key={edit === 'new' ? 'new' : edit.id} bank={bank} cats={cats.data?.data ?? []} q={edit === 'new' ? null : edit} onDone={() => { setEdit(null); refresh() }} />}
      </Modal>
    </>
  )
}
