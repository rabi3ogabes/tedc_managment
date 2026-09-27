import { Search, Star } from 'lucide-react'
import { useState } from 'react'
import { useTranslation } from 'react-i18next'
import { Card, PageHeader } from '@/components/ui'
import { DataView } from '@/components/ui/DataView'
import { useGet } from '@/hooks/useApi'
import { fmt } from '@/lib/format'
import type { LaravelPage } from '@/lib/types'

type School = { id: string; code: string; name_ar: string; name_en: string; type: string; stage: string; region: string; employees_count: number; is_partner: boolean }

export default function Schools() {
  const { t, i18n } = useTranslation()
  const [q, setQ] = useState('')
  const [region, setRegion] = useState('')
  const { data, isLoading } = useGet<LaravelPage<School>>('/admin/schools', { q: q || undefined, region: region || undefined, per_page: 50 })
  const regions = ['doha', 'al_rayyan', 'al_wakrah', 'al_khor', 'al_shamal', 'umm_salal', 'al_daayen', 'al_shahaniya']

  return (
    <>
      <PageHeader title={t('admin.schools.title')} />
      <Card padded={false}>
        <div className="flex flex-wrap gap-3 border-b border-navy-100 p-4">
          <div className="relative min-w-60 flex-1"><Search className="absolute start-3 top-3 size-4 text-slate-400" /><input className="input ps-9" placeholder={t('common.search')} value={q} onChange={(e) => setQ(e.target.value)} /></div>
          <select className="input w-auto" value={region} onChange={(e) => setRegion(e.target.value)}><option value="">{t('admin.schools.region')}</option>{regions.map((r) => <option key={r} value={r}>{t(`regions.${r}`)}</option>)}</select>
        </div>
        <DataView id="admin.schools" rows={data?.data} total={data?.total} loading={isLoading} rowKey={(s) => s.id} columns={[
          { key: 'code', header: t('admin.schools.code'), role: 'media', cell: (s) => <span className="inline-block rounded-lg bg-navy-100/70 px-2 py-1 font-mono text-[11px] font-bold text-navy-800" dir="ltr">{s.code}</span> },
          { key: 'name', header: t('common.name'), role: 'title', cell: (s) => <span className="font-semibold text-navy-900">{i18n.language === 'ar' ? s.name_ar : s.name_en} {s.is_partner && <Star className="inline size-3.5 fill-gold-500 text-gold-500" />}</span> },
          { key: 'region', header: t('admin.schools.region'), role: 'subtitle', cell: (s) => t(`regions.${s.region}`) },
          { key: 'type', header: t('admin.schools.type'), cell: (s) => t(`schoolTypes.${s.type}`) },
          { key: 'stage', header: t('admin.schools.stage'), cell: (s) => t(`stages.${s.stage}`) },
          { key: 'employees', header: t('admin.schools.employees'), cell: (s) => <span className="font-bold">{fmt.number(s.employees_count)}</span> },
        ]} />
      </Card>
    </>
  )
}
