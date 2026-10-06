/* eslint-disable @typescript-eslint/no-explicit-any */
import { useEffect, useState } from 'react'
import { useTranslation } from 'react-i18next'
import { useLang } from '@/components/dashboard/Widget'
import { Button, Card, PageHeader, Spinner } from '@/components/ui'
import { useGet } from '@/hooks/useApi'
import { api, errorMessage } from '@/lib/api'
import { toast } from '@/lib/toast'

/** Which widgets each role's dashboard offers. People then hide and reorder them within the preset. */
export default function DashboardPresets() {
  const { t } = useTranslation()
  const L = useLang()
  const res = useGet<{ data: any[]; meta: { widgets: any[] } }>('/admin/dashboard-presets', undefined, { staleTime: 0 })
  const [role, setRole] = useState<string>('')
  const [sel, setSel] = useState<Record<string, string[]>>({})
  const [busy, setBusy] = useState(false)
  useEffect(() => {
    if (res.data) { setSel(Object.fromEntries(res.data.data.map((r) => [r.role, r.widgets]))); setRole((cur) => cur || res.data!.data[0]?.role) }
  }, [res.data])
  if (!res.data) return <Spinner />
  const widgets = res.data.meta.widgets
  const cur = sel[role] ?? []
  const toggle = (k: string) => setSel({ ...sel, [role]: cur.includes(k) ? cur.filter((x) => x !== k) : [...cur, k] })
  const save = async () => { setBusy(true); try { await api.put(`/admin/dashboard-presets/${role}`, { widgets: cur }); toast(String(t('rep.dash.presetSaved'))) } catch (e) { toast(errorMessage(e), 'error') } finally { setBusy(false) } }

  return (
    <>
      <PageHeader title={t('rep.dash.presets')} subtitle={t('rep.dash.presetHint')} actions={<Button variant="gold" loading={busy} onClick={save}>{t('common.save')}</Button>} />
      <div className="grid gap-5 lg:grid-cols-[18rem_1fr]">
        <Card padded={false}><ul className="divide-y divide-navy-50">{res.data.data.map((r) => <li key={r.role}><button type="button" onClick={() => setRole(r.role)} className={`w-full p-3 text-start text-sm font-semibold ${role === r.role ? 'bg-gold-50 text-navy-900' : 'text-slate-600 hover:bg-ivory'}`}>{L(r.name)}</button></li>)}</ul></Card>
        <Card>
          <div className="grid gap-2 sm:grid-cols-2">
            {widgets.map((w) => <label key={w.key} className="flex items-center gap-2 rounded-xl border border-navy-100 p-3 text-sm"><input type="checkbox" className="accent-gold-600" checked={cur.includes(w.key)} onChange={() => toggle(w.key)} /><span className="flex-1">{L(w.title)}</span><span className="text-[10px] text-slate-400">{w.type}</span></label>)}
          </div>
        </Card>
      </div>
    </>
  )
}
