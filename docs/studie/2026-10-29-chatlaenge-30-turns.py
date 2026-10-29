#!/usr/bin/env python3
"""Chatlänge: Sind die Effekte noch sichtbar, wenn jeder Chat nur die ersten 30 Turns hat?

Rechnet aus dem Studien-Export (php artisan study:export-turns) die Anteile der
Fokus-Persona (Persona-Tag `focus persona: true`) in den Fenstern 1-30, 1-60 und
1-100 gesprochener Turns, die Effekte (Zelle A minus Zelle B), Gini, Stabilität
nach dem vorab festgelegten Kriterium aus 2026-10-08-chatlaenge.py (K=10 Turns,
DELTA=2 pp, kumulativer Anteil jedes Experten) und eine grobe Power-Schätzung.

Aufruf (auf einer KOPIE der Datenbank, nie auf database/database.sqlite):

    DB_DATABASE=/pfad/zur/kopie.sqlite php artisan study:export-turns /pfad/all.zip
    python3 -I docs/studie/2026-10-29-chatlaenge-30-turns.py --export /pfad/all.zip
    # optional: Gegenprobe gegen project:metrics --trajectory (braucht DB_DATABASE der Kopie)
    DB_DATABASE=/pfad/zur/kopie.sqlite python3 -I docs/studie/2026-10-29-chatlaenge-30-turns.py \
        --export /pfad/all.zip --verify

Schreibt 2026-10-29-chatlaenge-30-turns.json neben dieses Skript (--out ändert das Ziel)
und druckt Markdown-Tabellen. `--selftest` prüft Gini und Kriterium an Extremfällen.

Zählregeln wie SpokenTurns: gesprochen = `spoken` = 1 (words IS NOT NULL), Wörter statt Tokens,
alle Teilnehmenden zählen (auch mit 0 Turns). Ein Fenster 1-W nimmt die ersten W gesprochenen
Turns; der Anteil in 1-W ist zugleich der kumulative Anteil bei Turn W.
"""

from __future__ import annotations

import argparse
import csv
import io
import json
import os
import subprocess
import sys
import zipfile
from concurrent.futures import ThreadPoolExecutor
from pathlib import Path

import numpy as np
import pandas as pd
from scipy import stats

HERE = Path(__file__).resolve().parent
REPO = HERE.parent.parent
STEM = "2026-10-29-chatlaenge-30-turns"

WINDOWS = (30, 60, 100)
K, DELTA, ROLL = 10, 0.02, 20          # Kriterium und Fenster des gleitenden Anteils (wie 08.10.)
BLOCK = 10
SIMS, RNG_SEED = 300, 42
Z_POWER = stats.norm.ppf(0.975) + stats.norm.ppf(0.80)   # alpha 0,05 zweiseitig, Power 80 %

TOPICS = {"phones": "School", "company": "Office", "voting": "Family", "players": "Sport"}
PIPE = {"ScorePipeline": "Score", "OrchestratorPipeline": "Orchestrator", "QualityPipeline": "Quality",
        "BlindQualityPipeline": "BlindQuality", "RoundRobinPipeline": "RoundRobin",
        "JevScorePipeline": "JevScore", "JevOrchestratorPipeline": "JevOrchestrator",
        "JevQualityPipeline": "JevQuality", "JevBlindQualityPipeline": "JevBlindQuality"}


# --- Metrik- und Kriterium-Bausteine (Kriterium unverändert aus 2026-10-08-chatlaenge.py) ------------

def gini(values) -> float:
    """Wie App\\Discussion\\Metrics\\Gini::of."""
    v = np.sort(np.asarray(values, dtype=float))
    n, total = len(v), v.sum()
    if n == 0 or total <= 0:
        return 0.0
    return float(2 * np.sum(np.arange(1, n + 1) * v) / (n * total) - (n + 1) / n)


def settling_turn(shares: np.ndarray, k: int = K, delta: float = DELTA):
    """T (1-basiert) nach dem vorab festgelegten Kriterium, None wenn nie stabil. shares: (N, E)."""
    n = shares.shape[0]
    if n <= k:
        return None
    m = n - k
    later = np.arange(m)[:, None] + np.arange(1, k + 1)[None, :]
    ok = np.abs(shares[later] - shares[:m, None, :]).max(axis=(1, 2)) <= delta + 1e-12
    if not ok[-1]:
        return None
    failing = np.nonzero(~ok)[0]
    return 1 if failing.size == 0 else int(failing[-1]) + 2


