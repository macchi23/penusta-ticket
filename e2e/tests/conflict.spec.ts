import { test, expect } from '@playwright/test';
import { clearCart, pickAvailableSeat, pickOnSaleMatch, pickReservedBlock, pickUsers } from '../lib/api';
import { signInAs } from '../lib/session';
import { cartItem, seatButton, waitHoldConfirmed, waitReleaseConfirmed } from '../lib/seats';

let matchId: number;
let blockId: number;
let seat: { id: number; row: string; number: number };
let userA: number;
let userB: number;

test.beforeAll(async () => {
  matchId = await pickOnSaleMatch();
  blockId = await pickReservedBlock(matchId);
  seat = await pickAvailableSeat(matchId, blockId);
  [userA, userB] = await pickUsers(2);
});

test.beforeEach(async () => {
  await clearCart(userA, matchId);
  await clearCart(userB, matchId);
});

test.afterEach(async () => {
  await clearCart(userA, matchId);
  await clearCart(userB, matchId);
});

test('同じ席を取り合うと後から押した側が弾かれ、解放後に再び取れる', async ({ browser }) => {
  const ctxA = await browser.newContext();
  const ctxB = await browser.newContext();
  try {
    await signInAs(ctxA, userA);
    await signInAs(ctxB, userB);
    // AutoRefresh を止めて、B の画面を古いままにする
    await ctxB.addInitScript(() =>
      Object.defineProperty(document, 'visibilityState', { get: () => 'hidden' }),
    );
    const pageA = await ctxA.newPage();
    const pageB = await ctxB.newPage();
    const url = `/matches/${matchId}?block=${blockId}`;
    await pageA.goto(url);
    await pageB.goto(url);
    await expect(seatButton(pageB, seat.id)).toHaveAttribute('data-status', 'available');

    await seatButton(pageA, seat.id).click();
    await waitHoldConfirmed(pageA, seat);

    // B の画面ではまだ available
    await expect(seatButton(pageB, seat.id)).toHaveAttribute('data-status', 'available');
    await seatButton(pageB, seat.id).click();
    await expect(pageB.getByRole('alert').filter({ hasText: '他の方が先に確保しました' })).toBeVisible();
    await expect(seatButton(pageB, seat.id)).toHaveAttribute('data-status', 'held');
    await expect(seatButton(pageB, seat.id)).toBeDisabled();

    await cartItem(pageA, seat).getByRole('button', { name: '外す' }).click();
    await waitReleaseConfirmed(pageA, seat);

    await pageB.reload();
    await expect(seatButton(pageB, seat.id)).toHaveAttribute('data-status', 'available');
    await expect(seatButton(pageB, seat.id)).toBeEnabled();
  } finally {
    await ctxA.close();
    await ctxB.close();
  }
});
