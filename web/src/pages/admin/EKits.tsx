/* eslint-disable @typescript-eslint/no-explicit-any */
import { BookOpenCheck, Download, ExternalLink, RefreshCw } from 'lucide-react'
import { useState } from 'react'
import { useTranslation } from 'react-i18next'
import { useLang } from '@/components/help/helpApi'
import { Badge, Button, Card, Empty, PageHeader, Spinner } from '@/components/ui'
import { useGet } from '@/hooks/useApi'
import { api, downloadFile, errorMessage } from '@/lib/api'
import { toast } from '@/lib/toast'
import { dialogs } from '@/lib/dialogs'

/** The interactive e-kits kept in resources/ekits: publish each as an e-course and export it as a SCORM 2004 package. */
export default function EKits() {
  const { t } = useTranslation()
  const { lang } = useLang()
  const res = useGet<{ data: any[] }>('/admin/ekits', undefined, { staleTime: 0 })
  const [busy, setBusy] = useState<string | null>(null)

  const build = async (code: string, rebuild: boolean) => {
    if (rebuild && !await dialogs.confirm(String(t('hlp.kits.confirmRebuild')))) return
    setBusy(code)
    try { const { data } = await api.post(`/admin/ekits/${code}/build`, { rebuild }); toast(String(data.data.created ? t(rebuild ? 'hlp.kits.rebuilt' : 'hlp.kits.done') : t('hlp.kits.exists'))); res.refetch() } catch (e) { toast(errorMessage(e), 'error') } finally { setBusy(null) }
  }
  const scorm = async (code: string, l: 'ar' | 'en') => {
    setBusy(`${code}-${l}`)
    try { await downloadFile(`/admin/ekits/${code}/scorm?lang=${l}`, `${code}-${l}-scorm2004.zip`) } catch (e) { toast(errorMessage(e), 'error') } finally { setBusy(null) }
  }

  return (
    <>
      <PageHeader title={t('hlp.kits.title')} subtitle={t('hlp.kits.subtitle')} />
      {res.isLoading ? <Spinner /> : (res.data?.data.length ?? 0) === 0 ? <Empty /> : (
        <div className="grid gap-5 lg:grid-cols-2">
          {res.data!.data.map((k) => (
            <Card key={k.code} className="space-y-3">
              <div className="flex items-start justify-between gap-2">
                <h2 className="flex items-center gap-2 font-bold text-navy-900"><BookOpenCheck className="size-5 text-gold-600" />{lang === 'en' ? k.title_en : k.title_ar}</h2>
                <Badge color={k.published ? 'green' : 'gold'}>{t(k.published ? 'hlp.kits.published' : 'hlp.kits.notPublished')}</Badge>
              </div>
              <p className="text-xs text-slate-500" dir="ltr">{k.code}</p>
              <dl className="grid grid-cols-4 gap-2 text-center text-sm">
                {[['chapters', k.chapters], ['checks', k.check_questions], ['finalQ', k.final_questions], ['hours', k.hours]].map(([l, v]) => <div key={String(l)} className="rounded-xl bg-ivory p-2"><dd className="text-lg font-bold text-navy-900">{v}</dd><dt className="text-[11px] text-slate-500">{t(`hlp.kits.${l}`)}</dt></div>)}
              </dl>
              <p className="rounded-lg bg-ivory p-2 text-xs text-slate-700">{t('hlp.kits.topicNote')}</p>
              <div className="flex flex-wrap gap-2">
                {!k.published && <Button variant="gold" loading={busy === k.code} onClick={() => build(k.code, false)}>{t('hlp.kits.publish')}</Button>}
                {k.published && <Button variant="outline" loading={busy === k.code} icon={<RefreshCw className="size-4" />} onClick={() => build(k.code, true)}>{t('hlp.kits.rebuild')}</Button>}
                {k.program_id && <Button variant="outline" icon={<ExternalLink className="size-4" />} to={`/admin/programs/${k.program_id}`}>{t('hlp.kits.open')}</Button>}
                {(['ar', 'en'] as const).map((l) => <Button key={l} variant="outline" loading={busy === `${k.code}-${l}`} icon={<Download className="size-4" />} onClick={() => scorm(k.code, l)}>{t('hlp.kits.scorm')} · {l.toUpperCase()}</Button>)}
              </div>
            </Card>
          ))}
        </div>
      )}
    </>
  )
}
