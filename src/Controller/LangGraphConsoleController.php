<?php

namespace Drupal\drupal_langgraph\Controller;

use Drupal\Component\Utility\Html;
use Drupal\Core\Access\AccessResult;
use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\DependencyInjection\ContainerInjectionInterface;
use Drupal\Core\Link;
use Drupal\Core\Render\Markup;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\Url;
use Drupal\drupal_langgraph\Form\ProcessFlowCheckpointReplayForm;
use Drupal\drupal_langgraph\Form\ProcessFlowReleaseActionForm;
use Drupal\drupal_langgraph\Form\ProcessFlowRuntimeRequestForm;
use Drupal\drupal_langgraph\Form\ProcessFlowWorkspaceEditorForm;
use Drupal\drupal_langgraph\Service\ControlPlaneArtifactService;
use Drupal\drupal_langgraph\Service\ProcessFlowContextService;
use Drupal\drupal_langgraph\Service\HqPathManager;
use Drupal\drupal_langgraph\Service\LangGraphObserveService;
use Drupal\drupal_langgraph\Service\ProcessFlowRegistryService;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

final class LangGraphConsoleController extends ControllerBase implements ContainerInjectionInterface {

  public function __construct(
    private readonly HqPathManager $paths,
    private readonly LangGraphObserveService $observe,
    private readonly ProcessFlowRegistryService $flows,
    private readonly ProcessFlowContextService $flowContext,
    private readonly ControlPlaneArtifactService $artifacts,
  ) {}

  public static function create(ContainerInterface $container): self {
    return new self(
      $container->get('drupal_langgraph.path_manager'),
      $container->get('drupal_langgraph.observe_data'),
      $container->get('drupal_langgraph.process_flow_registry'),
      $container->get('drupal_langgraph.process_flow_context'),
      $container->get('drupal_langgraph.control_plane_artifacts'),
    );
  }

  public function adminAccess(AccountInterface $account): AccessResult {
    return AccessResult::allowedIfHasPermission($account, 'administer drupal langgraph')
      ->orIf(AccessResult::allowedIfHasPermission($account, 'administer copilot agent tracker'));
  }

  public function legacyHome(): RedirectResponse {
    return $this->redirectToRoute('drupal_langgraph.langgraph_console_home');
  }

  public function legacySession(): RedirectResponse {
    return $this->redirectToRoute('drupal_langgraph.langgraph_console_run');
  }

  public function legacyParity(): RedirectResponse {
    return $this->redirectToRoute('drupal_langgraph.langgraph_console_test');
  }

  public function legacyFeatureProgress(): RedirectResponse {
    return $this->redirectToRoute('drupal_langgraph.langgraph_console_subsection', [
      'section' => 'observe',
      'subsection' => 'feature-progress',
    ]);
  }

  public function legacyReleaseStatus(): RedirectResponse {
    return $this->redirectToRoute('drupal_langgraph.langgraph_console_release');
  }

  public function home(): array {
    $latest_tick = $this->readLatestTick();
    $parity = $this->readParity();
    $org_control = $this->readOrgControl();
    $release_control = $this->readReleaseControl();
    $tick_ts = (string) ($latest_tick['ts'] ?? '');
    $tick_age = $this->formatAgeFromTimestamp($tick_ts);
    $tick_epoch = $this->timestampToEpoch($tick_ts);
    $tick_age_seconds = ($tick_epoch !== NULL) ? max(0, time() - $tick_epoch) : NULL;
    $incident_rows = $this->observe->incidentRows(5);
    $parity_ok = isset($parity['parity_ok']) ? (bool) $parity['parity_ok'] : NULL;
    $engine_mode = $this->engineMode($latest_tick);
    $runtime_health = $this->overviewRuntimeHealth($tick_age_seconds, $parity_ok, $engine_mode);
    $freshness = $this->overviewFreshness($tick_age_seconds, $tick_age);
    $automation = $this->overviewAutomationState($org_control, $release_control);
    $next_action = $this->overviewNextAction($tick_age_seconds, $org_control, $release_control, $parity_ok, $incident_rows);

    $build = $this->buildPage('Overview', 'Operator dashboard for the LangGraph control plane.', [], FALSE, 'home');
    $build['status'] = $this->tableDetails('Operational Status', ['Signal', 'Current state', 'Recommended action'], $this->overviewStatusRows($runtime_health, $freshness, $automation, count($incident_rows), $parity_ok));
    $build['launchpad'] = $this->tableDetails('Section Launchpad', ['Section', 'Purpose', 'Open', 'Common actions'], $this->overviewLaunchpadRows());
    $build['recent_activity'] = $this->tableDetails('Recent Activity & Next Step', ['Signal', 'Value'], [
      ['Latest tick timestamp', $tick_ts !== '' ? $tick_ts : 'unavailable'],
      ['Latest tick age', $tick_age],
      ['Latest incident', isset($incident_rows[0]) ? (($incident_rows[0]['severity'] ?? 'unknown') . ': ' . ($incident_rows[0]['summary'] ?? '')) : 'No recent incidents'],
      ['Recommended next step', $next_action],
    ]);

    return $build;
  }

  public function flows(): array {
    $build = $this->buildPage('Flows', 'Registry and control panel for all process flows managed by Drupal LangGraph.', $this->buildSectionRows('flows'), TRUE, 'flows');
    $build['actions'] = [
      '#type' => 'container',
      'link' => [
        '#type' => 'link',
        '#title' => $this->t('New process flow'),
        '#url' => Url::fromRoute('drupal_langgraph.langgraph_console_flow_add'),
        '#attributes' => ['class' => ['button', 'button--primary']],
      ],
      'help' => ['#markup' => '<p>' . $this->t('Use the flow registry to select an existing process flow or start a new draft flow definition.') . '</p>'],
    ];
    $build['registry'] = $this->tableDetails('Process Flow Registry', ['Flow', 'Flow ID', 'Status', 'Owner', 'Version', 'Default entrypoint', 'Primary section', 'Source', 'Actions'], $this->buildFlowRegistryRows());
    $build['command_map'] = $this->tableDetails('Command-to-Control Mapping', ['LangGraph command', 'Console control', 'Section'], $this->flows->commandControlMap());

    return $this->withCurrentFlowContext($build);
  }

  public function flowDetail(string $flow_id): array {
    $flow = $this->requireFlow($flow_id);

    return $this->withFlowVisualization([
      '#type' => 'container',
      '#cache' => ['max-age' => 0],
      'title' => ['#markup' => '<h2>' . $this->t('@flow', ['@flow' => $flow['label']]) . '</h2>'],
      'description' => ['#markup' => '<p>' . $this->t('@description', ['@description' => $flow['description']]) . '</p>'],
      'actions' => [
        '#markup' => '<p>' .
          Link::fromTextAndUrl($this->t('Back to Flows'), Url::fromRoute('drupal_langgraph.langgraph_console_flows'))->toString() .
          ' | ' .
          $this->flowActionLinksMarkup($flow, FALSE) .
          ' | ' .
          Link::fromTextAndUrl($this->t('Build'), Url::fromRoute('drupal_langgraph.langgraph_console_flow_build', ['flow_id' => $flow['id']]))->toString() .
          ' | ' .
          Link::fromTextAndUrl($this->t('Test'), Url::fromRoute('drupal_langgraph.langgraph_console_flow_test', ['flow_id' => $flow['id']]))->toString() .
          ' | ' .
          Link::fromTextAndUrl($this->t('Run'), Url::fromRoute('drupal_langgraph.langgraph_console_flow_run', ['flow_id' => $flow['id']]))->toString() .
          ' | ' .
          Link::fromTextAndUrl($this->t('Observe'), Url::fromRoute('drupal_langgraph.langgraph_console_flow_observe', ['flow_id' => $flow['id']]))->toString() .
          ' | ' .
          Link::fromTextAndUrl($this->t('Release'), Url::fromRoute('drupal_langgraph.langgraph_console_flow_release', ['flow_id' => $flow['id']]))->toString() .
          ' | ' .
          Link::fromTextAndUrl($this->t('Create new process flow'), Url::fromRoute('drupal_langgraph.langgraph_console_flow_add'))->toString() .
          '</p>',
      ],
      'workspace' => $this->tableDetails('Flow Workspace', ['Section', 'Purpose', 'Primary controls'], $this->buildFlowWorkspaceRows($flow)),
      'summary' => $this->tableDetails('Flow Summary', ['Field', 'Value'], [
        ['Flow ID', $flow['id']],
        ['Status', $flow['status']],
        ['Owner', $flow['owner']],
        ['Graph type', $flow['graph_type']],
        ['Primary section', $flow['primary_section']],
        ['Default entrypoint', $flow['default_entrypoint']],
        ['Version', $flow['version']],
        ['Source', $flow['source']],
      ]),
      'structure' => $this->tableDetails('Flow Structure', ['Field', 'Value'], [
        ['State schema', $flow['state_schema_summary'] !== '' ? $flow['state_schema_summary'] : '-'],
        ['Nodes', isset($flow['nodes']) && $flow['nodes'] !== [] ? implode(', ', $flow['nodes']) : '-'],
        ['Routing rules', isset($flow['routing_rules']) && $flow['routing_rules'] !== [] ? implode(' | ', $flow['routing_rules']) : '-'],
        ['Tools', isset($flow['tools']) && $flow['tools'] !== [] ? implode(', ', $flow['tools']) : '-'],
        ['Prompt notes', $flow['prompt_notes'] !== '' ? $flow['prompt_notes'] : '-'],
      ]),
      'how_to_use' => $this->tableDetails('How to Use This Workspace', ['Topic', 'Guidance'], $this->flowWorkspaceOverviewGuidanceRows()),
      'version_state' => $this->tableDetails('Version & Promotion State', ['Field', 'Value'], $this->promotionStateRows($flow)),
      'version_history' => $this->tableDetails('Version Snapshots', $this->versionSnapshotHeaders(), $this->flowVersionRows($flow['id'])),
      'command_map' => $this->tableDetails('Mapped Console Controls', ['LangGraph command', 'Console control', 'Section'], $this->flows->commandControlMap()),
    ], $flow, 'Flow overview');
  }

  public function flowBuild(string $flow_id): array {
    return $this->flowWorkspaceSectionPage($this->requireFlow($flow_id), 'build');
  }

  public function flowTest(string $flow_id): array {
    return $this->flowWorkspaceSectionPage($this->requireFlow($flow_id), 'test');
  }

  public function flowRun(string $flow_id): array {
    return $this->flowWorkspaceSectionPage($this->requireFlow($flow_id), 'run');
  }

  public function flowObserve(string $flow_id): array {
    return $this->flowWorkspaceSectionPage($this->requireFlow($flow_id), 'observe');
  }

  public function flowRelease(string $flow_id): array {
    return $this->flowWorkspaceSectionPage($this->requireFlow($flow_id), 'release');
  }

  public function flowWorkspaceSubsection(string $flow_id, string $section, string $subsection): array {
    return $this->flowWorkspaceSubsectionPage($this->requireFlow($flow_id), $section, $subsection);
  }

  public function build(): array|RedirectResponse {
    return $this->globalFlowSectionLanding('build');
  }

  public function test(): array|RedirectResponse {
    return $this->globalFlowSectionLanding('test');
  }

  public function run(): array|RedirectResponse {
    return $this->globalFlowSectionLanding('run');
  }

  public function observe(): array|RedirectResponse {
    return $this->globalFlowSectionLanding('observe');
  }

  public function release(): array|RedirectResponse {
    return $this->globalFlowSectionLanding('release');
  }

  public function legacyBuildData(): array {
    $latest_tick = $this->readLatestTick();
    $step_results = is_array($latest_tick['step_results'] ?? NULL) ? $latest_tick['step_results'] : [];
    $nodes = array_values(array_filter(array_keys($step_results), static fn($key) => $key !== 'summarize_tick'));
    sort($nodes);
    $rows = $nodes ? array_map(fn(string $node): array => [$node, 'Observed in latest tick'], $nodes) : [['(none)', 'No step_results found in latest tick artifact.']];

    $build = $this->buildPage('Build', 'Design-time view over graph topology and structure evidence.', $this->buildSectionRows('build'), TRUE, 'build');
    $build['graph_shape'] = $this->tableDetails('Observed Graph Shape', ['Node', 'Observation'], $rows, $this->toRelativePath($this->paths->artifactPaths()['ticks']));
    return $build;
  }

  public function legacyTestData(): array {
    $parity = $this->readParity();
    $errors = is_array($parity['errors'] ?? NULL) ? $parity['errors'] : [];
    $rows = [
      ['parity_ok', isset($parity['parity_ok']) ? ((bool) $parity['parity_ok'] ? 'PASS' : 'FAIL') : 'unknown'],
      ['selected_agents.match', isset($parity['selected_agents']['match']) ? ((bool) $parity['selected_agents']['match'] ? 'yes' : 'no') : 'unknown'],
      ['steps.match', isset($parity['steps']['match']) ? ((bool) $parity['steps']['match'] ? 'yes' : 'no') : 'unknown'],
      ['generated_at', (string) ($parity['generated_at'] ?? 'unknown')],
      ['errors', $errors ? implode('; ', array_map('strval', $errors)) : '(none)'],
    ];

    $build = $this->buildPage('Test', 'Validation view over parity and correctness evidence.', $this->buildSectionRows('test'), TRUE, 'test');
    $build['parity_evidence'] = $this->tableDetails('Current Validation Evidence', ['Field', 'Value'], $rows, $this->toRelativePath($this->paths->artifactPaths()['parity']));
    return $build;
  }

  public function legacyRunData(): array {
    $rows = [];
    $ticks = array_slice($this->readTicks(), -25);
    foreach (array_reverse($ticks) as $tick) {
      $rows[] = [
        (string) ($tick['ts'] ?? ''),
        $this->formatAgeFromTimestamp((string) ($tick['ts'] ?? '')),
        $this->engineMode($tick),
        (string) ($tick['provider'] ?? ''),
        (string) ($tick['agent_cap'] ?? ''),
        (string) $this->countTickErrors($tick),
      ];
    }

    $build = $this->buildPage('Run', 'Execution-plane timeline for recent LangGraph activity.', $this->buildSectionRows('run'), TRUE, 'run');
    $build['run_timeline'] = $this->tableDetails('Recent Runs Timeline', ['Timestamp', 'Age', 'Engine mode', 'Provider', 'Agent cap', 'Error count'], $rows, $this->toRelativePath($this->paths->artifactPaths()['ticks']));
    return $build;
  }

  public function legacyObserveData(): array {
    $build = $this->buildPage('Observe', 'Observability view over node diagnostics and runtime behavior.', $this->buildSectionRows('observe'), TRUE, 'observe');
    $build['overview'] = $this->tableDetails('Observe Overview', ['Signal', 'Current Value'], $this->observe->overviewSummary());
    $build['metrics'] = $this->tableDetails('Latest Runtime Metrics', ['Metric', 'Value'], $this->buildObserveMetricRows($this->observe->metricSummary()), $this->toRelativePath($this->paths->artifactPaths()['ticks']));
    return $build;
  }

  public function observeTraces(): array {
    $rows = $this->observe->nodeTraceRows();
    $build = [
      '#type' => 'container',
      '#cache' => ['max-age' => 0],
      'title' => ['#markup' => '<h2>' . $this->t('Observe: Node Traces') . '</h2>'],
      'description' => ['#markup' => '<p>' . $this->t('Latest step-level trace evidence from the LangGraph tick stream.') . '</p>'],
      'guidance' => $this->tableDetails('How to Use This Page', ['Topic', 'Guidance'], $this->observationalPageGuidanceRows('observe-traces')),
      'table' => $this->tableDetails('Latest Tick Traces', ['Step', 'Tick timestamp', 'Status', 'Summary'], array_map(
        static fn(array $row): array => [$row['step'], $row['timestamp'], $row['status'], $row['summary']],
        $rows
      ), $this->toRelativePath($this->paths->artifactPaths()['ticks'])),
      'back' => ['#markup' => '<p>' . Link::fromTextAndUrl($this->t('Back to Observe'), Url::fromRoute('drupal_langgraph.langgraph_console_observe'))->toString() . '</p>'],
    ];

    foreach (array_slice($rows, 0, 12) as $index => $row) {
      $build['detail_' . $index] = $this->textDetails('Trace Detail: ' . $row['step'], $row['details'], $this->toRelativePath($this->paths->artifactPaths()['ticks']));
    }

    return $this->withCurrentFlowContext($build);
  }

  public function observeMetrics(): array {
    $summary = $this->observe->metricSummary();
    $trend = $this->observe->metricTrendRows();
    $anomalies = $this->observe->metricAnomalies();

    $build = [
      '#type' => 'container',
      '#cache' => ['max-age' => 0],
      'title' => ['#markup' => '<h2>' . $this->t('Observe: Runtime Metrics') . '</h2>'],
      'description' => ['#markup' => '<p>' . $this->t('Cadence, queue depth, worker counts, and error volume derived from recent ticks.') . '</p>'],
      'guidance' => $this->tableDetails('How to Use This Page', ['Topic', 'Guidance'], $this->observationalPageGuidanceRows('observe-metrics')),
      'summary' => $this->tableDetails('Current Metrics', ['Metric', 'Value'], $this->buildObserveMetricRows($summary), $this->toRelativePath($this->paths->artifactPaths()['ticks'])),
      'trend' => $this->tableDetails('Recent Tick Trend', ['Timestamp', 'Gap (s)', 'Selected agents', 'Queued agents', 'Workers', 'Error count'], array_map(
        static fn(array $row): array => [
          $row['timestamp'],
          isset($row['gap_seconds']) ? (string) $row['gap_seconds'] : '-',
          (string) $row['selected_agents'],
          (string) $row['queued_agents'],
          (string) $row['workers'],
          (string) $row['error_count'],
        ],
        $trend
      ), $this->toRelativePath($this->paths->artifactPaths()['ticks'])),
      'back' => ['#markup' => '<p>' . Link::fromTextAndUrl($this->t('Back to Observe'), Url::fromRoute('drupal_langgraph.langgraph_console_observe'))->toString() . '</p>'],
    ];

    if ($anomalies !== []) {
      $build['anomalies'] = [
        '#theme' => 'item_list',
        '#title' => $this->t('Detected anomalies'),
        '#items' => $anomalies,
      ];
    }

    return $this->withCurrentFlowContext($build);
  }

