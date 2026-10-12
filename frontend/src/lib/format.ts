export const yen = (n: number) => `¥${n.toLocaleString("ja-JP")}`;

export function formatJst(iso: string, withYear = false): string {
  return new Date(iso).toLocaleString("ja-JP", {
    ...(withYear ? { year: "numeric" } : {}),
    month: "numeric",
    day: "numeric",
    weekday: "short",
    hour: "2-digit",
    minute: "2-digit",
    timeZone: "Asia/Tokyo",
  });
}

// 出品者手数料（円未満四捨五入）。backend の ResaleService と同じ丸め
export function sellerFee(price: number, rate: number): number {
  return Math.round(Number((price * rate).toFixed(6)));
}
