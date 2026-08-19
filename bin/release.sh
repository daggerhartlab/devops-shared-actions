#!/usr/bin/env bash
#
# Cut an immutable version tag and move the floating major tag onto it.
#
#   bin/release.sh 1.4.3 -m "What changed"
#   bin/release.sh v1.4.3 -m "What changed" --dry-run
#
# Consumers pin the major tag (@v1), so force-moving it ships to every
# consuming repo on their next workflow run. There is no staging step - hence
# the checks below.
set -euo pipefail

die() { printf '\033[31merror:\033[0m %s\n' "$1" >&2; exit 1; }
step() { printf '\033[36m==>\033[0m %s\n' "$1"; }

usage() {
  sed -n '3,6p' "$0" | sed 's/^# \{0,1\}//'
  cat <<'EOF'

Options:
  -m, --message MSG   Annotated tag message (required)
  -n, --dry-run       Run every check, then print the commands instead
  -y, --yes           Skip the confirmation prompt
  -h, --help          Show this
EOF
  exit "${1:-0}"
}

version="" message="" dry_run=0 assume_yes=0
while [ $# -gt 0 ]; do
  case "$1" in
    -m|--message) message="${2:-}"; shift 2 ;;
    -n|--dry-run) dry_run=1; shift ;;
    -y|--yes)     assume_yes=1; shift ;;
    -h|--help)    usage 0 ;;
    -*)           die "unknown option: $1" ;;
    *)            [ -z "$version" ] || die "unexpected argument: $1"; version="$1"; shift ;;
  esac
done

[ -n "$version" ] || usage 1
[ -n "$message" ] || die "a tag message is required (-m)"

version="${version#v}"
[[ "$version" =~ ^[0-9]+\.[0-9]+\.[0-9]+$ ]] || die "version must be X.Y.Z (got '$version')"
tag="v$version"
major="v${version%%.*}"

cd "$(git rev-parse --show-toplevel 2>/dev/null)" || die "not inside a git repository"

step "Checking working tree"
branch=$(git symbolic-ref --short HEAD 2>/dev/null) || die "detached HEAD - check out a branch"
[ "$branch" = "main" ] || die "on '$branch'; releases are cut from main"
[ -z "$(git status --porcelain)" ] || die "working tree is dirty - commit or stash first"

step "Fetching from origin"
git fetch --quiet --tags origin

local_sha=$(git rev-parse HEAD)
remote_sha=$(git rev-parse "origin/$branch")
[ "$local_sha" = "$remote_sha" ] || die "$branch differs from origin/$branch - push or pull first"

# Version tags are immutable. Only the major tag ever moves.
if git rev-parse -q --verify "refs/tags/$tag" >/dev/null; then
  die "$tag already exists locally - version tags are never reused, pick a new number"
fi
if git ls-remote --exit-code --tags origin "refs/tags/$tag" >/dev/null 2>&1; then
  die "$tag already exists on origin - version tags are never reused, pick a new number"
fi

current=$(git rev-parse --short "${major}^{}" 2>/dev/null || echo "none")
printf '\n  release:  %s at %s\n' "$tag" "$(git rev-parse --short HEAD)"
printf '  moves:    %s  %s -> %s\n' "$major" "$current" "$(git rev-parse --short HEAD)"
printf '  subject:  %s\n\n' "$(git log -1 --format=%s)"

if [ "$dry_run" -eq 1 ]; then
  step "Dry run - would execute"
  cat <<EOF
  git tag -a $tag -m "<message>"
  git push origin $tag
  git tag -f -a $major -m "$major -> $tag" "${tag}^{}"
  git push origin $major --force
EOF
  exit 0
fi

if [ "$assume_yes" -eq 0 ]; then
  printf 'Moving %s reaches every consumer immediately. Continue? [y/N] ' "$major"
  read -r reply
  case "$reply" in [yY]*) ;; *) die "aborted" ;; esac
fi

step "Creating $tag"
git tag -a "$tag" -m "$message"
git push origin "$tag"

# ^{} peels the annotated tag to its commit; without it the major tag would
# point at the tag object.
step "Moving $major to $tag"
git tag -f -a "$major" -m "$major -> $tag" "${tag}^{}"
git push origin "$major" --force

step "Verifying"
a=$(git rev-parse "${major}^{}")
b=$(git rev-parse "${tag}^{}")
[ "$a" = "$b" ] || die "$major ($a) and $tag ($b) disagree"
printf '  %s and %s both at %s\n' "$major" "$tag" "$(git rev-parse --short "$b")"
git ls-remote --tags origin "refs/tags/$major*" "refs/tags/$tag*" | sed 's/^/  /'
