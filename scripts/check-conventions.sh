#!/bin/sh
# Enforce conventional commits on branch names and commit / PR subjects.
#
#   check-conventions.sh --branch chore/conventional-commits
#   check-conventions.sh --message "chore: enforce conventional commits"
#   check-conventions.sh --title "chore: enforce conventional commits"
set -eu

TYPES='feat|fix|docs|chore|refactor|test|ci|style|perf|build'
BRANCH_RE="^(${TYPES})/[a-z0-9][a-z0-9._-]*$"
SUBJECT_RE="^(${TYPES})(\\([a-z0-9._-]+\\))?(!)?: [^[:space:]].+$"

usage() {
	echo "Usage: check-conventions.sh --branch NAME | --message TEXT | --title TEXT" >&2
	exit 2
}

is_skipped_subject() {
	case "$1" in
		Merge\ *|Revert\ *|fixup\!*|squash\!*) return 0 ;;
	esac
	return 1
}

check_branch() {
	name=$1
	case "$name" in
		main|master) return 0 ;;
	esac
	if ! printf '%s\n' "$name" | grep -Eq "$BRANCH_RE"; then
		echo "Branch name '$name' is not conventional." >&2
		echo "Expected: <type>/<kebab-slug>  e.g. feat/catalog-lookup" >&2
		echo "Types: feat fix docs chore refactor test ci style perf build" >&2
		exit 1
	fi
}

check_subject() {
	kind=$1
	first=$(printf '%s\n' "$2" | head -n1)
	if is_skipped_subject "$first"; then
		return 0
	fi
	if ! printf '%s\n' "$first" | grep -Eq "$SUBJECT_RE"; then
		echo "$kind is not a conventional commit." >&2
		echo "  got:  $first" >&2
		echo "  want: <type>(optional-scope)!: short description" >&2
		echo "  e.g.: feat: add catalog filter" >&2
		echo "Types: feat fix docs chore refactor test ci style perf build" >&2
		exit 1
	fi
}

[ $# -ge 2 ] || usage

case "$1" in
	--branch) check_branch "$2" ;;
	--message) check_subject "Commit message" "$2" ;;
	--title) check_subject "PR title" "$2" ;;
	*) usage ;;
esac
