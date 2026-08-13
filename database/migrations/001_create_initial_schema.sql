CREATE TABLE admins (
    id INTEGER PRIMARY KEY,
    email TEXT NOT NULL UNIQUE,
    password_hash TEXT NOT NULL,
    recovery_key_hash TEXT NOT NULL,
    last_login_at TEXT,
    created_at TEXT NOT NULL,
    updated_at TEXT NOT NULL
) STRICT;

CREATE TABLE app_settings (
    setting_key TEXT PRIMARY KEY,
    setting_value TEXT NOT NULL,
    updated_at TEXT NOT NULL
) STRICT;

CREATE TABLE events (
    id INTEGER PRIMARY KEY CHECK (id = 1),
    name TEXT NOT NULL,
    description TEXT NOT NULL DEFAULT '',
    notice_text TEXT NOT NULL DEFAULT '',
    starts_at TEXT NOT NULL,
    ends_at TEXT NOT NULL,
    is_paused INTEGER NOT NULL DEFAULT 0 CHECK (is_paused IN (0, 1)),
    pause_message TEXT NOT NULL DEFAULT '',
    required_stamp_count INTEGER NOT NULL CHECK (required_stamp_count >= 1),
    completion_message TEXT NOT NULL DEFAULT '',
    application_enabled INTEGER NOT NULL DEFAULT 0 CHECK (application_enabled IN (0, 1)),
    application_deadline_at TEXT,
    privacy_purpose_text TEXT NOT NULL DEFAULT '抽選と当選者への連絡のために使用します。',
    created_at TEXT NOT NULL,
    updated_at TEXT NOT NULL,
    CHECK (starts_at < ends_at)
) STRICT;

CREATE TABLE application_fields (
    id INTEGER PRIMARY KEY,
    field_type TEXT NOT NULL UNIQUE CHECK (field_type IN ('name', 'email', 'address', 'phone')),
    is_enabled INTEGER NOT NULL DEFAULT 0 CHECK (is_enabled IN (0, 1)),
    is_required INTEGER NOT NULL DEFAULT 0 CHECK (is_required IN (0, 1)),
    display_order INTEGER NOT NULL,
    CHECK (is_required = 0 OR is_enabled = 1)
) STRICT;

CREATE TABLE spots (
    id INTEGER PRIMARY KEY,
    public_token_hash TEXT NOT NULL UNIQUE,
    name TEXT NOT NULL,
    description TEXT NOT NULL DEFAULT '',
    display_order INTEGER NOT NULL,
    is_active INTEGER NOT NULL DEFAULT 1 CHECK (is_active IN (0, 1)),
    created_at TEXT NOT NULL,
    updated_at TEXT NOT NULL
) STRICT;

CREATE TABLE participants (
    id INTEGER PRIMARY KEY,
    token_hash TEXT NOT NULL UNIQUE,
    nickname TEXT NOT NULL,
    first_seen_at TEXT NOT NULL,
    last_seen_at TEXT NOT NULL,
    completed_at TEXT,
    created_at TEXT NOT NULL,
    updated_at TEXT NOT NULL
) STRICT;

CREATE TABLE stamp_acquisitions (
    id INTEGER PRIMARY KEY,
    participant_id INTEGER NOT NULL REFERENCES participants(id) ON DELETE RESTRICT,
    spot_id INTEGER NOT NULL REFERENCES spots(id) ON DELETE RESTRICT,
    acquired_at TEXT NOT NULL,
    ip_hash TEXT,
    UNIQUE (participant_id, spot_id)
) STRICT;

CREATE TABLE applications (
    id INTEGER PRIMARY KEY,
    participant_id INTEGER NOT NULL UNIQUE REFERENCES participants(id) ON DELETE RESTRICT,
    application_number TEXT NOT NULL UNIQUE,
    name TEXT,
    email TEXT,
    address TEXT,
    phone TEXT,
    privacy_accepted_at TEXT NOT NULL,
    submitted_at TEXT NOT NULL,
    updated_at TEXT NOT NULL
) STRICT;

CREATE TABLE audit_logs (
    id INTEGER PRIMARY KEY,
    event_type TEXT NOT NULL,
    actor_type TEXT NOT NULL CHECK (actor_type IN ('admin', 'participant', 'system')),
    actor_id INTEGER,
    target_type TEXT,
    target_id INTEGER,
    result TEXT NOT NULL,
    context_json TEXT NOT NULL DEFAULT '{}',
    created_at TEXT NOT NULL,
    CHECK (json_valid(context_json))
) STRICT;

CREATE TABLE backup_operations (
    id INTEGER PRIMARY KEY,
    operation_type TEXT NOT NULL CHECK (operation_type IN ('backup', 'restore')),
    status TEXT NOT NULL CHECK (status IN ('uploaded', 'validating', 'ready', 'switching', 'completed', 'failed')),
    backup_version TEXT NOT NULL,
    file_path TEXT,
    file_hash TEXT,
    error_summary TEXT,
    created_at TEXT NOT NULL,
    updated_at TEXT NOT NULL,
    expires_at TEXT
) STRICT;

CREATE INDEX stamp_acquisitions_spot_id_index ON stamp_acquisitions (spot_id);
CREATE INDEX stamp_acquisitions_acquired_at_index ON stamp_acquisitions (acquired_at);
CREATE INDEX audit_logs_created_at_index ON audit_logs (created_at);

INSERT INTO application_fields (field_type, is_enabled, is_required, display_order)
VALUES
    ('name', 0, 0, 1),
    ('email', 0, 0, 2),
    ('address', 0, 0, 3),
    ('phone', 0, 0, 4);

INSERT INTO app_settings (setting_key, setting_value, updated_at)
VALUES
    ('product_version', '0.1.0-dev', strftime('%Y-%m-%dT%H:%M:%fZ', 'now')),
    ('schema_version', '1', strftime('%Y-%m-%dT%H:%M:%fZ', 'now'));
