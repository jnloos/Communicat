#!/usr/bin/env python3
"""Chatlänge: ab welchem Turn pendeln sich die Redeanteile ein?

Liest die Verläufe über `php artisan project:metrics {id} --trajectory`
(ShareTrajectory, also dieselben Zählregeln wie SpeakingShares), wendet das
vorab festgelegte Stabilitätskriterium an und schreibt Rohdaten, Ergebnis-JSON
und Abbildungen neben dieses Skript.

    python3 docs/studie/2026-10-08-chatlaenge.py            # alles
    python3 docs/studie/2026-10-08-chatlaenge.py --selftest # nur das Kriterium prüfen

Stabilitätskriterium (vor der Auswertung festgelegt):
    Ein Lauf ist ab Turn T stabil, wenn sich für jeden Experten der Anteil über
    die folgenden K = 10 Turns um höchstens DELTA = 2 Prozentpunkte gegenüber
    seinem Wert in Turn T ändert, und dies für jeden späteren Turn bis zum
    Laufende ebenso gilt. Formal, mit x_e(t) dem Anteil von Experte e nach t
    gesprochenen Turns und N der Lauflänge:

        ok(s)  <=>  für alle e, j = 1..K:  |x_e(s + j) - x_e(s)| <= DELTA
        T      =   min { s : ok(s') für alle s' in [s, N - K] }

    Erfüllt schon s = N - K das Kriterium nicht, ist der Lauf nicht stabil
    (T = None). T kann höchstens N - K sein: Stabilität muss über zehn
    beobachtete Turns belegt sein. Ein 60-Turn-Lauf kann also höchstens ab
    Turn 50 stabil sein (Rechtszensur).

Methodischer Vorbehalt: der kumulative Anteil glättet sich rein rechnerisch
(nach n Turns verschiebt ein Turn ihn um höchstens ~1/n). Darum steht neben
jedem T ein Referenzwert T_iid: der Median von T über simulierte Läufe, in
denen jeder Turn unabhängig mit den Endanteilen des echten Laufs vergeben wird
-- ein stationärer Prozess ohne jede Dynamik. Liegt T nahe T_iid, ist das
Einpendeln durch Arithmetik erklärt; liegt es deutlich darüber, hat sich der
Prozess selbst erst später beruhigt.
"""

from __future__ import annotations

import argparse
import csv
import io
import json
import subprocess
import sys
from pathlib import Path

import numpy as np

HERE = Path(__file__).resolve().parent
REPO = HERE.parent.parent
STEM = "2026-10-08-chatlaenge"

K = 10
DELTA = 0.02
WINDOW = 20
SIMULATIONS = 1000
HORIZON = 400
RNG_SEED = 42

# Projekte: die vier 60-Turn-Serien und die sechs 100-Turn-Läufe.
SERIES_60 = {
    "ScorePipeline": range(16, 24),
    "OrchestratorPipeline": range(25, 33),
    "QualityPipeline": range(41, 49),
    "RoundRobinPipeline": range(49, 57),
}
LONG_RUNS = range(57, 63)

PIPELINE_LABEL = {
    "ScorePipeline": "Score",
    "OrchestratorPipeline": "Orchestrator",
    "QualityPipeline": "Quality",
    "RoundRobinPipeline": "Round Robin",
}

SERIES = ("cumulative_turns", "cumulative_words", "rolling_turns", "rolling_words")


# --- the criterion -----------------------------------------------------------

def settling_turn(shares: np.ndarray, k: int = K, delta: float = DELTA) -> int | None:
    """T for one run.

    shares: array (N, E), row t-1 = every expert's share after spoken turn t.
    Returns the 1-based turn T, or None if the run never settles.
    """
    n = shares.shape[0]
    if n <= k:
        return None

    # ok[s] for 0-based anchor s in 0..n-k-1: largest move over the next k turns.
    m = n - k
    later = np.arange(m)[:, None] + np.arange(1, k + 1)[None, :]
    moves = np.abs(shares[later] - shares[:m, None, :]).max(axis=(1, 2))
    ok = moves <= delta + 1e-12

    if not ok[-1]:
        return None

    # The earliest anchor from which every later anchor is ok as well.
    failing = np.nonzero(~ok)[0]
    return 1 if failing.size == 0 else int(failing[-1]) + 2


def cumulative_shares(speakers: np.ndarray, amounts: np.ndarray, experts: int) -> np.ndarray:
    """Cumulative shares from a sequence of speaker indices and their amounts."""
    onehot = np.zeros((len(speakers), experts))
    onehot[np.arange(len(speakers)), speakers] = amounts
    totals = np.cumsum(onehot, axis=0)
    return totals / totals.sum(axis=1, keepdims=True)


