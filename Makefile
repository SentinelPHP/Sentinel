.PHONY: prod-build prod-up prod-down prod-logs prod-clean prod-migrate prod-console prod-deploy

# Catch-all target to allow passing arguments to prod-console
%:
	@:

# =============================================================================
# DDEV Commands (Development)
# =============================================================================
# Development commands are available as native DDEV commands:
#   ddev start, ddev stop, ddev restart, ddev logs, ddev ssh, ddev composer
#   ddev test, ddev phpstan, ddev console, ddev messenger, ddev swoole, ddev init

# =============================================================================
# Production Docker Commands
# =============================================================================

# Load .env first, then .env.local overrides (if exists)
DOCKER_COMPOSE_PROD = docker compose --env-file .env $(if $(wildcard .env.local),--env-file .env.local) -f .docker/docker-compose.prod.yml

prod-build:
	$(DOCKER_COMPOSE_PROD) build

prod-up:
	$(DOCKER_COMPOSE_PROD) up -d

prod-down:
	$(DOCKER_COMPOSE_PROD) down

prod-logs:
	$(DOCKER_COMPOSE_PROD) logs -f

prod-clean:
	$(DOCKER_COMPOSE_PROD) down -v --remove-orphans

prod-migrate:
	$(DOCKER_COMPOSE_PROD) exec app php bin/console doctrine:migrations:migrate --no-interaction

prod-console:
	$(DOCKER_COMPOSE_PROD) exec app php bin/console $(filter-out $@,$(MAKECMDGOALS))

prod-deploy: prod-build prod-up prod-migrate
	@echo "SentinelPHP production deployed successfully"

# =============================================================================
# Load Testing Commands (k6)
# =============================================================================

K6_COMPOSE = docker compose -f tests/load/docker-compose.k6.yml
K6_BASE_URL ?= http://host.docker.internal:8080
K6_API_TOKEN ?= test-token

.PHONY: k6-smoke k6-load k6-stress k6-spike k6-scenario k6-monitoring-up k6-monitoring-down

k6-smoke:
	@echo "Running smoke test..."
	BASE_URL=$(K6_BASE_URL) API_TOKEN=$(K6_API_TOKEN) $(K6_COMPOSE) run --rm k6 run scripts/smoke.js

k6-load:
	@echo "Running load test (this will take ~16 minutes)..."
	BASE_URL=$(K6_BASE_URL) API_TOKEN=$(K6_API_TOKEN) $(K6_COMPOSE) run --rm k6 run scripts/load.js

k6-stress:
	@echo "Running stress test (this will take ~18 minutes)..."
	BASE_URL=$(K6_BASE_URL) API_TOKEN=$(K6_API_TOKEN) $(K6_COMPOSE) run --rm k6 run scripts/stress.js

k6-spike:
	@echo "Running spike test..."
	BASE_URL=$(K6_BASE_URL) API_TOKEN=$(K6_API_TOKEN) $(K6_COMPOSE) run --rm k6 run scripts/spike.js

k6-scenario:
	@echo "Running proxy flow scenario..."
	BASE_URL=$(K6_BASE_URL) API_TOKEN=$(K6_API_TOKEN) $(K6_COMPOSE) run --rm k6 run scenarios/proxy-flow.js

k6-monitoring-up:
	@echo "Starting InfluxDB and Grafana for metrics visualization..."
	$(K6_COMPOSE) --profile monitoring up -d influxdb grafana
	@echo "Grafana available at http://localhost:3000"

k6-monitoring-down:
	$(K6_COMPOSE) --profile monitoring down

k6-load-with-metrics: k6-monitoring-up
	@echo "Running load test with InfluxDB output..."
	BASE_URL=$(K6_BASE_URL) API_TOKEN=$(K6_API_TOKEN) $(K6_COMPOSE) run --rm k6 run --out influxdb=http://influxdb:8086/k6 scripts/load.js

# =============================================================================
# Observability Stack Commands
# =============================================================================

OBSERVABILITY_COMPOSE = docker compose -f .docker/observability/docker-compose.observability.yml

.PHONY: observability-up observability-down observability-logs observability-status

observability-up:
	@echo "Starting observability stack (Prometheus, Grafana, Tempo, Loki)..."
	$(OBSERVABILITY_COMPOSE) up -d
	@echo ""
	@echo "Services available at:"
	@echo "  - Grafana:    http://localhost:3000 (admin/admin)"
	@echo "  - Prometheus: http://localhost:9090"
	@echo "  - Tempo:      http://localhost:3200"
	@echo "  - Loki:       http://localhost:3100"

observability-down:
	$(OBSERVABILITY_COMPOSE) down

observability-logs:
	$(OBSERVABILITY_COMPOSE) logs -f

observability-status:
	$(OBSERVABILITY_COMPOSE) ps

observability-with-logs:
	@echo "Starting observability stack with log collection..."
	$(OBSERVABILITY_COMPOSE) --profile logs up -d
