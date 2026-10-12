import Link from "next/link";
import type { OrderItem } from "@/lib/api";
import { yen } from "@/lib/format";

export default function OrderItemList({
  items,
  total,
  buyerFee = 0,
}: {
  items: OrderItem[];
  total: number;
  buyerFee?: number;
}) {
  return (
    <>
      <ul className="mt-3 divide-y divide-gray-200 text-sm dark:divide-gray-700">
        {items.map((it) => (
          <li key={it.id} className="flex flex-wrap items-center gap-2 py-2">
            <span className="min-w-0 flex-1">
              {it.block.stand} {it.block.code}{" "}
              {it.seat ? `${it.seat.row}列 ${it.seat.number}番` : "自由席"}
            </span>
            <span>{it.ticket_type.name}</span>
            <span className="w-20 text-right">{yen(it.price)}</span>
            {it.ticket_status === "resold" && (
              <span className="text-gray-500">
                リセール済み{it.resale_payout !== null && `（受取 ${yen(it.resale_payout)}）`}
              </span>
            )}
            {it.ticket_status === "paid" && (
              <Link href={`/tickets/${it.ticket_id}`} className="text-blue-600 underline">
                チケット
              </Link>
            )}
          </li>
        ))}
      </ul>
      {buyerFee > 0 && (
        <p className="mt-3 text-sm" data-testid="buyer-fee">
          購入者手数料 {yen(buyerFee)}
        </p>
      )}
      <p className="mt-3 font-bold">
        {items.length} 枚 ・ 合計 {yen(total)}
      </p>
    </>
  );
}
