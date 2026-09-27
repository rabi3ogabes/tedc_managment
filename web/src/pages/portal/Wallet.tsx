import { Download, QrCode } from 'lucide-react'
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
  const { data, isLoading } = useGet<{ data: Certificate[] }>('/me/certificates')
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
                <div className="flex items-center justify-between"><BrandMark onDark className="h-10 max-w-[150px]" markClassName="size-10" /><StatusBadge status={c.status} /></div>
                <h3 className="mt-6 text-xl font-bold leading-snug">{c.program?.title}</h3>
                <div className="mt-1 text-sm text-gold-300">{fmt.number(c.hours)} {t('common.hours')} · {fmt.date(c.issued_at)}</div>
                <div className="mt-6 flex items-end justify-between gap-4">
                  <div><div className="text-xs text-white/50">{t('verify.number')}</div><div className="font-mono text-sm" dir="ltr">{c.certificate_no}</div></div>
                  <div className="rounded-xl bg-white p-2"><QRCodeSVG value={c.verification_url} size={72} fgColor="#3d0a1c" /></div>
                </div>
                <Button variant="gold" size="sm" className="mt-5 w-full" icon={<Download className="size-4" />} onClick={() => downloadFile(`/certificates/${c.id}/download`, `${c.certificate_no}.pdf`, true)}>{t('common.download')}</Button>
              </div>
            </div>
          ))}
        </div>
      )}
    </>
  )
}