def cumulative(spk: np.ndarray, amount: np.ndarray, e: int) -> np.ndarray:
    onehot = np.zeros((len(spk), e))
    onehot[np.arange(len(spk)), spk] = amount
    tot = np.cumsum(onehot, axis=0)
    return tot / tot.sum(axis=1, keepdims=True)


def rolling(spk: np.ndarray, amount: np.ndarray, e: int, window: int = ROLL) -> np.ndarray:
    """Anteil über die letzten `window` Turns (wächst bis dahin), wie ShareTrajectory."""
    onehot = np.zeros((len(spk), e))
    onehot[np.arange(len(spk)), spk] = amount
    c = np.cumsum(onehot, axis=0)
    c[window:] -= c[:-window]
    return c / c.sum(axis=1, keepdims=True)


def iid_stable_probability(p: np.ndarray, n: int, rng: np.random.Generator) -> float:
    """Wie oft erfüllt ein Prozess ohne Dynamik (jeder Turn unabhängig mit Anteilen p) das Kriterium in n Turns."""
    p = np.asarray(p, float) / np.sum(p)
    hits = 0
    for _ in range(SIMS):
        spk = rng.choice(len(p), size=n, p=p)
        hits += settling_turn(cumulative(spk, np.ones(n), len(p))) is not None
    return hits / SIMS


def selftest() -> None:
    assert abs(gini([1, 1, 1, 1])) < 1e-12
    assert abs(gini([4, 0, 0, 0]) - 0.75) < 1e-12
    always = cumulative(np.zeros(30, dtype=int), np.ones(30), 4)
    assert settling_turn(always) == 1
    assert settling_turn(always[:10]) is None
    takeover = cumulative(np.array([0] * 14 + [1] * 16), np.ones(30), 4)
    assert settling_turn(takeover) is None
    r = rolling(np.array([0, 0, 1, 1]), np.ones(4), 2, window=2)
    assert np.allclose(r[3], [0, 1])
    print("selftest ok")


# --- Daten --------------------------------------------------------------------------------------

def read_export(path: Path):
    with zipfile.ZipFile(path) as z:
        names = {Path(n).name: n for n in z.namelist()}
        return tuple(pd.read_csv(io.BytesIO(z.read(names[f]))) for f in ("runs.csv", "participants.csv", "turns.csv"))


def topic_of(description: str) -> str:
    for key, label in TOPICS.items():
        if key in description:
            return label
    return description[:20]


def window_metrics(spk: np.ndarray, words: np.ndarray, e: int, focus: int, w: int) -> dict:
    ct = np.bincount(spk[:w], minlength=e)
    cw = np.bincount(spk[:w], weights=words[:w], minlength=e)
    return {"turn_share": ct[focus] / ct.sum(), "word_share": cw[focus] / cw.sum(),
            "gini_turns": gini(ct), "gini_words": gini(cw)}


