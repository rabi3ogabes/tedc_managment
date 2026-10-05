import { Download, Lock, QrCode } from 'lucide-react'
import { Link } from 'react-router-dom'
import { QRCodeSVG } from 'qrcode.react'
import { useTranslation } from 'react-i18next'
import { BrandMark } from '@/components/public/Logo'
import { Button, Card, Empty, PageHeader, Spinner, StatusBadge } from '@/components/ui'
import { useGet } from '@/hooks/useApi'
import { downloadFile } from '@/lib/api'
import { fmt } from '@/lib/format'
import type { Certificate } from '@/lib/types'

export default function Wallet() {
  const { t } = useTranslation()
  type Item = Partial<Certificate> & { id: string; kind?: 'trainee' | 'trainer'; downloadable?: boolean; survey_required?: boolean; sessions_done?: number; sessions_total?: number }
  const trainee = useGet<{ data: Item[] }>('/me/certificates')
  const trainer = useGet<{ data: Item[] }>('/me/trainer-certificates')
  const isLoading = trainee.isLoading && trainer.isLoading
  const data = { data: [...(trainer.data?.data ?? []), ...(trainee.data?.data ?? [])] }
  return (
    <>
      <PageHeader title={t('portal.certificates')} />
      {isLoading ? <Spinner /> : !data?.data.length ? <Card><Empty icon={<QrCode className="size-6" />} /></Card> : (
        <div className="grid gap-6 md:grid-cols-2 xl:grid-cols-3">
          {data.data.map((c) => (
            <div key={c.id} className="relative overflow-hidden rounded-3xl bg-gradient-to-br from-navy-900 via-navy-800 to-navy-700 p-6 text-white shadow-glass">
              <div className="pattern-bg absolute inset-0 opacity-25" />
              <div className="absolute -end-10 -top-10 size-40 rounded-full bg-gold-500/25 blur-2xl" />
              <div className="relative">
                <div className="flex items-center justify-between"><BrandMark onDark className="h-10 max-w-[150px]" markClassName="size-10" /><StatusBadge status={c.status ?? 'pending'} /></div>
                <div className="mt-6 text-xs font-semibold text-gold-300">{c.kind !== 'trainer' && c.type === 'attendance' ? t('passing.certAttendance') : t(c.kind === 'trainer' ? 'portal.trainerCert' : 'portal.traineeCert')}</div>
                <h3 className="mt-1 text-xl font-bold leading-snug">{c.program?.title}</h3>
                <div className="mt-1 text-sm text-gold-300">{c.issued_at ? `${fmt.number(c.hours ?? 0)} ${t('common.hours')} · ${fmt.date(c.issued_at)}` : `${t('portal.sessionsDone')}: ${c.sessions_done ?? 0}/${c.sessions_total ?? 0} · ${fmt.number(c.hours ?? 0)} ${t('common.hours')}`}</div>
                {c.downloadable === false ? (
                  <div className="mt-5 space-y-3">
                    <div className="flex items-start gap-2 text-sm text-white/80"><Lock className="mt-0.5 size-4 shrink-0" />{t(c.kind === 'trainer' ? 'portal.trainerLocked' : 'portal.traineeLocked')}</div>
                    {c.kind !== 'trainer' && <Link to="/portal/training" className="inline-block rounded-xl bg-gold-500 px-4 py-2 text-sm font-semibold text-navy-950">{t('portal.fillSurvey')}</Link>}
                  </div>
                ) : (<>
                <div className="mt-6 flex items-end justify-between gap-4">
                  <div><div className="text-xs text-white/50">{t('verify.number')}</div><div className="font-mono text-sm" dir="ltr">{c.certificate_no}</div></div>
                  <div className="rounded-xl bg-white p-2"><QRCodeSVG value={c.verification_url ?? ''} size={72} fgColor="#3d0a1c" /></div>
                </div>
                <Button variant="gold" size="sm" className="mt-5 w-full" icon={<Download className="size-4" />} onClick={() => downloadFile(`/${c.kind === 'trainer' ? 'trainer-certificates' : 'certificates'}/${c.id}/download`, `${c.certificate_no}.pdf`, true)}>{t('common.download')}</Button>
                </>)}
              </div>
            </div>
          ))}
        </div>
      )}
    </>
  )
}
