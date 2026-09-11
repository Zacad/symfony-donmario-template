#!/bin/sh
set -eu
kind=$1
settings=$2
baseline=$3
log=$4
result=$5
test "$result" -ne 0
case "$kind" in
    missing)
        test ! -e "$settings"
        grep -Fq 'Existing development volumes found without credentials.' "$log" ;;
    incomplete)
        cmp -s "$settings" "$baseline"
        grep -Fq 'Incomplete settings:' "$log" ;;
    *) exit 1 ;;
esac