def analyse_run(run: pd.Series, parts: pd.DataFrame, turns: pd.DataFrame, rng) -> dict | None:
    sp = turns[turns.spoken == 1].sort_values("turn_index")
    if len(sp) < min(WINDOWS):
        return None
    # Teilnehmende: Gesetzte plus alle, die gesprochen haben (SpokenTurns::participants).
    uuids = list(parts.expert_uuid) + [u for u in sp.expert_uuid.unique() if u not in set(parts.expert_uuid)]
    col = {u: i for i, u in enumerate(uuids)}
    focus_rows = parts[parts["focus persona"].astype(str).str.lower() == "true"]
    assert len(focus_rows) == 1, f"{run.run_uuid}: {len(focus_rows)} Fokus-Personas"
    focus = col[focus_rows.expert_uuid.iloc[0]]
    e = len(uuids)
    spk = sp.expert_uuid.map(col).to_numpy()
    words = sp.words.to_numpy(float)
    ct, cw = cumulative(spk, np.ones(len(spk)), e), cumulative(spk, words, e)
    rt, rw = rolling(spk, np.ones(len(spk)), e), rolling(spk, words, e)
    rr = run.pipeline == "RoundRobinPipeline"

    out = {"run_uuid": run.run_uuid, "title": run.title, "group": run.group, "pipeline": run.pipeline,
           "model": run.model, "condition": run["status condition"], "status_seat": run["Status seat"],
           "gender": run["gender composition"], "topic": topic_of(run.description),
           "spoken": len(sp), "participants": e, "focus_seat": int(focus_rows.seat.iloc[0]), "windows": {}}
    for w in WINDOWS:
        if len(sp) < w:
            continue
        m = window_metrics(spk, words, e, focus, w)
        m["T_turns"] = None if rr else settling_turn(ct[:w])
        m["T_words"] = settling_turn(cw[:w])
        m["T_rolling_turns"] = None if rr else settling_turn(rt[:w])
        m["T_rolling_words"] = settling_turn(rw[:w])
        m["iid_p_stable_turns"] = None if rr else iid_stable_probability(ct[w - 1], w, rng)
        m["rolling_turn_share"] = float(rt[w - 1, focus])
        m["rolling_word_share"] = float(rw[w - 1, focus])
        out["windows"][str(w)] = m
    # Bewegung nach Turn 30
    out["moves"] = {}
    for w in (60, 100):
        if len(sp) >= w:
            d_t, d_w = ct[w - 1] - ct[29], cw[w - 1] - cw[29]
            out["moves"][f"30_{w}"] = {"focus_turn": float(d_t[focus]), "focus_word": float(d_w[focus]),
                                       "any_turn_abs": float(np.abs(d_t).max()), "any_word_abs": float(np.abs(d_w).max())}
    if len(sp) >= 100:
        d = ct[99] - ct[59], cw[99] - cw[59]
        out["moves"]["60_100"] = {"focus_turn": float(d[0][focus]), "focus_word": float(d[1][focus]),
                                  "any_turn_abs": float(np.abs(d[0]).max()), "any_word_abs": float(np.abs(d[1]).max())}
    nb = len(sp) // BLOCK
    out["blocks_turn"] = [float(np.mean(spk[i * BLOCK:(i + 1) * BLOCK] == focus)) for i in range(nb)]
    out["blocks_word"] = [float(words[i * BLOCK:(i + 1) * BLOCK][spk[i * BLOCK:(i + 1) * BLOCK] == focus].sum()
                                / words[i * BLOCK:(i + 1) * BLOCK].sum()) for i in range(nb)]
    out["_cum"] = {"ct": ct, "cw": cw, "cols": uuids, "focus": focus}
    return out


# --- Kontraste -----------------------------------------------------------------------------------

POS_GROUPS = ["Pos. Status", "Pos. Status (Luna)", "Pos. Status (Haiku)"]


def build_contrasts(runs: list[dict]) -> list[dict]:
    """Jeder Kontrast: A (Zelle mit Manipulation) minus B (Referenz), gepaart über das Thema."""
    pipes = lambda g: sorted({r["pipeline"] for r in runs if r["group"] == g})
    sel = lambda **kw: {"filter": kw}
    c = []
    for g in POS_GROUPS + ["Pos. Status (Luna, JEV)"]:
        for p in pipes(g):
            c.append({"family": "status", "series": g, "pipeline": p, "label": "mit Titel minus Kontrolle",
                      "specs": [(dict(group=g, pipeline=p, condition="pos-status"), dict(group=g, pipeline=p, condition="eq-status"))]})
    for p in sorted({r["pipeline"] for r in runs if r["group"] in POS_GROUPS}):
        c.append({"family": "status", "series": "gepoolt mini+Luna+Haiku", "pipeline": p, "label": "mit Titel minus Kontrolle",
                  "specs": [(dict(group=g, pipeline=p, condition="pos-status"), dict(group=g, pipeline=p, condition="eq-status"))
                            for g in POS_GROUPS if p in pipes(g)]})
    for p in pipes("Neg. Status (Luna)"):
        c.append({"family": "neg", "series": "Neg. Status (Luna)", "pipeline": p, "label": "invertiert minus Kontrolle",
                  "specs": [(dict(group="Neg. Status (Luna)", pipeline=p, condition="neg-status"),
                             dict(group="Pos. Status (Luna)", pipeline=p, condition="eq-status"))]})
    for p in pipes("Seat test"):
        for seat in (1.0, 3.0):
            c.append({"family": "seat", "series": f"Sitztest, Sitz {int(seat)}", "pipeline": p, "label": "mit Titel minus Kontrolle",
                      "specs": [(dict(group="Seat test", pipeline=p, condition="pos-status", status_seat=seat),
                                 dict(group="Seat test", pipeline=p, condition="eq-status", status_seat=seat))]})
        for cond in ("pos-status", "eq-status"):
            c.append({"family": "seat", "series": f"Sitztest, {cond}", "pipeline": p, "label": "Sitz 3 minus Sitz 1",
                      "specs": [(dict(group="Seat test", pipeline=p, condition=cond, status_seat=3.0),
                                 dict(group="Seat test", pipeline=p, condition=cond, status_seat=1.0))]})
    for p in pipes("Gender"):
        for a, b, lab in (("one female", "all female", "einzige Frau minus Frau unter Frauen"),
                          ("one male", "all male", "einziger Mann minus Mann unter Männern"),
                          ("all female", "all male", "Frau unter Frauen minus Mann unter Männern")):
            c.append({"family": "gender", "series": "Gender", "pipeline": p, "label": lab,
                      "specs": [(dict(group="Gender", pipeline=p, gender=a), dict(group="Gender", pipeline=p, gender=b))]})
    return c


