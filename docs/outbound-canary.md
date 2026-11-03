# Outbound write canary — non-production only

JaySub outbound persistence is disabled by default. Preview works on all panels; this feature permits writes ONLY to one explicitly approved non-production canary. Fleet writes remain disabled.

## Preparation

1. Provision an isolated non-production 3x-ui, with zero production subscribers.
2. Register it in JaySub; verify its JaySub panel ID and hostname.
3. Back up its original xraySetting, 3x-ui database and system; verify restore works.
4. Run read-only preview and ensure the tag is unique and Xray is healthy.

## Explicit server-side opt-in

Back up /var/www/vpn-panel/config/config.php. Add a top-level array key outbound_persistence with these values:

```php
'outbound_persistence' => [
    'mode' => 'canary',
    'nonproduction_confirmed' => true,
    'canary_panel_id' => 999, // REPLACE with verified NON-PRODUCTION JaySub panel ID
],
```

Do not use a production panel ID. In canary mode, the UI selects only this test panel. The backend rejects all other targets, and rejects multi-panel requests before any write.

Create runs preview first and asks for explicit confirmation before the audited backup, re-fetch, write, health check and rollback workflow.

GET /admin/outbounds/api/panels reports save_mode and canary_panel_id to logged-in admins.

RoutingSaveGuard::PERSIST_ENABLED stays false. OutboundSaveGuard::PERSIST_ENABLED also stays false as the unrestricted/fleet kill switch.

## Acceptance test on non-production only

1. Confirm original config, working restore backup and Xray service health.
2. Create a unique test outbound; confirm it was persisted on the test 3x-ui and existing routing remains intact.
3. Verify the file in storage/outbound-backups/<canary_id>/ is safely stored and parseable.
4. Confirm identical tag/config is skipped and conflicting tag/config is rejected.
5. Test simulated failures and rollback using mocks, not forced failures of production systems.
6. Verify Xray logs, service health and connectivity; a successful API response alone is not enough.
7. Disable canary immediately following the test.

## Disable

Set mode to disabled or remove outbound_persistence from local config/config.php and reload php8.3-fpm. Preview should continue working; all writes must again be blocked.

Fleet-wide persistence is intentionally NOT enabled in this change. Enable only after a separately reviewed non-production acceptance run. Never bypass the guard by editing the constant.
