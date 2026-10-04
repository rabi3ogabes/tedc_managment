import { useEffect, useRef, useState } from 'react'
import { useParams } from 'react-router-dom'
import LobbyView, { STAGE, type LobbyData } from '@/components/lobby/LobbyView'
import { api } from '@/lib/api'

/** The portrait screen in the lobby (1080 × 1920): reached by its secret address, no sign-in. It fits itself to any window and refreshes by itself. */
export default function LobbyScreen() {
  const { token = '' } = useParams()
  const [data, setData] = useState<LobbyData | null>(null)
  const [error, setError] = useState(false)
  const box = useRef<HTMLDivElement>(null)
  const [scale, setScale] = useState(1)

  useEffect(() => {
    let alive = true
    const load = async () => {
      try {
        const res = await api.get<{ data: LobbyData }>(`/public/lobby-screen/${token}`)
        if (alive) { setData(res.data.data); setError(false) }
      } catch (e) { if (alive && (e as { response?: { status?: number } }).response?.status === 404) setError(true) }
    }
    void load()
    const id = window.setInterval(load, 60_000)
    return () => { alive = false; window.clearInterval(id) }
  }, [token])

  useEffect(() => {
    const fit = () => setScale(Math.min(window.innerWidth / STAGE.w, window.innerHeight / STAGE.h))
    fit()
    window.addEventListener('resize', fit)
    return () => window.removeEventListener('resize', fit)
  }, [])
  // Keep the display awake and out of the way: no cursor, no scroll.
  useEffect(() => { document.documentElement.style.cursor = 'none'; document.body.style.overflow = 'hidden'; return () => { document.documentElement.style.cursor = ''; document.body.style.overflow = '' } }, [])

  if (error) return <div className="grid h-screen place-items-center bg-black text-xl text-white/70">This screen is switched off or its address was changed.</div>
  return (
    <div ref={box} dir="ltr" className="fixed inset-0 overflow-hidden bg-black">
      {data && (
        <div style={{ position: 'absolute', left: '50%', top: '50%', width: STAGE.w, height: STAGE.h, transform: `translate(-50%, -50%) scale(${scale})`, transformOrigin: 'center center' }}>
          <LobbyView data={data} />
        </div>
      )}
    </div>
  )
}
