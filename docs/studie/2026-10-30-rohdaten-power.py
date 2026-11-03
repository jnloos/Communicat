"""Raw per-run data and the dispersion inputs for the power analysis (30.10.2026).

usage: python3 2026-10-30-rohdaten-power.py <sqlite copy> <out dir>
Writes runs.csv (one row per run) and prints the answers to the five questions of the
power memo. Reads a COPY of the database (sqlite3 .backup), never the live file.
Counting rule as everywhere: spoken = words IS NOT NULL (and expert_id not null).
"""
import collections, csv, math, os, sqlite3, statistics as st, sys

db = sqlite3.connect(sys.argv[1])
out = sys.argv[2]
q = lambda s, *a: db.execute(s, a).fetchall()
N = 30

focus = {r[0] for r in q("select taggable_id from taggables where taggable_type like '%Expert' and tag_value_id=9")}
tags = collections.defaultdict(dict)
names = {r[0]: r[1] for r in q("select id,name from tag_values")}
for pid, cat, val in q("select taggable_id,tag_category_id,tag_value_id from taggables where taggable_type like '%Project'"):
    tags[pid][cat] = names[val]
groups = {r[0]: r[1] for r in q("select id,name from project_groups")}
filed = {r[0]: r[1] for r in q("select project_id,project_group_id from project_contributors where contributor_type like '%User' and project_group_id is not null")}

rows = []
for pid, title, pipe, desc, seed, model in q("select id,title,pipeline,description,seed,model from projects order by id"):
    spoken = q("select expert_id,words from job_logs where project_id=? and words is not null and expert_id is not null order by turn_index limit ?", pid, N)
    if len(spoken) < N:
        continue
    experts = [r[0] for r in q("select contributor_id from project_contributors where project_id=? and contributor_type like '%Expert' order by seat", pid)]
    fseat = next((i + 1 for i, e in enumerate(experts) if e in focus), None)
    fw = sum(w for e, w in spoken if e in focus)
    parts = title.split(', ')
    rows.append(dict(
        project_id=pid, group=groups.get(filed.get(pid), ''), pipeline=pipe, topic=parts[1] if len(parts) > 1 else '', seed=seed, model=model,
        status=tags[pid].get(1, ''), status_seat=tags[pid].get(6, ''), gender=tags[pid].get(8, ''), origin=tags[pid].get(10, ''),
        agents=len(experts), focus_seat=fseat, experts=' '.join(map(str, experts)),
        turn_share=round(100 * sum(1 for e, _ in spoken if e in focus) / N, 2),
        word_share=round(100 * fw / sum(w for _, w in spoken), 2)))

with open(os.path.join(out, 'runs.csv'), 'w', newline='') as f:
    w = csv.DictWriter(f, fieldnames=list(rows[0]))
    w.writeheader(); w.writerows(rows)
print(len(rows), 'runs with >=', N, 'spoken turns ->', os.path.join(out, 'runs.csv'))

PIPES = ('ScorePipeline', 'OrchestratorPipeline', 'BlindQualityPipeline')
mini = [r for r in rows if r['model'] == 'openai-gpt-5-mini' and r['pipeline'] in PIPES]

def pairs(factor, a, b):
    """Paired differences a - b, i-th run of a with i-th run of b in the same cell (pipeline, topic, seat, agents, group)."""
    cells = collections.defaultdict(lambda: collections.defaultdict(list))
    for r in mini:
        if r[factor] in (a, b):
            cells[(r['pipeline'], r['topic'], r['status_seat'], r['agents'], r['group'] if factor == 'status' else '')][r[factor]].append(r)
    return [(k, x, y, x['turn_share'] - y['turn_share']) for k, d in cells.items() for x, y in zip(d[a], d[b])]

contrasts = {
    'H1 status (pos - eq)':                 ('status', 'pos-status', 'eq-status'),
    'H2 gender (one male - all female)':    ('gender', 'one male', 'all female'),
    'H2m mirror (one female - all male)':   ('gender', 'one female', 'all male'),
    'H3 origin (one nigerian - all german)': ('origin', 'one nigerian', 'all german'),
    'H3m mirror (one german - all nigerian)': ('origin', 'one german', 'all nigerian'),
}
print('\n== 1. SD of the paired differences (turn share, pp, first', N, 'turns) ==')
diffs = {}
for name, (fac, a, b) in contrasts.items():
    for agents in ((4, 3) if fac == 'status' else (3,)):
        d = [p for p in pairs(fac, a, b) if p[1]['agents'] == agents]
        diffs[(name, agents)] = d
        for pipe in PIPES + ('ALL',):
            v = [p[3] for p in d if pipe == 'ALL' or p[1]['pipeline'] == pipe]
            if len(v) > 1:
                print(f'{name:42} agents={agents} {pipe:22} n={len(v):3} mean={st.mean(v):6.1f} sd={st.stdev(v):5.1f}')

