<?php

/**
 * @file
 * Turns `composer audit --format=json` output into a job summary.
 *
 * Usage: php summarize.php <dir> <audit-output> <exit-code> [<dir> ...]
 *
 * One triple per audited directory; exit-code "missing" means the directory
 * has no composer.lock. Writes the summary to $GITHUB_STEP_SUMMARY and the
 * advisory_count / package_count totals to $GITHUB_OUTPUT. Exits non-zero
 * only when an audit could not run; finding advisories is a successful run.
 */

$locations = [];
foreach (array_chunk(array_slice($argv, 1), 3) as $chunk) {
  if (count($chunk) === 3) {
    $locations[] = audit_location(...$chunk);
  }
}

$multiple = count($locations) > 1;
$advisory_count = array_sum(array_column($locations, 'advisory_count'));
$package_count = array_sum(array_map(fn($l) => count($l['rows']), $locations));
$failed = array_filter($locations, fn($l) => $l['failed'] !== NULL);
write_output('advisory_count', (string) $advisory_count);
write_output('package_count', (string) $package_count);

$md = header_line() . "\n\n";
if ($multiple) {
  $md .= counts($package_count, $advisory_count) . ' across ' . count($locations) . " locations\n\n";
  foreach ($locations as $location) {
    $md .= '#### `' . $location['dir'] . "`\n\n" . location_body($location, FALSE);
  }
}
else {
  $md .= location_body($locations[0], TRUE);
}
write_summary($md);
exit($failed ? 1 : 0);

/**
 * Audits one directory's report into rows.
 *
 * @return array
 *   ['dir', 'failed' => NULL|message, 'rows', 'advisory_count']
 */
function audit_location(string $dir, string $report_file, string $exit_code): array {
  $dir = $dir === '.' ? '.' : rtrim($dir, '/');
  $location = ['dir' => $dir, 'failed' => NULL, 'rows' => [], 'advisory_count' => 0];
  echo "== {$dir} ==\n";

  if ($exit_code === 'missing') {
    $location['failed'] = 'No `composer.lock` in this directory.';
    return $location;
  }

  $code = (int) $exit_code;
  $raw = (string) @file_get_contents($report_file);
  // --abandoned=ignore leaves 0 (clean) and 1 (vulnerable) as the only results.
  // Composer also uses 1 for general errors, so a usable report is the real test.
  // A lock with no packages to audit exits 0 with nothing on stdout.
  if ($code === 0 && trim($raw) === '') {
    $audit = ['advisories' => []];
  }
  else {
    $audit = $code > 1 ? NULL : parse_report($raw);
  }
  if ($audit === NULL) {
    echo "composer audit output:\n" . $raw . "\n";
    echo "::error::composer audit in '{$dir}' did not produce a usable report (exit code {$code}). See its output above.\n";
    $location['failed'] = 'composer audit did not produce a report. See the job log.';
    return $location;
  }

  $installed = installed_versions($dir . '/composer.lock');
  $packages = $audit['advisories'];
  ksort($packages);
  foreach ($packages as $package => $advisories) {
    // Composer emits an object here instead of a list when it filtered entries.
    $advisories = array_values((array) $advisories);
    if (!$advisories) {
      continue;
    }
    $location['advisory_count'] += count($advisories);
    $version = $installed[$package] ?? '';

    $fixes = [];
    $lines = [];
    foreach ($advisories as $advisory) {
      $fixes[] = fixed_in($version, (string) ($advisory['affectedVersions'] ?? ''));
      $lines[] = advisory_line($advisory);
    }
    $fixed = combine_fixes($fixes);

    $location['rows'][] = [
      'package' => $package,
      'installed' => $version === '' ? 'unknown' : ltrim($version, 'vV'),
      'fixed' => $fixed,
      'lines' => $lines,
    ];
    echo sprintf("%s %s -> %s (%d %s)\n", $package, $version ?: '?', html_entity_decode(str_replace(['`', '<br>', '\\|'], ['', ', ', '|'], $fixed)), count($advisories), count($advisories) === 1 ? 'advisory' : 'advisories');
  }
  if (!$location['rows']) {
    echo "No security advisories.\n";
  }
  return $location;
}

/**
 * Markdown for one location: failure, clean line, or table.
 */
function location_body(array $location, bool $with_counts): string {
  if ($location['failed'] !== NULL) {
    return '**Audit failed** - ' . $location['failed'] . "\n\n";
  }
  if (!$location['rows']) {
    return "No security advisories.\n\n";
  }
  $md = $with_counts ? counts(count($location['rows']), $location['advisory_count']) . "\n\n" : '';
  $md .= "| Package | Installed | Fixed in | Advisories |\n";
  $md .= "|---|---|---|---|\n";
  foreach ($location['rows'] as $row) {
    $md .= sprintf("| %s | %s | %s | %s |\n",
      cell($row['package']),
      cell($row['installed']),
      $row['fixed'],
      implode('<br>', $row['lines']));
  }
  return $md . "\n";
}

