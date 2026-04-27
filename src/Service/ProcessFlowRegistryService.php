<?php

namespace Drupal\drupal_langgraph\Service;

use Drupal\Core\Config\ConfigFactoryInterface;

final class ProcessFlowRegistryService {

  private const CONFIG_NAME = 'drupal_langgraph.process_flows';

  public function __construct(
    private readonly ConfigFactoryInterface $configFactory,
  ) {}

  public function allFlows(): array {
    $flows = [];
    foreach (array_merge($this->builtInFlows(), $this->customFlows()) as $flow) {
      $flows[$flow['id']] = $flow;
    }

    uasort($flows, static function (array $a, array $b): int {
      return strcmp((string) ($a['label'] ?? $a['id'] ?? ''), (string) ($b['label'] ?? $b['id'] ?? ''));
    });
    return $flows;
  }

  public function getFlow(string $flow_id): ?array {
    $flows = $this->allFlows();
    return $flows[$flow_id] ?? NULL;
  }

  public function saveFlow(array $flow): void {
    $config = $this->configFactory->getEditable(self::CONFIG_NAME);
    $flows = $config->get('flows');
    $flows = is_array($flows) ? $flows : [];

    $flows = array_values(array_filter($flows, static fn(array $item): bool => ($item['id'] ?? '') !== $flow['id']));
    $flows[] = $flow;

    usort($flows, static fn(array $a, array $b): int => strcmp((string) ($a['label'] ?? ''), (string) ($b['label'] ?? '')));
    $config->set('flows', $flows)->save();
  }

  public function commandControlMap(): array {
    return [
      ['Create flow', 'New process flow', 'Flows'],
      ['Open flow control panel', 'Open', 'Flows'],
      ['Define state schema', 'Schema editor', 'Build'],
      ['Add node', 'Add node', 'Build'],
      ['Connect conditional routing', 'Add transition', 'Build'],
      ['Bind tools', 'Bind tool', 'Build'],
      ['Configure prompts and policy', 'Edit prompt config', 'Build'],
      ['Validate graph structure', 'Validate structure', 'Test'],
      ['Replay checkpoint', 'Replay checkpoint', 'Test'],
      ['Run manual execution', 'Run now', 'Run'],
      ['Pause or resume execution', 'Pause run / Resume run', 'Run'],
      ['Inspect traces', 'Open traces', 'Observe'],
      ['Inspect runtime drift', 'Open drift', 'Observe'],
      ['Create version', 'Create version', 'Release'],
      ['Promote version', 'Promote', 'Release'],
    ];
  }

