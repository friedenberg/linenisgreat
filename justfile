# Default recipe = the local CI loop the spinclass pre-merge gate runs (`just`,
# set in ./sweatfile). This repo's flake ships no package, so CI is treelint's
# read-only format+lint check plus the PHP test suites (htaccess + router +
# code/README), not a nix build. Hook-safe: no dodder/gh/network dependencies.
default: codemod-fmt-check test test-code

[private]
install-revealjs-mkdir:
  mkdir -p app/public/assets/revealjs

[working-directory: 'app/public/assets/revealjs']
[group("operational")]
install-revealjs: (install-revealjs-mkdir)
  find . -delete
  http \
    --download "https://github.com/hakimel/reveal.js/archive/master.zip" \
    -o reveal-js.zip
  unzip reveal-js.zip
  rm reveal-js.zip
  mv reveal.js-master/{css,dist,js,plugin} ./
  rm -rf reveal.js-master

[group("build")]
build-php-composer:
  composer install -d app/protected
  composer install -d api/protected

# Update composer dependencies to the latest allowed by each composer.json and
# refresh composer.lock. Run after bumping a constraint (e.g. mustache ^2 -> ^3).
[group("maintenance")]
update-php-composer:
  composer update -d app/protected
  composer update -d api/protected

# --- eng-versioning(7): single version source of truth in ./version.env ---
# This repo's flake ships no binary, so there is no ldflags/version embedding;
# version.env exists for tag/release bookkeeping and as the canonical version
# other artifacts (e.g. shared/card-render's composer.json) should track.

# Rewrite LINENISGREAT_VERSION in version.env. Pure mutation — staging/committing
# is `release`'s job, so this composes. eng-versioning(7).
[group("maintenance")]
bump-version new:
  #!/usr/bin/env bash
  set -euo pipefail
  sed -i "s/^export LINENISGREAT_VERSION=.*/export LINENISGREAT_VERSION={{new}}/" version.env
  gum log --level info "version.env -> {{new}}"

# Create a signed, annotated v<sem> tag from version.env, push it, and verify the
# signature. Root-level package, so the tag is `v<sem>` (no path prefix).
# eng-versioning(7).
[group("maintenance")]
tag message:
  #!/usr/bin/env bash
  set -euo pipefail
  source version.env
  t="v${LINENISGREAT_VERSION}"
  git tag -s "$t" -m "{{message}}"
  git push origin "$t"
  git tag -v "$t"
  gum log --level info "tagged $t"

# Full release flow (operational; run from the default branch — in this
# spinclass repo that means after merging your work to master). Generates the
# changelog BEFORE the bump so the release commit is excluded, bumps + commits
# version.env, tags with the changelog, and creates the GitHub release.
# eng-versioning(7).
[group("maintenance")]
release new:
  #!/usr/bin/env bash
  set -euo pipefail
  branch=$(git rev-parse --abbrev-ref HEAD)
  if [ "$branch" != "master" ]; then
    gum log --level error "release must run on master (currently on $branch)"
    exit 1
  fi
  last=$(git describe --tags --abbrev=0 2>/dev/null || true)
  range="${last:+$last..}HEAD"
  changelog=$(git log --no-merges --pretty=format:'- %s' "$range")
  body=$(printf 'release v%s\n\n%s\n' "{{new}}" "$changelog")
  just bump-version {{new}}
  git add version.env
  git commit -S -m "release v{{new}}"
  just tag "$body"
  printf '%s' "$body" | gh release create "v{{new}}" --notes-file -
  gum log --level info "released v{{new}}"

# Repair the whole tree via treelint (repair mode), configured by the repo-local
# ./treelint.toml. treelint runs every formatter/linter from this flake's
# devShell, applying formatter fixes and `[linter.*]` repair-commands.
[group("codemod")]
codemod-fmt:
  treelint

# Read-only format+lint gate (consumed by the pre-merge gate / CI). `treelint
# check` exits non-zero on any finding without writing to the tree.
[group("codemod")]
codemod-fmt-check:
  treelint check

[private]
build-der_object objectId:
  mkdir -p api/protected/data/objects/{{objectId}}
  {{bin_der}} format-blob {{objectId}} html-partial > api/protected/data/objects/{{objectId}}/index.html

# `der` (dodder) drives build/build-der_*/deploy-prod. Use a plain string, not
# require("der"): require is evaluated eagerly at justfile LOAD, so it would
# break `nix develop -c just` in any env without der on PATH (the spinclass
# pre-merge gate and GitHub CI). The der recipes still fail clearly at runtime
# if der is missing.
bin_der := "der"
der_query_public := "public !md"

