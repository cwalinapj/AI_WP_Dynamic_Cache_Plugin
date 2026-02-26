-- AI WP Dynamic Cache Plugin — D1 database schema
-- All tables use INTEGER primary keys with AUTOINCREMENT so IDs are
-- monotonically increasing and can be used for pagination.

CREATE TABLE IF NOT EXISTS cache_events (
  id          INTEGER PRIMARY KEY AUTOINCREMENT,
  site_id     TEXT    NOT NULL,
  event_type  TEXT    NOT NULL,
  url         TEXT    NOT NULL DEFAULT '',
  tags        TEXT    NOT NULL DEFAULT '[]',  -- JSON array
  timestamp   INTEGER NOT NULL
);

CREATE INDEX IF NOT EXISTS idx_cache_events_site_id
  ON cache_events (site_id);
CREATE INDEX IF NOT EXISTS idx_cache_events_timestamp
  ON cache_events (timestamp);

-- ---------------------------------------------------------------------------

CREATE TABLE IF NOT EXISTS purge_log (
  id           INTEGER PRIMARY KEY AUTOINCREMENT,
  site_id      TEXT    NOT NULL,
  tags         TEXT    NOT NULL DEFAULT '[]',  -- JSON array
  triggered_by TEXT    NOT NULL DEFAULT 'api',
  timestamp    INTEGER NOT NULL
);

CREATE INDEX IF NOT EXISTS idx_purge_log_site_id
  ON purge_log (site_id);
CREATE INDEX IF NOT EXISTS idx_purge_log_timestamp
  ON purge_log (timestamp);

-- ---------------------------------------------------------------------------

CREATE TABLE IF NOT EXISTS experiments (
  id           INTEGER PRIMARY KEY AUTOINCREMENT,
  site_id      TEXT    NOT NULL,
  strategy     TEXT    NOT NULL DEFAULT 'full',
  started_at   INTEGER NOT NULL,
  ended_at     INTEGER,
  metrics_json TEXT    NOT NULL DEFAULT '{}'
);

CREATE INDEX IF NOT EXISTS idx_experiments_site_id
  ON experiments (site_id);
CREATE INDEX IF NOT EXISTS idx_experiments_started_at
  ON experiments (started_at);

-- ---------------------------------------------------------------------------

CREATE TABLE IF NOT EXISTS heartbeats (
  id          INTEGER PRIMARY KEY AUTOINCREMENT,
  site_id     TEXT    NOT NULL UNIQUE,
  last_seen   INTEGER NOT NULL,
  version     TEXT    NOT NULL DEFAULT '',
  worker_url  TEXT    NOT NULL DEFAULT ''
);

CREATE INDEX IF NOT EXISTS idx_heartbeats_site_id
  ON heartbeats (site_id);
CREATE INDEX IF NOT EXISTS idx_heartbeats_last_seen
  ON heartbeats (last_seen);