  private function builtInFlows(): array {
    return [
      [
        'id' => 'hq_orchestrator_tick',
        'label' => 'HQ Orchestrator Tick',
        'description' => 'Primary LangGraph control-plane flow that coordinates the HQ tick pipeline and worker selection.',
        'owner' => 'drupal_langgraph',
        'status' => 'active',
        'graph_type' => 'state_graph',
        'primary_section' => 'run',
        'default_entrypoint' => 'consume_replies',
        'version' => 'runtime-observed',
        'source' => 'built-in',
        'state_schema_summary' => 'Tick state tracks selected agents, step results, provider metadata, and control-plane toggles.',
        'nodes' => ['consume_replies', 'dispatch_commands', 'release_cycle', 'coordinated_push', 'pick_agents', 'exec_agents', 'health_check', 'kpi_monitor', 'publish'],
        'routing_rules' => ['Always execute tick nodes in pipeline order.', 'Skip downstream steps only when upstream control gates disable execution.'],
        'tools' => ['hq_artifacts', 'runtime_ticks', 'agent_selection', 'publish_contract'],
        'prompt_notes' => 'Primary orchestration prompt must preserve deterministic control-plane ordering and auditable step results.',
      ],
      [
        'id' => 'release_cycle_automation',
        'label' => 'Release Cycle Automation',
        'description' => 'Release orchestration flow that tracks current and next release markers, evidence, and signoff readiness.',
        'owner' => 'drupal_langgraph',
        'status' => 'active',
        'graph_type' => 'subgraph',
        'primary_section' => 'release',
        'default_entrypoint' => 'release_cycle',
        'version' => 'runtime-observed',
        'source' => 'built-in',
        'state_schema_summary' => 'Release state tracks current/next release IDs, signoff evidence, and promotion readiness by site.',
        'nodes' => ['release_cycle', 'release_evidence', 'release_troubleshooting'],
        'routing_rules' => ['Advance only when required signoff artifacts are present.', 'Surface incomplete evidence as troubleshooting work rather than silent pass-through.'],
        'tools' => ['release_artifacts', 'signoff_discovery', 'flow_registry'],
        'prompt_notes' => 'Release prompts should bias toward evidence-backed status and explicit blockers.',
      ],
      [
        'id' => 'feature_progress_pipeline',
        'label' => 'Feature Progress Pipeline',
        'description' => 'Workflow that materializes feature progress and provides the read-only snapshot used across the console.',
        'owner' => 'drupal_langgraph',
        'status' => 'active',
        'graph_type' => 'subgraph',
        'primary_section' => 'observe',
        'default_entrypoint' => 'feature_progress',
        'version' => 'artifact-backed',
        'source' => 'built-in',
        'state_schema_summary' => 'Feature progress state tracks work-item status, module ownership, readiness, and generated summaries.',
        'nodes' => ['feature_progress_extract', 'feature_progress_summarize'],
        'routing_rules' => ['Include LangGraph-owned rows in flow-scoped observe views.', 'Preserve generated timestamp with each snapshot.'],
        'tools' => ['feature_progress_markdown', 'ownership_map'],
        'prompt_notes' => 'Summaries should stay concise and preserve status vocabulary from source artifacts.',
      ],
      [
        'id' => 'runtime_observability',
        'label' => 'Runtime Observability',
        'description' => 'Observability workflow for traces, metrics, anomalies, blocked work, and incident signals.',
        'owner' => 'drupal_langgraph',
        'status' => 'active',
        'graph_type' => 'supervisor_graph',
        'primary_section' => 'observe',
        'default_entrypoint' => 'observe',
        'version' => 'artifact-backed',
        'source' => 'built-in',
        'state_schema_summary' => 'Observability state tracks traces, metrics, anomalies, incidents, and drift markers across recent ticks.',
        'nodes' => ['observe_traces', 'observe_metrics', 'observe_drift', 'observe_alerts'],
        'routing_rules' => ['Route error-like signals to alerts.', 'Keep drift and metric analysis separate from incident summaries.'],
        'tools' => ['trace_reader', 'metric_aggregator', 'incident_parser'],
        'prompt_notes' => 'Observability prompts should optimize for anomaly surfacing and low-noise summaries.',
      ],
    ];
  }

  private function customFlows(): array {
    $flows = $this->configFactory->get(self::CONFIG_NAME)->get('flows');
    $flows = is_array($flows) ? $flows : [];

    return array_map(function (array $flow): array {
      return [
        'id' => (string) ($flow['id'] ?? ''),
        'label' => (string) ($flow['label'] ?? ''),
        'description' => (string) ($flow['description'] ?? ''),
        'owner' => (string) ($flow['owner'] ?? 'drupal_langgraph'),
        'status' => (string) ($flow['status'] ?? 'draft'),
        'graph_type' => (string) ($flow['graph_type'] ?? 'state_graph'),
        'primary_section' => (string) ($flow['primary_section'] ?? 'build'),
        'default_entrypoint' => (string) ($flow['default_entrypoint'] ?? ''),
        'version' => (string) ($flow['version'] ?? 'draft'),
        'source' => (string) ($flow['source'] ?? 'custom'),
        'state_schema_summary' => (string) ($flow['state_schema_summary'] ?? ''),
        'nodes' => array_values(array_filter(array_map('strval', (array) ($flow['nodes'] ?? [])), static fn(string $value): bool => $value !== '')),
        'routing_rules' => array_values(array_filter(array_map('strval', (array) ($flow['routing_rules'] ?? [])), static fn(string $value): bool => $value !== '')),
        'tools' => array_values(array_filter(array_map('strval', (array) ($flow['tools'] ?? [])), static fn(string $value): bool => $value !== '')),
        'prompt_notes' => (string) ($flow['prompt_notes'] ?? ''),
      ];
    }, $flows);
  }

}
