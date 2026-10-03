import clsx from 'clsx'
import { List, Map as MapIcon, RefreshCcw, Search, Star } from 'lucide-react'
import { useState } from 'react'
import { useTranslation } from 'react-i18next'
import { Button, Card, PageHeader } from '@/components/ui'
import { DataView } from '@/components/ui/DataView'
import { useGet } from '@/hooks/useApi'
import { api, errorMessage } from '@/lib/api'
import { useAuth } from '@/lib/auth'
import { fmt } from '@/lib/format'
import type { LaravelPage } from '@/lib/types'
import SchoolsMap, { type SchoolsMapData } from './schools/SchoolsMap'

type School = { id: string; code: string; name_ar: string; name_en: string; type: string; stage: string; region: string; employees_count: number; is_partner: boolean }

export default function Schools() {
  const { t, i18n } = useTranslation()
  const { can } = useAuth()
  const [tab, setTab] = useState<'map' | 'list'>('map')
  const [q, setQ] = useState('')
  const [region, setRegion] = useState('')
  const [syncing, setSyncing] = useState(false)
  const [note, setNote] = useState<{ ok: boolean; text: string } | null>(null)
  const list = useGet<LaravelPage<School>>('/admin/schools', { q: q || undefined, region: region || undefined, per_page: 50 }, { enabled: tab === 'list' })
  const map = useGet<{ data: SchoolsMapData }>('/admin/schools/map')
  const regions = ['doha', 'al_rayyan', 'al_wakrah', 'al_khor', 'al_shamal', 'umm_salal', 'al_daayen', 'al_shahaniya']
  const synced = map.data?.data.synced_at

  const sync = async () => {
    if (!window.confirm(t('schoolsMap.sync.confirm'))) return
    setSyncing(true); setNote(null)
    try {
      const { data } = await api.post('/admin/schools/sync')
      const r = data.data as { created: number; updated: number; from: 'ministry' | 'bundled' }
      setNote({ ok: true, text: `${t('schoolsMap.sync.done', { created: r.created, updated: r.updated })} ${t(r.from === 'ministry' ? 'schoolsMap.sync.fromMinistry' : 'schoolsMap.sync.fromBundled')}` })
      await Promise.all([map.refetch(), list.refetch()])
    } catch (e) { setNote({ ok: false, text: errorMessage(e) }) } finally { setSyncing(false) }
  }

  return (
    <>
      <PageHeader
        title={t('schoolsMap.title')}
        subtitle={t('schoolsMap.subtitle')}
        actions={
          <div className="flex flex-wrap items-center gap-3">
            <div role="tablist" className="inline-flex rounded-xl border border-navy-100 bg-white p-1">
              {([['map', MapIcon], ['list', List]] as const).map(([id, Icon]) => (
                <button key={id} type="button" role="tab" aria-selected={tab === id} onClick={() => setTab(id)} className={clsx('inline-flex items-center gap-2 rounded-lg px-4 py-2 text-sm font-bold transition', tab === id ? 'bg-navy-900 text-white' : 'text-slate-500 hover:text-navy-900')}><Icon className="size-4" />{t(`schoolsMap.tabs.${id}`)}</button>
              ))}
            </div>
            {can('schools.manage') && <Button variant="gold" icon={<RefreshCcw className="size-4" />} loading={syncing} onClick={sync}>{t('schoolsMap.sync.button')}</Button>}
          </div>
        }
      />
      <p className="-mt-2 mb-4 text-xs text-slate-400">{synced ? t('schoolsMap.sync.last', { date: fmt.date(synced) }) : t('schoolsMap.sync.never')}</p>
      {note && <div role="status" className={clsx('mb-4 rounded-2xl p-3 text-sm font-semibold', note.ok ? 'bg-emerald-50 text-emerald-800' : 'bg-red-50 text-danger')}>{note.text}</div>}

      {tab === 'map' ? <SchoolsMap data={map.data?.data} loading={map.isLoading} /> : (
        <Card padded={false}>
          <div className="flex flex-wrap gap-3 border-b border-navy-100 p-4">
            <div className="relative min-w-60 flex-1"><Search className="absolute start-3 top-3 size-4 text-slate-400" /><input className="input ps-9" placeholder={t('common.search')} value={q} onChange={(e) => setQ(e.target.value)} /></div>
            <select className="input w-auto" value={region} onChange={(e) => setRegion(e.target.value)}><option value="">{t('admin.schools.region')}</option>{regions.map((r) => <option key={r} value={r}>{t(`regions.${r}`)}</option>)}</select>
          </div>
          <DataView id="admin.schools" rows={list.data?.data} total={list.data?.total} loading={list.isLoading} rowKey={(s) => s.id} columns={[
            { key: 'code', header: t('admin.schools.code'), role: 'media', cell: (s) => <span className="inline-block rounded-lg bg-navy-100/70 px-2 py-1 font-mono text-[11px] font-bold text-navy-800" dir="ltr">{s.code}</span> },
            { key: 'name', header: t('common.name'), role: 'title', cell: (s) => <span className="font-semibold text-navy-900">{i18n.language === 'ar' ? s.name_ar : s.name_en} {s.is_partner && <Star className="inline size-3.5 fill-gold-500 text-gold-500" />}</span> },
            { key: 'region', header: t('admin.schools.region'), role: 'subtitle', cell: (s) => t(`regions.${s.region}`) },
            { key: 'type', header: t('admin.schools.type'), cell: (s) => t(`schoolTypes.${s.type}`) },
            { key: 'stage', header: t('admin.schools.stage'), cell: (s) => t(`stages.${s.stage}`) },
            { key: 'employees', header: t('admin.schools.employees'), cell: (s) => <span className="font-bold">{fmt.number(s.employees_count)}</span> },
          ]} />
        </Card>
      )}
    </>
  )
}