  public function observeDrift(): array {
    return $this->withCurrentFlowContext([
      '#type' => 'container',
      '#cache' => ['max-age' => 0],
      'title' => ['#markup' => '<h2>' . $this->t('Observe: Drift') . '</h2>'],
      'description' => ['#markup' => '<p>' . $this->t('Recent node behavior drift versus the historical tick baseline. Current artifacts do not expose per-step duration, so this view tracks presence and error-rate drift.') . '</p>'],
      'guidance' => $this->tableDetails('How to Use This Page', ['Topic', 'Guidance'], $this->observationalPageGuidanceRows('observe-drift')),
      'table' => $this->tableDetails(
        'Node Behavior Drift',
        ['Step', 'Baseline presence %', 'Baseline error %', 'Recent error %', 'Delta %', 'Latest status'],
        array_map(
          static fn(array $row): array => [
            $row['step'],
            (string) $row['baseline_presence_pct'],
            (string) $row['baseline_error_pct'],
            (string) $row['recent_error_pct'],
            (string) $row['delta_pct'],
            $row['latest_status'],
          ],
          $this->observe->driftRows()
        ),
        $this->toRelativePath($this->paths->artifactPaths()['ticks'])
      ),
      'back' => ['#markup' => '<p>' . Link::fromTextAndUrl($this->t('Back to Observe'), Url::fromRoute('drupal_langgraph.langgraph_console_observe'))->toString() . '</p>'],
    ]);
  }

  public function observeAlerts(): array {
    return $this->withCurrentFlowContext([
      '#type' => 'container',
      '#cache' => ['max-age' => 0],
      'title' => ['#markup' => '<h2>' . $this->t('Observe: Alerts & Incidents') . '</h2>'],
      'description' => ['#markup' => '<p>' . $this->t('Executor failures, blocked items, and timeout-like log signals surfaced from HQ runtime artifacts.') . '</p>'],
      'guidance' => $this->tableDetails('How to Use This Page', ['Topic', 'Guidance'], $this->observationalPageGuidanceRows('observe-alerts')),
      'table' => $this->tableDetails(
        'Recent Incidents',
        ['Timestamp', 'Severity', 'Category', 'Seat', 'Summary', 'Path'],
        $this->buildObserveIncidentRows($this->observe->incidentRows(), TRUE)
      ),
      'back' => ['#markup' => '<p>' . Link::fromTextAndUrl($this->t('Back to Observe'), Url::fromRoute('drupal_langgraph.langgraph_console_observe'))->toString() . '</p>'],
    ]);
  }

  public function observeFeatureProgress(): array {
    $feature_progress = $this->observe->langGraphFeatureProgress();
    return $this->withCurrentFlowContext([
      '#type' => 'container',
      '#cache' => ['max-age' => 0],
      'title' => ['#markup' => '<h2>' . $this->t('Observe: Feature Progress') . '</h2>'],
      'description' => ['#markup' => '<p>' . $this->t('Flow-scoped LangGraph feature progress view, anchored to the currently selected process flow.') . '</p>'],
      'guidance' => $this->tableDetails('How to Use This Page', ['Topic', 'Guidance'], $this->observationalPageGuidanceRows('observe-feature-progress')),
      'generated' => [
        '#markup' => '<p><strong>' . $this->t('Generated') . ':</strong> ' . $this->t('@generated', [
          '@generated' => $feature_progress['generated_at'] !== '' ? $feature_progress['generated_at'] : 'unknown',
        ]) . '</p>',
      ],
      'summary' => $this->tableDetails('LangGraph Status Summary', ['Status', 'Count'], $this->observe->featureProgressSummaryRows(), $this->toRelativePath($this->paths->artifactPaths()['feature_progress'])),
      'table' => $this->tableDetails('LangGraph Feature Rows', ['Work item', 'Module', 'Status', 'Priority'], $this->observe->featureProgressRows(), $this->toRelativePath($this->paths->artifactPaths()['feature_progress'])),
      'back' => ['#markup' => '<p>' . Link::fromTextAndUrl($this->t('Back to Observe'), Url::fromRoute('drupal_langgraph.langgraph_console_observe'))->toString() . '</p>'],
    ]);
  }

  public function observeControlRequests(): array {
    return $this->withCurrentFlowContext([
      '#type' => 'container',
      '#cache' => ['max-age' => 0],
      'title' => ['#markup' => '<h2>' . $this->t('Observe: Control Requests') . '</h2>'],
      'description' => ['#markup' => '<p>' . $this->t('Runtime, checkpoint, and release request artifacts currently visible to Drupal LangGraph.') . '</p>'],
      'guidance' => $this->tableDetails('How to Use This Page', ['Topic', 'Guidance'], $this->observationalPageGuidanceRows('observe-control-requests')),
      'runtime_requests' => $this->tableDetails('Runtime Requests', $this->runtimeRequestHeaders(), $this->runtimeControlRequestRows()),
      'replay_requests' => $this->tableDetails('Replay Requests', $this->replayRequestHeaders(), $this->replayRequestRows()),
      'promotion_requests' => $this->tableDetails('Promotion Requests', $this->promotionRequestHeaders(), $this->promotionRequestRows()),
      'version_snapshots' => $this->tableDetails('Version Snapshots', $this->versionSnapshotHeaders(), $this->flowVersionRows()),
      'back' => ['#markup' => '<p>' . Link::fromTextAndUrl($this->t('Back to Observe'), Url::fromRoute('drupal_langgraph.langgraph_console_observe'))->toString() . '</p>'],
    ]);
  }

  public function legacyReleaseData(): array {
    $release_control = $this->readReleaseControl();
    $release_rows = $this->readReleaseCycleRows();
    $coverage_rows = $this->buildReleaseCoverageRows($release_rows);

    $build = $this->buildPage('Release', 'Release-cycle and promotion posture for the control plane.', $this->buildSectionRows('release'), TRUE, 'release');
    $build['control'] = $this->tableDetails('Release-cycle Control', ['Field', 'Value'], [
      ['enabled', $this->boolLabel($release_control['enabled'] ?? NULL)],
      ['updated_at', (string) ($release_control['updated_at'] ?? '-')],
      ['updated_by', (string) ($release_control['updated_by'] ?? '-')],
      ['reason', (string) ($release_control['reason'] ?? '-')],
    ], $this->toRelativePath($this->activeControlPath('RELEASE_CYCLE_CONTROL_FILE', 'release_control_default', 'release_control_legacy')));
    $build['release_state'] = $this->tableDetails('Release Cycle State', ['Team', 'Current Release', 'Next Release', 'Source'], $release_rows);
    $build['coverage'] = $this->tableDetails('Active Release Evidence Coverage', ['Release id', 'Release notes', 'PM signoffs'], $coverage_rows);
    return $this->withCurrentFlowContext($build);
  }

  public function releaseEvidence(): array {
    $notes = $this->listReleaseNotes();
    $signoffs = $this->listReleaseSignoffs();

    $note_rows = [];
    foreach (array_slice($notes, 0, 10) as $note) {
      $note_rows[] = [
        $note['release_id'],
        $note['seat'],
        $note['site'],
        $note['state'],
        $note['updated_at'],
        $note['path'],
      ];
    }

    $signoff_rows = [];
    foreach (array_slice($signoffs, 0, 10) as $signoff) {
      $signoff_rows[] = [
        $signoff['release_id'],
        $signoff['site'],
        $signoff['seat'],
        $signoff['signed_off_at'],
        $signoff['path'],
      ];
    }

    $build = [
      '#type' => 'container',
      '#cache' => ['max-age' => 0],
      'title' => ['#markup' => '<h2>' . $this->t('Release: Evidence') . '</h2>'],
      'description' => ['#markup' => '<p>' . $this->t('Latest release-note narratives and PM signoff artifacts sourced from HQ session state.') . '</p>'],
      'guidance' => $this->tableDetails('How to Use This Page', ['Topic', 'Guidance'], $this->observationalPageGuidanceRows('release-evidence')),
      'notes' => $this->tableDetails('Recent Release Notes', ['Release id', 'Seat', 'Site', 'State', 'Updated at', 'Path'], $note_rows),
      'signoffs' => $this->tableDetails('Recent PM Signoffs', ['Release id', 'Site', 'PM seat', 'Signed off at', 'Path'], $signoff_rows),
      'back' => ['#markup' => '<p>' . Link::fromTextAndUrl($this->t('Back to Release'), Url::fromRoute('drupal_langgraph.langgraph_console_release'))->toString() . '</p>'],
    ];

    if ($notes !== []) {
      $build['latest_note'] = $this->textDetails('Latest Release-note Excerpt', $notes[0]['excerpt'], $notes[0]['path']);
    }

    return $this->withCurrentFlowContext($build);
  }

  public function releaseTroubleshooting(): array {
    $items = $this->listActiveInboxItems();
    $rows = [];
    foreach ($items as $item) {
      $rows[] = [
        $item['seat'],
        $item['item_id'],
        $item['triage'],
        $item['status'],
        $item['roi'],
        $item['age'],
        $item['summary'],
        $item['path'],
      ];
    }

    return $this->withCurrentFlowContext([
      '#type' => 'container',
      '#cache' => ['max-age' => 0],
      'title' => ['#markup' => '<h2>' . $this->t('Release: Troubleshooting') . '</h2>'],
      'description' => ['#markup' => '<p>' . $this->t('Seat-level triage of live inbox pressure, blocker-like work, and escalation-oriented items.') . '</p>'],
      'guidance' => $this->tableDetails('How to Use This Page', ['Topic', 'Guidance'], $this->observationalPageGuidanceRows('release-troubleshooting')),
      'active_work' => $this->tableDetails('Active Inbox Items', ['Seat', 'Item', 'Triage', 'Status', 'ROI', 'Age', 'Summary', 'Path'], $rows),
      'back' => ['#markup' => '<p>' . Link::fromTextAndUrl($this->t('Back to Release'), Url::fromRoute('drupal_langgraph.langgraph_console_release'))->toString() . '</p>'],
    ]);
  }

  public function featureProgress(): array {
    $feature_progress = $this->readFeatureProgress();
    $rows = [];
    foreach ($feature_progress['rows'] as $row) {
      $rows[] = [
        $row['Work item'] ?? '',
        $row['Website'] ?? '',
        $row['Module'] ?? '',
        $row['Status'] ?? '',
        $row['Priority'] ?? '',
        $row['PM'] ?? '',
        $row['Dev'] ?? '',
        $row['QA'] ?? '',
      ];
    }

    $summary = [];
    foreach ($feature_progress['rows'] as $row) {
      $status = trim((string) ($row['Status'] ?? 'unknown'));
      $summary[$status] = ($summary[$status] ?? 0) + 1;
    }
    ksort($summary);

    $build = $this->buildPage('Feature Progress', 'Read-only workflow snapshot sourced from the HQ feature progress dashboard.', [], TRUE, 'feature-progress');
    $build['guidance'] = $this->tableDetails('How to Use This Page', ['Topic', 'Guidance'], $this->observationalPageGuidanceRows('feature-progress'));
    $build['summary'] = $this->tableDetails(
      'Status Summary',
      ['Status', 'Count'],
      array_map(
        static fn(string $status, int $count): array => [$status !== '' ? $status : 'unknown', (string) $count],
        array_keys($summary),
        $summary
      ),
      $this->toRelativePath($this->paths->artifactPaths()['feature_progress'])
    );
    $build['generated'] = [
      '#markup' => '<p><strong>' . $this->t('Generated') . ':</strong> ' . $this->t('@generated', [
        '@generated' => $feature_progress['generated_at'] !== '' ? $feature_progress['generated_at'] : 'unknown',
      ]) . '</p>',
    ];
    $build['table'] = $this->tableDetails('Feature Progress', ['Work item', 'Website', 'Module', 'Status', 'Priority', 'PM', 'Dev', 'QA'], $rows, $this->toRelativePath($this->paths->artifactPaths()['feature_progress']));
    return $this->withCurrentFlowContext($build);
  }

  public function admin(): array {
    $paths = $this->paths->artifactPaths();
    $rows = [];
    foreach ($paths as $label => $path) {
      $rows[] = [
        $label,
        $this->toRelativePath($path),
        file_exists($path) ? 'yes' : 'no',
        is_readable($path) ? 'yes' : 'no',
        is_file($path) ? ((string) filesize($path) . ' bytes') : '-',
      ];
    }

    $build = $this->buildPage('Admin', 'Path contracts and artifact health for the consolidated HQ module.', $this->buildSectionRows('admin'), TRUE, 'admin');
    $build['control_artifacts'] = $this->tableDetails('Drupal LangGraph Private Artifact Roots', ['Artifact root', 'Resolved path'], [
      ['Runtime requests', $this->artifacts->runtimeControlRequestBaseDir()],
      ['Checkpoint replay requests', $this->artifacts->checkpointReplayRequestBaseDir()],
      ['Version snapshots', $this->artifacts->flowVersionBaseDir()],
      ['Promotion requests', $this->artifacts->promotionRequestBaseDir()],
      ['Promoted version state', $this->artifacts->promotionStateBaseDir()],
    ]);
    $build['governance'] = $this->tableDetails('Control-Plane Governance Summary', ['Artifact root', 'Total', 'Requested/running', 'Failed', 'Stale', 'Orphaned', 'Latest event'], $this->adminGovernanceRows());
    $build['contracts'] = $this->tableDetails('Writable Control Contracts', ['Artifact root', 'Writer', 'Consumer', 'Retention guidance'], $this->adminControlContractRows());
    $build['retention'] = $this->tableDetails('Retention & Cleanup', ['Artifact root', 'Old completed', 'Old failed/cancelled', 'Recommended action'], $this->adminRetentionRows());
    $build['artifact_health'] = $this->tableDetails('Artifact Health', ['Artifact', 'Path', 'Exists', 'Readable', 'Size'], $rows);
    return $build;
  }

  public function adminRuntimeRoots(): array {
    return $this->withCurrentFlowContext([
      '#type' => 'container',
      '#cache' => ['max-age' => 0],
      'title' => ['#markup' => '<h2>' . $this->t('Admin: Runtime Roots') . '</h2>'],
      'description' => ['#markup' => '<p>' . $this->t('Resolved filesystem roots used by Drupal LangGraph when reading HQ-managed artifacts.') . '</p>'],
      'guidance' => $this->tableDetails('How to Use This Page', ['Topic', 'Guidance'], $this->observationalPageGuidanceRows('admin-runtime-roots')),
      'roots' => $this->tableDetails('Runtime Root Resolution', ['Root', 'Resolved path', 'Used for'], [
        ['FORSETI_ROOT', $this->paths->forsetiRoot(), 'Repository-backed dashboards, features, sessions, and private control artifacts.'],
        ['COPILOT_HQ_ROOT', $this->paths->hqRuntimeRoot(), 'Live tick stream, parity artifacts, and orchestrator response logs.'],
      ]),
      'back' => ['#markup' => '<p>' . Link::fromTextAndUrl($this->t('Back to Admin'), Url::fromRoute('drupal_langgraph.langgraph_console_admin'))->toString() . '</p>'],
    ]);
  }

  public function subsection(string $section, string $subsection): array {
    $map = $this->sectionMap();
    if (!isset($map[$section])) {
      throw new NotFoundHttpException();
    }

    $subsections = $map[$section]['subsections'];
    if (!isset($subsections[$subsection])) {
      throw new NotFoundHttpException();
    }

    $sub_info = $subsections[$subsection];
    $method = $sub_info['method'] ?? NULL;
    if (is_string($method) && method_exists($this, $method)) {
      return $this->{$method}();
    }

    return $this->withCurrentFlowContext([
      '#type' => 'container',
      '#cache' => ['max-age' => 0],
      'title' => ['#markup' => '<h2>' . $this->t('@section: @subsection', ['@section' => $map[$section]['title'], '@subsection' => $sub_info['title']]) . '</h2>'],
      'description' => ['#markup' => '<p>' . $this->t($sub_info['description']) . '</p>'],
      'notice' => ['#markup' => '<div class="messages messages--status"><strong>' . $this->t('Stub Subsection') . ':</strong> ' . $this->t('This subsection frame is ready for future workflow wiring.') . '</div>'],
      'back' => ['#markup' => '<p>' . Link::fromTextAndUrl($this->t('Back to @section', ['@section' => $map[$section]['title']]), Url::fromRoute('drupal_langgraph.langgraph_console_' . $section))->toString() . '</p>'],
    ]);
  }

