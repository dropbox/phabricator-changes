<?php

final class ChangesInlineController extends PhabricatorController {

  public function processRequest() {
    $request = $this->getRequest();
    $revision_id = $request->getStr('revision_id');
    $diff_id = $request->getStr('diff_id');

    // fetch build info from changes

    $future = id(new ChangesFuture())
      ->setAPIPath('/api/0/phabricator/inline')
      ->setParams(array(
        'revision_id' => $revision_id,
        'diff_id' => $diff_id, 
      ));

    $api_data = $future->resolve();
    $build_list = id(new PHUIStatusListView());

    if (empty($api_data)) {
      return id(new AphrontAjaxResponse())->setContent(
        pht('No builds found'));
    }

    // we want the most recent build for each project, ordered alphabetically
    // by project name

    $sorted_builds = array(); // keys are project names
    foreach ($api_data as $build) {
      $project_name = idx(
        idx($build, 'project', array()),
        'name');
      
      // always keep most recent project build
      if (idx($sorted_builds, $project_name) && 
          $sorted_builds[$project_name]['dateCreated'] > $build['dateCreated']) {
        continue;
      }
      $sorted_builds[$project_name] = $build;
    }
    ksort($sorted_builds);

    // convert each build to a row w/ icon

    foreach ($api_data as $build) {
      $status = idx(
        idx($build, 'status', array()),
        'id');

      $result = idx(
        idx($build, 'result', array()),
        'id');

      $project_name = idx(
        idx($build, 'project', array()),
        'name');

      $changes_href = PhabricatorEnv::getEnvConfigIfExists('changes.uri');
      if ($changes_href) {
        $project_uri = id(new PhutilURI($changes_href))
          ->setPath("v2/diff/D{$revision_id}/")
          ->setQueryParam('buildID', idx($build, 'id'));

        $project_name = phutil_tag(
          'a',
          array( 
            'href' => $project_uri,
          ),
          $project_name);
      }

      $not_yet_finished = $status === 'queued' || $status === 'in_progress';

      $note = '';
      if (!$not_yet_finished && $status !== 'finished') {
        $icon = 'fa-question-circle';
        $color = 'gray';
        $label = 'Unknown';
      } else if ($not_yet_finished) {
        $icon = 'fa-clock-o';
        $color = 'gray';
        $label = $status === 'queued' ? 'Not yet started' : 'Running now';
      } else {
        if ($result === 'passed') {
          $icon = 'fa-check-circle';
          $color = 'green';
          $label = 'Passed';
        } else if ($result === 'failed' || 
                   $result === 'aborted' || 
                   $result === 'infra_failed') {
          $icon = 'fa-times-circle';
          $color = 'red';
          $label = $result === 'aborted' ? 'Aborted' : 'Failed';

          $tests_failed = idx(
            idx($build, 'stats', array()),
            'test_failures');
          $note = $tests_failed ?
            "{$tests_failed} tests failed" :
            'No test failures';
        } else if ($result === 'skipped') {
          $icon = 'fa-chevron-circle-right';
          $color = 'yellow';
          $label = 'Skipped';
        } else { 
          $icon = 'fa-question-circle';
          $color = 'gray';
          $label = 'Finished, but unknown';
        }
      }
      
      $build_item = id(new PHUIStatusItemView())
        ->setIcon($icon, $color, $label)
        ->setTarget($project_name);

      if ($note) {
        $build_item->setNote($note);
      }

      $build_list->addItem($build_item);
    }

    return id(new AphrontAjaxResponse())->setContent($build_list);
  }
}
