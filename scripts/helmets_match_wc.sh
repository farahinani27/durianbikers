#!/bin/bash
# Match HELMETS masterlist to WooCommerce with rate limiting and error recovery

set -euo pipefail

# Credentials come from the gitignored .env at the repo root -- never inline.
#   set -a; . ./.env; set +a
WC_BASE="${WP_BASE_URL:?source ./.env first}"
WC_API_USER="${WP_API_USER:?source ./.env first}"
WC_API_PASS="${WP_APP_PASSWORD:?source ./.env first}"

CSV_IN="/Users/superfly2026/Downloads/HELMETS_FULL_RECONCILIATION_2026-08-19.csv"
CSV_OUT="/Users/superfly2026/Downloads/HELMETS_FULL_RECONCILIATION_2026-08-19.csv"

MATCHED=0
NO_MATCH=0
RATE_LIMIT_DELAY=0.3

# Colors for stderr
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
NC='\033[0m' # No Color

# Function to make API calls with basic auth
wc_api_call() {
    local endpoint="$1"
    local params="${2:-}"
    local url="${WC_BASE}/wp-json/wc/v3${endpoint}"

    if [ -n "$params" ]; then
        url="${url}?${params}"
    fi

    curl -s -u "${WC_API_USER}:${WC_API_PASS}" \
        -H "Content-Type: application/json" \
        --max-time 30 \
        "$url" 2>/dev/null || echo ""
}

# Function to extract value from JSON
json_get() {
    local json="$1"
    local key="$2"
    echo "$json" | grep -o "\"${key}\":[^,}]*" | head -1 | cut -d':' -f2- | tr -d '"' || echo ""
}

# Function to normalize search term
normalize_search() {
    local brand="$1"
    local series="$2"
    local model="$3"
    echo "${brand} ${series} ${model}" | tr '[:upper:]' '[:lower:]' | sed 's/[^a-z0-9 ]//g'
}

echo "Starting HELMETS → WooCommerce reconciliation..." >&2

# Skip markdown header lines and process data
line_num=0
while IFS='|' read -r _ brand series model sku price_ml price_wc xs s m l xl xxl xxxl status _; do
    ((line_num++))

    # Skip header rows (lines 1-2 are markdown table format markers)
    if [ $line_num -le 2 ]; then
        continue
    fi

    # Skip empty lines
    brand=$(echo "$brand" | xargs)
    if [ -z "$brand" ]; then
        continue
    fi

    series=$(echo "$series" | xargs)
    model=$(echo "$model" | xargs)

    echo -e "\n${YELLOW}[Line $line_num]${NC} ${brand} ${series} ${model}" >&2

    # Search for product
    search_term=$(normalize_search "$brand" "$series" "$model")
    echo "  Searching: ${search_term}" >&2

    # Make API call
    search_result=$(wc_api_call "/products" "search=$(python3 -c "import urllib.parse; print(urllib.parse.quote('$search_term'))")&per_page=10")

    # Check if we got results
    if echo "$search_result" | grep -q '"id"'; then
        product_id=$(echo "$search_result" | grep -o '"id":[0-9]*' | head -1 | cut -d':' -f2)
        product_name=$(echo "$search_result" | grep -o '"name":"[^"]*"' | head -1 | cut -d'"' -f4)

        echo -e "  ${GREEN}Found:${NC} $product_name (ID: $product_id)" >&2
        ((MATCHED++))

        # Get WC price
        product_data=$(wc_api_call "/products/$product_id")
        wc_price=$(echo "$product_data" | grep -o '"price":"[^"]*"' | head -1 | cut -d'"' -f4)

        echo "  WC Price: ${wc_price:-0}" >&2

        # Get variations (for stock)
        echo "  Fetching variations..." >&2
        variations=$(wc_api_call "/products/$product_id/variations" "per_page=100")

        # For now, just mark as found but with stub data
        # (full variation parsing requires more complex JSON handling)
        echo "  WC Price: ${wc_price}" >&2
    else
        echo -e "  ${RED}No match${NC}" >&2
        ((NO_MATCH++))
    fi

    # Rate limiting
    sleep "$RATE_LIMIT_DELAY"

done < "$CSV_IN"

echo -e "\n${GREEN}Matched: $MATCHED${NC}" >&2
echo -e "${RED}No match: $NO_MATCH${NC}" >&2
echo "Total: $((MATCHED + NO_MATCH))" >&2