function counts(int $packages, int $advisories): string {
  return sprintf('%d %s, %d %s',
    $packages, $packages === 1 ? 'package' : 'packages',
    $advisories, $advisories === 1 ? 'advisory' : 'advisories');
}

/**
 * Extracts the JSON report, tolerating PHP notices printed around it.
 */
function parse_report(string $raw): ?array {
  $start = strpos($raw, '{');
  $end = strrpos($raw, '}');
  if ($start === FALSE || $end === FALSE) {
    return NULL;
  }
  $data = json_decode(substr($raw, $start, $end - $start + 1), TRUE);
  if (!is_array($data) || !isset($data['advisories']) || !is_array($data['advisories'])) {
    return NULL;
  }
  return $data;
}

/**
 * Maps package name to locked version.
 */
function installed_versions(string $lock_file): array {
  $lock = json_decode((string) @file_get_contents($lock_file), TRUE);
  $versions = [];
  foreach (['packages', 'packages-dev'] as $key) {
    foreach ($lock[$key] ?? [] as $package) {
      if (isset($package['name'], $package['version'])) {
        $versions[$package['name']] = $package['version'];
      }
    }
  }
  return $versions;
}

/**
 * Pads to four numeric parts so 2.41 and 2.41.0 compare equal.
 */
function normalize_version(string $version): ?string {
  $version = ltrim(trim($version), 'vV');
  if (!preg_match('/^(\d+(?:\.\d+)*)(.*)$/', $version, $m)) {
    return NULL;
  }
  $parts = explode('.', $m[1]);
  while (count($parts) < 4) {
    $parts[] = '0';
  }
  return implode('.', $parts) . $m[2];
}

/**
 * Parses an affected-versions string into ranges.
 *
 * Handles "||" or "|" between ranges, and a space or comma between bounds.
 * Returns NULL for anything else (^, ~, *, !=, dev branches).
 */
function parse_ranges(string $constraint): ?array {
  $constraint = trim($constraint);
  if ($constraint === '') {
    return NULL;
  }
  $ranges = [];
  foreach (preg_split('/\s*\|\|?\s*/', $constraint) as $segment) {
    $segment = preg_replace('/(>=|<=|==|>|<|=)\s+/', '$1', trim($segment));
    $tokens = preg_split('/[\s,]+/', $segment, -1, PREG_SPLIT_NO_EMPTY);
    if (!$tokens) {
      return NULL;
    }
    $range = [
      'min' => NULL,
      'min_inc' => TRUE,
      'max' => NULL,
      'max_inc' => FALSE,
      'max_raw' => '',
    ];
    foreach ($tokens as $token) {
      preg_match('/^(>=|<=|==|>|<|=)?(.*)$/', $token, $m);
      $version = normalize_version($m[2]);
      if ($version === NULL) {
        return NULL;
      }
      $op = $m[1];
      if ($op === '>' || $op === '>=') {
        $range['min'] = $version;
        $range['min_inc'] = $op === '>=';
      }
      elseif ($op === '<' || $op === '<=') {
        $range['max'] = $version;
        $range['max_inc'] = $op === '<=';
        $range['max_raw'] = ltrim($m[2], 'vV');
      }
      else {
        $range['min'] = $range['max'] = $version;
        $range['min_inc'] = $range['max_inc'] = TRUE;
        $range['max_raw'] = ltrim($m[2], 'vV');
      }
    }
    $ranges[] = $range;
  }
  return $ranges;
}

/**
 * Whether a point falls in a range.
 *
 * A point is [version, above]: above=TRUE means "just past version", which is
 * where a <=X bound leaves off.
 */
function in_range(array $point, array $range): bool {
  [$version, $above] = $point;
  if ($range['min'] !== NULL) {
    $c = version_compare($version, $range['min']);
    if ($above ? $c < 0 : ($c < 0 || ($c === 0 && !$range['min_inc']))) {
      return FALSE;
    }
  }
  if ($range['max'] !== NULL) {
    $c = version_compare($version, $range['max']);
    if ($above ? $c >= 0 : ($c > 0 || ($c === 0 && !$range['max_inc']))) {
      return FALSE;
    }
  }
  return TRUE;
}

/**
 * Finds the first version at or above $installed outside every range.
 *
 * Follows ranges that touch end to end, so ">=7.3.0,<7.4.0|>=7.4.0,<7.4.22"
 * from 7.3.x gives 7.4.22, not 7.4.0.
 *
 * @return array
 *   ['kind' => 'version'|'after'|'none'|'raw', 'version' => ..., 'range' => ...]
 */
