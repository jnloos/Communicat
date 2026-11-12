"""Power inputs from the pilot runs (09.11.2026): dispersion, inventory, topic/name ICC, effects, tokens.

usage: python3 -I 2026-11-09-power-eckdaten.py <sqlite copy> <out prefix>
Writes <prefix>.json (all numbers) and <prefix>-runs.csv (one row per included run).
Reads a COPY of the database (VACUUM INTO), never the live file.

Outcome: turn share of the focus persona (persona tag `focus persona: true`) in the first 30 spoken turns, in pp.
Counting rule as everywhere: spoken = words IS NOT NULL and expert_id IS NOT NULL (SpokenTurns).
Filters: 3 seated experts; >= 30 spoken turns; a focus persona among them; no failed turn up to the 30th spoken
turn whose error is not a transport error (a refusal, an HTTP 4xx, an unreadable answer), counted from the first
spoken turn on (a failure before anyone spoke leaves nothing behind in the sequence). Turns that failed on transport
(connection refused, provider overloaded, Reverb down) are infrastructure: counted but not excluded. Calls of such a
turn that had already succeeded may have overwritten a short-term thought; that residue is accepted.
"""
import collections, csv, json, math, sqlite3, statistics as st, sys

import numpy as np

db = sqlite3.connect(sys.argv[1])
prefix = sys.argv[2]
q = lambda s, *a: db.execute(s, a).fetchall()
N = 30
FOCUS_VALUE = 9  # tag value `focus persona: true`
CATS = {1: 'status', 6: 'status_seat', 8: 'gender', 10: 'origin', 11: 'gender_sentence'}

focus = {r[0] for r in q("select taggable_id from taggables where taggable_type like '%Expert' and tag_value_id=?", FOCUS_VALUE)}
value_name = dict(q("select id,name from tag_values"))
tags = collections.defaultdict(dict)
for pid, cat, val in q("select taggable_id,tag_category_id,tag_value_id from taggables where taggable_type like '%Project'"):
    if cat in CATS:
        tags[pid][CATS[cat]] = value_name[val]
expert_name = dict(q("select id,name from experts"))

excluded = collections.Counter()
infra_failed_runs = 0
rows = []
for pid, title, pipe, model, seed in q("select id,title,pipeline,model,seed from projects order by id"):
    experts = [r[0] for r in q("select contributor_id from project_contributors where project_id=? and contributor_type like '%Expert' order by seat", pid)]
    if len(experts) != 3:
        continue
    spoken = q("select turn_index,expert_id,words from job_logs where project_id=? and words is not null and expert_id is not null order by turn_index limit ?", pid, N)
    if len(spoken) < N:
        excluded['fewer than 30 spoken turns (aborted or not finished)'] += 1
        continue
    last = spoken[-1][0]
    fp = [e for e in experts if e in focus]
    if len(fp) != 1:
        excluded['no single focus persona'] += 1
        continue
    # infrastructure = the turn failed on transport (connection refused, provider overloaded, Reverb down); anything
    # else (a refusal, an HTTP 4xx, an unreadable answer) means the model was reached and answered unusably
    failed = q("select j.id, not (j.error like '%ProviderConnectionException%' or j.error like '%ProviderOverloadedException%' "
               "or j.error like 'BroadcastException%') from job_logs j "
               "where j.project_id=? and j.turn_index between ? and ? and j.status='failed'", pid, spoken[0][0], last)
    if any(called for _, called in failed):
        excluded['failed turn with a model call in the window'] += 1
        continue
    infra_failed_runs += bool(failed)
    tok = q("select sum(tokens_in), sum(tokens_out), sum(tokens_cached), count(*), count(tokens_in), count(tokens_cached) "
            "from prompt_logs where status='ok' and job_log_id in (select id from job_logs where project_id=? and turn_index<=?)", pid, last)[0]
    t = tags[pid]
    cond = ' / '.join(f'{k}={t[k]}' for k in ('status', 'gender', 'origin', 'gender_sentence') if k in t) or 'none'
    parts = title.split(', ')
    rows.append(dict(
        project_id=pid, pipeline=pipe, model=model, topic=parts[1] if len(parts) > 1 else '', seed=seed,
        condition=cond, **{k: t.get(k, '') for k in CATS.values()},
        focus_seat=experts.index(fp[0]) + 1, focus_name=expert_name[fp[0]], focus_expert_id=fp[0],
        turn_share=100 * sum(1 for _, e, _ in spoken if e == fp[0]) / N,
        word_share=100 * sum(w for _, e, w in spoken if e == fp[0]) / sum(w for *_, w in spoken),
        tokens_in=tok[0] if tok[4] == tok[3] else None, tokens_out=tok[1] if tok[4] == tok[3] else None,
        tokens_cached=tok[2] if tok[5] == tok[3] else None, calls=tok[3]))

sd = lambda v: st.stdev(v) if len(v) > 1 else None
r1 = lambda x: None if x is None else round(x, 1)


def design(rs, factors):
    """Intercept plus treatment-coded dummies of the factors."""
    cols = [np.ones(len(rs))]
    for f in factors:
        levels = sorted({r[f] for r in rs})
        cols += [np.array([r[f] == l for r in rs], float) for l in levels[1:]]
    return np.column_stack(cols), np.array([r['turn_share'] for r in rs])


def residuals(rs, factors):
    X, y = design(rs, factors)
    beta, *_ = np.linalg.lstsq(X, y, rcond=None)
    return y - X @ beta, len(rs) - np.linalg.matrix_rank(X)


