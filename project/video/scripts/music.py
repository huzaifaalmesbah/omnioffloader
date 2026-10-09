"""Synthesise a soft ambient pad + arpeggio bed (original, royalty-free)."""
import math, wave, array, random, sys
SR = 32000
import json, os
# Length follows the video: same formula as src/Video.tsx (LEAD 0.45, TAIL 0.75, outro hold 2.6, 16-frame fades at 30 fps).
_t = json.load(open(os.path.join(os.path.dirname(__file__), '..', 'src', 'timing.json')))
DUR = float(sys.argv[1]) if len(sys.argv) > 1 else sum(round((0.45 + s['duration'] + 0.75) * 30) for s in _t) / 30 + 2.6 - 16 * (len(_t) - 1) / 30 + 0.5
N = int(SR * DUR)
L = array.array('f', [0.0]) * N
R = array.array('f', [0.0]) * N
def hz(m): return 440 * 2 ** ((m - 69) / 12)
# Cmaj9 – Am9 – Fmaj7#11 – Gsus, 8 s each
chords = [[48, 55, 64, 67, 71, 74], [45, 52, 60, 64, 67, 71], [41, 48, 57, 64, 67, 69], [43, 50, 57, 62, 67, 71]]
BAR = 8.0
TWO_PI = 2 * math.pi
# pad
for ci in range(int(DUR / BAR) + 1):
    notes = chords[ci % 4]
    start = ci * BAR - 1.0
    length = BAR + 2.0
    s0 = max(0, int(start * SR)); s1 = min(N, int((start + length) * SR))
    for k, m in enumerate(notes):
        f = hz(m); amp = 0.05 if k else 0.07
        det = 1.0 + (0.0015 if k % 2 else -0.0015)
        pan = 0.3 + 0.4 * (k / len(notes))
        for i in range(s0, s1):
            t = i / SR - start
            env = min(1.0, t / 2.2) * min(1.0, (length - t) / 2.2)
            ph = TWO_PI * f * (i / SR)
            v = (math.sin(ph) + 0.5 * math.sin(ph * det) + 0.12 * math.sin(2 * ph)) * amp * env
            L[i] += v * (1 - pan); R[i] += v * pan
# arpeggio plucks, eighth notes at 90 bpm-ish
step = 60 / 90 / 2
pattern = [0, 2, 3, 4, 3, 2, 4, 5]
n = 0; t0 = 4.0
while t0 < DUR - 1:
    notes = chords[int(t0 // BAR) % 4]
    m = notes[pattern[n % 8] % len(notes)] + 12
    f = hz(m); s0 = int(t0 * SR); ln = int(1.6 * SR)
    pan = 0.25 if n % 2 else 0.75
    vel = 0.035 * (0.8 + 0.4 * random.random())
    for j in range(ln):
        i = s0 + j
        if i >= N: break
        t = j / SR
        env = math.exp(-t * 3.2) * min(1.0, t / 0.005)
        ph = TWO_PI * f * t
        v = (math.sin(ph) + 0.25 * math.sin(3 * ph) * math.exp(-t * 8)) * vel * env
        L[i] += v * (1 - pan); R[i] += v * pan
    n += 1; t0 += step
# simple stereo echo for space
d = int(0.375 * SR)
for i in range(d, N):
    L[i] += R[i - d] * 0.28; R[i] += L[i - d] * 0.28
peak = max(max(abs(x) for x in L), max(abs(x) for x in R)) or 1
out = array.array('h')
fade = int(4 * SR)
for i in range(N):
    g = 0.85 / peak * min(1.0, i / (2 * SR)) * min(1.0, (N - i) / fade)
    out.append(int(L[i] * g * 32767)); out.append(int(R[i] * g * 32767))
w = wave.open('public/music.wav', 'wb'); w.setnchannels(2); w.setsampwidth(2); w.setframerate(SR); w.writeframes(out.tobytes()); w.close()
