import { useTranslation } from 'react-i18next'
import { Button } from '@/components/ui'

/** Pagination footer shared by list pages. */
export function Pager({ page, last, onChange }: { page: number; last?: number; onChange: (p: number) => void }) {
  const { t } = useTranslation()
  if (!last || last <= 1) return null
  return (
    <div className="flex items-center justify-center gap-2 border-t border-navy-100 p-4">
      <Button variant="outline" size="sm" disabled={page <= 1} onClick={() => onChange(page - 1)}>{t('common.previous')}</Button>
      <span className="px-2 py-1.5 font-mono text-sm text-slate-500">{page} / {last}</span>
      <Button variant="outline" size="sm" disabled={page >= last} onClick={() => onChange(page + 1)}>{t('common.next')}</Button>
    </div>
  )
}
