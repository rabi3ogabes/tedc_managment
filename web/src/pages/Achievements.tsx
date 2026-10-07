/* eslint-disable @typescript-eslint/no-explicit-any */
import clsx from 'clsx'
import { Award, EyeOff, Flame, Gift, Medal, Target, Trophy } from 'lucide-react'
import { useState } from 'react'
import { useTranslation } from 'react-i18next'
import { Badge, Button, Card, Empty, ErrorState, PageHeader, Progress, Spinner, Tabs } from '@/components/ui'
import { useGet } from '@/hooks/useApi'
import { api, errorMessage } from '@/lib/api'
import { fmt } from '@/lib/format'
import { toast } from '@/lib/toast'
import { dialogs } from '@/lib/dialogs'

type Tab = 'overview' | 'board' | 'challenges' | 'rewards' | 'badges'
const TIER: Record<string, string> = { bronze: 'bg-amber-100 text-amber-800', silver: 'bg-slate-100 text-slate-700', gold: 'bg-gold-100 text-gold-700' }

/** Points, level, badges, leaderboards, challenges and the rewards shop of the signed-in person. */
export default function Achievements() {
  const { t, i18n } = useTranslation()
  const en = i18n.language === 'en'
  const [tab, setTab] = useState<Tab>('overview')
  const me = useGet<{ data: any }>('/gamification/me', undefined, { staleTime: 0, retry: false })
  if (me.isLoading) return <Spinner />
  if (me.isError || !me.data) return <><PageHeader title={t('soc.gam.title')} /><ErrorState message={String(t('soc.gam.off'))} /></>
  const p = me.data.data
  const next = p.next_level
  const base = p.level.min_points
  const pct = next ? Math.round(((p.earned - base) / Math.max(1, next.min_points - base)) * 100) : 100

  return (
    <>
      <PageHeader title={t('soc.gam.title')} subtitle={t('soc.gam.subtitle')} />
      <div className="mb-6 grid gap-4 md:grid-cols-3">
        <Card className="md:col-span-2">
          <div className="flex flex-wrap items-center gap-5">
            <div className="grid size-20 place-items-center rounded-2xl bg-gradient-to-br from-gold-300 to-gold-600 text-navy-950 shadow-glass"><Trophy className="size-9" /></div>
            <div className="min-w-0 flex-1">
              <p className="text-sm text-slate-500">{t('soc.gam.level')}</p>
              <h2 className="font-display text-2xl font-extrabold text-navy-900">{t('soc.gam.levelN', { n: p.level.no })} · {en ? p.level.name_en : p.level.name_ar}</h2>
              <Progress value={pct} className="mt-3" />
              <p className="mt-1.5 text-xs text-slate-500">{next ? t('soc.gam.toNext', { n: fmt.number(next.points_needed), name: en ? next.name_en : next.name_ar }) : t('soc.gam.maxLevel')}</p>
            </div>
          </div>
        </Card>
        <Card className="flex flex-col justify-center text-center">
          <p className="text-sm text-slate-500">{t('soc.gam.points')}</p>
          <p className="font-display text-5xl font-extrabold text-navy-900" aria-live="polite">{fmt.number(p.points)}</p>
        </Card>
      </div>
      <Tabs<Tab> value={tab} onChange={setTab} tabs={(['overview', 'board', 'challenges', 'rewards', 'badges'] as Tab[]).map((x) => ({ id: x, label: t(`soc.gam.tabs.${x}`) }))} />
      {tab === 'overview' && <Overview p={p} en={en} onPrivacy={async (hidden) => { try { await api.put('/gamification/me/privacy', { hidden }); me.refetch() } catch (e) { toast(errorMessage(e), 'error') } }} />}
      {tab === 'board' && <Board />}
      {tab === 'challenges' && <Challenges en={en} />}
      {tab === 'rewards' && <Rewards en={en} onChanged={() => me.refetch()} />}
      {tab === 'badges' && <Badges en={en} />}
    </>
  )
}

