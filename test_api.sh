#!/bin/bash

echo "Testing Spider.cloud-like API v1"
echo "=================================="
echo ""

# Test 1: Simple scrape
echo "Test 1: Simple scrape (example.com)"
curl -s -X POST http://localhost:8000/api/v1/scrape \
  -H "Content-Type: application/json" \
  -d '{
    "url": "https://example.com",
    "return_format": "markdown",
    "metadata": true,
    "cache": true
  }' | jq -r '.success, .data.title, .data.source_type' | head -3

echo ""
echo "=================================="
echo "API test script created!"
echo "Run: bash test_api.sh"
