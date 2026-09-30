import clsx from 'clsx'
import { CheckCheck, CircleDot, CornerDownLeft, MapPin, MessageSquarePlus, RotateCcw, Send, Sparkles, Trash2, UserRound, X } from 'lucide-react'
import { useEffect, useMemo, useRef, useState } from 'react'
import { useTranslation } from 'react-i18next'
import { Avatar, Badge, Button, Spinner } from '@/components/ui'
import { errorMessage } from '@/lib/api'
import { CATEGORIES, SEVERITIES, type Anchor, type CommentCategory, type CommentStatus, type Kit, type KitComment, type Severity } from '../types'
import { relativeTime, severityTone, useCommentActions, type NewComment } from './api'

type Props = {
  kit: Kit
  fileId?: string | null
  fileVersion?: number | null
  comments: KitComment[]
  loading?: boolean
  /** Where a new comment will be pinned (set by clicking on the slide, page, text or video). */
  draft?: Anchor | null
  onClearDraft?: () => void
  /** Restrict the list to the current slide / page (pass the predicate). */
  scopeLabel?: string
  scopeFilter?: (c: KitComment) => boolean
  activeId?: string | null
  onActive?: (c: KitComment | null) => void
  onJump?: (c: KitComment) => void
  anchorLabel: (a: Anchor | null) => string | null
}

const QUICK: { key: string; category: CommentCategory; severity: Severity }[] = [
  { key: 'unreadable', category: 'design', severity: 'major' }, { key: 'source', category: 'accuracy', severity: 'major' }, { key: 'language', category: 'language', severity: 'minor' },
  { key: 'objective', category: 'alignment', severity: 'major' }, { key: 'crowded', category: 'design', severity: 'minor' }, { key: 'activity', category: 'content', severity: 'info' },
  { key: 'alt', category: 'accessibility', severity: 'minor' }, { key: 'culture', category: 'content', severity: 'major' },
]

const ROLE_TAG: Record<string, string> = { qa_reviewer: 'qa', kit_developer: 'developer' }

function RoleTag({ roles }: { roles: string[] }) {
  const { t } = useTranslation()
  const tag = roles.map((r) => ROLE_TAG[r]).find(Boolean)
  if (!tag) return null
  return <span className={clsx('rounded-full px-1.5 py-px text-[10px] font-bold', tag === 'qa' ? 'bg-gold-100 text-gold-700' : 'bg-navy-100 text-navy-800')}>{t(`kits.comments.role.${tag}`)}</span>
}

