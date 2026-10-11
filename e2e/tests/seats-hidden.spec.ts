import { test, expect } from '@playwright/test';
import { pickBlockOf, pickHiddenMatch } from '../lib/api';

let matchId: number;
let reservedId: number;
let unreservedId: number;

test.beforeAll(async () => {
  matchId = await pickHiddenMatch();
  reservedId = await pickBlockOf(matchId, 'reserved');
  unreservedId = await pickBlockOf(matchId, 'unreserved');
});

test('未ログインでは指定席の席グリッドと自由席の残席が出ない', async ({ page }) => {
  await page.goto(`/matches/${matchId}?block=${reservedId}`);
  await expect(page.getByTestId('seats-hidden')).toBeVisible();
  await expect(page.locator('[data-testid^="seat-"]')).toHaveCount(0);

  await page.goto(`/matches/${matchId}?block=${unreservedId}`);
  await expect(page.getByTestId('seats-hidden')).toBeVisible();
  await expect(page.getByText(/残席 \d+/)).toHaveCount(0);
});
