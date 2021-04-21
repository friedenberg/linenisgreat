#! /bin/sh -xe

if [ -z "$SSH_CLIENT" ]; then

  git secret reveal -f

  rsync -r \
    --include ".htaccess" \
    --delete \
    --exclude ".*" \
    private conf protected public \
    isittimetostopworkingyet.com:../

  ssh isittimetostopworkingyet.com ../private/deploy.sh

else
  cd "$HOME../protected"
  php composer.phar install --no-dev

fi
