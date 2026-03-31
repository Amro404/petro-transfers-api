# Station Transfers API

Idempotent, concurrency-safe transfer event ingestion with per-station reconciliation summaries.

## Tech Stack

- **PHP 8.2** / **Laravel 12**
- **MySQL 8** (Docker; swappable via repository interface)
- **Redis** (lock coordination in Docker; file-based fallback locally)
- **Docker + Docker Compose**

## Quick Start

### Local (no Docker)

Requires a running MySQL instance. Update `.env` with your credentials.

```bash
cd backend
cp .env.example .env
composer install
php artisan key:generate
php artisan migrate
php artisan serve
```

Or with Make:

```bash
make run
```

### Run Tests (local)

```bash
cd backend
php artisan test
```

Or:

```bash
make test
```

### Docker

```bash
docker compose up --build
```

Run tests in container:

```bash
docker compose run --rm app test
```

Or:

```bash
make docker-test
```

## API

### `POST /api/transfers`

Ingest a batch of transfer events. Idempotent by `event_id`.

```bash
curl -s -X POST "http://localhost:8000/api/transfers" \
  -H "Content-Type: application/json" \
  -d '{
    "events": [
      {
        "event_id": "evt-1",
        "station_id": "S1",
        "amount": 100.5,
        "status": "approved",
        "created_at": "2026-02-19T10:00:00Z"
      }
    ]
  }'
```

Response:

```json
{
  "inserted": 1,
  "duplicates": 0,
  "invalid": 0,
  "failed": 0
}
```

- `inserted` — newly stored events
- `duplicates` — ignored (event_id already exists or repeated in batch)
- `invalid` — events that failed per-item validation (stored in `invalid_events` table)
- `failed` — events lost to unexpected errors (also stored in `invalid_events`)

### `GET /api/stations/{station_id}/summary`

```bash
curl -s "http://localhost:8000/api/stations/S1/summary"
```

Response:

```json
{
  "station_id": "S1",
  "total_approved_amount": 450.25,
  "events_count": 12
}
```

- `total_approved_amount` — sum of `amount` for **approved events only**
- `events_count` — count of **all stored events** for that station (all statuses)

### Error handling (400)

If the top-level payload shape is invalid (e.g. missing `events` array):

```json
{
  "message": "Validation failed",
  "errors": { "events": ["The events field is required."] }
}
```

## Design Notes

### Error Strategy: Partial Accept

Individual events are validated per-item. Valid events are inserted; invalid ones are
recorded in the `invalid_events` table with the failure reason, and the response reports
accurate counts for each category. This avoids one bad event blocking an entire batch.

### Idempotency

- `transfer_events.event_id` has a **unique DB constraint**.
- Inserts use `INSERT IGNORE` (`insertOrIgnore`), so duplicate `event_id` values
  are silently skipped at the DB level — no check-then-insert race.
- Within a single batch, events are deduplicated by `event_id` before insert.

### Concurrency

Two layers prevent double-inserts under concurrent requests:

1. **Bucketed cache locks** (`IngestionLockService`) — event IDs are hashed into 256
   lock buckets. Overlapping batches that share any bucket are serialized. Locks are
   acquired in sorted order to prevent deadlocks.
2. **DB unique constraint** — even if locks are bypassed (e.g. different lock store
   config), `insertOrIgnore` guarantees at-most-once storage per `event_id`.

Lock store is configurable via `TRANSFERS_LOCK_STORE` (defaults to cache driver).
In Docker, Redis provides cross-process lock coordination. Locally, the `file` driver
works for single-machine setups.

### Station Summaries (Incremental)

Rather than running `SUM()`/`COUNT()` on every summary request, the system maintains a
`station_summaries` table with pre-aggregated totals. After each batch insert,
`ApplyStationSummaryIncrementsJob` computes per-station deltas for truly new events and
atomically increments the summary row (`events_count`, `total_approved_amount`).

