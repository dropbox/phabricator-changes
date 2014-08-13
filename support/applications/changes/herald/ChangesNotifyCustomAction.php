<?php

final class ChangesNotifyCustomAction extends HeraldCustomAction {

  public function appliesToAdapter(HeraldAdapter $adapter) {
    return ($adapter instanceof HeraldDifferentialRevisionAdapter);
  }

  public function appliesToRuleType($rule_type) {
    return ($rule_type == HeraldRuleTypeConfig::RULE_TYPE_GLOBAL);
  }

  public function getActionKey() {
    return 'changes.build';
  }

  public function getActionName() {
    return pht('Create build in Changes');
  }

  public function getActionType() {
    return HeraldAdapter::VALUE_NONE;
  }

  public function applyEffect(
    HeraldAdapter $adapter,
    $object,
    HeraldEffect $effect) {

    $revision = $object;

    // NOTE: This specific API might change slightly at some point.
    $diff = $object->loadActiveDiff();

    $revision_id = $revision->getID();
    $diff_id = $diff->getID();

    $diff->attachRevision($revision);

    $helper = new ChangesBuildHelper();
    list($success, $result) = $helper->executeBuild($diff);

    $result = print_r($result, true);

    if ($success === false) {
      phlog("[Failed] Create build in Changes for diff {$diff_id}: {$result}");
    } else {
      phlog("[Success] Create build in Changes for diff {$diff_id}: {$result}");
    }

    return new HeraldApplyTranscript(
      $effect,
      $success,
      pht('Create build in Changes.'));
  }

}
