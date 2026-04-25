<?php

namespace Drupal\drupal_langgraph\Service;

final class HqPathManager {

  private const DEFAULT_FORSETI_ROOT = '/home/ubuntu/forseti.life';

  private const FALLBACK_RUNTIME_ROOTS = [
    '/home/ubuntu/forseti.life',
    '/home/ubuntu/forseti.life/copilot-hq',
    '/home/ubuntu/copilot-sessions-hq',
    '/home/keithaumiller/copilot-sessions-hq',
  ];

  public function forsetiRoot(): string {
    $configured = trim((string) getenv('FORSETI_ROOT'));
    if ($configured !== '') {
      return rtrim($configured, '/');
    }

    return self::DEFAULT_FORSETI_ROOT;
  }

  public function hqRuntimeRoot(): string {
    $forseti_root = $this->forsetiRoot();
    $configured = trim((string) getenv('COPILOT_HQ_ROOT'));
    $legacy_export_root = $forseti_root . '/copilot-hq';
    if ($configured !== '' && is_dir($configured)) {
      $configured = rtrim($configured, '/');
      if ($configured !== $legacy_export_root || !is_dir($forseti_root)) {
        return $configured;
      }
    }

    foreach ($this->runtimeRootCandidates($forseti_root) as $candidate) {
      if (is_dir($candidate)) {
        return rtrim($candidate, '/');
      }
    }

    return $forseti_root;
  }

  public function resolveForseti(string $relative_path): string {
    return $this->forsetiRoot() . '/' . ltrim($relative_path, '/');
  }

  public function resolveRuntime(string $relative_path): string {
    return $this->hqRuntimeRoot() . '/' . ltrim($relative_path, '/');
  }

  public function projectRegistryPath(): string {
    return $this->resolveForseti('dashboards/PROJECTS.md');
  }

  public function featuresPath(): string {
    return $this->resolveForseti('features');
  }

  public function artifactPaths(): array {
    return [
      'ticks' => $this->resolveRuntime('inbox/responses/langgraph-ticks.jsonl'),
      'parity' => $this->resolveRuntime('inbox/responses/langgraph-parity-latest.json'),
      'orchestrator_log' => $this->resolveRuntime('inbox/responses/orchestrator-latest.log'),
      'release_cycle_dir' => $this->resolveForseti('tmp/release-cycle-active'),
      'release_control_legacy' => $this->resolveForseti('tmp/release-cycle-control.json'),
      'release_control_default' => '/var/tmp/copilot-sessions-hq/release-cycle-control.json',
      'org_control_legacy' => $this->resolveForseti('tmp/org-control.json'),
      'org_control_default' => '/var/tmp/copilot-sessions-hq/org-control.json',
      'graph_definition' => $this->resolveForseti('orchestrator/langgraph/graph.py'),
      'feature_progress' => $this->resolveForseti('dashboards/FEATURE_PROGRESS.md'),
      'langgraph_runbook' => $this->resolveForseti('dashboards/LANGGRAPH_CONTROL_PLANE_RUNBOOK.md'),
      'org_roadmap' => $this->resolveForseti('ROADMAP.md'),
      'sessions_dir' => $this->resolveForseti('sessions'),
    ];
  }

  /**
   * Prefer the canonical HQ root and keep older exports as compatibility fallbacks.
   */
  private function runtimeRootCandidates(string $forseti_root): array {
    $candidates = [
      $forseti_root,
      $forseti_root . '/copilot-hq',
    ];

    foreach (self::FALLBACK_RUNTIME_ROOTS as $candidate) {
      if (!in_array($candidate, $candidates, TRUE)) {
        $candidates[] = $candidate;
      }
    }

    return $candidates;
  }

}
