#!/bin/bash
# Jardis Pre-Commit Hook — Branch-Name + Username Validierung
# Quality Gates (phpcs, phpstan, phpunit) laufen im Pre-Push Hook via make.

gitDir="$(git rev-parse --git-dir)"
branch="$(git rev-parse --abbrev-ref HEAD)"
user="$(git config user.name)"
pattern="^(feature|fix|hotfix)\/[0-9]{1,7}_[a-zA-Z0-9_-]+|:[0-9a-f]{7,40}$"

# Rebase ueberspringen
if [[ -d "${gitDir}/rebase-merge" || -d "${gitDir}/rebase-apply" ]]; then
    exit 0;
fi

# Branch-Name validieren (nur beim ersten Commit)
commits="$(git rev-list --count HEAD ^${branch} 2>/dev/null || echo 0)"
if [[ $commits -eq 0 ]]; then
    echo "Validate branch name..."
    if [[ ! $branch =~ $pattern ]]; then
        echo -e "\e[1;31mBranch-Name '${branch}' ungueltig! Format: feature/42_beschreibung\e[0m"
        exit 1;
    fi
fi

# Username validieren
echo "Committing as user ${user}"
if [[ $user =~ [.,:'!@#$%^&*()_+'] ]]; then
    echo -e "\e[1;31mGit-Username enthaelt ungueltige Zeichen!\e[0m"
    exit 1;
fi

exit 0
