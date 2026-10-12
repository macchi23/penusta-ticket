export type Team = {
  id: number;
  name: string;
  short_name: string;
  color: string;
};

export type SalePhaseKind = "fanclub_presale" | "general";
export type MembershipKind = "season_pass" | "fanclub";
export type SalePhase = {
  id: number;
  kind: SalePhaseKind;
  method: "first_come";
  eligibility: MembershipKind | null;
  starts_at: string;
  ends_at: string;
};

export type Match = {
  id: number;
  kickoff_at: string;
  status: string;
  section_label: string;
  home_team: Team;
  away_team: Team;
  stadium: string;
  sale_phases: SalePhase[];
};

export type Allocation = "home" | "away" | "buffer" | "closed";

export type BlockInfo = {
  id: number;
  code: string;
  stand: string;
  seating_type: "reserved" | "unreserved";
  seat_category: string;
  allocation: Allocation;
  capacity: number;
  // 購入できない人（未ログイン・発売前・非対象・終了後）には null。ブロックの割当の色だけ見せる
  available: number | null;
  geometry: { x: number; y: number; w: number; h: number };
};

export type SeatMap = {
  match: Match;
  viewbox: { width: number; height: number };
  blocks: BlockInfo[];
};

export type AdminMatch = Match & { editable: boolean };
export type TicketCounts = { paid: number; held: number };
export type AdminBlock = {
  id: number;
  code: string;
  stand: string;
  seating_type: "reserved" | "unreserved";
  seat_category: string;
  capacity: number;
  geometry: { x: number; y: number; w: number; h: number };
  allocation: Allocation;
  tickets: TicketCounts;
  locked: boolean;
};
export type AdminAllocations = {
  match: Match;
  editable: boolean;
  viewbox: { width: number; height: number };
  blocks: AdminBlock[];
};
export type AllocationChange = { block_id: number; expected: Allocation; allocation: Allocation };
export type AllocationUpdateResult = AdminAllocations & { change_set_id: string };
export type AllocationConflict = {
  block_id: number;
  reason: "stale" | "has_tickets";
  current: Allocation;
  tickets: TicketCounts;
};

export type SeatStatus = "available" | "held" | "sold";

export type BlockSeats = {
  block: { id: number; code: string; stand: string };
  rows: { row: string; seats: { id: number; number: number; status: SeatStatus }[] }[];
};

export function apiBase(): string {
  if (typeof window === "undefined") {
    return (
      process.env.API_URL_INTERNAL ??
      process.env.NEXT_PUBLIC_API_URL ??
      "http://localhost:8080"
    );
  }
  return process.env.NEXT_PUBLIC_API_URL ?? "http://localhost:8080";
}

export async function fetchJson<T>(path: string): Promise<T> {
  const res = await fetch(`${apiBase()}${path}`, { cache: "no-store" });
  if (!res.ok) {
    throw new Error(`API error ${res.status}: ${path}`);
  }
  return res.json();
}

export type DemoUser = { id: number; name: string; gender: string | null; birth_date: string | null; prefecture: string | null; role: "customer" | "admin"; memberships: string[] };
export type TicketType = { id: number; code: string; name: string };
export type MatchPrice = { seat_category: string; ticket_type: TicketType; price: number };
export type OrderStatus = "pending" | "paid" | "expired";
export type OrderItem = {
  id: number;
  ticket_id: number;
  block: { id: number; code: string; stand: string };
  seating_type: "reserved" | "unreserved";
  seat: { id: number; row: string; number: number } | null;
  seat_category: string;
  ticket_type: TicketType;
  price: number;
  // 元注文に残る明細の状態。resold のとき resale_payout に売却の受取額が入る
  ticket_status: "held" | "paid" | "resold";
  resale_payout: number | null;
};
export type Order = {
  id: number;
  match_id: number;
  status: OrderStatus;
  kind: "purchase" | "season_pass" | "resale";
  expires_at: string | null;
  ordered_at: string | null;
  // 明細の合計 + buyer_fee（リセール注文の支払額）
  total: number;
  buyer_fee: number;
  items: OrderItem[];
};
export type CartResponse = {
  cart: Order | null;
  server_time: string;
  max_items: number;
  sale: { current_phase_id: number | null; eligible: boolean };
};
export type UnreservedQuantity = { ticket_type_id: number; quantity: number };
export type ApiErrorCode =
  | "seat_unavailable" | "sold_out" | "cart_expired"
  | "cart_limit_reached" | "not_on_sale" | "price_not_found" | "cart_empty"
  | "payment_declined" | "user_required" | "validation_failed" | "unknown"
  | "allocation_conflict" | "match_locked" | "allocation_busy" | "busy" | "forbidden" | "not_eligible" | "seats_hidden"
  | "resale_closed" | "not_owner" | "not_listable" | "price_out_of_range" | "listing_unavailable"
  | "own_listing" | "resale_limit_reached" | "payment_failed"
  | "resale_hold_exists" | "listing_held" | "resale_hold_cooldown" | "not_found";
