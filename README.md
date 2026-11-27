# devops-shared-actions

Shared GitHub Actions for daggerhartlab projects. Consuming workflows
reference these directly instead of copying `action.yml` files between
repos.

## Versioning

Pin consumers to a major version tag (e.g. `@v1`), not `@main` — this repo
pins its own third-party action dependencies to commit SHAs for exactly
that reason (see `.github/actions/setup-runner/action.yml`), and consumers
should extend the same discipline to this repo. `v1` is tagged and is what
every example below uses.

## Actions in this repo

- [`setup-runner`](#setup-runner) — installs SSH, Node, PHP/Composer,
  Pantheon Terminus, Acquia CLI, WP-CLI, OpenVPN, Pandoc as needed.
- [`site-build`](#site-build) — builds a deployable artifact: Composer
  (no-dev), an optional Laravel Mix theme build, optional git-based
  artifact-repo prep, and an optional deploy version file.
- [`wp-core-update`](#wp-core-update) — updates WordPress core files,
  verifies them against WordPress.org checksums, and opens a PR.
- [`drupal-module-version`](#drupal-module-version) — stamps a release
  version and datestamp into a custom Drupal module's `*.info.yml`, creates
  the release tag on the packaging commit, and publishes a GitHub Release.
- [`drupal-module-test`](#drupal-module-test) — builds a Drupal site around a
  custom module's checkout and runs its PHPUnit tests, PHPCS and PHPStan.
- [`wp-plugin-version`](#wp-plugin-version) — stamps a release version into a
  WordPress plugin's header and `readme.txt` Stable tag on its primary branch,
  and pushes the branch with a new release tag on that commit.
- [`composer-audit`](#composer-audit) — runs `composer audit` against
  `composer.lock` and writes security advisories to the job summary, one row
  per package with the version that fixes them.
- [`composer-diff`](#composer-diff) — comments on a pull request with the
  package changes in each `composer.lock`, and keeps that comment current.

Reusable workflows:

- [`claude-review`](#claude-review) — reviews pull requests with Claude,
  following the consumer's `REVIEW.md`, and re-reviews on an `@claude`
  comment.
- [`claude-review-github-issues`](#claude-review-github-issues) — the same
  review, plus the GitHub issues the PR is linked to, for repos that track
  work in GitHub issues instead of Jira.

The actions are composite actions; see each `action.yml` (or the workflow
file) for the full, authoritative list of inputs and defaults. This README
covers common usage patterns and the gotchas we've already hit.

---

## `setup-runner`

PHP/Composer and Node always install — their version inputs have non-empty
defaults (`php_version: '8.3'`, `node_version: '20.x'`), so their steps run
on every consumer. Pass an empty string (`php_version: ''`) to skip one.

Every other tool — Terminus, Acquia CLI, WP-CLI, OpenVPN, Pandoc — only runs
if its relevant input is provided.

The on/off flags (`configure_git_identity`, `install_wp_cli`,
`install_openvpn`, `install_pandoc`) take `'true'` to enable. Omitting them,
or passing `'false'`, `'0'`, `'no'`, or `'off'`, disables them — so
`install_wp_cli: 'false'` does what it looks like it does.

### No Drush

`setup-runner` deliberately does not install Drush. Modern Drupal sites get
Drush from their own `composer.json`, so `site-build`'s `composer install`
already puts it at `vendor/bin/drush` — call it from there.

The `install_drush`/`drush_version` inputs were removed because they could
not do their job: Composer's advisory-blocking policy rejects every Drush 8,
9, and 10 release (their pinned `symfony/yaml` and `symfony/process`
constraints all resolve to versions with published advisories), and those are
exactly the versions a legacy site would want. Only Drush 11+ installs
cleanly — and any site new enough for Drush 11 already has its own. For a
Drupal 7 site that genuinely needs Drush 8, use the Drush 8 phar or a
project-local Composer install rather than a global `composer require`.

### Basic PHP/Composer setup (no deployment)

```yaml
- uses: daggerhartlab/devops-shared-actions/.github/actions/setup-runner@v1
  with:
    php_version: '8.3'
    php_extensions: gd, curl, bcmath
```

### Running CI tests (PHPUnit)

Unit tests need no extra extensions. Kernel/Functional tests need a real DB
connection, so add `pdo_mysql` (and whatever your test suite touches):

```yaml
# Unit
- uses: daggerhartlab/devops-shared-actions/.github/actions/setup-runner@v1
  with:
    php_version: '8.3'
    php_extensions: gd, curl, bcmath

# Kernel / Functional (alongside a DB service container in the job)
- uses: daggerhartlab/devops-shared-actions/.github/actions/setup-runner@v1
  with:
    php_version: '8.3'
    php_extensions: gd, curl, bcmath, mbstring, dom, xml, pdo_mysql, zip, intl
```

### Private Composer packages

By default Composer authenticates to GitHub with the workflow's
`GITHUB_TOKEN`, which can only read the repo the workflow runs in. A package
pulled from another private repo (a `vcs` repository in `composer.json`)
fails to download with an HTTP 404, not a 403.

Pass a token that can read those repos as `composer_github_token`:

```yaml
- uses: daggerhartlab/devops-shared-actions/.github/actions/setup-runner@v1
  with:
    php_version: '8.3'
    composer_github_token: ${{ secrets.COMPOSER_GITHUB_TOKEN }}
```

`setup-runner` exports it as `COMPOSER_AUTH` for the rest of the job, so
`site-build`, `composer-audit`, and any later `composer` step use it without
further configuration. The action can't default to an org secret, because
composite actions have no access to `secrets`, so each workflow has to pass it.

Use a fine-grained token with read-only **Contents** access to just the
private package repos. Composer runs package plugins and scripts during
install, and any of them can read `COMPOSER_AUTH`, so don't reuse a token that
can push or open PRs.

### Deploying to Pantheon

A full, working deploy job looks like this — see **Known gotchas** below
for why `fetch-depth: 0` and `configure_git_identity` both matter here:

```yaml
jobs:
  build:
    runs-on: ubuntu-latest
    steps:
      - uses: actions/checkout@v7
        with:
          # Pantheon rejects pushes from a shallow clone.
          fetch-depth: 0

      - uses: daggerhartlab/devops-shared-actions/.github/actions/setup-runner@v1
        with:
          configure_git_identity: 'true'
          ssh_key: ${{ secrets.PANTHEON_PRIVATE_SSH }}
          pantheon_machine_token: ${{ secrets.PANTHEON_MACHINE_TOKEN }}
          php_version: '8.3'
          php_extensions: gd, curl, bcmath
          terminus_version: '^3.0'
          terminus_build_tools_version: '^3.0'

      - uses: daggerhartlab/devops-shared-actions/.github/actions/site-build@v1
        with:
          laravel_mix_theme_path: web/themes/custom/my_theme
          artifact_repo_gitignore: .gitignore.pantheon
          artifact_repo_cleanup_paths: |
            web
            vendor

      # setup-runner already ran `terminus auth:login` with the machine
      # token above, and this is the same job — no need to log in again.
      - name: Push to Pantheon
        env:
          pantheon_site: 'my-site'
          pantheon_env: 'dev'
        run: |
          terminus build:env:push $pantheon_site.$pantheon_env --message="CI Deployment" -v
```

Note: never interpolate `${{ secrets.* }}` directly into a `run:` shell
string. Pass it through `env:` and reference it as a shell variable:

```yaml
        env:
          PANTHEON_MACHINE_TOKEN: ${{ secrets.PANTHEON_MACHINE_TOKEN }}
        run: |
          terminus auth:login --machine-token="$PANTHEON_MACHINE_TOKEN"   # good
          terminus auth:login --machine-token=${{ secrets.X }}            # bad
```

This avoids GitHub's documented script-injection risk for expression
interpolation in shell blocks.

### `ssh_config` examples by host

The SSH setup step only runs when **both** `ssh_key` and `ssh_config` are
set. `ssh_config` on its own does nothing — if you override it without also
passing `ssh_key`, no SSH config is written at all and the step is silently
skipped.

`ssh_config`'s default (`Host *.drush.in\n  StrictHostKeyChecking no`)
covers Pantheon. For other hosts, override it (alongside `ssh_key`):

**Acquia:**
```yaml
    ssh_config: |
      Host *.acquia-sites.com
        StrictHostKeyChecking no
```

**GitHub (e.g. pushing to another repo):**
```yaml
    ssh_config: |
      Host github.com
        StrictHostKeyChecking no
```

Multiple hosts can be chained in one value:
```yaml
    ssh_config: |
      Host *.drush.in
        StrictHostKeyChecking no
      Host *.acquia-sites.com
        StrictHostKeyChecking no
```

### Deploying to Acquia

Acquia CLI authenticates with its own key/secret, so this needs no SSH
setup:

```yaml
- uses: daggerhartlab/devops-shared-actions/.github/actions/setup-runner@v1
  with:
    php_version: '8.3'
    acli_key: ${{ secrets.ACQUIA_CLI_KEY }}
    acli_secret: ${{ secrets.ACQUIA_CLI_SECRET }}
```

If you also push to Acquia over git, add **both** `ssh_key` and the Acquia
`ssh_config` from the section above — neither works without the other.

### WP-CLI (WordPress)

Installed from the pinned release phar, landing at `/usr/local/bin/wp`:

```yaml
- uses: daggerhartlab/devops-shared-actions/.github/actions/setup-runner@v1
  with:
    php_version: '8.3'
    install_wp_cli: 'true'
    wp_cli_version: '2.12.0'   # optional; this is the default
```

`wp_cli_version` is a plain release number with no `v` prefix — the action
adds it when building the download URL. Pinning means a given tag of this
action always installs the same WP-CLI, so bump the default here when you
want the newer one; it won't drift on its own.

---

## `site-build`

`composer install --no-dev` always runs. Five further steps are
independently opt-in — each only runs if its own input is set, with no
coupling between them:

| Input | Step it enables |
|---|---|
| *(always runs)* | `composer install --no-dev` (production dependencies) |
| `theme_composer_path` | `composer install --no-dev` in that theme directory (for themes with their own `composer.json`, e.g. Timber or Sage) |
| `laravel_mix_theme_path` | `npm ci && npx mix --production` in that theme directory, then deletes its `node_modules` |
| `artifact_repo_gitignore` | Replace `.gitignore` with the given file |
| `artifact_repo_cleanup_paths` | Remove nested `.git` directories under the given paths |
| `deploy_version_file` | Write a JSON record of this build to the given path |

Note the `node_modules` deletion: it keeps build-time dependencies out of
the deployable artifact, but it also means any npm step you run *after*
`site-build` in the same job has to reinstall first.

### Building a Laravel Mix theme only

```yaml
- uses: daggerhartlab/devops-shared-actions/.github/actions/site-build@v1
  with:
    laravel_mix_theme_path: web/themes/custom/my_theme
```

### Theme with its own Composer dependencies

Some themes (Timber, Sage) keep a `composer.json` of their own. Set
`theme_composer_path` to install it; it's separate from
`laravel_mix_theme_path`, so it also works for themes built some other way.
Usually both point at the same directory:

```yaml
- uses: daggerhartlab/devops-shared-actions/.github/actions/site-build@v1
  with:
    theme_composer_path: web/wp-content/themes/my_theme
    laravel_mix_theme_path: web/wp-content/themes/my_theme
```

The theme's `vendor/` must not be ignored by the artifact repo's
`.gitignore`, or it won't be deployed.

### Preparing a git-based artifact repo only (no theme)

Git-based deploy targets (Pantheon, Acquia) reject a repo that contains
nested `.git` directories (e.g. from vendor packages), and typically need a
different `.gitignore` than your dev repo's — one that commits `vendor/`
and `web/core` instead of ignoring them:

```yaml
- uses: daggerhartlab/devops-shared-actions/.github/actions/site-build@v1
  with:
    artifact_repo_gitignore: .gitignore.pantheon
    artifact_repo_cleanup_paths: |
      web
      vendor
```

`artifact_repo_cleanup_paths` takes a newline-separated list (composite
actions have no native array input type — same convention `actions/cache`
uses for its own `path:` input).

### Recording what was deployed

Set `deploy_version_file` to write a JSON file describing the build into
the artifact, so a deployed site can report which source commit it's
running and when it was built:

```yaml
- uses: daggerhartlab/devops-shared-actions/.github/actions/site-build@v1
  with:
    artifact_repo_gitignore: .gitignore.pantheon
    deploy_version_file: .deploy-version.json
```

```json
{
  "deployed_at": "2026-10-19T17:42:03Z",
  "commit": "35c33de54a1f…",
  "repository": "daggerhartlab/my-site",
  "actor": "jdaggerhart",
  "run_url": "https://github.com/daggerhartlab/my-site/actions/runs/123456789",
  "tag_count": 13,
  "version": "2026.10.19.r13"
}
```

`deployed_at` is build time, which in a deploy job is seconds before the
push. `commit` is the checked-out `HEAD`, not the artifact repo's commit.
`tag_count` is the number of tags on `origin` plus one, read with
`git ls-remote` so it doesn't depend on `fetch-depth` or fetched tags. It
needs the credentials `actions/checkout` persists by default, so don't set
`persist-credentials: false` on a private repo.

`version` is `YYYY.MM.DD.r<tag_count>`, built from the same UTC timestamp
as `deployed_at`. It's the name this deploy would be released under. The `r`
number is the tag this deploy would add, so it only goes up when a tag is
pushed. Two deploys on the same day with no tag between them get the same
`version`. To make it unique per deploy, tag each one with it, as below. The
action itself never creates tags. It's also exposed as the `deploy_version`
step output:

```yaml
permissions:
  contents: write  # only needed to push the tag below

# Overlapping runs would compute the same version and collide on the tag.
concurrency:
  group: deploy
  cancel-in-progress: false

steps:
  - uses: daggerhartlab/devops-shared-actions/.github/actions/setup-runner@v1
    with:
      configure_git_identity: 'true'  # git tag -a needs an identity

  - id: site-build
    uses: daggerhartlab/devops-shared-actions/.github/actions/site-build@v1
    with:
      deploy_version_file: .deploy-version.json

  # Optional: tag the source commit with the build's version. Local only
  # until the deploy succeeds. Create it before any step that commits, so
  # HEAD is still the source commit.
  - name: Create release tag
    shell: bash
    env:
      VERSION: ${{ steps.site-build.outputs.deploy_version }}
    run: git tag -a "$VERSION" -m "Deploy $VERSION"

  # ... deploy steps (e.g. terminus build:env:push) ...

  - name: Push release tag
    shell: bash
    env:
      VERSION: ${{ steps.site-build.outputs.deploy_version }}
    run: git push origin "refs/tags/$VERSION"
```

A failed deploy never pushes its tag, so the `r` number only counts
successful deploys. `terminus build:env:push` pushes only the branch to
Pantheon, not tags, so the local tag doesn't leak there either.

The file only exists on the runner, so add it to the dev repo's
`.gitignore` to keep a stray local copy out, and make sure the artifact
repo's `.gitignore` doesn't match it. Whether a dotfile inside the docroot
is web-readable depends on the host; Pantheon blocks it, so
`.deploy-version.json` at the root of a WordPress repo is not publicly
served there.

### Both together

See the full "Deploying to Pantheon" example above — that's the real
combination used in production.

---

## Known gotchas

These cost real debugging time once already; documenting them so they
don't cost it again.

- **Shallow checkout breaks git-push deploys.** `actions/checkout` defaults
  to `fetch-depth: 1` (a single commit, shallow clone). Any workflow that
  pushes the checked-out repo to another git remote (e.g.
  `terminus build:env:push` to Pantheon) will fail with `shallow update not
  allowed`, because most git servers reject pushes from a shallow clone.
  Fix: set `fetch-depth: 0` on the `actions/checkout` step in any workflow
  that deploys via git push.

- **`configure_git_identity` is off by default.** `setup-runner`'s "Prepare
  Git" step (which sets `git config user.name`/`user.email` from the last
  commit's author) only runs if you pass `configure_git_identity: 'true'`.
  It's needed before `terminus build:env:push`, which creates a commit
  against Pantheon's git remote and needs an identity to attribute it to.
  Workflows that don't push anywhere (e.g. running tests) should leave this
  unset — no reason to mutate the runner's git config as a side effect.

- **The Terminus build-tools plugin install disables Composer's
  advisory-blocking policy, narrowly.** `terminus-build-tools-plugin` pins
  old, frozen dependencies that now carry security advisories, and its
  internal `composer require`/`update` call gets rejected by Composer's
  resolver-level advisory block. `setup-runner` works around this by
  toggling `policy.advisories.block` off immediately before the plugin
  install and back on immediately after — see the comments in
  `.github/actions/setup-runner/action.yml` for the full reasoning. This is
  handled automatically; no action needed from consumers, but worth knowing
  if you're debugging a future Composer/advisory error in this step.

---

## `wp-core-update`

Updates WordPress core and opens a PR for review. Intended to be run by hand
from the Actions tab when a site needs a core bump.

```yaml
name: Update WordPress core

on:
  workflow_dispatch:
    inputs:
      version:
        description: 'WordPress version (blank = latest)'
        required: false

permissions:
  contents: write
  pull-requests: write

jobs:
  update:
    runs-on: ubuntu-latest
    steps:
      - uses: actions/checkout@v7

      - uses: daggerhartlab/devops-shared-actions/.github/actions/setup-runner@v1
        with:
          php_version: '8.3'
          node_version: ''
          install_wp_cli: 'true'

      - uses: daggerhartlab/devops-shared-actions/.github/actions/wp-core-update@v1
        with:
          version: ${{ inputs.version }}
          token: ${{ secrets.LABBY_GITHUB_TOKEN_GENERIC }}
```

The PR targets whichever branch you selected when dispatching the workflow,
so `develop` and `master` sites both work without configuration. The branch
is `wp-core-update/<version>`; re-running for the same version force-updates
that branch and leaves the existing PR open.

Outputs: `updated`, `from_version`, `to_version`, `branch`, `pr_url`.

### Gotchas

- **It updates files, not the database.** `wp core update` bootstraps
  WordPress and therefore needs a database, which CI does not have — your
  `wp-config.php` reads credentials from `wp-config-local.php` or
  `PANTHEON_ENVIRONMENT`. This action uses `wp core download --force
  --skip-content` instead, which replaces core files (and drops files the new
  version no longer ships) without loading WordPress. The database half stays
  where it already lives: `terminus wp ... core update-db` in your deploy
  workflow, after the PR merges.

- **Pass a PAT or the PR gets no CI.** A pull request opened with the default
  `GITHUB_TOKEN` deliberately does not trigger other workflows, so the site's
  own tests will not run on it. Pass a personal access token as `token` to get
  checks. The same token is used for the push, since a push made with
  `GITHUB_TOKEN` will not fire `pull_request: synchronize` either.

- **Do not also pass `configure_git_identity` to `setup-runner`.** This action
  sets the git identity to the person who ran the workflow (via
  `<id>+<login>@users.noreply.github.com`, which is what links the commit to a
  GitHub profile). `configure_git_identity` sets it from the last commit's
  author instead, and whichever runs last wins.

- **`wp-content` is left alone**, which means the default themes core bundles
  (`twentytwentyfive` and friends) are not updated. That is the right default
  — clobbering `wp-content` in a repo where themes and plugins are tracked
  would be far worse — but those themes need updating separately.

- **`wp_path` is usually unnecessary.** WP-CLI reads `path:` from a
  `wp-cli.yml` in the working directory, which most of our WP repos already
  have (`web/wp`, `wp`, or `web`). Only set `wp_path` when there is no
  `wp-cli.yml` and core is not at the repo root.

---

## `drupal-module-version`

Gives a custom Drupal module a version number. Drupal reads an extension's
version from its `*.info.yml`, and drupal.org's packaging script writes that
key at release time — which is why a contrib module downloaded from
drupal.org reports a version and the same module cloned from git does not. A
custom module that Composer installs from a git repository is in the second
category, so Drupal shows no version and anything reporting on the site sees
`null`.

This action does the same job as a release step you run by hand: write the
version and datestamp into every info file, commit, create the release tag
on that commit, and publish a GitHub Release for it with GitHub's generated
"What's Changed" notes. The packaging commit is only reachable through the tag, so the
default branch stays unversioned.

```yaml
name: Release

on:
  workflow_dispatch:
    inputs:
      version:
        description: 'Version to release, e.g. 1.2.0'
        required: true

permissions:
  contents: write

jobs:
  release:
    runs-on: ubuntu-latest
    steps:
      - uses: actions/checkout@v7
        with:
          # Required — the action pushes, and a shallow clone cannot be
          # pushed from. The action fails fast if you forget.
          fetch-depth: 0

      - uses: daggerhartlab/devops-shared-actions/.github/actions/drupal-module-version@v1
        with:
          version: ${{ inputs.version }}
```

To release, run the workflow from the Actions tab, choosing the branch to
release from and entering the version. Drop it into any custom module
repository unchanged: the info files are found by glob, so nothing is
hardcoded per module.

Pass `release: false` to stop at the tag. A version containing a hyphen, such
as `1.3.0-beta1`, is published as a pre-release.

Outputs: `stamped`, `version`, `tag`, `datestamp`, `files`, `commit`,
`previous_tag`, `release_url`.

### Gotchas

- **Don't push release tags by hand.** Packagist records a tag's commit the
  moment the tag is pushed and never re-reads it, so a tag has to be born on
  the stamped commit. That is why the action creates the tag rather than
  reacting to one, and why it refuses a version whose tag already exists —
  locally or on `origin` — instead of moving it. A tag pushed by hand gives
  Packagist an unstamped release that can't be fixed in place; release the
  next version instead.
- **The tag points at a commit no branch contains.** That is deliberate —
  Composer installs a git-sourced package by cloning the tag, so the version
  has to be in the tag's tree to reach the installed module. A tarball
  attached to a GitHub Release would never be fetched.
- **A leading `v` stays in the tag and is stripped from the version.**
  `v1.2.0` creates tag `v1.2.0` and stamps `1.2.0`. Anything not
  version-shaped, such as `latest`, is an error.
- **It never writes `project`.** drupal.org's script adds one, but a
  `project` key marks an extension as contrib, so fleet tooling resolves it
  against drupal.org — for a private module that is a lookup which can only
  fail. There is deliberately no input to enable it.
- **An existing stamp is replaced, not appended to.** If the checked-out
  commit already carries the version, it is tagged as is with no packaging
  commit (`stamped=false`).
- **The tag push and release won't trigger other workflows** when made with
  the default `GITHUB_TOKEN`, so an `on: release` workflow will not fire. Pass
  a PAT as `token` if something else must run on them. Packagist's webhook is
  unaffected either way.
- **Release notes list pull requests merged since the previous release.**
  Commits pushed straight to the branch only show up in the "Full Changelog"
  compare link. The previous release is the highest earlier version tag
  with the same `v` convention (a stable release skips pre-releases), and is
  passed to GitHub explicitly as `previous_tag`. GitHub can't work it out
  itself: it looks for the last tag in the new tag's history, and each
  release tag sits on its own packaging commit, which is never in the next
  release's history.
- **`module_path` is for repos holding more than one module.** Left unset,
  every tracked `*.info.yml` in the repo is stamped, which is what a
  single-module repo wants. Git's pathspec glob crosses directory
  separators, so submodules nested under the path are included either way.

## `drupal-module-test`

Tests a custom Drupal module in its own repository. A module repo has no site
to test in, so the action builds one: `drupal/recommended-project`, with the
checkout installed from a symlinked path repository (the same layout as local
module development), `drupal/core-dev`, and the module's own `require-dev`.
It then runs the module's tests with core's `phpunit.xml.dist`, and
optionally PHPCS and PHPStan.

For module repos only. A client site is already a Drupal project and runs its
own suites after `composer install`; see
[Running CI tests](#running-ci-tests-phpunit).

```yaml
name: Tests

on:
  pull_request:
    branches: [main]
  push:
    branches: [main]
  workflow_dispatch:

concurrency:
  group: ${{ github.workflow }}-${{ github.ref }}
  cancel-in-progress: true

permissions:
  contents: read

jobs:
  phpunit:
    name: PHPUnit (Drupal ${{ matrix.drupal }}, PHP ${{ matrix.php }})
    runs-on: ubuntu-latest
    # The next-version job reports early breakage without failing the run.
    continue-on-error: ${{ matrix.experimental }}
    strategy:
      fail-fast: false
      matrix:
        include:
          - { drupal: '^11',      php: '8.3', stability: stable, experimental: false }
          - { drupal: '^11',      php: '8.5', stability: stable, experimental: false }
          - { drupal: '11.x-dev', php: '8.5', stability: dev,    experimental: true }
    # Composite actions can't declare services, so the database lives here.
    services:
      mysql:
        image: mysql:8.0
        env:
          MYSQL_ROOT_PASSWORD: root
          MYSQL_DATABASE: drupal
        ports:
          - 3306:3306
        options: >-
          --health-cmd="mysqladmin ping -h 127.0.0.1 -proot"
          --health-interval=5s
          --health-timeout=5s
          --health-retries=20
    steps:
      - uses: actions/checkout@v7

      - uses: daggerhartlab/devops-shared-actions/.github/actions/setup-runner@v1
        with:
          php_version: ${{ matrix.php }}
          php_extensions: gd, pdo_mysql

      - uses: daggerhartlab/devops-shared-actions/.github/actions/drupal-module-test@v1
        with:
          drupal: ${{ matrix.drupal }}
          stability: ${{ matrix.stability }}
          simpletest_db: mysql://root:root@127.0.0.1:3306/drupal

  code-quality:
    name: Code quality
    runs-on: ubuntu-latest
    steps:
      - uses: actions/checkout@v7

      - uses: daggerhartlab/devops-shared-actions/.github/actions/setup-runner@v1
        with:
          php_version: '8.3'

      - uses: daggerhartlab/devops-shared-actions/.github/actions/drupal-module-test@v1
        with:
          phpunit: false
          phpcs: true
          phpstan: true
          # Until the module passes both; remove to make them gate merges.
          report_only: true
```

Inputs, with defaults: `module_path` (`.`), `drupal` (`^11`), `stability`
(`stable`), `phpunit` (on), `phpunit_args`, `simpletest_db`, `phpcs` (off),
`phpcs_standard` (`Drupal,DrupalPractice`), `phpstan` (off), `phpstan_level`
(`2`), `report_only` (off), `artifact_name`.

Outputs: `drupal_root`, `module_dir` (the module inside the built site),
`package`. Use them for extra steps against the built site, e.g.
`working-directory: ${{ steps.test.outputs.drupal_root }}`.

### Gotchas

- **Run `setup-runner` first.** The action uses whatever PHP and Composer are
  on the runner. Kernel and Functional tests need `pdo_mysql` in
  `php_extensions`.
- **Declare test-only dependencies in the module's `require-dev`.** The
  action installs them; nothing else will. A test that installs a contrib
  module the module doesn't require (e.g. `paragraphs` for an integration
  test) fails here even when it passes in a local site that happens to have
  it.
- **PHPUnit runs once per job.** It starts PHP's built-in server on
  `127.0.0.1:8888`, and a second run in the same job is refused rather than
  tested against the first run's site. PHPCS/PHPStan-only runs can repeat.
- **Required check names come from your job names.** With a matrix, the
  values are part of the name (`PHPUnit (Drupal ^11, PHP 8.3)`). Renaming a
  job or changing a matrix value renames the check, and a required check
  that never reports blocks every pull request.
- **Deprecations are reported, not failed.** `SYMFONY_DEPRECATIONS_HELPER`
  defaults to `weak` unless the job sets it.
- **`drupal` pins core, not just the template.** The constraint is applied
  to `drupal/core-recommended` and `drupal/core-dev` as well, so
  `drupal: '~11.3.0'` tests 11.3 rather than the latest minor the template
  allows. Use it to test the lowest minor a module supports.
- **A dev constraint needs `stability: dev`**, e.g. `drupal: 11.x-dev`.
- **`report_only` covers PHPCS and PHPStan, never PHPUnit.** Errors are still
  listed (PHPCS in the job summary, PHPStan as annotations and in the log)
  with a warning, and the step passes.
- **PHPCS warnings never fail the run**, only errors, matching
  `ignore_warnings_on_exit`.
- **The Composer cache is shared across runs.** `setup-runner` keys it on
  `composer.lock`, which a module repo doesn't commit, so every run restores
  the same entry and it isn't refreshed. It still saves most downloads.
- **On a PHPUnit failure**, the browser output and web server log are
  uploaded as an artifact named from the job, Drupal constraint and PHP
  version, so matrix jobs don't collide. Set `artifact_name` to override.

## `wp-plugin-version`

Releases a WordPress plugin with its version in place. Git Updater decides
whether an update is available by reading the `Version:` header from the
remote file and comparing it to the installed one — an update only appears
when remote is greater. That remote file is read from the plugin's **primary
branch** (its `Primary Branch:` header, else the repository default branch),
not from a tag: the newest tag is only used to build the download link, and
Git Updater installs from that tag's tree. So a release needs the version in
both places — on the branch so the update is detected, and in the tag so the
installed plugin reports it. WordPress core reads the same header for the
plugins screen.

Run from the primary branch, this writes the version into the plugin header,
updates `readme.txt`'s Stable tag when there is one, commits, tags that
commit, and pushes the branch and the tag together. The tag is born on a
commit that is already on the branch, and is never moved.

```yaml
name: Release

on:
  workflow_dispatch:
    inputs:
      version:
        description: 'Version to release, e.g. 1.2.0'
        required: true

permissions:
  contents: write

jobs:
  release:
    runs-on: ubuntu-latest
    steps:
      - uses: actions/checkout@v7
        with:
          # Required — the action pushes, and a shallow clone cannot be
          # pushed from. The action fails fast if you forget.
          fetch-depth: 0

      - uses: daggerhartlab/devops-shared-actions/.github/actions/wp-plugin-version@v1
        with:
          version: ${{ inputs.version }}
```

To release, run the workflow from the Actions tab on the primary branch and
enter the version, or `gh workflow run release.yml -f version=1.2.0`. It needs
no per-plugin configuration: the entry file is discovered by looking for the
root-level `*.php` carrying a `Plugin Name:` header, which is how WordPress
itself identifies one.

Bumping the header on the branch yourself first also works: the version is
already there, so the checked-out commit is tagged without a packaging commit
(a stale `readme.txt` Stable tag is still corrected).

Inputs: `version` (required; a leading `v` is kept in the tag and stripped
from the header), `plugin_file`, `token`, `dry_run` (commits and tags locally
but pushes nothing).

Outputs: `stamped`, `version`, `tag`, `branch`, `plugin_file`,
`readme_updated`, `commit`.

### Gotchas

- **Don't push release tags by hand.** A tag pushed before the header is
  bumped points at a commit Git Updater will never offer. The action creates
  the tag, and refuses one that already exists.
- **It refuses to run off the primary branch.** Git Updater only reads the
  version from that branch, so a release cut anywhere else would never be
  offered.
- **It refuses a lower version** than the branch already has, compared the
  way Git Updater does (`1.0.0-beta1` is lower than `1.0.0`).
- **It pushes to the primary branch.** A protected branch rejects a push
  made with `GITHUB_TOKEN`, and GitHub Actions cannot be put on a ruleset's
  bypass list. Pass a token from an actor that can bypass via `token` — a
  GitHub App installation token (e.g. from `actions/create-github-app-token`)
  with the app on the bypass list, or a PAT of a user whose role is. The branch and tag are pushed atomically, so a rejected push
  leaves neither behind, and a branch that moved during the run is a
  non-fast-forward rejection that a re-run fixes.
- **Nothing it pushes triggers other workflows** when using `GITHUB_TOKEN` —
  neither the tag nor the branch commit. Pass a PAT as `token` if, say, a
  tag-push build must run.
- **`readme.txt`'s Stable tag is hygiene, not machinery.** It plays no part in
  Git Updater's comparison — that is the header's job. It is what
  wordpress.org uses to pick the released version, so it is inert for a
  privately distributed plugin but becomes load-bearing the moment one is
  published. A missing readme, or one with no `Stable tag:` line, is a clean
  skip rather than an error.
- **Only the first `Version:` match is rewritten.** A changelog entry, a
  string, or a docblock further down the file is left alone.
- **A committed `vendor/` is safe.** Discovery only looks at root-level PHP
  files, so a vendored package carrying its own `Plugin Name:` header is never
  mistaken for the entry file. Two root-level candidates is an error naming
  the `plugin_file` input, rather than a guess.
- **No datestamp.** That is a drupal.org packaging convention with no
  WordPress equivalent.

---

## `composer-audit`

Runs `composer audit` against `composer.lock` and writes the security
advisories to the run's Summary page, one row per package with the version
that fixes them, in a form that pastes cleanly into an issue or ticket.

```yaml
name: Security audit

on:
  workflow_dispatch:

permissions:
  contents: read

jobs:
  audit:
    runs-on: ubuntu-latest
    steps:
      - uses: actions/checkout@v7

      - uses: daggerhartlab/devops-shared-actions/.github/actions/setup-runner@v1
        with:
          php_version: '8.3'
          node_version: ''

      - uses: daggerhartlab/devops-shared-actions/.github/actions/composer-audit@v1
```

The summary looks like this:

| Package | Installed | Fixed in | Advisories |
|---|---|---|---|
| example/http-client | 2.3.1 | 2.3.4 | [ADVISORY-1](https://example.com/advisory-1): Header injection<br>[ADVISORY-2](https://example.com/advisory-2): Open redirect |
| example/markdown | 1.8.0 | after 1.8.0 | [ADVISORY-3](https://example.com/advisory-3): Denial of service |

A clean project gets a one-line "No security advisories". Both are green runs —
the step fails only when the audit itself could not run.

Inputs: `working_directory` (default `.`), `no_dev` (default `false`, audits
require-dev packages too; `'true'` audits production packages only).
Outputs: `advisory_count`, `package_count`.

### Several Composer directories

For a project with its own `composer.lock` in a subdirectory, such as a
theme or plugin, as well as the root, list each directory on its own line:

```yaml
      - uses: daggerhartlab/devops-shared-actions/.github/actions/composer-audit@v1
        with:
          working_directory: |
            .
            web/themes/custom/example
```

It is still one summary: the counts are totalled at the top, and each
directory gets its own heading with its table, or "No security advisories". A
listed directory with no `composer.lock`, or whose audit fails, shows **Audit
failed** under its heading; the others still report and the step then fails.

### Gotchas

- **"Fixed in" is worked out, not reported.** Composer only gives each
  advisory's affected range. The action takes the range your installed
  version falls in and follows it to its upper bound, through ranges that
  touch end to end (Symfony publishes one per minor), so it is the first
  release on your line that clears every advisory for that package. `after X`
  means the range ends at `<=X` and names no fix; `No fix released` means it
  has no upper bound; anything it can't parse (`^`, `~`, dev branches) shows
  the raw range under `Check advisory`.
- **It audits the lock file, not `vendor/`.** `--locked` means no
  `composer install`, so it is quick — and it reports what is committed, not
  what is deployed if the two have drifted.
- **Abandoned packages and `audit.ignore` entries are never shown.** The
  former is `--abandoned=ignore`; the latter is Composer's own filtering.
- **Only published advisories are reported.** Anything a package registry
  flags in other ways, such as a release marked unsupported, is not an
  advisory and does not appear. Advisories come from the sources Composer
  queries: Packagist (which aggregates the GitHub and FriendsOfPHP databases)
  and any repository that publishes its own, such as packages.drupal.org.

---

## `composer-diff`

Posts one comment on a pull request listing the Composer package changes,
direct dependencies and all dependencies, and edits that same comment on
later pushes instead of adding new ones.

```yaml
name: Composer diff

on:
  pull_request:
    paths:
      - 'composer.lock'
      - '**/composer.lock'

permissions:
  contents: read
  pull-requests: write

jobs:
  diff:
    runs-on: ubuntu-latest
    steps:
      - uses: actions/checkout@v7
        with:
          # Required - the base commit must be in the checkout to diff against.
          fetch-depth: 0

      - uses: daggerhartlab/devops-shared-actions/.github/actions/setup-runner@v1
        with:
          php_version: '8.3'
          node_version: ''

      - uses: daggerhartlab/devops-shared-actions/.github/actions/composer-diff@v1
```

The tables come from
[`ion-bazan/composer-diff`](https://github.com/IonBazan/composer-diff)
(pinned), the same tool `IonBazan/composer-diff-action` runs, so the output
matches that action's `pr` format. Each lock file is named above its tables.

For several lock files, list each directory on its own line. The comment gets
a section per lock, and one with no changes collapses to a single line:

```yaml
      - uses: daggerhartlab/devops-shared-actions/.github/actions/composer-diff@v1
        with:
          working_directory: |
            .
            web/themes/custom/example
```

Inputs: `working_directory` (default `.`), `base` (default: the PR's base
commit), `token` (default `GITHUB_TOKEN`), `dry_run`. Outputs: `changed`,
`comment_file`, `comment_url`.

### Gotchas

- **`fetch-depth: 0` is required.** A shallow checkout does not contain the
  base commit; the action fails saying so rather than reporting no changes.
- **The comment is found by its first line, `<!-- composer-diff -->`.** A
  comment left by an earlier workflow that starts with the same marker is
  taken over and updated in place.
- **No comment is created when nothing changed.** An existing comment is
  still updated, so it never goes stale while the workflow runs.
- **A reverted lock leaves the old comment.** With the `paths:` filter above,
  a push that undoes the lock changes does not run the workflow, so the
  comment still shows them. Drop the filter if that matters; the job is quick.
- **Long diffs are shortened.** GitHub caps a comment at 65,536 characters.
  Past that, the "All Dependencies" tables are replaced by a link to the job
  summary, which always has the full output.
- **A new or deleted lock is not an error.** Its packages show as new or
  removed. A listed directory with no `composer.lock` on either side fails,
  since that is almost always a typo.
- **Pull requests from forks cannot be commented on** with `GITHUB_TOKEN`,
  which is read-only for them.

## `claude-review`

A reusable workflow, not an action: it lives at
`.github/workflows/claude-review.yml` and is called as a whole job with
`uses:`. Claude reviews each non-draft PR once, on open, reopen, or ready for
review, posting one PR review: a summary plus inline comments. Comment
`@claude` on the PR to re-review after new pushes; unfixed issues from
unresolved threads are listed as still open rather than repeated, and
resolved threads aren't raised again.

```yaml
name: Claude code review

on:
  pull_request:
    types: [opened, ready_for_review, reopened]
  issue_comment:
    types: [created]

permissions:
  contents: read
  pull-requests: write

jobs:
  review:
    uses: daggerhartlab/devops-shared-actions/.github/workflows/claude-review.yml@v1
    secrets:
      CLAUDE_CODE_OAUTH_TOKEN: ${{ secrets.LABBY_CLAUDE_CODE_OAUTH_TOKEN }}
      JIRA_EMAIL: ${{ secrets.LABBY_JIRA_EMAIL }}
      JIRA_API_TOKEN: ${{ secrets.LABBY_JIRA_API_TOKEN }}
    with:
      exclude_paths: |
        **/composer.lock
        web/modules/custom/*/lark_exports/**
```

What to review lives in the consumer repo, not here: a `REVIEW.md` at the
repo root sets the scope, what to report, and what to skip, and is read
alongside `CLAUDE.md`. Edit it to tune reviews without touching the workflow.
Without one, Claude reports only correctness bugs and security issues.

Inputs: `include_paths` (default: the whole repo), `exclude_paths` (default
none), `model` (default `claude-opus-5-5`), `effort` (default `medium`),
`timeout_minutes` (default `20`), `jira_url` (default `https://daggerhart.atlassian.net`),
`composer_dirs` (default none). `github_issues` is set by
[`claude-review-github-issues`](#claude-review-github-issues); leave it unset.
Secrets: `CLAUDE_CODE_OAUTH_TOKEN`, required; `JIRA_EMAIL`,
`JIRA_API_TOKEN`, and `COMPOSER_AUTH`, optional.

### Setup

1. Create the token: run `claude setup-token` while logged in to the Claude
   account the reviews should bill to, and copy the token it prints.
2. Save it as an Actions secret. In the `daggerhartlab` org it's the org
   secret `LABBY_CLAUDE_CODE_OAUTH_TOKEN`; a repo in another org needs its
   own repo secret. The caller maps it to the workflow's
   `CLAUDE_CODE_OAUTH_TOKEN` under `secrets:`, as above. `secrets: inherit`
   won't do: it only passes secrets under their own names, and only within
   the same org.
3. Optional, for the Jira ticket: in the `daggerhartlab` org these are the
   org secrets `LABBY_JIRA_EMAIL` and `LABBY_JIRA_API_TOKEN`. Leave both out
   to review without the ticket.
4. Add the caller workflow above and a `REVIEW.md`.

The token doesn't refresh itself; when reviews start failing to
authenticate, create a new one and replace the secret.

### The Jira ticket

With the Jira secrets set, the workflow reads the ticket the PR is for and
gives it to Claude, which uses it to understand what the PR is meant to do
and lists requirements the diff misses or contradicts in the summary. It
looks for the ticket in this order:

1. a link to it on `jira_url` in the PR description, as the PR templates ask
   for: `/browse/KEY-123`, `.../issues/KEY-123`, or a board or list link
   with `selectedIssue=KEY-123`;
2. the branch name prefix, `KEY-123--name`;
3. the PR title prefix, `KEY-123: ...`.

Claude gets the ticket's summary, type, status, parent, and description,
then as many of its newest comments as fit in about 32 KB in all, with a note
saying how many older ones were left out; not attachments, images, or linked
issues. If there's no ticket or it can't be read, the review goes ahead
without it and the run shows a warning. The job summary names the ticket used.

The token must be a scoped API token with only `read:jira-work`, so it can't
change anything in Jira even though its account can. It sees whatever
projects its account can browse. Scoped tokens only work through
`api.atlassian.com`, so the workflow looks up the site's cloud ID from
`jira_url`.

Ticket text goes to Claude with the code, and anything Claude takes from it
can show up in the review. Like the PR description, it's treated as material
to review, never as instructions. To change how much weight it gets, say so
in `REVIEW.md`, e.g. "Missed acceptance criteria are inline comments" or
"Don't report missing requirements".

### Dependencies

The checkout only has what the repo commits. On a site that gitignores
`vendor/` and `web/modules/contrib`, Claude can't read the contrib code the
PR calls, such as Commerce's services and entities, and is told not to guess
at it. List the directories to install in `composer_dirs`, one per line,
relative to the repo root: `.` for the root, plus any theme or plugin with
its own lock.

```yaml
    with:
      composer_dirs: |
        .
        wp-content/themes/example
```

This runs `composer install --no-scripts --ignore-platform-reqs` in each,
from the PR head's locks, so a PR that adds a package is reviewed against
it. Plugins stay on, so packages land at their installer paths and
`composer-patches` patches apply. The PR's `composer.json` scripts don't
run, and since nothing boots the site, the runner's own PHP is used,
whatever version the site needs. Any `CLAUDE.md` that a package ships is
removed afterwards so it can't become a review rule.

A failed install (a private package, a patch that won't apply, a directory
with no `composer.json`) doesn't fail the run: it shows a warning, the other
directories still install, and Claude is told which ones are missing. For
private packages, pass Composer's auth JSON as the `COMPOSER_AUTH` secret.
Each directory adds up to a minute or so to each review.

Installed code is read only when Claude looks something up in it; it isn't
part of the diff and costs nothing otherwise.

### Limiting the review to some paths

Both path inputs take one git glob per line, relative to the repo root: `*`
stays within a directory, `**` crosses them. A pattern matches from the
root, so `vendor/**` only covers the top-level `vendor/`; use `**/vendor/**`
for every `vendor/` directory, and `**/composer.lock` for every lock file,
whose diffs are long and rarely worth a review. Files outside `include_paths`,
or matching `exclude_paths`, are removed from the diff before Claude sees it,
so large exported content or vendored code costs nothing to skip. A PR with
no changed file left in scope is not reviewed; on an `@claude` request, a
one-line comment says so. The job summary shows how many files were reviewed
and left out.

Use `include_paths` where most committed code isn't yours, as on WordPress
sites that commit core and third-party plugins: a plugin update PR would
otherwise send Claude hundreds of thousands of diff lines.

```yaml
    with:
      include_paths: |
        composer.json
        wp-content/mu-plugins/example/**
        wp-content/themes/example/**
      exclude_paths: |
        wp-content/themes/example/vendor/**
```

Use the path inputs for whole files that should never be reviewed, and
`REVIEW.md` for anything that needs judgment.

### Gotchas

- **Don't add workflow-level `concurrency` in the caller.** Every comment on
  a PR starts a run, and a workflow-level group would let any comment cancel
  a review in progress. The workflow sets job-level concurrency itself.
- **The caller must grant both permissions.** A reusable workflow can only
  narrow the caller's permissions. Claude works with the job's own
  `GITHUB_TOKEN`, so the Claude GitHub App doesn't need to be installed and
  comments come from `github-actions`.
- **Review rules come from the base branch,** so a PR can't rewrite the rules
  for its own review: the root `REVIEW.md` and `CLAUDE.md`, plus any
  `CLAUDE.md` inside the review scope. Edits to them are reviewed as part of
  the diff and take effect once merged. Every other `CLAUDE.md`, such as ones
  shipped inside third-party plugins or installed by `composer_dirs`, is
  removed before the review, so keep third-party code out of scope with
  `include_paths` or `exclude_paths`.
- **Claude can only read.** It reads the checkout (not `.git`) and the
  files the workflow prepares for it: the diff, the PR conversation, the
  Jira ticket, and any GitHub issues. It
  has no shell, can't write, and can't read elsewhere on the runner. It
  returns its comments, and the workflow posts them as one review, with
  images, embedded HTML, HTML comments, and token-like strings removed. If
  GitHub rejects an inline comment (a line outside the diff), all of them
  are listed in the review body instead.
- **Who can ask for a review.** `@claude` only counts from the repo's
  owner, org members, and collaborators; other comments, and any from bots,
  skip the job. `claude-code-action` also requires write access, so a
  read-only collaborator's `@claude` fails the run, and cancels a review in
  progress on that PR.
- **Pull requests opened by bots or GitHub Apps** (Dependabot, an app token)
  aren't reviewed when opened; `claude-code-action` refuses them. One opened
  with a personal access token counts as that user's.
- **Pull requests from forks** aren't reviewed when opened, since they get no
  secrets. A maintainer's `@claude` on one does review it, reading the
  fork's code; Claude can only read, so that's safe, but the fork's text
  can still try to steer the review.

## `claude-review-github-issues`

A reusable workflow for repos that track work in GitHub issues, such as
custom modules and plugins. It reads the issues a PR is linked to, then runs
[`claude-review`](#claude-review) with them; Claude uses them the way it
uses a Jira ticket, listing requirements the diff misses or contradicts in
the summary. Everything else, including inputs, secrets, `REVIEW.md` and
the gotchas, is `claude-review`'s.

```yaml
name: Claude code review

on:
  pull_request:
    types: [opened, ready_for_review, reopened]
  issue_comment:
    types: [created]

permissions:
  contents: read
  pull-requests: write
  issues: read

jobs:
  review:
    uses: daggerhartlab/devops-shared-actions/.github/workflows/claude-review-github-issues.yml@v1
    secrets:
      CLAUDE_CODE_OAUTH_TOKEN: ${{ secrets.LABBY_CLAUDE_CODE_OAUTH_TOKEN }}
    with:
      exclude_paths: |
        **/composer.lock
```

Compared with a `claude-review` caller: `issues: read` is added, the
`uses:` path names this workflow, and the Jira secrets are usually left out.
Every `claude-review` input and secret works the same.

Link the issue the usual way: a closing keyword in the PR description
(`Fixes #42`, `Closes #42`, `Resolves #42`) or the Development sidebar.
Claude gets, for up to five linked issues in the same repository, each one's
title, state, description and newest 20 comments, plus its milestone and its
parent issue's title and description, about 40 KB in all. With no linked
issue, or if they can't be read, the review runs exactly as `claude-review`
does, with a warning in the second case. The job summary lists the issues
used.

### Gotchas

- **`issues: read` is required.** Without it the run fails at startup, before
  any job starts: a reusable workflow can't get more than its caller grants.
- **A bare `#42` isn't a link.** Only closing keywords and sidebar links
  count, the same ones that close the issue on merge. Issues in other
  repositories are skipped.
- **Projects, discussions and wiki pages aren't read.** Projects aren't
  available to `GITHUB_TOKEN` at all, and the others are rarely linked from a
  PR. Put requirements in the issue.

## Testing

`.github/workflows/test.yml` runs on pull requests, pushes to `main`, a
weekly schedule, and manual dispatch. It exercises the actions from the
current ref (`./.github/actions/...`), so a PR is tested as it will behave
once `v1` is moved onto it.

| Job | What it covers |
|---|---|
| `lint` | actionlint over the workflows, plus a check that every on/off flag uses the same condition |
| `flag-semantics` | `install_wp_cli` across 9 values — `true`/`yes`/`1` install, `unset`/`false`/`FALSE`/`0`/`no`/`off` don't |
| `all-tools` | Everything enabled at once; asserts each binary landed, git identity was set, and `wp_cli_version` was honored |
| `minimal` | `php_version: ''` and `node_version: ''` — confirms the documented "pass empty to skip" actually works |
| `wp-core-update` | Builds an out-of-date WordPress fixture, runs the action with `dry_run`, asserts the update path, that `wp-content` is untouched, and that a re-run is a clean no-op |
| `drupal-module-version` | Builds a two-module fixture (one nested), runs the action with `dry_run`, asserts the stamp lands once per file with no `project` key and the tag is created on the packaging commit without being pushed or released, that an already-stamped commit is tagged without a new commit (and a `v` prefix stays in the tag only), that a changed version replaces rather than accumulates, that an existing tag, a missing version, and a non-version tag are all refused, and that `previous_tag` skips pre-releases for a stable release, orders a pre-release after its stable, compares numerically, keeps to the tag's `v` convention while ignoring a moving `v8` tag, and is empty when there is no earlier version tag |
| `drupal-module-test` | Builds a fixture module with one Unit, Kernel and Functional test and a `require-dev` package, runs every check against a MySQL service, asserts the module is loaded through a symlink to the checkout, the `require-dev` package was installed, and all three tests ran and passed |
| `drupal-module-test-quality` | Builds a fixture module with PHPCS and PHPStan errors, asserts `report_only` passes, PHPCS and PHPStan each fail the run on their own, and a `module_path` without `composer.json` fails |
| `wp-plugin-version` | Builds a plugin fixture with a decoy `Version:` line and a vendored `Plugin Name:` header, runs with `dry_run`, asserts only the first match is rewritten, the entry file is discovered correctly, and the tag is created on the packaging commit; that an already-bumped header is tagged without a new commit; that an existing tag, a lower version, a run off the primary branch, and two root-level entry files are all refused; and that `plugin_file` resolves the ambiguity |
| `composer-audit` | Audits a hand-written lock with a known-vulnerable package, asserts the dev package is included by default and excluded with `no_dev`, that a clean lock reports zero, that a missing lock fails, and that several directories total their counts while a missing one fails only after the rest report; then, offline, pins the Fixed in result for every range shape seen in real reports (`\|\|` and `\|`, comma and space, `v` prefixes, `<=`, no upper bound, end-to-end ranges, unparseable), that an unusable report fails with a summary, and the per-directory headings of a multi-location summary |
| `composer-diff` | Builds base and changed fixture commits, runs with `dry_run` across three lock files, asserts each is named, the changed tables are present, an unchanged lock collapses to one line, and a new lock shows its packages as new; that an unchanged-only run reports `changed=false`; and that a directory with no lock and a base missing from the checkout both fail |
| `claude-review-github-issues` | Checks the wrapper declares exactly `claude-review`'s inputs and secrets (minus `github_issues`) and passes each one through, and that its fetch job has the review job's condition; then runs the fetch step against fixture GraphQL responses, asserting milestones, same- and cross-repo parents, the older-comments note, that other repositories' issues are skipped, that an oversized issue is cut, and that no issues, a failed request, or a response it can't render give no output (the latter two with a warning) |

Two things worth knowing if you extend this:

- **`flag-semantics` uses WP-CLI deliberately.** It's fast to install and is
  *not* preinstalled on the runner image, so asserting "absent" is a real
  test. Doing the same with `pandoc` or `openvpn` risks a vacuous pass if the
  runner image already ships them. The `lint` job's condition check is what
  covers the other flags.
- **The weekly schedule isn't busywork.** These actions download pinned
  external artifacts (WP-CLI phar, Pandoc `.deb`, Acquia CLI release) whose
  URLs can rot with no change here. The scheduled run finds a dead download
  before a consumer's deploy does. Note GitHub only runs schedules on the
  default branch and disables them after 60 days of repo inactivity.

Credentialed paths (SSH, Terminus, Acquia CLI) are intentionally untested —
they need live secrets and can't run on PRs from forks. The same goes for
`claude-review`, which `lint` checks but nothing runs; test it on `daglab`
against a branch ref before moving `v1`.