function fixed_in(string $installed, string $constraint): array {
  $raw = ['kind' => 'raw', 'version' => NULL, 'range' => $constraint];
  $version = normalize_version($installed);
  $ranges = parse_ranges($constraint);
  if ($version === NULL || $ranges === NULL) {
    return $raw;
  }

  $point = [$version, FALSE];
  $display = '';
  for ($i = 0; $i <= count($ranges); $i++) {
    $containing = array_filter($ranges, fn($r) => in_range($point, $r));
    if (!$containing) {
      // Installed outside every range means the data disagrees with the lock.
      if ($i === 0) {
        return $raw;
      }
      return ['kind' => $point[1] ? 'after' : 'version', 'version' => $display, 'range' => $constraint];
    }
    $best = NULL;
    foreach ($containing as $range) {
      if ($range['max'] === NULL) {
        return ['kind' => 'none', 'version' => NULL, 'range' => $constraint];
      }
      if ($best === NULL) {
        $best = $range;
        continue;
      }
      $c = version_compare($range['max'], $best['max']);
      if ($c > 0 || ($c === 0 && $range['max_inc'])) {
        $best = $range;
      }
    }
    $point = [$best['max'], $best['max_inc']];
    $display = $best['max_raw'];
  }
  return $raw;
}

/**
 * Reduces per-advisory fixes to one cell: the version that clears them all.
 */
function combine_fixes(array $fixes): string {
  foreach ($fixes as $fix) {
    if ($fix['kind'] === 'none') {
      return 'No fix released';
    }
  }
  $raw = array_unique(array_column(array_filter($fixes, fn($f) => $f['kind'] === 'raw'), 'range'));
  if ($raw) {
    // Code spans render entities literally, so only the table pipe is escaped.
    return 'Check advisory: ' . implode('<br>', array_map(fn($r) => '`' . str_replace('|', '\\|', preg_replace('/\s+/', ' ', $r)) . '`', $raw));
  }
  $best = NULL;
  foreach ($fixes as $fix) {
    if ($best === NULL) {
      $best = $fix;
      continue;
    }
    $c = version_compare(normalize_version($fix['version']), normalize_version($best['version']));
    if ($c > 0 || ($c === 0 && $fix['kind'] === 'after')) {
      $best = $fix;
    }
  }
  return $best['kind'] === 'after' ? 'after ' . cell($best['version']) : cell($best['version']);
}

/**
 * One advisory as "[ID](link): Title".
 */
function advisory_line(array $advisory): string {
  $id = (string) ($advisory['advisoryId'] ?? '');
  $title = trim((string) ($advisory['title'] ?? ''));
  // Drupal.org titles end with " - SA-CONTRIB-…", which the link already shows.
  if ($id !== '') {
    $title = preg_replace('/\s*-\s*' . preg_quote($id, '/') . '$/i', '', $title);
  }
  $link = (string) ($advisory['link'] ?? '');
  $label = cell($id !== '' ? $id : 'advisory');
  if ($link !== '') {
    $label = '[' . $label . '](' . str_replace([' ', ')'], ['%20', '%29'], $link) . ')';
  }
  return $title === '' ? $label : $label . ': ' . cell($title);
}

/**
 * Escapes text for a markdown table cell.
 */
function cell(string $text): string {
  $text = htmlspecialchars(preg_replace('/\s+/', ' ', $text), ENT_QUOTES);
  return str_replace('|', '\|', $text);
}

/**
 * Heading plus the repo, ref, commit, and date the audit ran against.
 */
function header_line(): string {
  $repo = getenv('GITHUB_REPOSITORY') ?: 'this repository';
  $ref = getenv('GITHUB_REF_NAME') ?: '';
  $sha = substr((string) getenv('GITHUB_SHA'), 0, 7);
  $scope = getenv('NO_DEV') === 'true' ? 'production packages only' : 'including dev packages';
  $meta = array_filter([
    $ref !== '' ? '`' . $ref . '`' . ($sha !== '' ? ' @ `' . $sha . '`' : '') : '',
    gmdate('Y-m-d'),
    $scope,
  ]);
  return "### Security advisories: `{$repo}`\n\n" . implode(' · ', $meta);
}

function write_summary(string $markdown): void {
  $file = getenv('GITHUB_STEP_SUMMARY');
  if ($file) {
    file_put_contents($file, $markdown, FILE_APPEND);
  }
  else {
    echo "\n" . $markdown;
  }
}

function write_output(string $name, string $value): void {
  $file = getenv('GITHUB_OUTPUT');
  if ($file) {
    file_put_contents($file, "{$name}={$value}\n", FILE_APPEND);
  }
}
