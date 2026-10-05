import { Plus } from 'lucide-react'
import { useState } from 'react'
import { useTranslation } from 'react-i18next'
import { Badge, Button, Card, Empty, Field, Modal, PageHeader, Spinner, Tabs } from '@/components/ui'
import { useGet } from '@/hooks/useApi'
import { api, errorMessage } from '@/lib/api'
import { useAuth } from '@/lib/auth'
import { fmt } from '@/lib/format'
import { toast } from '@/lib/toast'

type Tab = 'absence' | 'devices'
type Alert = { id: string; level: string; absence_percent: number; supervisor_note: string | null; program: string; employee: string; created_at: string }
type Device = { id: string; name: string; vendor: string; serial: string | null; location_room_id: string | null; status: string; last_sync_at: string | null; has_secret: boolean }

/** Supervisor view of absence alerts, and the registry of fingerprint devices. */
export default function AttendanceOps() {
  const { t } = useTranslation()
  const { can } = useAuth()
  const tabs: { id: Tab; show: boolean; label: string }[] = [{ id: 'absence', show: can('attendance.manage'), label: t('ops.absence.alerts') }, { id: 'devices', show: can('attendance.devices'), label: t('ops.devices.title') }]
  const visible = tabs.filter((x) => x.show)
  const [tab, setTab] = useState<Tab>(visible[0]?.id ?? 'absence')
  return (
    <div className="space-y-6 pb-6">
      <PageHeader title={t('ops.absence.title')} />
      <Tabs<Tab> value={tab} onChange={setTab} tabs={visible.map((x) => ({ id: x.id, label: x.label }))} />
      {tab === 'absence' ? <Alerts /> : <Devices />}
    </div>
  )
}

function Alerts() {
  const { t } = useTranslation()
  const list = useGet<{ data: Alert[] }>('/admin/absence-alerts', undefined, { staleTime: 0 })
  const [target, setTarget] = useState<Alert | null>(null)
  const [note, setNote] = useState('')
  const save = async (resend: boolean) => {
    if (!target) return
    try { await api.put(`/admin/absence-alerts/${target.id}`, { supervisor_note: note, resend }); toast(t('ops.absence.saved')); setTarget(null); await list.refetch() } catch (e) { toast(errorMessage(e), 'error') }
  }
  if (list.isLoading || !list.data) return <Spinner />
  return (
    <>
      <Card padded={false}>{list.data.data.length === 0 ? <Empty text={t('ops.absence.empty')} /> : (
        <ul className="divide-y divide-navy-50">{list.data.data.map((a) => (
          <li key={a.id} className="flex flex-wrap items-center gap-3 px-5 py-4">
            <div className="min-w-0 flex-1"><div className="font-bold text-navy-900">{a.employee}</div><div className="text-sm text-slate-600">{a.program}</div>{a.supervisor_note && <p className="text-xs text-slate-500">{a.supervisor_note}</p>}</div>
            <Badge color={a.level === 'breach' ? 'red' : 'gold'}>{t(`ops.absence.level.${a.level}`)}</Badge><span className="text-sm tabular-nums text-slate-600">{t('ops.absence.percent')} {fmt.number(a.absence_percent, 1)}%</span>
            <Button size="sm" variant="outline" onClick={() => { setTarget(a); setNote(a.supervisor_note ?? '') }}>{t('ops.absence.note')}</Button>
          </li>))}</ul>
      )}</Card>
      <Modal open={!!target} onClose={() => setTarget(null)} title={target?.employee ?? ''}>
        <div className="space-y-4"><Field label={t('ops.absence.note')}><textarea className="input min-h-24" value={note} onChange={(e) => setNote(e.target.value)} /></Field><div className="flex gap-2"><Button variant="gold" onClick={() => void save(false)}>{t('common.save')}</Button><Button variant="outline" onClick={() => void save(true)}>{t('ops.absence.resend')}</Button></div></div>
      </Modal>
    </>
  )
}

