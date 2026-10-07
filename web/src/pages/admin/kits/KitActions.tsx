import { BadgeCheck, MessageSquareWarning, Rocket, RotateCcw, Send, Archive } from 'lucide-react'
import { useState } from 'react'
import { useTranslation } from 'react-i18next'
import { Button, Field, Modal } from '@/components/ui'
import { api, errorMessage } from '@/lib/api'
import type { KitDetail } from './types'
import { dialogs } from '@/lib/dialogs'

type Action = 'submit' | 'request-changes' | 'approve' | 'publish' | 'reopen' | 'archive'

/** The lifecycle buttons a person is allowed to press right now. */
export default function KitActions({ kit, onDone, size = 'md', onDark = false }: { kit: KitDetail; onDone: () => void; size?: 'sm' | 'md'; onDark?: boolean }) {
  const quiet = onDark ? 'light' : 'outline'
  const { t } = useTranslation()
  const [dialog, setDialog] = useState<Action | null>(null)
  const [note, setNote] = useState('')
  const [force, setForce] = useState(false)
  const [busy, setBusy] = useState(false)
  const [error, setError] = useState<string | null>(null)
  const can = kit.can
  if (!can) return null

  const editable = ['draft', 'in_development', 'changes_requested'].includes(kit.status)
  const blocking = kit.completeness.blocking_comments
  const run = async (action: Action, body: Record<string, unknown> = {}) => {
    setBusy(true)
    setError(null)
    try {
      await api.post(`/admin/kits/${kit.id}/${action}`, body)
      setDialog(null)
      setNote('')
      setForce(false)
      onDone()
    } catch (e) {
      setError(errorMessage(e))
    } finally {
      setBusy(false)
    }
  }
  const open = (a: Action) => { setError(null); setNote(''); setForce(false); setDialog(a) }

  return (
    <div className="flex flex-wrap items-center gap-2">
      {can.manage && editable && <Button size={size} variant="gold" icon={<Send className="size-4" />} onClick={() => open('submit')}>{kit.status === 'changes_requested' ? t('kits.actions.resubmit') : t('kits.actions.submit')}</Button>}
      {can.review && kit.status === 'in_review' && (
        <>
          <Button size={size} variant={quiet} icon={<MessageSquareWarning className="size-4" />} onClick={() => open('request-changes')}>{t('kits.actions.requestChanges')}</Button>
          <Button size={size} variant="gold" icon={<BadgeCheck className="size-4" />} onClick={() => open('approve')}>{t('kits.actions.approve')}</Button>
        </>
      )}
      {can.publish && kit.status === 'approved' && <Button size={size} variant="primary" icon={<Rocket className="size-4" />} loading={busy} onClick={() => run('publish')}>{t('kits.actions.publish')}</Button>}
      {(can.publish || kit.my_role === 'developer') && ['approved', 'published', 'archived'].includes(kit.status) && <Button size={size} variant={quiet} icon={<RotateCcw className="size-4" />} loading={busy} onClick={async () => await dialogs.confirm(t('kits.actions.confirmReopen')) && run('reopen')}>{t('kits.actions.reopen')}</Button>}
      {can.publish && kit.status !== 'archived' && kit.status !== 'draft' && <Button size={size} variant={onDark ? 'light' : 'ghost'} icon={<Archive className="size-4" />} loading={busy} onClick={async () => await dialogs.confirm(t('kits.actions.confirmArchive')) && run('archive')}>{t('kits.actions.archive')}</Button>}

      <Modal open={dialog === 'submit'} onClose={() => setDialog(null)} title={t('kits.actions.submitTitle')}>
        <div className="space-y-4">
          <p className="text-sm text-slate-600">{t('kits.actions.submitText')}</p>
          {blocking > 0 && <p className="rounded-xl bg-amber-50 p-3 text-sm text-amber-800">{t('kits.actions.openBlockers', { count: blocking })}</p>}
          <Field label={t('kits.actions.noteToReviewers')}><textarea rows={3} className="input" dir="auto" value={note} onChange={(e) => setNote(e.target.value)} /></Field>
          {error && <div className="rounded-xl bg-red-50 p-3 text-sm text-danger">{error}</div>}
          <div className="flex justify-end gap-2"><Button variant="ghost" onClick={() => setDialog(null)}>{t('kits.common.cancel')}</Button><Button variant="gold" loading={busy} icon={<Send className="size-4" />} onClick={() => run('submit', { note: note || null })}>{t('kits.actions.submit')}</Button></div>
        </div>
      </Modal>

      <Modal open={dialog === 'request-changes'} onClose={() => setDialog(null)} title={t('kits.actions.requestTitle')}>
        <div className="space-y-4">
          <Field label={t('kits.actions.whatToFix')}><textarea rows={4} className="input" dir="auto" value={note} onChange={(e) => setNote(e.target.value)} autoFocus /></Field>
          {error && <div className="rounded-xl bg-red-50 p-3 text-sm text-danger">{error}</div>}
          <div className="flex justify-end gap-2"><Button variant="ghost" onClick={() => setDialog(null)}>{t('kits.common.cancel')}</Button><Button variant="danger" loading={busy} disabled={note.trim().length < 3} onClick={() => run('request-changes', { note })}>{t('kits.actions.requestChanges')}</Button></div>
        </div>
      </Modal>

      <Modal open={dialog === 'approve'} onClose={() => setDialog(null)} title={t('kits.actions.approveTitle')}>
        <div className="space-y-4">
          {blocking > 0 ? (
            <div className="rounded-xl bg-red-50 p-3 text-sm text-red-800">
              <p className="font-semibold">{t('kits.actions.blockedByComments', { count: blocking })}</p>
              {can.publish && <label className="mt-2 flex items-center gap-2"><input type="checkbox" className="size-4 accent-red-600" checked={force} onChange={(e) => setForce(e.target.checked)} />{t('kits.actions.approveAnyway')}</label>}
            </div>
          ) : <p className="text-sm text-slate-600">{t('kits.actions.approveText')}</p>}
          <Field label={t('kits.actions.approvalNote')}><textarea rows={3} className="input" dir="auto" value={note} onChange={(e) => setNote(e.target.value)} /></Field>
          {error && <div className="rounded-xl bg-red-50 p-3 text-sm text-danger">{error}</div>}
          <div className="flex justify-end gap-2"><Button variant="ghost" onClick={() => setDialog(null)}>{t('kits.common.cancel')}</Button><Button variant="gold" loading={busy} disabled={blocking > 0 && !force} icon={<BadgeCheck className="size-4" />} onClick={() => run('approve', { note: note || null, force })}>{t('kits.actions.approve')}</Button></div>
        </div>
      </Modal>
    </div>
  )
}
