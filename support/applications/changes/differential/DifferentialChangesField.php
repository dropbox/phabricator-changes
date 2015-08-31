<?php

final class DifferentialChangesField
  extends DifferentialCustomField {

  public function getFieldKey() {
    return 'differential:changes';
  }

  public function getFieldName() {
    return pht('Changes');
  }

  public function getFieldDescription() {
    return pht('Shows the builds being run by changes.');
  }

  public function shouldAppearInPropertyView() {
    return true;
  }

  public function renderPropertyViewValue(array $handles) {
    return null;
  }

  public function shouldAppearInDiffPropertyView() {
    return true;
  }

  public function renderDiffPropertyViewLabel(DifferentialDiff $diff) {
    return $this->getFieldName();
  }

  public function renderDiffPropertyViewValue(DifferentialDiff $diff) {
    $uniq_id = celerity_generate_unique_node_id();

    Javelin::initBehavior(
      'changes-inline', 
      array(
        'div_id' => $uniq_id, 
        'diff_id' => $diff->getID(), 
        'revision_id' => $diff->getRevisionID(),
      ));

    return phutil_tag(
      'div',
      array(
        'id' => $uniq_id,
      ),
      pht('Loading...'));
  }

  public function shouldAppearInTransactionMail() {
    return false;
  }
}
