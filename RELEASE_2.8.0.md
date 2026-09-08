# FreeBot 2.8.0 — Queue State Hardening

This release fixes Telegram channel queues that could appear completed with 0/0 items and removes stale single-file pipeline behavior.

- Keep Telegram channel pipeline depth at 4 by default and allow 4–8 from the admin panel.
- Preserve generic sequential queues at pipeline depth 1 so non-Telegram queue semantics do not regress.
- Remove the legacy migration that reset active Telegram queues back to pipeline 1.
- Upgrade existing Telegram batches below pipeline 4, including cancelled batches, and enforce at least pipeline 4 immediately when a cancelled Telegram queue is resumed.
- Run the 2.8 queue-state migration only once through schema versioning instead of performing heavy ALTER/repair work on every request.
- Keep update.sh migration-safe by booting App::db() rather than embedding fragile escaped SQL in php -r.
- Count an item as already imported only when it was successfully uploaded to one of the current destination channels.
- Do not let failed or cancelled historical jobs suppress a new queue.
- Do not inherit dedupe state when the destination channel changes.
- Persist a separate successful-skip counter for channel scans.
- Automatically repair suspicious Telegram batches that were finalized as completed with 0/0 items.
- Resume cancelled Telegram batches for real: failed and cancelled unfinished jobs are restored while completed jobs stay completed.
- Resume failed or cancelled channel scans from their safe state.
- Keep queued/scanning Telegram batches out of completed state.
- Sort active/scanning batches before completed history in the admin panel.
- Improve batch status labels, destination cards, active-row highlighting and progress display.
- Add MariaDB regression coverage for zero-item repair, destination-aware dedupe, cancelled-batch continuation, generic sequential queues and parallel Telegram pipeline claims.

Existing suspicious 0/0 Telegram batches are repaired during update and scheduled for a fresh scan when their previous completion cannot be proven valid.
