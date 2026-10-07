import clsx from 'clsx'
import { Award, Download, Eye, Mail, MailCheck, MailWarning, Search, Send, X } from 'lucide-react'
import { useEffect, useMemo, useState } from 'react'
import { useTranslation } from 'react-i18next'
import { Badge, Button, Card, Modal, PageHeader, StatusBadge } from '@/components/ui'
import { DataView } from '@/components/ui/DataView'
import { useGet } from '@/hooks/useApi'
import { api, downloadFile, errorMessage } from '@/lib/api'
import { useAuth } from '@/lib/auth'
import { fmt } from '@/lib/format'
import type { Certificate, CertificateSendResult, Paginated, Program } from '@/lib/types'
import { Pager } from './shared'
import { dialogs } from '@/lib/dialogs'

type Filters = { q: string; program_id: string; status: string; sent: string; from: string; to: string }
const emptyFilters: Filters = { q: '', program_id: '', status: '', sent: '', from: '', to: '' }

/** "Sent" marker: an icon with the date, or a muted icon when nothing was sent yet. */
function SentMark({ c }: { c: Certificate }) {
  const { t } = useTranslation()
  if (c.is_sent) {
    const title = [t('admin.certificates.sentOn', { date: fmt.dateTime(c.sent_at) }), c.sent_to && t('admin.certificates.sentTo', { email: c.sent_to }), c.sent_count > 1 && t('admin.certificates.sentTimes', { count: c.sent_count })].filter(Boolean).join(' · ')
    return (
      <span className="inline-flex items-center gap-1.5 text-emerald-700" title={title}>
        <MailCheck className="size-4" />
        <span className="text-xs font-semibold">{fmt.date(c.sent_at, { day: 'numeric', month: 'short', year: 'numeric' })}</span>
      </span>
    )
  }
  if (c.send_error) {
    return <span className="inline-flex items-center gap-1.5 text-danger" title={t('admin.certificates.sendFailed', { error: c.send_error })}><MailWarning className="size-4" /><span className="text-xs font-semibold">{t('admin.certificates.notSent')}</span></span>
  }
  return <span className="inline-flex items-center gap-1.5 text-slate-400"><Mail className="size-4" /><span className="text-xs">{t('admin.certificates.notSent')}</span></span>
}

/** Fetches the certificate PDF and shows it in a frame (mounted only while the preview is open). */
function PreviewFrame({ id, title }: { id: string; title: string }) {
  const { t } = useTranslation()
  const [url, setUrl] = useState<string | null>(null)
  const [error, setError] = useState(false)

  useEffect(() => {
    let href: string | null = null
    let cancelled = false
    api.get(`/certificates/${id}/download`, { responseType: 'blob' })
      .then((r) => {
        if (cancelled) return
        href = URL.createObjectURL(r.data as Blob)
        setUrl(href)
      })
      .catch(() => !cancelled && setError(true))
    return () => {
      cancelled = true
      if (href) URL.revokeObjectURL(href)
    }
  }, [id])

  return (
    <div className="overflow-hidden rounded-xl border border-navy-100 bg-ivory">
      {error ? (
        <div className="grid h-[60vh] place-items-center p-6 text-sm text-danger">{t('admin.certificates.previewFailed')}</div>
      ) : url ? (
        <iframe title={title} src={url} className="h-[60vh] w-full" />
      ) : (
        <div className="grid h-[60vh] place-items-center"><div className="size-8 animate-spin rounded-full border-2 border-navy-200 border-t-gold-500" /></div>
      )}
    </div>
  )
}

/** Shows the certificate PDF so it can be checked before sending. */
function PreviewModal({ certificate, onClose, onSend, canSend }: { certificate: Certificate | null; onClose: () => void; onSend: (c: Certificate) => void; canSend: boolean }) {
  const { t } = useTranslation()
  return (
    <Modal open={!!certificate} onClose={onClose} wide title={certificate ? `${certificate.employee?.name ?? ''} · ${certificate.certificate_no}` : ''}>
      {certificate && (
        <>
          <PreviewFrame key={certificate.id} id={certificate.id} title={certificate.certificate_no} />
          <div className="mt-4 flex flex-wrap items-center justify-between gap-3">
            <SentMark c={certificate} />
            <div className="flex gap-2">
              <Button variant="outline" size="sm" icon={<Download className="size-4" />} onClick={() => downloadFile(`/certificates/${certificate.id}/download`, `${certificate.certificate_no}.pdf`)}>PDF</Button>
              {canSend && certificate.status === 'valid' && (
                <Button size="sm" variant="gold" icon={<Send className="size-4" />} onClick={() => onSend(certificate)}>{certificate.is_sent ? t('admin.certificates.resend') : t('admin.certificates.send')}</Button>
              )}
            </div>
          </div>
        </>
      )}
    </Modal>
  )
}

