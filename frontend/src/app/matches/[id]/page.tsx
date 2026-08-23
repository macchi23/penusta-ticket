import Link from "next/link";
import { fetchJson, SeatMap } from "@/lib/api";
import SeatMapView from "@/components/SeatMapView";

export default async function MatchPage(props: PageProps<"/matches/[id]">) {
  const { id } = await props.params;
  let seatmap: SeatMap;
  try {
    seatmap = await fetchJson<SeatMap>(`/api/matches/${id}/seatmap`);
  } catch {
    return (
      <main className="mx-auto max-w-3xl px-4 py-10">
        <p>試合が見つかりません。</p>
        <Link href="/" className="text-blue-600 underline">
          試合一覧へ戻る
        </Link>
      </main>
    );
  }

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
      <SeatMapView matchId={match.id} seatmap={seatmap} />
    </main>
  );
}
