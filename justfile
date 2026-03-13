install-composer:
  php -r "copy('https://getcomposer.org/installer', 'composer-setup.php');"
  php -r "if (hash_file('sha384', 'composer-setup.php') === 'c8b085408188070d5f52bcfe4ecfbee5f727afa458b2573b8eaaf77b3419b0bf2768dc67c86944da1544f06fa544fd47') { echo 'Installer verified'; } else { echo 'Installer corrupt or hash changed'; unlink('composer-setup.php'); } echo PHP_EOL;"
  php composer-setup.php
  php -r "unlink('composer-setup.php');"
  mv composer.phar app/protected/composer.phar

install-revealjs-mkdir:
  mkdir -p app/public/assets/revealjs

[working-directory: 'app/public/assets/revealjs']
install-revealjs: (install-revealjs-mkdir)
  find . -delete
  http \
    --download "https://github.com/hakimel/reveal.js/archive/master.zip" \
    -o reveal-js.zip
  unzip reveal-js.zip
  rm reveal-js.zip
  mv reveal.js-master/{css,dist,js,plugin} ./
  rm -rf reveal.js-master

[working-directory: 'app/protected']
build-php-composer:
  php composer.phar install

build-der_object objectId:
  mkdir -p api/protected/data/objects/{{objectId}}
  {{bin_der}} format-blob {{objectId}} html-partial > api/protected/data/objects/{{objectId}}/index.html

bin_der := require("der")
der_query_public := "public !md"

build-der_objects:
  #! /usr/bin/env -S bash -e
  {{bin_der}} show -format object-id {{der_query_public}} | parallel -n1 -X just build-der_object '{}'

build: build-der_objects
  mkdir -p api/protected/data
  {{bin_der}} show -format json {{der_query_public}} \
    | jq -s 'map({(.["object-id"] | tostring): .}) | add' \
    > api/protected/data/objects.json
  cp api/protected/data/{objects,notes}.json
  # {{bin_der}} show -format toml-json [public !toml-project-code]:e | jq -s 'INDEX(.blob.name)' > api/protected/data/code.json

deploy-prod: build
  rsync -r \
    --include ".htaccess" \
    --delete \
    --exclude ".*" \
    app/private app/conf app/protected app/public \
    linenisgreat.com:../

  rsync -r \
    --include ".htaccess" \
    --delete \
    --exclude ".*" \
    api/public api/protected api/private api/conf \
    api.linenisgreat.com:../

  ssh linenisgreat.com ../private/deploy.sh
  ssh api.linenisgreat.com ../private/deploy.sh

generate-htaccess:
  php app/private/router.php --generate-htaccess > app/public/.htaccess

test-htaccess:
  app/private/test-htaccess.sh

test-router PORT="2299" API_PORT="2298": build-php-composer
  #!/usr/bin/env bash
  set -euo pipefail

  # Start API server in background
  php -S localhost:{{API_PORT}} -t api/public/ &
  API_PID=$!

  # Start frontend server in background
  API_BASE_URL="http://localhost:{{API_PORT}}" \
  SERVER_NAME="linenisgreat.com" php \
      -d "auto_prepend_file={{absolute_path("app/protected/vendor/autoload.php")}}" \
      -S localhost:{{PORT}} \
      -c app/conf/php.ini \
      -t app/public/ \
      app/private/router.php &
  SERVER_PID=$!

  # Ensure both servers are stopped on exit
  trap "kill $SERVER_PID $API_PID 2>/dev/null || true" EXIT

  # Wait for servers to start
  sleep 1

  # Run tests
  app/private/test-router.sh {{PORT}}

test: test-htaccess test-router

deploy-local PORT="2222" API_PORT="2223": build-php-composer build
  #!/usr/bin/env bash
  set -euo pipefail

  # Start API server in background
  CORS_ORIGIN="http://localhost:{{PORT}}" php \
      -S localhost:{{API_PORT}} \
      -t api/public/ &
  API_PID=$!

  trap "kill $API_PID 2>/dev/null || true" EXIT

  # Start frontend server in foreground
  API_BASE_URL="http://localhost:{{API_PORT}}" \
  SERVER_NAME="linenisgreat.com" php \
      -d "auto_prepend_file={{absolute_path("app/protected/vendor/autoload.php")}}" \
      -S localhost:{{PORT}} \
      -c app/conf/php.ini \
      -t app/public/ \
      app/private/router.php

deploy-local-prod-api PORT="2222": build-php-composer
  API_BASE_URL="https://api.linenisgreat.com" \
  SERVER_NAME="linenisgreat.com" php \
      -d "auto_prepend_file={{absolute_path("app/protected/vendor/autoload.php")}}" \
      -S localhost:{{PORT}} \
      -c app/conf/php.ini \
      -t app/public/ \
      app/private/router.php