The job runs synchronously via Laravel's `sync` queue driver by default, so summaries
are consistent immediately after `POST /transfers` returns. To process asynchronously,
set `QUEUE_CONNECTION=redis` and run `php artisan queue:work` — summaries will then be
eventually consistent.

### Numeric Precision

Amounts are stored as `DECIMAL(14,2)` in both `transfer_events` and `station_summaries`.
Increments use SQL `total_approved_amount + N` to avoid PHP floating-point drift during
aggregation.

### Storage Abstraction

All persistence goes through `TransferEventRepositoryInterface`. The default
`EloquentTransferEventRepository` uses MySQL in Docker. To swap to Postgres or an
in-memory store, implement the interface and rebind in `AppServiceProvider`.
Tests use SQLite in-memory for speed and zero external dependencies.

### Tradeoffs

| Decision | Rationale |
|---|---|
| Partial accept over fail-fast | One bad event should not block a large batch |
| Bucketed locks (256 buckets) | Balance between lock granularity and contention |
| Pre-aggregated summaries | O(1) reads; slight complexity on write path |
| Sync queue default | Strong consistency; async available via config |

## OpenAPI

See `backend/openapi.yaml`.

## Tests

12 automated tests covering all required scenarios:

| Test | File |
|---|---|
| Batch insert returns correct inserted/duplicates | `TransfersIngestionTest` |
| Duplicate event does not change totals | `TransfersIngestionTest` |
| Out-of-order arrival still produces same totals | `TransfersIngestionTest` |
| Validation failure (partial accept) | `TransfersIngestionTest` |
| Mixed valid/invalid batch inserts valid only | `TransfersIngestionTest` |
| Unknown status stored but excluded from approved total | `TransfersIngestionTest` |
| Amount zero is valid | `TransfersIngestionTest` |
| Summary endpoint correctness per station | `StationSummaryTest` |
| Empty station returns zeros | `StationSummaryTest` |
| Concurrent ingestion does not double-insert | `ConcurrencyIngestionTest` |
| Invalid payload shape returns 400 | `IngestPayloadShapeTest` |

Run all with one command:

```bash
cd backend && php artisan test
```

## Load Testing (k6)

Requires [k6](https://grafana.com/docs/k6/latest/set-up/install-k6/) installed locally.

```bash
make load-test
```

The default scenario ramps up to **2 000 virtual users**, each sending batches of **50 events**
every ~100 ms. Over the 7-minute run this generates well over **1 million requests**
(50+ million events).

| Parameter | Default | Override |
|---|---|---|
| `BASE_URL` | `http://localhost:8000` | `--env BASE_URL=http://host:port` |
| `BATCH_SIZE` | `50` | `--env BATCH_SIZE=100` |
| `STATIONS` | `100` | `--env STATIONS=500` |

Example with custom parameters:

```bash
k6 run --env BASE_URL=http://localhost:8000 --env BATCH_SIZE=100 --env STATIONS=200 k6/load-test.js
```

A lighter variant for quick smoke tests:

```bash
make load-test-light
```

Results are printed to stdout and saved to `k6/results.json`.

## Project Structure

```
backend/
  app/
    DTO/                  # IngestTransfersDTO, TransferEventDTO
    Http/Controllers/     # TransferController, StationSummaryController
    Http/Requests/        # IngestTransfersRequest (payload shape validation)
    Jobs/                 # ApplyStationSummaryIncrementsJob
    Models/               # TransferEvent, InvalidEvent
    Repositories/         # TransferEventRepositoryInterface, EloquentTransferEventRepository, InvalidEventRepository
    Services/             # TransferIngestionService, TransferEventValidationService, IngestionLockService, IngestionMetricsService, StationSummaryService
  config/transfers.php    # Lock store configuration
  database/migrations/    # transfer_events, station_summaries, invalid_events
  entrypoint.sh           # Docker entrypoint (migrate + serve or test)
  openapi.yaml
  Dockerfile
docker-compose.yml
Makefile
k6/load-test.js               # k6 load testing script
```