function ResultModal({ result, onClose }: { result: CertificateSendResult | null; onClose: () => void }) {
  const { t } = useTranslation()
  const list = (rows: CertificateSendResult['skipped'], tone: string) => (
    <ul className="mt-2 max-h-48 space-y-1 overflow-y-auto text-sm">
      {rows.map((r) => (
        <li key={r.id} className={clsx('flex items-center justify-between gap-3 rounded-lg px-3 py-1.5', tone)}>
          <span className="truncate">{r.employee ?? r.certificate_no}</span>
          <span className="shrink-0 text-xs">{t(`admin.certificates.reasons.${r.reason}`, { defaultValue: r.reason })}</span>
        </li>
      ))}
    </ul>
  )
  return (
    <Modal open={!!result} onClose={onClose} title={t('admin.certificates.resultTitle')}>
      {result && (
        <div className="space-y-4">
          <div className="flex items-center gap-3 rounded-xl bg-emerald-50 p-4 text-emerald-800"><MailCheck className="size-6" /><span className="font-bold">{t('admin.certificates.resultSent', { count: result.sent })}</span></div>
          {result.skipped.length > 0 && <div><p className="text-sm font-bold text-navy-900">{t('admin.certificates.resultSkipped')} ({result.skipped.length})</p>{list(result.skipped, 'bg-amber-50 text-amber-800')}</div>}
          {result.failed.length > 0 && <div><p className="text-sm font-bold text-danger">{t('admin.certificates.resultFailed')} ({result.failed.length})</p>{list(result.failed, 'bg-red-50 text-danger')}</div>}
          <div className="flex justify-end"><Button onClick={onClose}>{t('admin.certificates.done')}</Button></div>
        </div>
      )}
    </Modal>
  )
}

