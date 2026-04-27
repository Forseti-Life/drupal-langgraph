<?php

namespace Drupal\drupal_langgraph\Service;

use Drupal\Core\Session\AccountProxyInterface;
use Drupal\user\UserDataInterface;

final class ProcessFlowContextService {

  private const MODULE = 'drupal_langgraph';
  private const KEY = 'selected_process_flow';

  public function __construct(
    private readonly UserDataInterface $userData,
    private readonly AccountProxyInterface $currentUser,
  ) {}

  public function getCurrentFlowId(): ?string {
    $uid = (int) $this->currentUser->id();
    $value = $this->userData->get(self::MODULE, $uid, self::KEY);
    return is_string($value) && $value !== '' ? $value : NULL;
  }

  public function setCurrentFlowId(string $flow_id): void {
    $uid = (int) $this->currentUser->id();
    $this->userData->set(self::MODULE, $uid, self::KEY, $flow_id);
  }

}
