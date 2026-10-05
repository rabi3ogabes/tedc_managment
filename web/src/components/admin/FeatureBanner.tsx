import { ShieldAlert } from 'lucide-react'
import { useTranslation } from 'react-i18next'
import { Link } from 'react-router-dom'
import { useFeatures } from '@/hooks/useFeature'

/** On a live system, tools meant for demonstrations and support stay visible for as long as they are switched on. */
export default function FeatureBanner() {
  const { t } = useTranslation()
  const { data } = useFeatures()
  const active = data?.data.unsafe_active ?? []
  if (!active.length) return null
  return (
    <div role="status" className="flex flex-wrap items-center justify-center gap-x-3 gap-y-1 bg-amber-100 px-4 py-2 text-sm font-semibold text-amber-900">
      <ShieldAlert className="size-4 shrink-0" />
      <span>{t('features.banner', { tools: active.map((k) => t(`features.names.${k}`, { defaultValue: k })).join('، ') })}</span>
      <Link to="/admin/settings?tab=features" className="rounded-lg bg-amber-900 px-3 py-0.5 text-xs font-bold text-amber-50 hover:bg-amber-950">{t('features.review')}</Link>
    </div>
  )
}
