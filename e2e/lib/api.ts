import { request, type APIRequestContext } from '@playwright/test';

export const API_URL = 'http://localhost:8080';

type Block = {
  id: number;
  code: string;
  seating_type: 'reserved' | 'unreserved';
  allocation: string;
  available: number;
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

export async function pickOnSaleMatch(): Promise<number> {
  const { data } = await getJson<{ data: { id: number; status: string }[] }>(undefined, '/api/matches');
  const m = data.find((x) => x.status === 'on_sale');
  if (!m) throw new Error('on_sale の試合がない');
  return m.id;
}

export async function pickReservedBlock(matchId: number): Promise<number> {
  const { blocks } = await getJson<{ blocks: Block[] }>(undefined, `/api/matches/${matchId}/seatmap`);
  const b = blocks
    .filter((x) => x.seating_type === 'reserved' && (x.allocation === 'home' || x.allocation === 'away') && x.available > 0)
    .sort((a, b) => b.available - a.available)[0];
  if (!b) throw new Error('空きのある指定席ブロックがない');
  return b.id;
}

export async function pickUnreservedBlock(matchId: number): Promise<number> {
  const { blocks } = await getJson<{ blocks: Block[] }>(undefined, `/api/matches/${matchId}/seatmap`);
  const b = blocks
    .filter((x) => x.seating_type === 'unreserved' && (x.allocation === 'home' || x.allocation === 'away') && x.available >= 3)
    .sort((a, b) => b.available - a.available)[0];
  if (!b) throw new Error('残席 3 以上の自由席ブロックがない');
  return b.id;
}

export async function getBlockSeatCategory(matchId: number, blockId: number): Promise<string> {
  const { blocks } = await getJson<{ blocks: (Block & { seat_category: string })[] }>(undefined, `/api/matches/${matchId}/seatmap`);
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
  const { rows } = await getJson<BlockSeats>(undefined, `/api/matches/${matchId}/blocks/${blockId}/seats`);
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
  const { rows } = await getJson<BlockSeats>(undefined, `/api/matches/${matchId}/blocks/${blockId}/seats`);
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
