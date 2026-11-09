UID := $(shell id -u)
GID := $(shell id -g)
export UID GID

.PHONY: dev dev-down dev-reset

dev:
	docker compose up -d --build --wait
	docker compose exec -T -u www-data app composer install --no-interaction
	@echo ""
	@echo "OwnPay is starting at http://localhost:8080"
	@echo "First run: open http://localhost:8080/install to complete setup."
	@echo "Stop with: docker compose down (data preserved) or make dev-down."

dev-down:
	docker compose down

dev-reset:
	docker compose down -v