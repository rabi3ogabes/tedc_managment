import { FaceMonitor, type FaceBox, type FaceEvent } from './faceMonitor.ts'

export type FaceCheckEvent = FaceEvent | 'face_check_degraded' | 'camera_off'
/** starting · on · unavailable (this browser cannot run the check) */
export type FaceStatus = 'starting' | 'on' | 'unavailable'

type Options = {
  intervalMs: number
  absentSeconds: number
  onEvent: (type: FaceCheckEvent) => void
  onStatus: (status: FaceStatus) => void
  /** How many faces the last frame showed (for the gentle on-screen hint; never sent). */
  onFaces?: (count: number) => void
}

type FromWorker = { type: 'ready' } | { type: 'failed'; reason: string } | { type: 'faces'; at: number; faces: FaceBox[]; ms: number }

/**
 * The exam's camera check: one small frame every `intervalMs` from a camera stream the learner allowed, counted in a
 * Web Worker; only event names leave the page. A device that cannot keep up halves the rate (down to one frame every
 * three seconds) and the reviewer is told once.
 */
export class FacePresence {
  private stream: MediaStream | null = null
  private video: HTMLVideoElement | null = null
  private worker: Worker | null = null
  private timer: number | undefined
  private busy = false
  private slow = 0
  private degraded = false
  private stopped = false
  private interval: number
  private readonly monitor: FaceMonitor
  private readonly o: Options

  constructor(o: Options) {
    this.o = o
    this.interval = Math.min(3000, Math.max(500, o.intervalMs))
    this.monitor = new FaceMonitor({ absentMs: Math.max(3, o.absentSeconds) * 1000, emit: (type) => o.onEvent(type) })
  }

  /** The browser asks the learner for the camera. */
  static async requestCamera(): Promise<MediaStream | 'denied' | 'no_camera'> {
    if (!navigator.mediaDevices?.getUserMedia) return 'no_camera'
    try {
      return await navigator.mediaDevices.getUserMedia({ video: { width: { ideal: 320 }, height: { ideal: 240 }, facingMode: 'user' }, audio: false })
    } catch (e) {
      const name = (e as DOMException | undefined)?.name
      return name === 'NotAllowedError' || name === 'SecurityError' ? 'denied' : 'no_camera'
    }
  }

  async start(stream: MediaStream, video: HTMLVideoElement): Promise<void> {
    this.stream = stream
    this.video = video
    video.muted = true
    video.playsInline = true
    video.srcObject = stream
    await video.play().catch(() => undefined)
    // The camera stopped (unplugged, permission withdrawn, another app took it).
    for (const track of stream.getVideoTracks()) track.addEventListener('ended', () => { if (!this.stopped) this.o.onEvent('camera_off') })
    this.o.onStatus('starting')
    try {
      this.worker = new Worker(new URL('./face.worker.ts', import.meta.url), { type: 'module' })
    } catch {
      this.unavailable()
      return
    }
    this.worker.onmessage = (e: MessageEvent<FromWorker>) => this.message(e.data)
    this.worker.onerror = () => this.unavailable()
    this.worker.postMessage({ type: 'init', base: new URL(`${import.meta.env.BASE_URL}vendor/mediapipe/`, location.origin).href })
  }

  private unavailable(): void {
    if (this.stopped) return
    window.clearInterval(this.timer)
    this.o.onStatus('unavailable')
    if (!this.degraded) {
      this.degraded = true
      this.o.onEvent('face_check_degraded')
    }
  }

  private message(m: FromWorker): void {
    if (this.stopped) return
    if (m.type === 'ready') {
      this.o.onStatus('on')
      this.schedule()
      return
    }
    if (m.type === 'failed') {
      this.busy = false
      this.unavailable()
      return
    }
    this.busy = false
    this.monitor.feed({ at: m.at, faces: m.faces })
    this.o.onFaces?.(m.faces.length)
    // Too slow for this device: fewer frames, and the reviewer is told once.
    if (m.ms > this.interval * 0.5) {
      this.slow += 1
      if (this.slow >= 5 && this.interval < 3000) {
        this.slow = 0
        this.interval = Math.min(3000, this.interval * 2)
        window.clearInterval(this.timer)
        this.schedule()
        if (!this.degraded) {
          this.degraded = true
          this.o.onEvent('face_check_degraded')
        }
      }
    } else {
      this.slow = 0
    }
  }

  private schedule(): void {
    this.timer = window.setInterval(() => void this.tick(), this.interval)
  }

  private async tick(): Promise<void> {
    const v = this.video
    // A hidden page is reported on its own (visibility); it never counts as time without a face.
    if (this.busy || !v || !this.worker || v.readyState < 2 || !v.videoWidth || document.hidden) return
    this.busy = true
    try {
      const bitmap = await createImageBitmap(v, { resizeWidth: 320, resizeHeight: Math.max(1, Math.round((320 * v.videoHeight) / v.videoWidth)) })
      this.worker.postMessage({ type: 'frame', bitmap, at: performance.now() }, [bitmap])
    } catch {
      this.busy = false
    }
  }

  stop(): void {
    this.stopped = true
    window.clearInterval(this.timer)
    this.worker?.terminate()
    this.worker = null
    this.stream?.getTracks().forEach((t) => t.stop())
    if (this.video) this.video.srcObject = null
  }
}
