<?php

declare(strict_types=1);

namespace Drupal\instructor_companion\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Render\Markup;
use Drupal\Core\Url;
use Drupal\instructor_companion\Service\TeachingPipeline;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\Request;

/**
 * Teaching pipeline by interest area: /admin/education/teaching-pipeline.
 */
class TeachingPipelineController extends ControllerBase {

  private const SORTS = ['area', 'interested', 'willing', 'badge', 'applied', 'taught', 'active', 'waiting'];

  public function __construct(protected TeachingPipeline $pipeline) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static($container->get('instructor_companion.teaching_pipeline'));
  }

  /**
   * Builds the page.
   */
  public function build(Request $request): array {
    $view = (string) $request->query->get('view', 'all');
    $view = in_array($view, ['all', 'waiting', 'few'], TRUE) ? $view : 'all';
    $sort = (string) $request->query->get('sort', 'waiting');
    $sort = in_array($sort, self::SORTS, TRUE) ? $sort : 'waiting';

    $all = $this->pipeline->rows();
    $rows = TeachingPipeline::sort(TeachingPipeline::filter($all, $view), $sort);
    // One scale for every filter, so a bar means the same thing on each view.
    $max = max(array_column($all, 'interested') ?: [1]) ?: 1;

    $build = [
      '#type' => 'container',
      '#attributes' => ['class' => ['education-console', 'teaching-pipeline']],
      '#attached' => ['library' => ['instructor_companion/education_console']],
      '#cache' => [
        'contexts' => ['url.query_args:view', 'url.query_args:sort', 'user.permissions'],
        'max-age' => 3600,
      ],
    ];
    $build['intro'] = [
      '#markup' => '<p class="education-console__intro">' . $this->t('Where people could teach and where classes need them. Counts only. A member can count in several areas, so columns do not add up.') . '</p>',
    ];

    $links = [];
    foreach ([
      'all' => $this->t('All areas'),
      'waiting' => $this->t('People waiting'),
      'few' => $this->t('One or no active instructors'),
    ] as $key => $label) {
      $links[$key] = [
        '#type' => 'link',
        '#title' => $label,
        '#url' => Url::fromRoute('<current>', [], ['query' => ['view' => $key, 'sort' => $sort]]),
        '#attributes' => [
          'class' => ['teaching-pipeline__filter'],
          'aria-current' => $key === $view ? 'true' : 'false',
        ],
      ];
    }
    $build['filters'] = [
      '#type' => 'container',
      '#attributes' => ['class' => ['teaching-pipeline__filters']],
    ] + $links;

    $build['legend'] = [
      '#markup' => '<p class="teaching-pipeline__legend">'
        . '<span class="teaching-pipeline__key teaching-pipeline__seg--badge"></span>' . $this->t('Willing and holds a badge for this area')
        . '<span class="teaching-pipeline__key teaching-pipeline__seg--willing"></span>' . $this->t('Willing to teach')
        . '<span class="teaching-pipeline__key teaching-pipeline__seg--interested"></span>' . $this->t('Interested')
        . '</p>',
    ];

    $header = [];
    foreach ([
      'area' => $this->t('Interest area'),
      'interested' => $this->t('Interested'),
      'willing' => $this->t('Willing'),
      'badge' => $this->t('Badge'),
      'applied' => $this->t('Applied'),
      'taught' => $this->t('Taught'),
      'active' => $this->t('Active'),
      'waiting' => $this->t('Waiting'),
    ] as $key => $label) {
      $header[$key] = [
        'data' => [
          '#type' => 'link',
          '#title' => $label,
          '#url' => Url::fromRoute('<current>', [], ['query' => ['view' => $view, 'sort' => $key]]),
        ],
        'aria-sort' => $key === $sort ? ($key === 'area' ? 'ascending' : 'descending') : 'none',
      ];
    }
    $header = array_slice($header, 0, 1, TRUE) + ['bar' => $this->t('Pipeline')] + array_slice($header, 1, NULL, TRUE);

    $table_rows = [];
    foreach ($rows as $row) {
      $table_rows[] = [
        'area' => $row['area'],
        'bar' => ['data' => ['#markup' => Markup::create($this->bar($row, $max))]],
        'interested' => $row['interested'],
        'willing' => $row['willing'],
        'badge' => $row['badge'] ?? $this->t('no badge'),
        'applied' => $row['applied'],
        'taught' => $row['taught'],
        'active' => $row['active'],
        'waiting' => $row['waiting'],
      ];
    }
    $build['table_wrap'] = [
      '#type' => 'container',
      '#attributes' => ['class' => ['teaching-pipeline__scroll']],
    ];
    $build['table_wrap']['table'] = [
      '#type' => 'table',
      '#header' => $header,
      '#rows' => $table_rows,
      '#empty' => $this->t('No areas match this filter.'),
      '#attributes' => ['class' => ['teaching-pipeline__table']],
    ];

    $build['notes'] = [
      '#theme' => 'item_list',
      '#title' => $this->t('How to read this'),
      '#attributes' => ['class' => ['teaching-pipeline__notes']],
      '#items' => [
        $this->t('<strong>Interested:</strong> active members who picked this area.'),
        $this->t('<strong>Willing:</strong> of those, members who ticked "available to teach" and are not already teaching. The tick is not tied to a topic, so treat this as a ceiling.'),
        $this->t('<strong>Badge:</strong> of those, members holding a badge for this area, not counting shop orientations. "No badge" means MakeHaven has no badge for this area, not that nobody has the skill.'),
        $this->t('<strong>Applied:</strong> people who picked this topic on the instructor interest form. The picker is new, so earlier applications are not counted.'),
        $this->t('<strong>Taught and Active:</strong> instructors of workshops, member-only classes and programs tagged with this area, ever and in the last 12 months, including non-members. Meetup hosts are not counted.'),
        $this->t('<strong>Waiting:</strong> people turned away from one of those classes in the last 12 months who never got a seat on a later run.'),
        $this->t('A gap here is not always an instructor gap. Space, ventilation or machines can be the limit.'),
      ],
    ];
    return $build;
  }

  /**
   * One nested bar: badge inside willing inside interested.
   *
   * Returned as trusted markup because the widths are inline styles, which the
   * default filter strips. The area name in the label is escaped here.
   */
  private function bar(array $row, int $max): string {
    $badge = (int) ($row['badge'] ?? 0);
    $willing = max(0, $row['willing'] - $badge);
    $interested = max(0, $row['interested'] - $row['willing']);
    $label = (string) $this->t('@area: @i interested, @w willing, @b with a badge', [
      '@area' => $row['area'],
      '@i' => $row['interested'],
      '@w' => $row['willing'],
      '@b' => $row['badge'] ?? 0,
    ]);
    $segment = static function (string $kind, int $n) use ($max): string {
      if ($n <= 0) {
        return '';
      }
      $width = round($n / $max * 100, 2);
      return '<span class="teaching-pipeline__seg teaching-pipeline__seg--' . $kind . '" style="width:' . $width . '%"></span>';
    };
    return '<span class="teaching-pipeline__bar" role="img" title="' . htmlspecialchars($label, ENT_QUOTES) . '" aria-label="' . htmlspecialchars($label, ENT_QUOTES) . '">'
      . $segment('badge', $badge) . $segment('willing', $willing) . $segment('interested', $interested) . '</span>';
  }

}
