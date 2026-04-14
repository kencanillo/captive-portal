#!/bin/sh

export GIT_TERMINAL_PROMPT=0
export COMPOSER_PROCESS_TIMEOUT=0

exec composer "$@" --prefer-source --no-interaction --no-progress
