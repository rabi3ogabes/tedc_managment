import { execFileSync } from 'node:child_process'
import { dirname, join } from 'node:path'
import { fileURLToPath } from 'node:url'

/** Prepares the local fixture (backend seeder E2EFaceCheckSeeder) and hands its ids to the tests. */
export default function globalSetup() {
  const base = process.env.E2E_BASE_URL ?? 'http://localhost:5173'
  if (!/^https?:\/\/(localhost|127\.0\.0\.1)(:\d+)?\/?$/.test(base)) throw new Error('E2E tests run only against a local TEDC.')
  const artisan = join(dirname(fileURLToPath(import.meta.url)), '../../backend/artisan')
  const out = execFileSync(process.env.PHP_BINARY ?? 'php', [artisan, 'db:seed', '--class=E2EFaceCheckSeeder', '--no-interaction'], { encoding: 'utf8' })
  const line = out.split('\n').find((l) => l.startsWith('E2E_FACE '))
  if (!line) throw new Error(`The E2E fixture did not run:\n${out}`)
  process.env.E2E_FACE = line.slice('E2E_FACE '.length)
}
