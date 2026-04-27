<?php

namespace Drupal\drupal_langgraph\Controller;

use Drupal\Core\Access\AccessResult;
use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\DependencyInjection\ContainerInjectionInterface;
use Drupal\Core\Link;
use Drupal\Core\Render\Markup;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\Url;
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
  ) {}

  public static function create(ContainerInterface $container): self {
    return new self(
      $container->get('drupal_langgraph.path_manager'),
      $container->get('drupal_langgraph.observe_data'),
      $container->get('drupal_langgraph.process_flow_registry'),
      $container->get('drupal_langgraph.process_flow_context'),
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
    $paths = $this->paths->artifactPaths();
    $latest_tick = $this->readLatestTick();
    $parity = $this->readParity();
    $org_control = $this->readOrgControl();
    $release_control = $this->readReleaseControl();
    $step_results = is_array($latest_tick['step_results'] ?? NULL) ? $latest_tick['step_results'] : [];
    $tick_ts = (string) ($latest_tick['ts'] ?? '');
    $tick_age = $this->formatAgeFromTimestamp($tick_ts);
    $tick_epoch = $this->timestampToEpoch($tick_ts);
    $tick_age_seconds = ($tick_epoch !== NULL) ? max(0, time() - $tick_epoch) : NULL;
    $incident_rows = $this->observe->incidentRows(5);
    $parity_ok = isset($parity['parity_ok']) ? (bool) $parity['parity_ok'] : NULL;
    $engine_mode = (string) ($latest_tick['engine_mode'] ?? 'unknown');
    $runtime_health = $this->overviewRuntimeHealth($tick_age_seconds, $parity_ok, $engine_mode);
    $freshness = $this->overviewFreshness($tick_age_seconds, $tick_age);
    $automation = $this->overviewAutomationState($org_control, $release_control);
    $next_action = $this->overviewNextAction($tick_age_seconds, $org_control, $release_control, $parity_ok, $incident_rows);
    $exception_rows = $this->overviewExceptionRows($tick_age_seconds, $tick_age, $parity_ok, $engine_mode, $org_control, $release_control, $incident_rows);

    $build = $this->buildPage('Overview', 'Operator dashboard for the LangGraph control plane.', [], FALSE);
    $build['summary'] = [
      '#type' => 'table',
      '#header' => [
        $this->t('Runtime health'),
        $this->t('Data freshness'),
        $this->t('Automation state'),
        $this->t('Active alerts'),
      ],
      '#rows' => [[
        $runtime_health,
        $freshness,
        $automation,
        (string) count($incident_rows),
      ]],
    ];
    $build['actions'] = [
      '#type' => 'container',
      'flows' => [
        '#type' => 'link',
        '#title' => $this->t('Open Flows'),
        '#url' => Url::fromRoute('drupal_langgraph.langgraph_console_flows'),
        '#attributes' => ['class' => ['button', 'button--primary']],
      ],
      'new_flow' => [
        '#type' => 'link',
        '#title' => $this->t('New Process Flow'),
        '#url' => Url::fromRoute('drupal_langgraph.langgraph_console_flow_add'),
        '#attributes' => ['class' => ['button']],
      ],
      'alerts' => [
        '#type' => 'link',
        '#title' => $this->t('Observe Alerts'),
        '#url' => Url::fromRoute('drupal_langgraph.langgraph_console_subsection', ['section' => 'observe', 'subsection' => 'alerts']),
        '#attributes' => ['class' => ['button']],
      ],
      'release' => [
        '#type' => 'link',
        '#title' => $this->t('Release Status'),
        '#url' => Url::fromRoute('drupal_langgraph.langgraph_console_release'),
        '#attributes' => ['class' => ['button']],
      ],
      'help' => ['#markup' => '<p>' . $this->t('Start from flows when working on a specific process flow, or jump directly to alerts and release state when triaging the control plane.') . '</p>'],
    ];
    if ($current_flow = $this->selectedFlow()) {
      $build['current_flow'] = $this->currentFlowDetailsBuild($current_flow);
    }
    $build['exceptions'] = $this->tableDetails('Needs Attention', ['Issue', 'Current state', 'Recommended action'], $exception_rows);
    $build['recent_activity'] = $this->tableDetails('Recent Activity & Next Step', ['Signal', 'Value'], [
      ['Latest tick timestamp', $tick_ts !== '' ? $tick_ts : 'unavailable'],
      ['Latest tick age', $tick_age],
      ['Latest incident', isset($incident_rows[0]) ? (($incident_rows[0]['severity'] ?? 'unknown') . ': ' . ($incident_rows[0]['summary'] ?? '')) : 'No recent incidents'],
      ['Recommended next step', $next_action],
    ]);
    $build['live_status'] = $this->tableDetails('Live Runtime Status', ['Signal', 'Current Value', 'Source'], [
      ['Latest tick timestamp', (string) ($latest_tick['ts'] ?? 'unavailable'), $this->toRelativePath($paths['ticks'])],
      ['Latest tick age', $tick_age, 'derived from latest tick timestamp'],
      ['Engine mode', (string) ($latest_tick['engine_mode'] ?? 'unknown'), $this->toRelativePath($paths['ticks'])],
      ['Provider', (string) ($latest_tick['provider'] ?? 'unknown'), $this->toRelativePath($paths['ticks'])],
      ['dry_run', $this->boolLabel($latest_tick['dry_run'] ?? NULL), $this->toRelativePath($paths['ticks'])],
      ['publish_enabled', $this->boolLabel($latest_tick['publish_enabled'] ?? NULL), $this->toRelativePath($paths['ticks'])],
      ['Org automation enabled', $this->boolLabel($org_control['enabled'] ?? NULL), $this->toRelativePath($this->activeControlPath('ORG_CONTROL_FILE', 'org_control_default', 'org_control_legacy'))],
      ['Release-cycle enabled', $this->boolLabel($release_control['enabled'] ?? NULL), $this->toRelativePath($this->activeControlPath('RELEASE_CYCLE_CONTROL_FILE', 'release_control_default', 'release_control_legacy'))],
      ['Parity health', isset($parity['parity_ok']) ? ((bool) $parity['parity_ok'] ? 'PASS' : 'FAIL') : 'unknown', $this->toRelativePath($paths['parity'])],
      ['Latest step count', (string) count($step_results), $this->toRelativePath($paths['ticks'])],
    ]);
    $build['controls'] = $this->tableDetails('Management Controls', ['Control', 'Enabled', 'Updated at', 'Updated by', 'Reason'], [
      [
        'Org automation',
        $this->boolLabel($org_control['enabled'] ?? NULL),
        (string) ($org_control['updated_at'] ?? '-'),
        (string) ($org_control['updated_by'] ?? '-'),
        (string) ($org_control['reason'] ?? '-'),
      ],
      [
        'Release-cycle automation',
        $this->boolLabel($release_control['enabled'] ?? NULL),
        (string) ($release_control['updated_at'] ?? '-'),
        (string) ($release_control['updated_by'] ?? '-'),
        (string) ($release_control['reason'] ?? '-'),
      ],
    ]);

    return $build;
  }

  public function flows(): array {
    $build = $this->buildPage('Flows', 'Registry and control panel for all process flows managed by Drupal LangGraph.', $this->buildSectionRows('flows'));
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
    $flow = $this->flows->getFlow($flow_id);
    if ($flow === NULL) {
      throw new NotFoundHttpException();
    }
    $this->flowContext->setCurrentFlowId($flow_id);

    return [
      '#type' => 'container',
      '#cache' => ['max-age' => 0],
      'title' => ['#markup' => '<h2>' . $this->t('@flow', ['@flow' => $flow['label']]) . '</h2>'],
      'description' => ['#markup' => '<p>' . $this->t('@description', ['@description' => $flow['description']]) . '</p>'],
      'actions' => [
        '#markup' => '<p>' .
          Link::fromTextAndUrl($this->t('Back to Flows'), Url::fromRoute('drupal_langgraph.langgraph_console_flows'))->toString() .
          ' | ' .
          Link::fromTextAndUrl($this->t('Build'), Url::fromRoute('drupal_langgraph.langgraph_console_build'))->toString() .
          ' | ' .
          Link::fromTextAndUrl($this->t('Test'), Url::fromRoute('drupal_langgraph.langgraph_console_test'))->toString() .
          ' | ' .
          Link::fromTextAndUrl($this->t('Run'), Url::fromRoute('drupal_langgraph.langgraph_console_run'))->toString() .
          ' | ' .
          Link::fromTextAndUrl($this->t('Observe'), Url::fromRoute('drupal_langgraph.langgraph_console_observe'))->toString() .
          ' | ' .
          Link::fromTextAndUrl($this->t('Release'), Url::fromRoute('drupal_langgraph.langgraph_console_release'))->toString() .
          ' | ' .
          Link::fromTextAndUrl($this->t('Create new process flow'), Url::fromRoute('drupal_langgraph.langgraph_console_flow_add'))->toString() .
          '</p>',
      ],
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
      'command_map' => $this->tableDetails('Mapped Console Controls', ['LangGraph command', 'Console control', 'Section'], $this->flows->commandControlMap()),
    ];
  }

  public function build(): array {
    $latest_tick = $this->readLatestTick();
    $step_results = is_array($latest_tick['step_results'] ?? NULL) ? $latest_tick['step_results'] : [];
    $nodes = array_values(array_filter(array_keys($step_results), static fn($key) => $key !== 'summarize_tick'));
    sort($nodes);
    $rows = $nodes ? array_map(fn(string $node): array => [$node, 'Observed in latest tick'], $nodes) : [['(none)', 'No step_results found in latest tick artifact.']];

    $build = $this->buildPage('Build', 'Design-time view over graph topology and structure evidence.', $this->buildSectionRows('build'));
    $build['graph_shape'] = $this->tableDetails('Observed Graph Shape', ['Node', 'Observation'], $rows, $this->toRelativePath($this->paths->artifactPaths()['ticks']));
    return $build;
  }

  public function test(): array {
    $parity = $this->readParity();
    $errors = is_array($parity['errors'] ?? NULL) ? $parity['errors'] : [];
    $rows = [
      ['parity_ok', isset($parity['parity_ok']) ? ((bool) $parity['parity_ok'] ? 'PASS' : 'FAIL') : 'unknown'],
      ['selected_agents.match', isset($parity['selected_agents']['match']) ? ((bool) $parity['selected_agents']['match'] ? 'yes' : 'no') : 'unknown'],
      ['steps.match', isset($parity['steps']['match']) ? ((bool) $parity['steps']['match'] ? 'yes' : 'no') : 'unknown'],
      ['generated_at', (string) ($parity['generated_at'] ?? 'unknown')],
      ['errors', $errors ? implode('; ', array_map('strval', $errors)) : '(none)'],
    ];

    $build = $this->buildPage('Test', 'Validation view over parity and correctness evidence.', $this->buildSectionRows('test'));
    $build['parity_evidence'] = $this->tableDetails('Current Validation Evidence', ['Field', 'Value'], $rows, $this->toRelativePath($this->paths->artifactPaths()['parity']));
    return $build;
  }

  public function run(): array {
    $rows = [];
    $ticks = array_slice($this->readTicks(), -25);
    foreach (array_reverse($ticks) as $tick) {
      $rows[] = [
        (string) ($tick['ts'] ?? ''),
        $this->formatAgeFromTimestamp((string) ($tick['ts'] ?? '')),
        (string) ($tick['engine_mode'] ?? 'unknown'),
        (string) ($tick['provider'] ?? ''),
        (string) ($tick['agent_cap'] ?? ''),
        (string) $this->countTickErrors($tick),
      ];
    }

    $build = $this->buildPage('Run', 'Execution-plane timeline for recent LangGraph activity.', $this->buildSectionRows('run'));
    $build['run_timeline'] = $this->tableDetails('Recent Runs Timeline', ['Timestamp', 'Age', 'Engine mode', 'Provider', 'Agent cap', 'Error count'], $rows, $this->toRelativePath($this->paths->artifactPaths()['ticks']));
    return $build;
  }

  public function observe(): array {
    $build = $this->buildPage('Observe', 'Observability view over node diagnostics and runtime behavior.', $this->buildSectionRows('observe'));
    $build['overview'] = $this->tableDetails('Observe Overview', ['Signal', 'Current Value'], $this->observe->overviewSummary());
    $build['metrics'] = $this->tableDetails('Latest Runtime Metrics', ['Metric', 'Value'], $this->buildObserveMetricRows($this->observe->metricSummary()), $this->toRelativePath($this->paths->artifactPaths()['ticks']));
    $build['incidents'] = $this->tableDetails('Recent Incidents', ['Timestamp', 'Severity', 'Category', 'Seat', 'Summary'], $this->buildObserveIncidentRows(array_slice($this->observe->incidentRows(), 0, 10)));
    return $build;
  }

  public function observeTraces(): array {
    $rows = $this->observe->nodeTraceRows();
    $build = [
      '#type' => 'container',
      '#cache' => ['max-age' => 0],
      'title' => ['#markup' => '<h2>' . $this->t('Observe: Node Traces') . '</h2>'],
      'description' => ['#markup' => '<p>' . $this->t('Latest step-level trace evidence from the LangGraph tick stream.') . '</p>'],
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

  public function release(): array {
    $release_control = $this->readReleaseControl();
    $release_rows = $this->readReleaseCycleRows();
    $coverage_rows = $this->buildReleaseCoverageRows($release_rows);

    $build = $this->buildPage('Release', 'Release-cycle and promotion posture for the control plane.', $this->buildSectionRows('release'));
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

    $build = $this->buildPage('Feature Progress', 'Read-only workflow snapshot sourced from the HQ feature progress dashboard.', []);
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

    $build = $this->buildPage('Admin', 'Path contracts and artifact health for the consolidated HQ module.', $this->buildSectionRows('admin'));
    $build['roots'] = $this->tableDetails('Runtime Roots', ['Root', 'Resolved path'], [
      ['FORSETI_ROOT', $this->paths->forsetiRoot()],
      ['COPILOT_HQ_ROOT', $this->paths->hqRuntimeRoot()],
    ]);
    $build['artifact_health'] = $this->tableDetails('Artifact Health', ['Artifact', 'Path', 'Exists', 'Readable', 'Size'], $rows);
    return $build;
  }

  public function adminRuntimeRoots(): array {
    return $this->withCurrentFlowContext([
      '#type' => 'container',
      '#cache' => ['max-age' => 0],
      'title' => ['#markup' => '<h2>' . $this->t('Admin: Runtime Roots') . '</h2>'],
      'description' => ['#markup' => '<p>' . $this->t('Resolved filesystem roots used by Drupal LangGraph when reading HQ-managed artifacts.') . '</p>'],
      'roots' => $this->tableDetails('Runtime Roots', ['Root', 'Resolved path'], [
        ['FORSETI_ROOT', $this->paths->forsetiRoot()],
        ['COPILOT_HQ_ROOT', $this->paths->hqRuntimeRoot()],
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

  private function buildPage(string $title, string $description, array $sections, bool $include_flow_context = TRUE): array {
    $build = [
      '#type' => 'container',
      '#cache' => ['max-age' => 0],
      'title' => ['#markup' => '<h2>' . $this->t($title) . '</h2>'],
      'description' => ['#markup' => '<p>' . $this->t($description) . '</p>'],
    ];

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
          '#markup' => Markup::create(Link::fromTextAndUrl($this->t('Open'), Url::fromRoute('drupal_langgraph.langgraph_console_flow_detail', ['flow_id' => $flow['id']]))->toString()),
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
          Link::fromTextAndUrl($this->t('Open flow'), Url::fromRoute('drupal_langgraph.langgraph_console_flow_detail', ['flow_id' => $current_flow['id']]))->toString() .
          ' | ' .
          Link::fromTextAndUrl($this->t('Flows registry'), Url::fromRoute('drupal_langgraph.langgraph_console_flows'))->toString() .
          '</p>',
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

  private function overviewExceptionRows(?int $tick_age_seconds, string $tick_age, ?bool $parity_ok, string $engine_mode, array $org_control, array $release_control, array $incidents): array {
    $rows = [];
    if ($tick_age_seconds === NULL || $tick_age_seconds > 3600) {
      $rows[] = ['Tick freshness', $tick_age_seconds === NULL ? 'Unknown freshness' : 'Stale: ' . $tick_age, 'Open Observe and investigate tick cadence.'];
    }
    if ($parity_ok === FALSE) {
      $rows[] = ['Parity health', 'FAIL', 'Open Test and review parity evidence.'];
    }
    if ($engine_mode === 'unknown') {
      $rows[] = ['Engine mode', 'unknown', 'Verify runtime artifacts and engine-mode emission.'];
    }
    if (($org_control['enabled'] ?? NULL) === NULL || ($release_control['enabled'] ?? NULL) === NULL) {
      $rows[] = ['Automation controls', 'Unknown control state', 'Validate control artifacts and admin/runtime roots.'];
    }
    if ($incidents !== []) {
      $rows[] = ['Recent incidents', (string) count($incidents) . ' incident(s) in recent history', 'Open Observe Alerts for incident detail.'];
    }
    if ($rows === []) {
      $rows[] = ['No critical exceptions', 'Overview signals are within expected range.', 'Open Flows or Observe for deeper work.'];
    }
    return $rows;
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
    return $this->readJsonFile($this->activeControlPath('ORG_CONTROL_FILE', 'org_control_default', 'org_control_legacy'));
  }

  private function readReleaseControl(): array {
    return $this->readJsonFile($this->activeControlPath('RELEASE_CYCLE_CONTROL_FILE', 'release_control_default', 'release_control_legacy'));
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
