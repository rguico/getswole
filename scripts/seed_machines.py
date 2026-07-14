import csv
import pathlib
import sqlite3

ROOT = pathlib.Path(__file__).resolve().parent.parent
DB = ROOT / "getswole.db"
CSV = ROOT / "machines.csv"

conn = sqlite3.connect(DB)
cur = conn.cursor()

with open(CSV, newline="") as f:
    for row in csv.DictReader(f):
        machine, group = row["machine"].strip(), row["group"].strip()
        cur.execute("INSERT OR IGNORE INTO muscle_groups (name) VALUES (?)", (group,))
        cur.execute("SELECT id FROM muscle_groups WHERE name = ?", (group,))
        group_id = cur.fetchone()[0]
        cur.execute(
            "INSERT OR IGNORE INTO machines (name, muscle_group_id) VALUES (?, ?)",
            (machine, group_id),
        )

conn.commit()
conn.close()
print("Seeded machines and muscle_groups from machines.csv")
