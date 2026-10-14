/// <reference lib="webworker" />
/**
 * Face detection off the main thread (EXM-10): MediaPipe's BlazeFace short-range model, served from TEDC's own origin.
 * It answers with face boxes and a head-turn estimate for each frame; frames are never kept or sent anywhere.
 */
import { FaceDetector, FilesetResolver } from '@mediapipe/tasks-vision'

type In = { type: 'init'; base: string } | { type: 'frame'; bitmap: ImageBitmap; at: number }

const ctx = self as unknown as DedicatedWorkerGlobalScope
let detector: FaceDetector | null = null

ctx.onmessage = async (e: MessageEvent<In>) => {
  const m = e.data
  if (m.type === 'init') {
    try {
      const files = await FilesetResolver.forVisionTasks(`${m.base}wasm`, true)
      detector = await FaceDetector.createFromOptions(files, {
        baseOptions: { modelAssetPath: `${m.base}blaze_face_short_range.tflite`, delegate: 'CPU' },
        runningMode: 'IMAGE',
        minDetectionConfidence: 0.6,
      })
      ctx.postMessage({ type: 'ready' })
    } catch (err) {
      ctx.postMessage({ type: 'failed', reason: String(err) })
    }
    return
  }
  const bitmap = m.bitmap
  if (!detector) {
    bitmap.close()
    return
  }
  const started = performance.now()
  try {
    const { width, height } = bitmap
    const faces = detector.detect(bitmap).detections
      .filter((d) => (d.categories?.[0]?.score ?? 0) >= 0.6 && d.boundingBox)
      .map((d) => {
        const b = d.boundingBox!
        // Keypoints: right eye, left eye, nose tip, mouth, ears. The nose drifts towards one eye when the head turns.
        const k = d.keypoints ?? []
        let yaw: number | undefined
        if (k.length >= 3) {
          const between = Math.abs(k[1].x - k[0].x)
          if (between > 0.01) yaw = (k[2].x - (k[0].x + k[1].x) / 2) / between
        }
        return { x: b.originX / width, y: b.originY / height, w: b.width / width, h: b.height / height, yaw }
      })
    ctx.postMessage({ type: 'faces', at: m.at, faces, ms: performance.now() - started })
  } catch (err) {
    ctx.postMessage({ type: 'failed', reason: String(err) })
  } finally {
    bitmap.close()
  }
}
