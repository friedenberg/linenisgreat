#!/usr/bin/env bash
set -euo pipefail

# Colors for output
RED='\033[0;31m'
GREEN='\033[0;32m'
NC='\033[0m' # No Color

FAILED=0

check_contains() {
    local pattern="$1"
    local description="$2"

    if echo "$HTACCESS" | grep -qE "$pattern"; then
        echo -e "${GREEN}✓${NC} ${description}"
    else
        echo -e "${RED}✗${NC} ${description} (pattern not found: ${pattern})"
        FAILED=1
    fi
}

echo "Testing htaccess generation"
echo

HTACCESS=$(php private/router.php --generate-htaccess)

# Check header
check_contains "^Options \+FollowSymLinks -Indexes" "Options directive"
check_contains "^RewriteEngine On" "RewriteEngine enabled"

# Check routes
check_contains 'RewriteRule.*object\.php\?tab=about' "Homepage route"
check_contains 'RewriteRule.*yoga\|code.*\$1\.php' "Section index routes"
check_contains 'RewriteRule.*objects.*object_with_metadata\.php' "Objects route"
check_contains 'RewriteRule.*notes.*object_with_metadata\.php' "Notes route"
check_contains 'RewriteRule.*slides.*object\.php.*template=slide' "Slides route"
check_contains 'RewriteRule.*yoga/.*yoga_object\.php' "Yoga object route"
check_contains 'RewriteRule.*code/.*code\.php' "Code project route"

# Check redirect
check_contains "RewriteCond.*code\.linenisgreat\.com" "Subdomain condition"
check_contains "RewriteRule.*https://linenisgreat\.com/code/" "Subdomain redirect"

echo
if [[ "$FAILED" -eq 0 ]]; then
    echo -e "${GREEN}All tests passed${NC}"
    exit 0
else
    echo -e "${RED}Some tests failed${NC}"
    exit 1
fi
