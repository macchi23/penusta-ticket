import Link from "next/link";
import type { MyTicket, MyTicketScope } from "@/lib/api";
import { ApiHttpError, apiGet } from "@/lib/server-api";
import { formatJst } from "@/lib/format";
import { SOURCE_LABEL, seatLabel, ticketPriceLabel } from "@/lib/ticket-labels";
import ListingStatusBadge from "@/components/ListingStatusBadge";
import LoginRequired from "@/components/LoginRequired";

export default async function TicketsPage(props: PageProps<"/tickets">) {
  const sp = await props.searchParams;
  const rawScope = Array.isArray(sp.scope) ? sp.scope[0] : sp.scope;
  const scope: MyTicketScope = rawScope === "past" ? "past" : "upcoming";

  let tickets: MyTicket[];
  try {
    tickets = (await apiGet<{ data: MyTicket[] }>(`/api/me/tickets?scope=${scope}`)).data;
  } catch (e) {
    if (e instanceof ApiHttpError && e.status === 401) return <LoginRequired />;
    throw e;
  }

  const tab = (value: MyTicketScope, label: string) => (
    <Link
      href={value === "past" ? "/tickets?scope=past" : "/tickets"}
      aria-current={scope === value ? "page" : undefined}
      className={
        scope === value
          ? "border-b-2 border-blue-600 px-3 py-1 font-semibold"
          : "px-3 py-1 text-blue-600 underline"
      }
    >
      {label}
    </Link>
  );

  return (
    <main className="mx-auto max-w-3xl px-4 py-8">
      <Link href="/" className="text-sm text-blue-600 underline">
        ← 試合一覧
      </Link>
      <h1 className="mt-2 text-2xl font-bold">マイチケット</h1>
      <nav className="mt-4 flex gap-2 text-sm">
        {tab("upcoming", "これから")}
        {tab("past", "過去")}
      </nav>
      {tickets.length === 0 ? (
        <p className="mt-6 text-gray-500">チケットはありません。</p>
      ) : (
        <ul className="mt-4 space-y-3">
          {tickets.map((t) => (
            <li key={t.id} data-testid={`ticket-${t.id}`} className="rounded-lg border border-gray-200 p-4 dark:border-gray-700">
              <div className="text-sm text-gray-500">{formatJst(t.match.kickoff_at)}</div>
              <div className="mt-1 font-semibold">
                {t.match.home_team.name} <span className="text-gray-400">vs</span> {t.match.away_team.name}
              </div>
              <div className="mt-1 text-sm">
                {seatLabel(t)} ・ {t.ticket_type.name} ・ {ticketPriceLabel(t)}
              </div>
              <div className="mt-1 text-xs text-gray-500">入手経路: {SOURCE_LABEL[t.source]}</div>
              <div className="mt-2 flex flex-wrap items-center gap-3">
                <ListingStatusBadge ticket={t} />
                <Link href={`/tickets/${t.id}`} className="text-sm text-blue-600 underline">
                  詳細
                </Link>
              </div>
            </li>
          ))}
        </ul>
      )}
    </main>
  );
}