function Overview({ p, en, onPrivacy }: { p: any; en: boolean; onPrivacy: (hidden: boolean) => void }) {
  const { t } = useTranslation()
  return (
    <div className="grid gap-5 lg:grid-cols-2">
      <Card>
        <h3 className="mb-3 font-display text-lg font-bold text-navy-900">{t('soc.gam.recent')}</h3>
        {p.recent.length === 0 ? <Empty text={String(t('soc.gam.noRecent'))} /> : (
          <ul className="divide-y divide-navy-50">
            {p.recent.map((r: any, i: number) => (
              <li key={i} className="flex items-center justify-between py-2.5 text-sm">
                <span>{t(`soc.gam.events.${r.event}`, { defaultValue: r.event })}{r.note && <span className="text-xs text-slate-400"> · {r.note}</span>}</span>
                <span className="flex items-center gap-3"><span className="text-xs text-slate-400">{fmt.dateTime(r.created_at)}</span><span className={clsx('font-bold', r.points >= 0 ? 'text-emerald-600' : 'text-danger')}>{r.points > 0 ? '+' : ''}{r.points}</span></span>
              </li>
            ))}
          </ul>
        )}
      </Card>
      <div className="space-y-5">
        <Card>
          <h3 className="mb-3 font-display text-lg font-bold text-navy-900">{t('soc.gam.badges')}</h3>
          {p.badges.length === 0 ? <Empty text={String(t('soc.gam.noBadges'))} /> : <div className="flex flex-wrap gap-3">{p.badges.map((b: any) => <BadgeChip key={b.id} b={b} en={en} />)}</div>}
        </Card>
        <Card>
          <label className="flex cursor-pointer items-start gap-3">
            <input type="checkbox" className="mt-1 accent-gold-600" checked={p.hidden} onChange={(e) => onPrivacy(e.target.checked)} />
            <span><span className="flex items-center gap-1.5 font-semibold text-navy-900"><EyeOff className="size-4" />{t('soc.gam.hide')}</span><span className="block text-xs text-slate-500">{t('soc.gam.hideHint')}</span></span>
          </label>
        </Card>
      </div>
    </div>
  )
}

function BadgeChip({ b, en, locked }: { b: any; en: boolean; locked?: boolean }) {
  const { t } = useTranslation()
  return (
    <div className={clsx('w-28 text-center', locked && 'opacity-45 grayscale')} title={(en ? b.description_en : b.description_ar) ?? ''}>
      <div className="mx-auto size-16" role="img" aria-label={en ? b.name_en : b.name_ar} dangerouslySetInnerHTML={{ __html: b.icon_svg ?? '' }} />
      <p className="mt-1 text-sm font-semibold leading-tight text-navy-900">{en ? b.name_en : b.name_ar}</p>
      <span className={clsx('mt-1 inline-block rounded-full px-2 py-0.5 text-[10px] font-bold', TIER[b.tier])}>{t(`soc.gam.tier.${b.tier}`)}</span>
    </div>
  )
}

function Badges({ en }: { en: boolean }) {
  const { t } = useTranslation()
  const res = useGet<{ data: any[] }>('/gamification/badges', undefined, { staleTime: 0 })
  if (res.isLoading) return <Spinner />
  return <Card><div className="flex flex-wrap gap-6">{res.data?.data.map((b) => <div key={b.id} className="text-center"><BadgeChip b={b} en={en} locked={!b.earned} /><p className="mt-1 text-[11px] text-slate-400">{t(b.earned ? 'soc.gam.earned' : 'soc.gam.locked')}</p></div>)}</div></Card>
}

function Board() {
  const { t } = useTranslation()
  const [period, setPeriod] = useState('month')
  const [scope, setScope] = useState('ministry')
  const res = useGet<{ data: { rows: any[]; me: any } }>('/gamification/leaderboard', { period, scope }, { staleTime: 0 })
  const rows = res.data?.data.rows ?? []
  return (
    <div>
      <div className="mb-4 flex flex-wrap gap-2">
        <select className="input w-auto" value={period} onChange={(e) => setPeriod(e.target.value)}>{['week', 'month', 'term'].map((x) => <option key={x} value={x}>{t(`soc.gam.period.${x}`)}</option>)}</select>
        <select className="input w-auto" value={scope} onChange={(e) => setScope(e.target.value)}>{['ministry', 'school'].map((x) => <option key={x} value={x}>{t(`soc.gam.scope.${x}`)}</option>)}</select>
        {res.data?.data.me && <Badge color="gold" className="self-center">{t('soc.gam.yourRank', { n: res.data.data.me.rank })}</Badge>}
      </div>
      {res.isLoading ? <Spinner /> : rows.length === 0 ? <Empty text={String(t('soc.gam.noBoard'))} icon={<Medal className="size-8" />} /> : (
        <Card padded={false}>
          <ol>
            {rows.map((r) => (
              <li key={r.user_id} className={clsx('flex items-center gap-4 border-b border-navy-50 px-5 py-3 last:border-0', r.me && 'bg-gold-50')}>
                <span className={clsx('grid size-8 place-items-center rounded-full text-sm font-bold', r.rank === 1 ? 'bg-gold-400 text-navy-950' : r.rank <= 3 ? 'bg-navy-100 text-navy-900' : 'text-slate-500')}>{r.rank}</span>
                <span className="flex-1 font-semibold text-navy-900">{r.me ? `${r.name ?? ''} (${t('soc.gam.you')})` : (r.name ?? '—')}</span>
                <span className="font-display text-lg font-bold text-navy-900">{fmt.number(r.points)}</span>
              </li>
            ))}
          </ol>
        </Card>
      )}
    </div>
  )
}

