import { KeyRound, Search, Trash2, UserPlus } from 'lucide-react'
import { useEffect, useState } from 'react'
import { useTranslation } from 'react-i18next'
import { Badge, Button, Card, Empty, Field, Spinner } from '@/components/ui'
import { useGet } from '@/hooks/useApi'
import { api, errorMessage } from '@/lib/api'
import { fmt } from '@/lib/format'
import { toast } from '@/lib/toast'

type Grant = { id: string; ability: string; expires_at: string | null; expired: boolean; user: { id: string; name: string; email: string } }
type Person = { id: string; name: string; email: string; roles: string[] }
const ABILITIES = ['attendance.mark', 'notifications.send', 'tasks.review', 'kits.assign']

/** Program → Staff & grants: the head of training gives a person the right to mark attendance, notify trainees, review tasks or assign kits for this program only. */
export default function StaffTab({ programId }: { programId: string }) {
  const { t } = useTranslation()
  const grants = useGet<{ data: Grant[] }>(`/admin/programs/${programId}/grants`, undefined, { staleTime: 0 })
  const [q, setQ] = useState('')
  const [debounced, setDebounced] = useState('')
  const [person, setPerson] = useState<Person | null>(null)
  const [ability, setAbility] = useState(ABILITIES[0])
  const [expires, setExpires] = useState('')
  const [busy, setBusy] = useState(false)
  useEffect(() => { const id = window.setTimeout(() => setDebounced(q.trim()), 250); return () => window.clearTimeout(id) }, [q])
  const found = useGet<{ data: Person[] }>(debounced.length >= 2 && !person ? '/admin/staff-lookup' : null, { q: debounced }, { retry: false })

  const add = async () => {
    if (!person) return
    setBusy(true)
    try {
      await api.post(`/admin/programs/${programId}/grants`, { user_id: person.id, ability, expires_at: expires || undefined })
      toast(t('grants.added')); setPerson(null); setQ(''); setExpires(''); await grants.refetch()
    } catch (e) { toast(errorMessage(e), 'error') } finally { setBusy(false) }
  }
  const revoke = async (g: Grant) => {
    if (!window.confirm(t('grants.confirmRevoke', { name: g.user.name }))) return
    try { await api.delete(`/admin/programs/${programId}/grants/${g.id}`); toast(t('grants.revoked')); await grants.refetch() } catch (e) { toast(errorMessage(e), 'error') }
  }

  return (
    <div className="space-y-6">
      <Card className="space-y-4">
        <div><h3 className="flex items-center gap-2 font-bold text-navy-900"><KeyRound className="size-4 text-gold-600" />{t('grants.title')}</h3><p className="mt-1 text-sm text-slate-500">{t('grants.hint')}</p></div>
        <div className="grid gap-4 md:grid-cols-[1fr_14rem_11rem_auto] md:items-end">
          <Field label={t('grants.person')}>
            {person ? (
              <div className="flex items-center gap-2 rounded-xl border border-gold-300 bg-gold-50 px-3 py-2"><span className="min-w-0 flex-1 truncate text-sm font-bold">{person.name} <span className="font-normal text-slate-500" dir="ltr">{person.email}</span></span><button type="button" className="text-xs font-bold text-slate-400 hover:text-danger" onClick={() => setPerson(null)}>×</button></div>
            ) : (
              <div className="relative">
                <Search className="pointer-events-none absolute start-3 top-1/2 size-4 -translate-y-1/2 text-slate-400" /><input className="input ps-9" value={q} onChange={(e) => setQ(e.target.value)} placeholder={t('grants.search')} />
                {debounced.length >= 2 && (
                  <ul className="absolute inset-x-0 top-full z-10 mt-1 max-h-60 overflow-auto rounded-xl border border-navy-100 bg-white shadow-lg">
                    {found.data?.data.map((p) => <li key={p.id}><button type="button" onClick={() => setPerson(p)} className="flex w-full flex-col px-3 py-2 text-start hover:bg-ivory"><span className="text-sm font-semibold text-navy-900">{p.name}</span><span className="text-xs text-slate-400">{p.email} · {p.roles.join('، ')}</span></button></li>)}
                    {found.data && found.data.data.length === 0 && <li className="px-3 py-4 text-center text-xs text-slate-400">{t('grants.noPeople')}</li>}
                  </ul>
                )}
              </div>
            )}
          </Field>
          <Field label={t('grants.ability')}><select className="input" value={ability} onChange={(e) => setAbility(e.target.value)}>{ABILITIES.map((a) => <option key={a} value={a}>{t(`grants.abilities.${a}`)}</option>)}</select></Field>
          <Field label={t('grants.expires')}><input type="date" className="input" min={new Date(Date.now() + 86_400_000).toISOString().slice(0, 10)} value={expires} onChange={(e) => setExpires(e.target.value)} /></Field>
          <Button variant="gold" icon={<UserPlus className="size-4" />} loading={busy} disabled={!person} onClick={() => void add()}>{t('grants.grant')}</Button>
        </div>
      </Card>

      <Card padded={false}>
        {grants.isLoading ? <Spinner /> : !grants.data?.data.length ? <Empty text={t('grants.empty')} /> : (
          <ul className="divide-y divide-navy-50">
            {grants.data.data.map((g) => (
              <li key={g.id} className="flex flex-wrap items-center gap-3 p-4">
                <div className="min-w-0 flex-1"><div className="font-bold text-navy-900">{g.user.name}</div><div className="text-xs text-slate-500" dir="ltr">{g.user.email}</div></div>
                <Badge color="navy">{t(`grants.abilities.${g.ability}`)}</Badge>
                <span className={g.expired ? 'text-xs font-bold text-danger' : 'text-xs text-slate-400'}>{g.expires_at ? (g.expired ? t('grants.expired') : t('grants.until', { date: fmt.date(g.expires_at) })) : t('grants.noExpiry')}</span>
                <button type="button" aria-label={t('grants.revoke')} onClick={() => void revoke(g)} className="grid size-9 place-items-center rounded-xl text-slate-400 hover:bg-red-50 hover:text-danger"><Trash2 className="size-4" /></button>
              </li>
            ))}
          </ul>
        )}
      </Card>
    </div>
  )
}
