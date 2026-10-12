import type { MyTicket } from "@/lib/api";
import { formatJst, yen } from "@/lib/format";

export default function ListingStatusBadge({ ticket }: { ticket: MyTicket }) {
  const l = ticket.listing;
  if (ticket.status === "resold" || l?.status === "sold") {
    return (
      <span className="rounded bg-gray-200 px-2 py-0.5 text-xs text-gray-800 dark:bg-gray-700 dark:text-gray-100">
        リセール済み{l ? `（受取 ${yen(l.payout)}）` : ""}
      </span>
    );
  }
  if (l?.status === "active") {
    return (
      <span className="rounded bg-amber-100 px-2 py-0.5 text-xs text-amber-900">
        出品中 {yen(l.price)}（受付締切 {formatJst(l.closes_at)}）
      </span>
    );
  }
  return null;
}
