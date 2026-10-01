# Background process

One cron entry runs everything that is queued (Communications SMS/email/WhatsApp
sends, eTIMS stamping retries):

```
* * * * * php /path/to/cron/run_scheduled_tasks.php <company_id> >> /path/to/cron/logs/cron.log 2>&1
```

- Only one runner works at a time (a database lock); overlapping runs exit quietly.
- Failed tasks retry with a doubling delay (capped at one hour) up to 5 attempts.
- Queue state and manual retry: **Setup > Maintenance > Background Tasks**.
- New background work is a `bg_task_register()` handler plus `bg_task_enqueue()`;
  never add another crontab line.

# Deployment order

1. Back up the database.
2. `php scripts/run_migrations.php <company_id> --list` (inspect), then `--apply`.
   Migrations never run from a page load; the Setup screen only reports status.
3. Sign in, open **Setup > Access Setup** and assign the new rights (System Audit
   Trail, Run Database Migrations, Background Tasks, eTIMS, Communications) to the
   roles that need them. `--apply` grants them to System Administrator only.
4. Communications: tick **Communications** in Company Setup, then configure
   gateways and rules under the Communications tab.
5. eTIMS: enter provider credentials under **Setup > eTIMS Integration**, map
   items, then switch stamping on.
