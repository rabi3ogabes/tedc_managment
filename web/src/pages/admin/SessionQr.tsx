import { Maximize2, Users } from 'lucide-react'
import { QRCodeSVG } from 'qrcode.react'
import { useEffect, useState } from 'react'
import { useTranslation } from 'react-i18next'
import { useParams } from 'react-router-dom'
import { LogoMark } from '@/components/public/Logo'
import { Spinner } from '@/components/ui'
import { useGet } from '@/hooks/useApi'
import { fmt } from '@/lib/format'

type Qr = { payload: string; expires_at: string; rotation_seconds: number; session: { id: string; title: string; starts_at: string; ends_at: string } }

/** Trainer projection screen: a dynamic, auto-rotating attendance QR code. */
export default function SessionQr() {
  const { id } = useParams()
  const { t } = useTranslation()
  const [now, setNow] = useState(Date.now())
  const { data, refetch } = useGet<{ data: Qr }>(`/admin/sessions/${id}/qr`, undefined, { staleTime: 0 })
  const report = useGet<{ data: { expected: number; present: number } }>(`/admin/sessions/${id}/attendance`, undefined, { refetchInterval: 10_000, staleTime: 0 })
  const qr = data?.data

  useEffect(() => {
    const tick = window.setInterval(() => setNow(Date.now()), 250)
    return () => window.clearInterval(tick)
  }, [])

  const remaining = qr ? Math.max(0, new Date(qr.expires_at).getTime() - now) : 0
  useEffect(() => {
    if (qr && remaining <= 0) refetch()
  }, [qr, remaining, refetch])

  if (!qr) return <Spinner className="min-h-screen bg-navy-950" />
  const pct = (remaining / (qr.rotation_seconds * 1000)) * 100

  return (
    <div className="relative flex min-h-screen flex-col items-center justify-center overflow-hidden bg-navy-950 p-8 text-white">
      <div className="pattern-bg absolute inset-0 opacity-20" />
      <div className="absolute -top-40 start-1/2 size-[600px] -translate-x-1/2 rounded-full bg-gold-500/10 blur-3xl" />
      <button onClick={() => document.documentElement.requestFullscreen?.()} className="glass-dark absolute end-6 top-6 inline-flex items-center gap-2 rounded-xl px-3 py-2 text-sm"><Maximize2 className="size-4" />{t('admin.qr.fullscreen')}</button>
      <div className="relative flex items-center gap-3"><LogoMark className="size-12" /><div className="font-display text-xl font-bold">{t('brand.name')}</div></div>
      <h1 className="relative mt-8 text-center text-3xl font-bold sm:text-4xl">{qr.session.title}</h1>
      <p className="relative mt-2 text-white/60">{fmt.dateTime(qr.session.starts_at)} – {fmt.time(qr.session.ends_at)}</p>
      <div className="relative mt-10 rounded-[2rem] bg-white p-6 shadow-gold">
        <QRCodeSVG value={qr.payload} size={Math.min(420, window.innerWidth - 120)} level="M" fgColor="#0B1F3A" />
        <div className="mt-5 h-2 overflow-hidden rounded-full bg-navy-100"><div className="h-full rounded-full bg-gradient-to-l from-gold-400 to-gold-600 transition-[width] duration-200" style={{ width: `${pct}%` }} /></div>
      </div>
      <p className="relative mt-6 max-w-lg text-center text-white/70">{t('admin.qr.hint', { s: qr.rotation_seconds })}</p>
      {report.data && (
        <div className="glass-dark relative mt-6 flex items-center gap-3 rounded-2xl px-6 py-3">
          <Users className="size-5 text-gold-300" />
          <span className="font-display text-3xl font-bold text-gold-300">{report.data.data.present}</span>
          <span className="text-white/60">/ {report.data.data.expected} {t('admin.qr.present')}</span>
        </div>
      )}
    </div>
  )
}
