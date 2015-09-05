<?php

final class ChangesInlineController extends PhabricatorController {

  public function processRequest() {
    try {
      return $this->processRequestImpl();
    } catch (Exception $e) {
      phlog($e);
      return id(new AphrontAjaxResponse())->setContent(
        pht('Error!'));
    }
  }

  private function processRequestImpl() {
    $request = $this->getRequest();
    $revision_id = $request->getStr('revision_id');
    $diff_id = $request->getStr('diff_id');

    $diff = id(new DifferentialDiffQuery())
      ->setViewer($this->getViewer())
      ->withIDs(array($diff_id))
      ->executeOne();


    $revision = id(new DifferentialRevisionQuery())
      ->setViewer($this->getViewer())
      ->withIDs(array($revision_id))
      ->executeOne();

    if (!$diff || !$revision) {
      return id(new AphrontAjaxResponse())->setContent(
        pht('Unable to load diff and revision!'));
    }

    if ($diff->getCreationMethod() === 'commit') {
      return id(new AphrontAjaxResponse())->setContent(
        pht('Automatic diff as part of commit; N/A.'));
    }

    // fetch build info from changes
    $future = id(new ChangesFuture())
      ->setAPIPath('/api/0/phabricator/inline')
      ->setParams(array(
        'revision_id' => $revision_id,
        'diff_id' => $diff_id, 
      ));

    $api_data = $future->resolve();

    $changes_content = pht('No builds found');
    if ($api_data) {
      $changes_content = $this->getChangesBuildInfo($api_data, $revision_id);
    }

    $herald_content = $this->getHeraldInfo($revision, $diff_id);

    // replace with new content
    return id(new AphrontAjaxResponse())->setContent(phutil_tag(
      'div',
      array(),
      array(
        $changes_content,
        $herald_content,
      )));
  }

  private function getHeraldInfo($revision, $diff_id) {
    $helper = new ChangesBuildHelper();

    // Transcripts are on revisions, not diffs. We record the diff id, though,
    // and can filter it ourselves
    $xscripts = id(new HeraldTranscriptQuery())
      ->setViewer($this->getViewer())
      ->withObjectPHIDs(array($revision->getPHID()))
      ->execute();

    if (!$xscripts) {
      $xscripts = array();
    }

    $saw_too_old = false;
    $saw_unknown = false;
    $content = array();
    foreach ($xscripts as $xscript) {
      foreach ($xscript->getApplyTranscripts() as $apply_xscript) {
        if ($apply_xscript->getAction() !== 'changes.build') {
          continue;
        }
        $effect_data = $apply_xscript->getAppliedReason();

        if ($effect_data === 'Create build in Changes') {
          // old herald rule (pre 8/2015 push)
          $saw_too_old = true;
          continue;
        } 
        
        if (!is_array($effect_data)) {
          // no idea what data herald stored
          $saw_unknown = true;
          continue;
        }

        $data = $effect_data[0]['data'];

        $text = $helper->stringifyApiResult(
          $effect_data[0]['type'] === ChangesNotifyCustomAction::DO_CHANGES_BUILD,
          $data);

        $transcript_link = phutil_tag('a', 
          array('href' => sprintf('/herald/transcript/%d', $xscript->getID())),
          'full');
        if ($data['diff_id'] === $diff_id) {
          $content[] = phutil_tag('b', 
            array(), 
            array($text, ' (', $transcript_link, ')'));
        } else {
          $content[] = array($text, ' (', $transcript_link, ')');
        }
      }
    }

    if ($saw_too_old) {
      $content = array('Revision too old to show');
    } else if ($saw_unknown) {
      $content = array('Unable to parse');
    } else if (!$content) {
      $content = array('Transcript not available (GCed?)');
    }

    // show relevant herald content
    $herald_content = array();
    foreach ($content as $c) {
      $herald_content[] = phutil_tag('div', array(), $c);
    }

    // we want to show this on click
    $show_link_id = celerity_generate_unique_node_id();
    $herald_id = celerity_generate_unique_node_id();

    $show_link = javelin_tag(
      'a',
      array(
        'sigil' => 'changes-herald-link',
        'id'    => $show_link_id,
        'meta'  => array(
          'myID' => $show_link_id,
          'heraldID' => $herald_id,
        ),
      ),
      pht('Show Herald Actions'));

    return phutil_tag('div',
      array(),
      array(
        $show_link,
        phutil_tag('div',
          array('id' => $herald_id, 'style' => 'display: none'),
          $herald_content),
      ));
  }

  private function getChangesBuildInfo($api_data, $revision_id) {
    $build_list = id(new PHUIStatusListView());

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

    return $build_list;
  }
}
