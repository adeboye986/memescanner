# cPanel Background Processes

These commands are designed for shared cPanel hosting. They are short-lived, use file-backed atomic locks, and do not require an SSH session, Supervisor, systemd, Redis, or Docker.

Replace `/path/to/application` with the deployment directory. The production PHP CLI must be PHP 8.4 or newer.

## Cron entries

Run the Laravel scheduler every minute:

```cron
* * * * * cd /path/to/application && /opt/cpanel/ea-php84/root/usr/bin/php artisan schedule:run >> /dev/null 2>&1
```

Drain the database queue every minute. The worker stops when the queue is empty or after its configured maximum lifetime:

```cron
* * * * * cd /path/to/application && /opt/cpanel/ea-php84/root/usr/bin/php artisan app:queue-drain >> /dev/null 2>&1
```

The drain prioritizes the `telegram` queue before the `default` queue. Callback acknowledgements occur synchronously at the authenticated webhook before either queue is used; queued processing performs the remaining menu or business action. `QUEUE_DRAIN_MAX_TIME` bounds how long the worker waits for more jobs, while `QUEUE_JOB_TIMEOUT` allows an already-started scanner job to finish safely.

`QUEUE_STALE_SECONDS` must remain longer than `QUEUE_JOB_TIMEOUT` plus startup/cleanup headroom. The provided defaults are 750 and 600 seconds respectively, preventing a healthy long scanner job from being reported as a dead worker.

Run a bounded fast tracker for most of each minute. With the default one-second interval, 50 cycles provide approximately 50 seconds of coverage:

```cron
* * * * * cd /path/to/application && /opt/cpanel/ea-php84/root/usr/bin/php artisan tokens:paper-track:fast --max-cycles=50 >> /dev/null 2>&1
```

For the current production location, replace `/path/to/application` with `/home1/newconci/memescanner`. Paths are intentionally not embedded in application code.

## Deployment and recovery

After deploying approved code:

```bash
cd /path/to/application
/opt/cpanel/ea-php84/root/usr/bin/php artisan migrate --force
/opt/cpanel/ea-php84/root/usr/bin/php artisan optimize:clear
/opt/cpanel/ea-php84/root/usr/bin/php artisan config:cache
/opt/cpanel/ea-php84/root/usr/bin/php artisan route:cache
/opt/cpanel/ea-php84/root/usr/bin/php artisan view:cache
/opt/cpanel/ea-php84/root/usr/bin/php artisan queue:restart
```

The cron-driven queue worker exits naturally; `queue:restart` is safe and ensures any active worker stops after its current job.

Inspect failures without retrying expired Telegram callback acknowledgements:

```bash
/opt/cpanel/ea-php84/root/usr/bin/php artisan queue:failed
```

Retry only jobs whose underlying business operation is known to be safe and idempotent. Old Telegram callback IDs have expired and should not be retried merely to acknowledge the callback.

Verify health:

```bash
/opt/cpanel/ea-php84/root/usr/bin/php artisan app:health
/opt/cpanel/ea-php84/root/usr/bin/php artisan schedule:list
/opt/cpanel/ea-php84/root/usr/bin/php artisan queue:monitor default:100
```

The admin dashboard reports scheduler, queue, fast-tracker, pending-job, and failed-job health without exposing credentials or server paths.
