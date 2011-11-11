<?php

/*
 * Copyright 2011 Facebook
 *
 * Licensed under the Apache License, Version 2.0 (the "License");
 * you may not use this file except in compliance with the License.
 * You may obtain a copy of the License at
 *
 *   http://www.apache.org/licenses/LICENSE-2.0
 *
 * Unless required by applicable law or agreed to in writing, software
 * distributed under the License is distributed on an "AS IS" BASIS,
 * WITHOUT WARRANTIES OR CONDITIONS OF ANY KIND, either express or implied.
 * See the License for the specific language governing permissions and
 * limitations under the License.
 */

class CommitListener extends PhutilEventListener {
  public function register() {
    $this->listen(ArcanistEventType::TYPE_COMMIT_WILLCOMMITSVN);
  }

  public function handleEvent(PhutilEvent $event) {
    $message = $event->getValue('message');
    $workflow = $event->getValue('workflow');
    $revision_id = $workflow->getRevisionID();
    $user_phid = $workflow->getUserPHID();
    $conduit = $workflow->getConduit();

    $revision = $conduit->callMethodSynchronous(
      'differential.getrevision',
      array(
        'revision_id' => $revision_id,
      )
    );

    $author_phid = $revision['authorPHID'];

    if ($author_phid != $user_phid) {
      // Committer is not the author, add `via`.
      $user_name = $this->userPHIDToName($conduit, $user_phid);
      $author_name = $this->userPHIDToName($conduit, $author_phid);
      $via_text = '('.$author_name.' via '.$user_name.')';
      $msg = explode("\n\n", $message, 2);
      $msg[2] = idx($msg, 1, '');
      $msg[1] = $via_text."\n";
      $message = implode("\n", $msg);
      $event->setValue('message', $message);
    }
  }

  private function userPHIDToName($conduit, $phid) {
    $user = $conduit->callMethodSynchronous(
      'user.info',
      array(
        'phid' => $phid
      )
    );
    return idx($user, 'realName');
  }
}