[group("build")]
build-der_objects:
  #! /usr/bin/env -S bash -e
  rm -rf api/protected/data/objects/
  {{bin_der}} show -format object-id {{der_query_public}} | parallel -n1 -X just build-der_object '{}'

[group("build")]
build: build-der_objects
  mkdir -p api/protected/data
  {{bin_der}} show -format json {{der_query_public}} \
    | jq -s 'map({(.["object-id"] | tostring): .}) | add' \
    > api/protected/data/objects.json.tmp \
    && mv api/protected/data/objects.json.tmp api/protected/data/objects.json
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
[group("build")]
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
  # 3. Capture each project's README as a rendered HTML partial, plus the footer
  #    metadata surfaced on /code/<name> (LICENSE link + README last-commit date).
  #    No README on GitHub -> fall back to the description so the page always
  #    renders. All GitHub lookups are best-effort: a 404/absent field is omitted,
  #    never fatal.
  meta_extra=$(mktemp)
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

    # Absolutize repo-relative README links/images back to GitHub so they don't
    # resolve against linenisgreat.com/code/<name> and 404. ast-grep is AST-scoped
    # (won't rewrite an href="..." that is literal text inside a code block) and
    # edits in place; the not-regex skips already-absolute / // / #-anchor /
    # mailto: refs. HEAD resolves to the repo's default branch.
    repo="amarbel-llc/$name"
    rules_dir=$(mktemp -d)
    printf 'id: readme-href\nlanguage: html\nrule:\n  pattern: %s\n  not: { regex: %s }\nfix: %s\n' \
      "'<a href=\"\$U\">\$\$\$C</a>'" "'href=\"(https?:|//|#|mailto:)'" \
      "'<a href=\"https://github.com/$repo/blob/HEAD/\$U\">\$\$\$C</a>'" > "$rules_dir/href.yml"
    printf 'id: readme-img\nlanguage: html\nrule:\n  pattern: %s\n  not: { regex: %s }\nfix: %s\n' \
      "'<img src=\"\$U\">'" "'src=\"(https?:|//|data:)'" \
      "'<img src=\"https://github.com/$repo/raw/HEAD/\$U\">'" > "$rules_dir/img.yml"
    ast-grep scan --rule "$rules_dir/href.yml" --update-all "$dir/index.html" >/dev/null
    ast-grep scan --rule "$rules_dir/img.yml" --update-all "$dir/index.html" >/dev/null
    rm -rf "$rules_dir"

    # LICENSE file link + README's last-commit date (date only; never the
    # committer email, which is private).
    license_url=$(gh api "repos/amarbel-llc/$name/license" --jq '.html_url' 2>/dev/null || true)
    readme_path=$(gh api "repos/amarbel-llc/$name/readme" --jq '.path' 2>/dev/null || echo README.md)
    readme_date=$(gh api "repos/amarbel-llc/$name/commits?path=${readme_path}&per_page=1" \
        --jq '.[0].commit.committer.date // empty' 2>/dev/null || true)
    readme_updated=""
    if [ -n "$readme_date" ]; then
      readme_updated=$(date -u -d "$readme_date" +%Y-%m-%d)
    fi
    jq -nc --arg n "$name" --arg lu "$license_url" --arg ru "$readme_updated" \
      '{name: $n, license_url: $lu, readme_updated: $ru}' >> "$meta_extra"
  done

  # 4. Merge the footer metadata into each entry's blob.meta in one pass; only
  #    non-empty fields are added.
  tmp=$(mktemp)
  jq --slurpfile extra "$meta_extra" '
      reduce $extra[] as $e (.;
        .[$e.name].blob.meta +=
          ( (if $e.license_url != "" then {license_url: $e.license_url} else {} end)
          + (if $e.readme_updated != "" then {readme_updated: $e.readme_updated} else {} end) ))
    ' "$data_dir/code.json" > "$tmp" && mv "$tmp" "$data_dir/code.json"
  rm -f "$meta_extra"

