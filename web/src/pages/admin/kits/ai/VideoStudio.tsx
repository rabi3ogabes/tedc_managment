import clsx from 'clsx'
import { Clapperboard, Film, ImagePlus, Loader2, Play, Save, Sparkles, Square } from 'lucide-react'
import { useRef, useState } from 'react'
import { useTranslation } from 'react-i18next'
import { useNavigate } from 'react-router-dom'
import { Button, Field, Modal, Progress } from '@/components/ui'
import { api, errorMessage } from '@/lib/api'
import type { Asset, Kit, KitFile } from '../types'

type Scene = { title: string; narration: string; image_prompt: string; seconds: number; asset?: Asset | null }
type Plan = { title: string; scenes: Scene[]; provider: string; ai_available: boolean }

const W = 1280
const H = 720

function load(url: string): Promise<HTMLImageElement> {
  return new Promise((resolve, reject) => {
    const img = new Image()
    img.crossOrigin = 'anonymous'
    img.onload = () => resolve(img)
    img.onerror = () => reject(new Error('image'))
    img.src = url
  })
}

function wrap(ctx: CanvasRenderingContext2D, text: string, maxWidth: number): string[] {
  const words = text.split(/\s+/)
  const lines: string[] = []
  let line = ''
  for (const w of words) {
    const test = line ? `${line} ${w}` : w
    if (ctx.measureText(test).width > maxWidth && line) { lines.push(line); line = w } else line = test
  }
  if (line) lines.push(line)
  return lines
}

/** Draws one frame: slow zoom on the picture, title, and a subtitle bar with the narration. */
function frame(ctx: CanvasRenderingContext2D, scenes: Scene[], images: (HTMLImageElement | null)[], t: number, rtl: boolean) {
  let acc = 0
  let index = scenes.length - 1
  for (let i = 0; i < scenes.length; i++) { if (t < acc + scenes[i].seconds) { index = i; break } acc += scenes[i].seconds }
  const local = t - acc
  const scene = scenes[index]
  const progress = Math.min(1, local / scene.seconds)
  const draw = (i: number, alpha: number, p: number) => {
    ctx.save()
    ctx.globalAlpha = alpha
    const img = images[i]
    if (img) {
      const scale = 1.04 + p * 0.1
      const r = Math.max(W / img.width, H / img.height) * scale
      const w = img.width * r, h = img.height * r
      ctx.drawImage(img, (W - w) / 2 + (p - 0.5) * 30 * (i % 2 ? -1 : 1), (H - h) / 2, w, h)
    } else {
      const g = ctx.createLinearGradient(0, 0, W, H)
      g.addColorStop(0, '#5E0E26'); g.addColorStop(1, '#8A1538')
      ctx.fillStyle = g
      ctx.fillRect(0, 0, W, H)
    }
    ctx.restore()
  }
  draw(index, 1, progress)
  const fade = 0.5
  if (index < scenes.length - 1 && scene.seconds - local < fade) draw(index + 1, 1 - (scene.seconds - local) / fade, 0)
  if (index > 0 && local < fade) { /* previous scene fades out under the new one */ }

  const grad = ctx.createLinearGradient(0, H * 0.45, 0, H)
  grad.addColorStop(0, 'rgba(0,0,0,0)'); grad.addColorStop(1, 'rgba(10,8,14,.82)')
  ctx.fillStyle = grad
  ctx.fillRect(0, H * 0.45, W, H * 0.55)

  ctx.direction = rtl ? 'rtl' : 'ltr'
  ctx.textAlign = 'center'
  ctx.fillStyle = '#F3E9D2'
  ctx.font = '700 46px "Qatar Sans", Tajawal, sans-serif'
  ctx.fillText(scene.title, W / 2, H - 200, W - 200)
  ctx.fillStyle = '#FFFFFF'
  ctx.font = '400 30px "Qatar Sans", Tajawal, sans-serif'
  wrap(ctx, scene.narration, W - 260).slice(0, 3).forEach((line, i) => ctx.fillText(line, W / 2, H - 140 + i * 42, W - 200))
  ctx.fillStyle = '#A29475'
  ctx.fillRect(0, H - 8, W * ((acc + local) / scenes.reduce((s, x) => s + x.seconds, 0)), 8)
}

