import { ClipboardCheck, Plus, QrCode, Trash2 } from 'lucide-react'
import { useState } from 'react'
import { useTranslation } from 'react-i18next'
import { Button, Card, Field, Modal, Spinner, StatusBadge, Table, Td } from '@/components/ui'
import { DataView } from '@/components/ui/DataView'
import { useGet } from '@/hooks/useApi'
import { api, errorMessage } from '@/lib/api'
import { useAuth } from '@/lib/auth'
import { fmt } from '@/lib/format'
import type { Program, Session } from '@/lib/types'

type Report = { expected: number; present: number; late: number; absent: number; rows: { registration_id: string; name: string; school?: string; status: string; check_in_at?: string; check_out_at?: string; minutes: number }[] }

export default function SessionsTab({ program }: { program: Program }) {
  const { t, i18n } = useTranslation()
  const { can } = useAuth()
  const { data, isLoading, refetch } = useGet<{ data: Session[] }>(`/admin/programs/${program.id}/sessions`)
  const lookups = useGet<{ data: { rooms: { id: string; name_ar: string; name_en: string }[]; trainers: { id: string; name_ar: string; name_en: string }[] } }>('/admin/lookups')
  const [adding, setAdding] = useState(false)
  const [attendanceFor, setAttendanceFor] = useState<Session | null>(null)
  const [form, setForm] = useState({ title_ar: '', title_en: '', starts_at: '', ends_at: '', trainer_id: '', training_room_id: '', location_text: '', online_url: '' })
  const [error, setError] = useState<string | null>(null)
  const nm = (o: { name_ar: string; name_en: string }) => (i18n.language === 'ar' ? o.name_ar : o.name_en)

  const save = async () => {
    setError(null)
    try {
      await api.post(`/admin/programs/${program.id}/sessions`, Object.fromEntries(Object.entries(form).map(([k, v]) => [k, v || null])))
      setAdding(false)
      refetch()
    } catch (e) {
      setError(errorMessage(e))
    }
  }

  return (
    <Card padded={false}>
      {can('programs.manage') && <div className="border-b border-navy-100 p-4"><Button size="sm" icon={<Plus className="size-4" />} onClick={() => setAdding(true)}>{t('admin.programs.addSession')}</Button></div>}
      <DataView id="admin.program.sessions" rows={data?.data} loading={isLoading} rowKey={(s) => s.id} columns={[
        { key: 'seq', header: '#', role: 'media', cell: (s) => <span className="grid size-9 place-items-center rounded-xl bg-gold-100 font-bold text-gold-700">{s.sequence}</span> },
        { key: 'title', header: t('programs.sessionsTitle'), role: 'title', cell: (s) => <><div className="font-semibold text-navy-900">{s.title}</div><div className="text-xs font-normal text-slate-400">{s.location}</div></> },
        { key: 'date', header: t('common.date'), className: 'whitespace-nowrap', cell: (s) => <span className="text-sm">{fmt.dateTime(s.starts_at)} – {fmt.time(s.ends_at)}</span> },
        { key: 'trainer', header: t('programs.trainersTitle'), cell: (s) => <span className="text-sm">{s.trainer?.name ?? '—'}</span> },
        { key: 'status', header: t('common.status'), role: 'badge', cell: (s) => <StatusBadge status={s.status} /> },
        { key: 'actions', header: t('common.actions'), role: 'actions', cell: (s) => (
          <div className="flex gap-1">
            {can('attendance.manage') && <Button size="sm" variant="gold" icon={<QrCode className="size-4" />} onClick={() => window.open(`/admin/sessions/${s.id}/qr`, '_blank')}>{t('admin.programs.qr')}</Button>}
            {can('attendance.manage') && <Button size="sm" variant="outline" icon={<ClipboardCheck className="size-4" />} onClick={() => setAttendanceFor(s)}>{t('admin.menu.attendance')}</Button>}
            {can('programs.manage') && <Button size="sm" variant="ghost" aria-label={t('common.delete')} onClick={async () => { await api.delete(`/admin/sessions/${s.id}`); refetch() }}><Trash2 className="size-4" /></Button>}
          </div>
        ) },
      ]} />

      <Modal open={adding} onClose={() => setAdding(false)} title={t('admin.programs.addSession')}>
        <div className="grid gap-3 sm:grid-cols-2">
          <Field label={t('admin.programs.titleAr')}><input className="input" value={form.title_ar} onChange={(e) => setForm({ ...form, title_ar: e.target.value })} /></Field>
          <Field label={t('admin.programs.titleEn')}><input className="input" dir="ltr" value={form.title_en} onChange={(e) => setForm({ ...form, title_en: e.target.value })} /></Field>
          <Field label={t('common.from')}><input className="input" type="datetime-local" value={form.starts_at} onChange={(e) => setForm({ ...form, starts_at: e.target.value })} /></Field>
          <Field label={t('common.to')}><input className="input" type="datetime-local" value={form.ends_at} onChange={(e) => setForm({ ...form, ends_at: e.target.value })} /></Field>
          <Field label={t('programs.trainersTitle')}><select className="input" value={form.trainer_id} onChange={(e) => setForm({ ...form, trainer_id: e.target.value })}><option value="">—</option>{lookups.data?.data.trainers.map((x) => <option key={x.id} value={x.id}>{nm(x)}</option>)}</select></Field>
          <Field label="Room"><select className="input" value={form.training_room_id} onChange={(e) => setForm({ ...form, training_room_id: e.target.value })}><option value="">—</option>{lookups.data?.data.rooms.map((x) => <option key={x.id} value={x.id}>{nm(x)}</option>)}</select></Field>
          <Field label="Location" className="sm:col-span-2"><input className="input" value={form.location_text} onChange={(e) => setForm({ ...form, location_text: e.target.value })} /></Field>
          <Field label="Online URL" className="sm:col-span-2"><input className="input" dir="ltr" value={form.online_url} onChange={(e) => setForm({ ...form, online_url: e.target.value })} /></Field>
        </div>
        {error && <p className="mt-3 text-sm text-danger">{error}</p>}
        <div className="mt-5 flex justify-end"><Button variant="gold" onClick={save}>{t('common.save')}</Button></div>
      </Modal>

      {attendanceFor && <AttendanceModal session={attendanceFor} onClose={() => setAttendanceFor(null)} />}
    </Card>
  )
}