  private function sectionMap(): array {
    return [
      'home' => [
        'title' => 'Home',
        'subsections' => [
          'runtime-status' => ['title' => 'Runtime Status', 'description' => 'High-level runtime health and control posture.', 'method' => 'home'],
        ],
      ],
      'flows' => [
        'title' => 'Flows',
        'subsections' => [
          'registry' => ['title' => 'Flow Registry', 'description' => 'Control panel for built-in and custom process flows.', 'method' => 'flows'],
          'new-flow' => ['title' => 'New Process Flow', 'description' => 'Create a draft process flow definition for the Drupal LangGraph console.'],
        ],
      ],
      'build' => [
        'title' => 'Build',
        'subsections' => [
          'graph-shape' => ['title' => 'Graph Shape', 'description' => 'Observed node set and graph evidence from runtime artifacts.', 'method' => 'build'],
        ],
      ],
      'test' => [
        'title' => 'Test',
        'subsections' => [
          'parity-evidence' => ['title' => 'Parity Evidence', 'description' => 'Current parity report and validation summary.', 'method' => 'test'],
        ],
      ],
      'run' => [
        'title' => 'Run',
        'subsections' => [
          'recent-runs' => ['title' => 'Recent Runs', 'description' => 'Recent run timeline from the tick stream.', 'method' => 'run'],
        ],
      ],
      'observe' => [
        'title' => 'Observe',
        'subsections' => [
          'traces' => ['title' => 'Node Traces', 'description' => 'Latest step-level trace evidence from the tick stream.', 'method' => 'observeTraces'],
          'metrics' => ['title' => 'Runtime Metrics', 'description' => 'Cadence, queue depth, workers, and anomaly signals.', 'method' => 'observeMetrics'],
          'drift' => ['title' => 'Drift', 'description' => 'Recent node behavior drift versus the historical baseline.', 'method' => 'observeDrift'],
          'alerts' => ['title' => 'Alerts & Incidents', 'description' => 'Executor failures, blocked items, and timeout-like log signals.', 'method' => 'observeAlerts'],
          'control-requests' => ['title' => 'Control Requests', 'description' => 'Runtime and promotion requests currently visible to Drupal LangGraph.', 'method' => 'observeControlRequests'],
          'feature-progress' => ['title' => 'Feature Progress', 'description' => 'LangGraph-only view of the HQ feature progress dashboard.', 'method' => 'observeFeatureProgress'],
        ],
      ],
      'release' => [
        'title' => 'Release',
        'subsections' => [
          'release-cycle' => ['title' => 'Release Cycle', 'description' => 'Current and next release markers by team.', 'method' => 'release'],
          'release-evidence' => ['title' => 'Release Evidence', 'description' => 'Latest release notes and PM signoffs.', 'method' => 'releaseEvidence'],
          'release-troubleshooting' => ['title' => 'Release Troubleshooting', 'description' => 'Live inbox pressure and blocker-oriented work items.', 'method' => 'releaseTroubleshooting'],
        ],
      ],
      'admin' => [
        'title' => 'Admin',
        'subsections' => [
          'runtime-roots' => ['title' => 'Runtime Roots', 'description' => 'Resolved HQ filesystem roots for this environment.', 'method' => 'adminRuntimeRoots'],
          'artifacts' => ['title' => 'Artifacts', 'description' => 'Filesystem health for the module contract.', 'method' => 'admin'],
        ],
      ],
    ];
  }

  private function buildSectionRows(string $section): array {
    $rows = [];
    foreach ($this->sectionMap()[$section]['subsections'] as $slug => $info) {
      $route = 'drupal_langgraph.langgraph_console_subsection';
      $parameters = ['section' => $section, 'subsection' => $slug];
      if ($section === 'flows' && $slug === 'new-flow') {
        $route = 'drupal_langgraph.langgraph_console_flow_add';
        $parameters = [];
      }
      $rows[] = [
        Link::fromTextAndUrl($this->t($info['title']), Url::fromRoute($route, $parameters))->toString(),
        $info['description'],
        $this->t('Ready'),
      ];
    }
    return $rows;
  }

  private function buildPage(string $title, string $description, array $sections, bool $include_flow_context = TRUE, ?string $guidance_key = NULL): array {
    $build = [
      '#type' => 'container',
      '#cache' => ['max-age' => 0],
      'title' => ['#markup' => '<h2>' . $this->t($title) . '</h2>'],
      'description' => ['#markup' => '<p>' . $this->t($description) . '</p>'],
    ];

    if ($guidance_key !== NULL) {
      $build['guidance'] = $this->tableDetails('How to Use This Page', ['Topic', 'Guidance'], $this->pageGuidanceRows($guidance_key));
    }

    if ($include_flow_context && ($current_flow = $this->selectedFlow())) {
      $build['current_flow'] = $this->currentFlowDetailsBuild($current_flow);
    }

    if ($sections !== []) {
      $build['sections'] = [
        '#type' => 'table',
        '#header' => [$this->t('Subsection'), $this->t('Purpose'), $this->t('Status')],
        '#rows' => $sections,
      ];
    }

    return $build;
  }

  private function buildObserveMetricRows(array $metrics): array {
    $rows = [];
    foreach ($metrics as $label => $value) {
      $rows[] = [str_replace('_', ' ', $label), isset($value) ? (string) $value : '-'];
    }
    return $rows;
  }

  private function buildObserveIncidentRows(array $rows, bool $include_path = FALSE): array {
    return array_map(
      function (array $row) use ($include_path): array {
        $base = [
          $row['timestamp'] ?? '',
          $row['severity'] ?? '',
          $row['category'] ?? '',
          $row['seat'] ?? '',
          $row['summary'] ?? '',
        ];
        if ($include_path) {
          $base[] = isset($row['path']) ? $this->toRelativePath((string) $row['path']) : '';
        }
        return $base;
      },
      $rows
    );
  }

  private function buildFlowRegistryRows(): array {
    $rows = [];
    foreach ($this->flows->allFlows() as $flow) {
      $rows[] = [
        ['data' => [
          '#type' => 'link',
          '#title' => $flow['label'],
          '#url' => Url::fromRoute('drupal_langgraph.langgraph_console_flow_detail', ['flow_id' => $flow['id']]),
        ]],
        $flow['id'],
        $flow['status'],
        $flow['owner'],
        $flow['version'],
        $flow['default_entrypoint'],
        $flow['primary_section'],
        ucfirst(str_replace('_', ' ', $flow['source'])),
        ['data' => [
          '#markup' => Markup::create($this->flowActionLinksMarkup($flow)),
        ]],
      ];
    }

    return $rows;
  }

  private function tableDetails(string $title, array $header, array $rows, ?string $source = NULL): array {
    $build = [
      '#type' => 'details',
      '#title' => $this->t($title),
      '#open' => TRUE,
      'table' => [
        '#type' => 'table',
        '#header' => array_map(fn(string $label) => $this->t($label), $header),
        '#rows' => $rows,
        '#empty' => $this->t('No data available.'),
      ],
    ];

    if ($source !== NULL) {
      $build['source'] = ['#markup' => '<p><strong>' . $this->t('Source') . ':</strong> ' . $source . '</p>'];
    }

    return $build;
  }

  private function textDetails(string $title, string $text, ?string $source = NULL): array {
    $build = [
      '#type' => 'details',
      '#title' => $this->t($title),
      '#open' => FALSE,
      'preview' => [
        '#type' => 'html_tag',
        '#tag' => 'pre',
        '#value' => $text !== '' ? $text : $this->t('No text available.'),
      ],
    ];

    if ($source !== NULL) {
      $build['source'] = ['#markup' => '<p><strong>' . $this->t('Source') . ':</strong> ' . $source . '</p>'];
    }

    return $build;
  }

  private function boolLabel(mixed $value): string {
    return isset($value) ? ((bool) $value ? 'yes' : 'no') : 'unknown';
  }

  private function selectedFlow(): ?array {
    $flow_id = $this->flowContext->getCurrentFlowId();
    if ($flow_id === NULL) {
      return NULL;
    }

    return $this->flows->getFlow($flow_id);
  }

  private function withCurrentFlowContext(array $build): array {
    $current_flow = $this->selectedFlow();
    if ($current_flow === NULL || isset($build['current_flow'])) {
      return $build;
    }

    $build['current_flow'] = $this->currentFlowDetailsBuild($current_flow);

    $title = $build['title'] ?? NULL;
    $description = $build['description'] ?? NULL;
    unset($build['title'], $build['description']);

    $prefixed = [];
    if ($title !== NULL) {
      $prefixed['title'] = $title;
    }
    if ($description !== NULL) {
      $prefixed['description'] = $description;
    }
    $prefixed['current_flow'] = $build['current_flow'];
    unset($build['current_flow']);

    return $prefixed + $build;
  }

  private function currentFlowDetailsBuild(array $current_flow): array {
    return [
      '#type' => 'details',
      '#title' => $this->t('Current process flow: @label', ['@label' => $current_flow['label']]),
      '#open' => TRUE,
      'summary' => [
        '#type' => 'table',
        '#header' => [$this->t('Field'), $this->t('Value')],
        '#rows' => [
          [$this->t('Flow ID'), $current_flow['id']],
          [$this->t('Status'), $current_flow['status']],
          [$this->t('Owner'), $current_flow['owner']],
          [$this->t('Graph type'), $current_flow['graph_type']],
          [$this->t('Default entrypoint'), $current_flow['default_entrypoint']],
          [$this->t('Primary section'), $current_flow['primary_section']],
        ],
      ],
      'actions' => [
        '#markup' => '<p>' .
          $this->flowActionLinksMarkup($current_flow) .
          ' | ' .
          Link::fromTextAndUrl($this->t('Flows registry'), Url::fromRoute('drupal_langgraph.langgraph_console_flows'))->toString() .
          '</p>',
      ],
    ];
  }

  private function flowActionLinksMarkup(array $flow, bool $include_open = TRUE): string {
    $links = [];
    if ($include_open) {
      $links[] = Link::fromTextAndUrl($this->t('Open'), Url::fromRoute('drupal_langgraph.langgraph_console_flow_detail', ['flow_id' => $flow['id']]))->toString();
    }

    $links[] = Link::fromTextAndUrl($this->t('Edit metadata'), Url::fromRoute('drupal_langgraph.langgraph_console_flow_workspace_subsection', [
      'flow_id' => $flow['id'],
      'section' => 'build',
      'subsection' => 'metadata',
    ]))->toString();
    $links[] = Link::fromTextAndUrl($this->t('Versions'), Url::fromRoute('drupal_langgraph.langgraph_console_flow_workspace_subsection', [
      'flow_id' => $flow['id'],
      'section' => 'release',
      'subsection' => 'versions',
    ]))->toString();

    if ($this->flows->canArchiveFlow($flow)) {
      $links[] = Link::fromTextAndUrl($this->t('Archive'), Url::fromRoute('drupal_langgraph.langgraph_console_flow_archive', [
        'flow_id' => $flow['id'],
      ]))->toString();
    }

    return implode(' | ', $links);
  }

  private function requireFlow(string $flow_id): array {
    $flow = $this->flows->getFlow($flow_id);
    if ($flow === NULL) {
      throw new NotFoundHttpException();
    }
    $this->flowContext->setCurrentFlowId($flow_id);
    return $flow;
  }

  private function globalFlowSectionLanding(string $section): array|RedirectResponse {
    $current_flow = $this->selectedFlow();
    if ($current_flow !== NULL) {
      return $this->redirectToRoute($this->flowSectionRouteName($section), ['flow_id' => $current_flow['id']]);
    }

    $map = $this->flowWorkspaceMap();
    $section_info = $map[$section];
    return $this->withCurrentFlowContext([
      '#type' => 'container',
      '#cache' => ['max-age' => 0],
      'title' => ['#markup' => '<h2>' . $this->t($section_info['title']) . '</h2>'],
      'description' => ['#markup' => '<p>' . $this->t('This section is flow-scoped in the ideal LangGraph UI. Select a flow to enter its @section workspace.', ['@section' => strtolower($section_info['title'])]) . '</p>'],
      'notice' => ['#markup' => '<div class="messages messages--status"><strong>' . $this->t('Flow selection required') . ':</strong> ' . $this->t('Perfect UI keeps @section under a specific process flow instead of as a global tab.', ['@section' => strtolower($section_info['title'])]) . '</div>'],
      'flows' => $this->tableDetails('Available Flow Workspaces', ['Flow', 'Status', 'Owner', 'Open workspace'], $this->buildFlowSectionLandingRows($section)),
    ]);
  }

  private function flowWorkspaceMap(): array {
    return [
      'build' => [
        'title' => 'Build',
        'description' => 'Design and edit the graph contract for this flow: metadata, state schema, nodes, routing, tools, and prompt policy.',
        'controls' => [
          'metadata' => ['title' => 'Flow Metadata', 'description' => 'Edit label, owner, status, entrypoint, and version.', 'status' => 'Supported'],
          'state-schema' => ['title' => 'State Schema', 'description' => 'Define the carried state, shape, and validation rules.', 'status' => 'Supported'],
          'nodes' => ['title' => 'Nodes', 'description' => 'Add, remove, and edit node definitions.', 'status' => 'Supported'],
          'routing' => ['title' => 'Routing', 'description' => 'Manage edges, conditions, and branching rules.', 'status' => 'Supported'],
          'tools' => ['title' => 'Tools', 'description' => 'Bind tools and runtime resources to this flow.', 'status' => 'Supported'],
          'prompts' => ['title' => 'Prompts & Policy', 'description' => 'Define orchestration prompts, policies, and guardrails.', 'status' => 'Supported'],
        ],
      ],
      'test' => [
        'title' => 'Test',
        'description' => 'Validate that this flow is well-formed, matches runtime expectations, and can be replayed from checkpoints.',
        'controls' => [
          'validate-structure' => ['title' => 'Validate Structure', 'description' => 'Run graph-shape and completeness validation.', 'status' => 'Supported'],
          'parity' => ['title' => 'Parity Evidence', 'description' => 'Compare expected flow behavior to observed runtime behavior.', 'status' => 'Supported'],
          'checkpoints' => ['title' => 'Replay Checkpoints', 'description' => 'Resume or replay a prior checkpoint state.', 'status' => 'Partial'],
        ],
      ],
      'run' => [
        'title' => 'Run',
        'description' => 'Operate this flow directly with manual execution controls and execution history.',
        'controls' => [
          'manual-run' => ['title' => 'Run Now', 'description' => 'Trigger a manual flow execution.', 'status' => 'Partial'],
          'pause-resume' => ['title' => 'Pause / Resume', 'description' => 'Pause or resume the active flow execution.', 'status' => 'Partial'],
          'execution-history' => ['title' => 'Execution History', 'description' => 'Inspect recent executions, providers, and error counts.', 'status' => 'Supported'],
        ],
      ],
      'observe' => [
        'title' => 'Observe',
        'description' => 'Inspect traces, metrics, drift, alerts, and flow-scoped feature progress from one workspace.',
        'controls' => [
          'traces' => ['title' => 'Node Traces', 'description' => 'Inspect step-level traces for this flow.', 'status' => 'Supported'],
          'metrics' => ['title' => 'Runtime Metrics', 'description' => 'Inspect cadence, queue depth, workers, and anomalies.', 'status' => 'Supported'],
          'drift' => ['title' => 'Drift', 'description' => 'Review behavioral drift and error-rate changes.', 'status' => 'Supported'],
          'alerts' => ['title' => 'Alerts & Incidents', 'description' => 'Inspect failures, blockers, and incident summaries.', 'status' => 'Supported'],
          'control-requests' => ['title' => 'Control Requests', 'description' => 'Inspect runtime and promotion requests for this flow.', 'status' => 'Supported'],
          'feature-progress' => ['title' => 'Feature Progress', 'description' => 'Review LangGraph work progress for this flow.', 'status' => 'Supported'],
        ],
      ],
      'release' => [
        'title' => 'Release',
        'description' => 'Manage versioning, promotion, release evidence, and blocker resolution for this flow.',
        'controls' => [
          'versions' => ['title' => 'Versions', 'description' => 'Create and inspect version history for this flow.', 'status' => 'Supported'],
          'promote' => ['title' => 'Promote', 'description' => 'Promote a selected version toward release.', 'status' => 'Partial'],
          'evidence' => ['title' => 'Release Evidence', 'description' => 'Inspect release notes and signoff evidence.', 'status' => 'Supported'],
          'troubleshooting' => ['title' => 'Troubleshooting', 'description' => 'Work through blockers and inbox pressure before release.', 'status' => 'Supported'],
        ],
      ],
    ];
  }

  private function buildFlowWorkspaceRows(array $flow): array {
    $rows = [];
    foreach ($this->flowWorkspaceMap() as $section => $info) {
      $control_titles = array_map(static fn(array $control): string => (string) $control['title'], array_slice($info['controls'], 0, 3));
      $rows[] = [
        $this->linkCell($info['title'], $this->flowSectionRouteName($section), ['flow_id' => $flow['id']]),
        $info['description'],
        implode(', ', $control_titles),
      ];
    }
    return $rows;
  }

  private function buildFlowSectionLandingRows(string $section): array {
    $rows = [];
    foreach ($this->flows->allFlows() as $flow) {
      $rows[] = [
        $this->linkCell($flow['label'], 'drupal_langgraph.langgraph_console_flow_detail', ['flow_id' => $flow['id']]),
        $flow['status'],
        $flow['owner'],
        $this->linkCell('Open ' . $this->flowWorkspaceMap()[$section]['title'], $this->flowSectionRouteName($section), ['flow_id' => $flow['id']]),
      ];
    }
    return $rows;
  }

  private function buildFlowWorkspaceControlRows(array $flow, string $section): array {
    $rows = [];
    foreach ($this->flowWorkspaceMap()[$section]['controls'] as $subsection => $info) {
      $rows[] = [
        $this->linkCell($info['title'], 'drupal_langgraph.langgraph_console_flow_workspace_subsection', [
          'flow_id' => $flow['id'],
          'section' => $section,
          'subsection' => $subsection,
        ]),
        $info['description'],
        $info['status'],
      ];
    }
    return $rows;
  }

