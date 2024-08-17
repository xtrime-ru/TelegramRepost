#!/usr/bin/env sh

composer install \
&& docker-compose-wait \
&& nice -n 19 php index.php "$@"