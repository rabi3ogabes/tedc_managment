/** A short, soft two-note chime for a new notification, made with the Web Audio API (no audio file to load). */
let ctx: AudioContext | null = null
let enabled: boolean | null = null

try {
  enabled = localStorage.getItem('tedc.chime') !== '0'
} catch {
  enabled = true
}

export function setChimeEnabled(on: boolean) {
  enabled = on
  try { localStorage.setItem('tedc.chime', on ? '1' : '0') } catch { /* storage unavailable */ }
}

export const chimeEnabled = () => enabled !== false

/** Browsers only allow sound after the person has touched the page once; the first touch unlocks it. */
function unlock() {
  try {
    const AC = window.AudioContext ?? (window as unknown as { webkitAudioContext?: typeof AudioContext }).webkitAudioContext
    if (!AC) return
    ctx ??= new AC()
    if (ctx.state === 'suspended') void ctx.resume()
  } catch { /* no audio available */ }
}
if (typeof window !== 'undefined') {
  window.addEventListener('pointerdown', unlock, { once: true, passive: true })
  window.addEventListener('keydown', unlock, { once: true })
}

export function playChime() {
  if (!chimeEnabled() || !ctx || ctx.state !== 'running') return   // locked or unavailable: stay silent
  try {
    const t0 = ctx.currentTime
    ;[[660, 0], [880, 0.14]].forEach(([freq, at]) => {
      const osc = ctx!.createOscillator()
      const gain = ctx!.createGain()
      osc.type = 'sine'
      osc.frequency.value = freq
      gain.gain.setValueAtTime(0.0001, t0 + at)
      gain.gain.exponentialRampToValueAtTime(0.12, t0 + at + 0.02)
      gain.gain.exponentialRampToValueAtTime(0.0001, t0 + at + 0.35)
      osc.connect(gain).connect(ctx!.destination)
      osc.start(t0 + at)
      osc.stop(t0 + at + 0.4)
    })
  } catch { /* ignore */ }
}
