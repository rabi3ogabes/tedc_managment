import { Maximize2, MonitorUp } from 'lucide-react'
import { useEffect, useState } from 'react'
import { useTranslation } from 'react-i18next'
import { useParams, useSearchParams } from 'react-router-dom'
import { ErrorState, Spinner } from '@/components/ui'
import { useGet } from '@/hooks/useApi'
import type { FileView, KitInfo } from '../api'
import { Shield } from '../shield'
import { channelName, INITIAL_SHOW, type Msg, type Pointer, type Show } from './state'
import { usePresentSource } from './source'
import Stage from './Stage'

/** The audience window: the presenter's slide, full screen, with whatever the presenter draws — nothing else, and nothing to click. */
export default function Audience() {
  const { t } = useTranslation()
  const { kitId = '', fileId = '' } = useParams()
  const [params] = useSearchParams()
  const token = params.get('ch') ?? ''
  const program = params.get('program') ?? ''
  const info = useGet<{ data: KitInfo }>(`/me/teaching/kits/${kitId}`, program ? { program } : undefined, { staleTime: 0, gcTime: 0 })
  const view = useGet<{ data: FileView }>(`/me/teaching/kits/${kitId}/files/${fileId}`, program ? { program } : undefined, { staleTime: 0, gcTime: 0 })
  const source = usePresentSource(view.data?.data)
  const [show, setShow] = useState<Show>(INITIAL_SHOW)
  const [pointer, setPointer] = useState<Pointer>(null)
  const [linked, setLinked] = useState(false)
  const [size, setSize] = useState({ w: window.innerWidth, h: window.innerHeight })

  useEffect(() => {
    const ch = new BroadcastChannel(channelName(kitId, fileId, token))
    ch.onmessage = (e: MessageEvent<Msg>) => {
      if (e.data.t === 'state') { setShow(e.data.s); setLinked(true) }
      else if (e.data.t === 'pointer') setPointer(e.data.p)
      else if (e.data.t === 'bye') setLinked(false)
    }
    ch.postMessage({ t: 'hello' } satisfies Msg)
    const onResize = () => setSize({ w: window.innerWidth, h: window.innerHeight })
    window.addEventListener('resize', onResize)
    return () => { ch.close(); window.removeEventListener('resize', onResize) }
  }, [kitId, fileId, token])

  useEffect(() => { document.title = info.data?.data ? (info.data.data.title_ar) : '…' }, [info.data])

  if (info.isLoading || view.isLoading) return <div className="grid h-screen place-items-center bg-black"><Spinner /></div>
  if (info.error || !info.data) return <ErrorState error={info.error} onRetry={info.refetch} />
  const kit = info.data.data
  return (
    <Shield settings={kit.protection} viewer={kit.viewer} surface="audience" kitId={kit.id} fileId={fileId} programId={program || kit.program_id} sparse className="fixed inset-0 grid place-items-center bg-black">
      <Stage source={source} show={show} pointer={pointer} width={size.w} height={size.h} />
      {!linked && (
        <div className="absolute inset-0 z-30 grid place-items-center bg-navy-950 text-center text-white">
          <div className="max-w-sm px-6"><MonitorUp className="mx-auto size-12 text-gold-300" /><h1 className="mt-4 text-xl font-bold">{t('teach.audience.waiting')}</h1><p className="mt-2 text-sm text-white/60">{t('teach.audience.waitingHint')}</p></div>
        </div>
      )}
      <button type="button" onClick={() => void document.documentElement.requestFullscreen?.()} className="absolute bottom-3 end-3 z-30 grid size-9 place-items-center rounded-full bg-white/10 text-white opacity-0 transition hover:opacity-100 focus:opacity-100" aria-label={t('teach.present.fullscreen')}><Maximize2 className="size-4" /></button>
    </Shield>
  )
}
