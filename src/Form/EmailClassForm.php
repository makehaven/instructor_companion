<?php

namespace Drupal\instructor_companion\Form;

use Drupal\Component\Utility\EmailValidatorInterface;
use Drupal\Core\Access\AccessResult;
use Drupal\Core\Database\Connection;
use Drupal\Core\Flood\FloodInterface;
use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Mail\MailManagerInterface;
use Drupal\Core\Routing\RouteMatchInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\Url;
use Drupal\instructor_companion\Controller\ClassCheckoutController;
use Drupal\instructor_companion\Service\PreClassReminderService;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Lets an instructor or meetup host email the people in their class.
 *
 * Ashley, 2026-10-27: instructors and hosts need to reach their own class
 * (a supply list, a room change, a follow-up) without asking staff. One email
 * per person, so nobody sees anyone else's address; replies go to the sender.
 * A copy goes to the sender and to education@, so staff can see what went
 * out. Access is the same "your class" check as attendance, plus the Host
 * field for member-hosted meetups.
 */
class EmailClassForm extends FormBase {

  /**
   * Sends per event per sender per day before the form says wait.
   */
  public const FLOOD_LIMIT = 5;

  /**
   * Participant statuses that are not in the class.
   */
  protected const EXCLUDED_STATUSES = [
    'Cancelled',
    'Rejected',
    'Transferred',
    'Expired',
    'On waitlist',
    'Awaiting approval',
    'Pending from waitlist',
    'Pending from approval',
  ];

