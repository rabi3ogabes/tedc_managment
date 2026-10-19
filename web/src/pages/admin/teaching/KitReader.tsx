import clsx from 'clsx'
import { ArrowRight, BookOpenCheck, FileText, FileVideo, ImageIcon, MonitorPlay, Presentation, ShieldCheck } from 'lucide-react'
import { useEffect, useState } from 'react'
import { useTranslation } from 'react-i18next'
import { Link, useNavigate, useParams, useSearchParams } from 'react-router-dom'
import { Badge, Button, Card, ErrorState, Spinner } from '@/components/ui'
import { useGet } from '@/hooks/useApi'
import { api } from '@/lib/api'
import { AcknowledgeGate, OwnershipNotice } from './Ownership'
import { Shield } from './shield'
import { FileViewer } from './viewers'
import type { FileView, KitFileRow, KitInfo } from './api'

const icon = (f: KitFileRow) => (f.kind === 'presentation' ? Presentation : f.kind === 'video' ? FileVideo : f.kind === 'image' ? ImageIcon : f.category === 'assessment' ? BookOpenCheck : FileText)

/**
 * The trainer's reader for a training kit. Files open here and nowhere else — drawn from the API with the trainer's name across
 * every page, no download, no print, no copy. A presentation or a PDF can be presented from the same screen.
 */
export default function KitReader() {
  const { t, i18n } = useTranslation()
  const { kitId = '' } = useParams()
  const [params, setParams] = useSearchParams()
  const nav = useNavigate()
  const program = params.get('program') ?? ''
  const fileId = params.get('file') ?? ''
  const info = useGet<{ data: KitInfo }>(`/me/teaching/kits/${kitId}`, program ? { program } : undefined, { staleTime: 0, gcTime: 0 })
  const kit = info.data?.data
  const [accepted, setAccepted] = useState(false)
  const [busy, setBusy] = useState(false)
  const files = kit?.files ?? []
  const current = files.find((f) => f.id === fileId) ?? files[0]
  const view = useGet<{ data: FileView }>(current && (accepted || kit?.acknowledged || !kit?.protection.require_acknowledgement) ? `/me/teaching/kits/${kitId}/files/${current.id}` : null, program ? { program } : undefined, { staleTime: 0, gcTime: 0 })
  const en = i18n.language === 'en'

  useEffect(() => { if (current && current.id !== fileId) setParams((p) => { p.set('file', current.id); return p }, { replace: true }) }, [current, fileId, setParams])

  if (info.isLoading) return <Spinner />
  if (info.error || !kit) return <ErrorState error={info.error} onRetry={info.refetch} home="/admin/teaching" />
  const gate = kit.protection.require_acknowledgement && !kit.acknowledged && !accepted
  const title = en && kit.title_en ? kit.title_en : kit.title_ar
  const groups = ['presentation', 'trainer_guide', 'handout', 'activity', 'assessment', 'media', 'other'].map((c) => ({ c, items: files.filter((f) => f.category === c) })).filter((g) => g.items.length)
  const present = (f: KitFileRow) => nav(`/admin/teaching/present/${kit.id}/${f.id}${program ? `?program=${program}` : ''}`)
  const accept = async () => { setBusy(true); try { await api.post(`/me/teaching/kits/${kit.id}/acknowledge`); setAccepted(true) } finally { setBusy(false) } }

  return (
    <div className="mx-auto max-w-[1400px]">
      {gate && <AcknowledgeGate title={title} busy={busy} onAccept={accept} onLeave={() => nav('/admin/teaching')} />}
      <div className="mb-4 flex flex-wrap items-center gap-3">
        <Link to="/admin/teaching" className="inline-flex items-center gap-1 text-sm font-semibold text-navy-700 hover:text-gold-700"><ArrowRight className="size-4 rtl:rotate-0 ltr:rotate-180" />{t('teach.back')}</Link>
        <Badge color="gold" className="font-mono">{kit.code}</Badge>
        <Badge color="gray">v{kit.version}</Badge>
        {kit.hours > 0 && <span className="text-xs text-slate-500">{kit.hours} {t('teach.hours')}</span>}
        <span className="ms-auto inline-flex items-center gap-1.5 text-xs text-slate-500"><ShieldCheck className="size-4 text-emerald-600" />{t('teach.protected')}</span>
      </div>
      <h1 className="font-display text-2xl font-bold text-navy-900">{title}</h1>
      <OwnershipNotice className="mt-3" />

      <div className="mt-5 grid gap-5 lg:grid-cols-[280px_1fr]">
        <aside className="space-y-4">
          {groups.map((g) => (
            <Card key={g.c} className="!p-3">
              <div className="mb-2 px-1 text-xs font-bold uppercase tracking-wide text-slate-500">{t(`teach.cat.${g.c}`)}</div>
              <ul className="space-y-1">
                {g.items.map((f) => {
                  const Icon = icon(f)
                  return (
                    <li key={f.id}>
                      <button type="button" onClick={() => setParams((p) => { p.set('file', f.id); return p }, { replace: true })} className={clsx('flex w-full items-center gap-2.5 rounded-xl px-2.5 py-2 text-start text-sm transition', current?.id === f.id ? 'bg-navy-900 text-white' : 'text-navy-900 hover:bg-ivory')}>
                        <Icon className={clsx('size-4 shrink-0', current?.id === f.id ? 'text-gold-300' : 'text-gold-600')} />
                        <span className="min-w-0 flex-1 truncate" dir="auto">{f.name}</span>
                        {f.presentable && <MonitorPlay className="size-3.5 shrink-0 opacity-60" />}
                      </button>
                    </li>
                  )
                })}
              </ul>
            </Card>
          ))}
          {!files.length && <Card><p className="text-sm text-slate-500">{t('teach.noFiles')}</p></Card>}
        </aside>

        <section className="min-w-0">
          {current && (
            <div className="mb-3 flex flex-wrap items-center gap-2">
              <h2 className="min-w-0 flex-1 truncate text-lg font-bold text-navy-900" dir="auto">{current.name}</h2>
              {current.presentable && <Button variant="gold" icon={<MonitorPlay className="size-4" />} onClick={() => present(current)}>{t('teach.presentBtn')}</Button>}
            </div>
          )}
          <Shield settings={kit.protection} viewer={kit.viewer} surface="reader" kitId={kit.id} fileId={current?.id} programId={program || kit.program_id} className="min-h-[60vh] overflow-hidden rounded-3xl bg-gradient-to-b from-navy-50/50 to-white p-4 ring-1 ring-navy-100 sm:p-6">
            {gate ? <div className="min-h-[50vh]" /> : view.isLoading ? <Spinner /> : view.error || !view.data ? <ErrorState error={view.error} onRetry={view.refetch} home="/admin/teaching" /> : <FileViewer view={view.data.data} />}
          </Shield>
        </section>
      </div>
    </div>
  )
}
