# devops-shared-actions

Shared GitHub Actions for daggerhartlab projects. Consuming workflows
reference these directly instead of copying `action.yml` files between
repos.

## Versioning

Once this repo has its first tagged release, pin consumers to a major
version tag (e.g. `@v1`), not `@main` — this repo pins its own third-party
action dependencies to commit SHAs for exactly that reason (see
`.github/actions/setup-runner/action.yml`), and consumers should extend the
same discipline to this repo.

## Actions in this repo

- [`setup-runner`](#setup-runner) — installs SSH, Node, PHP/Composer,
  Pantheon Terminus, Drush, Acquia CLI, OpenVPN, Pandoc as needed.
- [`site-build`](#site-build) — builds a deployable artifact: Composer
  (no-dev), an optional Laravel Mix theme build, and optional git-based
  artifact-repo prep.

Both are composite actions; see each `action.yml` for the full,
authoritative list of inputs and defaults. This README covers common usage
patterns and the gotchas we've already hit.

---

## `setup-runner`

Each tool only runs if its relevant input is provided.

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

      - name: Push to Pantheon
        env:
          pantheon_site: 'my-site'
          pantheon_env: 'dev'
          PANTHEON_MACHINE_TOKEN: ${{ secrets.PANTHEON_MACHINE_TOKEN }}
        run: |
          terminus auth:login --machine-token="$PANTHEON_MACHINE_TOKEN"
          terminus build:env:push $pantheon_site.$pantheon_env --message="CI Deployment" -v
```

Note: never interpolate `${{ secrets.* }}` directly into a `run:` shell
string (e.g. `--machine-token=${{ secrets.X }}`) — pass it through `env:`
and reference it as a shell variable instead, as above. This avoids
GitHub's documented script-injection risk for expression interpolation in
shell blocks.

### `ssh_config` examples by host

`ssh_config`'s default (`Host *.drush.in\n  StrictHostKeyChecking no`)
covers Pantheon. For other hosts, override it:

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

```yaml
- uses: daggerhartlab/devops-shared-actions/.github/actions/setup-runner@v1
  with:
    php_version: '8.3'
    acli_key: ${{ secrets.ACQUIA_CLI_KEY }}
    acli_secret: ${{ secrets.ACQUIA_CLI_SECRET }}
```

---

## `site-build`

Three independent, opt-in steps — each only runs if its own input is set,
with no coupling between them:

| Input | Step it enables |
|---|---|
| *(always runs)* | `composer install --no-dev` (production dependencies) |
| `laravel_mix_theme_path` | `npm ci && npx mix --production` in that theme directory |
| `artifact_repo_gitignore` | Replace `.gitignore` with the given file |
| `artifact_repo_cleanup_paths` | Remove nested `.git` directories under the given paths |

### Building a Laravel Mix theme only

```yaml
- uses: daggerhartlab/devops-shared-actions/.github/actions/site-build@v1
  with:
    laravel_mix_theme_path: web/themes/custom/my_theme
```

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
