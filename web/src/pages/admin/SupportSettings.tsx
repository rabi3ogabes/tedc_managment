import { LifeBuoy, Mail, Phone } from 'lucide-react'
import { useEffect, useState } from 'react'
import { useTranslation } from 'react-i18next'
import { Card, Field, PageHeader, SaveDock, Spinner } from '@/components/ui'
import { useGet } from '@/hooks/useApi'
import { api, errorMessage } from '@/lib/api'
import { toast } from '@/lib/toast'

type Level = { priority: string; response_ar: string; response_en: string; resolution_ar: string; resolution_en: string }
type Support = { phone: string; email: string; saaed_url: string; hours_ar: string; hours_en: string; sla: Level[]; saved: boolean }

/** Settings → Help & support: the contacts, working hours and service levels the help centre, the app and the e-mails show. */
export default function SupportSettings() {
  const { t, i18n } = useTranslation()
  const lang = i18n.language === 'en' ? 'en' : 'ar'
  const { data, isLoading, refetch } = useGet<{ data: Support }>('/admin/settings/support', undefined, { staleTime: 0 })
  const [draft, setDraft] = useState<Support | null>(null)
  const [busy, setBusy] = useState(false)
  const [error, setError] = useState<string | null>(null)

  useEffect(() => { if (data && !draft) setDraft(structuredClone(data.data)) }, [data, draft])
  if (isLoading || !data || !draft) return <Spinner />

  const set = <K extends keyof Support>(key: K, value: Support[K]) => setDraft((d) => d && { ...d, [key]: value })
  const level = (i: number, key: keyof Level, value: string) => setDraft((d) => d && { ...d, sla: d.sla.map((l, j) => (j === i ? { ...l, [key]: value } : l)) })
  const save = async () => {
    setBusy(true); setError(null)
    try {
      await api.put('/admin/settings/support', { phone: draft.phone, email: draft.email, saaed_url: draft.saaed_url, hours_ar: draft.hours_ar, hours_en: draft.hours_en, sla: draft.sla })
      const fresh = await refetch()   // the form shows what the server now holds
      if (fresh.data) setDraft(structuredClone(fresh.data.data))
      toast(String(t('min.support.saved')))
    } catch (e) { setError(errorMessage(e)) } finally { setBusy(false) }
  }
  const channels = [
    { key: 'phone', icon: Phone, value: draft.phone, label: t('min.support.phone') },
    { key: 'email', icon: Mail, value: draft.email, label: t('min.support.email') },
    { key: 'saaed', icon: LifeBuoy, value: draft.saaed_url, label: t('min.support.saaed') },
  ]
  const cell = 'input !py-1.5 text-xs'

  return (
    <>
      <PageHeader title={t('min.support.title')} subtitle={t('min.support.subtitle')} />
      {!data.data.saved && <p className="mb-4 rounded-xl bg-amber-50 p-3 text-sm text-amber-800">{t('min.support.notSaved')}</p>}
      <div className="grid gap-5 lg:grid-cols-2">
        <Card className="space-y-4">
          <h2 className="font-bold text-navy-900">{t('min.support.contacts')}</h2>
          <Field label={t('min.support.phone')} hint={t('min.support.phoneHint')}><input className="input" dir="ltr" inputMode="tel" autoComplete="off" value={draft.phone} onChange={(e) => set('phone', e.target.value)} /></Field>
          <Field label={t('min.support.email')}><input className="input" dir="ltr" type="email" autoComplete="off" value={draft.email} onChange={(e) => set('email', e.target.value)} /></Field>
          <Field label={t('min.support.saaed')} hint={t('min.support.saaedHint')}><input className="input" dir="ltr" placeholder="https://" autoComplete="off" value={draft.saaed_url} onChange={(e) => set('saaed_url', e.target.value)} /></Field>
          <h2 className="pt-2 font-bold text-navy-900">{t('min.support.hours')}</h2>
          <div className="grid gap-3 sm:grid-cols-2">
            <Field label={t('min.support.hoursAr')}><input className="input" dir="rtl" value={draft.hours_ar} onChange={(e) => set('hours_ar', e.target.value)} /></Field>
            <Field label={t('min.support.hoursEn')}><input className="input" dir="ltr" value={draft.hours_en} onChange={(e) => set('hours_en', e.target.value)} /></Field>
          </div>
          <p className="text-xs text-slate-400">{t('min.support.envNote')}</p>
        </Card>
        <div className="space-y-5">
          <Card>
            <h2 className="mb-1 font-bold text-navy-900">{t('min.support.preview')}</h2>
            <ul className="divide-y divide-navy-50">
              {channels.map((c) => (
                <li key={c.key} className="flex items-center gap-3 py-3">
                  <span className="grid size-9 place-items-center rounded-lg bg-navy-900 text-gold-300"><c.icon className="size-4" /></span>
                  <div className="min-w-0 flex-1"><div className="text-xs text-slate-500">{c.label}</div><div className={c.value ? 'break-all font-semibold text-navy-900' : 'text-sm text-slate-400'} dir={c.value ? 'ltr' : undefined}>{c.value || t('min.support.unset')}</div></div>
                </li>
              ))}
            </ul>
            <p className="mt-2 text-xs text-slate-500">{lang === 'en' ? draft.hours_en : draft.hours_ar}</p>
          </Card>
          <Card className="space-y-3">
            <div><h2 className="font-bold text-navy-900">{t('min.support.sla')}</h2><p className="text-xs text-slate-400">{t('min.support.slaHint')}</p></div>
            <div className="overflow-x-auto">
              <table className="w-full text-sm">
                <thead><tr className="text-xs text-slate-500"><th className="p-1 text-start">{t('min.support.priority')}</th><th className="p-1 text-start" colSpan={2}>{t('min.support.response')}</th><th className="p-1 text-start" colSpan={2}>{t('min.support.resolution')}</th></tr>
                  <tr className="text-[11px] text-slate-400"><th /><th className="p-1 text-start">{t('min.support.ar')}</th><th className="p-1 text-start">{t('min.support.en')}</th><th className="p-1 text-start">{t('min.support.ar')}</th><th className="p-1 text-start">{t('min.support.en')}</th></tr></thead>
                <tbody>
                  {draft.sla.map((l, i) => (
                    <tr key={l.priority}>
                      <td className="p-1 font-bold text-navy-900">{l.priority}</td>
                      <td className="p-1"><input className={cell} dir="rtl" aria-label={`${l.priority} ${t('min.support.response')} ${t('min.support.ar')}`} value={l.response_ar} onChange={(e) => level(i, 'response_ar', e.target.value)} /></td>
                      <td className="p-1"><input className={cell} dir="ltr" aria-label={`${l.priority} ${t('min.support.response')} ${t('min.support.en')}`} value={l.response_en} onChange={(e) => level(i, 'response_en', e.target.value)} /></td>
                      <td className="p-1"><input className={cell} dir="rtl" aria-label={`${l.priority} ${t('min.support.resolution')} ${t('min.support.ar')}`} value={l.resolution_ar} onChange={(e) => level(i, 'resolution_ar', e.target.value)} /></td>
                      <td className="p-1"><input className={cell} dir="ltr" aria-label={`${l.priority} ${t('min.support.resolution')} ${t('min.support.en')}`} value={l.resolution_en} onChange={(e) => level(i, 'resolution_en', e.target.value)} /></td>
                    </tr>
                  ))}
                </tbody>
              </table>
            </div>
          </Card>
        </div>
      </div>
      {error && <p className="mt-4 text-sm text-danger" role="alert">{error}</p>}
      <SaveDock label={t('min.support.save')} onClick={save} loading={busy} />
    </>
  )
}