  private function flowWorkspaceSectionPage(array $flow, string $section): array {
    if (empty($flow) || !isset($flow['id'], $flow['label'])) {
      \Drupal::logger('drupal_langgraph')->error('flowWorkspaceSectionPage called with invalid flow data');
      throw new \InvalidArgumentException('Flow data is invalid or incomplete');
    }
    
    $section_info = $this->flowWorkspaceMap()[$section];
    $build = [
      '#type' => 'container',
      '#cache' => ['max-age' => 0],
      'title' => ['#markup' => '<h2>' . $this->t('@flow: @section', ['@flow' => $flow['label'], '@section' => $section_info['title']]) . '</h2>'],
      'description' => ['#markup' => '<p>' . $this->t($section_info['description']) . '</p>'],
      'actions' => [
        '#markup' => '<p>' .
          Link::fromTextAndUrl($this->t('Flow overview'), Url::fromRoute('drupal_langgraph.langgraph_console_flow_detail', ['flow_id' => $flow['id']]))->toString() .
          ' | ' .
          Link::fromTextAndUrl($this->t('Back to Flows'), Url::fromRoute('drupal_langgraph.langgraph_console_flows'))->toString() .
          '</p>',
      ],
      'summary' => $this->tableDetails('Workspace Summary', ['Field', 'Value'], [
        ['Flow', $flow['label']],
        ['Flow ID', $flow['id']],
        ['Section', $section_info['title']],
        ['Primary section', $flow['primary_section'] ?? 'N/A'],
      ]),
      'guidance' => $this->tableDetails('How to Use This Section', ['Topic', 'Guidance'], $this->flowWorkspaceSectionGuidanceRows($section)),
      'controls' => $this->tableDetails('Section Controls', ['Control', 'Purpose', 'Status'], $this->buildFlowWorkspaceControlRows($flow, $section)),
    ];

    foreach ($this->flowWorkspaceEvidence($flow, $section) as $key => $component) {
      $build[$key] = $component;
    }

    return $this->withFlowVisualization($build, $flow, $section_info['title']);
  }

  private function pageGuidanceRows(string $page_key): array {
    return match ($page_key) {
      'home' => [
        ['Purpose', 'Use Overview as the global landing page for the console: check health quickly, then jump into the section that matches the task you need to perform.'],
        ['Best next step', 'Use the Section Launchpad below to open Flows, Build, Test, Run, Observe, Release, or Admin directly instead of treating Overview as a flow workspace.'],
      ],
      'flows' => [
        ['Purpose', 'Flows is the registry of process-flow contracts known to Drupal LangGraph, including built-in flows and local overrides.'],
        ['How it connects', 'Open a flow to move through Build, Test, Run, Observe, and Release for that specific workflow.'],
      ],
      'build' => [
        ['Purpose', 'Build is where authors define and inspect the flow contract: metadata, nodes, routing, tools, and prompts.'],
        ['How it connects', 'Use Test after Build to validate structure before requesting execution in Run or promotion in Release.'],
      ],
      'test' => [
        ['Purpose', 'Test verifies that the authored flow contract still lines up with parity evidence, runtime shape, and replay artifacts.'],
        ['How it connects', 'Resolve structural warnings here before submitting runtime or promotion requests elsewhere in the workspace.'],
      ],
      'run' => [
        ['Purpose', 'Run is the operator surface for manual execution, pause or resume requests, and recent execution history.'],
        ['How it connects', 'Use Observe to inspect the consequences of runtime actions and Release to capture or promote the resulting version state.'],
      ],
      'observe' => [
        ['Purpose', 'Observe gathers traces, metrics, drift, incidents, feature progress, and control requests into one troubleshooting surface.'],
        ['First troubleshooting step', 'Start with the subsection that matches the symptom: Alerts for failures, Metrics for freshness or capacity issues, Drift for behavior changes, and Control Requests for stuck operator actions.'],
      ],
      'release' => [
        ['Purpose', 'Release tracks version snapshots, promotion requests, release evidence, and active release-cycle posture.'],
        ['How it connects', 'Build changes define what can be versioned; Release captures and promotes those definitions once they are ready.'],
      ],
      'feature-progress' => [
        ['Purpose', 'Feature Progress is a read-only snapshot of HQ feature work so module and flow owners can see execution posture without shell access.'],
        ['First troubleshooting step', 'Start with the Generated timestamp and status summary; if the snapshot is old or unexpected, move to Observe > Feature Progress and then inspect the source dashboard artifact.'],
      ],
      'admin' => [
        ['Purpose', 'Admin explains the filesystem contract, control-plane artifact roots, governance counts, and retention expectations behind the module.'],
        ['First troubleshooting step', 'Start with Artifact Health and Governance Summary; if paths are missing or requests are stale, open Runtime Roots before investigating downstream data.'],
      ],
      default => [],
    };
  }

  private function observationalPageGuidanceRows(string $page_key): array {
    return match ($page_key) {
      'observe-traces' => [
        ['Purpose', 'Read the most recent per-step traces for the latest tick.'],
        ['First troubleshooting step', 'Start with rows whose Status is error or missing, then open the matching Trace Detail block below for the first failing step.'],
      ],
      'observe-metrics' => [
        ['Purpose', 'Review cadence, queue depth, worker count, and error rate from recent ticks.'],
        ['First troubleshooting step', 'Start with Recent Tick Trend; if Gap (s) or Error count spikes, use Observe Alerts next to identify the failing seat or category.'],
      ],
      'observe-drift' => [
        ['Purpose', 'Compare current node behavior to the recent baseline.'],
        ['First troubleshooting step', 'Start with the largest Delta % rows or any Latest status of error, then inspect Traces for the same step to see the concrete failure.'],
      ],
      'observe-alerts' => [
        ['Purpose', 'Review incident-style signals from runtime artifacts.'],
        ['First troubleshooting step', 'Start with the newest high-severity row, then use Category and Path to decide whether to continue in Metrics, Traces, or Release Troubleshooting.'],
      ],
      'observe-feature-progress' => [
        ['Purpose', 'Inspect the flow-scoped feature-progress snapshot associated with the current flow context.'],
        ['First troubleshooting step', 'Start with the Generated timestamp and the status summary; if the snapshot is stale or incomplete, verify the source dashboard artifact before debugging the flow itself.'],
      ],
      'observe-control-requests' => [
        ['Purpose', 'Read runtime, replay, promotion, and version-control artifacts visible to Drupal.'],
        ['First troubleshooting step', 'Start with requested or failed Runtime Requests, then compare related Replay or Promotion rows to see whether work is stuck at execution, replay, or release time.'],
      ],
      'release-evidence' => [
        ['Purpose', 'Inspect release-note and PM signoff artifacts for the latest releases.'],
        ['First troubleshooting step', 'Start with the most recent release row; if notes or signoffs are missing, move to Release Troubleshooting to find the blocking seat or inbox item.'],
      ],
      'release-troubleshooting' => [
        ['Purpose', 'Triages live release blockers and inbox pressure.'],
        ['First troubleshooting step', 'Start with the highest-ROI open item, then use the Path column to open the owning seat artifact and unblock the release from there.'],
      ],
      'feature-progress' => [
        ['Purpose', 'Read the global feature-progress snapshot sourced from the HQ dashboard.'],
        ['First troubleshooting step', 'Start with the Generated timestamp and Status Summary; if counts look wrong, verify the source dashboard artifact before assuming a workflow problem.'],
      ],
      'admin-runtime-roots' => [
        ['Purpose', 'Confirm the resolved filesystem roots Drupal uses for repository-backed and runtime-backed artifacts.'],
        ['First troubleshooting step', 'Start by confirming both roots point where you expect; if downstream pages are stale or empty, compare these paths to the artifact paths shown in Admin.'],
      ],
      default => [],
    };
  }

  private function flowWorkspaceOverviewGuidanceRows(): array {
    return [
      ['Lifecycle', 'Every flow moves through Build, Test, Run, Observe, and Release. The overview page summarizes the contract before you enter those sections.'],
      ['Source of truth', 'Drupal stores flow-management metadata here, but runtime evidence, control artifacts, and release signals are still read from LangGraph/HQ contracts.'],
    ];
  }

  private function flowWorkspaceSectionGuidanceRows(string $section): array {
    return match ($section) {
      'build' => [
        ['Purpose', 'Edit the flow contract and review the latest structure and version context for this specific flow.'],
        ['Watch for', 'Keep nodes, entrypoints, routing, and tool bindings consistent so Test and Observe can validate them cleanly.'],
      ],
      'test' => [
        ['Purpose', 'Validate authored structure against parity evidence, replay inputs, and control-plane history for this flow.'],
        ['Watch for', 'Warnings here usually mean the Build contract and runtime evidence have drifted apart.'],
      ],
      'run' => [
        ['Purpose', 'Submit runtime requests and inspect the latest runtime state and execution history for this flow.'],
        ['Watch for', 'Prefer dry runs when collecting evidence, and use pause or resume only when intentionally changing automation posture.'],
      ],
      'observe' => [
        ['Purpose', 'Inspect flow-scoped traces, metrics, incidents, drift, and control requests after runtime or release work.'],
        ['Watch for', 'Use this section to connect operator actions with the evidence they produced in runtime artifacts.'],
      ],
      'release' => [
        ['Purpose', 'Capture version snapshots, request promotions, and review release evidence tied to this flow.'],
        ['Watch for', 'Only versions captured here can be promoted, and promotions still honor release-cycle control.'],
      ],
      default => [],
    };
  }

  private function withFlowVisualization(array $build, ?array $flow, string $context_label): array {
    if ($flow === NULL) {
      \Drupal::logger('drupal_langgraph')->warning('withFlowVisualization called with null flow for context: @label', ['@label' => $context_label]);
      return $build;
    }
    
    $ordered = [];
    $inserted = FALSE;
    foreach ($build as $key => $value) {
      $ordered[$key] = $value;
      if ($key === 'description') {
        $ordered['flow_visualization'] = $this->flowVisualizationSection($flow, $context_label);
        $inserted = TRUE;
      }
    }

    if (!$inserted) {
      $ordered['flow_visualization'] = $this->flowVisualizationSection($flow, $context_label);
    }

    $ordered['#attached']['library'] = $ordered['#attached']['library'] ?? [];
    if (!in_array('drupal_langgraph/flow_visualization', $ordered['#attached']['library'], TRUE)) {
      $ordered['#attached']['library'][] = 'drupal_langgraph/flow_visualization';
    }

    return $ordered;
  }

  private function flowVisualizationSection(array $flow, string $context_label): array {
    $nodes = array_values(array_filter(array_map('strval', (array) ($flow['nodes'] ?? [])), static fn(string $value): bool => $value !== ''));
    $tools = array_values(array_filter(array_map('strval', (array) ($flow['tools'] ?? [])), static fn(string $value): bool => $value !== ''));
    $routing_rules = array_values(array_filter(array_map('strval', (array) ($flow['routing_rules'] ?? [])), static fn(string $value): bool => $value !== ''));
    $tool_options = $this->flows->toolOptions();
    $tool_labels = array_map(static fn(string $tool): string => $tool_options[$tool] ?? $tool, $tools);

    return [
      '#type' => 'container',
      '#attributes' => ['class' => ['drupal-langgraph-flow-visualization']],
      'summary' => [
        '#markup' => Markup::create(
          '<div class="drupal-langgraph-flow-chip-grid">' .
          $this->flowVisualizationChip('Context', $context_label) .
          $this->flowVisualizationChip('Graph type', (string) ($flow['graph_type'] ?? '-')) .
          $this->flowVisualizationChip('Entrypoint', (string) ($flow['default_entrypoint'] ?? '-')) .
          $this->flowVisualizationChip('Owning seat', (string) ($flow['owner'] ?? '-')) .
          $this->flowVisualizationChip('Nodes', (string) count($nodes)) .
          $this->flowVisualizationChip('Tools', $tool_labels !== [] ? implode(', ', $tool_labels) : 'None configured') .
          '</div>'
        ),
      ],
      'context' => $this->tableDetails('Execution Context', ['Signal', 'Value'], $this->flowVisualizationContextRows($flow)),
      'related_flows' => ($related_rows = $this->flowRelatedGraphRows($flow)) !== []
        ? $this->tableDetails('Related LangGraph Flows', ['Relationship', 'Flow', 'Entrypoint', 'Visibility'], $related_rows)
        : [],
      'diagram' => [
        '#markup' => Markup::create(
          '<details class="js-form-wrapper form-wrapper claro-details drupal-langgraph-flow-diagram" open>' .
          '<summary role="button" aria-expanded="true" class="claro-details__summary">' . $this->t('Process Flow Diagram') . '</summary>' .
          '<div class="claro-details__wrapper details-wrapper">' .
          '<div class="drupal-langgraph-mermaid" data-flow-id="' . Html::escape((string) ($flow['id'] ?? 'flow')) . '">' .
          Html::escape($this->flowMermaidDefinition($flow)) .
          '</div>' .
          '</div>' .
          '</details>'
        ),
      ],
      'routing' => $routing_rules !== []
        ? [
          '#markup' => '<p class="drupal-langgraph-flow-routing"><strong>' . $this->t('Routing cues') . ':</strong> ' . Html::escape(implode(' | ', $routing_rules)) . '</p>',
        ]
        : [],
    ];
  }

  private function flowVisualizationContextRows(array $flow): array {
    $nodes = array_values(array_filter(array_map('strval', (array) ($flow['nodes'] ?? [])), static fn(string $value): bool => $value !== ''));
    $primary_section = (string) ($flow['primary_section'] ?? '');
    $entrypoint = trim((string) ($flow['default_entrypoint'] ?? ''));
    $rows = [
      ['Modeled executable nodes', $nodes !== [] ? implode(' -> ', $nodes) : 'No nodes configured'],
    ];

    if ($primary_section !== '' && isset($this->flowWorkspaceMap()[$primary_section]['controls'])) {
      $control_titles = array_map(static fn(array $control): string => (string) ($control['title'] ?? ''), $this->flowWorkspaceMap()[$primary_section]['controls']);
      $control_titles = array_values(array_filter($control_titles, static fn(string $value): bool => $value !== ''));
      if ($control_titles !== []) {
        $rows[] = ['Workspace controls', implode(', ', $control_titles)];
      }
    }

    if ($parent = $this->flowParentInvocationSummary($flow, $entrypoint)) {
      $rows[] = ['Parent orchestration', $parent];
    }

    if ($entrypoint !== '') {
      $rows[] = ['Latest runtime evidence', $this->flowEntrypointEvidenceSummary($entrypoint)];
    }

    if (($flow['graph_type'] ?? '') === 'subgraph') {
      $rows[] = ['Interpretation', 'This Mermaid view shows executable graph nodes only. Workspace controls and evidence panels are adjacent operator surfaces, not extra graph nodes.'];
    }

    return $rows;
  }

  private function flowParentInvocationSummary(array $flow, string $entrypoint): string {
    if ($entrypoint === '') {
      return '';
    }

    foreach ($this->flows->allFlows() as $candidate) {
      if (($candidate['id'] ?? '') === ($flow['id'] ?? '')) {
        continue;
      }

      $candidate_nodes = array_values(array_filter(array_map('strval', (array) ($candidate['nodes'] ?? [])), static fn(string $value): bool => $value !== ''));
      if (in_array($entrypoint, $candidate_nodes, TRUE)) {
        return (string) ($candidate['label'] ?? $candidate['id'] ?? 'Another flow') . ' references this flow through the ' . $entrypoint . ' node.';
      }
    }

    return '';
  }

  private function flowEntrypointEvidenceSummary(string $entrypoint): string {
    $latest_tick = $this->readLatestTick();
    $step_results = is_array($latest_tick['step_results'] ?? NULL) ? $latest_tick['step_results'] : [];
    if (!array_key_exists($entrypoint, $step_results)) {
      return 'No top-level runtime evidence for this entrypoint in the latest tick.';
    }

    $entry_result = $step_results[$entrypoint];
    if (is_array($entry_result)) {
      $keys = array_keys($entry_result);
      return 'Observed in the latest tick with fields: ' . implode(', ', array_map('strval', $keys));
    }

    return 'Observed in the latest tick.';
  }

  private function flowRelatedGraphRows(array $flow): array {
    $rows = [];
    $entrypoint = trim((string) ($flow['default_entrypoint'] ?? ''));

    foreach ($this->parentFlowsFor($flow, $entrypoint) as $parent) {
      $rows[] = [
        'Invoked by',
        $this->linkCell((string) ($parent['label'] ?? $parent['id'] ?? 'Parent flow'), 'drupal_langgraph.langgraph_console_flow_detail', ['flow_id' => $parent['id']]),
        $entrypoint !== '' ? $entrypoint : '-',
        'Expanded in the parent flow when the entrypoint is represented as a child subgraph.',
      ];
    }

    foreach ($this->childFlowsFor($flow) as $entrypoint_name => $child) {
      $rows[] = [
        'Contains subgraph',
        $this->linkCell((string) ($child['label'] ?? $child['id'] ?? 'Child flow'), 'drupal_langgraph.langgraph_console_flow_detail', ['flow_id' => $child['id']]),
        $entrypoint_name,
        'Expanded inline in this flow diagram and available as its own flow page.',
      ];
    }

    return $rows;
  }

  private function childFlowsFor(array $flow): array {
    $nodes = array_values(array_filter(array_map('strval', (array) ($flow['nodes'] ?? [])), static fn(string $value): bool => $value !== ''));
    $children = [];

    foreach ($this->flows->allFlows() as $candidate) {
      if (($candidate['id'] ?? '') === ($flow['id'] ?? '')) {
        continue;
      }

      $candidate_entrypoint = trim((string) ($candidate['default_entrypoint'] ?? ''));
      if ($candidate_entrypoint !== '' && in_array($candidate_entrypoint, $nodes, TRUE)) {
        $children[$candidate_entrypoint] = $candidate;
      }
    }

    return $children;
  }

  private function parentFlowsFor(array $flow, string $entrypoint): array {
    if ($entrypoint === '') {
      return [];
    }

    $parents = [];
    foreach ($this->flows->allFlows() as $candidate) {
      if (($candidate['id'] ?? '') === ($flow['id'] ?? '')) {
        continue;
      }

      $candidate_nodes = array_values(array_filter(array_map('strval', (array) ($candidate['nodes'] ?? [])), static fn(string $value): bool => $value !== ''));
      if (in_array($entrypoint, $candidate_nodes, TRUE)) {
        $parents[] = $candidate;
      }
    }

    return $parents;
  }

  private function flowVisualizationChip(string $label, string $value): string {
    return '<div class="drupal-langgraph-flow-chip">' .
      '<span class="drupal-langgraph-flow-chip__label">' . Html::escape($label) . '</span>' .
      '<span class="drupal-langgraph-flow-chip__value">' . Html::escape($value) . '</span>' .
      '</div>';
  }

