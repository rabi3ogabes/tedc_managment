/**
 * Face presence for remote exams (EXM-10): turns what the detector sees in each frame into a few debounced,
 * explainable events. It works on face boxes only — never on who the person is — and nothing about the face leaves
 * the device: only the event names are sent.
 */
export type FaceEvent = 'face_absent' | 'multiple_faces' | 'looking_away'

/** A detected face, as a share of the frame (0–1, from the top-left). `yaw` is how far the nose sits from the middle of the eyes, in eye distances (0 = facing the camera). */
export type FaceBox = { x: number; y: number; w: number; h: number; yaw?: number }

export type FaceSample = { at: number; faces: FaceBox[] }

type Options = {
  /** No face for this long is one `face_absent` event (the assessment's setting, 10 s by default). */
  absentMs: number
  /** More than one face for this long is one `multiple_faces` event. */
  multipleMs?: number
  /** One face away from the screen for this long is one `looking_away` event. */
  awayMs?: number
  /** A longer pause between samples (hidden tab, sleeping laptop) starts every episode again. */
  maxGapMs?: number
  emit: (type: FaceEvent, at: number) => void
}

type Episode = 'none' | 'many' | 'away'
const EVENT: Record<Episode, FaceEvent> = { none: 'face_absent', many: 'multiple_faces', away: 'looking_away' }

export class FaceMonitor {
  private readonly since: Record<Episode, number | null> = { none: null, many: null, away: null }
  private readonly fired: Record<Episode, boolean> = { none: false, many: false, away: false }
  private last: number | null = null
  private readonly o: Options

  constructor(o: Options) {
    this.o = o
  }

  /**
   * Whether one face is looking at the screen: its centre in the middle of the frame (60 % of the width, 70 % of the
   * height), not turned aside (yaw within 0.6 eye distances) and not so small that the person moved away.
   */
  static looking(f: FaceBox): boolean {
    const cx = f.x + f.w / 2
    const cy = f.y + f.h / 2
    return cx >= 0.2 && cx <= 0.8 && cy >= 0.15 && cy <= 0.85 && Math.abs(f.yaw ?? 0) <= 0.6 && f.w >= 0.08
  }

  feed(s: FaceSample): void {
    if (this.last !== null && s.at - this.last > (this.o.maxGapMs ?? 5000)) this.reset()
    this.last = s.at
    const n = s.faces.length
    const now: Episode | 'ok' = n === 0 ? 'none' : n > 1 ? 'many' : FaceMonitor.looking(s.faces[0]) ? 'ok' : 'away'
    for (const k of ['none', 'many', 'away'] as const) {
      if (now !== k) {
        this.since[k] = null
        this.fired[k] = false
        continue
      }
      const start = (this.since[k] ??= s.at)
      const limit = k === 'none' ? this.o.absentMs : k === 'many' ? (this.o.multipleMs ?? 3000) : (this.o.awayMs ?? 5000)
      // One event per episode, however long it lasts and however often frames arrive.
      if (!this.fired[k] && s.at - start >= limit) {
        this.fired[k] = true
        this.o.emit(EVENT[k], s.at)
      }
    }
  }

  reset(): void {
    for (const k of ['none', 'many', 'away'] as const) {
      this.since[k] = null
      this.fired[k] = false
    }
  }
}
