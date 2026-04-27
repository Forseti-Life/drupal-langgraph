<?php

namespace Drupal\drupal_langgraph\Form;

use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\drupal_langgraph\Service\ControlPlaneArtifactService;
use Symfony\Component\DependencyInjection\ContainerInterface;

final class ProcessFlowReleaseActionForm extends FormBase {

  use ProcessFlowUiTextTrait;

  public function __construct(
    private readonly ControlPlaneArtifactService $artifacts,
  ) {}

  public static function create(ContainerInterface $container): self {
    return new self(
      $container->get('drupal_langgraph.control_plane_artifacts'),
    );
  }

  public function getFormId(): string {
    return 'drupal_langgraph_process_flow_release_action_form';
  }

  public function buildForm(array $form, FormStateInterface $form_state, array $flow = [], string $subsection = ''): array {
    $form_state->set('flow', $flow);
    $form_state->set('subsection', $subsection);

    $form['intro'] = $this->formStatusMessage(
      $subsection === 'versions' ? 'Create version snapshot' : 'Submit promotion request',
      $subsection === 'versions'
        ? 'Record a version snapshot from the current flow contract so Release keeps an auditable history.'
        : 'Submit a promotion request for an existing version snapshot.',
      $subsection === 'versions'
        ? ['Version snapshots capture flow metadata, structure, and prompt notes at the time you save them.']
        : ['Promotion requests are validated against release-cycle control before promoted-version state is updated.']
    );

    if ($subsection === 'versions') {
      $form['version_id'] = [
        '#type' => 'textfield',
        '#title' => $this->t('Version ID'),
        '#default_value' => (string) ($flow['version'] ?? ''),
        '#description' => $this->versionFieldDescription(),
        '#required' => TRUE,
      ];
      $form['summary'] = [
        '#type' => 'textarea',
        '#title' => $this->t('Summary'),
        '#description' => $this->t('Capture what changed so this snapshot can be understood without opening the full artifact payload.'),
        '#rows' => 3,
      ];
    }

    if ($subsection === 'promote') {
      $form['version_id'] = [
        '#type' => 'select',
        '#title' => $this->t('Version to promote'),
        '#description' => $this->t('Choose from the currently recorded version snapshots for this flow.'),
        '#options' => $this->versionOptions($flow),
        '#required' => TRUE,
      ];
      $form['reason'] = [
        '#type' => 'textarea',
        '#title' => $this->t('Promotion reason'),
        '#description' => $this->t('Explain why this version is ready so release audit trails stay meaningful.'),
        '#rows' => 3,
        '#required' => TRUE,
      ];
    }

    $form['actions'] = ['#type' => 'actions'];
    $form['actions']['submit'] = [
      '#type' => 'submit',
      '#value' => $this->t($subsection === 'versions' ? 'Create version snapshot' : 'Submit promotion request'),
      '#button_type' => 'primary',
    ];

    return $form;
  }

  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $flow = (array) $form_state->get('flow');
    $flow_id = (string) ($flow['id'] ?? '');
    if ($flow_id === '') {
      throw new \RuntimeException('Release action form is missing the target flow identifier.');
    }
    $subsection = (string) $form_state->get('subsection');

    if ($subsection === 'versions') {
      $version_id = (string) $form_state->getValue('version_id');
      $record = $this->artifacts->createVersionSnapshot($flow, $this->currentUser()->getAccountName(), $version_id, (string) $form_state->getValue('summary'));
      $this->messenger()->addStatus($this->t('Created version snapshot %version for %flow. Request ID: %request_id.', [
        '%version' => $version_id,
        '%flow' => $record['flow_label'] !== '' ? $record['flow_label'] : $record['flow_id'],
        '%request_id' => $record['request_id'],
      ]));
    }

    if ($subsection === 'promote') {
      $record = $this->artifacts->submitPromotionRequest(
        $flow,
        $this->currentUser()->getAccountName(),
        (string) $form_state->getValue('version_id'),
        (string) $form_state->getValue('reason')
      );
      $this->messenger()->addStatus($this->t('Submitted a promotion request for version %version. Request ID: %request_id.', [
        '%version' => $record['version_id'],
        '%request_id' => $record['request_id'],
      ]));
    }

    $form_state->setRedirect('drupal_langgraph.langgraph_console_flow_workspace_subsection', [
      'flow_id' => $flow_id,
      'section' => 'release',
      'subsection' => $subsection,
    ]);
  }

  private function versionOptions(array $flow): array {
    return $this->artifacts->versionOptions($flow);
  }

}
