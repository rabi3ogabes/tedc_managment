import clsx from 'clsx'
import { Maximize, Pause, Play, Volume2, VolumeX } from 'lucide-react'
import { useCallback, useEffect, useRef, useState } from 'react'
import { useTranslation } from 'react-i18next'
import QuestionInput from '@/components/assessment/QuestionInput'
import { api } from '@/lib/api'
import { Button } from '@/components/ui'
import { clock, type LessonDetail, type ProgressResult } from './types'

const BEAT_MS = 10_000

/* eslint-disable @typescript-eslint/no-explicit-any */
type Interaction = { id: string; at_seconds: number; type: string; prompt_ar: string | null; prompt_en: string | null; required: boolean; blocks_progress: boolean; require_correct: boolean; allow_skip?: boolean; question: any | null; answered: boolean; correct: boolean | null }

/**
 * A video player that reports what was really watched. Every ten seconds of playback (and on pause) it tells the
 * server the stretch just played; the server decides how much of it counts. With skipping switched off, the seek bar
 * cannot go beyond the furthest point reached. The seek bar shows the parts already watched.
 * Anti-distraction rules set by the author: a full-screen lesson pauses when the learner leaves full screen (and the
 * server credits no time outside it), and a pause limit stops the learner pausing once the allowed pauses are used —
 * the server keeps the count, so a reload does not reset it. Pauses the player makes itself are not counted.
 */
