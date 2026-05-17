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

> **Checkpoint note (2026-05-17):** This repository has been checkpoint-verified on `main`; refer to recent commits for the currently captured LangGraph/admin-surface state.

## Repository status

This repository is the standalone source of truth for the `drupal_langgraph` module. It integrates with HQ filesystem contracts, but it is versioned and released independently from `copilot-hq`.

## Dependency model

`drupal_langgraph` is **not** a fully self-sufficient product repository.

- The **module code** lives in this repository: `/home/ubuntu/forseti.life/drupal-langgraph`
- The module's **primary external dependency repository** is:
  `/home/ubuntu/forseti.life/copilot-hq`
- Some shared HQ documentation reached through compatibility paths may be
  sourced from:
  `/home/ubuntu/forseti.life/forseti-docs`
- Some runtime control artifacts are written to Drupal private storage under the
  live site and are therefore **not stored in git**

In practice, this repo provides the Drupal UI layer, while the surrounding HQ
repositories and runtime state provide the operational data that the UI renders.

## Repository and runtime dependencies

The module depends on the following external repositories and runtime surfaces:

| Dependency surface | Source of truth | Notes |
|---|---|---|
| Module code (`routes`, `controllers`, `forms`, `services`, templates, JS/CSS) | `drupal-langgraph` | Self-contained Drupal module code |
| HQ roadmap, org chart, ownership, runtime graph exports, orchestration artifacts | `copilot-hq` | Primary external repository dependency |
| Shared HQ docs behind compatibility paths | `forseti-docs` | Accessed through HQ compatibility paths when applicable |
| Drupal private artifact storage (`private://drupal_langgraph/...`) | Live Drupal files | Runtime request/replay/promotion artifacts; not versioned in git |
| Drupal config/database | Live Drupal site | Stores enabled module state and custom flow config |

## What comes from `copilot-hq`

The module reads or shells out to these `copilot-hq` surfaces:

- `dashboards/PROJECTS.md`
- `dashboards/FEATURE_PROGRESS.md`
- `dashboards/LANGGRAPH_CONTROL_PLANE_RUNBOOK.md`
- `features/*/feature.md`
- `org-chart/agents/agents.yaml`
- `org-chart/ownership/module-ownership.yaml`
- `org-chart/ownership/repository-ownership.yaml`
- `orchestrator/runtime_graph/export_flow_catalog.py`
- `inbox/responses/langgraph-ticks.jsonl`
- `inbox/responses/langgraph-llm-usage.jsonl`
- `inbox/responses/langgraph-parity-latest.json`
- `inbox/responses/orchestrator-latest.log`
- `sessions/*/...`
- `tmp/release-cycle-active/*`
- `tmp/executor-failures/*`

These dependencies are operationally required for the roadmap, observe, org
chart, and runtime-control surfaces to show meaningful data.

## Current module contents

- **Public roadmap UI**
  - `/roadmap`
  - `/roadmap/{project_id}`
- **Admin LangGraph console**
  - `/admin/reports/drupal-langgraph/langgraph-console`
  - `/admin/reports/drupal-langgraph/langgraph-console/org-chart`
  - build / test / run / observe / release / admin subsections
  - org chart view for seat hierarchy, ownership mappings, and instruction layers
  - Chart.js hierarchy diagram with Board as the root, a clustered CEO layer for readability, node drill-in to seat details, and in-node expand/collapse controls for manager branches
- Observe subsections: traces, metrics, drift, alerts, integrations, feature-progress
- the integrations view reads `langgraph-llm-usage.jsonl` to show backend coverage plus token-utilization rollups by backend/model
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

Important clarification:

- In the current live layout, the resolved canonical root contains the
  `copilot-hq` repository as a sibling workspace dependency, and many of the
  files below are actually satisfied by `copilot-hq`
- `COPILOT_HQ_ROOT` controls which runtime artifact root is read for
  `inbox/responses/*` and the runtime graph export
- `FORSETI_ROOT` controls the wider workspace root used for paths such as
  `tmp/`, `sessions/`, `features/`, and compatibility-facing HQ content

From those roots the module expects:

- `dashboards/PROJECTS.md`
- `dashboards/FEATURE_PROGRESS.md`
- `dashboards/LANGGRAPH_CONTROL_PLANE_RUNBOOK.md`
- `features/*/feature.md`
- `inbox/responses/langgraph-ticks.jsonl`
- `inbox/responses/langgraph-llm-usage.jsonl`
- `inbox/responses/langgraph-parity-latest.json`
- `sessions/*/artifacts/release-candidates/*/05-release-notes.md`
- `sessions/*/artifacts/release-signoffs/*.md`
- `sessions/*/inbox/*`
- `tmp/release-cycle-active/*.release_id`

If those files are absent, the module still installs and routes correctly, but
the affected admin surfaces will render empty, degraded, or request-only views.

## Runtime control dependency

The **Run**, **Test**, and **Release** control surfaces are not autonomous.

- The module can write runtime request, replay request, version, and promotion
  artifacts
- Those artifacts are written either to:
  - Drupal private storage: `private://drupal_langgraph/...`
  - or the filesystem fallback under `FORSETI_ROOT/tmp/langgraph-control-requests/...`
- External worker scripts in `copilot-hq/scripts/` are responsible for
  consuming those artifacts and performing the actual execution

Implication: the module can record operator intent on its own, but actual
LangGraph execution and control-plane changes depend on the HQ runtime outside
this repository.

## Current migration posture

1. `drupal_langgraph` owns the restored live roadmap surface.
2. `forseti-copilot-agent-tracker` remains enabled only as a compatibility shim for legacy admin URLs.
3. The module now includes flow authoring, runtime/replay/promotion control artifacts, and admin governance views; remaining work is incremental UX and feature maturity, not module-boundary cutover.
4. Built-in flow ownership now uses real seat IDs (for example `ceo-copilot-2`) instead of generic labels, and the Org Chart page exposes how seats, reporting lines, ownership files, and instruction layers connect to the control plane.

## Operator UX posture

- Console pages now include workspace-level guidance so operators can understand
  what each page is for and how it connects to neighboring sections.
- Process-flow forms use shared help text for authoring, runtime control,
  replay, and release actions so the module explains consequences before users
  write artifacts.
- Flow lifecycle remains intentionally split:
  - **Build** owns the editable flow contract
  - **Test** validates authored structure
  - **Run** records execution controls
  - **Observe** reads runtime evidence
  - **Release** captures and promotes versions
- Flow detail pages now derive **Phase Summary** and **Execution Lanes** tables
  from the directed transition graph so operators can see where a flow stays
  linear, where it fans out into parallel branches, and where those branches
  join back together before validation.
- Org structure is now visible in the module:
  - **Org Chart** maps seats from `org-chart/agents/agents.yaml`
  - the CEO first layer is clustered in the diagram so product leads, shared capabilities, executive extensions, and paused seats do not render as one flat row
  - flow owners are rendered as seat relationships
  - instruction layers are represented as `org-wide -> role -> site/product -> seat`
  - diagram node clicks open the matching seat detail panel without leaving the page

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
