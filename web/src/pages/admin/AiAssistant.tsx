import { Bot, Lightbulb, Send, Sparkles, Users } from 'lucide-react'
import { useRef, useState } from 'react'
import { useTranslation } from 'react-i18next'
import { Avatar, Badge, Button, Card, PageHeader, StatusBadge } from '@/components/ui'
import { useGet } from '@/hooks/useApi'
import { api, errorMessage } from '@/lib/api'
import { useAuth } from '@/lib/auth'

type Suggestion = { title_ar: string; title_en: string; target_group: string; seats: number; priority: string; skills: string[]; rationale: string }
type Answer = { answer: string; insights: string[]; suggestions: Suggestion[]; source: 'ai' | 'rules' }
type Turn = { role: 'user' | 'assistant'; content: string; answer?: Answer }

/** Minimal, safe Markdown rendering (bold + line breaks + lists) without injecting HTML. */
function Markdown({ text }: { text: string }) {
  return (
    <div className="space-y-2 leading-relaxed">
      {text.split('\n').filter((l) => l.trim()).map((line, i) => {
        const parts = line.replace(/^[-*]\s+/, '• ').split(/(\*\*[^*]+\*\*)/g)
        return <p key={i}>{parts.map((p, j) => (p.startsWith('**') ? <strong key={j} className="text-navy-900">{p.slice(2, -2)}</strong> : <span key={j}>{p.replace(/^#+\s*/, '')}</span>))}</p>
      })}
    </div>
  )
}

export default function AiAssistant() {
  const { t, i18n } = useTranslation()
  const { user } = useAuth()
  const status = useGet<{ data: { llm_enabled: boolean; model: string | null } }>('/admin/ai/status')
  const [turns, setTurns] = useState<Turn[]>([])
  const [question, setQuestion] = useState('')
  const [loading, setLoading] = useState(false)
  const [error, setError] = useState<string | null>(null)
  const end = useRef<HTMLDivElement>(null)
  const examples = t('admin.ai.examples', { returnObjects: true }) as string[]

  const ask = async (q: string) => {
    if (!q.trim()) return
    setError(null)
    setLoading(true)
    const history = turns.map(({ role, content }) => ({ role, content }))
    setTurns((ts) => [...ts, { role: 'user', content: q }])
    setQuestion('')
    try {
      const { data } = await api.post('/admin/ai/ask', { question: q, history })
      setTurns((ts) => [...ts, { role: 'assistant', content: data.data.answer, answer: data.data }])
    } catch (e) {
      setError(errorMessage(e))
    } finally {
      setLoading(false)
      setTimeout(() => end.current?.scrollIntoView({ behavior: 'smooth' }), 50)
    }
  }

  return (
    <>
      <PageHeader title={t('admin.ai.title')} subtitle={t('admin.ai.subtitle')}
        actions={status.data && <Badge color={status.data.data.llm_enabled ? 'gold' : 'gray'}><Sparkles className="size-3" />{status.data.data.llm_enabled ? `${t('admin.ai.sourceAi')} · ${status.data.data.model}` : t('admin.ai.sourceRules')}</Badge>} />
      <div className="mx-auto max-w-5xl">
        {!turns.length && (
          <div className="relative mb-6 overflow-hidden rounded-3xl bg-navy-900 p-8 text-white shadow-glass">
            <div className="pattern-bg absolute inset-0 opacity-20" />
            <div className="relative flex items-start gap-4">
              <div className="grid size-14 place-items-center rounded-2xl bg-gold-500 text-navy-950"><Bot className="size-7" /></div>
              <div>
                <h2 className="text-2xl font-bold">{t('admin.ai.title')}</h2>
                <p className="mt-1 text-white/70">{t('admin.ai.subtitle')}</p>
                <div className="mt-5 flex flex-wrap gap-2">{examples.map((ex) => <button key={ex} onClick={() => ask(ex)} className="glass-dark rounded-xl px-3 py-2 text-start text-sm hover:bg-white/15">{ex}</button>)}</div>
              </div>
            </div>
          </div>
        )}

        <div className="space-y-6">
          {turns.map((turn, i) => turn.role === 'user' ? (
            <div key={i} className="flex justify-end gap-3">
              <div className="max-w-2xl rounded-2xl rounded-se-sm bg-navy-900 px-4 py-3 text-white">{turn.content}</div>
              <Avatar name={user?.name ?? ''} size={36} />
            </div>
          ) : (
            <div key={i} className="flex gap-3">
              <div className="grid size-9 shrink-0 place-items-center rounded-full bg-gold-500 text-navy-950"><Bot className="size-5" /></div>
              <div className="min-w-0 flex-1 space-y-4">
                <Card className="text-slate-700"><Markdown text={turn.content} /></Card>
                {!!turn.answer?.insights.length && (
                  <Card>
                    <h4 className="mb-3 flex items-center gap-2 font-bold text-navy-900"><Lightbulb className="size-5 text-gold-600" />{t('admin.ai.insights')}</h4>
                    <ul className="space-y-2 text-sm text-slate-600">{turn.answer.insights.map((x) => <li key={x} className="flex gap-2"><span className="mt-2 size-1.5 shrink-0 rounded-full bg-gold-500" />{x}</li>)}</ul>
                  </Card>
                )}
                {!!turn.answer?.suggestions.length && (
                  <div>
                    <h4 className="mb-3 font-bold text-navy-900">{t('admin.ai.suggestions')}</h4>
                    <div className="grid gap-4 md:grid-cols-2">
                      {turn.answer.suggestions.map((s) => (
                        <Card key={s.title_en} className="border-s-4 border-s-gold-500">
                          <div className="flex items-start justify-between gap-2">
                            <h5 className="font-bold text-navy-900">{i18n.language === 'ar' ? s.title_ar : s.title_en}</h5>
                            <StatusBadge status={s.priority} />
                          </div>
                          <div className="mt-3 flex flex-wrap gap-3 text-sm text-slate-600">
                            <span className="flex items-center gap-1"><Users className="size-4 text-gold-600" />{s.seats} {t('admin.ai.seats')}</span>
                            <span>{t('admin.ai.target')}: {s.target_group}</span>
                          </div>
                          <p className="mt-3 text-sm text-slate-500">{s.rationale}</p>
                          <div className="mt-3 flex flex-wrap gap-1">{s.skills.map((k) => <Badge key={k} color="navy">{k}</Badge>)}</div>
                        </Card>
                      ))}
                    </div>
                  </div>
                )}
                <p className="text-xs text-slate-400">{turn.answer?.source === 'ai' ? t('admin.ai.sourceAi') : t('admin.ai.sourceRules')}</p>
              </div>
            </div>
          ))}
          {loading && <div className="flex items-center gap-3 text-sm text-slate-500"><div className="grid size-9 place-items-center rounded-full bg-gold-500 text-navy-950"><Bot className="size-5 animate-pulse" /></div>{t('common.loading')}</div>}
          {error && <p className="text-sm text-danger">{error}</p>}
          <div ref={end} />
        </div>

        <form className="glass sticky bottom-4 mt-8 flex gap-2 rounded-2xl p-2" onSubmit={(e) => { e.preventDefault(); ask(question) }}>
          <input className="flex-1 bg-transparent px-3 outline-none" value={question} onChange={(e) => setQuestion(e.target.value)} placeholder={t('admin.ai.placeholder')} />
          <Button variant="gold" loading={loading} icon={<Send className="size-4 rtl:-scale-x-100" />}>{t('admin.ai.ask')}</Button>
        </form>
      </div>
    </>
  )
}
