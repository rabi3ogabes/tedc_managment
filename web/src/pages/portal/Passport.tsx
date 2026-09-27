import { useTranslation } from 'react-i18next'
import PassportView, { type PassportData } from '@/components/PassportView'
import { PageHeader, Spinner } from '@/components/ui'
import { useGet } from '@/hooks/useApi'

export default function Passport() {
  const { t } = useTranslation()
  const { data, isLoading } = useGet<{ data: PassportData }>('/me/passport')
  return (
    <>
      <PageHeader title={t('portal.passport')} />
      {isLoading || !data ? <Spinner /> : <PassportView data={data.data} />}
    </>
  )
}
