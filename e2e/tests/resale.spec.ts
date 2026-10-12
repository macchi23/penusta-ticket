import { test, expect, type Browser, type BrowserContext, type Page } from '@playwright/test';
import {
  buySeat,
  cancelListingApi,
  clearCart,
  getMyTickets,
  listTicketApi,
  pickAvailableSeat,
  pickResaleBuyer,
  pickResaleMatch,
  pickReservedBlock,
  pickUsers,
  releaseResaleHold,
  type MyTicketLite,
} from '../lib/api';
import { signInAs } from '../lib/session';

let matchId: number;
let blockId: number;
let seller: number;
let buyer: number;
let third: number;
const listedTickets: number[] = [];

test.beforeAll(async () => {
  matchId = await pickResaleMatch();
  blockId = await pickReservedBlock(matchId);
  [third, seller] = await pickUsers(3);
  buyer = await pickResaleBuyer(matchId, seller, third);
});

test.afterEach(async () => {
  // 仮押さえと出品を残さない（取り消しのクールダウンは出品単位なので、次回は新しい出品を作る）
  // 1 つ失敗しても残りの後始末に進む
  const attempt = async (f: () => Promise<void>) => {
    try {
      await f();
    } catch (e) {
      console.warn('afterEach cleanup failed:', e);
    }
  };
  await attempt(() => releaseResaleHold(buyer, matchId));
  for (const id of listedTickets.splice(0)) await attempt(() => cancelListingApi(seller, id));
  await attempt(() => clearCart(seller, matchId));
  await attempt(() => clearCart(buyer, matchId));
});

// 席を 1 枚買い、そのチケットを返す
async function buyTicket(): Promise<{ ticket: MyTicketLite; seat: { id: number; row: string; number: number } }> {
  const seat = await pickAvailableSeat(matchId, blockId);
  await buySeat(seller, matchId, seat.id);
  const ticket = (await getMyTickets(seller)).find((t) => t.match.id === matchId && t.seat?.id === seat.id && t.status === 'paid');
  if (!ticket) throw new Error('購入した券がマイチケットにない');
  return { ticket, seat };
}

async function userPage(browser: Browser, userId: number): Promise<{ context: BrowserContext; page: Page }> {
  const context = await browser.newContext({ locale: 'ja-JP', timezoneId: 'Asia/Tokyo' });
  await signInAs(context, userId);
  return { context, page: await context.newPage() };
}

// 試合ページのリセール欄で、そのブロックを開いて出品の行を返す
async function openRow(page: Page, blockCode: string, seat: { row: string; number: number }) {
  await page.goto(`/matches/${matchId}`);
  const block = page.getByTestId(`resale-block-${blockCode}`);
  await block.getByRole('button', { name: new RegExp(blockCode) }).first().click();
  return block.getByRole('listitem').filter({ hasText: `${seat.row}列 ${seat.number}番` });
}

test('購入した券を出品し、別のユーザーがリセールで買うと出品者の券がリセール済みになる', async ({ page, browser, context }) => {
  const { ticket, seat } = await buyTicket();

  // 出品者: 詳細から出品
  await signInAs(context, seller);
  await page.goto(`/tickets/${ticket.id}`);
  await expect(page.getByTestId('qr-box')).toBeVisible();
  await page.getByLabel(/出品価格/).fill('1000');
  await expect(page.getByTestId('preview-payout')).toHaveText('¥570');
  await page.getByRole('button', { name: '出品する' }).click();
  listedTickets.push(ticket.id);
  await expect(page.getByRole('button', { name: '取り下げ' })).toBeVisible();
  await expect(page.getByText(/出品中 ・ 出品価格 ¥1,000 ・ 受取額 ¥570/)).toBeVisible();
  await expect(page.getByTestId('qr-box')).toHaveCount(0);
  await expect(page.getByText('出品中のため表示できません。')).toBeVisible();

  // 購入者: カートに入れて決済画面で確定
  const b = await userPage(browser, buyer);
  try {
    const row = await openRow(b.page, ticket.block.code, seat);
    await expect(row).toContainText('¥1,000 + 手数料 ¥220 = ¥1,220');
    await row.getByRole('button', { name: 'カートに入れる' }).click();

    await expect(b.page).toHaveURL(`/matches/${matchId}/resale-checkout`);
    await expect(b.page.getByText(/残り \d+:\d{2}/)).toBeVisible();
    await expect(b.page.getByText(`${seat.row}列 ${seat.number}番`)).toBeVisible();
    await expect(b.page.getByText('¥1,000', { exact: true })).toBeVisible();
    await expect(b.page.getByTestId('buyer-fee')).toHaveText('購入者手数料 ¥220');
    await expect(b.page.getByText(/合計 ¥1,220/)).toBeVisible();
    await b.page.getByRole('button', { name: '購入を確定する（モック）' }).click();

    await expect(b.page).toHaveURL(/\/orders\/\d+$/);
    await expect(b.page.getByRole('heading', { name: 'リセール購入が完了しました' })).toBeVisible();
    await expect(b.page.getByTestId('buyer-fee')).toHaveText('購入者手数料 ¥220');
    await expect(b.page.getByText(/合計 ¥1,220/)).toBeVisible();
    await expect(b.page.getByRole('link', { name: 'チケット', exact: true })).toBeVisible();
  } finally {
    await b.context.close();
  }

  // 出品者: リセール済みになっている
  await page.goto('/tickets');
  await expect(page.getByTestId(`ticket-${ticket.id}`)).toContainText('リセール済み（受取 ¥570）');
  await page.goto(`/tickets/${ticket.id}`);
  await expect(page.getByText('この券は他の方に譲渡されました。')).toBeVisible();
});