print('\n== 2. Same group, only the feature of the focus persona changes? ==')
for name in list(contrasts)[1:]:
    d = diffs[(name, 3)]
    same = sum(1 for _, x, y, _ in d if x['experts'].split()[1:] == y['experts'].split()[1:] and x['experts'].split()[0] != y['experts'].split()[0])
    print(f'{name:42} {len(d)} pairs, {same} with identical non-focus experts and a different focus record')

print('\n== 3. Runs with role status (gpt-5-mini, 3 pipelines) ==')
for k, v in sorted(collections.Counter((r['group'], r['status']) for r in mini if r['status']).items()):
    print(k, v)

print('\n== 4. Correlation of the two mirror contrasts (same topic, pipeline, seed) ==')
for lab, (n1, n2) in (('gender', ('H2 gender (one male - all female)', 'H2m mirror (one female - all male)')),
                      ('origin', ('H3 origin (one nigerian - all german)', 'H3m mirror (one german - all nigerian)'))):
    d1 = {(k[0], k[1]): x for k, _, _, x in diffs[(n1, 3)]}
    d2 = {(k[0], k[1]): x for k, _, _, x in diffs[(n2, 3)]}
    keys = sorted(set(d1) & set(d2))
    r = st.correlation([d1[k] for k in keys], [d2[k] for k in keys]) if len(keys) > 2 else float('nan')
    print(f'{lab}: {len(keys)} matched cells, r = {r:.2f}; seeds in the series: {sorted({x["seed"] for x in mini if x[lab]})}')
    for pipe in PIPES:
        kk = [k for k in keys if k[0] == pipe]
        if len(kk) > 2:
            print(f'   {pipe}: n={len(kk)} r = {st.correlation([d1[k] for k in kk], [d2[k] for k in kk]):.2f}')

print('\n== 5. Replicates of the same cell and ICC ==')
key = lambda r: (r['pipeline'], r['topic'], r['status'], r['status_seat'], r['gender'], r['origin'], r['agents'], r['experts'], r['seed'])
cells = collections.defaultdict(list)
for r in mini:
    cells[key(r)].append(r)
reps = {k: v for k, v in cells.items() if len(v) > 1}
print(len(reps), 'cells with >= 2 identical runs (same experts, seed, tags); groups:', dict(collections.Counter(tuple(sorted({x['group'] for x in v})) for v in reps.values())))
dev = [abs(v[0]['turn_share'] - v[1]['turn_share']) for v in reps.values()]
if dev:
    print(f'two runs of one cell differ by mean {st.mean(dev):.1f} pp, max {max(dev):.1f} pp; within-cell SD = {math.sqrt(sum(x * x for x in dev) / (2 * len(dev))):.1f}')
    print('(caveat: some replicates ran under older prompts, so this mixes run noise with prompt changes)')

def icc(vals):
    k = len(vals); n = sum(len(v) for v in vals.values())
    if k < 2 or n <= k:
        return None
    gm = sum(sum(v) for v in vals.values()) / n
    msb = sum(len(v) * (st.mean(v) - gm) ** 2 for v in vals.values()) / (k - 1)
    msw = sum(sum((x - st.mean(v)) ** 2 for x in v) for v in vals.values()) / (n - k)
    n0 = (n - sum(len(v) ** 2 for v in vals.values()) / n) / (k - 1)
    return (msb - msw) / (msb + (n0 - 1) * msw)

print('ICC(1) of topic (runs of one condition and pipeline, grouped by topic):')
for fac, cond in (('status', 'pos-status'), ('status', 'eq-status'), ('gender', 'one male'), ('gender', 'all female'), ('origin', 'one nigerian'), ('origin', 'all german')):
    for pipe in PIPES:
        v = collections.defaultdict(list)
        for r in mini:
            if r[fac] == cond and r['pipeline'] == pipe and r['agents'] == (4 if fac == 'status' else 3):
                v[r['topic']].append(r['turn_share'])
        i = icc(v)
        print(f'  {cond:13} {pipe:22} topics={len(v)} runs={sum(map(len, v.values()))} ICC={"n/a" if i is None else round(i, 2)}')
