import Fastify from "fastify";
import { chromium, Browser, Page } from "playwright";
import pLimit from "p-limit";

const port = Number(process.env.RENDERER_PORT || 3001);
const maxConcurrency = Number(process.env.RENDERER_MAX_CONCURRENCY || 2);
const defaultTimeout = Number(process.env.RENDERER_TIMEOUT_MS || 20000);

const app = Fastify({ logger: true });
const limit = pLimit(maxConcurrency);

let browser: Browser | null = null;

async function getBrowser(): Promise<Browser> {
  if (!browser) {
    browser = await chromium.launch({ args: ["--no-sandbox", "--disable-dev-shm-usage"] });
  }
  return browser;
}

async function createPage(): Promise<Page> {
  const b = await getBrowser();
  const context = await b.newContext({
    userAgent: "ScraperAPI/2.0",
  });
  const page = await context.newPage();

  await page.route("**/*", (route) => {
    const type = route.request().resourceType();
    if (["image", "media", "font"].includes(type)) {
      return route.abort();
    }
    return route.continue();
  });

  return page;
}

app.post("/render", async (request, reply) => {
  const body = request.body as {
    url: string;
    wait_for?: Array<{ type: string; selector?: string; timeout_ms?: number }>;
    scroll?: number;
    timeout_ms?: number;
  };

  if (!body?.url) {
    return reply.status(400).send({ error: "url required" });
  }

  const timeoutMs = body.timeout_ms ?? defaultTimeout;

  return limit(async () => {
    const start = Date.now();
    const page = await createPage();
    try {
      const response = await page.goto(body.url, { waitUntil: "domcontentloaded", timeout: timeoutMs });

      if (body.wait_for?.length) {
        for (const step of body.wait_for) {
          if (step.type === "selector" && step.selector) {
            await page.waitForSelector(step.selector, { timeout: step.timeout_ms ?? timeoutMs });
          }
        }
      }

      if (body.scroll && body.scroll > 0) {
        await page.evaluate(async (ms: number) => {
          const startTime = Date.now();
          while (Date.now() - startTime < ms) {
            window.scrollBy(0, window.innerHeight);
            await new Promise((r) => setTimeout(r, 250));
          }
        }, body.scroll);
      }

      const html = await page.content();
      const finalUrl = page.url();
      const statusCode = response?.status() ?? 200;
      const contentType = response?.headers()["content-type"] ?? "text/html";

      return reply.send({
        final_url: finalUrl,
        status_code: statusCode,
        content_type: contentType,
        html,
        timing_ms: { total: Date.now() - start },
      });
    } finally {
      await page.context().close();
    }
  });
});

app.listen({ port, host: "0.0.0.0" });