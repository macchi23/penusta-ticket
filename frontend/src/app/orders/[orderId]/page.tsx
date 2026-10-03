import Link from "next/link";
import { notFound, redirect } from "next/navigation";
import type { Order } from "@/lib/api";
import { ApiHttpError, apiGet } from "@/lib/server-api";
import OrderItemList from "@/components/OrderItemList";

export default async function OrderPage(props: PageProps<"/orders/[orderId]">) {
  const { orderId } = await props.params;
  if (!/^[1-9][0-9]*$/.test(orderId)) notFound();

  let order: Order;
  try {
    order = (await apiGet<{ order: Order }>(`/api/orders/${orderId}`)).order;
  } catch (e) {
    if (e instanceof ApiHttpError && e.status === 401) {
      return (
        <main className="mx-auto max-w-3xl px-4 py-10">
          <p>ユーザーを選択してください。</p>
          <Link href="/" className="text-blue-600 underline">
            試合一覧へ
          </Link>
        </main>
      );
    }
    if (e instanceof ApiHttpError && e.status === 404) notFound();
    throw e;
  }
  if (order.status !== "paid") redirect(`/matches/${order.match_id}`);

  const orderedAt = order.ordered_at
    ? new Date(order.ordered_at).toLocaleString("ja-JP", {
        year: "numeric",
        month: "numeric",
        day: "numeric",
        hour: "2-digit",
        minute: "2-digit",
        timeZone: "Asia/Tokyo",
      })
    : "-";

  return (
    <main className="mx-auto max-w-3xl px-4 py-8">
      <h1 className="text-2xl font-bold">購入が完了しました</h1>
      <p className="mt-2 text-sm text-gray-500">
        注文番号 {order.id} ・ 確定 {orderedAt}
      </p>
      <section className="mt-4 rounded-lg border border-gray-200 p-4 dark:border-gray-700">
        <OrderItemList items={order.items} total={order.total} />
      </section>
      <Link href="/" className="mt-6 inline-block text-blue-600 underline">
        試合一覧へ
      </Link>
    </main>
  );
}