function Devices() {
  const { t } = useTranslation()
  const list = useGet<{ data: Device[] }>('/admin/attendance-devices', undefined, { staleTime: 0 })
  const rooms = useGet<{ data: { rooms: { id: string; name_ar: string; name_en: string }[] } }>('/admin/lookups', undefined, { staleTime: 60_000 })
  const [open, setOpen] = useState(false)
  const [f, setF] = useState({ name: '', vendor: 'generic_http', serial: '', location_room_id: '', secret: '' })
  const { i18n } = useTranslation()
  const ar = i18n.language === 'ar'
  const create = async () => {
    try { await api.post('/admin/attendance-devices', { ...f, location_room_id: f.location_room_id || null, serial: f.serial || null, secret: f.secret || null }); toast(t('ops.devices.saved')); setOpen(false); setF({ name: '', vendor: 'generic_http', serial: '', location_room_id: '', secret: '' }); await list.refetch() } catch (e) { toast(errorMessage(e), 'error') }
  }
  const test = async (d: Device) => {
    try { const { data } = await api.post(`/admin/attendance-devices/${d.id}/test`); toast(data.data.ok ? t('ops.devices.ok') : data.data.issues.map((i: string) => t(`ops.devices.issues.${i}`)).join(' · '), data.data.ok ? 'success' : 'error') } catch (e) { toast(errorMessage(e), 'error') }
  }
  const upload = async (d: Device, file: File) => {
    const body = new FormData(); body.append('file', file)
    try { const { data } = await api.post(`/admin/attendance-devices/${d.id}/import`, body); const r = data.data; toast(t('ops.devices.result', { received: r.received, matched: r.matched, duplicates: r.duplicates, unmatched: r.unmatched.length })); await list.refetch() } catch (e) { toast(errorMessage(e), 'error') }
  }
  if (list.isLoading || !list.data) return <Spinner />
  const base = `${window.location.origin.replace(/\/$/, '')}/api/v1/integrations/fingerprint`
  return (
    <div className="space-y-4">
      <div className="flex justify-end"><Button variant="gold" icon={<Plus className="size-4" />} onClick={() => setOpen(true)}>{t('ops.devices.new')}</Button></div>
      {list.data.data.length === 0 ? <Card><Empty text={t('ops.devices.empty')} /></Card> : (
        <div className="grid gap-4 md:grid-cols-2">{list.data.data.map((d) => (
          <Card key={d.id} className="space-y-3">
            <div className="flex items-start justify-between gap-2"><div><h3 className="font-bold text-navy-900">{d.name}</h3><p className="text-xs text-slate-500">{d.vendor} · {d.serial ?? '—'}</p></div><Badge color={d.status === 'active' ? 'green' : 'slate'}>{d.status}</Badge></div>
            <p className="text-xs text-slate-500">{t('ops.devices.lastSync')}: {d.last_sync_at ? fmt.dateTime(d.last_sync_at) : t('ops.devices.never')}</p>
            <p className="break-all rounded-lg bg-ivory p-2 font-mono text-[11px]" dir="ltr" title={t('ops.devices.webhook')}>{base}/{d.id}/punches</p>
            <div className="flex flex-wrap gap-2"><Button size="sm" variant="outline" onClick={() => void test(d)}>{t('ops.devices.test')}</Button>
              <label className="cursor-pointer"><span className="inline-flex items-center rounded-xl border border-navy-100 bg-white px-3 py-1.5 text-sm font-bold text-navy-900">{t('ops.devices.import')}</span><input type="file" accept=".csv,text/csv" className="hidden" onChange={(e) => { const x = e.target.files?.[0]; if (x) void upload(d, x); e.target.value = '' }} /></label></div>
          </Card>))}</div>
      )}
      <Modal open={open} onClose={() => setOpen(false)} title={t('ops.devices.new')}>
        <div className="space-y-4">
          <Field label={t('ops.rooms.titleField')}><input className="input" value={f.name} onChange={(e) => setF({ ...f, name: e.target.value })} /></Field>
          <div className="grid gap-4 sm:grid-cols-2"><Field label={t('ops.devices.vendor')}><select className="input" value={f.vendor} onChange={(e) => setF({ ...f, vendor: e.target.value })}>{['generic_http', 'zkteco', 'suprema', 'csv'].map((v) => <option key={v} value={v}>{v}</option>)}</select></Field><Field label={t('ops.devices.serial')}><input className="input" dir="ltr" value={f.serial} onChange={(e) => setF({ ...f, serial: e.target.value })} /></Field></div>
          <Field label={t('ops.devices.room')}><select className="input" value={f.location_room_id} onChange={(e) => setF({ ...f, location_room_id: e.target.value })}><option value="" />{rooms.data?.data.rooms.map((r) => <option key={r.id} value={r.id}>{ar ? r.name_ar : r.name_en}</option>)}</select></Field>
          <Field label={t('ops.devices.secret')}><input className="input" dir="ltr" value={f.secret} onChange={(e) => setF({ ...f, secret: e.target.value })} /></Field>
          <Button variant="gold" disabled={!f.name.trim()} onClick={() => void create()}>{t('common.save')}</Button>
        </div>
      </Modal>
    </div>
  )
}
