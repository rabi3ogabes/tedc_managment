import clsx from 'clsx'
import { Building2, Camera, CameraOff, Clock, Eye, ShieldCheck } from 'lucide-react'
import { useState, type ReactNode } from 'react'
import { useTranslation } from 'react-i18next'
import { Link } from 'react-router-dom'
import { Button, Card } from '@/components/ui'
import { FacePresence, type FaceStatus } from '@/lib/proctoring/facePresence'

/** What the server says when the exam needs the learner's consent first. */
export type FaceConsentDetails = { fallback: 'centre' | 'flagged'; retention_days: number; consent_version: string }

export type FaceProctoring = { face_check?: boolean; face_absent_seconds?: number; face_check_interval_ms?: number; snapshots?: boolean }

function Point({ icon, title, children }: { icon: ReactNode; title: string; children: ReactNode }) {
  return (
    <li className="flex gap-3">
      <span className="mt-0.5 grid size-9 shrink-0 place-items-center rounded-xl bg-navy-50 text-navy-800">{icon}</span>
      <div><p className="font-bold text-navy-900">{title}</p><p className="text-sm leading-relaxed text-slate-600">{children}</p></div>
    </li>
  )
}

/**
 * The consent card shown before an exam with the camera presence check: what is checked, what never happens, how long
 * the data is kept and the alternative. Agreeing opens the browser's camera prompt; refusing (or having no camera)
 * leads to the assessment's fallback.
 */
export function FaceConsent({ details, snapshots, onStart }: { details: FaceConsentDetails; snapshots: boolean; onStart: (consent: { given: true; camera: boolean; version: string }, stream: MediaStream | null) => Promise<void> }) {
  const { t } = useTranslation()
  const [phase, setPhase] = useState<'ask' | 'asking' | 'refused'>('ask')
  const [reason, setReason] = useState<'denied' | 'no_camera' | null>(null)
  const [busy, setBusy] = useState(false)

  const accept = async () => {
    setPhase('asking')
    const s = await FacePresence.requestCamera()
    if (s instanceof MediaStream) {
      setBusy(true)
      await onStart({ given: true, camera: true, version: details.consent_version }, s).finally(() => setBusy(false))
      return
    }
    setReason(s)
    setPhase('refused')
  }

  return (
    <div className="mx-auto max-w-xl px-4 py-12">
      <Card className="space-y-6">
        <div className="flex items-start gap-3">
          <span className="grid size-12 shrink-0 place-items-center rounded-2xl bg-navy-900 text-gold-300"><Camera className="size-6" /></span>
          <div><h1 className="font-display text-xl font-bold text-navy-900">{t('assess.face.consentTitle')}</h1><p className="text-slate-600">{t('assess.face.consentIntro')}</p></div>
        </div>
        <ul className="space-y-4">
          <Point icon={<Eye className="size-5" />} title={t('assess.face.what')}>{t('assess.face.whatText')}</Point>
          <Point icon={<ShieldCheck className="size-5" />} title={t('assess.face.never')}>{t('assess.face.neverText')}{snapshots && <> {t('assess.face.snapshotsNote')}</>}</Point>
          <Point icon={<Clock className="size-5" />} title={t('assess.face.keep')}>{t('assess.face.keepText', { n: details.retention_days })}</Point>
          <Point icon={<Building2 className="size-5" />} title={t('assess.face.alternative')}>{t(details.fallback === 'flagged' ? 'assess.face.alternativeFlagged' : 'assess.face.alternativeCentre')}</Point>
        </ul>
        {phase === 'refused' ? (
          <div className="space-y-3 rounded-2xl bg-amber-50 p-4 text-amber-950" role="status">
            <p className="flex items-center gap-2 font-bold"><CameraOff className="size-5" />{t(reason === 'denied' ? 'assess.face.denied' : 'assess.face.missing')}</p>
            {details.fallback === 'flagged' ? (
              <>
                <p className="text-sm">{t('assess.face.proceedNote')}</p>
                <div className="flex flex-wrap gap-2">
                  <Button variant="gold" loading={busy} onClick={() => { setBusy(true); void onStart({ given: true, camera: false, version: details.consent_version }, null).finally(() => setBusy(false)) }}>{t('assess.face.proceedFlagged')}</Button>
                  <Button variant="outline" onClick={() => void accept()}>{t('assess.face.accept')}</Button>
                </div>
              </>
            ) : (
              <>
                <p className="text-sm">{t('assess.face.centreText')}</p>
                <div className="flex flex-wrap gap-2">
                  <Button variant="outline" onClick={() => void accept()}>{t('assess.face.accept')}</Button>
                  <Link to="/portal/assessments" className="self-center text-sm font-bold text-link">{t('assess.face.back')}</Link>
                </div>
              </>
            )}
          </div>
        ) : (
          <div className="flex flex-wrap items-center gap-3">
            <Button variant="gold" icon={<Camera className="size-4" />} loading={phase === 'asking' || busy} onClick={() => void accept()}>{t('assess.face.accept')}</Button>
            <Button variant="outline" onClick={() => { setReason('no_camera'); setPhase('refused') }}>{t('assess.face.noCamera')}</Button>
            {phase === 'asking' && <span className="text-sm text-slate-500" aria-live="polite">{t('assess.face.asking')}</span>}
          </div>
        )}
      </Card>
    </div>
  )
}

/** When the exam must be taken in the centre (no camera, and the assessment does not allow going ahead without one). */
export function FaceCentreOnly() {
  const { t } = useTranslation()
  return (
    <div className="mx-auto max-w-xl px-4 py-16">
      <Card className="space-y-4 text-center">
        <span className="mx-auto grid size-14 place-items-center rounded-2xl bg-navy-50 text-navy-800"><Building2 className="size-7" /></span>
        <h1 className="font-display text-xl font-bold text-navy-900">{t('assess.face.centreTitle')}</h1>
        <p className="text-slate-600">{t('assess.face.centreText')}</p>
        <Link to="/portal/assessments" className="font-bold text-link">{t('assess.face.back')}</Link>
      </Card>
    </div>
  )
}

/** The small "camera check on" pill in the exam header, with a button to look at the camera when the learner wants. */
export function FaceIndicator({ status, preview, onPreview }: { status: FaceStatus | 'off'; preview: boolean; onPreview: () => void }) {
  const { t } = useTranslation()
  if (status === 'off') return null
  return (
    <span className={clsx('inline-flex items-center gap-2 rounded-full px-3 py-1 text-xs font-bold', status === 'on' ? 'bg-emerald-50 text-emerald-800' : status === 'starting' ? 'bg-navy-50 text-navy-800' : 'bg-amber-50 text-amber-900')}>
      <span className={clsx('size-2 rounded-full', status === 'on' ? 'bg-emerald-500' : status === 'starting' ? 'animate-pulse bg-navy-400' : 'bg-amber-500')} aria-hidden />
      {t(status === 'on' ? 'assess.face.on' : status === 'starting' ? 'assess.face.starting' : 'assess.face.unavailable')}
      {status !== 'unavailable' && <button type="button" onClick={onPreview} className="underline decoration-dotted underline-offset-2">{t(preview ? 'assess.face.hideCamera' : 'assess.face.showCamera')}</button>}
    </span>
  )
}