/** Composer: severity / category / assignee, quick templates for reviewers, Ctrl+Enter to send. */
function Composer({ kit, draft, onClearDraft, anchorLabel, onSend, replyTo, onCancel, autoFocus }: {
  kit: Kit; draft?: Anchor | null; onClearDraft?: () => void; anchorLabel: Props['anchorLabel']; onSend: (p: Pick<NewComment, 'body' | 'category' | 'severity' | 'assignee_id'>) => Promise<void>
  replyTo?: KitComment; onCancel?: () => void; autoFocus?: boolean
}) {
  const { t } = useTranslation()
  const [body, setBody] = useState('')
  const [category, setCategory] = useState<CommentCategory>('content')
  const [severity, setSeverity] = useState<Severity>('minor')
  const [assignee, setAssignee] = useState('')
  const [busy, setBusy] = useState(false)
  const [error, setError] = useState<string | null>(null)
  const [more, setMore] = useState(false)
  const ref = useRef<HTMLTextAreaElement>(null)
  const canReview = !!kit.can?.review
  const isReply = !!replyTo
  const label = draft ? anchorLabel(draft) : null

  useEffect(() => { if (autoFocus || draft) ref.current?.focus() }, [autoFocus, draft])

  const send = async () => {
    if (!body.trim()) return
    setBusy(true)
    setError(null)
    try {
      await onSend({ body: body.trim(), category, severity: canReview ? severity : severity === 'info' ? 'info' : 'minor', assignee_id: assignee || null })
      setBody('')
      setMore(false)
    } catch (e) {
      setError(errorMessage(e))
    } finally {
      setBusy(false)
    }
  }
  const apply = (q: (typeof QUICK)[number]) => { setBody(t(`kits.comments.quick.${q.key}`)); setCategory(q.category); setSeverity(q.severity); ref.current?.focus() }

  return (
    <div className={clsx('rounded-2xl border bg-white p-3 shadow-sm', isReply ? 'border-navy-100' : 'border-gold-300 ring-2 ring-gold-100/70')}>
      {!isReply && (
        <div className="mb-2 flex items-center justify-between gap-2 text-xs">
          {label ? <span className="inline-flex items-center gap-1 rounded-full bg-gold-100 px-2 py-1 font-semibold text-gold-700"><MapPin className="size-3" />{label}</span> : <span className="text-slate-400">{t('kits.comments.generalHint')}</span>}
          {draft && <button type="button" onClick={onClearDraft} className="text-slate-400 hover:text-navy-900" aria-label={t('kits.common.cancel')}><X className="size-4" /></button>}
        </div>
      )}
      {!isReply && canReview && (
        <div className="mb-2 flex flex-wrap gap-1">
          {QUICK.map((q) => <button key={q.key} type="button" onClick={() => apply(q)} className="rounded-full border border-navy-100 bg-ivory px-2 py-0.5 text-[11px] font-semibold text-navy-800 transition hover:border-gold-400"><Sparkles className="me-1 inline size-3 text-gold-600" />{t(`kits.comments.quickLabel.${q.key}`)}</button>)}
        </div>
      )}
      <textarea ref={ref} value={body} rows={isReply ? 2 : 3} placeholder={isReply ? t('kits.comments.replyPlaceholder') : t('kits.comments.placeholder')} onChange={(e) => setBody(e.target.value)}
        onKeyDown={(e) => { if ((e.ctrlKey || e.metaKey) && e.key === 'Enter') { e.preventDefault(); void send() } }} className="input !rounded-xl !py-2 text-sm" />
      {!isReply && (
        <div className="mt-2 flex flex-wrap items-center gap-2">
          <div className="flex gap-1" role="radiogroup" aria-label={t('kits.comments.severity')}>
            {(canReview ? SEVERITIES : (['info', 'minor'] as Severity[])).map((s) => (
              <button key={s} type="button" role="radio" aria-checked={severity === s} onClick={() => setSeverity(s)} title={t(`kits.comments.severities.${s}`)}
                className={clsx('flex items-center gap-1 rounded-full px-2 py-1 text-[11px] font-bold ring-1 ring-inset transition', severity === s ? severityTone[s].chip : 'bg-white text-slate-400 ring-slate-200 hover:text-navy-900')}>
                <span className={clsx('size-2 rounded-full', severityTone[s].bar)} />{t(`kits.comments.severities.${s}`)}
              </button>
            ))}
          </div>
          <button type="button" onClick={() => setMore(!more)} className="ms-auto text-[11px] font-semibold text-slate-500 hover:text-navy-900">{more ? t('kits.comments.less') : t('kits.comments.more')}</button>
        </div>
      )}
      {!isReply && more && (
        <div className="mt-2 grid grid-cols-2 gap-2">
          <select className="input !py-1.5 text-xs" value={category} onChange={(e) => setCategory(e.target.value as CommentCategory)} aria-label={t('kits.comments.category')}>{CATEGORIES.map((c) => <option key={c} value={c}>{t(`kits.comments.categories.${c}`)}</option>)}</select>
          <select className="input !py-1.5 text-xs" value={assignee} onChange={(e) => setAssignee(e.target.value)} aria-label={t('kits.comments.assign')}>
            <option value="">{t('kits.comments.unassigned')}</option>
            {[...(kit.members ?? []).map((m) => ({ id: m.user_id, name: m.name ?? '' })), ...(kit.owner ? [{ id: kit.owner.id, name: kit.owner.name }] : [])].filter((m, i, a) => a.findIndex((x) => x.id === m.id) === i).map((m) => <option key={m.id} value={m.id}>{m.name}</option>)}
          </select>
        </div>
      )}
      {error && <p className="mt-2 rounded-lg bg-red-50 px-2 py-1 text-xs text-danger">{error}</p>}
      <div className="mt-2 flex items-center justify-between gap-2">
        <span className="hidden text-[10px] text-slate-400 sm:inline">Ctrl + <CornerDownLeft className="inline size-3" /></span>
        <div className="ms-auto flex gap-1.5">
          {onCancel && <Button size="sm" variant="ghost" onClick={onCancel}>{t('kits.common.cancel')}</Button>}
          <Button size="sm" variant="gold" loading={busy} disabled={!body.trim()} icon={<Send className="size-3.5" />} onClick={send}>{isReply ? t('kits.comments.reply') : t('kits.comments.send')}</Button>
        </div>
      </div>
    </div>
  )
}

