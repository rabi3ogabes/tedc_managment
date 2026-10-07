/* eslint-disable @typescript-eslint/no-explicit-any */
import clsx from 'clsx'
import { CheckCircle2, Flag, Lock, MessageCircle, MoreHorizontal, Pin, ThumbsUp } from 'lucide-react'
import { useState } from 'react'
import { useTranslation } from 'react-i18next'
import { Avatar, Badge, Button, Field, Modal } from '@/components/ui'
import { api, errorMessage } from '@/lib/api'
import { fmt } from '@/lib/format'
import { safeHtml } from '@/lib/safeHtml'
import { toast } from '@/lib/toast'
import { dialogs } from '@/lib/dialogs'

const REACTIONS = ['like', 'insightful', 'thanks'] as const

/** One post: body, reactions, poll, comments (loaded when opened), and the actions its reader may take. */
export default function PostCard({ post, canModerate, onChanged, startOpen = false }: { post: any; canModerate: boolean; onChanged: () => void; startOpen?: boolean }) {
  const { t } = useTranslation()
  const [open, setOpen] = useState(startOpen)
  const [detail, setDetail] = useState<any>(null)
  const [comment, setComment] = useState('')
  const [reply, setReply] = useState<string | null>(null)
  const [report, setReport] = useState<{ type: 'post' | 'comment'; id: string } | null>(null)
  const [menu, setMenu] = useState(false)
  const [busy, setBusy] = useState(false)

  const load = async () => {
    try { setDetail((await api.get(`/social/posts/${post.id}`)).data.data) } catch (e) { toast(errorMessage(e), 'error') }
  }
  const toggle = async () => { const next = !open; setOpen(next); if (next && !detail) await load() }
  const run = async (fn: () => Promise<unknown>, after?: () => void) => {
    setBusy(true)
    try { await fn(); after?.(); onChanged(); if (open) await load() } catch (e) { toast(errorMessage(e), 'error') } finally { setBusy(false) }
  }
  const p = detail ?? post
  const react = (type: string, targetType: 'post' | 'comment' = 'post', id = post.id) => run(() => api.post('/social/reactions', { target_type: targetType, target_id: id, type }))
  const send = () => run(() => api.post(`/social/posts/${post.id}/comments`, { body: comment, parent_id: reply }), () => { setComment(''); setReply(null) })
  const moderate = (action: string) => { setMenu(false); return run(() => api.post(`/social/posts/${post.id}/moderate`, { action })) }

  const comments: any[] = detail?.comments ?? []
  const top = comments.filter((c) => !c.parent_id)

  return (
    <article className={clsx('card p-5', p.is_pinned && 'ring-1 ring-gold-300', p.status === 'hidden' && 'opacity-70')}>
      <header className="flex items-start gap-3">
        <Avatar name={p.author?.name ?? '؟'} size={40} />
        <div className="min-w-0 flex-1">
          <div className="flex flex-wrap items-center gap-x-2 gap-y-1 text-sm">
            <span className="font-semibold text-navy-900">{p.author?.anonymous ? t('soc.post.anonymous') : p.author?.name}</span>
            <span className="text-xs text-slate-400">{fmt.dateTime(p.created_at)}{p.edited_at && ` · ${t('soc.post.edited')}`}</span>
            <Badge color={p.kind === 'announcement' ? 'gold' : p.kind === 'question' ? 'blue' : 'gray'}>{t(`soc.post.kinds.${p.kind}`)}</Badge>
            {p.is_pinned && <Badge color="gold"><Pin className="size-3" />{t('soc.post.pinned')}</Badge>}
            {p.is_locked && <Badge color="gray"><Lock className="size-3" />{t('soc.post.locked')}</Badge>}
            {p.status === 'hidden' && <Badge color="amber">{t('soc.post.hidden')}</Badge>}
            {p.kind === 'question' && p.accepted_answer_id && <Badge color="green"><CheckCircle2 className="size-3" />{t('soc.post.answered')}</Badge>}
          </div>
          {p.title && <h3 className="mt-1 font-display text-lg font-bold text-navy-900">{p.title}</h3>}
        </div>
        <div className="relative">
          <button type="button" aria-label="menu" aria-expanded={menu} onClick={() => setMenu(!menu)} className="rounded-lg p-1.5 text-slate-400 hover:bg-navy-50 hover:text-navy-900"><MoreHorizontal className="size-5" /></button>
          {menu && (
            <div className="absolute end-0 z-10 mt-1 w-48 rounded-xl border border-navy-100 bg-white p-1 text-sm shadow-lg" onMouseLeave={() => setMenu(false)}>
              {canModerate && <>
                <MenuItem onClick={() => moderate(p.is_pinned ? 'unpin' : 'pin')}>{t(p.is_pinned ? 'soc.post.unpin' : 'soc.post.pin')}</MenuItem>
                <MenuItem onClick={() => moderate(p.is_locked ? 'unlock' : 'lock')}>{t(p.is_locked ? 'soc.post.unlock' : 'soc.post.lock')}</MenuItem>
                <MenuItem onClick={() => moderate(p.status === 'hidden' ? 'publish' : 'hide')}>{t(p.status === 'hidden' ? 'soc.post.publishHeld' : 'soc.post.hide')}</MenuItem>
              </>}
              {(p.mine || canModerate) && <MenuItem danger onClick={async () => { setMenu(false); if (await dialogs.confirm(String(t('soc.post.confirmDelete')))) run(() => api.delete(`/social/posts/${post.id}`)) }}>{t('soc.post.delete')}</MenuItem>}
              {!p.mine && <MenuItem onClick={() => { setMenu(false); setReport({ type: 'post', id: post.id }) }}>{t('soc.post.report')}</MenuItem>}
            </div>
          )}
        </div>
      </header>

      <div className="prose prose-sm mt-3 max-w-none text-ink" dangerouslySetInnerHTML={{ __html: safeHtml(p.body) }} />
      {p.poll && <Poll poll={p.poll} onVote={(ids) => run(() => api.post(`/social/polls/${p.poll.id}/vote`, { option_ids: ids }), () => toast(String(t('soc.post.voted'))))} />}

      <footer className="mt-4 flex flex-wrap items-center gap-2 border-t border-navy-50 pt-3">
        {REACTIONS.map((r) => (
          <button key={r} type="button" disabled={busy} onClick={() => react(r)} aria-pressed={p.my_reaction === r}
            className={clsx('inline-flex items-center gap-1 rounded-full border px-3 py-1 text-xs font-semibold transition', p.my_reaction === r ? 'border-gold-500 bg-gold-100 text-gold-700' : 'border-navy-100 text-slate-500 hover:border-gold-400')}>
            <ThumbsUp className="size-3.5" />{t(`soc.post.react.${r}`)}
          </button>
        ))}
        {p.reactions_count > 0 && <span className="text-xs text-slate-500">{p.reactions_count}</span>}
        <button type="button" onClick={toggle} className="ms-auto inline-flex items-center gap-1 text-sm font-semibold text-navy-800 hover:text-gold-700"><MessageCircle className="size-4" />{t('soc.post.comments', { n: p.comments_count })}</button>
      </footer>

      {open && (
        <div className="mt-4 space-y-3">
          {top.map((c) => (
            <div key={c.id}>
              <CommentRow c={c} post={p} onReply={() => setReply(c.id)} onReact={() => react('like', 'comment', c.id)} onReport={() => setReport({ type: 'comment', id: c.id })}
                onAccept={() => run(() => api.post(`/social/posts/${post.id}/accept`, { comment_id: p.accepted_answer_id === c.id ? null : c.id }))} canAccept={p.kind === 'question' && (p.mine || canModerate)}
                onDelete={() => run(() => api.delete(`/social/comments/${c.id}`))} canDelete={c.mine || canModerate} />
              {comments.filter((r) => r.parent_id === c.id).map((r) => (
                <div key={r.id} className="ms-8 mt-2"><CommentRow c={r} post={p} onReply={() => setReply(c.id)} onReact={() => react('like', 'comment', r.id)} onReport={() => setReport({ type: 'comment', id: r.id })}
                  onAccept={() => run(() => api.post(`/social/posts/${post.id}/accept`, { comment_id: r.id }))} canAccept={false} onDelete={() => run(() => api.delete(`/social/comments/${r.id}`))} canDelete={r.mine || canModerate} /></div>
              ))}
            </div>
          ))}
          {(!p.is_locked || canModerate) && (
            <div className="flex items-start gap-2">
              <textarea className="input min-h-11 flex-1" rows={reply ? 2 : 1} value={comment} onChange={(e) => setComment(e.target.value)} placeholder={String(t(reply ? 'soc.post.reply' : 'soc.post.writeComment'))} />
              <div className="flex flex-col gap-1">
                <Button size="sm" variant="gold" loading={busy} disabled={!comment.trim()} onClick={send}>{t('soc.post.send')}</Button>
                {reply && <Button size="sm" variant="ghost" onClick={() => setReply(null)}>{t('soc.common.cancel')}</Button>}
              </div>
            </div>
          )}
        </div>
      )}
      {report && <ReportDialog target={report} onClose={() => setReport(null)} />}
    </article>
  )
}

