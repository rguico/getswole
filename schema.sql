-- Lifters (top-level headers in getswole.md)
CREATE TABLE lifters (
    id   INTEGER PRIMARY KEY,
    name TEXT NOT NULL UNIQUE
);

INSERT INTO lifters (name) VALUES ('Robert'), ('Jennie');

-- Muscle groups (from machines.csv)
CREATE TABLE muscle_groups (
    id   INTEGER PRIMARY KEY,
    name TEXT NOT NULL UNIQUE
);

-- Machines/exercises, each belonging to one muscle group. muscle_group_id
-- is nullable because machines can be added ad hoc from the entry form
-- before anyone has categorized them.
CREATE TABLE machines (
    id               INTEGER PRIMARY KEY,
    name             TEXT NOT NULL UNIQUE,
    muscle_group_id  INTEGER REFERENCES muscle_groups(id)
);

-- A session is one blank-line-separated block in getswole.md for a lifter.
-- getswole.md has no explicit dates, so session_order preserves the
-- chronological order of sessions as they appear in the file; logged_at
-- records when the row was written to the database and can be backfilled
-- with a real workout date later.
CREATE TABLE sessions (
    id            INTEGER PRIMARY KEY,
    lifter_id     INTEGER NOT NULL REFERENCES lifters(id),
    session_order INTEGER NOT NULL,
    logged_at     TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE (lifter_id, session_order)
);

-- One line within a session, e.g. "Bicep curl: 12, 10, 10 x 55, 60, 50"
-- logged_at is the time this specific entry was added, distinct from the
-- session's logged_at (which reflects only the session's first entry).
CREATE TABLE exercise_entries (
    id           INTEGER PRIMARY KEY,
    session_id   INTEGER NOT NULL REFERENCES sessions(id),
    machine_id   INTEGER NOT NULL REFERENCES machines(id),
    entry_order  INTEGER NOT NULL,
    raw_text     TEXT NOT NULL,
    comment      TEXT,
    logged_at    TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE (session_id, entry_order)
);

-- One reps/weight group within a line, e.g. "50 (2)" in "12 x 50 (2), 40"
-- becomes reps=12, weight=50, num_sets=2. A trailing comment tied to a
-- specific weight (e.g. "160 (too much)") is stored on that set's row.
CREATE TABLE sets (
    id         INTEGER PRIMARY KEY,
    entry_id   INTEGER NOT NULL REFERENCES exercise_entries(id),
    set_order  INTEGER NOT NULL,
    reps       INTEGER NOT NULL,
    weight     REAL NOT NULL,
    num_sets   INTEGER NOT NULL DEFAULT 1,
    comment    TEXT,
    UNIQUE (entry_id, set_order)
);

CREATE INDEX idx_machines_muscle_group ON machines(muscle_group_id);
CREATE INDEX idx_sessions_lifter       ON sessions(lifter_id);
CREATE INDEX idx_entries_session       ON exercise_entries(session_id);
CREATE INDEX idx_entries_machine       ON exercise_entries(machine_id);
CREATE INDEX idx_sets_entry            ON sets(entry_id);
