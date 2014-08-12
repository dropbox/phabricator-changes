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
    return HeraldAdapater::VALUE_NONE;
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

    $helper = new ChangesBuildHelper();
    $result = $helper->executeBuild($diff);

    phlog("Build D{$revision_id} / diff {$diff_id}.");

    return new HeraldApplyTranscript(
      $effect,
      $result,
      pht('Create build in Changes.'));
  }

}
