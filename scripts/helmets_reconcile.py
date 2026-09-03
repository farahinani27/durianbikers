#!/usr/bin/env python3
"""
HELMETS reconciliation: Match masterlist to WooCommerce.
Reads CSV, queries WC API, updates with stock/price data.
"""

import csv
import json
import os
import time
import sys
from pathlib import Path
from typing import Dict, List, Tuple, Optional
import urllib.request
import urllib.error
import base64

# Configuration -- credentials come from the gitignored .env at the repo root.
#   set -a; . ./.env; set +a
WC_BASE_URL = os.environ.get("WP_BASE_URL", "").rstrip("/")
WC_API_USER = os.environ.get("WP_API_USER", "")
WC_API_PASSWORD = os.environ.get("WP_APP_PASSWORD", "")
if not (WC_BASE_URL and WC_API_USER and WC_API_PASSWORD):
    sys.exit("Missing WP_BASE_URL / WP_API_USER / WP_APP_PASSWORD -- source ./.env first")
WC_API = f"{WC_BASE_URL}/wp-json/wc/v3"

CSV_INPUT = Path("/Users/superfly2026/Downloads/HELMETS_FULL_RECONCILIATION_2026-08-19.csv")
CSV_OUTPUT = Path("/Users/superfly2026/Downloads/HELMETS_FULL_RECONCILIATION_2026-08-19.csv")

SIZE_COLUMNS = ["XS", "S", "M", "L", "XL", "XXL", "XXXL"]
SIZE_MAPPING = {
    "XS": "xs",
    "S": "s",
    "M": "m",
    "L": "l",
    "XL": "xl",
    "XXL": "xxl",
    "XXXL": "xxxl"
}

# Rate limiting
REQUEST_DELAY = 0.5  # seconds between requests
MAX_RETRIES = 3
RETRY_DELAY = 2  # seconds between retries

class WCAPIClient:
    """WooCommerce REST API client with auth and retry logic."""

    def __init__(self, base_url: str, user: str, password: str):
        self.base_url = base_url
        self.user = user
        self.password = password
        self.auth_header = self._make_auth_header()
        self.last_request_time = 0

    def _make_auth_header(self) -> str:
        """Create Basic Auth header."""
        credentials = f"{self.user}:{self.password}"
        encoded = base64.b64encode(credentials.encode()).decode()
        return f"Basic {encoded}"

    def _rate_limit(self):
        """Enforce rate limiting."""
        elapsed = time.time() - self.last_request_time
        if elapsed < REQUEST_DELAY:
            time.sleep(REQUEST_DELAY - elapsed)
        self.last_request_time = time.time()

    def _request(self, endpoint: str, params: str = "") -> Optional[Dict]:
        """Make API request with retry logic."""
        url = f"{self.base_url}{endpoint}"
        if params:
            url += f"?{params}"

        for attempt in range(MAX_RETRIES):
            try:
                self._rate_limit()
                req = urllib.request.Request(url)
                req.add_header("Authorization", self.auth_header)
                req.add_header("User-Agent", "PythonWCClient/1.0")

                with urllib.request.urlopen(req, timeout=10) as response:
                    data = response.read().decode('utf-8')
                    return json.loads(data)
            except (urllib.error.HTTPError, urllib.error.URLError) as e:
                print(f"  Attempt {attempt+1}/{MAX_RETRIES} failed: {e}", file=sys.stderr)
                if attempt < MAX_RETRIES - 1:
                    time.sleep(RETRY_DELAY)
                else:
                    return None
            except Exception as e:
                print(f"  Error: {e}", file=sys.stderr)
                return None

        return None

    def search_products(self, search_term: str) -> List[Dict]:
        """Search for products by name."""
        params = f"search={urllib.parse.quote(search_term)}&per_page=100"
        result = self._request("/products", params)
        return result if isinstance(result, list) else []

    def get_product(self, product_id: int) -> Optional[Dict]:
        """Get product by ID."""
        return self._request(f"/products/{product_id}")

    def get_variations(self, product_id: int) -> List[Dict]:
        """Get all variations for a product."""
        params = "per_page=100"
        result = self._request(f"/products/{product_id}/variations", params)
        return result if isinstance(result, list) else []

def normalize_name(brand: str, series: str, model: str) -> str:
    """Normalize product name for searching."""
    return f"{brand} {series} {model}".lower()

def match_product(client: WCAPIClient, brand: str, series: str, model: str) -> Tuple[Optional[int], Optional[Dict]]:
    """Find matching WC product and return ID + full data."""
    search_term = normalize_name(brand, series, model)
    print(f"  Searching: {search_term[:60]}", file=sys.stderr)

    products = client.search_products(search_term)
    if not products:
        print(f"    → No match", file=sys.stderr)
        return None, None

    # Return first match
    product = products[0]
    print(f"    → Found: {product['name'][:60]} (ID {product['id']})", file=sys.stderr)
    return product['id'], product

def get_variation_stock(client: WCAPIClient, product_id: int) -> Dict[str, int]:
    """Get per-size stock from variations."""
    variations = client.get_variations(product_id)
    stock_by_size = {size: 0 for size in SIZE_COLUMNS}

    for var in variations:
        # Try to find size attribute
        for attr in var.get('attributes', []):
            attr_name = attr.get('name', '').lower()
            attr_value = attr.get('option', '').lower()

            # Match size attribute
            for size_code, size_name in SIZE_MAPPING.items():
                if attr_value == size_name or attr_value == size_code.lower():
                    stock = var.get('stock_quantity', 0) or 0
                    stock_by_size[size_code] = stock
                    break

    return stock_by_size

