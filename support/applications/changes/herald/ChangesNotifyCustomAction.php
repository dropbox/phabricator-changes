<?php

/*
 * Herald subscription that notifies changes when diffs are added or updated.
 * This is not an ideal solution: see https://secure.phabricator.com/T5462.
 */
final class ChangesNotifyCustomAction extends HeraldAction {

  const DO_CHANGES_BUILD = 'do.changes.build';
  const DO_CHANGES_BUILD_FAILED = 'do.changes.build.failed';

  // automatically used by HeraldAction
  const ACTIONCONST = 'changes.build';

  public function getActionGroupKey() {
    return HeraldSupportActionGroup::ACTIONGROUPKEY;
  }

  public function supportsObject($object) {
    $adapter = $this->getAdapter();
    // technically we can use HarbormasterBuildableAdapterInterface here to
    // allow this rule to trigger on commits, but we already have
    // infrastructure to do commit builds.
    return ($adapter instanceof HeraldDifferentialRevisionAdapter);
  }

  public function getHeraldActionName() {
    // text in dropdown when creating rule
    return pht('Create build in Changes');
  }

  public function renderActionDescription($value) {
    // text shown when visiting herald rule permalink
    return pht('Send this diff to Changes and run builds on it');
  }

  public function getHeraldActionStandardType() {
    // we don't need any input from the user
    return self::STANDARD_NONE;
  }

  public function supportsRuleType($rule_type) {
    return ($rule_type != HeraldRuleTypeConfig::RULE_TYPE_PERSONAL);
  }

  public function applyEffect($object, HeraldEffect $effect) {
    $revision = $object;
    // NOTE: This specific API might change slightly at some point.
    $diff = $object->loadActiveDiff();

    $diff->attachRevision($revision);

    $helper = new ChangesBuildHelper();
    list($success, $result) = $helper->executeBuild($diff);

    $success_effect = $success ? 
      self::DO_CHANGES_BUILD : 
      self::DO_CHANGES_BUILD_FAILED;

    $data = array(
      'revision_id' => $revision->getID(),
      'diff_id' => $diff->getID(),
      'api_result' => $result,
    );

    $this->logEffect($success_effect, $data);
  }

  protected function getActionEffectMap() {
    return array(
      self::DO_CHANGES_BUILD => array(
        'icon' => 'fa-play',
        'color' => 'green',
        'name' => pht('Building'),
      ),
      self::DO_CHANGES_BUILD_FAILED => array(
        'icon' => 'fa-ban',
        'color' => 'red',
        'name' => pht('Failed'),
      ),
    );
  }

  protected function renderActionEffectDescription($type, $data) {
    $revision_id = $data['revision_id'];
    $diff_id = $data['diff_id'];
    $api_result = $data['api_result'];

    $prefix = "D{$revision_id}:{$diff_id}";

    switch ($type) {
      case self::DO_CHANGES_BUILD:
        $build_names = array();
        foreach ($api_result as $build) {
          $project = idx($build, 'project');
          if ($project) {
            $build_names[] = $project['name'];
          }
        }

        return pht(
          '[%s] Started %s build(s): %s.',
          $prefix,
          new PhutilNumber(count($api_result)),
          implode(', ', $build_names));

      case self::DO_CHANGES_BUILD_FAILED:
        return pht(
          '[%s] Failed to start build(s). %s.',
          $prefix,
          print_r($api_result, true));
    }
  }
}
