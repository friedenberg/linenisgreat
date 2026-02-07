install-composer:
  php -r "copy('https://getcomposer.org/installer', 'composer-setup.php');"
  php -r "if (hash_file('sha384', 'composer-setup.php') === 'c8b085408188070d5f52bcfe4ecfbee5f727afa458b2573b8eaaf77b3419b0bf2768dc67c86944da1544f06fa544fd47') { echo 'Installer verified'; } else { echo 'Installer corrupt or hash changed'; unlink('composer-setup.php'); } echo PHP_EOL;"
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

build-der_object objectId: 
  mkdir -p public/objects/{{objectId}}
  {{bin_der}} format-blob {{objectId}} html-partial > public/objects/{{objectId}}/index.html

bin_der := "$HOME/eng/pkgs/bravo/dodder/go/build/debug/der"
der_query_public := "public !md"

build-der_objects:
  #! /usr/bin/env -S bash -e
  {{bin_der}} show -format object-id {{der_query_public}} | parallel -n1 -X just build-der_object '{}'

build: build-der_objects
  {{bin_der}} show -format json {{der_query_public}} \
    | jq -s 'map({(.["object-id"] | tostring): .}) | add' \
    > public/objects.json
  cp public/{objects,notes}.json
  # {{bin_der}} show -format toml-json [public !toml-project-code]:e | jq -s 'INDEX(.blob.name)' > public/code.json

deploy-prod: build
  rsync -r \
    --include ".htaccess" \
    --delete \
    --exclude ".*" \
    private conf protected public \
    linenisgreat.com:../

  ssh linenisgreat.com ../private/deploy.sh

generate-htaccess:
  php private/router.php --generate-htaccess > public/.htaccess

test-htaccess:
  private/test-htaccess.sh

test-router PORT="2299": build-php-composer
  #!/usr/bin/env bash
  set -euo pipefail

  # Start server in background
  SERVER_NAME="linenisgreat.com" php \
      -d "auto_prepend_file={{absolute_path("protected/vendor/autoload.php")}}" \
      -S localhost:{{PORT}} \
      -c conf/php.ini \
      -t public/ \
      private/router.php &
  SERVER_PID=$!

  # Ensure server is stopped on exit
  trap "kill $SERVER_PID 2>/dev/null || true" EXIT

  # Wait for server to start
  sleep 1

  # Run tests
  private/test-router.sh {{PORT}}

test: test-htaccess test-router

[no-cd]
deploy-local: build-php-composer build
  mkdir -p tmp
  SERVER_NAME="${1:-linenisgreat.com}" php \
      -d "auto_prepend_file={{absolute_path("protected/vendor/autoload.php")}}" \
      -S localhost:2222 \
      -c conf/php.ini \
      -t public/ \
      private/router.php