export default function Certificates() {
  const { t } = useTranslation()
  const { can } = useAuth()
  const [filters, setFilters] = useState<Filters>(emptyFilters)
  const [page, setPage] = useState(1)
  const [selected, setSelected] = useState<string[]>([])
  const [preview, setPreview] = useState<Certificate | null>(null)
  const [result, setResult] = useState<CertificateSendResult | null>(null)
  const [sending, setSending] = useState(false)
  const [error, setError] = useState<string | null>(null)

  const params = useMemo(() => Object.fromEntries(Object.entries(filters).filter(([, v]) => v)), [filters])
  const { data, isLoading, refetch } = useGet<Paginated<Certificate>>('/admin/certificates', { ...params, page })
  const programs = useGet<Paginated<Program>>('/admin/programs', { per_page: 100 })
  const canSend = can('certificates.issue')
  const rows = data?.data ?? []
  const filtered = Object.keys(params).length > 0

  const setFilter = (key: keyof Filters, value: string) => {
    setFilters((f) => ({ ...f, [key]: value }))
    setPage(1)
    setSelected([])
  }

  const run = async (body: Record<string, unknown>, confirmText: string) => {
    if (!await dialogs.confirm(confirmText)) return
    setSending(true)
    setError(null)
    try {
      const res = await api.post<{ data: CertificateSendResult }>('/admin/certificates/send', body)
      setResult(res.data.data)
      setSelected([])
      setPreview(null)
      refetch()
    } catch (e) {
      setError(errorMessage(e))
    } finally {
      setSending(false)
    }
  }
  const sendOne = (c: Certificate) => run({ ids: [c.id], resend: c.is_sent }, c.is_sent ? t('admin.certificates.resendConfirm') : t('admin.certificates.confirmSelected', { count: 1 }))
  const sendSelected = () => run({ ids: selected }, t('admin.certificates.confirmSelected', { count: selected.length }))
  const sendAll = () => run({ all: true, ...params, sent: params.sent ?? 'no' }, t('admin.certificates.confirmAll'))

  const pageIds = rows.map((c) => c.id)
  const allOnPage = pageIds.length > 0 && pageIds.every((id) => selected.includes(id))
  const revoke = async (c: Certificate) => {
    const reason = await dialogs.prompt(t('admin.certificates.reason'))
    if (!reason) return
    await api.post(`/admin/certificates/${c.id}/revoke`, { reason })
    refetch()
  }

  return (
    <>
      <PageHeader title={t('admin.certificates.title')} actions={canSend && (
        <div className="flex flex-wrap gap-2">
          {selected.length > 0 && <Button variant="gold" loading={sending} icon={<Send className="size-4" />} onClick={sendSelected}>{t('admin.certificates.sendSelected')} ({selected.length})</Button>}
          <Button variant="outline" loading={sending} icon={<Send className="size-4" />} onClick={sendAll}>{t('admin.certificates.sendAll')}</Button>
        </div>
      )} />
      <Card padded={false}>
        <div className="grid gap-3 border-b border-navy-100 p-4 sm:grid-cols-2 xl:grid-cols-6">
          <div className="relative xl:col-span-2"><Search className="absolute start-3 top-3 size-4 text-slate-400" /><input className="input ps-9" placeholder={t('admin.certificates.search')} value={filters.q} onChange={(e) => setFilter('q', e.target.value)} /></div>
          <select className="input" aria-label={t('verify.program')} value={filters.program_id} onChange={(e) => setFilter('program_id', e.target.value)}>
            <option value="">{t('admin.certificates.allPrograms')}</option>
            {programs.data?.data.map((p) => <option key={p.id} value={p.id}>{p.title}</option>)}
          </select>
          <select className="input" aria-label={t('common.status')} value={filters.sent} onChange={(e) => setFilter('sent', e.target.value)}>
            <option value="">{t('admin.certificates.allSent')}</option>
            <option value="yes">{t('admin.certificates.sentOnly')}</option>
            <option value="no">{t('admin.certificates.unsentOnly')}</option>
          </select>
          <select className="input" aria-label={t('common.status')} value={filters.status} onChange={(e) => setFilter('status', e.target.value)}>
            <option value="">{t('common.all')}</option>
            <option value="valid">{t('admin.certificates.validOnly')}</option>
            <option value="revoked">{t('admin.certificates.revokedOnly')}</option>
          </select>
          <div className="flex items-center gap-2">
            <input type="date" className="input" aria-label={t('admin.certificates.from')} value={filters.from} onChange={(e) => setFilter('from', e.target.value)} />
            <input type="date" className="input" aria-label={t('admin.certificates.to')} value={filters.to} onChange={(e) => setFilter('to', e.target.value)} />
          </div>
        </div>
        {(filtered || selected.length > 0) && (
          <div className="flex flex-wrap items-center gap-3 border-b border-navy-100 bg-ivory/60 px-4 py-2 text-sm">
            {selected.length > 0 && <Badge color="gold">{t('admin.certificates.selected', { count: selected.length })}</Badge>}
            {filtered && <button type="button" className="inline-flex items-center gap-1 text-xs font-semibold text-slate-500 hover:text-navy-900" onClick={() => { setFilters(emptyFilters); setPage(1); setSelected([]) }}><X className="size-3.5" />{t('admin.certificates.clear')}</button>}
          </div>
        )}
        {error && <div className="m-4 rounded-xl bg-red-50 p-3 text-sm text-danger">{error}</div>}
        <DataView id="admin.certificates" rows={data?.data} total={data?.meta?.total} loading={isLoading} rowKey={(c) => c.id} defaultMode="table" isSelected={(c) => selected.includes(c.id)} columns={[
          ...(canSend ? [{ key: 'select', header: (
            <input type="checkbox" className="accent-gold-600" aria-label={t('admin.certificates.selectPage')} checked={allOnPage} onChange={(e) => setSelected(e.target.checked ? Array.from(new Set([...selected, ...pageIds])) : selected.filter((id) => !pageIds.includes(id)))} />
          ), role: 'select' as const, cell: (c: Certificate) => (
            <input type="checkbox" className="accent-gold-600" aria-label={c.employee?.name} checked={selected.includes(c.id)} onChange={(e) => setSelected(e.target.checked ? [...selected, c.id] : selected.filter((id) => id !== c.id))} />
          ) }] : []),
          { key: 'media', header: '', role: 'media', hideInTable: true, cell: () => <span className="grid size-10 place-items-center rounded-xl bg-navy-900 text-gold-300"><Award className="size-5" /></span> },
          { key: 'employee', header: t('verify.participant'), role: 'title', cell: (c) => c.employee?.name },
          { key: 'program', header: t('verify.program'), role: 'subtitle', cell: (c) => c.program?.title },
          { key: 'no', header: t('admin.certificates.number'), cell: (c) => <span className="font-mono text-xs" dir="ltr">{c.certificate_no}</span> },
          { key: 'hours', header: t('verify.hours'), cell: (c) => fmt.number(c.hours) },
          { key: 'issued', header: t('verify.issuedAt'), cell: (c) => fmt.date(c.issued_at) },
          { key: 'sent', header: t('admin.certificates.sent'), cell: (c) => <SentMark c={c} /> },
          { key: 'status', header: t('common.status'), role: 'badge', cell: (c) => <StatusBadge status={c.status} /> },
          { key: 'actions', header: '', role: 'actions', cell: (c) => (
            <div className="flex flex-wrap gap-1">
              <Button size="sm" variant="outline" icon={<Eye className="size-4" />} onClick={() => setPreview(c)}>{t('admin.certificates.preview')}</Button>
              <Button size="sm" variant="ghost" icon={<Download className="size-4" />} onClick={() => downloadFile(`/certificates/${c.id}/download`, `${c.certificate_no}.pdf`)}>PDF</Button>
              {canSend && c.status === 'valid' && <Button size="sm" variant="ghost" icon={<Send className="size-4" />} onClick={() => sendOne(c)}>{c.is_sent ? t('admin.certificates.resend') : t('admin.certificates.send')}</Button>}
              {can('certificates.revoke') && c.status === 'valid' && <Button size="sm" variant="ghost" onClick={() => revoke(c)}>{t('admin.certificates.revoke')}</Button>}
            </div>
          ) },
        ]} />
        <Pager page={page} last={data?.meta?.last_page} onChange={setPage} />
      </Card>
      <PreviewModal certificate={preview} onClose={() => setPreview(null)} onSend={sendOne} canSend={canSend} />
      <ResultModal result={result} onClose={() => setResult(null)} />
    </>
  )
}
