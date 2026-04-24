<?php

namespace Drupal\drupal_langgraph\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\DependencyInjection\ContainerInjectionInterface;
use Drupal\Core\Url;
use Drupal\drupal_langgraph\Service\PipelineStatusResolver;
use Drupal\drupal_langgraph\Service\ProjectRegistryService;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

final class RoadmapController extends ControllerBase implements ContainerInjectionInterface {

  public function __construct(
    private readonly ProjectRegistryService $registry,
    private readonly PipelineStatusResolver $pipeline,
  ) {}

  public static function create(ContainerInterface $container): self {
    return new self(
      $container->get('drupal_langgraph.project_registry'),
      $container->get('drupal_langgraph.pipeline_status_resolver'),
    );
  }

  public function roadmap(): array {
    $projects = [];

    foreach ($this->registry->roadmapCards() as $card) {
      $project_id = $card['project_id'];
      $actions = [];

      if (($card['product'] ?? '') === 'dungeoncrawler') {
        $actions[] = [
          'url' => 'https://dungeoncrawler.forseti.life/roadmap',
          'text' => (string) $this->t('Open Dungeoncrawler roadmap'),
          'style' => 'primary',
        ];
      }
      elseif (($card['product'] ?? '') === 'forseti.life') {
        $actions[] = [
          'url' => Url::fromRoute('drupal_langgraph.roadmap_project', ['project_id' => $project_id])->toString(),
          'text' => (string) $this->t('Open project roadmap'),
          'style' => 'primary',
        ];
      }

      foreach ($this->buildReleaseNavigationLinks((string) ($card['last_scoped_release'] ?? '')) as $link) {
        $actions[] = $link;
      }

      $projects[] = [
        'title' => $project_id . ' - ' . ($card['name'] ?? $project_id),
        'status' => $this->registry->formatRoadmapStatus((string) ($card['status'] ?? ''), (string) ($card['priority'] ?? '')),
        'summary' => $card['summary'] ?? '',
        'meta' => implode(' • ', array_filter([
          'Type: ' . ($card['type'] ?? ''),
          'Product: ' . ($card['product'] ?? ''),
          'PM: ' . ($card['project_manager'] ?? ''),
          'Lead: ' . ($card['lead'] ?? ''),
          'Started: ' . ($card['started'] ?? ''),
          !empty($card['last_scoped_release']) ? 'Last release: ' . $card['last_scoped_release'] : '',
          !empty($card['progress_sla']) ? 'Progress: ' . $this->registry->summarizeProgressStatus((string) ($card['last_scoped_release'] ?? ''), (string) ($card['progress_sla'] ?? ''), (string) ($card['queue_status'] ?? '')) : '',
        ])),
        'roadmap' => $card['roadmap'] ?? [],
        'actions' => $actions,
        'pipeline_counts' => $this->pipeline->getProjectCounts($project_id),
      ];
    }

    return [
      '#theme' => 'drupal_langgraph_roadmap',
      '#title' => $this->t('Project Roadmaps'),
      '#intro' => $this->t('Drupal LangGraph exposes the authoritative portfolio registry and links it to live feature-pipeline evidence so roadmap pages stay grounded in execution.'),
      '#projects' => $projects,
      '#cache' => [
        'max-age' => 300,
        'contexts' => ['url'],
      ],
    ];
  }

  public function roadmapProject(string $project_id) {
    $project = $this->registry->loadProject($project_id);
    if ($project === NULL) {
      throw new NotFoundHttpException();
    }

    if (($project['product'] ?? '') === 'dungeoncrawler') {
      return new RedirectResponse('https://dungeoncrawler.forseti.life/roadmap');
    }

    return [
      '#theme' => 'drupal_langgraph_roadmap_project',
      '#title' => $project['project_id'] . ' - ' . $project['name'],
      '#project_id' => $project['project_id'],
      '#summary' => $project['scope'] ?: ($project['problem'] ?: $this->t('Portfolio initiative tracked in the HQ project registry.')),
      '#meta' => [
        'Type' => $project['type'] ?? '',
        'Status' => $project['status'] ?? '',
        'Priority' => $project['priority'] ?? '',
        'PM' => $project['project_manager'] ?? '',
        'Lead' => $project['lead'] ?? '',
        'Started' => $project['started'] ?? '',
        'Product' => $project['product'] ?? '',
        'Last scoped release' => $project['last_scoped_release'] ?? '',
        'Progress SLA' => $project['progress_sla'] ?? '',
        'Progress status' => $this->registry->summarizeProgressStatus((string) ($project['last_scoped_release'] ?? ''), (string) ($project['progress_sla'] ?? ''), (string) ($project['queue_status'] ?? '')),
      ],
      '#roadmap_reference' => $project['roadmap_reference'] ?? '',
      '#current_state' => $project['current_state'] ?? '',
      '#next_step' => $project['next_step'] ?? '',
      '#queue_status' => $project['queue_status'] ?? '',
      '#goals' => $project['goals'] ?? [],
      '#nav_links' => array_merge(
        [[
          'url' => Url::fromRoute('drupal_langgraph.roadmap')->toString(),
          'text' => (string) $this->t('Back to all roadmaps'),
          'style' => 'primary',
        ]],
        $this->buildReleaseNavigationLinks((string) ($project['last_scoped_release'] ?? ''))
      ),
      '#pipeline_counts' => $this->pipeline->getProjectCounts($project['project_id']),
      '#feature_groups' => $this->pipeline->getProjectFeatureGroups($project['project_id']),
      '#cache' => [
        'max-age' => 300,
        'contexts' => ['url'],
      ],
    ];
  }

  private function buildReleaseNavigationLinks(string $release_id): array {
    $release_id = trim($release_id);
    if ($release_id === '' || !$this->currentUser()->hasPermission('administer drupal langgraph')) {
      return [];
    }

    return [[
      'url' => Url::fromRoute('drupal_langgraph.langgraph_console_release', [], ['query' => ['release_id' => $release_id]])->toString(),
      'text' => (string) $this->t('Release console'),
      'style' => 'secondary',
    ]];
  }
}