/** Plan a short explainer video, draw a picture per scene, and render it to a video file in the browser. */
export default function VideoStudio({ kit, seed, onClose, onSaved }: { kit: Kit; seed?: Record<string, unknown>; onClose: () => void; onSaved?: (asset: Asset) => void }) {
  const { t, i18n } = useTranslation()
  const navigate = useNavigate()
  const [form, setForm] = useState({ topic: String(seed?.topic ?? (i18n.language === 'en' ? kit.title_en : kit.title_ar)), audience: String(seed?.audience ?? kit.audience ?? ''), scenes: 5, seconds: Number(seed?.seconds ?? 45), language: (i18n.language === 'en' ? 'en' : 'ar') as 'ar' | 'en' })
  const [plan, setPlan] = useState<Plan | null>(null)
  const [busy, setBusy] = useState<'plan' | 'images' | 'render' | 'save' | null>(null)
  const [done, setDone] = useState(0)
  const [error, setError] = useState<string | null>(null)
  const [video, setVideo] = useState<{ blob: Blob; url: string } | null>(null)
  const stop = useRef(false)
  const rtl = form.language === 'ar'

  const makePlan = async () => {
    setBusy('plan')
    setError(null)
    setVideo(null)
    try {
      const res = await api.post<{ data: Plan }>(`/admin/kits/${kit.id}/ai/storyboard`, { topic: form.topic, audience: form.audience || null, scenes: form.scenes, seconds: form.seconds, language: form.language })
      setPlan({ ...res.data.data, scenes: res.data.data.scenes.map((s) => ({ ...s, asset: null })) })
    } catch (e) {
      setError(errorMessage(e))
    } finally {
      setBusy(null)
    }
  }
  const patchScene = (i: number, patch: Partial<Scene>) => setPlan((p) => (p ? { ...p, scenes: p.scenes.map((s, n) => (n === i ? { ...s, ...patch } : s)) } : p))

  const drawImages = async () => {
    if (!plan) return
    setBusy('images')
    setError(null)
    setDone(0)
    stop.current = false
    try {
      for (let i = 0; i < plan.scenes.length; i++) {
        if (stop.current) break
        if (plan.scenes[i].asset) { setDone(i + 1); continue }
        const res = await api.post<{ data: Asset }>(`/admin/kits/${kit.id}/ai/image`, { prompt: plan.scenes[i].image_prompt, aspect: '16:9' })
        patchScene(i, { asset: res.data.data })
        setDone(i + 1)
      }
    } catch (e) {
      setError(errorMessage(e))
    } finally {
      setBusy(null)
    }
  }

  const render = async () => {
    if (!plan) return
    setBusy('render')
    setError(null)
    setDone(0)
    stop.current = false
    try {
      const images = await Promise.all(plan.scenes.map((s) => (s.asset ? load(s.asset.url).catch(() => null) : Promise.resolve(null))))
      const canvas = document.createElement('canvas')
      canvas.width = W
      canvas.height = H
      const ctx = canvas.getContext('2d')
      if (!ctx || typeof MediaRecorder === 'undefined') throw new Error(t('kits.video.unsupported'))
      const mime = ['video/webm;codecs=vp9', 'video/webm;codecs=vp8', 'video/webm'].find((m) => MediaRecorder.isTypeSupported(m)) ?? ''
      // Frames are pushed by hand so recording does not depend on the tab being in the foreground.
      // The canvas must be part of the page (but invisible) for the browser to keep producing frames.
      canvas.style.cssText = 'position:fixed;left:0;top:0;width:2px;height:1.2px;opacity:.02;pointer-events:none;z-index:-1'
      document.body.appendChild(canvas)
      const stream = canvas.captureStream(30)
      const track = stream.getVideoTracks()[0] as (MediaStreamTrack & { requestFrame?: () => void }) | undefined
      const recorder = new MediaRecorder(stream, mime ? { mimeType: mime, videoBitsPerSecond: 4_000_000 } : undefined)
      const chunks: Blob[] = []
      recorder.ondataavailable = (e) => e.data.size && chunks.push(e.data)
      const total = plan.scenes.reduce((s, x) => s + x.seconds, 0)
      const finished = new Promise<Blob>((resolve) => { recorder.onstop = () => resolve(new Blob(chunks, { type: 'video/webm' })) })
      frame(ctx, plan.scenes, images, 0, rtl)
      recorder.start(500)
      track?.requestFrame?.()
      const start = performance.now()
      await new Promise<void>((resolve) => {
        const timer = setInterval(() => {
          const elapsed = (performance.now() - start) / 1000
          if (elapsed >= total || stop.current) { clearInterval(timer); resolve(); return }
          frame(ctx, plan.scenes, images, elapsed, rtl)
          track?.requestFrame?.()
          setDone(Math.round((elapsed / total) * 100))
        }, 1000 / 30)
      })
      frame(ctx, plan.scenes, images, Math.max(0, total - 0.05), rtl)
      track?.requestFrame?.()
      recorder.requestData()
      recorder.stop()
      const blob = await finished
      canvas.remove()
      // A recording of a few hundred bytes means the browser produced no frames - never save that as a video.
      if (blob.size < 20_000) throw new Error(t('kits.video.empty'))
      setVideo({ blob, url: URL.createObjectURL(blob) })
      setDone(100)
    } catch (e) {
      document.querySelectorAll('body > canvas[style*="z-index:-1"]').forEach((c) => c.remove())
      setError(e instanceof Error ? e.message : errorMessage(e))
    } finally {
      setBusy(null)
    }
  }

  const save = async () => {
    if (!video || !plan) return
    setBusy('save')
    try {
      const body = new FormData()
      body.append('file', new File([video.blob], `${plan.title}.webm`, { type: 'video/webm' }))
      body.append('category', 'media')
      body.append('name', plan.title)
      const res = await api.post<{ data: KitFile }>(`/admin/kits/${kit.id}/files`, body)
      if (onSaved) {
        // Opened from a slide: the video joins the media library and goes straight onto the slide.
        const asset = (await api.post<{ data: Asset }>(`/admin/kits/${kit.id}/assets/from-file`, { file_id: res.data.data.id })).data.data
        onSaved(asset)
        return
      }
      navigate(`/admin/kits/${kit.id}/files/${res.data.data.id}`)
    } catch (e) {
      setError(errorMessage(e))
      setBusy(null)
    }
  }

  const imagesReady = plan?.scenes.every((s) => s.asset) ?? false
  const total = plan?.scenes.reduce((s, x) => s + x.seconds, 0) ?? 0

  return (
    <Modal open onClose={busy ? () => undefined : onClose} wide title={t('kits.video.title')}>
      <div className="space-y-5">
        {!plan ? (
          <>
            <p className="text-sm text-slate-500">{t('kits.video.intro')}</p>
            <Field label={t('kits.gen.topic')}><input className="input" dir="auto" value={form.topic} onChange={(e) => setForm({ ...form, topic: e.target.value })} /></Field>
            <div className="grid gap-4 sm:grid-cols-4">
              <Field label={t('kits.gen.audience')} className="sm:col-span-2"><input className="input" dir="auto" value={form.audience} onChange={(e) => setForm({ ...form, audience: e.target.value })} /></Field>
              <Field label={t('kits.video.scenes', { count: form.scenes })}><input type="range" min={3} max={8} value={form.scenes} onChange={(e) => setForm({ ...form, scenes: Number(e.target.value) })} className="w-full accent-gold-600" /></Field>
              <Field label={t('kits.video.seconds', { count: form.seconds })}><input type="range" min={20} max={120} step={5} value={form.seconds} onChange={(e) => setForm({ ...form, seconds: Number(e.target.value) })} className="w-full accent-gold-600" /></Field>
            </div>
            <div className="flex gap-1" role="radiogroup">{(['ar', 'en'] as const).map((l) => <button key={l} type="button" role="radio" aria-checked={form.language === l} onClick={() => setForm({ ...form, language: l })} className={clsx('rounded-xl border px-4 py-2 text-sm font-semibold', form.language === l ? 'border-navy-900 bg-navy-900 text-white' : 'border-navy-100 bg-white text-slate-600')}>{l === 'ar' ? 'العربية' : 'English'}</button>)}</div>
            <div className="flex justify-end"><Button variant="gold" loading={busy === 'plan'} disabled={form.topic.trim().length < 3} icon={<Clapperboard className="size-4" />} onClick={makePlan}>{t('kits.video.plan')}</Button></div>
          </>
        ) : (
          <>
            {!plan.ai_available && <p className="rounded-xl bg-amber-50 px-3 py-2 text-xs text-amber-800">{t('kits.gen.aiOff')}</p>}
            <div className="max-h-[42vh] space-y-3 overflow-y-auto pe-1">
              {plan.scenes.map((s, i) => (
                <div key={i} className="grid gap-3 rounded-2xl border border-navy-100 bg-white p-3 sm:grid-cols-[9rem_1fr]">
                  <div className="relative aspect-video overflow-hidden rounded-lg bg-gradient-to-br from-navy-900 to-navy-700">
                    {s.asset ? <img src={s.asset.url} alt="" className="size-full object-cover" /> : <div className="grid size-full place-items-center text-white/40"><ImagePlus className="size-6" /></div>}
                    <span className="absolute start-1 top-1 rounded-full bg-black/60 px-1.5 text-[10px] font-bold text-white">{i + 1}</span>
                  </div>
                  <div className="space-y-1.5">
                    <div className="flex gap-2"><input className="input !py-1.5 text-sm font-semibold" dir="auto" value={s.title} onChange={(e) => patchScene(i, { title: e.target.value })} aria-label={t('kits.video.sceneTitle')} />
                      <input type="number" min={3} max={30} className="input !w-20 !py-1.5 text-center text-sm" value={s.seconds} onChange={(e) => patchScene(i, { seconds: Math.max(3, Math.min(30, Number(e.target.value) || 5)) })} aria-label={t('kits.video.secondsShort')} /></div>
                    <textarea rows={2} className="input !py-1.5 text-sm" dir="auto" value={s.narration} onChange={(e) => patchScene(i, { narration: e.target.value })} aria-label={t('kits.video.narration')} />
                    <input className="input !py-1.5 text-xs" dir="ltr" value={s.image_prompt} onChange={(e) => patchScene(i, { image_prompt: e.target.value, asset: null })} aria-label={t('kits.video.imagePrompt')} />
                  </div>
                </div>
              ))}
            </div>
            {busy && busy !== 'plan' && busy !== 'save' && (
              <div><Progress value={busy === 'images' ? (done / plan.scenes.length) * 100 : done} /><p className="mt-1 text-xs text-slate-500">{busy === 'images' ? t('kits.video.drawing', { done, total: plan.scenes.length }) : t('kits.video.rendering', { seconds: total })}</p></div>
            )}
            {video && <video src={video.url} controls className="mx-auto max-h-64 rounded-xl bg-black" />}
            {error && <div className="rounded-xl bg-red-50 p-3 text-sm text-danger">{error}</div>}
            <div className="flex flex-wrap items-center justify-between gap-2">
              <Button variant="ghost" disabled={!!busy} onClick={() => { setPlan(null); setVideo(null) }}>{t('kits.video.startOver')}</Button>
              <div className="flex flex-wrap gap-2">
                {busy && <Button variant="outline" icon={<Square className="size-4" />} onClick={() => { stop.current = true }}>{t('kits.common.stop')}</Button>}
                <Button variant="outline" loading={busy === 'images'} disabled={!!busy && busy !== 'images'} icon={<Sparkles className="size-4" />} onClick={drawImages}>{imagesReady ? t('kits.video.redraw') : t('kits.video.draw')}</Button>
                <Button variant="gold" loading={busy === 'render'} disabled={!!busy && busy !== 'render'} icon={busy === 'render' ? <Loader2 className="size-4" /> : <Film className="size-4" />} onClick={render}>{video ? t('kits.video.renderAgain') : t('kits.video.render', { seconds: total })}</Button>
                {video && <Button variant="primary" loading={busy === 'save'} icon={<Save className="size-4" />} onClick={save}>{t('kits.video.save')}</Button>}
              </div>
            </div>
            <p className="flex items-center gap-1.5 text-[11px] text-slate-400"><Play className="size-3" />{t('kits.video.note')}</p>
          </>
        )}
        {!plan && error && <div className="rounded-xl bg-red-50 p-3 text-sm text-danger">{error}</div>}
      </div>
    </Modal>
  )
}
