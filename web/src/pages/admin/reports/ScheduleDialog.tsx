/* eslint-disable @typescript-eslint/no-explicit-any */
import { Trash2 } from 'lucide-react'
import { useState } from 'react'
import { useTranslation } from 'react-i18next'
import { Badge, Button, Field, Modal } from '@/components/ui'
import { useGet } from '@/hooks/useApi'
import { api, errorMessage } from '@/lib/api'
import { fmt } from '@/lib/format'
import { toast } from '@/lib/toast'
import { useLookups } from '../communication/shared'

const cls = 'w-full rounded-xl border border-navy-100 px-3 py-2 text-sm'

/** Send a report on a timetable to people, roles and e-mail addresses; see and remove existing schedules. */
export default function ScheduleDialog({ definition, onClose }: { definition?: { id: string; title: { ar: string; en: string } }; onClose: () => void }) {
  const { t, i18n } = useTranslation()
  const lookups = useLookups()
  const list = useGet<{ data: any[] }>('/admin/report-schedules', undefined, { staleTime: 0 })
  const [f, setF] = useState({ frequency: 'weekly', formats: ['pdf'] as string[], roles: [] as string[], emails: '' })
  const [busy, setBusy] = useState(false)
  const [error, setError] = useState<string | null>(null)
  const lang = i18n.language === 'en' ? 'en' : 'ar'

  const save = async () => {
    if (!definition) return
    setBusy(true); setError(null)
    try {
      await api.post('/admin/report-schedules', { definition_id: definition.id, frequency: f.frequency, formats: f.formats, lang, recipients: { roles: f.roles, emails: f.emails.split(/[,\s]+/).filter(Boolean) } })
      toast(String(t('rep.schedule.saved'))); list.refetch(); setF({ ...f, emails: '' })
    } catch (e) { setError(errorMessage(e)) } finally { setBusy(false) }
  }
  const remove = async (id: string) => { await api.delete(`/admin/report-schedules/${id}`); list.refetch() }

  return (
    <Modal open onClose={onClose} title={t('rep.schedule.title')} wide>
      {definition && (
        <div className="space-y-3">
          <p className="font-semibold text-navy-900">{definition.title[lang]}</p>
          <div className="grid gap-3 md:grid-cols-2">
            <Field label={t('rep.schedule.frequency')}><select className={cls} value={f.frequency} onChange={(e) => setF({ ...f, frequency: e.target.value })}>{['daily', 'weekly', 'monthly'].map((x) => <option key={x} value={x}>{t(`rep.schedule.${x}`)}</option>)}</select></Field>
            <Field label={t('rep.schedule.formats')}><div className="flex gap-3 pt-2 text-sm">{['xlsx', 'pdf', 'docx'].map((x) => <label key={x} className="flex items-center gap-1.5"><input type="checkbox" className="accent-gold-600" checked={f.formats.includes(x)} onChange={(e) => setF({ ...f, formats: e.target.checked ? [...f.formats, x] : f.formats.filter((y) => y !== x) })} />{t(`rep.${x}`)}</label>)}</div></Field>
            <Field label={t('rep.schedule.roles')}>
              <div className="flex max-h-28 flex-wrap gap-1.5 overflow-y-auto">{lookups?.roles.map((r) => {
                const on = f.roles.includes(r.slug)
                return <button key={r.slug} type="button" onClick={() => setF({ ...f, roles: on ? f.roles.filter((x) => x !== r.slug) : [...f.roles, r.slug] })} className={`rounded-full px-3 py-1 text-xs font-semibold ${on ? 'bg-navy-900 text-gold-300' : 'bg-navy-50 text-navy-700'}`}>{lang === 'ar' ? r.name_ar : r.name_en}</button>
              })}</div>
            </Field>
            <Field label={t('rep.schedule.emails')}><input className={cls} dir="ltr" value={f.emails} onChange={(e) => setF({ ...f, emails: e.target.value })} placeholder="name@example.com" /></Field>
          </div>
          {error && <p className="text-sm text-danger">{error}</p>}
          <div className="flex justify-end"><Button variant="gold" loading={busy} disabled={!f.formats.length || (!f.roles.length && !f.emails.trim())} onClick={save}>{t('rep.schedule.button')}</Button></div>
        </div>
      )}
      <h4 className="mb-2 mt-6 text-sm font-bold text-navy-900">{t('rep.schedule.list')}</h4>
      <ul className="divide-y divide-navy-50 text-sm">
        {list.data?.data.map((s) => (
          <li key={s.id} className="flex items-center justify-between gap-3 py-2">
            <div className="min-w-0"><div className="truncate font-semibold">{s.definition ? (lang === 'ar' ? s.definition.title_ar : s.definition.title_en) : ''}</div>
              <div className="text-xs text-slate-500">{t(`rep.schedule.${s.frequency}`)} · {t('rep.schedule.next')}: {s.next_run_at ? fmt.date(s.next_run_at, { dateStyle: 'medium' } as any) : '—'}{s.last_error && <Badge color="red" className="ms-2">{t('rep.schedule.error')}</Badge>}</div></div>
            <button className="text-slate-400 hover:text-danger" onClick={() => remove(s.id)} aria-label={String(t('rep.schedule.remove'))}><Trash2 className="size-4" /></button>
          </li>
        ))}
        {!list.data?.data.length && <li className="py-3 text-slate-400">{t('rep.schedule.empty')}</li>}
      </ul>
    </Modal>
  )
}
