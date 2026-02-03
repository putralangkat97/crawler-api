# 🧪 Testing Documentation Index

## 📚 Documentation Files Created

### 1. **`.github/QUICK_START_TESTING.md`** ⭐ START HERE
**Your go-to guide for getting started**
- TL;DR section with 1-liners
- 3 ways to test (automated, manual, Docker)
- Setup checklist
- Payload examples (including your sample)
- Response examples
- Common issues & solutions

👉 **Use this for:** Quick reference, getting tests running fast

---

### 2. **`.github/TESTING_GUIDE.md`** (Comprehensive)
**Deep dive into all testing approaches**
- Pest test structure (Feature & Unit)
- cURL examples for all endpoints
- Docker testing
- Debugging & troubleshooting
- Performance testing with Siege/Apache Bench
- Testing checklist (13 items)

👉 **Use this for:** Complete testing reference, detailed examples

---

### 3. **`.github/copilot-instructions.md`** (Updated)
**AI agent development guide** (already expanded)
- Architecture overview
- Service boundaries
- Data flows
- Common patterns
- **NEW:** Renderer integration & circuit breaker
- **NEW:** Multi-queue architecture details
- **NEW:** SSRF protection mechanisms
- **NEW:** Pest test patterns

👉 **Use this for:** Understanding architecture, AI/LLM context

---

## 🧬 Test Files Created

### 4. **`tests/Feature/ScrapeAndCrawlTest.php`** (11 tests)
```bash
php artisan test tests/Feature/ScrapeAndCrawlTest.php
```

Tests covered:
- ✅ Single URL scrape
- ✅ Batch URL scrape
- ✅ Invalid URL rejection
- ✅ Multiple return formats
- ✅ Chrome rendering validation
- ✅ Crawl job creation
- ✅ Crawl job status check
- ✅ Crawl results retrieval
- ✅ Job cancellation
- ✅ Health & readiness endpoints

---

### 5. **`tests/Unit/ServicesTest.php`** (11 tests)
```bash
php artisan test tests/Unit/ServicesTest.php
```

Tests covered:
- ✅ SSRF blocking (private IPs)
- ✅ SSRF blocking (invalid schemes)
- ✅ SSRF allowing (public URLs)
- ✅ URL normalization
- ✅ Domain extraction
- ✅ JS detection (SmartRouter)
- ✅ Politeness limiting
- ✅ Image scoring
- ✅ PDF content handling
- ✅ Max bytes enforcement
- ✅ Circuit breaker logic

---

## 🚀 Quick Test Scripts

### 6. **`api-test.sh`** (Executable)
```bash
bash api-test.sh scrape_simple    # Single URL
bash api-test.sh scrape_batch     # Multiple URLs
bash api-test.sh scrape_chrome    # With rendering
bash api-test.sh crawl_start      # Create async job
bash api-test.sh all              # Run all tests
```

12 different test scenarios with pretty output & error handling

---

## 🎯 Quick Navigation

### "I want to..."

| Goal | Command | File |
|------|---------|------|
| **Run all tests** | `composer test` | `QUICK_START_TESTING.md` |
| **Test single URL** | `bash api-test.sh scrape_simple` | `api-test.sh` |
| **Test batch URLs** | See payload example | `QUICK_START_TESTING.md` |
| **Test async crawl** | `bash api-test.sh crawl_start` | `api-test.sh` |
| **Understand architecture** | Read "Big Picture" | `copilot-instructions.md` |
| **Debug SSRF issues** | Check Unit test example | `ServicesTest.php` |
| **Write new feature test** | Copy pattern from | `ScrapeAndCrawlTest.php` |
| **Check circuit breaker** | Search "RendererClient" | `copilot-instructions.md` |
| **Setup environment** | Run `./setup.sh` | `QUICK_START_TESTING.md` |

---

## 📋 Test Coverage

### Endpoints Tested (Feature Tests)
- ✅ `POST /api/v1/scrape` (single & batch)
- ✅ `POST /api/v1/crawl` (job creation)
- ✅ `GET /api/v1/crawl/{job_id}` (status)
- ✅ `GET /api/v1/crawl/{job_id}/results` (results)
- ✅ `DELETE /api/v1/crawl/{job_id}` (cancel)
- ✅ `GET /health` (health check)
- ✅ `GET /ready` (readiness check)

### Services Tested (Unit Tests)
- ✅ `SsrfGuard` - SSRF protection
- ✅ `UrlNormalizer` - URL normalization
- ✅ `SmartRouter` - JS detection
- ✅ `PolitenessLimiter` - Rate limiting
- ✅ `ImageExtractor` - Image scoring
- ✅ `Extractor` - Content extraction
- ✅ `HttpFetcher` - HTTP fetching with limits
- ✅ `RendererClient` - Circuit breaker
- ✅ `CrawlJob` - Model casting

---

## 🔍 Example Payloads Included

All payloads from your `.github/example-payload.json` are documented with:
- ✅ Single URL examples
- ✅ Batch URL examples (8 URLs from your file)
- ✅ Chrome rendering examples
- ✅ Crawl with polite options
- ✅ Different return formats
- ✅ SSRF test cases

---

## 📖 How to Use This Documentation

### For Quick Testing:
1. Read `QUICK_START_TESTING.md` (5 min read)
2. Run `composer dev` to start services
3. Run `bash api-test.sh all` to test
4. Check outputs and response examples

### For Comprehensive Testing:
1. Read `TESTING_GUIDE.md` (detailed reference)
2. Write tests in `tests/Feature/` and `tests/Unit/`
3. Run `php artisan test --watch` for development
4. Use Docker Compose for integration tests

### For AI Agent Context:
1. Read `copilot-instructions.md` (architecture)
2. Reference specific service implementations
3. Check test patterns in `ServicesTest.php`
4. Follow conventions documented

---

## 🛠️ Commands Cheat Sheet

```bash
# Testing
composer test                    # Run all tests
php artisan test --watch         # Watch mode
bash api-test.sh all             # Test all endpoints

# Development
composer dev                      # Start all services
php artisan serve                # Server only
php artisan queue:listen         # Queue worker
php artisan pail                 # View logs

# Debugging
php artisan tinker               # Interactive shell
php artisan queue:failed         # View failed jobs
php artisan cache:clear          # Clear cache
docker-compose logs -f app       # Docker logs

# Setup
./setup.sh                       # Initial setup
php artisan migrate              # Run migrations
php artisan db:seed              # Seed database
```

---

## ✅ Verification Checklist

After setup, verify everything works:
- [ ] `composer test` passes ✓
- [ ] `bash api-test.sh all` passes ✓
- [ ] Endpoints respond correctly ✓
- [ ] Queue processes jobs ✓
- [ ] Renderer service available ✓
- [ ] Database connected ✓
- [ ] Redis cache working ✓

---

## 📞 Support

Each document includes:
- Common issues & solutions
- Debugging tips
- Links to related sections
- Code examples

**Main reference files:**
- 🏗️ Architecture: `copilot-instructions.md`
- 🧪 Testing Details: `TESTING_GUIDE.md`
- 🚀 Quick Start: `QUICK_START_TESTING.md`
- 🧬 Tests: `tests/Feature/` & `tests/Unit/`
- 🔧 API Script: `api-test.sh`

---

**Last Updated:** February 3, 2026
**Files:** 6 documents + 1 script + 22 test cases