  private function flowMermaidDefinition(array $flow): string {
    $nodes = array_values(array_filter(array_map('strval', (array) ($flow['nodes'] ?? [])), static fn(string $value): bool => $value !== ''));
    $tools = array_values(array_filter(array_map('strval', (array) ($flow['tools'] ?? [])), static fn(string $value): bool => $value !== ''));
    $entrypoint = trim((string) ($flow['default_entrypoint'] ?? ''));
    $owner = (string) ($flow['owner'] ?? 'unassigned');
    $tool_options = $this->flows->toolOptions();
    $child_flows = $this->childFlowsFor($flow);
    $lines = ['flowchart LR'];

    if ($nodes === []) {
      $lines[] = '  empty["No nodes configured yet"]';
      $lines[] = '  classDef empty fill:#f8fafc,stroke:#94a3b8,color:#334155;';
      $lines[] = '  class empty empty;';
      return implode("\n", $lines);
    }

    $owner_id = $this->mermaidId('owner_' . (string) ($flow['id'] ?? 'flow'));
    $lines[] = '  ' . $owner_id . '["Owning seat<br/>' . $this->mermaidLabel($owner) . '"]';

    $node_paths = [];
    foreach ($nodes as $index => $node) {
      if (isset($child_flows[$node])) {
        $child = $child_flows[$node];
        $child_nodes = array_values(array_filter(array_map('strval', (array) ($child['nodes'] ?? [])), static fn(string $value): bool => $value !== ''));
        if ($child_nodes !== []) {
          $subgraph_id = $this->mermaidId('subgraph_' . (string) ($child['id'] ?? $node));
          $lines[] = '  subgraph ' . $subgraph_id . '["' . $this->mermaidLabel((string) ($child['label'] ?? $child['id'] ?? $node)) . '"]';
          $prior_child_id = NULL;
          foreach ($child_nodes as $child_index => $child_node) {
            $child_node_id = $this->mermaidId('node_' . $index . '_' . $child_index . '_' . $child_node);
            $node_paths[$child_node] = ['first' => $child_node_id, 'last' => $child_node_id];
            $lines[] = '    ' . $child_node_id . '["' . $this->mermaidLabel($child_node) . '"]';
            if ($prior_child_id !== NULL) {
              $lines[] = '    ' . $prior_child_id . ' --> ' . $child_node_id;
            }
            $prior_child_id = $child_node_id;
          }
          $lines[] = '  end';
          $node_paths[$node] = [
            'first' => $this->mermaidId('node_' . $index . '_0_' . $child_nodes[0]),
            'last' => $this->mermaidId('node_' . $index . '_' . (count($child_nodes) - 1) . '_' . $child_nodes[count($child_nodes) - 1]),
          ];
          continue;
        }
      }

      $node_id = $this->mermaidId('node_' . $index . '_' . $node);
      $node_paths[$node] = ['first' => $node_id, 'last' => $node_id];
      $lines[] = '  ' . $node_id . '["' . $this->mermaidLabel($node) . '"]';
    }

    $first_path = reset($node_paths);
    $first_node_id = is_array($first_path) ? ($first_path['first'] ?? NULL) : NULL;
    if (is_string($first_node_id) && $first_node_id !== '') {
      $lines[] = '  ' . $owner_id . ' -. owns .-> ' . $first_node_id;
    }

    $prior_id = NULL;
    foreach ($nodes as $node) {
      $node_id = $node_paths[$node]['first'] ?? NULL;
      $node_last_id = $node_paths[$node]['last'] ?? $node_id;
      if (!is_string($node_id) || $node_id === '') {
        continue;
      }
      if ($prior_id !== NULL) {
        $lines[] = '  ' . $prior_id . ' --> ' . $node_id;
      }
      $prior_id = is_string($node_last_id) ? $node_last_id : $node_id;
    }

    if ($entrypoint !== '') {
      if (isset($node_paths[$entrypoint]['first'])) {
        $lines[] = '  class ' . $node_paths[$entrypoint]['first'] . ' entrypoint;';
      }
      else {
        $entry_id = $this->mermaidId('entry_' . $entrypoint);
        $lines[] = '  ' . $entry_id . '["Entrypoint<br/>' . $this->mermaidLabel($entrypoint) . '"]';
        if (is_string($first_node_id) && $first_node_id !== '') {
          $lines[] = '  ' . $entry_id . ' --> ' . $first_node_id;
        }
        $lines[] = '  class ' . $entry_id . ' entrypoint;';
      }
    }

    foreach ($tools as $index => $tool) {
      $tool_id = $this->mermaidId('tool_' . $index . '_' . $tool);
      $tool_label = $tool_options[$tool] ?? $tool;
      $lines[] = '  ' . $tool_id . '["Tool<br/>' . $this->mermaidLabel($tool_label) . '"]';
      if (is_string($first_node_id) && $first_node_id !== '') {
        $lines[] = '  ' . $tool_id . ' -. available .-> ' . $first_node_id;
      }
      $lines[] = '  class ' . $tool_id . ' tool;';
    }

    $lines[] = '  classDef entrypoint fill:#dcfce7,stroke:#15803d,stroke-width:3px,color:#14532d;';
    $lines[] = '  classDef tool fill:#dbeafe,stroke:#2563eb,color:#1e3a8a;';
    $lines[] = '  classDef owner fill:#fef3c7,stroke:#d97706,color:#78350f;';
    $lines[] = '  class ' . $owner_id . ' owner;';

    return implode("\n", $lines);
  }

  private function mermaidId(string $value): string {
    return Html::getId($value);
  }

  private function mermaidLabel(string $value): string {
    $value = preg_replace('/\s+/', ' ', trim($value)) ?? '';
    return str_replace(['"', '<', '>'], ["'", '', ''], $value);
  }

  private function flowWorkspaceSubsectionPage(array $flow, string $section, string $subsection): array {
    $map = $this->flowWorkspaceMap();
    if (!isset($map[$section]['controls'][$subsection])) {
      throw new NotFoundHttpException();
    }

    if ($section === 'build') {
      return $this->flowBuildEditorPage($flow, $subsection);
    }

    if ($section === 'test' && in_array($subsection, ['validate-structure', 'checkpoints'], TRUE)) {
      return $this->flowTestControlPage($flow, $subsection);
    }

    if ($section === 'run' && in_array($subsection, ['manual-run', 'pause-resume'], TRUE)) {
      return $this->flowRunControlPage($flow, $subsection);
    }

    if ($section === 'release' && in_array($subsection, ['versions', 'promote'], TRUE)) {
      return $this->flowReleaseControlPage($flow, $subsection);
    }

    if ($section === 'observe' && $subsection === 'control-requests') {
      return $this->flowObserveControlRequestsPage($flow);
    }

    $info = $map[$section]['controls'][$subsection];
    $is_stub = $info['status'] !== 'Supported';
    $build = [
      '#type' => 'container',
      '#cache' => ['max-age' => 0],
      'title' => ['#markup' => '<h2>' . $this->t('@flow: @control', ['@flow' => $flow['label'], '@control' => $info['title']]) . '</h2>'],
      'description' => ['#markup' => '<p>' . $this->t($info['description']) . '</p>'],
      'actions' => [
        '#markup' => '<p>' .
          Link::fromTextAndUrl($this->t('Back to @section', ['@section' => $map[$section]['title']]), Url::fromRoute($this->flowSectionRouteName($section), ['flow_id' => $flow['id']]))->toString() .
          ' | ' .
          Link::fromTextAndUrl($this->t('Flow overview'), Url::fromRoute('drupal_langgraph.langgraph_console_flow_detail', ['flow_id' => $flow['id']]))->toString() .
          '</p>',
      ],
      'status' => $this->tableDetails('Control Status', ['Field', 'Value'], [
        ['Flow', $flow['label']],
        ['Section', $map[$section]['title']],
        ['Control', $info['title']],
        ['Implementation', $info['status']],
      ]),
      'notice' => ['#markup' => '<div class="messages messages--' . ($is_stub ? 'warning' : 'status') . '"><strong>' . $this->t($is_stub ? 'Stubbed workspace' : 'Nested control') . ':</strong> ' . $this->t($is_stub ? 'This page marks the ideal home for the @control control inside the flow workspace. It is present now so the UI can be nested correctly while implementation catches up.' : 'This control now lives in the correct nested flow workspace. The surrounding authoring and execution controls still need to catch up to the ideal UI.', ['@control' => $info['title']]) . '</div>'],
    ];

    if ($snapshot_rows = $this->flowWorkspaceSnapshotRows($flow, $section, $subsection)) {
      $build['snapshot'] = $this->tableDetails('Current Flow Snapshot', ['Field', 'Value'], $snapshot_rows);
    }

    foreach ($this->flowWorkspaceSubsectionEvidence($section, $subsection) as $key => $component) {
      $build[$key] = $component;
    }

    return $this->withFlowVisualization($build, $flow, $info['title']);
  }

  private function flowObserveControlRequestsPage(array $flow): array {
    return $this->withFlowVisualization([
      '#type' => 'container',
      '#cache' => ['max-age' => 0],
      'title' => ['#markup' => '<h2>' . $this->t('@flow: Observe: Control Requests', ['@flow' => $flow['label']]) . '</h2>'],
      'description' => ['#markup' => '<p>' . $this->t('Inspect runtime, checkpoint, and release request artifacts recorded for this flow.') . '</p>'],
      'actions' => [
        '#markup' => '<p>' .
          Link::fromTextAndUrl($this->t('Back to Observe'), Url::fromRoute('drupal_langgraph.langgraph_console_flow_observe', ['flow_id' => $flow['id']]))->toString() .
          ' | ' .
          Link::fromTextAndUrl($this->t('Flow overview'), Url::fromRoute('drupal_langgraph.langgraph_console_flow_detail', ['flow_id' => $flow['id']]))->toString() .
          '</p>',
      ],
      'status' => $this->tableDetails('Control Status', ['Field', 'Value'], [
        ['Flow', $flow['label']],
        ['Section', 'Observe'],
        ['Control', 'Control Requests'],
        ['Implementation', 'Supported'],
      ]),
      'runtime_requests' => $this->tableDetails('Runtime Requests', $this->runtimeRequestHeaders(), $this->runtimeControlRequestRows($flow['id'])),
      'replay_requests' => $this->tableDetails('Replay Requests', $this->replayRequestHeaders(), $this->replayRequestRows($flow['id'])),
      'promotion_requests' => $this->tableDetails('Promotion Requests', $this->promotionRequestHeaders(), $this->promotionRequestRows($flow['id'])),
      'version_snapshots' => $this->tableDetails('Version Snapshots', $this->versionSnapshotHeaders(), $this->flowVersionRows($flow['id'])),
    ], $flow, 'Observe: Control Requests');
  }

  private function flowBuildEditorPage(array $flow, string $subsection): array {
    $info = $this->flowWorkspaceMap()['build']['controls'][$subsection];
    return $this->withFlowVisualization([
      '#type' => 'container',
      '#cache' => ['max-age' => 0],
      'title' => ['#markup' => '<h2>' . $this->t('@flow: @control', ['@flow' => $flow['label'], '@control' => $info['title']]) . '</h2>'],
      'description' => ['#markup' => '<p>' . $this->t($info['description']) . '</p>'],
      'actions' => [
        '#markup' => '<p>' .
          Link::fromTextAndUrl($this->t('Back to Build'), Url::fromRoute('drupal_langgraph.langgraph_console_flow_build', ['flow_id' => $flow['id']]))->toString() .
          ' | ' .
          Link::fromTextAndUrl($this->t('Flow overview'), Url::fromRoute('drupal_langgraph.langgraph_console_flow_detail', ['flow_id' => $flow['id']]))->toString() .
          '</p>',
      ],
      'status' => $this->tableDetails('Control Status', ['Field', 'Value'], [
        ['Flow', $flow['label']],
        ['Section', 'Build'],
        ['Control', $info['title']],
        ['Implementation', 'Supported'],
      ]),
      'notice' => ['#markup' => '<div class="messages messages--status"><strong>' . $this->t('Active editor') . ':</strong> ' . $this->t('This nested Build control now edits the live flow contract directly from the flow workspace.') . '</div>'],
      'form' => $this->formBuilder()->getForm(ProcessFlowWorkspaceEditorForm::class, $flow, $subsection),
      'snapshot' => $this->flowWorkspaceSnapshotRows($flow, 'build', $subsection) !== []
        ? $this->tableDetails('Current Flow Snapshot', ['Field', 'Value'], $this->flowWorkspaceSnapshotRows($flow, 'build', $subsection))
        : [],
    ], $flow, $info['title']);
  }

  private function flowTestControlPage(array $flow, string $subsection): array {
    $info = $this->flowWorkspaceMap()['test']['controls'][$subsection];
    $build = [
      '#type' => 'container',
      '#cache' => ['max-age' => 0],
      'title' => ['#markup' => '<h2>' . $this->t('@flow: @control', ['@flow' => $flow['label'], '@control' => $info['title']]) . '</h2>'],
      'description' => ['#markup' => '<p>' . $this->t($info['description']) . '</p>'],
      'actions' => [
        '#markup' => '<p>' .
          Link::fromTextAndUrl($this->t('Back to Test'), Url::fromRoute('drupal_langgraph.langgraph_console_flow_test', ['flow_id' => $flow['id']]))->toString() .
          ' | ' .
          Link::fromTextAndUrl($this->t('Flow overview'), Url::fromRoute('drupal_langgraph.langgraph_console_flow_detail', ['flow_id' => $flow['id']]))->toString() .
          '</p>',
      ],
      'status' => $this->tableDetails('Control Status', ['Field', 'Value'], [
        ['Flow', $flow['label']],
        ['Section', 'Test'],
        ['Control', $info['title']],
        ['Implementation', $info['status']],
      ]),
    ];

    if ($subsection === 'validate-structure') {
      $build['notice'] = ['#markup' => '<div class="messages messages--status"><strong>' . $this->t('Active validator') . ':</strong> ' . $this->t('This control now evaluates the saved flow contract against structural expectations and the latest observed runtime shape.') . '</div>'];
      $build['validation'] = $this->tableDetails('Structure Validation Report', ['Check', 'Result', 'Detail'], $this->flowStructureValidationRows($flow));
      return $this->withFlowVisualization($build, $flow, $info['title']);
    }

    $checkpoint_candidates = $this->checkpointReplayCandidates();
    $build['notice'] = ['#markup' => '<div class="messages messages--warning"><strong>' . $this->t('Replay request surface') . ':</strong> ' . $this->t('Checkpoint candidates can now be selected and recorded as replay requests, but no replay executor consumes them yet.') . '</div>'];
    if ($checkpoint_candidates !== []) {
      $build['form'] = $this->formBuilder()->getForm(ProcessFlowCheckpointReplayForm::class, $flow, $checkpoint_candidates);
    }
    $build['candidates'] = $this->tableDetails('Replay Candidate Inventory', ['Checkpoint', 'Path', 'Modified', 'Replay eligibility', 'Notes'], $this->checkpointCandidateRows());
    $build['support_artifacts'] = $this->tableDetails('Checkpoint Support Artifacts', ['Artifact', 'Path', 'Modified', 'Notes'], $this->checkpointSupportArtifactRows());
    $build['requests'] = $this->tableDetails('Replay Requests', $this->replayRequestHeaders(), $this->replayRequestRows($flow['id']));
    return $this->withFlowVisualization($build, $flow, $info['title']);
  }

  private function flowRunControlPage(array $flow, string $subsection): array {
    $info = $this->flowWorkspaceMap()['run']['controls'][$subsection];
    $supports_runtime_execution = $this->flowSupportsRuntimeExecution($flow);
    return $this->withFlowVisualization([
      '#type' => 'container',
      '#cache' => ['max-age' => 0],
      'title' => ['#markup' => '<h2>' . $this->t('@flow: @control', ['@flow' => $flow['label'], '@control' => $info['title']]) . '</h2>'],
      'description' => ['#markup' => '<p>' . $this->t($info['description']) . '</p>'],
      'actions' => [
        '#markup' => '<p>' .
          Link::fromTextAndUrl($this->t('Back to Run'), Url::fromRoute('drupal_langgraph.langgraph_console_flow_run', ['flow_id' => $flow['id']]))->toString() .
          ' | ' .
          Link::fromTextAndUrl($this->t('Flow overview'), Url::fromRoute('drupal_langgraph.langgraph_console_flow_detail', ['flow_id' => $flow['id']]))->toString() .
          '</p>',
      ],
      'status' => $this->tableDetails('Control Status', ['Field', 'Value'], [
        ['Flow', $flow['label']],
        ['Section', 'Run'],
        ['Control', $info['title']],
        ['Implementation', $supports_runtime_execution ? 'Supported for this flow' : 'Partial'],
      ]),
      'notice' => ['#markup' => '<div class="messages messages--' . ($supports_runtime_execution ? 'status' : 'warning') . '"><strong>' . $this->t($supports_runtime_execution ? 'Active executor' : 'Request surface') . ':</strong> ' . $this->t($supports_runtime_execution ? 'This flow now has a runtime executor. Requests are consumed through the HQ runtime hooks and their outcomes are written back into the shared control-plane artifact model.' : 'This control records auditable runtime requests, but no runtime executor is registered for this flow yet.') . '</div>'],
      'runtime_state' => $this->tableDetails('Runtime State', ['Signal', 'Value'], $this->runtimeStateRows($flow)),
      'form' => $this->formBuilder()->getForm(ProcessFlowRuntimeRequestForm::class, $flow, $subsection),
      'requests' => $this->tableDetails('Recent Runtime Requests', $this->runtimeRequestHeaders(), $this->runtimeControlRequestRows($flow['id'])),
      'replay_requests' => $this->tableDetails('Recent Replay Requests', $this->replayRequestHeaders(), $this->replayRequestRows($flow['id'])),
    ], $flow, $info['title']);
  }

