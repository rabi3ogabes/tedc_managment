import i18n from 'i18next'
import { initReactI18next } from 'react-i18next'
import ar from './ar'
import en from './en'
import { mgmtAr, mgmtEn } from './management'
import { surveysAr, surveysEn } from './surveys'

export type Locale = 'ar' | 'en'

const stored = (() => {
  try {
    return localStorage.getItem('tedc.locale') as Locale | null
  } catch {
    return null
  }
})()

// Arabic is the primary language of the platform.
export const initialLocale: Locale = stored === 'en' ? 'en' : 'ar'

export function applyDocumentLocale(locale: Locale) {
  document.documentElement.lang = locale
  document.documentElement.dir = locale === 'ar' ? 'rtl' : 'ltr'
}

i18n.use(initReactI18next).init({
  resources: { ar: { translation: { ...ar, surveys: surveysAr, mgmt: mgmtAr } }, en: { translation: { ...en, surveys: surveysEn, mgmt: mgmtEn } } },
  lng: initialLocale,
  fallbackLng: 'ar',
  interpolation: { escapeValue: false },
  returnObjects: true,
})

applyDocumentLocale(initialLocale)

i18n.on('languageChanged', (lng) => {
  applyDocumentLocale(lng as Locale)
  try {
    localStorage.setItem('tedc.locale', lng)
  } catch {
    /* storage unavailable */
  }
})

export default i18n
