<?php

namespace Drupal\drupal_langgraph\Service;

use Drupal\Core\Config\ConfigFactoryInterface;

final class ProcessFlowRegistryService {

  private const CONFIG_NAME = 'drupal_langgraph.process_flows';

  public function __construct(
    private readonly ConfigFactoryInterface $configFactory,
    private readonly HqPathManager $paths,
  ) {}

  public function allFlows(): array {
    $flows = [];
    $base_flows = array_merge($this->builtInFlows(), $this->runtimeDerivedFlows());
    foreach (array_merge($base_flows, $this->customFlows($base_flows)) as $flow) {
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

  public function isCustomFlow(array $flow): bool {
    return in_array((string) ($flow['source'] ?? ''), ['custom', 'custom_override'], TRUE);
  }

  public function canArchiveFlow(array $flow): bool {
    return $this->isCustomFlow($flow) && (string) ($flow['status'] ?? '') !== 'archived';
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

  public function archiveFlow(string $flow_id): ?array {
    $flow = $this->getFlow($flow_id);
    if ($flow === NULL || !$this->canArchiveFlow($flow)) {
      return NULL;
    }

    $flow['status'] = 'archived';
    $this->saveFlow($flow);

    return $flow;
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

  public function toolOptions(): array {
    return [
      'drush' => 'Drush',
      'shell' => 'Shell / CLI',
      'hq_artifacts' => 'HQ artifacts',
      'runtime_ticks' => 'Runtime tick artifacts',
      'agent_selection' => 'Agent selection state',
      'publish_contract' => 'Publish contract',
      'feature_progress_markdown' => 'Feature progress artifacts',
      'release_artifacts' => 'Release artifacts',
      'signoff_discovery' => 'Signoff discovery',
      'flow_registry' => 'Flow registry',
      'ownership_map' => 'Ownership map',
      'trace_reader' => 'Trace reader',
      'metric_aggregator' => 'Metric aggregator',
      'incident_parser' => 'Incident parser',
    ];
  }

  public function parseLineList(string $value): array {
    $lines = preg_split('/\R/', $value) ?: [];
    $lines = array_map(static fn(string $line): string => trim($line), $lines);
    return array_values(array_filter($lines, static fn(string $line): bool => $line !== ''));
  }

  public function duplicateValues(array $values): array {
    $counts = array_count_values($values);
    return array_values(array_map('strval', array_keys(array_filter($counts, static fn(int $count): bool => $count > 1))));
  }

  public function entrypointMatchesNodes(string $entrypoint, array $nodes): bool {
    $entrypoint = trim($entrypoint);
    $nodes = array_values(array_filter(array_map('strval', $nodes), static fn(string $value): bool => $value !== ''));
    return $entrypoint === '' || $nodes === [] || in_array($entrypoint, $nodes, TRUE);
  }

  public function unknownTools(array $tools): array {
    $tools = array_values(array_filter(array_map('strval', $tools), static fn(string $value): bool => $value !== ''));
    return array_values(array_diff($tools, array_keys($this->toolOptions())));
  }

  private function builtInFlows(): array {
    return [
      [
        'id' => 'hq_orchestrator_tick',
        'label' => 'HQ Orchestrator Tick',
        'description' => 'Primary LangGraph control-plane flow that coordinates the HQ tick pipeline and worker selection.',
        'owner' => 'ceo-copilot-2',
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
        'id' => 'agentic_sdlc',
        'label' => 'Agentic SDLC',
        'description' => 'Reference SDLC graph imported from the external LangGraph example, with design approval fanning out into parallel implementation and test-case authoring, an explicit readiness merge before QA, and review or QA failures routing directly back to the originating authoring step.',
        'owner' => 'architect-copilot',
        'status' => 'active',
        'graph_type' => 'state_graph',
        'primary_section' => 'build',
        'default_entrypoint' => 'User Requirements',
        'version' => 'reference-import',
        'source' => 'built-in',
        'state_schema_summary' => 'State carries requirements, user stories, design, code, reviews, security findings, test cases, QA readiness, QA results, and revision feedback through gated loops, including parallel code-and-test authoring after design approval, an explicit merge before QA, and direct rejection loops back to the authoring nodes.',
        'nodes' => [
          'User Requirements',
          'Auto-generate User Stories',
          'Product Owner Review',
          'Create Design Document',
          'Revise User Stories',
          'Revise Design Document',
          'Design Review',
          'Generate Code',
          'Write Test Cases',
          'Code Review',
          'Security Review',
          'Test Cases Review',
          'Ready for QA',
          'QA Testing',
        ],
        'routing_rules' => [
          'Product owner, design, code, security, and test-review stages branch on Approved versus Changes requested.',
          'Design approval starts code generation and test-case writing in parallel.',
          'QA begins only after both the security-approved code branch and the approved test-case branch meet at Ready for QA.',
          'Code review and security review reject directly back to Generate Code instead of introducing separate remediation nodes.',
          'Test case review rejects directly back to Write Test Cases instead of introducing a separate remediation node.',
          'QA failures route directly back to Generate Code, Write Test Cases, or both depending on what changed.',
          'Each feedback branch loops back into the appropriate revise-or-author stage before re-entering the main line.',
          'QA pass exits the graph at END.',
        ],
        'tools' => ['flow_registry'],
        'prompt_notes' => 'Render this flow with explicit conditional transitions so approval gates, the post-design parallel implementation/test branch, the Ready for QA merge, and the direct rejection loops back into the originating authoring steps stay visible.',
        'transitions' => [
          ['from_node' => 'User Requirements', 'to_node' => 'Auto-generate User Stories', 'kind' => 'direct', 'condition' => ''],
          ['from_node' => 'Auto-generate User Stories', 'to_node' => 'Product Owner Review', 'kind' => 'direct', 'condition' => ''],
          ['from_node' => 'Product Owner Review', 'to_node' => 'Create Design Document', 'kind' => 'conditional', 'condition' => 'Approved'],
          ['from_node' => 'Product Owner Review', 'to_node' => 'Revise User Stories', 'kind' => 'conditional', 'condition' => 'Changes requested'],
          ['from_node' => 'Revise User Stories', 'to_node' => 'Auto-generate User Stories', 'kind' => 'direct', 'condition' => ''],
          ['from_node' => 'Create Design Document', 'to_node' => 'Design Review', 'kind' => 'direct', 'condition' => ''],
          ['from_node' => 'Design Review', 'to_node' => 'Generate Code', 'kind' => 'conditional', 'condition' => 'Approved'],
          ['from_node' => 'Design Review', 'to_node' => 'Write Test Cases', 'kind' => 'conditional', 'condition' => 'Approved'],
          ['from_node' => 'Design Review', 'to_node' => 'Revise Design Document', 'kind' => 'conditional', 'condition' => 'Changes requested'],
          ['from_node' => 'Revise Design Document', 'to_node' => 'Design Review', 'kind' => 'direct', 'condition' => ''],
          ['from_node' => 'Generate Code', 'to_node' => 'Code Review', 'kind' => 'direct', 'condition' => ''],
          ['from_node' => 'Code Review', 'to_node' => 'Security Review', 'kind' => 'conditional', 'condition' => 'Approved'],
          ['from_node' => 'Code Review', 'to_node' => 'Generate Code', 'kind' => 'conditional', 'condition' => 'Changes requested'],
          ['from_node' => 'Security Review', 'to_node' => 'Ready for QA', 'kind' => 'conditional', 'condition' => 'Approved'],
          ['from_node' => 'Security Review', 'to_node' => 'Generate Code', 'kind' => 'conditional', 'condition' => 'Changes requested'],
          ['from_node' => 'Write Test Cases', 'to_node' => 'Test Cases Review', 'kind' => 'direct', 'condition' => ''],
          ['from_node' => 'Test Cases Review', 'to_node' => 'Ready for QA', 'kind' => 'conditional', 'condition' => 'Approved'],
          ['from_node' => 'Test Cases Review', 'to_node' => 'Write Test Cases', 'kind' => 'conditional', 'condition' => 'Changes requested'],
          ['from_node' => 'Ready for QA', 'to_node' => 'QA Testing', 'kind' => 'direct', 'condition' => ''],
          ['from_node' => 'QA Testing', 'to_node' => 'END', 'kind' => 'conditional', 'condition' => 'Passed'],
          ['from_node' => 'QA Testing', 'to_node' => 'Generate Code', 'kind' => 'conditional', 'condition' => 'Failed - code changes required'],
          ['from_node' => 'QA Testing', 'to_node' => 'Write Test Cases', 'kind' => 'conditional', 'condition' => 'Failed - test changes required'],
        ],
        'node_breakdown' => $this->agenticSdlcNodeBreakdown(),
      ],
      [
        'id' => 'release_cycle_automation',
        'label' => 'Release Cycle Automation',
        'description' => 'Release orchestration flow that tracks current and next release markers, evidence, and signoff readiness.',
        'owner' => 'ceo-copilot-2',
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
        'owner' => 'ceo-copilot-2',
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
        'owner' => 'ceo-copilot-2',
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

  private function customFlows(array $base_flows = []): array {
    $flows = $this->configFactory->get(self::CONFIG_NAME)->get('flows');
    $flows = is_array($flows) ? $flows : [];
    $defaults_by_id = [];
    foreach ($base_flows as $flow) {
      if (is_array($flow) && isset($flow['id'])) {
        $defaults_by_id[(string) $flow['id']] = $flow;
      }
    }

    return array_map(function (array $flow) use ($defaults_by_id): array {
      $defaults = $defaults_by_id[(string) ($flow['id'] ?? '')] ?? [];
      return $this->normalizeFlow($flow, $defaults, 'custom');
    }, $flows);
  }

  private function runtimeDerivedFlows(): array {
    $script = $this->paths->artifactPaths()['graph_catalog_export'] ?? '';
    if ($script === '' || !is_readable($script)) {
      return [];
    }

    $command = 'python3 ' . escapeshellarg($script) . ' 2>/dev/null';
    $output = shell_exec($command);
    if (!is_string($output) || trim($output) === '') {
      return [];
    }

    $data = json_decode($output, TRUE);
    if (!is_array($data)) {
      return [];
    }

    $flows = $data['flows'] ?? [];
    if (!is_array($flows)) {
      return [];
    }

    return array_map(
      fn(array $flow): array => $this->normalizeFlow($flow, [], 'runtime_graph'),
      array_values(array_filter($flows, static fn(mixed $flow): bool => is_array($flow)))
    );
  }

  private function agenticSdlcNodeBreakdown(): array {
    return [
      [
        'parent_node' => 'User Requirements',
        'internal_step' => 'Capture the user problem',
        'purpose' => 'Define the request, constraints, and acceptance target that the SDLC run must satisfy.',
        'state_effect' => 'Seeds the flow state with the initial requirements and problem framing.',
      ],
      [
        'parent_node' => 'Auto-generate User Stories',
        'internal_step' => 'Translate requirements into stories',
        'purpose' => 'Convert the raw request into implementable user stories and candidate acceptance slices.',
        'state_effect' => 'Adds structured user stories and draft delivery scope to the working state.',
      ],
      [
        'parent_node' => 'Product Owner Review',
        'internal_step' => 'Approve or request story revisions',
        'purpose' => 'Validate that the generated stories match the intended product outcome before design begins.',
        'state_effect' => 'Branches the state toward design on approval or back to story revision when changes are requested.',
      ],
      [
        'parent_node' => 'Create Design Document',
        'internal_step' => 'Draft the technical design',
        'purpose' => 'Shape the architecture, interfaces, and implementation approach for the approved stories.',
        'state_effect' => 'Adds a design artifact that downstream review and implementation nodes can evaluate.',
      ],
      [
        'parent_node' => 'Revise User Stories',
        'internal_step' => 'Incorporate product feedback',
        'purpose' => 'Refine the generated stories when product review finds mismatches or missing scope.',
        'state_effect' => 'Updates the story set, then loops back into story generation and review.',
      ],
      [
        'parent_node' => 'Revise Design Document',
        'internal_step' => 'Refine the proposed design',
        'purpose' => 'Address design review feedback before implementation work is allowed to start.',
        'state_effect' => 'Mutates the design artifact and sends the flow back through design review.',
      ],
      [
        'parent_node' => 'Design Review',
        'internal_step' => 'Gate design approval',
        'purpose' => 'Confirm the design is implementation-ready and determine whether work can branch into build and test authoring.',
        'state_effect' => 'Either loops back for design changes or fans out into parallel code and test-case tracks.',
      ],
      [
        'parent_node' => 'Generate Code',
        'internal_step' => 'Implement the design',
        'purpose' => 'Produce the initial code change set for the approved design branch.',
        'state_effect' => 'Adds implementation output that moves into code review.',
      ],
      [
        'parent_node' => 'Write Test Cases',
        'internal_step' => 'Author verification coverage',
        'purpose' => 'Create test cases in parallel with coding so QA has a concrete validation plan before merge readiness.',
        'state_effect' => 'Adds test definitions that move into test-case review.',
      ],
      [
        'parent_node' => 'Code Review',
        'internal_step' => 'Review implementation quality',
        'purpose' => 'Check the generated code for correctness and design alignment before security review.',
        'state_effect' => 'Either advances the code branch to security review or rejects it back to Generate Code for revision by the originator.',
      ],
      [
        'parent_node' => 'Security Review',
        'internal_step' => 'Evaluate security posture',
        'purpose' => 'Inspect the implementation for abuse paths, unsafe behavior, and unmet security expectations.',
        'state_effect' => 'Either approves the code branch into the QA readiness merge or rejects it back to Generate Code for revision by the originator.',
      ],
      [
        'parent_node' => 'Test Cases Review',
        'internal_step' => 'Review test design',
        'purpose' => 'Check that authored tests cover the intended behavior and edge cases before QA relies on them.',
        'state_effect' => 'Either approves the test branch into the QA readiness merge or rejects it back to Write Test Cases for revision by the originator.',
      ],
      [
        'parent_node' => 'Ready for QA',
        'internal_step' => 'Merge approved delivery branches',
        'purpose' => 'Represent the explicit readiness gate where both the approved code branch and the approved test branch are required before QA begins.',
        'state_effect' => 'Waits for both approved branches, then hands the combined delivery package into QA Testing.',
      ],
      [
        'parent_node' => 'QA Testing',
        'internal_step' => 'Run integrated validation',
        'purpose' => 'Validate the approved code and approved test artifacts together as the final pre-release gate.',
        'state_effect' => 'Either exits the graph on pass or routes failures directly back to Generate Code, Write Test Cases, or both depending on what QA found.',
      ],
    ];
  }

  private function normalizeFlow(array $flow, array $defaults = [], string $default_source = 'built-in'): array {
    $list = static fn(array $source, string $key, array $fallback = []): array => array_values(array_filter(array_map('strval', (array) ($source[$key] ?? $fallback)), static fn(string $value): bool => $value !== ''));
    $records = static fn(array $source, string $key, array $fallback = []): array => array_values(array_filter((array) ($source[$key] ?? $fallback), static fn(mixed $item): bool => is_array($item)));

    return [
      'id' => (string) ($flow['id'] ?? $defaults['id'] ?? ''),
      'label' => (string) ($flow['label'] ?? $defaults['label'] ?? ''),
      'description' => (string) ($flow['description'] ?? $defaults['description'] ?? ''),
      'owner' => (string) ($flow['owner'] ?? $defaults['owner'] ?? 'ceo-copilot-2'),
      'status' => (string) ($flow['status'] ?? $defaults['status'] ?? 'draft'),
      'graph_type' => (string) ($flow['graph_type'] ?? $defaults['graph_type'] ?? 'state_graph'),
      'primary_section' => (string) ($flow['primary_section'] ?? $defaults['primary_section'] ?? 'build'),
      'default_entrypoint' => (string) ($flow['default_entrypoint'] ?? $defaults['default_entrypoint'] ?? ''),
      'version' => (string) ($flow['version'] ?? $defaults['version'] ?? 'draft'),
      'source' => (string) ($flow['source'] ?? $defaults['source'] ?? $default_source),
      'state_schema_summary' => (string) ($flow['state_schema_summary'] ?? $defaults['state_schema_summary'] ?? ''),
      'nodes' => $list($flow, 'nodes', (array) ($defaults['nodes'] ?? [])),
      'routing_rules' => $list($flow, 'routing_rules', (array) ($defaults['routing_rules'] ?? [])),
      'tools' => $list($flow, 'tools', (array) ($defaults['tools'] ?? [])),
      'prompt_notes' => (string) ($flow['prompt_notes'] ?? $defaults['prompt_notes'] ?? ''),
      'node_breakdown' => $records($flow, 'node_breakdown', (array) ($defaults['node_breakdown'] ?? [])),
      'transitions' => $records($flow, 'transitions', (array) ($defaults['transitions'] ?? [])),
    ];
  }

}
