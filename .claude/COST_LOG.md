# COST_LOG — Liwonde Sun Hotel 2026 build system

> Append-only. One row per agent dispatch (build-planner, specialist, ui-designer,
> qa-auditor, codebase-scout). Written by the `/build-loop` orchestrator, not by the
> dispatched agents themselves.
>
> **Estimates are a heuristic** (`chars ÷ 4` on the drafted prompt + a fixed per-model
> overhead for system prompt/tool defs + an assumed output budget by task type) — not a
> real tokenizer count. Treat the Est. columns as ballpark, the Actual column as ground
> truth (`subagent_tokens` returned by the Agent tool on completion).
>
> Calibration note carried over from the Rosalyn build: **estimates ran low, often badly**
> (accuracy 82–1497%, typically 150–200%). Expect actuals to be roughly 2× the estimate and
> do not treat a low estimate as a guarantee.
>
> **Cost tier** is relative API-list-price weighting used only as a proxy for "how much
> of a Pro-plan usage allowance this burns" — Anthropic doesn't publish the exact Pro
> internal weighting formula, so treat the tier as directional, not literal billing.
> Haiku = 1x baseline · Sonnet ≈ 4x Haiku · Opus ≈ 19x Haiku (approx., from public API
> list pricing ratios).
>
> High-cost threshold: **35,000 estimated tokens**. A dispatch above this is flagged
> `HIGH-COST` and **proceeds anyway** — the loop does not stall on cost. Where the task is
> splittable, the planner splits it instead. Cost is reported once, in the SESSION SUMMARY.
> See `.claude/skills/build-loop/SKILL.md`.

## Log

| Timestamp | Task ID | Agent | Model | Est. In | Est. Out | Est. Total | Tier | Actual Tokens | Accuracy | High-Cost Flag |
|---|---|---|---|---|---|---|---|---|---|---|