function Thread({ c, kit, active, onActive, onJump, anchorLabel, actions }: { c: KitComment; kit: Kit; active: boolean; onActive: () => void; onJump?: () => void; anchorLabel: Props['anchorLabel']; actions: ReturnType<typeof useCommentActions> }) {
  const { t, i18n } = useTranslation()
  const [replying, setReplying] = useState(false)
  const [busy, setBusy] = useState(false)
  const ref = useRef<HTMLDivElement>(null)
  const can = kit.can
  const label = anchorLabel(c.anchor)
  const isDev = kit.my_role === 'developer' || !!can?.manage
  const done = c.status === 'resolved'

  useEffect(() => { if (active) ref.current?.scrollIntoView({ block: 'nearest', behavior: 'smooth' }) }, [active])
  const run = async (fn: () => Promise<unknown>) => { setBusy(true); try { await fn() } finally { setBusy(false) } }

  return (
    <div ref={ref} className={clsx('relative overflow-hidden rounded-2xl border bg-white transition', active ? 'border-gold-400 shadow-md ring-2 ring-gold-100' : 'border-navy-100', done && 'opacity-75')}>
      <span className={clsx('absolute inset-y-0 start-0 w-1', severityTone[c.severity].bar)} />
      <div className="cursor-pointer p-3 ps-4" onClick={onActive} role="button" tabIndex={0} onKeyDown={(e) => e.key === 'Enter' && onActive()}>
        <div className="flex items-start gap-2.5">
          <Avatar name={c.author.name} size={30} />
          <div className="min-w-0 flex-1">
            <div className="flex flex-wrap items-center gap-x-1.5 gap-y-0.5">
              <span className="text-sm font-bold text-navy-900">{c.author.name}</span><RoleTag roles={c.author.roles} />
              <span className="text-[11px] text-slate-400">{relativeTime(c.created_at, i18n.language)}</span>
            </div>
            <div className="mt-1 flex flex-wrap items-center gap-1">
              <span className={clsx('inline-flex items-center gap-1 rounded-full px-1.5 py-px text-[10px] font-bold ring-1 ring-inset', severityTone[c.severity].chip)}>{t(`kits.comments.severities.${c.severity}`)}</span>
              <span className="rounded-full bg-slate-100 px-1.5 py-px text-[10px] font-semibold text-slate-600">{t(`kits.comments.categories.${c.category}`)}</span>
              {label && <button type="button" onClick={(e) => { e.stopPropagation(); onJump?.() }} className="inline-flex items-center gap-0.5 rounded-full bg-gold-100 px-1.5 py-px text-[10px] font-bold text-gold-700 hover:bg-gold-200"><MapPin className="size-3" />{label}</button>}
              {c.file && <span className="max-w-[9rem] truncate rounded-full bg-navy-100/70 px-1.5 py-px text-[10px] font-semibold text-navy-800" title={c.file.name}>{c.file.name}</span>}
            </div>
          </div>
          <StatusPill status={c.status} />
        </div>
        <p className="mt-2 whitespace-pre-wrap text-sm leading-relaxed text-navy-900" dir="auto">{c.body}</p>
        {c.anchor?.quote && <blockquote className="mt-2 border-s-2 border-gold-400 ps-2 text-xs italic text-slate-500" dir="auto">{c.anchor.quote}</blockquote>}
        {c.assignee && <div className="mt-2 inline-flex items-center gap-1 text-[11px] text-slate-500"><UserRound className="size-3" />{t('kits.comments.assignedTo')} <b className="text-navy-800">{c.assignee.name}</b></div>}
      </div>

      {c.replies.length > 0 && (
        <div className="space-y-2 border-t border-dashed border-navy-100 bg-ivory/60 px-4 py-2.5">
          {c.replies.map((r) => (
            <div key={r.id} className="flex items-start gap-2">
              <Avatar name={r.author.name} size={22} />
              <div className="min-w-0 flex-1 text-sm">
                <div className="flex flex-wrap items-center gap-1.5"><span className="text-xs font-bold text-navy-900">{r.author.name}</span><RoleTag roles={r.author.roles} /><span className="text-[10px] text-slate-400">{relativeTime(r.created_at, i18n.language)}</span>
                  {r.mine && <button type="button" onClick={() => run(() => actions.remove(r.id))} className="ms-auto text-slate-300 hover:text-danger" aria-label={t('kits.common.delete')}><Trash2 className="size-3" /></button>}</div>
                <p className="whitespace-pre-wrap text-[13px] leading-relaxed text-slate-700" dir="auto">{r.body}</p>
              </div>
            </div>
          ))}
        </div>
      )}

      <div className="flex flex-wrap items-center gap-1.5 border-t border-navy-100 bg-white px-3 py-2 ps-4">
        {can?.comment && <Button size="sm" variant="ghost" onClick={() => setReplying(!replying)}>{t('kits.comments.reply')}</Button>}
        {c.status === 'open' && (isDev || can?.review) && <Button size="sm" variant="outline" loading={busy} icon={<CircleDot className="size-3.5" />} onClick={() => run(() => actions.setStatus(c.id, 'addressed'))}>{t('kits.comments.markAddressed')}</Button>}
        {c.status !== 'resolved' && can?.review && <Button size="sm" variant="gold" loading={busy} icon={<CheckCheck className="size-3.5" />} onClick={() => run(() => actions.setStatus(c.id, 'resolved'))}>{c.status === 'addressed' ? t('kits.comments.verify') : t('kits.comments.resolve')}</Button>}
        {c.status !== 'open' && can?.comment && <Button size="sm" variant="ghost" loading={busy} icon={<RotateCcw className="size-3.5" />} onClick={() => run(() => actions.setStatus(c.id, 'open'))}>{t('kits.comments.reopen')}</Button>}
        {c.mine && <button type="button" onClick={() => window.confirm(t('kits.comments.confirmDelete')) && run(() => actions.remove(c.id))} className="ms-auto p-1 text-slate-300 hover:text-danger" aria-label={t('kits.common.delete')}><Trash2 className="size-4" /></button>}
      </div>
      {replying && (
        <div className="border-t border-navy-100 bg-ivory/50 p-2.5">
          <Composer kit={kit} replyTo={c} anchorLabel={anchorLabel} autoFocus onCancel={() => setReplying(false)} onSend={async (p) => { await actions.create({ body: p.body, parent_id: c.id, file_id: c.file_id }); setReplying(false) }} />
        </div>
      )}
    </div>
  )
}

