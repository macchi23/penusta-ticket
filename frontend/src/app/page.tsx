import Link from "next/link";
import { Match } from "@/lib/api";
import { apiGet } from "@/lib/server-api";

type Health = { db: string; redis: string };

async function getHealth(): Promise<Health | null> {
  try {
    return await apiGet<Health>("/api/health");
  } catch {
    return null;
  }
}

async function getMatches(): Promise<Match[]> {
  try {
    const res = await apiGet<{ data: Match[] }>("/api/matches");
    // 終了済みの試合は一覧に出さない（API は全件返す）
    return res.data.filter((m) => m.status !== "finished");
  } catch {
    return [];
  }
}

function formatKickoff(iso: string): string {
  return new Date(iso).toLocaleString("ja-JP", {
    month: "numeric",
    day: "numeric",
    weekday: "short",
    hour: "2-digit",
    minute: "2-digit",
    timeZone: "Asia/Tokyo",
  });
}

export default async function Home() {
  const [health, matches] = await Promise.all([getHealth(), getMatches()]);

  return (
    <main className="mx-auto max-w-3xl px-4 py-10">
      <header className="mb-8">
        <h1 className="text-2xl font-bold">ペヌスタ チケット</h1>
        <p className="mt-1 text-sm text-gray-500">
          ペヌソニックスタジアム 公式チケットサイト（架空）
        </p>
        <p className="mt-2 text-xs text-gray-400">
          API: {health ? `db=${health.db} / redis=${health.redis}` : "接続不可"}
        </p>
      </header>

      <h2 className="mb-4 text-lg font-semibold">チケット販売中の試合</h2>
      {matches.length === 0 ? (
        <p className="text-gray-500">試合がありません（シード未投入？）</p>
      ) : (
        <ul className="space-y-3">
          {matches.map((m) => (
            <li key={m.id}>
              <Link
                href={`/matches/${m.id}`}
                className="block rounded-lg border border-gray-200 p-4 transition hover:border-blue-400 hover:shadow dark:border-gray-700"
              >
                <div className="text-xs text-gray-500">
                  {m.section_label} ・ {formatKickoff(m.kickoff_at)} ・ {m.stadium}
                </div>
                <div className="mt-1 flex items-center gap-3 text-lg font-semibold">
                  <span style={{ color: m.home_team.color }}>{m.home_team.name}</span>
                  <span className="text-sm text-gray-400">vs</span>
                  <span style={{ color: m.away_team.color }}>{m.away_team.name}</span>
                </div>
              </Link>
            </li>
          ))}
        </ul>
      )}
    </main>
  );
}