  private function flowReleaseControlPage(array $flow, string $subsection): array {
    $info = $this->flowWorkspaceMap()['release']['controls'][$subsection];
    $build = [
      '#type' => 'container',
      '#cache' => ['max-age' => 0],
      'title' => ['#markup' => '<h2>' . $this->t('@flow: @control', ['@flow' => $flow['label'], '@control' => $info['title']]) . '</h2>'],
      'description' => ['#markup' => '<p>' . $this->t($info['description']) . '</p>'],
      'actions' => [
        '#markup' => '<p>' .
          Link::fromTextAndUrl($this->t('Back to Release'), Url::fromRoute('drupal_langgraph.langgraph_console_flow_release', ['flow_id' => $flow['id']]))->toString() .
          ' | ' .
          Link::fromTextAndUrl($this->t('Flow overview'), Url::fromRoute('drupal_langgraph.langgraph_console_flow_detail', ['flow_id' => $flow['id']]))->toString() .
          '</p>',
      ],
      'status' => $this->tableDetails('Control Status', ['Field', 'Value'], [
        ['Flow', $flow['label']],
        ['Section', 'Release'],
        ['Control', $info['title']],
        ['Implementation', $info['status']],
      ]),
      'form' => $this->formBuilder()->getForm(ProcessFlowReleaseActionForm::class, $flow, $subsection),
    ];

    if ($subsection === 'versions') {
      $build['notice'] = ['#markup' => '<div class="messages messages--status"><strong>' . $this->t('Version snapshots') . ':</strong> ' . $this->t('This control now records version snapshots for the selected flow.') . '</div>'];
      $build['promotion_state'] = $this->tableDetails('Current Promotion State', ['Field', 'Value'], $this->promotionStateRows($flow));
      $build['versions'] = $this->tableDetails('Version Snapshots', $this->versionSnapshotHeaders(), $this->flowVersionRows($flow['id']));
      return $this->withFlowVisualization($build, $flow, $info['title']);
    }

    $build['notice'] = ['#markup' => '<div class="messages messages--status"><strong>' . $this->t('Promotion executor') . ':</strong> ' . $this->t('Promotion requests are validated against release-cycle control and promoted-version state is written back into the control plane.') . '</div>'];
    $build['promotion_state'] = $this->tableDetails('Current Promotion State', ['Field', 'Value'], $this->promotionStateRows($flow));
    $build['versions'] = $this->tableDetails('Available Versions', $this->versionSnapshotHeaders(), $this->flowVersionRows($flow['id']));
    $build['requests'] = $this->tableDetails('Promotion Requests', $this->promotionRequestHeaders(), $this->promotionRequestRows($flow['id']));
    return $this->withFlowVisualization($build, $flow, $info['title']);
  }

  private function flowStructureValidationRows(array $flow): array {
    $rows = [];
    $nodes = array_values(array_filter(array_map('strval', (array) ($flow['nodes'] ?? [])), static fn(string $value): bool => $value !== ''));
    $routing_rules = array_values(array_filter(array_map('strval', (array) ($flow['routing_rules'] ?? [])), static fn(string $value): bool => $value !== ''));
    $tools = array_values(array_filter(array_map('strval', (array) ($flow['tools'] ?? [])), static fn(string $value): bool => $value !== ''));
    $duplicate_nodes = $this->flows->duplicateValues($nodes);
    $unknown_tools = $this->flows->unknownTools($tools);
    $duplicate_routing_rules = $this->flows->duplicateValues($routing_rules);
    $entrypoint = (string) ($flow['default_entrypoint'] ?? '');
    $latest_tick = $this->readLatestTick();
    $observed_nodes = array_values(array_filter(array_keys((array) ($latest_tick['step_results'] ?? [])), static fn(string $key): bool => $key !== 'summarize_tick'));

    $rows[] = ['Flow metadata', (($flow['label'] ?? '') !== '' && ($flow['description'] ?? '') !== '') ? 'PASS' : 'FAIL', (($flow['label'] ?? '') !== '' && ($flow['description'] ?? '') !== '') ? 'Label and description are present.' : 'Label or description is missing.'];
    $rows[] = ['Default entrypoint', $entrypoint !== '' ? 'PASS' : 'FAIL', $entrypoint !== '' ? 'Entrypoint is configured.' : 'Default entrypoint is missing.'];
    $rows[] = ['Configured nodes', $nodes !== [] ? 'PASS' : 'WARN', $nodes !== [] ? implode(', ', $nodes) : 'No nodes are configured yet.'];
    $rows[] = ['Unique nodes', $duplicate_nodes === [] ? 'PASS' : 'FAIL', $duplicate_nodes === [] ? 'No duplicate node names detected.' : 'Duplicate node names: ' . implode(', ', $duplicate_nodes)];
    $rows[] = ['Entrypoint in nodes', $this->flows->entrypointMatchesNodes($entrypoint, $nodes) ? 'PASS' : 'FAIL', $this->flows->entrypointMatchesNodes($entrypoint, $nodes) ? 'Entrypoint is consistent with configured nodes.' : 'Entrypoint is not present in the configured node list.'];
    $rows[] = ['Routing rules', $routing_rules !== [] ? 'PASS' : ($nodes === [] || count($nodes) <= 1 ? 'PASS' : 'WARN'), $routing_rules !== [] ? (string) count($routing_rules) . ' rule(s) configured.' : (($nodes === [] || count($nodes) <= 1) ? 'Single-node flow does not require routing rules.' : 'No routing rules are configured for a multi-node flow.')];
    $rows[] = ['Unique routing rules', $duplicate_routing_rules === [] ? 'PASS' : 'FAIL', $duplicate_routing_rules === [] ? 'No duplicate routing rules detected.' : 'Duplicate routing rules: ' . implode(' | ', $duplicate_routing_rules)];
    $rows[] = ['Tools', $unknown_tools === [] ? ($tools !== [] ? 'PASS' : 'WARN') : 'FAIL', $unknown_tools === [] ? ($tools !== [] ? implode(', ', $tools) : 'No tools are configured.') : 'Unknown tool bindings: ' . implode(', ', $unknown_tools)];
    $rows[] = ['Observed runtime shape', $observed_nodes !== [] ? 'PASS' : 'WARN', $observed_nodes !== [] ? implode(', ', $observed_nodes) : 'No observed runtime node evidence in the latest tick.'];

    if ($nodes !== [] && $observed_nodes !== []) {
      $missing_from_runtime = array_values(array_diff($nodes, $observed_nodes));
      $unexpected_in_runtime = array_values(array_diff($observed_nodes, $nodes));
      $rows[] = [
        'Configured vs observed nodes',
        ($missing_from_runtime === [] && $unexpected_in_runtime === []) ? 'PASS' : 'WARN',
        ($missing_from_runtime === [] && $unexpected_in_runtime === [])
          ? 'Configured nodes match the latest observed runtime nodes.'
          : 'Missing from runtime: ' . ($missing_from_runtime !== [] ? implode(', ', $missing_from_runtime) : '(none)') . '; unexpected in runtime: ' . ($unexpected_in_runtime !== [] ? implode(', ', $unexpected_in_runtime) : '(none)'),
      ];
    }

    return $rows;
  }

  private function adminGovernanceRows(): array {
    $rows = [];
    foreach ($this->adminArtifactRootDefinitions() as $definition) {
      $summary = $this->summarizeAdminArtifactRecords($definition);
      $rows[] = [
        $definition['label'],
        (string) $summary['total'],
        (string) $summary['active'],
        (string) $summary['failed'],
        (string) $summary['stale'],
        (string) $summary['orphaned'],
        $summary['latest_event'],
      ];
    }

    return $rows;
  }

  private function adminControlContractRows(): array {
    $rows = [];
    foreach ($this->adminArtifactRootDefinitions() as $definition) {
      $rows[] = [
        $definition['label'],
        $definition['writer'],
        $definition['consumer'],
        $definition['retention_guidance'],
      ];
    }

    return $rows;
  }

  private function adminRetentionRows(): array {
    $rows = [];
    foreach ($this->adminArtifactRootDefinitions() as $definition) {
      $summary = $this->summarizeAdminArtifactRecords($definition);
      $rows[] = [
        $definition['label'],
        (string) $summary['old_completed'],
        (string) $summary['old_failed'],
        $summary['cleanup_action'],
      ];
    }

    return $rows;
  }

  private function adminArtifactRootDefinitions(): array {
    return [
      [
        'label' => 'Runtime requests',
        'path' => $this->artifacts->runtimeControlRequestBaseDir(),
        'records' => $this->artifactRecordsForAdmin('runtime_request'),
        'writer' => 'Run workspace and automation operators',
        'consumer' => 'hq-automation-watchdog.sh -> process-langgraph-runtime-requests.py',
        'retention_guidance' => 'Keep recent request history for operator audit; clean old completed entries after 30 days.',
        'stale_after' => 3600,
        'retention_after' => 30 * 86400,
      ],
      [
        'label' => 'Checkpoint replay requests',
        'path' => $this->artifacts->checkpointReplayRequestBaseDir(),
        'records' => $this->artifactRecordsForAdmin('replay_request'),
        'writer' => 'Test workspace operators',
        'consumer' => 'hq-automation-watchdog.sh -> process-langgraph-replay-requests.py',
        'retention_guidance' => 'Preserve recent replay provenance; clean old terminal entries after 30 days.',
        'stale_after' => 3600,
        'retention_after' => 30 * 86400,
      ],
      [
        'label' => 'Version snapshots',
        'path' => $this->artifacts->flowVersionBaseDir(),
        'records' => $this->artifactRecordsForAdmin('version_snapshot'),
        'writer' => 'Release workspace version capture',
        'consumer' => 'Release workspace, promotion requests, and audit review',
        'retention_guidance' => 'Treat as release evidence; retain until superseded by explicit archival policy.',
        'stale_after' => 0,
        'retention_after' => 90 * 86400,
      ],
      [
        'label' => 'Promotion requests',
        'path' => $this->artifacts->promotionRequestBaseDir(),
        'records' => $this->artifactRecordsForAdmin('promotion_request'),
        'writer' => 'Release workspace promotion control',
        'consumer' => 'hq-automation-watchdog.sh -> process-langgraph-promotion-requests.py',
        'retention_guidance' => 'Keep promotion decisions for release audit; clean terminal entries after 30 days when mirrored elsewhere.',
        'stale_after' => 3600,
        'retention_after' => 30 * 86400,
      ],
      [
        'label' => 'Promoted version state',
        'path' => $this->artifacts->promotionStateBaseDir(),
        'records' => $this->artifactRecordsForAdmin('promotion_state'),
        'writer' => 'Promotion worker',
        'consumer' => 'Flow detail, Build, and Release workspaces',
        'retention_guidance' => 'One current record per flow; retain current.json and investigate extra or orphaned state files.',
        'stale_after' => 0,
        'retention_after' => 90 * 86400,
      ],
    ];
  }

  private function artifactRecordsForAdmin(string $type): array {
    return match ($type) {
      'runtime_request' => $this->filterAdminArtifactRecords($this->artifacts->listRuntimeRequests(NULL, 500)),
      'replay_request' => $this->filterAdminArtifactRecords($this->artifacts->listReplayRequests(NULL, 500)),
      'version_snapshot' => $this->filterAdminArtifactRecords($this->artifacts->listVersionSnapshots(NULL, 500)),
      'promotion_request' => $this->filterAdminArtifactRecords($this->artifacts->listPromotionRequests(NULL, 500)),
      'promotion_state' => $this->promotionStateRecordsForAdmin(),
      default => [],
    };
  }

  private function filterAdminArtifactRecords(array $records): array {
    return array_values(array_filter($records, static function (array $record): bool {
      return (($record['path'] ?? '-') !== '-') && (($record['request_id'] ?? '-') !== '-');
    }));
  }

  private function promotionStateRecordsForAdmin(): array {
    $records = [];
    foreach (glob(rtrim($this->artifacts->promotionStateBaseDir(), '/') . '/*/current.json') ?: [] as $path) {
      $payload = $this->readJsonFile($path);
      if ($payload === []) {
        continue;
      }

      $records[] = [
        'artifact_type' => 'promotion_state',
        'request_id' => (string) ($payload['request_id'] ?? basename(dirname($path)) . '-current'),
        'status' => (string) ($payload['status'] ?? 'completed'),
        'flow_id' => (string) ($payload['flow_id'] ?? basename(dirname($path))),
        'event_at' => (string) ($payload['promoted_at'] ?? gmdate('c', (int) filemtime($path))),
        'path' => $path,
      ];
    }

    return $records;
  }

  private function summarizeAdminArtifactRecords(array $definition): array {
    $valid_flow_ids = array_keys($this->flows->allFlows());
    $active_statuses = ['requested', 'accepted', 'running'];
    $terminal_failure_statuses = ['failed', 'cancelled'];
    $records = $definition['records'];
    $stale_after = (int) $definition['stale_after'];
    $retention_after = (int) $definition['retention_after'];
    $summary = [
      'total' => count($records),
      'active' => 0,
      'failed' => 0,
      'stale' => 0,
      'orphaned' => 0,
      'latest_event' => 'none',
      'old_completed' => 0,
      'old_failed' => 0,
      'cleanup_action' => 'No cleanup needed.',
    ];

    $latest_epoch = NULL;
    foreach ($records as $record) {
      $status = (string) ($record['status'] ?? '');
      $flow_id = (string) ($record['flow_id'] ?? '');
      $event_at = (string) ($record['event_at'] ?? '');
      $epoch = $this->timestampToEpoch($event_at);

      if (in_array($status, $active_statuses, TRUE)) {
        $summary['active']++;
      }
      if (in_array($status, $terminal_failure_statuses, TRUE)) {
        $summary['failed']++;
      }
      if ($stale_after > 0 && in_array($status, $active_statuses, TRUE) && $epoch !== NULL && (time() - $epoch) > $stale_after) {
        $summary['stale']++;
      }
      if ($flow_id === '' || !in_array($flow_id, $valid_flow_ids, TRUE)) {
        $summary['orphaned']++;
      }
      if ($epoch !== NULL && ($latest_epoch === NULL || $epoch > $latest_epoch)) {
        $latest_epoch = $epoch;
      }
      if ($retention_after > 0 && $epoch !== NULL && (time() - $epoch) > $retention_after) {
        if ($status === 'completed') {
          $summary['old_completed']++;
        }
        if (in_array($status, $terminal_failure_statuses, TRUE)) {
          $summary['old_failed']++;
        }
      }
    }

    if ($latest_epoch !== NULL) {
      $summary['latest_event'] = $this->formatAgeFromEpoch($latest_epoch);
    }

    $cleanup_parts = [];
    if ($summary['stale'] > 0) {
      $cleanup_parts[] = 'Investigate stale active requests.';
    }
    if ($summary['orphaned'] > 0) {
      $cleanup_parts[] = 'Review orphaned flow references.';
    }
    if ($summary['old_completed'] > 0 || $summary['old_failed'] > 0) {
      $cleanup_parts[] = 'Archive or prune aged terminal artifacts.';
    }
    if ($cleanup_parts !== []) {
      $summary['cleanup_action'] = implode(' ', $cleanup_parts);
    }

    return $summary;
  }

  private function checkpointReplayCandidates(): array {
    $candidates = [];
    $forseti_root = $this->paths->forsetiRoot();
    $files = [];
    foreach ([
      $forseti_root . '/inbox/responses/auto-checkpoint*.log',
      $forseti_root . '/inbox/responses/auto-checkpoint-latest.log',
      $forseti_root . '/inbox/responses/auto-checkpoint-cron.log',
    ] as $pattern) {
      foreach (glob($pattern) ?: [] as $path) {
        if (is_file($path)) {
          $files[$path] = $path;
        }
      }
    }

    ksort($files);
    foreach ($files as $path) {
      $checkpoint_id = basename($path);
      $modified = gmdate('c', (int) filemtime($path));
      $candidates[$checkpoint_id] = [
        'path' => $path,
        'label' => $checkpoint_id . ' (' . $modified . ')',
        'modified' => $modified,
      ];
    }

    return $candidates;
  }

  private function checkpointCandidateRows(): array {
    $rows = [];
    foreach ($this->checkpointReplayCandidates() as $checkpoint_id => $candidate) {
      $rows[] = [
        $checkpoint_id,
        $this->toRelativePath((string) $candidate['path']),
        (string) $candidate['modified'],
        'Eligible',
        'Replay request can reference this artifact.',
      ];
    }

    return $rows !== [] ? $rows : [['-', '-', '-', 'Unavailable', 'No replay candidate artifacts were found.']];
  }

  private function checkpointSupportArtifactRows(): array {
    $files = [];
    $forseti_root = $this->paths->forsetiRoot();
    foreach ([
      $forseti_root . '/scripts/*checkpoint*.sh',
      $forseti_root . '/.auto-checkpoint-loop.pid',
    ] as $pattern) {
      foreach (glob($pattern) ?: [] as $path) {
        $files[$path] = $path;
      }
    }

    if ($files === []) {
      return [['Checkpoint support', '-', '-', 'No checkpoint support artifacts were found in the current runtime root.']];
    }

    ksort($files);
    $rows = [];
    foreach ($files as $path) {
      $rows[] = [
        basename($path),
        $this->toRelativePath($path),
        gmdate('c', (int) filemtime($path)),
        is_file($path) ? 'Artifact present; replay action not yet wired.' : 'Path present.',
      ];
    }

    return $rows;
  }

  private function replayRequestRows(?string $flow_id = NULL): array {
    $rows = [];
    foreach ($this->artifacts->listReplayRequests($flow_id) as $record) {
      $rows[] = [
        (string) ($record['event_at'] ?? '-'),
        (string) ($record['status'] ?? '-'),
        (string) ($record['actor'] ?? '-'),
        (string) ($record['checkpoint_id'] ?? '-'),
        $this->artifactDetail($record),
        $this->toRelativePath((string) ($record['path'] ?? '-')),
      ];
    }
    return $rows;
  }

