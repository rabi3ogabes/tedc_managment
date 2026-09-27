import { useState } from 'react'
import { useTranslation } from 'react-i18next'
import { Badge, Button, Card, PageHeader, Spinner, Table, Td } from '@/components/ui'
import { useGet } from '@/hooks/useApi'
import { fmt } from '@/lib/format'
import type { LaravelPage } from '@/lib/types'

type Log = { id: string; action: string; auditable_type?: string; auditable_id?: string; new_values?: Record<string, unknown>; old_values?: Record<string, unknown>; ip_address?: string; created_at: string; user?: { name: string; email: string } }

export default function AuditLog() {
  const { t } = useTranslation()
  const [page, setPage] = useState(1)
  const [action, setAction] = useState('')
  const { data, isLoading } = useGet<LaravelPage<Log>>('/admin/audit-logs', { page, action: action || undefined })
  return (
    <>
      <PageHeader title={t('admin.audit.title')} />
      <Card padded={false}>
        <div className="border-b border-navy-100 p-4">
          <select className="input w-auto" value={action} onChange={(e) => setAction(e.target.value)}>
            <option value="">{t('common.all')}</option>{['created', 'updated', 'deleted', 'roles_changed', 'permissions_changed'].map((a) => <option key={a}>{a}</option>)}
          </select>
        </div>
        {isLoading ? <Spinner /> : (
          <Table head={[t('common.date'), t('admin.audit.user'), t('admin.audit.action'), t('admin.audit.entity'), t('common.details'), t('admin.audit.ip')]}>
            {data?.data.map((l) => (
              <tr key={l.id}>
                <Td className="whitespace-nowrap text-xs">{fmt.dateTime(l.created_at)}</Td>
                <Td className="text-sm">{l.user?.name ?? 'system'}</Td>
                <Td><Badge color={l.action === 'deleted' ? 'red' : l.action === 'created' ? 'green' : 'navy'}>{l.action}</Badge></Td>
                <Td className="text-xs text-slate-500">{l.auditable_type?.split('\\').pop()}</Td>
                <Td><code className="block max-w-md truncate text-[11px] text-slate-500" dir="ltr">{JSON.stringify(l.new_values ?? l.old_values ?? {})}</code></Td>
                <Td className="text-xs" ><span dir="ltr">{l.ip_address}</span></Td>
              </tr>
            ))}
          </Table>
        )}
        <div className="flex justify-center gap-2 border-t border-navy-100 p-4">
          <Button variant="outline" size="sm" disabled={page <= 1} onClick={() => setPage(page - 1)}>{t('common.previous')}</Button>
          <Button variant="outline" size="sm" disabled={!data || page >= data.last_page} onClick={() => setPage(page + 1)}>{t('common.next')}</Button>
        </div>
      </Card>
    </>
  )
}
