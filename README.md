# Warehouse Inventory Reservation Engine

A concurrency-safe inventory reservation engine for the Innov8 Hiring Quest, built with Laravel, MySQL, and Redis (via Sail).

## Setup

1. Clone the repo and install dependencies:

```bash
   composer install
```

2. Copy `.env.example` to `.env` and set your app key:

```bash
   cp .env.example .env
   php artisan key:generate
```

3. Start the environment (MySQL + Redis + app, via Docker):

```bash
   sail up -d
```

4. Run migrations and seed sample data:

```bash
   sail artisan migrate:fresh --seed
```

## Running the app

- Process pending shipments:

```bash
  sail artisan shipments:process
  sail artisan queue:work --once
```

- Sweep expired reservations:

```bash
  sail artisan reservations:release-expired
```

## Running tests

```bash
sail artisan test
```

All core reservation and shipment scenarios are covered — see `docs/ARCHITECTURE.md` for details on what's tested and why.

## Assumptions

See `docs/ARCHITECTURE.md` → "Business Rule Decisions" for the five open questions from the brief and how they were resolved.

## Known limitations

- No HTTP API or UI — this challenge is scoped to the inventory engine itself, exercised via Artisan commands, Tinker, and tests.
- Reservation expiry requires an external scheduler trigger (e.g. cron calling the Artisan command)
