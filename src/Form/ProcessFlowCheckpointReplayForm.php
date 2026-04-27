<?php

namespace Drupal\drupal_langgraph\Form;

use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\drupal_langgraph\Service\ControlPlaneArtifactService;
use Symfony\Component\DependencyInjection\ContainerInterface;

final class ProcessFlowCheckpointReplayForm extends FormBase {

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
    return 'drupal_langgraph_process_flow_checkpoint_replay_form';
  }

  public function buildForm(array $form, FormStateInterface $form_state, array $flow = [], array $checkpoint_candidates = []): array {
    $form_state->set('flow', $flow);
    $form_state->set('checkpoint_candidates', $checkpoint_candidates);

    $options = [];
    foreach ($checkpoint_candidates as $checkpoint_id => $candidate) {
      $options[$checkpoint_id] = (string) ($candidate['label'] ?? $checkpoint_id);
    }

    $form['intro'] = $this->formStatusMessage(
      'Submit checkpoint replay request',
      'Select a checkpoint artifact and record a replay request for this flow.',
      [
        'Replay requests are stored in the shared control-plane artifact model for the replay worker.',
        'The current backend performs an artifact-referenced rerun, not a native checkpoint state restore.',
      ]
    );

    $form['checkpoint_id'] = [
      '#type' => 'select',
      '#title' => $this->t('Checkpoint artifact'),
      '#description' => $this->t('Choose the checkpoint evidence you want the replay worker to reference.'),
      '#options' => $options,
      '#required' => TRUE,
    ];

    $form['reason'] = [
      '#type' => 'textarea',
      '#title' => $this->t('Replay reason'),
      '#description' => $this->t('Explain what you are validating or rechecking so replay history remains auditable.'),
      '#rows' => 4,
      '#required' => TRUE,
    ];

    $form['actions'] = ['#type' => 'actions'];
    $form['actions']['submit'] = [
      '#type' => 'submit',
      '#value' => $this->t('Submit replay request'),
      '#button_type' => 'primary',
    ];

    return $form;
  }

  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $flow = (array) $form_state->get('flow');
    $flow_id = (string) ($flow['id'] ?? '');
    if ($flow_id === '') {
      throw new \RuntimeException('Replay request form is missing the target flow identifier.');
    }
    $checkpoint_candidates = (array) $form_state->get('checkpoint_candidates');
    $checkpoint_id = (string) $form_state->getValue('checkpoint_id');
    $checkpoint_path = (string) (($checkpoint_candidates[$checkpoint_id]['path'] ?? ''));

    $record = $this->artifacts->submitReplayRequest(
      $flow,
      $this->currentUser()->getAccountName(),
      $checkpoint_id,
      $checkpoint_path,
      (string) $form_state->getValue('reason')
    );

    $this->messenger()->addStatus($this->t('Submitted replay request %request_id for checkpoint %checkpoint.', [
      '%request_id' => $record['request_id'],
      '%checkpoint' => $checkpoint_id,
    ]));

    $form_state->setRedirect('drupal_langgraph.langgraph_console_flow_workspace_subsection', [
      'flow_id' => $flow_id,
      'section' => 'test',
      'subsection' => 'checkpoints',
    ]);
  }

  public function validateForm(array &$form, FormStateInterface $form_state): void {
    $checkpoint_candidates = (array) $form_state->get('checkpoint_candidates');
    $checkpoint_id = (string) $form_state->getValue('checkpoint_id');
    $checkpoint_path = (string) (($checkpoint_candidates[$checkpoint_id]['path'] ?? ''));

    if ($checkpoint_id === '' || $checkpoint_path === '') {
      $form_state->setErrorByName('checkpoint_id', $this->t('Select a valid checkpoint artifact.'));
      return;
    }

    if (!is_file($checkpoint_path)) {
      $form_state->setErrorByName('checkpoint_id', $this->t('The selected checkpoint artifact is no longer available on disk.'));
    }
  }

}
