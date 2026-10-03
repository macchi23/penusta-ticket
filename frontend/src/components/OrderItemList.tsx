import type { OrderItem } from "@/lib/api";

const yen = (n: number) => `¥${n.toLocaleString("ja-JP")}`;

export default function OrderItemList({ items, total }: { items: OrderItem[]; total: number }) {
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
          </li>
        ))}
      </ul>
      <p className="mt-3 font-bold">
        {items.length} 枚 ・ 合計 {yen(total)}
      </p>
    </>
  );
}
