# AI Coding Instructions for Scraper API

## Project Overview
Laravel 12 web scraper API with Laravel Octane (FrankenPHP) for high-performance image extraction from URLs. Uses Symfony DomCrawler for HTML parsing and intelligent image scoring/filtering.

## Architecture & Core Components

### Service Pattern
- Business logic lives in `app/Services/` (e.g., `ImageExtractor.php`)
- Controllers are thin, single-responsibility invokables in `app/Http/Controllers/`
- Use constructor injection for services: `public function __invoke(Request $request, ImageExtractor $extractor)`

### ImageExtractor Service
The core service (`app/Services/ImageExtractor.php`) implements a sophisticated image extraction pipeline:
1. **Concurrent fetching**: Uses `Http::pool()` for parallel URL scraping (configurable via `config/scraper.php`)
2. **Multi-source extraction**: Prioritizes Open Graph/Twitter meta tags, then falls back to `<img>` tags with lazy-loading support (`data-src`, `data-lazy-src`)
3. **Intelligent scoring**: Scores images by relevance (social media images +20, same-domain +10, size heuristics, format bonuses)
4. **Comprehensive junk filtering**: 
   - Hard blacklist for tracking pixels, icons, logos, small thumbnails (<150px), analytics domains
   - Query parameter size detection (`?w=40&h=40`)
   - Aspect ratio penalties for stretched banners (ratio >15:1 or <1:5)
   - Path-based filtering (`/products/`, `/merchants/`, `/infographics/`, `/misc/`, `/thumbor/`, etc.)
   - Configurable GIF blocking (default: enabled)
5. **Top N selection**: Returns configurable number of images (default 5) per URL

Example usage pattern:
```php
$results = $extractor->extractFromUrls($request->urls, blockGifs: true);
// Returns: [['url' => '...', 'images' => [...], 'count' => 5], ...]
```

### Configuration
Scraper behavior is configurable via `config/scraper.php`:
- `block_gifs`: Block GIF images (default: true) - can be overridden per-request
- `max_images_per_url`: Maximum images to return per URL (default: 5)
- `concurrency`: HTTP pool concurrency for parallel scraping (default: 5)

## Development Workflow

### Aliases & Commands
Use `pa` alias (likely defined in shell) instead of `php artisan`:
- Create controllers: `pa make:controller NameController`
- Run migrations: `pa migrate`
- Clear cache: `pa config:clear`

### Running the Application
**Preferred**: Use composer scripts for coordinated development environment:
```bash
composer dev  # Runs server, queue worker, logs (pail), and vite concurrently
```

This starts:
- Laravel development server (`php artisan serve`)
- Queue listener (`php artisan queue:listen`)
- Log viewer (`php artisan pail`)
- Vite dev server for asset compilation

**Alternative**: Individual commands
```bash
php artisan serve               # Development server
php artisan octane:start        # Production-like (FrankenPHP)
```

### Testing
Uses Pest PHP (v4.3) as the test framework:
```bash
composer test                   # Clears config + runs test suite
php artisan test                # Direct test execution
```

Test files in `tests/Feature/` and `tests/Unit/`. Write Pest-style function tests, not class-based PHPUnit.

### Initial Setup
```bash
composer setup   # Installs deps, copies .env, generates key, migrates, builds assets
```

## Project-Specific Conventions

### API Endpoints
- Single invokable controllers for simple actions (see `ScrapeController`)
- Route pattern: `Route::post('/scrape/images', App\Http\Controllers\ScrapeController::class)`
- JSON responses with standard structure: `{ success: bool, data: array, count: int }`

### Validation
Always validate incoming requests directly in controllers:
```php
$request->validate([
    'urls' => 'required|array|min:1|max:50',
    'urls.*' => 'required|url',
]);
```

### HTTP Client Pattern
- Use Laravel's `Http` facade for external requests
- Prefer `Http::pool()` for concurrent requests (see `ImageExtractor::extractFromUrls()`)
- Pool responses are keyed by their `as()` identifier

### Code Style
- Follow Laravel conventions and PSR-12
- Use Laravel Pint for formatting: `./vendor/bin/pint`
- Prefer explicit over implicit (e.g., full namespace paths in routes)

## Dependencies & Integration

### Key Packages
- **Laravel Octane**: High-performance server (FrankenPHP backend configured in `config/octane.php`)
- **Symfony DomCrawler**: HTML parsing (`Crawler`) and URI resolution (`UriResolver`)
- **Laravel Sanctum**: API authentication (configured but not actively used in scraper endpoint)
- **Tailwind CSS v4**: Frontend styling (Vite plugin configured)

### External Service Integration
When scraping external URLs:
- Always use `UriResolver::resolve()` to handle relative URLs correctly
- Check response success before parsing: `$response->successful()`
- Consider rate limiting and respect robots.txt in production

## Performance Considerations
- Octane keeps application in memory; avoid static state accumulation
- HTTP pool concurrency is tunable (default 5, configurable via method param)
- Image scoring algorithm optimizes for top 5 results (adjust `array_slice` limit if needed)

## Common Patterns to Follow
1. **Service extraction**: Move complex logic from controllers to services
2. **Single action controllers**: Use invokable controllers for simple endpoints
3. **Validation first**: Always validate at controller entry point
4. **Concurrent operations**: Use pools/batches for external API calls
5. **Score-and-filter**: For ranking/selection algorithms, separate scoring from filtering
