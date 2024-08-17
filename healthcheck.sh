#!/usr/bin/env sh

ttl="$1"
updated_ago=$(($(date +%s) - $(date -r /root/.healthcheck +%s)))
[ "$updated_ago" -ge 0 ] && [ "$updated_ago" -le "$ttl" ] || sh -c 'kill -INT -1 && (sleep 5; kill -s 9 -1)'
cat /root/.healthcheck
exit 0