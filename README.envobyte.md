
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

To run the stuck-import monitor manually: `php artisan import:monitor`. It also runs on the scheduler every 5 minutes via `php artisan schedule:run`.

## How it works

Upload a CSV to `POST /api/import` with a vault ID. You immediately get back a job record with a `pending` status and an ID you can poll. In the background, the CSV is split into chunks of 50 rows, each dispatched as a separate queued job. Per-row errors are recorded individually so a bad email on row 40 doesn't kill the rest of the file. When everything finishes the status flips to `completed` (or `failed` if literally every row errored).

## API

| Method | Path | |
|--------|------|-|
| POST | `/api/import` | upload CSV, get job ID back |
| GET | `/api/import` | list your imports |
| GET | `/api/import/{id}` | check progress |
| POST | `/api/import/{id}/cancel` | cancel if still running |
| GET | `/api/import/{id}/errors.csv` | download failed rows with error message |

> For Sanctum auth token and vault IDs, you can use the following artisan command `php artisan auth:token`

## Assumptions

First I tried to upload the contact without vault ID. But it doesn't work as Monica store the contact inside vault. I only realised this after writing the initial migration, so there's a second migration that adds the column.

The CSV should have a `name` column (or `first_name`). Email is optional but validated if present. Files go to `storage/app/imports/`.

## A few decisions worth explaining

**Why not Redis for progress tracking** — I thought about this. Redis would be faster for the counter updates, but Monica is self-hosted and a lot of instances won't have Redis. Storing progress in the DB means one extra write per batch but the progress endpoint is just a primary-key lookup so it's still fast. Also if a worker crashes, Redis state is gone whereas the DB survives.

**Batch size of 50** — Honestly picked this as a reasonable middle ground. 10 rows per job would give smoother progress updates but creates too many queue entries. 200 uses more memory and makes progress feel choppy on smaller files. 50 seemed right for a personal CRM where files are typically a few hundred rows.

**Raw data stored in the DB** — The parsed CSV rows get saved as JSON on the import job at upload time. This means batch jobs don't need to touch the file on disk, and it's how the error CSV gets reconstructed later without joining against the contacts table. The downside is that a 10k-row file could put a few MB in the `raw_data` column. Fine for personal use, probably not for production at scale.

## Observability

The monitor command checks for stuck imports (anything in `processing` for over 30 minutes) and logs a critical alert if the failure rate across the last hour is over 20%.

Queries behind it:

```sql
-- stuck imports
SELECT * FROM import_jobs
WHERE status = 'processing'
  AND started_at < NOW() - INTERVAL 30 MINUTE;
```

```sql
-- failure rate over last hour
SELECT
    SUM(failed_rows) / NULLIF(SUM(total_rows), 0) * 100 AS failure_rate_pct
FROM import_jobs
WHERE completed_at >= NOW() - INTERVAL 1 HOUR
  AND status IN ('completed', 'failed');
```