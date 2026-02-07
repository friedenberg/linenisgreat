#!/usr/bin/env bash
set -euo pipefail

PORT="${1:-2299}"
BASE_URL="http://localhost:${PORT}"

# Colors for output
RED='\033[0;31m'
GREEN='\033[0;32m'
NC='\033[0m' # No Color

FAILED=0

test_route() {
    local path="$1"
    local expected_status="$2"
    local description="$3"

    actual_status=$(curl -s -o /dev/null -w "%{http_code}" "${BASE_URL}${path}")

    if [[ "$actual_status" == "$expected_status" ]]; then
        echo -e "${GREEN}✓${NC} ${description}: ${path} → ${actual_status}"
    else
        echo -e "${RED}✗${NC} ${description}: ${path} → ${actual_status} (expected ${expected_status})"
        FAILED=1
    fi
}

echo "Testing router at ${BASE_URL}"
echo

# Homepage
test_route "/" 200 "Homepage"

# Section index pages
test_route "/yoga" 200 "Yoga index"
test_route "/yoga/" 200 "Yoga index (trailing slash)"
test_route "/code" 200 "Code index"
test_route "/objects" 200 "Objects index"
test_route "/notes" 200 "Notes index"
test_route "/slides" 200 "Slides index"
test_route "/cocktails" 200 "Cocktails index"
test_route "/resume" 200 "Resume"
test_route "/meet" 200 "Meet"

# Parameterized routes
test_route "/yoga/test-pose" 200 "Yoga object"
test_route "/code/testproject" 200 "Code project"
test_route "/code/testproject/subpath" 200 "Code project with remainder"

# Static assets
test_route "/assets/stylesheet.css" 200 "Static CSS file"

# 404s
test_route "/nonexistent-page" 404 "Nonexistent page"

echo
if [[ "$FAILED" -eq 0 ]]; then
    echo -e "${GREEN}All tests passed${NC}"
    exit 0
else
    echo -e "${RED}Some tests failed${NC}"
    exit 1
fi