# Verify the read-only git smart-HTTP proxy (app/public/code_git_proxy.php) that
# lets `code.linenisgreat.com/<name>` serve as a git/Nix-flake endpoint by
# transparently forwarding to GitHub. Standalone + networked (clones a public
# repo), so it is NOT in the hook-safe `test` gate. Points the proxy upstream at
# github.com/<UPSTREAM_ORG> (default octocat) and drives a real `git clone`,
# which exercises the same info/refs + git-upload-pack flow Nix's git+https
# fetcher uses. On a nix host, additionally try:
#   nix flake metadata "git+http://localhost:<PORT>/code/Hello-World"
[group("post-build")]
test-code-git PORT="2295" API_PORT="2294" UPSTREAM_ORG="octocat" REPO="Hello-World": build-php-composer
  #!/usr/bin/env bash
  set -euo pipefail

  php \
      -d "auto_prepend_file={{absolute_path("api/protected/vendor/autoload.php")}}" \
      -S localhost:{{API_PORT}} \
      -t api/public/ &
  API_PID=$!

  CODE_GIT_UPSTREAM="https://github.com/{{UPSTREAM_ORG}}" \
  API_BASE_URL="http://localhost:{{API_PORT}}" \
  SERVER_NAME="linenisgreat.com" php \
      -d "auto_prepend_file={{absolute_path("app/protected/vendor/autoload.php")}}" \
      -S localhost:{{PORT}} \
      -c app/conf/php.ini \
      -t app/public/ \
      app/private/router.php &
  APP_PID=$!
  trap "kill $APP_PID $API_PID 2>/dev/null || true; rm -rf /tmp/test-code-git" EXIT
  sleep 1

  base="http://localhost:{{PORT}}/code/{{REPO}}"
  rm -rf /tmp/test-code-git
  fail=0
  echo "TAP version 14"
  echo "1..6"

  # 1. Ref discovery advertises the upload-pack service with the right type.
  hdrs=$(curl -s -D - -o /tmp/test-code-git.refs "$base/info/refs?service=git-upload-pack")
  if echo "$hdrs" | grep -qi 'content-type: application/x-git-upload-pack-advertisement' \
     && head -c 40 /tmp/test-code-git.refs | grep -q '# service=git-upload-pack'; then
    echo "ok 1 - info/refs advertises git-upload-pack"
  else
    echo "not ok 1 - info/refs advertisement malformed"; fail=1
  fi

  # 2. A real git clone through the proxy succeeds.
  if git clone -q "$base" /tmp/test-code-git 2>/dev/null; then
    echo "ok 2 - git clone through proxy succeeds"
  else
    echo "not ok 2 - git clone failed"; fail=1
  fi

  # 3. Cloned HEAD matches what GitHub serves directly (proxy is transparent).
  proxy_head=$(git -C /tmp/test-code-git rev-parse HEAD 2>/dev/null || echo none)
  gh_head=$(git ls-remote "https://github.com/{{UPSTREAM_ORG}}/{{REPO}}.git" HEAD 2>/dev/null | cut -f1)
  if [ -n "$gh_head" ] && [ "$proxy_head" = "$gh_head" ]; then
    echo "ok 3 - cloned HEAD matches upstream ($proxy_head)"
  else
    echo "not ok 3 - HEAD mismatch (proxy=$proxy_head upstream=$gh_head)"; fail=1
  fi

  # 4. Push transport (receive-pack) is never served — read-only.
  code=$(curl -s -o /dev/null -w '%{http_code}' "$base/info/refs?service=git-receive-pack")
  if [ "$code" = "403" ]; then
    echo "ok 4 - receive-pack (push) discovery refused (403)"
  else
    echo "not ok 4 - receive-pack not refused (got $code)"; fail=1
  fi

  # 5. The human-facing catch-all /code/<name> route is unaffected.
  code=$(curl -s -o /dev/null -w '%{http_code}' "http://localhost:{{PORT}}/code/testproject")
  if [ "$code" = "200" ]; then
    echo "ok 5 - /code/<name> HTML route still 200"
  else
    echo "not ok 5 - /code/<name> route regressed (got $code)"; fail=1
  fi

  # 6. The literal flake host form — code.linenisgreat.com/<name> — resolves via
  #    the bare-path host-scoped route (simulated with a Host header).
  ct=$(curl -s -o /dev/null -H 'Host: code.linenisgreat.com' -w '%{content_type}' \
    "http://localhost:{{PORT}}/{{REPO}}/info/refs?service=git-upload-pack")
  if [ "$ct" = "application/x-git-upload-pack-advertisement" ]; then
    echo "ok 6 - code.linenisgreat.com/<name> bare-path git endpoint resolves"
  else
    echo "not ok 6 - subdomain bare-path endpoint failed (content-type=$ct)"; fail=1
  fi

  exit $fail

