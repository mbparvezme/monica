# Contact Import — Envobyte Assignment

## Setup

```bash
git clone https://github.com/mbparvezme/monica.git
git checkout envobyte-assignment
composer install
yarn install

php artisan migrate
php artisan queue:work --queue=imports
php artisan serve
```

> To get a Sanctum token and vault ID for testing: `php artisan import:token`

## How it works

Upload a CSV to `POST /api/import` with a vault ID. You get back a job ID and can poll progress. In the background the CSV is split into chunks of 50 rows, each a separate queued job. Bad rows are recorded and skipped — a bad email on row 40 doesn't stop the rest.

## API

| Method | Path | |
|--------|------|-|
| POST | `/api/import` | upload CSV, get job ID back |
| GET | `/api/import` | list your imports |
| GET | `/api/import/{id}` | check progress |
| POST | `/api/import/{id}/cancel` | cancel if still running |
| GET | `/api/import/{id}/errors.csv` | download failed rows with error column |

## Notes & decisions

- `vault_id` is required — Monica stores contacts inside vaults. I missed this in the first migration so there's a second one that adds the column.
- Progress is tracked in the DB, not Redis. Monica is self-hosted and many instances won't have Redis. A primary-key lookup on `import_jobs` is fast enough.
- Batch size is 50. Small enough to keep memory low, large enough that a 1000-row file doesn't flood the queue with 100 jobs.
- Raw CSV rows are stored as JSON on the import job so the error CSV can be reconstructed without touching the contacts table.

## Tests

```bash
php artisan test tests/Unit/Domains/Import
```

## Observability

`php artisan import:monitor` — runs automatically every 5 minutes via the scheduler, or manually.

Marks stuck imports as failed (processing for 30+ min):

```sql
SELECT * FROM import_jobs
WHERE status = 'processing'
  AND started_at < NOW() - INTERVAL 30 MINUTE;
```

Alerts if row failure rate exceeds 20% in the last hour:

```sql
SELECT
    SUM(failed_rows) / NULLIF(SUM(total_rows), 0) * 100 AS failure_rate_pct
FROM import_jobs
WHERE completed_at >= NOW() - INTERVAL 1 HOUR
  AND status IN ('completed', 'failed');
```
