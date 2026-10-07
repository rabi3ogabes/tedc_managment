import clsx from 'clsx'
import { Eye, History, MessageSquarePlus, Play, RotateCcw, ScanSearch, Sparkles, Wand2 } from 'lucide-react'
import { useState } from 'react'
import { useTranslation } from 'react-i18next'
import { Badge, Button, Empty, Field, Modal, Spinner } from '@/components/ui'
import { useGet } from '@/hooks/useApi'
import { api, errorMessage } from '@/lib/api'
import { fmt } from '@/lib/format'
import { severityTone, relativeTime } from '../comments/api'
import type { Analysis, Finding, Kit, VersionRow } from '../types'
import type { Deck, Slide } from './model'
import { SlideView } from './SlideView'
import { dialogs } from '@/lib/dialogs'

const errorBox = (e: string | null) => e && <div className="rounded-xl bg-red-50 p-3 text-sm text-danger">{e}</div>

/** Automatic quality check: readability, structure, accessibility and alignment with the objectives. */
export function ChecksPanel({ kit, fileId, onJump, onComment, canComment }: { kit: Kit; fileId: string; onJump: (f: Finding) => void; onComment: (findings: Finding[]) => Promise<void>; canComment: boolean }) {
  const { t } = useTranslation()
  const [result, setResult] = useState<Analysis | null>(null)
  const [busy, setBusy] = useState(false)
  const [ai, setAi] = useState(false)
  const [error, setError] = useState<string | null>(null)
  const [sent, setSent] = useState<Set<string>>(new Set())

  const run = async () => {
    setBusy(true)
    setError(null)
    try {
      const res = await api.post<{ data: Analysis }>(`/admin/kits/${kit.id}/files/${fileId}/analyze`, {}, { params: { ai: ai ? 1 : 0 } })
      setResult(res.data.data)
      setSent(new Set())
    } catch (e) {
      setError(errorMessage(e))
    } finally {
      setBusy(false)
    }
  }
  const comment = async (list: Finding[]) => {
    setBusy(true)
    try { await onComment(list); setSent((cur) => new Set([...cur, ...list.map((f) => f.id)])) } catch (e) { setError(errorMessage(e)) } finally { setBusy(false) }
  }
  const scoreTone = result ? (result.score >= 85 ? 'text-emerald-600' : result.score >= 65 ? 'text-amber-600' : 'text-red-600') : ''

  return (
    <div className="space-y-4">
      <div className="rounded-2xl bg-gradient-to-br from-navy-950 to-navy-800 p-4 text-white">
        <div className="flex items-center gap-2 text-sm font-bold"><ScanSearch className="size-4 text-gold-300" />{t('kits.checks.title')}</div>
        <p className="mt-1 text-xs text-white/70">{t('kits.checks.hint')}</p>
        <label className="mt-3 flex items-center gap-2 text-xs text-white/85"><input type="checkbox" className="size-4 accent-gold-500" checked={ai} onChange={(e) => setAi(e.target.checked)} />{t('kits.checks.withAi')}</label>
        <Button className="mt-3 w-full" variant="gold" loading={busy} icon={<ScanSearch className="size-4" />} onClick={run}>{result ? t('kits.checks.rerun') : t('kits.checks.run')}</Button>
      </div>
      {errorBox(error)}
      {busy && !result && <Spinner />}
      {result && (
        <>
          <div className="flex items-center gap-4 rounded-2xl border border-navy-100 bg-white p-4">
            <div className={clsx('text-5xl font-bold leading-none', scoreTone)}>{result.score}</div>
            <div className="text-xs text-slate-500">
              <div className="font-bold text-navy-900">{t('kits.checks.score')}</div>
              <div>{t('kits.checks.stats', { slides: result.stats.slides, words: result.stats.avg_words })}</div>
              <div>{result.findings.length === 0 ? t('kits.checks.clean') : t('kits.checks.found', { count: result.findings.length })}</div>
            </div>
          </div>
          {canComment && result.findings.length > 1 && <Button size="sm" variant="outline" loading={busy} icon={<MessageSquarePlus className="size-4" />} onClick={() => comment(result.findings.filter((f) => !sent.has(f.id)))} disabled={result.findings.every((f) => sent.has(f.id))}>{t('kits.checks.commentAll')}</Button>}
          <ul className="space-y-2">
            {result.findings.map((f) => (
              <li key={f.id} className="relative overflow-hidden rounded-xl border border-navy-100 bg-white p-3 ps-4">
                <span className={clsx('absolute inset-y-0 start-0 w-1', severityTone[f.severity].bar)} />
                <div className="flex items-start justify-between gap-2">
                  <div className="text-sm font-bold text-navy-900" dir="auto">{f.title}</div>
                  <span className={clsx('shrink-0 rounded-full px-1.5 py-px text-[10px] font-bold ring-1 ring-inset', severityTone[f.severity].chip)}>{t(`kits.comments.severities.${f.severity}`)}</span>
                </div>
                <p className="mt-1 text-xs text-slate-500" dir="auto">{f.detail}</p>
                <p className="mt-1 text-xs font-semibold text-navy-800" dir="auto">→ {f.suggestion}</p>
                <div className="mt-2 flex flex-wrap gap-1.5">
                  {f.slide_id && <Button size="sm" variant="outline" icon={<Eye className="size-3.5" />} onClick={() => onJump(f)}>{t('kits.checks.goTo', { n: (f.slide_index ?? 0) + 1 })}</Button>}
                  {canComment && <Button size="sm" variant="ghost" disabled={sent.has(f.id)} icon={<MessageSquarePlus className="size-3.5" />} onClick={() => comment([f])}>{sent.has(f.id) ? t('kits.checks.commented') : t('kits.checks.comment')}</Button>}
                </div>
              </li>
            ))}
          </ul>
        </>
      )}
    </div>
  )
}

