import { randomBytes } from 'node:crypto';
import type { BrowserContext } from '@playwright/test';

const BASE_URL = process.env.E2E_BASE_URL ?? 'http://localhost:3100';

// penusta_sid は e2e0 始まりにして、events から E2E 分を除外できるようにする
export async function signInAs(context: BrowserContext, userId: number): Promise<void> {
  const sid = `e2e00000-0000-4000-8000-${randomBytes(6).toString('hex')}`;
  await context.addCookies([
    { name: 'penusta_demo_user', value: String(userId), url: BASE_URL, httpOnly: true, sameSite: 'Lax' },
    { name: 'penusta_sid', value: sid, url: BASE_URL, httpOnly: true, sameSite: 'Lax' },
  ]);
}
