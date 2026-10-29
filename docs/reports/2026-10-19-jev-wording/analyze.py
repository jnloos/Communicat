# Compares the four wordings. Reads cache-V*.jsonl and meta.json; writes results-wording.md and .json.
import json, math, random, sys, os
D = os.path.dirname(os.path.abspath(__file__))
meta = {int(k): v for k, v in json.load(open(f'{D}/meta.json')).items()}
VARIANTS = ['V0', 'V1', 'V2', 'V3']
LABEL = {'V0': 'V0 „trägt am meisten bei“ (heute)', 'V1': 'V1 „wahrscheinlich als Nächstes“', 'V2': 'V2 „ist als Nächstes dran“', 'V3': 'V3 „sollte als Nächstes sprechen“'}

cache = {}
for v in VARIANTS:
    p = f'{D}/cache-{v}.jsonl'
    cache[v] = {}
    if os.path.exists(p):
        for line in open(p):
            r = json.loads(line)
            cache[v][r['key']] = r

def get(v, cell, t, order, roster):
    r = cache[v].get(f'{cell}:{t}:{order}:{roster}')
    return r['probs'] if r and r['probs'] else None

def argmax(p):  # first of the leaders in listed order, as ties are rare and unbiased enough for a rate
    top = max(p.values()); return [k for k, x in p.items() if x == top][0]

def entropy(p):
    return -sum(x * math.log2(x) for x in p.values() if x > 0)

def mean(xs):
    xs = [x for x in xs if x is not None]; return sum(xs) / len(xs) if xs else float('nan')

def boot(by_cell, reps=2000, seed=7):
    """Percentile CI of the overall mean, resampling states within cells (cells kept)."""
    rnd = random.Random(seed); ms = []
    for _ in range(reps):
        tot = n = 0
        for xs in by_cell.values():
            if xs:
                for _ in xs:
                    tot += xs[rnd.randrange(len(xs))]; n += 1
        ms.append(tot / n)
    ms.sort(); return ms[int(.025 * reps)], ms[int(.975 * reps)]

def rate(v, order, pred):
    """Share of states (roster own, turn >= 2 unless noted) where pred(state, probs, cand) is 1/0; None skips."""
    by = {}
    for c, m in meta.items():
        xs = []
        for s in m['states']:
            p = get(v, c, s['t'], order, 'own')
            if p is None: continue
            x = pred(s, p, m)
            if x is not None: xs.append(x)
        by[c] = xs
    allx = [x for xs in by.values() for x in xs]
    return (mean(allx), len(allx)) if not allx else (mean(allx), len(allx))

res = {v: {} for v in VARIANTS}
for v in VARIANTS:
    for order in ['own', 'shuf']:
        R = {}
        R['n_states'] = sum(1 for c, m in meta.items() for s in m['states'] if get(v, c, s['t'], order, 'own'))
        R['modal'] = rate(v, order, lambda s, p, m: None if not s['modal'] or s['t'] < 2 else float(argmax(p) in s['modal']))[0]
        R['modal_chance'] = rate(v, order, lambda s, p, m: None if not s['modal'] or s['t'] < 2 else len(s['modal']) / len(p))[0]
        R['repeat_last'] = rate(v, order, lambda s, p, m: None if s['last'] is None else float(argmax(p) == s['last']))[0]
        R['luna_repeat'] = mean([float(s['actual'] == s['last']) for m in meta.values() for s in m['states'] if s['last']])
        R['addr_follow'] = rate(v, order, lambda s, p, m: None if not s['last_addressee'] else float(argmax(p) == s['last_addressee']))[0]
        R['addr_chance'] = rate(v, order, lambda s, p, m: None if not s['last_addressee'] else 1 / len(p))[0]
        R['addr_n'] = sum(1 for m in meta.values() for s in m['states'] if s['last_addressee'])
        R['agree_luna'] = rate(v, order, lambda s, p, m: float(argmax(p) == s['actual']))[0]
        R['entropy'] = rate(v, order, lambda s, p, m: entropy(p))[0]
        R['p_top'] = rate(v, order, lambda s, p, m: max(p.values()))[0]
        R['p_first_listed'] = mean([get(v, c, s['t'], order, 'own')[list(get(v, c, s['t'], order, 'own').keys())[0]]
                                    for c, m in meta.items() for s in m['states'] if get(v, c, s['t'], order, 'own')])
        R['argmax_is_first_listed'] = rate(v, order, lambda s, p, m: float(argmax(p) == list(p.keys())[0]))[0]
        R['p_focus'] = {}
        for kind in ['pos', 'eq', 'neg']:
            R['p_focus'][kind] = mean([get(v, c, s['t'], order, 'own')[m['focus']] for c, m in meta.items() if m['kind'] == kind
                                       for s in m['states'] if get(v, c, s['t'], order, 'own')])
        # argmax share of the focus persona's seat in the actual conversation (what Luna did) for reference
        R['focus_argmax'] = {}
        for kind in ['pos', 'eq', 'neg']:
            R['focus_argmax'][kind] = mean([float(argmax(get(v, c, s['t'], order, 'own')) == m['focus']) for c, m in meta.items() if m['kind'] == kind
                                            for s in m['states'] if get(v, c, s['t'], order, 'own')])
        # role effect: P(focus) with the status roster minus with the equal roster, paired on the same state
        R['role_effect'] = {}
        for kind in ['pos', 'neg', 'eq']:
            by = {}
            for c, m in meta.items():
                if m['kind'] != kind: continue
                xs = []
                for s in m['states']:
                    a, b = get(v, c, s['t'], order, 'own'), get(v, c, s['t'], order, 'alt')
                    if a and b:
                        # pos/neg: own roster carries the manipulation, alt is equal; eq: alt carries the manipulation (pos twin's roles)
                        xs.append((b[m['focus']] - a[m['focus']]) if kind == 'eq' else (a[m['focus']] - b[m['focus']]))
                by[c] = xs
            allx = [x for xs in by.values() for x in xs]
            if allx:
                lo, hi = boot(by)
                R['role_effect'][kind] = (mean(allx), lo, hi, len(allx))
        res[v][order] = R
    # stability of the argmax between conditions (roster own, turn all)
    def agree(o1, r1, o2, r2):
        xs = []
        for c, m in meta.items():
            for s in m['states']:
                a, b = get(v, c, s['t'], o1, r1), get(v, c, s['t'], o2, r2)
                if a and b: xs.append(float(argmax(a) == argmax(b)))
        return mean(xs)
    res[v]['stab_order'] = agree('own', 'own', 'shuf', 'own')
    res[v]['stab_roster_own_order'] = agree('own', 'own', 'own', 'alt')
    res[v]['stab_roster_shuf_order'] = agree('shuf', 'own', 'shuf', 'alt')

