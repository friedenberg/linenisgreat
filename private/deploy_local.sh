#! /bin/sh -xe

git secret reveal -f

cd "protected"
php composer.phar install

cd "../"

php -S localhost:2222 -c conf/php.ini -t public/