def stationary_reference(shares: np.ndarray, rng: np.random.Generator,
                         horizon: int = HORIZON) -> dict:
    """How long a run with these shares needs when nothing but chance moves them.

    Simulates runs of `horizon` turns in which every turn goes to an expert
    independently with probability `shares` -- a stationary process without
    any dynamics -- and reports the median and the 80th percentile of T. A run
    that never settles within the horizon counts as later than any T.
    """
    p = np.asarray(shares, dtype=float)
    p = p / p.sum()

    def simulate(n: int) -> np.ndarray:
        results = []
        for _ in range(SIMULATIONS):
            speakers = rng.choice(len(p), size=n, p=p)
            t = settling_turn(cumulative_shares(speakers, np.ones(n), len(p)))
            results.append(np.inf if t is None else t)
        return np.asarray(results)

    long = simulate(horizon)
    median, p80 = np.percentile(long, [50, 80], method="higher")
    return {
        "median": None if np.isinf(median) else int(median),
        "p80": None if np.isinf(p80) else int(p80),
        # How often a stationary run of exactly this length meets the criterion at all.
        "settles_within_60": round(float(np.mean(np.isfinite(simulate(60)))), 3),
        "settles_within_100": round(float(np.mean(np.isfinite(simulate(100)))), 3),
    }


def selftest() -> None:
    """The criterion on extreme cases whose answer is known by hand."""
    # One expert always speaks: the shares never move, stable from turn 1.
    always = cumulative_shares(np.zeros(60, dtype=int), np.ones(60), 4)
    assert settling_turn(always) == 1, settling_turn(always)

    # Strict rotation: cumulative share after t turns deviates from 1/4 by
    # at most 0.75/t, the move over ten turns is below 2 pp from t = 38 on.
    rotation = cumulative_shares(np.arange(100) % 4, np.ones(100), 4)
    t_rot = settling_turn(rotation)
    assert t_rot is not None and 30 <= t_rot <= 45, t_rot

    # A new speaker who takes over at turn 30 and keeps the floor: the shares
    # keep drifting, a 60-turn run never settles.
    takeover = cumulative_shares(np.array([0] * 29 + [1] * 31), np.ones(60), 4)
    assert settling_turn(takeover) is None

    # Too short to show ten turns of stability.
    assert settling_turn(always[:10]) is None

    # A late jump is enough to push T behind it.
    late = np.vstack([np.full((50, 2), 0.5), np.full((30, 2), 0.5)])
    late[55:] = [0.6, 0.4]
    assert settling_turn(late) == 56, settling_turn(late)

    print("selftest ok: always=1, rotation=%d, takeover=None, late=56" % t_rot)


# --- data --------------------------------------------------------------------

def artisan(*args: str) -> str:
    return subprocess.run(
        ["php", str(REPO / "artisan"), *args], check=True, capture_output=True, text=True, cwd=REPO,
    ).stdout


def load_run(project_id: int) -> dict:
    meta = json.loads(artisan("project:metrics", str(project_id), "--format=json"))
    rows = list(csv.DictReader(io.StringIO(artisan(
        "project:metrics", str(project_id), "--trajectory", f"--window={WINDOW}", "--format=csv",
    ))))

    participants = []
    for row in rows:
        entry = (int(row["expert_id"]), row["name"], int(row["seat"]))
        if entry not in participants:
            participants.append(entry)
    ids = [p[0] for p in participants]

    turns = sorted({int(r["turn"]) for r in rows})
    arrays = {s: np.zeros((len(turns), len(ids))) for s in SERIES}
    speakers = [0] * len(turns)
    for row in rows:
        t, e = int(row["turn"]) - 1, ids.index(int(row["expert_id"]))
        speakers[t] = int(row["speaker_id"])
        for s in SERIES:
            arrays[s][t, e] = float(row[s])

    title = meta["project"]["title"]
    return {
        "id": project_id,
        "title": title,
        "pipeline": meta["project"]["pipeline"],
        "topic": title.split(" ")[0],
        "participants": [{"expert_id": i, "name": n, "seat": s} for i, n, s in participants],
        "speakers": speakers,
        "shares": arrays,
        "rows": rows,
    }


# --- analysis ----------------------------------------------------------------

def block_shares(run: dict, size: int = 20) -> list[float]:
    """Turn share of seat 1 in consecutive blocks of `size` turns."""
    seat1 = run["participants"][0]["expert_id"]
    spk = run["speakers"]
    return [round(sum(1 for s in spk[i:i + size] if s == seat1) / len(spk[i:i + size]), 3)
            for i in range(0, len(spk), size)]


def analyse(run: dict, rng: np.random.Generator) -> dict:
    sh = run["shares"]
    n = sh["cumulative_turns"].shape[0]
    rr = run["pipeline"] == "RoundRobinPipeline"
    result = {
        "id": run["id"],
        "title": run["title"],
        "pipeline": run["pipeline"],
        "turns": n,
        "T_cumulative_turns": None if rr else settling_turn(sh["cumulative_turns"]),
        "T_cumulative_words": settling_turn(sh["cumulative_words"]),
        "T_rolling_turns": None if rr else settling_turn(sh["rolling_turns"]),
        "T_rolling_words": settling_turn(sh["rolling_words"]),
        "stationary_reference": None if rr else stationary_reference(sh["cumulative_turns"][-1], rng),
        "seat1_final_turn_share": round(float(sh["cumulative_turns"][-1, 0]), 4),
        "seat1_final_word_share": round(float(sh["cumulative_words"][-1, 0]), 4),
        "seat1_block_turn_shares_20": block_shares(run),
    }
    if n > 60:
        # Would a 60-turn run have named the same T?
        result["T_cumulative_turns_at_60"] = settling_turn(sh["cumulative_turns"][:60])
        result["T_cumulative_words_at_60"] = settling_turn(sh["cumulative_words"][:60])
        result["seat1_turn_share_at_60"] = round(float(sh["cumulative_turns"][59, 0]), 4)
    return result


