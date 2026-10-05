export type ToastTone = 'success' | 'error' | 'info'

/** A short message at the bottom of the screen (shown by ToastHost): `toast('Saved')`. */
export function toast(text: string, tone: ToastTone = 'success') {
  window.dispatchEvent(new CustomEvent('tedc:toast', { detail: { text, tone } }))
}
