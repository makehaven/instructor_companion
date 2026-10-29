<?php

declare(strict_types=1);

namespace Drupal\instructor_companion\Service;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\KeyValueStore\KeyValueFactoryInterface;
use Drupal\Core\Mail\MailManagerInterface;
use Drupal\Core\Url;
use Psr\Log\LoggerInterface;

/**
 * Tells the education team when an invited instructor's class is close and
 * setup is not finished.
 *
 * Nothing tied setup to the class date, so an instructor could reach "Happening
 * now" without a signed agreement or a way into the building. One email per
 * instructor per class, a week out, listing the open steps.
 */
class SetupDeadlineAlert {

  public const DAYS_BEFORE = 7;
  public const SENT_COLLECTION = 'instructor_companion.setup_deadline_alerted';

  public function __construct(
    protected InstructorSetupChecklist $checklist,
    protected KeyValueFactoryInterface $keyValueFactory,
    protected ConfigFactoryInterface $configFactory,
    protected MailManagerInterface $mailManager,
    protected TimeInterface $time,
    protected LoggerInterface $logger,
  ) {}

  /**
   * Sends any alerts due. Called from cron.
   */
  public function run(): int {
    $to = trim((string) $this->configFactory->get('instructor_companion.settings')->get('notification_email'));
    if ($to === '') {
      return 0;
    }
    $sent_store = $this->keyValueFactory->get(self::SENT_COLLECTION);
    $now = $this->time->getRequestTime();
    $sent = 0;

    foreach ($this->checklist->inProgress() as $uid => $record) {
      $class = $this->checklist->nextClass((int) $uid);
      if (!$class || !self::isDue($class['start'], $now)) {
        continue;
      }
      $key = $uid . ':' . $class['id'];
      if ($sent_store->get($key)) {
        continue;
      }
      $open = self::openSteps($record['steps']);
      if (!$open) {
        continue;
      }
      $result = $this->mailManager->mail('instructor_companion', 'setup_deadline_alert', $to, 'en', [
        'instructor_name' => $record['user']->getDisplayName(),
        'event_title' => $class['title'],
        'event_start' => date('D, M j \a\t g:ia', strtotime($class['start'])),
        'open_steps' => $open,
        'console_url' => Url::fromRoute('instructor_companion.education_console', [], ['absolute' => TRUE])->toString(),
      ]);
      if (!empty($result['result'])) {
        $sent_store->set($key, $now);
        $sent++;
      }
      else {
        $this->logger->warning('Setup deadline alert for uid @uid (event @eid) did not send.', ['@uid' => $uid, '@eid' => $class['id']]);
      }
    }
    return $sent;
  }

  /**
   * Whether a class starting at $start (local Y-m-d H:i:s) is within the window.
   */
  public static function isDue(string $start, int $now): bool {
    $ts = strtotime($start);
    return $ts !== FALSE && $ts >= $now && $ts - $now <= self::DAYS_BEFORE * 86400;
  }

  /**
   * Titles of the steps still to do, skipping ones with nothing to do yet.
   *
   * @return string[]
   *   Step titles.
   */
  public static function openSteps(array $steps): array {
    $open = [];
    foreach ($steps as $step) {
      if (empty($step['done'])) {
        $open[] = (string) $step['title'];
      }
    }
    return $open;
  }

}
