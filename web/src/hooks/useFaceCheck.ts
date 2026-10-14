import { useEffect, useRef, useState } from 'react'
import type { FaceProctoring } from '@/components/assessment/FaceCheck'
import { FacePresence, type FaceCheckEvent, type FaceStatus } from '@/lib/proctoring/facePresence'

/**
 * Runs the camera check for an attempt that asks for it. Only event names are reported; the hint (no face, more than
 * one) is for the learner and appears after a few seconds, so a missed frame never alarms anyone.
 */
export function useFaceCheck(attemptId: string | null, proctoring: FaceProctoring | null | undefined, report: (type: FaceCheckEvent) => void, initialStream: MediaStream | null) {
  const videoRef = useRef<HTMLVideoElement>(null)
  const [status, setStatus] = useState<FaceStatus | 'off'>('off')
  const [hint, setHint] = useState<'notSeen' | 'many' | null>(null)
  const since = useRef<{ kind: 'notSeen' | 'many' | null; at: number }>({ kind: null, at: 0 })
  const reportRef = useRef(report)
  useEffect(() => { reportRef.current = report }, [report])
  const on = !!proctoring?.face_check
  const interval = proctoring?.face_check_interval_ms ?? 1000
  const absent = proctoring?.face_absent_seconds ?? 10

  useEffect(() => {
    if (!attemptId || !on) return
    let presence: FacePresence | null = null
    let cancelled = false
    void (async () => {
      const stream = initialStream ?? (await FacePresence.requestCamera())
      if (cancelled) {
        if (stream instanceof MediaStream) stream.getTracks().forEach((x) => x.stop())
        return
      }
      if (!(stream instanceof MediaStream) || !videoRef.current) {
        reportRef.current('camera_off')
        setStatus('unavailable')
        return
      }
      presence = new FacePresence({
        intervalMs: interval, absentSeconds: absent, onEvent: (type) => reportRef.current(type), onStatus: setStatus,
        onFaces: (n) => {
          const kind = n === 0 ? 'notSeen' : n > 1 ? 'many' : null
          const now = performance.now()
          if (kind !== since.current.kind) since.current = { kind, at: now }
          setHint(kind && now - since.current.at >= 3000 ? kind : null)
        },
      })
      await presence.start(stream, videoRef.current)
    })()
    return () => { cancelled = true; presence?.stop() }
  }, [attemptId, on, interval, absent]) // eslint-disable-line react-hooks/exhaustive-deps

  return { videoRef, status, hint, on }
}
