#!/bin/bash
set -e

PORT="${PORT:-10000}"

sed -i "s#Listen 80#Listen ${PORT}#g" /etc/apache2/ports.conf
sed -i "s#<VirtualHost \*:80>#<VirtualHost *:${PORT}>#g" /etc/apache2/sites-available/000-default.conf

apache2ctl -D FOREGROUND
