import clsx from 'clsx'
import { Landmark } from 'lucide-react'
import { useState } from 'react'
import { useTranslation } from 'react-i18next'
import { Button } from '@/components/ui'

/** "The training kit belongs to the training centre" — said on every screen that shows it. */
export function OwnershipNotice({ compact = false, className }: { compact?: boolean; className?: string }) {
  const { t } = useTranslation()
  return (
    <div className={clsx('flex items-start gap-3 rounded-2xl border border-gold-300/70 bg-gradient-to-l from-gold-50 to-white', compact ? 'px-3 py-2' : 'px-4 py-3', className)} role="note">
      <span className={clsx('grid shrink-0 place-items-center rounded-xl bg-navy-900 text-gold-300', compact ? 'size-8' : 'size-10')}><Landmark className={compact ? 'size-4' : 'size-5'} /></span>
      <div className="min-w-0">
        <div className={clsx('font-bold text-navy-900', compact ? 'text-sm' : 'text-base')}>{t('teach.ownership')}</div>
        {!compact && <p className="mt-0.5 text-xs leading-6 text-slate-600">{t('teach.ownershipRules')}</p>}
      </div>
    </div>
  )
}

/** First opening of a kit: the trainer accepts, in so many words, that it belongs to the centre. */
export function AcknowledgeGate({ title, onAccept, onLeave, busy }: { title: string; onAccept: () => void; onLeave: () => void; busy?: boolean }) {
  const { t } = useTranslation()
  const [agree, setAgree] = useState(false)
  return (
    <div className="fixed inset-0 z-[70] grid place-items-center bg-navy-950/70 p-4 backdrop-blur-sm" role="dialog" aria-modal="true">
      <div className="w-full max-w-lg rounded-3xl bg-white p-6 shadow-2xl">
        <div className="flex items-center gap-3"><span className="grid size-12 place-items-center rounded-2xl bg-navy-900 text-gold-300"><Landmark className="size-6" /></span><div><div className="text-xs text-slate-500">{title}</div><h2 className="text-lg font-bold text-navy-900">{t('teach.ownership')}</h2></div></div>
        <ul className="mt-4 space-y-2 text-sm leading-7 text-slate-700">
          {(t('teach.rules', { returnObjects: true }) as string[]).map((r) => <li key={r} className="flex gap-2"><span className="mt-2 size-1.5 shrink-0 rounded-full bg-gold-500" />{r}</li>)}
        </ul>
        <label className="mt-4 flex cursor-pointer items-start gap-2 rounded-xl bg-ivory p-3 text-sm font-semibold text-navy-900"><input type="checkbox" className="mt-1 size-4 accent-[#8A1538]" checked={agree} onChange={(e) => setAgree(e.target.checked)} />{t('teach.agree')}</label>
        <div className="mt-5 flex justify-end gap-2"><Button variant="ghost" onClick={onLeave}>{t('teach.leave')}</Button><Button onClick={onAccept} disabled={!agree} loading={busy}>{t('teach.continue')}</Button></div>
      </div>
    </div>
  )
}
