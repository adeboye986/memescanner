# Stage B HostGator Rollout and Rollback

This is an operator procedure, not an authorization to deploy. It applies only to the Laravel Stage B observability changes. It does not authorize a scan, trade, TypeScript deployment, LIVE activation, or Phase 2.5 activation.

## Preconditions

- Attach the reviewed commit, passing Laravel/frontend evidence, and built Vite artifact to the change record.
- Confirm `DB_CONNECTION=sqlite`, the database queue is selected, `DB_QUEUE_CONNECTION` is empty or names the same SQLite connection, and SQLite transaction mode remains `IMMEDIATE`.
- Confirm `DB_QUEUE_RETRY_AFTER` remains greater than both the 30-second job timeout and the 120-second stale-attempt threshold (the repository default is 660 seconds).
- Confirm the SQLite file path and backup destination without printing secrets.
- Identify the cPanel cron entries for `schedule:run` and `app:queue-drain`.
- Budget at least the configured queue job timeout plus retry/cleanup headroom for old workers to leave.

## Export state transitions

```text
pre-dispatch -- atomic export + jobs insert --> queued
       |
       +-- definitive local queue persistence failure --> permanently_failed

                                    conclusive HTTP rejection
queued -> submitting ------------------------------------------> permanently_failed
            |   |
            |   +-- retryable/transport error -> retrying -> submitting
            |
            +-- valid HTTP acknowledgement ----------------------------+
            |                                                           |
            +-- exhausted/invalid/timeout -> acceptance_unknown         |
                                                                        v
any matching delivery state -- authenticated recorded event --------> acknowledged

configured boundary -> skipped
```

Every HTTP completion is fenced by its claimed `attempt_count`. A stale attempt cannot change a newer attempt. `acknowledged`, `skipped`, and `permanently_failed` reject ordinary stale transitions. Authenticated `opportunity.recorded.v1` evidence is authoritative and may promote an ambiguous delivery outcome to `acknowledged`. Missing webhook evidence is never interpreted as rejection.

## Deployment sequence

1. **Pause scan submission and new queue consumers.** Start the announced maintenance window so dashboard scan requests cannot be submitted. Disable the cPanel cron entries that invoke `schedule:run` and `app:queue-drain`; do not delete them. Do not invoke a manual worker.
2. **Drain old processes.** Wait at least the configured queue job timeout plus cleanup headroom. Verify no `app:queue-drain`/`queue:work` process remains and the `operations.queue-drain` lock is no longer held. Stop if an old worker remains; old and new worker code must never consume this queue concurrently.
3. **Back up SQLite.** With application writes and workers stopped, make a timestamped operator-held copy of the SQLite database (including `-wal`/`-shm` handling where present) outside the release directory. Record its size and SHA-256. A plain copy while writers are active is not an approved backup.
4. **Apply additive migrations.** Run the approved Laravel migration command once. Verify the two nullable `system_activities` columns and the two new tables/indexes. Confirm historical activity rows remain present with null structured fields. Do not run migration rollback as an application rollback.
5. **Deploy one application release.** Replace Laravel application code as one release while queue consumption remains paused. Clear/rebuild the approved Laravel caches. Confirm the deployed commit before continuing.
6. **Restart bounded workers on new code only.** Issue the normal queue restart signal, then re-enable only the `app:queue-drain` cron entry. Observe one bounded run and verify its process is executing the new release. Stop if another old-code process appears.
7. **Deploy frontend assets.** Publish the Vite artifacts built from the same reviewed commit. Verify asset manifest integrity and that historical scans still render without a structured summary.
8. **Resume scheduling and scan submission.** Re-enable `schedule:run`, then end the dashboard maintenance window. Do not initiate a production scan solely as a deployment test.
9. **Read-only verification.** Check `app:health`, `schedule:list`, bounded-worker health, pending/failed queue counts, migration status, and dashboard access. Inspect existing rows only: no raw provider errors or payloads should appear, queued counts require a non-null `queued_at`, and `acceptance_unknown` must be presented as unknown rather than rejected.

## Stop conditions

- Queue and application database connections differ.
- Any old-code queue worker remains when the new worker starts.
- SQLite backup cannot be validated or WAL state is ambiguous.
- Migration failure, missing index/column/table, or historical-row loss.
- A queue row exists without its corresponding export row after the new release, or an export reports queued with no `queued_at`.
- An acknowledged export is downgraded, a stale attempt changes a newer result, or browser output contains internal exception text/payloads.

## Rollback

Keep scan submission and queue consumption paused. Roll back application/assets only if necessary, but leave the additive columns and tracking tables in place so evidence is preserved. Do not execute the three Stage B `down()` migrations after any Stage B evidence exists: they remove correlations/exports and structured summaries.

Before allowing old code to consume again, prove that the queue contains no jobs created by the new release. Although the job constructor remains serialization-compatible, old code does not enforce the new attempt fence or ambiguity semantics. Prefer a forward fix with new-code workers. Restore the SQLite backup only for demonstrated database corruption and only after accepting loss of every legitimate write after the backup point.
