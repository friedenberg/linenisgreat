#! /bin/sh -xe

cd "$HOME../protected"

# since autoload is included in php.ini, make sure the file exists (given the
# --delete in rsync)
touch vendor/autoload.php
php composer.phar install --no-dev

# forces php.ini to be reloaded faster
nfsn web-kick