function Challenges({ en }: { en: boolean }) {
  const { t } = useTranslation()
  const res = useGet<{ data: any[] }>('/gamification/challenges', undefined, { staleTime: 0 })
  const join = async (id: string) => { try { await api.post(`/gamification/challenges/${id}/join`); toast(String(t('soc.gam.joinedOk'))); res.refetch() } catch (e) { toast(errorMessage(e), 'error') } }
  if (res.isLoading) return <Spinner />
  if (!res.data?.data.length) return <Empty text={String(t('soc.gam.noChallenges'))} icon={<Target className="size-8" />} />
  return (
    <div className="grid gap-4 md:grid-cols-2">
      {res.data.data.map((c) => {
        const goal = c.goal?.count ?? 1
        return (
          <Card key={c.id}>
            <div className="mb-2 flex items-start justify-between gap-2"><h3 className="font-display text-lg font-bold text-navy-900">{en ? c.title_en : c.title_ar}</h3>{c.completed ? <Badge color="green">{t('soc.gam.done')}</Badge> : c.closed ? <Badge color="gray">{t('soc.gam.closedC')}</Badge> : c.joined ? <Badge color="gold">{t('soc.gam.joined')}</Badge> : null}</div>
            <p className="text-sm text-slate-500">{en ? c.description_en : c.description_ar}</p>
            <p className="mt-3 text-xs text-slate-500">{t('soc.gam.goal')}: {goal} × {t(`soc.gam.events.${c.goal?.event}`, { defaultValue: c.goal?.event })}</p>
            {c.reward && <p className="mt-1 flex items-center gap-1 text-xs font-semibold text-gold-700"><Gift className="size-3.5" />{t('soc.gam.reward')}: {c.reward.points ? t('soc.gam.cost', { n: c.reward.points }) : ''}{c.reward.text ? ` ${c.reward.text}` : ''}</p>}
            {c.joined && <div className="mt-3"><Progress value={Math.min(100, (c.progress / goal) * 100)} /><p className="mt-1 text-xs text-slate-500">{t('soc.gam.progress', { a: c.progress, b: goal })}</p></div>}
            <div className="mt-3 flex items-center justify-between text-xs text-slate-400"><span>{t('soc.gam.ends', { d: fmt.date(c.ends_at) })}</span><span className="inline-flex items-center gap-1"><Flame className="size-3.5" />{t('soc.gam.participants', { n: c.participants })}</span></div>
            {!c.joined && !c.closed && <Button className="mt-3 w-full" variant="gold" onClick={() => join(c.id)}>{t('soc.gam.join')}</Button>}
          </Card>
        )
      })}
    </div>
  )
}

function Rewards({ en, onChanged }: { en: boolean; onChanged: () => void }) {
  const { t } = useTranslation()
  const res = useGet<{ data: any[]; points: number; redemptions: any[] }>('/gamification/rewards', undefined, { staleTime: 0 })
  const redeem = async (r: any) => {
    if (!await dialogs.confirm(String(t('soc.gam.confirmRedeem', { n: r.cost_points })))) return
    try { const { data } = await api.post(`/gamification/rewards/${r.id}/redeem`); toast(String(t('soc.gam.redeemed', { code: data.data.code }))); res.refetch(); onChanged() } catch (e) { toast(errorMessage(e), 'error') }
  }
  if (res.isLoading) return <Spinner />
  return (
    <div className="space-y-6">
      {res.data?.data.length ? (
        <div className="grid gap-4 sm:grid-cols-2 xl:grid-cols-3">
          {res.data.data.map((r) => (
            <Card key={r.id} className="flex flex-col">
              <Award className="mb-2 size-7 text-gold-600" />
              <h3 className="font-display text-lg font-bold text-navy-900">{en ? r.title_en : r.title_ar}</h3>
              <p className="mt-1 flex-1 text-sm text-slate-500">{en ? r.description_en : r.description_ar}</p>
              <p className="mt-3 text-sm font-bold text-navy-900">{t('soc.gam.cost', { n: fmt.number(r.cost_points) })}</p>
              {r.min_level > 1 && <p className="text-xs text-slate-400">{t('soc.gam.needLevel', { n: r.min_level })}</p>}
              <Button className="mt-3" variant="gold" disabled={!r.can_redeem} onClick={() => redeem(r)}>{r.in_stock ? t('soc.gam.redeem') : t('soc.gam.outOfStock')}</Button>
            </Card>
          ))}
        </div>
      ) : <Empty text={String(t('soc.gam.noRewards'))} icon={<Gift className="size-8" />} />}
      {!!res.data?.redemptions.length && (
        <Card padded={false}>
          <h3 className="px-5 pt-4 font-display text-lg font-bold text-navy-900">{t('soc.gam.myRedemptions')}</h3>
          <ul className="divide-y divide-navy-50">{res.data.redemptions.map((x) => <li key={x.id} className="flex items-center justify-between px-5 py-3 text-sm"><span>{en ? x.title_en : x.title_ar}<span className="ms-2 font-mono text-xs text-slate-500">{x.code}</span></span><span className="text-xs text-slate-400">{fmt.date(x.created_at)}</span></li>)}</ul>
        </Card>
      )}
    </div>
  )
}
