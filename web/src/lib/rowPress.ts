/**
 * Whether a press on a row should open the row: not when it landed on something that acts by itself (a link, a button,
 * a field), and not when the person was selecting text in the row to copy it.
 */
export function opensRow(target: { closest?: (selector: string) => unknown } | null, selectedText: string): boolean {
  if (selectedText.trim() !== '') return false
  return !target?.closest?.('a, button, input, select, textarea, label, [role="button"]')
}