  public function __construct(
    protected Connection $database,
    protected MailManagerInterface $mailManager,
    protected FloodInterface $flood,
    protected EmailValidatorInterface $emailValidator,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('database'),
      $container->get('plugin.manager.mail'),
      $container->get('flood'),
      $container->get('email.validator'),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string {
    return 'instructor_companion_email_class';
  }

  /**
   * Custom access: staff, the class's instructor, or the meetup's host.
   */
  public static function access(RouteMatchInterface $route_match, AccountInterface $account): AccessResult {
    $result = (new ClassCheckoutController())->access($route_match, $account);
    if ($result->isAllowed()) {
      return $result;
    }
    $event_id = (int) $route_match->getParameter('event_id');
    $db = \Drupal::database();
    $is_host = $event_id > 0 && $db->schema()->tableExists('civicrm_event__field_civi_event_host')
      && (bool) $db->select('civicrm_event__field_civi_event_host', 'h')
        ->fields('h', ['entity_id'])
        ->condition('h.entity_id', $event_id)
        ->condition('h.field_civi_event_host_target_id', $account->id())
        ->execute()
        ->fetchField();
    return ($is_host ? AccessResult::allowed() : AccessResult::forbidden())->cachePerUser()->setCacheMaxAge(0);
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state, $event_id = NULL): array {
    $event = $this->loadEvent((int) $event_id);
    if (!$event) {
      throw new NotFoundHttpException();
    }
    $form_state->set('event', $event);
    $when = $event->start_date ? date('D M j, g:ia', strtotime($event->start_date)) : '';
    $registered = $this->recipients($event->id, FALSE);
    $attended = $this->recipients($event->id, TRUE);

    $form['#title'] = $this->t('Email your class');
    $form['intro'] = [
      '#markup' => '<p><strong>' . htmlspecialchars($event->title) . '</strong>' . ($when ? ' · ' . $when : '') . '</p>'
      . '<p>' . $this->t("Each person gets their own email, so nobody sees anyone else's address. Replies come to you at @email. A copy goes to you and to the education team.", [
        '@email' => $this->currentUser()->getEmail(),
      ]) . '</p>',
    ];

    if (!$registered) {
      $form['none'] = [
        '#markup' => '<div class="messages messages--warning">' . $this->t('Nobody is registered for this class yet.') . '</div>',
      ];
      return $form;
    }

    $options = ['registered' => $this->t('Everyone registered (@n)', ['@n' => count($registered)])];
    if ($attended) {
      $options['attended'] = $this->t('Only people marked Attended (@n)', ['@n' => count($attended)]);
    }
    $form['audience'] = [
      '#type' => 'radios',
      '#title' => $this->t('Send to'),
      '#options' => $options,
      '#default_value' => 'registered',
      '#access' => count($options) > 1,
    ];
    $form['subject'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Subject'),
      '#required' => TRUE,
      '#maxlength' => 150,
      '#default_value' => (string) $this->t('About @class', ['@class' => $event->title]),
    ];
    $form['message'] = [
      '#type' => 'textarea',
      '#title' => $this->t('Message'),
      '#required' => TRUE,
      '#rows' => 10,
      '#description' => $this->t('Plain text. Your name is added at the end.'),
    ];
    $form['actions'] = ['#type' => 'actions'];
    $form['actions']['submit'] = [
      '#type' => 'submit',
      '#value' => $this->t('Send'),
    ];
    $form['actions']['cancel'] = [
      '#type' => 'link',
      '#title' => $this->t('Cancel'),
      '#url' => $this->backUrl($event->id),
    ];
    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function validateForm(array &$form, FormStateInterface $form_state): void {
    $event = $form_state->get('event');
    if ($event && !$this->flood->isAllowed($this->floodName($event->id), self::FLOOD_LIMIT, 86400, (string) $this->currentUser()->id())) {
      $form_state->setErrorByName('message', $this->t('You have emailed this class @n times today. Write to education@makehaven.org if you need to send more.', ['@n' => self::FLOOD_LIMIT]));
    }
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $event = $form_state->get('event');
    $sender = $this->currentUser();
    $sender_name = $sender->getDisplayName();
    $sender_email = (string) $sender->getEmail();
    $attended_only = $form_state->getValue('audience') === 'attended';
    $recipients = $this->recipients($event->id, $attended_only);
    $subject = trim((string) $form_state->getValue('subject'));
    $message = trim((string) $form_state->getValue('message'));
    $footer = $this->t('You are getting this because you signed up for @class at MakeHaven. Reply to this email to reach @name.', [
      '@class' => $event->title,
      '@name' => $sender_name,
    ]);
    $body = $message . "\n\n" . $sender_name . "\n\n--\n" . $footer;
    $reply_to = $this->emailValidator->isValid($sender_email) ? $sender_email : PreClassReminderService::DEFAULT_REPLY_TO;

    $sent = 0;
    foreach ($recipients as $email) {
      $result = $this->mailManager->mail('instructor_companion', 'class_message', $email, 'en', [
        'subject' => $subject,
        'body' => $body,
      ], $reply_to, TRUE);
      if (!empty($result['result'])) {
        $sent++;
      }
    }

    // One record copy each for the sender and staff, saying who it went to.
    $copy_intro = $this->t('Copy of your message to @n people in @class:', [
      '@n' => $sent,
      '@class' => $event->title,
    ]);
    $copy_body = $copy_intro . "\n\n" . $body;
    $staff = (string) ($this->config('instructor_companion.settings')->get('preclass_reply_to') ?: PreClassReminderService::DEFAULT_REPLY_TO);
    foreach (array_unique(array_filter([$sender_email, $staff])) as $to) {
      $this->mailManager->mail('instructor_companion', 'class_message', $to, 'en', [
        'subject' => '[Copy] ' . $subject,
        'body' => $copy_body,
      ], $reply_to, TRUE);
    }

    $this->flood->register($this->floodName($event->id), 86400, (string) $sender->id());
    $this->logger('instructor_companion')->notice('@name emailed @n of @total people in event @event: "@subject".', [
      '@name' => $sender_name,
      '@n' => $sent,
      '@total' => count($recipients),
      '@event' => $event->id,
      '@subject' => $subject,
    ]);
    if ($sent === count($recipients)) {
      $this->messenger()->addStatus($this->t('Sent to @n people. A copy is in your inbox.', ['@n' => $sent]));
    }
    else {
      $this->messenger()->addWarning($this->t('Sent to @n of @total people; the rest could not be sent. Staff have the log.', [
        '@n' => $sent,
        '@total' => count($recipients),
      ]));
    }
    $form_state->setRedirectUrl($this->backUrl($event->id));
  }

  /**
   * Distinct, deliverable email addresses of the people in the class.
   *
   * @return string[]
   *   Lower-cased addresses.
   */
  public function recipients(int $event_id, bool $attended_only): array {
    $q = $this->database->select('civicrm_participant', 'p');
    $q->innerJoin('civicrm_contact', 'c', 'c.id = p.contact_id');
    $q->innerJoin('civicrm_participant_status_type', 'pst', 'pst.id = p.status_id');
    $q->innerJoin('civicrm_email', 'em', 'em.contact_id = c.id AND em.is_primary = 1');
    $q->addField('em', 'email');
    $q->condition('p.event_id', $event_id)
      ->condition('p.is_test', 0)
      ->condition('c.is_deleted', 0)
      ->condition('c.is_deceased', 0)
      ->condition('em.on_hold', 0);
    if ($attended_only) {
      $q->condition('pst.name', 'Attended');
    }
    else {
      $q->condition('pst.name', self::EXCLUDED_STATUSES, 'NOT IN');
    }
    $emails = [];
    foreach ($q->execute()->fetchCol() as $email) {
      $email = strtolower(trim((string) $email));
      if ($email !== '' && $this->emailValidator->isValid($email)) {
        $emails[$email] = $email;
      }
    }
    return array_values($emails);
  }

  /**
   * The event's id, title and start, or NULL.
   */
  protected function loadEvent(int $event_id): ?object {
    if ($event_id <= 0) {
      return NULL;
    }
    $row = $this->database->query('SELECT id, title, start_date FROM {civicrm_event} WHERE id = :id AND is_template = 0', [':id' => $event_id])->fetchObject();
    if (!$row) {
      return NULL;
    }
    $row->id = (int) $row->id;
    $row->title = (string) $row->title;
    return $row;
  }

  /**
   * Instructors go back to their dashboard; hosts (who have none) to the event.
   */
  protected function backUrl(int $event_id): Url {
    return $this->currentUser()->hasPermission('access instructor dashboard')
      ? Url::fromRoute('instructor_companion.dashboard')
      : Url::fromUri('internal:/civicrm/event/info', ['query' => ['reset' => 1, 'id' => $event_id]]);
  }

  /**
   * Flood event name for one class.
   */
  protected function floodName(int $event_id): string {
    return 'instructor_companion.class_email.' . $event_id;
  }

}
