import { useState } from 'react'
import { useTranslation } from 'react-i18next'
import { Badge, Button, Card, Empty, Field, PageHeader, Spinner } from '@/components/ui'
import { useGet } from '@/hooks/useApi'
import { api, errorMessage } from '@/lib/api'
import { toast } from '@/lib/toast'

type Need = { id: string; skill: { name_ar: string; name_en: string } | null; source: string; status: string; gap: number; explanation_ar: string | null; explanation_en: string | null; manager_note: string | null }
const TONE: Record<string, 'gold' | 'green' | 'red' | 'navy'> = { pending_manager: 'gold', approved: 'green', auto_approved: 'green', rejected: 'red', fulfilled: 'navy' }

/** The employee's own development needs: what the manager approved and what they propose. */
export default function MyNeeds() {
  const { t, i18n } = useTranslation()
  const ar = i18n.language === 'ar'
  const needs = useGet<{ data: Need[] }>('/me/needs', undefined, { staleTime: 0 })
  const skills = useGet<{ data: { id: string; name_ar: string; name_en: string }[] }>('/me/competencies')
  const [skill, setSkill] = useState('')
  const [why, setWhy] = useState('')
  const send = async () => {
    try { await api.post('/me/needs', { skill_id: skill, justification: why }); toast(t('needsHub.my.sent')); setSkill(''); setWhy(''); await needs.refetch() } catch (e) { toast(errorMessage(e), 'error') }
  }
  if (needs.isLoading || !needs.data) return <Spinner />
  return (
    <div className="space-y-6 pb-6">
      <PageHeader title={t('needsHub.my.title')} subtitle={t('needsHub.my.subtitle')} />
      {skills.data && (
        <Card className="space-y-3"><h3 className="font-bold text-navy-900">{t('needsHub.my.declare')}</h3>
          <div className="grid gap-3 sm:grid-cols-[16rem_1fr_auto] sm:items-end"><Field label={t('needsHub.my.skill')}><select className="input" value={skill} onChange={(e) => setSkill(e.target.value)}><option value="" />{skills.data.data.map((s) => <option key={s.id} value={s.id}>{ar ? s.name_ar : s.name_en}</option>)}</select></Field>
            <Field label={t('needsHub.my.why')}><input className="input" value={why} onChange={(e) => setWhy(e.target.value)} /></Field><Button variant="gold" disabled={!skill || !why.trim()} onClick={() => void send()}>{t('needsHub.my.send')}</Button></div></Card>
      )}
      <Card padded={false}>{needs.data.data.length === 0 ? <Empty text={t('needsHub.my.empty')} /> : (
        <ul className="divide-y divide-navy-50">{needs.data.data.map((n) => <li key={n.id} className="flex flex-wrap items-center gap-3 px-5 py-4"><div className="min-w-0 flex-1"><div className="font-bold text-navy-900">{ar ? n.skill?.name_ar : n.skill?.name_en}</div><p className="text-xs text-slate-500">{ar ? n.explanation_ar : n.explanation_en}{n.manager_note && ` — ${n.manager_note}`}</p></div><Badge color="slate">{t(`needsHub.individual.source.${n.source}`)}</Badge><Badge color={TONE[n.status] ?? 'navy'}>{t(`needsHub.individual.status.${n.status}`)}</Badge></li>)}</ul>
      )}</Card>
    </div>
  )
}
