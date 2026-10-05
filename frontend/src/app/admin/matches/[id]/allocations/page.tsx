import Link from "next/link";
import { notFound } from "next/navigation";
import { getAdminAllocations } from "@/lib/admin-api";
import AllocationEditor from "@/components/admin/AllocationEditor";

export default async function AllocationsPage(props: PageProps<"/admin/matches/[id]/allocations">) {
  const { id } = await props.params;
  if (!/^[1-9][0-9]*$/.test(id)) notFound();
  const data = await getAdminAllocations(Number(id));
  const { match } = data;
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
      <Link href="/admin" className="text-sm text-blue-600 underline">
        ← 管理画面
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
      <AllocationEditor matchId={match.id} editable={data.editable} viewbox={data.viewbox} blocks={data.blocks} />
    </main>
  );
}
