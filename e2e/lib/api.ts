import { request, type APIRequestContext } from '@playwright/test';

export const API_URL = 'http://localhost:8080';

type Block = {
  id: number;
  code: string;
  seating_type: 'reserved' | 'unreserved';
  allocation: string;
  available: number | null;
};
type SeatStatus = 'available' | 'held' | 'sold';
type BlockSeats = { rows: { row: string; seats: { id: number; number: number; status: SeatStatus }[] }[] };
type CartItem = { id: number };

async function newApi(userId?: number): Promise<APIRequestContext> {
  return request.newContext({
    baseURL: API_URL,
    extraHTTPHeaders: {
      Accept: 'application/json',
      'X-Session-Id': 'e2e00000-0000-4000-8000-000000000000',
      ...(userId !== undefined ? { 'X-Demo-User-Id': String(userId) } : {}),
    },
  });
}

async function getJson<T>(userId: number | undefined, path: string): Promise<T> {
  const api = await newApi(userId);
  try {
    const res = await api.get(path);
    if (!res.ok()) throw new Error(`GET ${path} -> ${res.status()}`);
    return (await res.json()) as T;
  } finally {
    await api.dispose();
  }
}

// 一般販売フェーズの最中にある on_sale の試合（FC 先行中だと会員でないデモユーザーが買えない）
// 実行中にフェーズが切り替わらないよう、現フェーズの終了まで 1 時間以上ある試合に絞る
const HOUR = 3600_000;

export async function pickOnSaleMatch(): Promise<number> {
  const { data } = await getJson<{
    data: { id: number; status: string; sale_phases: { kind: string; starts_at: string; ends_at: string }[] }[];
  }>(undefined, '/api/matches');
  const now = Date.now();
  const m = data.find(
    (x) =>
      x.status === 'on_sale' &&
      x.sale_phases.some((p) => p.kind === 'general' && Date.parse(p.starts_at) <= now && now + HOUR < Date.parse(p.ends_at)),
  );
  if (!m) throw new Error('一般販売中の on_sale の試合がない');
  return m.id;
}

// 席・残数は一般販売中の試合ならログイン済みの誰にでも見える（未ログインには見えない）。閲覧用に customer を 1 人使う
async function viewerId(): Promise<number> {
  const { data } = await getJson<{ data: { id: number; role: string }[] }>(undefined, '/api/demo/users?limit=200');
  const u = data.filter((x) => x.role === 'customer').slice(-1)[0];
  if (!u) throw new Error('customer のデモユーザーがいない');
  return u.id;
}

export async function pickReservedBlock(matchId: number): Promise<number> {
  const { blocks } = await getJson<{ blocks: Block[] }>(await viewerId(), `/api/matches/${matchId}/seatmap`);
  const b = blocks
    .filter((x) => x.seating_type === 'reserved' && (x.allocation === 'home' || x.allocation === 'away') && x.available !== null && x.available > 0)
    .sort((a, b) => (b.available ?? 0) - (a.available ?? 0))[0];
  if (!b) throw new Error('空きのある指定席ブロックがない');
  return b.id;
}

export async function pickUnreservedBlock(matchId: number): Promise<number> {
  const { blocks } = await getJson<{ blocks: Block[] }>(await viewerId(), `/api/matches/${matchId}/seatmap`);
  const b = blocks
    .filter((x) => x.seating_type === 'unreserved' && (x.allocation === 'home' || x.allocation === 'away') && x.available !== null && x.available >= 3)
    .sort((a, b) => (b.available ?? 0) - (a.available ?? 0))[0];
  if (!b) throw new Error('残席 3 以上の自由席ブロックがない');
  return b.id;
}

export async function getBlockSeatCategory(matchId: number, blockId: number): Promise<string> {
  const { blocks } = await getJson<{ blocks: (Block & { seat_category: string })[] }>(await viewerId(), `/api/matches/${matchId}/seatmap`);
  const b = blocks.find((x) => x.id === blockId);
  if (!b) throw new Error(`ブロック ${blockId} が見つからない`);
  return b.seat_category;
}

