"""Parse getswole.md into the sessions / exercise_entries / sets tables.

Grammar (see conversation notes for derivation):
  line := MACHINE ":" EXPR | MACHINE
  EXPR := GROUP ("x" GROUP)*   -- "x" is a standalone token, never part of a word
  Splitting EXPR on "x" yields chunks c0, c1, ..., ck (k = number of "x"s).
  Group i's reps come from the last comma-item of chunk[i] (or all of c0 for
  group 0); group i's weights are the remaining comma-items of chunk[i+1]
  (or all of the final chunk for the last group).

  Within a group:
    - one reps value & one weight value, and this is the ONLY group in the
      whole expression, and the weight has no explicit "(N)" -> defaults to
      3 sets (the gym's standard, per user clarification).
    - one reps value & one weight value with an explicit "(N)" -> N sets.
    - many reps values & one weight value -> each reps value pairs with the
      weight for exactly 1 set; any trailing "(N)" on the weight is a
      redundant restatement of the count and is not applied as a multiplier.
    - one reps value & many weight values -> each weight is its own set of
      that many reps; a trailing "(N)" on a given weight multiplies just
      that weight's set count.
    - many reps values & equally many weight values -> paired positionally,
      1 set each.

  A trailing parenthetical that isn't purely numeric (e.g. "(too much)",
  "(go to 250)") is a comment, not a multiplier, and is attached to the
  set (if on a weight token) or the entry (if on a reps token).
"""
import argparse
import pathlib
import re
import sqlite3
import sys

ROOT = pathlib.Path(__file__).resolve().parent.parent
DB = ROOT / "getswole.db"
MD = ROOT / "getswole.md"

X_SPLIT = re.compile(r"(?i)\s*(?<![a-z])x(?![a-z])\s*")
TOKEN = re.compile(r"^\s*([\d.]+)\s*((?:\([^)]*\)\s*)*)(.*)$")
PAREN = re.compile(r"\(([^)]*)\)")

SYNONYMS = {"biceps curl": "bicep curl"}


def normalize_machine(name):
    key = name.strip().lower()
    return SYNONYMS.get(key, key)


def split_top_level_commas(text):
    parts, depth, current = [], 0, ""
    for ch in text:
        if ch == "(":
            depth += 1
        elif ch == ")":
            depth -= 1
        if ch == "," and depth == 0:
            parts.append(current)
            current = ""
        else:
            current += ch
    parts.append(current)
    return [p.strip() for p in parts if p.strip()]


def parse_token(token):
    """'85 (2) (too much)' -> (85.0, 2, 'too much')"""
    m = TOKEN.match(token)
    if not m:
        return None, None, token.strip() or None
    value, paren_blob, trailing = m.groups()
    multiplier, comments = None, []
    for content in PAREN.findall(paren_blob):
        content = content.strip()
        if content.isdigit():
            multiplier = int(content)
        elif content:
            comments.append(content)
    trailing = trailing.strip()
    if trailing:
        comments.append(trailing)
    comment = "; ".join(comments) if comments else None
    return float(value), multiplier, comment


def build_groups(expr):
    chunks = X_SPLIT.split(expr.strip())
    groups = []
    reps_text = chunks[0]
    for i in range(1, len(chunks)):
        items = split_top_level_commas(chunks[i])
        if i < len(chunks) - 1:
            groups.append((reps_text, items[:-1]))
            reps_text = items[-1]
        else:
            groups.append((reps_text, items))
    return groups


