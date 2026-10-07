import { useEffect, useState } from 'react'
import { useTranslation } from 'react-i18next'
import type { DialogRequest } from '@/lib/dialogs'
import { Button, Modal } from '.'

/** Shows the questions asked through `dialogs.confirm` / `dialogs.prompt`, one at a time, in the application's own style. */
export default function DialogHost() {
  const { t } = useTranslation()
  const [queue, setQueue] = useState<DialogRequest[]>([])
  const [text, setText] = useState('')
  const current = queue[0]

  useEffect(() => {
    const onDialog = (e: Event) => setQueue((q) => [...q, (e as CustomEvent<DialogRequest>).detail])
    window.addEventListener('tedc:dialog', onDialog)
    return () => window.removeEventListener('tedc:dialog', onDialog)
  }, [])
  useEffect(() => { setText(current?.initial ?? '') }, [current?.id, current?.initial])

  if (!current) return null
  const finish = (value: boolean | string | null) => { current.resolve(value); setQueue((q) => q.slice(1)) }
  const cancel = () => finish(current.kind === 'confirm' ? false : null)
  const ok = () => finish(current.kind === 'confirm' ? true : text)
  return (
    <Modal open onClose={cancel} title={current.kind === 'confirm' ? t('common.confirm', { defaultValue: 'Confirm' }) : current.message}>
      <form className="space-y-4" onSubmit={(e) => { e.preventDefault(); ok() }}>
        {current.kind === 'confirm' ? <p className="whitespace-pre-line leading-7 text-slate-700">{current.message}</p> : (
          <textarea className="input min-h-20" autoFocus value={text} onChange={(e) => setText(e.target.value)} aria-label={current.message} />
        )}
        <div className="flex justify-end gap-2">
          <Button type="button" variant="outline" onClick={cancel}>{t('common.cancel')}</Button>
          <Button type="submit" variant="gold" autoFocus={current.kind === 'confirm'}>{t('common.confirm', { defaultValue: 'OK' })}</Button>
        </div>
      </form>
    </Modal>
  )
}