# Org-wide breadth check for the git proxy: clone every non-archived
# {{UPSTREAM_ORG}} repo through a local server and assert each succeeds. Networked
# (hits GitHub) + slow, so NOT in the hook-safe `test` gate — run before a deploy
# that changes the proxy. Complements test-code-git's single-repo deep check.
# Archived repos are skipped, notably the nixpkgs fork that exceeds the proxy's
# 170s cap (tracked in issue #7). Each clone is bounded by `timeout 180` (just
# above the proxy's curl cap) and discarded immediately.
[group("post-build")]
test-code-git-org PORT="2295" API_PORT="2294" UPSTREAM_ORG="amarbel-llc": build-php-composer
  #!/usr/bin/env bash
  set -uo pipefail

  php \
      -d "auto_prepend_file={{absolute_path("api/protected/vendor/autoload.php")}}" \
      -S localhost:{{API_PORT}} -t api/public/ >/dev/null 2>&1 &
  API_PID=$!
  CODE_GIT_UPSTREAM="https://github.com/{{UPSTREAM_ORG}}" \
  API_BASE_URL="http://localhost:{{API_PORT}}" \
  SERVER_NAME="linenisgreat.com" php \
      -d "auto_prepend_file={{absolute_path("app/protected/vendor/autoload.php")}}" \
      -S localhost:{{PORT}} -c app/conf/php.ini -t app/public/ app/private/router.php >/dev/null 2>&1 &
  APP_PID=$!
  tmpd=$(mktemp -d)
  trap "kill $APP_PID $API_PID 2>/dev/null || true; rm -rf $tmpd" EXIT
  sleep 1

  mapfile -t repos < <(gh api --paginate 'orgs/{{UPSTREAM_ORG}}/repos?per_page=100&type=all' \
    | jq -rs 'add | map(select(.archived | not)) | sort_by(.size) | .[] | [.name, .size] | @tsv')

  echo "TAP version 14"
  echo "1..${#repos[@]}"
  i=0; fail=0
  for line in "${repos[@]}"; do
    i=$((i + 1))
    name=${line%%$'\t'*}
    size=${line##*$'\t'}
    err="$tmpd/$name.err"
    SECONDS=0
    if timeout 180 git clone --quiet "http://localhost:{{PORT}}/code/$name" "$tmpd/$name" >/dev/null 2>"$err"; then
      echo "ok $i - $name (${size}KB, ${SECONDS}s)"
    else
      rc=$?
      msg=$(grep -iaE 'fatal|error|rpc|hung up|EOF|disconnect' "$err" | head -1 | tr -d '\r')
      [ "$rc" -eq 124 ] && msg="outer-timeout 180s; $msg"
      echo "not ok $i - $name (${size}KB, ${SECONDS}s) - exit $rc: $msg"
      fail=1
    fi
    rm -rf "$tmpd/$name" "$err"
  done
  exit $fail

# Repo-local piggy secret store: PIV/YubiKey-encrypted .ebox files committed
# under secrets/. Recipes pin the store path so they don't depend on direnv;
# the sweatfile's [direnv].dotenv sets PIGGY_STORE_DIR for interactive use.
PIGGY_STORE_DIR := justfile_directory() / "secrets"

# Materialize the plaintext secret PHP classes from the committed piggy store in
# a single PIN/touch (pass show-batch). Replaces the old `git secret reveal`:
# writes the two gitignored files the apps load — api/.../GithubToken.php (the
# README read-through PAT) and app/.../Html2ImageApiKey.php (the hcti.io key).
# Run before deploy-prod whenever a secret changes; the plaintext is never
# committed. Serves the deploy dev loop.
[group("operational")]
reveal-secrets:
  #!/usr/bin/env bash
  set -euo pipefail
  tmp=$(mktemp -d)
  trap 'rm -rf "$tmp"' EXIT
  # Force the graphical PIN prompt: setsid drops the controlling terminal so the
  # prompt can't open /dev/tty, stdin is /dev/null, and SSH_ASKPASS_REQUIRE=force
  # tells OpenSSH-derived prompts to use SSH_ASKPASS (pivy's zenity helper)
  # instead of the terminal. setsid -w waits and propagates the exit status.
  # Workaround until piggy show-batch consults SSH_ASKPASS by default:
  # https://github.com/amarbel-llc/piggy/issues/140 — drop the setsid/force/
  # /dev/null wrapper once that lands.
  SSH_ASKPASS_REQUIRE=force \
  PIGGY_STORE_DIR="{{PIGGY_STORE_DIR}}" \
    setsid -w piggy pass show-batch --out-dir "$tmp" \
      github-readme-token html2image-api-key \
      < /dev/null
  # Wrap each plaintext into its PHP class; var_export gives injection-safe quoting.
  php -r '
    $targets = [
      ["api/protected/lib/GithubToken.php",      "GithubToken",      "TOKEN", $argv[1]],
      ["app/protected/lib/Html2ImageApiKey.php", "Html2ImageApiKey", "KEY",   $argv[2]],
    ];
    foreach ($targets as [$path, $class, $const, $plaintextFile]) {
      $value = rtrim(file_get_contents($plaintextFile), "\n");
      file_put_contents($path,
        "<?php\n\ndeclare(strict_types=1);\n\nclass {$class}\n{\n    const {$const} = "
        . var_export($value, true) . ";\n}\n");
    }
  ' "$tmp/github-readme-token" "$tmp/html2image-api-key"
  echo "revealed: api/protected/lib/GithubToken.php app/protected/lib/Html2ImageApiKey.php"

[group("operational")]
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

# Regenerate app/public/.htaccess from the unified router definition.
[group("build")]
build-htaccess:
  php app/private/router.php --generate-htaccess > app/public/.htaccess

[group("post-build")]
test-htaccess:
  app/private/test-htaccess.sh

[group("post-build")]
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

[group("post-build")]
test: test-htaccess test-router test-readme-absolutize

# Hermetic unit check of the README link absolutizer (no network, no composer):
# asserts the request-time DOMDocument rewrite matches the build-time ast-grep
# rules and leaves absolute/scheme-relative/anchor/mailto/data refs — and
# code-block literals — untouched. In the `test` gate.
[group("post-build")]
test-readme-absolutize:
  php api/private/test-readme-absolutize.php

# Run the shared card-render package's standalone unit tests. Serves the
# card-render dev loop (Card\Html2Image, CardRenderer, OgImage). eng:tdd.
[group("post-build")]
test-card-render:
  #!/usr/bin/env bash
  set -euo pipefail
  for t in shared/card-render/tests/*.php; do
    echo "# $t"
    php "$t"
  done

# Verify the /code tab + /code/<project> README pages end to end. Starts app+api
# locally (API with the explicit autoload the recipes otherwise omit) and asserts
# CONTENT, not just status — so an API autoload fatal can't masquerade as a 200
# the way it does under test-router. Serves the build-code-github dev loop.
# Standalone (not in `test`): the README partials are gitignored / built on
# demand, but every check here also passes via the description fallback.
[group("post-build")]
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

# Networked end-to-end check of the live README read-through. With a piggy-
# revealed token present (api/protected/lib/GithubToken.php) it starts app+api
# and asserts /code/<PROJECT> serves live GitHub README content — the MARKER
# string, which the description fallback lacks — and that relative links are
# absolutized back to GitHub. Skips cleanly with no token. Networked +
# token-gated, so NOT in the `test` gate; run before a deploy that touches the
# read-through. MARKER defaults to a word in dodder's live README.
[group("post-build")]
test-readme-live PORT="2293" API_PORT="2292" PROJECT="dodder" MARKER="Zettelkasten": build-php-composer
  #!/usr/bin/env bash
  set -euo pipefail

  echo "TAP version 14"
  echo "1..3"

  if [ ! -f api/protected/lib/GithubToken.php ]; then
    echo "ok 1 - SKIP no GithubToken.php (run 'just reveal-secrets' first)"
    echo "ok 2 - SKIP no token"
    echo "ok 3 - SKIP no token"
    exit 0
  fi

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

  api="http://localhost:{{API_PORT}}"
  base="http://localhost:{{PORT}}"
  fail=0

  # 1. API serves the live README partial carrying the freshness marker.
  if curl -sf "$api/code/{{PROJECT}}/html" | grep -q "{{MARKER}}"; then
    echo "ok 1 - API /code/{{PROJECT}}/html carries live README ({{MARKER}})"
  else
    echo "not ok 1 - API live README missing marker {{MARKER}}"; fail=1
  fi

  # 2. Frontend page renders that same live README body.
  if [ "$(curl -s -o /dev/null -w '%{http_code}' "$base/code/{{PROJECT}}")" = "200" ] \
     && curl -s "$base/code/{{PROJECT}}" | grep -q "{{MARKER}}"; then
    echo "ok 2 - /code/{{PROJECT}} renders live README ({{MARKER}})"
  else
    echo "not ok 2 - /code/{{PROJECT}} missing live README marker"; fail=1
  fi

  # 3. Relative README links/images are absolutized back to GitHub.
  if curl -s "$api/code/{{PROJECT}}/html" \
       | grep -q "https://github.com/amarbel-llc/{{PROJECT}}/\(blob\|raw\)/HEAD/"; then
    echo "ok 3 - relative README links absolutized to GitHub"
  else
    echo "not ok 3 - no absolutized GitHub links found"; fail=1
  fi

  exit $fail

[group("operational")]
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

[group("operational")]
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
[group("operational")]
deploy-local-fast PORT="2222" API_PORT="2223": build-php-composer
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
