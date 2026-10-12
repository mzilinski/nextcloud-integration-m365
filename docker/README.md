# Dev instance

```bash
docker compose -f docker/compose.yml up -d
# wait until http://localhost:8181 shows the login (admin / admin), then:
docker compose -f docker/compose.yml exec -u www-data app php occ app:enable integration_m365
docker compose -f docker/compose.yml exec -u www-data app php occ config:system:set debug --value=true --type=boolean
docker compose -f docker/compose.yml exec -u www-data app php occ config:system:set loglevel --value=0 --type=integer
docker compose -f docker/compose.yml exec -u www-data app php occ background:cron
```

Cron / sync by hand:

```bash
docker compose -f docker/compose.yml exec -u www-data app php -f cron.php
docker compose -f docker/compose.yml exec -u www-data app php occ integration_m365:sync --user=admin -v
docker compose -f docker/compose.yml exec -u www-data app php occ log:tail
```

PHP tooling runs inside the container (the app's PHP version), the frontend on the host:

```bash
docker compose -f docker/compose.yml run --rm composer install
docker compose -f docker/compose.yml exec -w /var/www/html/custom_apps/integration_m365 app vendor/bin/phpunit
docker compose -f docker/compose.yml exec -w /var/www/html/custom_apps/integration_m365 app vendor/bin/psalm.phar
npm ci && npm run build        # or: npm run watch
```

Azure: add `http://localhost:8181/apps/integration_m365/oauth-redirect` as a "Web" redirect URI of the app registration used for development.

## Graph calls with a user's stored token

`docker/dev/graph.php` issues raw Graph requests with the token the app stored for a user (handy for seeding test data):

```bash
docker compose -f docker/compose.yml exec -u www-data app php custom_apps/integration_m365/docker/dev/graph.php mzili GET /me/calendars
docker compose -f docker/compose.yml exec -u www-data app php custom_apps/integration_m365/docker/dev/graph.php mzili POST /me/calendars '{"name":"nc-test-cal"}'
docker compose -f docker/compose.yml exec -u www-data app php custom_apps/integration_m365/docker/dev/graph.php mzili LIST /me/contactFolders
```
