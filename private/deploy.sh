#! /bin/sh -xe

if [ -z "$SSH_CLIENT" ]; then

  # git secret reveal -f

  rsync -r \
    --include ".htaccess" \
    --delete \
    --exclude ".*" \
    private conf protected public \
    linenisgreat.com:../

  ssh linenisgreat.com ../private/deploy.sh

else
  cd "$HOME../protected"
  # since autoload is included in php.ini, make sure the file exists (given the
  # --delete in rsync)
  touch vendor/autoload.php
  php composer.phar install --no-dev
  # forces php.ini to be reloaded faster
  nfsn web-kick
fi