json.dump(res, open(f'{D}/results-wording.json', 'w'), indent=1)

pc = lambda x: f'{100 * x:.1f} %'
L = ['# Wording-Vergleich Jev-Orchestrator (Stage-0-Replay)', '',
     'Zustände: 12 Luna-Orchestrator-Zellen × 60 Turns; Long-Term leer (nicht pro Turn gespeichert). Je Variante Original- und gemischte Listenreihenfolge (SeededShuffle), je mit eigenem und getauschtem Roster. Modalsprecher, Letzter-Sprecher, Adressat nur ab Turn 2.', '']
def table(title, rows, header):
    L.extend([f'## {title}', '', '| ' + ' | '.join(header) + ' |', '|' + '---|' * len(header)])
    for r in rows: L.append('| ' + ' | '.join(r) + ' |')
    L.append('')
for order, name in [('own', 'Originalreihenfolge'), ('shuf', 'Gemischte Reihenfolge')]:
    rows = []
    for v in VARIANTS:
        R = res[v][order]
        rows.append([LABEL[v], str(R['n_states']), pc(R['modal']), pc(R['repeat_last']), pc(R['addr_follow']), pc(R['agree_luna']), f"{R['entropy']:.2f}", f"{R['p_top']:.2f}", pc(R['argmax_is_first_listed'])])
    ref = res['V0'][order]
    rows.append(['Zufall / Luna', '', pc(ref['modal_chance']), f"{pc(ref['luna_repeat'])} (Luna)", f"{pc(ref['addr_chance'])} (n={ref['addr_n']})", '≈ 25–33 %', '2.00 max', '', '25 %'])
    table(f'Verhalten – {name} (Roster wie im Lauf)', rows, ['Variante', 'Zustände', 'Argmax = Modalsprecher', 'Argmax = letzter Sprecher', 'Argmax = Adressat', 'Argmax = Lunas Sprecher', 'Entropie (bit)', 'P(Top)', 'Argmax = zuerst gelistet'])
rows = []
for v in VARIANTS:
    for order in ['own', 'shuf']:
        R = res[v][order]; E = R['role_effect']
        f = lambda k: (f"{100 * E[k][0]:+.1f} pp [{100 * E[k][1]:+.1f}; {100 * E[k][2]:+.1f}]" if k in E else '–')
        rows.append([LABEL[v], 'Original' if order == 'own' else 'gemischt', f('pos'), f('neg'), f('eq'), pc(R['p_focus']['pos']), pc(R['p_focus']['eq']), pc(R['p_focus']['neg'])])
table('Rolleneffekt auf P(Fokuspersona), gepaart (Status minus gleiche Rollen; 95 %-KI, Bootstrap innerhalb der Zellen)', rows,
      ['Variante', 'Reihenfolge', 'Pos. Status', 'Neg. Status', 'Eq + Titel', 'P(Fokus) pos', 'P(Fokus) eq', 'P(Fokus) neg'])
rows = [[LABEL[v], pc(res[v]['stab_order']), pc(res[v]['stab_roster_own_order']), pc(res[v]['stab_roster_shuf_order']), f"{res[v]['own']['p_first_listed']:.3f}", f"{res[v]['shuf']['p_first_listed']:.3f}"] for v in VARIANTS]
table('Stabilität des Argmax und Positionsbias', rows, ['Variante', 'gleich bei Reihenfolge-Wechsel', 'gleich bei Roster-Tausch (Original)', 'gleich bei Roster-Tausch (gemischt)', 'mittleres P(zuerst gelistet), Original', 'dito, gemischt'])
open(f'{D}/results-wording.md', 'w').write('\n'.join(L))
print('\n'.join(L))
