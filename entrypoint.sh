#!/usr/bin/env bash

docker-compose-wait \
&& nice -n 20 php index.php "$@"