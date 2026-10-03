import { test, expect } from '@playwright/test';
import { cartItemCount, clearCart, getBlockSeatCategory, getTicketTypeNames, pickOnSaleMatch, pickUnreservedBlock, pickUsers } from '../lib/api';
import { signInAs } from '../lib/session';

let matchId: number;
let blockId: number;
let typeNames: string[];
let userA: number;

test.beforeAll(async () => {
  matchId = await pickOnSaleMatch();
  blockId = await pickUnreservedBlock(matchId);
  typeNames = await getTicketTypeNames(matchId, await getBlockSeatCategory(matchId, blockId));
  if (typeNames.length < 2) throw new Error('券種が 2 つ以上ない');
  [userA] = await pickUsers(2);
});

test.beforeEach(async () => {
  await clearCart(userA, matchId);
});

test.afterEach(async () => {
  await clearCart(userA, matchId);
});

test('自由席を券種ごとの枚数で一括反映できる', async ({ page, context }) => {
  await signInAs(context, userA);
  await page.goto(`/matches/${matchId}?block=${blockId}`);
  await expect(page.locator('select[name="user_id"]')).toHaveValue(String(userA));

  const [first, second] = typeNames;
  const cart = page.locator('section').filter({ has: page.getByRole('heading', { name: 'カート', exact: true }) });
  const items = cart.getByRole('listitem');
  const apply = page.getByRole('button', { name: 'カートに反映' });

  await page.getByLabel(first, { exact: true }).selectOption('2');
  await page.getByLabel(second, { exact: true }).selectOption('1');
  await apply.click();

  // 確定: 「確保中…」が消えて全明細に「外す」が出る
  await expect(items).toHaveCount(3);
  await expect(cart.getByRole('button', { name: '外す' })).toHaveCount(3);
  await expect(cart.getByText('確保中…')).toHaveCount(0);
  await expect(cart.getByText(/^3 \/ \d+ 枚$/)).toBeVisible();
  await expect(items.filter({ hasText: '自由席' })).toHaveCount(3);
  await expect(page.getByLabel(first, { exact: true })).toHaveValue('2');
  await expect(page.getByLabel(second, { exact: true })).toHaveValue('1');
  await expect(apply).toBeDisabled();

  await page.getByLabel(first, { exact: true }).selectOption('0');
  await page.getByLabel(second, { exact: true }).selectOption('0');
  await apply.click();

  await expect.poll(() => cartItemCount(userA, matchId)).toBe(0);
  await expect(items).toHaveCount(0);
  await expect(cart.getByText('席を選ぶとここに表示されます。')).toBeVisible();
  await expect(cart.getByText(/^0 \/ \d+ 枚$/)).toBeVisible();
});
