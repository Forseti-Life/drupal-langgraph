<?php

namespace Drupal\drupal_langgraph\Form;

use Drupal\Core\Form\ConfirmFormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Url;
use Drupal\drupal_langgraph\Service\ProcessFlowRegistryService;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

final class ProcessFlowArchiveForm extends ConfirmFormBase {

  private array $flow = [];

  public function __construct(
    private readonly ProcessFlowRegistryService $registry,
  ) {}

  public static function create(ContainerInterface $container): self {
    return new self(
      $container->get('drupal_langgraph.process_flow_registry'),
    );
  }

  public function getFormId(): string {
    return 'drupal_langgraph_process_flow_archive_form';
  }

  public function buildForm(array $form, FormStateInterface $form_state, string $flow_id = ''): array {
    $flow = $this->registry->getFlow($flow_id);
    if ($flow === NULL || !$this->registry->canArchiveFlow($flow)) {
      throw new NotFoundHttpException();
    }

    $this->flow = $flow;

    $form['notice'] = [
      '#markup' => '<p>' . $this->t('Archiving hides this custom flow from active authoring without deleting its saved contract or version history. You can later re-activate it from the metadata editor if needed.') . '</p>',
    ];

    return parent::buildForm($form, $form_state);
  }

  public function getQuestion(): string {
    return (string) $this->t('Archive process flow %label?', [
      '%label' => (string) ($this->flow['label'] ?? $this->flow['id'] ?? 'flow'),
    ]);
  }

  public function getDescription(): string {
    return (string) $this->t('This marks the flow status as archived.');
  }

  public function getConfirmText(): string {
    return (string) $this->t('Archive flow');
  }

  public function getCancelUrl(): Url {
    return Url::fromRoute('drupal_langgraph.langgraph_console_flow_detail', [
      'flow_id' => (string) ($this->flow['id'] ?? ''),
    ]);
  }

  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $flow = $this->registry->archiveFlow((string) ($this->flow['id'] ?? ''));
    if ($flow === NULL) {
      throw new NotFoundHttpException();
    }

    $this->messenger()->addStatus($this->t('Archived process flow %label.', [
      '%label' => (string) ($flow['label'] ?? $flow['id']),
    ]));

    $form_state->setRedirect('drupal_langgraph.langgraph_console_flow_detail', [
      'flow_id' => (string) ($flow['id'] ?? ''),
    ]);
  }

}