function MenuItem({ children, onClick, danger }: { children: React.ReactNode; onClick: () => void; danger?: boolean }) {
  return <button type="button" onClick={onClick} className={clsx('block w-full rounded-lg px-3 py-2 text-start hover:bg-navy-50', danger && 'text-danger')}>{children}</button>
}

function CommentRow({ c, post, onReply, onReact, onReport, onAccept, canAccept, onDelete, canDelete }: any) {
  const { t } = useTranslation()
  return (
    <div className={clsx('rounded-xl border p-3', c.accepted ? 'border-emerald-300 bg-emerald-50/50' : 'border-navy-50 bg-navy-50/30')}>
      <div className="flex flex-wrap items-center gap-2 text-xs">
        <span className="font-semibold text-navy-900">{c.author?.name}</span><span className="text-slate-400">{fmt.dateTime(c.created_at)}</span>
        {c.accepted && <Badge color="green"><CheckCircle2 className="size-3" />{t('soc.post.accepted')}</Badge>}
      </div>
      {c.body === null ? <p className="mt-1 text-sm italic text-slate-400">{t('soc.post.hidden')}</p> : <div className="prose prose-sm mt-1 max-w-none" dangerouslySetInnerHTML={{ __html: safeHtml(c.body) }} />}
      <div className="mt-2 flex flex-wrap gap-3 text-xs font-semibold text-slate-500">
        <button type="button" onClick={onReact} className={clsx('hover:text-gold-700', c.my_reaction && 'text-gold-700')}>{t('soc.post.react.like')}{c.reactions > 0 && ` (${c.reactions})`}</button>
        {!post.is_locked && <button type="button" onClick={onReply} className="hover:text-gold-700">{t('soc.post.reply')}</button>}
        {canAccept && <button type="button" onClick={onAccept} className="hover:text-emerald-700">{t(c.accepted ? 'soc.post.unaccept' : 'soc.post.accept')}</button>}
        {canDelete && <button type="button" onClick={onDelete} className="hover:text-danger">{t('soc.post.delete')}</button>}
        {!c.mine && <button type="button" onClick={onReport} className="inline-flex items-center gap-1 hover:text-danger"><Flag className="size-3" />{t('soc.post.report')}</button>}
      </div>
    </div>
  )
}

