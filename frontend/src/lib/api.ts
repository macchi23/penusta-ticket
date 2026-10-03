export type Team = {
  id: number;
  name: string;
  short_name: string;
  color: string;
};

export type Match = {
  id: number;
  kickoff_at: string;
  status: string;
  section_label: string;
  home_team: Team;
  away_team: Team;
  stadium: string;
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
  available: number;
  geometry: { x: number; y: number; w: number; h: number };
};

export type SeatMap = {
  match: Match;
  viewbox: { width: number; height: number };
  blocks: BlockInfo[];
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

export type DemoUser = { id: number; name: string; gender: string | null; birth_date: string | null; prefecture: string | null; memberships: string[] };
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
};
export type Order = {
  id: number;
  match_id: number;
  status: OrderStatus;
  expires_at: string | null;
  ordered_at: string | null;
  total: number;
  items: OrderItem[];
};
export type CartResponse = { cart: Order | null; server_time: string; max_items: number };
export type ApiErrorCode =
  | "seat_unavailable" | "sold_out" | "cart_expired"
  | "cart_limit_reached" | "not_on_sale" | "price_not_found" | "cart_empty"
  | "payment_declined" | "user_required" | "validation_failed" | "unknown";
export type ApiError = { code: ApiErrorCode; message: string };
export type ActionResult<T> = { ok: true; data: T } | { ok: false; error: ApiError };
export type SeatDisplayStatus = SeatStatus | "mine";
