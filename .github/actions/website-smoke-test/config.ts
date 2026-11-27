import { readFileSync } from 'node:fs';
import { resolve } from 'node:path';
import { parse } from 'yaml';

export type PathConfig = {
	path: string;
	content_selector?: string;
	min_content_chars?: number;
	min_body_bytes?: number;
	title_pattern?: string;
	required?: string[];
	mask?: string[];
	hide?: string[];
	max_diff_pixel_ratio?: number;
	visual?: boolean;
};

// Console messages or failed URLs matching these regexes are ignored.
type Ignores = {
	ignore_console?: string[];
	ignore_requests?: string[];
};

// An environment is a URL, or a URL plus ignores that apply only there.
type EnvironmentConfig = string | ({ url: string } & Ignores);

export type SmokeConfig = Ignores & {
	environments: Record<string, EnvironmentConfig>;
	user_agent?: string;
	defaults?: Omit<PathConfig, 'path'>;
	paths: PathConfig[];
};

if (!process.env.SMOKE_CONFIG) {
	throw new Error('SMOKE_CONFIG is unset: point it at the site\'s website-smoke-test.yml.');
}

export const configPath = resolve(process.env.SMOKE_CONFIG);

export const config: SmokeConfig = parse(readFileSync(configPath, 'utf8'));

export const environment = process.env.SMOKE_ENVIRONMENT || 'live';

const env = config.environments?.[environment];
const envConfig: { url?: string } & Ignores = typeof env === 'string' ? { url: env } : env ?? {};

// SMOKE_BASE_URL overrides the configured environment, e.g. for Lando.
export const baseURL = process.env.SMOKE_BASE_URL || envConfig.url;

if (!baseURL) {
	throw new Error(`No base URL: environment "${environment}" isn't in ${configPath} and SMOKE_BASE_URL is unset.`);
}

export const ignoreConsole = [...(config.ignore_console ?? []), ...(envConfig.ignore_console ?? [])];
export const ignoreRequests = [...(config.ignore_requests ?? []), ...(envConfig.ignore_requests ?? [])];
