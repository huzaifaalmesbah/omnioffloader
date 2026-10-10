"""Walkthrough narration: clips + word timings + caption chunks → src/wt-timing.json.

Usage: python3 scripts/voice-wt.py [scene-id …]   (no ids: every scene)
"""
import asyncio, json, re, subprocess, sys, os
import edge_tts

VOICE, RATE = 'en-US-AndrewMultilingualNeural', '+4%'
OUT = 'src/wt-timing.json'
os.makedirs('public/voice-wt', exist_ok=True)

def norm(s):
    return re.sub(r'[^a-z0-9]', '', s.lower())

def chunks(text, max_words=9):
    """Split into caption lines at sentence/clause ends, at most max_words each."""
    parts = re.split(r'(?<=[.?!:])\s+', text.strip())
    out = []
    for p in parts:
        words = p.split()
        n = -(-len(words) // max_words)
        per = -(-len(words) // n)
        for i in range(0, len(words), per):
            out.append(' '.join(words[i:i + per]))
    return out

async def scene(s):
    mp3 = f"public/voice-wt/{s['id']}.mp3"
    c = edge_tts.Communicate(s['text'], VOICE, rate=RATE, boundary='WordBoundary')
    words, audio = [], bytearray()
    async for ch in c.stream():
        if ch['type'] == 'audio':
            audio += ch['data']
        elif ch['type'] == 'WordBoundary':
            words.append([ch['text'], round(ch['offset'] / 1e7, 3), round((ch['offset'] + ch['duration']) / 1e7, 3)])
    open(mp3, 'wb').write(audio)
    dur = float(re.search(r'estimated duration: ([\d.]+)', subprocess.run(['afinfo', mp3], capture_output=True, text=True).stdout).group(1))
    # Align every text token to the boundary words, then time each caption line by its first token.
    starts, wi = [], 0
    for tok in s['text'].split():
        t = norm(tok)
        starts.append(words[wi][1] if wi < len(words) else dur)
        acc = ''
        while wi < len(words) and (len(acc) < len(t) or not t):
            acc += norm(words[wi][0])
            wi += 1
            if not t:
                break
    cues, k = [], 0
    for line in chunks(s['text']):
        cues.append({'start': starts[k], 'end': None, 'text': line})
        k += len(line.split())
    for i, cue in enumerate(cues):
        cue['end'] = cues[i + 1]['start'] if i + 1 < len(cues) else dur
    return {'id': s['id'], 'duration': dur, 'words': [[w[0], w[1]] for w in words], 'cues': cues}

async def main():
    script = json.load(open('walkthrough.json'))
    only = set(sys.argv[1:])
    old = {o['id']: o for o in json.load(open(OUT))} if os.path.exists(OUT) else {}
    res = []
    for s in script:
        res.append(await scene(s) if (not only or s['id'] in only or s['id'] not in old) else old[s['id']])
        print(f"{res[-1]['id']:10s} {res[-1]['duration']:6.2f}s")
    json.dump(res, open(OUT, 'w'), indent=0)
    print('total', round(sum(r['duration'] for r in res), 1))

asyncio.run(main())