def pick(runs, flt):
    return [r for r in runs if all((r[k] == v) or (pd.isna(v) and pd.isna(r[k])) for k, v in flt.items())]


def summarise_contrast(c: dict, runs: list[dict]) -> dict:
    res = {k: c[k] for k in ("family", "series", "pipeline", "label")}
    res["windows"] = {}
    for w in map(str, WINDOWS):
        for unit in ("turn_share", "word_share", "gini_turns", "gini_words"):
            a_vals, b_vals, diffs = [], [], []
            for fa, fb in c["specs"]:
                A = {(r["topic"]): r for r in pick(runs, fa) if w in r["windows"]}
                B = {(r["topic"]): r for r in pick(runs, fb) if w in r["windows"]}
                for t in sorted(set(A) & set(B)):
                    a, b = A[t]["windows"][w][unit], B[t]["windows"][w][unit]
                    a_vals.append(a); b_vals.append(b); diffs.append(a - b)
            if not diffs:
                continue
            d = np.array(diffs) * (100 if "share" in unit else 1)
            n = len(d)
            sd = float(d.std(ddof=1)) if n > 1 else None
            ci = None
            if n > 1:
                half = stats.t.ppf(0.975, n - 1) * sd / np.sqrt(n)
                ci = [float(d.mean() - half), float(d.mean() + half)]
            f = 100 if "share" in unit else 1
            res["windows"].setdefault(w, {})[unit] = {
                "n_pairs": n, "mean_A": float(np.mean(a_vals) * f), "sd_A": float(np.std(a_vals, ddof=1) * f) if n > 1 else None,
                "mean_B": float(np.mean(b_vals) * f), "sd_B": float(np.std(b_vals, ddof=1) * f) if n > 1 else None,
                "effect": float(d.mean()), "sd_pair_diff": sd, "ci95_paired": ci,
                "n_positive": int((d > 0).sum()), "n_negative": int((d < 0).sum()),
                "pairs": [round(float(x), 3) for x in d],
            }
    return res


def power_estimates(contrasts: list[dict]) -> list[dict]:
    """Grobe Schätzung gepaarter Differenzen: n = (Z * sd / |effekt|)^2 (Normalnäherung, ohne t-Korrektur)."""
    rows = []
    for c in contrasts:
        if c["family"] != "status" or c["pipeline"] == "RoundRobinPipeline":
            continue
        for unit in ("turn_share", "word_share"):
            w = c["windows"]
            if not all(k in w and unit in w[k] for k in ("30", "60")):
                continue
            e30, e60 = w["30"][unit], w["60"][unit]
            need = lambda sd, eff: None if (sd is None or abs(eff) < 1e-9) else float((Z_POWER * sd / abs(eff)) ** 2)
            rows.append({"series": c["series"], "pipeline": c["pipeline"], "unit": unit,
                         "sd30": e30["sd_pair_diff"], "sd60": e60["sd_pair_diff"],
                         "effect30": e30["effect"], "effect60": e60["effect"],
                         "n_own_30": need(e30["sd_pair_diff"], e30["effect"]),
                         "n_own_60": need(e60["sd_pair_diff"], e60["effect"]),
                         "n_30_for_effect_60": need(e30["sd_pair_diff"], e60["effect"])})
    return rows


