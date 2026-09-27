import { BadgeCheck, SearchCheck, ShieldAlert, ShieldX } from 'lucide-react'
import { useEffect, useState } from 'react'
import { useTranslation } from 'react-i18next'
import { useNavigate, useParams } from 'react-router-dom'
import { PageHero } from '@/components/public/Section'
import { Button, Card } from '@/components/ui'
import { api } from '@/lib/api'
import { fmt } from '@/lib/format'

type Result = { valid: boolean; status?: string; certificate_no?: string; participant?: { ar: string; en: string }; program?: { ar: string; en: string }; hours?: number; issued_at?: string }

export default function Verify() {
  const { t, i18n } = useTranslation()
  const params = useParams()
  const navigate = useNavigate()
  const [code, setCode] = useState(params.code ?? '')
  const [result, setResult] = useState<Result | null>(null)
  const [loading, setLoading] = useState(false)
  const lang = i18n.language as 'ar' | 'en'

  const verify = async (value: string) => {
    if (!value.trim()) return
    setLoading(true)
    try {
      const { data } = await api.get(`/public/certificates/verify/${encodeURIComponent(value.trim())}`)
      setResult(data.data)
    } catch {
      setResult({ valid: false })
    } finally {
      setLoading(false)
    }
  }

  useEffect(() => {
    if (params.code) verify(params.code)
  }, [params.code])

  return (
    <>
      <PageHero title={t('verify.title')} subtitle={t('verify.subtitle')} />
      <section className="py-16">
        <div className="container-x max-w-3xl">
          <Card>
            <form className="flex flex-col gap-3 sm:flex-row" onSubmit={(e) => { e.preventDefault(); navigate(`/verify/${code.trim()}`); verify(code) }}>
              <input className="input text-center font-mono text-lg tracking-widest sm:text-start" dir="ltr" value={code} onChange={(e) => setCode(e.target.value.toUpperCase())} placeholder={t('verify.placeholder')} />
              <Button variant="gold" size="lg" loading={loading} icon={<SearchCheck className="size-5" />}>{t('verify.button')}</Button>
            </form>
          </Card>

          {result && (
            <div className="mt-8 animate-fade-up">
              {result.valid ? (
                <div className="relative overflow-hidden rounded-3xl bg-navy-900 p-8 text-white shadow-glass">
                  <div className="pattern-bg absolute inset-0 opacity-25" />
                  <div className="relative">
                    <div className="flex items-center gap-3 text-emerald-300"><BadgeCheck className="size-8" /><span className="text-xl font-bold">{t('verify.valid')}</span></div>
                    <dl className="mt-8 grid gap-6 sm:grid-cols-2">
                      <Row label={t('verify.participant')} value={result.participant?.[lang]} />
                      <Row label={t('verify.program')} value={result.program?.[lang]} />
                      <Row label={t('verify.hours')} value={`${fmt.number(result.hours)} ${t('common.hours')}`} />
                      <Row label={t('verify.issuedAt')} value={fmt.date(result.issued_at)} />
                      <Row label={t('verify.number')} value={result.certificate_no} mono />
                    </dl>
                  </div>
                </div>
              ) : (
                <div className="flex items-center gap-4 rounded-3xl border border-red-200 bg-red-50 p-6 text-red-700">
                  {result.status === 'revoked' ? <ShieldAlert className="size-8" /> : <ShieldX className="size-8" />}
                  <span className="text-lg font-bold">{result.status === 'revoked' ? t('verify.revoked') : t('verify.invalid')}</span>
                </div>
              )}
            </div>
          )}
        </div>
      </section>
    </>
  )
}

function Row({ label, value, mono }: { label: string; value?: string; mono?: boolean }) {
  return (
    <div>
      <dt className="text-sm text-white/60">{label}</dt>
      <dd className={`mt-1 text-lg font-bold text-gold-300 ${mono ? 'font-mono' : ''}`} dir={mono ? 'ltr' : undefined}>{value ?? '—'}</dd>
    </div>
  )
}
