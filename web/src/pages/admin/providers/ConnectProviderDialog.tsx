import { useState } from 'react'
import { useTranslation } from 'react-i18next'
import { useLang } from '@/components/dashboard/Widget'
import { Button, Field, Modal } from '@/components/ui'
import { api } from '@/lib/api'
import { toast } from '@/lib/toast'
import { useStepUp } from '../SecurityAdmin'
import type { Provider, ProviderField } from './types'

const input = 'w-full rounded-xl border border-navy-100 px-3 py-2 text-sm'

/** The connection settings of one provider. Secrets are never shown: a blank one keeps what is stored. Changing them asks for the second factor again, like the integration card. */
export default function ConnectProviderDialog({ p, onClose, onSaved }: { p: Provider; onClose: () => void; onSaved: () => void }) {
  const { t, i18n } = useTranslation()
  const L = useLang()
  const step = useStepUp()
  const lang = i18n.language === 'en' ? 'en' : 'ar'
  const [driver, setDriver] = useState<string>(p.driver)
  const [enabled, setEnabled] = useState(p.enabled)
  const [values, setValues] = useState<Record<string, string>>(() => Object.fromEntries(p.fields.map((f) => [f.k, f.secret ? '' : String(p.settings[f.k] ?? f.default ?? '')])))
  const [saving, setSaving] = useState(false)
  const training = driver === 'fake'

  const save = () => step.guard(async () => {
    setSaving(true)
    try {
      await api.put(`/admin/learning-providers/${p.key}`, { driver, enabled, settings: values })
      toast(String(t('min.providers.saved')))
      onSaved()
      onClose()
    } finally {
      setSaving(false)
    }
  })

  const label = (f: ProviderField) => String(t(`min.providers.f.${f.k}`, { defaultValue: f.k }))
  const hint = (f: ProviderField) => (f.secret && p.secrets_set[f.k] ? String(t('min.providers.secretSet')) : (f.doc?.[lang] ?? f.hint))

  return (
    <Modal open onClose={onClose} title={t('min.providers.connectTitle', { name: L(p.name) })} wide>
      <p className="mb-4 text-sm text-slate-500">{t('min.providers.connectHint')}</p>
      <div className="grid gap-4 md:grid-cols-2">
        <Field label={t('min.providers.driver.label')}>
          <select className={input} value={driver} onChange={(e) => setDriver(e.target.value)}>
            {p.drivers.map((d) => <option key={d} value={d}>{t(`min.providers.driver.${d}`)}</option>)}
          </select>
        </Field>
        <label className="flex items-center gap-2 pt-7 text-sm">
          <input type="checkbox" className="accent-gold-600" checked={enabled} onChange={(e) => setEnabled(e.target.checked)} />
          {t('min.providers.enabled')}
        </label>
        {!training && p.fields.map((f) => (
          <Field key={f.k} label={<>{label(f)}{f.required && <span className="ms-1 text-danger" aria-hidden="true">*</span>}</>} hint={hint(f)}>
            {f.options ? (
              <select className={input} value={values[f.k]} onChange={(e) => setValues({ ...values, [f.k]: e.target.value })}>
                {f.options.map((o) => <option key={o} value={o}>{t(`min.providers.source.${o}`, { defaultValue: o })}</option>)}
              </select>
            ) : (
              <input
                className={input} dir="ltr" autoComplete="off" value={values[f.k]} required={!!f.required && !(f.secret && p.secrets_set[f.k])}
                type={f.secret ? 'password' : f.type === 'number' ? 'number' : 'text'} min={f.type === 'number' ? 0 : undefined}
                onChange={(e) => setValues({ ...values, [f.k]: e.target.value })}
              />
            )}
          </Field>
        ))}
      </div>
      {training && <p className="mt-4 rounded-xl bg-sky-50 px-4 py-3 text-sm text-sky-800">{t('min.providers.trainingNote')}</p>}
      <div className="mt-6 flex justify-end gap-2">
        <Button variant="outline" onClick={onClose}>{t('common.cancel')}</Button>
        <Button variant="gold" loading={saving} onClick={save}>{t('min.providers.save')}</Button>
      </div>
      {step.dialog}
    </Modal>
  )
}