// そのブロックの席種で売っている券種名（season_pass を除く、API の順）
export async function getTicketTypeNames(matchId: number, seatCategory: string): Promise<string[]> {
  const res = await getJson<{ data: { seat_category: string; ticket_type: { code: string; name: string } }[] }>(
    undefined,
    `/api/matches/${matchId}/prices`,
  );
  return res.data
    .filter((p) => p.seat_category === seatCategory && p.ticket_type.code !== 'season_pass')
    .map((p) => p.ticket_type.name);
}

export async function pickAvailableSeat(
  matchId: number,
  blockId: number,
): Promise<{ id: number; row: string; number: number }> {
  const { rows } = await getJson<BlockSeats>(await viewerId(), `/api/matches/${matchId}/blocks/${blockId}/seats`);
  for (const r of rows) {
    const s = r.seats.find((x) => x.status === 'available');
    if (s) return { id: s.id, row: r.row, number: s.number };
  }
  throw new Error('空席がない');
}

export async function pickUsers(n: number): Promise<number[]> {
  const { data } = await getJson<{ data: { id: number }[] }>(undefined, '/api/demo/users?limit=200');
  return data.slice(-n).map((u) => u.id);
}

// 今その試合を買える一般ユーザー。販売中のフェーズが会員限定なら、その会員資格を持つユーザーを選ぶ
export async function pickCustomerFor(matchId: number): Promise<number> {
  const { data: matches } = await getJson<{
    data: { id: number; sale_phases: { eligibility: string | null; starts_at: string; ends_at: string }[] }[];
  }>(undefined, '/api/matches');
  const now = Date.now();
  const phase = matches
    .find((m) => m.id === matchId)
    ?.sale_phases.find((p) => Date.parse(p.starts_at) <= now && now + HOUR < Date.parse(p.ends_at));
  if (!phase) throw new Error(`試合 ${matchId} は販売中ではない`);
  const { data } = await getJson<{ data: { id: number; role: string; memberships: string[] }[] }>(
    undefined,
    '/api/demo/users?limit=200',
  );
  const u = data
    .filter((x) => x.role === 'customer' && (phase.eligibility === null || x.memberships.includes(phase.eligibility)))
    .slice(-1)[0];
  if (!u) throw new Error('購入できるデモユーザーがいない');
  return u.id;
}

export async function clearCart(userId: number, matchId: number): Promise<void> {
  const { cart } = await getJson<{ cart: { items: CartItem[] } | null }>(userId, `/api/matches/${matchId}/cart`);
  if (!cart) return;
  const api = await newApi(userId);
  try {
    for (const it of cart.items) {
      const res = await api.delete(`/api/matches/${matchId}/cart/items/${it.id}`);
      if (!res.ok()) throw new Error(`DELETE cart item ${it.id} -> ${res.status()}`);
    }
  } finally {
    await api.dispose();
  }
}

export async function seatStatus(matchId: number, blockId: number, seatId: number): Promise<SeatStatus> {
  const { rows } = await getJson<BlockSeats>(await viewerId(), `/api/matches/${matchId}/blocks/${blockId}/seats`);
  for (const r of rows) {
    const s = r.seats.find((x) => x.id === seatId);
    if (s) return s.status;
  }
  throw new Error(`席 ${seatId} が見つからない`);
}

export async function getOrder(userId: number, orderId: number): Promise<{ id: number; status: string }> {
  const { order } = await getJson<{ order: { id: number; status: string } }>(userId, `/api/orders/${orderId}`);
  return order;
}

export async function cartItemCount(userId: number, matchId: number): Promise<number> {
  const { cart } = await getJson<{ cart: { items: CartItem[] } | null }>(userId, `/api/matches/${matchId}/cart`);
  return cart?.items.length ?? 0;
}

export type AdminBlock = {
  id: number;
  code: string;
  seating_type: 'reserved' | 'unreserved';
  seat_category: string;
  allocation: string;
  locked: boolean;
};
export type AllocationChange = { block_id: number; expected: string; allocation: string };

export async function pickAdmin(): Promise<number> {
  const { data } = await getJson<{ data: { id: number; role: string }[] }>(undefined, '/api/demo/users?limit=200');
  const a = data.find((u) => u.role === 'admin');
  if (!a) throw new Error('role=admin のユーザーがいない');
  return a.id;
}

