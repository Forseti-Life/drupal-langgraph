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
