import { Search, Star } from 'lucide-react'
import { useState } from 'react'
import { useTranslation } from 'react-i18next'
import { Card, Empty, PageHeader, Spinner, Table, Td } from '@/components/ui'
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
        {isLoading ? <Spinner /> : !data?.data.length ? <Empty /> : (
          <Table head={[t('admin.schools.code'), t('common.name'), t('admin.schools.type'), t('admin.schools.stage'), t('admin.schools.region'), t('admin.schools.employees')]}>
            {data.data.map((s) => (
              <tr key={s.id}>
                <Td><span className="font-mono text-xs" dir="ltr">{s.code}</span></Td>
                <Td className="font-semibold text-navy-900">{i18n.language === 'ar' ? s.name_ar : s.name_en} {s.is_partner && <Star className="inline size-3.5 fill-gold-500 text-gold-500" />}</Td>
                <Td>{t(`schoolTypes.${s.type}`)}</Td>
                <Td>{t(`stages.${s.stage}`)}</Td>
                <Td>{t(`regions.${s.region}`)}</Td>
                <Td>{fmt.number(s.employees_count)}</Td>
              </tr>
            ))}
          </Table>
        )}
      </Card>
    </>
  )
}