def residual_sd(rs, factors):
    """sqrt(RSS / (n - rank)) of an OLS of turn_share on the factors."""
    if not rs:
        return None, 0, 0
    e, df = residuals(rs, factors)
    return (math.sqrt((e ** 2).sum() / df) if df > 0 else None), len(rs), int(df)


def icc1(groups):
    """ICC(1) from one-way ANOVA, unbalanced (n0 correction); negative estimates are reported as they are."""
    k = len(groups); n = sum(map(len, groups.values()))
    if k < 2 or n <= k:
        return None
    gm = sum(map(sum, groups.values())) / n
    msb = sum(len(v) * (st.mean(v) - gm) ** 2 for v in groups.values()) / (k - 1)
    msw = sum(sum((x - st.mean(v)) ** 2 for x in v) for v in groups.values()) / (n - k)
    n0 = (n - sum(len(v) ** 2 for v in groups.values()) / n) / (k - 1)
    return (msb - msw) / (msb + (n0 - 1) * msw)


strata = sorted({(r['pipeline'], r['model']) for r in rows})
by = lambda p, m: [r for r in rows if r['pipeline'] == p and r['model'] == m]
out = dict(filters=dict(outcome='focus persona turn share, first 30 spoken turns, pp', agents=3, min_spoken=N,
                        included=len(rows), excluded=dict(excluded),
                        included_with_infrastructure_failures=infra_failed_runs))

# 1. dispersion: raw SD per pipeline x model, then residual SD after condition (+ topic, pipeline, model)
out['1_sd_raw'] = [dict(pipeline=p, model=m, n=len(by(p, m)), mean=r1(st.mean(x['turn_share'] for x in by(p, m))),
                        sd=r1(sd([x['turn_share'] for x in by(p, m)]))) for p, m in strata]
out['1_sd_residual'] = []
for p, m in strata:
    s, n, df = residual_sd(by(p, m), ('condition', 'topic'))
    out['1_sd_residual'].append(dict(pipeline=p, model=m, n=n, df=df, residual_sd=r1(s), formula='turn_share ~ condition + topic'))
for label in ['all'] + sorted({r['model'] for r in rows}):
    rs = [r for r in rows if label in ('all', r['model'])]
    s, n, df = residual_sd(rs, ('condition', 'pipeline', 'topic', 'model'))
    out['1_sd_residual'].append(dict(pipeline='pooled', model=label, n=n, df=df, residual_sd=r1(s),
                                     formula='turn_share ~ condition + pipeline + topic + model'))

# 2. inventory
inv = collections.Counter((r['pipeline'], r['condition'], r['topic'], r['model'], r['focus_seat']) for r in rows)
out['2_inventory'] = [dict(pipeline=k[0], condition=k[1], topic=k[2], model=k[3], focus_seat=k[4], runs=v) for k, v in sorted(inv.items())]

# 3. topics and names, on residuals after condition and model within a pipeline
out['3_topic_name'] = []
for p in sorted({r['pipeline'] for r in rows}):
    rs = [r for r in rows if r['pipeline'] == p]
    if len(rs) < 4:
        continue
    res, _ = residuals(rs, ('condition', 'model'))
    entry = dict(pipeline=p, n=len(rs), adjusted_for='condition + model')
    for key in ('topic', 'focus_name'):
        g = collections.defaultdict(list)
        for r, e in zip(rs, res):
            g[r[key]].append(float(e))
        i = icc1(g)
        entry[key] = dict(levels=len(g), runs_per_level=sorted(map(len, g.values())),
                          sd_of_means=r1(sd([st.mean(v) for v in g.values()])), icc1=None if i is None else round(i, 3))
    out['3_topic_name'].append(entry)

# 4. effects: mean turn share per condition
cell = collections.defaultdict(list)
for r in rows:
    cell[(r['pipeline'], r['model'], r['condition'])].append(r['turn_share'])
out['4_condition_means'] = [dict(pipeline=k[0], model=k[1], condition=k[2], n=len(v), mean=r1(st.mean(v)), sd=r1(sd(v)))
                            for k, v in sorted(cell.items())]

# 5. tokens per run (successful calls up to the 30th spoken turn; a sum only when every one reported the figure)
out['5_tokens'] = []
for p, m in strata:
    rs = by(p, m)
    e = dict(pipeline=p, model=m, n=len(rs))
    for k in ('tokens_in', 'tokens_cached', 'tokens_out'):
        v = [r[k] for r in rs if r[k] is not None]
        e[k] = dict(n=len(v), mean=round(st.mean(v)) if v else None, median=round(st.median(v)) if v else None)
    out['5_tokens'].append(e)

# where could "122 runs, SD 19.2" come from? The obvious subsets.
subsets = [('all 3-agent runs', rows)]
subsets += [(f'model={m}', [r for r in rows if r['model'] == m]) for m in sorted({r['model'] for r in rows})]
subsets += [(f'pipeline={p}', [r for r in rows if r['pipeline'] == p]) for p in sorted({r['pipeline'] for r in rows})]
out['check_122_runs_sd_19_2'] = [dict(subset=l, n=len(rs), sd=r1(sd([r['turn_share'] for r in rs]))) for l, rs in subsets]

with open(prefix + '.json', 'w') as f:
    json.dump(out, f, indent=1, ensure_ascii=False)
with open(prefix + '-runs.csv', 'w', newline='') as f:
    w = csv.DictWriter(f, fieldnames=list(rows[0]))
    w.writeheader()
    w.writerows({**r, 'turn_share': round(r['turn_share'], 2), 'word_share': round(r['word_share'], 2)} for r in rows)
print(json.dumps({k: out[k] for k in ('filters', '1_sd_raw', '1_sd_residual', '3_topic_name', '5_tokens', 'check_122_runs_sd_19_2')}, indent=1, ensure_ascii=False))
