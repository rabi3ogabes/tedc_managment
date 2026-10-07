import clsx from 'clsx'
import { CalendarDays, Eye, EyeOff, Plus, Trash2 } from 'lucide-react'
import { useState } from 'react'
import { useTranslation } from 'react-i18next'
import { Badge, Button } from '@/components/ui'
import { useGet } from '@/hooks/useApi'
import { api, errorMessage } from '@/lib/api'
import { toast } from '@/lib/toast'
import type { Occasion, Theme } from '@/lib/theme'

type CatalogItem = { id: string; name_ar: string; name_en: string; patch: Occasion['patch'] }

const status = (o: Occasion): 'on' | 'soon' | 'past' | 'off' => {
  if (!o.enabled) return 'off'
  const today = new Date().toISOString().slice(0, 10)
  if (o.recurring) {
    const md = today.slice(5), from = o.starts_on.slice(5), to = o.ends_on.slice(5)
    const inside = from <= to ? md >= from && md <= to : md >= from || md <= to
    return inside ? 'on' : 'soon'
  }
  return today > o.ends_on ? 'past' : today >= o.starts_on ? 'on' : 'soon'
}

/** Occasion themes: scheduled looks laid over the theme by the server while they are in force (Ramadan, Eid, National Day, Teachers' Day…). */
export default function OccasionsPanel({ draft, onChange, trying, onTry }: { draft: Theme; onChange: (occasions: Occasion[]) => void; trying: string | null; onTry: (id: string | null) => void }) {
  const { t, i18n } = useTranslation()
  const lang = i18n.language === 'en' ? 'en' : 'ar'
  const catalog = useGet<{ data: CatalogItem[] }>('/admin/theme/occasions/catalog', undefined, { staleTime: 600_000 })
  const [year, setYear] = useState(new Date().getFullYear())
  const [busy, setBusy] = useState(false)
  const list = draft.occasions
  const set = (id: string, patch: Partial<Occasion>) => onChange(list.map((o) => (o.id === id ? { ...o, ...patch } : o)))

  const addStandard = async () => {
    setBusy(true)
    try {
      const { data } = await api.get('/admin/theme/occasions/standard', { params: { year } })
      const have = new Set(list.map((o) => o.id))
      const fresh = (data.data as Occasion[]).filter((o) => !have.has(o.id))
      if (fresh.length) onChange([...list, ...fresh])
      toast(String(fresh.length ? t('apa.added', { n: fresh.length }) : t('apa.nothingNew')))
    } catch (e) { toast(errorMessage(e), 'error') } finally { setBusy(false) }
  }
  const addCustom = () => {
    const today = new Date().toISOString().slice(0, 10)
    onChange([...list, { id: `custom_${Date.now().toString(36)}`, name_ar: 'مناسبة جديدة', name_en: 'New occasion', enabled: false, recurring: false, starts_on: today, ends_on: today, patch: {} }])
  }
  const takeLook = (id: string, catalogId: string) => {
    const c = catalog.data?.data.find((x) => x.id === catalogId)
    if (c) set(id, { patch: c.patch })
  }
  const active = list.find((o) => status(o) === 'on')

  return (
    <div className="space-y-5">
      <p className="text-sm text-slate-500">{t('apa.occHint')}</p>
      <div className={clsx('rounded-2xl p-4 text-sm font-semibold', active ? 'bg-emerald-50 text-emerald-800' : 'bg-ivory text-slate-600')}>
        {active ? <>{t('apa.occActive')}: <b>{lang === 'en' ? active.name_en : active.name_ar}</b></> : t('apa.occNone')}
      </div>
      <div className="flex flex-wrap items-end gap-3">
        <label className="block"><span className="mb-1 block text-xs font-semibold text-slate-500">{t('apa.year')}</span>
          <input type="number" className="input w-28" min={2024} max={2040} value={year} onChange={(e) => setYear(Number(e.target.value) || year)} /></label>
        <Button variant="gold" loading={busy} icon={<CalendarDays className="size-4" />} onClick={addStandard}>{t('apa.addStandard')}</Button>
        <Button variant="outline" icon={<Plus className="size-4" />} onClick={addCustom}>{t('apa.addCustom')}</Button>
      </div>
      <p className="text-xs text-slate-400">{t('apa.hijriNote')}</p>
      {trying && <div className="rounded-xl bg-amber-50 p-3 text-sm font-semibold text-amber-800" role="status">{t('apa.trying')} <button type="button" className="ms-2 underline" onClick={() => onTry(null)}>{t('apa.stopTry')}</button></div>}
      {list.length === 0 ? <p className="rounded-2xl bg-ivory p-4 text-sm text-slate-500">{t('apa.noOcc')}</p> : (
        <ul className="space-y-3">
          {list.map((o) => {
            const st = status(o)
            const c = o.patch.colors
            return (
              <li key={o.id} className="space-y-3 rounded-2xl border border-navy-100 bg-white p-4">
                <div className="flex flex-wrap items-center justify-between gap-2">
                  <div className="flex items-center gap-2">
                    <div className="flex -space-x-1.5 rtl:space-x-reverse">{c && [c.primary, c.accent, c.background].map((x, i) => <span key={i} className="size-6 rounded-full ring-2 ring-white" style={{ background: x }} />)}</div>
                    <b className="text-navy-900">{lang === 'en' ? o.name_en : o.name_ar}</b>
                    <Badge color={st === 'on' ? 'green' : st === 'soon' ? 'gold' : 'navy'}>{t(`apa.status.${st}`)}</Badge>
                  </div>
                  <div className="flex gap-2">
                    <Button size="sm" variant="outline" icon={trying === o.id ? <EyeOff className="size-4" /> : <Eye className="size-4" />} onClick={() => onTry(trying === o.id ? null : o.id)}>{trying === o.id ? t('apa.stopTry') : t('apa.tryIt')}</Button>
                    <Button size="sm" variant="ghost" icon={<Trash2 className="size-4" />} onClick={() => { if (trying === o.id) onTry(null); onChange(list.filter((x) => x.id !== o.id)) }} aria-label={String(t('apa.remove'))}>{t('apa.remove')}</Button>
                  </div>
                </div>
                <div className="grid gap-3 sm:grid-cols-2">
                  <label className="block"><span className="mb-1 block text-xs font-semibold text-slate-500">{t('apa.nameAr')}</span><input className="input" dir="rtl" value={o.name_ar} onChange={(e) => set(o.id, { name_ar: e.target.value })} /></label>
                  <label className="block"><span className="mb-1 block text-xs font-semibold text-slate-500">{t('apa.nameEn')}</span><input className="input" dir="ltr" value={o.name_en} onChange={(e) => set(o.id, { name_en: e.target.value })} /></label>
                  <label className="block"><span className="mb-1 block text-xs font-semibold text-slate-500">{t('apa.from')}</span><input type="date" className="input" value={o.starts_on} onChange={(e) => set(o.id, { starts_on: e.target.value, ends_on: o.ends_on < e.target.value ? e.target.value : o.ends_on })} /></label>
                  <label className="block"><span className="mb-1 block text-xs font-semibold text-slate-500">{t('apa.to')}</span><input type="date" className="input" min={o.starts_on} value={o.ends_on} onChange={(e) => set(o.id, { ends_on: e.target.value })} /></label>
                </div>
                <div className="flex flex-wrap items-center gap-4 text-sm font-semibold text-navy-800">
                  <label className="flex items-center gap-2"><input type="checkbox" checked={o.enabled} onChange={(e) => set(o.id, { enabled: e.target.checked })} />{t('apa.enabled')}</label>
                  <label className="flex items-center gap-2"><input type="checkbox" checked={o.recurring} onChange={(e) => set(o.id, { recurring: e.target.checked })} />{t('apa.recurring')}</label>
                  <label className="flex items-center gap-2">{t('apa.lookFrom')}
                    <select className="input w-auto" value="" onChange={(e) => e.target.value && takeLook(o.id, e.target.value)}>
                      <option value="">—</option>
                      {(catalog.data?.data ?? []).map((x) => <option key={x.id} value={x.id}>{t(`apa.presetNames.${x.id}`, { defaultValue: lang === 'en' ? x.name_en : x.name_ar })}</option>)}
                    </select>
                  </label>
                </div>
              </li>
            )
          })}
        </ul>
      )}
    </div>
  )
}
