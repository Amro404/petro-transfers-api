# Station Transfers API

Idempotent, concurrency-safe transfer event ingestion with per-station reconciliation summaries.

## Tech Stack

- **PHP 8.2** / **Laravel 12**
- **Laravel Octane** (FrankenPHP) — optional, for load testing
- **MySQL 8** (Docker; swappable via repository interface)
- **Redis** (queue + lock coordination in Docker)
- **Docker + Docker Compose**
- **k6** (load testing)

## Quick Start

### Local (no Docker)

Requires a running MySQL and Redis instance. Update `.env` with your credentials.

```bash
cd backend
cp .env.example .env
composer install
php artisan key:generate
php artisan migrate
php artisan serve
```

If using async queue, run a worker in a separate terminal:

```bash
cd backend && php artisan queue:work
```

### Run Tests (local)

Tests use SQLite in-memory — no external services needed.

```bash
make test
```

### Docker

Starts the app, a queue worker, MySQL, and Redis:

```bash
make docker-up
```

Run tests in container:

```bash
make docker-test
```

### Docker with Octane (for load testing)

```bash
make docker-up-octane
```

This uses `Dockerfile.octane`, which is built on the FrankenPHP image.

### Why two Dockerfiles?

`Dockerfile` uses `php artisan serve` — single-threaded, good for development.
`Dockerfile.octane` uses **Laravel Octane (FrankenPHP)** with 16 workers — needed for
load testing since the dev server can't handle concurrent requests.

### Make Targets

| Target | Description |
|---|---|
| `make run` | Start dev server (`php artisan serve`) |
| `make run-octane` | Start Octane with FrankenPHP (16 workers, local) |
| `make test` | Run test suite locally |
| `make docker-up` | Build & start Docker services (standard) |
| `make docker-up-octane` | Build & start Docker services (Octane) |
| `make docker-down` | Stop all Docker services |
| `make docker-test` | Run tests inside Docker |
| `make load-test` | Run k6 load test |
| `make load-test-light` | Run lighter k6 smoke test |

## Docker Services

### Standard (`docker-compose.yml`)

| Service | Image | Purpose | Exposed Port |
|---|---|---|---|
| `app` | `php:8.2-cli-alpine` | `php artisan serve` | `8000` |
| `worker` | same | Queue worker (`queue:work redis`) | — |
| `mysql` | `mysql:8.0` | Primary database | `3307` → 3306 |
| `redis` | `redis:7-alpine` | Queue broker + lock store | — |

### Octane (`docker-compose.octane.yml`)

| Service | Image | Purpose | Exposed Port |
|---|---|---|---|
| `app` | `dunglas/frankenphp:php8.2-alpine` | Octane web server (16 workers) | `8000` |
| `worker` | same (×4 replicas) | Queue worker (`queue:work redis`) | — |
| `mysql` | `mysql:8.0` | Primary database | `3307` → 3306 |
| `redis` | `redis:7-alpine` | Queue broker + lock store | — |

Connect to MySQL from local machine:

```bash
mysql -h 127.0.0.1 -P 3307 -u transfers -psecret transfers
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

Returns pre-aggregated data from the `station_summaries` table (O(1) lookup).

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

### `GET /api/stations/{station_id}/summary/live`

Computes the same data on-the-fly directly from `transfer_events` using
`COUNT(*)` and `SUM(CASE WHEN status = 'approved' ...)`. Useful for auditing
and verifying that the cached summaries are correct.

```bash
curl -s "http://localhost:8000/api/stations/S1/summary/live"
```

Response shape is identical to `/summary`. The difference is the source:

| | `/summary` | `/summary/live` |
|---|---|---|
| Source | `station_summaries` (pre-aggregated) | `transfer_events` (computed) |
| Speed | O(1) | O(N) per station |
| Freshness | Eventually consistent | Always exact |
| Use case | Production reads | Auditing / verification |

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
   acquired in sorted order to prevent deadlocks. If the cache driver doesn't support
   locking, the service degrades gracefully and relies solely on the DB constraint.
2. **DB unique constraint** — even if locks are bypassed (e.g. different lock store
   config), `insertOrIgnore` guarantees at-most-once storage per `event_id`.

Lock store is configurable via `TRANSFERS_LOCK_STORE` (defaults to cache driver).
In Docker, Redis provides cross-process lock coordination.

### Station Summaries

Pre-aggregated `station_summaries` table gives O(1) reads. A queued job
(`ApplyStationSummaryIncrementsJob`) incrementally updates totals after each batch insert,
with per-station retries for concurrency safety. `/summary/live` computes the same data
directly from `transfer_events` for auditing. Amounts use `DECIMAL(14,2)` and `bcadd()`
to avoid floating-point drift.

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
| Pre-aggregated summaries + live audit endpoint | O(1) reads; `/summary/live` for verification |
| Per-station micro-transactions with retry | Eliminates cross-station deadlocks under concurrent workers |
| Redis queue in Docker | Decouples summary updates from HTTP response time |
| Graceful lock degradation | Works even if cache driver doesn't support locks |
| Octane only for load testing | Dev server is fine day-to-day; Octane steps in when you need real concurrency |

## OpenAPI

See `backend/openapi.yaml`.

## Tests

15 automated tests covering all required scenarios:

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
| Live summary computes from transfer events | `StationSummaryTest` |
| Live summary for empty station returns zeros | `StationSummaryTest` |
| Live summary matches cached summary | `StationSummaryTest` |
| Concurrent ingestion does not double-insert | `ConcurrencyIngestionTest` |
| Invalid payload shape returns 400 | `IngestPayloadShapeTest` |

```bash
make test
```

## Load Testing (k6)

Requires [k6](https://grafana.com/docs/k6/latest/set-up/install-k6/) installed locally.

Spin up the Octane stack first, then fire off the load test in a second terminal:

```bash
# Terminal 1: start with Octane
make docker-up-octane

# Terminal 2: run k6
make load-test
```

The default scenario gradually ramps up to **200 virtual users**, each firing batches of
**50 events** every ~100 ms. Over the full 8-minute run this adds up to well over
**1 million requests** — enough to surface concurrency bugs and bottlenecks.

| Parameter | Default | Override |
|---|---|---|
| `BASE_URL` | `http://localhost:8000` | `--env BASE_URL=http://host:port` |
| `BATCH_SIZE` | `50` | `--env BATCH_SIZE=100` |
| `STATIONS` | `100` | `--env STATIONS=500` |

Example with custom parameters:

```bash
k6 run --env BASE_URL=http://localhost:8000 --env BATCH_SIZE=50 --env STATIONS=200 k6/load-test.js
```

If you just want a quick sanity check without the full ramp:

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
  entrypoint.sh           # Docker entrypoint (MySQL wait, migrate, serve/test)
  .env.docker             # Docker-specific env (overrides .env at container start)
  openapi.yaml
  Dockerfile              # Standard (php artisan serve)
  Dockerfile.octane       # Octane/FrankenPHP (for load testing)
docker-compose.yml        # Standard Docker setup
docker-compose.octane.yml # Octane Docker setup (for load testing)
Makefile
k6/load-test.js           # k6 load testing script
```
