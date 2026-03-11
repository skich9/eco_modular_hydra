Repository snapshot — quick guide for AI coding agents

This is a combined Laravel (backend) + Angular (frontend) project with Docker development helpers.
Goal: be concise — show where to look, how to run things, and the project-specific conventions an agent should follow.

1) Big picture
- Backend: src/ (Laravel 12, PHP 8.2). Responsible for business logic, SIN/SIAT invoicing flows, queues, websocket server.
- Frontend: frontend/ (Angular 20). Single-page app, SSR-ready (server files are present). The UI calls the backend API.
- Dev containers: docker-compose.yml sets up MySQL, PHP/nginx, Redis, Angular dev server, composer/artisan helpers and a queue worker.

2) Important files to read first (high signal)
- src/routes/api.php — top-level API surface and important endpoints (cobros, sin, facturas, webhooks).
- src/app/Http/Controllers/Api/CobroController.php — billing / batch-store / SIN/CUFD/CUF flows (complex example of domain logic).
- src/config/sin.php and src/.env.example — where SIN/SGA configuration lives (CUIS/CUFD/API keys, offline mode).
- src/app/Services/Siat/ (RecepcionPaqueteService, CufGenerator, EstadoFacturaService) — integration with the tax service.
- websocket/websocket-server.php and src/websocket/ — real-time events (Workerman websocket process) used for invoice events.
- frontend/src/app/services/* and frontend/README.md — how the frontend interacts with the API (examples: cobros.service.ts uses /facturas and /sin endpoints).

3) Dev & test flows (what commands actually work here)
- Local (non-Docker):
  - Backend dev: cd src; composer install; cp .env.example .env; php artisan key:generate; php artisan serve
  - Frontend dev: cd frontend; npm install; npm start (Angular serves on 0.0.0.0:4200)
  - Backend tests: cd src; composer test (runs `php artisan test` / phpunit)

- Dockerized dev (recommended for matching environment):
  - Start (all): docker-compose up --build
  - Useful services: backend HTTP is exposed from nginx at 8069, PHP node at 8068, Angular dev at 4200, phpmyadmin at 8091.
  - One-off artisan commands: docker-compose run --rm angular_laravel_artisan migrate or docker exec -it angular_laravel_php sh -c "php artisan <command>"
  - Run only frontend builder (manual): docker-compose run --rm angular_laravel_angular_builder

4) Project-specific patterns / conventions
- Billing (cobros) has composite keys in routes and DB (cod_ceta, cod_pensum, tipo_inscripcion, nro_cobro) — check routes/api.php and migrations.
- The system must support SIN (Bolivian tax) online/offline flows. Look for config('sin.*') and services under app/Services/Siat/. Tests or features that touch tax services should mock external SW/soap calls.
- Facturas vs Recibos: CobroController.batchStore groups items into either RECIBO or FACTURA and enforces not mixing them in a single batch.
- CUIS / CUFD / CUF flows are cached and handled by Repositories (app/Repositories/Sin/). Be careful: operations may be atomic and use FacturaService->nextFacturaAtomic when present.
- Logging & fallback: many endpoints use friendly fallbacks when external SGA/SIN is unavailable (see routes/api.php and controllers). Prefer inspecting logs when reproducing billing issues.

5) Safety and tests
- External SIN/SGA integrations are network/soap heavy. When writing tests: stub SoapClientFactory or use config('sin.offline') for deterministic behavior.
- Use database transactions when writing integration tests for invoice/receipt flows. The repo already uses FacturaService/ReciboService helpers with atomic increments.

6) How an AI agent should help
- For feature work: always reference the API route(s) and controller(s) you change (e.g., `routes/api.php` and `app/Http/Controllers/Api/CobroController.php`).
- For changes that affect billing, mention tests that should cover: batchStore (validations), FACTURA vs RECIBO separation, CUFD/CUF generation and SIN offline mode.
- For any modification touching SIN/SGA, list required env vars in `src/.env.example` and `src/config/sin.php` — avoid hardcoding credentials or endpoints.

If anything above is unclear or you need more detail (examples, short design notes or additional references), I can iterate — tell me which area to expand. ✅
