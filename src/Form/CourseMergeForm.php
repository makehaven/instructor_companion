<?php

namespace Drupal\instructor_companion\Form;

use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\node\Entity\Node;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Provides a form to select a target course for merging.
 */
class CourseMergeForm extends FormBase {

  /**
   * {@inheritdoc}
   */
  public function getFormId() {
    return 'instructor_companion_course_merge_form';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state, $source_nid = NULL) {
    $source = Node::load($source_nid);
    if (!$source || $source->getType() !== 'course') {
      $this->messenger()->addError($this->t('Invalid source course.'));
      return ['#markup' => $this->t('Invalid source course.')];
    }

    $form['source_nid'] = [
      '#type' => 'value',
      '#value' => $source_nid,
    ];

    $form['help'] = [
      '#markup' => '<div class="messages messages--warning">' . 
        $this->t('You are merging <strong>"@title"</strong> into another course. All historical events and interest votes will be moved to the target course, and "@title" will be deleted.', ['@title' => $source->label()]) . 
        '</div>',
    ];

    $form['target_nid'] = [
      '#type' => 'entity_autocomplete',
      '#target_type' => 'node',
      '#selection_settings' => [
        'target_bundles' => ['course'],
      ],
      '#title' => $this->t('Select the Target Course (to keep)'),
      '#required' => TRUE,
      '#description' => $this->t('Search for the course that should receive the data.'),
    ];

    $form['actions'] = ['#type' => 'actions'];
    $form['actions']['submit'] = [
      '#type' => 'submit',
      '#value' => $this->t('Perform Merge & Delete Source'),
      '#button_type' => 'primary',
      '#attributes' => [
        'onclick' => 'return confirm("' . $this->t('Are you sure? This action is permanent and will delete the source course.') . '");',
      ],
    ];

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function validateForm(array &$form, FormStateInterface $form_state) {
    $source_nid = $form_state->getValue('source_nid');
    $target_nid = $form_state->getValue('target_nid');

    if ($source_nid == $target_nid) {
      $form_state->setErrorByName('target_nid', $this->t('Cannot merge a course into itself.'));
    }
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
    $source_nid = $form_state->getValue('source_nid');
    $target_nid = $form_state->getValue('target_nid');

    // Redirect to the controller logic we already wrote.
    $form_state->setRedirect('instructor_companion.course_merge_execute', [
      'source_nid' => $source_nid,
      'target_nid' => $target_nid,
    ]);
  }

}