export default function VideoPlayer({ lesson, onProgress }: { lesson: LessonDetail; onProgress: (r: ProgressResult) => void }) {
  const { t, i18n } = useTranslation()
  const media = lesson.media
  const video = useRef<HTMLVideoElement>(null)
  const box = useRef<HTMLDivElement>(null)
  const sent = useRef(lesson.progress.position)     // where the last reported stretch ended
  const furthest = useRef(lesson.progress.furthest)
  const lastBeat = useRef(0)
  const lastTick = useRef(0)
  const systemPause = useRef(false)   // the next pause comes from the player (question, hidden page, full screen), not the learner
  const fsSupported = typeof document !== 'undefined' && !!document.fullscreenEnabled
  const needFullscreen = lesson.rules.require_fullscreen && fsSupported
  const [pausesLeft, setPausesLeft] = useState<number | null>(lesson.rules.pauses_left ?? null)
  const [playing, setPlaying] = useState(false)
  const [time, setTime] = useState(0)
  const [duration, setDuration] = useState(lesson.duration_seconds)
  const [rate, setRate] = useState(1)
  const [muted, setMuted] = useState(false)
  const [segments, setSegments] = useState(lesson.progress.segments)
  const [percent, setPercent] = useState(lesson.progress.percent)
  const [notice, setNotice] = useState<string | null>(null)
  const [done, setDone] = useState(lesson.progress.status === 'completed')
  const [items, setItems] = useState<Interaction[]>([])
  const [active, setActive] = useState<Interaction | null>(null)
  const [reply, setReply] = useState<any>(undefined)
  const [verdict, setVerdict] = useState<{ correct: boolean | null; unlocked: boolean; explanation: string | null } | null>(null)
  const shown = useRef<Set<string>>(new Set())
  useEffect(() => { api.get<{ data: Interaction[] }>(`/me/lessons/${lesson.id}/interactions`).then((r) => setItems(r.data.data)).catch(() => undefined) }, [lesson.id])

  const beat = useCallback(async (force = false, paused = false) => {
    const el = video.current
    if (!el || (el.paused && !force)) return
    const from = sent.current
    const to = el.currentTime
    if (to - from < 0.5 && !force) return
    lastBeat.current = Date.now()
    sent.current = to
    if (to <= from && !paused) return
    try {
      const res = await api.post<{ data: ProgressResult }>(`/me/lessons/${lesson.id}/heartbeat`, {
        from, to: Math.max(from, to), duration: el.duration || undefined, rate: el.playbackRate, visible: !document.hidden,
        fullscreen: fsSupported ? !!document.fullscreenElement : undefined, paused: paused || undefined,
      })
      const r = res.data.data
      if (r.pauses_left !== undefined) setPausesLeft(r.pauses_left)
      setPercent(r.percent)
      setDone(r.completed)
      onProgress(r)
      if (r.furthest != null) furthest.current = r.furthest
      const blockedAt = (r as unknown as { blocked_at?: number | null }).blocked_at
      if (blockedAt != null && el.currentTime > blockedAt + 1) { el.currentTime = blockedAt; sent.current = blockedAt }
      // The server reached a lower point than the player (a skip): bring the learner back.
      if (!lesson.rules.allow_seeking && r.position != null && el.currentTime > r.position + 8) {
        el.currentTime = r.position
        sent.current = r.position
        setNotice(t('learn.noSeek'))
      }
      setSegments((s) => mergeSegment(s, [from, from + (r.credited ?? to - from)]))
    } catch {
      sent.current = from // try the same stretch again next time
    }
  }, [lesson.id, lesson.rules.allow_seeking, fsSupported, onProgress, t])

  // Resume where the learner stopped.
  const onLoaded = () => {
    const el = video.current
    if (!el) return
    setDuration(el.duration || lesson.duration_seconds)
    const at = lesson.progress.status === 'completed' ? 0 : lesson.progress.position
    if (at > 5 && at < el.duration - 5) {
      el.currentTime = at
      sent.current = at
      setNotice(t('learn.resumeAt', { time: clock(at) }))
    }
  }

  // Regular reports while playing, and one when the page is closed or hidden.
  useEffect(() => {
    const id = window.setInterval(() => void beat(), BEAT_MS)
    const onHide = () => {
      if (document.hidden) {
        if (lesson.rules.pause_when_hidden && video.current && !video.current.paused) { systemPause.current = true; video.current.pause(); setNotice(t('learn.hiddenPause')) }
        void beat(true)
      }
    }
    document.addEventListener('visibilitychange', onHide)
    return () => { window.clearInterval(id); document.removeEventListener('visibilitychange', onHide); void beat(true) }
  }, [beat, lesson.rules.pause_when_hidden, t])

  // A full-screen lesson stops when the learner leaves full screen.
  useEffect(() => {
    if (!needFullscreen) return
    const onChange = () => {
      const el = video.current
      if (!document.fullscreenElement && el && !el.paused) { systemPause.current = true; el.pause(); setNotice(t('learn.fullscreenNeeded')) }
    }
    document.addEventListener('fullscreenchange', onChange)
    return () => document.removeEventListener('fullscreenchange', onChange)
  }, [needFullscreen, t])

  useEffect(() => { if (!notice) return; const id = window.setTimeout(() => setNotice(null), 4000); return () => window.clearTimeout(id) }, [notice])

  if (!media) return <div className="grid aspect-video place-items-center rounded-2xl bg-navy-950 text-white/70">{t('learn.mediaMissing')}</div>

  // YouTube / Vimeo: tracked by time spent on the page while it is visible.
  if (media.kind === 'link' && media.embed) return <EmbeddedVideo lesson={lesson} embed={media.embed} onProgress={onProgress} />

  const src = media.url
  const seek = (to: number) => {
    const el = video.current
    if (!el) return
    const limit = lesson.rules.allow_seeking ? duration : furthest.current + 1
    if (to > limit) { setNotice(t('learn.noSeek')); to = limit }
    void beat(true)
    el.currentTime = to
    sent.current = to
  }
  const play = async () => {
    const el = video.current
    if (!el) return
    if (needFullscreen && !document.fullscreenElement) {
      try { await box.current?.requestFullscreen() } catch { setNotice(t('learn.fullscreenNeeded')); return }
    }
    void el.play()
  }
  const toggle = () => {
    const el = video.current
    if (!el) return
    if (el.paused) { void play(); return }
    if (pausesLeft === 0) { setNotice(t('learn.noPause')); return }
    el.pause()
  }
  // A pause the learner made (keyboard, media keys) after the limit is used is undone; the player's own pauses are not counted.
  const onPause = () => {
    const el = video.current
    const own = !systemPause.current && !!el && !el.ended
    systemPause.current = false
    if (own && pausesLeft === 0 && el) { setNotice(t('learn.noPause')); void el.play(); return }
    setPlaying(false)
    void beat(true, own)
  }
  const speeds = [0.75, 1, 1.25, 1.5, 1.75, 2, 2.5, 3].filter((x) => x <= lesson.rules.max_speed)

  return (
    <div ref={box} className="group relative overflow-hidden rounded-2xl bg-black shadow-glass">
      <video
        ref={video} src={src} className="aspect-video w-full" playsInline preload="metadata" controlsList="nodownload noplaybackrate" disablePictureInPicture
        onLoadedMetadata={onLoaded} onClick={toggle} onContextMenu={(e) => e.preventDefault()}
        onPlay={() => { setPlaying(true); sent.current = video.current?.currentTime ?? sent.current }} onPause={onPause}
        onEnded={() => { setPlaying(false); void beat(true) }}
        onTimeUpdate={() => {
          const el = video.current
          if (!el) return
          const cur = el.currentTime
          setTime(cur)
          // Continuous playback moves the reachable point forward; a jump does not.
          if (!el.seeking && cur >= lastTick.current && cur - lastTick.current < 2) furthest.current = Math.max(furthest.current, cur)
          lastTick.current = cur
          // An interaction opens the first time its moment is reached.
          const due = items.find((i) => !i.answered && !shown.current.has(i.id) && cur >= i.at_seconds && cur < i.at_seconds + 3)
          if (due && !active) { shown.current.add(due.id); if (!el.paused) { systemPause.current = true; el.pause() } setReply(undefined); setVerdict(null); setActive(due) }
          if (!lesson.rules.allow_seeking && !el.seeking && cur > furthest.current + 2) el.currentTime = furthest.current
        }}
        onSeeking={() => { const el = video.current; if (el && !lesson.rules.allow_seeking && el.currentTime > furthest.current + 2) { setNotice(t('learn.noSeek')); el.currentTime = furthest.current } }}
        onRateChange={() => setRate(video.current?.playbackRate ?? 1)}
      />
      {!playing && <button type="button" aria-label={needFullscreen ? t('learn.fullscreenResume') : 'play'} onClick={() => void play()} className="absolute inset-0 grid place-items-center bg-black/25 transition hover:bg-black/35"><span className="grid size-20 place-items-center rounded-full bg-white/95 text-navy-900 shadow-xl"><Play className="size-9 translate-x-0.5" /></span></button>}
      {active && (
        <div role="dialog" aria-modal="true" className="absolute inset-0 z-10 grid place-items-center overflow-y-auto bg-navy-950/90 p-4 text-white">
          <div className="w-full max-w-xl space-y-4 rounded-2xl bg-white p-5 text-navy-900" dir="auto">
            {active.question ? <QuestionInput q={{ id: active.id, type: active.question.type, stem_ar: active.question.stem_ar, stem_en: active.question.stem_en, media: active.question.media, points: 1, payload: active.question.payload }} value={reply} onChange={setReply} disabled={!!verdict?.unlocked} />
              : <><p className="font-semibold">{(i18n.language === 'ar' ? active.prompt_ar : active.prompt_en) || active.prompt_ar || active.prompt_en}</p>{active.type === 'reflection' && <textarea className="w-full rounded-xl border border-navy-100 p-2 text-sm" rows={3} value={reply ?? ''} onChange={(e) => setReply(e.target.value)} />}</>}
            {verdict && <p className={clsx('rounded-xl p-2 text-sm', verdict.correct === false ? 'bg-red-50 text-danger' : 'bg-emerald-50 text-emerald-800')}>{verdict.correct === false ? t('assess.video.wrongMsg') : verdict.correct ? t('assess.video.correctMsg') : ''} {verdict.explanation}</p>}
            <div className="flex gap-2">
              {!verdict?.unlocked && <Button variant="gold" onClick={async () => {
                try { const { data } = await api.post(`/me/lessons/${lesson.id}/interactions/${active.id}/answer`, { answer: reply ?? '' }); const d = data.data; setVerdict({ correct: d.correct, unlocked: d.unlocked, explanation: (i18n.language === 'ar' ? d.explanation_ar : d.explanation_en) ?? null }); if (d.unlocked) setItems((x) => x.map((i) => (i.id === active.id ? { ...i, answered: true } : i))) } catch { setNotice(t('learn.noSeek')) }
              }}>{t('assess.video.submit')}</Button>}
              {(verdict?.unlocked || (!active.required && active.allow_skip !== false)) && <Button variant="outline" onClick={() => { setActive(null); void play() }}>{verdict?.unlocked ? t('assess.video.continue') : t('assess.video.skip')}</Button>}
            </div>
          </div>
        </div>
      )}
      {notice && <div className="absolute inset-x-0 top-3 mx-auto w-fit rounded-full bg-navy-950/90 px-4 py-1.5 text-sm text-white shadow">{notice}</div>}
      {done && <div className="absolute end-3 top-3 rounded-full bg-emerald-500 px-3 py-1 text-xs font-bold text-white shadow">{t('learn.lessonDone')}</div>}

      <div className="absolute inset-x-0 bottom-0 bg-gradient-to-t from-black/85 to-transparent px-4 pb-3 pt-10 text-white">
        <Seekbar duration={duration} time={time} segments={segments} furthest={lesson.rules.allow_seeking ? duration : furthest.current} onSeek={seek} />
        <div className="mt-2 flex items-center gap-3 text-sm" dir="ltr">
          <button type="button" aria-label="play/pause" onClick={toggle} disabled={playing && pausesLeft === 0} className="disabled:opacity-40">{playing ? <Pause className="size-5" /> : <Play className="size-5" />}</button>
          <button type="button" aria-label="mute" onClick={() => { if (video.current) { video.current.muted = !video.current.muted; setMuted(video.current.muted) } }}>{muted ? <VolumeX className="size-5" /> : <Volume2 className="size-5" />}</button>
          <span className="font-mono text-xs tabular-nums">{clock(time)} / {clock(duration)}</span>
          {pausesLeft !== null && <span role="status" aria-label={t('learn.pausesLeft', { n: pausesLeft })} title={t('learn.pausesLeft', { n: pausesLeft })} className={clsx('inline-flex shrink-0 items-center gap-1 whitespace-nowrap rounded-full px-2 py-0.5 text-xs tabular-nums', pausesLeft === 0 ? 'bg-white/15 text-white/60' : 'bg-gold-400/20 text-gold-200')}><Pause className="size-3" aria-hidden="true" />{pausesLeft}</span>}
          <span className="ms-auto text-xs text-white/70">{t('learn.watched', { percent: Math.round(percent) })}</span>
          <select aria-label={t('learn.speed')} value={rate} onChange={(e) => { if (video.current) video.current.playbackRate = Number(e.target.value) }} className="rounded-md border border-white/25 bg-black/40 px-1.5 py-0.5 text-xs">{speeds.map((x) => <option key={x} value={x}>{x}×</option>)}</select>
          <button type="button" aria-label={t('learn.fullscreen')} onClick={() => void (document.fullscreenElement ? document.exitFullscreen() : box.current?.requestFullscreen())}><Maximize className="size-5" /></button>
        </div>
      </div>
    </div>
  )
}

