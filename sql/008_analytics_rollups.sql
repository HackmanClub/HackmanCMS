-- Hourly rollup: views + uniques per (project, hour, path, status, referrer).
CREATE TABLE IF NOT EXISTS site_visits_hourly (
    id          INTEGER PRIMARY KEY AUTOINCREMENT,
    project_id  INTEGER NOT NULL REFERENCES projects(id),
    bucket_at   DATETIME NOT NULL,
    path        TEXT NOT NULL,
    status      INTEGER NOT NULL DEFAULT 200,
    referrer    TEXT NOT NULL DEFAULT '',
    views       INTEGER NOT NULL DEFAULT 0,
    uniques     INTEGER NOT NULL DEFAULT 0,
    UNIQUE(project_id, bucket_at, path, status, referrer)
);

CREATE INDEX IF NOT EXISTS idx_visits_hourly_project_time
    ON site_visits_hourly(project_id, bucket_at);
CREATE INDEX IF NOT EXISTS idx_visits_hourly_project_status
    ON site_visits_hourly(project_id, status);

-- Daily rollup: views + uniques per (project, day, path, status, referrer).
-- "Uniques" here is "distinct ip_hash within the day" — and since the salt
-- rotates daily, that hash is meaningful only within the bucket's day.
CREATE TABLE IF NOT EXISTS site_visits_daily (
    id          INTEGER PRIMARY KEY AUTOINCREMENT,
    project_id  INTEGER NOT NULL REFERENCES projects(id),
    bucket_at   DATE NOT NULL,
    path        TEXT NOT NULL,
    status      INTEGER NOT NULL DEFAULT 200,
    referrer    TEXT NOT NULL DEFAULT '',
    views       INTEGER NOT NULL DEFAULT 0,
    uniques     INTEGER NOT NULL DEFAULT 0,
    UNIQUE(project_id, bucket_at, path, status, referrer)
);

CREATE INDEX IF NOT EXISTS idx_visits_daily_project_time
    ON site_visits_daily(project_id, bucket_at);
CREATE INDEX IF NOT EXISTS idx_visits_daily_project_status
    ON site_visits_daily(project_id, status);
