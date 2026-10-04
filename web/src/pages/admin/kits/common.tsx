import clsx from 'clsx'
import { Award, BookOpenCheck, Building2, FileArchive, FileAudio2, FileImage, FileText, FileType2, FileVideo, Layers, MonitorPlay, Presentation, ScrollText, type LucideIcon } from 'lucide-react'
import { useTranslation } from 'react-i18next'
import type { FileCategory, FileKind, KitDelivery, KitStatus } from './types'

export const statusTone: Record<KitStatus, { dot: string; chip: string; column: string }> = {
  draft: { dot: 'bg-slate-400', chip: 'bg-slate-100 text-slate-700 ring-slate-500/20', column: 'from-slate-400' },
  in_development: { dot: 'bg-sky-500', chip: 'bg-sky-50 text-sky-700 ring-sky-600/20', column: 'from-sky-500' },
  in_review: { dot: 'bg-amber-500', chip: 'bg-amber-50 text-amber-800 ring-amber-600/25', column: 'from-amber-500' },
  changes_requested: { dot: 'bg-red-500', chip: 'bg-red-50 text-red-700 ring-red-600/20', column: 'from-red-500' },
  approved: { dot: 'bg-emerald-500', chip: 'bg-emerald-50 text-emerald-700 ring-emerald-600/20', column: 'from-emerald-500' },
  published: { dot: 'bg-navy-700', chip: 'bg-navy-100 text-navy-800 ring-navy-600/20', column: 'from-navy-700' },
  archived: { dot: 'bg-slate-300', chip: 'bg-slate-100 text-slate-500 ring-slate-400/20', column: 'from-slate-300' },
}

export function KitStatusBadge({ status, className }: { status: KitStatus; className?: string }) {
  const { t } = useTranslation()
  return (
    <span className={clsx('inline-flex items-center gap-1.5 whitespace-nowrap rounded-full px-2.5 py-0.5 text-xs font-semibold ring-1 ring-inset', statusTone[status].chip, className)}>
      <span className={clsx('size-1.5 rounded-full', statusTone[status].dot)} />{t(`kits.status.${status}`)}
    </span>
  )
}

/** What kind of program a kit is for: in person ("normal"), online or hybrid. */
export const deliveryMeta: Record<KitDelivery, { icon: LucideIcon; chip: string; solid: string }> = {
  in_person: { icon: Building2, chip: 'bg-navy-100 text-navy-800 ring-navy-600/20', solid: 'bg-navy-900 text-gold-300' },
  online: { icon: MonitorPlay, chip: 'bg-sky-50 text-sky-700 ring-sky-600/20', solid: 'bg-sky-600 text-white' },
  hybrid: { icon: Layers, chip: 'bg-emerald-50 text-emerald-700 ring-emerald-600/20', solid: 'bg-emerald-600 text-white' },
}

/** Shown on every kit, so the kind of its program is always clear. */
export function DeliveryBadge({ delivery, className, onDark }: { delivery?: KitDelivery | null; className?: string; onDark?: boolean }) {
  const { t } = useTranslation()
  const d: KitDelivery = delivery && delivery in deliveryMeta ? delivery : 'in_person'
  const { icon: Icon, chip } = deliveryMeta[d]
  return (
    <span className={clsx('inline-flex items-center gap-1 whitespace-nowrap rounded-full px-2 py-0.5 text-[11px] font-bold ring-1 ring-inset', onDark ? 'bg-white/15 text-white ring-white/25' : chip, className)}>
      <Icon className="size-3.5" />{t(`kits.delivery.badge.${d}`)}
    </span>
  )
}

export const kindIcon: Record<FileKind, LucideIcon> = { presentation: Presentation, document: FileText, pdf: FileType2, image: FileImage, video: FileVideo, other: FileArchive }
export const kindTint: Record<FileKind, string> = {
  presentation: 'bg-orange-50 text-orange-600', document: 'bg-sky-50 text-sky-600', pdf: 'bg-red-50 text-red-600', image: 'bg-emerald-50 text-emerald-600', video: 'bg-violet-50 text-violet-600', other: 'bg-slate-100 text-slate-500',
}
export const categoryIcon: Record<FileCategory, LucideIcon> = { presentation: Presentation, trainer_guide: BookOpenCheck, handout: ScrollText, assessment: Award, activity: FileAudio2, media: FileVideo, other: FileArchive }

export const fmtSize = (n: number) => (n > 1_048_576 ? `${(n / 1_048_576).toFixed(1)} MB` : `${Math.max(1, Math.round(n / 1024))} KB`)
