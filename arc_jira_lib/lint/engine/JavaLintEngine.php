<?php

/*
 * Copyright 2012 Facebook
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
      if (Filesystem::pathExists($this->getFilePathOnDisk($path))) {
        if (preg_match('/\.java$/', $path)) {
          $text_linter->addPath($path);
        }
      }
    }

    // Make all lint messages warnings so we only show them for modified
    // lines.
    $text_linter->setCustomSeverityMap(
      array(
        ArcanistTextLinter::LINT_DOS_NEWLINE
          => ArcanistLintSeverity::SEVERITY_WARNING,
        ArcanistTextLinter::LINT_TAB_LITERAL
          => ArcanistLintSeverity::SEVERITY_WARNING,
        ArcanistTextLinter::LINT_LINE_WRAP
          => ArcanistLintSeverity::SEVERITY_WARNING,
        ArcanistTextLinter::LINT_EOF_NEWLINE
          => ArcanistLintSeverity::SEVERITY_WARNING,
        ArcanistTextLinter::LINT_BAD_CHARSET
          => ArcanistLintSeverity::SEVERITY_WARNING,
        ArcanistTextLinter::LINT_TRAILING_WHITESPACE
          => ArcanistLintSeverity::SEVERITY_WARNING,
        ArcanistTextLinter::LINT_NO_COMMIT
          => ArcanistLintSeverity::SEVERITY_WARNING,
        ));

    return $linters;
  }

}

