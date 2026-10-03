import Link from "next/link";
import { notFound } from "next/navigation";
import type { BlockSeats, CartResponse, MatchPrice, SeatMap } from "@/lib/api";
import { ApiHttpError, apiGet } from "@/lib/server-api";
import MatchPurchase from "@/components/MatchPurchase";
import AutoRefresh from "@/components/AutoRefresh";

type Settled<T> = { ok: true; data: T } | { ok: false; status: number };

// 失敗を throw にせず status で返す。通信エラーは status 0
async function settle<T>(p: Promise<T>): Promise<Settled<T>> {
  try {
    return { ok: true, data: await p };
  } catch (e) {
    return { ok: false, status: e instanceof ApiHttpError ? e.status : 0 };
  }
}

export default async function MatchPage(props: PageProps<"/matches/[id]">) {
  const { id } = await props.params;
  if (!/^[1-9][0-9]*$/.test(id)) notFound();
  const sp = await props.searchParams;
  const rawBlock = Array.isArray(sp.block) ? sp.block[0] : sp.block;
  const blockId = rawBlock && /^[1-9][0-9]*$/.test(rawBlock) ? Number(rawBlock) : null;

  const [seatmap, cartRes, pricesRes, seatsRes] = await Promise.all([
    apiGet<SeatMap>(`/api/matches/${id}/seatmap`).catch(() => null),
    settle(apiGet<CartResponse>(`/api/matches/${id}/cart`)),
    settle(apiGet<{ data: MatchPrice[] }>(`/api/matches/${id}/prices`)),
    blockId
      ? settle(apiGet<BlockSeats>(`/api/matches/${id}/blocks/${blockId}/seats`))
      : Promise.resolve(null),
  ]);
  if (!seatmap || (!cartRes.ok && cartRes.status === 404)) {
    return (
      <main className="mx-auto max-w-3xl px-4 py-10">
        <p>試合が見つかりません。</p>
        <Link href="/" className="text-blue-600 underline">
          試合一覧へ戻る
        </Link>
      </main>
    );
  }
  const cart = cartRes.ok ? cartRes.data : null;
  const cartError = !cartRes.ok && cartRes.status !== 401;
  const pricesError = !pricesRes.ok && pricesRes.status !== 404;
  const selectedBlock = seatmap.blocks.find((b) => b.id === blockId) ?? null;
  const reservedSelected = selectedBlock?.seating_type === "reserved";
  const blockSeats = reservedSelected && seatsRes?.ok ? seatsRes.data : null;
  const seatsError = reservedSelected && seatsRes !== null && !seatsRes.ok && seatsRes.status !== 404;

  const { match } = seatmap;
  const kickoff = new Date(match.kickoff_at).toLocaleString("ja-JP", {
    year: "numeric",
    month: "numeric",
    day: "numeric",
    weekday: "short",
    hour: "2-digit",
    minute: "2-digit",
    timeZone: "Asia/Tokyo",
  });

  return (
    <main className="mx-auto max-w-5xl px-4 py-8">
      <Link href="/" className="text-sm text-blue-600 underline">
        ← 試合一覧
      </Link>
      <header className="mt-2 mb-6">
        <div className="text-sm text-gray-500">
          {match.section_label} ・ {kickoff} ・ {match.stadium}
        </div>
        <h1 className="mt-1 flex items-center gap-3 text-2xl font-bold">
          <span style={{ color: match.home_team.color }}>{match.home_team.name}</span>
          <span className="text-base text-gray-400">vs</span>
          <span style={{ color: match.away_team.color }}>{match.away_team.name}</span>
        </h1>
      </header>
      <MatchPurchase
        match={match}
        seatmap={seatmap}
        cart={cart}
        prices={pricesRes.ok ? pricesRes.data.data : []}
        cartError={cartError}
        pricesError={pricesError}
        seatsError={seatsError}
        selectedBlock={selectedBlock}
        blockSeats={blockSeats}
      />
      <AutoRefresh />
    </main>
  );
}
