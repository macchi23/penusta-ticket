import Link from "next/link";
import { notFound, redirect } from "next/navigation";
import type { CartResponse, SeatMap } from "@/lib/api";
import { ApiHttpError, apiGet } from "@/lib/server-api";
import CheckoutForm from "@/components/CheckoutForm";
import HoldCountdown from "@/components/HoldCountdown";
import OrderItemList from "@/components/OrderItemList";

type Settled<T> = { ok: true; data: T } | { ok: false; status: number };

// 失敗を throw にせず status で返す。通信エラーは status 0
async function settle<T>(p: Promise<T>): Promise<Settled<T>> {
  try {
    return { ok: true, data: await p };
  } catch (e) {
    return { ok: false, status: e instanceof ApiHttpError ? e.status : 0 };
  }
}

export default async function CheckoutPage(props: PageProps<"/matches/[id]/checkout">) {
  const { id } = await props.params;
  if (!/^[1-9][0-9]*$/.test(id)) notFound();

  const [cartRes, seatmapRes] = await Promise.all([
    settle(apiGet<CartResponse>(`/api/matches/${id}/cart`)),
    settle(apiGet<SeatMap>(`/api/matches/${id}/seatmap`)),
  ]);

  if ((!cartRes.ok && cartRes.status === 404) || (!seatmapRes.ok && seatmapRes.status === 404)) {
    notFound();
  }
  if (!cartRes.ok || !seatmapRes.ok) {
    return (
      <main className="mx-auto max-w-3xl px-4 py-10">
        <p>
          {!cartRes.ok && cartRes.status === 401
            ? "ユーザーを選択してください。"
            : "カートを取得できません。"}
        </p>
        <Link href={`/matches/${id}`} className="text-blue-600 underline">
          試合ページへ
        </Link>
      </main>
    );
  }
  const cart = cartRes.data.cart;
  if (!cart || cart.items.length === 0) redirect(`/matches/${id}`);

  const { match } = seatmapRes.data;
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
    <main className="mx-auto max-w-3xl px-4 py-8">
      <Link href={`/matches/${id}`} className="text-sm text-blue-600 underline">
        ← 席の選択に戻る
      </Link>
      <header className="mt-2 mb-4">
        <div className="text-sm text-gray-500">{kickoff}</div>
        <h1 className="mt-1 flex items-center gap-3 text-2xl font-bold">
          <span style={{ color: match.home_team.color }}>{match.home_team.name}</span>
          <span className="text-base text-gray-400">vs</span>
          <span style={{ color: match.away_team.color }}>{match.away_team.name}</span>
        </h1>
      </header>
      <section className="rounded-lg border border-gray-200 p-4 dark:border-gray-700">
        <div className="flex items-baseline justify-between">
          <h2 className="text-lg font-bold">購入内容の確認</h2>
          {cart.expires_at && (
            <span className="text-sm">
              <HoldCountdown expiresAt={cart.expires_at} serverTime={cartRes.data.server_time} />
            </span>
          )}
        </div>
        <OrderItemList items={cart.items} total={cart.items.reduce((s, i) => s + i.price, 0)} />
      </section>
      <CheckoutForm matchId={Number(id)} orderId={cart.id} />
    </main>
  );
}
