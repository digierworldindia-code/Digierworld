/**
 * Response helpers.
 *
 * Two habits enforced here:
 *   - responses are *built*, never spread from a database row, so adding a
 *     column to a table cannot quietly start leaking it through an endpoint
 *   - list endpoints always paginate, so no route can be turned into a bulk
 *     export by omitting a parameter
 */
import type { FastifyReply } from 'fastify';
import { z } from 'zod';
import { errors } from './errors.js';

export interface Page<T> {
  items: T[];
  page: number;
  pageSize: number;
  total: number;
  totalPages: number;
}

export function paginate<T>(items: T[], total: number, page: number, pageSize: number): Page<T> {
  return {
    items,
    page,
    pageSize,
    total,
    totalPages: Math.max(1, Math.ceil(total / pageSize)),
  };
}

export function skipTake(page: number, pageSize: number): { skip: number; take: number } {
  return { skip: (page - 1) * pageSize, take: pageSize };
}

/**
 * Parses input against a schema and converts a failure into a field-level
 * validation error. Every route body, query and parameter set goes through
 * this — there is no path where a raw request object reaches a service.
 */
export function parse<S extends z.ZodTypeAny>(schema: S, input: unknown): z.infer<S> {
  const result = schema.safeParse(input);
  if (!result.success) {
    const details: Record<string, string[]> = {};
    for (const issue of result.error.issues) {
      const key = issue.path.length > 0 ? issue.path.join('.') : '_';
      (details[key] ??= []).push(issue.message);
    }
    throw errors.validation(details);
  }
  return result.data;
}

export function noStore(reply: FastifyReply): FastifyReply {
  return reply.header('Cache-Control', 'no-store, max-age=0');
}

/** Public, cacheable content served to the website's server-side renderer. */
export function publicCache(reply: FastifyReply, seconds: number): FastifyReply {
  return reply.header('Cache-Control', `public, max-age=0, s-maxage=${seconds}, stale-while-revalidate=${seconds * 4}`);
}

export const idParam = z.object({ id: z.string().uuid() });
