#! /bin/sh -xe

# git secret reveal -f

cd "protected"
php composer.phar install

cd "../"

SERVER_NAME="${1:-linenisgreat.com}" php -S localhost:2222 -c conf/php.ini -t public/

