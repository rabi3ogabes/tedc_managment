import clsx from 'clsx'
import { useEffect, useRef, useState } from 'react'
import { useTranslation } from 'react-i18next'
import { severityTone } from '../comments/api'
import type { Anchor, KitComment } from '../types'

type Props = { kind: 'image' | 'video'; url: string; reviewing: boolean; comments: KitComment[]; activeId: string | null; draft: Anchor | null; onPlace: (a: Anchor) => void; onPinClick: (c: KitComment) => void; seekTo: number | null }

const fmtTime = (s: number) => `${Math.floor(s / 60)}:${String(Math.floor(s % 60)).padStart(2, '0')}`

/** Images get pinned comments; videos get time-stamped ones with markers on the timeline. */
export default function MediaViewer({ kind, url, reviewing, comments, activeId, draft, onPlace, onPinClick, seekTo }: Props) {
  const { t } = useTranslation()
  const video = useRef<HTMLVideoElement>(null)
  const [now, setNow] = useState(0)

  useEffect(() => { if (seekTo != null && video.current) video.current.currentTime = seekTo }, [seekTo])

  const place = (e: React.PointerEvent) => {
    if (!reviewing) return
    const r = (e.currentTarget as HTMLElement).getBoundingClientRect()
    const anchor: Anchor = { type: kind === 'video' ? 'time' : 'point', x: Math.round(((e.clientX - r.left) / r.width) * 1000) / 1000, y: Math.round(((e.clientY - r.top) / r.height) * 1000) / 1000, at: kind === 'video' ? Math.round((video.current?.currentTime ?? 0) * 10) / 10 : null }
    if (kind === 'video') video.current?.pause()
    onPlace(anchor)
  }
  const visible = kind === 'video' ? comments.filter((c) => c.anchor?.at != null && Math.abs((c.anchor.at ?? 0) - now) < 2.5) : comments

  return (
    <div className="grid h-full place-items-center overflow-auto bg-[#1B1B1F] p-6">
      <div className="w-full max-w-5xl">
        <div className={clsx('relative mx-auto w-fit max-w-full overflow-hidden rounded-xl shadow-2xl', reviewing && 'ring-2 ring-gold-500')}>
          {kind === 'image' ? <img src={url} alt="" className="block max-h-[70vh] max-w-full bg-white" draggable={false} /> : <video ref={video} src={url} controls className="block max-h-[70vh] max-w-full bg-black" onTimeUpdate={(e) => setNow(e.currentTarget.currentTime)} />}
          {reviewing && <div className="absolute inset-0 cursor-crosshair" style={{ bottom: kind === 'video' ? 48 : 0 }} onPointerDown={place} />}
          {visible.map((c) => c.anchor?.x != null && c.anchor.y != null && (
            <button key={c.id} type="button" onClick={() => onPinClick(c)} title={c.body}
              className={clsx('absolute grid -translate-x-1/2 -translate-y-full place-items-center rounded-full rounded-bl-none text-[11px] font-bold text-white shadow-lg ring-2 ring-white', activeId === c.id ? 'z-10 size-8' : 'size-6')}
              style={{ left: `${c.anchor.x * 100}%`, top: `${c.anchor.y * 100}%`, background: severityTone[c.severity].pin }}>{comments.indexOf(c) + 1}</button>
          ))}
          {draft?.x != null && draft.y != null && <span className="absolute size-7 -translate-x-1/2 -translate-y-full animate-bounce rounded-full rounded-bl-none bg-gold-500 ring-2 ring-white" style={{ left: `${draft.x * 100}%`, top: `${draft.y * 100}%` }} />}
        </div>
        {kind === 'video' && comments.some((c) => c.anchor?.at != null) && (
          <div className="mx-auto mt-4 flex max-w-3xl flex-wrap gap-1.5">
            {comments.filter((c) => c.anchor?.at != null).map((c) => (
              <button key={c.id} type="button" onClick={() => { onPinClick(c); if (video.current && c.anchor?.at != null) video.current.currentTime = c.anchor.at }} className={clsx('rounded-full px-2.5 py-1 text-xs font-bold text-white', activeId === c.id ? 'ring-2 ring-gold-400' : '')} style={{ background: severityTone[c.severity].pin }} dir="ltr">{fmtTime(c.anchor?.at ?? 0)}</button>
            ))}
          </div>
        )}
        {reviewing && <p className="mt-4 text-center text-xs font-semibold text-gold-300">{kind === 'video' ? t('kits.viewer.videoHint') : t('kits.viewer.imageHint')}</p>}
      </div>
    </div>
  )
}
