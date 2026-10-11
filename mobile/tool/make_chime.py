import math, struct, wave, sys
rate = 22050
dur = 0.9
n = int(rate * dur)
notes = [(660.0, 0.0), (880.0, 0.14)]           # the same two notes as the web chime
partials = [(1.0, 1.0), (2.0, 0.28), (3.0, 0.08), (2.76, 0.06)]   # soft bell colour
buf = [0.0] * n
for f0, start in notes:
    s0 = int(start * rate)
    for i in range(s0, n):
        t = (i - s0) / rate
        attack = min(1.0, t / 0.012)
        env = attack * math.exp(-t / 0.2)
        v = sum(a * math.sin(2 * math.pi * f0 * m * t) * math.exp(-t * (m - 1) * 2.5) for m, a in partials)
        buf[i] += env * v
fade = int(0.08 * rate)
for k in range(fade):
    buf[n - 1 - k] *= k / fade
peak = max(abs(x) for x in buf)
gain = (10 ** (-6 / 20)) / peak                    # peak at -6 dBFS: gentle, never harsh
with wave.open(sys.argv[1], 'wb') as w:
    w.setnchannels(1); w.setsampwidth(2); w.setframerate(rate)
    w.writeframes(b''.join(struct.pack('<h', int(max(-1, min(1, x * gain)) * 32767)) for x in buf))
