import type { TFunction } from 'i18next'

/** «درّب N برنامجاً في المركز» with the Arabic number forms. */
export function trainedText(n: number, t: TFunction): string {
  const key = n <= 0 ? 'none' : n === 1 ? 'one' : n === 2 ? 'two' : n <= 10 ? 'few' : 'many'
  return String(t(`liveT.trained.${key}`, { n }))
}
