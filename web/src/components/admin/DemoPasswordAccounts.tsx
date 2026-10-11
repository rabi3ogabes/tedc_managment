import { CheckCircle2, RefreshCcw, ShieldAlert } from 'lucide-react'
import { useMemo, useState } from 'react'
import { useTranslation } from 'react-i18next'
import { Link } from 'react-router-dom'
import { Badge, Button, Card } from '@/components/ui'
import { Skeleton } from '@/components/ui/Skeleton'
import { useGet } from '@/hooks/useApi'
import { api } from '@/lib/api'
import { dialogs } from '@/lib/dialogs'
import { fmt } from '@/lib/format'
import { toast } from '@/lib/toast'

type Account = {
  id: string; name: string; name_ar: string | null; email: string; status: string; is_you: boolean; last_login_at: string | null
  roles: { slug: string; name_ar: string; name_en: string }[]
}

/**
 * Settings → Security: accounts whose password is still the shared demo password. A security administrator suspends them
 * or gives them a random password; their own account is never touched here (they change it on their security page).
 */
export default function DemoPasswordAccounts({ guard }: { guard: (fn: () => Promise<void>) => Promise<void> }) {
  const { t, i18n } = useTranslation()
  const [rescan, setRescan] = useState(0)
  const res = useGet<{ data: { accounts: Account[]; demo_mode: boolean } }>('/admin/security/demo-accounts', rescan ? { rescan: 1, n: rescan } : undefined, { staleTime: 0 })
  const [picked, setPicked] = useState<Set<string>>(new Set())
  const [busy, setBusy] = useState<null | 'suspend' | 'reset'>(null)
  const accounts = useMemo(() => res.data?.data.accounts ?? [], [res.data])
  const others = accounts.filter((a) => !a.is_you)
  const ar = i18n.language === 'ar'

  const toggle = (id: string) => setPicked((s) => { const n = new Set(s); if (n.has(id)) n.delete(id); else n.add(id); return n })
  const all = others.length > 0 && others.every((a) => picked.has(a.id))
  // Errors go to the page's step-up guard: it asks for the second factor again when needed, or shows the message.
  const run = (mode: 'suspend' | 'reset') => guard(async () => {
    const ids = [...picked]
    if (!ids.length) return
    if (!(await dialogs.confirm(String(t(mode === 'suspend' ? 'idn.sec.demo.confirmSuspend' : 'idn.sec.demo.confirmReset', { n: ids.length }))))) return
    setBusy(mode)
    try {
      const { data } = await api.post('/admin/security/demo-accounts/neutralise', { mode, user_ids: ids })
      toast(String(t('idn.sec.demo.done', { n: data.data.done })))
      setPicked(new Set())
      await res.refetch()
    } finally {
      setBusy(null)
    }
  })

  return (
    <Card className="mt-5" padded={false}>
      <div className="flex flex-wrap items-start justify-between gap-3 border-b border-navy-100 p-4">
        <div className="flex min-w-0 gap-3">
          <span className={`grid size-10 shrink-0 place-items-center rounded-xl ${accounts.length ? 'bg-amber-50 text-amber-700' : 'bg-emerald-50 text-emerald-700'}`}>
            {accounts.length ? <ShieldAlert className="size-5" aria-hidden /> : <CheckCircle2 className="size-5" aria-hidden />}
          </span>
          <div className="min-w-0">
            <h3 className="font-bold text-navy-900">{t('idn.sec.demo.title')}{accounts.length > 0 && <span className="ms-2 tabular-nums text-amber-700">({accounts.length})</span>}</h3>
            <p className="mt-1 max-w-3xl text-sm text-slate-600">{res.data?.data.demo_mode ? t('idn.sec.demo.demoMode') : t('idn.sec.demo.hint')}</p>
          </div>
        </div>
        <Button size="sm" variant="outline" icon={<RefreshCcw className="size-4" />} loading={res.isFetching} onClick={() => setRescan((n) => n + 1)}>{t('idn.sec.demo.rescan')}</Button>
      </div>

      {res.isLoading ? <div className="space-y-2 p-4"><Skeleton className="h-10" /><Skeleton className="h-10" /></div>
        : !accounts.length ? <p className="p-4 text-sm font-semibold text-emerald-700">{t('idn.sec.demo.none')}</p>
          : (
            <>
              <ul className="max-h-[30rem] divide-y divide-navy-50 overflow-y-auto overscroll-contain">
                {others.length > 0 && (
                  <li className="flex items-center gap-3 bg-ivory/60 px-4 py-2 text-sm">
                    <input id="demo-all" type="checkbox" className="size-4 accent-gold-600" checked={all} onChange={() => setPicked(all ? new Set() : new Set(others.map((a) => a.id)))} />
                    <label htmlFor="demo-all" className="font-semibold text-navy-900">{t('idn.sec.demo.selectAll')}</label>
                  </li>
                )}
                {accounts.map((a) => (
                  <li key={a.id} className="flex flex-wrap items-center gap-x-3 gap-y-1 px-4 py-3">
                    <input type="checkbox" aria-label={a.email} className="size-4 accent-gold-600 disabled:opacity-30" disabled={a.is_you} checked={picked.has(a.id)} onChange={() => toggle(a.id)} />
                    <div className="min-w-0 flex-1">
                      <div className="flex flex-wrap items-center gap-2">
                        <span className="font-semibold text-navy-900">{(ar ? a.name_ar : a.name) || a.name}</span>
                        {a.is_you && <Badge color="gold">{t('idn.sec.demo.you')}</Badge>}
                        <Badge color={a.status === 'suspended' ? 'gray' : 'green'}>{t(`idn.sec.demo.status.${a.status === 'suspended' ? 'suspended' : 'active'}`)}</Badge>
                      </div>
                      <div className="text-xs text-slate-500"><bdi dir="ltr">{a.email}</bdi> · {a.roles.map((r) => (ar ? r.name_ar : r.name_en)).join('، ')}</div>
                    </div>
                    <div className="text-xs text-slate-500">{t('idn.sec.demo.lastLogin')}: {a.last_login_at ? fmt.date(a.last_login_at, { dateStyle: 'medium' }) : t('idn.sec.demo.never')}</div>
                    {a.is_you && <Link to="/admin/security" className="text-xs font-semibold text-link">{t('idn.sec.demo.changeOwn')}</Link>}
                  </li>
                ))}
              </ul>
              {others.length > 0 && (
                <div className="flex flex-wrap justify-end gap-2 border-t border-navy-100 p-4">
                  <Button size="sm" variant="outline" disabled={!picked.size} loading={busy === 'reset'} onClick={() => run('reset')}>{t('idn.sec.demo.reset')}</Button>
                  <Button size="sm" variant="danger" disabled={!picked.size} loading={busy === 'suspend'} onClick={() => run('suspend')}>{t('idn.sec.demo.suspend')}</Button>
                </div>
              )}
            </>
          )}
    </Card>
  )
}
