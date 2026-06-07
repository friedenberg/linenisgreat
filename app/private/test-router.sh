#!/usr/bin/env bash
set -euo pipefail

PORT="${1:-2299}"
BASE_URL="http://localhost:${PORT}"

TEST_NUM=0
FAILED=0

# Define all tests upfront for TAP plan (path|status|description)
TESTS=(
  "/|200|Homepage"
  "/yoga|200|Yoga index"
  "/yoga/|200|Yoga index (trailing slash)"
  "/code|200|Code index"
  "/objects|200|Objects index"
  "/notes|200|Notes index"
  "/slides|200|Slides index"
  "/cocktails|200|Cocktails index"
  "/events|200|Events index"
  "/events/summer-solstice-group-ride|200|Event object"
  "/resume|200|Resume"
  "/meet|200|Meet"
  "/yoga/test-pose|200|Yoga object"
  "/code/testproject|200|Code project"
  "/code/testproject/subpath|200|Code project with remainder"
  "/assets/stylesheet.css|200|Static CSS file"
  "/nonexistent-page|404|Nonexistent page"
)

test_route() {
  local path="$1"
  local expected_status="$2"
  local description="$3"

  ((++TEST_NUM))

  actual_status=$(curl -s -o /dev/null -w "%{http_code}" "${BASE_URL}${path}")

  if [[ $actual_status == "$expected_status" ]]; then
    echo "ok ${TEST_NUM} - ${description}: ${path} -> ${actual_status}"
  else
    echo "not ok ${TEST_NUM} - ${description}: ${path}"
    echo "  ---"
    echo "  expected: ${expected_status}"
    echo "  actual: ${actual_status}"
    echo "  ..."
    ((++FAILED))
  fi
}

# Assert the response body for a path does (or does NOT) contain a substring.
# mode is "contains" or "absent".
test_body() {
  local path="$1"
  local mode="$2"
  local needle="$3"
  local description="$4"

  ((++TEST_NUM))

  local body
  body=$(curl -s "${BASE_URL}${path}")

  local hit=0
  if [[ $body == *"$needle"* ]]; then
    hit=1
  fi

  local pass=0
  case "$mode" in
  contains) [[ $hit -eq 1 ]] && pass=1 ;;
  absent) [[ $hit -eq 0 ]] && pass=1 ;;
  esac

  if [[ $pass -eq 1 ]]; then
    echo "ok ${TEST_NUM} - ${description}"
  else
    echo "not ok ${TEST_NUM} - ${description}"
    echo "  ---"
    echo "  path: ${path}"
    echo "  mode: ${mode}"
    echo "  needle: ${needle}"
    echo "  ..."
    ((++FAILED))
  fi
}

gum style --border normal --padding "0 1" --border-foreground 212 \
  "Testing router at ${BASE_URL}"

# 7 extra body assertions beyond the status-code TESTS plan.
PLAN=$((${#TESTS[@]} + 7))

# TAP header
echo "TAP version 14"
echo "1..${PLAN}"

# Run tests
for test in "${TESTS[@]}"; do
  IFS='|' read -r path expected description <<<"$test"
  test_route "$path" "$expected" "$description"
done

# Detail pages emit an absolute og:image meta pointing at the API format
# endpoint; index pages must not. (Host is the test API_BASE_URL locally, so we
# assert the path suffix, not the host.)
test_body "/code/testproject" "contains" \
  'property="og:image"' \
  "Detail page emits og:image meta: /code/testproject"
test_body "/code/testproject" "contains" \
  '/blob/formats/og-image' \
  "og:image content points at format endpoint: /code/testproject"
test_body "/code" "absent" \
  'og:image' \
  "Index page omits og:image meta: /code"

# The events index auto-includes Atom/RSS alternate links (framework feed) and a
# visible feed footer; the event detail page carries the framework object footer
# (ics | add to cal) and an og:image.
test_body "/events" "contains" \
  'type="application/atom+xml"' \
  "Events index emits Atom alternate link: /events"
test_body "/events" "contains" \
  'class="feed-link"' \
  "Events index emits visible feed links: /events"
test_body "/events/summer-solstice-group-ride" "contains" \
  'add to cal' \
  "Event detail emits ics | add to cal footer"
test_body "/events/summer-solstice-group-ride" "contains" \
  '/blob/formats/og-image' \
  "Event detail emits og:image meta"

# Summary
echo
if [[ $FAILED -eq 0 ]]; then
  gum style --foreground 212 "All ${PLAN} tests passed"
  exit 0
else
  gum style --foreground 196 "${FAILED} of ${PLAN} tests failed"
  exit 1
fi