export async function getAdminAllocations(adminId: number, matchId: number): Promise<{ editable: boolean; blocks: AdminBlock[] }> {
  return getJson(adminId, `/api/admin/matches/${matchId}/allocations`);
}

export async function patchAllocations(
  adminId: number,
  matchId: number,
  changes: AllocationChange[],
): Promise<{ status: number; body: unknown }> {
  const api = await newApi(adminId);
  try {
    const res = await api.patch(`/api/admin/matches/${matchId}/allocations`, { data: { changes } });
    return { status: res.status(), body: await res.json().catch(() => null) };
  } finally {
    await api.dispose();
  }
}

export async function pickEditableUnsoldMatch(adminId: number): Promise<number> {
  const { data } = await getJson<{
    data: { id: number; editable: boolean; status: string; sale_phases: { starts_at: string; ends_at: string }[] }[];
  }>(adminId, '/api/admin/matches');
  const now = Date.now();
  // 販売フェーズの最中でないと、購入側の操作（仮押さえ）を試せない
  const selling = (m: (typeof data)[number]) =>
    m.sale_phases.some((p) => Date.parse(p.starts_at) <= now && now + HOUR < Date.parse(p.ends_at));
  for (const m of data.filter((x) => x.editable && x.status === 'on_sale' && selling(x))) {
    const { blocks } = await getAdminAllocations(adminId, m.id);
    // 未ロックの自由席ブロックを 2 つ以上探す（ホームブロックが全てロックされている場合に対応）
    const unlockedUnreserved = blocks.filter((b) => b.seating_type === 'unreserved' && !b.locked);
    if (unlockedUnreserved.length >= 2) return m.id;
  }
  throw new Error(
    '条件を満たす試合が無い。未ロックの自由席ブロックが 2 つ以上ある試合を探してください。' +
    'dev DB のシード（migrate:fresh --seed）か、kickoff 日付（2027-06 以降は全試合キックオフ済みになる）を確認',
  );
}

// 自由席ブロックの数量を 1 枚セットする（先頭の券種）。レスポンスの status と body を返す
export async function setUnreservedQuantity(
  userId: number,
  matchId: number,
  block: { id: number; seat_category: string },
  quantity: number,
): Promise<{ status: number; body: unknown }> {
  const { data } = await getJson<{ data: { seat_category: string; ticket_type: { id: number; code: string } }[] }>(
    undefined,
    `/api/matches/${matchId}/prices`,
  );
  const p = data.find((x) => x.seat_category === block.seat_category && x.ticket_type.code !== 'season_pass');
  if (!p) throw new Error('券種が見つからない');
  const api = await newApi(userId);
  try {
    const res = await api.put(`/api/matches/${matchId}/cart/blocks/${block.id}/items`, {
      data: { quantities: [{ ticket_type_id: p.ticket_type.id, quantity }] },
    });
    return { status: res.status(), body: await res.json().catch(() => null) };
  } finally {
    await api.dispose();
  }
}

// 未ログインで席を隠せる試合。販売中でない、または会員限定のフェーズの試合を優先し、なければ一般販売中の試合（未ログインは常に隠れる）
export async function pickHiddenMatch(): Promise<number> {
  const { data } = await getJson<{
    data: { id: number; status: string; sale_phases: { eligibility: string | null; starts_at: string; ends_at: string }[] }[];
  }>(undefined, '/api/matches');
  const now = Date.now();
  const current = (m: (typeof data)[number]) => m.sale_phases.find((p) => Date.parse(p.starts_at) <= now && now < Date.parse(p.ends_at));
  const m = data.find((x) => x.status === 'on_sale' && (!current(x) || current(x)!.eligibility !== null)) ?? data.find((x) => x.status === 'on_sale' && current(x));
  if (!m) throw new Error('on_sale の試合がない');
  return m.id;
}

export async function pickBlockOf(matchId: number, type: 'reserved' | 'unreserved'): Promise<number> {
  const { blocks } = await getJson<{ blocks: Block[] }>(undefined, `/api/matches/${matchId}/seatmap`);
  const b = blocks.find((x) => x.seating_type === type && (x.allocation === 'home' || x.allocation === 'away'));
  if (!b) throw new Error(`${type} のブロックがない`);
  return b.id;
}
