// Serves MediaPipe's WebAssembly from TEDC's own origin (no CDN at run time): copies the module-worker build of the
// vision runtime from node_modules into public/vendor/mediapipe/wasm before dev and build. The face model
// (blaze_face_short_range.tflite, Apache-2.0) is committed next to it.
import { copyFileSync, existsSync, mkdirSync } from 'node:fs'
import { dirname, join } from 'node:path'
import { fileURLToPath } from 'node:url'

const root = join(dirname(fileURLToPath(import.meta.url)), '..')
const from = join(root, 'node_modules/@mediapipe/tasks-vision/wasm')
const to = join(root, 'public/vendor/mediapipe/wasm')
const files = ['vision_wasm_module_internal.js', 'vision_wasm_module_internal.wasm']

if (!existsSync(from)) {
  console.warn('copy-mediapipe: @mediapipe/tasks-vision is not installed; the exam face check will not start.')
} else {
  mkdirSync(to, { recursive: true })
  for (const f of files) copyFileSync(join(from, f), join(to, f))
}
if (!existsSync(join(root, 'public/vendor/mediapipe/blaze_face_short_range.tflite'))) {
  console.warn('copy-mediapipe: public/vendor/mediapipe/blaze_face_short_range.tflite is missing.')
}
