<?php

namespace Drupal\instructor_companion\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Datetime\DateFormatterInterface;
use Drupal\Core\Url;
use Drupal\webform\WebformSubmissionInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Shared rendering for webform-submission review queues.
 *
 * Subclasses declare which webform ID to list and which columns to show.
 * The base handles loading, formatting rows, and rendering a sortable table.
 */
abstract class SubmissionQueueControllerBase extends ControllerBase {

  public function __construct(
    protected DateFormatterInterface $dateFormatter,
  ) {}

  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('date.formatter'),
    );
  }

  /**
   * The webform machine ID this queue lists.
   */
  abstract protected function webformId(): string;

  /**
   * Title shown above the queue.
   */
  abstract protected function title(): string;

  /**
   * Extra table headers inserted between "Submitter" and "Age".
   *
   * @return array<string, string> Header machine key => display label.
   */
  protected function extraHeaders(): array {
    return [];
  }

  /**
   * Extra row cells for a submission, keyed to match extraHeaders().
   */
  protected function extraCells(WebformSubmissionInterface $submission): array {
    return [];
  }

  /**
   * Name of the webform element used to track review status, if any.
   * Return NULL for queues with no built-in status field.
   */
  protected function statusElement(): ?string {
    return NULL;
  }

  /**
   * Default active filter when none given in the query string.
   */
  protected function defaultStatusFilter(): string {
    return 'unreviewed';
  }

  public function build() {
    $status_filter = $this->requestStack()->getCurrentRequest()->query->get('status')
      ?? $this->defaultStatusFilter();

    $submissions = $this->loadSubmissions($status_filter);
    $rows = array_map(fn(WebformSubmissionInterface $s) => $this->buildRow($s), $submissions);

    $header = [
      'submitter' => $this->t('Submitter'),
      'email' => $this->t('Email'),
    ];
    foreach ($this->extraHeaders() as $key => $label) {
      $header[$key] = $label;
    }
    $header['age'] = $this->t('Age');
    $header['actions'] = $this->t('Actions');

    $build = [
      '#type' => 'container',
      '#attributes' => ['class' => ['instructor-companion-queue']],
    ];
    $build['header'] = [
      '#markup' => '<h2>' . $this->title() . '</h2>',
    ];
    if ($this->statusElement()) {
      $build['filters'] = $this->buildStatusFilterLinks($status_filter);
    }
    $build['summary'] = [
      '#markup' => '<p>' . $this->formatPlural(
        count($submissions),
        '1 submission',
        '@count submissions'
      ) . '</p>',
    ];
    $build['table'] = [
      '#type' => 'table',
      '#header' => $header,
      '#rows' => $rows,
      '#empty' => $this->t('No submissions match the current filter.'),
      '#attributes' => ['class' => ['instructor-companion-queue-table']],
    ];

    return $build;
  }

  /**
   * Loads submissions for this webform, optionally filtered by review status.
   *
   * @return WebformSubmissionInterface[]
   */
  protected function loadSubmissions(string $status_filter): array {
    $storage = $this->entityTypeManager()->getStorage('webform_submission');
    $ids = $storage->getQuery()
      ->accessCheck(FALSE)
      ->condition('webform_id', $this->webformId())
      ->sort('created', 'DESC')
      ->range(0, 500)
      ->execute();

    if (!$ids) {
      return [];
    }

    /** @var WebformSubmissionInterface[] $submissions */
    $submissions = $storage->loadMultiple($ids);

    if ($this->statusElement() && $status_filter !== 'all') {
      $submissions = array_filter($submissions, function (WebformSubmissionInterface $s) use ($status_filter) {
        $value = (string) ($s->getData()[$this->statusElement()] ?? '');
        if ($status_filter === 'unreviewed') {
          return $value === '';
        }
        return $value === $status_filter;
      });
    }

    return $submissions;
  }

  /**
   * Renders a single table row for a submission.
   */
  protected function buildRow(WebformSubmissionInterface $submission): array {
    $data = $submission->getData();

    $submitter = $this->pickFirst($data, [
      'your_name', 'your_name_25', 'name', 'name_6',
    ]) ?? '(anonymous)';
    $email = $this->pickFirst($data, [
      'e_mail_address', 'e_mail_address_25', 'email', 'email_6',
    ]) ?? '';

    $age = $this->dateFormatter->formatTimeDiffSince($submission->getCreatedTime());

    $review_link = [
      '#type' => 'link',
      '#title' => $this->t('Review'),
      '#url' => Url::fromRoute('entity.webform_submission.canonical', [
        'webform' => $submission->getWebform()->id(),
        'webform_submission' => $submission->id(),
      ]),
      '#attributes' => ['class' => ['button', 'button--small']],
    ];

    $row = [
      'submitter' => $submitter,
      'email' => $email,
    ];
    foreach ($this->extraCells($submission) as $key => $cell) {
      $row[$key] = $cell;
    }
    $row['age'] = $age;
    $row['actions'] = ['data' => $review_link];
    return $row;
  }

  /**
   * Builds status filter links (All / Unreviewed / Approved / ...).
   */
  protected function buildStatusFilterLinks(string $active): array {
    $options = [
      'unreviewed' => $this->t('Unreviewed'),
      'reworking' => $this->t('Reworking'),
      'deferred' => $this->t('Deferred'),
      'approved' => $this->t('Approved'),
      'denied' => $this->t('Denied'),
      'all' => $this->t('All'),
    ];
    $links = [];
    foreach ($options as $key => $label) {
      $links[] = [
        '#type' => 'link',
        '#title' => $label,
        '#url' => Url::fromRoute('<current>', [], ['query' => ['status' => $key]]),
        '#attributes' => [
          'class' => ['button', 'button--small', $active === $key ? 'is-active' : ''],
        ],
      ];
    }
    return [
      '#type' => 'container',
      '#attributes' => ['class' => ['instructor-companion-queue-filters']],
      'links' => $links,
    ];
  }

  /**
   * Returns the first non-empty string from $data among $keys.
   */
  protected function pickFirst(array $data, array $keys): ?string {
    foreach ($keys as $key) {
      if (!empty($data[$key]) && is_string($data[$key])) {
        return $data[$key];
      }
    }
    return NULL;
  }

  protected function requestStack() {
    return \Drupal::service('request_stack');
  }

}