def summary(contrasts: list[dict]) -> dict:
    """Vorzeichen, Rangfolge der Pipelines und Streuungsverhältnis 30 gegen 60."""
    out = {"sign": [], "ranking": [], "sd_ratio": []}
    for c in contrasts:
        for unit in ("turn_share", "word_share"):
            w = c["windows"]
            if "30" in w and "60" in w and unit in w["30"] and unit in w["60"]:
                e30, e60 = w["30"][unit]["effect"], w["60"][unit]["effect"]
                out["sign"].append({"family": c["family"], "series": c["series"], "pipeline": c["pipeline"], "label": c["label"],
                                    "unit": unit, "effect30": e30, "effect60": e60,
                                    "same_sign": bool(np.sign(round(e30, 6)) == np.sign(round(e60, 6)))})
                if c["pipeline"] != "RoundRobinPipeline" or unit == "word_share":
                    for side in ("sd_A", "sd_B", "sd_pair_diff"):
                        a, b = w["30"][unit][side], w["60"][unit][side]
                        if a is not None and b:
                            out["sd_ratio"].append({"series": c["series"], "pipeline": c["pipeline"], "unit": unit, "which": side,
                                                    "sd30": a, "sd60": b, "ratio": a / b})
    keyf = lambda c: (c["family"], c["series"], c["label"])
    for key in sorted({keyf(c) for c in contrasts}):
        group = [c for c in contrasts if keyf(c) == key and len(c["windows"]) >= 2]
        for unit in ("turn_share", "word_share"):
            row = {"family": key[0], "series": key[1], "label": key[2], "unit": unit}
            for w in ("30", "60"):
                eff = {PIPE[c["pipeline"]]: c["windows"][w][unit]["effect"] for c in group if w in c["windows"] and unit in c["windows"][w]}
                row[f"order_{w}"] = sorted(eff, key=lambda k: -eff[k])
                row[f"effects_{w}"] = eff
            out["ranking"].append(row)
    return out


# --- Gegenprobe gegen project:metrics --trajectory -------------------------------------------------

def verify(runs: list[dict], db: str) -> dict:
    import sqlite3
    con = sqlite3.connect(f"file:{db}?mode=ro", uri=True)
    ids = dict(con.execute("select uuid, id from projects"))
    env = {**os.environ, "DB_DATABASE": db}

    def check(r):
        out = subprocess.run(["php", str(REPO / "artisan"), "project:metrics", str(ids[r["run_uuid"]]), "--trajectory",
                              f"--window={ROLL}", "--format=csv"], capture_output=True, text=True, cwd=REPO, env=env, check=True).stdout
        rows = list(csv.DictReader(io.StringIO(out)))
        worst = 0.0
        for row in rows:
            t = int(row["turn"])
            if t not in WINDOWS and t != r["spoken"]:
                continue
            # Teilnehmende über expert_id; die Export-Reihenfolge der Spalten ist uuid-basiert, daher über Sitz.
            seat = int(row["seat"]) if row["seat"] not in ("", None) else None
            if seat is None:
                continue
            idx = r["_seat_cols"].get(seat)
            if idx is None:
                continue
            for key, arr in (("cumulative_turns", r["_cum"]["ct"]), ("cumulative_words", r["_cum"]["cw"])):
                worst = max(worst, abs(float(row[key]) - arr[t - 1, idx]))
        return worst

    with ThreadPoolExecutor(6) as pool:
        diffs = list(pool.map(check, runs))
    return {"runs_checked": len(runs), "max_abs_difference": float(max(diffs))}


# --- Hauptteil ------------------------------------------------------------------------------------