function AttendanceModal({ session, onClose }: { session: Session; onClose: () => void }) {
  const { t } = useTranslation()
  const { data, isLoading, refetch } = useGet<{ data: Report }>(`/admin/sessions/${session.id}/attendance`)
  const mark = async (registration_id: string, status: string) => {
    await api.post(`/admin/sessions/${session.id}/attendance`, { registration_id, status })
    refetch()
  }
  const r = data?.data
  return (
    <Modal open onClose={onClose} title={`${t('admin.menu.attendance')} — ${session.title}`} wide>
      {isLoading || !r ? <Spinner /> : (
        <>
          <div className="mb-4 grid grid-cols-4 gap-2 text-center text-sm">
            {([['expected', r.expected], ['present', r.present], ['late', r.late], ['absent', r.absent]] as const).map(([k, v]) => (
              <div key={k} className="rounded-xl bg-ivory p-2"><div className="text-xl font-bold text-navy-900">{v}</div><div className="text-xs text-slate-500">{k === 'expected' ? t('admin.qr.expected') : t(`status.${k}`)}</div></div>
            ))}
          </div>
          <div className="max-h-96 overflow-y-auto">
            <Table head={[t('common.name'), t('common.status'), 'In', 'Out', '']}>
              {r.rows.map((row) => (
                <tr key={row.registration_id}>
                  <Td><div className="font-semibold">{row.name}</div><div className="text-xs text-slate-400">{row.school}</div></Td>
                  <Td><StatusBadge status={row.status} /></Td>
                  <Td className="text-xs">{fmt.time(row.check_in_at)}</Td>
                  <Td className="text-xs">{fmt.time(row.check_out_at)}</Td>
                  <Td>
                    <select className="input py-1 text-xs" value="" onChange={(e) => e.target.value && mark(row.registration_id, e.target.value)}>
                      <option value="">{t('common.edit')}</option>
                      {['present', 'late', 'absent', 'excused'].map((s) => <option key={s} value={s}>{t(`status.${s}`)}</option>)}
                    </select>
                  </Td>
                </tr>
              ))}
            </Table>
          </div>
        </>
      )}
    </Modal>
  )
}
