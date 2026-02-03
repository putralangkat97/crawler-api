#!/bin/bash

# Scraper API Testing Script
# Usage: bash api-test.sh [scrape|crawl|status|results|cancel|health]

set -e

BASE_URL="http://localhost:8000/api"
CONTENT_TYPE="Content-Type: application/json"

# Colors for output
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
NC='\033[0m' # No Color

print_header() {
    echo -e "\n${YELLOW}=== $1 ===${NC}\n"
}

print_success() {
    echo -e "${GREEN}✓ $1${NC}"
}

print_error() {
    echo -e "${RED}✗ $1${NC}"
}

# Test 1: Simple single URL scrape
test_scrape_simple() {
    print_header "Test 1: Simple URL Scrape"
    
    curl -s -X POST "$BASE_URL/v1/scrape" \
        -H "$CONTENT_TYPE" \
        -d '{
            "url": "https://example.com",
            "return_format": "markdown",
            "metadata": true
        }' | jq .
}

# Test 2: Batch scrape multiple URLs
test_scrape_batch() {
    print_header "Test 2: Batch Scrape (Multiple URLs)"
    
    curl -s -X POST "$BASE_URL/v1/scrape" \
        -H "$CONTENT_TYPE" \
        -d '{
            "url": [
                "https://example.com",
                "https://example.org",
                "https://example.net"
            ],
            "return_format": "markdown",
            "metadata": true,
            "timeout_ms": 15000
        }' | jq .
}

# Test 3: Scrape with Chrome rendering
test_scrape_chrome() {
    print_header "Test 3: Scrape with Chrome Rendering"
    
    curl -s -X POST "$BASE_URL/v1/scrape" \
        -H "$CONTENT_TYPE" \
        -d '{
            "url": "https://example.com",
            "request": "chrome",
            "wait_for": [
                {
                    "type": "selector",
                    "selector": "body",
                    "timeout_ms": 5000
                }
            ],
            "scroll": 2000,
            "return_format": "markdown"
        }' | jq .
}

# Test 4: Different return formats
test_scrape_formats() {
    print_header "Test 4: Test Different Return Formats"
    
    for format in markdown raw text commonmark; do
        echo -e "${YELLOW}Format: $format${NC}"
        curl -s -X POST "$BASE_URL/v1/scrape" \
            -H "$CONTENT_TYPE" \
            -d "{
                \"url\": \"https://example.com\",
                \"return_format\": \"$format\"
            }" | jq '.success' 
    done
}

# Test 5: Start a crawl job
test_crawl_start() {
    print_header "Test 5: Start Crawl Job"
    
    RESPONSE=$(curl -s -X POST "$BASE_URL/v1/crawl" \
        -H "$CONTENT_TYPE" \
        -d '{
            "url": "https://example.com",
            "limit": 20,
            "depth": 2,
            "same_domain_only": true,
            "return_format": "markdown",
            "metadata": true
        }')
    
    echo "$RESPONSE" | jq .
    
    # Extract job_id for later tests
    JOB_ID=$(echo "$RESPONSE" | jq -r '.job_id // empty')
    if [ -n "$JOB_ID" ]; then
        echo "$JOB_ID" > /tmp/crawl_job_id.txt
        print_success "Job created: $JOB_ID"
    fi
}

# Test 6: Check crawl job status
test_crawl_status() {
    print_header "Test 6: Check Crawl Job Status"
    
    if [ ! -f /tmp/crawl_job_id.txt ]; then
        print_error "No job ID found. Run 'test_crawl_start' first"
        return
    fi
    
    JOB_ID=$(cat /tmp/crawl_job_id.txt)
    
    curl -s -X GET "$BASE_URL/v1/crawl/$JOB_ID" \
        -H "$CONTENT_TYPE" | jq .
}

