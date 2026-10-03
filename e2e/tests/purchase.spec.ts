import { test, expect } from '@playwright/test';
import { clearCart, getOrder, pickAvailableSeat, pickOnSaleMatch, pickReservedBlock, pickUsers, seatStatus } from '../lib/api';
import { signInAs } from '../lib/session';
import { cartItem, seatButton, waitHoldConfirmed } from '../lib/seats';

let matchId: number;
let blockId: number;
let seat: { id: number; row: string; number: number };
let userA: number;

test.beforeAll(async () => {
  matchId = await pickOnSaleMatch();
  blockId = await pickReservedBlock(matchId);
  seat = await pickAvailableSeat(matchId, blockId);
  [userA] = await pickUsers(2);
});

test.beforeEach(async () => {
  await clearCart(userA, matchId);
});

test.afterEach(async () => {
  await clearCart(userA, matchId);
});

test('空席を選んで購入を完了できる', async ({ page, context }) => {
  await signInAs(context, userA);
  await page.goto(`/matches/${matchId}?block=${blockId}`);
  await expect(page.locator('select[name="user_id"]')).toHaveValue(String(userA));

  await seatButton(page, seat.id).click();
  await waitHoldConfirmed(page, seat);

  const item = cartItem(page, seat);
  await expect(item).toContainText(`${seat.row}列 ${seat.number}番`);
  await expect(item).toContainText(/¥[\d,]+/);
  await expect(page.getByText(/^1 \/ \d+ 枚$/)).toBeVisible();
  await expect(page.getByText(/残り \d+:\d{2}/)).toBeVisible();

  await page.getByRole('link', { name: '購入手続きへ' }).click();
  await expect(page).toHaveURL(`/matches/${matchId}/checkout`);
  await expect(cartItem(page, seat)).toBeVisible();
  await expect(page.getByText(/合計 ¥[\d,]+/)).toBeVisible();

  await page.getByRole('button', { name: '決済する（モック）' }).click();
  await expect(page).toHaveURL(/\/orders\/\d+$/);
  await expect(page.getByRole('heading', { name: '購入が完了しました' })).toBeVisible();

  const orderId = Number(new URL(page.url()).pathname.split('/').pop());
  expect((await getOrder(userA, orderId)).status).toBe('paid');
  await expect.poll(() => seatStatus(matchId, blockId, seat.id)).toBe('sold');
});
