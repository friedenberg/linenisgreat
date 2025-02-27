install-composer:
  php -r "copy('https://getcomposer.org/installer', 'composer-setup.php');"
  php -r "if (hash_file('sha384', 'composer-setup.php') === 'dac665fdc30fdd8ec78b38b9800061b4150413ff2e3b6f88543c636f7cd84f6db9189d43a81e5503cda447da73c7e5b6') { echo 'Installer verified'; } else { echo 'Installer corrupt'; unlink('composer-setup.php'); } echo PHP_EOL;"
  php composer-setup.php
  php -r "unlink('composer-setup.php');"
  mv composer.phar protected/composer.phar

install-revealjs-mkdir:
  mkdir -p public/assets/revealjs

[working-directory: 'public/assets/revealjs']
install-revealjs: (install-revealjs-mkdir)
  find . -delete
  http \
    --download "https://github.com/hakimel/reveal.js/archive/master.zip" \
    -o reveal-js.zip
  unzip reveal-js.zip
  rm reveal-js.zip
  mv reveal.js-master/{css,dist,js,plugin} ./
  rm -rf reveal.js-master

[working-directory: 'protected']
build-php-composer:
  php composer.phar install

build:
  # zit show -format json public | jq -s > ~/eng/site-linenisgreat/public/objects.json

deploy-prod: build
  rsync -r \
    --include ".htaccess" \
    --delete \
    --exclude ".*" \
    private conf protected public \
    linenisgreat.com:../

  ssh linenisgreat.com ../private/deploy.sh

[no-cd]
deploy-local: build-php-composer build
  mkdir -p tmp
  SERVER_NAME="${1:-linenisgreat.com}" php \
      -d "auto_prepend_file={{absolute_path("protected/vendor/autoload.php")}}" \
      -S localhost:2222 \
      -c conf/php.ini \
      -t public/
