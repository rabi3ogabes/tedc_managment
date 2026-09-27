import { Download, Search } from 'lucide-react'
import { useState } from 'react'
import { useTranslation } from 'react-i18next'
import { Button, EligibilityPanel, Field, Modal, Spinner, Table, Td } from '@/components/ui'
import { useGet } from '@/hooks/useApi'
import { api, downloadFile, errorMessage } from '@/lib/api'
import { useAuth } from '@/lib/auth'
import type { Eligibility, Employee } from '@/lib/types'

type NominationResult = { employee_id: string; ok: boolean; status?: string; message?: string; details?: Eligibility }

export function NominateModal({ open, onClose, programId }: { open: boolean; onClose: () => void; programId: string }) {
  const { t } = useTranslation()
  const { can } = useAuth()
  const [q, setQ] = useState('')
  const [selected, setSelected] = useState<Employee[]>([])
  const [justification, setJustification] = useState('')
  const [override, setOverride] = useState(false)
  const [results, setResults] = useState<NominationResult[] | null>(null)
  const [loading, setLoading] = useState(false)
  const candidates = useGet<{ data: { employee: Employee; skill_gap: number }[] }>(open ? `/admin/programs/${programId}/candidates` : null)
  const search = useGet<{ data: Employee[] }>(open && q.length > 1 ? '/admin/employees' : null, { q, per_page: 10 })

  const list = q.length > 1 ? search.data?.data ?? [] : (candidates.data?.data ?? []).map((c) => c.employee)
  const toggle = (e: Employee) => setSelected((s) => (s.some((x) => x.id === e.id) ? s.filter((x) => x.id !== e.id) : [...s, e]))

  const submit = async () => {
    setLoading(true)
    try {
      const { data } = await api.post(`/admin/programs/${programId}/nominations`, { employee_ids: selected.map((s) => s.id), justification, override })
      setResults(data.data)
    } catch (e) {
      setResults([{ employee_id: '', ok: false, message: errorMessage(e) }])
    } finally {
      setLoading(false)
    }
  }
  const close = () => { setResults(null); setSelected([]); onClose() }

  return (
    <Modal open={open} onClose={close} title={t('admin.registrations.nominate')} wide>
      {results ? (
        <div className="space-y-3">
          {results.map((r, i) => {
            const emp = selected.find((s) => s.id === r.employee_id)
            return (
              <div key={i} className="rounded-xl border border-navy-100 p-3">
                <div className="mb-2 font-semibold text-navy-900">{emp?.name} {r.ok && <span className="text-emerald-600">✓ {t(`status.${r.status}`)}</span>}</div>
                {!r.ok && (r.details?.checks ? <EligibilityPanel result={r.details} /> : <p className="text-sm text-danger">{r.message}</p>)}
              </div>
            )
          })}
          <Button onClick={close}>{t('common.close')}</Button>
        </div>
      ) : (
        <>
          <div className="relative mb-3"><Search className="absolute start-3 top-3 size-4 text-slate-400" /><input className="input ps-9" placeholder={t('common.search')} value={q} onChange={(e) => setQ(e.target.value)} /></div>
          {!q && <p className="mb-2 text-xs font-bold text-gold-700">{t('admin.registrations.candidates')}</p>}
          <div className="max-h-72 overflow-y-auto rounded-xl border border-navy-100">
            {candidates.isLoading ? <Spinner /> : (
              <Table head={['', t('common.name'), t('admin.employees.school'), t('admin.employees.jobTitle')]}>
                {list.map((e) => (
                  <tr key={e.id} className="cursor-pointer hover:bg-ivory" onClick={() => toggle(e)}>
                    <Td><input type="checkbox" readOnly className="accent-gold-600" checked={selected.some((s) => s.id === e.id)} /></Td>
                    <Td className="font-semibold">{e.name}</Td><Td className="text-xs">{e.school?.name}</Td><Td className="text-xs">{e.job_title?.name}</Td>
                  </tr>
                ))}
              </Table>
            )}
          </div>
          <Field label={t('admin.registrations.justification')} className="mt-4"><textarea className="input" value={justification} onChange={(e) => setJustification(e.target.value)} /></Field>
          {can('nominations.center') && <label className="mt-3 flex items-center gap-2 text-sm"><input type="checkbox" className="accent-gold-600" checked={override} onChange={(e) => setOverride(e.target.checked)} />{t('admin.registrations.override')}</label>}
          <div className="mt-5 flex justify-end gap-2">
            <Button variant="ghost" onClick={close}>{t('common.cancel')}</Button>
            <Button variant="gold" disabled={!selected.length} loading={loading} onClick={submit}>{t('admin.registrations.nominate')} ({selected.length})</Button>
          </div>
        </>
      )}
    </Modal>
  )
}