export function StatusPill({ status }: { status: CommentStatus }) {
  const { t } = useTranslation()
  const tone = { open: 'red', addressed: 'amber', resolved: 'green' } as const
  return <Badge color={tone[status]}>{t(`kits.comments.status.${status}`)}</Badge>
}

/** The review side panel: composer, filters and the threaded list. */
export default function CommentsPanel({ kit, fileId, fileVersion, comments, loading, draft, onClearDraft, scopeLabel, scopeFilter, activeId, onActive, onJump, anchorLabel }: Props) {
  const { t } = useTranslation()
  const actions = useCommentActions(kit.id)
  const [status, setStatus] = useState<'all' | CommentStatus>('open')
  const [scoped, setScoped] = useState(false)
  const [mine, setMine] = useState(false)

  const shown = useMemo(() => comments.filter((c) => (status === 'all' || c.status === status) && (!scoped || !scopeFilter || scopeFilter(c)) && (!mine || c.assignee_id === kit.owner_id || c.mine)), [comments, status, scoped, scopeFilter, mine, kit.owner_id])
  const counts = useMemo(() => ({ open: comments.filter((c) => c.status === 'open').length, addressed: comments.filter((c) => c.status === 'addressed').length, resolved: comments.filter((c) => c.status === 'resolved').length }), [comments])

  return (
    <div className="flex h-full min-h-0 flex-col gap-3">
      {kit.can?.comment && (
        <Composer kit={kit} draft={draft} onClearDraft={onClearDraft} anchorLabel={anchorLabel}
          onSend={async (p) => { await actions.create({ ...p, file_id: fileId ?? null, anchor: draft ?? (fileId ? { type: 'file' } : null), file_version: fileVersion ?? null }); onClearDraft?.() }} />
      )}
      <div className="flex flex-wrap items-center gap-1">
        {(['open', 'addressed', 'resolved', 'all'] as const).map((s) => (
          <button key={s} type="button" aria-pressed={status === s} onClick={() => setStatus(s)} className={clsx('rounded-full px-2.5 py-1 text-xs font-semibold transition', status === s ? 'bg-navy-900 text-white' : 'bg-white text-slate-500 ring-1 ring-inset ring-navy-100 hover:text-navy-900')}>
            {t(`kits.comments.filter.${s}`)}{s !== 'all' && <span className="ms-1 opacity-70">{counts[s]}</span>}
          </button>
        ))}
        {scopeFilter && <button type="button" aria-pressed={scoped} onClick={() => setScoped(!scoped)} className={clsx('ms-auto rounded-full px-2.5 py-1 text-xs font-semibold ring-1 ring-inset transition', scoped ? 'bg-gold-100 text-gold-700 ring-gold-400/40' : 'bg-white text-slate-500 ring-navy-100')}>{scopeLabel ?? t('kits.comments.thisPage')}</button>}
        <button type="button" aria-pressed={mine} onClick={() => setMine(!mine)} className={clsx('rounded-full px-2.5 py-1 text-xs font-semibold ring-1 ring-inset transition', mine ? 'bg-gold-100 text-gold-700 ring-gold-400/40' : 'bg-white text-slate-500 ring-navy-100')}>{t('kits.comments.mine')}</button>
      </div>
      <div className="-me-1 min-h-0 flex-1 space-y-2.5 overflow-y-auto pe-1">
        {loading ? <Spinner /> : shown.length === 0 ? (
          <div className="grid place-items-center rounded-2xl border border-dashed border-navy-200 px-4 py-10 text-center text-sm text-slate-400"><MessageSquarePlus className="mb-2 size-7 text-navy-200" />{comments.length ? t('kits.comments.noneMatch') : t('kits.comments.empty')}</div>
        ) : shown.map((c) => (
          <Thread key={c.id} c={c} kit={kit} active={activeId === c.id} onActive={() => onActive?.(activeId === c.id ? null : c)} onJump={() => onJump?.(c)} anchorLabel={anchorLabel} actions={actions} />
        ))}
      </div>
    </div>
  )
}
