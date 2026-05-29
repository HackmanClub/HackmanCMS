-- Scheduled social posts campaign (one row = one scheduled send)
CREATE TABLE IF NOT EXISTS scheduled_posts (
    id           INTEGER PRIMARY KEY AUTOINCREMENT,
    project_id   INTEGER NOT NULL REFERENCES projects(id) ON DELETE CASCADE,
    content      TEXT NOT NULL,
    url          TEXT,
    scheduled_at DATETIME NOT NULL,
    status       TEXT NOT NULL DEFAULT 'pending',  -- pending | processing | done | partial
    created_at   DATETIME DEFAULT CURRENT_TIMESTAMP
);

-- Per-platform delivery target (one row per platform × target per post)
CREATE TABLE IF NOT EXISTS scheduled_post_targets (
    id       INTEGER PRIMARY KEY AUTOINCREMENT,
    post_id  INTEGER NOT NULL REFERENCES scheduled_posts(id) ON DELETE CASCADE,
    platform TEXT NOT NULL,  -- discord | mastodon | linkedin
    target   TEXT NOT NULL,  -- channel key (discord) | account key (mastodon) | page key (linkedin)
    status   TEXT NOT NULL DEFAULT 'pending',  -- pending | sent | failed
    error    TEXT,
    sent_at  DATETIME
);

CREATE INDEX IF NOT EXISTS idx_scheduled_posts_project_status
    ON scheduled_posts(project_id, status, scheduled_at);
CREATE INDEX IF NOT EXISTS idx_scheduled_post_targets_post
    ON scheduled_post_targets(post_id, status);
