CREATE TABLE IF NOT EXISTS recent_files (
    id         INTEGER PRIMARY KEY AUTOINCREMENT,
    project_id INTEGER NOT NULL REFERENCES projects(id),
    path       TEXT NOT NULL,
    opened_at  DATETIME DEFAULT CURRENT_TIMESTAMP,
    UNIQUE(project_id, path)
);

CREATE INDEX IF NOT EXISTS idx_recent_files_project ON recent_files(project_id, opened_at DESC);

CREATE TABLE IF NOT EXISTS scheduled_builds (
    id           INTEGER PRIMARY KEY AUTOINCREMENT,
    project_id   INTEGER NOT NULL REFERENCES projects(id),
    cmd_id       TEXT NOT NULL,
    cron         TEXT NOT NULL,
    is_enabled   INTEGER NOT NULL DEFAULT 1,
    last_run_at  DATETIME,
    last_status  TEXT,
    created_at   DATETIME DEFAULT CURRENT_TIMESTAMP
);

CREATE INDEX IF NOT EXISTS idx_scheduled_builds_project ON scheduled_builds(project_id);

CREATE TABLE IF NOT EXISTS link_check_runs (
    id          INTEGER PRIMARY KEY AUTOINCREMENT,
    project_id  INTEGER NOT NULL REFERENCES projects(id),
    started_at  DATETIME DEFAULT CURRENT_TIMESTAMP,
    finished_at DATETIME,
    total_links INTEGER DEFAULT 0,
    broken      INTEGER DEFAULT 0
);

CREATE TABLE IF NOT EXISTS link_check_results (
    id          INTEGER PRIMARY KEY AUTOINCREMENT,
    run_id      INTEGER NOT NULL REFERENCES link_check_runs(id) ON DELETE CASCADE,
    project_id  INTEGER NOT NULL REFERENCES projects(id),
    url         TEXT NOT NULL,
    source      TEXT NOT NULL,
    status_code INTEGER,
    error       TEXT,
    checked_at  DATETIME DEFAULT CURRENT_TIMESTAMP
);

CREATE INDEX IF NOT EXISTS idx_link_results_run ON link_check_results(run_id);
