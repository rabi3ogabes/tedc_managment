/* eslint-disable @typescript-eslint/no-explicit-any */
import { BellRing, Mail, MessageSquareText, Smartphone, Volume2 } from 'lucide-react'
import { useEffect, useState } from 'react'
import { useTranslation } from 'react-i18next'
import { Button, Card, PageHeader, Spinner } from '@/components/ui'
import { useGet } from '@/hooks/useApi'
import { api, errorMessage } from '@/lib/api'
import { setChimeEnabled } from '@/lib/chime'
import { toast } from '@/lib/toast'

const ICONS = { push: Smartphone, email: Mail, sms: MessageSquareText } as const

/** Each person's choices for optional notifications: channels per kind of event, and the arrival chime. */
export default function NotificationPrefs() {
  const { t } = useTranslation()
  const res = useGet<{ data: { groups: any[]; sound: boolean } }>('/me/notification-preferences', undefined, { staleTime: 0 })
  const [d, setD] = useState<{ groups: any[]; sound: boolean } | null>(null)
  const [busy, setBusy] = useState(false)
  useEffect(() => { if (res.data) setD(res.data.data) }, [res.data])
  if (!d) return <Spinner />

  const toggle = (g: string, c: string) => setD({ ...d, groups: d.groups.map((x) => (x.group === g ? { ...x, channels: { ...x.channels, [c]: !x.channels[c] } } : x)) })
  const save = async () => {
    setBusy(true)
    try {
      const { data } = await api.put('/me/notification-preferences', { sound: d.sound, groups: d.groups.map((g) => ({ group: g.group, channels: g.channels })) })
      setD(data.data); setChimeEnabled(data.data.sound); toast(String(t('comm.prefs.saved')))
    } catch (e) { toast(errorMessage(e), 'error') } finally { setBusy(false) }
  }

  return (
    <>
      <PageHeader title={t('comm.prefs.title')} subtitle={t('comm.prefs.hint')} />
      <Card className="space-y-6">
        <label className="flex items-center gap-3 text-sm font-semibold text-navy-900"><Volume2 className="size-5 text-gold-600" /><input type="checkbox" className="accent-gold-600" checked={d.sound} onChange={(e) => setD({ ...d, sound: e.target.checked })} />{t('comm.prefs.sound')}</label>
        <div className="overflow-x-auto">
          <table className="w-full text-sm">
            <thead><tr className="text-xs text-slate-500"><th className="p-2 text-start">{t('comm.prefs.group')}</th>{(['push', 'email', 'sms'] as const).map((c) => <th key={c} className="p-2 text-center"><span className="inline-flex items-center gap-1">{(() => { const I = ICONS[c]; return <I className="size-4" /> })()}{t(`comm.channels.${c}`)}</span></th>)}</tr></thead>
            <tbody className="divide-y divide-navy-50">
              {d.groups.map((g) => (
                <tr key={g.group}>
                  <td className="p-2"><div className="flex items-center gap-2 font-semibold text-navy-900"><BellRing className="size-4 text-gold-600" />{t(`comm.prefs.groups.${g.group}`, { defaultValue: g.group })}</div>{g.mandatory_events > 0 && <div className="text-xs text-slate-400">{t('comm.prefs.mandatory', { n: g.mandatory_events })}</div>}</td>
                  {(['push', 'email', 'sms'] as const).map((c) => <td key={c} className="p-2 text-center"><input type="checkbox" className="size-4 accent-gold-600" checked={!!g.channels[c]} onChange={() => toggle(g.group, c)} aria-label={`${g.group} ${c}`} /></td>)}
                </tr>
              ))}
            </tbody>
          </table>
        </div>
        <div className="flex justify-end"><Button variant="gold" loading={busy} onClick={save}>{t('common.save')}</Button></div>
      </Card>
    </>
  )
}
