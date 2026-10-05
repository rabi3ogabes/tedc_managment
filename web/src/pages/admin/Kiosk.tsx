import { Check, LogOut, Search } from 'lucide-react'
import { useEffect, useRef, useState } from 'react'
import { useTranslation } from 'react-i18next'
import { Link, useParams } from 'react-router-dom'
import { Button, Spinner } from '@/components/ui'
import { useGet } from '@/hooks/useApi'
import { api, errorMessage } from '@/lib/api'

type Row = { registration_id: string; name: string | null; school: string | null; checked_in: boolean; checked_out: boolean }

/** Tablet kiosk: trainees find their name, sign with a finger and are checked in or out. Large touch targets, resets after each person. */
export default function Kiosk() {
  const { id } = useParams()
  const { t, i18n } = useTranslation()
  const { data, isLoading, refetch } = useGet<{ data: { session: { title_ar: string; title_en: string }; roster: Row[] } }>(`/admin/sessions/${id}/kiosk`, undefined, { staleTime: 0 })
  const [q, setQ] = useState('')
  const [who, setWho] = useState<Row | null>(null)
  const [done, setDone] = useState<string | null>(null)
  const [error, setError] = useState<string | null>(null)
  const canvas = useRef<HTMLCanvasElement>(null)
  const drawing = useRef(false)
  const [inked, setInked] = useState(false)

  useEffect(() => { if (done) { const tm = window.setTimeout(() => { setDone(null); setWho(null) }, 2500); return () => window.clearTimeout(tm) } }, [done])
  useEffect(() => {
    const c = canvas.current
    if (!c) return
    const ctx = c.getContext('2d')
    if (!ctx) return
    ctx.fillStyle = '#fff'; ctx.fillRect(0, 0, c.width, c.height); ctx.lineWidth = 3; ctx.lineCap = 'round'; ctx.strokeStyle = '#14213d'
    setInked(false)
  }, [who])

  const pos = (e: React.PointerEvent<HTMLCanvasElement>) => { const r = e.currentTarget.getBoundingClientRect(); return [(e.clientX - r.left) * (e.currentTarget.width / r.width), (e.clientY - r.top) * (e.currentTarget.height / r.height)] as const }
  const down = (e: React.PointerEvent<HTMLCanvasElement>) => { drawing.current = true; e.currentTarget.setPointerCapture(e.pointerId); const ctx = e.currentTarget.getContext('2d'); const [x, y] = pos(e); ctx?.beginPath(); ctx?.moveTo(x, y) }
  const move = (e: React.PointerEvent<HTMLCanvasElement>) => { if (!drawing.current) return; const ctx = e.currentTarget.getContext('2d'); const [x, y] = pos(e); ctx?.lineTo(x, y); ctx?.stroke(); setInked(true) }
  const clear = () => { const c = canvas.current; const ctx = c?.getContext('2d'); if (c && ctx) { ctx.fillStyle = '#fff'; ctx.fillRect(0, 0, c.width, c.height); setInked(false) } }

  const sign = async (direction: 'in' | 'out') => {
    if (!who || !canvas.current) return
    setError(null)
    try {
      await api.post(`/admin/sessions/${id}/signatures`, { registration_id: who.registration_id, direction, signature: canvas.current.toDataURL('image/png') })
      setDone(who.name ?? ''); await refetch()
    } catch (e) { setError(errorMessage(e)) }
  }

  if (isLoading || !data) return <Spinner className="min-h-screen" />
  const ar = i18n.language === 'ar'
  const list = data.data.roster.filter((r) => !q || (r.name ?? '').toLowerCase().includes(q.toLowerCase()))
  return (
    <div className="flex min-h-screen flex-col bg-navy-950 text-white">
      <header className="flex items-center justify-between gap-4 px-6 py-4">
        <div><div className="text-xs text-gold-300">{t('ops.kiosk.title')}</div><h1 className="text-2xl font-extrabold">{ar ? data.data.session.title_ar : data.data.session.title_en}</h1></div>
        <Link to="/admin/programs" className="flex items-center gap-2 rounded-xl border border-white/20 px-4 py-2 text-sm font-bold hover:bg-white/10"><LogOut className="size-4" />{t('ops.kiosk.exit')}</Link>
      </header>
      {done ? (
        <div className="grid flex-1 place-items-center"><div className="text-center"><span className="mx-auto grid size-28 animate-pulse place-items-center rounded-full bg-emerald-500"><Check className="size-14" /></span><p className="mt-6 text-3xl font-extrabold">{t('ops.kiosk.done', { name: done })}</p></div></div>
      ) : !who ? (
        <div className="mx-auto w-full max-w-3xl flex-1 px-6 pb-8">
          <div className="relative mb-4"><Search className="pointer-events-none absolute start-4 top-1/2 size-6 -translate-y-1/2 text-slate-400" /><input autoFocus className="w-full rounded-2xl border-0 bg-white py-5 ps-14 pe-4 text-xl text-navy-900 outline-none" placeholder={t('ops.kiosk.search')} value={q} onChange={(e) => setQ(e.target.value)} /></div>
          {list.length === 0 ? <p className="py-12 text-center text-slate-400">{t('ops.kiosk.empty')}</p> : (
            <ul className="grid gap-3 sm:grid-cols-2">{list.map((r) => (
              <li key={r.registration_id}><button type="button" onClick={() => { setWho(r); setError(null) }} className="flex min-h-20 w-full items-center justify-between gap-3 rounded-2xl bg-white/10 px-5 py-4 text-start text-xl font-bold hover:bg-white/20"><span className="min-w-0"><span className="block truncate">{r.name}</span><span className="block truncate text-sm font-normal text-slate-300">{r.school}</span></span>{r.checked_in && !r.checked_out && <span className="rounded-full bg-emerald-500 px-3 py-1 text-xs">{t('ops.kiosk.already')}</span>}</button></li>
            ))}</ul>
          )}
        </div>
      ) : (
        <div className="mx-auto w-full max-w-3xl flex-1 px-6 pb-8">
          <h2 className="mb-1 text-2xl font-extrabold">{who.name}</h2><p className="mb-3 text-slate-300">{t('ops.kiosk.sign')}</p>
          <canvas ref={canvas} width={900} height={360} onPointerDown={down} onPointerMove={move} onPointerUp={() => { drawing.current = false }} className="w-full touch-none rounded-2xl bg-white" aria-label={t('ops.kiosk.sign')} />
          {error && <p className="mt-3 rounded-xl bg-red-500/20 p-3 text-red-100">{error}</p>}
          <div className="mt-4 flex flex-wrap gap-3">
            <Button variant="gold" className="min-h-14 flex-1 text-lg" disabled={!inked} onClick={() => void sign('in')}>{t('ops.kiosk.in')}</Button>
            <Button variant="outline" className="min-h-14 flex-1 text-lg !border-white/30 !text-white" disabled={!inked || !who.checked_in} onClick={() => void sign('out')}>{t('ops.kiosk.out')}</Button>
            <Button variant="ghost" className="min-h-14 !text-white" onClick={clear}>{t('ops.kiosk.clear')}</Button>
            <Button variant="ghost" className="min-h-14 !text-white" onClick={() => setWho(null)}>{t('ops.kiosk.back')}</Button>
          </div>
        </div>
      )}
    </div>
  )
}
