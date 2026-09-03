# Staging Deploy

Staging is deployed via **DigitalOcean App Platform**: pushing to the `staging`
branch triggers an automatic rebuild of the `api` service and the `queue`
worker from the same Docker image/env. Migrations are a manual operator step
after schema changes.

There is no GitHub Actions deploy workflow for this repository — the legacy
SSH-based `deploy-staging.yml` workflow (direct Droplet deploy via
`appleboy/ssh-action`, `systemctl restart php8.4-fpm`, `systemctl reload
caddy`) was removed because it targeted infrastructure that predates the
App Platform migration and had been failing on every run for weeks.

See `ixora-infra/docs/architecture/backend/deploy-pipeline.md` for the
canonical description of the current deploy pipeline.
