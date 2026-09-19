UPDATE app_settings
SET updated_at = strftime('%Y-%m-%dT%H:%M:%fZ', 'now')
WHERE setting_key = 'product_version';
