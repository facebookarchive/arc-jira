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

class JavaLintEngine extends ArcanistLintEngine {
  public function buildLinters() {
    $linters = array();

    $text_linter = new ArcanistTextLinter();
    $max_line_length = $this->workingCopy->getConfig('max_line_length');
    if (!$max_line_length) {
      $max_line_length = 80;
    }
    $text_linter->setMaxLineLength($max_line_length);

    $linters[] = $text_linter;

    $paths = $this->getPaths();

    foreach ($paths as $path) {
      if (preg_match('/\.java$/', $path)) {
        $text_linter->addPath($path);
      }
    }

    return $linters;
  }
}