def read_csv_rows(csv_file: Path) -> List[Dict]:
    """Read CSV and return rows as dicts."""
    rows = []
    with open(csv_file, 'r', encoding='utf-8') as f:
        # Skip header rows (lines 1-2 are markdown table format)
        f.readline()  # |---|
        f.readline()  # |---|

        # Read data rows
        for line in f:
            if not line.strip() or line.startswith('|') and '|' not in line[1:]:
                continue

            # Parse markdown table row
            cells = [cell.strip() for cell in line.split('|')[1:-1]]
            if len(cells) >= 14:
                rows.append({
                    'brand': cells[0],
                    'series': cells[1],
                    'model': cells[2],
                    'sku': cells[3],
                    'price_ml': cells[4],
                    'price_wc': cells[5],
                    'xs_orig': cells[6],
                    's_orig': cells[7],
                    'm_orig': cells[8],
                    'l_orig': cells[9],
                    'xl_orig': cells[10],
                    'xxl_orig': cells[11],
                    'xxxl_orig': cells[12],
                    'status': cells[13] if len(cells) > 13 else '❌'
                })

    return rows

def extract_ml_stock(stock_str: str) -> int:
    """Extract ML value from 'ML X → WC Y' format."""
    if ' → ' not in stock_str:
        return 0
    ml_part = stock_str.split(' → ')[0]
    try:
        return int(ml_part.split()[-1])
    except (ValueError, IndexError):
        return 0

def main():
    print(f"Reading masterlist data from {CSV_INPUT}", file=sys.stderr)
    rows = read_csv_rows(CSV_INPUT)
    print(f"Loaded {len(rows)} helmets", file=sys.stderr)

    # Initialize API client
    client = WCAPIClient(WC_API, WC_API_USER, WC_API_PASSWORD)

    matched_count = 0
    mismatched_count = 0

    # Process each helmet
    for i, row in enumerate(rows, 1):
        print(f"\n[{i}/{len(rows)}] {row['brand']} {row['series']} {row['model']}", file=sys.stderr)

        # Extract masterlist stock
        ml_stock = {
            'XS': extract_ml_stock(row['xs_orig']),
            'S': extract_ml_stock(row['s_orig']),
            'M': extract_ml_stock(row['m_orig']),
            'L': extract_ml_stock(row['l_orig']),
            'XL': extract_ml_stock(row['xl_orig']),
            'XXL': extract_ml_stock(row['xxl_orig']),
            'XXXL': extract_ml_stock(row['xxxl_orig']),
        }

        # Match to WC
        product_id, product = match_product(client, row['brand'], row['series'], row['model'])

        if not product:
            print(f"    Status: ❌ (no match)", file=sys.stderr)
            matched_count += 0
            mismatched_count += 1
            continue

        # Get WC price
        wc_price = product.get('price', '0')
        try:
            wc_price_val = float(wc_price or 0)
        except:
            wc_price_val = 0

        # Get variations
        wc_stock = get_variation_stock(client, product_id)

        # Check if stock matches
        stock_match = all(ml_stock[size] == wc_stock[size] for size in SIZE_COLUMNS)
        price_match = str(wc_price_val) == row['price_ml'].replace(',', '')

        status = '✅' if stock_match and price_match else '❌'
        print(f"    WC Price: {wc_price_val} (ML: {row['price_ml']})", file=sys.stderr)
        print(f"    Stock match: {stock_match}, Price match: {price_match}", file=sys.stderr)
        print(f"    Status: {status}", file=sys.stderr)

        # Update row
        row['price_wc'] = str(wc_price_val)
        for size in SIZE_COLUMNS:
            row[f'{size.lower()}_wc'] = f"ML {ml_stock[size]} → WC {wc_stock[size]}"
        row['status'] = status

        if status == '✅':
            matched_count += 1
        else:
            mismatched_count += 1

    # Write output
    print(f"\n\nWriting reconciliation results to {CSV_OUTPUT}", file=sys.stderr)
    with open(CSV_OUTPUT, 'w', encoding='utf-8', newline='') as f:
        writer = csv.writer(f)

        # Write header
        writer.writerow(['| BRAND | SERIES | MODEL | SKU | PRICE_ML | PRICE_WC | XS | S | M | L | XL | XXL | XXXL | STATUS |'])
        writer.writerow(['|-------|--------|-------|-----|----------|----------|-------|-------|-------|-------|-------|-------|-------|--------|'])

        # Write data rows
        for row in rows:
            writer.writerow([
                f"| {row['brand']} | {row['series']} | {row['model']} | {row['sku']} | {row['price_ml']} | {row['price_wc']} | "
                f"{row.get('xs_wc', row['xs_orig'])} | {row.get('s_wc', row['s_orig'])} | {row.get('m_wc', row['m_orig'])} | "
                f"{row.get('l_wc', row['l_orig'])} | {row.get('xl_wc', row['xl_orig'])} | {row.get('xxl_wc', row['xxl_orig'])} | "
                f"{row.get('xxxl_wc', row['xxxl_orig'])} | {row['status']} |"
            ])

    print(f"\n✅ Matched: {matched_count}", file=sys.stderr)
    print(f"❌ Mismatched/No match: {mismatched_count}", file=sys.stderr)
    print(f"Total: {len(rows)}", file=sys.stderr)

if __name__ == '__main__':
    main()
