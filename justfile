# Default recipe = the local CI loop the spinclass pre-merge gate runs (`just`,
# set in ./sweatfile). This repo's flake ships no package, so CI is the PHP
# test suites (htaccess + router + code/README), not a nix build. Hook-safe:
# no dodder/gh/network dependencies.
default: test test-code

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

build-php-composer:
  composer install -d app/protected
  composer install -d api/protected

# Update composer dependencies to the latest allowed by each composer.json and
# refresh composer.lock. Run after bumping a constraint (e.g. mustache ^2 -> ^3).
update-php-composer:
  composer update -d app/protected
  composer update -d api/protected

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

# Regenerate api/protected/data/code.json from the amarbel-llc GitHub org.
# TEMPORARY GitHub bridge: dodder is meant to re-become the source of truth for
# code projects (see the commented `der ... > code.json` line in `build` above),
# at which point the API itself is intended to be served from a dodder repo.
# Until then this keeps the /code tab in sync with live GitHub metadata.
# Includes originals only (excludes forks and archived repos). Go repos get the
# code_go_import vanity-import meta (code.linenisgreat.com/<name>); others get
# just their GitHub URL. Each project's README is captured as a rendered HTML
# partial at code/<name>/index.html (GitHub renders the GFM), mirroring the
# objects/<id>/index.html pattern and surfaced on the /code/<name> page.
# Serves the dev loop: `just build-code-github` then review + `just deploy-prod`.
build-code-github:
  #!/usr/bin/env bash
  set -euo pipefail
  data_dir="api/protected/data"
  # 1. Build code.json from the org (originals only: no forks, no archived).
  gh api --paginate 'orgs/amarbel-llc/repos?per_page=100&type=all' \
    | jq -sS '
        add
        | [ .[] | select((.fork | not) and (.archived | not)) ]
        | sort_by(.name)
        | INDEX(.name)
        | map_values({
            "object-id": ("project-" + .name),
            "description": (.description // ""),
            "type": "!toml-project-code",
            "tags": ["public"],
            "blob": {
              "name": .name,
              "meta": (
                if .language == "Go"
                then { "name": ("code.linenisgreat.com/" + .name), "template": "code_go_import", "url": .html_url }
                else { "url": .html_url }
                end
              )
            }
          })
      ' \
    > "$data_dir/code.json"
  # 2. Rebuild README partials from scratch so projects dropped from the org
  #    (archived, deleted, renamed) don't leave stale dirs behind.
  rm -rf "$data_dir/code"
  # 3. Capture each project's README as a rendered HTML partial. No README on
  #    GitHub -> fall back to the description so /code/<name> always renders.
  for name in $(jq -r 'keys[]' "$data_dir/code.json"); do
    dir="$data_dir/code/$name"
    mkdir -p "$dir"
    if readme_html=$(gh api "repos/amarbel-llc/$name/readme" \
        -H "Accept: application/vnd.github.html+json" 2>/dev/null); then
      printf '%s' "$readme_html" > "$dir/index.html"
    else
      desc=$(jq -r --arg n "$name" '.[$n].description // ""' "$data_dir/code.json")
      printf '<article class="markdown-body"><p>%s</p></article>\n' "$desc" > "$dir/index.html"
    fi
  done

deploy-prod: build-php-composer build
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

  # Start API server in background (explicit autoload; the api/conf/php.ini
  # auto_prepend_file path is prod-layout-only, so the API 500s without this).
  php \
      -d "auto_prepend_file={{absolute_path("api/protected/vendor/autoload.php")}}" \
      -S localhost:{{API_PORT}} \
      -t api/public/ &
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

# Verify the /code tab + /code/<project> README pages end to end. Starts app+api
# locally (API with the explicit autoload the recipes otherwise omit) and asserts
# CONTENT, not just status — so an API autoload fatal can't masquerade as a 200
# the way it does under test-router. Serves the build-code-github dev loop.
# Standalone (not in `test`): the README partials are gitignored / built on
# demand, but every check here also passes via the description fallback.
test-code PORT="2297" API_PORT="2296": build-php-composer
  #!/usr/bin/env bash
  set -euo pipefail

  php \
      -d "auto_prepend_file={{absolute_path("api/protected/vendor/autoload.php")}}" \
      -S localhost:{{API_PORT}} \
      -t api/public/ &
  API_PID=$!

  API_BASE_URL="http://localhost:{{API_PORT}}" \
  SERVER_NAME="linenisgreat.com" php \
      -d "auto_prepend_file={{absolute_path("app/protected/vendor/autoload.php")}}" \
      -S localhost:{{PORT}} \
      -c app/conf/php.ini \
      -t app/public/ \
      app/private/router.php &
  APP_PID=$!

  trap "kill $API_PID $APP_PID 2>/dev/null || true" EXIT

  sleep 1

  base="http://localhost:{{PORT}}"
  api="http://localhost:{{API_PORT}}"
  fail=0
  echo "TAP version 14"
  echo "1..7"

  # 1. API /code returns valid JSON (catches the autoload fatal directly).
  if curl -sf "$api/code" | jq -e '.data.chrest.blob.name == "chrest"' >/dev/null 2>&1; then
    echo "ok 1 - API /code returns JSON keyed by project"
  else
    echo "not ok 1 - API /code did not return expected JSON"; fail=1
  fi

  # 2. API never leaks a FileDataSource autoload fatal.
  if curl -s "$api/code" | grep -q 'FileDataSource'; then
    echo "not ok 2 - API /code leaked a PHP fatal (autoload broken)"; fail=1
  else
    echo "ok 2 - API /code has no autoload fatal"
  fi

  # 3. Frontend /code index renders one card per project in code.json.
  cards=$(curl -s "$base/code" | grep -c 'data-match' || true)
  expected=$(jq 'keys | length' api/protected/data/code.json)
  if [ "$cards" -eq "$expected" ]; then
    echo "ok 3 - /code index rendered all $cards project cards"
  else
    echo "not ok 3 - /code index rendered $cards cards (expected $expected)"; fail=1
  fi

  # 4. /code/chrest is 200 and carries a rendered README body (real or fallback).
  if [ "$(curl -s -o /dev/null -w '%{http_code}' "$base/code/chrest")" = "200" ] \
     && curl -s "$base/code/chrest" | grep -q 'markdown-body'; then
    echo "ok 4 - /code/chrest renders a README body"
  else
    echo "not ok 4 - /code/chrest missing README body"; fail=1
  fi

  # 5. Unknown project still 200 via the description fallback.
  if [ "$(curl -s -o /dev/null -w '%{http_code}' "$base/code/testproject")" = "200" ]; then
    echo "ok 5 - /code/testproject fallback is 200"
  else
    echo "not ok 5 - /code/testproject not 200"; fail=1
  fi

  # 6. Frontend pages never leak a PHP fatal.
  if curl -s "$base/code" | grep -qiE 'fatal error|uncaught'; then
    echo "not ok 6 - /code leaked a PHP fatal"; fail=1
  else
    echo "ok 6 - /code has no PHP fatal"
  fi

  # 7. No PHP deprecation notice leaks (guards the mustache v3 upgrade).
  if curl -s "$base/code" | grep -qi 'Deprecated:'; then
    echo "not ok 7 - /code leaked a PHP deprecation notice"; fail=1
  else
    echo "ok 7 - /code has no deprecation notice"
  fi

  exit $fail

deploy-local PORT="2222" API_PORT="2223": build-php-composer build
  #!/usr/bin/env bash
  set -euo pipefail

  # Start API server in background. The API autoloads via auto_prepend_file,
  # whose api/conf/php.ini path is prod-layout-only, so pass the worktree
  # autoload explicitly (matches the app server) or the API 500s on FileDataSource.
  CORS_ORIGIN="http://localhost:{{PORT}}" php \
      -d "auto_prepend_file={{absolute_path("api/protected/vendor/autoload.php")}}" \
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

# Serve app+api locally WITHOUT the dodder `build` step, so the /code tab and
# /code/<project> README pages can be eyeballed against the GitHub-sourced data
# (`just build-code-github`) without needing a dodder repo present. Unlike
# deploy-local, this does not regenerate objects.json from dodder.
serve-local PORT="2222" API_PORT="2223": build-php-composer
  #!/usr/bin/env bash
  set -euo pipefail

  # The API relies on auto_prepend_file for PSR-4 autoload; api/conf/php.ini's
  # path is prod-layout-only, so pass the worktree autoload explicitly (the app
  # server below does the same). Without this the API 500s on FileDataSource.
  CORS_ORIGIN="http://localhost:{{PORT}}" php \
      -d "auto_prepend_file={{absolute_path("api/protected/vendor/autoload.php")}}" \
      -S localhost:{{API_PORT}} \
      -t api/public/ &
  API_PID=$!

  trap "kill $API_PID 2>/dev/null || true" EXIT

  API_BASE_URL="http://localhost:{{API_PORT}}" \
  SERVER_NAME="linenisgreat.com" php \
      -d "auto_prepend_file={{absolute_path("app/protected/vendor/autoload.php")}}" \
      -S localhost:{{PORT}} \
      -c app/conf/php.ini \
      -t app/public/ \
      app/private/router.php