export type ApiError = { code: ApiErrorCode; message: string; details?: { blocks?: AllocationConflict[] } };
export type ActionResult<T> = { ok: true; data: T } | { ok: false; error: ApiError };
export type SeatDisplayStatus = SeatStatus | "mine";

// マイチケットとリセール
export type TicketBlock = { id: number; code: string; stand: string };
export type TicketSeat = { id: number; row: string; number: number };
export type ResaleListingStatus = "active" | "sold" | "cancelled" | "expired";
export type MyListing = {
  id: number;
  status: ResaleListingStatus;
  price: number;
  seller_fee: number;
  transfer_fee: number;
  // 出品者の受取額 = price - seller_fee - transfer_fee
  payout: number;
  closes_at: string;
  // 買い手が仮押さえ中（取り下げ不可）
  on_hold: boolean;
};
export type MyTicket = {
  id: number;
  match: Match;
  block: TicketBlock;
  seating_type: "reserved" | "unreserved";
  seat_category: string;
  // null = 自由席
  seat: TicketSeat | null;
  ticket_type: TicketType;
  price: number;
  source: "purchase" | "season_pass" | "resale";
  status: "paid" | "resold";
  order_id: number;
  // active か sold の出品
  listing: MyListing | null;
  can_list: boolean;
  can_cancel: boolean;
};
export type ListingTerms = {
  face_price: number;
  min_price: number;
  seller_fee_rate: number;
  transfer_fee: number;
  buyer_fee: number;
};
export type TicketDetail = MyTicket & {
  // paid かつ出品なしのときだけ値が入る
  qr_uuid: string | null;
  // can_list のときだけ
  listing_terms: ListingTerms | null;
};
export type MyTicketScope = "upcoming" | "past";

export type ResaleOffer = {
  id: number;
  seat: TicketSeat | null;
  ticket_type: TicketType;
  price: number;
  buyer_fee: number;
  total: number;
};
// 買い手本人の仮押さえ（10 分）。ticket_id・出品者の情報は含まない
export type ResaleHold = {
  id: number;
  listing_id: number;
  match_id: number;
  expires_at: string;
  block: TicketBlock;
  seat: TicketSeat | null;
  seat_category: string;
  ticket_type: TicketType;
  price: number;
  buyer_fee: number;
  total: number;
};
export type ResaleCartResponse = { hold: ResaleHold | null; server_time: string };
export type ResaleBlock = {
  block: TicketBlock;
  seating_type: "reserved" | "unreserved";
  seat_category: string;
  count: number;
  min_total: number;
  // total 昇順
  listings: ResaleOffer[];
};
export type ResaleBoardStatus = "open" | "login_required" | "not_started" | "closed";
export type ResaleBoard = {
  status: ResaleBoardStatus;
  // 一般販売の開始。フェーズが無いときは null
  opens_at: string | null;
  // リセールの締切。試合が販売中でないときは null
  closes_at: string | null;
  // status が open のときだけ中身がある
  blocks: ResaleBlock[];
};
