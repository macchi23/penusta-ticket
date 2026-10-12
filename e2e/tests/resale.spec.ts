import { test, expect } from '@playwright/test';
import { buySeat, clearCart, getMyTickets, pickAvailableSeat, pickResaleBuyer, pickResaleMatch, pickReservedBlock, pickUsers } from '../lib/api';
import { signInAs } from '../lib/session';

let matchId: number;
let seat: { id: number; row: string; number: number };
let seller: number;
let buyer: number;

test.beforeAll(async () => {
  matchId = await pickResaleMatch();
  const blockId = await pickReservedBlock(matchId);
  seat = await pickAvailableSeat(matchId, blockId);
  [seller] = await pickUsers(2);
  buyer = await pickResaleBuyer(matchId, seller);
});

test.afterEach(async () => {
  await clearCart(seller, matchId);
  await clearCart(buyer, matchId);
});

test('購入した券を出品し、別のユーザーがリセールで買うと出品者の券がリセール済みになる', async ({ page, browser, context }) => {
  await buySeat(seller, matchId, seat.id);
  const ticket = (await getMyTickets(seller)).find((t) => t.match.id === matchId && t.seat?.id === seat.id && t.status === 'paid');
  if (!ticket) throw new Error('購入した券がマイチケットにない');

  // 出品者: 詳細から出品
  await signInAs(context, seller);
  await page.goto(`/tickets/${ticket.id}`);
  await expect(page.getByTestId('qr-box')).toBeVisible();
  await page.getByLabel(/出品価格/).fill('1000');
  await expect(page.getByTestId('preview-payout')).toHaveText('¥570');
  await page.getByRole('button', { name: '出品する' }).click();
  await expect(page.getByRole('button', { name: '取り下げ' })).toBeVisible();
  await expect(page.getByText(/出品中 ・ 出品価格 ¥1,000 ・ 受取額 ¥570/)).toBeVisible();
  await expect(page.getByTestId('qr-box')).toHaveCount(0);
  await expect(page.getByText('出品中のため表示できません。')).toBeVisible();

  // 購入者: 試合ページのリセールから購入
  const buyerContext = await browser.newContext({ locale: 'ja-JP', timezoneId: 'Asia/Tokyo' });
  try {
    await signInAs(buyerContext, buyer);
    const buyerPage = await buyerContext.newPage();
    await buyerPage.goto(`/matches/${matchId}`);
    const block = buyerPage.getByTestId(`resale-block-${ticket.block.code}`);
    await block.getByRole('button', { name: new RegExp(`${ticket.block.code}`) }).first().click();
    const row = block.getByRole('listitem').filter({ hasText: `${seat.row}列 ${seat.number}番` });
    await expect(row).toContainText('¥1,000 + 手数料 ¥220 = ¥1,220');
    buyerPage.once('dialog', (d) => d.accept());
    await row.getByRole('button', { name: '購入' }).click();

    await expect(buyerPage).toHaveURL(/\/orders\/\d+$/);
    await expect(buyerPage.getByRole('heading', { name: 'リセール購入が完了しました' })).toBeVisible();
    await expect(buyerPage.getByTestId('buyer-fee')).toHaveText('購入者手数料 ¥220');
    await expect(buyerPage.getByText(/合計 ¥1,220/)).toBeVisible();
    await expect(buyerPage.getByRole('link', { name: 'チケット', exact: true })).toBeVisible();
  } finally {
    await buyerContext.close();
  }

  // 出品者: リセール済みになっている
  await page.goto('/tickets');
  await expect(page.getByTestId(`ticket-${ticket.id}`)).toContainText('リセール済み（受取 ¥570）');
  await page.goto(`/tickets/${ticket.id}`);
  await expect(page.getByText('この券は他の方に譲渡されました。')).toBeVisible();
});