/** Version history with preview and restore. */
export function VersionsPanel({ kit, fileId, canEdit, onRestored, onSaved }: { kit: Kit; fileId: string; canEdit: boolean; onRestored: (deck: Deck, revision: number) => void; onSaved: () => Promise<void> }) {
  const { t, i18n } = useTranslation()
  const url = `/admin/kits/${kit.id}/files/${fileId}/versions`
  const list = useGet<{ data: VersionRow[]; current: number }>(url)
  const [note, setNote] = useState('')
  const [busy, setBusy] = useState(false)
  const [preview, setPreview] = useState<{ row: VersionRow; deck: Deck | null } | null>(null)
  const [error, setError] = useState<string | null>(null)

  const save = async () => {
    setBusy(true)
    setError(null)
    try { await onSaved(); await api.post(url, { note: note || null }); setNote(''); await list.refetch() } catch (e) { setError(errorMessage(e)) } finally { setBusy(false) }
  }
  const open = async (row: VersionRow) => {
    try { const res = await api.get<{ data: { deck: Deck | null } }>(`${url}/${row.id}`); setPreview({ row, deck: res.data.data.deck }) } catch (e) { setError(errorMessage(e)) }
  }
  const restore = async (row: VersionRow) => {
    if (!await dialogs.confirm(t('kits.versions.confirmRestore', { n: row.version }))) return
    setBusy(true)
    try { const res = await api.post<{ data: { deck: Deck; revision: number } }>(`${url}/${row.id}/restore`); onRestored(res.data.data.deck, res.data.data.revision); setPreview(null); await list.refetch() } catch (e) { setError(errorMessage(e)) } finally { setBusy(false) }
  }

  return (
    <div className="space-y-4">
      {canEdit && (
        <div className="rounded-2xl border border-navy-100 bg-white p-3">
          <Field label={t('kits.versions.save')}><input className="input !py-2 text-sm" dir="auto" value={note} placeholder={t('kits.versions.notePlaceholder')} onChange={(e) => setNote(e.target.value)} onKeyDown={(e) => e.key === 'Enter' && save()} /></Field>
          <Button className="mt-2 w-full" size="sm" variant="primary" loading={busy} icon={<History className="size-4" />} onClick={save}>{t('kits.versions.saveButton')}</Button>
        </div>
      )}
      {errorBox(error)}
      {list.isLoading ? <Spinner /> : !list.data?.data.length ? <Empty text={t('kits.versions.empty')} /> : (
        <ol className="relative space-y-3 border-s border-navy-100 ps-4">
          <li className="relative"><span className="absolute -start-[21px] top-1 size-2.5 rounded-full bg-emerald-500 ring-4 ring-white" /><div className="text-sm font-bold text-navy-900">{t('kits.versions.current', { n: list.data.current })}</div></li>
          {list.data.data.map((v) => (
            <li key={v.id} className="relative">
              <span className="absolute -start-[21px] top-1 size-2.5 rounded-full bg-navy-300 ring-4 ring-white" />
              <div className="flex flex-wrap items-center gap-1.5"><span className="text-sm font-bold text-navy-900">v{v.version}</span><Badge color={v.source === 'submit' ? 'gold' : v.source === 'manual' ? 'navy' : 'gray'}>{t(`kits.versions.sources.${v.source}`, { defaultValue: v.source })}</Badge>{v.slides != null && <span className="text-[11px] text-slate-400">{v.slides} {t('kits.editor.slidesShort')}</span>}</div>
              {v.note && <p className="text-xs text-slate-500" dir="auto">{v.note}</p>}
              <div className="text-[11px] text-slate-400">{v.author} · {relativeTime(v.created_at, i18n.language)}</div>
              <div className="mt-1.5 flex gap-1.5">
                {v.slides != null && <Button size="sm" variant="outline" icon={<Eye className="size-3.5" />} onClick={() => open(v)}>{t('kits.versions.view')}</Button>}
                {canEdit && v.slides != null && <Button size="sm" variant="ghost" icon={<RotateCcw className="size-3.5" />} onClick={() => restore(v)}>{t('kits.versions.restore')}</Button>}
              </div>
            </li>
          ))}
        </ol>
      )}
      {preview && <VersionPreview deck={preview.deck} title={`v${preview.row.version}`} onClose={() => setPreview(null)} onRestore={canEdit ? () => restore(preview.row) : undefined} />}
    </div>
  )
}

