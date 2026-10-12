import type { ApiError } from "@/lib/api";

export type ResaleContext = "list" | "cancel" | "purchase";

export function resaleErrorMessage(error: ApiError, context: ResaleContext): string {
  switch (error.code) {
    case "resale_closed":
      return "リセールの受付期間外です。";
    case "not_owner":
      return "この券を操作する権限がありません。";
    case "not_listable":
      return "この券は出品できません（または出品中の状態が変わりました）。";
    case "price_out_of_range":
      return "出品価格が範囲外です。";
    case "listing_unavailable":
      return context === "purchase"
        ? "他の方が先に購入しました。"
        : "出品の状態が変わりました。ページを更新してください。";
    case "own_listing":
      return "自分の出品は購入できません。";
    case "resale_limit_reached":
      return "この試合で購入できる枚数の上限に達しています。";
    case "payment_failed":
      return "決済に失敗しました。もう一度お試しください。";
    case "user_required":
      return "ユーザーを選択してください。";
    default:
      return error.message || "エラーが発生しました。";
  }
}