def parse_expr(expr):
    """Returns (rows, entry_comment, warnings) where rows are
    (reps, weight, num_sets, comment)."""
    groups = build_groups(expr)
    rows, entry_comments, warnings = [], [], []
    lone_group = len(groups) == 1

    for reps_text, weight_tokens in groups:
        reps_tokens = split_top_level_commas(reps_text)
        reps_vals = []
        for rt in reps_tokens:
            val, mult, comment = parse_token(rt)
            if val is None:
                warnings.append(f"unparsed reps token: {rt!r}")
                continue
            if mult is not None:
                warnings.append(f"unexpected multiplier on reps token: {rt!r}")
            reps_vals.append(int(val))
            if comment:
                entry_comments.append(comment)

        weights = []
        for wt in weight_tokens:
            val, mult, comment = parse_token(wt)
            if val is None:
                warnings.append(f"unparsed weight token: {wt!r}")
                continue
            weights.append((val, mult, comment))

        if not reps_vals or not weights:
            continue

        if len(reps_vals) == 1 and len(weights) == 1:
            weight, mult, comment = weights[0]
            if mult is not None:
                num_sets = mult
            elif lone_group:
                num_sets = 3
            else:
                num_sets = 1
            rows.append((reps_vals[0], weight, num_sets, comment))
        elif len(reps_vals) > 1 and len(weights) == 1:
            weight, mult, comment = weights[0]
            if mult is not None and mult != len(reps_vals):
                warnings.append(
                    f"multiplier ({mult}) on weight doesn't match reps count "
                    f"({len(reps_vals)}) in group {reps_text!r} x {weight_tokens!r}"
                )
            for reps in reps_vals:
                rows.append((reps, weight, 1, comment))
        elif len(reps_vals) == 1 and len(weights) > 1:
            reps = reps_vals[0]
            for weight, mult, comment in weights:
                rows.append((reps, weight, mult if mult is not None else 1, comment))
        elif len(reps_vals) == len(weights):
            for reps, (weight, mult, comment) in zip(reps_vals, weights):
                rows.append((reps, weight, mult if mult is not None else 1, comment))
        else:
            warnings.append(
                f"mismatched reps/weight counts: {reps_vals!r} vs {weight_tokens!r}"
            )

    entry_comment = "; ".join(entry_comments) if entry_comments else None
    return rows, entry_comment, warnings


def parse_line(line):
    if ":" not in line:
        return line.strip(), None, None, []
    machine, expr = line.split(":", 1)
    rows, comment, warnings = parse_expr(expr)
    return machine.strip(), rows, comment, warnings


def parse_file(text):
    """Yields (lifter_name, session_index, [line, ...]) per session."""
    lifter = None
    session_lines = []
    session_index = 0

    def flush():
        nonlocal session_lines, session_index
        if session_lines:
            session_index += 1
            yield lifter, session_index, session_lines
            session_lines = []

    for raw_line in text.splitlines():
        line = raw_line.strip()
        if line.startswith("#"):
            yield from flush()
            lifter = line.lstrip("#").strip()
            session_index = 0
        elif not line:
            yield from flush()
        else:
            session_lines.append(line)
    yield from flush()


def main():
    parser = argparse.ArgumentParser()
    parser.add_argument("--dry-run", action="store_true")
    args = parser.parse_args()

    conn = sqlite3.connect(DB)
    conn.execute("PRAGMA foreign_keys = ON")
    cur = conn.cursor()

    lifters = dict(cur.execute("SELECT name, id FROM lifters"))
    machines = {
        normalize_machine(name): mid
        for name, mid in cur.execute("SELECT name, id FROM machines")
    }

    if not args.dry_run:
        cur.execute("DELETE FROM sets")
        cur.execute("DELETE FROM exercise_entries")
        cur.execute("DELETE FROM sessions")

    total_warnings = 0
    for lifter_name, session_order, lines in parse_file(MD.read_text()):
        if lifter_name not in lifters:
            print(f"WARNING: unknown lifter {lifter_name!r}, skipping session", file=sys.stderr)
            continue
        lifter_id = lifters[lifter_name]

        if args.dry_run:
            print(f"\n=== {lifter_name} session {session_order} ===")
        else:
            cur.execute(
                "INSERT INTO sessions (lifter_id, session_order) VALUES (?, ?)",
                (lifter_id, session_order),
            )
            session_id = cur.lastrowid

        for entry_order, line in enumerate(lines, start=1):
            machine_name, rows, comment, warnings = parse_line(line)
            machine_id = machines.get(normalize_machine(machine_name))
            if machine_id is None:
                print(f"WARNING: unknown machine {machine_name!r} in line {line!r}", file=sys.stderr)
                total_warnings += 1
                continue
            for w in warnings:
                print(f"WARNING: {w} (line: {line!r})", file=sys.stderr)
                total_warnings += 1

            if args.dry_run:
                print(f"  {machine_name}: {rows} comment={comment!r}")
                continue

            cur.execute(
                "INSERT INTO exercise_entries (session_id, machine_id, entry_order, raw_text, comment) "
                "VALUES (?, ?, ?, ?, ?)",
                (session_id, machine_id, entry_order, line, comment),
            )
            entry_id = cur.lastrowid
            rows = rows or []
            for set_order, (reps, weight, num_sets, set_comment) in enumerate(rows, start=1):
                cur.execute(
                    "INSERT INTO sets (entry_id, set_order, reps, weight, num_sets, comment) "
                    "VALUES (?, ?, ?, ?, ?, ?)",
                    (entry_id, set_order, reps, weight, num_sets, set_comment),
                )

    if not args.dry_run:
        conn.commit()
    conn.close()

    print(f"\nDone. {total_warnings} warning(s).", file=sys.stderr)


if __name__ == "__main__":
    main()