function mergeSegment(list: [number, number][], add: [number, number]): [number, number][] {
  const all = [...list, add].sort((a, b) => a[0] - b[0])
  const out: [number, number][] = []
  for (const s of all) {
    const last = out[out.length - 1]
    if (last && s[0] <= last[1] + 0.5) last[1] = Math.max(last[1], s[1])
    else out.push([s[0], s[1]])
  }
  return out
}

/** Seek bar with the watched parts painted on it and the unreachable end greyed out when skipping is off. */
function Seekbar({ duration, time, segments, furthest, onSeek }: { duration: number; time: number; segments: [number, number][]; furthest: number; onSeek: (t: number) => void }) {
  const bar = useRef<HTMLDivElement>(null)
  const at = (clientX: number) => { const r = bar.current!.getBoundingClientRect(); return Math.max(0, Math.min(1, (clientX - r.left) / r.width)) * duration }
  const pct = (x: number) => (duration > 0 ? (x / duration) * 100 : 0)
  return (
    <div ref={bar} dir="ltr" role="slider" tabIndex={0} aria-valuemin={0} aria-valuemax={Math.round(duration)} aria-valuenow={Math.round(time)} className="relative h-2 cursor-pointer rounded-full bg-white/25"
      onPointerDown={(e) => { (e.currentTarget as HTMLElement).setPointerCapture(e.pointerId); onSeek(at(e.clientX)) }}
      onPointerMove={(e) => { if (e.buttons === 1) onSeek(at(e.clientX)) }}
      onKeyDown={(e) => { if (e.key === 'ArrowRight') onSeek(time + 5); if (e.key === 'ArrowLeft') onSeek(Math.max(0, time - 5)) }}>
      {furthest < duration && <div className="absolute inset-y-0 end-0 rounded-e-full bg-black/40" style={{ left: `${pct(furthest)}%` }} />}
      {segments.map((s, i) => <div key={i} className="absolute inset-y-0 rounded-full bg-gold-400/80" style={{ left: `${pct(s[0])}%`, width: `${Math.max(0.4, pct(s[1] - s[0]))}%` }} />)}
      <div className="absolute inset-y-0 left-0 rounded-full bg-white" style={{ width: `${pct(time)}%` }} />
      <div className="absolute top-1/2 size-3.5 -translate-x-1/2 -translate-y-1/2 rounded-full bg-white shadow" style={{ left: `${pct(time)}%` }} />
    </div>
  )
}

