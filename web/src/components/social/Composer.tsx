/* eslint-disable @typescript-eslint/no-explicit-any */
import { useState } from 'react'
import { useTranslation } from 'react-i18next'
import { Button, Card, Field } from '@/components/ui'
import { api, errorMessage } from '@/lib/api'
import { toast } from '@/lib/toast'

/** Writes a new post: a discussion, a question, a poll, and — for moderators — an announcement. */
export default function Composer({ spaceId, canModerate, allowPolls, onPosted }: { spaceId: string; canModerate: boolean; allowPolls: boolean; onPosted: () => void }) {
  const { t } = useTranslation()
  const [kind, setKind] = useState('discussion')
  const [title, setTitle] = useState('')
  const [body, setBody] = useState('')
  const [question, setQuestion] = useState('')
  const [options, setOptions] = useState('')
  const [multiple, setMultiple] = useState(false)
  const [busy, setBusy] = useState(false)
  const kinds = ['discussion', 'question', ...(allowPolls ? ['poll'] : []), ...(canModerate ? ['announcement'] : [])]
  const opts = options.split('\n').map((x) => x.trim()).filter(Boolean)
  const ready = kind === 'poll' ? opts.length >= 2 && question.trim() : body.trim()

  const submit = async () => {
    setBusy(true)
    try {
      const esc = (s: string) => s.replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')
      const html = body.trim() ? body.split(/\n{2,}/).map((p) => `<p>${esc(p).replace(/\n/g, '<br>')}</p>`).join('') : ''
      const { data } = await api.post(`/social/spaces/${spaceId}/posts`, { kind, title: title || undefined, body: html || (kind === 'poll' ? `<p>${esc(question)}</p>` : ''), ...(kind === 'poll' ? { poll: { question, options: opts, multiple } } : {}) })
      toast(String(t(data.data.status === 'hidden' ? 'soc.post.heldForReview' : 'soc.post.published')))
      setTitle(''); setBody(''); setQuestion(''); setOptions(''); setKind('discussion'); onPosted()
    } catch (e) { toast(errorMessage(e), 'error') } finally { setBusy(false) }
  }

  return (
    <Card className="mb-5">
      <div className="mb-3 flex flex-wrap gap-1.5">
        {kinds.map((k) => <button key={k} type="button" onClick={() => setKind(k)} className={`rounded-full border px-3 py-1 text-xs font-semibold transition ${kind === k ? 'border-navy-900 bg-navy-900 text-white' : 'border-navy-100 text-slate-600 hover:border-gold-400'}`}>{t(`soc.post.kinds.${k}`)}</button>)}
      </div>
      <input className="input mb-2" value={title} onChange={(e) => setTitle(e.target.value)} placeholder={String(t('soc.post.title'))} maxLength={250} />
      {kind === 'poll' ? (
        <div className="space-y-3">
          <Field label={t('soc.post.pollQuestion')}><input className="input" value={question} onChange={(e) => setQuestion(e.target.value)} maxLength={250} /></Field>
          <Field label={t('soc.post.pollOptions')}><textarea className="input min-h-24" value={options} onChange={(e) => setOptions(e.target.value)} /></Field>
          <label className="flex items-center gap-2 text-sm"><input type="checkbox" className="accent-gold-600" checked={multiple} onChange={(e) => setMultiple(e.target.checked)} />{t('soc.post.multiple')}</label>
        </div>
      ) : <textarea className="input min-h-24" value={body} onChange={(e) => setBody(e.target.value)} placeholder={String(t('soc.post.body'))} maxLength={20000} />}
      <div className="mt-3 flex justify-end"><Button variant="gold" loading={busy} disabled={!ready} onClick={submit}>{t('soc.post.publish')}</Button></div>
    </Card>
  )
}
