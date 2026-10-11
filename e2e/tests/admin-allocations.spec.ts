import { test, expect, type Page } from '@playwright/test';
import {
  clearCart,
  getAdminAllocations,
  patchAllocations,
  pickAdmin,
  pickCustomerFor,
  pickEditableUnsoldMatch,
  pickUsers,
  setUnreservedQuantity,
  type AdminBlock,
} from '../lib/api';
import { signInAs } from '../lib/session';

const ALLOCATION_LABELS: Record<string, string> = {
  home: 'ホーム',
  away: 'アウェー',
  buffer: '緩衝帯（販売なし）',
  closed: '閉鎖',
};

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
    matchId = await pickEditableUnsoldMatch(adminId);
    customerId = await pickCustomerFor(matchId);
    const { blocks } = await getAdminAllocations(adminId, matchId);
    original = new Map(blocks.map((b) => [b.id, b.allocation]));
    // 年パスの固定席があるブロックはロックされるので、未ロックの自由席ブロックから選ぶ。
    // target は緩衝帯に塗る対象なので、元が緩衝帯のものは除く
    const unlockedUnreserved = blocks.filter((b) => b.seating_type === 'unreserved' && !b.locked);
    const t = unlockedUnreserved.find((b) => b.allocation !== 'buffer');
    if (!t) throw new Error('緩衝帯でない未ロックの自由席ブロックがない');
    target = t;
    const o = unlockedUnreserved.find((b) => b.id !== t.id);
    if (!o) throw new Error('別の未ロック自由席ブロックがない');
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
    const originalLabel = ALLOCATION_LABELS[target.allocation];
    await expect(page.getByText(`${target.code}: ${originalLabel} → 緩衝帯（販売なし）`)).toBeVisible();

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
    // other は未ロックの自由席なので home 以外。UI でホームに塗る
    await brush(page, 'ホーム').click();
    await block(page, other.code).click();
    await expect(block(page, other.code)).toHaveAttribute('data-allocation', 'home');

    // その間に別の管理者が other を変更する（元の割当と違う値にして、確実に競合させる）
    const conflicting = other.allocation === 'buffer' ? 'closed' : 'buffer';
    const res = await patchAllocations(adminId, matchId, [{ block_id: other.id, expected: other.allocation, allocation: conflicting }]);
    expect(res.status).toBe(200);

    await page.getByRole('button', { name: '保存', exact: true }).click();
    await expect(page.getByText('競合したブロックがあり保存できませんでした', { exact: false })).toBeVisible();
    await expect(page.getByText(`${other.code}: 他の管理者が変更済み（現在: ${ALLOCATION_LABELS[conflicting]}）`)).toBeVisible();
    await expect(block(page, other.code)).toHaveAttribute('data-allocation', conflicting);
    await expect(page.getByRole('heading', { name: '変更（0 件）' })).toBeVisible();
  });
});

// 割当に依存しない（落ちても割当エディタのテストを巻き込まない）
test.describe('管理画面の入口と 404', () => {
  let adminId: number;
  let customerId: number;

  test.beforeAll(async () => {
    adminId = await pickAdmin();
    [customerId] = await pickUsers(1);
  });

  // 画面上部のユーザー切替バーの実 UI で選ぶ（cookie のみ。DB は書き換えない）
  const switchUser = async (page: Page, id: number | null) => {
    const select = page.getByRole('combobox');
    const value = id === null ? '' : String(id);
    // option の表示は「名前 (#id)」。バーには名前が出る
    const label = (await select.locator(`option[value="${value}"]`).textContent()) ?? '';
    const expected = id === null ? 'ユーザー未選択' : label.replace(/\s*\(#\d+\)$/, '');
    await select.selectOption(value);
    await page.getByRole('button', { name: '切替', exact: true }).click();
    // Server Action の cookie 設定とバー再描画の完了を待つ（待たずに goto すると未反映の cookie で遷移する。A→B でも効く）
    await expect(page.getByText('DEV').locator('..')).toContainText(expected);
  };
  const notFoundText = (page: Page) => page.getByText('ページが見つかりません。');

  test('UI で一般ユーザーを選ぶと /admin は 403 で forbidden が出る（404 ではない）', async ({ page }) => {
    await page.goto('/');
    await expect(page.getByText('ユーザー未選択')).toBeVisible();
    await switchUser(page, customerId);

    const res = await page.goto('/admin');
    expect(res?.status()).toBe(403);
    await expect(page.getByText('この画面を表示する権限がありません。')).toBeVisible();
    await expect(notFoundText(page)).toHaveCount(0);
  });

  test('未選択のまま /admin は 401 で unauthorized が出る', async ({ page }) => {
    const res = await page.goto('/admin');
    expect(res?.status()).toBe(401);
    await expect(page.getByText('ユーザーが選択されていません。')).toBeVisible();
    await expect(notFoundText(page)).toHaveCount(0);
  });

  test('管理画面を開いたままバーで一般ユーザーに切り替えると forbidden になる', async ({ page }) => {
    await page.goto('/');
    await switchUser(page, adminId);
    await page.goto('/admin');
    await expect(page.getByRole('heading', { name: '管理画面: 席種割当' })).toBeVisible();

    await switchUser(page, customerId);
    await expect(page.getByText('この画面を表示する権限がありません。')).toBeVisible();
    await expect(page.getByRole('heading', { name: '管理画面: 席種割当' })).toHaveCount(0);
  });

  test('存在しない URL（/matches/admin）は 404 で日本語の not-found が出る', async ({ page }) => {
    const res = await page.goto('/matches/admin');
    expect(res?.status()).toBe(404);
    await expect(notFoundText(page)).toBeVisible();
    await expect(page.getByRole('link', { name: '試合一覧へ' })).toHaveAttribute('href', '/');
  });
});
