<?php

final class ChangesConfigOptions extends PhabricatorApplicationConfigOptions {

  public function getName() {
    return pht('Changes');
  }

  public function getDescription() {
    return pht('Configure Changes builds.');
  }

  public function getOptions() {
    return array(
      $this->newOption(
        'changes.uri',
        'string',
        '')
        ->setDescription(pht('URL to Changes build server.'))
        ->addExample('https://changes.example.com', 'Changes server'),
    );
  }

}
