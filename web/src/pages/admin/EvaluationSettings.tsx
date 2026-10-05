/* eslint-disable @typescript-eslint/no-explicit-any */
import { useEffect, useState } from 'react'
import { useTranslation } from 'react-i18next'
import { Button, Card, Field, PageHeader, Spinner } from '@/components/ui'
import { useGet } from '@/hooks/useApi'
import { api, errorMessage } from '@/lib/api'
import { toast } from '@/lib/toast'

const input = 'w-full rounded-xl border border-navy-100 px-3 py-2 text-sm'

/** When impact forms go out, when low satisfaction raises an alert, and what classifies a program: all editable live. */
export default function EvaluationSettings() {
  const { t } = useTranslation()
  const res = useGet<{ data: any }>('/admin/settings/evaluation', undefined, { staleTime: 0 })
  const [s, setS] = useState<any>(null)
  useEffect(() => { if (res.data) setS(res.data.data) }, [res.data])
  if (!s) return <Spinner />
  const num = (path: [string, string], label: string) => <Field label={label}><input className={input} type="number" value={s[path[0]][path[1]] ?? ''} onChange={(e) => setS({ ...s, [path[0]]: { ...s[path[0]], [path[1]]: e.target.value === '' ? null : Number(e.target.value) } })} /></Field>
  const roles = ['center_leadership', 'program_coordinator', 'planning_head', 'center_admin']
  const save = async () => { try { await api.put('/admin/settings/evaluation', s); toast(t('evalc.settings.saved')) } catch (e) { toast(errorMessage(e), 'error') } }
  return (
    <>
      <PageHeader title={t('evalc.settings.title')} />
      <div className="space-y-5">
        <Card className="space-y-3"><h3 className="font-bold text-navy-900">{t('evalc.settings.impact')}</h3><div className="grid gap-3 sm:grid-cols-3">{num(['impact', 'trainee_days'], t('evalc.settings.traineeDays'))}{num(['impact', 'manager_days'], t('evalc.settings.managerDays'))}{num(['impact', 'optional_days'], t('evalc.settings.optionalDays'))}{num(['impact', 'reminder_after_days'], t('evalc.settings.reminderDays'))}{num(['impact', 'expiry_days'], t('evalc.settings.expiryDays'))}</div></Card>
        <Card className="space-y-3"><h3 className="font-bold text-navy-900">{t('evalc.settings.alerts')}</h3>
          <label className="flex items-center gap-2 text-sm"><input type="checkbox" checked={!!s.alerts.is_active} onChange={(e) => setS({ ...s, alerts: { ...s.alerts, is_active: e.target.checked } })} />{t('evalc.settings.alertOn')}</label>
          <div className="grid gap-3 sm:grid-cols-3">{num(['alerts', 'min_response_rate'], t('evalc.settings.minRate'))}{num(['alerts', 'threshold'], t('evalc.settings.threshold'))}</div>
          <div className="flex flex-wrap gap-4 text-sm"><b>{t('evalc.settings.recipients')}</b>{roles.map((r) => <label key={r} className="flex items-center gap-2"><input type="checkbox" checked={s.alerts.recipients.includes(r)} onChange={(e) => setS({ ...s, alerts: { ...s.alerts, recipients: e.target.checked ? [...s.alerts.recipients, r] : s.alerts.recipients.filter((x: string) => x !== r) } })} />{t(`evalc.settings.roles.${r}`)}</label>)}</div></Card>
        <Card className="space-y-3"><h3 className="font-bold text-navy-900">{t('evalc.settings.classification')}</h3><div className="grid gap-3 sm:grid-cols-3">{num(['classification', 'satisfaction_good'], t('evalc.settings.satGood'))}{num(['classification', 'satisfaction_low'], t('evalc.settings.satLow'))}{num(['classification', 'gain_good'], t('evalc.settings.gainGood'))}{num(['classification', 'gain_low'], t('evalc.settings.gainLow'))}{num(['classification', 'impact_good'], t('evalc.settings.impactGood'))}{num(['classification', 'impact_low'], t('evalc.settings.impactLow'))}</div>
          <Field label={t('evalc.settings.anonymous')}><input className={input} type="number" min="1" value={s.anonymous_min_responses} onChange={(e) => setS({ ...s, anonymous_min_responses: Number(e.target.value) })} /></Field></Card>
        <Button variant="gold" onClick={() => void save()}>{t('common.save')}</Button>
      </div>
    </>
  )
}
