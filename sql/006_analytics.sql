CREATE TABLE IF NOT EXISTS site_visits (
    id          INTEGER PRIMARY KEY AUTOINCREMENT,
    project_id  INTEGER NOT NULL REFERENCES projects(id),
    path        TEXT NOT NULL,
    referrer    TEXT,
    ua_hash     TEXT,
    ip_hash     TEXT,
    visited_at  DATETIME DEFAULT CURRENT_TIMESTAMP
);

CREATE INDEX IF NOT EXISTS idx_visits_project_time
    ON site_visits(project_id, visited_at DESC);

CREATE INDEX IF NOT EXISTS idx_visits_project_path
    ON site_visits(project_id, path);
