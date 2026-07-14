import json
import pathlib
import sqlite3

ROOT = pathlib.Path(__file__).resolve().parent.parent
DB = ROOT / "getswole.db"
OUT = ROOT / "data.json"

conn = sqlite3.connect(DB)
conn.row_factory = sqlite3.Row
cur = conn.cursor()

machines = [
    dict(row)
    for row in cur.execute(
        """
        SELECT m.id, m.name, g.name AS muscle_group
        FROM machines m
        JOIN muscle_groups g ON g.id = m.muscle_group_id
        ORDER BY m.name
        """
    )
]

sets = [
    dict(row)
    for row in cur.execute(
        """
        SELECT st.id, l.name AS lifter, e.machine_id, s.logged_at,
               st.reps, st.weight, st.num_sets, st.comment
        FROM sets st
        JOIN exercise_entries e ON e.id = st.entry_id
        JOIN sessions s ON s.id = e.session_id
        JOIN lifters l ON l.id = s.lifter_id
        """
    )
]

conn.close()

OUT.write_text(json.dumps({"machines": machines, "sets": sets}, indent=2))
print(f"Wrote {len(machines)} machines and {len(sets)} sets to {OUT}")