def aggregates(runs: list[dict]) -> dict:
    """Stabilität, Bewegung nach Turn 30 und Zeitblöcke je Pipeline (ohne die 100-Turn-Läufe der Gruppe Chat length)."""
    base = [r for r in runs if r["group"] != "Chat length"]
    out = {"stability": [], "moves": [], "blocks": [], "chat_length_100": []}
    for p in sorted({r["pipeline"] for r in base}):
        rs = [r for r in base if r["pipeline"] == p]
        for w in ("30", "60"):
            row = {"pipeline": p, "window": int(w), "runs": len(rs)}
            for key in ("T_turns", "T_words", "T_rolling_turns", "T_rolling_words"):
                vals = [r["windows"][w][key] for r in rs]
                if all(v is None for v in vals) and key.endswith("turns") and p.endswith("RoundRobinPipeline"):
                    row[key] = None
                else:
                    row[key] = sum(v is not None for v in vals)
            iid = [r["windows"][w]["iid_p_stable_turns"] for r in rs if r["windows"][w]["iid_p_stable_turns"] is not None]
            row["iid_p_stable_turns_mean"] = float(np.mean(iid)) if iid else None
            out["stability"].append(row)
        for cond in sorted({str(r["condition"]) for r in rs}):
            rc = [r for r in rs if str(r["condition"]) == cond]
            m = [r["moves"]["30_60"] for r in rc]
            for unit in ("turn", "word"):
                f = np.array([x[f"focus_{unit}"] for x in m]) * 100
                a = np.array([x[f"any_{unit}_abs"] for x in m]) * 100
                out["moves"].append({"pipeline": p, "condition": cond, "unit": unit, "runs": len(rc),
                                     "focus_signed_mean": float(f.mean()), "focus_abs_mean": float(np.abs(f).mean()),
                                     "focus_abs_max": float(np.abs(f).max()), "any_abs_mean": float(a.mean()), "any_abs_max": float(a.max())})
            out["blocks"].append({"pipeline": p, "condition": cond, "runs": len(rc),
                                  "turn_share_by_block": [float(np.mean([r["blocks_turn"][i] for r in rc]) * 100) for i in range(6)],
                                  "word_share_by_block": [float(np.mean([r["blocks_word"][i] for r in rc]) * 100) for i in range(6)]})
    for r in runs:
        if r["group"] == "Chat length":
            out["chat_length_100"].append({"title": r["title"], "pipeline": r["pipeline"], "topic": r["topic"],
                                           **{f"turn_share_{w}": r["windows"][w]["turn_share"] * 100 for w in r["windows"]},
                                           **{f"word_share_{w}": r["windows"][w]["word_share"] * 100 for w in r["windows"]},
                                           "moves": r["moves"], "blocks_turn": r["blocks_turn"],
                                           "T_turns": {w: r["windows"][w]["T_turns"] for w in r["windows"]},
                                           "T_words": {w: r["windows"][w]["T_words"] for w in r["windows"]}})
    return out


def main() -> int:
    ap = argparse.ArgumentParser()
    ap.add_argument("--export", type=Path)
    ap.add_argument("--out", type=Path, default=HERE / f"{STEM}.json")
    ap.add_argument("--verify", action="store_true")
    ap.add_argument("--selftest", action="store_true")
    args = ap.parse_args()
    selftest()
    if args.selftest:
        return 0
    if not args.export:
        ap.error("--export fehlt")

    runs_df, parts_df, turns_df = read_export(args.export)
    rng = np.random.default_rng(RNG_SEED)
    runs, dropped = [], []
    for _, run in runs_df.iterrows():
        r = analyse_run(run, parts_df[parts_df.run_uuid == run.run_uuid], turns_df[turns_df.run_uuid == run.run_uuid], rng)
        if r is None:
            dropped.append({"title": run.title, "group": run.group, "pipeline": run.pipeline, "spoken": int(run.turns_spoken)})
        else:
            runs.append(r)
    seats = parts_df.set_index(["run_uuid", "expert_uuid"]).seat.to_dict()
    for r in runs:
        r["_seat_cols"] = {int(seats[(r["run_uuid"], u)]): i for i, u in enumerate(r["_cum"]["cols"]) if (r["run_uuid"], u) in seats}

    contrasts = [summarise_contrast(c, [r for r in runs if r["group"] != "Chat length"]) for c in build_contrasts(runs)]
    result = {
        "criterion": {"k": K, "delta": DELTA, "rolling_window": ROLL, "sims": SIMS, "rng_seed": RNG_SEED, "block": BLOCK,
                      "z_power_80_alpha_05": float(Z_POWER)},
        "dropped_runs_lt_30_spoken": dropped,
        "runs": [{k: v for k, v in r.items() if not k.startswith("_")} for r in runs],
        "contrasts": contrasts,
        "power": power_estimates(contrasts),
        "aggregates": aggregates(runs),
        "summary": summary(contrasts),
    }
    if args.verify:
        result["verification"] = verify(runs, os.environ["DB_DATABASE"])
        print("Gegenprobe:", result["verification"])
    args.out.write_text(json.dumps(result, ensure_ascii=False, indent=1, default=float))
    print(f"{len(runs)} Läufe ausgewertet, {len(dropped)} mit weniger als 30 gesprochenen Turns weggelassen -> {args.out}")
    return 0


if __name__ == "__main__":
    sys.exit(main())
