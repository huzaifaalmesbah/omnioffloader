"""Sentence start times for the Bangla clips → src/bn-timing.json.

Whisper's Bangla spelling is too rough to match word by word, so each script
sentence's position (its share of the script's characters) is mapped onto
Whisper's word timeline by the same share of Whisper's characters, then snapped
to the nearest real pause in the audio (within 1.2 s). Captions use the script
text, timed by those starts.

Usage: python3 scripts/sentences-bn.py   (needs .venv/wav16/*.wav from whisper-bn.py)
"""
import json, re, wave, array, subprocess, os, unicodedata

FRAME = 0.02  # 20 ms analysis window

def split(text):
    return [s.strip() for s in re.split(r'(?<=[।?!])\s+', text) if s.strip()]

def pauses(wav):
    with wave.open(wav) as w:
        sr = w.getframerate()
        pcm = array.array('h', w.readframes(w.getnframes()))
    hop = int(sr * FRAME)
    rms = []
    for i in range(0, len(pcm) - hop, hop):
        seg = pcm[i:i + hop]
        rms.append((sum(x * x for x in seg) / hop) ** 0.5)
    peak = max(rms) or 1
    voiced = [r > peak * 0.06 for r in rms]
    first = voiced.index(True) * FRAME
    last = (len(voiced) - voiced[::-1].index(True)) * FRAME
    gaps, run = [], 0
    for i, v in enumerate(voiced):
        if not v:
            run += 1
        else:
            if run * FRAME >= 0.18 and (i - run) * FRAME > first:
                gaps.append(((i - run) * FRAME, i * FRAME, run * FRAME))  # silence start, speech resume, length
            run = 0
    return first, last, gaps

def nchars(t):
    return len(re.sub(r'\s+', '', t))

# Consonant skeleton: drop vowel signs and marks, merge letters Whisper confuses,
# so "ছোবি" (heard) still matches "ছবি" (script).
_DROP = re.compile('[ঁ-ঃ়া-্ৗ‌‍।,.?!।:;\'"()-]')
_MAP = str.maketrans({'শ': 'স', 'ষ': 'স', 'ণ': 'ন', 'য': 'জ', 'ছ': 'চ', 'ঢ': 'ড', 'ঠ': 'ট', 'ঘ': 'গ', 'খ': 'ক', 'ধ': 'দ', 'থ': 'ত', 'ভ': 'ব', 'ফ': 'প', 'ঝ': 'জ', 'ঐ': 'অ', 'ঔ': 'অ', 'আ': 'অ', 'ই': 'অ', 'ঈ': 'অ', 'উ': 'অ', 'ঊ': 'অ', 'এ': 'অ', 'ও': 'অ'})

def skel(t):
    # ড়/ঢ়/য় are base letter + nukta; the nukta is dropped above.
    return _DROP.sub('', unicodedata.normalize('NFD', t)).translate(_MAP).replace(' ', '')

from difflib import SequenceMatcher

def anchor(words, sent, lo, hi):
    """Start time of the Whisper word where this sentence begins, or None."""
    toks = sent.split()
    if not toks or re.search('[A-Za-z0-9]', toks[0]):
        return None
    want = skel(' '.join(toks[:2]))
    if len(want) < 3:
        return None
    best = None
    for i, w in enumerate(words):
        if w[1] < lo or w[1] > hi:
            continue
        heard = skel(' '.join(x[0] for x in words[i:i + 2]))[: len(want) + 1]
        r = SequenceMatcher(None, want, heard).ratio()
        if r >= 0.7 and (best is None or r > best[0]):
            best = (r, w[1])
    return best[1] if best else None

whisper = {o['id']: o for o in json.load(open('src/bn-whisper.json'))}
out = []
for s in json.load(open('bn-script.json')):
    sid = s['id']
    wav = f'.venv/wav16/{sid}.wav'
    if not os.path.exists(wav):
        subprocess.run(['afconvert', '-f', 'WAVE', '-d', 'LEI16@16000', '-c', '1', f'voice-bn/{sid}.mp3', wav], check=True)
    sents = split(s['text'])
    first, last, gaps = pauses(wav)
    words = whisper[sid]['words']
    total = sum(nchars(t) for t in sents)
    cum = [0]
    for t in sents:
        cum.append(cum[-1] + nchars(t))
    # Expected start from the character share, used as a search window and fallback.
    expect = [first + (last - first) * cum[k] / total for k in range(len(sents))]
    starts, how = [first], ['start']
    for k in range(1, len(sents)):
        lo = starts[-1] + 0.4
        a = anchor(words, sents[k], max(lo, expect[k] - 3.0), expect[k] + 3.0)
        if a is not None and a >= lo:
            near = [g for g in gaps if abs(g[1] - a) <= 0.5]
            starts.append(min(near, key=lambda g: abs(g[1] - a))[1] if near else a)
            how.append('heard')
            continue
        guess = expect[k]
        near = [g for g in gaps if abs(g[1] - guess) <= 1.8 and g[1] >= lo]
        starts.append(min(near, key=lambda g: abs(g[1] - guess))[1] if near else max(guess, lo))
        how.append('share')
    ends = starts[1:] + [last]
    with wave.open(wav) as w:
        dur = w.getnframes() / w.getframerate()
    out.append({'id': sid, 'duration': round(dur, 3), 'sentences': [{'start': round(a, 2), 'end': round(b, 2), 'text': t} for a, b, t in zip(starts, ends, sents)]})
    print(f'{sid:11s}', ' '.join(f'{a:5.1f}{h[0]}' for a, h in zip(starts, how)))
json.dump(out, open('src/bn-timing.json', 'w'), ensure_ascii=False, indent=1)
