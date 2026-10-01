# eTIMS deployment and page loading

Run the migration from the application root **before serving updated code**,
once for each company that uses eTIMS:

```sh
php scripts/migrate_etims.php 0
```

Replace `0` with the company ID in `config_db.php`. This idempotent command
creates/checks the shared background queue and eTIMS tables, installs missing
reference data, and records `etims_schema_version` after successful completion.
Existing credentials, mappings, submissions and stamping settings are retained.
Existing installations also need this one-time run to record the version marker.
Take your normal database backup before deployment migrations.

eTIMS pages now perform only an indexed, read-only version lookup. If setup is
missing or outdated, they show the administrator's migration command instead
of creating or altering tables. An existing login sees the marker immediately;
no logout is required. Customer inquiry restamping uses the same readiness check.
The recurring scheduler no longer runs the eTIMS migration (its existing shared
background-queue setup is unchanged).

Products, Sales and Credit Notes initially show **Load / refresh live data**.
Click it to fetch the provider's current data; Previous/Next fetch the requested
page. X and Z reports offer **Load / refresh current shift** separately from
Close Day. These pages no longer contact the provider just by opening them.
No stale cached fiscal totals are presented. Explicit loads still depend on the
provider's response time (30-second timeout per call; token renewal can add a
login call). PDF downloads continue to fetch on request.

Invoice/credit stamping, retry behavior and the Close Day confirmation are
unchanged. No provider endpoints need to be called to deploy this change.

Offline regression checks (no database connection or provider calls):

```sh
php tests/etims_page_performance.php
```
