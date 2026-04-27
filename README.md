# Drupal LangGraph

Drupal module repo scaffold for consolidating:

- the public roadmap surface previously stranded in `forseti_content`
- the LangGraph admin console currently housed in `forseti-copilot-agent-tracker`
- HQ-backed file contracts from `dashboards/`, `features/`, `orchestrator/`, `scripts/`, `sessions/`, and `tmp/`

## Purpose

This repo is the active single-module `drupal_langgraph` boundary for the
restored public roadmap and consolidated LangGraph admin surface.
It does **not** move live runtime data into Drupal. Instead, it reads
authoritative HQ files from the filesystem.

## Current module contents

- **Public roadmap UI**
  - `/roadmap`
  - `/roadmap/{project_id}`
- **Admin LangGraph console**
  - `/admin/reports/drupal-langgraph/langgraph-console`
  - build / test / run / observe / release / admin subsections
  - Observe subsections: traces, metrics, drift, alerts, feature-progress
  - compatibility-facing aliases under `/admin/reports/drupal-langgraph/langgraph/*`
  - release evidence / release troubleshooting parity sourced from HQ session artifacts
- **HQ-backed services**
  - project registry parsing from `dashboards/PROJECTS.md`
  - project pipeline rollups from `features/*/feature.md`
  - runtime artifact reads from `inbox/responses/*`, `sessions/*/artifacts/*`, and `tmp/release-cycle-active/*`

## Path contracts

The module resolves paths from environment variables when present:

- `FORSETI_ROOT` → defaults to `/home/ubuntu/forseti.life`
- `COPILOT_HQ_ROOT` → optional override for non-canonical runtime roots

By default the module now treats `/home/ubuntu/forseti.life` as the canonical HQ/runtime root and only falls back to older exported/copied HQ roots when the canonical path is unavailable.

From those roots the module expects:

- `dashboards/PROJECTS.md`
- `dashboards/FEATURE_PROGRESS.md`
- `dashboards/LANGGRAPH_CONTROL_PLANE_RUNBOOK.md`
- `features/*/feature.md`
- `inbox/responses/langgraph-ticks.jsonl`
- `inbox/responses/langgraph-parity-latest.json`
- `sessions/*/artifacts/release-candidates/*/05-release-notes.md`
- `sessions/*/artifacts/release-signoffs/*.md`
- `sessions/*/inbox/*`
- `tmp/release-cycle-active/*.release_id`

## Current migration posture

1. `drupal_langgraph` owns the restored live roadmap surface.
2. `forseti-copilot-agent-tracker` remains enabled only as a compatibility shim for legacy admin URLs.
3. Remaining work is additive feature parity and richer admin reporting, not module-boundary cutover.

## Runtime activation notes

As of 2026-04-27, live route exposure is restored.

1. The active production docroot is `/var/www/html/forseti/web`, not
   `/home/ubuntu/forseti.life/sites/forseti/web`.
2. The live module mount is
   `/var/www/html/forseti/web/modules/custom/drupal_langgraph ->
   /home/ubuntu/forseti.life/drupal-langgraph`.
3. The initial 404s were caused by the production Drupal database having
   `drupal_langgraph` disabled, which meant no live router entries existed for
   the module.
4. Enabling the module and rebuilding caches from `/var/www/html/forseti` fixed
   route exposure:
   - `/roadmap` returns `200 OK`
   - `/admin/reports/drupal-langgraph/langgraph-console/observe` returns `403 Forbidden`
     when unauthenticated, confirming the route exists and permissions are being enforced
5. Production CLI bootstrap works from the live tree with:
   - `cd /var/www/html/forseti`
   - `vendor/bin/drush --uri=https://forseti.life status`

Implication: future live verification and operational work should treat
`/var/www/html/forseti` as the production Drupal root, while the module source
continues to live in `/home/ubuntu/forseti.life/drupal-langgraph`.
