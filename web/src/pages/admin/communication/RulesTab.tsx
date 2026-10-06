/* eslint-disable @typescript-eslint/no-explicit-any */
import { Plus, Trash2 } from 'lucide-react'
import { useState } from 'react'
import { useTranslation } from 'react-i18next'
import { Badge, Button, Card, Empty, Field, Modal, Spinner, Table, Td } from '@/components/ui'
import { useGet } from '@/hooks/useApi'
import { api, errorMessage } from '@/lib/api'
import type { Paginated, Program } from '@/lib/types'
import { toast } from '@/lib/toast'
import { AudienceBuilder, ChannelChecks, input, useLookups, type AudienceFilter } from './shared'

const blank = { event: '*', program_id: '', category_id: '', enabled: true, channels: null as string[] | null, delay_minutes: 0, quiet: false, days: [0, 1, 2, 3, 4], from: '08:00', to: '20:00', quiet_channels: ['sms', 'email'] as string[], audience: {} as AudienceFilter }

/** Switch events off per category/program/audience, narrow their channels, delay them or keep them inside allowed hours. */
export default function RulesTab() {
  const { t, i18n } = useTranslation()
  const { data, isLoading, refetch } = useGet<{ data: any[]; meta: { events: { event: string; name: { ar: string; en: string }; mandatory: boolean }[] } }>('/admin/notification-rules', undefined, { staleTime: 0 })
  const programs = useGet<Paginated<Program>>('/admin/programs', { per_page: 100 })
  const lookups = useLookups()
  const [open, setOpen] = useState(false)
  const [f, setF] = useState<any>(blank)
  const [error, setError] = useState<string | null>(null)
  const events = data?.meta.events ?? []
  const evName = (e: string) => (e === '*' ? String(t('comm.rules.allEvents')) : events.find((x) => x.event === e)?.name[i18n.language === 'ar' ? 'ar' : 'en'] ?? e)
  const nm = (o: { name_ar: string; name_en: string }) => (i18n.language === 'ar' ? o.name_ar : o.name_en)

  const save = async () => {
    setError(null)
    try {
      await api.post('/admin/notification-rules', {
        event: f.event, program_id: f.program_id || null, category_id: f.category_id || null, enabled: f.enabled, channels: f.channels, delay_minutes: Number(f.delay_minutes) || 0,
        audience_filter: Object.keys(f.audience).length ? f.audience : null,
        quiet_hours: f.quiet ? { days: f.days, from: f.from, to: f.to, timezone: 'Asia/Qatar', channels: f.quiet_channels } : null,
      })
      toast(String(t('comm.rules.saved'))); setOpen(false); setF(blank); refetch()
    } catch (e) { setError(errorMessage(e)) }
  }
  const toggle = async (r: any) => { await api.put(`/admin/notification-rules/${r.id}`, { enabled: !r.enabled }); refetch() }
  const remove = async (r: any) => { if (window.confirm(String(t('comm.rules.delete')))) { await api.delete(`/admin/notification-rules/${r.id}`); refetch() } }
  const summary = (r: any) => [r.program_id && programs.data?.data.find((p) => p.id === r.program_id)?.title, r.category_id && lookups?.categories.find((c) => c.id === r.category_id) && nm(lookups.categories.find((c) => c.id === r.category_id)!), r.audience_filter && t('comm.audience.title')].filter(Boolean).join(' · ') || '—'

  return (
    <>
      <div className="mb-4 flex flex-wrap items-center justify-between gap-3">
        <p className="max-w-2xl text-sm text-slate-500">{t('comm.rules.hint')}</p>
        <Button variant="gold" icon={<Plus className="size-4" />} onClick={() => setOpen(true)}>{t('comm.rules.new')}</Button>
      </div>
      {isLoading ? <Spinner /> : !data?.data.length ? <Card><Empty text={String(t('comm.rules.empty'))} /></Card> : (
        <Card padded={false}>
          <Table head={[t('comm.rules.event'), t('comm.rules.scope'), t('comm.rules.channels'), t('comm.rules.delay'), t('comm.rules.quiet'), t('comm.rules.enabled'), '']}>
            {data.data.map((r) => (
              <tr key={r.id}>
                <Td><div className="font-semibold text-navy-900">{evName(r.event)}</div>{events.find((e) => e.event === r.event)?.mandatory && <Badge color="gold">{t('comm.rules.mandatory')}</Badge>}</Td>
                <Td className="text-xs">{summary(r)}</Td>
                <Td>{r.channels ? r.channels.map((c: string) => t(`comm.channels.${c}`)).join('، ') : t('comm.rules.keepChannels')}</Td>
                <Td>{r.delay_minutes || '—'}</Td>
                <Td className="text-xs">{r.quiet_hours ? `${r.quiet_hours.from}–${r.quiet_hours.to} · ${(r.quiet_hours.days ?? []).map((d: number) => (t('comm.rules.dayNames', { returnObjects: true }) as string[])[d]).join(' ')}` : '—'}</Td>
                <Td><label className="inline-flex items-center gap-2 text-xs"><input type="checkbox" className="accent-gold-600" checked={r.enabled} onChange={() => toggle(r)} />{r.enabled ? t('comm.rules.enabled') : t('comm.rules.disabledNote')}</label></Td>
                <Td><button className="text-slate-400 hover:text-danger" onClick={() => remove(r)} aria-label="delete"><Trash2 className="size-4" /></button></Td>
              </tr>
            ))}
          </Table>
        </Card>
      )}

      <Modal open={open} onClose={() => setOpen(false)} title={t('comm.rules.new')} wide>
        <div className="grid gap-3 md:grid-cols-2">
          <Field label={t('comm.rules.event')}>
            <select className={input} value={f.event} onChange={(e) => setF({ ...f, event: e.target.value })}>
              <option value="*">{t('comm.rules.allEvents')}</option>
              <option value="announcement">{t('comm.tabs.announcements')}</option>
              {events.map((e) => <option key={e.event} value={e.event}>{e.name[i18n.language === 'ar' ? 'ar' : 'en']}{e.mandatory ? ' •' : ''}</option>)}
            </select>
          </Field>
          <Field label={t('comm.rules.enabled')}><label className="mt-2 flex items-center gap-2 text-sm"><input type="checkbox" className="accent-gold-600" checked={f.enabled} onChange={(e) => setF({ ...f, enabled: e.target.checked })} />{f.enabled ? t('comm.rules.enabled') : t('comm.rules.disabledNote')}</label></Field>
          <Field label={t('comm.rules.program')}><select className={input} value={f.program_id} onChange={(e) => setF({ ...f, program_id: e.target.value })}><option value="">{t('comm.rules.any')}</option>{programs.data?.data.map((p) => <option key={p.id} value={p.id}>{p.title}</option>)}</select></Field>
          <Field label={t('comm.rules.category')}><select className={input} value={f.category_id} onChange={(e) => setF({ ...f, category_id: e.target.value })}><option value="">{t('comm.rules.any')}</option>{lookups?.categories.map((c) => <option key={c.id} value={c.id}>{nm(c)}</option>)}</select></Field>
          <Field label={t('comm.rules.channels')} className="md:col-span-2">
            <div className="space-y-2">
              <label className="flex items-center gap-2 text-sm"><input type="checkbox" className="accent-gold-600" checked={f.channels === null} onChange={(e) => setF({ ...f, channels: e.target.checked ? null : ['push'] })} />{t('comm.rules.keepChannels')}</label>
              {f.channels && <ChannelChecks value={f.channels} onChange={(v) => setF({ ...f, channels: v })} />}
            </div>
          </Field>
          <Field label={t('comm.rules.delay')}><input type="number" min={0} max={10080} className={input} value={f.delay_minutes} onChange={(e) => setF({ ...f, delay_minutes: e.target.value })} /></Field>
          <div className="md:col-span-2">
            <label className="flex items-center gap-2 text-sm font-semibold"><input type="checkbox" className="accent-gold-600" checked={f.quiet} onChange={(e) => setF({ ...f, quiet: e.target.checked })} />{t('comm.rules.quietOn')} <span className="text-xs font-normal text-slate-400">({t('comm.rules.tz')})</span></label>
            {f.quiet && (
              <div className="mt-3 grid gap-3 rounded-xl bg-ivory p-3 md:grid-cols-2">
                <Field label={t('comm.rules.days')} className="md:col-span-2">
                  <div className="flex flex-wrap gap-1.5">{(t('comm.rules.dayNames', { returnObjects: true }) as string[]).map((d, i) => (
                    <button key={i} type="button" onClick={() => setF({ ...f, days: f.days.includes(i) ? f.days.filter((x: number) => x !== i) : [...f.days, i] })} className={`rounded-full px-3 py-1 text-xs font-semibold ${f.days.includes(i) ? 'bg-navy-900 text-gold-300' : 'bg-white text-navy-700 ring-1 ring-navy-100'}`}>{d}</button>
                  ))}</div>
                </Field>
                <Field label={t('comm.rules.from')}><input type="time" className={input} value={f.from} onChange={(e) => setF({ ...f, from: e.target.value })} /></Field>
                <Field label={t('comm.rules.to')}><input type="time" className={input} value={f.to} onChange={(e) => setF({ ...f, to: e.target.value })} /></Field>
                <Field label={t('comm.rules.quietChannels')} className="md:col-span-2"><ChannelChecks value={f.quiet_channels} onChange={(v) => setF({ ...f, quiet_channels: v })} /></Field>
              </div>
            )}
          </div>
          <div className="md:col-span-2"><AudienceBuilder value={f.audience} onChange={(v) => setF({ ...f, audience: v })} preview={false} /></div>
        </div>
        {error && <p className="mt-3 text-sm text-danger">{error}</p>}
        <div className="mt-5 flex justify-end gap-2"><Button variant="outline" onClick={() => setOpen(false)}>{t('common.cancel')}</Button><Button variant="gold" onClick={save}>{t('common.save')}</Button></div>
      </Modal>
    </>
  )
}
