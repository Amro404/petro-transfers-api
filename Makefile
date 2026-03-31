.PHONY: run run-octane test docker-up docker-test load-test load-test-light

run:
	cd backend && php artisan serve

run-octane:
	cd backend && php artisan octane:start --server=frankenphp --host=0.0.0.0 --port=8000 --workers=4

test:
	cd backend && php artisan test

docker-up:
	docker compose up --build

docker-test:
	docker compose run --rm app test

load-test:
	k6 run k6/load-test.js

load-test-light:
	k6 run --env BATCH_SIZE=20 k6/load-test.js
