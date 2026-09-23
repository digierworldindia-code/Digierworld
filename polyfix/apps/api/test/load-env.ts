/**
 * Loads the development environment for the test run.
 *
 * Tests execute against a real PostgreSQL database with the real migrations
 * applied. Nothing here is mocked: dealer isolation, Row Level Security, the
 * audit chain and the upload checks are only meaningful if they are exercised
 * against the database that enforces them.
 */
import { readFileSync } from 'node:fs';
import path from 'node:path';

const envPath = path.resolve(import.meta.dirname, '../../../.env');

try {
  for (const line of readFileSync(envPath, 'utf8').split('\n')) {
    const trimmed = line.trim();
    if (!trimmed || trimmed.startsWith('#')) continue;
    const index = trimmed.indexOf('=');
    if (index === -1) continue;
    const key = trimmed.slice(0, index).trim();
    const value = trimmed.slice(index + 1).trim().replace(/^["']|["']$/g, '');
    if (!(key in process.env)) process.env[key] = value;
  }
} catch {
  throw new Error(`Tests need a .env at ${envPath}. Copy .env.example and point it at a test database.`);
}

process.env.APP_ENV = 'test';
process.env.NODE_ENV = 'test';
process.env.LOG_LEVEL = 'error';
// Rate limits would otherwise make a fast test suite trip its own defences.
process.env.RATE_LIMIT_GLOBAL_PER_MINUTE = '100000';
// High enough that ordinary test sign-ins do not trip it, low enough that the
// rate-limit test can still reach it deliberately.
process.env.RATE_LIMIT_LOGIN_PER_MINUTE = '60';
