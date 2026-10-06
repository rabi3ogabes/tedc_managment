/* eslint-disable @typescript-eslint/no-explicit-any */
import { Plus, Trash2 } from 'lucide-react'
import { useEffect, useState } from 'react'
import { useTranslation } from 'react-i18next'
import { Button, Card, Spinner } from '@/components/ui'
import { useGet } from '@/hooks/useApi'
import { api, errorMessage } from '@/lib/api'
import { toast } from '@/lib/toast'

const cls = 'w-full rounded-xl border border-navy-100 px-3 py-2 text-sm'

/** The numbers on the public homepage: built-in sources are computed, or the centre enters its own. */
export default function StatsManager() {
  const { t, i18n } = useTranslation()
  const res = useGet<{ data: any[]; meta: { sources: Record<string, { ar: string; en: string }>; query_keys: string[] } }>('/admin/public-stats', undefined, { staleTime: 0 })
  const [rows, setRows] = useState<any[] | null>(null)
  const [busy, setBusy] = useState(false)
  useEffect(() => { if (res.data) setRows(res.data.data) }, [res.data])
  if (!rows || !res.data) return <Spinner />
  const meta = res.data.meta
  const set = (i: number, patch: any) => setRows(rows.map((r, j) => (j === i ? { ...r, ...patch } : r)))
  const save = async () => {
    setBusy(true)
    try { const { data } = await api.put('/admin/public-stats', { stats: rows.map(({ computed: _c, id: _i, created_at: _a, updated_at: _u, sort_order: _s, ...r }) => r) }); setRows(data.data); toast(String(t('common.saved'))) } catch (e) { toast(errorMessage(e), 'error') } finally { setBusy(false) }
  }
  return (
    <Card>
      <p className="mb-4 text-sm text-slate-500">{t('comm.home.statsHint')}</p>
      <div className="space-y-3">
        {rows.map((r, i) => (
          <div key={i} className="grid items-end gap-2 rounded-xl border border-navy-100 p-3 md:grid-cols-[1fr_1fr_1.2fr_1fr_auto_auto]">
            <label className="text-xs font-semibold text-slate-500">{t('comm.home.statLabelAr')}<input className={cls} value={r.label_ar} onChange={(e) => set(i, { label_ar: e.target.value })} /></label>
            <label className="text-xs font-semibold text-slate-500">{t('comm.home.statLabelEn')}<input className={cls} dir="ltr" value={r.label_en} onChange={(e) => set(i, { label_en: e.target.value })} /></label>
            <label className="text-xs font-semibold text-slate-500">{t('comm.home.source')}
              <select className={cls} value={r.source} onChange={(e) => set(i, { source: e.target.value, value: e.target.value === 'custom_query_key' ? meta.query_keys[0] : null })}>{Object.entries(meta.sources).map(([k, v]) => <option key={k} value={k}>{v[i18n.language === 'ar' ? 'ar' : 'en']}</option>)}</select>
            </label>
            {r.source === 'custom_value' ? <label className="text-xs font-semibold text-slate-500">{t('comm.home.value')}<input className={cls} value={r.value ?? ''} onChange={(e) => set(i, { value: e.target.value })} /></label>
              : r.source === 'custom_query_key' ? <label className="text-xs font-semibold text-slate-500">{t('comm.home.value')}<select className={cls} value={r.value ?? ''} onChange={(e) => set(i, { value: e.target.value })}>{meta.query_keys.map((k) => <option key={k}>{k}</option>)}</select></label>
              : <div className="text-xs text-slate-400">{t('comm.home.computed')}: <b className="text-navy-900">{String(r.computed ?? '')}</b></div>}
            <label className="flex items-center gap-1.5 pb-2 text-xs"><input type="checkbox" className="accent-gold-600" checked={r.is_visible} onChange={(e) => set(i, { is_visible: e.target.checked })} />{t('comm.home.visible')}</label>
            <button className="pb-2 text-slate-400 hover:text-danger" onClick={() => setRows(rows.filter((_, j) => j !== i))} aria-label="remove"><Trash2 className="size-4" /></button>
          </div>
        ))}
      </div>
      <div className="mt-4 flex justify-between">
        <Button variant="outline" icon={<Plus className="size-4" />} onClick={() => setRows([...rows, { key: `stat_${Date.now().toString(36)}`, label_ar: '', label_en: '', source: 'custom_value', value: '', icon: null, is_visible: true }])}>{t('comm.home.addStat')}</Button>
        <Button variant="gold" loading={busy} onClick={save}>{t('common.save')}</Button>
      </div>
    </Card>
  )
}
