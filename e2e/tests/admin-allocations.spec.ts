import { test, expect, type Page } from '@playwright/test';
import {
  clearCart,
  getAdminAllocations,
  patchAllocations,
  pickAdmin,
  pickEditableUnsoldMatch,
  pickUsers,
  setUnreservedQuantity,
  type AdminBlock,
} from '../lib/api';
import { signInAs } from '../lib/session';

test.describe.serial('管理画面の割当エディタ', () => {
  let adminId: number;
  let customerId: number;
  let matchId: number;
  let original: Map<number, string>;
  let target: AdminBlock; // home の自由席
  let other: AdminBlock; // 競合用の別の home ブロック

  const block = (page: Page, code: string) => page.locator(`[data-block-code="${code}"]`);
  const brush = (page: Page, name: string) => page.getByRole('group', { name: 'ブラシ' }).getByRole('button', { name, exact: true });
  const editorUrl = () => `/admin/matches/${matchId}/allocations`;
  const allocationOf = async (id: number) =>
    (await getAdminAllocations(adminId, matchId)).blocks.find((b) => b.id === id)?.allocation;

  async function restore() {
    const errors: string[] = [];
    try {
      await clearCart(customerId, matchId);
    } catch (e) {
      errors.push(`clearCart: ${String(e)}`);
    }
    try {
      await restoreAllocations();
    } catch (e) {
      errors.push(`割当の復元: ${String(e)}`);
    }
    if (errors.length > 0) throw new Error(errors.join(' / '));
  }

  async function restoreAllocations() {
    const { blocks } = await getAdminAllocations(adminId, matchId);
    const changes = blocks
      .filter((b) => original.get(b.id) !== b.allocation)
      .map((b) => ({ block_id: b.id, expected: b.allocation, allocation: original.get(b.id) as string }));
    if (changes.length > 0) {
      const res = await patchAllocations(adminId, matchId, changes);
      if (res.status !== 200) throw new Error(`割当の復元に失敗: ${res.status} ${JSON.stringify(res.body)}`);
    }
  }

  test.beforeAll(async () => {
    adminId = await pickAdmin();
    [customerId] = await pickUsers(1);
    matchId = await pickEditableUnsoldMatch(adminId);
    const { blocks } = await getAdminAllocations(adminId, matchId);
    original = new Map(blocks.map((b) => [b.id, b.allocation]));
    const homes = blocks.filter((b) => b.allocation === 'home');
    const t = homes.find((b) => b.seating_type === 'unreserved');
    if (!t) throw new Error('home の自由席ブロックがない');
    target = t;
    const o = homes.find((b) => b.id !== t.id);
    if (!o) throw new Error('別の home ブロックがない');
    other = o;
  });

  test.afterAll(async () => {
    if (matchId === undefined) return;
    await restore();
  });

  test('非管理者は 403、未選択は 401 のページになる', async ({ browser }) => {
    const ctx = await browser.newContext();
    try {
      await signInAs(ctx, customerId);
      const page = await ctx.newPage();
      await page.goto('/admin');
      await expect(page.getByText('この画面を表示する権限がありません。')).toBeVisible();
    } finally {
      await ctx.close();
    }
    const anon = await browser.newContext();
    try {
      const page = await anon.newPage();
      await page.goto('/admin');
      await expect(page.getByText('ユーザーが選択されていません。')).toBeVisible();
    } finally {
      await anon.close();
    }
  });

  test('管理者が自由席ブロックを緩衝帯に塗って保存できる', async ({ page, context }) => {
    await signInAs(context, adminId);
    await page.goto('/admin');
    await page.locator(`a[href="${editorUrl()}"]`).click();
    await expect(page).toHaveURL(new RegExp(`${editorUrl()}$`));

    await brush(page, '緩衝帯（販売なし）').click();
    await expect(brush(page, '緩衝帯（販売なし）')).toHaveAttribute('aria-pressed', 'true');
    await block(page, target.code).click();
    await expect(block(page, target.code)).toHaveAttribute('data-allocation', 'buffer');
    await expect(page.getByText(`${target.code}: ホーム → 緩衝帯（販売なし）`)).toBeVisible();

    await page.getByRole('button', { name: '保存', exact: true }).click();
    await expect(page.getByRole('status')).toContainText('1 件の割当を保存しました');
    await expect.poll(() => allocationOf(target.id)).toBe('buffer');
  });

  test('緩衝帯のブロックは一般利用者に購入できない', async ({ page, context }) => {
    await signInAs(context, customerId);
    await page.goto(`/matches/${matchId}`);
    const seat = page.getByTestId(`block-${target.id}`);
    await expect(seat).toHaveAttribute('data-allocation', 'buffer');
    await seat.click();
    await expect(page.getByText('ブロックをクリックすると席を選べます。')).toBeVisible();
    await expect(page.getByRole('button', { name: 'カートに反映' })).toHaveCount(0);

    const res = await setUnreservedQuantity(customerId, matchId, target, 1);
    expect(res.status).toBe(422);
    expect(JSON.stringify(res.body)).toContain('not_on_sale');
  });

  test('ホームに戻すと仮押さえでき、チケットのあるブロックは塗れない', async ({ page, context }) => {
    await signInAs(context, adminId);
    await page.goto(editorUrl());
    await brush(page, 'ホーム').click();
    await block(page, target.code).click();
    await expect(block(page, target.code)).toHaveAttribute('data-allocation', 'home');
    await page.getByRole('button', { name: '保存', exact: true }).click();
    await expect(page.getByRole('status')).toContainText('1 件の割当を保存しました');
    await expect.poll(() => allocationOf(target.id)).toBe('home');

    const res = await setUnreservedQuantity(customerId, matchId, target, 1);
    expect(res.status).toBe(200);

    await page.reload();
    await expect(block(page, target.code)).toHaveAttribute('data-locked', 'true');
    await brush(page, 'アウェー').click();
    await block(page, target.code).click({ force: true });
    await expect(page.getByRole('status')).toContainText('チケットがあるため変更できません');
    await expect(block(page, target.code)).toHaveAttribute('data-allocation', 'home');

    await clearCart(customerId, matchId);
  });

  test('他の管理者が先に変更していると競合として表示される', async ({ page, context }) => {
    await signInAs(context, adminId);
    await page.goto(editorUrl());
    await brush(page, 'ホーム').click();
    await brush(page, 'アウェー').click();
    await block(page, other.code).click();
    await expect(block(page, other.code)).toHaveAttribute('data-allocation', 'away');

    const res = await patchAllocations(adminId, matchId, [{ block_id: other.id, expected: 'home', allocation: 'buffer' }]);
    expect(res.status).toBe(200);

    await page.getByRole('button', { name: '保存', exact: true }).click();
    await expect(page.getByText('競合したブロックがあり保存できませんでした', { exact: false })).toBeVisible();
    await expect(page.getByText(`${other.code}: 他の管理者が変更済み（現在: 緩衝帯（販売なし））`)).toBeVisible();
    await expect(block(page, other.code)).toHaveAttribute('data-allocation', 'buffer');
    await expect(page.getByRole('heading', { name: '変更（0 件）' })).toBeVisible();
  });
});
