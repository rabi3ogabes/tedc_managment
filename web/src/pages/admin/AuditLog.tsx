import { useState } from 'react'
import { useTranslation } from 'react-i18next'
import { Badge, Button, Card, PageHeader } from '@/components/ui'
import { DataView } from '@/components/ui/DataView'
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
        <DataView id="admin.audit" rows={data?.data} total={data?.total} loading={isLoading} rowKey={(l) => l.id} columns={[
          { key: 'action', header: t('admin.audit.action'), role: 'badge', cell: (l) => <Badge color={l.action === 'deleted' ? 'red' : l.action === 'created' ? 'green' : 'navy'}>{l.action}</Badge> },
          { key: 'entity', header: t('admin.audit.entity'), role: 'title', cell: (l) => <span className="text-sm">{l.auditable_type?.split('\\').pop() ?? '—'}</span> },
          { key: 'date', header: t('common.date'), role: 'subtitle', className: 'whitespace-nowrap', cell: (l) => <span className="text-xs">{fmt.dateTime(l.created_at)}</span> },
          { key: 'user', header: t('admin.audit.user'), cell: (l) => <span className="text-sm">{l.user?.name ?? 'system'}</span> },
          { key: 'ip', header: t('admin.audit.ip'), cell: (l) => <span className="font-mono text-xs" dir="ltr">{l.ip_address ?? '—'}</span> },
          { key: 'details', header: t('common.details'), cell: (l) => <code className="block max-w-md truncate text-[11px] text-slate-500" dir="ltr">{JSON.stringify(l.new_values ?? l.old_values ?? {})}</code> },
        ]} />
        <div className="flex justify-center gap-2 border-t border-navy-100 p-4">
          <Button variant="outline" size="sm" disabled={page <= 1} onClick={() => setPage(page - 1)}>{t('common.previous')}</Button>
          <Button variant="outline" size="sm" disabled={!data || page >= data.last_page} onClick={() => setPage(page + 1)}>{t('common.next')}</Button>
        </div>
      </Card>
    </>
  )
}
