#! /bin/sh

rsync -r conf protected public isittimetostopworkingyet.com:../
ssh isittimetostopworkingyet.com \
  'cd ../protected && php composer.phar install'
#add clear tmp
