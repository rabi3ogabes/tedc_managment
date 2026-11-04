import { ArrowLeft, Crown, Loader2, Lock, LockOpen } from 'lucide-react'
import { useState } from 'react'
import { useTranslation } from 'react-i18next'
import { api, errorMessage } from '@/lib/api'

type Leader = { key: string; group: string; name: string }

/**
 * The leadership accounts of the showcase (system administrator, head of the training centre, of training, of planning), behind the showcase
 * code. They see the platform's real data, so unlike the other showcase accounts they open only after the right code is typed; the
 * administrator makes the code in Settings → Showcase accounts. The code stays in this component and goes with each tap.
 */
export default function ShowcaseLeadership({ onSignedIn }: { onSignedIn: (data: unknown) => void }) {
  const { t } = useTranslation()
  const [code, setCode] = useState('')
  const [leaders, setLeaders] = useState<Leader[] | null>(null)
  const [busy, setBusy] = useState<string | null>(null)
  const [error, setError] = useState<string | null>(null)

  const unlock = async (e: React.FormEvent) => {
    e.preventDefault()
    if (!code.trim()) return
    setBusy('unlock'); setError(null)
    try { const { data } = await api.post('/public/showcase-accounts/leadership', { code }); setLeaders(data.data as Leader[]) } catch (err) { setError(errorMessage(err)) } finally { setBusy(null) }
  }
  const open = async (key: string) => {
    setBusy(key); setError(null)
    try { const { data } = await api.post(`/public/showcase-accounts/leadership/${key}/sign-in`, { code }); onSignedIn(data) } catch (err) { setError(errorMessage(err)); setBusy(null) }
  }

  return (
    <div className="mt-4 rounded-2xl bg-white/70 p-3.5 ring-1 ring-navy-100">
      <div className="flex items-center gap-2 text-sm font-extrabold text-navy-900">{leaders ? <LockOpen className="size-4 text-emerald-600" /> : <Lock className="size-4 text-gold-600" />}{t('auth.showcase.leadersTitle')}</div>
      {leaders === null ? (
        <>
          <p className="mt-1 text-xs leading-relaxed text-slate-500">{t('auth.showcase.leadersHint')}</p>
          <form onSubmit={unlock} className="mt-3 flex gap-2">
            <input className="input !py-2 text-sm uppercase tracking-widest" dir="ltr" autoComplete="off" autoCapitalize="characters" spellCheck={false} placeholder={t('auth.showcase.codePlaceholder')} value={code} onChange={(e) => setCode(e.target.value)} aria-label={t('auth.showcase.codePlaceholder')} />
            <button type="submit" disabled={busy !== null || !code.trim()} className="inline-flex shrink-0 items-center gap-1.5 rounded-xl bg-navy-900 px-4 text-sm font-bold text-white transition hover:bg-navy-800 disabled:opacity-50">{busy === 'unlock' ? <Loader2 className="size-4 animate-spin" /> : <LockOpen className="size-4" />}{t('auth.showcase.unlock')}</button>
          </form>
        </>
      ) : (
        <div className="mt-3 grid gap-2 sm:grid-cols-2">
          {leaders.map((a) => (
            <button key={a.key} type="button" disabled={busy !== null} aria-busy={busy === a.key} onClick={() => void open(a.key)} className="group flex items-center gap-3 rounded-2xl bg-white px-3 py-2.5 text-start ring-1 ring-navy-100 transition hover:-translate-y-0.5 hover:shadow-sm hover:ring-gold-400 focus-visible:outline-2 focus-visible:outline-gold-400 disabled:cursor-wait disabled:opacity-60">
              <span className="grid size-9 shrink-0 place-items-center rounded-xl bg-navy-900 text-gold-300"><Crown className="size-4" /></span>
              <span className="min-w-0 flex-1"><span className="block text-sm font-bold leading-snug text-navy-900">{a.name}</span><span className="block text-[11px] text-slate-500">{t('auth.showcase.leadership')}</span></span>
              {busy === a.key ? <Loader2 className="size-4 shrink-0 animate-spin text-gold-600" /> : <ArrowLeft className="size-4 shrink-0 text-slate-300 transition group-hover:text-gold-600 ltr:rotate-180" />}
            </button>
          ))}
        </div>
      )}
      {error && <p className="mt-2 rounded-xl bg-red-50 p-2.5 text-xs font-semibold text-danger">{error}</p>}
    </div>
  )
}
