<?php

final class ChangesBuildStepImplementation
  extends HarbormasterBuildStepImplementation {

  public function getName() {
    return pht('Create build in Changes');
  }

  public function getGenericDescription() {
    return pht('Create a new build in Changes.');
  }

  public function getDescription() {
    $settings = $this->getSettings();

    return pht('Create a new build in Changes');
  }

  public function getFieldSpecifications() {
    return array(
      'wait_results' => array(
        'name' => pht('Wait for results'),
        'type' => 'bool',
        'required' => false,
        'caption' => pht('Expect the build server to report the result.'),
      ),
    );
  }

  public function execute(
    HarbormasterBuild $build,
    HarbormasterBuildTarget $build_target) {

    $buildable = $build->getBuildable();
    $object = $buildable->getBuildableObject();

    $helper = new ChangesBuildHelper();
    $helper->executeBuild($object);

    if ($helper === false) {
      $build->setBuildStatus(HarbormasterBuild::STATUS_FAILED);
      $build->save();

      return;
    }
  }

  public function shouldWaitForMessage(HarbormasterBuildTarget $target) {
    $settings = $this->getSettings();
    return (bool)$settings['wait_results'];
  }
}
