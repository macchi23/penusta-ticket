import Link from "next/link";
import { notFound } from "next/navigation";
import type { TicketDetail } from "@/lib/api";
import { ApiHttpError, apiGet } from "@/lib/server-api";
import { formatJst, yen } from "@/lib/format";
import { SOURCE_LABEL, seatLabel, ticketPriceLabel } from "@/lib/ticket-labels";
import ListingStatusBadge from "@/components/ListingStatusBadge";
import ListingForm from "@/components/ListingForm";
import CancelListingButton from "@/components/CancelListingButton";
import LoginRequired from "@/components/LoginRequired";

export default async function TicketPage(props: PageProps<"/tickets/[ticketId]">) {
  const { ticketId } = await props.params;
  if (!/^[1-9][0-9]*$/.test(ticketId)) notFound();

  let t: TicketDetail;
  try {
    t = (await apiGet<{ ticket: TicketDetail }>(`/api/tickets/${ticketId}`)).ticket;
  } catch (e) {
    if (e instanceof ApiHttpError && e.status === 401) return <LoginRequired />;
    if (e instanceof ApiHttpError && e.status === 404) notFound();
    throw e;
  }

  const resold = t.status === "resold" || t.listing?.status === "sold";
  const active = t.listing?.status === "active" ? t.listing : null;

  let qrNote: string | null = null;
  if (!t.qr_uuid) qrNote = resold ? "リセール済みのため表示できません。" : "出品中のため表示できません。";

  let noListReason: string | null = null;
  if (!t.can_list && !active && !resold) {
    noListReason =
      t.source === "resale"
        ? "リセールで購入した券は再出品できません。"
        : "現在は出品できません（受付期間外、または出品できない券です）。";
  }

  return (
    <main className="mx-auto max-w-3xl px-4 py-8">
      <Link href="/tickets" className="text-sm text-blue-600 underline">
        ← マイチケット
      </Link>
      <header className="mt-2">
        <div className="text-sm text-gray-500">{formatJst(t.match.kickoff_at, true)} ・ {t.match.stadium}</div>
        <h1 className="mt-1 text-2xl font-bold">
          {t.match.home_team.name} <span className="text-base text-gray-400">vs</span> {t.match.away_team.name}
        </h1>
      </header>

      <section className="mt-4 rounded-lg border border-gray-200 p-4 text-sm dark:border-gray-700">
        <div>{seatLabel(t)}</div>
        <div className="mt-1">
          {t.ticket_type.name} ・ {ticketPriceLabel(t)} ・ 入手経路: {SOURCE_LABEL[t.source]}
        </div>
        <div className="mt-2 flex flex-wrap gap-2">
          <ListingStatusBadge ticket={t} />
        </div>
        <div className="mt-2 flex gap-4">
          <Link href={`/orders/${t.order_id}`} className="text-blue-600 underline">
            注文詳細
          </Link>
          <Link href={`/matches/${t.match.id}`} className="text-blue-600 underline">
            試合ページ
          </Link>
        </div>
      </section>

      <section className="mt-4 rounded-lg border border-gray-200 p-4 dark:border-gray-700">
        <h2 className="font-semibold">入場用 QR（デモ）</h2>
        {t.qr_uuid ? (
          <div
            data-testid="qr-box"
            className="mt-2 inline-block rounded border-2 border-gray-800 p-4 font-mono text-xs break-all dark:border-gray-200"
          >
            {t.qr_uuid}
          </div>
        ) : (
          <p className="mt-2 text-sm text-gray-500">{qrNote}</p>
        )}
      </section>

      <section className="mt-4 rounded-lg border border-gray-200 p-4 dark:border-gray-700">
        <h2 className="font-semibold">リセール</h2>
        {resold && (
          <p className="mt-2 text-sm">
            この券は他の方に譲渡されました。{t.listing && <>受取額 {yen(t.listing.payout)}</>}
          </p>
        )}
        {active && (
          <div className="mt-2 text-sm">
            <p>
              出品中 ・ 出品価格 {yen(active.price)} ・ 受取額 {yen(active.payout)}
            </p>
            <p className="text-gray-500">受付締切 {formatJst(active.closes_at)}</p>
            {active.on_hold && (
              <p className="mt-1 text-gray-500">購入手続き中のため取り下げできません。</p>
            )}
            {t.can_cancel && <CancelListingButton ticketId={t.id} />}
          </div>
        )}
        {t.can_list && t.listing_terms && <ListingForm ticketId={t.id} terms={t.listing_terms} />}
        {noListReason && <p className="mt-2 text-sm text-gray-500">{noListReason}</p>}
      </section>
    </main>
  );
}
