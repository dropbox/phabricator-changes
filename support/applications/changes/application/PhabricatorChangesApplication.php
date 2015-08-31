<?php

final class PhabricatorChangesApplication extends PhabricatorApplication {

  public function canUninstall() {
    return false;
  }

  public function isLaunchable() {
    return false;
  }

  public function getName() {
    return pht('Changes Integration');
  }

  public function getFontIcon() {
    return 'fa-recycle';
  }

  public function getShortDescription() {
    return pht('Powers integrations with Changes');
  }

  public function getRoutes() {
    return array(
      '/changes/' => array(
        'inline/' => 'ChangesInlineController',
      ),
    );
  }

}
