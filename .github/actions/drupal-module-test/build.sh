#!/usr/bin/env bash
#
# Builds a drupal/recommended-project site with the module at MODULE_PATH
# installed from a symlinked path repository, plus drupal/core-dev and the
# module's own require-dev packages - Composer never installs a dependency's
# require-dev, so they are required here.
#
# Env: MODULE_PATH, PROJECT_DIR, DRUPAL, STABILITY.
# Outputs: drupal_root, module_dir, package.

set -euo pipefail

if ! command -v composer >/dev/null 2>&1; then
  echo "::error::Composer is not installed. Run setup-runner with a php_version before this action."
  exit 1
fi

if [ ! -f "${MODULE_PATH%/}/composer.json" ]; then
  echo "::error::No composer.json in '${MODULE_PATH}'. Check the module_path input."
  exit 1
fi
source_dir="$(cd "$MODULE_PATH" && pwd -P)"

# Reads the module's composer.json: its package name, or its require-dev as
# "package:constraint" lines.
read_module() {
  php -r '
    $c = json_decode(file_get_contents($argv[1]), TRUE);
    if (!is_array($c)) { fwrite(STDERR, "composer.json is not valid JSON\n"); exit(1); }
    if ($argv[2] === "name") { echo $c["name"] ?? ""; exit; }
    foreach ($c["require-dev"] ?? [] as $p => $v) { echo "$p:$v\n"; }
  ' "$source_dir/composer.json" "$1"
}

package="$(read_module name)"
if [ -z "$package" ]; then
  echo "::error::${MODULE_PATH}/composer.json has no \"name\"; it is needed to require the module."
  exit 1
fi

if [ -e "$PROJECT_DIR" ]; then
  echo "::error::'${PROJECT_DIR}' already exists. Run this action once per job."
  exit 1
fi

echo "::group::Create drupal/recommended-project:${DRUPAL} (${STABILITY})"
composer create-project "drupal/recommended-project:${DRUPAL}" "$PROJECT_DIR" \
  --no-install --no-interaction --stability="$STABILITY"
echo "::endgroup::"

cd "$PROJECT_DIR"

# Not needed for testing, and an unlisted plugin stops a non-interactive run.
composer config --no-plugins allow-plugins.tbachert/spi false

# A named repository is prepended, so the checkout wins over any published copy.
composer config repositories.module "$(php -r '
  echo json_encode(["type" => "path", "url" => $argv[1], "options" => ["symlink" => TRUE]], JSON_UNESCAPED_SLASHES);
' "$source_dir")"

composer require --no-update "${package}:*@dev"
# The template allows any later minor, which --with-all-dependencies would
# install; pin core to the requested constraint.
composer require --no-update "drupal/core-recommended:${DRUPAL}"
composer require --dev --no-update "drupal/core-dev:${DRUPAL}"
read_module require-dev | xargs -r composer require --dev --no-update

echo "::group::Install"
composer update --no-interaction --no-progress --with-all-dependencies
echo "::endgroup::"

# `composer show --path` reports the symlink's target, not where Drupal sees
# the module, so find the symlink instead.
module_dir=""
while IFS= read -r link; do
  if [ "$(cd "$link" && pwd -P)" = "$source_dir" ]; then
    module_dir="$PROJECT_DIR/$link"
    break
  fi
done < <(find web/modules web/themes web/profiles -maxdepth 2 -type l 2>/dev/null)

if [ -z "$module_dir" ]; then
  echo "::error::${package} was installed, but no symlink to it was found under web/modules, web/themes or web/profiles. Is its composer.json type a Drupal extension type?"
  exit 1
fi

mkdir -p web/sites/simpletest/browser_output

echo "Installed ${package} at ${module_dir}"
{
  echo "drupal_root=${PROJECT_DIR}"
  echo "module_dir=${module_dir}"
  echo "package=${package}"
} >> "$GITHUB_OUTPUT"
