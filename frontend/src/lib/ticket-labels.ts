import type { MyTicket } from "@/lib/api";
import { yen } from "@/lib/format";

export const SOURCE_LABEL: Record<MyTicket["source"], string> = {
  purchase: "購入",
  season_pass: "年パス",
  resale: "リセールで購入",
};

export const ticketPriceLabel = (t: MyTicket) => (t.source === "season_pass" ? "年パス" : yen(t.price));

export const seatLabel = (t: MyTicket) =>
  `${t.block.stand} ${t.block.code} ${t.seat ? `${t.seat.row}列 ${t.seat.number}番` : "自由席"}`;
