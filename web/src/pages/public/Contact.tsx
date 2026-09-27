import { CheckCircle2, Clock, Mail, MapPin, Phone } from 'lucide-react'
import { useState } from 'react'
import { useTranslation } from 'react-i18next'
import { PageHero } from '@/components/public/Section'
import { Button, Card, Field } from '@/components/ui'
import { api, errorMessage } from '@/lib/api'

export default function Contact() {
  const { t } = useTranslation()
  const [form, setForm] = useState({ name: '', email: '', phone: '', subject: '', message: '' })
  const [state, setState] = useState<{ loading?: boolean; ok?: boolean; error?: string }>({})
  const set = (k: keyof typeof form) => (e: React.ChangeEvent<HTMLInputElement | HTMLTextAreaElement>) => setForm({ ...form, [k]: e.target.value })

  const submit = async (e: React.FormEvent) => {
    e.preventDefault()
    setState({ loading: true })
    try {
      await api.post('/public/contact', form)
      setState({ ok: true })
      setForm({ name: '', email: '', phone: '', subject: '', message: '' })
    } catch (err) {
      setState({ error: errorMessage(err) })
    }
  }

  return (
    <>
      <PageHero title={t('contact.title')} subtitle={t('contact.subtitle')} />
      <section className="py-16">
        <div className="container-x grid gap-8 lg:grid-cols-3">
          <div className="space-y-4">
            {[
              { icon: MapPin, text: t('contact.address') },
              { icon: Phone, text: '+974 4000 0000', ltr: true },
              { icon: Mail, text: 'info@tedc.edu.qa' },
              { icon: Clock, text: t('contact.hours') },
            ].map(({ icon: Icon, text, ltr }) => (
              <Card key={text} className="flex items-center gap-4 !p-5">
                <div className="grid size-12 place-items-center rounded-xl bg-navy-900 text-gold-300"><Icon className="size-5" /></div>
                <span className="font-semibold text-navy-900" dir={ltr ? 'ltr' : undefined}>{text}</span>
              </Card>
            ))}
          </div>
          <Card className="lg:col-span-2">
            {state.ok && <div className="mb-5 flex items-center gap-2 rounded-xl bg-emerald-50 p-4 text-emerald-700"><CheckCircle2 className="size-5" />{t('contact.sent')}</div>}
            <form onSubmit={submit} className="grid gap-4 sm:grid-cols-2">
              <Field label={t('common.name')}><input className="input" required value={form.name} onChange={set('name')} /></Field>
              <Field label={t('common.email')}><input className="input" type="email" required value={form.email} onChange={set('email')} /></Field>
              <Field label={t('common.phone')}><input className="input" value={form.phone} onChange={set('phone')} /></Field>
              <Field label={t('contact.subject')}><input className="input" required value={form.subject} onChange={set('subject')} /></Field>
              <Field label={t('contact.message')} className="sm:col-span-2"><textarea className="input min-h-36" required value={form.message} onChange={set('message')} /></Field>
              {state.error && <p className="text-sm text-danger sm:col-span-2">{state.error}</p>}
              <div className="sm:col-span-2"><Button variant="gold" size="lg" loading={state.loading}>{t('contact.send')}</Button></div>
            </form>
          </Card>
        </div>
      </section>
    </>
  )
}
