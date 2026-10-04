import { useQueryClient } from '@tanstack/react-query'
import { Sparkles } from 'lucide-react'
import { useState } from 'react'
import { useTranslation } from 'react-i18next'
import { Button } from '@/components/ui'
import { useGet } from '@/hooks/useApi'
import { api, errorMessage } from '@/lib/api'
import { DeliveryBadge } from './common'
import type { KitDelivery } from './types'

type Sample = { n: number; code: string; mode: KitDelivery; title: string; built: boolean; kit_id: string | null }

/** Offers the fifteen ready sample kits (five per kind of program) until they exist; builds them one request at a time. */
export default function SampleKits() {
  const { t } = useTranslation()
  const queryClient = useQueryClient()
  const { data, refetch } = useGet<{ data: Sample[] }>('/admin/kits/samples')
  const [at, setAt] = useState(0)
  const [busy, setBusy] = useState(false)
  const [note, setNote] = useState<{ ok: boolean; text: string } | null>(null)
  const samples = data?.data ?? []
  const todo = samples.filter((s) => !s.built)
  if (samples.length === 0 || (todo.length === 0 && !note)) return null

  const build = async () => {
    setBusy(true); setNote(null)
    try {
      // One kit per request keeps every call short, even on a slow connection to the database.
      for (const [i, s] of todo.entries()) { setAt(i + 1); await api.post('/admin/kits/samples', { n: s.n }, { timeout: 120_000 }) }
      await refetch(); await queryClient.invalidateQueries()
      setNote({ ok: true, text: t('kits.delivery.samples.done') })
    } catch (e) { setNote({ ok: false, text: `${errorMessage(e)} — ${t('kits.delivery.samples.retry')}` }); await refetch() } finally { setBusy(false); setAt(0) }
  }

  return (
    <section className="mb-6 overflow-hidden rounded-3xl border border-gold-300 bg-gradient-to-l from-gold-100/60 to-white p-5">
      <div className="flex flex-wrap items-center gap-4">
        <span className="grid size-12 place-items-center rounded-2xl bg-gold-500 text-navy-950"><Sparkles className="size-6" /></span>
        <div className="min-w-0 flex-1 basis-72">
          <h2 className="font-extrabold text-navy-900">{t('kits.delivery.samples.title')}</h2>
          <p className="mt-0.5 text-sm text-slate-600">{t('kits.delivery.samples.text')}</p>
          <div className="mt-2 flex flex-wrap gap-2">{(['in_person', 'online', 'hybrid'] as KitDelivery[]).map((m) => <span key={m} className="inline-flex items-center gap-1.5"><DeliveryBadge delivery={m} /><b className="text-xs tabular-nums text-navy-800">{samples.filter((s) => s.mode === m && s.built).length}/5</b></span>)}</div>
        </div>
        {todo.length > 0 && <Button variant="gold" icon={<Sparkles className="size-4" />} loading={busy} onClick={build}>{busy && at > 0 ? t('kits.delivery.samples.progress', { n: at, total: todo.length }) : t('kits.delivery.samples.build')}</Button>}
      </div>
      {note && <p role="status" className={note.ok ? 'mt-3 text-sm font-semibold text-emerald-700' : 'mt-3 text-sm font-semibold text-danger'}>{note.text}</p>}
    </section>
  )
}
