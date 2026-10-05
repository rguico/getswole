# Analyzing getswole backups

Directions for future prompts that ask to "analyze the latest backup" or similar.

## Finding the latest backup

Backups live in `backups/`, named `getswole-YYYYMMDD-HHMMSS.db` (created by `make
backup`, which scp's the live deployed db down). They are gitignored, so `git log`
won't show them — list the directory and sort by name/mtime to find the latest:

```sh
ls -t backups/*.db | head -1
```

## Schema (see schema.sql for authoritative version)

- `lifters` — Robert, Jennie.
- `muscle_groups` — Core / Upper / Lower.
- `machines` — exercise names, optionally tagged with a muscle group. New
  machines get added ad hoc with a NULL muscle_group_id until categorized.
- `sessions` — one per lifter, ordered by `session_order` (chronological, not a
  date). `logged_at` is when the row was written, not necessarily a real workout
  date — early sessions were backfilled in bulk from `getswole.md` and share a
  single timestamp, so don't treat closely-clustered `logged_at` values as
  "same day, high frequency" without checking `session_order` continuity too.
- `exercise_entries` — one line per exercise within a session.
- `sets` — one reps/weight/num_sets group per entry, e.g. "10 x 55, 60 (2)"
  becomes a row `{reps:10, weight:55, num_sets:1}` and a row
  `{reps:10, weight:60, num_sets:2}`. `comment` on a set is tied to that
  specific weight (e.g. "too much"); `comment` on the entry applies to the
  whole exercise line (e.g. "each side", "narrow").

## Useful query: full history flattened

```sh
sqlite3 -header -csv backups/<latest>.db "
SELECT l.name AS lifter, s.session_order, date(s.logged_at) AS date,
       m.name AS exercise, e.entry_order, st.set_order,
       st.reps, st.weight, st.num_sets, st.comment, e.comment AS entry_comment
FROM sets st
JOIN exercise_entries e ON e.id = st.entry_id
JOIN sessions s ON s.id = e.session_id
JOIN lifters l ON l.id = s.lifter_id
JOIN machines m ON m.id = e.machine_id
ORDER BY l.name, s.session_order, e.entry_order, st.set_order;
"
```

This is small enough (a couple hundred rows at present) to dump in full and
read directly rather than aggregating blindly in SQL — aggregate queries hide
things like a `(too much)` comment right after a weight jump.

## What to look for per lifter, per exercise

- **Progression**: is top weight at the last session higher than at first
  appearance? Over what real-world date range (use `date(logged_at)`, not
  session_order, once sessions have distinct real dates)?
- **Plateaus**: same top weight across 3+ consecutive sessions for that
  exercise — worth calling out as a recommendation target.
- **Dropped exercises**: appeared early, then stopped being logged — could be
  intentional routine changes or things falling through the cracks. Cross-check
  against what replaced them in the same muscle group.
- **Suspicious jumps**: a sudden large weight increase between two sessions
  (much bigger than the lifter's typical increment) may be a genuine PR, a
  machine/equipment change, or a data entry mistake (e.g., missing a digit) —
  flag it rather than silently trusting it.
- **Comments**: `(too much)`, `(failure)`, `(start at X next)` are the
  lifter's own annotations and are strong signal — weight it above inferring
  from numbers alone.
- **Session cadence**: once `session_order` stops being backfilled in bulk
  (check where `date(logged_at)` values start to diverge), look at the gaps
  between real dates to comment on training frequency/consistency.

## Output expectations

When asked to summarize + recommend:
- Summarize per lifter, not just globally — the two lifters have very
  different loads and goals.
- Give concrete numbers (weights, dates) rather than vague "improving" language.
- Recommendations should be specific and actionable (which exercise, what to
  change), not generic training advice.