function VersionPreview({ deck, title, onClose, onRestore }: { deck: Deck | null; title: string; onClose: () => void; onRestore?: () => void }) {
  const { t } = useTranslation()
  const [i, setI] = useState(0)
  const slide: Slide | undefined = deck?.slides[i]
  return (
    <Modal open onClose={onClose} wide title={`${t('kits.versions.preview')} ${title}`}>
      {!deck || !slide ? <Empty /> : (
        <div className="space-y-3">
          <div className="overflow-hidden rounded-xl ring-1 ring-black/10"><SlideView slide={slide} theme={deck.theme} width={640} placeholder /></div>
          <div className="flex items-center justify-between">
            <div className="flex gap-1">{deck.slides.map((s, n) => <button key={s.id} type="button" onClick={() => setI(n)} className={clsx('size-7 rounded-md text-xs font-bold', n === i ? 'bg-navy-900 text-white' : 'bg-slate-100 text-slate-500 hover:bg-navy-100')}>{fmt.number(n + 1)}</button>)}</div>
            {onRestore && <Button size="sm" variant="gold" icon={<RotateCcw className="size-4" />} onClick={onRestore}>{t('kits.versions.restore')}</Button>}
          </div>
        </div>
      )}
    </Modal>
  )
}

/** AI helpers for the open deck: new slides from a prompt, rewrite of the selected text, a picture on demand. */
export function AiPanel({ kit, fileId, hasTextSelection, onInsertSlides, onRewrite, onImage }: { kit: Kit; fileId: string; hasTextSelection: boolean; onInsertSlides: (slides: Slide[]) => void; onRewrite: (mode: string) => Promise<void>; onImage: () => void }) {
  const { t } = useTranslation()
  const [topic, setTopic] = useState('')
  const [count, setCount] = useState(2)
  const [instructions, setInstructions] = useState('')
  const [busy, setBusy] = useState<string | null>(null)
  const [error, setError] = useState<string | null>(null)
  const [note, setNote] = useState<string | null>(null)
  const status = useGet<{ data: { ai: boolean } }>('/admin/kits/ai/status')

  const addSlides = async () => {
    setBusy('slides')
    setError(null)
    setNote(null)
    try {
      const res = await api.post<{ data: { slides: Slide[]; provider: string } }>(`/admin/kits/${kit.id}/files/${fileId}/ai/slides`, { topic, count, instructions: instructions || null })
      onInsertSlides(res.data.data.slides)
      setNote(res.data.data.provider === 'template' ? t('kits.ai.templateNote') : t('kits.ai.inserted', { count: res.data.data.slides.length }))
    } catch (e) {
      setError(errorMessage(e))
    } finally {
      setBusy(null)
    }
  }
  const rewrite = async (mode: string) => {
    setBusy(mode)
    setError(null)
    try { await onRewrite(mode) } catch (e) { setError(errorMessage(e)) } finally { setBusy(null) }
  }
  const modes = ['improve', 'shorten', 'expand', 'simplify', 'formal', 'translate_en', 'translate_ar']

  return (
    <div className="space-y-5">
      {status.data && !status.data.data.ai && <p className="rounded-xl bg-amber-50 p-3 text-xs leading-relaxed text-amber-800">{t('kits.ai.noKey')}</p>}
      {errorBox(error)}
      {note && <p className="rounded-xl bg-emerald-50 p-3 text-xs text-emerald-800">{note}</p>}

      <section className="space-y-2 rounded-2xl border border-navy-100 bg-white p-3">
        <h4 className="flex items-center gap-2 text-sm font-bold text-navy-900"><Wand2 className="size-4 text-gold-600" />{t('kits.ai.addSlides')}</h4>
        <input className="input !py-2 text-sm" dir="auto" value={topic} placeholder={t('kits.ai.topicPlaceholder')} onChange={(e) => setTopic(e.target.value)} />
        <textarea rows={2} className="input text-sm" dir="auto" value={instructions} placeholder={t('kits.ai.instructions')} onChange={(e) => setInstructions(e.target.value)} />
        <div className="flex items-center gap-2">
          <label className="flex items-center gap-2 text-xs text-slate-500">{t('kits.ai.count')}<input type="number" min={1} max={8} className="input !w-16 !py-1.5 text-center text-xs" value={count} onChange={(e) => setCount(Math.max(1, Math.min(8, Number(e.target.value) || 1)))} /></label>
          <Button className="ms-auto" size="sm" variant="gold" loading={busy === 'slides'} disabled={topic.trim().length < 3} icon={<Sparkles className="size-4" />} onClick={addSlides}>{t('kits.ai.generate')}</Button>
        </div>
      </section>

      <section className="space-y-2 rounded-2xl border border-navy-100 bg-white p-3">
        <h4 className="flex items-center gap-2 text-sm font-bold text-navy-900"><Sparkles className="size-4 text-gold-600" />{t('kits.ai.rewrite')}</h4>
        <p className="text-xs text-slate-500">{hasTextSelection ? t('kits.ai.rewriteHint') : t('kits.ai.rewriteSelect')}</p>
        <div className="flex flex-wrap gap-1.5">
          {modes.map((m) => <Button key={m} size="sm" variant="outline" disabled={!hasTextSelection || !!busy} loading={busy === m} onClick={() => rewrite(m)}>{t(`kits.ai.modes.${m}`)}</Button>)}
        </div>
      </section>

      <section className="space-y-2 rounded-2xl border border-navy-100 bg-white p-3">
        <h4 className="flex items-center gap-2 text-sm font-bold text-navy-900"><Play className="size-4 text-gold-600" />{t('kits.ai.picture')}</h4>
        <p className="text-xs text-slate-500">{t('kits.ai.pictureHint')}</p>
        <Button size="sm" variant="primary" icon={<Sparkles className="size-4" />} onClick={onImage}>{t('kits.ai.pictureButton')}</Button>
      </section>
    </div>
  )
}
