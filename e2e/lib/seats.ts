import { expect, type Page } from '@playwright/test';

type Seat = { id: number; row: string; number: number };

export const seatButton = (page: Page, seatId: number) => page.getByTestId(`seat-${seatId}`);

export const cartItem = (page: Page, seat: Seat) =>
  page.getByRole('listitem').filter({ hasText: `${seat.row}列 ${seat.number}番` });

// 楽観更新の仮明細ではなく、サーバー確定後の状態を待つ
export async function waitHoldConfirmed(page: Page, seat: Seat): Promise<void> {
  await expect(cartItem(page, seat).getByRole('button', { name: '外す' })).toBeVisible();
  const btn = seatButton(page, seat.id);
  await expect(btn).toHaveAttribute('data-status', 'mine');
  await expect(btn).toBeEnabled();
}

// 解除の確定を待つ。楽観更新中は held に戻って disabled、refresh() で確定すると available になる
export async function waitReleaseConfirmed(page: Page, seat: Seat): Promise<void> {
  await expect(cartItem(page, seat)).toHaveCount(0);
  const btn = seatButton(page, seat.id);
  await expect(btn).toHaveAttribute('data-status', 'available');
  await expect(btn).toBeEnabled();
}