export function ImportModal({ open, onClose, programId }: { open: boolean; onClose: () => void; programId: string }) {
  const { t } = useTranslation()
  const { can } = useAuth()
  const [file, setFile] = useState<File | null>(null)
  const [override, setOverride] = useState(false)
  const [report, setReport] = useState<{ imported: number; waitlisted: number; failed: number; rows: { row: number; employee_no: string; status: string; message?: string }[] } | null>(null)
  const [loading, setLoading] = useState(false)
  const [error, setError] = useState<string | null>(null)

  const submit = async () => {
    if (!file) return
    setLoading(true)
    setError(null)
    const body = new FormData()
    body.append('file', file)
    if (override) body.append('override', '1')
    try {
      const { data } = await api.post(`/admin/programs/${programId}/registrations/import`, body)
      setReport(data.data)
    } catch (e) {
      setError(errorMessage(e))
    } finally {
      setLoading(false)
    }
  }
  const close = () => { setReport(null); setFile(null); onClose() }

  return (
    <Modal open={open} onClose={close} title={t('admin.registrations.import')}>
      {report ? (
        <div>
          <div className="grid grid-cols-3 gap-3 text-center">
            <div className="rounded-xl bg-emerald-50 p-3"><div className="text-2xl font-bold text-emerald-700">{report.imported}</div><div className="text-xs">{t('admin.registrations.imported')}</div></div>
            <div className="rounded-xl bg-sky-50 p-3"><div className="text-2xl font-bold text-sky-700">{report.waitlisted}</div><div className="text-xs">{t('admin.registrations.waitlisted')}</div></div>
            <div className="rounded-xl bg-red-50 p-3"><div className="text-2xl font-bold text-red-700">{report.failed}</div><div className="text-xs">{t('admin.registrations.failed')}</div></div>
          </div>
          <ul className="mt-4 max-h-60 space-y-1 overflow-y-auto text-xs">{report.rows.filter((r) => r.status === 'failed').map((r) => <li key={r.row} className="text-danger">{r.message}</li>)}</ul>
          <Button className="mt-4" onClick={close}>{t('common.close')}</Button>
        </div>
      ) : (
        <div className="space-y-4">
          <Button variant="outline" size="sm" icon={<Download className="size-4" />} onClick={() => downloadFile('/admin/registrations/import/template', 'registrations-template.xlsx')}>{t('admin.registrations.template')}</Button>
          <input type="file" accept=".xlsx,.xls,.csv" className="input" onChange={(e) => setFile(e.target.files?.[0] ?? null)} />
          {can('nominations.center') && <label className="flex items-center gap-2 text-sm"><input type="checkbox" className="accent-gold-600" checked={override} onChange={(e) => setOverride(e.target.checked)} />{t('admin.registrations.override')}</label>}
          {error && <p className="text-sm text-danger">{error}</p>}
          <div className="flex justify-end"><Button variant="gold" disabled={!file} loading={loading} onClick={submit}>{t('common.import')}</Button></div>
        </div>
      )}
    </Modal>
  )
}
