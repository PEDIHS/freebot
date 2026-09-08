# FreeBot 2.8.1 — Collation-safe Telegram queues

This release fixes MariaDB `SQLSTATE[HY000] 1270 Illegal mix of collations` errors seen while scanning or rendering Telegram channel queues on databases upgraded across older FreeBot versions.

- Removes SQL `CASE` / `COALESCE` expressions that mixed `media_jobs.target_channel_id` and `media_batches.channel_id` under incompatible collations.
- Makes destination-aware deduplication compare both stored channels and prepared parameters as binary values explicitly.
- Resolves effective destination channel IDs in PHP instead of forcing MariaDB to merge different string collations.
- Groups destination statistics by numeric `target_slot`, then resolves the channel label after the query.
- Keeps parallel Telegram upload ordering without collation-sensitive string CASE expressions.
- Adds a regression test that switches the MariaDB connection to `SET NAMES binary` while legacy columns use `utf8mb4_bin` and `utf8mb4_unicode_ci`, matching the production failure class.

Version: `2.8.1-collation-safe`.
