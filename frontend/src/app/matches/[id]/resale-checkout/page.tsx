import Link from "next/link";
import { notFound } from "next/navigation";
import type { ResaleCartResponse, SeatMap } from "@/lib/api";
import { ApiHttpError, apiGet } from "@/lib/server-api";
import { formatJst } from "@/lib/format";
import HoldCountdown from "@/components/HoldCountdown";
import OrderItemList from "@/components/OrderItemList";
import ResaleCheckoutForm from "@/components/ResaleCheckoutForm";

type Settled<T> = { ok: true; data: T } | { ok: false; status: number };

// 失敗を throw にせず status で返す。通信エラーは status 0
async function settle<T>(p: Promise<T>): Promise<Settled<T>> {
  try {
    return { ok: true, data: await p };
  } catch (e) {
    return { ok: false, status: e instanceof ApiHttpError ? e.status : 0 };
  }
}

export default async function ResaleCheckoutPage(props: PageProps<"/matches/[id]/resale-checkout">) {
  const { id } = await props.params;
  if (!/^[1-9][0-9]*$/.test(id)) notFound();

  const [cartRes, seatmapRes] = await Promise.all([
    settle(apiGet<ResaleCartResponse>(`/api/matches/${id}/resale-cart`)),
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
            : "購入手続きの情報を取得できません。"}
        </p>
        <Link href={`/matches/${id}`} className="text-blue-600 underline">
          試合ページへ
        </Link>
      </main>
    );
  }
  const hold = cartRes.data.hold;
  if (!hold) {
    return (
      <main className="mx-auto max-w-3xl px-4 py-10">
        <p>購入手続き中の出品はありません。有効期限が切れた可能性があります。</p>
        <Link href={`/matches/${id}`} className="text-blue-600 underline">
          試合ページへ
        </Link>
      </main>
    );
  }

  const { match } = seatmapRes.data;

  return (
    <main className="mx-auto max-w-3xl px-4 py-8">
      <Link href={`/matches/${id}`} className="text-sm text-blue-600 underline">
        ← 試合ページに戻る
      </Link>
      <header className="mt-2 mb-4">
        <div className="text-sm text-gray-500">{formatJst(match.kickoff_at, true)}</div>
        <h1 className="mt-1 flex items-center gap-3 text-2xl font-bold">
          <span style={{ color: match.home_team.color }}>{match.home_team.name}</span>
          <span className="text-base text-gray-400">vs</span>
          <span style={{ color: match.away_team.color }}>{match.away_team.name}</span>
        </h1>
      </header>
      <section className="rounded-lg border border-gray-200 p-4 dark:border-gray-700">
        <div className="flex items-baseline justify-between">
          <h2 className="text-lg font-bold">購入内容の確認（リセール）</h2>
          <span className="text-sm">
            <HoldCountdown expiresAt={hold.expires_at} serverTime={cartRes.data.server_time} />
          </span>
        </div>
        <OrderItemList items={[hold]} total={hold.total} buyerFee={hold.buyer_fee} />
      </section>
      <ResaleCheckoutForm matchId={Number(id)} holdId={hold.id} />
    </main>
  );
}
