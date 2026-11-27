import { test, expect, type Page } from '@playwright/test';
import { config, ignoreConsole, ignoreRequests, type PathConfig } from './config';

const visual = process.env.SMOKE_VISUAL !== 'off';
const cacheBust = process.env.SMOKE_CACHE_BUST || String(Date.now());

const PHP_ERRORS = [
	/Fatal error/i,
	/There has been a critical error/i,
	/<b>(Warning|Notice|Deprecated|Parse error)<\/b>:/,
	/Uncaught (Error|Exception)/,
];

// Required lists add to the defaults; everything else overrides.
function settings(p: PathConfig): Required<Omit<PathConfig, 'title_pattern'>> & { title_pattern?: string } {
	const d = config.defaults ?? {};
	return {
		path: p.path,
		content_selector: p.content_selector ?? d.content_selector ?? 'main, [role="main"]',
		min_content_chars: p.min_content_chars ?? d.min_content_chars ?? 200,
		min_body_bytes: p.min_body_bytes ?? d.min_body_bytes ?? 5000,
		title_pattern: p.title_pattern ?? d.title_pattern,
		required: [...(d.required ?? []), ...(p.required ?? [])],
		mask: [...(d.mask ?? []), ...(p.mask ?? [])],
		hide: [...(d.hide ?? []), ...(p.hide ?? [])],
		max_diff_pixel_ratio: p.max_diff_pixel_ratio ?? d.max_diff_pixel_ratio ?? 0.01,
		visual: p.visual ?? d.visual ?? true,
	};
}

function matchesAny(value: string, patterns: string[] = []): boolean {
	return patterns.some((p) => new RegExp(p).test(value));
}

function withCacheBust(path: string): string {
	const url = new URL(path, 'http://placeholder');
	url.searchParams.set('_smoke', cacheBust);
	return url.pathname + url.search;
}

function slug(path: string): string {
	return path.replace(/^\/|\/$/g, '').replace(/[^a-z0-9]+/gi, '-') || 'home';
}

// Scroll through the page so lazy-loaded images load, then wait for them.
async function settle(page: Page): Promise<void> {
	await page.evaluate(async () => {
		const step = window.innerHeight / 2;
		for (let y = 0; y < document.body.scrollHeight; y += step) {
			window.scrollTo(0, y);
			await new Promise((r) => setTimeout(r, 100));
		}
		window.scrollTo(0, 0);
	});
	await page.waitForLoadState('networkidle');
	// Lazy images outside the viewport's width (e.g. tickers) never load.
	await page.waitForFunction(() => Array.from(document.images)
		.filter((img) => {
			const r = img.getBoundingClientRect();
			return r.width > 0 && r.right > 0 && r.left < window.innerWidth;
		})
		.every((img) => img.complete), undefined, { timeout: 15_000 });
}

for (const entry of config.paths) {
	const s = settings(entry);

	test(s.path, async ({ page, baseURL }) => {
		const origin = new URL(baseURL!).origin;
		const consoleErrors: string[] = [];
		const failedRequests: string[] = [];

		page.on('console', (msg) => {
			if (msg.type() === 'error' && !matchesAny(msg.text(), ignoreConsole)) {
				consoleErrors.push(msg.text());
			}
		});
		page.on('pageerror', (err) => {
			if (!matchesAny(err.message, ignoreConsole)) {
				consoleErrors.push(`pageerror: ${err.message}`);
			}
		});
		// First-party assets only; third-party outages aren't our deploy.
		page.on('response', (res) => {
			const url = res.url();
			const type = res.request().resourceType();
			if (url.startsWith(origin) && res.status() >= 400 && ['stylesheet', 'script', 'image', 'font'].includes(type) && !matchesAny(url, ignoreRequests)) {
				failedRequests.push(`${res.status()} ${url}`);
			}
		});
		page.on('requestfailed', (req) => {
			const url = req.url();
			if (url.startsWith(origin) && !matchesAny(url, ignoreRequests)) {
				failedRequests.push(`failed ${url}: ${req.failure()?.errorText}`);
			}
		});

		const response = await page.goto(withCacheBust(s.path), { waitUntil: 'load' });
		expect(response, 'navigation response').not.toBeNull();
		expect(response!.status(), 'HTTP status').toBe(200);

		const html = await response!.text();
		expect(Buffer.byteLength(html), 'body size in bytes').toBeGreaterThanOrEqual(s.min_body_bytes);
		expect(html, 'closing </html>').toContain('</html>');
		for (const pattern of PHP_ERRORS) {
			expect(html, `PHP error output ${pattern}`).not.toMatch(pattern);
		}

		if (s.title_pattern) {
			await expect(page, 'page title').toHaveTitle(new RegExp(s.title_pattern));
		}
		await expect(page.locator('html'), '<html lang>').toHaveAttribute('lang', /.+/);

		for (const selector of s.required) {
			await expect(page.locator(selector).first(), `required: ${selector}`).toBeAttached();
		}

		const contentText = (await page.locator(s.content_selector).first().innerText()).trim();
		expect(contentText.length, `text in ${s.content_selector}`).toBeGreaterThanOrEqual(s.min_content_chars);

		await settle(page);

		expect.soft(consoleErrors, 'console errors').toEqual([]);
		expect.soft(failedRequests, 'failed first-party requests').toEqual([]);

		if (visual && s.visual) {
			const css = [
				'*, *::before, *::after { animation: none !important; transition: none !important; caret-color: transparent !important; }',
				...s.hide.map((sel) => `${sel} { visibility: hidden !important; }`),
			].join('\n');
			await page.addStyleTag({ content: css });
			await expect(page).toHaveScreenshot(`${slug(s.path)}.png`, {
				fullPage: true,
				mask: s.mask.map((sel) => page.locator(sel)),
				maxDiffPixelRatio: s.max_diff_pixel_ratio,
			});
		}
	});
}
