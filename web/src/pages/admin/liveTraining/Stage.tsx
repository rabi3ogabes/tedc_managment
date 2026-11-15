import { useEffect, useImperativeHandle, useRef, useState, type Ref } from 'react'
import { useTranslation } from 'react-i18next'
import { Loader2 } from 'lucide-react'
import type { LiveScene } from './scene'
import type { SceneRoom } from './types'

export type StageHandle = { zoom: (factor: number) => void; reset: () => void }

/**
 * The 3D drawing of the centre. The drawing library is loaded only when this page opens, and a device that cannot draw
 * 3D gets a plain message instead: the room list beside the stage carries the same information.
 */
export default function Stage({ rooms, selected, onSelect, onHover, ref }: {
  rooms: SceneRoom[]; selected: string | null; onSelect: (roomId: string) => void; onHover: (roomId: string | null, x: number, y: number) => void; ref?: Ref<StageHandle>
}) {
  const { t, i18n } = useTranslation()
  const host = useRef<HTMLDivElement>(null)
  const scene = useRef<LiveScene | null>(null)
  const [status, setStatus] = useState<'loading' | 'ready' | 'failed'>('loading')
  // The scene is built once; it reads the latest callbacks and data through these.
  const latest = useRef({ rooms, selected, onSelect, onHover, t })
  useEffect(() => { latest.current = { rooms, selected, onSelect, onHover, t } })

  useImperativeHandle(ref, () => ({ zoom: (f) => scene.current?.zoom(f), reset: () => scene.current?.resetView() }), [])

  useEffect(() => {
    let alive = true
    const el = host.current
    if (!el) return
    setStatus('loading')
    import('./scene').then(({ LiveScene }) => {
      if (!alive) return
      try {
        const { t } = latest.current
        const s = new LiveScene(el, {
          mirror: i18n.dir() === 'rtl', reducedMotion: window.matchMedia('(prefers-reduced-motion: reduce)').matches,
          text: { trainer: String(t('liveT.tip.trainer')), supervisor: String(t('liveT.tip.supervisor')), floor: (name) => (/طابق|floor/i.test(name) ? name : String(t('liveT.stage.floor', { name }))) },
          onSelect: (id) => latest.current.onSelect(id),
          onHover: (id, x, y) => latest.current.onHover(id, x, y),
        })
        scene.current = s
        s.setData(latest.current.rooms)
        s.select(latest.current.selected)
        setStatus('ready')
      } catch {
        setStatus('failed')
      }
    }).catch(() => { if (alive) setStatus('failed') })
    return () => { alive = false; scene.current?.dispose(); scene.current = null }
    // The scene is rebuilt only when the language (and so the reading direction and the labels) changes.
  }, [i18n, i18n.language])

  useEffect(() => { scene.current?.setData(rooms) }, [rooms])
  useEffect(() => { scene.current?.select(selected) }, [selected])

  return (
    <div className="absolute inset-0">
      <div ref={host} className="absolute inset-0" role="img" aria-label={String(t('liveT.stage.label'))} />
      {status === 'loading' && <div className="absolute inset-0 grid place-items-center text-sm text-white/70"><span className="flex items-center gap-3"><Loader2 className="size-5 animate-spin text-gold-300" />{t('liveT.stage.loading')}</span></div>}
      {status === 'failed' && <div className="absolute inset-0 grid place-items-center p-8"><p className="max-w-sm text-center text-sm leading-7 text-white/75">{t('liveT.stage.failed')}</p></div>}
    </div>
  )
}