# Test 7: Get crawl results
test_crawl_results() {
    print_header "Test 7: Get Crawl Results (Paginated)"
    
    if [ ! -f /tmp/crawl_job_id.txt ]; then
        print_error "No job ID found. Run 'test_crawl_start' first"
        return
    fi
    
    JOB_ID=$(cat /tmp/crawl_job_id.txt)
    
    curl -s -X GET "$BASE_URL/v1/crawl/$JOB_ID/results?per_page=5&cursor=" \
        -H "$CONTENT_TYPE" | jq .
}

# Test 8: Cancel crawl job
test_crawl_cancel() {
    print_header "Test 8: Cancel Crawl Job"
    
    if [ ! -f /tmp/crawl_job_id.txt ]; then
        print_error "No job ID found. Run 'test_crawl_start' first"
        return
    fi
    
    JOB_ID=$(cat /tmp/crawl_job_id.txt)
    
    curl -s -X DELETE "$BASE_URL/v1/crawl/$JOB_ID" \
        -H "$CONTENT_TYPE" | jq .
}

# Test 9: Invalid URL test
test_invalid_url() {
    print_header "Test 9: Invalid URL (Should Fail)"
    
    curl -s -X POST "$BASE_URL/v1/scrape" \
        -H "$CONTENT_TYPE" \
        -d '{
            "url": "not-a-valid-url"
        }' | jq .
}

# Test 10: SSRF protection test
test_ssrf_protection() {
    print_header "Test 10: SSRF Protection (Should Block Private IP)"
    
    curl -s -X POST "$BASE_URL/v1/scrape" \
        -H "$CONTENT_TYPE" \
        -d '{
            "url": "http://127.0.0.1:8000/admin"
        }' | jq .
}

# Test 11: Health check
test_health() {
    print_header "Test 11: Health Check"
    
    curl -s -X GET "$BASE_URL/../health" | jq .
}

# Test 12: Readiness check
test_ready() {
    print_header "Test 12: Readiness Check"
    
    curl -s -X GET "$BASE_URL/../ready" | jq .
}

# Help message
show_help() {
    cat << EOF
${GREEN}Scraper API Testing Script${NC}

Usage: bash api-test.sh [test_name]

Available Tests:
  scrape_simple       - Single URL scrape
  scrape_batch        - Multiple URL scrape
  scrape_chrome       - Scrape with Chrome rendering
  scrape_formats      - Test different return formats
  crawl_start         - Start a crawl job
  crawl_status        - Check crawl job status
  crawl_results       - Get crawl results
  crawl_cancel        - Cancel a crawl job
  invalid_url         - Test invalid URL handling
  ssrf_protection     - Test SSRF protection
  health              - Health check
  ready               - Readiness check
  all                 - Run all tests

Examples:
  bash api-test.sh scrape_simple
  bash api-test.sh crawl_start
  bash api-test.sh all

Prerequisites:
  - App running: php artisan serve
  - Queue listening: php artisan queue:listen
  - jq installed: brew install jq (macOS) or apt-get install jq (Linux)

EOF
}

# Main script
case "${1:-all}" in
    scrape_simple)
        test_scrape_simple
        ;;
    scrape_batch)
        test_scrape_batch
        ;;
    scrape_chrome)
        test_scrape_chrome
        ;;
    scrape_formats)
        test_scrape_formats
        ;;
    crawl_start)
        test_crawl_start
        ;;
    crawl_status)
        test_crawl_status
        ;;
    crawl_results)
        test_crawl_results
        ;;
    crawl_cancel)
        test_crawl_cancel
        ;;
    invalid_url)
        test_invalid_url
        ;;
    ssrf_protection)
        test_ssrf_protection
        ;;
    health)
        test_health
        ;;
    ready)
        test_ready
        ;;
    all)
        test_scrape_simple
        test_scrape_batch
        test_scrape_formats
        test_invalid_url
        test_ssrf_protection
        test_health
        test_ready
        echo -e "\n${GREEN}All tests completed!${NC}"
        ;;
    help|-h|--help)
        show_help
        ;;
    *)
        echo -e "${RED}Unknown test: $1${NC}\n"
        show_help
        exit 1
        ;;
esac

echo ""
