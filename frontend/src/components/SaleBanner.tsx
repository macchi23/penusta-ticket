import type { CartResponse, SalePhase } from "@/lib/api";

const jst = (iso: string) => {
  const parts = new Intl.DateTimeFormat("ja-JP", {
    month: "numeric",
    day: "numeric",
    hour: "2-digit",
    minute: "2-digit",
    hourCycle: "h23",
    timeZone: "Asia/Tokyo",
  }).formatToParts(new Date(iso));
  const get = (t: string) => parts.find((p) => p.type === t)?.value ?? "";
  return `${get("month")}/${get("day")} ${get("hour")}:${get("minute")}`;
};

function describe(phases: SalePhase[], sale: CartResponse["sale"], serverTime: string): string | null {
  if (phases.length === 0) return null;
  const now = Date.parse(serverTime);
  const current = phases.find((p) => p.id === sale.current_phase_id);
  if (current) {
    if (current.kind === "general") return "一般販売中";
    if (sale.eligible) return "FC 会員先行販売中";
    const general = phases.find((p) => p.kind === "general");
    return general
      ? `FC 会員先行販売中（一般販売は ${jst(general.starts_at)} から）`
      : "FC 会員先行販売中";
  }
  const upcoming = phases.find((p) => Date.parse(p.starts_at) > now);
  if (upcoming) {
    return `${upcoming.kind === "fanclub_presale" ? "FC 先行" : "一般販売"}は ${jst(upcoming.starts_at)} から`;
  }
  return "販売終了";
}

// 席を見せられない理由。帯の文言と矛盾しないよう describe と同じ判定で選ぶ
export function seatsHiddenReason(
  phases: SalePhase[],
  sale: CartResponse["sale"] | null,
  serverTime: string | null,
  state: { noUser: boolean; cartError: boolean },
): string {
  if (state.noUser) return "席の状況の表示にはログイン（ユーザー選択）が必要です";
  if (state.cartError || sale === null || serverTime === null) return "販売状況を取得できませんでした";
  if (sale.current_phase_id !== null) {
    if (sale.eligible) return "販売状況が更新されました。再読み込みしてください";
    return "FC 会員先行販売中は会員のみ席を表示できます";
  }
  const now = Date.parse(serverTime);
  if (phases.some((p) => Date.parse(p.starts_at) > now)) return "発売前のため席は選べません";
  return phases.length === 0 ? "現在販売していないため席は選べません" : "販売は終了しました";
}

export default function SaleBanner({
  phases,
  sale,
  serverTime,
}: {
  phases: SalePhase[];
  sale: CartResponse["sale"];
  serverTime: string;
}) {
  const text = describe(phases, sale, serverTime);
  if (text === null) return null;
  return (
    <p
      data-testid="sale-banner"
      className="rounded border border-blue-300 bg-blue-50 p-3 text-sm text-blue-900 dark:border-blue-700 dark:bg-blue-950 dark:text-blue-100"
    >
      {text}
    </p>
  );
}
