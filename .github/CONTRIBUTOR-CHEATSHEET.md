Quick start cheat-sheet — new contributors

Use this short card to get productive fast.

- What this repo is: Laravel (src/) backend + Angular (frontend/) client. Docker compose is provided for local dev.

- Quick dev: Backend (local)
  - cd src
  - composer install
  - copy .env.example to .env
  - php artisan key:generate
  - php artisan serve  # runs on php built-in server

- Quick dev: Frontend (local)
  - cd frontend
  - npm install
  - npm start  # serves at 0.0.0.0:4200

- Docker (recommended for parity)
  - docker-compose up --build
  - Useful ports: 
    - Backend (nginx): http://localhost:8069
    - PHP container: 8068
    - Angular dev: http://localhost:4200
    - phpMyAdmin: http://localhost:8091
  - One-off artisan (inside container):
    - docker-compose run --rm angular_laravel_artisan migrate

- Tests
  - Backend: cd src && composer test  # runs phpunit via artisan test
  - Frontend unit tests: cd frontend && npm test

- Important quick references
  - API routes: `src/routes/api.php`
  - Complex billing flow: `src/app/Http/Controllers/Api/CobroController.php`
  - SIN / SIAT config: `src/config/sin.php` and `src/.env.example`
  - SIN helpers & SOAP clients: `src/app/Services/Siat/*` and `app/Repositories/Sin/*`
  - Websocket server: `websocket/websocket-server.php`
  - Frontend HTTP helpers/services (cobros): `frontend/src/app/services/cobros.service.ts`

- Common patterns / gotchas
  - Billing uses composite keys (cod_ceta, cod_pensum, tipo_inscripcion, nro_cobro). Check routes & migrations before changing endpoints.
  - Facturas vs Recibos cannot be mixed in the same batch (see `CobroController::batchStore`).
  - SIN integrations are external and flaky; prefer `config('sin.offline')` or stub `SoapClientFactory` in tests.

If you'd like this shortened further into a single reference card for the README or PR template, I can produce that next.
