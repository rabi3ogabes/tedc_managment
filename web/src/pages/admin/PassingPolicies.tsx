import { useTranslation } from 'react-i18next'
import { PageHeader } from '@/components/ui'
import { PolicyEditor } from './program/PassingTab'

/** The global passing policy: applies to every program that has none of its own. */
export default function PassingPolicies() {
  const { t } = useTranslation()
  return (
    <>
      <PageHeader title={t('passing.global')} subtitle={t('passing.globalHint')} />
      <PolicyEditor scope="global" />
    </>
  )
}
