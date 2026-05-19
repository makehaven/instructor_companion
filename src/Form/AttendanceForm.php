<?php

namespace Drupal\instructor_companion\Form;

use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\Core\Url;
use Drupal\instructor_companion\Service\AttendanceManager;
use Drupal\instructor_companion\Service\PostEventStatusService;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Mobile-friendly attendance screen for an instructor's class.
 *
 * Reachable at class start ("Take attendance" from the dashboard's today
 * row) and again afterwards from the post-event hub to fix late arrivals or
 * someone who was missed. Saving stamps an attendance-confirmed flag so the
 * hub's step-1 ticks green and the +48h reminder stops nagging about it.
 */
class AttendanceForm extends FormBase {

  public function __construct(
    protected AttendanceManager $attendance,
    protected PostEventStatusService $postEventStatus,
    protected AccountProxyInterface $currentUser,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('instructor_companion.attendance_manager'),
      $container->get('instructor_companion.post_event_status'),
      $container->get('current_user'),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string {
    return 'instructor_companion_attendance';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state, $event_id = NULL): array {
    $event_id = (int) $event_id;
    $event = $event_id
      ? $this->entityTypeManager()->getStorage('civicrm_event')->load($event_id)
      : NULL;
    if (!$event) {
      throw new NotFoundHttpException();
    }

    $form['#attached']['library'][] = 'instructor_companion/dashboard';
    $form['event_id'] = ['#type' => 'value', '#value' => $event_id];

    $form['intro'] = [
      '#markup' => '<div class="attendance-intro"><h2>'
      . $this->t('Attendance: @label', ['@label' => $event->label()])
      . '</h2><p>' . $this->t('Check everyone who showed up. You can come back and fix this later if someone arrives late or you miss a name.') . '</p></div>',
    ];

    $roster = $this->attendance->getRoster($event_id);
    $options = [];
    $default = [];
    foreach ($roster as $row) {
      $pid = $row['participant_id'];
      $suffix = $row['is_attended'] ? '' : ' (' . $row['status_name'] . ')';
      $options[$pid] = $row['name'] . $suffix;
      if ($row['is_attended']) {
        $default[] = $pid;
      }
    }

    if ($options) {
      $form['present'] = [
        '#type' => 'checkboxes',
        '#title' => $this->t('Who attended?'),
        '#options' => $options,
        '#default_value' => $default,
        '#attributes' => ['class' => ['attendance-checklist']],
      ];
    }
    else {
      $form['empty'] = [
        '#markup' => '<p>' . $this->t('Nobody is registered for this class yet. You can still add a walk-in below.') . '</p>',
      ];
    }
    // Used by submit to know the full set being toggled.
    $form['all_participant_ids'] = [
      '#type' => 'value',
      '#value' => array_keys($options),
    ];

    $form['walk_in'] = [
      '#type' => 'details',
      '#title' => $this->t('Add a walk-in'),
      '#open' => $options === [],
    ];
    $form['walk_in']['walk_in_email'] = [
      '#type' => 'email',
      '#title' => $this->t('MakeHaven account email'),
      '#description' => $this->t('Enter the email of someone who attended but was not registered. They must already have a MakeHaven account. (Adding several? Save, then repeat.)'),
    ];

    $form['actions'] = ['#type' => 'actions'];
    $form['actions']['submit'] = [
      '#type' => 'submit',
      '#value' => $this->t('Save attendance'),
      '#button_type' => 'primary',
    ];
    $form['actions']['back'] = [
      '#type' => 'link',
      '#title' => $this->t('Back to post-class tasks'),
      '#url' => Url::fromRoute('instructor_companion.post_event_hub', ['event_id' => $event_id]),
      '#attributes' => ['class' => ['button', 'button--small']],
    ];

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $event_id = (int) $form_state->getValue('event_id');
    $all = array_map('intval', (array) $form_state->getValue('all_participant_ids'));
    $present = array_values(array_filter(array_map('intval', (array) $form_state->getValue('present'))));

    if ($all) {
      $changed = $this->attendance->applyAttendance($all, $present);
      $this->messenger()->addStatus($this->t('Attendance saved: @p present, @n change(s) recorded.', [
        '@p' => count($present),
        '@n' => $changed,
      ]));
    }

    $walk_in = trim((string) $form_state->getValue('walk_in_email'));
    if ($walk_in !== '') {
      $contact_id = $this->attendance->findContactIdByAccountEmail($walk_in);
      if ($contact_id && $this->attendance->addWalkIn($event_id, $contact_id)) {
        $this->messenger()->addStatus($this->t('Added @email as a walk-in (marked attended).', ['@email' => $walk_in]));
      }
      else {
        $this->messenger()->addWarning($this->t('Could not add @email — no MakeHaven account found for that address. Ask them to create an account, then add them.', ['@email' => $walk_in]));
      }
    }

    $this->postEventStatus->confirmAttendance($event_id, (int) $this->currentUser->id());
    $form_state->setRedirect('instructor_companion.post_event_hub', ['event_id' => $event_id]);
  }

}
