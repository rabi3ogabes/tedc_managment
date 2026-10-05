import { ClipboardCheck, FileSpreadsheet, FileText, PenLine, Plus, QrCode, Trash2, Video } from 'lucide-react'
import { useState } from 'react'
import { useTranslation } from 'react-i18next'
import { Button, Card, Field, Modal, Spinner, StatusBadge, Table, Td } from '@/components/ui'
import { DataView } from '@/components/ui/DataView'
import { useGet } from '@/hooks/useApi'
import { api, downloadFile, errorMessage } from '@/lib/api'
import { useAuth } from '@/lib/auth'
import { fmt } from '@/lib/format'
import { toast } from '@/lib/toast'
import type { Program, Session } from '@/lib/types'

type Report = { expected: number; present: number; late: number; absent: number; rows: { registration_id: string; name: string; school?: string; status: string; check_in_at?: string; check_out_at?: string; minutes: number; attendance_id?: string | null; method?: string | null; participated?: boolean; leave_minutes?: number }[] }
type TrainerRow = { trainer_id: string; name: string | null; check_in_at: string | null; check_out_at: string | null; method: string | null; minutes: number }

export default function SessionsTab({ program }: { program: Program }) {
  const { t, i18n } = useTranslation()
  const { can } = useAuth()
  const { data, isLoading, refetch } = useGet<{ data: Session[] }>(`/admin/programs/${program.id}/sessions`)
  const lookups = useGet<{ data: { rooms: { id: string; name_ar: string; name_en: string }[]; trainers: { id: string; name_ar: string; name_en: string }[] } }>('/admin/lookups')
  const [adding, setAdding] = useState(false)
  const [attendanceFor, setAttendanceFor] = useState<Session | null>(null)
  const [form, setForm] = useState({ title_ar: '', title_en: '', starts_at: '', ends_at: '', trainer_id: '', training_room_id: '', location_text: '', online_url: '', mode: program.delivery_mode === 'online' ? 'online' : 'in_person', online_platform: '', online_passcode: '' })
  const [editing, setEditing] = useState<Session | null>(null)
  const [link, setLink] = useState({ online_url: '', online_platform: '', online_passcode: '', recording_url: '' })
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

  const openLink = (s: Session) => {
    setEditing(s)
    setError(null)
    setLink({ online_url: s.online_url ?? '', online_platform: s.online_platform ?? '', online_passcode: s.online_passcode ?? '', recording_url: s.recording_url ?? '' })
  }
  const saveLink = async () => {
    if (!editing) return
    setError(null)
    try {
      await api.put(`/admin/sessions/${editing.id}`, Object.fromEntries(Object.entries(link).map(([k, v]) => [k, v || null])))
      setEditing(null)
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
            {can('attendance.manage') && s.mode !== 'online' && <Button size="sm" variant="gold" icon={<QrCode className="size-4" />} onClick={() => window.open(`/admin/sessions/${s.id}/qr`, '_blank')}>{t('admin.programs.qr')}</Button>}
            {can('programs.manage') && s.mode === 'online' && <Button size="sm" variant="outline" icon={<Video className="size-4" />} onClick={() => openLink(s)}>{t('studio.sessions.link')}</Button>}
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
          <Field label={t('studio.sessions.mode')}><select className="input" value={form.mode} onChange={(e) => setForm({ ...form, mode: e.target.value })}><option value="in_person">{t('studio.sessions.inPerson')}</option><option value="online">{t('studio.sessions.online')}</option></select></Field>
          {form.mode === 'in_person' && <Field label="Room"><select className="input" value={form.training_room_id} onChange={(e) => setForm({ ...form, training_room_id: e.target.value })}><option value="">—</option>{lookups.data?.data.rooms.map((x) => <option key={x.id} value={x.id}>{nm(x)}</option>)}</select></Field>}
          {form.mode === 'in_person' ? <Field label="Location" className="sm:col-span-2"><input className="input" value={form.location_text} onChange={(e) => setForm({ ...form, location_text: e.target.value })} /></Field> : (
            <>
              <Field label={t('studio.sessions.url')} hint={t('studio.sessions.urlHint')} className="sm:col-span-2"><input className="input" dir="ltr" placeholder="https://" value={form.online_url} onChange={(e) => setForm({ ...form, online_url: e.target.value })} /></Field>
              <Field label={t('studio.delivery.passcode')}><input className="input font-mono" dir="ltr" value={form.online_passcode} onChange={(e) => setForm({ ...form, online_passcode: e.target.value })} /></Field>
            </>
          )}
        </div>
        {error && <p className="mt-3 text-sm text-danger">{error}</p>}
        <div className="mt-5 flex justify-end"><Button variant="gold" onClick={save}>{t('common.save')}</Button></div>
      </Modal>

      <Modal open={!!editing} onClose={() => setEditing(null)} title={t('studio.sessions.linkTitle')}>
        <div className="grid gap-3">
          <Field label={t('studio.sessions.url')}><input className="input" dir="ltr" placeholder="https://" value={link.online_url} onChange={(e) => setLink({ ...link, online_url: e.target.value })} /></Field>
          <div className="grid gap-3 sm:grid-cols-2">
            <Field label={t('studio.delivery.platform')}><select className="input" value={link.online_platform} onChange={(e) => setLink({ ...link, online_platform: e.target.value })}><option value="">—</option>{(['zoom', 'teams', 'meet', 'webex', 'other'] as const).map((p) => <option key={p} value={p}>{t(`studio.delivery.platforms.${p}`)}</option>)}</select></Field>
            <Field label={t('studio.delivery.passcode')}><input className="input font-mono" dir="ltr" value={link.online_passcode} onChange={(e) => setLink({ ...link, online_passcode: e.target.value })} /></Field>
          </div>
          <Field label={t('studio.sessions.recording')} hint={t('studio.sessions.recordingHint')}><input className="input" dir="ltr" placeholder="https://" value={link.recording_url} onChange={(e) => setLink({ ...link, recording_url: e.target.value })} /></Field>
        </div>
        {error && <p className="mt-3 text-sm text-danger">{error}</p>}
        <div className="mt-5 flex justify-end"><Button variant="gold" onClick={saveLink}>{t('common.save')}</Button></div>
      </Modal>

      {attendanceFor && <AttendanceModal session={attendanceFor} onClose={() => setAttendanceFor(null)} />}
    </Card>
  )
}

function AttendanceModal({ session, onClose }: { session: Session; onClose: () => void }) {
  const { t } = useTranslation()
  const { can } = useAuth()
  const { data, isLoading, refetch } = useGet<{ data: Report }>(`/admin/sessions/${session.id}/attendance`, undefined, { staleTime: 0 })
  const trainers = useGet<{ data: TrainerRow[] }>(`/admin/sessions/${session.id}/trainer-attendance`, undefined, { staleTime: 0 })
  const [payload, setPayload] = useState('')
  const [leaveFor, setLeaveFor] = useState<Report['rows'][number] | null>(null)
  const [leave, setLeave] = useState({ type: 'early_leave', minutes: 15, reason: '' })
  const mark = async (registration_id: string, status: string) => {
    try { await api.post(`/admin/sessions/${session.id}/attendance`, { registration_id, status }); await refetch() } catch (e) { toast(errorMessage(e), 'error') }
  }
  const scan = async () => {
    try { const { data: r } = await api.post(`/admin/sessions/${session.id}/staff-scan`, { payload: payload.trim() }); toast(t('ops.session.scanned', { name: r.data.name, action: r.data.action === 'check_in' ? t('ops.session.checkIn') : t('ops.session.checkOut') })); setPayload(''); await Promise.all([refetch(), trainers.refetch()]) } catch (e) { toast(errorMessage(e), 'error') }
  }
  const saveLeave = async () => {
    if (!leaveFor?.attendance_id) return
    try { await api.post(`/admin/attendance/${leaveFor.attendance_id}/leaves`, leave); toast(t('ops.session.leaveSaved')); setLeaveFor(null); await refetch() } catch (e) { toast(errorMessage(e), 'error') }
  }
  const participate = async (registration_id: string, participated: boolean) => {
    try { await api.put(`/admin/sessions/${session.id}/participation`, { marks: [{ registration_id, participated }] }); await refetch() } catch (e) { toast(errorMessage(e), 'error') }
  }
  const markTrainer = async (trainer_id: string, status: 'present' | 'absent') => {
    try { await api.post(`/admin/sessions/${session.id}/trainer-attendance`, { trainer_id, status }); await trainers.refetch() } catch (e) { toast(errorMessage(e), 'error') }
  }
  const r = data?.data
  return (
    <Modal open onClose={onClose} title={`${t('admin.menu.attendance')} — ${session.title}`} wide>
      {isLoading || !r ? <Spinner /> : (
        <>
          <div className="mb-4 flex flex-wrap items-center gap-2">
            <Button size="sm" variant="gold" icon={<PenLine className="size-4" />} onClick={() => window.open(`/kiosk/sessions/${session.id}`, '_blank')}>{t('ops.session.kiosk')}</Button>
            <Button size="sm" variant="outline" icon={<FileSpreadsheet className="size-4" />} onClick={() => downloadFile(`/admin/sessions/${session.id}/attendance/export?format=xlsx`, 'attendance.xlsx')}>{t('ops.session.xlsx')}</Button>
            <Button size="sm" variant="outline" icon={<FileText className="size-4" />} onClick={() => downloadFile(`/admin/sessions/${session.id}/attendance/export?format=pdf`, 'attendance.pdf', true)}>{t('ops.session.pdf')}</Button>
            <Button size="sm" variant="outline" icon={<FileText className="size-4" />} onClick={() => downloadFile(`/admin/sessions/${session.id}/sheet.pdf`, 'sheet.pdf', true)}>{t('ops.session.blank')}</Button>
          </div>
          <div className="mb-4 grid grid-cols-4 gap-2 text-center text-sm">
            {([['expected', r.expected], ['present', r.present], ['late', r.late], ['absent', r.absent]] as const).map(([k, v]) => (
              <div key={k} className="rounded-xl bg-ivory p-2"><div className="text-xl font-bold text-navy-900">{v}</div><div className="text-xs text-slate-500">{k === 'expected' ? t('admin.qr.expected') : t(`status.${k}`)}</div></div>
            ))}
          </div>
          <div className="mb-4 flex gap-2"><input className="input min-w-0 flex-1 font-mono text-xs" dir="ltr" placeholder={t('ops.session.staffScanHint')} value={payload} onChange={(e) => setPayload(e.target.value)} /><Button variant="outline" disabled={!payload.trim()} onClick={() => void scan()}>{t('ops.session.scan')}</Button></div>
          <div className="max-h-80 overflow-y-auto">
            <Table head={[t('common.name'), t('common.status'), t('ops.session.method'), 'In', 'Out', t('passing.marks'), '']}>
              {r.rows.map((row) => (
                <tr key={row.registration_id}>
                  <Td><div className="font-semibold">{row.name}</div><div className="text-xs text-slate-400">{row.school}</div></Td>
                  <Td><StatusBadge status={row.status} />{(row.leave_minutes ?? 0) > 0 && <span className="ms-1 text-[11px] text-amber-700">−{row.leave_minutes}′</span>}</Td>
                  <Td className="text-xs">{row.method ? t(`ops.session.methods.${row.method}`, { defaultValue: row.method }) : '—'}</Td>
                  <Td className="text-xs">{fmt.time(row.check_in_at)}</Td>
                  <Td className="text-xs">{fmt.time(row.check_out_at)}</Td>
                  <Td>{row.attendance_id && <input type="checkbox" className="accent-gold-600" aria-label={t('passing.participated')} checked={!!row.participated} onChange={(e) => void participate(row.registration_id, e.target.checked)} />}</Td>
                  <Td>
                    <div className="flex gap-1">
                      <select className="input py-1 text-xs" value="" onChange={(e) => e.target.value && mark(row.registration_id, e.target.value)}>
                        <option value="">{t('common.edit')}</option>
                        {['present', 'late', 'absent', 'excused'].map((x) => <option key={x} value={x}>{t(`status.${x}`)}</option>)}
                      </select>
                      {can('leaves.manage') && row.attendance_id && row.minutes > 0 && <Button size="sm" variant="ghost" onClick={() => setLeaveFor(row)}>{t('ops.session.leave')}</Button>}
                    </div>
                  </Td>
                </tr>
              ))}
            </Table>
          </div>
          <div className="mt-5">
            <h4 className="mb-2 font-bold text-navy-900">{t('ops.session.trainers')}</h4>
            {(trainers.data?.data ?? []).map((tr) => (
              <div key={tr.trainer_id} className="flex flex-wrap items-center gap-3 rounded-xl bg-ivory px-3 py-2 text-sm">
                <span className="min-w-0 flex-1 font-semibold text-navy-900">{tr.name}</span><span className="text-xs text-slate-500">{fmt.time(tr.check_in_at)} – {fmt.time(tr.check_out_at)} · {tr.minutes}′ · {tr.method ? t(`ops.session.methods.${tr.method}`, { defaultValue: tr.method }) : '—'}</span>
                <Button size="sm" variant="outline" onClick={() => void markTrainer(tr.trainer_id, 'present')}>{t('ops.session.present')}</Button><Button size="sm" variant="ghost" onClick={() => void markTrainer(tr.trainer_id, 'absent')}>{t('ops.session.absent')}</Button>
              </div>
            ))}
          </div>
          <Modal open={!!leaveFor} onClose={() => setLeaveFor(null)} title={`${t('ops.session.leaveTitle')} — ${leaveFor?.name ?? ''}`}>
            <div className="space-y-4">
              <Field label={t('ops.session.leaveType')}><select className="input" value={leave.type} onChange={(e) => setLeave({ ...leave, type: e.target.value })}>{['late_arrival', 'early_leave', 'temporary'].map((x) => <option key={x} value={x}>{t(`ops.session.leaveTypes.${x}`)}</option>)}</select></Field>
              <Field label={t('ops.session.minutes')}><input type="number" min={1} className="input" value={leave.minutes} onChange={(e) => setLeave({ ...leave, minutes: Number(e.target.value) })} /></Field>
              <Field label={t('ops.session.reason')}><input className="input" value={leave.reason} onChange={(e) => setLeave({ ...leave, reason: e.target.value })} /></Field>
              <Button variant="gold" onClick={() => void saveLeave()}>{t('common.save')}</Button>
            </div>
          </Modal>
        </>
      )}
    </Modal>
  )
}
