#!/bin/bash

DOCKER_COMPOSE="docker compose"
PUID=$(id -u)
PGID=$(id -g)

user="$(git config user.name)"
gitDir="$(git rev-parse --git-dir)"
files=$(git diff --cached --name-only --diff-filter=ACMR -- '*.php')

branch="$(git rev-parse --abbrev-ref HEAD)"
commits="$(git rev-list --count HEAD ^${branch})"
pattern="^(feature|fix|hotfix)\/[0-9]{1,7}_[a-zA-Z0-9_-]+|:[0-9a-f]{7,40}$"

if [[ -d "${gitDir}/rebase-merge" || -d "${gitDir}/rebase-apply" ]]; then
    exit 0;
fi

if [[ $commits -eq 0 ]]; then
    echo "Validate branch name..."
    if [[ ! $branch =~ $pattern ]]; then
        echo -e "\e[1;31mCommit not valid because of branch name '${branch}' !\n\e[0m"
        exit 1;
    fi
fi

echo "Committing as user ${user}"
if [[ $user =~ [.,:'!@#$%^&*()_+'] ]]; then
    echo -e "\e[1;31mThe git username does not match convention!\e[0m"
    exit 1;
fi

if [[ -n "$files" ]]; then
    echo "Processing phpcs:"
    $DOCKER_COMPOSE run -u ${PUID}:${PGID} --rm phpcli vendor/bin/phpcs ${files} -
    if [[ "$?" != 0 ]]; then
        exit 1
    fi
fi

exit 0;
