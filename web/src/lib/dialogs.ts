/** In-app replacements for the browser's confirm() and prompt() (shown by DialogHost): `if (!(await dialogs.confirm('Delete?'))) return`. */
export type DialogRequest = { id: number; kind: 'confirm' | 'prompt'; message: string; initial?: string; resolve: (value: boolean | string | null) => void }

let nextId = 1

function open(kind: DialogRequest['kind'], message: string, initial?: string): Promise<boolean | string | null> {
  return new Promise((resolve) => {
    window.dispatchEvent(new CustomEvent<DialogRequest>('tedc:dialog', { detail: { id: nextId++, kind, message, initial, resolve } }))
  })
}

export const dialogs = {
  /** Resolves true when the person confirms, false when they cancel or close the window. */
  confirm: (message: string) => open('confirm', message) as Promise<boolean>,
  /** Resolves the text typed (possibly empty), or null when cancelled, like window.prompt. */
  prompt: (message: string, initial = '') => open('prompt', message, initial) as Promise<string | null>,
}
