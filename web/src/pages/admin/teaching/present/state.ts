import type { ReactNode } from 'react'

/** What the presenter and the audience window share. Points are normalised (0–1) so both screens draw the same thing at any size. */
export type Tool = 'none' | 'laser' | 'spot' | 'pen' | 'marker' | 'zoom'
export type Stroke = { tool: 'pen' | 'marker'; color: string; w: number; pts: [number, number][] }
export type Overlay =
  | null
  | { kind: 'picker'; name: string }
  | { kind: 'timer'; endsAt: number; label: string }
  | { kind: 'message'; text: string }
export type Pointer = { x: number; y: number; mode: 'laser' | 'spot' } | null
export type Zoom = { x: number; y: number; scale: number } | null
export type Show = { index: number; blackout: 'off' | 'black' | 'white'; strokes: Record<number, Stroke[]>; zoom: Zoom; overlay: Overlay }

export const INITIAL_SHOW: Show = { index: 0, blackout: 'off', strokes: {}, zoom: null, overlay: null }

export type Msg = { t: 'state'; s: Show } | { t: 'pointer'; p: Pointer } | { t: 'hello' } | { t: 'bye' }

/** Where slides come from: a deck's slides or a PDF's pages — the presenter does not care which. */
export type Source = {
  kind: 'deck' | 'pdf'
  ready: boolean
  error: boolean
  count: number
  notes: (i: number) => string
  /** Draws slide `i` to fit inside w × h. */
  render: (i: number, w: number, h: number) => ReactNode
  /** Small picture for the strip; null = show the number only. */
  thumb: ((i: number, w: number) => ReactNode) | null
}

export const COLORS = ['#E11D48', '#F59E0B', '#16A34A', '#2563EB', '#111827', '#FFFFFF']

export const channelName = (kitId: string, fileId: string, token: string) => `tedc-present-${kitId}-${fileId}-${token}`

export function newToken() {
  return Math.random().toString(36).slice(2, 8)
}