test('押さえ中の出品は他の人の一覧から消え、出品者は取り下げられず、取り消すと一覧に戻る', async ({ browser, page, context }) => {
  const { ticket, seat } = await buyTicket();
  await listTicketApi(seller, ticket.id, 1000);
  listedTickets.push(ticket.id);
  // 同じブロックに別の出品を用意して、ブロックが必ず一覧に残るようにする
  const other = await buyTicket();
  await listTicketApi(seller, other.ticket.id, 1000);
  listedTickets.push(other.ticket.id);

  const t = await userPage(browser, third);
  const b = await userPage(browser, buyer);
  try {
    // 押さえる前は 3 人目にも見える
    await expect(await openRow(t.page, ticket.block.code, seat)).toBeVisible();

    // 買い手: カートに入れる → 試合ページに手続き中の表示が出る
    const row = await openRow(b.page, ticket.block.code, seat);
    await row.getByRole('button', { name: 'カートに入れる' }).click();
    await expect(b.page).toHaveURL(`/matches/${matchId}/resale-checkout`);
    await b.page.goto(`/matches/${matchId}`);
    const summary = b.page.getByTestId('resale-hold');
    await expect(summary).toContainText('購入手続き中の出品があります');
    await expect(summary).toContainText(`${seat.row}列 ${seat.number}番`);
    await expect(summary.getByText(/残り \d+:\d{2}/)).toBeVisible();
    await summary.getByRole('link', { name: '決済へ進む' }).click();
    await expect(b.page).toHaveURL(`/matches/${matchId}/resale-checkout`);

    // 3 人目: ブロックを開いても、押さえ中の出品だけが消えている
    const heldRow = await openRow(t.page, ticket.block.code, seat);
    await expect(heldRow).toHaveCount(0);
    await expect(
      t.page.getByTestId(`resale-block-${ticket.block.code}`).getByRole('listitem').filter({ hasText: `${other.seat.row}列 ${other.seat.number}番` }),
    ).toBeVisible();

    // 出品者: 手続き中で取り下げ不可
    await signInAs(context, seller);
    await page.goto('/tickets');
    await expect(page.getByTestId(`ticket-${ticket.id}`)).toContainText('購入手続き中');
    await page.goto(`/tickets/${ticket.id}`);
    await expect(page.getByText('購入手続き中のため取り下げできません。')).toBeVisible();
    await expect(page.getByRole('button', { name: '取り下げ' })).toHaveCount(0);

    // 買い手: 取り消す → 試合ページに戻り、手続き中の表示が消える
    await b.page.getByRole('button', { name: '取り消す' }).click();
    await expect(b.page).toHaveURL(`/matches/${matchId}`);
    await expect(b.page.getByTestId('resale-section')).toBeVisible();
    await expect(b.page.getByTestId('resale-hold')).toHaveCount(0);

    // 3 人目: 一覧に戻っている
    await expect(await openRow(t.page, ticket.block.code, seat)).toBeVisible();
  } finally {
    await t.context.close();
    await b.context.close();
  }
});
