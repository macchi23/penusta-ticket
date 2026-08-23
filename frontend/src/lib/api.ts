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
