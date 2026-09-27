import { Download, Search } from 'lucide-react'
import { useState } from 'react'
import { useTranslation } from 'react-i18next'
import { Button, Card, Empty, PageHeader, Spinner, StatusBadge, Table, Td } from '@/components/ui'
import { useGet } from '@/hooks/useApi'
import { api, downloadFile } from '@/lib/api'
import { useAuth } from '@/lib/auth'
import { fmt } from '@/lib/format'
import type { Certificate, Paginated } from '@/lib/types'

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
        {isLoading ? <Spinner /> : !data?.data.length ? <Empty /> : (
          <Table head={[t('admin.certificates.number'), t('verify.participant'), t('verify.program'), t('verify.hours'), t('verify.issuedAt'), t('common.status'), '']}>
            {data.data.map((c) => (
              <tr key={c.id}>
                <Td><span className="font-mono text-xs" dir="ltr">{c.certificate_no}</span></Td>
                <Td className="font-semibold">{c.employee?.name}</Td>
                <Td className="text-sm">{c.program?.title}</Td>
                <Td>{fmt.number(c.hours)}</Td>
                <Td className="text-sm">{fmt.date(c.issued_at)}</Td>
                <Td><StatusBadge status={c.status} /></Td>
                <Td>
                  <div className="flex gap-1">
                    <Button size="sm" variant="outline" icon={<Download className="size-4" />} onClick={() => downloadFile(`/certificates/${c.id}/download`, `${c.certificate_no}.pdf`, true)}>PDF</Button>
                    {can('certificates.revoke') && c.status === 'valid' && <Button size="sm" variant="ghost" onClick={() => revoke(c)}>{t('admin.certificates.revoke')}</Button>}
                  </div>
                </Td>
              </tr>
            ))}
          </Table>
        )}
        {data?.meta && data.meta.last_page > 1 && (
          <div className="flex justify-center gap-2 border-t border-navy-100 p-4">
            <Button variant="outline" size="sm" disabled={page <= 1} onClick={() => setPage(page - 1)}>{t('common.previous')}</Button>
            <Button variant="outline" size="sm" disabled={page >= data.meta.last_page} onClick={() => setPage(page + 1)}>{t('common.next')}</Button>
          </div>
        )}
      </Card>
    </>
  )
}
