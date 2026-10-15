import { KeyRound, Mail } from 'lucide-react'
import { useCallback, useEffect, useState } from 'react'
import { useTranslation } from 'react-i18next'
import { useParams } from 'react-router-dom'
import EngineForm from '@/components/surveys/EngineForm'
import { Button, Card, Field, Spinner } from '@/components/ui'
import { api, errorMessage } from '@/lib/api'
import type { Answers, Question, Settings } from '@/lib/surveyEngine'

type Def = { id: string; title: string; title_en?: string | null; description_ar?: string | null; description_en?: string | null; questions: Question[]; settings: Settings; rule: 'open' | 'device' | 'email_code' | 'invitation'; requires_code: boolean; name?: string | null; retention_days?: number | null
  answered?: boolean; draft?: { answers: Answers; page: number } | null; submission?: { answers: Answers } | null; can_edit?: boolean }

/** A client id kept in this browser so the device rule can tell one device from another without knowing anyone. */
function clientId(): string {
  try { let v = localStorage.getItem('tedc.survey.client'); if (!v) { v = Array.from(crypto.getRandomValues(new Uint8Array(18)), (b) => b.toString(16).padStart(2, '0')).join('').slice(0, 32); localStorage.setItem('tedc.survey.client', v) } return v } catch { return 'nostorage' + Math.random().toString(36).slice(2, 20) }
}

/** The public survey page: `/s/:slug` (public link) or `/s/i/:token` (invitation). No account; the server knows only a pseudonymous key. */
export default function PublicSurvey() {
  const { t, i18n } = useTranslation()
  const { slug, token } = useParams()
  const en = i18n.language === 'en'
  const base = token ? `/public/surveys/i/${token}` : `/public/surveys/${slug}`
  const [def, setDef] = useState<Def | null>(null)
  const [error, setError] = useState<{ code?: string; message: string } | null>(null)
  const [email, setEmail] = useState('')
  const [code, setCode] = useState('')
  const [codeSent, setCodeSent] = useState(false)
  const [respondentToken, setRespondentToken] = useState<string | null>(null)
  const [resume, setResume] = useState<{ draft: Def['draft']; submission: Def['submission']; can_edit: boolean } | null>(null)
  const [busy, setBusy] = useState(false)
  const credential = useCallback(() => (token ? {} : def?.rule === 'email_code' ? { respondent_token: respondentToken } : { client_id: clientId() }), [token, def?.rule, respondentToken])

  useEffect(() => {
    let alive = true
    api.get(base).then(({ data }) => { if (alive) setDef(data.data) }).catch((e) => { if (alive) setError({ code: e?.response?.data?.code, message: errorMessage(e) }) })
    return () => { alive = false }
  }, [base])
  useEffect(() => {
    if (!def || token || def.rule === 'email_code') return
    api.post(`${base}/resume`, credential()).then(({ data }) => setResume(data.data)).catch(() => setResume({ draft: null, submission: null, can_edit: false }))
  }, [def, token, base, credential])

  const verify = async () => {
    setBusy(true)
    try {
      if (!codeSent) { await api.post(`${base}/code`, { email }); setCodeSent(true) } else {
        const { data } = await api.post(`${base}/verify`, { email, code }); setRespondentToken(data.data.respondent_token)
        const r = await api.post(`${base}/resume`, { respondent_token: data.data.respondent_token }); setResume(r.data.data)
      }
    } catch (e) { setError({ code: (e as { response?: { data?: { code?: string } } }).response?.data?.code, message: errorMessage(e) }) } finally { setBusy(false) }
  }

  if (error && !def) return <Shell><div className="py-16 text-center"><h2 className="font-display text-2xl font-bold text-navy-900">{t(`ss.public.errors.${error.code ?? 'generic'}`, { defaultValue: error.message })}</h2></div></Shell>
  if (!def) return <Shell><Spinner /></Shell>
  const needsCode = def.rule === 'email_code' && !respondentToken
  const ready = token ? true : def.rule === 'email_code' ? !!respondentToken && !!resume : !!resume
  const state = token ? { draft: def.draft ?? null, submission: def.submission ?? null, can_edit: !!def.can_edit } : resume

  return (
    <Shell>
      {needsCode ? (
        <div className="mx-auto max-w-md py-10">
          <div className="mb-6 text-center"><div className="mx-auto grid size-14 place-items-center rounded-2xl bg-navy-100 text-navy-900"><Mail className="size-6" /></div><h2 className="mt-4 font-display text-2xl font-bold text-navy-900">{def.title}</h2><p className="mt-2 text-sm text-slate-600">{t('ss.public.codeIntro')}</p></div>
          <Field label={t('ss.public.email')}><input className="input" type="email" dir="ltr" value={email} disabled={codeSent} onChange={(e) => setEmail(e.target.value)} /></Field>
          {codeSent && <Field label={t('ss.public.code')} hint={t('ss.public.codeHint')} className="mt-3"><input className="input text-center font-mono text-lg tracking-[0.4em]" dir="ltr" inputMode="numeric" maxLength={6} value={code} onChange={(e) => setCode(e.target.value.replace(/\D/g, ''))} /></Field>}
          {error && <p className="mt-3 rounded-xl bg-red-50 p-3 text-sm text-red-700">{t(`ss.public.errors.${error.code ?? 'generic'}`, { defaultValue: error.message })}</p>}
          <Button className="mt-5 w-full" variant="gold" icon={<KeyRound className="size-4" />} loading={busy} disabled={!email || (codeSent && code.length !== 6)} onClick={() => { setError(null); verify() }}>{codeSent ? t('ss.public.verify') : t('ss.public.sendCode')}</Button>
          {codeSent && <button type="button" className="mt-3 w-full text-xs text-slate-500 underline" onClick={() => { setCodeSent(false); setCode('') }}>{t('ss.public.changeEmail')}</button>}
        </div>
      ) : !ready ? <Spinner /> : (
        <div className="flex min-h-[80vh] flex-col">
          <EngineForm
            survey={{ title: en && def.title_en ? def.title_en : def.title, description: en ? def.description_en || def.description_ar : def.description_ar, questions: def.questions, settings: def.settings, privacy_mode: 'anonymous', retention_days: def.retention_days }}
            initialAnswers={state?.submission?.answers ?? (def.answered ? {} : null)}
            draft={state?.draft ?? null}
            canEdit={!!state?.can_edit}
            onDraft={async (answers, page) => api.put(`${base}/draft`, { ...credential(), answers, page })}
            onUpload={async (q, file) => { const fd = new FormData(); fd.append('question_id', q.id); fd.append('file', file); Object.entries(credential()).forEach(([k, v]) => v && fd.append(k, String(v))); const { data } = await api.post(`${base}/files`, fd); return data.data }}
            onSubmit={async (answers, duration) => { const { data } = await api.post(base, { ...credential(), answers, duration_seconds: duration }); return data.message }}
          />
        </div>
      )}
    </Shell>
  )
}

function Shell({ children }: { children: React.ReactNode }) {
  return <div className="mx-auto max-w-3xl px-4 pb-16 pt-28"><Card padded={false} className="overflow-hidden">{children}</Card></div>
}