  private function runtimeControlRequestRows(?string $flow_id = NULL): array {
    $rows = [];
    foreach ($this->artifacts->listRuntimeRequests($flow_id) as $record) {
      $rows[] = [
        (string) ($record['event_at'] ?? '-'),
        (string) ($record['status'] ?? '-'),
        (string) ($record['action'] ?? '-'),
        (string) ($record['actor'] ?? '-'),
        $this->artifactDetail($record),
        $this->toRelativePath((string) ($record['path'] ?? '-')),
      ];
    }
    return $rows;
  }

  private function runtimeControlRequestBaseDir(): string {
    return $this->artifacts->runtimeControlRequestBaseDir();
  }

  private function flowVersionRows(?string $flow_id = NULL): array {
    $rows = [];
    foreach ($this->artifacts->listVersionSnapshots($flow_id) as $record) {
      $rows[] = [
        (string) ($record['version_id'] ?? '-'),
        (string) ($record['status'] ?? '-'),
        (string) ($record['event_at'] ?? '-'),
        (string) ($record['actor'] ?? '-'),
        $this->artifactDetail($record),
        $this->toRelativePath((string) ($record['path'] ?? '-')),
      ];
    }
    return $rows;
  }

  private function promotionRequestRows(?string $flow_id = NULL): array {
    $rows = [];
    foreach ($this->artifacts->listPromotionRequests($flow_id) as $record) {
      $rows[] = [
        (string) ($record['event_at'] ?? '-'),
        (string) ($record['status'] ?? '-'),
        (string) ($record['version_id'] ?? '-'),
        (string) ($record['actor'] ?? '-'),
        $this->artifactDetail($record),
        $this->toRelativePath((string) ($record['path'] ?? '-')),
      ];
    }
    return $rows;
  }

  private function flowSupportsRuntimeExecution(array $flow): bool {
    return (string) ($flow['id'] ?? '') === 'hq_orchestrator_tick';
  }

  private function runtimeStateRows(array $flow): array {
    if (!$this->flowSupportsRuntimeExecution($flow)) {
      return [
        ['Runtime adapter', 'Request-only'],
        ['Effective state', 'No executor registered for this flow yet.'],
      ];
    }

    $org_control = $this->readOrgControl();
    $loop_status = $this->hqRuntimeCommandOutput(['bash', 'scripts/orchestrator-loop.sh', 'status']);
    $latest_request = $this->artifacts->listRuntimeRequests((string) ($flow['id'] ?? ''), 1)[0] ?? [];
    $latest_request_status = (string) ($latest_request['status'] ?? '-');

    $effective_state = 'idle';
    if (!($org_control['enabled'] ?? TRUE)) {
      $effective_state = 'paused';
    }
    elseif (in_array($latest_request_status, ['requested', 'accepted'], TRUE)) {
      $effective_state = 'queued';
    }
    elseif ($latest_request_status === 'running') {
      $effective_state = 'running';
    }
    elseif (str_contains(strtolower($loop_status), 'running')) {
      $effective_state = 'running';
    }
    elseif ($latest_request_status === 'failed') {
      $effective_state = 'failed';
    }

    return [
      ['Org automation', $this->boolLabel($org_control['enabled'] ?? NULL)],
      ['Loop status', $loop_status !== '' ? $loop_status : 'unknown'],
      ['Latest request status', $latest_request_status !== '' ? $latest_request_status : '-'],
      ['Effective runtime state', $effective_state],
    ];
  }

  private function hqRuntimeCommandOutput(array $command): string {
    $cwd = $this->paths->forsetiRoot();
    $escaped = implode(' ', array_map('escapeshellarg', $command));
    $output = shell_exec('cd ' . escapeshellarg($cwd) . ' && ' . $escaped . ' 2>/dev/null');
    return trim((string) $output);
  }

  private function artifactDetail(array $record): string {
    $detail = trim((string) ($record['detail'] ?? '-'));
    $status_message = trim((string) ($record['status_message'] ?? ''));
    if ($status_message !== '') {
      return $detail !== '' && $detail !== '-' ? $detail . ' | ' . $status_message : $status_message;
    }
    return $detail !== '' ? $detail : '-';
  }

  private function promotionStateRows(array $flow): array {
    $state = $this->artifacts->currentPromotionState((string) ($flow['id'] ?? ''));
    if ($state === []) {
      return [
        ['Current promoted version', 'None recorded yet.'],
        ['Release-cycle control', $this->boolLabel($this->readReleaseControl()['enabled'] ?? NULL)],
      ];
    }

    return [
      ['Current promoted version', (string) ($state['version_id'] ?? '-')],
      ['Promoted at', (string) ($state['promoted_at'] ?? '-')],
      ['Promoted by', (string) ($state['promoted_by'] ?? '-')],
      ['Previous version', (string) ($state['previous_version_id'] ?? '-')],
      ['State path', $this->toRelativePath((string) ($state['path'] ?? '-'))],
    ];
  }

  private function flowVersionBaseDir(): string {
    return $this->artifacts->flowVersionBaseDir();
  }

  private function promotionRequestBaseDir(): string {
    return $this->artifacts->promotionRequestBaseDir();
  }

  private function runtimeRequestHeaders(): array {
    return ['Requested at', 'Status', 'Action', 'Requested by', 'Detail', 'Path'];
  }

  private function replayRequestHeaders(): array {
    return ['Requested at', 'Status', 'Requested by', 'Checkpoint', 'Detail', 'Path'];
  }

  private function versionSnapshotHeaders(): array {
    return ['Version', 'Status', 'Created at', 'Created by', 'Summary', 'Path'];
  }

  private function promotionRequestHeaders(): array {
    return ['Requested at', 'Status', 'Version', 'Requested by', 'Reason', 'Path'];
  }

  private function flowWorkspaceEvidence(array $flow, string $section): array {
    return match ($section) {
      'build' => [
        'evidence' => $this->legacyBuildData()['graph_shape'],
        'authoring_guardrails' => $this->tableDetails('Authoring Guardrails', ['Decision', 'Policy'], [
          ['Authoring surface', 'Build remains the single edit surface for flow metadata, state schema, nodes, routing, tools, and prompt policy.'],
          ['Version lifecycle', 'Version snapshots and promotion stay in Release so authoring and release governance remain separate.'],
        ]),
        'version_state' => $this->tableDetails('Version & Promotion State', ['Field', 'Value'], $this->promotionStateRows($flow)),
        'version_history' => $this->tableDetails('Version Snapshots', $this->versionSnapshotHeaders(), $this->flowVersionRows($flow['id'])),
      ],
      'test' => [
        'structure' => $this->tableDetails('Structure Validation Summary', ['Check', 'Result', 'Detail'], $this->flowStructureValidationRows($flow)),
        'evidence' => $this->legacyTestData()['parity_evidence'],
      ],
      'run' => [
        'runtime_state' => $this->tableDetails('Runtime State', ['Signal', 'Value'], $this->runtimeStateRows($flow)),
        'requests' => $this->tableDetails('Recent Runtime Requests', $this->runtimeRequestHeaders(), $this->runtimeControlRequestRows($flow['id'])),
        'replay_requests' => $this->tableDetails('Recent Replay Requests', $this->replayRequestHeaders(), $this->replayRequestRows($flow['id'])),
        'evidence' => $this->legacyRunData()['run_timeline'],
      ],
      'observe' => [
        'overview' => $this->legacyObserveData()['overview'],
        'metrics' => $this->legacyObserveData()['metrics'],
      ],
      'release' => [
        'promotion_state' => $this->tableDetails('Current Promotion State', ['Field', 'Value'], $this->promotionStateRows($flow)),
        'versions' => $this->tableDetails('Version Snapshots', $this->versionSnapshotHeaders(), $this->flowVersionRows($flow['id'])),
        'promotion_requests' => $this->tableDetails('Promotion Requests', $this->promotionRequestHeaders(), $this->promotionRequestRows($flow['id'])),
        'control' => $this->legacyReleaseData()['control'],
        'release_state' => $this->legacyReleaseData()['release_state'],
        'coverage' => $this->legacyReleaseData()['coverage'],
      ],
      default => [],
    };
  }

  private function flowWorkspaceSubsectionEvidence(string $section, string $subsection): array {
    return match ($section . '/' . $subsection) {
      'test/parity' => ['evidence' => $this->legacyTestData()['parity_evidence']],
      'run/execution-history' => ['history' => $this->legacyRunData()['run_timeline']],
      'observe/traces' => ['traces' => $this->tableDetails('Trace Snapshot', ['Step', 'Tick timestamp', 'Status', 'Summary'], array_map(
        static fn(array $row): array => [$row['step'], $row['timestamp'], $row['status'], $row['summary']],
        array_slice($this->observe->nodeTraceRows(), 0, 10)
      ), $this->toRelativePath($this->paths->artifactPaths()['ticks']))],
      'observe/metrics' => ['metrics' => $this->tableDetails('Metric Snapshot', ['Metric', 'Value'], $this->buildObserveMetricRows($this->observe->metricSummary()), $this->toRelativePath($this->paths->artifactPaths()['ticks']))],
      'observe/drift' => ['drift' => $this->tableDetails('Drift Snapshot', ['Step', 'Baseline presence %', 'Baseline error %', 'Recent error %', 'Delta %', 'Latest status'], array_map(
        static fn(array $row): array => [$row['step'], (string) $row['baseline_presence_pct'], (string) $row['baseline_error_pct'], (string) $row['recent_error_pct'], (string) $row['delta_pct'], $row['latest_status']],
        $this->observe->driftRows()
      ), $this->toRelativePath($this->paths->artifactPaths()['ticks']))],
      'observe/alerts' => ['alerts' => $this->tableDetails('Incident Snapshot', ['Timestamp', 'Severity', 'Category', 'Seat', 'Summary', 'Path'], $this->buildObserveIncidentRows(array_slice($this->observe->incidentRows(), 0, 20), TRUE))],
      'observe/feature-progress' => ['progress' => $this->tableDetails('Feature Progress Snapshot', ['Work item', 'Module', 'Status', 'Priority'], $this->observe->featureProgressRows(), $this->toRelativePath($this->paths->artifactPaths()['feature_progress']))],
      'release/evidence' => [
        'notes' => $this->tableDetails('Recent Release Notes', ['Release id', 'Seat', 'Site', 'State', 'Updated at', 'Path'], array_map(
          static fn(array $note): array => [$note['release_id'], $note['seat'], $note['site'], $note['state'], $note['updated_at'], $note['path']],
          array_slice($this->listReleaseNotes(), 0, 10)
        )),
        'signoffs' => $this->tableDetails('Recent PM Signoffs', ['Release id', 'Site', 'PM seat', 'Signed off at', 'Path'], array_map(
          static fn(array $signoff): array => [$signoff['release_id'], $signoff['site'], $signoff['seat'], $signoff['signed_off_at'], $signoff['path']],
          array_slice($this->listReleaseSignoffs(), 0, 10)
        )),
      ],
      'release/troubleshooting' => ['active_work' => $this->tableDetails('Active Inbox Items', ['Seat', 'Item', 'Triage', 'Status', 'ROI', 'Age', 'Summary', 'Path'], array_map(
        static fn(array $item): array => [$item['seat'], $item['item_id'], $item['triage'], $item['status'], $item['roi'], $item['age'], $item['summary'], $item['path']],
        $this->listActiveInboxItems()
      ))],
      default => [],
    };
  }

  private function flowWorkspaceSnapshotRows(array $flow, string $section, string $subsection): array {
    return match ($section . '/' . $subsection) {
      'build/metadata' => [
        ['Label', $flow['label']],
        ['Status', $flow['status']],
        ['Owner', $flow['owner']],
        ['Default entrypoint', $flow['default_entrypoint']],
        ['Version', $flow['version']],
      ],
      'build/state-schema' => [['State schema summary', $flow['state_schema_summary'] !== '' ? $flow['state_schema_summary'] : '-']],
      'build/nodes' => [['Nodes', $flow['nodes'] !== [] ? implode(', ', $flow['nodes']) : '-']],
      'build/routing' => [['Routing rules', $flow['routing_rules'] !== [] ? implode(' | ', $flow['routing_rules']) : '-']],
      'build/tools' => [['Tools', $flow['tools'] !== [] ? implode(', ', $flow['tools']) : '-']],
      'build/prompts' => [['Prompt notes', $flow['prompt_notes'] !== '' ? $flow['prompt_notes'] : '-']],
      'release/versions' => [['Current version marker', $flow['version']]],
      default => [],
    };
  }

  private function flowSectionRouteName(string $section): string {
    return 'drupal_langgraph.langgraph_console_flow_' . $section;
  }

  private function linkCell(string $title, string $route_name, array $parameters = []): array {
    return [
      'data' => [
        '#markup' => Markup::create(Link::fromTextAndUrl($this->t($title), Url::fromRoute($route_name, $parameters))->toString()),
      ],
    ];
  }

  private function actionLinksCell(array $links): array {
    $items = [];
    foreach ($links as $link) {
      $items[] = Link::fromTextAndUrl($this->t($link['title']), Url::fromRoute($link['route'], $link['parameters'] ?? []))->toString();
    }

    return [
      'data' => [
        '#markup' => Markup::create(implode(' | ', $items)),
      ],
    ];
  }

  private function overviewLaunchpadRows(): array {
    return [
      [
        $this->linkCell('Flows', 'drupal_langgraph.langgraph_console_flows'),
        'Choose a process flow, inspect its workspace, or create a new draft flow.',
        $this->linkCell('Open Flows', 'drupal_langgraph.langgraph_console_flows'),
        $this->actionLinksCell([
          ['title' => 'New process flow', 'route' => 'drupal_langgraph.langgraph_console_flow_add'],
        ]),
      ],
      [
        $this->linkCell('Build', 'drupal_langgraph.langgraph_console_build'),
        'Author and inspect flow contracts, graph shape, and structure guidance.',
        $this->linkCell('Open Build', 'drupal_langgraph.langgraph_console_build'),
        $this->actionLinksCell([
          ['title' => 'Graph Shape', 'route' => 'drupal_langgraph.langgraph_console_subsection', 'parameters' => ['section' => 'build', 'subsection' => 'graph-shape']],
        ]),
      ],
      [
        $this->linkCell('Test', 'drupal_langgraph.langgraph_console_test'),
        'Review parity evidence and move into flow-scoped validation or replay work.',
        $this->linkCell('Open Test', 'drupal_langgraph.langgraph_console_test'),
        $this->actionLinksCell([
          ['title' => 'Parity Evidence', 'route' => 'drupal_langgraph.langgraph_console_subsection', 'parameters' => ['section' => 'test', 'subsection' => 'parity-evidence']],
        ]),
      ],
      [
        $this->linkCell('Run', 'drupal_langgraph.langgraph_console_run'),
        'Inspect recent execution history before selecting a flow for runtime actions.',
        $this->linkCell('Open Run', 'drupal_langgraph.langgraph_console_run'),
        $this->actionLinksCell([
          ['title' => 'Recent Runs', 'route' => 'drupal_langgraph.langgraph_console_subsection', 'parameters' => ['section' => 'run', 'subsection' => 'recent-runs']],
        ]),
      ],
      [
        $this->linkCell('Observe', 'drupal_langgraph.langgraph_console_observe'),
        'Troubleshoot runtime behavior, alerts, drift, metrics, and control-plane evidence.',
        $this->linkCell('Open Observe', 'drupal_langgraph.langgraph_console_observe'),
        $this->actionLinksCell([
          ['title' => 'Alerts', 'route' => 'drupal_langgraph.langgraph_console_subsection', 'parameters' => ['section' => 'observe', 'subsection' => 'alerts']],
          ['title' => 'Metrics', 'route' => 'drupal_langgraph.langgraph_console_subsection', 'parameters' => ['section' => 'observe', 'subsection' => 'metrics']],
          ['title' => 'Control Requests', 'route' => 'drupal_langgraph.langgraph_console_subsection', 'parameters' => ['section' => 'observe', 'subsection' => 'control-requests']],
        ]),
      ],
      [
        $this->linkCell('Release', 'drupal_langgraph.langgraph_console_release'),
        'Review release posture, evidence, troubleshooting, and flow-scoped version actions.',
        $this->linkCell('Open Release', 'drupal_langgraph.langgraph_console_release'),
        $this->actionLinksCell([
          ['title' => 'Release Evidence', 'route' => 'drupal_langgraph.langgraph_console_subsection', 'parameters' => ['section' => 'release', 'subsection' => 'release-evidence']],
          ['title' => 'Troubleshooting', 'route' => 'drupal_langgraph.langgraph_console_subsection', 'parameters' => ['section' => 'release', 'subsection' => 'release-troubleshooting']],
        ]),
      ],
      [
        $this->linkCell('Admin', 'drupal_langgraph.langgraph_console_admin'),
        'Inspect runtime roots, artifact health, governance counts, and retention expectations.',
        $this->linkCell('Open Admin', 'drupal_langgraph.langgraph_console_admin'),
        $this->actionLinksCell([
          ['title' => 'Runtime Roots', 'route' => 'drupal_langgraph.langgraph_console_subsection', 'parameters' => ['section' => 'admin', 'subsection' => 'runtime-roots']],
          ['title' => 'Artifacts', 'route' => 'drupal_langgraph.langgraph_console_subsection', 'parameters' => ['section' => 'admin', 'subsection' => 'artifacts']],
        ]),
      ],
    ];
  }

