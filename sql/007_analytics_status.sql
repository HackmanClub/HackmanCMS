-- Track HTTP status on each visit so analytics can split 200s from 404s.
ALTER TABLE site_visits ADD COLUMN status INTEGER NOT NULL DEFAULT 200;

CREATE INDEX IF NOT EXISTS idx_visits_project_status
    ON site_visits(project_id, status);
