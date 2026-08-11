ALTER TABLE admins
ADD COLUMN auth_version INTEGER NOT NULL DEFAULT 1 CHECK (auth_version >= 1);