  private function overviewStatusRows(string $runtime_health, string $freshness, string $automation, int $incident_count, ?bool $parity_ok): array {
    return [
      [
        'Runtime health',
        $runtime_health,
        $this->actionLinksCell([
          ['title' => 'Open Observe', 'route' => 'drupal_langgraph.langgraph_console_observe'],
        ]),
      ],
      [
        'Data freshness',
        $freshness,
        $this->actionLinksCell([
          ['title' => 'Open Observe', 'route' => 'drupal_langgraph.langgraph_console_observe'],
          ['title' => 'Runtime Metrics', 'route' => 'drupal_langgraph.langgraph_console_subsection', 'parameters' => ['section' => 'observe', 'subsection' => 'metrics']],
        ]),
      ],
      [
        'Automation state',
        $automation,
        $this->actionLinksCell([
          ['title' => 'Open Admin', 'route' => 'drupal_langgraph.langgraph_console_admin'],
          ['title' => 'Runtime Roots', 'route' => 'drupal_langgraph.langgraph_console_subsection', 'parameters' => ['section' => 'admin', 'subsection' => 'runtime-roots']],
        ]),
      ],
      [
        'Active alerts',
        (string) $incident_count,
        $this->actionLinksCell([
          ['title' => 'Open Observe Alerts', 'route' => 'drupal_langgraph.langgraph_console_subsection', 'parameters' => ['section' => 'observe', 'subsection' => 'alerts']],
        ]),
      ],
      [
        'Parity health',
        $parity_ok === NULL ? 'unknown' : ($parity_ok ? 'PASS' : 'FAIL'),
        $this->actionLinksCell([
          ['title' => 'Open Test', 'route' => 'drupal_langgraph.langgraph_console_test'],
          ['title' => 'Parity Evidence', 'route' => 'drupal_langgraph.langgraph_console_subsection', 'parameters' => ['section' => 'test', 'subsection' => 'parity-evidence']],
        ]),
      ],
    ];
  }

  private function overviewRuntimeHealth(?int $tick_age_seconds, ?bool $parity_ok, string $engine_mode): string {
    if ($tick_age_seconds === NULL || $tick_age_seconds > 3600 || $parity_ok === FALSE) {
      return 'Needs attention';
    }
    if ($engine_mode === 'unknown') {
      return 'Degraded';
    }
    return 'Healthy';
  }

  private function overviewFreshness(?int $tick_age_seconds, string $formatted_age): string {
    if ($tick_age_seconds === NULL) {
      return 'Unknown';
    }
    if ($tick_age_seconds > 3600) {
      return 'Stale (' . $formatted_age . ')';
    }
    if ($tick_age_seconds > 900) {
      return 'Delayed (' . $formatted_age . ')';
    }
    return 'Fresh (' . $formatted_age . ')';
  }

  private function overviewAutomationState(array $org_control, array $release_control): string {
    $org_enabled = $org_control['enabled'] ?? NULL;
    $release_enabled = $release_control['enabled'] ?? NULL;
    if ($org_enabled === NULL || $release_enabled === NULL) {
      return 'Unknown';
    }
    if ((bool) $org_enabled && (bool) $release_enabled) {
      return 'Enabled';
    }
    return 'Partially disabled';
  }

  private function overviewNextAction(?int $tick_age_seconds, array $org_control, array $release_control, ?bool $parity_ok, array $incidents): string {
    if ($tick_age_seconds === NULL || $tick_age_seconds > 3600) {
      return 'Open Observe and investigate stale runtime data.';
    }
    if (($org_control['enabled'] ?? NULL) === NULL || ($release_control['enabled'] ?? NULL) === NULL) {
      return 'Open Admin and confirm control artifacts are readable.';
    }
    if ($parity_ok === FALSE) {
      return 'Open Test and resolve parity failures.';
    }
    if ($incidents !== []) {
      return 'Open Observe Alerts and triage the newest incident.';
    }
    if ($this->selectedFlow() !== NULL) {
      return 'Open the selected flow and continue work in its lifecycle tabs.';
    }
    return 'Open Flows and choose or create the next process flow.';
  }

  private function redirectToRoute(string $route_name, array $parameters = []): RedirectResponse {
    return new RedirectResponse(Url::fromRoute($route_name, $parameters)->toString());
  }

  private function detailSummary(array $detail, array $keys): string {
    $parts = [];
    foreach ($keys as $key) {
      if (isset($detail[$key])) {
        $parts[] = $key . '=' . (string) $detail[$key];
      }
    }

    return $parts !== [] ? implode('; ', $parts) : 'ok';
  }

  private function activeControlPath(string $env_key, string $default_key, string $legacy_key): string {
    $paths = $this->paths->artifactPaths();
    $preferred = trim((string) getenv($env_key));
    foreach ([$preferred, $paths[$default_key] ?? '', $paths[$legacy_key] ?? ''] as $path) {
      $path = trim((string) $path);
      if ($path !== '' && is_readable($path)) {
        return $path;
      }
    }

    return trim((string) ($paths[$default_key] ?? $paths[$legacy_key] ?? ''));
  }

  private function readJsonFile(string $path): array {
    if ($path === '' || !is_readable($path)) {
      return [];
    }

    $raw = @file_get_contents($path);
    if ($raw === FALSE) {
      return [];
    }

    $decoded = json_decode((string) $raw, TRUE);
    return is_array($decoded) ? $decoded : [];
  }

  private function readTicks(): array {
    $path = $this->paths->artifactPaths()['ticks'];
    if (!is_readable($path)) {
      return [];
    }

    $lines = @file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];
    $rows = [];
    foreach ($lines as $line) {
      $decoded = json_decode(trim((string) $line), TRUE);
      if (is_array($decoded)) {
        $rows[] = $decoded;
      }
    }

    return $rows;
  }

  private function readLatestTick(): array {
    $ticks = $this->readTicks();
    if ($ticks === []) {
      return [];
    }

    $latest = end($ticks);
    return is_array($latest) ? $latest : [];
  }

  private function readParity(): array {
    return $this->readJsonFile($this->paths->artifactPaths()['parity']);
  }

  private function readOrgControl(): array {
    $path = $this->activeControlPath('ORG_CONTROL_FILE', 'org_control_default', 'org_control_legacy');
    $payload = $this->readJsonFile($path);
    if (array_key_exists('enabled', $payload)) {
      return $payload;
    }

    $latest_tick = $this->readLatestTick();
    if (isset($latest_tick['summarize_tick']['org_enabled'])) {
      return $this->derivedControlState(
        (bool) $latest_tick['summarize_tick']['org_enabled'],
        $path,
        'Derived from latest tick summarize_tick.org_enabled because the control artifact was not readable as structured JSON.'
      );
    }

    $tick_epoch = $this->timestampToEpoch((string) ($latest_tick['ts'] ?? ''));
    if ($tick_epoch !== NULL && (time() - $tick_epoch) <= 3600 && is_array($latest_tick['step_results'] ?? NULL)) {
      return $this->derivedControlState(
        TRUE,
        $path,
        'Derived from fresh tick activity because the control artifact was not readable as structured JSON.'
      );
    }

    return $payload;
  }

  private function readReleaseControl(): array {
    $path = $this->activeControlPath('RELEASE_CYCLE_CONTROL_FILE', 'release_control_default', 'release_control_legacy');
    $payload = $this->readJsonFile($path);
    if (array_key_exists('enabled', $payload)) {
      return $payload;
    }

    $latest_tick = $this->readLatestTick();
    if (is_array($latest_tick['step_results']['release_cycle'] ?? NULL)) {
      return $this->derivedControlState(
        TRUE,
        $path,
        'Derived from the latest tick release_cycle step because the control artifact was not readable as structured JSON.'
      );
    }

    return $payload;
  }

  private function derivedControlState(bool $enabled, string $path, string $reason): array {
    $mtime = is_readable($path) ? @filemtime($path) : FALSE;
    return [
      'enabled' => $enabled,
      'updated_at' => $mtime !== FALSE ? gmdate('c', (int) $mtime) : '-',
      'updated_by' => 'derived',
      'reason' => $reason,
    ];
  }

  private function readTextFile(string $path): string {
    if ($path === '' || !is_readable($path)) {
      return '';
    }

    $raw = @file_get_contents($path);
    if ($raw === FALSE) {
      return '';
    }

    return trim((string) $raw);
  }

  private function readFeatureProgress(): array {
    $path = $this->paths->artifactPaths()['feature_progress'];
    $raw = $this->readTextFile($path);
    if ($raw === '') {
      return ['generated_at' => '', 'rows' => []];
    }

    $generated_at = '';
    $lines = preg_split('/\R/', $raw) ?: [];
    foreach ($lines as $line) {
      if (preg_match('/^Generated:\s*(.+)$/', trim((string) $line), $matches)) {
        $generated_at = trim((string) $matches[1]);
        break;
      }
    }

    $rows = [];
    for ($i = 0; $i < count($lines); $i++) {
      $line = trim((string) $lines[$i]);
      $next = trim((string) ($lines[$i + 1] ?? ''));
      if (!str_starts_with($line, '|') || !str_starts_with($next, '|')) {
        continue;
      }
      if (!preg_match('/^\|\s*-+/', $next)) {
        continue;
      }

      $headers = $this->parseMarkdownTableRow($line);
      for ($j = $i + 2; $j < count($lines); $j++) {
        $row_line = trim((string) $lines[$j]);
        if ($row_line === '' || !str_starts_with($row_line, '|')) {
          break;
        }
        $values = $this->parseMarkdownTableRow($row_line);
        if (count($values) !== count($headers)) {
          continue;
        }
        $rows[] = array_combine($headers, $values) ?: [];
      }
      break;
    }

    return [
      'generated_at' => $generated_at,
      'rows' => $rows,
    ];
  }

  private function parseMarkdownTableRow(string $line): array {
    $parts = explode('|', trim($line, '|'));
    return array_map(static fn(string $part): string => trim($part), $parts);
  }

  private function readReleaseCycleRows(): array {
    $dir = $this->paths->artifactPaths()['release_cycle_dir'];
    if (!is_dir($dir)) {
      return [];
    }

    $files = glob($dir . '/*.release_id') ?: [];
    sort($files);
    $rows = [];
    foreach ($files as $file) {
      $team = basename((string) $file, '.release_id');
      $current = trim((string) @file_get_contents((string) $file));
      $next_file = $dir . '/' . $team . '.next_release_id';
      $next = is_readable($next_file) ? trim((string) @file_get_contents($next_file)) : '';
      $rows[] = [$team, $current !== '' ? $current : '-', $next !== '' ? $next : '-', $this->toRelativePath($file)];
    }
    return $rows;
  }

  private function buildReleaseCoverageRows(array $release_rows): array {
    $notes = $this->listReleaseNotes();
    $signoffs = $this->listReleaseSignoffs();
    $note_ids = [];
    $signoff_counts = [];

    foreach ($notes as $note) {
      $note_ids[$note['release_id']] = TRUE;
    }
    foreach ($signoffs as $signoff) {
      $signoff_counts[$signoff['release_id']] = ($signoff_counts[$signoff['release_id']] ?? 0) + 1;
    }

    $rows = [];
    foreach ($release_rows as $row) {
      $release_id = (string) ($row[1] ?? '');
      if ($release_id === '' || $release_id === '-') {
        continue;
      }
      $rows[] = [
        $release_id,
        isset($note_ids[$release_id]) ? 'present' : 'missing',
        (string) ($signoff_counts[$release_id] ?? 0),
      ];
    }

    return $rows;
  }

  private function listReleaseNotes(): array {
    $sessions_dir = $this->paths->artifactPaths()['sessions_dir'];
    if (!is_dir($sessions_dir)) {
      return [];
    }

    $files = glob($sessions_dir . '/*/artifacts/release-candidates/*/05-release-notes.md') ?: [];
    usort($files, static fn(string $a, string $b): int => filemtime($b) <=> filemtime($a));

    $rows = [];
    foreach ($files as $file) {
      $text = $this->readTextFile($file);
      $rows[] = [
        'release_id' => $this->extractFrontMatterValue($text, ['Release id']) ?: basename(dirname($file)),
        'seat' => basename(dirname(dirname(dirname($file)))),
        'site' => $this->extractFrontMatterValue($text, ['Site']),
        'state' => $this->extractFrontMatterValue($text, ['State']),
        'updated_at' => gmdate('c', (int) filemtime($file)),
        'path' => $this->toRelativePath($file),
        'excerpt' => implode("\n", array_slice(preg_split('/\R/', $text) ?: [], 0, 40)),
      ];
    }

    return $rows;
  }

  private function listReleaseSignoffs(): array {
    $sessions_dir = $this->paths->artifactPaths()['sessions_dir'];
    if (!is_dir($sessions_dir)) {
      return [];
    }

    $files = glob($sessions_dir . '/*/artifacts/release-signoffs/*.md') ?: [];
    usort($files, static fn(string $a, string $b): int => filemtime($b) <=> filemtime($a));

    $rows = [];
    foreach ($files as $file) {
      $text = $this->readTextFile($file);
      $rows[] = [
        'release_id' => $this->extractFrontMatterValue($text, ['Release id']),
        'site' => $this->extractFrontMatterValue($text, ['Site']),
        'seat' => $this->extractFrontMatterValue($text, ['PM seat']) ?: basename(dirname(dirname(dirname($file)))),
        'signed_off_at' => $this->extractFrontMatterValue($text, ['Signed off at']) ?: gmdate('c', (int) filemtime($file)),
        'path' => $this->toRelativePath($file),
      ];
    }

    return $rows;
  }

  private function listActiveInboxItems(): array {
    $sessions_dir = $this->paths->artifactPaths()['sessions_dir'];
    if (!is_dir($sessions_dir)) {
      return [];
    }

    $items = [];
    foreach (glob($sessions_dir . '/*/inbox/*') ?: [] as $path) {
      if (!is_dir($path) || basename($path) === '_archived') {
        continue;
      }

      $seat = basename(dirname(dirname($path)));
      $item_id = basename($path);
      $roi = $this->readTextFile($path . '/roi.txt');
      $summary = $this->readItemSummary($path);
      $last_progress = is_file($path . '/.last-progress-at') ? filemtime($path . '/.last-progress-at') : filemtime($path);
      $status = is_file($path . '/.inwork') ? 'in_progress' : 'queued';
      $triage = $this->inferTriageLabel($item_id, $summary);

      $items[] = [
        'seat' => $seat,
        'item_id' => $item_id,
        'triage' => $triage,
        'status' => $status,
        'roi' => $roi !== '' ? $roi : '-',
        'age' => $this->formatAgeFromEpoch((int) $last_progress),
        'summary' => $summary,
        'path' => $this->toRelativePath($path),
        'sort_epoch' => (int) $last_progress,
      ];
    }

    usort($items, static function (array $a, array $b): int {
      if ($a['triage'] !== $b['triage']) {
        return strcmp((string) $a['triage'], (string) $b['triage']);
      }
      return ($a['sort_epoch'] ?? 0) <=> ($b['sort_epoch'] ?? 0);
    });

    return array_map(static function (array $item): array {
      unset($item['sort_epoch']);
      return $item;
    }, $items);
  }

  private function readItemSummary(string $item_dir): string {
    foreach (['command.md', 'README.md', '00-problem-statement.md'] as $candidate) {
      $path = $item_dir . '/' . $candidate;
      if (!is_readable($path)) {
        continue;
      }
      $lines = preg_split('/\R/', $this->readTextFile($path)) ?: [];
      foreach ($lines as $line) {
        $line = trim((string) $line);
        if ($line === '' || str_starts_with($line, '**From:**') || str_starts_with($line, '**To:**')) {
          continue;
        }
        return ltrim($line, "# \t");
      }
    }

    return '(no summary found)';
  }

  private function inferTriageLabel(string $item_id, string $summary): string {
    $haystack = strtolower($item_id . ' ' . $summary);
    if (str_contains($haystack, 'needs-') || str_contains($haystack, 'needs info')) {
      return 'needs-info';
    }
    if (str_contains($haystack, 'blocked') || str_contains($haystack, 'stagnation') || str_contains($haystack, 'awaiting')) {
      return 'blocked';
    }

    return 'active';
  }

  private function extractFrontMatterValue(string $text, array $labels): string {
    $lines = preg_split('/\R/', $text) ?: [];
    $normalized_labels = array_map(static fn(string $label): string => trim(str_replace('**', '', $label)), $labels);
    foreach ($lines as $line) {
      $normalized_line = trim(str_replace('**', '', (string) $line));
      foreach ($normalized_labels as $label) {
        $prefix = $label . ':';
        if (str_starts_with($normalized_line, $prefix)) {
          return trim(substr($normalized_line, strlen($prefix)));
        }
      }
    }

    return '';
  }

  private function countTickErrors(array $tick): int {
    $count = count((array) ($tick['errors'] ?? []));
    $steps = is_array($tick['step_results'] ?? NULL) ? $tick['step_results'] : [];
    foreach ($steps as $step) {
      if (is_array($step) && (($step['status'] ?? '') === 'error' || !empty($step['errors']) || isset($step['error']))) {
        $count++;
      }
    }
    return $count;
  }

  private function engineMode(array $tick): string {
    $engine_mode = trim((string) ($tick['engine_mode'] ?? ''));
    if ($engine_mode !== '') {
      return $engine_mode;
    }

    if (
      is_array($tick['step_results'] ?? NULL)
      || array_key_exists('dry_run', $tick)
      || array_key_exists('publish_enabled', $tick)
    ) {
      return 'langgraph';
    }

    return 'unknown';
  }

  private function formatAgeFromTimestamp(string $ts): string {
    if ($ts === '') {
      return 'unknown';
    }
    $value = strtotime($ts);
    if ($value === FALSE) {
      return 'unknown';
    }
    return (string) max(0, time() - $value) . 's';
  }

  private function timestampToEpoch(string $ts): ?int {
    if ($ts === '') {
      return NULL;
    }
    $value = strtotime($ts);
    return ($value === FALSE) ? NULL : (int) $value;
  }

  private function formatAgeFromEpoch(int $epoch): string {
    if ($epoch <= 0) {
      return 'unknown';
    }
    return (string) max(0, time() - $epoch) . 's';
  }

  private function toRelativePath(string $path): string {
    $forseti_root = rtrim($this->paths->forsetiRoot(), '/') . '/';
    if (str_starts_with($path, $forseti_root)) {
      return substr($path, strlen($forseti_root));
    }
    return $path;
  }

}
