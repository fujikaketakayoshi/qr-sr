CREATE TABLE admin_login_attempts (
    id INTEGER PRIMARY KEY,
    identifier_hash TEXT NOT NULL,
    attempted_at TEXT NOT NULL
) STRICT;

CREATE INDEX admin_login_attempts_identifier_time_index
    ON admin_login_attempts (identifier_hash, attempted_at);
