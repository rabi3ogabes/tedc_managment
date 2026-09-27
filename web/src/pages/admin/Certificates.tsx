import { Award, Download, Search } from 'lucide-react'
import { useState } from 'react'
import { useTranslation } from 'react-i18next'
import { Button, Card, PageHeader, StatusBadge } from '@/components/ui'
import { DataView } from '@/components/ui/DataView'
import { useGet } from '@/hooks/useApi'
import { api, downloadFile } from '@/lib/api'
import { useAuth } from '@/lib/auth'
import { fmt } from '@/lib/format'
import type { Certificate, Paginated } from '@/lib/types'
import { Pager } from './shared'

export default function Certificates() {
  const { t } = useTranslation()
  const { can } = useAuth()
  const [q, setQ] = useState('')
  const [page, setPage] = useState(1)
  const { data, isLoading, refetch } = useGet<Paginated<Certificate>>('/admin/certificates', { q: q || undefined, page })

  const revoke = async (c: Certificate) => {
    const reason = window.prompt(t('admin.certificates.reason'))
    if (!reason) return
    await api.post(`/admin/certificates/${c.id}/revoke`, { reason })
    refetch()
  }

  return (
    <>
      <PageHeader title={t('admin.certificates.title')} />
      <Card padded={false}>
        <div className="border-b border-navy-100 p-4"><div className="relative max-w-md"><Search className="absolute start-3 top-3 size-4 text-slate-400" /><input className="input ps-9" placeholder={t('admin.certificates.number')} value={q} onChange={(e) => setQ(e.target.value)} /></div></div>
        <DataView id="admin.certificates" rows={data?.data} total={data?.meta?.total} loading={isLoading} rowKey={(c) => c.id} defaultMode="cards" columns={[
          { key: 'media', header: '', role: 'media', hideInTable: true, cell: () => <span className="grid size-10 place-items-center rounded-xl bg-navy-900 text-gold-300"><Award className="size-5" /></span> },
          { key: 'employee', header: t('verify.participant'), role: 'title', cell: (c) => c.employee?.name },
          { key: 'program', header: t('verify.program'), role: 'subtitle', cell: (c) => c.program?.title },
          { key: 'no', header: t('admin.certificates.number'), cell: (c) => <span className="font-mono text-xs" dir="ltr">{c.certificate_no}</span> },
          { key: 'hours', header: t('verify.hours'), cell: (c) => fmt.number(c.hours) },
          { key: 'issued', header: t('verify.issuedAt'), cell: (c) => fmt.date(c.issued_at) },
          { key: 'status', header: t('common.status'), role: 'badge', cell: (c) => <StatusBadge status={c.status} /> },
          { key: 'actions', header: '', role: 'actions', cell: (c) => (
            <div className="flex gap-1">
              <Button size="sm" variant="outline" icon={<Download className="size-4" />} onClick={() => downloadFile(`/certificates/${c.id}/download`, `${c.certificate_no}.pdf`, true)}>PDF</Button>
              {can('certificates.revoke') && c.status === 'valid' && <Button size="sm" variant="ghost" onClick={() => revoke(c)}>{t('admin.certificates.revoke')}</Button>}
            </div>
          ) },
        ]} />
        <Pager page={page} last={data?.meta?.last_page} onChange={setPage} />
      </Card>
    </>
  )
}
