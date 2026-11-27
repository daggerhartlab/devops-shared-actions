import { defineConfig } from '@playwright/test';
import { baseURL, config, environment } from './config';

const user = process.env.BASIC_AUTH_USER;
const pass = process.env.BASIC_AUTH_PASSWORD;

export default defineConfig({
	testDir: '.',
	testMatch: 'website-smoke-test.spec.ts',
	// Per-environment snapshots, outside the spec folder, so CI can move them
	// between jobs as an artifact.
	snapshotPathTemplate: `${process.env.SMOKE_SNAPSHOT_DIR || 'snapshots'}/${environment}/{arg}{ext}`,
	retries: Number(process.env.SMOKE_RETRIES ?? 2),
	workers: 2,
	timeout: 60_000,
	reporter: [['list'], ['html', { open: 'never', outputFolder: 'report' }]],
	use: {
		baseURL,
		channel: process.env.SMOKE_BROWSER_CHANNEL || 'chrome',
		// Only sent when the server answers 401 (Pantheon's environment lock).
		httpCredentials: user && pass ? { username: user, password: pass } : undefined,
		ignoreHTTPSErrors: !!process.env.SMOKE_IGNORE_HTTPS_ERRORS,
		userAgent: process.env.SMOKE_USER_AGENT || config.user_agent || undefined,
		viewport: { width: 1280, height: 800 },
		trace: 'retain-on-failure',
	},
});
