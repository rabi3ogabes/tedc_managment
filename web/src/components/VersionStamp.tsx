import { useTranslation } from 'react-i18next'
import { APP_UPDATED_AT, APP_VERSION } from '@/lib/version'

/** «الإصدار 0.9.1 · آخر تحديث 20 أكتوبر 2026، 11:05 م» — in Qatar time, whatever the visitor's clock says. */
export default function VersionStamp({ className = '' }: { className?: string }) {
  const { t, i18n } = useTranslation()
  const when = new Intl.DateTimeFormat(i18n.language === 'ar' ? 'ar-QA-u-nu-latn' : 'en-GB', { day: 'numeric', month: 'long', year: 'numeric', hour: '2-digit', minute: '2-digit', timeZone: 'Asia/Qatar' }).format(new Date(APP_UPDATED_AT))
  return (
    <span className={className} title={APP_UPDATED_AT}>
      {t('footer.version')} <b dir="ltr" className="font-mono font-bold">{APP_VERSION}</b> · {t('footer.updated')} <time dateTime={APP_UPDATED_AT}>{when}</time>
    </span>
  )
}
