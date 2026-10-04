import clsx from 'clsx'
import mammoth from 'mammoth'
import { safeHtml } from '@/lib/safeHtml'
import { MessageSquarePlus } from 'lucide-react'
import { useEffect, useRef, useState } from 'react'
import { useTranslation } from 'react-i18next'
import { Spinner } from '@/components/ui'
import { severityTone } from '../comments/api'
import type { Anchor, KitComment } from '../types'

type Props = { url: string; reviewing: boolean; comments: KitComment[]; activeId: string | null; onPlace: (a: Anchor) => void; onPinClick: (c: KitComment) => void; scrollToParagraph: number | null }

/** Word documents rendered as HTML. Select any passage in review mode to comment on exactly that text. */
export default function DocxViewer({ url, reviewing, comments, activeId, onPlace, onPinClick, scrollToParagraph }: Props) {
  const { t } = useTranslation()
  const root = useRef<HTMLDivElement>(null)
  const [html, setHtml] = useState<string | null>(null)
  const [error, setError] = useState<string | null>(null)
  const [selection, setSelection] = useState<{ x: number; y: number; anchor: Anchor } | null>(null)
  const [marks, setMarks] = useState<{ c: KitComment; top: number }[]>([])

  useEffect(() => {
    let cancelled = false
    void (async () => {
      try {
        const buffer = await (await fetch(url)).arrayBuffer()
        const res = await mammoth.convertToHtml({ arrayBuffer: buffer }, { styleMap: ['p[style-name="Title"] => h1.title:fresh'] })
        if (!cancelled) setHtml(safeHtml(res.value))
      } catch (e) {
        if (!cancelled) setError(e instanceof Error ? e.message : 'error')
      }
    })()
    return () => { cancelled = true }
  }, [url])

  const paragraphs = () => Array.from(root.current?.querySelectorAll<HTMLElement>('p, h1, h2, h3, h4, li, td') ?? [])

  // Margin markers + highlights for comments that quote a passage.
  useEffect(() => {
    const el = root.current
    if (!el || !html) return
    el.querySelectorAll('mark[data-c]').forEach((m) => m.replaceWith(document.createTextNode(m.textContent ?? '')))
    el.normalize()
    const paras = paragraphs()
    const next: { c: KitComment; top: number }[] = []
    comments.forEach((c) => {
      const p = c.anchor?.paragraph != null ? paras[c.anchor.paragraph] : null
      if (!p) return
      const quote = c.anchor?.quote
      if (quote) {
        const walker = document.createTreeWalker(p, NodeFilter.SHOW_TEXT)
        let node: Node | null
        while ((node = walker.nextNode())) {
          const idx = (node.textContent ?? '').indexOf(quote)
          if (idx >= 0 && node.textContent) {
            const range = document.createRange()
            range.setStart(node, idx)
            range.setEnd(node, idx + quote.length)
            const mark = document.createElement('mark')
            mark.dataset.c = c.id
            mark.className = c.status === 'resolved' ? 'bg-emerald-100/70' : 'bg-gold-200/80'
            try { range.surroundContents(mark) } catch { /* selection spans elements: keep the margin marker only */ }
            break
          }
        }
      }
      next.push({ c, top: p.offsetTop })
    })
    setMarks(next)
  }, [html, comments])

  useEffect(() => {
    if (scrollToParagraph != null) paragraphs()[scrollToParagraph]?.scrollIntoView({ behavior: 'smooth', block: 'center' })
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [scrollToParagraph])

  const onMouseUp = () => {
    if (!reviewing) return
    const sel = window.getSelection()
    if (!sel || sel.isCollapsed || !root.current) { setSelection(null); return }
    const range = sel.getRangeAt(0)
    if (!root.current.contains(range.commonAncestorContainer)) return
    const quote = sel.toString().trim().slice(0, 300)
    if (!quote) return
    const node = (range.startContainer.nodeType === 3 ? range.startContainer.parentElement : (range.startContainer as HTMLElement))?.closest('p, h1, h2, h3, h4, li, td') as HTMLElement | null
    const index = node ? paragraphs().indexOf(node) : -1
    const rect = range.getBoundingClientRect()
    const host = root.current.getBoundingClientRect()
    setSelection({ x: rect.left - host.left + rect.width / 2, y: rect.top - host.top, anchor: { type: 'text', paragraph: index >= 0 ? index : null, quote } })
  }

  if (error) return <div className="grid h-full place-items-center p-8 text-sm text-danger">{t('kits.viewer.docFailed')}: {error}</div>
  if (html === null) return <Spinner className="h-full" />

  return (
    <div className="h-full overflow-auto bg-[#E9E4D9] p-6">
      <div className="relative mx-auto max-w-3xl rounded-sm bg-white px-14 py-12 shadow-lg" onMouseUp={onMouseUp}>
        {reviewing && <p className="mb-6 rounded-lg bg-gold-100/60 px-3 py-2 text-center text-xs font-semibold text-gold-700">{t('kits.viewer.docHint')}</p>}
        <div ref={root} className="kit-doc relative" dir="auto" dangerouslySetInnerHTML={{ __html: html }} />
        {marks.map(({ c, top }, i) => (
          <button key={c.id} type="button" onClick={() => onPinClick(c)} title={c.body}
            className={clsx('absolute -start-9 grid size-6 place-items-center rounded-full text-[11px] font-bold text-white shadow ring-2 ring-white', activeId === c.id && 'scale-125')}
            style={{ top: top + 48 + (reviewing ? 56 : 0), background: c.status === 'resolved' ? '#16A34A' : c.status === 'addressed' ? '#D97706' : severityTone[c.severity].pin }}>{i + 1}</button>
        ))}
        {selection && (
          <button type="button" onMouseDown={(e) => e.preventDefault()} onClick={() => { onPlace(selection.anchor); setSelection(null); window.getSelection()?.removeAllRanges() }}
            className="absolute z-10 -translate-x-1/2 -translate-y-full items-center gap-1.5 rounded-full bg-navy-900 px-3 py-1.5 text-xs font-semibold text-white shadow-glass" style={{ left: selection.x + 56, top: selection.y + 56 + 48 - 6, display: 'inline-flex' }}>
            <MessageSquarePlus className="size-3.5" />{t('kits.viewer.commentSelection')}
          </button>
        )}
      </div>
      <style>{`.kit-doc{font-size:16px;line-height:1.9;color:#1a1a1a}.kit-doc h1{font-size:28px;font-weight:700;margin:1.2em 0 .5em;color:#8A1538}.kit-doc h2{font-size:22px;font-weight:700;margin:1.1em 0 .4em;color:#8A1538}.kit-doc h3{font-size:18px;font-weight:700;margin:1em 0 .3em}.kit-doc p{margin:.6em 0}.kit-doc ul,.kit-doc ol{margin:.6em 0;padding-inline-start:1.6em}.kit-doc ul{list-style:disc}.kit-doc ol{list-style:decimal}.kit-doc table{border-collapse:collapse;width:100%;margin:1em 0}.kit-doc td,.kit-doc th{border:1px solid #d6cfbf;padding:6px 10px}.kit-doc img{max-width:100%}`}</style>
    </div>
  )
}
