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

    if [[ "$actual_status" == "$expected_status" ]]; then
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

gum style --border normal --padding "0 1" --border-foreground 212 \
    "Testing router at ${BASE_URL}"

# TAP header
echo "TAP version 14"
echo "1..${#TESTS[@]}"

# Run tests
for test in "${TESTS[@]}"; do
    IFS='|' read -r path expected description <<< "$test"
    test_route "$path" "$expected" "$description"
done

# Summary
echo
if [[ "$FAILED" -eq 0 ]]; then
    gum style --foreground 212 "All ${#TESTS[@]} tests passed"
    exit 0
else
    gum style --foreground 196 "${FAILED} of ${#TESTS[@]} tests failed"
    exit 1
fi