# --- figures -----------------------------------------------------------------

def figure(run: dict, result: dict) -> None:
    import matplotlib

    matplotlib.use("Agg")
    import matplotlib.pyplot as plt

    accent, other = "#2a6fdb", "#9aa0a6"
    turns = np.arange(1, result["turns"] + 1)
    fig, axes = plt.subplots(1, 2, figsize=(10, 3.6), sharey=True, constrained_layout=True)

    for ax, key, heading in (
        (axes[0], "cumulative_turns", "Kumulativer Turn-Anteil"),
        (axes[1], "rolling_turns", f"Gleitender Turn-Anteil (Fenster {WINDOW})"),
    ):
        data = run["shares"][key] * 100
        for e, participant in reversed(list(enumerate(run["participants"]))):
            titled = participant["seat"] == 1
            ax.plot(turns, data[:, e], color=accent if titled else other,
                    linewidth=2.2 if titled else 1.2, zorder=3 if titled else 2,
                    label=participant["name"] + (" (Titel)" if titled else ""))
            ax.annotate(participant["name"].split(" ")[0], (turns[-1], data[-1, e]),
                        xytext=(4, 0), textcoords="offset points", va="center", fontsize=7,
                        color="#202124" if titled else "#5f6368")
        ax.axhline(25, color="#5f6368", linewidth=0.8, linestyle=":", zorder=1)
        t = result["T_cumulative_turns" if key == "cumulative_turns" else "T_rolling_turns"]
        if t is not None:
            ax.axvline(t, color="#202124", linewidth=1, linestyle="--")
            ax.annotate(f"T = {t}", (t, 97), xytext=(4, 0), textcoords="offset points",
                        fontsize=8, va="top")
        else:
            ax.annotate("nicht stabil", (2, 97), fontsize=8, va="top", color="#5f6368")
        ax.set_title(heading, fontsize=10, loc="left")
        ax.set_xlabel("Gesprochener Turn")
        ax.set_xlim(1, result["turns"] + 8)
        ax.set_ylim(0, 100)
        ax.grid(axis="y", color="#e8eaed", linewidth=0.8)
        for side in ("top", "right"):
            ax.spines[side].set_visible(False)
    axes[0].set_ylabel("Anteil in %")
    axes[0].legend(fontsize=7, frameon=False, loc="upper right")

    label = PIPELINE_LABEL[run["pipeline"]]
    fig.suptitle(f"{label} · {run['title']} · Projekt {run['id']}", fontsize=11, x=0.01, ha="left")
    base = HERE / f"{STEM}-abb-{run['id']}-{label.lower().replace(' ', '')}-{run['topic'].lower()}"
    fig.savefig(base.with_suffix(".svg"))
    fig.savefig(base.with_suffix(".png"), dpi=200)
    plt.close(fig)


# --- main --------------------------------------------------------------------

def main() -> None:
    parser = argparse.ArgumentParser()
    parser.add_argument("--selftest", action="store_true")
    args = parser.parse_args()

    selftest()
    if args.selftest:
        return

    rng = np.random.default_rng(RNG_SEED)
    ids = [i for r in SERIES_60.values() for i in r] + list(LONG_RUNS)
    runs = [run for run in map(load_run, ids) if run["speakers"]]
    missing = sorted(set(ids) - {run["id"] for run in runs})
    if missing:
        print(f"no spoken turn yet, left out: {missing}", file=sys.stderr)
    results = [analyse(run, rng) for run in runs]

    with open(HERE / f"{STEM}-trajektorien.csv", "w", newline="") as handle:
        writer = csv.DictWriter(handle, fieldnames=list(runs[0]["rows"][0].keys()))
        writer.writeheader()
        for run in runs:
            writer.writerows(run["rows"])

    with open(HERE / f"{STEM}-daten.json", "w") as handle:
        json.dump({
            "criterion": {"k": K, "delta": DELTA, "window": WINDOW, "simulations": SIMULATIONS,
                          "rng_seed": RNG_SEED},
            "runs": [
                {**result, "participants": run["participants"], "speakers": run["speakers"]}
                for run, result in zip(runs, results)
            ],
        }, handle, ensure_ascii=False, indent=1)

    for run, result in zip(runs, results):
        if run["id"] in LONG_RUNS:
            figure(run, result)

    for r in results:
        print(json.dumps({k: v for k, v in r.items() if k != "title"}, ensure_ascii=False), r["title"])


if __name__ == "__main__":
    sys.exit(main())