/** YouTube / Vimeo: progress counts the time the page is open and visible, since the player cannot be observed. */
function EmbeddedVideo({ lesson, embed, onProgress }: { lesson: LessonDetail; embed: string; onProgress: (r: ProgressResult) => void }) {
  const { t } = useTranslation()
  const position = useRef(lesson.progress.position)
  useEffect(() => {
    const id = window.setInterval(async () => {
      if (document.hidden || !document.hasFocus()) return
      const from = position.current
      position.current = from + 10
      try {
        const res = await api.post<{ data: ProgressResult }>(`/me/lessons/${lesson.id}/heartbeat`, { from, to: from + 10, duration: lesson.duration_seconds || undefined })
        onProgress(res.data.data)
      } catch { position.current = from }
    }, 10_000)
    return () => window.clearInterval(id)
  }, [lesson.id, lesson.duration_seconds, onProgress])
  return (
    <div className="overflow-hidden rounded-2xl bg-black shadow-glass">
      <iframe src={embed} title={lesson.title} className="aspect-video w-full" allow="accelerometer; autoplay; encrypted-media; picture-in-picture; fullscreen" allowFullScreen referrerPolicy="strict-origin-when-cross-origin" />
      <p className={clsx('bg-navy-900 px-4 py-2 text-xs text-white/70')}>{t('learn.needWatch', { percent: lesson.rules.min_watch_percent })}</p>
    </div>
  )
}
