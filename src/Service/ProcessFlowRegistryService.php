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
      'product_team_registry' => 'Product team registry',
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
        'nodes' => ['consume_replies', 'dispatch_commands', 'pick_agents', 'exec_agents', 'health_check', 'kpi_monitor', 'publish'],
        'routing_rules' => ['Always execute tick nodes in pipeline order.', 'Keep the top-level orchestrator graph focused on the live control-plane steps.'],
        'tools' => ['hq_artifacts', 'runtime_ticks', 'agent_selection', 'publish_contract'],
        'prompt_notes' => 'Primary orchestration prompt must preserve deterministic control-plane ordering and auditable step results.',
      ],
      [
        'id' => 'agentic_sdlc',
        'label' => 'Agentic SDLC',
        'description' => 'Reference SDLC graph imported from the external LangGraph example, with design approval fanning out into parallel implementation and test-case authoring, an explicit readiness merge before QA, review or QA failures routing directly back to the originating authoring step, and product-team-owned BA/PM/Dev/QA stages resolved dynamically from the selected product team. When delivery is release-scoped, this flow is the delivery subprocess inside release_shipping_flow.',
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
          'PM Scope Rebaseline',
          'Write Test Cases',
          'Code Review',
          'Security Review',
          'Test Cases Review',
          'Ready for QA',
          'QA Testing',
        ],
        'routing_rules' => [
          'Product owner, design, code, security, and test-review stages branch on Approved versus Changes requested.',
          'BA, PM, Dev, and QA stages bind dynamically to the selected product team while shared architecture, code review, and security seats remain fixed.',
          'Design approval starts code generation and test-case writing in parallel.',
          'If delivery discovers a scope/ownership ambiguity, the originating Dev or QA stage routes to PM Scope Rebaseline instead of stalling in ad hoc escalation.',
          'QA begins only after both the security-approved code branch and the approved test-case branch meet at Ready for QA.',
          'Code review and security review reject directly back to Generate Code instead of introducing separate remediation nodes.',
          'Test case review rejects directly back to Write Test Cases instead of introducing a separate remediation node.',
          'PM Scope Rebaseline can resume implementation, send the work back to requirements, or end delivery when PM decides to hold, defer, or consolidate the feature.',
          'QA failures route directly back to Generate Code, Write Test Cases, or both depending on what changed.',
          'When the work is part of an active release, this flow handles the remediation and PM rebaseline loops while release_shipping_flow remains focused on release-only validation and push readiness.',
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
          ['from_node' => 'Generate Code', 'to_node' => 'PM Scope Rebaseline', 'kind' => 'conditional', 'condition' => 'Scope decision required'],
          ['from_node' => 'Write Test Cases', 'to_node' => 'PM Scope Rebaseline', 'kind' => 'conditional', 'condition' => 'Scope decision required'],
          ['from_node' => 'Code Review', 'to_node' => 'Security Review', 'kind' => 'conditional', 'condition' => 'Approved'],
          ['from_node' => 'Code Review', 'to_node' => 'Generate Code', 'kind' => 'conditional', 'condition' => 'Changes requested'],
          ['from_node' => 'Security Review', 'to_node' => 'Ready for QA', 'kind' => 'conditional', 'condition' => 'Approved'],
          ['from_node' => 'Security Review', 'to_node' => 'Generate Code', 'kind' => 'conditional', 'condition' => 'Changes requested'],
          ['from_node' => 'Write Test Cases', 'to_node' => 'Test Cases Review', 'kind' => 'direct', 'condition' => ''],
          ['from_node' => 'Test Cases Review', 'to_node' => 'Ready for QA', 'kind' => 'conditional', 'condition' => 'Approved'],
          ['from_node' => 'Test Cases Review', 'to_node' => 'Write Test Cases', 'kind' => 'conditional', 'condition' => 'Changes requested'],
          ['from_node' => 'PM Scope Rebaseline', 'to_node' => 'Generate Code', 'kind' => 'conditional', 'condition' => 'Resume implementation'],
          ['from_node' => 'PM Scope Rebaseline', 'to_node' => 'Write Test Cases', 'kind' => 'conditional', 'condition' => 'Resume test design'],
          ['from_node' => 'PM Scope Rebaseline', 'to_node' => 'Revise User Stories', 'kind' => 'conditional', 'condition' => 'Re-scope requirements'],
          ['from_node' => 'PM Scope Rebaseline', 'to_node' => 'END', 'kind' => 'conditional', 'condition' => 'Hold / defer / consolidate'],
          ['from_node' => 'Ready for QA', 'to_node' => 'QA Testing', 'kind' => 'direct', 'condition' => ''],
          ['from_node' => 'QA Testing', 'to_node' => 'END', 'kind' => 'conditional', 'condition' => 'Passed'],
          ['from_node' => 'QA Testing', 'to_node' => 'Generate Code', 'kind' => 'conditional', 'condition' => 'Failed - code changes required'],
          ['from_node' => 'QA Testing', 'to_node' => 'Write Test Cases', 'kind' => 'conditional', 'condition' => 'Failed - test changes required'],
          ['from_node' => 'QA Testing', 'to_node' => 'PM Scope Rebaseline', 'kind' => 'conditional', 'condition' => 'Failed - scope decision required'],
        ],
        'node_breakdown' => $this->agenticSdlcNodeBreakdown(),
      ],
      [
        'id' => 'feature_request_intake',
        'label' => 'Feature Request Intake',
        'description' => 'Front-door intake flow for reviewing incoming feature requests, clarifying incomplete requirements, matching the request to the correct product team, and routing approved work into the selected team\'s BA and PM ownership lane before delivery starts.',
        'owner' => 'ceo-copilot-2',
        'status' => 'active',
        'graph_type' => 'state_graph',
        'primary_section' => 'build',
        'default_entrypoint' => 'Receive Feature Request',
        'version' => 'org-intake-v1',
        'source' => 'built-in',
        'state_schema_summary' => 'State tracks the incoming request, clarification feedback, candidate and selected product teams, structured requirements, PM disposition, and the final delivery handoff package.',
        'nodes' => [
          'Receive Feature Request',
          'Intake Review',
          'Clarify Request',
          'Match Product Team',
          'BA Requirements Review',
          'Refine Requirements',
          'PM Scope Decision',
          'Prepare Delivery Handoff',
        ],
        'routing_rules' => [
          'Intake review decides whether the request is valid, needs clarification, or should be rejected before product-team work begins.',
          'Product-team matching must emit a Product team id so downstream dynamic seat bindings resolve to the correct BA and PM seats.',
          'BA and PM intake nodes resolve dynamically from the selected product team instead of hardcoding a single product lane.',
          'BA clarification loops return through Refine Requirements until the request is structured enough for PM scope review.',
          'PM can approve for delivery, request requirement changes, or park the request in backlog without starting delivery.',
          'Preparing the delivery handoff auto-launches the downstream agentic_sdlc flow with the selected product-team metadata.',
        ],
        'tools' => ['flow_registry', 'product_team_registry'],
        'prompt_notes' => 'Render dynamic ownership clearly so the diagram shows which nodes are fixed CEO-owned intake steps and which nodes bind to the selected product team\'s BA or PM seats.',
        'transitions' => [
          ['from_node' => 'Receive Feature Request', 'to_node' => 'Intake Review', 'kind' => 'direct', 'condition' => ''],
          ['from_node' => 'Intake Review', 'to_node' => 'Match Product Team', 'kind' => 'conditional', 'condition' => 'Valid request'],
          ['from_node' => 'Intake Review', 'to_node' => 'Clarify Request', 'kind' => 'conditional', 'condition' => 'Needs clarification'],
          ['from_node' => 'Intake Review', 'to_node' => 'END', 'kind' => 'conditional', 'condition' => 'Rejected / duplicate / not a feature'],
          ['from_node' => 'Clarify Request', 'to_node' => 'Intake Review', 'kind' => 'direct', 'condition' => ''],
          ['from_node' => 'Match Product Team', 'to_node' => 'BA Requirements Review', 'kind' => 'conditional', 'condition' => 'Team selected'],
          ['from_node' => 'Match Product Team', 'to_node' => 'Clarify Request', 'kind' => 'conditional', 'condition' => 'No confident team match'],
          ['from_node' => 'BA Requirements Review', 'to_node' => 'PM Scope Decision', 'kind' => 'conditional', 'condition' => 'Requirements ready'],
          ['from_node' => 'BA Requirements Review', 'to_node' => 'Refine Requirements', 'kind' => 'conditional', 'condition' => 'Needs clarification'],
          ['from_node' => 'BA Requirements Review', 'to_node' => 'END', 'kind' => 'conditional', 'condition' => 'Rejected as non-actionable'],
          ['from_node' => 'Refine Requirements', 'to_node' => 'BA Requirements Review', 'kind' => 'direct', 'condition' => ''],
          ['from_node' => 'PM Scope Decision', 'to_node' => 'Prepare Delivery Handoff', 'kind' => 'conditional', 'condition' => 'Approved for delivery'],
          ['from_node' => 'PM Scope Decision', 'to_node' => 'Refine Requirements', 'kind' => 'conditional', 'condition' => 'Changes requested'],
          ['from_node' => 'PM Scope Decision', 'to_node' => 'END', 'kind' => 'conditional', 'condition' => 'Parked in backlog'],
          ['from_node' => 'Prepare Delivery Handoff', 'to_node' => 'END', 'kind' => 'direct', 'condition' => ''],
        ],
        'node_breakdown' => $this->featureRequestIntakeNodeBreakdown(),
      ],
      [
        'id' => 'release_shipping_flow',
        'label' => 'Release Shipping Flow',
        'description' => 'First-class release signoff flow for visualizing and formalizing the release-only validation path: Gate 1b, Gate 2, PM signoff readiness, and coordinated push. Delivery and remediation stay inside agentic_sdlc.',
        'owner' => 'ceo-copilot-2',
        'status' => 'draft',
        'graph_type' => 'state_graph',
        'primary_section' => 'release',
        'default_entrypoint' => 'Seed Release Cycle',
        'version' => 'target-state-v1',
        'source' => 'built-in',
        'state_schema_summary' => 'State tracks the active release id, release-review findings, PM gate decisions, whether scoped SDLC work has returned cleanly for release validation, QA verification evidence, PM signoff readiness, and the coordinated push handoff needed to advance the active release boundary.',
        'nodes' => [
          'Seed Release Cycle',
          'Release Code Review',
          'PM Code Review Triage',
          'SDLC Delivery',
          'Release QA Verification',
          'PM Signoff Readiness Check',
          'Coordinated Push',
          'Advance Release Boundary',
        ],
        'routing_rules' => [
          'Previous state: release Gate 1b code review was queued by release-cycle scripts as a legacy inbox item with no Flow id / Flow node metadata, so LangGraph could not advance PM or Dev follow-up automatically.',
          'Current guarded state: signoff automation blocks on unresolved MEDIUM+ release-review findings and queues code-review-followup instead of awaiting-signoff when Gate 1b is still open.',
          'Target state: release review, PM gate decisions, release-only QA verification, PM signoff readiness, and coordinated push are represented as first-class graph nodes instead of artifact-only side effects.',
          'All feature delivery, fix-forward work, and QA remediation belong to agentic_sdlc; release_shipping_flow only hands work back to the SDLC lane when release validation discovers unresolved delivery issues.',
          'MEDIUM+ findings from release review must either hand work back into SDLC Delivery or terminate the release-review branch with explicit PM risk acceptance before signoff readiness may pass.',
          'Release QA verification may begin only when release review is clean or every MEDIUM+ finding has been routed or accepted.',
          'PM signoff readiness must branch back to Gate 1b or release QA verification when either gate is incomplete; it may only advance to coordinated push when both gates are satisfied in repo state.',
          'Only the coordinated push / release advance branch may move the active release boundary to the next release.',
        ],
        'tools' => ['release_artifacts', 'signoff_discovery', 'flow_registry', 'product_team_registry'],
        'prompt_notes' => 'Render this flow as the thin release-validation wrapper around agentic_sdlc: release owns Gate 1b, Gate 2, signoff readiness, and coordinated push, while any delivery or remediation loop returns to SDLC instead of living in the release lane.',
        'transitions' => [
          ['from_node' => 'Seed Release Cycle', 'to_node' => 'Release Code Review', 'kind' => 'direct', 'condition' => ''],
          ['from_node' => 'Release Code Review', 'to_node' => 'PM Code Review Triage', 'kind' => 'conditional', 'condition' => 'MEDIUM+ findings present'],
          ['from_node' => 'Release Code Review', 'to_node' => 'Release QA Verification', 'kind' => 'conditional', 'condition' => 'No MEDIUM+ findings'],
          ['from_node' => 'PM Code Review Triage', 'to_node' => 'SDLC Delivery', 'kind' => 'conditional', 'condition' => 'Route fixes to Dev'],
          ['from_node' => 'PM Code Review Triage', 'to_node' => 'Release QA Verification', 'kind' => 'conditional', 'condition' => 'Risk accepted / all findings resolved'],
          ['from_node' => 'SDLC Delivery', 'to_node' => 'Release QA Verification', 'kind' => 'direct', 'condition' => ''],
          ['from_node' => 'SDLC Delivery', 'to_node' => 'PM Code Review Triage', 'kind' => 'conditional', 'condition' => 'Scope decision required'],
          ['from_node' => 'Release QA Verification', 'to_node' => 'PM Signoff Readiness Check', 'kind' => 'conditional', 'condition' => 'APPROVE'],
          ['from_node' => 'Release QA Verification', 'to_node' => 'SDLC Delivery', 'kind' => 'conditional', 'condition' => 'BLOCK - code changes required'],
          ['from_node' => 'Release QA Verification', 'to_node' => 'PM Code Review Triage', 'kind' => 'conditional', 'condition' => 'BLOCK - scope or risk decision required'],
          ['from_node' => 'PM Signoff Readiness Check', 'to_node' => 'PM Code Review Triage', 'kind' => 'conditional', 'condition' => 'Gate 1b incomplete'],
          ['from_node' => 'PM Signoff Readiness Check', 'to_node' => 'Release QA Verification', 'kind' => 'conditional', 'condition' => 'Gate 2 incomplete'],
          ['from_node' => 'PM Signoff Readiness Check', 'to_node' => 'Coordinated Push', 'kind' => 'conditional', 'condition' => 'Ready for signoff and push'],
          ['from_node' => 'Coordinated Push', 'to_node' => 'Advance Release Boundary', 'kind' => 'direct', 'condition' => ''],
          ['from_node' => 'Advance Release Boundary', 'to_node' => 'END', 'kind' => 'direct', 'condition' => ''],
        ],
        'node_breakdown' => $this->releaseShippingFlowNodeBreakdown(),
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
        'owner_binding' => 'product_team.ba_agent',
        'purpose' => 'Define the request, constraints, and acceptance target that the SDLC run must satisfy.',
        'state_effect' => 'Seeds the flow state with the initial requirements and problem framing.',
      ],
      [
        'parent_node' => 'Auto-generate User Stories',
        'internal_step' => 'Translate requirements into stories',
        'owner_binding' => 'product_team.ba_agent',
        'purpose' => 'Convert the raw request into implementable user stories and candidate acceptance slices.',
        'state_effect' => 'Adds structured user stories and draft delivery scope to the working state.',
      ],
      [
        'parent_node' => 'Product Owner Review',
        'internal_step' => 'Approve or request story revisions',
        'owner_binding' => 'product_team.pm_agent',
        'purpose' => 'Validate that the generated stories match the intended product outcome before design begins.',
        'state_effect' => 'Branches the state toward design on approval or back to story revision when changes are requested.',
      ],
      [
        'parent_node' => 'Create Design Document',
        'internal_step' => 'Draft the technical design',
        'owner_seat' => 'architect-copilot',
        'purpose' => 'Shape the architecture, interfaces, and implementation approach for the approved stories.',
        'state_effect' => 'Adds a design artifact that downstream review and implementation nodes can evaluate.',
      ],
      [
        'parent_node' => 'Revise User Stories',
        'internal_step' => 'Incorporate product feedback',
        'owner_binding' => 'product_team.ba_agent',
        'purpose' => 'Refine the generated stories when product review finds mismatches or missing scope.',
        'state_effect' => 'Updates the story set, then loops back into story generation and review.',
      ],
      [
        'parent_node' => 'Revise Design Document',
        'internal_step' => 'Refine the proposed design',
        'owner_seat' => 'architect-copilot',
        'purpose' => 'Address design review feedback before implementation work is allowed to start.',
        'state_effect' => 'Mutates the design artifact and sends the flow back through design review.',
      ],
      [
        'parent_node' => 'Design Review',
        'internal_step' => 'Gate design approval',
        'owner_seat' => 'architect-copilot',
        'purpose' => 'Confirm the design is implementation-ready and determine whether work can branch into build and test authoring.',
        'state_effect' => 'Either loops back for design changes or fans out into parallel code and test-case tracks.',
      ],
      [
        'parent_node' => 'Generate Code',
        'internal_step' => 'Implement the design',
        'owner_binding' => 'product_team.dev_agent',
        'purpose' => 'Produce the initial code change set for the approved design branch.',
        'state_effect' => 'Adds implementation output that moves into code review.',
      ],
      [
        'parent_node' => 'PM Scope Rebaseline',
        'internal_step' => 'Rebaseline delivery scope',
        'owner_binding' => 'product_team.pm_agent',
        'purpose' => 'Resolve delivery-time scope ambiguity such as hold/defer decisions, consolidation into a parent feature, or requirements that must be split before implementation continues.',
        'state_effect' => 'Either resumes the active delivery lane, routes the work back to requirements for a tighter slice, or ends the current delivery run when PM decides to hold, defer, or consolidate the feature.',
      ],
      [
        'parent_node' => 'Write Test Cases',
        'internal_step' => 'Author verification coverage',
        'owner_binding' => 'product_team.qa_agent',
        'purpose' => 'Create test cases in parallel with coding so QA has a concrete validation plan before merge readiness.',
        'state_effect' => 'Adds test definitions that move into test-case review.',
      ],
      [
        'parent_node' => 'Code Review',
        'internal_step' => 'Review implementation quality',
        'owner_seat' => 'agent-code-review',
        'purpose' => 'Check the generated code for correctness and design alignment before security review.',
        'state_effect' => 'Either advances the code branch to security review or rejects it back to Generate Code for revision by the originator.',
      ],
      [
        'parent_node' => 'Security Review',
        'internal_step' => 'Evaluate security posture',
        'owner_seat' => 'sec-analyst-forseti',
        'purpose' => 'Inspect the implementation for abuse paths, unsafe behavior, and unmet security expectations.',
        'state_effect' => 'Either approves the code branch into the QA readiness merge or rejects it back to Generate Code for revision by the originator.',
      ],
      [
        'parent_node' => 'Test Cases Review',
        'internal_step' => 'Review test design',
        'owner_binding' => 'product_team.qa_agent',
        'purpose' => 'Check that authored tests cover the intended behavior and edge cases before QA relies on them.',
        'state_effect' => 'Either approves the test branch into the QA readiness merge or rejects it back to Write Test Cases for revision by the originator.',
      ],
      [
        'parent_node' => 'Ready for QA',
        'internal_step' => 'Merge approved delivery branches',
        'owner_binding' => 'product_team.qa_agent',
        'purpose' => 'Represent the explicit readiness gate where both the approved code branch and the approved test branch are required before QA begins.',
        'state_effect' => 'Waits for both approved branches, then hands the combined delivery package into QA Testing.',
      ],
      [
        'parent_node' => 'QA Testing',
        'internal_step' => 'Run integrated validation',
        'owner_binding' => 'product_team.qa_agent',
        'purpose' => 'Validate the approved code and approved test artifacts together as the final pre-release gate.',
        'state_effect' => 'Either exits the graph on pass or routes failures directly back to Generate Code, Write Test Cases, or both depending on what QA found.',
      ],
    ];
  }

  private function featureRequestIntakeNodeBreakdown(): array {
    return [
      [
        'parent_node' => 'Receive Feature Request',
        'internal_step' => 'Capture the incoming request',
        'owner_seat' => 'ceo-copilot-2',
        'purpose' => 'Create the initial intake record from a feature request, requirement note, or escalation before any product lane is selected.',
        'state_effect' => 'Seeds the flow with the raw request text, origin context, and any initial urgency or mission notes.',
      ],
      [
        'parent_node' => 'Intake Review',
        'internal_step' => 'Screen for validity and readiness',
        'owner_seat' => 'ceo-copilot-2',
        'purpose' => 'Decide whether the request is actionable, needs clarification, or should be rejected before product-team work begins.',
        'state_effect' => 'Branches the request toward clarification, team matching, or terminal rejection.',
      ],
      [
        'parent_node' => 'Clarify Request',
        'internal_step' => 'Request missing detail',
        'owner_seat' => 'ceo-copilot-2',
        'purpose' => 'Capture follow-up questions and missing constraints when the intake is too ambiguous to route confidently.',
        'state_effect' => 'Adds clarification feedback, then loops the request back into intake review.',
      ],
      [
        'parent_node' => 'Match Product Team',
        'internal_step' => 'Select the owning product team',
        'owner_seat' => 'ceo-copilot-2',
        'purpose' => 'Choose the best-fit product team for the request and emit the Product team id that downstream dynamic ownership will use.',
        'state_effect' => 'Stores the selected product team for later BA/PM seat resolution or loops back for clarification if no confident match exists.',
      ],
      [
        'parent_node' => 'BA Requirements Review',
        'internal_step' => 'Structure the request into usable requirements',
        'owner_binding' => 'product_team.ba_agent',
        'purpose' => 'Hand the selected request to the target team\'s BA seat so the request becomes a structured, delivery-ready requirement package.',
        'state_effect' => 'Produces structured requirements for PM review or sends the request back for clarification when more detail is needed.',
      ],
      [
        'parent_node' => 'Refine Requirements',
        'internal_step' => 'Incorporate BA or PM feedback',
        'owner_binding' => 'product_team.ba_agent',
        'purpose' => 'Revise the structured requirements after BA or PM review requests changes.',
        'state_effect' => 'Updates the requirement package and loops it back into BA review.',
      ],
      [
        'parent_node' => 'PM Scope Decision',
        'internal_step' => 'Decide delivery versus backlog',
        'owner_binding' => 'product_team.pm_agent',
        'purpose' => 'Let the selected team\'s PM decide whether the request is approved for delivery, needs requirement changes, or should be parked in backlog.',
        'state_effect' => 'Routes approved work into the handoff step, loops back for requirement changes, or ends the intake flow in backlog.',
      ],
      [
        'parent_node' => 'Prepare Delivery Handoff',
        'internal_step' => 'Package approved intake for delivery',
        'owner_binding' => 'product_team.ba_agent',
        'handoff_flow_id' => 'agentic_sdlc',
        'purpose' => 'Create the product-team-ready handoff package that can seed the downstream delivery flow.',
        'state_effect' => 'Finalizes the approved requirements, then launches the downstream agentic_sdlc flow with the same selected product team.',
      ],
    ];
  }

  private function releaseShippingFlowNodeBreakdown(): array {
    return [
      [
        'parent_node' => 'Seed Release Cycle',
        'internal_step' => 'Seed current and next release runtime state',
        'owner_seat' => 'ceo-copilot-2',
        'purpose' => 'Represent the release startup step that creates the active release boundary and queues the initial release work.',
        'state_effect' => 'Creates the active release id, successor release id, and startup handoff context used by downstream release-gate steps.',
      ],
      [
        'parent_node' => 'Release Code Review',
        'internal_step' => 'Run Gate 1b pre-ship review',
        'owner_seat' => 'agent-code-review',
        'purpose' => 'Evaluate the release-scoped code changes and emit any MEDIUM+ findings that must be routed before signoff.',
        'state_effect' => 'Adds release-review findings that either branch to PM triage or let the release proceed directly to QA verification when no MEDIUM+ issues remain.',
      ],
      [
        'parent_node' => 'PM Code Review Triage',
        'internal_step' => 'Route findings or record risk acceptance',
        'owner_binding' => 'product_team.pm_agent',
        'purpose' => 'Turn release-review findings into explicit dev work or PM risk-acceptance artifacts instead of leaving them stranded in an outbox.',
        'state_effect' => 'Creates routed remediation work, records accepted risk, and closes the Gate 1b routing gap before QA or signoff can proceed.',
      ],
      [
        'parent_node' => 'SDLC Delivery',
        'internal_step' => 'Return unresolved delivery work to SDLC',
        'owner_binding' => 'product_team.dev_agent',
        'purpose' => 'Represent the handback from release validation into the active delivery lane so Dev and QA remediation stay inside agentic_sdlc instead of becoming a separate release-owned workflow.',
        'state_effect' => 'Routes unresolved delivery issues back through SDLC work and returns to release QA verification only after delivery reports clean completion.',
      ],
      [
        'parent_node' => 'Release QA Verification',
        'internal_step' => 'Run release-level Gate 2 verification',
        'owner_binding' => 'product_team.qa_agent',
        'purpose' => 'Validate the assembled release candidate, publish APPROVE or BLOCK evidence, and confirm that any SDLC remediation actually resolved the release issues.',
        'state_effect' => 'Publishes Gate 2 evidence that either advances the release toward signoff readiness or hands the work back into SDLC / PM gate decisions.',
      ],
      [
        'parent_node' => 'PM Signoff Readiness Check',
        'internal_step' => 'Enforce Gate 1b plus Gate 2 before signoff',
        'owner_binding' => 'product_team.pm_agent',
        'purpose' => 'Represent the guarded signoff step that verifies code-review routing and QA APPROVE before PM signoff and push readiness are allowed.',
        'state_effect' => 'Branches back to Gate 1b or Gate 2 when release evidence is incomplete, or advances to coordinated push when the release is truly ready.',
      ],
      [
        'parent_node' => 'Coordinated Push',
        'internal_step' => 'Execute the official push',
        'owner_seat' => 'ceo-copilot-2',
        'purpose' => 'Perform the coordinated ship step after the PM signoff gates are satisfied.',
        'state_effect' => 'Turns a signed-off release into a pushed release without changing the active release pointer prematurely.',
      ],
      [
        'parent_node' => 'Advance Release Boundary',
        'internal_step' => 'Move current to next and reseed',
        'owner_seat' => 'ceo-copilot-2',
        'purpose' => 'Advance the runtime release boundary only after the coordinated push succeeds and reseed the next cycle.',
        'state_effect' => 'Closes the current release and creates the new current/next release pair for the next cycle.',
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