function Poll({ poll, onVote }: { poll: any; onVote: (ids: string[]) => void }) {
  const { t } = useTranslation()
  const [sel, setSel] = useState<string[]>(poll.mine ?? [])
  const total = Math.max(1, poll.options.reduce((s: number, o: any) => s + o.votes, 0))
  const closed = poll.closes_at && new Date(poll.closes_at) < new Date()
  const pick = (id: string) => setSel(poll.multiple ? (sel.includes(id) ? sel.filter((x) => x !== id) : [...sel, id]) : [id])
  return (
    <div className="mt-4 rounded-2xl border border-navy-100 bg-white p-4">
      <p className="mb-3 font-semibold text-navy-900">{poll.question}</p>
      <ul className="space-y-2">
        {poll.options.map((o: any) => {
          const pct = Math.round((o.votes / total) * 100)
          return (
            <li key={o.id}>
              <label className="relative block cursor-pointer overflow-hidden rounded-xl border border-navy-100 px-3 py-2 text-sm">
                <span className="absolute inset-y-0 start-0 bg-gold-100 transition-all" style={{ width: `${pct}%` }} aria-hidden />
                <span className="relative flex items-center justify-between gap-3">
                  <span className="flex items-center gap-2"><input type={poll.multiple ? 'checkbox' : 'radio'} className="accent-gold-600" checked={sel.includes(o.id)} disabled={!!closed} onChange={() => pick(o.id)} />{o.text}</span>
                  <span className="text-xs text-slate-500">{pct}% · {t('soc.post.votes', { n: o.votes })}</span>
                </span>
              </label>
            </li>
          )
        })}
      </ul>
      <div className="mt-3 flex items-center justify-between text-xs text-slate-500">
        <span>{t('soc.post.votes', { n: poll.total_voters })}{poll.closes_at && ` · ${closed ? t('soc.post.closed') : `${t('soc.post.closes')} ${fmt.dateTime(poll.closes_at)}`}`}</span>
        {!closed && <Button size="sm" variant="outline" disabled={sel.length === 0} onClick={() => onVote(sel)}>{t('soc.post.vote')}</Button>}
      </div>
    </div>
  )
}

function ReportDialog({ target, onClose }: { target: { type: string; id: string }; onClose: () => void }) {
  const { t } = useTranslation()
  const [reason, setReason] = useState('spam')
  const [note, setNote] = useState('')
  const [busy, setBusy] = useState(false)
  const submit = async () => {
    setBusy(true)
    try { await api.post('/social/reports', { target_type: target.type, target_id: target.id, reason, note: note || undefined }); toast(String(t('soc.post.reported'))); onClose() } catch (e) { toast(errorMessage(e), 'error') } finally { setBusy(false) }
  }
  return (
    <Modal open onClose={onClose} title={t('soc.post.report')}>
      <div className="space-y-4">
        <Field label={t('soc.post.reason')}><select className="input" value={reason} onChange={(e) => setReason(e.target.value)}>{['spam', 'abuse', 'inappropriate', 'privacy', 'other'].map((r) => <option key={r} value={r}>{t(`soc.post.reportReasons.${r}`)}</option>)}</select></Field>
        <Field label={t('soc.post.note')}><textarea className="input min-h-20" value={note} onChange={(e) => setNote(e.target.value)} maxLength={1000} /></Field>
        <div className="flex justify-end gap-2"><Button variant="ghost" onClick={onClose}>{t('soc.common.cancel')}</Button><Button variant="danger" loading={busy} onClick={submit}>{t('soc.post.report')}</Button></div>
      </div>
    </Modal>
  )
}
