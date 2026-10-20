// Bumps the patch number in src/lib/version.ts and stamps the current date and time (Qatar time, UTC+3).
// Usage: npm run stamp            → 0.9.1 → 0.9.2, now
//        npm run stamp -- minor   → 0.9.1 → 0.10.0
import { readFileSync, writeFileSync } from 'node:fs'
import { fileURLToPath } from 'node:url'

const file = fileURLToPath(new URL('../src/lib/version.ts', import.meta.url))
const src = readFileSync(file, 'utf8')
const [major, minor, patch] = /APP_VERSION = '(\d+)\.(\d+)\.(\d+)'/.exec(src).slice(1).map(Number)
const kind = process.argv[2] ?? 'patch'
const next = kind === 'major' ? [major + 1, 0, 0] : kind === 'minor' ? [major, minor + 1, 0] : [major, minor, patch + 1]
const qatar = new Date(Date.now() + 3 * 3600e3).toISOString().slice(0, 19) + '+03:00'
const out = src.replace(/APP_VERSION = '[^']*'/, `APP_VERSION = '${next.join('.')}'`).replace(/APP_UPDATED_AT = '[^']*'/, `APP_UPDATED_AT = '${qatar}'`)
writeFileSync(file, out)
console.log(`version ${next.join('.')} · ${qatar}`)
