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

## `setup-runner`

Composite action that sets up SSH, Node, PHP/Composer (with dependency
caching), Pantheon Terminus, Drush, Acquia CLI, OpenVPN, and Pandoc — each
tool only runs if its relevant input is provided. See
[`action.yml`](.github/actions/setup-runner/action.yml) for the full,
authoritative list of inputs and defaults; this README covers common usage
patterns.

### Basic PHP/Composer setup (no deployment)

```yaml
- uses: daggerhartlab/devops-shared-actions/.github/actions/setup-runner@v1
  with:
    php_version: '8.3'
    php_extensions: gd, curl, bcmath
```

### Deploying to Pantheon

Pantheon deploys via `terminus build:env:push`, which creates a git commit
against Pantheon's own git remote. That commit needs a git identity to
attribute it to — **set `configure_git_identity: 'true'`** or the push step
will fail with no git user configured. This input defaults to **off**, since
most workflows using this action (e.g. running tests) never commit anything
and shouldn't have their git config silently mutated as a side effect.

```yaml
- uses: daggerhartlab/devops-shared-actions/.github/actions/setup-runner@v1
  with:
    configure_git_identity: 'true'
    ssh_key: ${{ secrets.PANTHEON_PRIVATE_SSH }}
    pantheon_machine_token: ${{ secrets.PANTHEON_MACHINE_TOKEN }}
    php_version: '8.3'
    php_extensions: gd, curl, bcmath
    terminus_version: '^3.0'
    terminus_build_tools_version: '^3.0'

- name: Push to Pantheon
  env:
    pantheon_site: 'my-site'
    pantheon_env: 'dev'
  run: |
    terminus auth:login --machine-token="${{ secrets.PANTHEON_MACHINE_TOKEN }}"
    terminus build:env:push $pantheon_site.$pantheon_env --message="CI Deployment" -v
```

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
