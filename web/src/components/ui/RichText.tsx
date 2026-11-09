import clsx from 'clsx'
import { Bold, Code, Heading3, Italic, Link2, List, ListOrdered, Quote, RemoveFormatting, Unlink } from 'lucide-react'
import { useLayoutEffect, useRef, useState } from 'react'
import { useTranslation } from 'react-i18next'
import { HELP_BODY_CLASS } from '@/components/help/helpApi'
import { dialogs } from '@/lib/dialogs'
import { safeHtml } from '@/lib/safeHtml'

type Tool = { id: string; icon: typeof Bold; key: string; run: () => void }

/**
 * A small writing surface for text with headings, bold, lists, quotes and links, in the reading direction of its
 * language (Arabic right-to-left, English left-to-right), so the people who write help never see markup. «HTML» shows
 * the source for the few who want it. What is written is cleaned again when it is shown (safeHtml), and pasted text
 * keeps its words but not its formatting.
 */
export default function RichText({ value, onChange, dir, label, minHeight = '12rem' }: { value: string; onChange: (html: string) => void; dir: 'rtl' | 'ltr'; label: string; minHeight?: string }) {
  const { t } = useTranslation()
  const box = useRef<HTMLDivElement>(null)
  const last = useRef<string | null>(null)
  const [source, setSource] = useState(false)

  // The surface is set from the value on mount, when coming back from the source view and when the value is replaced from outside (not on every keystroke, which would move the cursor).
  useLayoutEffect(() => {
    if (source || !box.current || value === last.current) return
    box.current.innerHTML = safeHtml(value || '')
    last.current = value
  }, [value, source])

  const emit = () => { const html = box.current?.innerHTML ?? ''; last.current = html; onChange(html) }
  const exec = (name: string, arg?: string) => { box.current?.focus(); document.execCommand(name, false, arg); emit() }
  const link = async () => {
    const url = await dialogs.prompt(String(t('hlp.admin.linkPrompt')), 'https://')
    if (url && /^(https?:\/\/|mailto:|\/)/i.test(url.trim())) exec('createLink', url.trim())
  }
  const tools: Tool[] = [
    { id: 'h', icon: Heading3, key: 'hlp.admin.fmt.heading', run: () => exec('formatBlock', 'h3') },
    { id: 'b', icon: Bold, key: 'hlp.admin.fmt.bold', run: () => exec('bold') },
    { id: 'i', icon: Italic, key: 'hlp.admin.fmt.italic', run: () => exec('italic') },
    { id: 'ul', icon: List, key: 'hlp.admin.fmt.bullets', run: () => exec('insertUnorderedList') },
    { id: 'ol', icon: ListOrdered, key: 'hlp.admin.fmt.numbers', run: () => exec('insertOrderedList') },
    { id: 'q', icon: Quote, key: 'hlp.admin.fmt.quote', run: () => exec('formatBlock', 'blockquote') },
    { id: 'a', icon: Link2, key: 'hlp.admin.fmt.link', run: () => void link() },
    { id: 'u', icon: Unlink, key: 'hlp.admin.fmt.unlink', run: () => exec('unlink') },
    { id: 'c', icon: RemoveFormatting, key: 'hlp.admin.fmt.clear', run: () => { exec('removeFormat'); exec('formatBlock', 'p') } },
  ]

  return (
    <div className="overflow-hidden rounded-2xl border border-navy-100 bg-white focus-within:border-gold-400 focus-within:ring-2 focus-within:ring-gold-200">
      <div className="flex flex-wrap items-center gap-1 border-b border-navy-100 bg-ivory px-2 py-1.5" role="toolbar" aria-label={label}>
        {!source && tools.map(({ id, icon: Icon, key, run }) => (
          <button key={id} type="button" title={String(t(key))} aria-label={String(t(key))} onMouseDown={(e) => e.preventDefault()} onClick={run}
            className="grid size-9 place-items-center rounded-lg text-navy-800 transition hover:bg-white focus-visible:outline-2 focus-visible:outline-gold-500"><Icon className="size-4" /></button>
        ))}
        <button type="button" aria-pressed={source} onClick={() => setSource((s) => !s)} className={clsx('ms-auto inline-flex min-h-9 items-center gap-1.5 rounded-lg px-3 text-xs font-bold transition', source ? 'bg-navy-900 text-white' : 'text-slate-600 hover:bg-white')}><Code className="size-4" />HTML</button>
      </div>
      {source ? (
        <textarea className="block w-full resize-y bg-white p-3 font-mono text-xs outline-none" dir="ltr" style={{ minHeight }} value={value} aria-label={label} onChange={(e) => onChange(e.target.value)} />
      ) : (
        <div ref={box} contentEditable suppressContentEditableWarning role="textbox" aria-multiline="true" aria-label={label} dir={dir} lang={dir === 'rtl' ? 'ar' : 'en'} style={{ minHeight }}
          onInput={emit} onBlur={emit}
          onPaste={(e) => { e.preventDefault(); document.execCommand('insertText', false, e.clipboardData.getData('text/plain')) }}
          className={clsx('p-4 outline-none', HELP_BODY_CLASS)} />
      )}
    </div>
  )
}
